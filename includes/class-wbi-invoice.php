<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI AFIP Invoicing Module
 *
 * Genera facturas tipo A/B/C con numeración correlativa, metabox en el pedido,
 * PDF imprimible puro PHP/HTML y listado de facturas emitidas.
 */
class WBI_Invoice_Module {

    public function __construct() {
        // Admin submenu
        add_action( 'admin_menu', array( $this, 'add_submenu' ), 100 );

        // Settings section
        add_action( 'admin_init', array( $this, 'register_invoice_settings' ) );

        // Order meta box
        add_action( 'add_meta_boxes', array( $this, 'add_order_metabox' ) );

        // Save customer fiscal data from metabox (HPOS-compatible)
        add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_order_fiscal_meta' ) );
        add_action( 'woocommerce_update_order', array( $this, 'save_order_fiscal_meta' ) );

        // Admin-post: view/generate PDF
        add_action( 'admin_post_wbi_view_invoice', array( $this, 'handle_view_invoice' ) );

        // Admin-post: generate invoice
        add_action( 'admin_post_wbi_generate_invoice', array( $this, 'handle_generate_invoice' ) );

        // Admin-post: export CSV
        add_action( 'admin_post_wbi_invoice_export_csv', array( $this, 'handle_export_csv' ) );
    }

    // -------------------------------------------------------------------------
    // Admin Menu
    // -------------------------------------------------------------------------

    public function add_submenu() {
        add_submenu_page(
            'wbi-dashboard-view',
            '📑 Facturación',
            '<span class="dashicons dashicons-media-spreadsheet" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> Facturación',
            'manage_options',
            'wbi-invoices',
            array( $this, 'render_page' )
        );
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    public function register_invoice_settings() {
        register_setting( 'wbi_group', 'wbi_invoice_settings', array( $this, 'sanitize_invoice_settings' ) );

        add_settings_section( 'wbi_invoice_section', '📑 Facturación AFIP', null, 'wbi-settings' );

        $fields = array(
            'razon_social'     => 'Razón Social',
            'cuit'             => 'CUIT (XX-XXXXXXXX-X)',
            'tax_condition'    => 'Condición impositiva',
            'invoice_type'     => 'Tipo de comprobante por defecto',
            'invoice_prefix'   => 'Prefijo de factura',
            'punto_venta'      => 'Punto de Venta (1-99999)',
            'ingresos_brutos'  => 'Ingresos Brutos',
            'address'          => 'Domicilio comercial',
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
        $clean['razon_social']      = sanitize_text_field( wp_unslash( $input['razon_social'] ?? '' ) );
        $clean['cuit']              = sanitize_text_field( wp_unslash( $input['cuit'] ?? '' ) );
        $clean['tax_condition']     = sanitize_text_field( wp_unslash( $input['tax_condition'] ?? '' ) );
        $clean['invoice_type']      = sanitize_text_field( wp_unslash( $input['invoice_type'] ?? 'B' ) );
        $clean['invoice_prefix']    = sanitize_text_field( wp_unslash( $input['invoice_prefix'] ?? 'WBI' ) );
        $clean['punto_venta']       = absint( $input['punto_venta'] ?? 1 );
        $clean['ingresos_brutos']   = sanitize_text_field( wp_unslash( $input['ingresos_brutos'] ?? '' ) );
        $clean['address']           = sanitize_textarea_field( wp_unslash( $input['address'] ?? '' ) );
        $clean['inicio_actividades']= sanitize_text_field( wp_unslash( $input['inicio_actividades'] ?? '' ) );
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
                echo '<input type="date" name="wbi_invoice_settings[inicio_actividades]" value="' . $val . '">';
                break;
            default:
                echo '<input type="text" name="wbi_invoice_settings[' . esc_attr( $id ) . ']" value="' . $val . '" class="regular-text">';
        }
    }

    // -------------------------------------------------------------------------
    // Invoice number management
    // -------------------------------------------------------------------------

    private function next_invoice_number() {
        $counter = absint( get_option( 'wbi_invoice_counter', 0 ) ) + 1;
        update_option( 'wbi_invoice_counter', $counter );
        return $counter;
    }

    public function format_invoice_number( $type = null ) {
        $opts   = get_option( 'wbi_invoice_settings', array() );
        $prefix = ! empty( $opts['invoice_prefix'] ) ? strtoupper( $opts['invoice_prefix'] ) : 'WBI';
        $pv     = str_pad( absint( $opts['punto_venta'] ?? 1 ), 4, '0', STR_PAD_LEFT );
        $num    = str_pad( $this->next_invoice_number(), 8, '0', STR_PAD_LEFT );
        return $prefix . '-' . $pv . '-' . $num;
    }

    // -------------------------------------------------------------------------
    // Generate invoice
    // -------------------------------------------------------------------------

    public function handle_generate_invoice() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permisos.' );
        check_admin_referer( 'wbi_generate_invoice', '_wbi_inv_nonce' );

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $type     = sanitize_text_field( wp_unslash( $_POST['invoice_type'] ?? 'B' ) );
        if ( ! in_array( $type, array( 'A', 'B', 'C' ), true ) ) $type = 'B';

        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_die( 'Pedido no encontrado.' );

        $inv_number = $this->format_invoice_number( $type );
        $inv_date   = current_time( 'Y-m-d' );

        // Customer fiscal data
        $cuit          = sanitize_text_field( wp_unslash( $_POST['customer_cuit'] ?? '' ) );
        $tax_condition = sanitize_text_field( wp_unslash( $_POST['customer_tax_condition'] ?? 'Consumidor Final' ) );
        $razon_social  = sanitize_text_field( wp_unslash( $_POST['customer_razon_social'] ?? '' ) );

        // Build invoice data
        $opts = get_option( 'wbi_invoice_settings', array() );
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
            'number'               => $inv_number,
            'type'                 => $type,
            'date'                 => $inv_date,
            'order_id'             => $order_id,
            'seller'               => $opts,
            'customer_name'        => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'customer_email'       => $order->get_billing_email(),
            'customer_address'     => $order->get_billing_address_1() . ' ' . $order->get_billing_address_2(),
            'customer_city'        => $order->get_billing_city(),
            'customer_cuit'        => $cuit,
            'customer_tax_cond'    => $tax_condition,
            'customer_razon_social'=> $razon_social,
            'items'                => $items,
            'subtotal'             => $order->get_subtotal(),
            'total'                => $order->get_total(),
            'cae'                  => '',
            'cae_vto'              => '',
        );

