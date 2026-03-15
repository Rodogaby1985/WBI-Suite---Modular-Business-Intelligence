<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Remitos_Module {

    public function __construct() {
        // Order actions button
        add_action( 'woocommerce_order_actions', array( $this, 'add_order_action' ) );
        add_action( 'woocommerce_order_action_wbi_generate_remito', array( $this, 'process_order_action' ) );

        // AJAX endpoint for remito generation (also used directly via admin-post)
        add_action( 'wp_ajax_wbi_generate_remito', array( $this, 'generate_remito' ) );
        add_action( 'admin_post_wbi_generate_remito', array( $this, 'generate_remito' ) );

        // Admin submenu
        add_action( 'admin_menu', array( $this, 'add_submenu' ) );
    }

    // -------------------------------------------------------------------------
    // Order actions hook (adds button in WC order edit screen)
    // -------------------------------------------------------------------------

    public function add_order_action( $actions ) {
        $actions['wbi_generate_remito'] = '📄 Generar Remito';
        return $actions;
    }

    /**
     * Called when the WooCommerce order action dropdown fires the remito action.
     * Stores the remito number and redirects to printable view.
     */
    public function process_order_action( $order ) {
        $order_id = $order->get_id();
        $remito_number = $this->ensure_remito_number( $order_id );
        $url = wp_nonce_url(
            admin_url( 'admin-post.php?action=wbi_generate_remito&order_id=' . $order_id ),
            'wbi_remito_' . $order_id
        );
        // Redirect to printable remito in new window via JavaScript (inline)
        echo '<script>window.open(' . wp_json_encode( $url ) . ', "_blank");</script>';
    }

    // -------------------------------------------------------------------------
    // Remito number management
    // -------------------------------------------------------------------------

    private function ensure_remito_number( $order_id ) {
        $existing = get_post_meta( $order_id, '_wbi_remito_number', true );
        if ( $existing ) return $existing;

        $counter = (int) get_option( 'wbi_remito_counter', 0 ) + 1;
        update_option( 'wbi_remito_counter', $counter, false ); // autoload = no (counter not needed on every page load)
        update_post_meta( $order_id, '_wbi_remito_number', $counter );
        update_post_meta( $order_id, '_wbi_remito_date', current_time( 'mysql' ) );
        return $counter;
    }

    // -------------------------------------------------------------------------
    // Generate printable remito (admin-post / ajax)
    // -------------------------------------------------------------------------

    public function generate_remito() {
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        if ( ! $order_id ) wp_die( 'Pedido no válido.' );

        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'wbi_remito_' . $order_id ) ) {
            wp_die( 'Nonce inválido.' );
        }
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_die( 'Sin permisos.' );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_die( 'Pedido no encontrado.' );

        $remito_number = $this->ensure_remito_number( $order_id );
        $remito_date   = get_post_meta( $order_id, '_wbi_remito_date', true );

        // Company info from WBI Config options
        $company_name = esc_html( get_option( 'wbi_company_name', get_bloginfo( 'name' ) ) );
        $company_cuit = esc_html( get_option( 'wbi_company_cuit', '' ) );
        $company_addr = esc_html( get_option( 'wbi_company_address', '' ) );

        // Client info
        $client_name  = esc_html( $order->get_formatted_billing_full_name() );
        $client_addr  = esc_html( implode( ', ', array_filter( array(
            $order->get_billing_address_1(),
            $order->get_billing_address_2(),
            $order->get_billing_city(),
            $order->get_billing_state(),
        ) ) ) );
        $client_phone = esc_html( $order->get_billing_phone() );

        $items = $order->get_items();
        $notes = esc_html( $order->get_customer_note() );

        $date_formatted = $remito_date ? date_i18n( 'd/m/Y', strtotime( $remito_date ) ) : date_i18n( 'd/m/Y' );

        // Clean any existing output before printing the standalone HTML page
        if ( ob_get_length() ) ob_end_clean();

        header( 'Content-Type: text/html; charset=utf-8' );
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
</head>
<body>
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
<script>window.print();</script>
</body>
</html>
        <?php
        exit;
    }

    // -------------------------------------------------------------------------
    // Admin submenu
    // -------------------------------------------------------------------------

    public function add_submenu() {
        add_submenu_page(
            'wbi-dashboard-view',
            'Remitos',
            '📄 Remitos',
            'manage_woocommerce',
            'wbi-remitos',
            array( $this, 'render_log_page' )
        );
    }

    // -------------------------------------------------------------------------
    // Remito log page
    // -------------------------------------------------------------------------

    public function render_log_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Sin permisos' );

        global $wpdb;

        $per_page    = 20;
        $paged       = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $offset      = ( $paged - 1 ) * $per_page;

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wbi_remito_number'
             WHERE p.post_type = 'shop_order'"
        );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID AS order_id,
                    p.post_date,
                    pm_num.meta_value  AS remito_number,
                    pm_date.meta_value AS remito_date,
                    pm_name.meta_value AS billing_first,
                    pm_lname.meta_value AS billing_last
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm_num   ON p.ID = pm_num.post_id   AND pm_num.meta_key   = '_wbi_remito_number'
             LEFT JOIN {$wpdb->postmeta} pm_date  ON p.ID = pm_date.post_id  AND pm_date.meta_key  = '_wbi_remito_date'
             LEFT JOIN {$wpdb->postmeta} pm_name  ON p.ID = pm_name.post_id  AND pm_name.meta_key  = '_billing_first_name'
             LEFT JOIN {$wpdb->postmeta} pm_lname ON p.ID = pm_lname.post_id AND pm_lname.meta_key = '_billing_last_name'
             WHERE p.post_type = 'shop_order'
             ORDER BY CAST(pm_num.meta_value AS SIGNED) DESC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ) );

        $total_pages = ceil( $total / $per_page );
        ?>
        <div class="wrap">
            <h1>📄 Remitos Generados</h1>
            <p style="color:#555;">Total: <strong><?php echo intval( $total ); ?></strong> remitos</p>

            <table class="widefat striped wbi-sortable">
                <thead>
                    <tr>
                        <th>#Remito</th>
                        <th>#Pedido</th>
                        <th>Fecha Remito</th>
                        <th>Cliente</th>
                        <th>Reimprimir</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="5" style="text-align:center;color:#888;">Aún no se generaron remitos.</td></tr>
                <?php else : ?>
                    <?php foreach ( $rows as $row ) :
                        $remito_date_fmt = $row->remito_date ? date_i18n( 'd/m/Y', strtotime( $row->remito_date ) ) : '—';
                        $client_name     = trim( $row->billing_first . ' ' . $row->billing_last ) ?: '—';
                        $order_url       = get_edit_post_link( $row->order_id );
                        $print_url       = wp_nonce_url(
                            admin_url( 'admin-post.php?action=wbi_generate_remito&order_id=' . $row->order_id ),
                            'wbi_remito_' . $row->order_id
                        );
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( str_pad( intval( $row->remito_number ), 6, '0', STR_PAD_LEFT ) ); ?></strong></td>
                        <td><a href="<?php echo esc_url( $order_url ); ?>">#<?php echo intval( $row->order_id ); ?></a></td>
                        <td><?php echo esc_html( $remito_date_fmt ); ?></td>
                        <td><?php echo esc_html( $client_name ); ?></td>
                        <td><a href="<?php echo esc_url( $print_url ); ?>" target="_blank" class="button button-small">🖨 Reimprimir</a></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) :
                $pagination = paginate_links( array(
                    'base'      => add_query_arg( 'paged', '%#%' ),
                    'format'    => '',
                    'current'   => $paged,
                    'total'     => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ) );
                echo '<div class="tablenav"><div class="tablenav-pages">' . $pagination . '</div></div>';
            endif; ?>
        </div>
        <?php
    }
}
