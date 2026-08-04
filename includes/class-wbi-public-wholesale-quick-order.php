<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Public_Wholesale_Quick_Order_Module {

    const ASSET_VERSION = '1.0.0';

    public function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'woocommerce_after_shop_loop_item', array( $this, 'render_quick_order_ui' ), 15 );
        add_action( 'wp_footer', array( $this, 'render_feedback_shell' ) );
        add_action( 'wp_ajax_wbi_public_quick_order_add', array( $this, 'ajax_add_to_cart' ) );
        add_action( 'wp_ajax_nopriv_wbi_public_quick_order_add', array( $this, 'ajax_add_to_cart' ) );
    }

    public function enqueue_assets() {
        if ( is_admin() || ! function_exists( 'is_shop' ) || ! $this->should_load_assets() ) {
            return;
        }

        $plugin_root_url = trailingslashit( plugins_url( '/', dirname( __FILE__ ) ) );

        wp_enqueue_style(
            'wbi-public-wholesale-quick-order',
            $plugin_root_url . 'assets/css/wbi-public-wholesale-quick-order.css',
            array(),
            self::ASSET_VERSION
        );

        wp_enqueue_script(
            'wbi-public-wholesale-quick-order',
            $plugin_root_url . 'assets/js/wbi-public-wholesale-quick-order.js',
            array(),
            self::ASSET_VERSION,
            true
        );

        wp_localize_script(
            'wbi-public-wholesale-quick-order',
            'WBIPublicQuickOrder',
            array(
                'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
                'nonce'          => wp_create_nonce( 'wbi_public_quick_order' ),
                'currencySymbol' => get_woocommerce_currency_symbol(),
                'i18n'           => array(
                    'adding'            => 'Agregando…',
                    'defaultButton'     => 'Agregar al pedido',
                    'errorGeneric'      => 'No pudimos agregar este producto. Intentá nuevamente.',
                    'successSingle'     => 'Agregaste %1$s unidad de %2$s.',
                    'successPlural'     => 'Agregaste %1$s unidades de %2$s.',
                    'counterSingular'   => '%1$s producto · %2$s unidad',
                    'counterPlural'     => '%1$s productos · %2$s unidades',
                    'selectVariation'   => 'Elegí una variante para continuar.',
                ),
            )
        );
    }

    private function should_load_assets() {
        return is_shop() || is_product_taxonomy() || is_product_category() || is_product_tag();
    }

    public function render_quick_order_ui() {
        global $product;

        if ( ! $product instanceof WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
            return;
        }

        $product_data = $this->build_product_data( $product );
        if ( empty( $product_data['variants'] ) ) {
            return;
        }

        $default_variant = reset( $product_data['variants'] );
        ?>
        <div class="wbi-public-quick-order" data-product="<?php echo esc_attr( wp_json_encode( $product_data ) ); ?>">
            <div class="wbi-public-quick-order__row">
                <?php if ( ! empty( $product_data['has_variations'] ) ) : ?>
                    <label class="screen-reader-text" for="wbi-quick-order-variant-<?php echo esc_attr( $product->get_id() ); ?>">Elegí una variante</label>
                    <select id="wbi-quick-order-variant-<?php echo esc_attr( $product->get_id() ); ?>" class="wbi-public-quick-order__variant">
                        <?php foreach ( $product_data['variants'] as $variant ) : ?>
                            <option value="<?php echo esc_attr( $variant['id'] ); ?>"><?php echo esc_html( $variant['label'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else : ?>
                    <div class="wbi-public-quick-order__variant wbi-public-quick-order__variant--static">
                        <?php echo esc_html( $default_variant['label'] ); ?>
                    </div>
                <?php endif; ?>

                <label class="screen-reader-text" for="wbi-quick-order-qty-<?php echo esc_attr( $product->get_id() ); ?>">Cantidad</label>
                <input
                    id="wbi-quick-order-qty-<?php echo esc_attr( $product->get_id() ); ?>"
                    class="wbi-public-quick-order__qty"
                    type="number"
                    min="<?php echo esc_attr( $default_variant['min_qty'] ); ?>"
                    step="<?php echo esc_attr( $default_variant['step_qty'] ); ?>"
                    value="<?php echo esc_attr( $default_variant['default_qty'] ); ?>"
                    inputmode="numeric"
                />

                <button type="button" class="button alt wbi-public-quick-order__button">
                    Agregar al pedido
                </button>
            </div>

            <div class="wbi-public-quick-order__meta">
                <span class="wbi-public-quick-order__rules">
                    <?php echo esc_html( $this->build_rules_message( $default_variant ) ); ?>
                </span>
                <span class="wbi-public-quick-order__status" aria-live="polite"></span>
            </div>
        </div>
        <?php
    }

    public function render_feedback_shell() {
        if ( is_admin() || ! $this->should_load_assets() ) {
            return;
        }

        $summary = $this->get_cart_summary();
        ?>
        <div class="wbi-public-quick-order-toast" hidden></div>
        <div class="wbi-public-quick-order-summary" data-items="<?php echo esc_attr( $summary['items'] ); ?>" data-units="<?php echo esc_attr( $summary['units'] ); ?>">
            <?php echo esc_html( $summary['label'] ); ?>
        </div>
        <?php
    }

    private function build_product_data( WC_Product $product ) {
        $variants = array();

        if ( $product->is_type( 'variable' ) ) {
            foreach ( $product->get_available_variations() as $variation_data ) {
                if ( empty( $variation_data['variation_id'] ) || empty( $variation_data['is_purchasable'] ) ) {
                    continue;
                }

                $variation = wc_get_product( $variation_data['variation_id'] );
                if ( ! $variation instanceof WC_Product_Variation || ! $variation->is_in_stock() ) {
                    continue;
                }

                $rules      = $this->get_purchase_rules( $variation, $product );
                $attributes = wc_get_formatted_variation( $variation, true, false, true );
                $variants[] = array(
                    'id'          => $variation->get_id(),
                    'label'       => wp_strip_all_tags( $attributes ? $attributes : $variation->get_name() ),
                    'min_qty'     => $rules['min_qty'],
                    'step_qty'    => $rules['step_qty'],
                    'default_qty' => $rules['default_qty'],
                    'rule_text'   => $this->build_rules_message( $rules ),
                );
            }
        } else {
            $rules      = $this->get_purchase_rules( $product );
            $variants[] = array(
                'id'          => 0,
                'label'       => 'Presentación disponible',
                'min_qty'     => $rules['min_qty'],
                'step_qty'    => $rules['step_qty'],
                'default_qty' => $rules['default_qty'],
                'rule_text'   => $this->build_rules_message( $rules ),
            );
        }

        return array(
            'product_id'      => $product->get_id(),
            'product_name'    => $product->get_name(),
            'has_variations'  => $product->is_type( 'variable' ),
            'variants'        => array_values( $variants ),
        );
    }

    private function get_purchase_rules( WC_Product $product, WC_Product $parent_product = null ) {
        $min_qty  = $this->read_numeric_meta( $product, array( '_wbi_min_qty', '_wbi_wholesale_min_qty', '_min_qty', 'wbi_min_qty' ) );
        $step_qty = $this->read_numeric_meta( $product, array( '_wbi_pack_multiple', '_wbi_qty_multiple', '_qty_step', '_quantity_step', 'wbi_pack_multiple' ) );

        if ( $parent_product instanceof WC_Product ) {
            if ( $min_qty <= 0 ) {
                $min_qty = $this->read_numeric_meta( $parent_product, array( '_wbi_min_qty', '_wbi_wholesale_min_qty', '_min_qty', 'wbi_min_qty' ) );
            }
            if ( $step_qty <= 0 ) {
                $step_qty = $this->read_numeric_meta( $parent_product, array( '_wbi_pack_multiple', '_wbi_qty_multiple', '_qty_step', '_quantity_step', 'wbi_pack_multiple' ) );
            }
        }

        $min_qty  = max( 1, (int) $min_qty );
        $step_qty = max( 1, (int) $step_qty );

        if ( $min_qty > 1 && 0 !== $min_qty % $step_qty ) {
            $default_qty = $min_qty;
        } else {
            $default_qty = max( $min_qty, $step_qty );
        }

        return array(
            'min_qty'     => $min_qty,
            'step_qty'    => $step_qty,
            'default_qty' => $default_qty,
        );
    }

    private function read_numeric_meta( WC_Product $product, array $keys ) {
        foreach ( $keys as $key ) {
            $value = get_post_meta( $product->get_id(), $key, true );
            if ( '' !== $value && false !== $value && is_numeric( $value ) ) {
                return (int) $value;
            }
        }

        return 0;
    }

    private function build_rules_message( array $rules ) {
        $messages = array();

        if ( ! empty( $rules['min_qty'] ) && $rules['min_qty'] > 1 ) {
            $messages[] = sprintf( 'Mínimo: %d unidades', (int) $rules['min_qty'] );
        }

        if ( ! empty( $rules['step_qty'] ) && $rules['step_qty'] > 1 ) {
            $messages[] = sprintf( 'Múltiplos de %d', (int) $rules['step_qty'] );
        }

        return ! empty( $messages ) ? implode( ' · ', $messages ) : 'Elegí la cantidad y agregá al pedido.';
    }

    public function ajax_add_to_cart() {
        check_ajax_referer( 'wbi_public_quick_order', 'nonce' );

        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            wp_send_json_error( array( 'message' => 'El carrito no está disponible en este momento.' ), 400 );
        }

        $product_id   = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
        $variation_id = isset( $_POST['variation_id'] ) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0;
        $quantity     = isset( $_POST['quantity'] ) ? (int) wp_unslash( $_POST['quantity'] ) : 0;

        $product = $variation_id ? wc_get_product( $variation_id ) : wc_get_product( $product_id );
        if ( ! $product instanceof WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
            wp_send_json_error( array( 'message' => 'Este producto no está disponible para agregar al pedido.' ), 400 );
        }

        $parent_product = ( $product instanceof WC_Product_Variation ) ? wc_get_product( $product->get_parent_id() ) : null;
        $rules          = $this->get_purchase_rules( $product, $parent_product );
        $validation     = $this->validate_quantity( $quantity, $rules );

        if ( is_wp_error( $validation ) ) {
            wp_send_json_error( array( 'message' => $validation->get_error_message() ), 400 );
        }

        $variation = array();
        if ( $product instanceof WC_Product_Variation ) {
            $product_id   = $product->get_parent_id();
            $variation_id = $product->get_id();
            $variation    = $product->get_variation_attributes();
        }

        $added = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation );
        if ( ! $added ) {
            wp_send_json_error( array( 'message' => 'No pudimos agregar este producto al pedido.' ), 400 );
        }

        $summary      = $this->get_cart_summary();
        $product_name = $product instanceof WC_Product_Variation && $parent_product instanceof WC_Product
            ? $parent_product->get_name()
            : $product->get_name();

        wp_send_json_success(
            array(
                'message' => sprintf(
                    _n( 'Agregaste %1$s unidad de %2$s.', 'Agregaste %1$s unidades de %2$s.', $quantity, 'wbi-suite' ),
                    $quantity,
                    $product_name
                ),
                'summary' => $summary,
            )
        );
    }

    private function validate_quantity( $quantity, array $rules ) {
        if ( $quantity <= 0 ) {
            return new WP_Error( 'invalid_quantity', 'Ingresá una cantidad válida para continuar.' );
        }

        if ( $quantity < $rules['min_qty'] ) {
            return new WP_Error(
                'minimum_quantity',
                sprintf(
                    'Te faltan %1$d para completar el mínimo. Mínimo: %2$d unidades.',
                    $rules['min_qty'] - $quantity,
                    $rules['min_qty']
                )
            );
        }

        if ( $rules['step_qty'] > 1 && 0 !== $quantity % $rules['step_qty'] ) {
            return new WP_Error(
                'pack_multiple',
                sprintf(
                    'Elegí múltiplos de %1$d unidades para este producto.',
                    $rules['step_qty']
                )
            );
        }

        return true;
    }

    private function get_cart_summary() {
        $items = 0;
        $units = 0;

        if ( function_exists( 'WC' ) && WC()->cart ) {
            $items = count( WC()->cart->get_cart() );
            foreach ( WC()->cart->get_cart() as $cart_item ) {
                $units += isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;
            }
        }

        return array(
            'items' => $items,
            'units' => $units,
            'label' => sprintf(
                '%1$s %2$s · %3$s %4$s',
                $items,
                1 === $items ? 'producto' : 'productos',
                $units,
                1 === $units ? 'unidad' : 'unidades'
            ),
        );
    }
}
