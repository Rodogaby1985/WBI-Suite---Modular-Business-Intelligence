<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Remitos_Module {

    public function __construct() {
        // Order actions dropdown (secondary method — still works for non-HPOS)
        add_action( 'woocommerce_order_actions', array( $this, 'add_order_action' ) );
        add_action( 'woocommerce_order_action_wbi_generate_remito', array( $this, 'process_order_action' ) );

        // Meta box on the order edit screen (primary method — compatible with legacy + HPOS)
        add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );

        // AJAX endpoint for remito generation (also used directly via admin-post)
        add_action( 'wp_ajax_wbi_generate_remito', array( $this, 'generate_remito' ) );
        add_action( 'admin_post_wbi_generate_remito', array( $this, 'generate_remito' ) );

        // CSV export handler
        add_action( 'admin_post_wbi_remito_export', array( $this, 'handle_remito_export' ) );

        // Admin submenu
        add_action( 'admin_menu', array( $this, 'add_submenu' ), 100 );
    }

    // -------------------------------------------------------------------------
    // Meta box on the order edit screen (legacy + HPOS compatible)
    // -------------------------------------------------------------------------

    public function register_meta_box() {
        // Legacy orders screen (WooCommerce < 8 or HPOS disabled)
        add_meta_box(
            'wbi-remito-meta-box',
            '<span class="dashicons dashicons-media-text" style="vertical-align:middle;margin-right:4px;"></span> Remito',
            array( $this, 'render_remito_meta_box' ),
            'shop_order',
            'side',
            'default'
        );
        // HPOS orders screen (WooCommerce 8+ with HPOS enabled)
        add_meta_box(
            'wbi-remito-meta-box',
            '<span class="dashicons dashicons-media-text" style="vertical-align:middle;margin-right:4px;"></span> Remito',
            array( $this, 'render_remito_meta_box' ),
            'woocommerce_page_wc-orders',
            'side',
            'default'
        );
    }

    public function render_remito_meta_box( $post_or_order ) {
        // Accept both a WP_Post (legacy) and a WC_Order (HPOS)
        if ( $post_or_order instanceof WP_Post ) {
            $order = wc_get_order( $post_or_order->ID );
        } else {
            $order = $post_or_order;
        }

        if ( ! $order ) return;

        $order_id      = $order->get_id();
        $remito_number = $order->get_meta( '_wbi_remito_number' );
        $remito_date   = $order->get_meta( '_wbi_remito_date' );

        if ( $remito_number ) {
            $print_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=wbi_generate_remito&order_id=' . $order_id ),
                'wbi_remito_' . $order_id
            );
            $date_fmt = $remito_date ? date_i18n( 'd/m/Y', strtotime( $remito_date ) ) : '—';
            echo '<p><strong>N° Remito:</strong> ' . esc_html( str_pad( intval( $remito_number ), 6, '0', STR_PAD_LEFT ) ) . '</p>';
            echo '<p><strong>Fecha:</strong> ' . esc_html( $date_fmt ) . '</p>';
            echo '<p><a href="' . esc_url( $print_url ) . '" target="_blank" class="button button-primary" style="margin-top:6px;">🖨 Ver / Imprimir Remito</a></p>';
        } else {
            $generate_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=wbi_generate_remito&order_id=' . $order_id ),
                'wbi_remito_' . $order_id
            );
            echo '<p style="color:#555;font-size:12px;">Aún no se generó un remito para este pedido.</p>';
            echo '<p><a href="' . esc_url( $generate_url ) . '" target="_blank" class="button button-primary" style="margin-top:6px;">📄 Generar Remito</a></p>';
        }
    }

    // -------------------------------------------------------------------------
    // Order actions hook (adds item in WC order actions dropdown)
    // -------------------------------------------------------------------------

    public function add_order_action( $actions ) {
        $actions['wbi_generate_remito'] = '📄 Generar Remito';
        return $actions;
    }

    /**
     * Called when the WooCommerce order action dropdown fires the remito action.
     * Ensures the remito number is stored and adds an order note with a link.
     * Does NOT use echo '<script>' (which fails with the HPOS redirect).
     */
    public function process_order_action( $order ) {
        $remito_number = $this->ensure_remito_number( $order );
        $url = wp_nonce_url(
            admin_url( 'admin-post.php?action=wbi_generate_remito&order_id=' . $order->get_id() ),
            'wbi_remito_' . $order->get_id()
        );
        /* translators: %1$s: padded remito number, %2$s: remito URL */
        $order->add_order_note( sprintf(
            __( 'Remito N° %1$s generado. <a href="%2$s" target="_blank">Ver / Imprimir</a>', 'wbi-suite' ),
            str_pad( intval( $remito_number ), 6, '0', STR_PAD_LEFT ),
            esc_url( $url )
        ) );
    }

    // -------------------------------------------------------------------------
    // Remito number management
    // -------------------------------------------------------------------------

    /**
     * Ensures a remito number exists for the given order.
     * Uses the WC_Order meta API so it works with both legacy posts and HPOS.
     *
     * @param WC_Order $order
     * @return int|string Remito number.
     */
    private function ensure_remito_number( $order ) {
        $existing = $order->get_meta( '_wbi_remito_number' );
        if ( $existing ) return $existing;

        $counter = (int) get_option( 'wbi_remito_counter', 0 ) + 1;
        update_option( 'wbi_remito_counter', $counter, false ); // autoload = no (counter not needed on every page load)
        $order->update_meta_data( '_wbi_remito_number', $counter );
        $order->update_meta_data( '_wbi_remito_date', current_time( 'mysql' ) );
        $order->save();
        return $counter;
    }

    // -------------------------------------------------------------------------
    // Generate printable remito (admin-post / ajax)
    // -------------------------------------------------------------------------

    public function generate_remito() {
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        if ( ! $order_id ) wp_die( 'Pedido no válido.' );

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'wbi_remito_' . $order_id ) ) {
            wp_die( 'Nonce inválido.' );
        }
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_die( 'Sin permisos.' );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_die( 'Pedido no encontrado.' );

        // ensure_remito_number now uses the order object API (HPOS-compatible)
        $remito_number = $this->ensure_remito_number( $order );
        $remito_date   = $order->get_meta( '_wbi_remito_date' );

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
            '<span class="dashicons dashicons-media-text" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> Remitos',
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

        $per_page = 20;
        $paged    = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $offset   = ( $paged - 1 ) * $per_page;

        // Use wc_get_orders() so the query works with both legacy posts and HPOS tables.
        $all_ids = wc_get_orders( array(
            'meta_key'     => '_wbi_remito_number',
            'meta_compare' => 'EXISTS',
            'return'       => 'ids',
            'limit'        => -1,
        ) );
        $total       = count( $all_ids );
        $total_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 1;

        // Paginated subset
        $paged_ids = wc_get_orders( array(
            'meta_key'     => '_wbi_remito_number',
            'meta_compare' => 'EXISTS',
            'return'       => 'ids',
            'limit'        => $per_page,
            'offset'       => $offset,
            'orderby'      => 'meta_value_num',
            'order'        => 'DESC',
        ) );

        $export_url = add_query_arg( array(
            'action'   => 'wbi_remito_export',
            '_wpnonce' => wp_create_nonce( 'wbi_remito_export' ),
        ), admin_url( 'admin-post.php' ) );
        ?>
        <div class="wrap">
            <h1>Remitos Generados</h1>

            <div class="notice notice-info"><p><strong>¿Qué son los Remitos?</strong> Un remito es un documento de entrega que acompaña la mercadería. Se genera vinculado a un pedido de WooCommerce y puede imprimirse para el transportista o cliente. Para generar un remito, abrí un pedido y usá el botón <em>Generar Remito</em> del panel lateral.</p></div>

            <p style="color:#555;">Total: <strong><?php echo intval( $total ); ?></strong> remitos &nbsp; <a href="<?php echo esc_url( $export_url ); ?>" class="button">Exportar CSV</a></p>

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
                <?php if ( empty( $paged_ids ) ) : ?>
                    <tr><td colspan="5" style="text-align:center;color:#888;">Aún no se generaron remitos.</td></tr>
                <?php else : ?>
                    <?php foreach ( $paged_ids as $order_id ) :
                        $order = wc_get_order( $order_id );
                        if ( ! $order ) continue;

                        $remito_number   = $order->get_meta( '_wbi_remito_number' );
                        $remito_date     = $order->get_meta( '_wbi_remito_date' );
                        $remito_date_fmt = $remito_date ? date_i18n( 'd/m/Y', strtotime( $remito_date ) ) : '—';
                        $client_name     = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: '—';
                        // Use the order's own edit URL — works for both legacy and HPOS
                        $order_url = $order->get_edit_order_url();
                        $print_url = wp_nonce_url(
                            admin_url( 'admin-post.php?action=wbi_generate_remito&order_id=' . $order_id ),
                            'wbi_remito_' . $order_id
                        );
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( str_pad( intval( $remito_number ), 6, '0', STR_PAD_LEFT ) ); ?></strong></td>
                        <td><a href="<?php echo esc_url( $order_url ); ?>">#<?php echo intval( $order_id ); ?></a></td>
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

    public function handle_remito_export() {
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'wbi_remito_export' ) ) wp_die( 'Nonce inválido' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Sin permisos' );

        // Use wc_get_orders() for HPOS compatibility — no direct SQL against wp_posts/wp_postmeta.
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
            $remito_number = $order->get_meta( '_wbi_remito_number' );
            $remito_date   = $order->get_meta( '_wbi_remito_date' );
            $date          = $remito_date ? date_i18n( 'd/m/Y', strtotime( $remito_date ) ) : '';
            fputcsv( $out, array(
                str_pad( intval( $remito_number ), 6, '0', STR_PAD_LEFT ),
                $order_id,
                $date,
                trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                $order->get_total(),
            ) );
        }
        fclose( $out );
        exit;
    }
}