        // Collect custom fields for the invoice
        $cf_config          = get_option( 'wbi_custom_fields_config', array() );
        $custom_fields_data = array();
        $customer_id_cf     = $order->get_customer_id();
        foreach ( $cf_config as $cf ) {
            $cf_key = sanitize_key( $cf['key'] );
            $val    = $order->get_meta( $cf_key, true );
            if ( ( '' === $val || false === $val ) && $customer_id_cf ) {
                $val = get_user_meta( $customer_id_cf, $cf_key, true );
            }
            if ( '' !== $val && false !== $val ) {
                $custom_fields_data[] = array(
                    'label' => $cf['label'],
                    'key'   => $cf_key,
                    'value' => $val,
                );
            }
        }
        $inv_data['custom_fields'] = $custom_fields_data;

        // Store meta (HPOS-compatible)
        $order->update_meta_data( '_wbi_invoice_number', $inv_number );
        $order->update_meta_data( '_wbi_invoice_type', $type );
        $order->update_meta_data( '_wbi_invoice_date', $inv_date );
        $order->update_meta_data( '_wbi_invoice_data', wp_json_encode( $inv_data ) );
        $order->update_meta_data( '_wbi_customer_cuit', $cuit );
        $order->update_meta_data( '_wbi_customer_tax_condition', $tax_condition );
        $order->save();

