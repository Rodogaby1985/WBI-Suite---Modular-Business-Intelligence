<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Load cash sessions helper
require_once dirname( __FILE__ ) . '/class-wbi-pos-cash-sessions.php';

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

        // Ensure cash sessions table exists
        WBI_POS_Cash_Sessions::maybe_create_table();

        // Map manage_woocommerce => wbi_pos_access (so admin/shop_manager can also access POS)
        add_filter( 'user_has_cap', array( $this, 'map_pos_access_cap' ), 10, 3 );

        // AJAX endpoints — products, customers, orders
        add_action( 'wp_ajax_wbi_pos_search_products',  array( $this, 'ajax_search_products' ) );
        add_action( 'wp_ajax_wbi_pos_search_customers', array( $this, 'ajax_search_customers' ) );
        add_action( 'wp_ajax_wbi_pos_create_order',     array( $this, 'ajax_create_order' ) );
        add_action( 'wp_ajax_wbi_pos_try_invoice',      array( $this, 'ajax_try_invoice' ) );

        // AJAX endpoints — sellers & cash sessions
        add_action( 'wp_ajax_wbi_pos_get_sellers',      array( $this, 'ajax_get_sellers' ) );
        add_action( 'wp_ajax_wbi_pos_get_cash_status',  array( $this, 'ajax_get_cash_status' ) );
        add_action( 'wp_ajax_wbi_pos_open_cash',        array( $this, 'ajax_open_cash' ) );
        add_action( 'wp_ajax_wbi_pos_close_cash',       array( $this, 'ajax_close_cash' ) );
    }

    // =========================================================================
    // CAPABILITY HELPERS
    // =========================================================================

    /**
     * Dynamically grant wbi_pos_access to users who have manage_woocommerce.
     * This allows admin and shop_manager to use the POS without explicitly
     * holding the wbi_pos_access capability.
     *
     * @param array $allcaps All capabilities of the user.
     * @param array $caps    Required capabilities being checked.
     * @return array
     */
    public function map_pos_access_cap( $allcaps, $caps ) {
        if ( in_array( 'wbi_pos_access', (array) $caps, true ) ) {
            if ( ! empty( $allcaps['manage_woocommerce'] ) ) {
                $allcaps['wbi_pos_access'] = true;
            }
        }
        return $allcaps;
    }

    /**
     * Returns true if the current user can access the POS.
     *
     * @return bool
     */
    private function current_user_can_pos() {
        return current_user_can( 'wbi_pos_access' ) || current_user_can( 'manage_woocommerce' );
    }

    // =========================================================================
    // ADMIN MENU
    // =========================================================================

    public function add_submenu() {
        $user          = wp_get_current_user();
        $pos_only_roles = array( 'wbi_cashier', 'wbi_vendedor' );
        $is_pos_only   = ! empty( array_intersect( (array) $user->roles, $pos_only_roles ) );

        if ( $is_pos_only ) {
            // POS-only roles: register as a standalone top-level menu item
            add_menu_page(
                '🏪 POS / Mostrador',
                '🏪 POS',
                'wbi_pos_access',
                'wbi-pos',
                array( $this, 'render_page' ),
                'dashicons-store',
                2
            );
        } else {
            // Regular users (admin, shop_manager): POS as submenu under WBI Dashboard
            add_submenu_page(
                'wbi-dashboard-view',
                '🏪 POS / Mostrador',
                '<span class="dashicons dashicons-store" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> POS / Mostrador',
                'manage_woocommerce',
                'wbi-pos',
                array( $this, 'render_page' )
            );
        }
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
                // Cash session strings
                'cashOpen'           => '✅ Caja abierta',
                'cashClosed'         => '🔴 Caja cerrada',
                'cashLoading'        => '⚪ Cargando…',
                'openCash'           => 'Abrir caja',
                'closeCash'          => 'Cerrar caja',
                'openCashTitle'      => 'Abrir caja',
                'closeCashTitle'     => 'Cerrar caja',
                'openingCash'        => 'Efectivo inicial',
                'closingCash'        => 'Efectivo contado al cierre',
                'note'               => 'Nota',
                'openCashBtn'        => 'Abrir caja',
                'closeCashBtn'       => 'Cerrar caja',
                'cancel'             => 'Cancelar',
                'cashOpenedOk'       => 'Caja abierta correctamente.',
                'cashClosedOk'       => 'Caja cerrada correctamente.',
                'cashError'          => 'Error al procesar la caja.',
                'noCashToConfirm'    => 'Debés abrir la caja antes de confirmar una venta.',
                'seller'             => 'Vendedor / Cajero',
                'selectSeller'       => 'Seleccionar vendedor…',
                // Close summary
                'closeSummaryTitle'  => 'Resumen de caja',
                'totalSold'          => 'Total vendido',
                'totalPaid'          => 'Total cobrado',
                'totalBalance'       => 'Saldo cuenta corriente',
                'orderCount'         => 'Cantidad de ventas',
                'cashIn'             => 'Efectivo inicial',
                'cashCollected'      => 'Efectivo cobrado',
                'difference'         => 'Diferencia',
                'openedAt'           => 'Apertura',
            ),
        ) );
    }

    // =========================================================================
    // PAGE RENDER
    // =========================================================================

    public function render_page() {
        if ( ! $this->current_user_can_pos() ) {
            wp_die( esc_html__( 'Sin permisos.', 'wbi-suite' ) );
        }
        ?>
        <div id="wbi-pos-app">

            <!-- ── TOP BAR ──────────────────────────────────────────── -->
            <div class="pos-topbar">
                <span class="pos-logo">🛒 POS / Mostrador</span>

                <!-- Seller selector -->
                <div class="pos-seller-wrap">
                    <label class="pos-seller-label" for="pos-seller-select">
                        <?php esc_html_e( 'Vendedor:', 'wbi-suite' ); ?>
                    </label>
                    <select id="pos-seller-select" class="pos-seller-select">
                        <option value=""><?php esc_html_e( 'Seleccionar vendedor…', 'wbi-suite' ); ?></option>
                    </select>
                </div>

                <!-- Cash status -->
                <div class="pos-cash-status" id="pos-cash-status">
                    <span id="pos-cash-status-badge" class="pos-cash-badge">⚪ <?php esc_html_e( 'Cargando…', 'wbi-suite' ); ?></span>
                    <button id="pos-btn-open-cash" class="pos-btn pos-btn-success pos-btn-sm" style="display:none;">
                        💰 <?php esc_html_e( 'Abrir caja', 'wbi-suite' ); ?>
                    </button>
                    <button id="pos-btn-close-cash" class="pos-btn pos-btn-danger pos-btn-sm" style="display:none;">
                        🔒 <?php esc_html_e( 'Cerrar caja', 'wbi-suite' ); ?>
                    </button>
                </div>

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

            <!-- ── CASH MODALS ───────────────────────────────────────── -->

            <!-- Open Cash Modal -->
            <div id="pos-modal-open-cash" class="pos-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="pos-modal-open-cash-title">
                <div class="pos-modal-backdrop"></div>
                <div class="pos-modal-box">
                    <h2 id="pos-modal-open-cash-title">💰 <?php esc_html_e( 'Abrir caja', 'wbi-suite' ); ?></h2>
                    <div class="pos-modal-body">
                        <div class="pos-field-group">
                            <label for="pos-open-cash-amount"><?php esc_html_e( 'Efectivo inicial ($)', 'wbi-suite' ); ?></label>
                            <input type="number" id="pos-open-cash-amount" min="0" step="0.01" value="0" class="pos-input">
                        </div>
                        <div class="pos-field-group">
                            <label for="pos-open-cash-note"><?php esc_html_e( 'Nota (opcional)', 'wbi-suite' ); ?></label>
                            <textarea id="pos-open-cash-note" rows="2" class="pos-input" placeholder="<?php esc_attr_e( 'Observaciones…', 'wbi-suite' ); ?>"></textarea>
                        </div>
                    </div>
                    <div class="pos-modal-actions">
                        <button id="pos-btn-open-cash-confirm" class="pos-btn pos-btn-primary">
                            💰 <?php esc_html_e( 'Abrir caja', 'wbi-suite' ); ?>
                        </button>
                        <button class="pos-btn pos-btn-secondary pos-modal-close" data-modal="pos-modal-open-cash">
                            <?php esc_html_e( 'Cancelar', 'wbi-suite' ); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Close Cash Modal -->
            <div id="pos-modal-close-cash" class="pos-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="pos-modal-close-cash-title">
                <div class="pos-modal-backdrop"></div>
                <div class="pos-modal-box pos-modal-box-lg">
                    <h2 id="pos-modal-close-cash-title">🔒 <?php esc_html_e( 'Cerrar caja', 'wbi-suite' ); ?></h2>
                    <div class="pos-modal-body">
                        <!-- Summary will be injected here by JS -->
                        <div id="pos-close-cash-summary"></div>
                        <div class="pos-field-group">
                            <label for="pos-close-cash-amount"><?php esc_html_e( 'Efectivo contado al cierre ($)', 'wbi-suite' ); ?></label>
                            <input type="number" id="pos-close-cash-amount" min="0" step="0.01" value="0" class="pos-input">
                        </div>
                        <div class="pos-field-group">
                            <label for="pos-close-cash-note"><?php esc_html_e( 'Nota de cierre (opcional)', 'wbi-suite' ); ?></label>
                            <textarea id="pos-close-cash-note" rows="2" class="pos-input" placeholder="<?php esc_attr_e( 'Observaciones…', 'wbi-suite' ); ?>"></textarea>
                        </div>
                    </div>
                    <div class="pos-modal-actions">
                        <button id="pos-btn-close-cash-confirm" class="pos-btn pos-btn-danger">
                            🔒 <?php esc_html_e( 'Cerrar caja', 'wbi-suite' ); ?>
                        </button>
                        <button class="pos-btn pos-btn-secondary pos-modal-close" data-modal="pos-modal-close-cash">
                            <?php esc_html_e( 'Cancelar', 'wbi-suite' ); ?>
                        </button>
                    </div>
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

        if ( ! $this->current_user_can_pos() ) {
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

        if ( ! $this->current_user_can_pos() ) {
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

        if ( ! $this->current_user_can_pos() ) {
            wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
        }

        // Seller / operator / cash session
        $seller_user_id   = absint( $_POST['seller_user_id'] ?? 0 );
        $cash_session_id  = absint( $_POST['cash_session_id'] ?? 0 );
        $operator_user_id = get_current_user_id();

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
        $order->update_meta_data( '_wbi_origin',                  'pos' );
        $order->update_meta_data( '_wbi_pos_created_by',          get_current_user_id() );
        $order->update_meta_data( '_wbi_pos_operator_user_id',    $operator_user_id );
        $order->update_meta_data( '_wbi_pos_seller_user_id',      $seller_user_id > 0 ? $seller_user_id : $operator_user_id );
        $order->update_meta_data( '_wbi_pos_cash_session_id',     $cash_session_id );
        $order->update_meta_data( '_wbi_pos_payments',            wp_json_encode( $clean_payments ) );
        $order->update_meta_data( '_wbi_pos_paid_total',          $paid_total );
        $order->update_meta_data( '_wbi_pos_balance_due',         $balance_due );
        $order->update_meta_data( '_wbi_pos_invoice_status',      'pending' );

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

        if ( ! $this->current_user_can_pos() ) {
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

    // =========================================================================
    // AJAX: GET SELLERS
    // =========================================================================

    /**
     * Returns the list of users allowed to be selected as seller/cashier.
     * Includes: administrator, shop_manager, wbi_cashier, wbi_vendedor.
     */
    public function ajax_get_sellers() {
        check_ajax_referer( 'wbi_pos_nonce', 'nonce' );

        if ( ! $this->current_user_can_pos() ) {
            wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
        }

        $users = get_users( array(
            'role__in' => array( 'administrator', 'shop_manager', 'wbi_cashier', 'wbi_vendedor' ),
            'fields'   => array( 'ID', 'display_name' ),
            'orderby'  => 'display_name',
            'order'    => 'ASC',
            'number'   => 200,
        ) );

        $sellers = array();
        foreach ( $users as $user ) {
            $sellers[] = array(
                'id'   => (int) $user->ID,
                'name' => $user->display_name,
            );
        }

        wp_send_json_success( $sellers );
    }

    // =========================================================================
    // AJAX: GET CASH STATUS
    // =========================================================================

    /**
     * Returns the open cash session for a given seller (or current user if none given).
     */
    public function ajax_get_cash_status() {
        check_ajax_referer( 'wbi_pos_nonce', 'nonce' );

        if ( ! $this->current_user_can_pos() ) {
            wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
        }

        $seller_id = absint( $_GET['seller_id'] ?? get_current_user_id() );
        $session   = WBI_POS_Cash_Sessions::get_open_session( $seller_id );

        if ( $session ) {
            $seller = get_userdata( $session->seller_user_id );
            wp_send_json_success( array(
                'status'       => 'open',
                'session_id'   => (int) $session->id,
                'seller_id'    => (int) $session->seller_user_id,
                'seller_name'  => $seller ? $seller->display_name : '',
                'opening_cash' => (float) $session->opening_cash,
                'opened_at'    => $session->opened_at,
            ) );
        } else {
            wp_send_json_success( array( 'status' => 'closed' ) );
        }
    }

    // =========================================================================
    // AJAX: OPEN CASH
    // =========================================================================

    public function ajax_open_cash() {
        check_ajax_referer( 'wbi_pos_nonce', 'nonce' );

        if ( ! $this->current_user_can_pos() ) {
            wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
        }

        $seller_id    = absint( $_POST['seller_id'] ?? get_current_user_id() );
        $opening_cash = (float) ( $_POST['opening_cash'] ?? 0 );
        $note         = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );

        // Prevent duplicate open session
        $existing = WBI_POS_Cash_Sessions::get_open_session( $seller_id );
        if ( $existing ) {
            wp_send_json_error( array( 'message' => 'Ya hay una caja abierta para este vendedor.' ) );
        }

        $session_id = WBI_POS_Cash_Sessions::open_session(
            $seller_id,
            get_current_user_id(),
            $opening_cash,
            $note
        );

        if ( ! $session_id ) {
            wp_send_json_error( array( 'message' => 'Error al abrir la caja. Intentá nuevamente.' ) );
        }

        wp_send_json_success( array(
            'session_id'   => $session_id,
            'opened_at'    => current_time( 'mysql' ),
            'opening_cash' => $opening_cash,
        ) );
    }

    // =========================================================================
    // AJAX: CLOSE CASH
    // =========================================================================

    public function ajax_close_cash() {
        check_ajax_referer( 'wbi_pos_nonce', 'nonce' );

        if ( ! $this->current_user_can_pos() ) {
            wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
        }

        $session_id   = absint( $_POST['session_id'] ?? 0 );
        $closing_cash = (float) ( $_POST['closing_cash'] ?? 0 );
        $note         = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );

        if ( ! $session_id ) {
            wp_send_json_error( array( 'message' => 'ID de sesión inválido.' ) );
        }

        $session = WBI_POS_Cash_Sessions::get_session( $session_id );
        if ( ! $session ) {
            wp_send_json_error( array( 'message' => 'Sesión no encontrada.' ) );
        }

        // Only seller, operator or admin can close this session
        $current_user_id = get_current_user_id();
        if (
            (int) $session->seller_user_id !== $current_user_id &&
            (int) $session->operator_user_id !== $current_user_id &&
            ! current_user_can( 'manage_woocommerce' )
        ) {
            wp_send_json_error( array( 'message' => 'No tenés permisos para cerrar esta caja.' ) );
        }

        $summary = WBI_POS_Cash_Sessions::get_session_summary( $session_id );
        $cash_in = (float) $session->opening_cash + (float) ( $summary['totals_by_method']['cash'] ?? 0 );
        $diff    = round( $closing_cash - $cash_in, 2 );

        $ok = WBI_POS_Cash_Sessions::close_session( $session_id, $closing_cash, $note );
        if ( ! $ok ) {
            wp_send_json_error( array( 'message' => 'Error al cerrar la caja.' ) );
        }

        wp_send_json_success( array(
            'summary'      => $summary,
            'opening_cash' => (float) $session->opening_cash,
            'closing_cash' => $closing_cash,
            'cash_in'      => round( $cash_in, 2 ),
            'difference'   => $diff,
            'opened_at'    => $session->opened_at,
            'closed_at'    => current_time( 'mysql' ),
        ) );
    }
}
