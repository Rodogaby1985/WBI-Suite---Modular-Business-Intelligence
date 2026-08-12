<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Load cash sessions helper
require_once dirname( __FILE__ ) . '/class-wbi-pos-cash-sessions.php';

// Load cash movements helper
require_once dirname( __FILE__ ) . '/class-wbi-pos-cash-movements.php';

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

        // POS settings page
        add_action( 'admin_menu',  array( $this, 'add_pos_settings_submenu' ), 101 );
        add_action( 'admin_init',  array( $this, 'register_pos_settings' ) );

        // Ensure cash sessions and movements tables exist
        WBI_POS_Cash_Sessions::maybe_create_table();
        WBI_POS_Cash_Movements::maybe_create_table();

        // Map manage_woocommerce => wbi_pos_access (so admin/shop_manager can also access POS)
        add_filter( 'user_has_cap', array( $this, 'map_pos_access_cap' ), 10, 3 );

        // AJAX endpoints — products, customers, orders
        add_action( 'wp_ajax_wbi_pos_search_products',  array( $this, 'ajax_search_products' ) );
        add_action( 'wp_ajax_wbi_pos_search_customers', array( $this, 'ajax_search_customers' ) );
        add_action( 'wp_ajax_wbi_pos_create_customer',  array( $this, 'ajax_create_customer' ) );
        add_action( 'wp_ajax_wbi_pos_create_order',     array( $this, 'ajax_create_order' ) );
        add_action( 'wp_ajax_wbi_pos_try_invoice',      array( $this, 'ajax_try_invoice' ) );

        // AJAX endpoints — sellers & cash sessions
        add_action( 'wp_ajax_wbi_pos_get_sellers',      array( $this, 'ajax_get_sellers' ) );
        add_action( 'wp_ajax_wbi_pos_get_cash_status',  array( $this, 'ajax_get_cash_status' ) );
        add_action( 'wp_ajax_wbi_pos_open_cash',        array( $this, 'ajax_open_cash' ) );
        add_action( 'wp_ajax_wbi_pos_close_cash',       array( $this, 'ajax_close_cash' ) );

        // AJAX endpoints — manual movements (income / expense / withdrawal / deposit)
        add_action( 'wp_ajax_wbi_pos_add_movement',     array( $this, 'ajax_add_movement' ) );
        add_action( 'wp_ajax_wbi_pos_get_movements',    array( $this, 'ajax_get_movements' ) );
    }

    // =========================================================================
    // POS SETTINGS HELPERS
    // =========================================================================

    /**
     * Returns the current POS settings array.
     *
     * @return array<string,mixed>
     */
    public static function get_pos_settings() {
        $defaults = array(
            'pos_require_customer'          => 0,
            'pos_allow_quick_create'        => 1,
            'pos_allow_no_email'            => 1,
            'pos_placeholder_email_enabled' => 0,
            'pos_placeholder_email_pattern' => 'pos_{phone}@poslocal.internal',
            'pos_enable_adjustments'        => 1,
            'pos_enable_discount'           => 1,
            'pos_enable_surcharge'          => 1,
            'pos_enable_shipping'           => 1,
            'pos_enable_manual_tax'         => 1,
            'pos_max_discount_pct'          => 100,
            'pos_require_discount_reason'   => 0,
        );
        $saved = get_option( 'wbi_pos_settings', array() );
        return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
    }

    /**
     * Register POS settings page as submenu under WBI Dashboard.
     */
    public function add_pos_settings_submenu() {
        add_submenu_page(
            'wbi-dashboard-view',
            'POS — Configuración',
            'POS — Config.',
            'manage_woocommerce',
            'wbi-pos-settings',
            array( $this, 'render_pos_settings_page' )
        );
    }

    /**
     * Register the wbi_pos_settings option.
     */
    public function register_pos_settings() {
        register_setting( 'wbi_pos_settings_group', 'wbi_pos_settings', array(
            'sanitize_callback' => array( $this, 'sanitize_pos_settings' ),
        ) );
    }

    /**
     * Sanitize POS settings on save.
     *
     * @param  mixed $input Raw input.
     * @return array<string,mixed>
     */
    public function sanitize_pos_settings( $input ) {
        if ( ! is_array( $input ) ) {
            return array();
        }
        $clean = array();
        $toggles = array(
            'pos_require_customer', 'pos_allow_quick_create', 'pos_allow_no_email',
            'pos_placeholder_email_enabled', 'pos_enable_adjustments', 'pos_enable_discount',
            'pos_enable_surcharge', 'pos_enable_shipping', 'pos_enable_manual_tax',
            'pos_require_discount_reason',
        );
        foreach ( $toggles as $key ) {
            $clean[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
        }
        $clean['pos_max_discount_pct']          = absint( $input['pos_max_discount_pct'] ?? 100 );
        $clean['pos_placeholder_email_pattern'] = sanitize_text_field( $input['pos_placeholder_email_pattern'] ?? 'pos_{phone}@poslocal.internal' );
        return $clean;
    }

    /**
     * Render POS settings admin page.
     */
    public function render_pos_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Sin permisos.', 'wbi-suite' ) );
        }
        $s = self::get_pos_settings();
        ?>
        <div class="wrap">
            <h1>🏪 POS — Configuración</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'wbi_pos_settings_group' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Requerir cliente antes de confirmar</th>
                        <td><label><input type="checkbox" name="wbi_pos_settings[pos_require_customer]" value="1" <?php checked( 1, $s['pos_require_customer'] ); ?>> Activado</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Permitir creación rápida de clientes</th>
                        <td><label><input type="checkbox" name="wbi_pos_settings[pos_allow_quick_create]" value="1" <?php checked( 1, $s['pos_allow_quick_create'] ); ?>> Activado</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Permitir cliente sin email</th>
                        <td><label><input type="checkbox" name="wbi_pos_settings[pos_allow_no_email]" value="1" <?php checked( 1, $s['pos_allow_no_email'] ); ?>> Activado</label><p class="description">Si está desactivado, se creará un perfil de invitado en el pedido sin usuario WordPress.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Auto-generar email placeholder</th>
                        <td><label><input type="checkbox" name="wbi_pos_settings[pos_placeholder_email_enabled]" value="1" <?php checked( 1, $s['pos_placeholder_email_enabled'] ); ?>> Activado</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Patrón de email placeholder</th>
                        <td><input type="text" name="wbi_pos_settings[pos_placeholder_email_pattern]" value="<?php echo esc_attr( $s['pos_placeholder_email_pattern'] ); ?>" class="regular-text"><p class="description">Variables disponibles: <code>{phone}</code>, <code>{timestamp}</code></p></td>
                    </tr>
                    <tr><th scope="row" colspan="2"><h2 style="margin:0">Ajustes al pedido</h2></th></tr>
                    <tr>
                        <th scope="row">Habilitar panel de ajustes</th>
                        <td><label><input type="checkbox" name="wbi_pos_settings[pos_enable_adjustments]" value="1" <?php checked( 1, $s['pos_enable_adjustments'] ); ?>> Activado</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Habilitar descuentos</th>
                        <td><label><input type="checkbox" name="wbi_pos_settings[pos_enable_discount]" value="1" <?php checked( 1, $s['pos_enable_discount'] ); ?>> Activado</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Habilitar recargos</th>
                        <td><label><input type="checkbox" name="wbi_pos_settings[pos_enable_surcharge]" value="1" <?php checked( 1, $s['pos_enable_surcharge'] ); ?>> Activado</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Habilitar envío manual</th>
                        <td><label><input type="checkbox" name="wbi_pos_settings[pos_enable_shipping]" value="1" <?php checked( 1, $s['pos_enable_shipping'] ); ?>> Activado</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Habilitar impuesto manual</th>
                        <td><label><input type="checkbox" name="wbi_pos_settings[pos_enable_manual_tax]" value="1" <?php checked( 1, $s['pos_enable_manual_tax'] ); ?>> Activado</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Descuento máximo (%)</th>
                        <td><input type="number" name="wbi_pos_settings[pos_max_discount_pct]" value="<?php echo esc_attr( $s['pos_max_discount_pct'] ); ?>" min="0" max="100" class="small-text"> %</td>
                    </tr>
                    <tr>
                        <th scope="row">Requerir motivo en descuentos</th>
                        <td><label><input type="checkbox" name="wbi_pos_settings[pos_require_discount_reason]" value="1" <?php checked( 1, $s['pos_require_discount_reason'] ); ?>> Activado</label></td>
                    </tr>
                </table>
                <?php submit_button( 'Guardar configuración POS' ); ?>
            </form>
        </div>
        <?php
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
        // Also grant customer-creation and adjustments caps to manage_woocommerce users
        $pos_caps = array( 'wbi_pos_create_customer', 'wbi_pos_apply_adjustments' );
        foreach ( $pos_caps as $cap ) {
            if ( in_array( $cap, (array) $caps, true ) && ! empty( $allcaps['manage_woocommerce'] ) ) {
                $allcaps[ $cap ] = true;
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

        $pos_settings = self::get_pos_settings();

        wp_localize_script( 'wbi-pos-js', 'wbiPos', apply_filters( 'wbi_pos_localize_data', array(
            'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( 'wbi_pos_nonce' ),
            'currency'         => get_woocommerce_currency_symbol(),
            'priceDecimals'    => (int) wc_get_price_decimals(),
            'decimalSeparator' => wc_get_price_decimal_separator(),
            'settings' => array(
                'requireCustomer'         => (bool) $pos_settings['pos_require_customer'],
                'allowQuickCreate'        => (bool) $pos_settings['pos_allow_quick_create'],
                'allowNoEmail'            => (bool) $pos_settings['pos_allow_no_email'],
                'placeholderEmailEnabled' => (bool) $pos_settings['pos_placeholder_email_enabled'],
                'enableAdjustments'       => (bool) $pos_settings['pos_enable_adjustments'],
                'enableDiscount'          => (bool) $pos_settings['pos_enable_discount'],
                'enableSurcharge'         => (bool) $pos_settings['pos_enable_surcharge'],
                'enableShipping'          => (bool) $pos_settings['pos_enable_shipping'],
                'enableManualTax'         => (bool) $pos_settings['pos_enable_manual_tax'],
                'maxDiscountPct'          => (int)  $pos_settings['pos_max_discount_pct'],
                'requireDiscountReason'   => (bool) $pos_settings['pos_require_discount_reason'],
            ),
            'i18n'     => array(
                'searchPlaceholder'  => 'Buscar por nombre, SKU o código de barras…',
                'addProduct'         => 'Agregar',
                'removeItem'         => 'Quitar',
                'qty'                => 'Cant.',
                'price'              => 'Precio',
                'priceInvalid'       => 'Precio inválido. Se restauró el último valor válido.',
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
                'noSearchResults'    => 'Sin resultados',
                'noCustomers'        => 'No se encontraron clientes.',
                'loadingProducts'    => 'Cargando productos…',
                'loadingMoreProducts'=> 'Cargando más…',
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
                // Manual movements
                'addMovement'        => 'Ingreso/Egreso',
                'movementTitle'      => 'Registrar movimiento de caja',
                'movementType'       => 'Tipo de movimiento',
                'movementTypes'      => array(
                    'manual_income'  => '⬆️ Ingreso manual',
                    'manual_expense' => '⬇️ Egreso manual',
                    'withdrawal'     => '🏧 Retiro',
                    'deposit'        => '💵 Depósito',
                ),
                'movementConfirm'    => 'Registrar',
                'movementOk'         => 'Movimiento registrado correctamente.',
                'movementError'      => 'Error al registrar el movimiento.',
                'noCashForMovement'  => 'Debés abrir la caja antes de registrar un movimiento.',
                'expectedCash'       => 'Efectivo esperado',
                // Customer flow
                'customerSearchPlaceholder' => 'Buscar por nombre, apellido, email, teléfono, DNI/CUIT…',
                'newCustomer'               => '+ Nuevo cliente',
                'createCustomer'            => 'Crear cliente',
                'customerCreated'           => 'Cliente creado correctamente.',
                'customerError'             => 'Error al crear el cliente.',
                'customerRequired'          => 'Seleccioná un cliente antes de confirmar el pedido.',
                'wholesale'                 => 'Mayorista',
                'retail'                    => 'Minorista',
                'consumidorFinal'           => 'Consumidor Final',
                'customerTypeRequired'      => 'Seleccioná el tipo de cliente.',
                'phoneRequired'             => 'El teléfono/WhatsApp es obligatorio.',
                'firstNameRequired'         => 'El nombre es obligatorio.',
                'lastNameRequired'          => 'El apellido es obligatorio.',
                'emailInvalid'              => 'El email no tiene un formato válido.',
                'wholesalePrices'           => '✅ Precios mayoristas activos.',
                // Adjustments
                'adjustments'               => 'Ajustes',
                'addAdjustment'             => '＋ Ajuste',
                'adjustmentTitle'           => 'Agregar ajuste al pedido',
                'adjustmentType'            => 'Tipo',
                'adjustmentTypes'           => array(
                    'discount'   => '🏷️ Descuento',
                    'surcharge'  => '➕ Recargo',
                    'shipping'   => '🚚 Envío manual',
                    'manual_tax' => '📋 Impuesto manual',
                ),
                'adjustmentMode'            => 'Modo',
                'adjustmentModeFixed'       => 'Fijo ($)',
                'adjustmentModePct'         => 'Porcentaje (%)',
                'adjustmentValue'           => 'Valor',
                'adjustmentReason'          => 'Motivo',
                'adjustmentReasonRequired'  => 'El motivo es obligatorio para descuentos.',
                'adjustmentAdd'             => 'Agregar',
                'adjustmentValueRequired'   => 'El valor debe ser mayor a cero.',
                'adjustmentMaxDiscount'     => 'El descuento supera el máximo permitido (%s%).',
                'noAdjustments'             => 'Sin ajustes',
            ),
        ) ) );
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
                    <button id="pos-btn-add-movement" class="pos-btn pos-btn-outline pos-btn-sm" style="display:none;">
                        ↕️ <?php esc_html_e( 'Ingreso/Egreso', 'wbi-suite' ); ?>
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

            <!-- Manual Movement Modal -->
            <div id="pos-modal-movement" class="pos-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="pos-modal-movement-title">
                <div class="pos-modal-backdrop"></div>
                <div class="pos-modal-box">
                    <h2 id="pos-modal-movement-title">↕️ <?php esc_html_e( 'Registrar movimiento de caja', 'wbi-suite' ); ?></h2>
                    <div class="pos-modal-body">
                        <div class="pos-field-group">
                            <label for="pos-movement-type"><?php esc_html_e( 'Tipo de movimiento', 'wbi-suite' ); ?></label>
                            <select id="pos-movement-type" class="pos-input">
                                <option value="manual_income">⬆️ <?php esc_html_e( 'Ingreso manual', 'wbi-suite' ); ?></option>
                                <option value="manual_expense">⬇️ <?php esc_html_e( 'Egreso manual', 'wbi-suite' ); ?></option>
                                <option value="withdrawal">🏧 <?php esc_html_e( 'Retiro', 'wbi-suite' ); ?></option>
                                <option value="deposit">💵 <?php esc_html_e( 'Depósito', 'wbi-suite' ); ?></option>
                            </select>
                        </div>
                        <div class="pos-field-group">
                            <label for="pos-movement-method"><?php esc_html_e( 'Medio', 'wbi-suite' ); ?></label>
                            <select id="pos-movement-method" class="pos-input">
                                <option value="cash"><?php esc_html_e( 'Efectivo', 'wbi-suite' ); ?></option>
                                <option value="transfer"><?php esc_html_e( 'Transferencia', 'wbi-suite' ); ?></option>
                                <option value="debit"><?php esc_html_e( 'Tarjeta Débito', 'wbi-suite' ); ?></option>
                                <option value="credit"><?php esc_html_e( 'Tarjeta Crédito', 'wbi-suite' ); ?></option>
                                <option value="qr"><?php esc_html_e( 'QR / MercadoPago', 'wbi-suite' ); ?></option>
                                <option value="other"><?php esc_html_e( 'Otro', 'wbi-suite' ); ?></option>
                            </select>
                        </div>
                        <div class="pos-field-group">
                            <label for="pos-movement-amount"><?php esc_html_e( 'Monto ($)', 'wbi-suite' ); ?></label>
                            <input type="number" id="pos-movement-amount" min="0.01" step="0.01" value="" class="pos-input">
                        </div>
                        <div class="pos-field-group">
                            <label for="pos-movement-reference"><?php esc_html_e( 'Referencia (opcional)', 'wbi-suite' ); ?></label>
                            <input type="text" id="pos-movement-reference" class="pos-input" placeholder="<?php esc_attr_e( 'Número de comprobante, etc.', 'wbi-suite' ); ?>">
                        </div>
                        <div class="pos-field-group">
                            <label for="pos-movement-notes"><?php esc_html_e( 'Notas (opcional)', 'wbi-suite' ); ?></label>
                            <textarea id="pos-movement-notes" rows="2" class="pos-input" placeholder="<?php esc_attr_e( 'Observaciones…', 'wbi-suite' ); ?>"></textarea>
                        </div>
                    </div>
                    <div class="pos-modal-actions">
                        <button id="pos-btn-movement-confirm" class="pos-btn pos-btn-primary">
                            ✅ <?php esc_html_e( 'Registrar', 'wbi-suite' ); ?>
                        </button>
                        <button class="pos-btn pos-btn-secondary pos-modal-close" data-modal="pos-modal-movement">
                            <?php esc_html_e( 'Cancelar', 'wbi-suite' ); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ── QUICK CREATE CUSTOMER MODAL ──────────────────────── -->
            <div id="pos-modal-create-customer" class="pos-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="pos-modal-create-customer-title">
                <div class="pos-modal-backdrop"></div>
                <div class="pos-modal-box pos-modal-box-xl">
                    <h2 id="pos-modal-create-customer-title">👤 <?php esc_html_e( 'Nuevo cliente', 'wbi-suite' ); ?></h2>
                    <div class="pos-modal-body">
                        <div class="pos-form-grid">
                            <div class="pos-field-group">
                                <label for="pos-cc-first-name"><?php esc_html_e( 'Nombre *', 'wbi-suite' ); ?></label>
                                <input type="text" id="pos-cc-first-name" class="pos-input" autocomplete="off">
                            </div>
                            <div class="pos-field-group">
                                <label for="pos-cc-last-name"><?php esc_html_e( 'Apellido *', 'wbi-suite' ); ?></label>
                                <input type="text" id="pos-cc-last-name" class="pos-input" autocomplete="off">
                            </div>
                            <div class="pos-field-group">
                                <label for="pos-cc-phone"><?php esc_html_e( 'Teléfono / WhatsApp *', 'wbi-suite' ); ?></label>
                                <input type="text" id="pos-cc-phone" class="pos-input" placeholder="<?php esc_attr_e( 'Ej: +5491112345678', 'wbi-suite' ); ?>" autocomplete="off">
                            </div>
                            <div class="pos-field-group">
                                <label for="pos-cc-customer-type"><?php esc_html_e( 'Tipo de cliente *', 'wbi-suite' ); ?></label>
                                <select id="pos-cc-customer-type" class="pos-input">
                                    <option value=""><?php esc_html_e( '— Seleccioná —', 'wbi-suite' ); ?></option>
                                    <option value="retail"><?php esc_html_e( 'Minorista', 'wbi-suite' ); ?></option>
                                    <option value="wholesale"><?php esc_html_e( 'Mayorista', 'wbi-suite' ); ?></option>
                                </select>
                            </div>
                            <div class="pos-field-group">
                                <label for="pos-cc-email"><?php esc_html_e( 'Email (opcional)', 'wbi-suite' ); ?></label>
                                <input type="email" id="pos-cc-email" class="pos-input" autocomplete="off">
                            </div>
                            <div class="pos-field-group">
                                <label for="pos-cc-document-type"><?php esc_html_e( 'Tipo de documento', 'wbi-suite' ); ?></label>
                                <select id="pos-cc-document-type" class="pos-input">
                                    <option value=""><?php esc_html_e( '— Ninguno —', 'wbi-suite' ); ?></option>
                                    <option value="DNI">DNI</option>
                                    <option value="CUIT">CUIT</option>
                                    <option value="other"><?php esc_html_e( 'Otro', 'wbi-suite' ); ?></option>
                                </select>
                            </div>
                            <div class="pos-field-group">
                                <label for="pos-cc-document-number"><?php esc_html_e( 'Número de documento', 'wbi-suite' ); ?></label>
                                <input type="text" id="pos-cc-document-number" class="pos-input" autocomplete="off">
                            </div>
                            <div class="pos-field-group">
                                <label for="pos-cc-company-name"><?php esc_html_e( 'Empresa / Razón social', 'wbi-suite' ); ?></label>
                                <input type="text" id="pos-cc-company-name" class="pos-input" autocomplete="off">
                            </div>
                            <div class="pos-field-group pos-field-full">
                                <label for="pos-cc-address-1"><?php esc_html_e( 'Dirección', 'wbi-suite' ); ?></label>
                                <input type="text" id="pos-cc-address-1" class="pos-input" autocomplete="off">
                            </div>
                            <div class="pos-field-group">
                                <label for="pos-cc-city"><?php esc_html_e( 'Ciudad', 'wbi-suite' ); ?></label>
                                <input type="text" id="pos-cc-city" class="pos-input" autocomplete="off">
                            </div>
                            <div class="pos-field-group">
                                <label for="pos-cc-postcode"><?php esc_html_e( 'Código postal', 'wbi-suite' ); ?></label>
                                <input type="text" id="pos-cc-postcode" class="pos-input" autocomplete="off">
                            </div>
                        </div>
                        <div id="pos-cc-error" class="pos-cc-error" style="display:none;"></div>
                    </div>
                    <div class="pos-modal-actions">
                        <button id="pos-btn-cc-confirm" class="pos-btn pos-btn-primary">
                            👤 <?php esc_html_e( 'Crear cliente', 'wbi-suite' ); ?>
                        </button>
                        <button class="pos-btn pos-btn-secondary pos-modal-close" data-modal="pos-modal-create-customer">
                            <?php esc_html_e( 'Cancelar', 'wbi-suite' ); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ── ADJUSTMENTS MODAL ─────────────────────────────────── -->
            <div id="pos-modal-adjustment" class="pos-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="pos-modal-adjustment-title">
                <div class="pos-modal-backdrop"></div>
                <div class="pos-modal-box">
                    <h2 id="pos-modal-adjustment-title">🏷️ <?php esc_html_e( 'Agregar ajuste al pedido', 'wbi-suite' ); ?></h2>
                    <div class="pos-modal-body">
                        <div class="pos-field-group">
                            <label for="pos-adj-type"><?php esc_html_e( 'Tipo de ajuste', 'wbi-suite' ); ?></label>
                            <select id="pos-adj-type" class="pos-input">
                                <option value="discount">🏷️ <?php esc_html_e( 'Descuento', 'wbi-suite' ); ?></option>
                                <option value="surcharge">➕ <?php esc_html_e( 'Recargo', 'wbi-suite' ); ?></option>
                                <option value="shipping">🚚 <?php esc_html_e( 'Envío manual', 'wbi-suite' ); ?></option>
                                <option value="manual_tax">📋 <?php esc_html_e( 'Impuesto manual', 'wbi-suite' ); ?></option>
                            </select>
                        </div>
                        <div class="pos-field-group">
                            <label for="pos-adj-mode"><?php esc_html_e( 'Modo', 'wbi-suite' ); ?></label>
                            <select id="pos-adj-mode" class="pos-input">
                                <option value="fixed"><?php esc_html_e( 'Fijo ($)', 'wbi-suite' ); ?></option>
                                <option value="percent"><?php esc_html_e( 'Porcentaje (%)', 'wbi-suite' ); ?></option>
                            </select>
                        </div>
                        <div class="pos-field-group">
                            <label for="pos-adj-value"><?php esc_html_e( 'Valor', 'wbi-suite' ); ?></label>
                            <input type="number" id="pos-adj-value" min="0.01" step="0.01" class="pos-input">
                        </div>
                        <div class="pos-field-group" id="pos-adj-reason-group">
                            <label for="pos-adj-reason"><?php esc_html_e( 'Motivo', 'wbi-suite' ); ?></label>
                            <input type="text" id="pos-adj-reason" class="pos-input" placeholder="<?php esc_attr_e( 'Motivo del ajuste…', 'wbi-suite' ); ?>">
                        </div>
                    </div>
                    <div class="pos-modal-actions">
                        <button id="pos-btn-adj-confirm" class="pos-btn pos-btn-primary">
                            ✅ <?php esc_html_e( 'Agregar', 'wbi-suite' ); ?>
                        </button>
                        <button class="pos-btn pos-btn-secondary pos-modal-close" data-modal="pos-modal-adjustment">
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
                                   placeholder="<?php esc_attr_e( 'Buscar por nombre, apellido, email, teléfono, DNI/CUIT…', 'wbi-suite' ); ?>"
                                   autocomplete="off">
                            <button id="pos-btn-consumer" class="pos-btn pos-btn-outline pos-btn-sm">
                                <?php esc_html_e( 'Consumidor Final', 'wbi-suite' ); ?>
                            </button>
                            <button id="pos-btn-new-customer" class="pos-btn pos-btn-success pos-btn-sm" style="display:none;">
                                + <?php esc_html_e( 'Nuevo cliente', 'wbi-suite' ); ?>
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
                            <span><?php esc_html_e( 'Subtotal', 'wbi-suite' ); ?></span>
                            <strong id="pos-subtotal">$0.00</strong>
                        </div>
                        <div class="pos-total-row pos-adjustments-row" style="display:none;">
                            <span><?php esc_html_e( 'Ajustes', 'wbi-suite' ); ?></span>
                            <strong id="pos-adjustments-total" class="pos-adj-amount">$0.00</strong>
                        </div>
                        <div class="pos-total-row pos-total-final-row">
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

                    <!-- Adjustments panel (shown when enabled in settings) -->
                    <div class="pos-adjustments-wrap" id="pos-adjustments-wrap" style="display:none;">
                        <div class="pos-payments-header">
                            <span class="pos-label"><?php esc_html_e( 'Ajustes', 'wbi-suite' ); ?></span>
                            <button id="pos-btn-add-adjustment" class="pos-btn pos-btn-outline pos-btn-sm">
                                ＋ <?php esc_html_e( 'Ajuste', 'wbi-suite' ); ?>
                            </button>
                        </div>
                        <div id="pos-adjustments-list"></div>
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

        $query            = trim( sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ) );
        $page             = max( 1, absint( $_GET['page'] ?? 1 ) );
        $requested_per    = absint( $_GET['per_page'] ?? 20 );
        $per_page         = min( 20, max( 1, $requested_per ) );
        $offset           = ( $page - 1 ) * $per_page;
        $products         = array();
        $candidate_ids    = array();
        $has_more         = false;

        if ( '' === $query ) {
            $default_orderby = get_option( 'woocommerce_default_catalog_orderby', 'menu_order' );
            if ( 'menu_order' === $default_orderby ) {
                $default_orderby = 'menu_order';
            }

            $ordering_args = wc_get_catalog_ordering_args( $default_orderby, 'asc' );

            $catalog_args = array(
                'post_type'              => array( 'product', 'product_variation' ),
                'post_status'            => 'publish',
                'posts_per_page'         => $per_page + 1,
                'offset'                 => $offset,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'ignore_sticky_posts'    => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'orderby'                => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
                'order'                  => 'ASC',
            );

            if ( ! empty( $ordering_args['orderby'] ) ) {
                $catalog_args['orderby'] = $ordering_args['orderby'];
            }
            if ( ! empty( $ordering_args['order'] ) ) {
                $catalog_args['order'] = $ordering_args['order'];
            }
            if ( ! empty( $ordering_args['meta_key'] ) ) {
                $catalog_args['meta_key'] = $ordering_args['meta_key'];
            }

            $catalog_query  = new WP_Query( $catalog_args );
            $candidate_ids  = is_array( $catalog_query->posts ) ? $catalog_query->posts : array();
        } else {
            $window_size = ( $page * $per_page ) + 1;
            $base_args   = array(
                'post_type'              => array( 'product', 'product_variation' ),
                'post_status'            => 'publish',
                'posts_per_page'         => $window_size,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'ignore_sticky_posts'    => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            );

            $sku_args = $base_args;
            $sku_args['meta_query'] = array(
                'relation' => 'OR',
                array( 'key' => '_sku',      'value' => $query, 'compare' => 'LIKE' ),
                array( 'key' => '_ean',      'value' => $query, 'compare' => 'LIKE' ),
                array( 'key' => '_barcode',  'value' => $query, 'compare' => 'LIKE' ),
                array( 'key' => 'ean',       'value' => $query, 'compare' => 'LIKE' ),
                array( 'key' => 'barcode',   'value' => $query, 'compare' => 'LIKE' ),
            );

            $name_args = $base_args;
            $name_args['s']       = $query;
            $name_args['orderby'] = 'relevance';

            $sku_query  = new WP_Query( $sku_args );
            $name_query = new WP_Query( $name_args );

            $merged_ids = array_merge(
                $sku_query->posts ?? array(),
                $name_query->posts ?? array()
            );

            $seen = array();
            foreach ( $merged_ids as $product_id ) {
                $product_id = absint( $product_id );
                if ( ! $product_id || isset( $seen[ $product_id ] ) ) {
                    continue;
                }
                $seen[ $product_id ] = true;
                $candidate_ids[]     = $product_id;
            }

            if ( $offset > 0 ) {
                $candidate_ids = array_slice( $candidate_ids, $offset );
            }
        }

        $candidate_ids = array_values( array_filter( array_map( 'absint', $candidate_ids ) ) );

        foreach ( $candidate_ids as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product || ! $product->is_purchasable() ) {
                continue;
            }

            $products[] = $this->format_pos_product_payload( $product );

            if ( count( $products ) >= ( $per_page + 1 ) ) {
                break;
            }
        }

        if ( count( $products ) > $per_page ) {
            $has_more = true;
            $products = array_slice( $products, 0, $per_page );
        }

        wp_send_json_success( array(
            'items'      => $products,
            'pagination' => array(
                'page'     => $page,
                'per_page' => $per_page,
                'has_more' => $has_more,
            ),
            'mode'       => '' === $query ? 'catalog' : 'search',
        ) );
    }

    private function format_pos_product_payload( WC_Product $product ) {
        $stock_qty = $product->get_stock_quantity();
        $price     = (float) $product->get_price();
        $payload   = array(
            'product_id'    => $product->get_id(),
            'title'         => $product->get_name(),
            'sku'           => $product->get_sku(),
            'price'         => $price,
            'image_url'     => wp_get_attachment_url( $product->get_image_id() ) ?: '',
            'stock_status'  => $product->get_stock_status(),
            // Backward compatible fields used by current POS UI.
            'id'            => $product->get_id(),
            'name'          => $product->get_name(),
            'stock'         => null !== $stock_qty ? (int) $stock_qty : null,
            'image'         => wp_get_attachment_url( $product->get_image_id() ) ?: '',
        );

        return $payload;
    }

    // =========================================================================
    // AJAX: SEARCH CUSTOMERS (enhanced)
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

        // Search by standard WP fields (login, email, display_name, nicename)
        $user_query_std = new WP_User_Query( array(
            'search'         => '*' . $query . '*',
            'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ),
            'number'         => 20,
        ) );

        // Search by billing meta fields (phone, first_name, last_name, document_number)
        $user_query_meta = new WP_User_Query( array(
            'meta_query' => array(
                'relation' => 'OR',
                array( 'key' => 'billing_first_name',    'value' => $query, 'compare' => 'LIKE' ),
                array( 'key' => 'billing_last_name',     'value' => $query, 'compare' => 'LIKE' ),
                array( 'key' => 'billing_phone',         'value' => $query, 'compare' => 'LIKE' ),
                array( 'key' => '_wbi_whatsapp',         'value' => $query, 'compare' => 'LIKE' ),
                array( 'key' => '_wbi_document_number',  'value' => $query, 'compare' => 'LIKE' ),
            ),
            'number' => 20,
        ) );

        $all_users = array_merge(
            $user_query_std->get_results(),
            $user_query_meta->get_results()
        );

        $seen    = array();
        $results = array();
        foreach ( $all_users as $user ) {
            if ( isset( $seen[ $user->ID ] ) ) continue;
            $seen[ $user->ID ] = true;

            $customer_type = get_user_meta( $user->ID, '_wbi_customer_type', true );
            $phone         = get_user_meta( $user->ID, 'billing_phone', true );
            $whatsapp      = get_user_meta( $user->ID, '_wbi_whatsapp', true );
            $doc_number    = get_user_meta( $user->ID, '_wbi_document_number', true );

            $results[] = array(
                'id'            => $user->ID,
                'name'          => $user->display_name,
                'email'         => $user->user_email,
                'phone'         => $phone ?: $whatsapp,
                'customer_type' => $customer_type ?: 'retail',
                'doc_number'    => $doc_number,
            );

            if ( count( $results ) >= 15 ) break;
        }

        wp_send_json_success( $results );
    }

    // =========================================================================
    // AJAX: CREATE CUSTOMER
    // =========================================================================

    public function ajax_create_customer() {
        check_ajax_referer( 'wbi_pos_nonce', 'nonce' );

        if ( ! $this->current_user_can_pos() ) {
            wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
        }

        // Check if quick create is allowed
        $pos_settings = self::get_pos_settings();
        if ( empty( $pos_settings['pos_allow_quick_create'] ) ) {
            wp_send_json_error( array( 'message' => 'La creación rápida de clientes no está habilitada.' ) );
        }

        // Capability: must be able to create customers
        if ( ! current_user_can( 'wbi_pos_create_customer' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'No tenés permisos para crear clientes.' ), 403 );
        }

        // --- Required fields ---
        $first_name     = sanitize_text_field( wp_unslash( $_POST['first_name']     ?? '' ) );
        $last_name      = sanitize_text_field( wp_unslash( $_POST['last_name']      ?? '' ) );
        $phone          = sanitize_text_field( wp_unslash( $_POST['phone']          ?? '' ) );
        $customer_type  = sanitize_text_field( wp_unslash( $_POST['customer_type']  ?? '' ) );

        if ( ! $first_name ) {
            wp_send_json_error( array( 'message' => 'El nombre es obligatorio.' ) );
        }
        if ( ! $last_name ) {
            wp_send_json_error( array( 'message' => 'El apellido es obligatorio.' ) );
        }
        if ( ! $phone ) {
            wp_send_json_error( array( 'message' => 'El teléfono/WhatsApp es obligatorio.' ) );
        }
        if ( ! in_array( $customer_type, array( 'wholesale', 'retail' ), true ) ) {
            wp_send_json_error( array( 'message' => 'El tipo de cliente es obligatorio.' ) );
        }

        // --- Optional fields ---
        $email          = sanitize_email( wp_unslash( $_POST['email']           ?? '' ) );
        $document_type  = sanitize_text_field( wp_unslash( $_POST['document_type']  ?? '' ) );
        $document_number= sanitize_text_field( wp_unslash( $_POST['document_number']?? '' ) );
        $company_name   = sanitize_text_field( wp_unslash( $_POST['company_name']   ?? '' ) );
        $address_1      = sanitize_text_field( wp_unslash( $_POST['address_1']      ?? '' ) );
        $city           = sanitize_text_field( wp_unslash( $_POST['city']           ?? '' ) );
        $state          = sanitize_text_field( wp_unslash( $_POST['state']          ?? '' ) );
        $postcode       = sanitize_text_field( wp_unslash( $_POST['postcode']       ?? '' ) );

        // --- Email validation ---
        if ( $email ) {
            if ( ! is_email( $email ) ) {
                wp_send_json_error( array( 'message' => 'El formato del email no es válido.' ) );
            }
            $existing_id = email_exists( $email );
            if ( $existing_id ) {
                // If the user already exists, return that user instead of creating a new one
                $existing = get_userdata( $existing_id );
                wp_send_json_error( array(
                    'message'   => 'Ya existe un cliente con ese email.',
                    'existing'  => array(
                        'id'    => $existing_id,
                        'name'  => $existing ? $existing->display_name : '',
                        'email' => $email,
                    ),
                ) );
            }
        }

        $cashier_id   = get_current_user_id();
        $cashier_data = get_userdata( $cashier_id );
        $cashier_name = $cashier_data ? $cashier_data->display_name : 'Cajero';
        $timestamp    = current_time( 'mysql' );

        $customer_id = 0;
        $is_guest    = false;
        $final_email = $email;

        if ( $email ) {
            // Create full WP user + WooCommerce customer with provided email
            if ( function_exists( 'wc_create_new_customer' ) ) {
                $username = wc_create_new_customer_username( $email );
                $result   = wc_create_new_customer( $email, $username, wp_generate_password( 12, false ) );
            } else {
                $result = wp_insert_user( array(
                    'user_login' => sanitize_user( $email, true ),
                    'user_email' => $email,
                    'user_pass'  => wp_generate_password( 12, false ),
                    'role'       => 'customer',
                ) );
            }
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }
            $customer_id = (int) $result;

        } elseif ( ! empty( $pos_settings['pos_allow_no_email'] ) ) {

            if ( ! empty( $pos_settings['pos_placeholder_email_enabled'] ) ) {
                // Generate placeholder email
                $pattern     = $pos_settings['pos_placeholder_email_pattern'] ?: 'pos_{phone}@poslocal.internal';
                $final_email = str_replace(
                    array( '{phone}', '{timestamp}' ),
                    array( preg_replace( '/[^0-9]/', '', $phone ), time() ),
                    $pattern
                );

                // Ensure uniqueness
                $attempt = $final_email;
                $suffix  = 1;
                while ( email_exists( $attempt ) ) {
                    $attempt = str_replace( '@', '_' . $suffix . '@', $final_email );
                    $suffix++;
                }
                $final_email = $attempt;

                if ( function_exists( 'wc_create_new_customer' ) ) {
                    $username = wc_create_new_customer_username( $final_email );
                    $result   = wc_create_new_customer( $final_email, $username, wp_generate_password( 12, false ) );
                } else {
                    $result = wp_insert_user( array(
                        'user_login' => sanitize_user( $final_email, true ),
                        'user_email' => $final_email,
                        'user_pass'  => wp_generate_password( 12, false ),
                        'role'       => 'customer',
                    ) );
                }
                if ( is_wp_error( $result ) ) {
                    wp_send_json_error( array( 'message' => $result->get_error_message() ) );
                }
                $customer_id = (int) $result;
            } else {
                // Guest profile — no WP user created
                $is_guest = true;
            }

        } else {
            // Policy disallows creating users without email and placeholder is off
            $is_guest = true;
        }

        // --- Save metas if a WP user was created ---
        if ( $customer_id > 0 ) {
            $display_name = trim( $first_name . ' ' . $last_name );
            wp_update_user( array(
                'ID'           => $customer_id,
                'first_name'   => $first_name,
                'last_name'    => $last_name,
                'display_name' => $display_name,
                'role'         => 'customer',
            ) );

            update_user_meta( $customer_id, 'billing_first_name',    $first_name );
            update_user_meta( $customer_id, 'billing_last_name',     $last_name );
            update_user_meta( $customer_id, 'billing_phone',         $phone );
            update_user_meta( $customer_id, 'billing_email',         $final_email );
            update_user_meta( $customer_id, 'billing_address_1',     $address_1 );
            update_user_meta( $customer_id, 'billing_city',          $city );
            update_user_meta( $customer_id, 'billing_state',         $state );
            update_user_meta( $customer_id, 'billing_postcode',      $postcode );
            update_user_meta( $customer_id, '_wbi_whatsapp',         $phone );
            update_user_meta( $customer_id, '_wbi_customer_type',    $customer_type );
            update_user_meta( $customer_id, '_wbi_document_type',    $document_type );
            update_user_meta( $customer_id, '_wbi_document_number',  $document_number );
            update_user_meta( $customer_id, '_wbi_company_name',     $company_name );
            update_user_meta( $customer_id, '_wbi_pos_created_by',   $cashier_id );

            if ( 'wholesale' === $customer_type ) {
                update_user_meta( $customer_id, 'wholesale_customer', 'yes' );
            }
        }

        $display_name = trim( $first_name . ' ' . $last_name );

        wp_send_json_success( array(
            'customer_id'   => $customer_id,
            'is_guest'      => $is_guest,
            'name'          => $display_name,
            'email'         => $final_email,
            'phone'         => $phone,
            'customer_type' => $customer_type,
            'doc_number'    => $document_number,
            'guest_data'    => $is_guest ? array(
                'first_name'      => $first_name,
                'last_name'       => $last_name,
                'phone'           => $phone,
                'customer_type'   => $customer_type,
                'document_type'   => $document_type,
                'document_number' => $document_number,
                'company_name'    => $company_name,
                'address_1'       => $address_1,
                'city'            => $city,
                'state'           => $state,
                'postcode'        => $postcode,
                'created_by'      => $cashier_name,
                'created_at'      => $timestamp,
            ) : null,
            'log'           => sprintf( '[POS] Cliente creado por %s el %s', $cashier_name, $timestamp ),
        ) );
    }

    // =========================================================================
    // AJAX: CREATE ORDER
    // =========================================================================

    public function ajax_create_order() {
        check_ajax_referer( 'wbi_pos_nonce', 'nonce' );

        if ( ! $this->current_user_can_pos() ) {
            wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
        }

        $pos_settings = self::get_pos_settings();

        // Seller / operator / cash session
        $seller_user_id   = absint( $_POST['seller_user_id'] ?? 0 );
        $cash_session_id  = absint( $_POST['cash_session_id'] ?? 0 );
        $operator_user_id = get_current_user_id();
        $cashier_data     = get_userdata( $operator_user_id );
        $cashier_name     = $cashier_data ? $cashier_data->display_name : 'Cajero';

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

        // --- Customer ---
        $customer_id      = absint( $_POST['customer_id'] ?? 0 );
        $customer_type    = sanitize_text_field( wp_unslash( $_POST['customer_type'] ?? '' ) );
        $is_consumer_final = ! empty( $_POST['is_consumer_final'] );
        $is_guest          = ! empty( $_POST['is_guest'] );

        // Parse guest_data if provided
        $guest_data = null;
        if ( $is_guest && ! empty( $_POST['guest_data'] ) ) {
            $raw_gd = wp_unslash( $_POST['guest_data'] );
            if ( is_string( $raw_gd ) ) {
                $raw_gd = json_decode( $raw_gd, true );
            }
            if ( is_array( $raw_gd ) ) {
                $guest_data = array_map( 'sanitize_text_field', $raw_gd );
            }
        }

        // Enforce require_customer policy
        if ( ! empty( $pos_settings['pos_require_customer'] ) && ! $customer_id && ! $is_guest && ! $is_consumer_final ) {
            wp_send_json_error( array( 'message' => 'Seleccioná un cliente antes de confirmar el pedido.' ) );
        }

        // --- Parse adjustments ---
        $raw_adjustments = isset( $_POST['adjustments'] ) && is_array( $_POST['adjustments'] )
            ? $_POST['adjustments'] : array();

        $clean_adjustments = array();
        $has_discount      = false;

        if ( ! empty( $raw_adjustments ) ) {
            // Capability check for adjustments
            if ( ! current_user_can( 'wbi_pos_apply_adjustments' ) && ! current_user_can( 'manage_woocommerce' ) ) {
                wp_send_json_error( array( 'message' => 'No tenés permisos para aplicar ajustes al pedido.' ), 403 );
            }

            $allowed_types = array( 'discount', 'surcharge', 'shipping', 'manual_tax' );
            foreach ( $raw_adjustments as $adj ) {
                $type   = sanitize_text_field( wp_unslash( $adj['type']   ?? '' ) );
                $mode   = sanitize_text_field( wp_unslash( $adj['mode']   ?? 'fixed' ) );
                $value  = abs( (float) ( $adj['value'] ?? 0 ) );
                $reason = sanitize_text_field( wp_unslash( $adj['reason'] ?? '' ) );

                if ( ! in_array( $type, $allowed_types, true ) || $value <= 0 ) continue;
                if ( ! in_array( $mode, array( 'fixed', 'percent' ), true ) ) continue;

                // Check per-type settings
                if ( 'discount'   === $type && empty( $pos_settings['pos_enable_discount'] ) ) continue;
                if ( 'surcharge'  === $type && empty( $pos_settings['pos_enable_surcharge'] ) ) continue;
                if ( 'shipping'   === $type && empty( $pos_settings['pos_enable_shipping'] ) ) continue;
                if ( 'manual_tax' === $type && empty( $pos_settings['pos_enable_manual_tax'] ) ) continue;

                // Validate discount reason if required
                if ( 'discount' === $type && ! empty( $pos_settings['pos_require_discount_reason'] ) && ! $reason ) {
                    wp_send_json_error( array( 'message' => 'El motivo es obligatorio para descuentos.' ) );
                }

                if ( 'discount' === $type ) $has_discount = true;

                $clean_adjustments[] = array(
                    'type'   => $type,
                    'mode'   => $mode,
                    'value'  => $value,
                    'reason' => $reason,
                );
            }
        }

        $note = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );

        // --- Create WC_Order ---
        $order = wc_create_order( array(
            'customer_id' => $customer_id,
            'status'      => 'pending',
        ) );

        if ( is_wp_error( $order ) ) {
            wp_send_json_error( array( 'message' => $order->get_error_message() ) );
        }

        // Add items
        $order_subtotal = 0.0;
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

            $order_subtotal += $price * $qty;
        }

        // --- Apply adjustments as fee lines ---
        $adjustments_net  = 0.0;
        $adj_notes        = array();
        $running_total    = $order_subtotal; // track running total for percent calculations
        foreach ( $clean_adjustments as $adj ) {
            $type   = $adj['type'];
            $mode   = $adj['mode'];
            $value  = $adj['value'];
            $reason = $adj['reason'];

            // Calculate amount against the running total so each percent adjustment
            // is applied on the effective total after previous adjustments.
            $amount = 0.0;
            if ( 'percent' === $mode ) {
                $amount = round( max( 0.0, $running_total ) * $value / 100, 2 );
            } else {
                $amount = $value;
            }

            // Discounts reduce total; others add to it
            $is_reduction = ( 'discount' === $type );
            $fee_amount   = $is_reduction ? - $amount : $amount;
            $running_total += $fee_amount;

            $type_labels = array(
                'discount'   => 'Descuento',
                'surcharge'  => 'Recargo',
                'shipping'   => 'Envío manual',
                'manual_tax' => 'Impuesto manual',
            );
            $label = ( $type_labels[ $type ] ?? $type );
            if ( $reason ) $label .= ' (' . $reason . ')';

            $fee = new WC_Order_Item_Fee();
            $fee->set_name( $label );
            $fee->set_amount( $fee_amount );
            $fee->set_total( $fee_amount );
            $fee->set_tax_status( 'none' );
            $order->add_item( $fee );

            $adjustments_net += $fee_amount;

            // Prepare audit note
            $mode_label = ( 'percent' === $mode )
                ? number_format( $value, 2 ) . '%'
                : wc_price( $value );
            $note_text = sprintf(
                '[POS] %s %s (%s) aplicado por %s',
                $label,
                $mode_label,
                wc_price( abs( $fee_amount ) ),
                $cashier_name
            );
            if ( $reason ) {
                $note_text .= ' — Motivo: ' . $reason;
            }
            $adj_notes[] = $note_text;
        }

        $order_total = max( 0.0, $order_subtotal + $adjustments_net );
        $order->set_total( $order_total );

        // Set billing from WP customer if available
        if ( $customer_id ) {
            $wc_customer = new WC_Customer( $customer_id );
            $order->set_billing_first_name( $wc_customer->get_billing_first_name() );
            $order->set_billing_last_name( $wc_customer->get_billing_last_name() );
            $order->set_billing_email( $wc_customer->get_billing_email() );
            $order->set_billing_phone( $wc_customer->get_billing_phone() );
            $order->set_billing_address_1( $wc_customer->get_billing_address_1() );
            $order->set_billing_city( $wc_customer->get_billing_city() );
            $order->set_billing_state( $wc_customer->get_billing_state() );
            $order->set_billing_postcode( $wc_customer->get_billing_postcode() );
            $order->set_billing_country( $wc_customer->get_billing_country() );
        }

        // Set billing from guest_data if guest
        if ( $is_guest && ! empty( $guest_data ) ) {
            $order->set_billing_first_name( $guest_data['first_name'] ?? '' );
            $order->set_billing_last_name( $guest_data['last_name']  ?? '' );
            $order->set_billing_phone(     $guest_data['phone']       ?? '' );
            $order->set_billing_address_1( $guest_data['address_1']  ?? '' );
            $order->set_billing_city(      $guest_data['city']        ?? '' );
            $order->set_billing_state(     $guest_data['state']       ?? '' );
            $order->set_billing_postcode(  $guest_data['postcode']    ?? '' );
        }

        // Note
        if ( $note ) {
            $order->add_order_note( $note, 0, false );
        }

        // Add per-adjustment audit notes
        foreach ( $adj_notes as $adj_note ) {
            $order->add_order_note( $adj_note, 0, false );
        }

        // Cashier assignment note
        $order->add_order_note(
            sprintf( '[POS] Pedido creado por %s el %s', $cashier_name, current_time( 'mysql' ) ),
            0,
            false
        );

        if ( $customer_id && $customer_type ) {
            $order->add_order_note(
                sprintf( '[POS] Cliente #%d (%s) asignado por %s', $customer_id, $customer_type, $cashier_name ),
                0,
                false
            );
        }

        if ( $is_consumer_final ) {
            $order->add_order_note( '[POS] Consumidor Final', 0, false );
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
        $order->update_meta_data( '_wbi_pos_created_by',          $operator_user_id );
        $order->update_meta_data( '_wbi_pos_operator_user_id',    $operator_user_id );
        $order->update_meta_data( '_wbi_pos_seller_user_id',      $seller_user_id > 0 ? $seller_user_id : $operator_user_id );
        $order->update_meta_data( '_wbi_pos_cash_session_id',     $cash_session_id );
        $order->update_meta_data( '_wbi_pos_payments',            wp_json_encode( $clean_payments ) );
        $order->update_meta_data( '_wbi_pos_paid_total',          $paid_total );
        $order->update_meta_data( '_wbi_pos_balance_due',         $balance_due );
        $order->update_meta_data( '_wbi_pos_invoice_status',      'pending' );
        $order->update_meta_data( '_wbi_pos_customer_type',       $customer_type );
        $order->update_meta_data( '_wbi_pos_is_consumer_final',   $is_consumer_final ? 1 : 0 );

        if ( $is_guest && ! empty( $guest_data ) ) {
            $order->update_meta_data( '_wbi_pos_guest_first_name',      $guest_data['first_name']      ?? '' );
            $order->update_meta_data( '_wbi_pos_guest_last_name',       $guest_data['last_name']       ?? '' );
            $order->update_meta_data( '_wbi_pos_guest_phone',           $guest_data['phone']           ?? '' );
            $order->update_meta_data( '_wbi_pos_guest_customer_type',   $guest_data['customer_type']   ?? '' );
            $order->update_meta_data( '_wbi_pos_guest_document_type',   $guest_data['document_type']   ?? '' );
            $order->update_meta_data( '_wbi_pos_guest_document_number', $guest_data['document_number'] ?? '' );
            $order->update_meta_data( '_wbi_pos_guest_company_name',    $guest_data['company_name']    ?? '' );
            $order->update_meta_data( '_wbi_pos_guest_created_by',      $cashier_name );
        }

        if ( ! empty( $clean_adjustments ) ) {
            $order->update_meta_data( '_wbi_pos_adjustments', wp_json_encode( $clean_adjustments ) );
        }

        $order->save();

        $order_id  = $order->get_id();
        $order_url = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
        if ( function_exists( 'wc_get_order_edit_link' ) ) {
            $order_url = wc_get_order_edit_link( $order_id );
        }

        // ── Record sale_income movements for each payment method ──────────────
        if ( $cash_session_id > 0 && $paid_total > 0 ) {
            foreach ( $clean_payments as $payment ) {
                WBI_POS_Cash_Movements::add_movement(
                    $cash_session_id,
                    WBI_POS_Cash_Movements::TYPE_SALE_INCOME,
                    $payment['method'],
                    $payment['amount'],
                    (string) $order_id,
                    '',
                    $operator_user_id
                );
            }
        }

        wp_send_json_success( array(
            'order_id'    => $order_id,
            'order_url'   => $order_url,
            'subtotal'    => $order_subtotal,
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

        $summary       = WBI_POS_Cash_Sessions::get_session_summary( $session_id );
        $mov_totals    = WBI_POS_Cash_Movements::get_session_totals( $session_id, $session->opening_cash );
        $expected_cash = $mov_totals['expected_cash'];
        $diff          = round( $closing_cash - $expected_cash, 2 );

        $ok = WBI_POS_Cash_Sessions::close_session( $session_id, $closing_cash, $note, $expected_cash );
        if ( ! $ok ) {
            wp_send_json_error( array( 'message' => 'Error al cerrar la caja.' ) );
        }

        wp_send_json_success( array(
            'summary'       => $summary,
            'mov_totals'    => $mov_totals,
            'opening_cash'  => (float) $session->opening_cash,
            'closing_cash'  => $closing_cash,
            'expected_cash' => $expected_cash,
            'difference'    => $diff,
            'opened_at'     => $session->opened_at,
            'closed_at'     => current_time( 'mysql' ),
        ) );
    }

    // =========================================================================
    // AJAX: ADD MANUAL MOVEMENT
    // =========================================================================

    public function ajax_add_movement() {
        check_ajax_referer( 'wbi_pos_nonce', 'nonce' );

        if ( ! $this->current_user_can_pos() ) {
            wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
        }

        $session_id = absint( $_POST['session_id'] ?? 0 );
        $type       = sanitize_text_field( wp_unslash( $_POST['type']      ?? '' ) );
        $method     = sanitize_text_field( wp_unslash( $_POST['method']    ?? 'cash' ) );
        $amount     = (float) ( $_POST['amount'] ?? 0 );
        $reference  = sanitize_text_field( wp_unslash( $_POST['reference'] ?? '' ) );
        $notes      = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );

        if ( ! $session_id ) {
            wp_send_json_error( array( 'message' => 'ID de sesión inválido.' ) );
        }

        if ( $amount <= 0 ) {
            wp_send_json_error( array( 'message' => 'El monto debe ser mayor a cero.' ) );
        }

        $allowed_types = array(
            WBI_POS_Cash_Movements::TYPE_MANUAL_INCOME,
            WBI_POS_Cash_Movements::TYPE_MANUAL_EXPENSE,
            WBI_POS_Cash_Movements::TYPE_WITHDRAWAL,
            WBI_POS_Cash_Movements::TYPE_DEPOSIT,
        );
        if ( ! in_array( $type, $allowed_types, true ) ) {
            wp_send_json_error( array( 'message' => 'Tipo de movimiento inválido.' ) );
        }

        $session = WBI_POS_Cash_Sessions::get_session( $session_id );
        if ( ! $session || 'open' !== $session->status ) {
            wp_send_json_error( array( 'message' => 'La sesión no existe o no está abierta.' ) );
        }

        // Only seller, operator or admin can add movements to this session
        $current_user_id = get_current_user_id();
        if (
            (int) $session->seller_user_id !== $current_user_id &&
            (int) $session->operator_user_id !== $current_user_id &&
            ! current_user_can( 'manage_woocommerce' )
        ) {
            wp_send_json_error( array( 'message' => 'No tenés permisos para registrar movimientos en esta caja.' ) );
        }

        $movement_id = WBI_POS_Cash_Movements::add_movement(
            $session_id,
            $type,
            $method,
            $amount,
            $reference,
            $notes,
            $current_user_id
        );

        if ( ! $movement_id ) {
            wp_send_json_error( array( 'message' => 'Error al registrar el movimiento.' ) );
        }

        wp_send_json_success( array(
            'movement_id' => $movement_id,
            'type'        => $type,
            'method'      => $method,
            'amount'      => $amount,
        ) );
    }

    // =========================================================================
    // AJAX: GET MOVEMENTS
    // =========================================================================

    public function ajax_get_movements() {
        check_ajax_referer( 'wbi_pos_nonce', 'nonce' );

        if ( ! $this->current_user_can_pos() ) {
            wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
        }

        $session_id = absint( $_GET['session_id'] ?? 0 );
        if ( ! $session_id ) {
            wp_send_json_error( array( 'message' => 'ID de sesión inválido.' ) );
        }

        $session = WBI_POS_Cash_Sessions::get_session( $session_id );
        if ( ! $session ) {
            wp_send_json_error( array( 'message' => 'Sesión no encontrada.' ) );
        }

        // Permission check
        $current_user_id = get_current_user_id();
        if (
            (int) $session->seller_user_id !== $current_user_id &&
            (int) $session->operator_user_id !== $current_user_id &&
            ! current_user_can( 'manage_woocommerce' )
        ) {
            wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
        }

        $movements = WBI_POS_Cash_Movements::get_movements( $session_id );
        $totals    = WBI_POS_Cash_Movements::get_session_totals( $session_id, $session->opening_cash );

        wp_send_json_success( array(
            'movements' => $movements,
            'totals'    => $totals,
        ) );
    }
}