        $redirect = admin_url( 'admin-post.php?action=wbi_view_invoice&order_id=' . $order_id . '&_wpnonce=' . wp_create_nonce( 'wbi_view_invoice_' . $order_id ) );
        wp_redirect( $redirect );
        exit;
    }

    // -------------------------------------------------------------------------
    // View / Print invoice PDF (pure PHP/HTML)
    // -------------------------------------------------------------------------

    public function handle_view_invoice() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permisos.' );

        $order_id = absint( $_GET['order_id'] ?? 0 );
        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'wbi_view_invoice_' . $order_id ) ) wp_die( 'Nonce inválido.' );

        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_die( 'Pedido no encontrado.' );
        $raw = $order->get_meta( '_wbi_invoice_data', true );
        if ( ! $raw ) wp_die( 'No existe factura para este pedido.' );

        $inv = json_decode( $raw, true );
        if ( ! $inv ) wp_die( 'Datos de factura corruptos.' );

        $this->render_invoice_pdf( $inv );
        exit;
    }

    private function render_invoice_pdf( $inv ) {
        $type     = esc_html( $inv['type'] );
        $number   = esc_html( $inv['number'] );
        $date     = esc_html( $inv['date'] );
        $seller   = $inv['seller'];
        $items    = is_array( $inv['items'] ) ? $inv['items'] : array();
        $subtotal = floatval( $inv['subtotal'] );
        $total    = floatval( $inv['total'] );

        // IVA only for type A
        $iva_rate = ( $type === 'A' ) ? 0.21 : 0;
        $iva_amt  = $total * $iva_rate;
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
</head>
<body>
<div class="no-print" style="margin-bottom:15px;">
    <button onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
    <button onclick="window.close()" style="margin-left:10px;">✕ Cerrar</button>
</div>

<div class="header-grid">
    <div>
        <h1><?php echo esc_html( $seller['razon_social'] ?? get_bloginfo('name') ); ?></h1>
        <p>CUIT: <?php echo esc_html( $seller['cuit'] ?? '' ); ?><br>
        <?php echo esc_html( $seller['tax_condition'] ?? '' ); ?><br>
        <?php echo esc_html( $seller['address'] ?? '' ); ?><br>
        IIBB: <?php echo esc_html( $seller['ingresos_brutos'] ?? '' ); ?><br>
        Inicio Actividades: <?php echo esc_html( $seller['inicio_actividades'] ?? '' ); ?></p>
    </div>
    <div style="text-align:center;">
        <div class="badge"><?php echo $type; ?></div>
        <p>Cod. <?php echo $type === 'A' ? '001' : ($type === 'B' ? '006' : '011'); ?></p>
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

<?php
$custom_fields = isset( $inv['custom_fields'] ) ? $inv['custom_fields'] : array();
if ( ! empty( $custom_fields ) ) :
?>
<div class="section-title">Datos Adicionales del Cliente</div>
<table>
<?php
    $chunks = array_chunk( $custom_fields, 2 );
    foreach ( $chunks as $pair ) :
?>
    <tr>
        <td><strong><?php echo esc_html( $pair[0]['label'] ); ?>:</strong> <?php echo esc_html( $pair[0]['value'] ); ?></td>
        <?php if ( isset( $pair[1] ) ) : ?>
            <td><strong><?php echo esc_html( $pair[1]['label'] ); ?>:</strong> <?php echo esc_html( $pair[1]['value'] ); ?></td>
        <?php else : ?>
            <td></td>
        <?php endif; ?>
    </tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

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
    <small>Comprobante generado por wooErp. CAE pendiente de validación con AFIP.</small>
</div>
</body>
</html>
        <?php
    }

    // -------------------------------------------------------------------------
    // Order meta box
    // -------------------------------------------------------------------------

    public function add_order_metabox() {
        $screens = array( 'shop_order' );
        if ( function_exists( 'wc_get_page_screen_id' ) ) {
            $screens[] = wc_get_page_screen_id( 'shop-order' );
        }
        foreach ( array_unique( $screens ) as $screen ) {
            add_meta_box(
                'wbi_invoice_metabox',
                '📑 Factura AFIP',
                array( $this, 'render_order_metabox' ),
                $screen,
                'side',
                'default'
            );
        }
    }

    public function render_order_metabox( $post_or_order ) {
        if ( $post_or_order instanceof WP_Post ) {
            $order_id = $post_or_order->ID;
        } else {
            $order_id = $post_or_order->get_id();
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $inv_number    = $order->get_meta( '_wbi_invoice_number', true );
        $inv_type      = $order->get_meta( '_wbi_invoice_type', true );
        $inv_date      = $order->get_meta( '_wbi_invoice_date', true );
        $cuit          = $order->get_meta( '_wbi_customer_cuit', true );
        $tax_condition = $order->get_meta( '_wbi_customer_tax_condition', true );

        echo '<div style="font-size:12px;">';
        if ( $inv_number ) {
            echo '<p><strong>Nº Factura:</strong> ' . esc_html( $inv_number ) . '<br>';
            echo '<strong>Tipo:</strong> ' . esc_html( $inv_type ) . '<br>';
            echo '<strong>Fecha:</strong> ' . esc_html( $inv_date ) . '</p>';
            $view_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=wbi_view_invoice&order_id=' . $order_id ),
                'wbi_view_invoice_' . $order_id
            );
            echo '<a href="' . esc_url( $view_url ) . '" target="_blank" class="button button-small">📄 Ver / Imprimir PDF</a>';
        } else {
            echo '<p style="color:#777;">Sin factura generada.</p>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'wbi_generate_invoice', '_wbi_inv_nonce' );
            echo '<input type="hidden" name="action" value="wbi_generate_invoice">';
            echo '<input type="hidden" name="order_id" value="' . intval( $order_id ) . '">';
            echo '<p>Tipo: <select name="invoice_type"><option>A</option><option selected>B</option><option>C</option></select></p>';

            // Try to auto-fill CUIT from custom fields if empty
            $razon_social_prefill = '';
            $cf_config            = get_option( 'wbi_custom_fields_config', array() );
            $customer_id_cf       = $order->get_customer_id();
            foreach ( $cf_config as $cf ) {
                $cf_key = sanitize_key( $cf['key'] );
                if ( empty( $cuit ) && preg_match( '/(cuit|dni|doc|nro_doc|documento)/', $cf_key ) ) {
                    $val = $order->get_meta( $cf_key, true );
                    if ( empty( $val ) && $customer_id_cf ) {
                        $val = get_user_meta( $customer_id_cf, $cf_key, true );
                    }
                    if ( ! empty( $val ) ) {
                        $cuit = $val;
                    }
                }
                if ( empty( $razon_social_prefill ) && preg_match( '/(razon|social|empresa|company)/', $cf_key ) ) {
                    $val = $order->get_meta( $cf_key, true );
                    if ( empty( $val ) && $customer_id_cf ) {
                        $val = get_user_meta( $customer_id_cf, $cf_key, true );
                    }
                    if ( ! empty( $val ) ) {
                        $razon_social_prefill = $val;
                    }
                }
            }

            echo '<p><label>CUIT/DNI:<br><input type="text" name="customer_cuit" value="' . esc_attr( $cuit ) . '" style="width:100%;"></label></p>';
            echo '<p><label>Razón Social:<br><input type="text" name="customer_razon_social" value="' . esc_attr( $razon_social_prefill ) . '" style="width:100%;"></label></p>';
            echo '<p><label>Condición IVA:<br><select name="customer_tax_condition" style="width:100%;">';
            foreach ( array( 'Consumidor Final', 'Responsable Inscripto', 'Monotributista', 'Exento' ) as $opt ) {
                $sel = selected( $tax_condition, $opt, false );
                echo '<option value="' . esc_attr( $opt ) . '" ' . $sel . '>' . esc_html( $opt ) . '</option>';
            }
            echo '</select></label></p>';
            echo '<button type="submit" class="button button-primary button-small">📑 Generar Factura</button>';
            echo '</form>';
        }
        echo '</div>';
    }

    public function save_order_fiscal_meta( $order_id ) {
        if ( ! isset( $_POST['_wbi_inv_nonce'] ) ) return;
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wbi_inv_nonce'] ) ), 'wbi_generate_invoice' ) ) return;
        // Saving is handled in handle_generate_invoice via admin-post
    }

    // -------------------------------------------------------------------------
    // Admin page: invoice list
    // -------------------------------------------------------------------------

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Export CSV
        if ( isset( $_GET['export'] ) && $_GET['export'] === 'csv' ) {
            check_admin_referer( 'wbi_invoice_export' );
            $this->export_csv();
            return;
        }

        $date_from  = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : date( 'Y-m-d', strtotime( '-30 days' ) );
        $date_to    = isset( $_GET['date_to'] )   ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )   : date( 'Y-m-d' );
        $type_filter= isset( $_GET['inv_type'] )  ? sanitize_text_field( wp_unslash( $_GET['inv_type'] ) )  : '';
        $paged      = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $per_page   = 20;
        $offset     = ( $paged - 1 ) * $per_page;

        // Build wc_get_orders() args for HPOS-compatible queries
        $query_args = array(
            'meta_key'     => '_wbi_invoice_number',
            'meta_compare' => 'EXISTS',
            // WooCommerce date_created range format: 'YYYY-MM-DD...YYYY-MM-DD'
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

        echo '<div class="wrap">';
        echo '<h1>📑 Facturación AFIP</h1>';

        // Filter form
        echo '<form method="get" style="margin-bottom:15px;">';
        echo '<input type="hidden" name="page" value="wbi-invoices">';
        echo '<label>Desde: <input type="date" name="date_from" value="' . esc_attr( $date_from ) . '"></label> ';
        echo '<label>Hasta: <input type="date" name="date_to" value="' . esc_attr( $date_to ) . '"></label> ';
        echo '<label>Tipo: <select name="inv_type"><option value="">Todos</option>';
        foreach ( array( 'A', 'B', 'C' ) as $t ) {
            $sel = selected( $type_filter, $t, false );
            echo '<option value="' . esc_attr( $t ) . '" ' . $sel . '>' . esc_html( $t ) . '</option>';
        }
        echo '</select></label> ';
        echo '<button type="submit" class="button">Filtrar</button> ';
        $export_url = wp_nonce_url( admin_url( 'admin.php?page=wbi-invoices&export=csv&date_from=' . urlencode( $date_from ) . '&date_to=' . urlencode( $date_to ) ), 'wbi_invoice_export' );
        echo '<a href="' . esc_url( $export_url ) . '" class="button">⬇️ Exportar CSV</a>';
        echo '</form>';

        echo '<table class="wp-list-table widefat fixed striped wbi-sortable">';
        echo '<thead><tr><th>#Factura</th><th>Tipo</th><th>Fecha</th><th>#Pedido</th><th>Cliente</th><th>CUIT</th><th>Total</th><th>Acciones</th></tr></thead><tbody>';

        if ( empty( $page_ids ) ) {
            echo '<tr><td colspan="8">Sin facturas en el período seleccionado.</td></tr>';
        } else {
            foreach ( $page_ids as $oid ) {
                $oid    = intval( $oid );
                $order  = wc_get_order( $oid );
                $cuit   = $order ? $order->get_meta( '_wbi_customer_cuit', true ) : '';
                $total  = $order ? wc_price( $order->get_total() ) : '—';
                $name   = $order ? esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) : '—';
                $inv_number = $order ? esc_html( $order->get_meta( '_wbi_invoice_number', true ) ) : '';
                $inv_type   = $order ? esc_html( $order->get_meta( '_wbi_invoice_type', true ) ) : '';
                $inv_date_raw = $order ? $order->get_date_created() : null;
                $inv_date   = $inv_date_raw ? $inv_date_raw->date( 'd/m/Y' ) : '—';
                $view_url = wp_nonce_url( admin_url( 'admin-post.php?action=wbi_view_invoice&order_id=' . $oid ), 'wbi_view_invoice_' . $oid );
                $edit_url = $order ? $order->get_edit_order_url() : '#';
                echo '<tr>';
                echo '<td>' . esc_html( $inv_number ) . '</td>';
                echo '<td>' . esc_html( $inv_type ) . '</td>';
                echo '<td>' . esc_html( $inv_date ) . '</td>';
                echo '<td><a href="' . esc_url( $edit_url ) . '">#' . $oid . '</a></td>';
                echo '<td>' . $name . '</td>';
                echo '<td>' . esc_html( $cuit ) . '</td>';
                echo '<td>' . $total . '</td>';
                echo '<td><a href="' . esc_url( $view_url ) . '" target="_blank" class="button button-small">📄 Ver PDF</a></td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        // Pagination
        echo paginate_links( array(
            'base'    => add_query_arg( 'paged', '%#%' ),
            'format'  => '',
            'current' => $paged,
            'total'   => ceil( $total_rows / $per_page ),
        ) );

        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // CSV export
    // -------------------------------------------------------------------------

    public function handle_export_csv() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permisos.' );
        check_admin_referer( 'wbi_invoice_export' );
        $this->export_csv();
    }

    private function export_csv() {
        $order_ids = wc_get_orders( array(
            'meta_key'     => '_wbi_invoice_number',
            'meta_compare' => 'EXISTS',
            'limit'        => 1000,
            'orderby'      => 'ID',
            'order'        => 'DESC',
            'return'       => 'ids',
        ) );

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="facturas-wbi-' . date( 'Y-m-d' ) . '.csv"' );
        echo "\xEF\xBB\xBF"; // UTF-8 BOM
        echo "Nro Factura,Tipo,Fecha,Nro Pedido,Cliente,CUIT,Total\n";
        foreach ( $order_ids as $oid ) {
            $oid   = intval( $oid );
            $order = wc_get_order( $oid );
            if ( ! $order ) continue;
            $name       = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
            $total      = $order->get_total();
            $cuit       = $order->get_meta( '_wbi_customer_cuit', true );
            $inv_number = $order->get_meta( '_wbi_invoice_number', true );
            $inv_type   = $order->get_meta( '_wbi_invoice_type', true );
            $inv_date_raw = $order->get_date_created();
            $inv_date   = $inv_date_raw ? $inv_date_raw->date( 'd/m/Y' ) : '';
            echo implode( ',', array_map( 'wbi_csv_escape', array(
                $inv_number,
                $inv_type,
                $inv_date,
                $oid,
                $name,
                $cuit,
                $total,
            ) ) ) . "\n";
        }
        exit;
    }
}

// Helper: escape CSV cell
function wbi_csv_escape( $val ) {
    $val = str_replace( '"', '""', $val );
    return '"' . $val . '"';
}
