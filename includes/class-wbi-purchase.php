<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI Purchase Orders Module — Órdenes de Compra
 *
 * Gestión completa de órdenes de compra: creación, envío al proveedor,
 * confirmación, recepción de mercadería y actualización de stock WooCommerce.
 */
class WBI_Purchase_Module {

    const DB_VERSION = '1.0';

    public function __construct() {
        // Create/update tables when DB version changes
        if ( get_option( 'wbi_purchase_db_version' ) !== self::DB_VERSION ) {
            $this->create_tables();
            update_option( 'wbi_purchase_db_version', self::DB_VERSION );
        }

        // Admin submenu
        add_action( 'admin_menu', array( $this, 'add_submenu' ), 100 );

        // AJAX handlers — logged-in users
        add_action( 'wp_ajax_wbi_purchase_search_products', array( $this, 'ajax_search_products' ) );
        add_action( 'wp_ajax_wbi_purchase_save',            array( $this, 'ajax_save_po' ) );
        add_action( 'wp_ajax_wbi_purchase_receive',         array( $this, 'ajax_receive' ) );
        add_action( 'wp_ajax_wbi_purchase_update_status',   array( $this, 'ajax_update_status' ) );
        add_action( 'wp_ajax_wbi_purchase_delete',          array( $this, 'ajax_delete_po' ) );

        // Admin scripts/styles
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // Dashboard widget integration
        add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
    }

    // =========================================================================
    // DATABASE TABLES
    // =========================================================================

    private function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $orders_table = $wpdb->prefix . 'wbi_purchase_orders';
        $items_table  = $wpdb->prefix . 'wbi_purchase_order_items';
        $receipts_table     = $wpdb->prefix . 'wbi_purchase_receipts';
        $receipt_items_table = $wpdb->prefix . 'wbi_purchase_receipt_items';

