<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI POS Module — Tomador de Pedidos en Mostrador
 *
 * Provides a full-screen POS interface inside wp-admin for creating orders,
 * managing mixed payments and optionally generating AFIP invoices.
 *
 * Order status mapping (uses standard WooCommerce statuses + metas):
 *   paid_total == 0               => pending
 *   paid_total > 0 && balance > 0 => on-hold  (cuenta corriente)
 *   balance == 0                  => processing
 */
class WBI_POS_Module {

    public function __construct() {
        add_action( 'admin_menu',            array( $this, 'add_submenu' ), 100 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // AJAX endpoints
        add_action( 'wp_ajax_wbi_pos_search_products',  array( $this, 'ajax_search_products' ) );
        add_action( 'wp_ajax_wbi_pos_search_customers', array( $this, 'ajax_search_customers' ) );
        add_action( 'wp_ajax_wbi_pos_create_order',     array( $this, 'ajax_create_order' ) );
        add_action( 'wp_ajax_wbi_pos_try_invoice',      array( $this, 'ajax_try_invoice' ) );
    }

    // =========================================================================
    // ADMIN MENU
    // =========================================================================

    public function add_submenu() {
        add_submenu_page(
            'wbi-dashboard-view',
            '🛒 POS / Mostrador',
            '<span class="dashicons dashicons-store" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> POS / Mostrador',
            'manage_woocommerce',
            'wbi-pos',
            array( $this, 'render_page' )
        );
    }

    // =========================================================================
    // ASSETS
    // =========================================================================

    public function enqueue_assets( $hook ) {
        if ( false === strpos( $hook, 'wbi-pos' ) ) {
            return;
        }

        $base = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/';

        wp_enqueue_style(
            'wbi-pos-css',
            $base . 'pos.css',
            array(),
            '1.0.0'
        );

        wp_enqueue_script(
            'wbi-pos-js',
            $base . 'pos.js',
            array( 'jquery' ),
            '1.0.0',
            true
        );

        wp_localize_script( 'wbi-pos-js', 'wbiPos', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wbi_pos_nonce' ),
            'currency'=> get_woocommerce_currency_symbol(),
            'i18n'    => array(
                'searchPlaceholder'  => 'Buscar por nombre, SKU o código de barras…',
                'addProduct'         => 'Agregar',
                'removeItem'         => 'Quitar',
                'qty'                => 'Cant.',
                'price'              => 'Precio',
                'subtotal'           => 'Subtotal',
                'total'              => 'Total',
                'paid'               => 'Pagado',
                'balance'            => 'Saldo',
                'addPayment'         => '+ Agregar pago',
                'confirmOrder'       => 'Confirmar Pedido',
                'newOrder'           => 'Nuevo Pedido',
                'invoiceNow'         => 'Facturar ahora',
                'viewOrder'          => 'Ver pedido',
                'orderCreated'       => 'Pedido creado correctamente',
                'orderError'         => 'Error al crear el pedido',
                'invoiceSuccess'     => 'Factura generada correctamente',
                'invoiceError'       => 'Error al facturar. El pedido ya fue guardado.',
                'noProducts'         => 'No se encontraron productos.',
                'noCustomers'        => 'No se encontraron clientes.',
                'finalConsumer'      => 'Consumidor Final',
                'recoverDraft'       => 'Hay un borrador guardado. ¿Deseás recuperarlo?',
                'scannerMode'        => 'Modo escáner',
                'scannerHint'        => 'Input activo — usá el lector de barras',
                'confirmNewOrder'    => '¿Descartás el pedido actual y comenzás uno nuevo?',
                'paymentMethod'      => 'Medio de pago',
                'amount'             => 'Monto',
                'reference'          => 'Ref. (opcional)',
                'methods'            => array(
                    'cash'           => 'Efectivo',
                    'transfer'       => 'Transferencia',
                    'debit'          => 'Tarjeta Débito',
                    'credit'         => 'Tarjeta Crédito',
                    'qr'             => 'QR / MercadoPago',
                    'other'          => 'Otro',
                ),
            ),
        ) );
    }

