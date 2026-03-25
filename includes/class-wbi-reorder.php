<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI Reorder Rules Module
 *
 * Reglas de Reabastecimiento automático: punto de reorden, stock mínimo/máximo,
 * generación automática de Órdenes de Compra y alertas cuando el stock cae por
 * debajo del punto de reorden.
 */
class WBI_Reorder_Module {

    // -------------------------------------------------------------------------
    // Constructor & Hooks
    // -------------------------------------------------------------------------

    public function __construct() {
        // Create/update DB tables on init
        add_action( 'init', array( $this, 'maybe_create_tables' ) );

        // Admin submenu
        add_action( 'admin_menu', array( $this, 'add_submenu' ), 100 );

        // WP Cron: reorder check every 12 hours
        add_action( 'wbi_reorder_check', array( $this, 'check_reorder_rules' ) );
        add_action( 'admin_init', array( $this, 'maybe_schedule_cron' ) );

        // WooCommerce stock change hooks — trigger immediately on stock change
        add_action( 'woocommerce_product_set_stock',          array( $this, 'on_stock_change' ), 10, 1 );
        add_action( 'woocommerce_variation_set_stock',        array( $this, 'on_stock_change' ), 10, 1 );

        // AJAX endpoints
        add_action( 'wp_ajax_wbi_reorder_search_products', array( $this, 'ajax_search_products' ) );
        add_action( 'wp_ajax_wbi_reorder_save_rule',       array( $this, 'ajax_save_rule' ) );
        add_action( 'wp_ajax_wbi_reorder_delete_rule',     array( $this, 'ajax_delete_rule' ) );
        add_action( 'wp_ajax_wbi_reorder_toggle_rule',     array( $this, 'ajax_toggle_rule' ) );
        add_action( 'wp_ajax_wbi_reorder_run_now',         array( $this, 'ajax_run_now' ) );

        // Dashboard widget
        add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );

        // Admin-post: bulk actions
        add_action( 'admin_post_wbi_reorder_bulk', array( $this, 'handle_bulk_action' ) );
    }

    // -------------------------------------------------------------------------
    // Database Tables
    // -------------------------------------------------------------------------

    public function maybe_create_tables() {
        global $wpdb;

        $installed = get_option( 'wbi_reorder_db_version', '' );
        if ( $installed === '1.0' ) {
            return;
        }

        $charset_collate = $wpdb->get_charset_collate();

        $sql_rules = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wbi_reorder_rules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            product_name VARCHAR(255) NOT NULL DEFAULT '',
            sku VARCHAR(100) NOT NULL DEFAULT '',
            min_stock DECIMAL(10,2) NOT NULL DEFAULT 0,
            max_stock DECIMAL(10,2) NOT NULL DEFAULT 0,
            supplier_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            supplier_name VARCHAR(255) NOT NULL DEFAULT '',
            unit_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
            lead_time_days INT NOT NULL DEFAULT 7,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_triggered DATETIME DEFAULT NULL,
            last_po_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_product_id (product_id),
            KEY idx_is_active (is_active)
        ) $charset_collate;";

        $sql_log = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wbi_reorder_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rule_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            current_stock DECIMAL(10,2) NOT NULL DEFAULT 0,
            min_stock DECIMAL(10,2) NOT NULL DEFAULT 0,
            quantity_to_order DECIMAL(10,2) NOT NULL DEFAULT 0,
            action_taken VARCHAR(50) NOT NULL DEFAULT '',
            po_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_rule_id (rule_id),
            KEY idx_product_id (product_id),
            KEY idx_created_at (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_rules );
        dbDelta( $sql_log );

        update_option( 'wbi_reorder_db_version', '1.0' );
    }

    // -------------------------------------------------------------------------
    // WP Cron
    // -------------------------------------------------------------------------

    public function maybe_schedule_cron() {
        if ( ! wp_next_scheduled( 'wbi_reorder_check' ) ) {
            wp_schedule_event( time(), 'twicedaily', 'wbi_reorder_check' );
        }
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled( 'wbi_reorder_check' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'wbi_reorder_check' );
        }
    }

    // -------------------------------------------------------------------------
    // WooCommerce Stock Change Hook
    // -------------------------------------------------------------------------

    /**
     * Triggered immediately when product stock is updated.
     *
     * @param WC_Product $product
     */
    public function on_stock_change( $product ) {
        if ( ! $product instanceof WC_Product ) {
            return;
        }

        $product_id    = $product->get_id();
        $current_stock = (float) $product->get_stock_quantity();

        global $wpdb;
        $rule = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wbi_reorder_rules WHERE product_id = %d AND is_active = 1 LIMIT 1",
            $product_id
        ) );

        if ( ! $rule ) {
            return;
        }

        if ( $current_stock <= (float) $rule->min_stock ) {
            $this->process_rule( $rule, $current_stock );
        }
    }

    // -------------------------------------------------------------------------
    // Cron: Check All Rules
    // -------------------------------------------------------------------------

    /**
     * Main cron callback: check all active reorder rules.
     * Smart PO grouping: multiple products from the same supplier → one PO.
     */
    public function check_reorder_rules() {
        global $wpdb;

        $rules = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}wbi_reorder_rules WHERE is_active = 1"
        );

        if ( empty( $rules ) ) {
            return;
        }

        // Group rules by supplier for smart PO batching
        $by_supplier = array();

        foreach ( $rules as $rule ) {
            $current_stock = (float) get_post_meta( $rule->product_id, '_stock', true );

            if ( $current_stock > (float) $rule->min_stock ) {
                continue; // Stock is fine — skip
            }

            $supplier_key = intval( $rule->supplier_id ) > 0 ? intval( $rule->supplier_id ) : 0;
            if ( ! isset( $by_supplier[ $supplier_key ] ) ) {
                $by_supplier[ $supplier_key ] = array();
            }
            $by_supplier[ $supplier_key ][] = array(
                'rule'          => $rule,
                'current_stock' => $current_stock,
            );
        }

        foreach ( $by_supplier as $supplier_id => $items ) {
            $this->process_supplier_group( $supplier_id, $items );
        }
    }

    /**
     * Process a group of rules for the same supplier.
     * If Purchase module is active and supplier_id > 0: create one grouped PO.
     * Else if Notifications module is active: send notification per item.
     * Else: send email to admin.
     */
    private function process_supplier_group( $supplier_id, array $items ) {
        $purchase_active      = class_exists( 'WBI_Purchase_Module' );
        $notifications_active = class_exists( 'WBI_Notifications_Module' );

        if ( $purchase_active && $supplier_id > 0 ) {
            $this->create_grouped_po( $supplier_id, $items );
        } elseif ( $notifications_active ) {
            foreach ( $items as $item ) {
                $this->process_rule_notification( $item['rule'], $item['current_stock'] );
            }
        } else {
            $this->send_reorder_email( $items );
            foreach ( $items as $item ) {
                $this->log_reorder_event( $item['rule'], $item['current_stock'], 'email_sent', 0 );
                $this->update_rule_triggered( $item['rule']->id, 0 );
            }
        }
    }

    /**
     * Process a single rule immediately (triggered by stock change hook).
     */
    private function process_rule( $rule, $current_stock ) {
        $purchase_active      = class_exists( 'WBI_Purchase_Module' );
        $notifications_active = class_exists( 'WBI_Notifications_Module' );

        if ( $purchase_active && intval( $rule->supplier_id ) > 0 ) {
            $this->create_grouped_po( intval( $rule->supplier_id ), array(
                array( 'rule' => $rule, 'current_stock' => $current_stock ),
            ) );
        } elseif ( $notifications_active ) {
            $this->process_rule_notification( $rule, $current_stock );
        } else {
            $this->send_reorder_email( array(
                array( 'rule' => $rule, 'current_stock' => $current_stock ),
            ) );
            $this->log_reorder_event( $rule, $current_stock, 'email_sent', 0 );
            $this->update_rule_triggered( $rule->id, 0 );
        }
    }

    /**
     * Create a draft Purchase Order (or multiple line items in one PO) via WBI_Purchase_Module.
     */
    private function create_grouped_po( $supplier_id, array $items ) {
        if ( ! class_exists( 'WBI_Purchase_Module' ) ) {
            return;
        }

        global $wpdb;

        // Build line items array
        $line_items = array();
        foreach ( $items as $item ) {
            $rule           = $item['rule'];
            $current_stock  = $item['current_stock'];
            $qty_to_order   = max( 0, (float) $rule->max_stock - $current_stock );

            if ( $qty_to_order <= 0 ) {
                continue;
            }

            $line_items[] = array(
                'product_id'   => intval( $rule->product_id ),
                'product_name' => $rule->product_name,
                'sku'          => $rule->sku,
                'quantity'     => $qty_to_order,
                'unit_cost'    => (float) $rule->unit_cost,
            );
        }

        if ( empty( $line_items ) ) {
            return;
        }

        // Call the Purchase module to create a draft PO
        $po_id = 0;
        if ( method_exists( 'WBI_Purchase_Module', 'create_draft_po' ) ) {
            $po_id = WBI_Purchase_Module::create_draft_po( $supplier_id, $line_items );
        }

        // Log each rule
        foreach ( $items as $item ) {
            $rule          = $item['rule'];
            $current_stock = $item['current_stock'];
            $qty_to_order  = max( 0, (float) $rule->max_stock - $current_stock );

            $this->log_reorder_event( $rule, $current_stock, 'po_created', $po_id );
            $this->update_rule_triggered( $rule->id, $po_id );
        }
    }

    /**
     * Create a notification via WBI_Notifications_Module.
     */
    private function process_rule_notification( $rule, $current_stock ) {
        if ( ! class_exists( 'WBI_Notifications_Module' ) ) {
            return;
        }

        $message = sprintf(
            'Reabastecimiento necesario: %s (stock: %s, mínimo: %s)',
            $rule->product_name,
            number_format( $current_stock, 2 ),
            number_format( (float) $rule->min_stock, 2 )
        );

        // WBI_Notifications_Module::add() if it exists, otherwise fallback
        if ( method_exists( 'WBI_Notifications_Module', 'add' ) ) {
            WBI_Notifications_Module::add( 'warning', $message, admin_url( 'admin.php?page=wbi-reorder' ) );
        }

        $this->log_reorder_event( $rule, $current_stock, 'notification_sent', 0 );
        $this->update_rule_triggered( $rule->id, 0 );
    }

    /**
     * Send email to admin listing products that need reorder.
     *
     * @param array $items Array of [ 'rule' => $rule, 'current_stock' => float ]
     */
    private function send_reorder_email( array $items ) {
        $admin_email = get_option( 'admin_email' );
        $site_name   = get_bloginfo( 'name' );

        $subject = sprintf( '[%s] Reabastecimiento necesario — %d producto(s)', $site_name, count( $items ) );

        $body = "Los siguientes productos están por debajo del punto de reorden:\n\n";
        foreach ( $items as $item ) {
            $rule          = $item['rule'];
            $current_stock = $item['current_stock'];
            $qty_to_order  = max( 0, (float) $rule->max_stock - $current_stock );

            $body .= sprintf(
                "- %s (SKU: %s)\n  Stock actual: %s | Mínimo: %s | Cantidad a pedir: %s\n\n",
                $rule->product_name,
                $rule->sku ?: 'N/A',
                number_format( $current_stock, 2 ),
                number_format( (float) $rule->min_stock, 2 ),
                number_format( $qty_to_order, 2 )
            );
        }

        $body .= "\nVer reglas: " . admin_url( 'admin.php?page=wbi-reorder' );

        wp_mail( $admin_email, $subject, $body );
    }

    /**
     * Log a reorder event to the wbi_reorder_log table.
     */
    private function log_reorder_event( $rule, $current_stock, $action_taken, $po_id ) {
        global $wpdb;

        $qty_to_order = max( 0, (float) $rule->max_stock - $current_stock );

        $wpdb->insert(
            $wpdb->prefix . 'wbi_reorder_log',
            array(
                'rule_id'          => intval( $rule->id ),
                'product_id'       => intval( $rule->product_id ),
                'current_stock'    => (float) $current_stock,
                'min_stock'        => (float) $rule->min_stock,
                'quantity_to_order'=> $qty_to_order,
                'action_taken'     => sanitize_text_field( $action_taken ),
                'po_id'            => intval( $po_id ),
            ),
            array( '%d', '%d', '%f', '%f', '%f', '%s', '%d' )
        );
    }

    /**
     * Update last_triggered and last_po_id on a rule.
     */
    private function update_rule_triggered( $rule_id, $po_id ) {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'wbi_reorder_rules',
            array(
                'last_triggered' => current_time( 'mysql' ),
                'last_po_id'     => intval( $po_id ),
            ),
            array( 'id' => intval( $rule_id ) ),
            array( '%s', '%d' ),
            array( '%d' )
        );
    }

    // -------------------------------------------------------------------------
    // Admin Submenu
    // -------------------------------------------------------------------------

    public function add_submenu() {
        add_submenu_page(
            'wbi-dashboard-view',
            'Reglas de Reabastecimiento',
            '🔄 Reabastecimiento',
            'manage_options',
            'wbi-reorder',
            array( $this, 'render_admin_page' )
        );
    }

    // -------------------------------------------------------------------------
    // Admin Page Dispatcher
    // -------------------------------------------------------------------------

    public function render_admin_page() {
        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';

        switch ( $action ) {
            case 'new':
                $this->render_rule_form( null );
                break;
            case 'edit':
                $rule_id = isset( $_GET['rule_id'] ) ? absint( $_GET['rule_id'] ) : 0;
                $this->render_rule_form( $rule_id );
                break;
            case 'log':
                $this->render_log_page();
                break;
            default:
                $this->render_rules_list();
                break;
        }
    }

    // -------------------------------------------------------------------------
    // Rules List Page
    // -------------------------------------------------------------------------

    private function render_rules_list() {
        global $wpdb;

        // Handle notices
        $notice = '';
        if ( isset( $_GET['reorder_saved'] ) ) {
            $notice = '<div class="notice notice-success is-dismissible"><p>✅ Regla guardada correctamente.</p></div>';
        }
        if ( isset( $_GET['reorder_deleted'] ) ) {
            $notice = '<div class="notice notice-success is-dismissible"><p>🗑️ Regla eliminada.</p></div>';
        }
        if ( isset( $_GET['reorder_triggered'] ) ) {
            $notice = '<div class="notice notice-success is-dismissible"><p>▶️ Reabastecimiento ejecutado. Revisá el log para ver los resultados.</p></div>';
        }

        // Filters
        $filter_status  = isset( $_GET['filter_status'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_status'] ) ) : '';
        $filter_reorder = isset( $_GET['filter_reorder'] ) ? 1 : 0;

        // Query rules
        if ( $filter_status === 'active' ) {
            $rules = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wbi_reorder_rules WHERE is_active = 1 ORDER BY id DESC" );
        } elseif ( $filter_status === 'paused' ) {
            $rules = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wbi_reorder_rules WHERE is_active = 0 ORDER BY id DESC" );
        } else {
            $rules = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wbi_reorder_rules ORDER BY id DESC" );
        }

        // Enrich with live stock and apply "needs reorder" filter
        $enriched = array();
        foreach ( $rules as $rule ) {
            $current_stock = (float) get_post_meta( $rule->product_id, '_stock', true );
            $needs_reorder = $current_stock <= (float) $rule->min_stock;

            if ( $filter_reorder && ! $needs_reorder ) {
                continue;
            }

            $rule->current_stock = $current_stock;
            $rule->needs_reorder = $needs_reorder;
            $enriched[]          = $rule;
        }

        $base_url = admin_url( 'admin.php?page=wbi-reorder' );
        $new_url  = add_query_arg( 'action', 'new', $base_url );
        $log_url  = add_query_arg( 'action', 'log', $base_url );

        $run_url  = wp_nonce_url( admin_url( 'admin-post.php?action=wbi_reorder_bulk&bulk_action=run_now' ), 'wbi_reorder_bulk' );
        ?>
        <div class="wrap">
            <h1 style="display:flex; align-items:center; gap:12px;">
                🔄 Reglas de Reabastecimiento
                <a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action">+ Agregar Regla</a>
                <a href="<?php echo esc_url( $log_url ); ?>" class="page-title-action">Ver Log</a>
            </h1>

            <?php echo wp_kses_post( $notice ); ?>

            <?php
            // Summary bar
            $total_active    = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wbi_reorder_rules WHERE is_active = 1" );
            $total_needs     = 0;
            foreach ( $enriched as $r ) {
                if ( $r->needs_reorder ) $total_needs++;
            }
            if ( ! $filter_reorder ) {
                // Recalculate across all rules
                $all_rules = $wpdb->get_results( "SELECT product_id, min_stock FROM {$wpdb->prefix}wbi_reorder_rules WHERE is_active = 1" );
                $total_needs = 0;
                foreach ( $all_rules as $r ) {
                    $cs = (float) get_post_meta( $r->product_id, '_stock', true );
                    if ( $cs <= (float) $r->min_stock ) $total_needs++;
                }
            }
            ?>
            <div style="display:flex; gap:16px; margin:16px 0; flex-wrap:wrap;">
                <div style="background:#fff; border:1px solid #c3c4c7; border-radius:6px; padding:12px 20px; min-width:160px;">
                    <div style="font-size:24px; font-weight:bold; color:#1d2327;"><?php echo intval( $total_active ); ?></div>
                    <div style="font-size:12px; color:#50575e;">Reglas activas</div>
                </div>
                <?php if ( $total_needs > 0 ) : ?>
                <div style="background:#fff3cd; border:1px solid #ffc107; border-radius:6px; padding:12px 20px; min-width:160px;">
                    <div style="font-size:24px; font-weight:bold; color:#856404;">⚠️ <?php echo intval( $total_needs ); ?></div>
                    <div style="font-size:12px; color:#856404;">Requieren reabastecimiento</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Filters & Run Now -->
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:16px; flex-wrap:wrap;">
                <form method="get" style="display:inline-flex; gap:8px; align-items:center;">
                    <input type="hidden" name="page" value="wbi-reorder">
                    <select name="filter_status" onchange="this.form.submit()">
                        <option value="" <?php selected( $filter_status, '' ); ?>>Todos los estados</option>
                        <option value="active" <?php selected( $filter_status, 'active' ); ?>>Activas</option>
                        <option value="paused" <?php selected( $filter_status, 'paused' ); ?>>Pausadas</option>
                    </select>
                    <label style="display:inline-flex; align-items:center; gap:4px;">
                        <input type="checkbox" name="filter_reorder" value="1" <?php checked( $filter_reorder, 1 ); ?> onchange="this.form.submit()">
                        Solo con stock bajo
                    </label>
                </form>

                <a href="<?php echo esc_url( $run_url ); ?>"
                   class="button button-primary"
                   onclick="return confirm('¿Ejecutar verificación de reabastecimiento ahora?');">
                    ▶ Ejecutar Reabastecimiento Ahora
                </a>
            </div>

            <!-- Bulk Actions Form -->
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="wbi_reorder_bulk">
                <?php wp_nonce_field( 'wbi_reorder_bulk', '_wpnonce' ); ?>

                <div style="margin-bottom:8px; display:flex; gap:8px; align-items:center;">
                    <select name="bulk_action">
                        <option value="">— Acción masiva —</option>
                        <option value="activate">Activar seleccionados</option>
                        <option value="deactivate">Pausar seleccionados</option>
                        <option value="delete">Eliminar seleccionados</option>
                    </select>
                    <button type="submit" class="button">Aplicar</button>
                </div>

                <?php if ( empty( $enriched ) ) : ?>
                    <div class="notice notice-info inline"><p>No hay reglas de reabastecimiento configuradas. <a href="<?php echo esc_url( $new_url ); ?>">Agregar la primera regla →</a></p></div>
                <?php else : ?>
                <table class="widefat striped wbi-sortable" style="margin-top:10px;">
                    <thead>
                        <tr>
                            <th style="width:30px;"><input type="checkbox" id="wbi-select-all" onclick="document.querySelectorAll('.wbi-rule-cb').forEach(cb=>cb.checked=this.checked)"></th>
                            <th>Producto</th>
                            <th>SKU</th>
                            <th>Stock Actual</th>
                            <th>Mín</th>
                            <th>Máx</th>
                            <th>Proveedor</th>
                            <th>Estado</th>
                            <th>Último Trigger</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $enriched as $rule ) :
                            $row_style = $rule->needs_reorder ? 'background:#fff3cd;' : '';
                            $edit_url  = add_query_arg( array( 'action' => 'edit', 'rule_id' => $rule->id ), $base_url );
                        ?>
                        <tr style="<?php echo esc_attr( $row_style ); ?>">
                            <td><input type="checkbox" class="wbi-rule-cb" name="rule_ids[]" value="<?php echo intval( $rule->id ); ?>"></td>
                            <td>
                                <?php if ( $rule->needs_reorder ) echo '⚠️ '; ?>
                                <strong><?php echo esc_html( $rule->product_name ); ?></strong>
                                <div><a href="<?php echo esc_url( get_edit_post_link( $rule->product_id ) ); ?>" style="font-size:11px; color:#50575e;">Ver producto</a></div>
                            </td>
                            <td><?php echo esc_html( $rule->sku ?: '—' ); ?></td>
                            <td>
                                <span style="font-weight:bold; color:<?php echo $rule->needs_reorder ? '#d63638' : '#0a3622'; ?>;">
                                    <?php echo number_format( $rule->current_stock, 2 ); ?>
                                </span>
                            </td>
                            <td><?php echo number_format( (float) $rule->min_stock, 2 ); ?></td>
                            <td><?php echo number_format( (float) $rule->max_stock, 2 ); ?></td>
                            <td><?php echo esc_html( $rule->supplier_name ?: '—' ); ?></td>
                            <td>
                                <?php if ( $rule->is_active ) : ?>
                                    <span style="color:#0a3622; background:#d1e7dd; padding:2px 8px; border-radius:4px; font-size:12px;">Activa</span>
                                <?php else : ?>
                                    <span style="color:#664d03; background:#fff3cd; padding:2px 8px; border-radius:4px; font-size:12px;">Pausada</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px; color:#50575e;">
                                <?php echo $rule->last_triggered ? esc_html( $rule->last_triggered ) : '—'; ?>
                                <?php if ( $rule->last_po_id > 0 ) : ?>
                                    <br><small>OC #<?php echo intval( $rule->last_po_id ); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">Editar</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Add/Edit Rule Form
    // -------------------------------------------------------------------------

    private function render_rule_form( $rule_id ) {
        global $wpdb;

        $rule = null;
        if ( $rule_id ) {
            $rule = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wbi_reorder_rules WHERE id = %d",
                $rule_id
            ) );
        }

        $is_edit  = ( $rule !== null );
        $title    = $is_edit ? 'Editar Regla de Reabastecimiento' : 'Agregar Regla de Reabastecimiento';
        $back_url = admin_url( 'admin.php?page=wbi-reorder' );

        // Get suppliers list if Suppliers module is active
        $suppliers = array();
        if ( class_exists( 'WBI_Suppliers_Module' ) ) {
            $supplier_posts = get_posts( array(
                'post_type'      => 'wbi_supplier',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'post_status'    => 'publish',
            ) );
            foreach ( $supplier_posts as $sp ) {
                $suppliers[] = array( 'id' => $sp->ID, 'name' => $sp->post_title );
            }
        }

        // Default values
        $product_id    = $rule ? intval( $rule->product_id )    : 0;
        $product_name  = $rule ? esc_attr( $rule->product_name ): '';
        $sku           = $rule ? esc_attr( $rule->sku )         : '';
        $min_stock     = $rule ? (float) $rule->min_stock       : 0;
        $max_stock     = $rule ? (float) $rule->max_stock       : 0;
        $supplier_id   = $rule ? intval( $rule->supplier_id )   : 0;
        $supplier_name = $rule ? esc_attr( $rule->supplier_name): '';
        $unit_cost     = $rule ? (float) $rule->unit_cost       : 0;
        $lead_time     = $rule ? intval( $rule->lead_time_days ): 7;
        $is_active     = $rule ? intval( $rule->is_active )     : 1;

        $nonce = wp_create_nonce( 'wbi_reorder_save_rule' );
        ?>
        <div class="wrap">
            <h1>
                <?php echo esc_html( $title ); ?>
                <a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">← Volver</a>
            </h1>

            <div style="max-width:700px; background:#fff; border:1px solid #c3c4c7; border-radius:6px; padding:24px; margin-top:16px;">

                <table class="form-table">
                    <tr>
                        <th><label for="wbi_product_search">Producto *</label></th>
                        <td>
                            <input type="text" id="wbi_product_search"
                                   placeholder="Buscar producto por nombre o SKU..."
                                   value="<?php echo $product_id ? esc_attr( $product_name . ( $sku ? ' [' . $sku . ']' : '' ) ) : ''; ?>"
                                   style="width:100%; max-width:400px;"
                                   autocomplete="off">
                            <input type="hidden" id="wbi_product_id" name="product_id" value="<?php echo intval( $product_id ); ?>">
                            <div id="wbi_product_results" style="position:absolute; z-index:9999; background:#fff; border:1px solid #c3c4c7; border-radius:4px; max-width:400px; display:none; max-height:200px; overflow-y:auto;"></div>
                            <p class="description" id="wbi_current_stock_info" style="margin-top:4px; color:#50575e;">
                                <?php if ( $product_id ) :
                                    $cs = (float) get_post_meta( $product_id, '_stock', true );
                                    echo 'Stock actual: <strong>' . number_format( $cs, 2 ) . '</strong>';
                                endif; ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wbi_min_stock">Stock Mínimo (punto de reorden) *</label></th>
                        <td>
                            <input type="number" id="wbi_min_stock" name="min_stock"
                                   value="<?php echo esc_attr( $min_stock ); ?>"
                                   min="0" step="0.01" style="width:120px;" required>
                            <p class="description">Cuando el stock cae a este nivel o por debajo, se activa el reabastecimiento.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wbi_max_stock">Stock Máximo (nivel objetivo) *</label></th>
                        <td>
                            <input type="number" id="wbi_max_stock" name="max_stock"
                                   value="<?php echo esc_attr( $max_stock ); ?>"
                                   min="0" step="0.01" style="width:120px;" required>
                            <p class="description">Cantidad a pedir = Stock Máximo − Stock Actual. Debe ser mayor que el Stock Mínimo.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wbi_supplier">Proveedor</label></th>
                        <td>
                            <?php if ( ! empty( $suppliers ) ) : ?>
                                <select id="wbi_supplier_id" name="supplier_id" style="width:100%; max-width:300px;">
                                    <option value="0">— Sin proveedor preferido —</option>
                                    <?php foreach ( $suppliers as $sup ) : ?>
                                        <option value="<?php echo intval( $sup['id'] ); ?>"
                                            <?php selected( $supplier_id, $sup['id'] ); ?>
                                            data-name="<?php echo esc_attr( $sup['name'] ); ?>">
                                            <?php echo esc_html( $sup['name'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="supplier_name" id="wbi_supplier_name" value="<?php echo esc_attr( $supplier_name ); ?>">
                            <?php else : ?>
                                <input type="text" name="supplier_name" id="wbi_supplier_name"
                                       value="<?php echo esc_attr( $supplier_name ); ?>"
                                       placeholder="Nombre del proveedor"
                                       style="width:100%; max-width:300px;">
                                <input type="hidden" name="supplier_id" value="<?php echo intval( $supplier_id ); ?>">
                                <p class="description">Activá el módulo de Proveedores para usar el selector.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wbi_unit_cost">Precio de Costo Unitario</label></th>
                        <td>
                            <input type="number" id="wbi_unit_cost" name="unit_cost"
                                   value="<?php echo esc_attr( $unit_cost ); ?>"
                                   min="0" step="0.01" style="width:120px;">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wbi_lead_time">Plazo de Entrega (días)</label></th>
                        <td>
                            <input type="number" id="wbi_lead_time" name="lead_time_days"
                                   value="<?php echo intval( $lead_time ); ?>"
                                   min="0" step="1" style="width:80px;">
                        </td>
                    </tr>
                    <tr>
                        <th>Estado</th>
                        <td>
                            <label style="display:inline-flex; align-items:center; gap:8px;">
                                <input type="checkbox" name="is_active" value="1" <?php checked( $is_active, 1 ); ?>>
                                Regla activa
                            </label>
                        </td>
                    </tr>
                </table>

                <div id="wbi_reorder_form_msg" style="margin-top:12px; display:none;"></div>

                <p style="margin-top:16px;">
                    <button type="button" id="wbi_reorder_save_btn" class="button button-primary" style="min-width:120px;">
                        <?php echo $is_edit ? 'Actualizar Regla' : 'Guardar Regla'; ?>
                    </button>
                    <a href="<?php echo esc_url( $back_url ); ?>" class="button" style="margin-left:8px;">Cancelar</a>
                    <?php if ( $is_edit ) : ?>
                        <button type="button" id="wbi_reorder_delete_btn" class="button"
                                style="margin-left:16px; color:#d63638; border-color:#d63638;"
                                data-rule-id="<?php echo intval( $rule_id ); ?>">
                            Eliminar Regla
                        </button>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <script>
        (function($) {
            var ajaxUrl  = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
            var nonce    = '<?php echo esc_js( $nonce ); ?>';
            var ruleId   = <?php echo $is_edit ? intval( $rule_id ) : 0; ?>;
            var backUrl  = '<?php echo esc_url( $back_url ); ?>';

            // --- Product autocomplete ---
            var searchTimeout;
            $('#wbi_product_search').on('input', function() {
                var q = $(this).val().trim();
                if (q.length < 2) { $('#wbi_product_results').hide(); return; }
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    $.post(ajaxUrl, { action:'wbi_reorder_search_products', q:q, nonce:nonce }, function(res) {
                        if (res.success && res.data.length) {
                            var html = '';
                            res.data.forEach(function(p) {
                                html += '<div class="wbi-prod-item" data-id="'+p.id+'" data-name="'+escHtml(p.name)+'" data-sku="'+escHtml(p.sku)+'" data-stock="'+p.stock+'" style="padding:8px 12px; cursor:pointer; border-bottom:1px solid #f0f0f1; font-size:13px;">';
                                html += '<strong>'+escHtml(p.name)+'</strong>';
                                if (p.sku) html += ' <span style="color:#50575e;">['+escHtml(p.sku)+']</span>';
                                html += ' — Stock: '+p.stock;
                                html += '</div>';
                            });
                            $('#wbi_product_results').html(html).show();
                        } else {
                            $('#wbi_product_results').html('<div style="padding:8px 12px; color:#50575e; font-size:13px;">No se encontraron productos.</div>').show();
                        }
                    });
                }, 300);
            });

            $(document).on('click', '.wbi-prod-item', function() {
                var id    = $(this).data('id');
                var name  = $(this).data('name');
                var sku   = $(this).data('sku');
                var stock = $(this).data('stock');
                $('#wbi_product_id').val(id);
                $('#wbi_product_search').val(name + (sku ? ' [' + sku + ']' : ''));
                $('#wbi_current_stock_info').html('Stock actual: <strong>' + stock + '</strong>');
                $('#wbi_product_results').hide();
            });

            $(document).on('click', function(e) {
                if (!$(e.target).closest('#wbi_product_search, #wbi_product_results').length) {
                    $('#wbi_product_results').hide();
                }
            });

            // --- Supplier name sync ---
            $('#wbi_supplier_id').on('change', function() {
                var name = $(this).find('option:selected').data('name') || '';
                $('#wbi_supplier_name').val(name);
            });

            // --- Save Rule ---
            $('#wbi_reorder_save_btn').on('click', function() {
                var productId = $('#wbi_product_id').val();
                if (!productId || productId == '0') {
                    showMsg('error', 'Por favor seleccioná un producto.');
                    return;
                }
                var minStock = parseFloat($('#wbi_min_stock').val());
                var maxStock = parseFloat($('#wbi_max_stock').val());
                if (isNaN(minStock) || isNaN(maxStock)) {
                    showMsg('error', 'Ingresá valores válidos para Stock Mínimo y Máximo.');
                    return;
                }
                if (maxStock <= minStock) {
                    showMsg('error', 'El Stock Máximo debe ser mayor que el Stock Mínimo.');
                    return;
                }

                var data = {
                    action:        'wbi_reorder_save_rule',
                    nonce:         nonce,
                    rule_id:       ruleId,
                    product_id:    productId,
                    min_stock:     minStock,
                    max_stock:     maxStock,
                    supplier_id:   $('[name="supplier_id"]').val() || 0,
                    supplier_name: $('#wbi_supplier_name').val(),
                    unit_cost:     $('#wbi_unit_cost').val(),
                    lead_time_days:$('#wbi_lead_time').val(),
                    is_active:     $('[name="is_active"]').is(':checked') ? 1 : 0,
                };

                $.post(ajaxUrl, data, function(res) {
                    if (res.success) {
                        window.location = backUrl + '&reorder_saved=1';
                    } else {
                        showMsg('error', res.data || 'Error al guardar.');
                    }
                });
            });

            // --- Delete Rule ---
            $('#wbi_reorder_delete_btn').on('click', function() {
                if (!confirm('¿Eliminar esta regla? Esta acción no se puede deshacer.')) return;
                $.post(ajaxUrl, { action:'wbi_reorder_delete_rule', nonce:nonce, rule_id:ruleId }, function(res) {
                    if (res.success) {
                        window.location = backUrl + '&reorder_deleted=1';
                    } else {
                        showMsg('error', res.data || 'Error al eliminar.');
                    }
                });
            });

            function showMsg(type, msg) {
                var cls = type === 'error' ? 'notice-error' : 'notice-success';
                $('#wbi_reorder_form_msg').attr('class', 'notice ' + cls + ' inline').html('<p>' + msg + '</p>').show();
            }

            function escHtml(str) {
                return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }
        })(jQuery);
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // Reorder Log Page
    // -------------------------------------------------------------------------

    private function render_log_page() {
        global $wpdb;

        $back_url = admin_url( 'admin.php?page=wbi-reorder' );

        // Filters
        $filter_action  = isset( $_GET['filter_action'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_action'] ) ) : '';
        $filter_product = isset( $_GET['filter_product'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_product'] ) ) : '';
        $date_from      = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
        $date_to        = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';

        $where  = '1=1';
        $params = array();

        if ( $filter_action ) {
            $where   .= ' AND l.action_taken = %s';
            $params[] = $filter_action;
        }
        if ( $filter_product ) {
            $where   .= ' AND r.product_name LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $filter_product ) . '%';
        }
        if ( $date_from ) {
            $where   .= ' AND l.created_at >= %s';
            $params[] = $date_from . ' 00:00:00';
        }
        if ( $date_to ) {
            $where   .= ' AND l.created_at <= %s';
            $params[] = $date_to . ' 23:59:59';
        }

        $sql = "SELECT l.*, r.product_name, r.sku
                FROM {$wpdb->prefix}wbi_reorder_log l
                LEFT JOIN {$wpdb->prefix}wbi_reorder_rules r ON r.id = l.rule_id
                WHERE $where
                ORDER BY l.created_at DESC
                LIMIT 200";

        if ( ! empty( $params ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $logs = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $logs = $wpdb->get_results( $sql );
        }

        $action_labels = array(
            'po_created'        => '📦 OC Creada',
            'notification_sent' => '🔔 Notificación',
            'email_sent'        => '📧 Email enviado',
            'skipped'           => '⏭ Saltado',
        );
        ?>
        <div class="wrap">
            <h1>
                Log de Reabastecimiento
                <a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">← Volver</a>
            </h1>

            <!-- Filters -->
            <form method="get" style="margin-bottom:16px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <input type="hidden" name="page" value="wbi-reorder">
                <input type="hidden" name="action" value="log">
                <input type="text" name="filter_product" value="<?php echo esc_attr( $filter_product ); ?>" placeholder="Filtrar por producto" style="width:200px;">
                <select name="filter_action">
                    <option value="">Todas las acciones</option>
                    <?php foreach ( $action_labels as $val => $label ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $filter_action, $val ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Desde: <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>"></label>
                <label>Hasta: <input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>"></label>
                <button type="submit" class="button">Filtrar</button>
                <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'wbi-reorder', 'action' => 'log' ), admin_url( 'admin.php' ) ) ); ?>" class="button">Limpiar</a>
            </form>

            <?php if ( empty( $logs ) ) : ?>
                <div class="notice notice-info inline"><p>No hay eventos en el log.</p></div>
            <?php else : ?>
            <table class="widefat striped wbi-sortable">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Producto</th>
                        <th>Stock al momento</th>
                        <th>Stock Mín</th>
                        <th>Qty a pedir</th>
                        <th>Acción</th>
                        <th>OC #</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $logs as $log ) : ?>
                    <tr>
                        <td style="white-space:nowrap; font-size:12px;"><?php echo esc_html( $log->created_at ); ?></td>
                        <td>
                            <strong><?php echo esc_html( $log->product_name ?: '(producto eliminado)' ); ?></strong>
                            <?php if ( $log->sku ) : ?>
                                <br><small style="color:#50575e;"><?php echo esc_html( $log->sku ); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo number_format( (float) $log->current_stock, 2 ); ?></td>
                        <td><?php echo number_format( (float) $log->min_stock, 2 ); ?></td>
                        <td><?php echo number_format( (float) $log->quantity_to_order, 2 ); ?></td>
                        <td><?php echo esc_html( $action_labels[ $log->action_taken ] ?? $log->action_taken ); ?></td>
                        <td>
                            <?php if ( $log->po_id > 0 ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-purchase&action=view&po_id=' . intval( $log->po_id ) ) ); ?>">
                                    #<?php echo intval( $log->po_id ); ?>
                                </a>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Bulk Action Handler (admin-post)
    // -------------------------------------------------------------------------

    public function handle_bulk_action() {
        check_admin_referer( 'wbi_reorder_bulk' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Sin permisos.' );
        }

        $bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_action'] ) ) : '';

        // Run Now (GET link)
        if ( isset( $_GET['bulk_action'] ) && $_GET['bulk_action'] === 'run_now' ) {
            $this->check_reorder_rules();
            wp_safe_redirect( admin_url( 'admin.php?page=wbi-reorder&reorder_triggered=1' ) );
            exit;
        }

        $rule_ids = isset( $_POST['rule_ids'] ) ? array_map( 'absint', (array) $_POST['rule_ids'] ) : array();

        global $wpdb;

        if ( $bulk_action === 'activate' && ! empty( $rule_ids ) ) {
            foreach ( $rule_ids as $id ) {
                $wpdb->update(
                    $wpdb->prefix . 'wbi_reorder_rules',
                    array( 'is_active' => 1 ),
                    array( 'id' => $id ),
                    array( '%d' ),
                    array( '%d' )
                );
            }
        } elseif ( $bulk_action === 'deactivate' && ! empty( $rule_ids ) ) {
            foreach ( $rule_ids as $id ) {
                $wpdb->update(
                    $wpdb->prefix . 'wbi_reorder_rules',
                    array( 'is_active' => 0 ),
                    array( 'id' => $id ),
                    array( '%d' ),
                    array( '%d' )
                );
            }
        } elseif ( $bulk_action === 'delete' && ! empty( $rule_ids ) ) {
            foreach ( $rule_ids as $id ) {
                $wpdb->delete(
                    $wpdb->prefix . 'wbi_reorder_rules',
                    array( 'id' => $id ),
                    array( '%d' )
                );
            }
        }

        wp_safe_redirect( admin_url( 'admin.php?page=wbi-reorder' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // AJAX: Product Search
    // -------------------------------------------------------------------------

    public function ajax_search_products() {
        check_ajax_referer( 'wbi_reorder_save_rule', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Sin permisos.' );
        }

        $q = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';

        if ( strlen( $q ) < 2 ) {
            wp_send_json_success( array() );
        }

        global $wpdb;

        $like = '%' . $wpdb->esc_like( $q ) . '%';

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title,
                    MAX(CASE WHEN pm.meta_key = '_sku' THEN pm.meta_value END) AS sku,
                    MAX(CASE WHEN pm.meta_key = '_stock' THEN pm.meta_value END) AS stock
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN ('_sku', '_stock')
             WHERE p.post_type = 'product'
               AND p.post_status = 'publish'
               AND (p.post_title LIKE %s OR EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} pm2
                   WHERE pm2.post_id = p.ID AND pm2.meta_key = '_sku' AND pm2.meta_value LIKE %s
               ))
             GROUP BY p.ID
             ORDER BY p.post_title ASC
             LIMIT 20",
            $like,
            $like
        ) );

        $data = array();
        foreach ( $results as $row ) {
            $data[] = array(
                'id'    => intval( $row->ID ),
                'name'  => $row->post_title,
                'sku'   => $row->sku ?: '',
                'stock' => $row->stock !== null ? number_format( (float) $row->stock, 2 ) : '0.00',
            );
        }

        wp_send_json_success( $data );
    }

    // -------------------------------------------------------------------------
    // AJAX: Save Rule
    // -------------------------------------------------------------------------

    public function ajax_save_rule() {
        check_ajax_referer( 'wbi_reorder_save_rule', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Sin permisos.' );
        }

        $rule_id       = isset( $_POST['rule_id'] )       ? absint( $_POST['rule_id'] )                                        : 0;
        $product_id    = isset( $_POST['product_id'] )    ? absint( $_POST['product_id'] )                                     : 0;
        $min_stock     = isset( $_POST['min_stock'] )     ? (float) sanitize_text_field( wp_unslash( $_POST['min_stock'] ) )    : 0;
        $max_stock     = isset( $_POST['max_stock'] )     ? (float) sanitize_text_field( wp_unslash( $_POST['max_stock'] ) )    : 0;
        $supplier_id   = isset( $_POST['supplier_id'] )   ? absint( $_POST['supplier_id'] )                                    : 0;
        $supplier_name = isset( $_POST['supplier_name'] ) ? sanitize_text_field( wp_unslash( $_POST['supplier_name'] ) )        : '';
        $unit_cost     = isset( $_POST['unit_cost'] )     ? (float) sanitize_text_field( wp_unslash( $_POST['unit_cost'] ) )    : 0;
        $lead_time     = isset( $_POST['lead_time_days'] )? absint( $_POST['lead_time_days'] )                                  : 7;
        $is_active     = isset( $_POST['is_active'] )     ? absint( $_POST['is_active'] )                                      : 0;

        if ( ! $product_id ) {
            wp_send_json_error( 'Seleccioná un producto.' );
        }
        if ( $max_stock <= $min_stock ) {
            wp_send_json_error( 'El Stock Máximo debe ser mayor que el Stock Mínimo.' );
        }

        // Get product name and SKU
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_send_json_error( 'Producto no encontrado.' );
        }

        $product_name = $product->get_name();
        $sku          = $product->get_sku();

        global $wpdb;

        $data = array(
            'product_id'    => $product_id,
            'product_name'  => $product_name,
            'sku'           => $sku,
            'min_stock'     => $min_stock,
            'max_stock'     => $max_stock,
            'supplier_id'   => $supplier_id,
            'supplier_name' => $supplier_name,
            'unit_cost'     => $unit_cost,
            'lead_time_days'=> $lead_time,
            'is_active'     => $is_active,
        );

        $formats = array( '%d', '%s', '%s', '%f', '%f', '%d', '%s', '%f', '%d', '%d' );

        if ( $rule_id ) {
            $wpdb->update(
                $wpdb->prefix . 'wbi_reorder_rules',
                $data,
                array( 'id' => $rule_id ),
                $formats,
                array( '%d' )
            );
        } else {
            $wpdb->insert( $wpdb->prefix . 'wbi_reorder_rules', $data, $formats );
        }

        wp_send_json_success( array( 'rule_id' => $rule_id ?: $wpdb->insert_id ) );
    }

    // -------------------------------------------------------------------------
    // AJAX: Delete Rule
    // -------------------------------------------------------------------------

    public function ajax_delete_rule() {
        check_ajax_referer( 'wbi_reorder_save_rule', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Sin permisos.' );
        }

        $rule_id = isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : 0;

        if ( ! $rule_id ) {
            wp_send_json_error( 'ID de regla inválido.' );
        }

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'wbi_reorder_rules', array( 'id' => $rule_id ), array( '%d' ) );

        wp_send_json_success();
    }

    // -------------------------------------------------------------------------
    // AJAX: Toggle Rule
    // -------------------------------------------------------------------------

    public function ajax_toggle_rule() {
        check_ajax_referer( 'wbi_reorder_save_rule', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Sin permisos.' );
        }

        $rule_id = isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : 0;

        if ( ! $rule_id ) {
            wp_send_json_error( 'ID inválido.' );
        }

        global $wpdb;

        $rule = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, is_active FROM {$wpdb->prefix}wbi_reorder_rules WHERE id = %d",
            $rule_id
        ) );

        if ( ! $rule ) {
            wp_send_json_error( 'Regla no encontrada.' );
        }

        $new_status = $rule->is_active ? 0 : 1;

        $wpdb->update(
            $wpdb->prefix . 'wbi_reorder_rules',
            array( 'is_active' => $new_status ),
            array( 'id' => $rule_id ),
            array( '%d' ),
            array( '%d' )
        );

        wp_send_json_success( array( 'is_active' => $new_status ) );
    }

    // -------------------------------------------------------------------------
    // AJAX: Run Now
    // -------------------------------------------------------------------------

    public function ajax_run_now() {
        check_ajax_referer( 'wbi_reorder_save_rule', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Sin permisos.' );
        }

        $this->check_reorder_rules();

        wp_send_json_success( array( 'message' => 'Verificación de reabastecimiento completada.' ) );
    }

    // -------------------------------------------------------------------------
    // Dashboard Widget
    // -------------------------------------------------------------------------

    public function register_dashboard_widget() {
        wp_add_dashboard_widget(
            'wbi_reorder_widget',
            '🔄 Reabastecimiento',
            array( $this, 'render_dashboard_widget' )
        );
    }

    public function render_dashboard_widget() {
        global $wpdb;

        $active_rules = $wpdb->get_results(
            "SELECT product_id, min_stock, product_name FROM {$wpdb->prefix}wbi_reorder_rules WHERE is_active = 1"
        );

        $needs_reorder = array();
        foreach ( $active_rules as $rule ) {
            $cs = (float) get_post_meta( $rule->product_id, '_stock', true );
            if ( $cs <= (float) $rule->min_stock ) {
                $needs_reorder[] = $rule;
            }
        }

        $total_active = count( $active_rules );
        $count_needs  = count( $needs_reorder );

        echo '<p><strong>Reglas activas:</strong> ' . intval( $total_active ) . '</p>';

        if ( $count_needs > 0 ) {
            echo '<p style="color:#d63638; font-weight:bold;">⚠️ ' . intval( $count_needs ) . ' producto(s) por debajo del punto de reorden</p>';
            echo '<ul style="margin:0 0 8px 16px; list-style:disc;">';
            foreach ( array_slice( $needs_reorder, 0, 5 ) as $r ) {
                $cs = (float) get_post_meta( $r->product_id, '_stock', true );
                echo '<li>' . esc_html( $r->product_name ) . ' — Stock: <strong>' . number_format( $cs, 2 ) . '</strong></li>';
            }
            if ( $count_needs > 5 ) {
                echo '<li>... y ' . intval( $count_needs - 5 ) . ' más</li>';
            }
            echo '</ul>';
        } else {
            echo '<p style="color:#0a3622;">✅ Todos los productos tienen stock suficiente.</p>';
        }

        echo '<a href="' . esc_url( admin_url( 'admin.php?page=wbi-reorder' ) ) . '" class="button button-small">Ver reglas →</a>';
    }
}
