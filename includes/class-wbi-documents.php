<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI Unified Documents Module (Facturación + Remitos)
 *
 * Replaces the separate class-wbi-invoice.php and class-wbi-remitos.php modules.
 * All document generation is done from a dedicated admin page, NOT from the order metabox.
 * The order metabox is read-only, following the Picking module pattern.
 */
class WBI_Documents_Module {

    public function __construct() {
        // Admin menu — single submenu entry
        add_action( 'admin_menu', array( $this, 'add_submenu' ), 100 );

        // Settings (invoicing fields — same option name wbi_invoice_settings for backward compat)
        add_action( 'admin_init', array( $this, 'register_invoice_settings' ) );

        // Read-only metabox on the order screen
        add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );

        // admin-post handlers
        add_action( 'admin_post_wbi_generate_document',    array( $this, 'handle_generate_document' ) );
        add_action( 'admin_post_wbi_view_document',        array( $this, 'handle_view_document' ) );
        add_action( 'admin_post_wbi_document_export_csv',  array( $this, 'handle_export_csv' ) );
    }

    // =========================================================================
    // ADMIN MENU
    // =========================================================================

    public function add_submenu() {
        add_submenu_page(
            'wbi-dashboard-view',
            '📑 Documentos',
            '<span class="dashicons dashicons-media-spreadsheet" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> Documentos',
            'manage_woocommerce',
            'wbi-documents',
            array( $this, 'render_page' )
        );
    }

    // =========================================================================
    // SETTINGS (wbi_invoice_settings — same option for backward compat)
    // =========================================================================

    public function register_invoice_settings() {
        register_setting( 'wbi_group', 'wbi_invoice_settings', array( $this, 'sanitize_invoice_settings' ) );

        add_settings_section( 'wbi_invoice_section', '📑 Facturación AFIP', null, 'wbi-settings' );

        $fields = array(
            'razon_social'      => 'Razón Social',
            'cuit'              => 'CUIT (XX-XXXXXXXX-X)',
            'tax_condition'     => 'Condición impositiva',
            'invoice_type'      => 'Tipo de comprobante por defecto',
            'invoice_prefix'    => 'Prefijo de factura',
            'punto_venta'       => 'Punto de Venta (1-99999)',
            'ingresos_brutos'   => 'Ingresos Brutos',
            'address'           => 'Domicilio comercial',
            'inicio_actividades'=> 'Inicio de Actividades',
        );

        foreach ( $fields as $id => $label ) {
            add_settings_field(
                'wbi_invoice_' . $id,
                $label,
                array( $this, 'render_invoice_field' ),
                'wbi-settings',
                'wbi_invoice_section',
                array( 'id' => $id )
            );
        }
    }

    public function sanitize_invoice_settings( $input ) {
        if ( ! is_array( $input ) ) return array();
        $clean = array();
        $clean['razon_social']       = sanitize_text_field( wp_unslash( $input['razon_social'] ?? '' ) );
        $clean['cuit']               = sanitize_text_field( wp_unslash( $input['cuit'] ?? '' ) );
        $clean['tax_condition']      = sanitize_text_field( wp_unslash( $input['tax_condition'] ?? '' ) );
        $clean['invoice_type']       = sanitize_text_field( wp_unslash( $input['invoice_type'] ?? 'B' ) );
        $clean['invoice_prefix']     = sanitize_text_field( wp_unslash( $input['invoice_prefix'] ?? 'WBI' ) );
        $clean['punto_venta']        = absint( $input['punto_venta'] ?? 1 );
        $clean['ingresos_brutos']    = sanitize_text_field( wp_unslash( $input['ingresos_brutos'] ?? '' ) );
        $clean['address']            = sanitize_textarea_field( wp_unslash( $input['address'] ?? '' ) );
        $clean['inicio_actividades'] = sanitize_text_field( wp_unslash( $input['inicio_actividades'] ?? '' ) );
        return $clean;
    }

    public function render_invoice_field( $args ) {
        $id   = $args['id'];
        $opts = get_option( 'wbi_invoice_settings', array() );
        $val  = isset( $opts[ $id ] ) ? esc_attr( $opts[ $id ] ) : '';

        switch ( $id ) {
            case 'tax_condition':
                echo '<select name="wbi_invoice_settings[tax_condition]">';
                foreach ( array( 'Responsable Inscripto', 'Monotributista', 'Exento' ) as $opt ) {
                    $sel = selected( $val, $opt, false );
                    echo '<option value="' . esc_attr( $opt ) . '" ' . $sel . '>' . esc_html( $opt ) . '</option>';
                }
                echo '</select>';
                break;
            case 'invoice_type':
                echo '<select name="wbi_invoice_settings[invoice_type]">';
                foreach ( array( 'A', 'B', 'C' ) as $opt ) {
                    $sel = selected( $val, $opt, false );
                    echo '<option value="' . esc_attr( $opt ) . '" ' . $sel . '>' . esc_html( $opt ) . '</option>';
                }
                echo '</select>';
                break;
            case 'address':
                echo '<textarea name="wbi_invoice_settings[address]" rows="3" class="regular-text">' . esc_textarea( $opts['address'] ?? '' ) . '</textarea>';
                break;
            case 'inicio_actividades':
                echo '<input type="date" name="wbi_invoice_settings[inicio_actividades]" value="' . esc_attr( $val ) . '">';
                break;
            default:
                echo '<input type="text" name="wbi_invoice_settings[' . esc_attr( $id ) . ']" value="' . esc_attr( $val ) . '" class="regular-text">';
        }
    }

    // =========================================================================
    // READ-ONLY METABOX (no forms, no submit buttons, no nonces)
    // =========================================================================

    public function register_meta_box() {
        $screens = array( 'shop_order' );
        if ( function_exists( 'wc_get_page_screen_id' ) ) {
            $screens[] = wc_get_page_screen_id( 'shop-order' );
        }
        foreach ( array_unique( $screens ) as $screen ) {
            add_meta_box(
                'wbi_documents_metabox',
                '<span class="dashicons dashicons-media-spreadsheet" style="vertical-align:middle;margin-right:4px;"></span> Documentos WBI',
                array( $this, 'render_meta_box' ),
                $screen,
                'side',
                'high'
            );
        }
    }

    public function render_meta_box( $post_or_order ) {
        if ( $post_or_order instanceof WP_Post ) {
            $order = wc_get_order( $post_or_order->ID );
        } else {
            $order = $post_or_order;
        }
        if ( ! $order ) return;

        $order_id   = $order->get_id();
        $inv_number = $order->get_meta( '_wbi_invoice_number', true );
        $inv_type   = $order->get_meta( '_wbi_invoice_type', true );
        $inv_date   = $order->get_meta( '_wbi_invoice_date', true );
        $rem_number = $order->get_meta( '_wbi_remito_number', true );
        $rem_date   = $order->get_meta( '_wbi_remito_date', true );

        $docs_url = admin_url( 'admin.php?page=wbi-documents' );

        echo '<div style="font-size:12px;">';

        if ( $inv_number ) {
            $view_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=wbi_view_document&type=invoice&order_id=' . $order_id ),
                'wbi_view_document_' . $order_id
            );
            echo '<p><strong>📑 Factura ' . esc_html( $inv_type ) . ':</strong> ' . esc_html( $inv_number ) . '<br>';
            if ( $inv_date ) {
                echo '<small>' . esc_html( date_i18n( 'd/m/Y', strtotime( $inv_date ) ) ) . '</small>';
            }
            echo '</p>';
            echo '<a href="' . esc_url( $view_url ) . '" target="_blank" class="button button-small" style="margin-bottom:6px;">📄 Ver PDF Factura</a>';
        }

        if ( $rem_number ) {
            $rem_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=wbi_view_document&type=remito&order_id=' . $order_id ),
                'wbi_view_document_' . $order_id
            );
            echo '<p><strong>📄 Remito N°:</strong> ' . esc_html( str_pad( intval( $rem_number ), 6, '0', STR_PAD_LEFT ) );
            if ( $rem_date ) {
                echo '<br><small>' . esc_html( date_i18n( 'd/m/Y', strtotime( $rem_date ) ) ) . '</small>';
            }
            echo '</p>';
            echo '<a href="' . esc_url( $rem_url ) . '" target="_blank" class="button button-small" style="margin-bottom:6px;">🖨 Ver PDF Remito</a>';
        }

        if ( ! $inv_number && ! $rem_number ) {
            echo '<p style="color:#888;">Sin documentos generados.</p>';
        }

        $orden_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=wbi_view_document&type=orden&order_id=' . $order_id ),
            'wbi_view_document_' . $order_id
        );
        echo '<hr style="margin:8px 0;">';
        echo '<a href="' . esc_url( $orden_url ) . '" target="_blank" class="button button-small" style="width:100%;text-align:center;">📦 Orden de Pedido</a>';

        echo '<p style="margin-top:6px;"><a href="' . esc_url( $docs_url ) . '" class="button button-small">🗂 Ir a Documentos →</a></p>';
        echo '</div>';
    }

    // =========================================================================
    // INVOICE NUMBER HELPERS
    // =========================================================================

    private function next_invoice_number() {
        $counter = absint( get_option( 'wbi_invoice_counter', 0 ) ) + 1;
        update_option( 'wbi_invoice_counter', $counter );
        return $counter;
    }

    private function format_invoice_number() {
        $opts   = get_option( 'wbi_invoice_settings', array() );
        $prefix = ! empty( $opts['invoice_prefix'] ) ? strtoupper( $opts['invoice_prefix'] ) : 'WBI';
        $pv     = str_pad( absint( $opts['punto_venta'] ?? 1 ), 4, '0', STR_PAD_LEFT );
        $num    = str_pad( $this->next_invoice_number(), 8, '0', STR_PAD_LEFT );
        return $prefix . '-' . $pv . '-' . $num;
    }

    private function next_remito_number() {
        $counter = (int) get_option( 'wbi_remito_counter', 0 ) + 1;
        update_option( 'wbi_remito_counter', $counter, false );
        return $counter;
    }

    // =========================================================================
    // ADMIN-POST: GENERATE DOCUMENT (invoice or remito)
    // =========================================================================

    public function handle_generate_document() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Sin permisos.' );
        check_admin_referer( 'wbi_generate_document', '_wbi_doc_nonce' );

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $doc_type = sanitize_text_field( wp_unslash( $_POST['doc_type'] ?? '' ) );

        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_die( 'Pedido no encontrado.' );

        if ( $doc_type === 'invoice' ) {
            $this->generate_invoice( $order );
        } elseif ( $doc_type === 'remito' ) {
            $this->generate_remito( $order );
        } elseif ( $doc_type === 'orden' ) {
            $this->generate_orden( $order );
        } else {
            wp_die( 'Tipo de documento inválido.' );
        }
    }

    private function generate_invoice( $order ) {
        $order_id = $order->get_id();

        $type = sanitize_text_field( wp_unslash( $_POST['invoice_type'] ?? 'B' ) );
        if ( ! in_array( $type, array( 'A', 'B', 'C' ), true ) ) $type = 'B';

        $cuit          = sanitize_text_field( wp_unslash( $_POST['customer_cuit'] ?? '' ) );
        $tax_condition = sanitize_text_field( wp_unslash( $_POST['customer_tax_condition'] ?? 'Consumidor Final' ) );
        $razon_social  = sanitize_text_field( wp_unslash( $_POST['customer_razon_social'] ?? '' ) );

        $inv_number = $this->format_invoice_number();
        $inv_date   = current_time( 'Y-m-d' );

        $opts  = get_option( 'wbi_invoice_settings', array() );
        $items = array();
        foreach ( $order->get_items() as $item ) {
            $items[] = array(
                'name'     => $item->get_name(),
                'qty'      => $item->get_quantity(),
                'price'    => $order->get_item_subtotal( $item, false ),
                'subtotal' => $item->get_subtotal(),
            );
        }

        $inv_data = array(
            'number'                => $inv_number,
            'type'                  => $type,
            'date'                  => $inv_date,
            'order_id'              => $order_id,
            'seller'                => $opts,
            'customer_name'         => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'customer_email'        => $order->get_billing_email(),
            'customer_address'      => $order->get_billing_address_1() . ' ' . $order->get_billing_address_2(),
            'customer_city'         => $order->get_billing_city(),
            'customer_cuit'         => $cuit,
            'customer_tax_cond'     => $tax_condition,
            'customer_razon_social' => $razon_social,
            'items'                 => $items,
            'subtotal'              => $order->get_subtotal(),
            'total'                 => $order->get_total(),
            'cae'                   => '',
            'cae_vto'               => '',
        );

        $order->update_meta_data( '_wbi_invoice_number', $inv_number );
        $order->update_meta_data( '_wbi_invoice_type',   $type );
        $order->update_meta_data( '_wbi_invoice_date',   $inv_date );
        $order->update_meta_data( '_wbi_invoice_data',   wp_json_encode( $inv_data ) );
        $order->update_meta_data( '_wbi_customer_cuit',           $cuit );
        $order->update_meta_data( '_wbi_customer_tax_condition',  $tax_condition );
        $order->save();

        $redirect = admin_url(
            'admin-post.php?action=wbi_view_document&type=invoice&order_id=' . $order_id .
            '&_wpnonce=' . wp_create_nonce( 'wbi_view_document_' . $order_id )
        );
        wp_redirect( $redirect );
        exit;
    }

    private function generate_remito( $order ) {
        $order_id = $order->get_id();

        $existing = $order->get_meta( '_wbi_remito_number', true );
        if ( ! $existing ) {
            $counter = $this->next_remito_number();
            $order->update_meta_data( '_wbi_remito_number', $counter );
            $order->update_meta_data( '_wbi_remito_date',   current_time( 'mysql' ) );
            $order->save();
        }

        $redirect = admin_url(
            'admin-post.php?action=wbi_view_document&type=remito&order_id=' . $order_id .
            '&_wpnonce=' . wp_create_nonce( 'wbi_view_document_' . $order_id )
        );
        wp_redirect( $redirect );
        exit;
    }

    private function generate_orden( $order ) {
        $order_id = $order->get_id();
        $redirect = admin_url(
            'admin-post.php?action=wbi_view_document&type=orden&order_id=' . $order_id .
            '&_wpnonce=' . wp_create_nonce( 'wbi_view_document_' . $order_id )
        );
        wp_redirect( $redirect );
        exit;
    }

    // =========================================================================
    // ADMIN-POST: VIEW DOCUMENT PDF
    // =========================================================================

    public function handle_view_document() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Sin permisos.' );

        $order_id = absint( $_GET['order_id'] ?? 0 );
        $doc_type = sanitize_text_field( wp_unslash( $_GET['type'] ?? '' ) );

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'wbi_view_document_' . $order_id ) ) {
            wp_die( 'Nonce inválido.' );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_die( 'Pedido no encontrado.' );

        if ( $doc_type === 'invoice' ) {
            $raw = $order->get_meta( '_wbi_invoice_data', true );
            if ( ! $raw ) wp_die( 'No existe factura para este pedido.' );
            $inv = json_decode( $raw, true );
            if ( ! $inv ) wp_die( 'Datos de factura corruptos.' );
            if ( ob_get_length() ) ob_end_clean();
            header( 'Content-Type: text/html; charset=utf-8' );
            $this->render_invoice_pdf( $inv );
        } elseif ( $doc_type === 'remito' ) {
            if ( ob_get_length() ) ob_end_clean();
            header( 'Content-Type: text/html; charset=utf-8' );
            $this->render_remito_pdf( $order );
        } elseif ( $doc_type === 'orden' ) {
            if ( ob_get_length() ) ob_end_clean();
            header( 'Content-Type: text/html; charset=utf-8' );
            $this->render_orden_pdf( $order );
        } else {
            wp_die( 'Tipo de documento inválido.' );
        }
        exit;
    }

    // =========================================================================
    // ADMIN-POST: EXPORT CSV
    // =========================================================================

    public function handle_export_csv() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Sin permisos.' );

        $export_type = sanitize_text_field( wp_unslash( $_GET['export_type'] ?? 'invoices' ) );

        if ( $export_type === 'invoices' ) {
            check_admin_referer( 'wbi_invoice_export' );
            $this->export_invoices_csv();
        } else {
            check_admin_referer( 'wbi_remito_export' );
            $this->export_remitos_csv();
        }
    }

    private function export_invoices_csv() {
        $order_ids = wc_get_orders( array(
            'meta_key'     => '_wbi_invoice_number',
            'meta_compare' => 'EXISTS',
            'limit'        => 10000,
            'orderby'      => 'ID',
            'order'        => 'DESC',
            'return'       => 'ids',
        ) );

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="facturas-wbi-' . gmdate( 'Y-m-d' ) . '.csv"' );
        echo "\xEF\xBB\xBF";
        echo "Nro Factura,Tipo,Fecha,Nro Pedido,Cliente,CUIT,Total\n";
        foreach ( $order_ids as $oid ) {
            $oid   = intval( $oid );
            $order = wc_get_order( $oid );
            if ( ! $order ) continue;
            $inv_date_raw = $order->get_date_created();
            echo implode( ',', array_map( array( $this, 'csv_escape' ), array(
                $order->get_meta( '_wbi_invoice_number', true ),
                $order->get_meta( '_wbi_invoice_type', true ),
                $inv_date_raw ? $inv_date_raw->date( 'd/m/Y' ) : '',
                $oid,
                trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                $order->get_meta( '_wbi_customer_cuit', true ),
                $order->get_total(),
            ) ) ) . "\n";
        }
        exit;
    }

    private function export_remitos_csv() {
        $order_ids = wc_get_orders( array(
            'meta_key'     => '_wbi_remito_number',
            'meta_compare' => 'EXISTS',
            'return'       => 'ids',
            'limit'        => 10000,
        ) );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="wbi-remitos-export-' . gmdate( 'Y-m-d' ) . '.csv"' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'remito_number', 'order_id', 'date', 'customer', 'total' ) );
        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) continue;
            $remito_date = $order->get_meta( '_wbi_remito_date', true );
            fputcsv( $out, array(
                str_pad( intval( $order->get_meta( '_wbi_remito_number', true ) ), 6, '0', STR_PAD_LEFT ),
                $order_id,
                $remito_date ? date_i18n( 'd/m/Y', strtotime( $remito_date ) ) : '',
                trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                $order->get_total(),
            ) );
        }
        fclose( $out );
        exit;
    }

    private function csv_escape( $val ) {
        $val = str_replace( '"', '""', $val );
        return '"' . $val . '"';
    }

    // =========================================================================
    // MAIN PAGE (TABS)
    // =========================================================================

    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Sin permisos.' );

        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'pending';
        $base_url   = admin_url( 'admin.php?page=wbi-documents' );

        // If a generate form was submitted, show the generate interface instead of the tab
        if ( isset( $_GET['generate'] ) && isset( $_GET['order_id'] ) ) {
            $this->render_generate_interface(
                absint( $_GET['order_id'] ),
                sanitize_text_field( wp_unslash( $_GET['generate'] ) )
            );
            return;
        }

        ?>
        <div class="wrap">
            <h1>📑 Documentos Comerciales</h1>

            <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
                <a href="<?php echo esc_url( $base_url . '&tab=pending' ); ?>"
                   class="nav-tab <?php echo $active_tab === 'pending' ? 'nav-tab-active' : ''; ?>">
                    📋 Pedidos sin Documento
                </a>
                <a href="<?php echo esc_url( $base_url . '&tab=invoices' ); ?>"
                   class="nav-tab <?php echo $active_tab === 'invoices' ? 'nav-tab-active' : ''; ?>">
                    📑 Facturas Emitidas
                </a>
                <a href="<?php echo esc_url( $base_url . '&tab=remitos' ); ?>"
                   class="nav-tab <?php echo $active_tab === 'remitos' ? 'nav-tab-active' : ''; ?>">
                    📄 Remitos Generados
                </a>
                <a href="<?php echo esc_url( $base_url . '&tab=ordenes' ); ?>"
                   class="nav-tab <?php echo $active_tab === 'ordenes' ? 'nav-tab-active' : ''; ?>">
                    📦 Órdenes de Pedido
                </a>
            </nav>

            <?php
            if ( $active_tab === 'pending' ) {
                $this->render_tab_pending();
            } elseif ( $active_tab === 'invoices' ) {
                $this->render_tab_invoices();
            } elseif ( $active_tab === 'remitos' ) {
                $this->render_tab_remitos();
            } elseif ( $active_tab === 'ordenes' ) {
                $this->render_tab_ordenes();
            }
            ?>
        </div>
        <?php
    }

    // =========================================================================
    // TAB: PEDIDOS SIN DOCUMENTO
    // =========================================================================

    private function render_tab_pending() {
        $per_page = 20;
        $paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $offset   = ( $paged - 1 ) * $per_page;

        // Get completed + processing orders that have neither invoice nor remito
        $all_ids = wc_get_orders( array(
            'status'  => array( 'wc-completed', 'wc-processing' ),
            'limit'   => -1,
            'return'  => 'ids',
            'orderby' => 'date',
            'order'   => 'DESC',
        ) );

        // Filter out orders that already have a document
        $pending_ids = array_filter( $all_ids, function( $id ) {
            $order = wc_get_order( $id );
            if ( ! $order ) return false;
            return ! $order->get_meta( '_wbi_invoice_number', true )
                && ! $order->get_meta( '_wbi_remito_number', true );
        } );
        $pending_ids  = array_values( $pending_ids );
        $total        = count( $pending_ids );
        $total_pages  = $total > 0 ? (int) ceil( $total / $per_page ) : 1;
        $paged_ids    = array_slice( $pending_ids, $offset, $per_page );

        $base_url = admin_url( 'admin.php?page=wbi-documents&tab=pending' );

        echo '<p style="color:#555;">Total: <strong>' . intval( $total ) . '</strong> pedidos sin documento.</p>';
        ?>
        <table class="widefat striped wbi-sortable">
            <thead>
                <tr>
                    <th>#Pedido</th>
                    <th>Fecha</th>
                    <th>Cliente</th>
                    <th>Estado</th>
                    <th>Total</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $paged_ids ) ) : ?>
                <tr><td colspan="6" style="text-align:center;color:#888;">¡No hay pedidos pendientes de documentar!</td></tr>
            <?php else : ?>
                <?php foreach ( $paged_ids as $order_id ) :
                    $order = wc_get_order( $order_id );
                    if ( ! $order ) continue;
                    $date_obj    = $order->get_date_created();
                    $date_fmt    = $date_obj ? $date_obj->date_i18n( 'd/m/Y' ) : '—';
                    $client_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: '—';
                    $status      = wc_get_order_status_name( $order->get_status() );
                    $total       = wc_price( $order->get_total() );
                    $inv_url     = esc_url( admin_url( 'admin.php?page=wbi-documents&generate=invoice&order_id=' . $order_id ) );
                    $rem_url     = esc_url( admin_url( 'admin.php?page=wbi-documents&generate=remito&order_id=' . $order_id ) );
                    $orden_url   = esc_url( admin_url( 'admin.php?page=wbi-documents&generate=orden&order_id=' . $order_id ) );
                    $edit_url    = esc_url( $order->get_edit_order_url() );
                ?>
                <tr>
                    <td><a href="<?php echo $edit_url; ?>">#<?php echo intval( $order_id ); ?></a></td>
                    <td><?php echo esc_html( $date_fmt ); ?></td>
                    <td><?php echo esc_html( $client_name ); ?></td>
                    <td><?php echo esc_html( $status ); ?></td>
                    <td><?php echo $total; ?></td>
                    <td>
                        <a href="<?php echo $inv_url; ?>" class="button button-small button-primary">📑 Generar Factura</a>
                        <a href="<?php echo $rem_url; ?>" class="button button-small" style="margin-left:4px;">📄 Generar Remito</a>
                        <a href="<?php echo $orden_url; ?>" class="button button-small" style="margin-left:4px;">📦 Orden de Pedido</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <?php
        if ( $total_pages > 1 ) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links( array(
                'base'      => add_query_arg( 'paged', '%#%', $base_url ),
                'format'    => '',
                'current'   => $paged,
                'total'     => $total_pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ) );
            echo '</div></div>';
        }
    }

    // =========================================================================
    // TAB: FACTURAS EMITIDAS
    // =========================================================================

    private function render_tab_invoices() {
        $date_from   = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : gmdate( 'Y-m-d', strtotime( '-30 days' ) );
        $date_to     = isset( $_GET['date_to'] )   ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )   : gmdate( 'Y-m-d' );
        $type_filter = isset( $_GET['inv_type'] )  ? sanitize_text_field( wp_unslash( $_GET['inv_type'] ) )  : '';
        $paged       = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $per_page    = 20;
        $offset      = ( $paged - 1 ) * $per_page;

        $query_args = array(
            'meta_key'     => '_wbi_invoice_number',
            'meta_compare' => 'EXISTS',
            'date_created' => $date_from . '...' . $date_to,
            'return'       => 'ids',
            'limit'        => -1,
        );
        if ( in_array( $type_filter, array( 'A', 'B', 'C' ), true ) ) {
            $query_args['meta_query'] = array(
                array(
                    'key'     => '_wbi_invoice_type',
                    'value'   => $type_filter,
                    'compare' => '=',
                ),
            );
        }
        $all_ids    = wc_get_orders( $query_args );
        $total_rows = count( $all_ids );
        $page_ids   = array_slice( $all_ids, $offset, $per_page );

        $export_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=wbi_document_export_csv&export_type=invoices' ),
            'wbi_invoice_export'
        );

        $base_url = admin_url( 'admin.php?page=wbi-documents&tab=invoices&date_from=' . urlencode( $date_from ) . '&date_to=' . urlencode( $date_to ) );
        ?>
        <form method="get" style="margin-bottom:15px;">
            <input type="hidden" name="page" value="wbi-documents">
            <input type="hidden" name="tab" value="invoices">
            <label>Desde: <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>"></label>
            <label style="margin-left:8px;">Hasta: <input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>"></label>
            <label style="margin-left:8px;">Tipo:
                <select name="inv_type">
                    <option value="">Todos</option>
                    <?php foreach ( array( 'A', 'B', 'C' ) as $t ) : ?>
                        <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type_filter, $t ); ?>><?php echo esc_html( $t ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="button" style="margin-left:8px;">Filtrar</button>
            <a href="<?php echo esc_url( $export_url ); ?>" class="button" style="margin-left:8px;">⬇️ Exportar CSV</a>
        </form>

        <p style="color:#555;">Total: <strong><?php echo intval( $total_rows ); ?></strong> facturas en el período.</p>

        <table class="widefat fixed striped wbi-sortable">
            <thead>
                <tr>
                    <th>#Factura</th>
                    <th>Tipo</th>
                    <th>Fecha</th>
                    <th>#Pedido</th>
                    <th>Cliente</th>
                    <th>CUIT</th>
                    <th>Total</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $page_ids ) ) : ?>
                <tr><td colspan="8" style="text-align:center;color:#888;">Sin facturas en el período seleccionado.</td></tr>
            <?php else : ?>
                <?php foreach ( $page_ids as $oid ) :
                    $oid   = intval( $oid );
                    $order = wc_get_order( $oid );
                    if ( ! $order ) continue;
                    $cuit       = $order->get_meta( '_wbi_customer_cuit', true );
                    $inv_number = $order->get_meta( '_wbi_invoice_number', true );
                    $inv_type   = $order->get_meta( '_wbi_invoice_type', true );
                    $inv_date_r = $order->get_date_created();
                    $inv_date   = $inv_date_r ? $inv_date_r->date( 'd/m/Y' ) : '—';
                    $name       = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
                    $view_url   = wp_nonce_url(
                        admin_url( 'admin-post.php?action=wbi_view_document&type=invoice&order_id=' . $oid ),
                        'wbi_view_document_' . $oid
                    );
                    $edit_url = $order->get_edit_order_url();
                ?>
                <tr>
                    <td><?php echo esc_html( $inv_number ); ?></td>
                    <td><?php echo esc_html( $inv_type ); ?></td>
                    <td><?php echo esc_html( $inv_date ); ?></td>
                    <td><a href="<?php echo esc_url( $edit_url ); ?>">#<?php echo $oid; ?></a></td>
                    <td><?php echo esc_html( $name ); ?></td>
                    <td><?php echo esc_html( $cuit ); ?></td>
                    <td><?php echo wc_price( $order->get_total() ); ?></td>
                    <td><a href="<?php echo esc_url( $view_url ); ?>" target="_blank" class="button button-small">📄 Ver PDF</a></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <?php
        if ( $total_rows > $per_page ) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links( array(
                'base'      => add_query_arg( 'paged', '%#%', $base_url ),
                'format'    => '',
                'current'   => $paged,
                'total'     => ceil( $total_rows / $per_page ),
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ) );
            echo '</div></div>';
        }
    }

    // =========================================================================
    // TAB: REMITOS GENERADOS
    // =========================================================================

    private function render_tab_remitos() {
        $per_page = 20;
        $paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $offset   = ( $paged - 1 ) * $per_page;

        $all_ids = wc_get_orders( array(
            'meta_key'     => '_wbi_remito_number',
            'meta_compare' => 'EXISTS',
            'return'       => 'ids',
            'limit'        => -1,
        ) );
        $total       = count( $all_ids );
        $total_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 1;

        $paged_ids = wc_get_orders( array(
            'meta_key'     => '_wbi_remito_number',
            'meta_compare' => 'EXISTS',
            'return'       => 'ids',
            'limit'        => $per_page,
            'offset'       => $offset,
            'orderby'      => 'meta_value_num',
            'order'        => 'DESC',
        ) );

        $export_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=wbi_document_export_csv&export_type=remitos' ),
            'wbi_remito_export'
        );

        $base_url = admin_url( 'admin.php?page=wbi-documents&tab=remitos' );

        echo '<p style="color:#555;">Total: <strong>' . intval( $total ) . '</strong> remitos &nbsp;';
        echo '<a href="' . esc_url( $export_url ) . '" class="button">Exportar CSV</a></p>';
        ?>
        <table class="widefat striped wbi-sortable">
            <thead>
                <tr>
                    <th>#Remito</th>
                    <th>#Pedido</th>
                    <th>Fecha Remito</th>
                    <th>Cliente</th>
                    <th>Total</th>
                    <th>Reimprimir</th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $paged_ids ) ) : ?>
                <tr><td colspan="6" style="text-align:center;color:#888;">Aún no se generaron remitos.</td></tr>
            <?php else : ?>
                <?php foreach ( $paged_ids as $order_id ) :
                    $order = wc_get_order( $order_id );
                    if ( ! $order ) continue;
                    $rem_num  = $order->get_meta( '_wbi_remito_number', true );
                    $rem_date = $order->get_meta( '_wbi_remito_date', true );
                    $date_fmt = $rem_date ? date_i18n( 'd/m/Y', strtotime( $rem_date ) ) : '—';
                    $name     = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: '—';
                    $edit_url = $order->get_edit_order_url();
                    $print_url = wp_nonce_url(
                        admin_url( 'admin-post.php?action=wbi_view_document&type=remito&order_id=' . $order_id ),
                        'wbi_view_document_' . $order_id
                    );
                ?>
                <tr>
                    <td><strong><?php echo esc_html( str_pad( intval( $rem_num ), 6, '0', STR_PAD_LEFT ) ); ?></strong></td>
                    <td><a href="<?php echo esc_url( $edit_url ); ?>">#<?php echo intval( $order_id ); ?></a></td>
                    <td><?php echo esc_html( $date_fmt ); ?></td>
                    <td><?php echo esc_html( $name ); ?></td>
                    <td><?php echo wc_price( $order->get_total() ); ?></td>
                    <td><a href="<?php echo esc_url( $print_url ); ?>" target="_blank" class="button button-small">🖨 Reimprimir</a></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <?php
        if ( $total_pages > 1 ) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links( array(
                'base'      => add_query_arg( 'paged', '%#%', $base_url ),
                'format'    => '',
                'current'   => $paged,
                'total'     => $total_pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ) );
            echo '</div></div>';
        }
    }

    // =========================================================================
    // GENERATE INTERFACE (shown before form submission)
    // =========================================================================

    private function render_generate_interface( $order_id, $generate_type ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            echo '<div class="notice notice-error"><p>Pedido no encontrado.</p></div>';
            return;
        }

        $back_url   = esc_url( admin_url( 'admin.php?page=wbi-documents&tab=pending' ) );
        $action_url = esc_url( admin_url( 'admin-post.php' ) );
        $client_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $opts        = get_option( 'wbi_invoice_settings', array() );
        $default_type = ! empty( $opts['invoice_type'] ) ? $opts['invoice_type'] : 'B';

        if ( $generate_type === 'invoice' ) :
        ?>
        <div class="wrap">
            <h1>📑 Generar Factura — Pedido #<?php echo intval( $order_id ); ?></h1>
            <p><a href="<?php echo $back_url; ?>">&larr; Volver a la lista</a></p>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;max-width:900px;">
                <div style="background:#fff;border:1px solid #c3c4c7;padding:20px;border-radius:6px;">
                    <h3 style="margin-top:0;">📋 Datos del Pedido</h3>
                    <table class="widefat striped">
                        <tr><th>Pedido</th><td>#<?php echo intval( $order_id ); ?></td></tr>
                        <tr><th>Cliente</th><td><?php echo esc_html( $client_name ); ?></td></tr>
                        <tr><th>Email</th><td><?php echo esc_html( $order->get_billing_email() ); ?></td></tr>
                        <tr><th>Dirección</th><td><?php echo esc_html( $order->get_billing_address_1() . ', ' . $order->get_billing_city() ); ?></td></tr>
                        <tr><th>Total</th><td><?php echo wc_price( $order->get_total() ); ?></td></tr>
                        <tr><th>Estado</th><td><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></td></tr>
                    </table>
                    <h4>Detalle de Items</h4>
                    <table class="widefat striped">
                        <thead><tr><th>Producto</th><th>Cant.</th><th>Subtotal</th></tr></thead>
                        <tbody>
                        <?php foreach ( $order->get_items() as $item ) : ?>
                            <tr>
                                <td><?php echo esc_html( $item->get_name() ); ?></td>
                                <td><?php echo intval( $item->get_quantity() ); ?></td>
                                <td><?php echo wc_price( $item->get_subtotal() ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="background:#fff;border:1px solid #c3c4c7;padding:20px;border-radius:6px;">
                    <h3 style="margin-top:0;">📑 Datos Fiscales</h3>
                    <form method="post" action="<?php echo $action_url; ?>" target="_blank">
                        <?php wp_nonce_field( 'wbi_generate_document', '_wbi_doc_nonce' ); ?>
                        <input type="hidden" name="action" value="wbi_generate_document">
                        <input type="hidden" name="doc_type" value="invoice">
                        <input type="hidden" name="order_id" value="<?php echo intval( $order_id ); ?>">

                        <table class="form-table">
                            <tr>
                                <th><label>Tipo Comprobante</label></th>
                                <td>
                                    <select name="invoice_type" style="width:100%;">
                                        <?php foreach ( array( 'A', 'B', 'C' ) as $t ) : ?>
                                            <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $default_type, $t ); ?>>
                                                Factura <?php echo esc_html( $t ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">A = Responsable Inscripto, B = Consumidor Final, C = Monotributista</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label>Razón Social / Nombre</label></th>
                                <td><input type="text" name="customer_razon_social" value="<?php echo esc_attr( $client_name ); ?>" class="regular-text" style="width:100%;"></td>
                            </tr>
                            <tr>
                                <th><label>CUIT / CUIL / DNI</label></th>
                                <td>
                                    <input type="text" name="customer_cuit"
                                           value="<?php echo esc_attr( $order->get_meta( '_wbi_customer_cuit', true ) ); ?>"
                                           class="regular-text" style="width:100%;"
                                           placeholder="XX-XXXXXXXX-X">
                                </td>
                            </tr>
                            <tr>
                                <th><label>Condición IVA</label></th>
                                <td>
                                    <select name="customer_tax_condition" style="width:100%;">
                                        <?php
                                        $saved_tax = $order->get_meta( '_wbi_customer_tax_condition', true );
                                        foreach ( array( 'Consumidor Final', 'Responsable Inscripto', 'Monotributista', 'Exento' ) as $opt ) :
                                            $sel = selected( $saved_tax, $opt, false );
                                        ?>
                                            <option value="<?php echo esc_attr( $opt ); ?>" <?php echo $sel; ?>><?php echo esc_html( $opt ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" class="button button-primary button-large">
                                📑 Generar Factura y Ver PDF
                            </button>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <?php
        elseif ( $generate_type === 'remito' ) :
        ?>
        <div class="wrap">
            <h1>📄 Generar Remito — Pedido #<?php echo intval( $order_id ); ?></h1>
            <p><a href="<?php echo $back_url; ?>">&larr; Volver a la lista</a></p>

            <div style="max-width:600px;background:#fff;border:1px solid #c3c4c7;padding:20px;border-radius:6px;">
                <h3 style="margin-top:0;">📋 Resumen del Pedido</h3>
                <table class="widefat striped" style="margin-bottom:16px;">
                    <tr><th>Pedido</th><td>#<?php echo intval( $order_id ); ?></td></tr>
                    <tr><th>Cliente</th><td><?php echo esc_html( $client_name ); ?></td></tr>
                    <tr><th>Dirección</th><td><?php echo esc_html( $order->get_billing_address_1() . ', ' . $order->get_billing_city() ); ?></td></tr>
                    <tr><th>Total</th><td><?php echo wc_price( $order->get_total() ); ?></td></tr>
                </table>

                <form method="post" action="<?php echo $action_url; ?>" target="_blank">
                    <?php wp_nonce_field( 'wbi_generate_document', '_wbi_doc_nonce' ); ?>
                    <input type="hidden" name="action" value="wbi_generate_document">
                    <input type="hidden" name="doc_type" value="remito">
                    <input type="hidden" name="order_id" value="<?php echo intval( $order_id ); ?>">

                    <p class="submit">
                        <button type="submit" class="button button-primary button-large">
                            📄 Generar Remito y Ver PDF
                        </button>
                    </p>
                </form>
            </div>
        </div>
        <?php
        else :
            if ( $generate_type === 'orden' ) :
            ?>
        <div class="wrap">
            <h1>📦 Orden de Pedido — Pedido #<?php echo intval( $order_id ); ?></h1>
            <p><a href="<?php echo $back_url; ?>">&larr; Volver a la lista</a></p>

            <div style="max-width:600px;background:#fff;border:1px solid #c3c4c7;padding:20px;border-radius:6px;">
                <h3 style="margin-top:0;">📋 Resumen del Pedido</h3>
                <table class="widefat striped" style="margin-bottom:16px;">
                    <tr><th>Pedido</th><td>#<?php echo intval( $order_id ); ?></td></tr>
                    <tr><th>Cliente</th><td><?php echo esc_html( $client_name ); ?></td></tr>
                    <tr><th>Dirección</th><td><?php echo esc_html( $order->get_billing_address_1() . ', ' . $order->get_billing_city() ); ?></td></tr>
                    <tr><th>Total</th><td><?php echo wc_price( $order->get_total() ); ?></td></tr>
                    <tr><th>Estado</th><td><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></td></tr>
                </table>

                <form method="post" action="<?php echo $action_url; ?>" target="_blank">
                    <?php wp_nonce_field( 'wbi_generate_document', '_wbi_doc_nonce' ); ?>
                    <input type="hidden" name="action" value="wbi_generate_document">
                    <input type="hidden" name="doc_type" value="orden">
                    <input type="hidden" name="order_id" value="<?php echo intval( $order_id ); ?>">

                    <p class="submit">
                        <button type="submit" class="button button-primary button-large">
                            📦 Generar Orden de Pedido
                        </button>
                    </p>
                </form>
            </div>
        </div>
            <?php
            else :
                echo '<div class="notice notice-error"><p>Tipo de generación inválido.</p></div>';
            endif;
        endif;
    }

    // =========================================================================
    // PDF: INVOICE
    // =========================================================================

    private function render_invoice_pdf( $inv ) {
        $type     = esc_html( $inv['type'] );
        $number   = esc_html( $inv['number'] );
        $date     = esc_html( $inv['date'] );
        $seller   = $inv['seller'];
        $items    = is_array( $inv['items'] ) ? $inv['items'] : array();
        $total    = floatval( $inv['total'] );

        $iva_rate = ( $type === 'A' ) ? 0.21 : 0;
        ?>
<!DOCTYPE html>
<html lang="es-AR">
<head>
<meta charset="UTF-8">
<title>Factura <?php echo $type; ?> <?php echo $number; ?></title>
<style>
body { font-family: Arial, sans-serif; font-size: 12px; color: #333; margin: 20px; }
h1 { font-size: 24px; margin: 0; }
.badge { display:inline-block; width:60px; height:60px; line-height:60px; text-align:center;
         font-size:36px; font-weight:bold; border:3px solid #333; border-radius:4px; }
.header-grid { display:grid; grid-template-columns:1fr auto 1fr; gap:20px; border-bottom:2px solid #333; padding-bottom:10px; }
.section-title { font-weight:bold; background:#f5f5f5; padding:4px 8px; margin:10px 0 4px; }
table { width:100%; border-collapse:collapse; }
th, td { border:1px solid #ccc; padding:5px 8px; }
th { background:#f0f0f0; }
.totals td { text-align:right; }
.totals .label { text-align:left; font-weight:bold; }
.cae-box { border:1px solid #999; padding:10px; margin-top:15px; font-size:11px; }
@media print {
    body { margin:0; }
    .no-print { display:none; }
}
</style>
<script>
function wbiClosePdf() { if (!window.close()) { alert('Podés cerrar esta pestaña manualmente.'); } }
</script>
</head>
<body>
<div class="no-print" style="margin-bottom:15px;">
    <button onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
    <button onclick="wbiClosePdf()" style="margin-left:10px;">← Cerrar pestaña</button>
</div>

<div class="header-grid">
    <div>
        <h1><?php echo esc_html( $seller['razon_social'] ?? get_bloginfo( 'name' ) ); ?></h1>
        <p>CUIT: <?php echo esc_html( $seller['cuit'] ?? '' ); ?><br>
        <?php echo esc_html( $seller['tax_condition'] ?? '' ); ?><br>
        <?php echo esc_html( $seller['address'] ?? '' ); ?><br>
        IIBB: <?php echo esc_html( $seller['ingresos_brutos'] ?? '' ); ?><br>
        Inicio Actividades: <?php echo esc_html( $seller['inicio_actividades'] ?? '' ); ?></p>
    </div>
    <div style="text-align:center;">
        <div class="badge"><?php echo $type; ?></div>
        <p>Cod. <?php echo $type === 'A' ? '001' : ( $type === 'B' ? '006' : '011' ); ?></p>
    </div>
    <div style="text-align:right;">
        <p><strong>Factura <?php echo $type; ?></strong><br>
        Nº: <?php echo $number; ?><br>
        Fecha: <?php echo $date; ?></p>
    </div>
</div>

<div class="section-title">Datos del Cliente</div>
<table>
    <tr>
        <td><strong>Razón Social / Nombre:</strong> <?php echo esc_html( $inv['customer_razon_social'] ?: $inv['customer_name'] ); ?></td>
        <td><strong>CUIT / CUIL / DNI:</strong> <?php echo esc_html( $inv['customer_cuit'] ); ?></td>
    </tr>
    <tr>
        <td><strong>Domicilio:</strong> <?php echo esc_html( $inv['customer_address'] . ' ' . $inv['customer_city'] ); ?></td>
        <td><strong>Condición IVA:</strong> <?php echo esc_html( $inv['customer_tax_cond'] ); ?></td>
    </tr>
</table>

<div class="section-title">Detalle</div>
<table>
    <thead><tr><th>Producto</th><th>Cantidad</th><th>Precio Unit.</th><th>Subtotal</th></tr></thead>
    <tbody>
    <?php foreach ( $items as $item ) : ?>
        <tr>
            <td><?php echo esc_html( $item['name'] ); ?></td>
            <td style="text-align:center;"><?php echo intval( $item['qty'] ); ?></td>
            <td style="text-align:right;"><?php echo wc_price( floatval( $item['price'] ) ); ?></td>
            <td style="text-align:right;"><?php echo wc_price( floatval( $item['subtotal'] ) ); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<table class="totals" style="margin-top:8px; width:300px; margin-left:auto;">
    <?php if ( $iva_rate > 0 ) : ?>
    <tr><td class="label">Subtotal (Neto):</td><td><?php echo wc_price( $total / ( 1 + $iva_rate ) ); ?></td></tr>
    <tr><td class="label">IVA 21%:</td><td><?php echo wc_price( $total - $total / ( 1 + $iva_rate ) ); ?></td></tr>
    <?php endif; ?>
    <tr style="font-size:14px; font-weight:bold;"><td class="label">TOTAL:</td><td><?php echo wc_price( $total ); ?></td></tr>
</table>

<div class="cae-box">
    <strong>CAE:</strong> <?php echo esc_html( $inv['cae'] ?: '___________________' ); ?> &nbsp;&nbsp;
    <strong>Vto. CAE:</strong> <?php echo esc_html( $inv['cae_vto'] ?: '___________________' ); ?><br>
    <small>Comprobante generado por WBI Suite. CAE pendiente de validación con AFIP.</small>
</div>
</body>
</html>
        <?php
    }

    // =========================================================================
    // PDF: REMITO
    // =========================================================================

    private function render_remito_pdf( $order ) {
        $order_id      = $order->get_id();
        $remito_number = $order->get_meta( '_wbi_remito_number', true );
        $remito_date   = $order->get_meta( '_wbi_remito_date', true );

        $company_name = esc_html( get_option( 'wbi_company_name', get_bloginfo( 'name' ) ) );
        $company_cuit = esc_html( get_option( 'wbi_company_cuit', '' ) );
        $company_addr = esc_html( get_option( 'wbi_company_address', '' ) );

        $client_name  = esc_html( $order->get_formatted_billing_full_name() );
        $client_addr  = esc_html( implode( ', ', array_filter( array(
            $order->get_billing_address_1(),
            $order->get_billing_address_2(),
            $order->get_billing_city(),
            $order->get_billing_state(),
        ) ) ) );
        $client_phone = esc_html( $order->get_billing_phone() );

        $items          = $order->get_items();
        $notes          = esc_html( $order->get_customer_note() );
        $date_formatted = $remito_date ? date_i18n( 'd/m/Y', strtotime( $remito_date ) ) : date_i18n( 'd/m/Y' );
        ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Remito <?php echo intval( $remito_number ); ?> - Pedido #<?php echo intval( $order_id ); ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #000; padding: 20px; }
.remito-wrapper { max-width: 800px; margin: 0 auto; }
.remito-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #000; padding-bottom: 12px; margin-bottom: 16px; }
.company-info h2 { font-size: 18px; font-weight: bold; }
.company-info p { margin-top: 3px; color: #333; }
.remito-meta { text-align: right; }
.remito-meta .remito-number { font-size: 22px; font-weight: bold; color: #000; }
.remito-meta .remito-label { font-size: 11px; color: #555; text-transform: uppercase; letter-spacing: 1px; }
.section-title { font-weight: bold; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #555; margin-bottom: 6px; }
.client-box { border: 1px solid #ccc; padding: 10px 14px; margin-bottom: 16px; border-radius: 3px; }
.client-box p { margin-top: 3px; }
table.items { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
table.items th { background: #f5f5f5; border-bottom: 2px solid #000; padding: 7px 8px; text-align: left; font-size: 11px; text-transform: uppercase; }
table.items td { border-bottom: 1px solid #ddd; padding: 7px 8px; }
table.items tr:last-child td { border-bottom: 2px solid #000; }
.notes-box { border: 1px solid #ccc; padding: 8px 12px; margin-bottom: 20px; font-size: 11px; color: #555; }
.signature-line { margin-top: 40px; border-top: 1px solid #000; padding-top: 6px; text-align: center; font-size: 11px; color: #555; }
.footer-bar { margin-top: 30px; border-top: 1px dashed #ccc; padding-top: 8px; font-size: 10px; color: #888; text-align: center; }
@media print {
    body { padding: 0; }
    .no-print { display: none !important; }
}
</style>
<script>
function wbiClosePdf() { if (!window.close()) { alert('Podés cerrar esta pestaña manualmente.'); } }
</script>
</head>
<body>
<div class="no-print" style="margin-bottom:15px;">
    <button onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
    <button onclick="wbiClosePdf()" style="margin-left:10px;">← Cerrar pestaña</button>
</div>
<div class="remito-wrapper">

    <div class="remito-header">
        <div class="company-info">
            <h2><?php echo $company_name; ?></h2>
            <?php if ( $company_cuit ) : ?>
                <p>CUIT: <?php echo $company_cuit; ?></p>
            <?php endif; ?>
            <?php if ( $company_addr ) : ?>
                <p><?php echo $company_addr; ?></p>
            <?php endif; ?>
        </div>
        <div class="remito-meta">
            <div class="remito-label">Remito</div>
            <div class="remito-number">N° <?php echo str_pad( intval( $remito_number ), 6, '0', STR_PAD_LEFT ); ?></div>
            <div style="margin-top:6px; font-size:12px;">Fecha: <strong><?php echo esc_html( $date_formatted ); ?></strong></div>
            <div style="margin-top:3px; font-size:11px; color:#555;">Pedido #<?php echo intval( $order_id ); ?></div>
        </div>
    </div>

    <div class="client-box">
        <div class="section-title">Destinatario</div>
        <p><strong><?php echo $client_name; ?></strong></p>
        <?php if ( $client_addr ) : ?><p><?php echo $client_addr; ?></p><?php endif; ?>
        <?php if ( $client_phone ) : ?><p>Tel: <?php echo $client_phone; ?></p><?php endif; ?>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th style="width:50%;">Producto</th>
                <th style="width:20%;">SKU</th>
                <th style="width:15%;">Cantidad</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $items as $item ) :
            $product = $item->get_product();
            $sku     = $product ? esc_html( $product->get_sku() ) : '—';
        ?>
            <tr>
                <td><?php echo esc_html( $item->get_name() ); ?></td>
                <td><?php echo $sku; ?></td>
                <td><?php echo intval( $item->get_quantity() ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ( $notes ) : ?>
    <div class="notes-box">
        <strong>Notas del pedido:</strong> <?php echo $notes; ?>
    </div>
    <?php endif; ?>

    <div class="signature-line">
        Recibí conforme: ________________&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Firma: ________________&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Aclaración: ________________
    </div>

    <div class="footer-bar">
        Documento generado por WBI Suite — <?php echo $company_name; ?>
    </div>

</div>
</body>
</html>
        <?php
    }

    // =========================================================================
    // TAB: ÓRDENES DE PEDIDO
    // =========================================================================

    private function render_tab_ordenes() {
        $per_page = 20;
        $paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $offset   = ( $paged - 1 ) * $per_page;

        $all_ids = wc_get_orders( array(
            'status'  => array( 'wc-processing', 'wc-on-hold' ),
            'limit'   => -1,
            'return'  => 'ids',
            'orderby' => 'date',
            'order'   => 'DESC',
        ) );

        $total       = count( $all_ids );
        $total_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 1;
        $paged_ids   = array_slice( $all_ids, $offset, $per_page );

        $base_url = admin_url( 'admin.php?page=wbi-documents&tab=ordenes' );

        echo '<p style="color:#555;">Total: <strong>' . intval( $total ) . '</strong> pedidos para despachar.</p>';
        ?>
        <table class="widefat striped wbi-sortable">
            <thead>
                <tr>
                    <th>#Pedido</th>
                    <th>Fecha</th>
                    <th>Cliente</th>
                    <th>Método de Envío</th>
                    <th>Total</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $paged_ids ) ) : ?>
                <tr><td colspan="6" style="text-align:center;color:#888;">No hay pedidos para despachar.</td></tr>
            <?php else : ?>
                <?php foreach ( $paged_ids as $order_id ) :
                    $order = wc_get_order( $order_id );
                    if ( ! $order ) continue;
                    $date_obj     = $order->get_date_created();
                    $date_fmt     = $date_obj ? $date_obj->date_i18n( 'd/m/Y' ) : '—';
                    $client_name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: '—';
                    $edit_url     = esc_url( $order->get_edit_order_url() );
                    $shipping_methods = $order->get_shipping_methods();
                    $shipping_name = '—';
                    foreach ( $shipping_methods as $method ) {
                        $shipping_name = esc_html( $method->get_name() );
                        break;
                    }
                    $view_url = wp_nonce_url(
                        admin_url( 'admin-post.php?action=wbi_view_document&type=orden&order_id=' . $order_id ),
                        'wbi_view_document_' . $order_id
                    );
                ?>
                <tr>
                    <td><a href="<?php echo $edit_url; ?>">#<?php echo intval( $order_id ); ?></a></td>
                    <td><?php echo esc_html( $date_fmt ); ?></td>
                    <td><?php echo esc_html( $client_name ); ?></td>
                    <td><?php echo $shipping_name; ?></td>
                    <td><?php echo wc_price( $order->get_total() ); ?></td>
                    <td><a href="<?php echo esc_url( $view_url ); ?>" target="_blank" class="button button-small">📦 Ver Orden</a></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <?php
        if ( $total_pages > 1 ) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links( array(
                'base'      => add_query_arg( 'paged', '%#%', $base_url ),
                'format'    => '',
                'current'   => $paged,
                'total'     => $total_pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ) );
            echo '</div></div>';
        }
    }

    // =========================================================================
    // PDF: ORDEN DE PEDIDO
    // =========================================================================

    private function render_orden_pdf( $order ) {
        $order_id = $order->get_id();
        $opts     = get_option( 'wbi_invoice_settings', array() );

        $company_name = ! empty( $opts['razon_social'] ) ? $opts['razon_social'] : get_bloginfo( 'name' );
        $company_cuit = ! empty( $opts['cuit'] ) ? $opts['cuit'] : '';
        $company_addr = ! empty( $opts['address'] ) ? $opts['address'] : '';

        $date_obj   = $order->get_date_created();
        $date_fmt   = $date_obj ? $date_obj->date_i18n( 'd/m/Y' ) : date_i18n( 'd/m/Y' );
        $status_lbl = wc_get_order_status_name( $order->get_status() );

        // Customer data
        $customer_name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $customer_phone = $order->get_billing_phone();
        $customer_email = $order->get_billing_email();
        $customer_dni   = $order->get_meta( '_billing_dni', true ) ?: $order->get_meta( '_wbi_customer_cuit', true );

        // Shipping address (fallback to billing)
        $ship_addr1   = $order->get_shipping_address_1() ?: $order->get_billing_address_1();
        $ship_addr2   = $order->get_shipping_address_2() ?: $order->get_billing_address_2();
        $ship_city    = $order->get_shipping_city() ?: $order->get_billing_city();
        $ship_postcode = $order->get_shipping_postcode() ?: $order->get_billing_postcode();
        $ship_country = $order->get_shipping_country() ?: $order->get_billing_country();

        $ship_state_code = $order->get_shipping_state() ?: $order->get_billing_state();
        $ship_state_name = $ship_state_code;
        if ( $ship_country && function_exists( 'WC' ) && WC()->countries ) {
            $states = WC()->countries->get_states( $ship_country );
            if ( is_array( $states ) && isset( $states[ $ship_state_code ] ) ) {
                $ship_state_name = $states[ $ship_state_code ];
            }
        }

        // Shipping method
        $shipping_methods = $order->get_shipping_methods();
        $shipping_name    = '—';
        foreach ( $shipping_methods as $method ) {
            $shipping_name = $method->get_name();
            break;
        }

        $customer_note = $order->get_customer_note();

        // Items
        $items = $order->get_items();
        ?>
<!DOCTYPE html>
<html lang="es-AR">
<head>
<meta charset="UTF-8">
<title>Orden de Pedido #<?php echo intval( $order_id ); ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #000; padding: 20px; }
.orden-wrapper { max-width: 800px; margin: 0 auto; }
.orden-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #000; padding-bottom: 12px; margin-bottom: 16px; }
.company-info h2 { font-size: 18px; font-weight: bold; }
.company-info p { margin-top: 3px; color: #333; }
.orden-meta { text-align: right; }
.orden-meta .orden-title { font-size: 20px; font-weight: bold; color: #000; text-transform: uppercase; letter-spacing: 1px; }
.orden-meta .orden-number { font-size: 16px; margin-top: 4px; }
.section-title { font-weight: bold; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #555; background: #f5f5f5; padding: 4px 8px; margin: 12px 0 6px; }
.info-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
.info-table th { width: 35%; text-align: left; padding: 5px 8px; font-weight: bold; background: #fafafa; border: 1px solid #ddd; }
.info-table td { padding: 5px 8px; border: 1px solid #ddd; }
table.items { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
table.items th { background: #f0f0f0; border-bottom: 2px solid #000; padding: 6px 8px; text-align: left; font-size: 11px; text-transform: uppercase; }
table.items td { border-bottom: 1px solid #ddd; padding: 6px 8px; }
table.items tr:last-child td { border-bottom: 2px solid #000; }
.totals-table { width: 280px; margin-left: auto; border-collapse: collapse; margin-bottom: 16px; }
.totals-table td { padding: 4px 8px; border: 1px solid #ddd; }
.totals-table .label { font-weight: bold; background: #fafafa; }
.totals-table .grand-total td { font-size: 14px; font-weight: bold; }
.signature-block { margin-top: 30px; border-top: 1px dashed #999; padding-top: 12px; font-size: 11px; color: #555; }
.footer-bar { margin-top: 20px; border-top: 1px dashed #ccc; padding-top: 8px; font-size: 10px; color: #888; text-align: center; }
@media print {
    body { padding: 0; }
    .no-print { display: none !important; }
}
</style>
<script>
function wbiClosePdf() { if (!window.close()) { alert('Podés cerrar esta pestaña manualmente.'); } }
</script>
</head>
<body>
<div class="no-print" style="margin-bottom:15px;">
    <button onclick="window.print()">🖨️ Imprimir</button>
    <button onclick="wbiClosePdf()" style="margin-left:10px;">← Cerrar pestaña</button>
</div>
<div class="orden-wrapper">

    <div class="orden-header">
        <div class="company-info">
            <h2><?php echo esc_html( $company_name ); ?></h2>
            <?php if ( $company_cuit ) : ?><p>CUIT: <?php echo esc_html( $company_cuit ); ?></p><?php endif; ?>
            <?php if ( $company_addr ) : ?><p><?php echo esc_html( $company_addr ); ?></p><?php endif; ?>
        </div>
        <div class="orden-meta">
            <div class="orden-title">Orden de Pedido</div>
            <div class="orden-number">Pedido #<?php echo intval( $order_id ); ?></div>
            <div style="margin-top:4px; font-size:12px;">Fecha: <strong><?php echo esc_html( $date_fmt ); ?></strong></div>
            <div style="margin-top:2px; font-size:11px; color:#555;">Estado: <?php echo esc_html( $status_lbl ); ?></div>
        </div>
    </div>

    <div class="section-title">Datos del Cliente</div>
    <table class="info-table">
        <tr><th>Nombre completo</th><td><?php echo esc_html( $customer_name ); ?></td></tr>
        <tr><th>Teléfono</th><td><?php echo esc_html( $customer_phone ); ?></td></tr>
        <tr><th>Email</th><td><?php echo esc_html( $customer_email ); ?></td></tr>
        <tr><th>DNI / CUIT</th><td><?php echo esc_html( $customer_dni ); ?></td></tr>
    </table>

    <div class="section-title">Dirección de Envío</div>
    <table class="info-table">
        <tr><th>Dirección</th><td><?php echo esc_html( $ship_addr1 ); ?></td></tr>
        <?php if ( $ship_addr2 ) : ?>
        <tr><th>Dirección 2</th><td><?php echo esc_html( $ship_addr2 ); ?></td></tr>
        <?php endif; ?>
        <tr><th>Localidad</th><td><?php echo esc_html( $ship_city ); ?></td></tr>
        <tr><th>Provincia</th><td><?php echo esc_html( $ship_state_name ); ?></td></tr>
        <tr><th>Código Postal</th><td><?php echo esc_html( $ship_postcode ); ?></td></tr>
        <tr><th>País</th><td><?php echo esc_html( $ship_country ); ?></td></tr>
    </table>

    <div class="section-title">Datos del Envío</div>
    <table class="info-table">
        <tr><th>Método de envío</th><td><?php echo esc_html( $shipping_name ); ?></td></tr>
        <?php if ( $customer_note ) : ?>
        <tr><th>Notas del cliente</th><td><?php echo esc_html( $customer_note ); ?></td></tr>
        <?php endif; ?>
    </table>

    <div class="section-title">Detalle de Productos</div>
    <table class="items">
        <thead>
            <tr>
                <th style="width:35%;">Producto</th>
                <th style="width:15%;">SKU</th>
                <th style="width:15%;">Cód. Barra</th>
                <th style="width:10%; text-align:center;">Cant.</th>
                <th style="width:10%; text-align:center;">Peso</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $items as $item ) :
            $product  = $item->get_product();
            $sku      = $product ? $product->get_sku() : '';
            $weight   = '';
            $barcode  = '';
            if ( $product ) {
                $weight  = $product->get_weight();
            $lookup_id = $product->get_id();
                $barcode = get_post_meta( $lookup_id, '_wbi_barcode', true );
            }
        ?>
            <tr>
                <td><?php echo esc_html( $item->get_name() ); ?></td>
                <td><?php echo esc_html( $sku ); ?></td>
                <td><?php echo esc_html( $barcode ); ?></td>
                <td style="text-align:center;"><?php echo intval( $item->get_quantity() ); ?></td>
                <td style="text-align:center;"><?php echo $weight ? esc_html( $weight ) . ' kg' : '—'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="section-title">Resumen</div>
    <table class="totals-table">
        <tr><td class="label">Subtotal</td><td><?php echo wc_price( $order->get_subtotal() ); ?></td></tr>
        <tr><td class="label">Envío</td><td><?php echo wc_price( $order->get_shipping_total() ); ?></td></tr>
        <tr class="grand-total"><td class="label">Total</td><td><?php echo wc_price( $order->get_total() ); ?></td></tr>
        <tr><td class="label">Método de pago</td><td><?php echo esc_html( $order->get_payment_method_title() ); ?></td></tr>
        <tr><td class="label">Bultos</td><td>________</td></tr>
    </table>

    <div class="signature-block">
        Preparado por: _________________________ &nbsp;&nbsp;
        Despachado por: _________________________ &nbsp;&nbsp;
        Fecha despacho: _________________________
    </div>

    <div class="footer-bar">
        Documento generado por WBI Suite
    </div>

</div>
</body>
</html>
        <?php
    }
}