    // =========================================================================
    // PAGE RENDER
    // =========================================================================

    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Sin permisos.', 'wbi-suite' ) );
        }
        ?>
        <div id="wbi-pos-app">

            <!-- ── TOP BAR ──────────────────────────────────────────── -->
            <div class="pos-topbar">
                <span class="pos-logo">🛒 POS / Mostrador</span>
                <div class="pos-topbar-actions">
                    <label class="pos-scanner-toggle">
                        <input type="checkbox" id="pos-scanner-mode">
                        <span><?php esc_html_e( 'Modo escáner', 'wbi-suite' ); ?></span>
                    </label>
                    <button id="pos-btn-new" class="pos-btn pos-btn-secondary">
                        🔄 <?php esc_html_e( 'Nuevo Pedido', 'wbi-suite' ); ?>
                    </button>
                </div>
            </div>

            <!-- ── MAIN LAYOUT ──────────────────────────────────────── -->
            <div class="pos-main">

                <!-- LEFT COLUMN: search + cart -->
                <div class="pos-left">

                    <!-- Product search -->
                    <div class="pos-search-bar">
                        <input type="text" id="pos-product-search"
                               placeholder="<?php esc_attr_e( 'Buscar por nombre, SKU o código de barras…', 'wbi-suite' ); ?>"
                               autocomplete="off">
                        <div id="pos-product-results" class="pos-dropdown"></div>
                    </div>

                    <!-- Cart -->
                    <div class="pos-cart-wrap">
                        <table class="pos-cart-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Producto', 'wbi-suite' ); ?></th>
                                    <th><?php esc_html_e( 'Cant.', 'wbi-suite' ); ?></th>
                                    <th><?php esc_html_e( 'Precio', 'wbi-suite' ); ?></th>
                                    <th><?php esc_html_e( 'Subtotal', 'wbi-suite' ); ?></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="pos-cart-body">
                                <tr id="pos-cart-empty">
                                    <td colspan="5" class="pos-cart-empty-msg">
                                        <?php esc_html_e( 'El carrito está vacío. Buscá productos arriba.', 'wbi-suite' ); ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Customer search -->
                    <div class="pos-customer-wrap">
                        <label class="pos-label"><?php esc_html_e( 'Cliente', 'wbi-suite' ); ?></label>
                        <div class="pos-customer-search-row">
                            <input type="text" id="pos-customer-search"
                                   placeholder="<?php esc_attr_e( 'Buscar por email o nombre…', 'wbi-suite' ); ?>"
                                   autocomplete="off">
                            <button id="pos-btn-consumer" class="pos-btn pos-btn-outline pos-btn-sm">
                                <?php esc_html_e( 'Consumidor Final', 'wbi-suite' ); ?>
                            </button>
                        </div>
                        <div id="pos-customer-results" class="pos-dropdown"></div>
                        <div id="pos-customer-selected" class="pos-customer-selected" style="display:none;"></div>
                    </div>

                </div><!-- /.pos-left -->

                <!-- RIGHT COLUMN: totals + payments + confirm -->
                <div class="pos-right">

                    <!-- Totals -->
                    <div class="pos-totals-box">
                        <div class="pos-total-row">
                            <span><?php esc_html_e( 'Total', 'wbi-suite' ); ?></span>
                            <strong id="pos-total">$0.00</strong>
                        </div>
                        <div class="pos-total-row pos-paid-row">
                            <span><?php esc_html_e( 'Pagado', 'wbi-suite' ); ?></span>
                            <strong id="pos-paid">$0.00</strong>
                        </div>
                        <div class="pos-total-row pos-balance-row">
                            <span><?php esc_html_e( 'Saldo', 'wbi-suite' ); ?></span>
                            <strong id="pos-balance">$0.00</strong>
                        </div>
                    </div>

                    <!-- Payments -->
                    <div class="pos-payments-wrap">
                        <div class="pos-payments-header">
                            <span class="pos-label"><?php esc_html_e( 'Pagos', 'wbi-suite' ); ?></span>
                            <button id="pos-btn-add-payment" class="pos-btn pos-btn-outline pos-btn-sm">
                                + <?php esc_html_e( 'Agregar pago', 'wbi-suite' ); ?>
                            </button>
                        </div>
                        <div id="pos-payments-list"></div>
                    </div>

                    <!-- Order note -->
                    <div class="pos-note-wrap">
                        <label class="pos-label"><?php esc_html_e( 'Nota del pedido', 'wbi-suite' ); ?></label>
                        <textarea id="pos-order-note" rows="2" placeholder="<?php esc_attr_e( 'Opcional…', 'wbi-suite' ); ?>"></textarea>
                    </div>

                    <!-- Actions -->
                    <div class="pos-actions">
                        <button id="pos-btn-confirm" class="pos-btn pos-btn-primary pos-btn-full" disabled>
                            ✅ <?php esc_html_e( 'Confirmar Pedido', 'wbi-suite' ); ?>
                        </button>
                    </div>

                    <!-- Result panel (shown after order created) -->
                    <div id="pos-result-panel" class="pos-result-panel" style="display:none;"></div>

                </div><!-- /.pos-right -->

            </div><!-- /.pos-main -->

        </div><!-- /#wbi-pos-app -->
        <?php
    }

    // =========================================================================
    // AJAX: SEARCH PRODUCTS
    // =========================================================================

    public function ajax_search_products() {
        check_ajax_referer( 'wbi_pos_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
        }

        $query = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
        if ( strlen( $query ) < 1 ) {
            wp_send_json_success( array() );
        }

        $products = array();

        // Search by title
        $args = array(
            'post_type'      => array( 'product', 'product_variation' ),
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'orderby'        => 'relevance',
        );

        // Try SKU / barcode first (exact meta matches)
        $sku_args = $args;
        $sku_args['meta_query'] = array(
            'relation' => 'OR',
            array( 'key' => '_sku',      'value' => $query, 'compare' => 'LIKE' ),
            array( 'key' => '_ean',      'value' => $query, 'compare' => 'LIKE' ),
            array( 'key' => '_barcode',  'value' => $query, 'compare' => 'LIKE' ),
            array( 'key' => 'ean',       'value' => $query, 'compare' => 'LIKE' ),
            array( 'key' => 'barcode',   'value' => $query, 'compare' => 'LIKE' ),
        );

        $sku_query = new WP_Query( $sku_args );

        // Search by name
        $name_args = $args;
        $name_args['s'] = $query;
        $name_query = new WP_Query( $name_args );

        $all_posts = array_merge(
            $sku_query->posts ?? array(),
            $name_query->posts ?? array()
        );

        $seen = array();
        foreach ( $all_posts as $post ) {
            if ( isset( $seen[ $post->ID ] ) ) continue;
            $seen[ $post->ID ] = true;

            $product = wc_get_product( $post->ID );
            if ( ! $product || ! $product->is_purchasable() ) continue;

            $price = (float) $product->get_price();
            $sku   = $product->get_sku();

            $products[] = array(
                'id'    => $product->get_id(),
                'name'  => $product->get_name(),
                'sku'   => $sku,
                'price' => $price,
                'stock' => $product->get_stock_quantity(),
                'image' => wp_get_attachment_url( $product->get_image_id() ) ?: '',
            );

            if ( count( $products ) >= 20 ) break;
        }

        wp_send_json_success( $products );
    }

    // =========================================================================
    // AJAX: SEARCH CUSTOMERS
    // =========================================================================

    public function ajax_search_customers() {
        check_ajax_referer( 'wbi_pos_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
        }

        $query = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
        if ( strlen( $query ) < 2 ) {
            wp_send_json_success( array() );
        }

        $users = get_users( array(
            'search'         => '*' . $query . '*',
            'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ),
            'number'         => 15,
        ) );

        $results = array();
        foreach ( $users as $user ) {
            $results[] = array(
                'id'    => $user->ID,
                'name'  => $user->display_name,
                'email' => $user->user_email,
            );
        }

        wp_send_json_success( $results );
    }

    // =========================================================================
    // AJAX: CREATE ORDER
    // =========================================================================

    public function ajax_create_order() {
        check_ajax_referer( 'wbi_pos_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
        }

        // Parse items from form-encoded POST: items[0][id], items[0][qty], etc.
        $raw_items = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? $_POST['items'] : array();
        if ( empty( $raw_items ) ) {
            wp_send_json_error( array( 'message' => 'El carrito está vacío.' ) );
        }
        $items = array();
        foreach ( $raw_items as $ri ) {
            $items[] = array(
                'id'    => absint( $ri['id'] ?? 0 ),
                'name'  => sanitize_text_field( wp_unslash( $ri['name'] ?? '' ) ),
                'qty'   => max( 1, absint( $ri['qty'] ?? 1 ) ),
                'price' => (float) ( $ri['price'] ?? 0 ),
            );
        }

        // --- Validate payments ---
        $raw_payments = isset( $_POST['payments'] ) && is_array( $_POST['payments'] ) ? $_POST['payments'] : array();
        $paid_total = 0.0;
        $clean_payments = array();
        foreach ( $raw_payments as $p ) {
            $method    = sanitize_text_field( wp_unslash( $p['method'] ?? 'cash' ) );
            $amount    = (float) ( $p['amount'] ?? 0 );
            $reference = sanitize_text_field( wp_unslash( $p['reference'] ?? '' ) );
            if ( $amount > 0 ) {
                $clean_payments[] = array(
                    'method'    => $method,
                    'amount'    => $amount,
                    'reference' => $reference,
                );
                $paid_total += $amount;
            }
        }

        $customer_id = absint( $_POST['customer_id'] ?? 0 );
        $note        = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );

        // --- Create WC_Order ---
        $order = wc_create_order( array(
            'customer_id' => $customer_id,
            'status'      => 'pending',
        ) );

        if ( is_wp_error( $order ) ) {
            wp_send_json_error( array( 'message' => $order->get_error_message() ) );
        }

        // Add items
        $order_total = 0.0;
        foreach ( $items as $item ) {
            $product_id = absint( $item['id'] ?? 0 );
            $qty        = max( 1, absint( $item['qty'] ?? 1 ) );
            $price      = (float) ( $item['price'] ?? 0 );

            $product = wc_get_product( $product_id );
            if ( ! $product ) continue;

            $line = new WC_Order_Item_Product();
            $line->set_product( $product );
            $line->set_quantity( $qty );
            $line->set_subtotal( $price * $qty );
            $line->set_total( $price * $qty );
            $order->add_item( $line );

            $order_total += $price * $qty;
        }

        $order->set_total( $order_total );

        // Set billing from customer if available
        if ( $customer_id ) {
            $customer = new WC_Customer( $customer_id );
            $order->set_billing_first_name( $customer->get_billing_first_name() );
            $order->set_billing_last_name( $customer->get_billing_last_name() );
            $order->set_billing_email( $customer->get_billing_email() );
            $order->set_billing_phone( $customer->get_billing_phone() );
            $order->set_billing_address_1( $customer->get_billing_address_1() );
            $order->set_billing_city( $customer->get_billing_city() );
            $order->set_billing_state( $customer->get_billing_state() );
            $order->set_billing_postcode( $customer->get_billing_postcode() );
            $order->set_billing_country( $customer->get_billing_country() );
        }

        // Note
        if ( $note ) {
            $order->add_order_note( $note, 0, false );
        }

        // Set payment method label
        $order->set_payment_method( 'pos' );
        $order->set_payment_method_title( 'POS / Mostrador' );

        // Compute balance
        $balance_due = max( 0.0, $order_total - $paid_total );

        // Determine status
        if ( $paid_total <= 0 ) {
            $order->set_status( 'pending' );
        } elseif ( $balance_due > 0 ) {
            $order->set_status( 'on-hold' );
        } else {
            $order->set_status( 'processing' );
        }

        // Save POS metas
        $order->update_meta_data( '_wbi_origin',             'pos' );
        $order->update_meta_data( '_wbi_pos_created_by',     get_current_user_id() );
        $order->update_meta_data( '_wbi_pos_payments',       wp_json_encode( $clean_payments ) );
        $order->update_meta_data( '_wbi_pos_paid_total',     $paid_total );
        $order->update_meta_data( '_wbi_pos_balance_due',    $balance_due );
        $order->update_meta_data( '_wbi_pos_invoice_status', 'pending' );

        $order->save();

        $order_id  = $order->get_id();
        $order_url = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
        if ( function_exists( 'wc_get_order_edit_link' ) ) {
            $order_url = wc_get_order_edit_link( $order_id );
        }

        wp_send_json_success( array(
            'order_id'    => $order_id,
            'order_url'   => $order_url,
            'total'       => $order_total,
            'paid_total'  => $paid_total,
            'balance_due' => $balance_due,
            'status'      => $order->get_status(),
        ) );
    }

    // =========================================================================
    // AJAX: TRY INVOICE (AFIP)
    // =========================================================================

    public function ajax_try_invoice() {
        check_ajax_referer( 'wbi_pos_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error( array( 'message' => 'ID de pedido inválido.' ) );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => 'Pedido no encontrado.' ) );
        }

        // Check if WBI_Documents_Module is available for invoicing
        if ( class_exists( 'WBI_Documents_Module' ) ) {
            $docs_url = admin_url( 'admin.php?page=wbi-documents&highlight=' . $order_id );
            $order->update_meta_data( '_wbi_pos_invoice_status', 'pending' );
            $order->save();

            wp_send_json_success( array(
                'status'   => 'redirect',
                'docs_url' => $docs_url,
                'message'  => 'Redirigiendo al módulo de Documentos para facturar.',
            ) );
        }

        // Module not available
        $order->update_meta_data( '_wbi_pos_invoice_status', 'failed' );
        $order->update_meta_data( '_wbi_pos_invoice_error',  'Módulo de Facturación no disponible.' );
        $order->save();

        wp_send_json_error( array(
            'message'  => 'El módulo de Facturación no está activo. Podés facturar manualmente desde el pedido.',
            'order_url'=> admin_url( 'post.php?post=' . $order_id . '&action=edit' ),
        ) );
    }
}