        $sql = "CREATE TABLE {$orders_table} (
            id BIGINT NOT NULL AUTO_INCREMENT,
            po_number VARCHAR(30) NOT NULL,
            supplier_id BIGINT NOT NULL DEFAULT 0,
            supplier_name VARCHAR(255) DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            order_date DATE NOT NULL,
            expected_date DATE DEFAULT NULL,
            received_date DATE DEFAULT NULL,
            subtotal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(3) NOT NULL DEFAULT 'ARS',
            notes TEXT,
            created_by BIGINT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY po_number (po_number)
        ) {$charset};

        CREATE TABLE {$items_table} (
            id BIGINT NOT NULL AUTO_INCREMENT,
            po_id BIGINT NOT NULL,
            product_id BIGINT NOT NULL,
            product_name VARCHAR(255) DEFAULT '',
            sku VARCHAR(100) DEFAULT '',
            quantity_ordered DECIMAL(10,2) NOT NULL,
            quantity_received DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            unit_cost DECIMAL(15,2) NOT NULL,
            tax_rate DECIMAL(5,2) NOT NULL DEFAULT 21.00,
            line_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            PRIMARY KEY (id),
            KEY po_id (po_id)
        ) {$charset};

        CREATE TABLE {$receipts_table} (
            id BIGINT NOT NULL AUTO_INCREMENT,
            po_id BIGINT NOT NULL,
            receipt_number VARCHAR(30) NOT NULL,
            received_by BIGINT NOT NULL,
            received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            notes TEXT,
            PRIMARY KEY (id),
            KEY po_id (po_id)
        ) {$charset};

        CREATE TABLE {$receipt_items_table} (
            id BIGINT NOT NULL AUTO_INCREMENT,
            receipt_id BIGINT NOT NULL,
            po_item_id BIGINT NOT NULL,
            product_id BIGINT NOT NULL,
            quantity_received DECIMAL(10,2) NOT NULL,
            PRIMARY KEY (id),
            KEY receipt_id (receipt_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // =========================================================================
    // AUTO-NUMBERING
    // =========================================================================

    private function generate_po_number() {
        global $wpdb;
        $year  = gmdate( 'Y' );
        $table = $wpdb->prefix . 'wbi_purchase_orders';
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE YEAR(order_date) = %d",
            $year
        ) );
        return 'PO-' . $year . '-' . str_pad( $count + 1, 5, '0', STR_PAD_LEFT );
    }

    private function generate_receipt_number() {
        global $wpdb;
        $year  = gmdate( 'Y' );
        $table = $wpdb->prefix . 'wbi_purchase_receipts';
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE YEAR(received_at) = %d",
            $year
        ) );
        return 'REC-' . $year . '-' . str_pad( $count + 1, 5, '0', STR_PAD_LEFT );
    }

    // =========================================================================
    // ADMIN MENU
    // =========================================================================

    public function add_submenu() {
        add_submenu_page(
            'wbi-dashboard-view',
            'Órdenes de Compra',
            '<span class="dashicons dashicons-cart" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> Órdenes de Compra',
            $this->get_capability(),
            'wbi-purchase',
            array( $this, 'render_page' )
        );
    }

    private function get_capability() {
        $opts = get_option( 'wbi_modules_settings', array() );
        $roles = isset( $opts['wbi_permissions_purchase'] ) ? (array) $opts['wbi_permissions_purchase'] : array();
        if ( empty( $roles ) ) return 'manage_woocommerce';
        foreach ( $roles as $role ) {
            if ( current_user_can( $role ) ) return $role;
        }
        return 'manage_woocommerce';
    }

    // =========================================================================
    // PAGE ROUTER
    // =========================================================================

    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'No tenés permiso para acceder a esta página.', 'wbi-suite' ) );
        }

        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
        $po_id  = isset( $_GET['po_id'] ) ? absint( $_GET['po_id'] ) : 0;
        $tab    = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';

        if ( 'reports' === $tab ) {
            $this->render_reports_page();
            return;
        }

        switch ( $action ) {
            case 'new':
                $this->render_form_page( 0 );
                break;
            case 'edit':
                $this->render_form_page( $po_id );
                break;
            case 'view':
                $this->render_view_page( $po_id );
                break;
            default:
                $this->render_list_page();
                break;
        }
    }

    // =========================================================================
    // LIST PAGE
    // =========================================================================

    private function render_list_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'wbi_purchase_orders';

        // Filters
        $filter_status   = isset( $_GET['filter_status'] ) ? sanitize_key( $_GET['filter_status'] ) : '';
        $filter_supplier = isset( $_GET['filter_supplier'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_supplier'] ) ) : '';
        $filter_from     = isset( $_GET['filter_from'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_from'] ) ) : '';
        $filter_to       = isset( $_GET['filter_to'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_to'] ) ) : '';

        $where  = array( '1=1' );
        $params = array();

        if ( $filter_status ) {
            $where[]  = 'status = %s';
            $params[] = $filter_status;
        }
        if ( $filter_supplier ) {
            $where[]  = 'supplier_name LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $filter_supplier ) . '%';
        }
        if ( $filter_from ) {
            $where[]  = 'order_date >= %s';
            $params[] = $filter_from;
        }
        if ( $filter_to ) {
            $where[]  = 'order_date <= %s';
            $params[] = $filter_to;
        }

        $where_sql = implode( ' AND ', $where );
        $query     = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC";
        $orders    = empty( $params )
            ? $wpdb->get_results( $query ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            : $wpdb->get_results( $wpdb->prepare( $query, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $new_url     = admin_url( 'admin.php?page=wbi-purchase&action=new' );
        $reports_url = admin_url( 'admin.php?page=wbi-purchase&tab=reports' );
        ?>
        <div class="wrap">
            <h1 style="display:flex;align-items:center;gap:12px;">
                🛒 Órdenes de Compra
                <a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action">+ Nueva Orden de Compra</a>
                <a href="<?php echo esc_url( $reports_url ); ?>" class="page-title-action">📊 Reportes</a>
            </h1>

            <!-- Filters -->
            <form method="get" style="margin:16px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                <input type="hidden" name="page" value="wbi-purchase">
                <select name="filter_status" style="min-width:140px;">
                    <option value="">— Estado —</option>
                    <?php foreach ( $this->get_statuses() as $k => $v ) : ?>
                        <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filter_status, $k ); ?>><?php echo esc_html( $v['label'] ); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="filter_supplier" placeholder="Proveedor" value="<?php echo esc_attr( $filter_supplier ); ?>" style="width:160px;">
                <input type="date" name="filter_from" value="<?php echo esc_attr( $filter_from ); ?>">
                <input type="date" name="filter_to" value="<?php echo esc_attr( $filter_to ); ?>">
                <button type="submit" class="button">Filtrar</button>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-purchase' ) ); ?>" class="button">Limpiar</a>
            </form>

            <table class="wp-list-table widefat fixed striped wbi-sortable">
                <thead>
                    <tr>
                        <th>Nro. OC</th>
                        <th>Proveedor</th>
                        <th>Fecha</th>
                        <th>Fecha Esperada</th>
                        <th>Estado</th>
                        <th style="text-align:right;">Total</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $orders ) ) : ?>
                    <tr><td colspan="7" style="text-align:center;padding:20px;color:#888;">No hay órdenes de compra. <a href="<?php echo esc_url( $new_url ); ?>">Crear la primera</a>.</td></tr>
                <?php else : ?>
                    <?php foreach ( $orders as $order ) :
                        $view_url   = admin_url( 'admin.php?page=wbi-purchase&action=view&po_id=' . $order->id );
                        $edit_url   = admin_url( 'admin.php?page=wbi-purchase&action=edit&po_id=' . $order->id );
                        $status_cfg = $this->get_statuses()[ $order->status ] ?? array( 'label' => $order->status, 'color' => '#888' );
                    ?>
                    <tr>
                        <td><a href="<?php echo esc_url( $view_url ); ?>" style="font-weight:600;"><?php echo esc_html( $order->po_number ); ?></a></td>
                        <td><?php echo esc_html( $order->supplier_name ?: '—' ); ?></td>
                        <td><?php echo esc_html( $order->order_date ); ?></td>
                        <td><?php echo esc_html( $order->expected_date ?: '—' ); ?></td>
                        <td><span style="background:<?php echo esc_attr( $status_cfg['color'] ); ?>;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;"><?php echo esc_html( $status_cfg['label'] ); ?></span></td>
                        <td style="text-align:right;"><?php echo wc_price( $order->total ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( $view_url ); ?>" class="button button-small">Ver</a>
                            <?php if ( in_array( $order->status, array( 'draft', 'sent' ), true ) ) : ?>
                            <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">Editar</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // =========================================================================
    // CREATE / EDIT FORM PAGE
    // =========================================================================

    private function render_form_page( $po_id ) {
        global $wpdb;
        $table       = $wpdb->prefix . 'wbi_purchase_orders';
        $items_table = $wpdb->prefix . 'wbi_purchase_order_items';

        $po    = null;
        $items = array();

        if ( $po_id > 0 ) {
            $po = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $po_id ) );
            if ( ! $po ) {
                echo '<div class="wrap"><p>Orden no encontrada.</p></div>';
                return;
            }
            if ( ! in_array( $po->status, array( 'draft', 'sent' ), true ) ) {
                echo '<div class="wrap"><p>Esta orden no puede ser editada en su estado actual. <a href="' . esc_url( admin_url( 'admin.php?page=wbi-purchase&action=view&po_id=' . $po_id ) ) . '">Ver OC</a></p></div>';
                return;
            }
            $items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$items_table} WHERE po_id = %d ORDER BY id ASC", $po_id ) );
        }

        // Get suppliers list
        $suppliers     = $this->get_suppliers_list();
        $has_suppliers = ! empty( $suppliers );

        $list_url   = admin_url( 'admin.php?page=wbi-purchase' );
        $form_title = $po_id ? 'Editar Orden de Compra: ' . esc_html( $po->po_number ) : 'Nueva Orden de Compra';
        $nonce      = wp_create_nonce( 'wbi_purchase_save' );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $form_title ); ?> <a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action">← Volver</a></h1>

            <div id="wbi-purchase-form-wrap" style="max-width:1100px;">
                <input type="hidden" id="wbi_po_nonce" value="<?php echo esc_attr( $nonce ); ?>">
                <input type="hidden" id="wbi_po_id" value="<?php echo esc_attr( $po_id ); ?>">

                <!-- Header fields -->
                <table class="form-table" style="max-width:700px;">
                    <tr>
                        <th><label for="wbi_po_supplier">Proveedor</label></th>
                        <td>
                        <?php if ( $has_suppliers ) : ?>
                            <select id="wbi_po_supplier_id" name="supplier_id" style="min-width:280px;">
                                <option value="">— Seleccionar proveedor —</option>
                                <?php foreach ( $suppliers as $s ) : ?>
                                    <option value="<?php echo esc_attr( $s['id'] ); ?>"
                                            data-name="<?php echo esc_attr( $s['name'] ); ?>"
                                            <?php selected( $po ? (string) $po->supplier_id : '', (string) $s['id'] ); ?>>
                                        <?php echo esc_html( $s['name'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else : ?>
                            <input type="text" id="wbi_po_supplier_name" name="supplier_name"
                                   value="<?php echo esc_attr( $po ? $po->supplier_name : '' ); ?>"
                                   placeholder="Nombre del proveedor" class="regular-text">
                        <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wbi_po_order_date">Fecha de Orden</label></th>
                        <td><input type="date" id="wbi_po_order_date" value="<?php echo esc_attr( $po ? $po->order_date : gmdate( 'Y-m-d' ) ); ?>" style="width:180px;"></td>
                    </tr>
                    <tr>
                        <th><label for="wbi_po_expected_date">Fecha Esperada</label></th>
                        <td><input type="date" id="wbi_po_expected_date" value="<?php echo esc_attr( $po && $po->expected_date ? $po->expected_date : '' ); ?>" style="width:180px;"></td>
                    </tr>
                    <tr>
                        <th><label for="wbi_po_currency">Moneda</label></th>
                        <td>
                            <select id="wbi_po_currency" style="width:100px;">
                                <?php foreach ( array( 'ARS', 'USD', 'EUR', 'BRL' ) as $cur ) : ?>
                                    <option value="<?php echo esc_attr( $cur ); ?>" <?php selected( $po ? $po->currency : 'ARS', $cur ); ?>><?php echo esc_html( $cur ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <!-- Items table -->
                <h3 style="margin-top:24px;">Artículos</h3>
                <div style="overflow-x:auto;">
                <table class="widefat" id="wbi-po-items-table">
                    <thead>
                        <tr>
                            <th style="width:280px;">Producto</th>
                            <th style="width:100px;">SKU</th>
                            <th style="width:90px;">Cant.</th>
                            <th style="width:110px;">Costo Unit.</th>
                            <th style="width:80px;">IVA %</th>
                            <th style="width:110px;">Total línea</th>
                            <th style="width:60px;"></th>
                        </tr>
                    </thead>
                    <tbody id="wbi-po-items-body">
                    <?php if ( ! empty( $items ) ) : ?>
                        <?php foreach ( $items as $item ) : ?>
                        <tr class="wbi-po-item-row">
                            <td>
                                <input type="hidden" class="item-product-id" value="<?php echo esc_attr( $item->product_id ); ?>">
                                <input type="hidden" class="item-id" value="<?php echo esc_attr( $item->id ); ?>">
                                <input type="text" class="item-product-name" value="<?php echo esc_attr( $item->product_name ); ?>" placeholder="Buscar producto..." style="width:100%;" autocomplete="off">
                            </td>
                            <td><input type="text" class="item-sku" value="<?php echo esc_attr( $item->sku ); ?>" style="width:90px;" readonly></td>
                            <td><input type="number" class="item-qty" value="<?php echo esc_attr( $item->quantity_ordered ); ?>" min="0.01" step="0.01" style="width:80px;"></td>
                            <td><input type="number" class="item-cost" value="<?php echo esc_attr( $item->unit_cost ); ?>" min="0" step="0.01" style="width:100px;"></td>
                            <td><input type="number" class="item-tax" value="<?php echo esc_attr( $item->tax_rate ); ?>" min="0" max="100" step="0.01" style="width:70px;"></td>
                            <td class="item-line-total" style="font-weight:600;"><?php echo wc_price( $item->line_total ); ?></td>
                            <td><button type="button" class="button button-small wbi-remove-item" style="color:red;">✕</button></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
                <button type="button" id="wbi-add-item-row" class="button" style="margin-top:8px;">+ Agregar línea</button>

                <!-- Totals -->
                <table style="margin-top:16px;min-width:320px;margin-left:auto;">
                    <tr><td style="padding:4px 16px;text-align:right;color:#555;">Subtotal:</td><td style="padding:4px 8px;font-weight:600;" id="wbi-po-subtotal">$0,00</td></tr>
                    <tr><td style="padding:4px 16px;text-align:right;color:#555;">IVA:</td><td style="padding:4px 8px;font-weight:600;" id="wbi-po-tax">$0,00</td></tr>
                    <tr><td style="padding:4px 16px;text-align:right;font-size:15px;font-weight:700;">Total:</td><td style="padding:4px 8px;font-size:15px;font-weight:700;" id="wbi-po-total">$0,00</td></tr>
                </table>

                <!-- Notes -->
                <table class="form-table" style="max-width:700px;margin-top:16px;">
                    <tr>
                        <th><label for="wbi_po_notes">Notas</label></th>
                        <td><textarea id="wbi_po_notes" rows="4" class="large-text"><?php echo esc_textarea( $po ? $po->notes : '' ); ?></textarea></td>
                    </tr>
                </table>

                <!-- Action buttons -->
                <div style="margin-top:20px;display:flex;gap:8px;">
                    <button type="button" class="button button-primary" data-status="draft" onclick="wbiPOSave('draft')">💾 Guardar Borrador</button>
                    <button type="button" class="button" style="background:#0073aa;color:#fff;border-color:#0073aa;" onclick="wbiPOSave('sent')">📧 Enviar al Proveedor</button>
                    <?php if ( $po && 'sent' === $po->status ) : ?>
                    <button type="button" class="button" style="background:#2ea32e;color:#fff;border-color:#2ea32e;" onclick="wbiPOSave('confirmed')">✅ Confirmar</button>
                    <?php endif; ?>
                    <a href="<?php echo esc_url( $list_url ); ?>" class="button">Cancelar</a>
                </div>
                <p id="wbi-po-save-msg" style="margin-top:12px;font-weight:600;display:none;"></p>
            </div>
        </div>

        <!-- Item row template (hidden) -->
        <template id="wbi-item-row-tpl">
            <tr class="wbi-po-item-row">
                <td>
                    <input type="hidden" class="item-product-id" value="">
                    <input type="hidden" class="item-id" value="0">
                    <input type="text" class="item-product-name" value="" placeholder="Buscar producto..." style="width:100%;" autocomplete="off">
                </td>
                <td><input type="text" class="item-sku" value="" style="width:90px;" readonly></td>
                <td><input type="number" class="item-qty" value="1" min="0.01" step="0.01" style="width:80px;"></td>
                <td><input type="number" class="item-cost" value="" min="0" step="0.01" style="width:100px;"></td>
                <td><input type="number" class="item-tax" value="21" min="0" max="100" step="0.01" style="width:70px;"></td>
                <td class="item-line-total" style="font-weight:600;">$0,00</td>
                <td><button type="button" class="button button-small wbi-remove-item" style="color:red;">✕</button></td>
            </tr>
        </template>

        <script>
        (function($){
            var ajaxurl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

            // ── Autocomplete ──────────────────────────────────────────────────
            $(document).on('input', '.item-product-name', function(){
                var $input = $(this);
                var term   = $input.val();
                if ( term.length < 2 ) return;

                var $row = $input.closest('tr');
                clearTimeout( $input.data('timer') );
                $input.data('timer', setTimeout(function(){
                    $.post( ajaxurl, {
                        action: 'wbi_purchase_search_products',
                        term: term,
                        nonce: $('#wbi_po_nonce').val()
                    }, function(res){
                        if ( ! res.success ) return;
                        // Remove old dropdown
                        $row.find('.wbi-product-dropdown').remove();
                        var $ul = $('<ul class="wbi-product-dropdown" style="position:absolute;background:#fff;border:1px solid #ccc;z-index:9999;list-style:none;margin:0;padding:0;min-width:260px;max-height:200px;overflow-y:auto;"></ul>');
                        $.each( res.data, function(i, p){
                            var $li = $('<li style="padding:6px 12px;cursor:pointer;">' + p.name + ' <small style="color:#888;">' + p.sku + '</small></li>');
                            $li.on('click', function(){
                                $row.find('.item-product-id').val( p.id );
                                $row.find('.item-product-name').val( p.name );
                                $row.find('.item-sku').val( p.sku );
                                $row.find('.item-cost').val( p.cost || '' );
                                $ul.remove();
                                wbiCalcTotals();
                            });
                            $ul.append($li);
                        });
                        $input.after($ul);
                    });
                }, 350));
            });

            $(document).on('click', function(e){
                if ( ! $(e.target).hasClass('item-product-name') ) {
                    $('.wbi-product-dropdown').remove();
                }
            });

            // ── Add / Remove rows ─────────────────────────────────────────────
            $('#wbi-add-item-row').on('click', function(){
                var tpl = document.getElementById('wbi-item-row-tpl');
                var clone = tpl.content.cloneNode(true);
                $('#wbi-po-items-body').append(clone);
            });

            $(document).on('click', '.wbi-remove-item', function(){
                $(this).closest('tr').remove();
                wbiCalcTotals();
            });

            // ── Recalc on input change ────────────────────────────────────────
            $(document).on('input change', '.item-qty, .item-cost, .item-tax', function(){
                var $row = $(this).closest('tr');
                var qty  = parseFloat( $row.find('.item-qty').val() ) || 0;
                var cost = parseFloat( $row.find('.item-cost').val() ) || 0;
                var tax  = parseFloat( $row.find('.item-tax').val() ) || 0;
                var net  = qty * cost;
                var tot  = net * (1 + tax / 100);
                $row.find('.item-line-total').text( wbiFormatMoney(tot) );
                wbiCalcTotals();
            });

            function wbiCalcTotals(){
                var subtotal = 0, taxamt = 0;
                $('#wbi-po-items-body tr').each(function(){
                    var qty  = parseFloat( $(this).find('.item-qty').val() ) || 0;
                    var cost = parseFloat( $(this).find('.item-cost').val() ) || 0;
                    var tax  = parseFloat( $(this).find('.item-tax').val() ) || 0;
                    var net  = qty * cost;
                    subtotal += net;
                    taxamt   += net * tax / 100;
                });
                $('#wbi-po-subtotal').text( wbiFormatMoney(subtotal) );
                $('#wbi-po-tax').text( wbiFormatMoney(taxamt) );
                $('#wbi-po-total').text( wbiFormatMoney(subtotal + taxamt) );
            }

            function wbiFormatMoney(n){ return '$' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, '.').replace('.', ',').replace(',', '.').replace(/\.(\d{2})$/, ',$1'); }

            // ── Save ──────────────────────────────────────────────────────────
            window.wbiPOSave = function( status ){
                var items = [];
                var valid = true;
                $('#wbi-po-items-body tr').each(function(){
                    var pid  = $(this).find('.item-product-id').val();
                    var qty  = parseFloat( $(this).find('.item-qty').val() );
                    var cost = parseFloat( $(this).find('.item-cost').val() );
                    var tax  = parseFloat( $(this).find('.item-tax').val() );
                    var name = $(this).find('.item-product-name').val();
                    var sku  = $(this).find('.item-sku').val();
                    var iid  = $(this).find('.item-id').val();
                    if ( ! pid || ! qty || isNaN(cost) ) { valid = false; return; }
                    items.push({ item_id: iid, product_id: pid, product_name: name, sku: sku, qty: qty, cost: cost, tax: tax });
                });
                if ( ! valid || items.length === 0 ) {
                    alert('Completá todos los ítems con producto, cantidad y costo.');
                    return;
                }

                <?php if ( $has_suppliers ) : ?>
                var supplier_id   = $('#wbi_po_supplier_id').val();
                var supplier_name = $('#wbi_po_supplier_id option:selected').data('name') || '';
                <?php else : ?>
                var supplier_id   = 0;
                var supplier_name = $('#wbi_po_supplier_name').val();
                <?php endif; ?>

                var data = {
                    action:        'wbi_purchase_save',
                    nonce:         $('#wbi_po_nonce').val(),
                    po_id:         $('#wbi_po_id').val(),
                    status:        status,
                    supplier_id:   supplier_id,
                    supplier_name: supplier_name,
                    order_date:    $('#wbi_po_order_date').val(),
                    expected_date: $('#wbi_po_expected_date').val(),
                    currency:      $('#wbi_po_currency').val(),
                    notes:         $('#wbi_po_notes').val(),
                    items:         JSON.stringify(items)
                };

                $('#wbi-po-save-msg').text('Guardando...').css('color','#0073aa').show();
                $.post( ajaxurl, data, function(res){
                    if ( res.success ) {
                        $('#wbi-po-save-msg').text('✅ ' + res.data.message).css('color','#2ea32e');
                        setTimeout(function(){ window.location = '<?php echo esc_js( admin_url( 'admin.php?page=wbi-purchase&action=view&po_id=' ) ); ?>' + res.data.po_id; }, 800);
                    } else {
                        $('#wbi-po-save-msg').text('❌ ' + res.data).css('color','#cc0000');
                    }
                });
            };

            // Init totals on load
            wbiCalcTotals();

        })(jQuery);
        </script>
        <?php
    }

    // =========================================================================
    // VIEW / RECEIVE PAGE
    // =========================================================================

    private function render_view_page( $po_id ) {
        global $wpdb;
        $table           = $wpdb->prefix . 'wbi_purchase_orders';
        $items_table     = $wpdb->prefix . 'wbi_purchase_order_items';
        $receipts_table  = $wpdb->prefix . 'wbi_purchase_receipts';
        $ri_table        = $wpdb->prefix . 'wbi_purchase_receipt_items';

        $po = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $po_id ) );
        if ( ! $po ) {
            echo '<div class="wrap"><p>Orden no encontrada. <a href="' . esc_url( admin_url( 'admin.php?page=wbi-purchase' ) ) . '">← Volver</a></p></div>';
            return;
        }

        $items    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$items_table} WHERE po_id = %d ORDER BY id ASC", $po_id ) );
        $receipts = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*, u.display_name AS received_by_name FROM {$receipts_table} r LEFT JOIN {$wpdb->users} u ON r.received_by = u.ID WHERE r.po_id = %d ORDER BY r.received_at DESC",
            $po_id
        ) );

        $statuses   = $this->get_statuses();
        $status_cfg = $statuses[ $po->status ] ?? array( 'label' => $po->status, 'color' => '#888' );
        $list_url   = admin_url( 'admin.php?page=wbi-purchase' );
        $edit_url   = admin_url( 'admin.php?page=wbi-purchase&action=edit&po_id=' . $po_id );
        $nonce      = wp_create_nonce( 'wbi_purchase_receive' );
        $status_nonce = wp_create_nonce( 'wbi_purchase_status' );
        $can_receive = in_array( $po->status, array( 'confirmed', 'partial', 'sent' ), true );
        ?>
        <div class="wrap" id="wbi-po-view-wrap">
            <h1>
                Orden <?php echo esc_html( $po->po_number ); ?>
                <span style="background:<?php echo esc_attr( $status_cfg['color'] ); ?>;color:#fff;padding:3px 12px;border-radius:10px;font-size:13px;margin-left:10px;"><?php echo esc_html( $status_cfg['label'] ); ?></span>
                <a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action">← Volver</a>
                <?php if ( in_array( $po->status, array( 'draft', 'sent' ), true ) ) : ?>
                <a href="<?php echo esc_url( $edit_url ); ?>" class="page-title-action">✏️ Editar</a>
                <?php endif; ?>
                <button type="button" onclick="window.print()" class="page-title-action">🖨️ Imprimir</button>
            </h1>

            <!-- PO Header info -->
            <div style="display:flex;flex-wrap:wrap;gap:32px;margin:20px 0;background:#fff;padding:20px;border:1px solid #ddd;border-radius:4px;">
                <div><strong>Proveedor:</strong><br><?php echo esc_html( $po->supplier_name ?: '—' ); ?></div>
                <div><strong>Fecha Orden:</strong><br><?php echo esc_html( $po->order_date ); ?></div>
                <div><strong>Fecha Esperada:</strong><br><?php echo esc_html( $po->expected_date ?: '—' ); ?></div>
                <div><strong>Fecha Recibida:</strong><br><?php echo esc_html( $po->received_date ?: '—' ); ?></div>
                <div><strong>Moneda:</strong><br><?php echo esc_html( $po->currency ); ?></div>
                <?php if ( $po->notes ) : ?>
                <div style="flex-basis:100%;"><strong>Notas:</strong><br><?php echo nl2br( esc_html( $po->notes ) ); ?></div>
                <?php endif; ?>
            </div>

            <!-- Items table -->
            <h3>Artículos</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Producto</th><th>SKU</th><th>Cant. Pedida</th><th>Cant. Recibida</th><th>Pendiente</th>
                        <th style="text-align:right;">Costo Unit.</th><th style="text-align:right;">IVA %</th><th style="text-align:right;">Total Línea</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $items as $item ) :
                    $pending = max( 0, $item->quantity_ordered - $item->quantity_received );
                ?>
                <tr>
                    <td><?php echo esc_html( $item->product_name ); ?></td>
                    <td><?php echo esc_html( $item->sku ?: '—' ); ?></td>
                    <td><?php echo esc_html( $item->quantity_ordered ); ?></td>
                    <td style="color:<?php echo $item->quantity_received > 0 ? '#2ea32e' : '#888'; ?>;"><?php echo esc_html( $item->quantity_received ); ?></td>
                    <td style="color:<?php echo $pending > 0 ? '#cc0000' : '#2ea32e'; ?>;"><?php echo esc_html( $pending ); ?></td>
                    <td style="text-align:right;"><?php echo wc_price( $item->unit_cost ); ?></td>
                    <td style="text-align:right;"><?php echo esc_html( $item->tax_rate ); ?>%</td>
                    <td style="text-align:right;"><?php echo wc_price( $item->line_total ); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="7" style="text-align:right;"><strong>Subtotal:</strong></td>
                        <td style="text-align:right;font-weight:600;"><?php echo wc_price( $po->subtotal ); ?></td>
                    </tr>
                    <tr>
                        <td colspan="7" style="text-align:right;"><strong>IVA:</strong></td>
                        <td style="text-align:right;font-weight:600;"><?php echo wc_price( $po->tax_amount ); ?></td>
                    </tr>
                    <tr>
                        <td colspan="7" style="text-align:right;font-size:14px;"><strong>Total:</strong></td>
                        <td style="text-align:right;font-size:14px;font-weight:700;"><?php echo wc_price( $po->total ); ?></td>
                    </tr>
                </tfoot>
            </table>

            <!-- Status actions -->
            <div style="margin-top:20px;display:flex;gap:8px;flex-wrap:wrap;" class="no-print">
                <?php if ( 'draft' === $po->status ) : ?>
                <button class="button" style="background:#0073aa;color:#fff;border-color:#0073aa;" onclick="wbiUpdateStatus(<?php echo intval( $po_id ); ?>,'sent','<?php echo esc_js( $status_nonce ); ?>')">📧 Enviar al Proveedor</button>
                <?php endif; ?>
                <?php if ( 'sent' === $po->status ) : ?>
                <button class="button" style="background:#2ea32e;color:#fff;border-color:#2ea32e;" onclick="wbiUpdateStatus(<?php echo intval( $po_id ); ?>,'confirmed','<?php echo esc_js( $status_nonce ); ?>')">✅ Confirmar</button>
                <?php endif; ?>
                <?php if ( in_array( $po->status, array( 'draft', 'sent' ), true ) ) : ?>
                <button class="button" style="color:#cc0000;border-color:#cc0000;" onclick="wbiUpdateStatus(<?php echo intval( $po_id ); ?>,'cancelled','<?php echo esc_js( $status_nonce ); ?>')">🚫 Cancelar</button>
                <?php endif; ?>
                <?php if ( $po->status === 'draft' ) : ?>
                <button class="button" style="color:#cc0000;" onclick="wbiDeletePO(<?php echo intval( $po_id ); ?>,'<?php echo esc_js( wp_create_nonce( 'wbi_purchase_delete' ) ); ?>')">🗑️ Eliminar</button>
                <?php endif; ?>
            </div>

            <!-- Receive goods form -->
            <?php if ( $can_receive ) : ?>
            <div id="wbi-receive-section" style="margin-top:32px;background:#f0f8f0;border:1px solid #b5e7b5;padding:20px;border-radius:4px;" class="no-print">
                <h3>📦 Recibir Mercadería</h3>
                <input type="hidden" id="wbi_receive_nonce" value="<?php echo esc_attr( $nonce ); ?>">
                <table class="widefat" style="margin-bottom:12px;">
                    <thead><tr><th>Producto</th><th>SKU</th><th>Pendiente</th><th>Cant. a Recibir</th></tr></thead>
                    <tbody>
                    <?php foreach ( $items as $item ) :
                        $pending = max( 0, $item->quantity_ordered - $item->quantity_received );
                        if ( $pending <= 0 ) continue;
                    ?>
                    <tr>
                        <td><?php echo esc_html( $item->product_name ); ?></td>
                        <td><?php echo esc_html( $item->sku ?: '—' ); ?></td>
                        <td><?php echo esc_html( $pending ); ?></td>
                        <td>
                            <input type="number" class="receive-qty"
                                   data-item-id="<?php echo esc_attr( $item->id ); ?>"
                                   data-product-id="<?php echo esc_attr( $item->product_id ); ?>"
                                   value="<?php echo esc_attr( $pending ); ?>"
                                   min="0" max="<?php echo esc_attr( $pending ); ?>" step="0.01"
                                   style="width:100px;">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div>
                    <label><strong>Notas de recepción:</strong></label><br>
                    <textarea id="wbi_receive_notes" rows="2" class="large-text" style="max-width:600px;"></textarea>
                </div>
                <button type="button" class="button button-primary" style="margin-top:12px;" onclick="wbiReceiveGoods(<?php echo intval( $po_id ); ?>)">✅ Confirmar Recepción</button>
                <p id="wbi-receive-msg" style="font-weight:600;display:none;margin-top:8px;"></p>
            </div>
            <?php endif; ?>

            <!-- Receipt history -->
            <?php if ( ! empty( $receipts ) ) : ?>
            <div style="margin-top:32px;">
                <h3>Historial de Recepciones</h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th>Nro. Recepción</th><th>Fecha</th><th>Recibido por</th><th>Notas</th></tr></thead>
                    <tbody>
                    <?php foreach ( $receipts as $rec ) : ?>
                    <tr>
                        <td style="font-weight:600;"><?php echo esc_html( $rec->receipt_number ); ?></td>
                        <td><?php echo esc_html( $rec->received_at ); ?></td>
                        <td><?php echo esc_html( $rec->received_by_name ?: 'Usuario #' . $rec->received_by ); ?></td>
                        <td><?php echo esc_html( $rec->notes ?: '—' ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <style>
        @media print {
            .no-print { display:none !important; }
            #adminmenumain, #wpadminbar, #wpfooter { display:none !important; }
            #wpcontent { margin-left:0 !important; }
        }
        </style>

        <script>
        (function($){
            var ajaxurl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

            window.wbiUpdateStatus = function( po_id, status, nonce ){
                if ( ! confirm('¿Confirmar cambio de estado?') ) return;
                $.post( ajaxurl, { action:'wbi_purchase_update_status', nonce:nonce, po_id:po_id, status:status }, function(res){
                    if ( res.success ) { location.reload(); }
                    else { alert( res.data || 'Error al actualizar estado.' ); }
                });
            };

            window.wbiDeletePO = function( po_id, nonce ){
                if ( ! confirm('¿Eliminar esta orden de compra? Esta acción no se puede deshacer.') ) return;
                $.post( ajaxurl, { action:'wbi_purchase_delete', nonce:nonce, po_id:po_id }, function(res){
                    if ( res.success ) { window.location = '<?php echo esc_js( admin_url( 'admin.php?page=wbi-purchase' ) ); ?>'; }
                    else { alert( res.data || 'Error al eliminar.' ); }
                });
            };

            window.wbiReceiveGoods = function( po_id ){
                var items = [];
                $('.receive-qty').each(function(){
                    var qty = parseFloat( $(this).val() );
                    if ( qty > 0 ) {
                        items.push({ item_id: $(this).data('item-id'), product_id: $(this).data('product-id'), qty: qty });
                    }
                });
                if ( items.length === 0 ) { alert('Ingresá al menos una cantidad a recibir.'); return; }

                $('#wbi-receive-msg').text('Procesando...').css('color','#0073aa').show();
                $.post( ajaxurl, {
                    action:  'wbi_purchase_receive',
                    nonce:   $('#wbi_receive_nonce').val(),
                    po_id:   po_id,
                    items:   JSON.stringify(items),
                    notes:   $('#wbi_receive_notes').val()
                }, function(res){
                    if ( res.success ) {
                        $('#wbi-receive-msg').text('✅ ' + res.data.message).css('color','#2ea32e');
                        setTimeout(function(){ location.reload(); }, 1200);
                    } else {
                        $('#wbi-receive-msg').text('❌ ' + res.data).css('color','#cc0000');
                    }
                });
            };
        })(jQuery);
        </script>
        <?php
    }

    // =========================================================================
    // REPORTS PAGE
    // =========================================================================

    private function render_reports_page() {
        global $wpdb;
        $orders_table = $wpdb->prefix . 'wbi_purchase_orders';
        $items_table  = $wpdb->prefix . 'wbi_purchase_order_items';

        // Purchases by month (last 12 months)
        $by_month = $wpdb->get_results(
            "SELECT DATE_FORMAT(order_date,'%Y-%m') AS month, SUM(total) AS total, COUNT(*) AS cnt
             FROM {$orders_table}
             WHERE status NOT IN ('cancelled','draft') AND order_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
             GROUP BY month ORDER BY month ASC"
        );

        // Top suppliers by volume
        $top_suppliers = $wpdb->get_results(
            "SELECT supplier_name, SUM(total) AS total, COUNT(*) AS cnt
             FROM {$orders_table}
             WHERE status NOT IN ('cancelled','draft')
             GROUP BY supplier_name ORDER BY total DESC LIMIT 10"
        );

        // Pending deliveries
        $pending = $wpdb->get_results(
            "SELECT po.*, SUM(i.quantity_ordered - i.quantity_received) AS pending_qty
             FROM {$orders_table} po
             JOIN {$items_table} i ON i.po_id = po.id
             WHERE po.status IN ('confirmed','partial','sent')
             GROUP BY po.id
             ORDER BY po.expected_date ASC LIMIT 20"
        );

        // Average lead time (confirmed → received)
        $avg_lead = $wpdb->get_var(
            "SELECT AVG(DATEDIFF(received_date, order_date))
             FROM {$orders_table}
             WHERE status = 'received' AND received_date IS NOT NULL AND order_date IS NOT NULL"
        );

        $max_month = 0;
        foreach ( $by_month as $m ) {
            if ( (float) $m->total > $max_month ) $max_month = (float) $m->total;
        }
        ?>
        <div class="wrap">
            <h1>📊 Reportes de Compras <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-purchase' ) ); ?>" class="page-title-action">← Órdenes</a></h1>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:20px;">

                <!-- Purchases by month -->
                <div style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:4px;">
                    <h3>Compras por Mes (últimos 12 meses)</h3>
                    <?php if ( empty( $by_month ) ) : ?>
                        <p style="color:#888;">Sin datos.</p>
                    <?php else : ?>
                    <div style="display:flex;align-items:flex-end;gap:6px;height:160px;padding-bottom:8px;">
                    <?php foreach ( $by_month as $m ) :
                        $pct = $max_month > 0 ? ( (float) $m->total / $max_month * 100 ) : 0;
                    ?>
                        <div style="display:flex;flex-direction:column;align-items:center;flex:1;height:100%;">
                            <div title="<?php echo esc_attr( wc_price( $m->total ) ); ?>"
                                 style="background:#0073aa;width:100%;height:<?php echo esc_attr( number_format( $pct, 1 ) ); ?>%;border-radius:2px 2px 0 0;min-height:2px;"></div>
                            <small style="font-size:9px;color:#555;margin-top:4px;writing-mode:vertical-rl;"><?php echo esc_html( $m->month ); ?></small>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Top suppliers -->
                <div style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:4px;">
                    <h3>Top Proveedores por Volumen</h3>
                    <?php if ( empty( $top_suppliers ) ) : ?>
                        <p style="color:#888;">Sin datos.</p>
                    <?php else : ?>
                    <table class="widefat striped">
                        <thead><tr><th>Proveedor</th><th>OC</th><th style="text-align:right;">Total</th></tr></thead>
                        <tbody>
                        <?php foreach ( $top_suppliers as $s ) : ?>
                        <tr>
                            <td><?php echo esc_html( $s->supplier_name ?: '—' ); ?></td>
                            <td><?php echo esc_html( $s->cnt ); ?></td>
                            <td style="text-align:right;"><?php echo wc_price( $s->total ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>

                <!-- Pending deliveries -->
                <div style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:4px;">
                    <h3>Entregas Pendientes</h3>
                    <?php if ( empty( $pending ) ) : ?>
                        <p style="color:#888;">No hay entregas pendientes.</p>
                    <?php else : ?>
                    <table class="widefat striped">
                        <thead><tr><th>OC</th><th>Proveedor</th><th>Fecha Esp.</th><th>Cant. Pendiente</th></tr></thead>
                        <tbody>
                        <?php foreach ( $pending as $p ) :
                            $overdue = $p->expected_date && $p->expected_date < gmdate( 'Y-m-d' );
                        ?>
                        <tr style="<?php echo $overdue ? 'background:#fff5f5;' : ''; ?>">
                            <td><a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-purchase&action=view&po_id=' . $p->id ) ); ?>"><?php echo esc_html( $p->po_number ); ?></a></td>
                            <td><?php echo esc_html( $p->supplier_name ?: '—' ); ?></td>
                            <td style="color:<?php echo $overdue ? '#cc0000' : 'inherit'; ?>;"><?php echo esc_html( $p->expected_date ?: '—' ); ?><?php echo $overdue ? ' ⚠️' : ''; ?></td>
                            <td><?php echo esc_html( number_format( (float) $p->pending_qty, 2 ) ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>

                <!-- Average lead time -->
                <div style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:4px;">
                    <h3>Lead Time Promedio</h3>
                    <div style="text-align:center;padding:30px 0;">
                        <span style="font-size:52px;font-weight:700;color:#0073aa;"><?php echo $avg_lead ? esc_html( number_format( (float) $avg_lead, 1 ) ) : '—'; ?></span>
                        <p style="color:#555;margin:4px 0 0;">días promedio (orden → recepción)</p>
                    </div>
                </div>

            </div>
        </div>
        <?php
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    public function ajax_search_products() {
        check_ajax_referer( 'wbi_purchase_save', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Sin permisos' );

        $term = sanitize_text_field( wp_unslash( $_POST['term'] ?? '' ) );
        if ( strlen( $term ) < 2 ) wp_send_json_success( array() );

        $args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 15,
            's'              => $term,
        );
        $products = get_posts( $args );
        $results  = array();
        foreach ( $products as $p ) {
            $sku  = get_post_meta( $p->ID, '_sku', true );
            $cost = get_post_meta( $p->ID, '_wbi_cost_price', true );
            if ( ! $cost ) {
                $cost = get_post_meta( $p->ID, '_price', true );
            }
            $results[] = array(
                'id'   => $p->ID,
                'name' => $p->post_title,
                'sku'  => $sku ?: '',
                'cost' => $cost ? number_format( (float) $cost, 2, '.', '' ) : '',
            );
        }
        // Also search by SKU if term looks like a SKU
        if ( empty( $results ) ) {
            global $wpdb;
            $sku_products = $wpdb->get_results( $wpdb->prepare(
                "SELECT p.ID, p.post_title, pm.meta_value AS sku
                 FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
                 WHERE p.post_type = 'product' AND p.post_status = 'publish'
                   AND pm.meta_value LIKE %s
                 LIMIT 10",
                '%' . $wpdb->esc_like( $term ) . '%'
            ) );
            foreach ( $sku_products as $p ) {
                $cost = get_post_meta( $p->ID, '_wbi_cost_price', true ) ?: get_post_meta( $p->ID, '_price', true );
                $results[] = array(
                    'id'   => $p->ID,
                    'name' => $p->post_title,
                    'sku'  => $p->sku,
                    'cost' => $cost ? number_format( (float) $cost, 2, '.', '' ) : '',
                );
            }
        }
        wp_send_json_success( $results );
    }

    public function ajax_save_po() {
        check_ajax_referer( 'wbi_purchase_save', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Sin permisos' );

        global $wpdb;
        $table       = $wpdb->prefix . 'wbi_purchase_orders';
        $items_table = $wpdb->prefix . 'wbi_purchase_order_items';

        $po_id        = absint( $_POST['po_id'] ?? 0 );
        $status       = sanitize_key( $_POST['status'] ?? 'draft' );
        $supplier_id  = absint( $_POST['supplier_id'] ?? 0 );
        $supplier_name = sanitize_text_field( wp_unslash( $_POST['supplier_name'] ?? '' ) );
        $order_date   = sanitize_text_field( wp_unslash( $_POST['order_date'] ?? '' ) );
        $expected_date = sanitize_text_field( wp_unslash( $_POST['expected_date'] ?? '' ) );
        $currency     = sanitize_text_field( wp_unslash( $_POST['currency'] ?? 'ARS' ) );
        $notes        = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
        $items_json   = wp_unslash( $_POST['items'] ?? '[]' );

        $valid_statuses = array_keys( $this->get_statuses() );
        if ( ! in_array( $status, $valid_statuses, true ) ) {
            wp_send_json_error( 'Estado inválido.' );
        }

        if ( empty( $order_date ) ) {
            wp_send_json_error( 'La fecha de orden es obligatoria.' );
        }

        $items = json_decode( $items_json, true );
        if ( ! is_array( $items ) || empty( $items ) ) {
            wp_send_json_error( 'Se requiere al menos un ítem.' );
        }

        // Calculate totals
        $subtotal   = 0.0;
        $tax_amount = 0.0;
        foreach ( $items as &$item ) {
            $qty  = (float) ( $item['qty'] ?? 0 );
            $cost = (float) ( $item['cost'] ?? 0 );
            $tax  = (float) ( $item['tax'] ?? 21 );
            $net  = $qty * $cost;
            $item['line_total'] = $net * ( 1 + $tax / 100 );
            $subtotal   += $net;
            $tax_amount += $net * $tax / 100;
        }
        unset( $item );
        $total = $subtotal + $tax_amount;

        // Get supplier name from supplier module if ID provided
        if ( $supplier_id > 0 && empty( $supplier_name ) ) {
            $supplier_post = get_post( $supplier_id );
            if ( $supplier_post ) {
                $supplier_name = $supplier_post->post_title;
            }
        }

        if ( $po_id > 0 ) {
            // Update existing PO
            $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $po_id ) );
            if ( ! $existing ) {
                wp_send_json_error( 'Orden no encontrada.' );
            }
            if ( ! in_array( $existing->status, array( 'draft', 'sent' ), true ) ) {
                wp_send_json_error( 'No se puede editar una orden en estado: ' . $existing->status );
            }

            $wpdb->update( $table, array(
                'status'        => $status,
                'supplier_id'   => $supplier_id,
                'supplier_name' => $supplier_name,
                'order_date'    => $order_date,
                'expected_date' => $expected_date ?: null,
                'subtotal'      => $subtotal,
                'tax_amount'    => $tax_amount,
                'total'         => $total,
                'currency'      => $currency,
                'notes'         => $notes,
            ), array( 'id' => $po_id ), array( '%s','%d','%s','%s','%s','%f','%f','%f','%s','%s' ), array( '%d' ) );

            // Delete existing items and re-insert
            $wpdb->delete( $items_table, array( 'po_id' => $po_id ), array( '%d' ) );
        } else {
            // Insert new PO
            $po_number = $this->generate_po_number();
            $wpdb->insert( $table, array(
                'po_number'     => $po_number,
                'status'        => $status,
                'supplier_id'   => $supplier_id,
                'supplier_name' => $supplier_name,
                'order_date'    => $order_date,
                'expected_date' => $expected_date ?: null,
                'subtotal'      => $subtotal,
                'tax_amount'    => $tax_amount,
                'total'         => $total,
                'currency'      => $currency,
                'notes'         => $notes,
                'created_by'    => get_current_user_id(),
            ), array( '%s','%s','%d','%s','%s','%s','%f','%f','%f','%s','%s','%d' ) );
            $po_id = (int) $wpdb->insert_id;
        }

        // Insert items
        foreach ( $items as $item ) {
            $wpdb->insert( $items_table, array(
                'po_id'            => $po_id,
                'product_id'       => absint( $item['product_id'] ?? 0 ),
                'product_name'     => sanitize_text_field( $item['product_name'] ?? '' ),
                'sku'              => sanitize_text_field( $item['sku'] ?? '' ),
                'quantity_ordered' => (float) ( $item['qty'] ?? 0 ),
                'quantity_received'=> 0,
                'unit_cost'        => (float) ( $item['cost'] ?? 0 ),
                'tax_rate'         => (float) ( $item['tax'] ?? 21 ),
                'line_total'       => (float) ( $item['line_total'] ?? 0 ),
            ), array( '%d','%d','%s','%s','%f','%f','%f','%f','%f' ) );
        }

        // Fire notification if module is active
        $this->fire_notification( 'po_saved', $po_id, $status );

        wp_send_json_success( array(
            'po_id'   => $po_id,
            'message' => 'Orden guardada correctamente.',
        ) );
    }

    public function ajax_receive() {
        check_ajax_referer( 'wbi_purchase_receive', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Sin permisos' );

        global $wpdb;
        $table           = $wpdb->prefix . 'wbi_purchase_orders';
        $items_table     = $wpdb->prefix . 'wbi_purchase_order_items';
        $receipts_table  = $wpdb->prefix . 'wbi_purchase_receipts';
        $ri_table        = $wpdb->prefix . 'wbi_purchase_receipt_items';

        $po_id      = absint( $_POST['po_id'] ?? 0 );
        $notes      = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
        $items_json = wp_unslash( $_POST['items'] ?? '[]' );

        $po = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $po_id ) );
        if ( ! $po ) wp_send_json_error( 'Orden no encontrada.' );
        if ( ! in_array( $po->status, array( 'confirmed', 'partial', 'sent' ), true ) ) {
            wp_send_json_error( 'La orden no está en un estado válido para recibir.' );
        }

        $items = json_decode( $items_json, true );
        if ( ! is_array( $items ) || empty( $items ) ) {
            wp_send_json_error( 'Se requiere al menos un ítem a recibir.' );
        }

        // Create receipt
        $receipt_number = $this->generate_receipt_number();
        $wpdb->insert( $receipts_table, array(
            'po_id'          => $po_id,
            'receipt_number' => $receipt_number,
            'received_by'    => get_current_user_id(),
            'notes'          => $notes,
        ), array( '%d','%s','%d','%s' ) );
        $receipt_id = (int) $wpdb->insert_id;

        foreach ( $items as $item ) {
            $item_id    = absint( $item['item_id'] ?? 0 );
            $product_id = absint( $item['product_id'] ?? 0 );
            $qty        = (float) ( $item['qty'] ?? 0 );
            if ( $qty <= 0 || ! $item_id ) continue;

            // Insert receipt item
            $wpdb->insert( $ri_table, array(
                'receipt_id'        => $receipt_id,
                'po_item_id'        => $item_id,
                'product_id'        => $product_id,
                'quantity_received' => $qty,
            ), array( '%d','%d','%d','%f' ) );

            // Update quantity_received on PO item
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$items_table} SET quantity_received = quantity_received + %f WHERE id = %d",
                $qty, $item_id
            ) );

            // Update WooCommerce stock
            if ( $product_id && function_exists( 'wc_update_product_stock' ) ) {
                wc_update_product_stock( $product_id, $qty, 'increase' );
            }

            // Update cost meta if Costs module is active
            $po_item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$items_table} WHERE id = %d", $item_id ) );
            $wbi_opts = get_option( 'wbi_modules_settings', array() );
            if ( $po_item && $product_id && ( $wbi_opts['wbi_enable_costs'] ?? false ) ) {
                update_post_meta( $product_id, '_wbi_cost_price', $po_item->unit_cost );
                update_post_meta( $product_id, '_wbi_cost', $po_item->unit_cost );
            }

            // Resolve stock alerts if module is active
            if ( class_exists( 'WBI_Stock_Alerts' ) ) {
                $this->maybe_resolve_stock_alert( $product_id );
            }
        }

        // Update PO status based on remaining qty
        $remaining = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$items_table}
             WHERE po_id = %d AND quantity_received < quantity_ordered",
            $po_id
        ) );
        $new_status    = ( $remaining === 0 ) ? 'received' : 'partial';
        $received_date = ( $remaining === 0 ) ? gmdate( 'Y-m-d' ) : null;

        $update_data   = array( 'status' => $new_status );
        $update_format = array( '%s' );
        if ( $received_date ) {
            $update_data['received_date'] = $received_date;
            $update_format[]              = '%s';
        }
        $wpdb->update( $table, $update_data, array( 'id' => $po_id ), $update_format, array( '%d' ) );

        // Fire notification
        $this->fire_notification( 'po_received', $po_id, $new_status );

        wp_send_json_success( array(
            'message' => 'Mercadería recibida. Recepción: ' . $receipt_number,
            'status'  => $new_status,
        ) );
    }

    public function ajax_update_status() {
        check_ajax_referer( 'wbi_purchase_status', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Sin permisos' );

        global $wpdb;
        $table  = $wpdb->prefix . 'wbi_purchase_orders';
        $po_id  = absint( $_POST['po_id'] ?? 0 );
        $status = sanitize_key( $_POST['status'] ?? '' );

        if ( ! array_key_exists( $status, $this->get_statuses() ) ) {
            wp_send_json_error( 'Estado inválido.' );
        }

        $po = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $po_id ) );
        if ( ! $po ) wp_send_json_error( 'Orden no encontrada.' );

        $wpdb->update( $table, array( 'status' => $status ), array( 'id' => $po_id ), array( '%s' ), array( '%d' ) );

        $this->fire_notification( 'po_status_changed', $po_id, $status );

        wp_send_json_success( array( 'status' => $status ) );
    }

    public function ajax_delete_po() {
        check_ajax_referer( 'wbi_purchase_delete', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Sin permisos' );

        global $wpdb;
        $table       = $wpdb->prefix . 'wbi_purchase_orders';
        $items_table = $wpdb->prefix . 'wbi_purchase_order_items';
        $po_id       = absint( $_POST['po_id'] ?? 0 );

        $po = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $po_id ) );
        if ( ! $po ) wp_send_json_error( 'Orden no encontrada.' );
        if ( 'draft' !== $po->status ) {
            wp_send_json_error( 'Solo se pueden eliminar órdenes en estado Borrador.' );
        }

        $wpdb->delete( $items_table, array( 'po_id' => $po_id ), array( '%d' ) );
        $wpdb->delete( $table, array( 'id' => $po_id ), array( '%d' ) );

        wp_send_json_success( array( 'message' => 'Orden eliminada.' ) );
    }

    // =========================================================================
    // DASHBOARD WIDGET
    // =========================================================================

    public function register_dashboard_widget() {
        $opts = get_option( 'wbi_modules_settings', array() );
        if ( empty( $opts['wbi_enable_dashboard'] ) ) return;
        wp_add_dashboard_widget(
            'wbi_purchase_widget',
            '🛒 Órdenes de Compra',
            array( $this, 'render_dashboard_widget' )
        );
    }

    public function render_dashboard_widget() {
        global $wpdb;
        $table = $wpdb->prefix . 'wbi_purchase_orders';

        $open_pos = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE status IN ('draft','sent','confirmed','partial')"
        );
        $pending_value = (float) $wpdb->get_var(
            "SELECT SUM(total) FROM {$table} WHERE status IN ('confirmed','partial')"
        );
        $overdue = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status IN ('confirmed','partial') AND expected_date < %s",
            gmdate( 'Y-m-d' )
        ) );

        echo '<ul style="margin:0;padding:0;list-style:none;">';
        echo '<li style="padding:6px 0;border-bottom:1px solid #eee;">📋 OC abiertas: <strong>' . esc_html( $open_pos ) . '</strong></li>';
        echo '<li style="padding:6px 0;border-bottom:1px solid #eee;">⏳ Valor pendiente: <strong>' . wp_kses_post( wc_price( $pending_value ) ) . '</strong></li>';
        echo '<li style="padding:6px 0;' . ( $overdue ? 'color:#cc0000;' : '' ) . '">⚠️ Entregas vencidas: <strong>' . esc_html( $overdue ) . '</strong></li>';
        echo '</ul>';
        echo '<p style="margin-top:12px;"><a href="' . esc_url( admin_url( 'admin.php?page=wbi-purchase' ) ) . '" class="button button-small">Ver Órdenes</a></p>';
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function get_statuses() {
        return array(
            'draft'     => array( 'label' => 'Borrador',   'color' => '#888888' ),
            'sent'      => array( 'label' => 'Enviada',    'color' => '#0073aa' ),
            'confirmed' => array( 'label' => 'Confirmada', 'color' => '#2ea32e' ),
            'partial'   => array( 'label' => 'Parcial',    'color' => '#f56e28' ),
            'received'  => array( 'label' => 'Recibida',   'color' => '#00a0d2' ),
            'cancelled' => array( 'label' => 'Cancelada',  'color' => '#cc0000' ),
        );
    }

    private function get_suppliers_list() {
        // Try Suppliers module (CPT-based)
        if ( post_type_exists( 'wbi_supplier' ) ) {
            $posts = get_posts( array(
                'post_type'      => 'wbi_supplier',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ) );
            if ( ! empty( $posts ) ) {
                return array_map( function( $p ) {
                    return array( 'id' => $p->ID, 'name' => $p->post_title );
                }, $posts );
            }
        }
        return array();
    }

    private function fire_notification( $event, $po_id, $status ) {
        if ( ! class_exists( 'WBI_Notifications_Module' ) ) return;
        global $wpdb;
        $table = $wpdb->prefix . 'wbi_purchase_orders';
        $po    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $po_id ) );
        if ( ! $po ) return;

        $statuses = $this->get_statuses();
        $status_label = $statuses[ $status ]['label'] ?? $status;

        $message = sprintf(
            'OC %s — %s | Proveedor: %s | Total: %s',
            $po->po_number,
            $status_label,
            $po->supplier_name ?: '—',
            wc_price( $po->total )
        );

        if ( method_exists( 'WBI_Notifications_Module', 'add_notification' ) ) {
            WBI_Notifications_Module::add_notification( 'purchase', $message, 'info' );
        }
    }

    private function maybe_resolve_stock_alert( $product_id ) {
        if ( ! class_exists( 'WBI_Stock_Alerts' ) ) return;
        // WBI_Stock_Alerts uses transient-based alerts; clearing the transient
        // forces a re-evaluation on next page load.
        $wbi_opts  = get_option( 'wbi_modules_settings', array() );
        $threshold = (int) ( $wbi_opts['wbi_stock_threshold'] ?? 5 );
        $stock     = (float) get_post_meta( $product_id, '_stock', true );
        if ( $stock > $threshold ) {
            // Stock is now above threshold — clear any cached alerts
            delete_transient( 'wbi_stock_alert_products' );
        }
    }

    // =========================================================================
    // ENQUEUE ASSETS
    // =========================================================================

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'wbi-purchase' ) === false ) return;
        wp_enqueue_script( 'jquery' );
        wp_enqueue_style( 'wbi-admin', plugin_dir_url( dirname( __FILE__ ) ) . 'assets/admin.css', array(), '8.2.0' );
    }

    // =========================================================================
    // PUBLIC API — for Reorder Rules module
    // =========================================================================

    /**
     * Create a draft PO programmatically (used by Reorder Rules module).
     *
     * @param int    $supplier_id   Supplier post ID (0 if none).
     * @param string $supplier_name Supplier name.
     * @param array  $items         Array of ['product_id','product_name','sku','qty','cost','tax'].
     * @return int|false New PO ID or false on failure.
     */
    public function create_draft_po( $supplier_id, $supplier_name, $items ) {
        global $wpdb;
        $table       = $wpdb->prefix . 'wbi_purchase_orders';
        $items_table = $wpdb->prefix . 'wbi_purchase_order_items';

        if ( empty( $items ) ) return false;

        $subtotal   = 0.0;
        $tax_amount = 0.0;
        foreach ( $items as &$item ) {
            $qty  = (float) ( $item['qty'] ?? 1 );
            $cost = (float) ( $item['cost'] ?? 0 );
            $tax  = (float) ( $item['tax'] ?? 21 );
            $net  = $qty * $cost;
            $item['line_total'] = $net * ( 1 + $tax / 100 );
            $subtotal   += $net;
            $tax_amount += $net * $tax / 100;
        }
        unset( $item );

        $po_number = $this->generate_po_number();
        $result    = $wpdb->insert( $table, array(
            'po_number'     => $po_number,
            'status'        => 'draft',
            'supplier_id'   => absint( $supplier_id ),
            'supplier_name' => sanitize_text_field( $supplier_name ),
            'order_date'    => gmdate( 'Y-m-d' ),
            'subtotal'      => $subtotal,
            'tax_amount'    => $tax_amount,
            'total'         => $subtotal + $tax_amount,
            'currency'      => 'ARS',
            'created_by'    => get_current_user_id() ?: 1,
        ), array( '%s','%s','%d','%s','%s','%f','%f','%f','%s','%d' ) );

        if ( ! $result ) return false;
        $po_id = (int) $wpdb->insert_id;

        foreach ( $items as $item ) {
            $wpdb->insert( $items_table, array(
                'po_id'            => $po_id,
                'product_id'       => absint( $item['product_id'] ?? 0 ),
                'product_name'     => sanitize_text_field( $item['product_name'] ?? '' ),
                'sku'              => sanitize_text_field( $item['sku'] ?? '' ),
                'quantity_ordered' => (float) ( $item['qty'] ?? 1 ),
                'quantity_received'=> 0,
                'unit_cost'        => (float) ( $item['cost'] ?? 0 ),
                'tax_rate'         => (float) ( $item['tax'] ?? 21 ),
                'line_total'       => (float) ( $item['line_total'] ?? 0 ),
            ), array( '%d','%d','%s','%s','%f','%f','%f','%f','%f' ) );
        }

        return $po_id;
    }
}
