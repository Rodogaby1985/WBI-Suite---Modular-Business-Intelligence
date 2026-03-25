<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI Credit Notes / Debit Notes Module
 *
 * Module #23 — Notas de Crédito / Débito vinculadas a facturas AFIP.
 * Toggle key  : wbi_enable_credit_notes
 * Admin slug  : wbi-credit-notes
 * Permissions : wbi_permissions_credit_notes
 * Group       : finanzas
 */
class WBI_Credit_Notes_Module {

    /** DB table names (without $wpdb->prefix). */
    const TABLE_NOTES = 'wbi_credit_debit_notes';
    const TABLE_ITEMS = 'wbi_credit_debit_note_items';

    /** DB schema version stored in options. */
    const SCHEMA_VERSION = '1.0';

    /** Common reasons for a credit/debit note. */
    private static $reasons = array(
        'Devolución de mercadería',
        'Descuento posterior',
        'Error en facturación',
        'Anulación total',
        'Diferencia de precio',
        'Otro',
    );

    public function __construct() {
        // Create tables on init (safe to call every request — dbDelta skips if up-to-date).
        add_action( 'init', array( $this, 'maybe_create_tables' ) );

        // Admin menu.
        add_action( 'admin_menu', array( $this, 'add_submenu' ), 100 );

        // AJAX endpoints.
        add_action( 'wp_ajax_wbi_cn_save',               array( $this, 'ajax_save' ) );
        add_action( 'wp_ajax_wbi_cn_authorize',          array( $this, 'ajax_authorize' ) );
        add_action( 'wp_ajax_wbi_cn_cancel',             array( $this, 'ajax_cancel' ) );
        add_action( 'wp_ajax_wbi_cn_search_invoices',    array( $this, 'ajax_search_invoices' ) );
        add_action( 'wp_ajax_wbi_cn_copy_invoice_items', array( $this, 'ajax_copy_invoice_items' ) );

        // WooCommerce order metabox — "Emitir Nota de Crédito" button.
        add_action( 'add_meta_boxes', array( $this, 'register_order_metabox' ) );

        // Optional: auto-create draft NC when WooCommerce refund is issued.
        add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 10, 2 );
    }

    // =========================================================================
    // DATABASE
    // =========================================================================

    /**
     * Create (or upgrade) the two custom tables using dbDelta().
     */
    public function maybe_create_tables() {
        if ( get_option( 'wbi_cn_schema_version' ) === self::SCHEMA_VERSION ) {
            return;
        }

        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // --- Main notes table ---
        $table_notes = $wpdb->prefix . self::TABLE_NOTES;
        $sql_notes = "CREATE TABLE {$table_notes} (
            id                     BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            note_number            VARCHAR(30)         NOT NULL,
            type                   ENUM('credit','debit') NOT NULL,
            letter                 ENUM('A','B','C','M','E') NOT NULL,
            pto_venta              INT(5) UNSIGNED     NOT NULL DEFAULT 1,
            numero                 INT(8) UNSIGNED     NOT NULL DEFAULT 0,
            related_invoice_id     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            related_invoice_number VARCHAR(30)         DEFAULT NULL,
            order_id               BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            customer_id            BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            customer_name          VARCHAR(255)        DEFAULT NULL,
            customer_cuit          VARCHAR(20)         DEFAULT NULL,
            customer_tax_condition VARCHAR(50)         DEFAULT NULL,
            reason                 TEXT                NOT NULL,
            subtotal               DECIMAL(15,2)       NOT NULL DEFAULT 0.00,
            iva_105                DECIMAL(15,2)       NOT NULL DEFAULT 0.00,
            iva_21                 DECIMAL(15,2)       NOT NULL DEFAULT 0.00,
            iva_27                 DECIMAL(15,2)       NOT NULL DEFAULT 0.00,
            other_taxes            DECIMAL(15,2)       NOT NULL DEFAULT 0.00,
            total                  DECIMAL(15,2)       NOT NULL DEFAULT 0.00,
            currency               VARCHAR(3)          NOT NULL DEFAULT 'ARS',
            status                 ENUM('draft','authorized','cancelled') NOT NULL DEFAULT 'draft',
            cae                    VARCHAR(20)         NOT NULL DEFAULT '',
            cae_vto                DATE                DEFAULT NULL,
            issue_date             DATE                NOT NULL,
            created_by             BIGINT(20) UNSIGNED DEFAULT NULL,
            created_at             DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY   note_number (note_number),
            KEY          order_id (order_id),
            KEY          customer_id (customer_id),
            KEY          related_invoice_id (related_invoice_id),
            KEY          status (status)
        ) {$charset};";

        // --- Items table ---
        $table_items = $wpdb->prefix . self::TABLE_ITEMS;
        $sql_items = "CREATE TABLE {$table_items} (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            note_id     BIGINT(20) UNSIGNED NOT NULL,
            product_id  BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            description VARCHAR(255)        NOT NULL,
            quantity    DECIMAL(10,2)       NOT NULL,
            unit_price  DECIMAL(15,2)       NOT NULL,
            iva_rate    DECIMAL(5,2)        NOT NULL DEFAULT 21.00,
            iva_amount  DECIMAL(15,2)       NOT NULL DEFAULT 0.00,
            line_total  DECIMAL(15,2)       NOT NULL DEFAULT 0.00,
            PRIMARY KEY (id),
            KEY         note_id (note_id)
        ) {$charset};";

        dbDelta( $sql_notes );
        dbDelta( $sql_items );

        update_option( 'wbi_cn_schema_version', self::SCHEMA_VERSION );
    }

    // =========================================================================
    // ADMIN MENU
    // =========================================================================

    public function add_submenu() {
        add_submenu_page(
            'wbi-dashboard-view',
            '💳 Notas de Crédito/Débito',
            '<span class="dashicons dashicons-media-text" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> NC / ND',
            'manage_woocommerce',
            'wbi-credit-notes',
            array( $this, 'render_page' )
        );
    }

    // =========================================================================
    // PAGE ROUTER
    // =========================================================================

    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Sin permisos para acceder a esta página.', 'wbi-suite' ) );
        }

        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';

        switch ( $action ) {
            case 'new':
            case 'edit':
                $this->render_form_page();
                break;
            case 'view':
                $this->render_view_page();
                break;
            case 'reports':
                $this->render_reports_page();
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
        $table = $wpdb->prefix . self::TABLE_NOTES;

        // --- Filters ---
        $filter_type   = isset( $_GET['filter_type'] )   ? sanitize_key( $_GET['filter_type'] )         : '';
        $filter_letter = isset( $_GET['filter_letter'] )  ? sanitize_key( $_GET['filter_letter'] )        : '';
        $filter_status = isset( $_GET['filter_status'] )  ? sanitize_key( $_GET['filter_status'] )        : '';
        $filter_from   = isset( $_GET['filter_from'] )    ? sanitize_text_field( wp_unslash( $_GET['filter_from'] ) ) : '';
        $filter_to     = isset( $_GET['filter_to'] )      ? sanitize_text_field( wp_unslash( $_GET['filter_to'] ) )   : '';
        $search        = isset( $_GET['s'] )               ? sanitize_text_field( wp_unslash( $_GET['s'] ) )           : '';

        $where  = 'WHERE 1=1';
        $params = array();

        if ( $filter_type && in_array( $filter_type, array( 'credit', 'debit' ), true ) ) {
            $where   .= ' AND type = %s';
            $params[] = $filter_type;
        }
        if ( $filter_letter && in_array( $filter_letter, array( 'A', 'B', 'C', 'M', 'E' ), true ) ) {
            $where   .= ' AND letter = %s';
            $params[] = $filter_letter;
        }
        if ( $filter_status && in_array( $filter_status, array( 'draft', 'authorized', 'cancelled' ), true ) ) {
            $where   .= ' AND status = %s';
            $params[] = $filter_status;
        }
        if ( $filter_from ) {
            $where   .= ' AND issue_date >= %s';
            $params[] = $filter_from;
        }
        if ( $filter_to ) {
            $where   .= ' AND issue_date <= %s';
            $params[] = $filter_to;
        }
        if ( $search ) {
            $where   .= ' AND (note_number LIKE %s OR customer_name LIKE %s OR customer_cuit LIKE %s)';
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $full_query = "SELECT * FROM {$table} {$where} ORDER BY id DESC";
        if ( ! empty( $params ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $notes = $wpdb->get_results( $wpdb->prepare( $full_query, $params ) );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $notes = $wpdb->get_results( $full_query );
        }
        // phpcs:enable

        $base_url = admin_url( 'admin.php?page=wbi-credit-notes' );
        $new_nc   = esc_url( $base_url . '&action=new&type=credit' );
        $new_nd   = esc_url( $base_url . '&action=new&type=debit' );
        $rep_url  = esc_url( $base_url . '&action=reports' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">💳 Notas de Crédito / Débito</h1>
            <a href="<?php echo $new_nc; ?>" class="page-title-action">+ Nueva NC</a>
            <a href="<?php echo $new_nd; ?>" class="page-title-action">+ Nueva ND</a>
            <a href="<?php echo $rep_url; ?>" class="page-title-action">📊 Reportes</a>
            <hr class="wp-header-end">

            <?php if ( ! empty( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>✅ Nota guardada correctamente.</p></div>
            <?php endif; ?>
            <?php if ( ! empty( $_GET['authorized'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>✅ Nota autorizada.</p></div>
            <?php endif; ?>
            <?php if ( ! empty( $_GET['cancelled'] ) ) : ?>
                <div class="notice notice-warning is-dismissible"><p>⚠️ Nota anulada.</p></div>
            <?php endif; ?>

            <!-- Filters -->
            <form method="get" style="margin-bottom:16px;">
                <input type="hidden" name="page" value="wbi-credit-notes">
                <select name="filter_type">
                    <option value="">— Tipo —</option>
                    <option value="credit" <?php selected( $filter_type, 'credit' ); ?>>NC (Crédito)</option>
                    <option value="debit"  <?php selected( $filter_type, 'debit' ); ?>>ND (Débito)</option>
                </select>
                <select name="filter_letter">
                    <option value="">— Letra —</option>
                    <?php foreach ( array( 'A', 'B', 'C', 'M', 'E' ) as $l ) : ?>
                        <option value="<?php echo esc_attr( $l ); ?>" <?php selected( $filter_letter, $l ); ?>><?php echo esc_html( $l ); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="filter_status">
                    <option value="">— Estado —</option>
                    <option value="draft"      <?php selected( $filter_status, 'draft' ); ?>>Borrador</option>
                    <option value="authorized" <?php selected( $filter_status, 'authorized' ); ?>>Autorizada</option>
                    <option value="cancelled"  <?php selected( $filter_status, 'cancelled' ); ?>>Anulada</option>
                </select>
                <input type="date" name="filter_from" value="<?php echo esc_attr( $filter_from ); ?>" placeholder="Desde">
                <input type="date" name="filter_to"   value="<?php echo esc_attr( $filter_to ); ?>"   placeholder="Hasta">
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Buscar cliente / número...">
                <button type="submit" class="button">Filtrar</button>
                <a href="<?php echo esc_url( $base_url ); ?>" class="button">Limpiar</a>
            </form>

            <table class="wp-list-table widefat fixed striped wbi-sortable">
                <thead>
                    <tr>
                        <th>Número</th>
                        <th>Tipo</th>
                        <th>Letra</th>
                        <th>Factura relacionada</th>
                        <th>Cliente</th>
                        <th>Fecha</th>
                        <th>Total</th>
                        <th>Estado</th>
                        <th>CAE</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $notes ) ) : ?>
                    <tr><td colspan="10" style="text-align:center; padding:20px; color:#888;">No hay notas registradas.</td></tr>
                <?php else : ?>
                    <?php foreach ( $notes as $note ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $note->note_number ); ?></strong></td>
                        <td><?php echo $this->type_badge( $note->type ); ?></td>
                        <td><?php echo esc_html( $note->letter ); ?></td>
                        <td><?php echo $note->related_invoice_number ? esc_html( $note->related_invoice_number ) : '<span style="color:#888">—</span>'; ?></td>
                        <td><?php echo esc_html( $note->customer_name ?: '—' ); ?></td>
                        <td><?php echo $note->issue_date ? esc_html( date_i18n( 'd/m/Y', strtotime( $note->issue_date ) ) ) : '—'; ?></td>
                        <td>$ <?php echo number_format( (float) $note->total, 2, ',', '.' ); ?></td>
                        <td><?php echo $this->status_badge( $note->status ); ?></td>
                        <td><?php echo esc_html( $note->cae ?: '—' ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( $base_url . '&action=view&note_id=' . intval( $note->id ) ); ?>" class="button button-small">Ver</a>
                            <?php if ( $note->status === 'draft' ) : ?>
                                <a href="<?php echo esc_url( $base_url . '&action=edit&note_id=' . intval( $note->id ) ); ?>" class="button button-small">Editar</a>
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
    // CREATE / EDIT FORM
    // =========================================================================

    private function render_form_page() {
        global $wpdb;
        $table_notes = $wpdb->prefix . self::TABLE_NOTES;
        $table_items = $wpdb->prefix . self::TABLE_ITEMS;

        $note_id    = isset( $_GET['note_id'] ) ? absint( $_GET['note_id'] ) : 0;
        $type_param = isset( $_GET['type'] )    ? sanitize_key( $_GET['type'] ) : 'credit';
        $order_id   = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

        // Defaults.
        $note  = null;
        $items = array();

        if ( $note_id ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $note  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_notes} WHERE id = %d", $note_id ) );
            $items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_items} WHERE note_id = %d ORDER BY id ASC", $note_id ) );
            // phpcs:enable
            if ( ! $note ) {
                wp_die( 'Nota no encontrada.' );
            }
        }

        // Pre-fill from WooCommerce order if provided.
        $prefill_customer_name = '';
        $prefill_customer_cuit = '';
        $prefill_customer_id   = 0;
        $prefill_items         = array();

        if ( $order_id && ! $note_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $prefill_customer_id   = $order->get_customer_id();
                $prefill_customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
                foreach ( $order->get_items() as $item ) {
                    $prefill_items[] = array(
                        'product_id'  => $item->get_product_id(),
                        'description' => $item->get_name(),
                        'quantity'    => $item->get_quantity(),
                        'unit_price'  => $order->get_item_subtotal( $item, false, false ),
                        'iva_rate'    => 21.00,
                        'iva_amount'  => 0,
                        'line_total'  => $item->get_subtotal(),
                    );
                }
            }
        }

        $inv_settings = get_option( 'wbi_invoice_settings', array() );
        $pto_venta    = absint( $inv_settings['punto_venta'] ?? 1 );
        $base_url     = admin_url( 'admin.php?page=wbi-credit-notes' );

        $is_edit   = (bool) $note;
        $form_type = $note ? $note->type : $type_param;
        $title     = 'credit' === $form_type ? 'Nueva Nota de Crédito' : 'Nueva Nota de Débito';
        if ( $is_edit ) {
            $title = 'Editar ' . ( 'credit' === $form_type ? 'Nota de Crédito' : 'Nota de Débito' );
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $title ); ?></h1>
            <a href="<?php echo esc_url( $base_url ); ?>" class="button" style="margin-bottom:16px;">← Volver al listado</a>

            <div id="wbi-cn-notice" style="display:none;" class="notice notice-success is-dismissible"><p></p></div>

            <form id="wbi-cn-form" style="max-width:900px;">
                <?php wp_nonce_field( 'wbi_cn_save', '_wbi_cn_nonce' ); ?>
                <input type="hidden" name="note_id" value="<?php echo intval( $note_id ); ?>">
                <input type="hidden" name="action"  value="wbi_cn_save">

                <table class="form-table" style="margin-bottom:20px;">
                    <tr>
                        <th>Tipo de Nota</th>
                        <td>
                            <label><input type="radio" name="type" value="credit" <?php echo ( $form_type === 'credit' ) ? 'checked' : ''; ?>> Nota de Crédito (NC)</label>
                            &nbsp;&nbsp;
                            <label><input type="radio" name="type" value="debit" <?php echo ( $form_type === 'debit' ) ? 'checked' : ''; ?>> Nota de Débito (ND)</label>
                        </td>
                    </tr>
                    <tr>
                        <th>Letra</th>
                        <td>
                            <select name="letter">
                                <?php
                                $current_letter = $note ? $note->letter : 'B';
                                foreach ( array( 'A' => 'A — Resp. Inscripto', 'B' => 'B — Consumidor Final', 'C' => 'C — Monotributo', 'M' => 'M', 'E' => 'E — Exportación' ) as $val => $label ) {
                                    printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $current_letter, $val, false ), esc_html( $label ) );
                                }
                                ?>
                            </select>
                            <span style="color:#888; font-size:12px; margin-left:8px;">Se determina automáticamente según condición impositiva del cliente.</span>
                        </td>
                    </tr>
                    <tr>
                        <th>Punto de Venta</th>
                        <td><input type="number" name="pto_venta" value="<?php echo intval( $note ? $note->pto_venta : $pto_venta ); ?>" min="1" max="99999" class="small-text" required></td>
                    </tr>
                    <tr>
                        <th>Fecha de Emisión</th>
                        <td><input type="date" name="issue_date" value="<?php echo esc_attr( $note ? $note->issue_date : current_time( 'Y-m-d' ) ); ?>" required></td>
                    </tr>
                    <tr>
                        <th>Factura relacionada</th>
                        <td>
                            <?php if ( class_exists( 'WBI_Documents_Module' ) ) : ?>
                                <input type="text" id="wbi-cn-invoice-search" placeholder="Buscar por número de factura..." style="width:300px;">
                                <div id="wbi-cn-invoice-results" style="position:absolute;background:#fff;border:1px solid #ccc;max-height:200px;overflow-y:auto;z-index:1000;display:none;min-width:300px;"></div>
                                <input type="hidden" name="related_invoice_id"     id="wbi-cn-invoice-id"     value="<?php echo intval( $note ? $note->related_invoice_id : 0 ); ?>">
                                <input type="text"   name="related_invoice_number" id="wbi-cn-invoice-number" value="<?php echo esc_attr( $note ? $note->related_invoice_number : '' ); ?>" placeholder="Número (ej. FA-0001-00000001)" style="width:220px;" readonly>
                                <button type="button" id="wbi-cn-copy-items" class="button" style="display:none;">Copiar ítems de la factura</button>
                            <?php else : ?>
                                <input type="text" name="related_invoice_number" value="<?php echo esc_attr( $note ? $note->related_invoice_number : '' ); ?>" placeholder="Número de factura relacionada" style="width:300px;">
                                <input type="hidden" name="related_invoice_id" value="0">
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Motivo</th>
                        <td>
                            <select name="reason_select" id="wbi-cn-reason-select">
                                <?php
                                $saved_reason = $note ? $note->reason : '';
                                foreach ( self::$reasons as $r ) {
                                    $sel = ( $saved_reason === $r ) ? 'selected' : '';
                                    printf( '<option value="%s" %s>%s</option>', esc_attr( $r ), $sel, esc_html( $r ) );
                                }
                                // Check if saved reason is a custom one.
                                $is_custom = $saved_reason && ! in_array( $saved_reason, self::$reasons, true );
                                echo '<option value="__custom__" ' . ( $is_custom ? 'selected' : '' ) . '>Otro (texto libre)</option>';
                                ?>
                            </select>
                            <div id="wbi-cn-reason-custom" style="margin-top:8px;<?php echo $is_custom ? '' : 'display:none;'; ?>">
                                <textarea name="reason" rows="3" style="width:100%;" placeholder="Descripción del motivo..."><?php echo esc_textarea( $saved_reason ); ?></textarea>
                            </div>
                            <input type="hidden" id="wbi-cn-reason-hidden" name="reason_preset" value="<?php echo $is_custom ? '' : esc_attr( $saved_reason ); ?>">
                        </td>
                    </tr>
                </table>

                <!-- Customer section -->
                <h2>Datos del cliente</h2>
                <table class="form-table" style="margin-bottom:20px;">
                    <tr>
                        <th>ID cliente WC</th>
                        <td><input type="number" name="customer_id" id="wbi-cn-customer-id" value="<?php echo intval( $note ? $note->customer_id : $prefill_customer_id ); ?>" min="0" class="small-text"></td>
                    </tr>
                    <tr>
                        <th>Nombre / Razón Social</th>
                        <td><input type="text" name="customer_name" id="wbi-cn-customer-name" value="<?php echo esc_attr( $note ? $note->customer_name : $prefill_customer_name ); ?>" style="width:320px;"></td>
                    </tr>
                    <tr>
                        <th>CUIT</th>
                        <td><input type="text" name="customer_cuit" id="wbi-cn-customer-cuit" value="<?php echo esc_attr( $note ? $note->customer_cuit : $prefill_customer_cuit ); ?>" style="width:200px;" placeholder="XX-XXXXXXXX-X"></td>
                    </tr>
                    <tr>
                        <th>Condición impositiva</th>
                        <td>
                            <select name="customer_tax_condition" id="wbi-cn-tax-condition">
                                <?php
                                $conditions  = array( 'Responsable Inscripto', 'Consumidor Final', 'Monotributo', 'Exento', 'No categorizado' );
                                $saved_cond  = $note ? $note->customer_tax_condition : '';
                                foreach ( $conditions as $c ) {
                                    printf( '<option value="%s" %s>%s</option>', esc_attr( $c ), selected( $saved_cond, $c, false ), esc_html( $c ) );
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <!-- Items table -->
                <h2>Ítems de la nota</h2>
                <p><button type="button" id="wbi-cn-add-item" class="button">+ Agregar ítem</button></p>
                <table class="wp-list-table widefat fixed" id="wbi-cn-items-table">
                    <thead>
                        <tr>
                            <th style="width:30%">Descripción</th>
                            <th style="width:10%">Cantidad</th>
                            <th style="width:15%">Precio unit.</th>
                            <th style="width:10%">IVA %</th>
                            <th style="width:12%">IVA $</th>
                            <th style="width:13%">Subtotal</th>
                            <th style="width:10%">—</th>
                        </tr>
                    </thead>
                    <tbody id="wbi-cn-items-body">
                    <?php
                    $display_items = $items ?: ( $prefill_items ?: array() );
                    if ( empty( $display_items ) ) {
                        // Start with one blank row.
                        $display_items = array( array( 'product_id' => 0, 'description' => '', 'quantity' => 1, 'unit_price' => 0, 'iva_rate' => 21.00, 'iva_amount' => 0, 'line_total' => 0 ) );
                    }
                    foreach ( $display_items as $idx => $it ) :
                        $it = (array) $it;
                    ?>
                        <tr class="wbi-cn-item-row">
                            <td><input type="hidden" name="items[<?php echo $idx; ?>][product_id]" value="<?php echo intval( $it['product_id'] ?? 0 ); ?>"><input type="text" name="items[<?php echo $idx; ?>][description]" value="<?php echo esc_attr( $it['description'] ?? '' ); ?>" style="width:100%;" required></td>
                            <td><input type="number" name="items[<?php echo $idx; ?>][quantity]" value="<?php echo esc_attr( $it['quantity'] ?? 1 ); ?>" step="0.01" min="0.01" style="width:70px;" class="wbi-cn-qty" required></td>
                            <td><input type="number" name="items[<?php echo $idx; ?>][unit_price]" value="<?php echo esc_attr( $it['unit_price'] ?? 0 ); ?>" step="0.01" min="0" style="width:100px;" class="wbi-cn-price" required></td>
                            <td>
                                <select name="items[<?php echo $idx; ?>][iva_rate]" class="wbi-cn-iva-rate">
                                    <?php foreach ( array( 0, 10.5, 21, 27 ) as $rate ) {
                                        printf( '<option value="%s" %s>%s%%</option>', $rate, selected( (float)($it['iva_rate'] ?? 21), $rate, false ), $rate );
                                    } ?>
                                </select>
                            </td>
                            <td><input type="number" name="items[<?php echo $idx; ?>][iva_amount]" value="<?php echo esc_attr( $it['iva_amount'] ?? 0 ); ?>" step="0.01" style="width:90px;" class="wbi-cn-iva-amount" readonly></td>
                            <td><input type="number" name="items[<?php echo $idx; ?>][line_total]" value="<?php echo esc_attr( $it['line_total'] ?? 0 ); ?>" step="0.01" style="width:100px;" class="wbi-cn-line-total" readonly></td>
                            <td><button type="button" class="button wbi-cn-remove-item">✕</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Totals -->
                <table style="margin-top:16px; float:right; min-width:300px; border-collapse:collapse;">
                    <tr><td style="padding:4px 12px; text-align:right;"><strong>Neto gravado:</strong></td><td style="padding:4px 12px; text-align:right;"><input type="number" name="subtotal" id="wbi-cn-subtotal" step="0.01" style="width:120px; text-align:right;" value="<?php echo esc_attr( $note ? $note->subtotal : 0 ); ?>" readonly></td></tr>
                    <tr><td style="padding:4px 12px; text-align:right;">IVA 10.5%:</td><td style="padding:4px 12px;"><input type="number" name="iva_105" id="wbi-cn-iva105" step="0.01" style="width:120px; text-align:right;" value="<?php echo esc_attr( $note ? $note->iva_105 : 0 ); ?>" readonly></td></tr>
                    <tr><td style="padding:4px 12px; text-align:right;">IVA 21%:</td><td style="padding:4px 12px;"><input type="number" name="iva_21" id="wbi-cn-iva21" step="0.01" style="width:120px; text-align:right;" value="<?php echo esc_attr( $note ? $note->iva_21 : 0 ); ?>" readonly></td></tr>
                    <tr><td style="padding:4px 12px; text-align:right;">IVA 27%:</td><td style="padding:4px 12px;"><input type="number" name="iva_27" id="wbi-cn-iva27" step="0.01" style="width:120px; text-align:right;" value="<?php echo esc_attr( $note ? $note->iva_27 : 0 ); ?>" readonly></td></tr>
                    <tr><td style="padding:4px 12px; text-align:right;">Otros tributos:</td><td style="padding:4px 12px;"><input type="number" name="other_taxes" id="wbi-cn-other-taxes" step="0.01" style="width:120px; text-align:right;" value="<?php echo esc_attr( $note ? $note->other_taxes : 0 ); ?>"></td></tr>
                    <tr style="font-size:16px;"><td style="padding:8px 12px; text-align:right; border-top:2px solid #ccc;"><strong>TOTAL:</strong></td><td style="padding:8px 12px; border-top:2px solid #ccc;"><input type="number" name="total" id="wbi-cn-total" step="0.01" style="width:120px; text-align:right; font-weight:bold;" value="<?php echo esc_attr( $note ? $note->total : 0 ); ?>" readonly></td></tr>
                </table>
                <div style="clear:both;"></div>

                <p style="margin-top:24px;">
                    <button type="button" id="wbi-cn-submit" class="button button-primary button-large">💾 Guardar Nota</button>
                    <a href="<?php echo esc_url( $base_url ); ?>" class="button button-large">Cancelar</a>
                </p>
            </form>
        </div>

        <?php $this->enqueue_form_scripts( $base_url ); ?>
        <?php
    }

    /**
     * Enqueue inline JS for the create/edit form.
     */
    private function enqueue_form_scripts( $base_url ) {
        $nonce = wp_create_nonce( 'wbi_cn_save' );
        $nonce_search = wp_create_nonce( 'wbi_cn_search_invoices' );
        $nonce_copy   = wp_create_nonce( 'wbi_cn_copy_invoice_items' );
        $ajax_url     = admin_url( 'admin-ajax.php' );
        ?>
        <script>
        (function($){
            var itemIdx = <?php echo count( (array) ( isset( $_GET['note_id'] ) ? array() : array(0) ) ); ?>;
            // Count existing rows to get correct next index.
            itemIdx = $('#wbi-cn-items-body .wbi-cn-item-row').length;

            // --- Totals calculation ---
            function calcTotals() {
                var subtotal = 0, iva105 = 0, iva21 = 0, iva27 = 0;
                $('#wbi-cn-items-body .wbi-cn-item-row').each(function(){
                    var qty   = parseFloat($(this).find('.wbi-cn-qty').val())   || 0;
                    var price = parseFloat($(this).find('.wbi-cn-price').val()) || 0;
                    var rate  = parseFloat($(this).find('.wbi-cn-iva-rate').val()) || 0;
                    var net   = qty * price;
                    var iva   = net * rate / 100;
                    $(this).find('.wbi-cn-iva-amount').val(iva.toFixed(2));
                    $(this).find('.wbi-cn-line-total').val((net + iva).toFixed(2));
                    subtotal += net;
                    if (rate === 10.5) iva105 += iva;
                    else if (rate === 21)  iva21  += iva;
                    else if (rate === 27)  iva27  += iva;
                });
                $('#wbi-cn-subtotal').val(subtotal.toFixed(2));
                $('#wbi-cn-iva105').val(iva105.toFixed(2));
                $('#wbi-cn-iva21').val(iva21.toFixed(2));
                $('#wbi-cn-iva27').val(iva27.toFixed(2));
                var other  = parseFloat($('#wbi-cn-other-taxes').val()) || 0;
                var total  = subtotal + iva105 + iva21 + iva27 + other;
                $('#wbi-cn-total').val(total.toFixed(2));
            }

            $(document).on('input change', '.wbi-cn-qty, .wbi-cn-price, .wbi-cn-iva-rate, #wbi-cn-other-taxes', function(){
                calcTotals();
            });

            // --- Add item row ---
            function buildItemRow(idx, item) {
                item = item || {};
                var rates = [0, 10.5, 21, 27];
                var rateOpts = rates.map(function(r){
                    var sel = (parseFloat(item.iva_rate) === r) ? 'selected' : '';
                    return '<option value="'+r+'" '+sel+'>'+r+'%</option>';
                }).join('');
                return '<tr class="wbi-cn-item-row">' +
                    '<td><input type="hidden" name="items['+idx+'][product_id]" value="'+(item.product_id||0)+'"><input type="text" name="items['+idx+'][description]" value="'+(item.description||'')+'" style="width:100%;" required></td>' +
                    '<td><input type="number" name="items['+idx+'][quantity]" value="'+(item.quantity||1)+'" step="0.01" min="0.01" style="width:70px;" class="wbi-cn-qty" required></td>' +
                    '<td><input type="number" name="items['+idx+'][unit_price]" value="'+(item.unit_price||0)+'" step="0.01" min="0" style="width:100px;" class="wbi-cn-price" required></td>' +
                    '<td><select name="items['+idx+'][iva_rate]" class="wbi-cn-iva-rate">'+rateOpts+'</select></td>' +
                    '<td><input type="number" name="items['+idx+'][iva_amount]" value="'+(item.iva_amount||0)+'" step="0.01" style="width:90px;" class="wbi-cn-iva-amount" readonly></td>' +
                    '<td><input type="number" name="items['+idx+'][line_total]" value="'+(item.line_total||0)+'" step="0.01" style="width:100px;" class="wbi-cn-line-total" readonly></td>' +
                    '<td><button type="button" class="button wbi-cn-remove-item">\u2715</button></td>' +
                    '</tr>';
            }

            $('#wbi-cn-add-item').on('click', function(){
                $('#wbi-cn-items-body').append(buildItemRow(itemIdx++));
                calcTotals();
            });

            $(document).on('click', '.wbi-cn-remove-item', function(){
                if ($('#wbi-cn-items-body .wbi-cn-item-row').length <= 1) {
                    alert('Debe haber al menos un ítem.');
                    return;
                }
                $(this).closest('tr').remove();
                calcTotals();
            });

            // --- Reason dropdown ---
            $('#wbi-cn-reason-select').on('change', function(){
                if ($(this).val() === '__custom__') {
                    $('#wbi-cn-reason-custom').show();
                    $('#wbi-cn-reason-hidden').val('');
                } else {
                    $('#wbi-cn-reason-custom').hide();
                    $('#wbi-cn-reason-hidden').val($(this).val());
                    $('[name="reason"]').val('');
                }
            }).trigger('change');

            // --- Invoice search ---
            var searchTimer;
            $('#wbi-cn-invoice-search').on('input', function(){
                clearTimeout(searchTimer);
                var q = $(this).val();
                if (q.length < 2) { $('#wbi-cn-invoice-results').hide(); return; }
                searchTimer = setTimeout(function(){
                    $.post(<?php echo wp_json_encode( $ajax_url ); ?>, {
                        action: 'wbi_cn_search_invoices',
                        q: q,
                        _ajax_nonce: <?php echo wp_json_encode( $nonce_search ); ?>
                    }, function(resp){
                        if (!resp.success || !resp.data.length) { $('#wbi-cn-invoice-results').hide(); return; }
                        var html = '';
                        resp.data.forEach(function(inv){
                            html += '<div class="wbi-cn-inv-option" data-id="'+inv.id+'" data-number="'+inv.number+'" style="padding:6px 10px;cursor:pointer;border-bottom:1px solid #eee;">'+inv.number+' — '+inv.customer+'</div>';
                        });
                        $('#wbi-cn-invoice-results').html(html).show();
                    });
                }, 300);
            });

            $(document).on('click', '.wbi-cn-inv-option', function(){
                var id  = $(this).data('id');
                var num = $(this).data('number');
                $('#wbi-cn-invoice-id').val(id);
                $('#wbi-cn-invoice-number').val(num);
                $('#wbi-cn-invoice-search').val(num);
                $('#wbi-cn-invoice-results').hide();
                $('#wbi-cn-copy-items').show();
            });

            $(document).on('click', function(e){
                if (!$(e.target).closest('#wbi-cn-invoice-search, #wbi-cn-invoice-results').length) {
                    $('#wbi-cn-invoice-results').hide();
                }
            });

            // --- Copy invoice items ---
            $('#wbi-cn-copy-items').on('click', function(){
                var invId = $('#wbi-cn-invoice-id').val();
                if (!invId) return;
                $.post(<?php echo wp_json_encode( $ajax_url ); ?>, {
                    action: 'wbi_cn_copy_invoice_items',
                    invoice_id: invId,
                    _ajax_nonce: <?php echo wp_json_encode( $nonce_copy ); ?>
                }, function(resp){
                    if (!resp.success) { alert(resp.data || 'Error al copiar ítems'); return; }
                    $('#wbi-cn-items-body').empty();
                    itemIdx = 0;
                    resp.data.forEach(function(it){
                        $('#wbi-cn-items-body').append(buildItemRow(itemIdx++, it));
                    });
                    calcTotals();
                });
            });

            // --- Tax condition → auto-set letter ---
            $('#wbi-cn-tax-condition').on('change', function(){
                var cond = $(this).val();
                var letter = 'B';
                if (cond === 'Responsable Inscripto') letter = 'A';
                else if (cond === 'Monotributo') letter = 'C';
                $('[name="letter"]').val(letter);
            });

            // --- Submit via AJAX ---
            $('#wbi-cn-submit').on('click', function(){
                // Build reason value.
                var reason = $('#wbi-cn-reason-select').val() === '__custom__'
                    ? $('[name="reason"]').val()
                    : $('#wbi-cn-reason-hidden').val();
                if (!reason) { alert('El motivo es obligatorio.'); return; }
                // Check items.
                if (!$('#wbi-cn-items-body .wbi-cn-item-row').length) { alert('Debe agregar al menos un ítem.'); return; }

                var data = $('#wbi-cn-form').serializeArray();
                data.push({name:'action', value:'wbi_cn_save'});
                data.push({name:'reason_final', value:reason});

                $.post(<?php echo wp_json_encode( $ajax_url ); ?>, data, function(resp){
                    if (resp.success) {
                        window.location.href = <?php echo wp_json_encode( $base_url . '&saved=1' ); ?>;
                    } else {
                        alert(resp.data || 'Error al guardar');
                    }
                });
            });

            // Init totals on load.
            calcTotals();

        })(jQuery);
        </script>
        <?php
    }

    // =========================================================================
    // VIEW / PRINT PAGE
    // =========================================================================

    private function render_view_page() {
        global $wpdb;
        $table_notes = $wpdb->prefix . self::TABLE_NOTES;
        $table_items = $wpdb->prefix . self::TABLE_ITEMS;

        $note_id = isset( $_GET['note_id'] ) ? absint( $_GET['note_id'] ) : 0;
        if ( ! $note_id ) {
            wp_die( 'ID de nota no especificado.' );
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $note  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_notes} WHERE id = %d", $note_id ) );
        $items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_items} WHERE note_id = %d ORDER BY id ASC", $note_id ) );
        // phpcs:enable

        if ( ! $note ) {
            wp_die( 'Nota no encontrada.' );
        }

        $inv_settings = get_option( 'wbi_invoice_settings', array() );
        $base_url     = admin_url( 'admin.php?page=wbi-credit-notes' );
        $nonce_auth   = wp_create_nonce( 'wbi_cn_authorize' );
        $nonce_cancel = wp_create_nonce( 'wbi_cn_cancel' );
        $type_label   = 'credit' === $note->type ? 'NOTA DE CRÉDITO' : 'NOTA DE DÉBITO';
        $ajax_url     = admin_url( 'admin-ajax.php' );
        ?>
        <style>
        @media print {
            #wpwrap, #adminmenumain, #wpadminbar, .wbi-no-print { display: none !important; }
            #wbi-cn-print-area { margin: 0; padding: 10px; }
            .wbi-cn-document { box-shadow: none !important; border: 1px solid #000 !important; }
        }
        .wbi-cn-document { background:#fff; max-width:800px; margin:20px auto; padding:30px; box-shadow:0 2px 8px rgba(0,0,0,.15); font-size:13px; }
        .wbi-cn-doc-header { display:flex; justify-content:space-between; border-bottom:2px solid #333; padding-bottom:16px; margin-bottom:16px; }
        .wbi-cn-doc-title { text-align:center; font-size:22px; font-weight:bold; padding:8px 20px; border:2px solid #333; }
        .wbi-cn-items-table { width:100%; border-collapse:collapse; margin:16px 0; }
        .wbi-cn-items-table th, .wbi-cn-items-table td { border:1px solid #ccc; padding:6px 8px; font-size:12px; }
        .wbi-cn-items-table th { background:#f5f5f5; }
        .wbi-cn-totals { float:right; width:280px; border:1px solid #ccc; margin-top:8px; }
        .wbi-cn-totals td { padding:4px 8px; }
        .wbi-cn-totals .grand-total { font-weight:bold; font-size:15px; background:#f0f0f0; }
        .wbi-cae-box { margin-top:20px; padding:10px; border:2px solid #333; text-align:center; }
        </style>

        <div class="wrap">
            <div class="wbi-no-print" style="margin-bottom:16px;">
                <a href="<?php echo esc_url( $base_url ); ?>" class="button">← Volver</a>
                <button onclick="window.print()" class="button button-secondary">🖨 Imprimir / PDF</button>
                <?php if ( $note->status === 'draft' ) : ?>
                    <button id="wbi-cn-btn-authorize" class="button button-primary" data-id="<?php echo intval( $note->id ); ?>" data-nonce="<?php echo esc_attr( $nonce_auth ); ?>">✅ Autorizar en AFIP</button>
                    <a href="<?php echo esc_url( $base_url . '&action=edit&note_id=' . intval( $note->id ) ); ?>" class="button">✏️ Editar</a>
                <?php endif; ?>
                <?php if ( in_array( $note->status, array( 'draft', 'authorized' ), true ) ) : ?>
                    <button id="wbi-cn-btn-cancel" class="button" style="color:#a00; border-color:#a00;" data-id="<?php echo intval( $note->id ); ?>" data-nonce="<?php echo esc_attr( $nonce_cancel ); ?>">🚫 Anular</button>
                <?php endif; ?>
            </div>

            <div id="wbi-cn-print-area">
            <div class="wbi-cn-document">
                <!-- Header -->
                <div class="wbi-cn-doc-header">
                    <div style="flex:1;">
                        <strong style="font-size:16px;"><?php echo esc_html( $inv_settings['razon_social'] ?? get_bloginfo('name') ); ?></strong><br>
                        CUIT: <?php echo esc_html( $inv_settings['cuit'] ?? '—' ); ?><br>
                        <?php echo esc_html( $inv_settings['address'] ?? '' ); ?><br>
                        Ingresos Brutos: <?php echo esc_html( $inv_settings['ingresos_brutos'] ?? '—' ); ?><br>
                        Inicio actividades: <?php echo esc_html( $inv_settings['inicio_actividades'] ?? '—' ); ?>
                    </div>
                    <div style="text-align:center; flex:0 0 180px;">
                        <div class="wbi-cn-doc-title">
                            <?php echo esc_html( $type_label ); ?><br>
                            <span style="font-size:40px; line-height:1;"><?php echo esc_html( $note->letter ); ?></span>
                        </div>
                        <div style="margin-top:8px; font-size:12px;">
                            Cod. 21<br>
                            N°: <?php echo esc_html( $note->note_number ); ?>
                        </div>
                    </div>
                    <div style="flex:1; text-align:right;">
                        Fecha: <?php echo $note->issue_date ? esc_html( date_i18n( 'd/m/Y', strtotime( $note->issue_date ) ) ) : '—'; ?><br>
                        Condición IVA: <?php echo esc_html( $inv_settings['tax_condition'] ?? '—' ); ?>
                    </div>
                </div>

                <!-- Customer -->
                <div style="border:1px solid #ccc; padding:10px; margin-bottom:16px;">
                    <strong>Receptor:</strong> <?php echo esc_html( $note->customer_name ?: '—' ); ?>
                    &nbsp;&nbsp;
                    <strong>CUIT:</strong> <?php echo esc_html( $note->customer_cuit ?: '—' ); ?>
                    &nbsp;&nbsp;
                    <strong>Condición IVA:</strong> <?php echo esc_html( $note->customer_tax_condition ?: '—' ); ?><br>
                    <?php if ( $note->related_invoice_number ) : ?>
                        <strong>Comprobante relacionado:</strong> <?php echo esc_html( $note->related_invoice_number ); ?>
                    <?php endif; ?>
                    <br><strong>Motivo:</strong> <?php echo esc_html( $note->reason ); ?>
                </div>

                <!-- Items -->
                <table class="wbi-cn-items-table">
                    <thead>
                        <tr>
                            <th style="width:40%">Descripción</th>
                            <th style="width:10%">Cant.</th>
                            <th style="width:15%">Precio unit.</th>
                            <th style="width:10%">IVA %</th>
                            <th style="width:12%">IVA $</th>
                            <th style="width:13%">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $items as $item ) : ?>
                        <tr>
                            <td><?php echo esc_html( $item->description ); ?></td>
                            <td style="text-align:right;"><?php echo esc_html( number_format( (float) $item->quantity, 2, ',', '.' ) ); ?></td>
                            <td style="text-align:right;">$ <?php echo esc_html( number_format( (float) $item->unit_price, 2, ',', '.' ) ); ?></td>
                            <td style="text-align:center;"><?php echo esc_html( $item->iva_rate ); ?>%</td>
                            <td style="text-align:right;">$ <?php echo esc_html( number_format( (float) $item->iva_amount, 2, ',', '.' ) ); ?></td>
                            <td style="text-align:right;">$ <?php echo esc_html( number_format( (float) $item->line_total, 2, ',', '.' ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Totals -->
                <table class="wbi-cn-totals">
                    <tr><td>Neto gravado:</td><td style="text-align:right;">$ <?php echo esc_html( number_format( (float) $note->subtotal, 2, ',', '.' ) ); ?></td></tr>
                    <?php if ( $note->iva_105 > 0 ) : ?><tr><td>IVA 10.5%:</td><td style="text-align:right;">$ <?php echo esc_html( number_format( (float) $note->iva_105, 2, ',', '.' ) ); ?></td></tr><?php endif; ?>
                    <?php if ( $note->iva_21 > 0 ) : ?><tr><td>IVA 21%:</td><td style="text-align:right;">$ <?php echo esc_html( number_format( (float) $note->iva_21, 2, ',', '.' ) ); ?></td></tr><?php endif; ?>
                    <?php if ( $note->iva_27 > 0 ) : ?><tr><td>IVA 27%:</td><td style="text-align:right;">$ <?php echo esc_html( number_format( (float) $note->iva_27, 2, ',', '.' ) ); ?></td></tr><?php endif; ?>
                    <?php if ( $note->other_taxes > 0 ) : ?><tr><td>Otros tributos:</td><td style="text-align:right;">$ <?php echo esc_html( number_format( (float) $note->other_taxes, 2, ',', '.' ) ); ?></td></tr><?php endif; ?>
                    <tr class="grand-total"><td><strong>TOTAL <?php echo esc_html( $note->currency ?: 'ARS' ); ?>:</strong></td><td style="text-align:right;"><strong>$ <?php echo esc_html( number_format( (float) $note->total, 2, ',', '.' ) ); ?></strong></td></tr>
                </table>
                <div style="clear:both;"></div>

                <!-- Status badge -->
                <div style="margin-top:24px; text-align:center;">
                    <?php echo $this->status_badge( $note->status ); ?>
                </div>

                <!-- CAE section -->
                <?php if ( $note->status === 'authorized' && $note->cae ) : ?>
                <div class="wbi-cae-box">
                    <strong>CAE:</strong> <?php echo esc_html( $note->cae ); ?>
                    &nbsp;&nbsp;
                    <strong>Vto. CAE:</strong> <?php echo $note->cae_vto ? esc_html( date_i18n( 'd/m/Y', strtotime( $note->cae_vto ) ) ) : '—'; ?><br>
                    <div style="margin-top:8px; font-size:11px; color:#555;">[Código de barras AFIP — integración con servicio web pendiente]</div>
                </div>
                <?php endif; ?>
            </div><!-- .wbi-cn-document -->
            </div><!-- #wbi-cn-print-area -->
        </div>

        <script>
        (function($){
            $('#wbi-cn-btn-authorize').on('click', function(){
                if (!confirm('¿Autorizar esta nota en AFIP? (Placeholder — se asignará CAE de prueba)')) return;
                var btn = $(this);
                btn.prop('disabled', true).text('Autorizando...');
                $.post(<?php echo wp_json_encode( $ajax_url ); ?>, {
                    action: 'wbi_cn_authorize',
                    note_id: btn.data('id'),
                    _ajax_nonce: btn.data('nonce')
                }, function(resp){
                    if (resp.success) {
                        window.location.href = <?php echo wp_json_encode( $base_url . '&authorized=1' ); ?>;
                    } else {
                        alert(resp.data || 'Error al autorizar');
                        btn.prop('disabled', false).text('✅ Autorizar en AFIP');
                    }
                });
            });

            $('#wbi-cn-btn-cancel').on('click', function(){
                if (!confirm('¿Anular esta nota? Esta acción no se puede deshacer.')) return;
                var btn = $(this);
                btn.prop('disabled', true).text('Anulando...');
                $.post(<?php echo wp_json_encode( $ajax_url ); ?>, {
                    action: 'wbi_cn_cancel',
                    note_id: btn.data('id'),
                    _ajax_nonce: btn.data('nonce')
                }, function(resp){
                    if (resp.success) {
                        window.location.href = <?php echo wp_json_encode( $base_url . '&cancelled=1' ); ?>;
                    } else {
                        alert(resp.data || 'Error al anular');
                        btn.prop('disabled', false).text('🚫 Anular');
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    // =========================================================================
    // REPORTS PAGE
    // =========================================================================

    private function render_reports_page() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NOTES;
        $base_url = admin_url( 'admin.php?page=wbi-credit-notes' );

        // Last 12 months of NC/ND totals.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $monthly = $wpdb->get_results(
            "SELECT DATE_FORMAT(issue_date,'%Y-%m') AS month,
                    type,
                    COUNT(*) AS qty,
                    SUM(total) AS total_amount
             FROM {$table}
             WHERE issue_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
               AND status != 'cancelled'
             GROUP BY month, type
             ORDER BY month ASC"
        );

        // Reasons breakdown.
        $reasons_data = $wpdb->get_results(
            "SELECT reason, COUNT(*) AS qty, SUM(total) AS total_amount
             FROM {$table}
             WHERE status != 'cancelled'
             GROUP BY reason
             ORDER BY qty DESC"
        );

        // Revenue impact: sum of NC reduces income, ND increases.
        $impact = $wpdb->get_row(
            "SELECT
                SUM(CASE WHEN type='credit' THEN total ELSE 0 END) AS nc_total,
                SUM(CASE WHEN type='debit'  THEN total ELSE 0 END) AS nd_total
             FROM {$table}
             WHERE status = 'authorized'"
        );
        // phpcs:enable

        $net_impact = (float) ( $impact->nd_total ?? 0 ) - (float) ( $impact->nc_total ?? 0 );
        ?>
        <div class="wrap">
            <h1>📊 Reportes — Notas de Crédito / Débito</h1>
            <a href="<?php echo esc_url( $base_url ); ?>" class="button" style="margin-bottom:20px;">← Volver al listado</a>

            <!-- Impact summary -->
            <div style="display:flex; gap:20px; margin-bottom:24px; flex-wrap:wrap;">
                <div style="background:#fff; border:1px solid #e0e0e0; border-radius:6px; padding:20px; min-width:180px; text-align:center;">
                    <div style="font-size:28px; font-weight:bold; color:#c0392b;">$ <?php echo number_format( (float)( $impact->nc_total ?? 0 ), 2, ',', '.' ); ?></div>
                    <div style="color:#666; font-size:13px; margin-top:4px;">Total NC autorizadas (reduce ingresos)</div>
                </div>
                <div style="background:#fff; border:1px solid #e0e0e0; border-radius:6px; padding:20px; min-width:180px; text-align:center;">
                    <div style="font-size:28px; font-weight:bold; color:#27ae60;">$ <?php echo number_format( (float)( $impact->nd_total ?? 0 ), 2, ',', '.' ); ?></div>
                    <div style="color:#666; font-size:13px; margin-top:4px;">Total ND autorizadas (aumenta ingresos)</div>
                </div>
                <div style="background:#fff; border:1px solid #e0e0e0; border-radius:6px; padding:20px; min-width:180px; text-align:center;">
                    <div style="font-size:28px; font-weight:bold; color:<?php echo $net_impact >= 0 ? '#27ae60' : '#c0392b'; ?>;">
                        <?php echo ( $net_impact >= 0 ? '+' : '' ) . '$ ' . number_format( abs( $net_impact ), 2, ',', '.' ); ?>
                    </div>
                    <div style="color:#666; font-size:13px; margin-top:4px;">Impacto neto en ingresos</div>
                </div>
            </div>

            <!-- Monthly table -->
            <h2>Totales por mes (últimos 12 meses)</h2>
            <table class="wp-list-table widefat fixed striped wbi-sortable" style="max-width:600px;">
                <thead>
                    <tr><th>Mes</th><th>Tipo</th><th>Cantidad</th><th>Total</th></tr>
                </thead>
                <tbody>
                <?php if ( empty( $monthly ) ) : ?>
                    <tr><td colspan="4" style="text-align:center;color:#888;">Sin datos.</td></tr>
                <?php else : ?>
                    <?php foreach ( $monthly as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( $row->month ); ?></td>
                        <td><?php echo $this->type_badge( $row->type ); ?></td>
                        <td><?php echo intval( $row->qty ); ?></td>
                        <td>$ <?php echo number_format( (float) $row->total_amount, 2, ',', '.' ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <!-- Reasons breakdown -->
            <h2 style="margin-top:30px;">Desglose por motivo</h2>
            <?php if ( ! empty( $reasons_data ) ) : ?>
                <?php
                $max_qty = max( array_column( $reasons_data, 'qty' ) );
                $colors  = array( '#3498db', '#e74c3c', '#2ecc71', '#f39c12', '#9b59b6', '#1abc9c', '#e67e22' );
                ?>
                <div style="display:flex; gap:24px; align-items:flex-start; flex-wrap:wrap;">
                    <table class="wp-list-table widefat fixed striped" style="max-width:500px;">
                        <thead><tr><th>Motivo</th><th>Cantidad</th><th>Total</th><th>Proporción</th></tr></thead>
                        <tbody>
                        <?php foreach ( $reasons_data as $idx => $rd ) : ?>
                            <tr>
                                <td><?php echo esc_html( $rd->reason ); ?></td>
                                <td><?php echo intval( $rd->qty ); ?></td>
                                <td>$ <?php echo number_format( (float) $rd->total_amount, 2, ',', '.' ); ?></td>
                                <td>
                                    <div style="background:#e0e0e0; border-radius:4px; height:14px; width:100%;">
                                        <div style="background:<?php echo esc_attr( $colors[ $idx % count( $colors ) ] ); ?>; border-radius:4px; height:14px; width:<?php echo round( $rd->qty / $max_qty * 100 ); ?>%;"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <!-- Simple CSS pie chart representation -->
                    <div>
                        <h3 style="margin-top:0;">Distribución por motivo</h3>
                        <div style="display:flex; flex-wrap:wrap; gap:8px; max-width:260px;">
                        <?php
                        $total_qty = array_sum( array_column( $reasons_data, 'qty' ) );
                        foreach ( $reasons_data as $idx => $rd ) :
                            $pct = $total_qty ? round( $rd->qty / $total_qty * 100 ) : 0;
                        ?>
                            <div style="display:flex; align-items:center; gap:6px; font-size:12px;">
                                <span style="display:inline-block; width:14px; height:14px; background:<?php echo esc_attr( $colors[ $idx % count( $colors ) ] ); ?>; border-radius:3px;"></span>
                                <?php echo esc_html( $rd->reason ); ?> (<?php echo $pct; ?>%)
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php else : ?>
                <p style="color:#888;">Sin datos de motivos.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    // =========================================================================
    // AJAX — SAVE NOTE
    // =========================================================================

    public function ajax_save() {
        check_ajax_referer( 'wbi_cn_save', '_wbi_cn_nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Sin permisos.' );
        }

        global $wpdb;
        $table_notes = $wpdb->prefix . self::TABLE_NOTES;
        $table_items = $wpdb->prefix . self::TABLE_ITEMS;

        $note_id = absint( $_POST['note_id'] ?? 0 );

        // -- Collect and sanitize note fields --
        $type      = in_array( sanitize_key( wp_unslash( $_POST['type'] ?? '' ) ), array( 'credit', 'debit' ), true )
                     ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'credit';
        $letter    = in_array( sanitize_text_field( wp_unslash( $_POST['letter'] ?? '' ) ), array( 'A', 'B', 'C', 'M', 'E' ), true )
                     ? sanitize_text_field( wp_unslash( $_POST['letter'] ) ) : 'B';
        $pto_venta = absint( $_POST['pto_venta'] ?? 1 );
        if ( $pto_venta < 1 ) $pto_venta = 1;

        $issue_date = sanitize_text_field( wp_unslash( $_POST['issue_date'] ?? '' ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $issue_date ) ) {
            $issue_date = current_time( 'Y-m-d' );
        }

        // Reason: prefer 'reason_final' sent by JS, fall back to preset.
        $reason = sanitize_textarea_field( wp_unslash( $_POST['reason_final'] ?? '' ) );
        if ( ! $reason ) {
            $reason = sanitize_textarea_field( wp_unslash( $_POST['reason_preset'] ?? $_POST['reason'] ?? '' ) );
        }
        if ( ! $reason ) {
            wp_send_json_error( 'El motivo es obligatorio.' );
        }

        $related_invoice_id     = absint( $_POST['related_invoice_id'] ?? 0 );
        $related_invoice_number = sanitize_text_field( wp_unslash( $_POST['related_invoice_number'] ?? '' ) );
        $order_id               = absint( $_POST['order_id'] ?? 0 );
        $customer_id            = absint( $_POST['customer_id'] ?? 0 );
        $customer_name          = sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? '' ) );
        $customer_cuit          = sanitize_text_field( wp_unslash( $_POST['customer_cuit'] ?? '' ) );
        $customer_tax_condition = sanitize_text_field( wp_unslash( $_POST['customer_tax_condition'] ?? '' ) );
        $other_taxes            = max( 0, (float) ( $_POST['other_taxes'] ?? 0 ) );
        $currency               = sanitize_text_field( wp_unslash( $_POST['currency'] ?? 'ARS' ) );
        if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) $currency = 'ARS';

        // -- Process items --
        $raw_items = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? $_POST['items'] : array();
        if ( empty( $raw_items ) ) {
            wp_send_json_error( 'Debe agregar al menos un ítem.' );
        }

        $clean_items = array();
        $subtotal = 0;
        $iva_105  = 0;
        $iva_21   = 0;
        $iva_27   = 0;

        foreach ( $raw_items as $it ) {
            if ( ! is_array( $it ) ) continue;
            $desc     = sanitize_text_field( wp_unslash( $it['description'] ?? '' ) );
            if ( ! $desc ) continue;
            $qty       = max( 0.01, (float) ( $it['quantity']   ?? 1 ) );
            $price     = max( 0,    (float) ( $it['unit_price'] ?? 0 ) );
            $iva_rate  = (float) ( $it['iva_rate'] ?? 21 );
            if ( ! in_array( $iva_rate, array( 0, 10.5, 21, 27 ), true ) ) $iva_rate = 21;
            $product_id = absint( $it['product_id'] ?? 0 );

            $net        = $qty * $price;
            $iva_amount = $net * $iva_rate / 100;
            $line_total = $net + $iva_amount;

            $subtotal += $net;
            if ( (string) $iva_rate === '10.5' ) $iva_105 += $iva_amount;
            elseif ( (string) $iva_rate === '21' ) $iva_21  += $iva_amount;
            elseif ( (string) $iva_rate === '27' ) $iva_27  += $iva_amount;

            $clean_items[] = array(
                'product_id'  => $product_id,
                'description' => $desc,
                'quantity'    => $qty,
                'unit_price'  => $price,
                'iva_rate'    => $iva_rate,
                'iva_amount'  => round( $iva_amount, 2 ),
                'line_total'  => round( $line_total, 2 ),
            );
        }

        if ( empty( $clean_items ) ) {
            wp_send_json_error( 'Debe agregar al menos un ítem válido.' );
        }

        $total = round( $subtotal + $iva_105 + $iva_21 + $iva_27 + $other_taxes, 2 );

        // -- Auto-number for new notes --
        $note_number = '';
        if ( ! $note_id ) {
            $numero = $this->next_numero( $type, $letter, $pto_venta );
            $prefix = strtoupper( 'credit' === $type ? 'NC' : 'ND' );
            $note_number = sprintf(
                '%s-%s-%04d-%08d',
                $prefix,
                $letter,
                $pto_venta,
                $numero
            );
        }

        // -- Save note --
        $note_data = array(
            'type'                   => $type,
            'letter'                 => $letter,
            'pto_venta'              => $pto_venta,
            'related_invoice_id'     => $related_invoice_id,
            'related_invoice_number' => $related_invoice_number,
            'order_id'               => $order_id,
            'customer_id'            => $customer_id,
            'customer_name'          => $customer_name,
            'customer_cuit'          => $customer_cuit,
            'customer_tax_condition' => $customer_tax_condition,
            'reason'                 => $reason,
            'subtotal'               => round( $subtotal, 2 ),
            'iva_105'                => round( $iva_105, 2 ),
            'iva_21'                 => round( $iva_21, 2 ),
            'iva_27'                 => round( $iva_27, 2 ),
            'other_taxes'            => round( $other_taxes, 2 ),
            'total'                  => $total,
            'currency'               => $currency,
            'issue_date'             => $issue_date,
        );

        $note_format = array( '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%s', '%s' );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        if ( $note_id ) {
            // Update existing note (only drafts can be edited).
            $existing = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$table_notes} WHERE id = %d", $note_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
            if ( ! $existing || $existing->status !== 'draft' ) {
                wp_send_json_error( 'Solo las notas en borrador pueden editarse.' );
            }
            $wpdb->update( $table_notes, $note_data, array( 'id' => $note_id ), $note_format, array( '%d' ) );
            // Delete old items and reinsert.
            $wpdb->delete( $table_items, array( 'note_id' => $note_id ), array( '%d' ) );
        } else {
            $note_data['note_number'] = $note_number;
            $note_data['numero']      = $numero;
            $note_data['created_by']  = get_current_user_id();
            $note_format[]            = '%s';
            $note_format[]            = '%d';
            $note_format[]            = '%d';
            $wpdb->insert( $table_notes, $note_data, $note_format );
            $note_id = $wpdb->insert_id;
        }

        if ( ! $note_id ) {
            // phpcs:enable
            wp_send_json_error( 'Error al guardar la nota en la base de datos.' );
        }

        // Insert items.
        foreach ( $clean_items as $ci ) {
            $ci['note_id'] = $note_id;
            $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $table_items,
                $ci,
                array( '%d', '%d', '%s', '%f', '%f', '%f', '%f', '%f' )
            );
        }
        // phpcs:enable

        // Integration: Cash Flow — credit reduces income, debit increases.
        $this->sync_cashflow( $note_id );

        wp_send_json_success( array( 'note_id' => $note_id ) );
    }

    // =========================================================================
    // AJAX — AUTHORIZE (AFIP placeholder)
    // =========================================================================

    public function ajax_authorize() {
        check_ajax_referer( 'wbi_cn_authorize' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Sin permisos.' );
        }

        global $wpdb;
        $table  = $wpdb->prefix . self::TABLE_NOTES;
        $note_id = absint( $_POST['note_id'] ?? 0 );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $note = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $note_id ) );
        // phpcs:enable
        if ( ! $note || $note->status !== 'draft' ) {
            wp_send_json_error( 'La nota no existe o no está en borrador.' );
        }

        // Placeholder: assign a dummy CAE (real AFIP ws integration to be added later).
        $dummy_cae     = 'CAE' . date( 'Ymd' ) . str_pad( $note_id, 8, '0', STR_PAD_LEFT );
        $dummy_cae_vto = date( 'Y-m-d', strtotime( '+10 days' ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->update(
            $table,
            array( 'status' => 'authorized', 'cae' => $dummy_cae, 'cae_vto' => $dummy_cae_vto ),
            array( 'id' => $note_id ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );
        // phpcs:enable

        // Notification integration.
        $this->fire_notification( 'authorized', $note_id, $note );

        // Cash Flow update.
        $this->sync_cashflow( $note_id );

        do_action( 'wbi_credit_note_authorized', $note_id );

        wp_send_json_success( array( 'cae' => $dummy_cae, 'cae_vto' => $dummy_cae_vto ) );
    }

    // =========================================================================
    // AJAX — CANCEL
    // =========================================================================

    public function ajax_cancel() {
        check_ajax_referer( 'wbi_cn_cancel' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Sin permisos.' );
        }

        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE_NOTES;
        $note_id = absint( $_POST['note_id'] ?? 0 );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $note = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $note_id ) );
        // phpcs:enable
        if ( ! $note || $note->status === 'cancelled' ) {
            wp_send_json_error( 'La nota no puede anularse.' );
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->update(
            $table,
            array( 'status' => 'cancelled' ),
            array( 'id' => $note_id ),
            array( '%s' ),
            array( '%d' )
        );
        // phpcs:enable

        do_action( 'wbi_credit_note_cancelled', $note_id );

        wp_send_json_success();
    }

    // =========================================================================
    // AJAX — SEARCH INVOICES
    // =========================================================================

    public function ajax_search_invoices() {
        check_ajax_referer( 'wbi_cn_search_invoices' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Sin permisos.' );
        }

        if ( ! class_exists( 'WBI_Documents_Module' ) ) {
            wp_send_json_error( 'Módulo de Documentos no activo.' );
        }

        global $wpdb;
        $q = '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) ) ) . '%';

        // Search in WC orders that have invoice metadata (set by Documents module).
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id AS order_id,
                        MAX(CASE WHEN meta_key='_wbi_invoice_number' THEN meta_value END) AS inv_number,
                        MAX(CASE WHEN meta_key='_wbi_invoice_type'   THEN meta_value END) AS inv_type
                 FROM {$wpdb->postmeta}
                 WHERE meta_key IN ('_wbi_invoice_number','_wbi_invoice_type')
                   AND meta_value LIKE %s
                 GROUP BY post_id
                 LIMIT 20",
                $q
            )
        );
        // phpcs:enable

        $results = array();
        foreach ( $rows as $row ) {
            if ( ! $row->inv_number ) continue;
            $order    = wc_get_order( $row->order_id );
            $customer = $order ? trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) : '—';
            $results[] = array(
                'id'       => $row->order_id, // use order_id as invoice reference
                'number'   => $row->inv_number,
                'customer' => $customer,
            );
        }

        wp_send_json_success( $results );
    }

    // =========================================================================
    // AJAX — COPY INVOICE ITEMS
    // =========================================================================

    public function ajax_copy_invoice_items() {
        check_ajax_referer( 'wbi_cn_copy_invoice_items' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Sin permisos.' );
        }

        $invoice_id = absint( $_POST['invoice_id'] ?? 0 );
        $order      = wc_get_order( $invoice_id );
        if ( ! $order ) {
            wp_send_json_error( 'Pedido no encontrado.' );
        }

        $items = array();
        foreach ( $order->get_items() as $item ) {
            $net       = $order->get_item_subtotal( $item, false, false );
            $iva_rate  = 21.00;
            $iva_amt   = $net * $iva_rate / 100;
            $items[]   = array(
                'product_id'  => $item->get_product_id(),
                'description' => $item->get_name(),
                'quantity'    => $item->get_quantity(),
                'unit_price'  => round( $net, 2 ),
                'iva_rate'    => $iva_rate,
                'iva_amount'  => round( $iva_amt, 2 ),
                'line_total'  => round( $net + $iva_amt, 2 ),
            );
        }

        if ( empty( $items ) ) {
            wp_send_json_error( 'El pedido no tiene ítems.' );
        }

        wp_send_json_success( $items );
    }

    // =========================================================================
    // WOOCOMMERCE ORDER METABOX
    // =========================================================================

    public function register_order_metabox() {
        $screens = array( 'shop_order' );
        if ( function_exists( 'wc_get_page_screen_id' ) ) {
            $screens[] = wc_get_page_screen_id( 'shop-order' );
        }
        foreach ( array_unique( $screens ) as $screen ) {
            add_meta_box(
                'wbi_credit_notes_metabox',
                '<span class="dashicons dashicons-media-text" style="vertical-align:middle;margin-right:4px;"></span> Notas de Crédito/Débito',
                array( $this, 'render_order_metabox' ),
                $screen,
                'side',
                'default'
            );
        }
    }

    public function render_order_metabox( $post_or_order ) {
        if ( $post_or_order instanceof WP_Post ) {
            $order = wc_get_order( $post_or_order->ID );
        } else {
            $order = $post_or_order;
        }
        if ( ! $order ) return;

        global $wpdb;
        $table    = $wpdb->prefix . self::TABLE_NOTES;
        $order_id = $order->get_id();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $notes = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, note_number, type, status, total FROM {$table} WHERE order_id = %d ORDER BY id DESC",
            $order_id
        ) );
        // phpcs:enable

        $nc_url = admin_url( 'admin.php?page=wbi-credit-notes&action=new&type=credit&order_id=' . $order_id );
        $nd_url = admin_url( 'admin.php?page=wbi-credit-notes&action=new&type=debit&order_id=' . $order_id );

        echo '<div style="font-size:12px;">';

        if ( $notes ) {
            foreach ( $notes as $n ) {
                $view_url = admin_url( 'admin.php?page=wbi-credit-notes&action=view&note_id=' . intval( $n->id ) );
                echo '<p><a href="' . esc_url( $view_url ) . '"><strong>' . esc_html( $n->note_number ) . '</strong></a> '
                    . $this->status_badge( $n->status )
                    . ' $ ' . esc_html( number_format( (float) $n->total, 2, ',', '.' ) ) . '</p>';
            }
            echo '<hr style="margin:6px 0;">';
        } else {
            echo '<p style="color:#888;">Sin notas en este pedido.</p>';
        }

        echo '<a href="' . esc_url( $nc_url ) . '" class="button button-small" style="width:100%;text-align:center;margin-bottom:4px;display:block;">+ Emitir Nota de Crédito</a>';
        echo '<a href="' . esc_url( $nd_url ) . '" class="button button-small" style="width:100%;text-align:center;display:block;">+ Emitir Nota de Débito</a>';
        echo '</div>';
    }

    // =========================================================================
    // WOOCOMMERCE REFUND HOOK — AUTO-CREATE DRAFT NC
    // =========================================================================

    /**
     * When a WooCommerce refund is created, optionally auto-create a draft NC.
     *
     * @param int $order_id  WC order ID.
     * @param int $refund_id WC refund ID.
     */
    public function on_order_refunded( $order_id, $refund_id ) {
        // Only auto-create when the setting is enabled.
        $opts = get_option( 'wbi_modules_settings', array() );
        if ( empty( $opts['wbi_cn_auto_nc_on_refund'] ) ) {
            return;
        }

        $order  = wc_get_order( $order_id );
        $refund = wc_get_order( $refund_id );
        if ( ! $order || ! $refund ) return;

        global $wpdb;
        $table_notes = $wpdb->prefix . self::TABLE_NOTES;
        $table_items = $wpdb->prefix . self::TABLE_ITEMS;

        $customer_id   = $order->get_customer_id();
        $customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $inv_number    = $order->get_meta( '_wbi_invoice_number', true );

        $pto_venta = absint( get_option( 'wbi_invoice_settings', array() )['punto_venta'] ?? 1 );
        $letter    = 'B'; // Default.
        $type      = 'credit';
        $numero    = $this->next_numero( $type, $letter, $pto_venta );
        $note_number = sprintf( 'NC-%s-%04d-%08d', $letter, $pto_venta, $numero );
        $reason    = 'Devolución de mercadería';
        $subtotal  = 0;
        $iva_21    = 0;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert(
            $table_notes,
            array(
                'note_number'            => $note_number,
                'type'                   => $type,
                'letter'                 => $letter,
                'pto_venta'              => $pto_venta,
                'numero'                 => $numero,
                'related_invoice_number' => $inv_number ?: '',
                'order_id'               => $order_id,
                'customer_id'            => $customer_id,
                'customer_name'          => $customer_name,
                'reason'                 => $reason,
                'subtotal'               => 0,
                'iva_21'                 => 0,
                'total'                  => abs( $refund->get_total() ),
                'issue_date'             => current_time( 'Y-m-d' ),
                'created_by'             => get_current_user_id(),
                'status'                 => 'draft',
            ),
            array( '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%f', '%f', '%f', '%s', '%d', '%s' )
        );
        $new_id = $wpdb->insert_id;
        // phpcs:enable

        if ( $new_id ) {
            // Insert one summary item from refund.
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert(
                $table_items,
                array(
                    'note_id'     => $new_id,
                    'description' => sprintf( 'Devolución pedido #%d', $order_id ),
                    'quantity'    => 1,
                    'unit_price'  => abs( $refund->get_total() ),
                    'iva_rate'    => 0,
                    'iva_amount'  => 0,
                    'line_total'  => abs( $refund->get_total() ),
                ),
                array( '%d', '%s', '%f', '%f', '%f', '%f', '%f' )
            );
            // phpcs:enable
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Get (and increment) the next note number for a given type/letter/pto_venta combination.
     */
    private function next_numero( $type, $letter, $pto_venta ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NOTES;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $max = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(numero) FROM {$table} WHERE type=%s AND letter=%s AND pto_venta=%d",
            $type, $letter, $pto_venta
        ) );
        // phpcs:enable
        return $max + 1;
    }

    /**
     * Sync the note with the Cash Flow module (if active).
     * NC reduces income, ND increases income.
     */
    private function sync_cashflow( $note_id ) {
        if ( ! class_exists( 'WBI_Cashflow_Module' ) ) return;
        // Cash Flow uses get_option('wbi_cashflow_expenses') for manual entries.
        // NC/ND impact on revenue is reflected automatically since WC orders are the base.
        // For manual cash flow adjustments, we fire an action that CashFlow can hook into.
        do_action( 'wbi_cn_cashflow_sync', $note_id );
    }

    /**
     * Fire a notification via WBI Notifications module.
     */
    private function fire_notification( $event, $note_id, $note ) {
        if ( ! class_exists( 'WBI_Notifications_Module' ) ) return;
        do_action(
            'wbi_push_notification',
            sprintf(
                '💳 Nota %s #%s %s.',
                strtoupper( $note->type === 'credit' ? 'NC' : 'ND' ),
                esc_html( $note->note_number ),
                $event === 'authorized' ? 'autorizada' : $event
            )
        );
    }

    /**
     * Return an HTML badge for the note type.
     */
    private function type_badge( $type ) {
        if ( $type === 'credit' ) {
            return '<span style="background:#27ae60;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:bold;">NC</span>';
        }
        return '<span style="background:#e74c3c;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:bold;">ND</span>';
    }

    /**
     * Return an HTML badge for the note status.
     */
    private function status_badge( $status ) {
        $map = array(
            'draft'      => array( '#888', 'Borrador' ),
            'authorized' => array( '#27ae60', 'Autorizada' ),
            'cancelled'  => array( '#e74c3c', 'Anulada' ),
        );
        $cfg = $map[ $status ] ?? array( '#888', ucfirst( $status ) );
        return sprintf(
            '<span style="background:%s;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;">%s</span>',
            esc_attr( $cfg[0] ),
            esc_html( $cfg[1] )
        );
    }

    // =========================================================================
    // DASHBOARD WIDGET
    // =========================================================================

    /**
     * Register a dashboard widget showing NC/ND issued this month.
     * Called from the Dashboard module (if available).
     */
    public static function dashboard_widget_data() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NOTES;
        $month = current_time( 'Y-m' );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $data = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*)                                             AS qty,
                SUM(CASE WHEN type='credit' THEN total ELSE 0 END)  AS nc_total,
                SUM(CASE WHEN type='debit'  THEN total ELSE 0 END)  AS nd_total
             FROM {$table}
             WHERE DATE_FORMAT(issue_date,'%Y-%m') = %s
               AND status != 'cancelled'",
            $month
        ) );
        // phpcs:enable

        return array(
            'qty'      => (int) ( $data->qty ?? 0 ),
            'nc_total' => (float) ( $data->nc_total ?? 0 ),
            'nd_total' => (float) ( $data->nd_total ?? 0 ),
        );
    }
}
