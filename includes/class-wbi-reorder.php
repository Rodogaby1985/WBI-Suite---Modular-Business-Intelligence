<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Reorder_Module {

    public function __construct() {
        // Create DB table on init
        add_action( 'init', array( $this, 'maybe_create_table' ) );

        // Admin pages
        add_action( 'admin_menu', array( $this, 'register_page' ), 100 );

        // WP Cron — runs twice daily (approx. every 12 hours)
        add_action( 'wbi_reorder_check', array( $this, 'run_reorder_check' ) );
        if ( ! wp_next_scheduled( 'wbi_reorder_check' ) ) {
            wp_schedule_event( time(), 'twicedaily', 'wbi_reorder_check' );
        }

        // Real-time trigger when stock changes
        add_action( 'woocommerce_product_set_stock', array( $this, 'on_stock_change' ) );

        // AJAX handlers
        add_action( 'wp_ajax_wbi_reorder_search_products', array( $this, 'ajax_search_products' ) );
        add_action( 'wp_ajax_wbi_reorder_save_rule',       array( $this, 'ajax_save_rule' ) );
        add_action( 'wp_ajax_wbi_reorder_delete_rule',     array( $this, 'ajax_delete_rule' ) );
        add_action( 'wp_ajax_wbi_reorder_toggle_active',   array( $this, 'ajax_toggle_active' ) );
        add_action( 'wp_ajax_wbi_reorder_run_now',         array( $this, 'ajax_run_now' ) );
        add_action( 'wp_ajax_wbi_reorder_bulk_action',     array( $this, 'ajax_bulk_action' ) );

        // Inline JS/CSS for admin page
        add_action( 'admin_footer', array( $this, 'admin_footer_scripts' ) );
    }

    // -------------------------------------------------------------------------
    // Database
    // -------------------------------------------------------------------------

    public function maybe_create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . 'wbi_reorder_rules';
        $charset = $wpdb->get_charset_collate();

        // Only create if not already present
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== null ) {
            return;
        }

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            product_name VARCHAR(255) NOT NULL DEFAULT '',
            sku VARCHAR(100) NOT NULL DEFAULT '',
            supplier_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            supplier_name VARCHAR(255) NOT NULL DEFAULT '',
            min_stock DECIMAL(10,2) NOT NULL DEFAULT 0,
            max_stock DECIMAL(10,2) NOT NULL DEFAULT 0,
            reorder_qty DECIMAL(10,2) NOT NULL DEFAULT 0,
            rule_type ENUM('min_max','fixed_qty') NOT NULL DEFAULT 'min_max',
            lead_time_days INT NOT NULL DEFAULT 7,
            unit_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_triggered_at DATETIME DEFAULT NULL,
            last_po_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY is_active (is_active)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // -------------------------------------------------------------------------
    // Admin Menu
    // -------------------------------------------------------------------------

    public function register_page() {
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
    // Admin Page Router
    // -------------------------------------------------------------------------

    public function render_admin_page() {
        if ( ! $this->current_user_can() ) {
            wp_die( esc_html__( 'No tenés permisos para acceder a esta página.', 'wbi' ) );
        }

        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';

        switch ( $action ) {
            case 'new':
                $this->render_form_page( 0 );
                break;
            case 'edit':
                $rule_id = isset( $_GET['rule_id'] ) ? absint( $_GET['rule_id'] ) : 0;
                $this->render_form_page( $rule_id );
                break;
            case 'log':
                $this->render_log_page();
                break;
            default:
                $this->render_list_page();
        }
    }

    // -------------------------------------------------------------------------
    // List Page
    // -------------------------------------------------------------------------

    private function render_list_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'wbi_reorder_rules';

        // Handle "run now" form submission
        if ( isset( $_POST['wbi_reorder_run_now'] ) ) {
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'wbi_reorder_run_now' ) ) {
                wp_die( 'Nonce inválido' );
            }
            $result = $this->run_reorder_check();
            echo '<div class="notice notice-success"><p>✅ Revisión de reabastecimiento ejecutada. '
                . intval( $result ) . ' regla(s) procesada(s).</p></div>';
        }

        // Handle bulk action form submission
        if ( isset( $_POST['wbi_bulk_action'] ) && isset( $_POST['rule_ids'] ) ) {
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce_bulk'] ?? '' ) ), 'wbi_reorder_bulk' ) ) {
                wp_die( 'Nonce inválido' );
            }
            $bulk_action = sanitize_key( $_POST['wbi_bulk_action'] );
            $rule_ids    = array_map( 'absint', (array) $_POST['rule_ids'] );
            $this->process_bulk_action( $bulk_action, $rule_ids );
        }

        // Filters
        $filter_supplier = isset( $_GET['filter_supplier'] ) ? absint( $_GET['filter_supplier'] ) : 0;
        $filter_active   = isset( $_GET['filter_active'] ) ? sanitize_key( $_GET['filter_active'] ) : '';
        $filter_below    = isset( $_GET['filter_below'] ) ? (bool) $_GET['filter_below'] : false;

        // Build query
        $where  = array( '1=1' );
        $params = array();

        if ( $filter_supplier ) {
            $where[]  = 'supplier_id = %d';
            $params[] = $filter_supplier;
        }
        if ( $filter_active !== '' ) {
            $where[]  = 'is_active = %d';
            $params[] = ( $filter_active === 'active' ) ? 1 : 0;
        }

        $where_sql = implode( ' AND ', $where );

        if ( ! empty( $params ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rules = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC", ...$params ) );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rules = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" );
        }

        // Attach live stock to each rule
        foreach ( $rules as $rule ) {
            $rule->current_stock = (float) get_post_meta( $rule->product_id, '_stock', true );
        }

        // Apply "below min stock" filter in PHP (needs live stock)
        if ( $filter_below ) {
            $rules = array_values( array_filter( $rules, function( $r ) {
                return $r->current_stock <= $r->min_stock;
            } ) );
        }

        // Get unique suppliers for filter dropdown
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $suppliers_in_rules = $wpdb->get_results( "SELECT DISTINCT supplier_id, supplier_name FROM {$table} WHERE supplier_id > 0 ORDER BY supplier_name" );

        $list_url = admin_url( 'admin.php?page=wbi-reorder' );
        $new_url  = admin_url( 'admin.php?page=wbi-reorder&action=new' );
        $log_url  = admin_url( 'admin.php?page=wbi-reorder&action=log' );
        ?>
        <div class="wrap">
            <h1>🔄 Reglas de Reabastecimiento
                <a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action">+ Nueva Regla</a>
            </h1>
            <p>
                <a href="<?php echo esc_url( $log_url ); ?>" class="button">📋 Ver Historial de Órdenes</a>
                &nbsp;
                <form method="post" style="display:inline;">
                    <?php wp_nonce_field( 'wbi_reorder_run_now', '_wpnonce' ); ?>
                    <button type="submit" name="wbi_reorder_run_now" value="1" class="button button-primary"
                            onclick="return confirm('¿Ejecutar revisión de reabastecimiento ahora?');">
                        ▶ Ejecutar Reabastecimiento Ahora
                    </button>
                </form>
            </p>

            <!-- Filters -->
            <form method="get" style="margin-bottom:16px; display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                <input type="hidden" name="page" value="wbi-reorder">
                <?php if ( ! empty( $suppliers_in_rules ) ) : ?>
                <select name="filter_supplier">
                    <option value="">— Todos los proveedores —</option>
                    <?php foreach ( $suppliers_in_rules as $s ) : ?>
                        <option value="<?php echo esc_attr( $s->supplier_id ); ?>"
                            <?php selected( $filter_supplier, $s->supplier_id ); ?>>
                            <?php echo esc_html( $s->supplier_name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <select name="filter_active">
                    <option value="">— Estado —</option>
                    <option value="active" <?php selected( $filter_active, 'active' ); ?>>✅ Activas</option>
                    <option value="inactive" <?php selected( $filter_active, 'inactive' ); ?>>⏸ Inactivas</option>
                </select>
                <label>
                    <input type="checkbox" name="filter_below" value="1" <?php checked( $filter_below ); ?>>
                    Solo productos bajo mínimo
                </label>
                <button type="submit" class="button">Filtrar</button>
                <a href="<?php echo esc_url( $list_url ); ?>" class="button">Limpiar</a>
            </form>

            <?php if ( empty( $rules ) ) : ?>
                <div class="notice notice-info inline"><p>No hay reglas de reabastecimiento. <a href="<?php echo esc_url( $new_url ); ?>">Crear la primera regla</a>.</p></div>
            <?php else : ?>

            <!-- Bulk actions -->
            <form method="post" id="wbi-reorder-list-form">
                <?php wp_nonce_field( 'wbi_reorder_bulk', '_wpnonce_bulk' ); ?>
                <div style="display:flex; gap:8px; align-items:center; margin-bottom:10px;">
                    <select name="wbi_bulk_action" id="wbi-bulk-action">
                        <option value="">— Acción masiva —</option>
                        <option value="activate">✅ Activar</option>
                        <option value="deactivate">⏸ Desactivar</option>
                        <option value="trigger">▶ Reordenar ahora</option>
                        <option value="delete">🗑 Eliminar</option>
                    </select>
                    <button type="submit" name="wbi_bulk_action_submit" class="button"
                            onclick="return confirm('¿Aplicar acción masiva a los seleccionados?');">
                        Aplicar
                    </button>
                </div>

                <table class="widefat striped wbi-sortable" style="margin-top:0;">
                    <thead>
                        <tr>
                            <th style="width:30px;"><input type="checkbox" id="wbi-check-all"></th>
                            <th>Producto</th>
                            <th>SKU</th>
                            <th>Stock Actual</th>
                            <th>Min Stock</th>
                            <th>Max / Reorder</th>
                            <th>Proveedor</th>
                            <th>Lead Time</th>
                            <th>Estado</th>
                            <th>Último Trigger</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rules as $rule ) :
                            $below      = $rule->current_stock <= $rule->min_stock;
                            $row_style  = $below ? 'background:#fff3cd; border-left:4px solid #d63638;' : '';
                            $edit_url   = admin_url( 'admin.php?page=wbi-reorder&action=edit&rule_id=' . $rule->id );
                            $qty_label  = $rule->rule_type === 'min_max'
                                ? 'Max: ' . number_format( (float) $rule->max_stock, 0 )
                                : 'Cant: ' . number_format( (float) $rule->reorder_qty, 0 );
                            $triggered  = $rule->last_triggered_at
                                ? wp_date( 'd/m/Y H:i', strtotime( $rule->last_triggered_at ) )
                                : '—';
                        ?>
                        <tr style="<?php echo esc_attr( $row_style ); ?>">
                            <td><input type="checkbox" name="rule_ids[]" value="<?php echo esc_attr( $rule->id ); ?>"></td>
                            <td>
                                <strong><?php echo esc_html( $rule->product_name ); ?></strong>
                                <?php if ( $below ) : ?>
                                    <span style="color:#d63638; font-size:11px; display:block;">⚠️ Por debajo del mínimo</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $rule->sku ?: '—' ); ?></td>
                            <td>
                                <strong style="color:<?php echo esc_attr( $below ? '#d63638' : '#00a32a' ); ?>;">
                                    <?php echo number_format( $rule->current_stock, 0 ); ?>
                                </strong>
                            </td>
                            <td><?php echo number_format( (float) $rule->min_stock, 0 ); ?></td>
                            <td><?php echo esc_html( $qty_label ); ?></td>
                            <td><?php echo esc_html( $rule->supplier_name ?: '—' ); ?></td>
                            <td><?php echo intval( $rule->lead_time_days ); ?>d</td>
                            <td>
                                <?php if ( $rule->is_active ) : ?>
                                    <span style="color:#00a32a;">✅ Activa</span>
                                <?php else : ?>
                                    <span style="color:#50575e;">⏸ Inactiva</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $triggered ); ?></td>
                            <td style="white-space:nowrap;">
                                <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">Editar</a>
                                <button type="button" class="button button-small wbi-reorder-toggle"
                                        data-id="<?php echo esc_attr( $rule->id ); ?>"
                                        data-active="<?php echo esc_attr( $rule->is_active ); ?>"
                                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'wbi_reorder_toggle_' . $rule->id ) ); ?>">
                                    <?php echo $rule->is_active ? 'Desactivar' : 'Activar'; ?>
                                </button>
                                <button type="button" class="button button-small button-link-delete wbi-reorder-delete"
                                        data-id="<?php echo esc_attr( $rule->id ); ?>"
                                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'wbi_reorder_delete_' . $rule->id ) ); ?>">
                                    Eliminar
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Create / Edit Form Page
    // -------------------------------------------------------------------------

    private function render_form_page( $rule_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wbi_reorder_rules';
        $rule  = null;

        if ( $rule_id ) {
            $rule = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $rule_id ) );
            if ( ! $rule ) {
                echo '<div class="wrap"><div class="notice notice-error"><p>Regla no encontrada.</p></div></div>';
                return;
            }
        }

        // Get suppliers list (from Suppliers module if active, otherwise empty)
        $suppliers = $this->get_suppliers_list();

        $title    = $rule_id ? 'Editar Regla #' . $rule_id : 'Nueva Regla de Reabastecimiento';
        $back_url = admin_url( 'admin.php?page=wbi-reorder' );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $title ); ?></h1>
            <p><a href="<?php echo esc_url( $back_url ); ?>">← Volver al listado</a></p>

            <form method="post" id="wbi-reorder-form" style="max-width:700px;">
                <?php wp_nonce_field( 'wbi_reorder_save', '_wbi_reorder_nonce' ); ?>
                <input type="hidden" name="rule_id" value="<?php echo esc_attr( $rule_id ); ?>">

                <table class="form-table">
                    <tr>
                        <th><label for="wbi_product_search">Producto *</label></th>
                        <td>
                            <input type="text" id="wbi_product_search" placeholder="Escribí para buscar..."
                                   value="<?php echo $rule ? esc_attr( $rule->product_name ) : ''; ?>"
                                   class="regular-text" autocomplete="off">
                            <input type="hidden" id="wbi_product_id" name="product_id"
                                   value="<?php echo $rule ? esc_attr( $rule->product_id ) : ''; ?>">
                            <div id="wbi-product-suggestions" style="display:none; position:absolute; background:#fff; border:1px solid #c3c4c7; border-radius:4px; z-index:100; width:300px; max-height:200px; overflow-y:auto;"></div>
                            <p class="description" id="wbi-product-info" style="margin-top:6px;">
                                <?php if ( $rule && $rule->product_id ) {
                                    $stock = get_post_meta( $rule->product_id, '_stock', true );
                                    echo 'SKU: ' . esc_html( $rule->sku ) . ' | Stock actual: <strong>' . number_format( (float) $stock, 0 ) . '</strong>';
                                } ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wbi_rule_type">Tipo de Regla</label></th>
                        <td>
                            <select name="rule_type" id="wbi_rule_type">
                                <option value="min_max" <?php selected( $rule ? $rule->rule_type : 'min_max', 'min_max' ); ?>>Min/Max — Ordenar hasta el máximo</option>
                                <option value="fixed_qty" <?php selected( $rule ? $rule->rule_type : '', 'fixed_qty' ); ?>>Cantidad Fija — Siempre ordenar la misma cantidad</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wbi_min_stock">Stock Mínimo (punto de reorden) *</label></th>
                        <td>
                            <input type="number" id="wbi_min_stock" name="min_stock" step="0.01" min="0"
                                   value="<?php echo $rule ? esc_attr( $rule->min_stock ) : '0'; ?>"
                                   class="small-text">
                            <p class="description">Se activa el reabastecimiento cuando el stock ≤ este valor.</p>
                        </td>
                    </tr>
                    <tr id="row_max_stock" <?php echo ( $rule && $rule->rule_type === 'fixed_qty' ) ? 'style="display:none;"' : ''; ?>>
                        <th><label for="wbi_max_stock">Stock Máximo (ordenar hasta)</label></th>
                        <td>
                            <input type="number" id="wbi_max_stock" name="max_stock" step="0.01" min="0"
                                   value="<?php echo $rule ? esc_attr( $rule->max_stock ) : '0'; ?>"
                                   class="small-text">
                            <p class="description">Se ordenará: max_stock − stock_actual.</p>
                        </td>
                    </tr>
                    <tr id="row_reorder_qty" <?php echo ( ! $rule || $rule->rule_type !== 'fixed_qty' ) ? 'style="display:none;"' : ''; ?>>
                        <th><label for="wbi_reorder_qty">Cantidad a Ordenar</label></th>
                        <td>
                            <input type="number" id="wbi_reorder_qty" name="reorder_qty" step="0.01" min="0"
                                   value="<?php echo $rule ? esc_attr( $rule->reorder_qty ) : '0'; ?>"
                                   class="small-text">
                            <p class="description">Cantidad fija a ordenar cada vez.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Proveedor</label></th>
                        <td>
                            <?php if ( ! empty( $suppliers ) ) : ?>
                                <select name="supplier_id" id="wbi_supplier_id">
                                    <option value="0">— Sin proveedor —</option>
                                    <?php foreach ( $suppliers as $s ) : ?>
                                        <option value="<?php echo esc_attr( $s->ID ); ?>"
                                            <?php selected( $rule ? $rule->supplier_id : 0, $s->ID ); ?>>
                                            <?php echo esc_html( $s->post_title ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="supplier_name" id="wbi_supplier_name"
                                       value="<?php echo $rule ? esc_attr( $rule->supplier_name ) : ''; ?>">
                            <?php else : ?>
                                <input type="text" name="supplier_name" id="wbi_supplier_name"
                                       value="<?php echo $rule ? esc_attr( $rule->supplier_name ) : ''; ?>"
                                       class="regular-text" placeholder="Nombre del proveedor">
                                <input type="hidden" name="supplier_id" value="0">
                                <p class="description">Activá el Módulo de Proveedores para seleccionar de una lista.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wbi_lead_time_days">Lead Time (días)</label></th>
                        <td>
                            <input type="number" id="wbi_lead_time_days" name="lead_time_days" min="0"
                                   value="<?php echo $rule ? esc_attr( $rule->lead_time_days ) : '7'; ?>"
                                   class="small-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wbi_unit_cost">Costo Unitario</label></th>
                        <td>
                            <input type="number" id="wbi_unit_cost" name="unit_cost" step="0.01" min="0"
                                   value="<?php echo $rule ? esc_attr( $rule->unit_cost ) : '0'; ?>"
                                   class="small-text">
                            <p class="description" id="wbi-cost-info"></p>
                        </td>
                    </tr>
                    <tr>
                        <th>Estado</th>
                        <td>
                            <label>
                                <input type="checkbox" name="is_active" value="1"
                                    <?php checked( $rule ? $rule->is_active : 1, 1 ); ?>>
                                Regla activa
                            </label>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">💾 Guardar Regla</button>
                    <a href="<?php echo esc_url( $back_url ); ?>" class="button">Cancelar</a>
                </p>
            </form>
        </div>

        <script>
        (function() {
            var searchInput  = document.getElementById('wbi_product_search');
            var productIdField = document.getElementById('wbi_product_id');
            var suggestions  = document.getElementById('wbi-product-suggestions');
            var productInfo  = document.getElementById('wbi-product-info');
            var unitCostInfo = document.getElementById('wbi-cost-info');
            var ruleType     = document.getElementById('wbi_rule_type');
            var rowMax       = document.getElementById('row_max_stock');
            var rowFixed     = document.getElementById('row_reorder_qty');
            var supplierSel  = document.getElementById('wbi_supplier_id');
            var supplierName = document.getElementById('wbi_supplier_name');
            var debounceTimer;

            // Rule type toggle
            if (ruleType) {
                ruleType.addEventListener('change', function() {
                    if (this.value === 'min_max') {
                        rowMax.style.display = '';
                        rowFixed.style.display = 'none';
                    } else {
                        rowMax.style.display = 'none';
                        rowFixed.style.display = '';
                    }
                });
            }

            // Supplier selector sync name
            if (supplierSel) {
                supplierSel.addEventListener('change', function() {
                    var opt = this.options[this.selectedIndex];
                    supplierName.value = this.value > 0 ? opt.text : '';
                });
            }

            // Product search autocomplete
            if (!searchInput) return;
            searchInput.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                var term = this.value;
                if (term.length < 2) { suggestions.style.display = 'none'; return; }
                debounceTimer = setTimeout(function() {
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxurl);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() {
                        var res = JSON.parse(xhr.responseText);
                        suggestions.innerHTML = '';
                        if (res.success && res.data.length) {
                            res.data.forEach(function(item) {
                                var div = document.createElement('div');
                                div.textContent = item.label;
                                div.style.cssText = 'padding:8px 12px; cursor:pointer;';
                                div.addEventListener('mouseenter', function() { this.style.background = '#f0f0f1'; });
                                div.addEventListener('mouseleave', function() { this.style.background = ''; });
                                div.addEventListener('click', function() {
                                    searchInput.value   = item.label;
                                    productIdField.value = item.id;
                                    suggestions.style.display = 'none';
                                    productInfo.innerHTML = 'SKU: ' + (item.sku || '—') + ' | Stock actual: <strong>' + item.stock + '</strong>';
                                    if (item.cost) {
                                        document.getElementById('wbi_unit_cost').value = item.cost;
                                        unitCostInfo.textContent = 'Pre-cargado desde el módulo de Costos.';
                                    }
                                });
                                suggestions.appendChild(div);
                            });
                            suggestions.style.display = 'block';
                        } else {
                            suggestions.style.display = 'none';
                        }
                    };
                    xhr.send('action=wbi_reorder_search_products&term=' + encodeURIComponent(term)
                        + '&nonce=<?php echo esc_js( wp_create_nonce( 'wbi_reorder_search' ) ); ?>');
                }, 300);
            });

            document.addEventListener('click', function(e) {
                if (!suggestions.contains(e.target) && e.target !== searchInput) {
                    suggestions.style.display = 'none';
                }
            });

            // Form submit via AJAX
            var form = document.getElementById('wbi-reorder-form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var data = new FormData(form);
                    data.append('action', 'wbi_reorder_save_rule');
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxurl);
                    xhr.onload = function() {
                        var res = JSON.parse(xhr.responseText);
                        if (res.success) {
                            window.location.href = '<?php echo esc_js( admin_url( 'admin.php?page=wbi-reorder' ) ); ?>';
                        } else {
                            alert('Error: ' + (res.data || 'No se pudo guardar la regla.'));
                        }
                    };
                    xhr.send(data);
                });
            }
        })();
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // Log / History Page
    // -------------------------------------------------------------------------

    private function render_log_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'wbi_reorder_rules';
        $back_url = admin_url( 'admin.php?page=wbi-reorder' );

        // Rules that have been triggered (have a last_triggered_at)
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rules = $wpdb->get_results( "SELECT * FROM {$table} WHERE last_triggered_at IS NOT NULL ORDER BY last_triggered_at DESC" );

        $purchase_active = class_exists( 'WBI_Purchase_Module' );
        ?>
        <div class="wrap">
            <h1>📋 Historial de Reabastecimiento</h1>
            <p><a href="<?php echo esc_url( $back_url ); ?>">← Volver al listado</a></p>

            <?php if ( empty( $rules ) ) : ?>
                <div class="notice notice-info inline"><p>No hay reabastecimientos registrados todavía.</p></div>
            <?php else : ?>
            <table class="widefat striped wbi-sortable">
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th>Proveedor</th>
                        <th>Tipo</th>
                        <th>Min Stock</th>
                        <th>Max / Cant.</th>
                        <th>Lead Time</th>
                        <th>Último PO #</th>
                        <th>Último Trigger</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rules as $rule ) :
                        $current_stock = (float) get_post_meta( $rule->product_id, '_stock', true );
                        $qty_label     = $rule->rule_type === 'min_max'
                            ? 'Max: ' . number_format( (float) $rule->max_stock, 0 )
                            : 'Cant: ' . number_format( (float) $rule->reorder_qty, 0 );
                        $triggered     = wp_date( 'd/m/Y H:i', strtotime( $rule->last_triggered_at ) );
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $rule->product_name ); ?></strong><br>
                            <small>Stock actual: <?php echo number_format( $current_stock, 0 ); ?></small>
                        </td>
                        <td><?php echo esc_html( $rule->supplier_name ?: '—' ); ?></td>
                        <td><?php echo $rule->rule_type === 'min_max' ? 'Min/Max' : 'Fija'; ?></td>
                        <td><?php echo number_format( (float) $rule->min_stock, 0 ); ?></td>
                        <td><?php echo esc_html( $qty_label ); ?></td>
                        <td><?php echo intval( $rule->lead_time_days ); ?>d</td>
                        <td>
                            <?php if ( $rule->last_po_id ) : ?>
                                <?php if ( $purchase_active ) : ?>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-purchase&action=view&po_id=' . $rule->last_po_id ) ); ?>">
                                        #<?php echo intval( $rule->last_po_id ); ?>
                                    </a>
                                <?php else : ?>
                                    #<?php echo intval( $rule->last_po_id ); ?>
                                <?php endif; ?>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $triggered ); ?></td>
                        <td><?php echo $rule->is_active ? '<span style="color:#00a32a;">✅ Activa</span>' : '<span style="color:#50575e;">⏸ Inactiva</span>'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Reorder Logic
    // -------------------------------------------------------------------------

    /**
     * Main reorder check — runs via cron or manually.
     * Returns the number of rules that triggered a reorder.
     *
     * @return int
     */
    public function run_reorder_check() {
        global $wpdb;
        $table   = $wpdb->prefix . 'wbi_reorder_rules';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rules   = $wpdb->get_results( "SELECT * FROM {$table} WHERE is_active = 1" );
        $count   = 0;

        // Group rules by supplier so we create one PO per supplier
        $by_supplier = array();
        foreach ( $rules as $rule ) {
            $stock = (float) get_post_meta( $rule->product_id, '_stock', true );
            if ( $stock <= (float) $rule->min_stock ) {
                $qty = $this->calculate_order_qty( $rule, $stock );
                if ( $qty <= 0 ) continue;
                $by_supplier[ $rule->supplier_id ][] = array( 'rule' => $rule, 'qty' => $qty );
                $count++;
            }
        }

        foreach ( $by_supplier as $supplier_id => $items ) {
            $this->process_supplier_reorder( $supplier_id, $items );
        }

        return $count;
    }

    /**
     * Check a single product for triggered reorder rules.
     * Hooked to woocommerce_product_set_stock.
     *
     * @param WC_Product $product
     */
    public function on_stock_change( $product ) {
        if ( ! $product ) return;
        global $wpdb;
        $table      = $wpdb->prefix . 'wbi_reorder_rules';
        $product_id = $product->get_id();
        $stock      = (float) $product->get_stock_quantity();

        $rules = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE product_id = %d AND is_active = 1",
            $product_id
        ) );

        $by_supplier = array();
        foreach ( $rules as $rule ) {
            if ( $stock <= (float) $rule->min_stock ) {
                $qty = $this->calculate_order_qty( $rule, $stock );
                if ( $qty <= 0 ) continue;
                $by_supplier[ $rule->supplier_id ][] = array( 'rule' => $rule, 'qty' => $qty );
            }
        }

        foreach ( $by_supplier as $supplier_id => $items ) {
            $this->process_supplier_reorder( $supplier_id, $items );
        }
    }

    /**
     * Calculate quantity to order for a rule.
     */
    private function calculate_order_qty( $rule, $current_stock ) {
        if ( $rule->rule_type === 'min_max' ) {
            return max( 0, (float) $rule->max_stock - $current_stock );
        }
        return max( 0, (float) $rule->reorder_qty );
    }

    /**
     * Process reorder for a group of items from the same supplier.
     */
    private function process_supplier_reorder( $supplier_id, array $items ) {
        global $wpdb;
        $table    = $wpdb->prefix . 'wbi_reorder_rules';
        $po_id    = 0;

        // Create a draft PO in the Purchase module if active
        if ( class_exists( 'WBI_Purchase_Module' ) ) {
            $po_id = $this->create_draft_po( $supplier_id, $items );
        }

        $now = current_time( 'mysql' );

        foreach ( $items as $item ) {
            $rule = $item['rule'];

            // Update rule metadata
            $wpdb->update(
                $table,
                array(
                    'last_triggered_at' => $now,
                    'last_po_id'        => $po_id,
                    'updated_at'        => $now,
                ),
                array( 'id' => $rule->id ),
                array( '%s', '%d', '%s' ),
                array( '%d' )
            );

            // Notifications module integration
            if ( class_exists( 'WBI_Notifications_Module' ) && method_exists( 'WBI_Notifications_Module', 'add_notification' ) ) {
                $po_label = $po_id ? ' — OC #' . $po_id : '';
                WBI_Notifications_Module::add_notification(
                    'Reabastecimiento automático: ' . $rule->product_name . $po_label,
                    'reorder'
                );
            }

            // WhatsApp module integration
            if ( class_exists( 'WBI_Whatsapp_Module' ) && method_exists( 'WBI_Whatsapp_Module', 'send_admin_message' ) ) {
                $opts        = get_option( 'wbi_modules_settings', array() );
                $admin_phone = isset( $opts['wbi_whatsapp_admin_phone'] ) ? $opts['wbi_whatsapp_admin_phone'] : '';
                if ( $admin_phone ) {
                    $po_label = $po_id ? ' OC #' . $po_id : '';
                    WBI_Whatsapp_Module::send_admin_message(
                        'Reabastecimiento automático: ' . $rule->product_name . $po_label
                    );
                }
            }
        }
    }

    /**
     * Create a draft Purchase Order via WBI_Purchase_Module.
     * Returns the new PO ID, or 0 on failure.
     */
    private function create_draft_po( $supplier_id, array $items ) {
        if ( ! class_exists( 'WBI_Purchase_Module' ) ) return 0;
        if ( ! method_exists( 'WBI_Purchase_Module', 'create_draft_po' ) ) return 0;

        $line_items = array();
        $supplier_name = '';
        foreach ( $items as $item ) {
            $rule = $item['rule'];
            if ( ! $supplier_name ) $supplier_name = $rule->supplier_name;
            $line_items[] = array(
                'product_id'   => $rule->product_id,
                'product_name' => $rule->product_name,
                'sku'          => $rule->sku,
                'qty'          => $item['qty'],
                'unit_cost'    => $rule->unit_cost,
            );
        }

        return (int) WBI_Purchase_Module::create_draft_po( array(
            'supplier_id'   => $supplier_id,
            'supplier_name' => $supplier_name,
            'status'        => 'draft',
            'origin'        => 'reorder',
            'line_items'    => $line_items,
        ) );
    }

    // -------------------------------------------------------------------------
    // Bulk Action Processing
    // -------------------------------------------------------------------------

    private function process_bulk_action( $action, array $rule_ids ) {
        if ( empty( $rule_ids ) ) return;
        global $wpdb;
        $table = $wpdb->prefix . 'wbi_reorder_rules';
        $ids_format = implode( ',', array_fill( 0, count( $rule_ids ), '%d' ) );

        switch ( $action ) {
            case 'activate':
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_active = 1 WHERE id IN ({$ids_format})", ...$rule_ids ) );
                break;
            case 'deactivate':
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_active = 0 WHERE id IN ({$ids_format})", ...$rule_ids ) );
                break;
            case 'trigger':
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $rules = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ({$ids_format})", ...$rule_ids ) );
                foreach ( $rules as $rule ) {
                    $stock = (float) get_post_meta( $rule->product_id, '_stock', true );
                    $qty   = $this->calculate_order_qty( $rule, $stock );
                    if ( $qty > 0 ) {
                        $this->process_supplier_reorder( $rule->supplier_id, array( array( 'rule' => $rule, 'qty' => $qty ) ) );
                    }
                }
                break;
            case 'delete':
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$ids_format})", ...$rule_ids ) );
                break;
        }
    }

    // -------------------------------------------------------------------------
    // AJAX Handlers
    // -------------------------------------------------------------------------

    public function ajax_search_products() {
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'wbi_reorder_search' ) ) {
            wp_send_json_error( 'Nonce inválido' );
        }
        if ( ! $this->current_user_can() ) {
            wp_send_json_error( 'Sin permisos' );
        }

        $term = sanitize_text_field( wp_unslash( $_POST['term'] ?? '' ) );
        if ( strlen( $term ) < 2 ) {
            wp_send_json_success( array() );
        }

        $args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            's'              => $term,
            'fields'         => 'ids',
        );
        $query      = new WP_Query( $args );
        $results    = array();
        $costs_active = class_exists( 'WBI_Costs_Module' );

        foreach ( $query->posts as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) continue;
            $sku   = $product->get_sku();
            $stock = (float) get_post_meta( $product_id, '_stock', true );
            $cost  = 0;
            if ( $costs_active ) {
                $cost = (float) get_post_meta( $product_id, '_wbi_cost', true );
            }
            $results[] = array(
                'id'    => $product_id,
                'label' => $product->get_name() . ( $sku ? ' [' . $sku . ']' : '' ),
                'sku'   => $sku,
                'stock' => number_format( $stock, 0 ),
                'cost'  => $cost,
            );
        }

        wp_send_json_success( $results );
    }

    public function ajax_save_rule() {
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wbi_reorder_nonce'] ?? '' ) ), 'wbi_reorder_save' ) ) {
            wp_send_json_error( 'Nonce inválido' );
        }
        if ( ! $this->current_user_can() ) {
            wp_send_json_error( 'Sin permisos' );
        }

        $rule_id    = absint( $_POST['rule_id'] ?? 0 );
        $product_id = absint( $_POST['product_id'] ?? 0 );

        if ( ! $product_id ) {
            wp_send_json_error( 'Seleccioná un producto válido.' );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_send_json_error( 'Producto no encontrado.' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wbi_reorder_rules';

        $supplier_id   = absint( $_POST['supplier_id'] ?? 0 );
        $supplier_name = sanitize_text_field( wp_unslash( $_POST['supplier_name'] ?? '' ) );

        // If supplier_id provided but name is empty, fetch from CPT
        if ( $supplier_id && ! $supplier_name ) {
            $supplier_name = get_the_title( $supplier_id );
        }

        $data = array(
            'product_id'    => $product_id,
            'product_name'  => $product->get_name(),
            'sku'           => $product->get_sku(),
            'supplier_id'   => $supplier_id,
            'supplier_name' => $supplier_name,
            'min_stock'     => (float) ( $_POST['min_stock'] ?? 0 ),
            'max_stock'     => (float) ( $_POST['max_stock'] ?? 0 ),
            'reorder_qty'   => (float) ( $_POST['reorder_qty'] ?? 0 ),
            'rule_type'     => sanitize_key( $_POST['rule_type'] ?? 'min_max' ) === 'fixed_qty' ? 'fixed_qty' : 'min_max',
            'lead_time_days'=> absint( $_POST['lead_time_days'] ?? 7 ),
            'unit_cost'     => (float) ( $_POST['unit_cost'] ?? 0 ),
            'is_active'     => isset( $_POST['is_active'] ) ? 1 : 0,
            'updated_at'    => current_time( 'mysql' ),
        );
        $formats = array( '%d', '%s', '%s', '%d', '%s', '%f', '%f', '%f', '%s', '%d', '%f', '%d', '%s' );

        if ( $rule_id ) {
            $result = $wpdb->update( $table, $data, array( 'id' => $rule_id ), $formats, array( '%d' ) );
        } else {
            $data['created_at'] = current_time( 'mysql' );
            $formats[]          = '%s';
            $result             = $wpdb->insert( $table, $data, $formats );
            $rule_id            = $wpdb->insert_id;
        }

        if ( false === $result ) {
            wp_send_json_error( 'Error al guardar la regla.' );
        }

        wp_send_json_success( array( 'rule_id' => $rule_id ) );
    }

    public function ajax_delete_rule() {
        $rule_id = absint( $_POST['rule_id'] ?? 0 );
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'wbi_reorder_delete_' . $rule_id ) ) {
            wp_send_json_error( 'Nonce inválido' );
        }
        if ( ! $this->current_user_can() ) {
            wp_send_json_error( 'Sin permisos' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wbi_reorder_rules';
        $wpdb->delete( $table, array( 'id' => $rule_id ), array( '%d' ) );

        wp_send_json_success();
    }

    public function ajax_toggle_active() {
        $rule_id = absint( $_POST['rule_id'] ?? 0 );
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'wbi_reorder_toggle_' . $rule_id ) ) {
            wp_send_json_error( 'Nonce inválido' );
        }
        if ( ! $this->current_user_can() ) {
            wp_send_json_error( 'Sin permisos' );
        }

        global $wpdb;
        $table      = $wpdb->prefix . 'wbi_reorder_rules';
        $current    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT is_active FROM {$table} WHERE id = %d", $rule_id ) );
        $new_status = $current ? 0 : 1;

        $wpdb->update( $table, array( 'is_active' => $new_status ), array( 'id' => $rule_id ), array( '%d' ), array( '%d' ) );

        wp_send_json_success( array( 'is_active' => $new_status ) );
    }

    public function ajax_run_now() {
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'wbi_reorder_run_now_ajax' ) ) {
            wp_send_json_error( 'Nonce inválido' );
        }
        if ( ! $this->current_user_can() ) {
            wp_send_json_error( 'Sin permisos' );
        }

        $count = $this->run_reorder_check();
        wp_send_json_success( array( 'processed' => $count ) );
    }

    public function ajax_bulk_action() {
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'wbi_reorder_bulk_ajax' ) ) {
            wp_send_json_error( 'Nonce inválido' );
        }
        if ( ! $this->current_user_can() ) {
            wp_send_json_error( 'Sin permisos' );
        }

        $action   = sanitize_key( $_POST['bulk_action'] ?? '' );
        $rule_ids = array_map( 'absint', (array) ( $_POST['rule_ids'] ?? array() ) );

        if ( ! $action || empty( $rule_ids ) ) {
            wp_send_json_error( 'Datos incompletos.' );
        }

        $this->process_bulk_action( $action, $rule_ids );
        wp_send_json_success();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function current_user_can() {
        if ( class_exists( 'WBI_Suite_Loader' ) ) {
            global $wbi_suite_loader;
            if ( $wbi_suite_loader && method_exists( $wbi_suite_loader, 'user_can_access_module' ) ) {
                return $wbi_suite_loader->user_can_access_module( 'reorder' );
            }
        }
        return current_user_can( 'manage_options' );
    }

    /**
     * Get suppliers from WBI_Suppliers_Module CPT, or empty array if not active.
     */
    private function get_suppliers_list() {
        if ( ! class_exists( 'WBI_Suppliers_Module' ) ) {
            return array();
        }
        return get_posts( array(
            'post_type'      => 'wbi_supplier',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
    }

    // -------------------------------------------------------------------------
    // Inline admin JS for list page actions (toggle/delete)
    // -------------------------------------------------------------------------

    public function admin_footer_scripts() {
        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'wbi-reorder' ) === false ) return;
        ?>
        <script>
        (function() {
            // Toggle active button
            document.querySelectorAll('.wbi-reorder-toggle').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var id    = this.dataset.id;
                    var nonce = this.dataset.nonce;
                    var el    = this;
                    var xhr   = new XMLHttpRequest();
                    xhr.open('POST', ajaxurl);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() {
                        var res = JSON.parse(xhr.responseText);
                        if (res.success) {
                            window.location.reload();
                        } else {
                            alert('Error: ' + (res.data || 'No se pudo cambiar el estado.'));
                        }
                    };
                    xhr.send('action=wbi_reorder_toggle_active&rule_id=' + id + '&nonce=' + nonce);
                });
            });

            // Delete button
            document.querySelectorAll('.wbi-reorder-delete').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (!confirm('¿Eliminar esta regla de reabastecimiento?')) return;
                    var id    = this.dataset.id;
                    var nonce = this.dataset.nonce;
                    var xhr   = new XMLHttpRequest();
                    xhr.open('POST', ajaxurl);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() {
                        var res = JSON.parse(xhr.responseText);
                        if (res.success) {
                            window.location.reload();
                        } else {
                            alert('Error: ' + (res.data || 'No se pudo eliminar.'));
                        }
                    };
                    xhr.send('action=wbi_reorder_delete_rule&rule_id=' + id + '&nonce=' + nonce);
                });
            });

            // Select all checkbox
            var checkAll = document.getElementById('wbi-check-all');
            if (checkAll) {
                checkAll.addEventListener('change', function() {
                    document.querySelectorAll('input[name="rule_ids[]"]').forEach(function(cb) {
                        cb.checked = checkAll.checked;
                    });
                });
            }
        })();
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // Deactivation
    // -------------------------------------------------------------------------

    public static function deactivate() {
        wp_clear_scheduled_hook( 'wbi_reorder_check' );
    }
}
