<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI Public Wholesale Quick Order Module
 *
 * Settings (stored in wbi_modules_settings):
 *   wbi_pwoq_variant_selector_mode        string  'inline' | 'modal'
 *   wbi_pwoq_show_sku                     bool
 *   wbi_pwoq_show_dimensions              bool
 *   wbi_pwoq_show_color_count             bool
 *   wbi_pwoq_enforce_min_qty              bool
 *   wbi_pwoq_enforce_pack_multiples       bool
 *   wbi_pwoq_global_add_enabled           bool
 *   wbi_pwoq_initial_qty_zero             bool
 *   wbi_pwoq_hide_native_add_to_cart      bool
 *   wbi_pwoq_force_reload_on_fragment_fail bool
 */
class WBI_Public_Wholesale_Quick_Order_Module {

    const ASSET_VERSION = '2.1.0';

    /** @var array Cached module settings */
    private $settings;

    public function __construct() {
        $this->settings = $this->load_settings();

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_quantity_sync_js' ), 20 );
        add_action( 'wp_footer', array( $this, 'render_feedback_shell' ) );

        add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'render_loop_add_to_cart_form' ), 10, 3 );
        add_filter( 'woocommerce_product_single_add_to_cart_text', array( $this, 'maybe_change_single_add_to_cart_text' ), 10, 2 );
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_quick_order_add_to_cart' ), 10, 6 );
    }

    private function load_settings() {
        $opts = get_option( 'wbi_modules_settings', array() );

        return array(
            'variant_selector_mode'        => isset( $opts['wbi_pwoq_variant_selector_mode'] ) ? $opts['wbi_pwoq_variant_selector_mode'] : 'modal',
            'show_sku'                     => ! empty( $opts['wbi_pwoq_show_sku'] ),
            'show_dimensions'              => ! empty( $opts['wbi_pwoq_show_dimensions'] ),
            'show_color_count'             => ! empty( $opts['wbi_pwoq_show_color_count'] ),
            'enforce_min_qty'              => isset( $opts['wbi_pwoq_enforce_min_qty'] ) ? (bool) $opts['wbi_pwoq_enforce_min_qty'] : true,
            'enforce_pack_multiples'       => isset( $opts['wbi_pwoq_enforce_pack_multiples'] ) ? (bool) $opts['wbi_pwoq_enforce_pack_multiples'] : true,
            'global_add_enabled'           => ! empty( $opts['wbi_pwoq_global_add_enabled'] ),
            'initial_qty_zero'             => ! empty( $opts['wbi_pwoq_initial_qty_zero'] ),
            'hide_native_add_to_cart'      => ! empty( $opts['wbi_pwoq_hide_native_add_to_cart'] ),
            'force_reload_on_fragment_fail'=> ! empty( $opts['wbi_pwoq_force_reload_on_fragment_fail'] ),
        );
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
            array( 'jquery', 'wc-add-to-cart' ),
            self::ASSET_VERSION,
            true
        );

        wp_localize_script(
            'wbi-public-wholesale-quick-order',
            'WBIPublicQuickOrder',
            array(
                'nonce'                     => wp_create_nonce( 'wbi_public_quick_order' ),
                'variantSelectorMode'       => $this->settings['variant_selector_mode'],
                'globalAddEnabled'          => $this->settings['global_add_enabled'],
                'initialQtyZero'            => $this->settings['initial_qty_zero'],
                'forceReloadOnFragmentFail' => $this->settings['force_reload_on_fragment_fail'],
                'i18n'                      => array(
                    'adding'          => 'Agregando…',
                    'addLabel'        => 'AGREGAR',
                    'errorGeneric'    => 'No pudimos agregar este producto. Intentá nuevamente.',
                    'selectVariation' => 'Seleccioná una opción',
                    'globalAdd'       => 'AGREGAR SELECCIONADOS AL CARRITO',
                    'globalEmpty'     => 'Seleccioná cantidades para agregar al carrito.',
                    'globalSkipped'   => 'Se omitieron %d productos sin variante válida.',
                    'globalSuccess'   => 'Se agregaron %1$d productos por %2$d unidades.',
                    'counterSingular' => '%1$d producto · %2$d unidad',
                    'counterPlural'   => '%1$d productos · %2$d unidades',
                    'selectedDetail'  => '%1$s · +%2$d más',
                    'qtyPositive'     => 'Ingresá una cantidad mayor a 0 para continuar.',
                    'minQty'          => 'Mínimo: %d unidades',
                    'missingMin'      => 'Te faltan %d para completar el mínimo. Mínimo: %d unidades.',
                    'packMultiple'    => 'Elegí múltiplos de %d unidades para este producto.',
                ),
            )
        );
    }

    private function should_load_assets() {
        return is_shop() || is_product_taxonomy() || is_product_category() || is_product_tag();
    }

    public function render_loop_add_to_cart_form( $html, $product, $args ) {
        if ( ! $this->should_load_assets() || ! $product instanceof WC_Product ) {
            return $html;
        }

        if ( ! $product->is_purchasable() || ! $product->is_in_stock() || $product->is_sold_individually() ) {
            return $html;
        }

        $product_data = $this->build_product_data( $product );
        if ( empty( $product_data['variants'] ) ) {
            return $html;
        }

        $default_variant = $this->get_default_variant_for_ui( $product_data );
        $rules_text      = $this->build_rules_message( $default_variant );
        $initial_value   = $this->get_initial_quantity_value( $default_variant );
        $min_value       = $this->settings['initial_qty_zero'] ? 0 : (int) $default_variant['min_qty'];

        $qty_html = woocommerce_quantity_input(
            array(
                'input_name'   => 'quantity',
                'input_value'  => $initial_value,
                'min_value'    => $min_value,
                'max_value'    => $product->get_max_purchase_quantity(),
                'step'         => max( 1, (int) $default_variant['step_qty'] ),
                'classes'      => array( 'input-text', 'qty', 'text', 'wbi-pwoq__qty' ),
            ),
            $product,
            false
        );

        $button_classes = array( 'button', 'alt', 'add_to_cart_button', 'wbi-pwoq__submit', 'wbi-pwoq-add-btn' );
        if ( $product->is_type( 'simple' ) ) {
            $button_classes[] = 'ajax_add_to_cart';
            $button_classes[] = 'product_type_simple';
        } else {
            $button_classes[] = 'product_type_variable';
        }

        $button_html = sprintf(
            '<button type="submit" class="%1$s" data-product_id="%2$d" data-product_sku="%3$s" data-quantity="%4$d" aria-label="%5$s">%6$s</button>',
            esc_attr( implode( ' ', $button_classes ) ),
            (int) $product->get_id(),
            esc_attr( $product->get_sku() ),
            (int) max( 0, $initial_value ),
            esc_attr( sprintf( __( 'Agregar %s al carrito', 'woocommerce' ), $product->get_name() ) ),
            esc_html__( 'AGREGAR', 'woocommerce' )
        );

        ob_start();
        ?>
        <div class="wbi-pwoq wbi-pwoq-card" data-product="<?php echo esc_attr( wp_json_encode( $product_data ) ); ?>" data-mode="<?php echo esc_attr( $this->settings['variant_selector_mode'] ); ?>">
            <?php $meta_parts = $this->build_meta_parts( $product ); ?>
            <?php if ( ! empty( $meta_parts ) ) : ?>
                <div class="wbi-pwoq__meta-line"><?php echo esc_html( implode( ' · ', $meta_parts ) ); ?></div>
            <?php endif; ?>

            <?php if ( ! empty( $product_data['has_variations'] ) ) : ?>
                <?php if ( 'inline' === $this->settings['variant_selector_mode'] ) : ?>
                    <div class="wbi-pwoq__selectors wbi-pwoq__selectors--chips">
                        <?php echo $this->render_attribute_chips( $product_data ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    </div>
                <?php else : ?>
                    <div class="wbi-pwoq__selectors wbi-pwoq__selectors--dropdowns">
                        <?php echo $this->render_attribute_dropdowns( $product_data ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <form class="cart wbi-pwoq-loop-cart" method="post" enctype="multipart/form-data" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
                <input type="hidden" name="add-to-cart" value="<?php echo esc_attr( $product->get_id() ); ?>" />
                <input type="hidden" name="product_id" value="<?php echo esc_attr( $product->get_id() ); ?>" />
                <input type="hidden" name="variation_id" value="0" class="wbi-pwoq__variation-id" />
                <input type="hidden" name="wbi_pwoq_request" value="1" />
                <?php if ( ! empty( $product_data['attributes'] ) ) : ?>
                    <?php foreach ( array_keys( $product_data['attributes'] ) as $attribute_key ) : ?>
                        <input type="hidden" name="<?php echo esc_attr( 'attribute_' . $attribute_key ); ?>" value="" class="wbi-pwoq__attr-hidden" data-attr="<?php echo esc_attr( $attribute_key ); ?>" />
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="wbi-pwoq__action-row wbi-pwoq-action-row">
                    <?php echo $qty_html; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <?php echo $button_html; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </div>
            </form>

            <div class="wbi-pwoq__rules-line" aria-live="polite"><?php echo esc_html( $rules_text ); ?></div>
            <div class="wbi-pwoq__status" aria-live="polite"></div>
        </div>
        <?php

        $quick_order_html = trim( ob_get_clean() );

        if ( $this->settings['hide_native_add_to_cart'] ) {
            return $quick_order_html;
        }

        return '<div class="wbi-pwoq-native-loop">' . $html . '</div>' . $quick_order_html;
    }

    private function render_attribute_chips( array $product_data ) {
        if ( empty( $product_data['attributes'] ) ) {
            return '';
        }

        $out = '';
        foreach ( $product_data['attributes'] as $attr_name => $attr_values ) {
            $label = ucfirst( str_replace( array( 'pa_', '-', '_' ), array( '', ' ', ' ' ), $attr_name ) );
            $out  .= '<div class="wbi-pwoq__attr-group" data-attr="' . esc_attr( $attr_name ) . '">';
            $out  .= '<span class="wbi-pwoq__attr-label">' . esc_html( $label ) . '</span>';
            $out  .= '<div class="wbi-pwoq__attr-chips">';
            foreach ( $attr_values as $attr_value ) {
                $chip_label = is_array( $attr_value ) && isset( $attr_value['value'] ) ? $attr_value['value'] : $attr_value;
                $chip_slug  = is_array( $attr_value ) && isset( $attr_value['slug'] ) ? $attr_value['slug'] : $chip_label;
                $out       .= '<button type="button" class="wbi-pwoq__chip" data-value="' . esc_attr( $chip_label ) . '" data-slug="' . esc_attr( $chip_slug ) . '">' . esc_html( $chip_label ) . '</button>';
            }
            $out .= '</div>';
            $out .= '</div>';
        }

        return $out;
    }

    private function render_attribute_dropdowns( array $product_data ) {
        if ( empty( $product_data['attributes'] ) ) {
            return '';
        }

        $out = '';
        foreach ( $product_data['attributes'] as $attr_name => $attr_values ) {
            $label = ucfirst( str_replace( array( 'pa_', '-', '_' ), array( '', ' ', ' ' ), $attr_name ) );
            $out  .= '<label class="wbi-pwoq__select-wrap" data-attr="' . esc_attr( $attr_name ) . '">';
            $out  .= '<span class="wbi-pwoq__attr-label">' . esc_html( $label ) . '</span>';
            $out  .= '<select class="wbi-pwoq__select" data-attr="' . esc_attr( $attr_name ) . '">';
            $out  .= '<option value="">' . esc_html__( 'Seleccioná una opción', 'wbi-suite' ) . '</option>';
            foreach ( $attr_values as $attr_value ) {
                $option_label = is_array( $attr_value ) && isset( $attr_value['value'] ) ? $attr_value['value'] : $attr_value;
                $option_slug  = is_array( $attr_value ) && isset( $attr_value['slug'] ) ? $attr_value['slug'] : $option_label;
                $out .= '<option value="' . esc_attr( $option_slug ) . '" data-label="' . esc_attr( $option_label ) . '">' . esc_html( $option_label ) . '</option>';
            }
            $out .= '</select>';
            $out .= '</label>';
        }

        return $out;
    }

    public function render_feedback_shell() {
        if ( is_admin() || ! $this->should_load_assets() ) {
            return;
        }

        ?>
        <div class="wbi-pwoq-toast" hidden></div>
        <?php if ( $this->settings['global_add_enabled'] ) : ?>
            <div class="wbi-pwoq-global-bar" hidden aria-live="polite">
                <div class="wbi-pwoq-global-bar__inner">
                    <div class="wbi-pwoq-global-bar__content">
                        <div class="wbi-pwoq-global-bar__summary" aria-atomic="true">0 productos · 0 unidades</div>
                        <div class="wbi-pwoq-global-bar__detail" aria-atomic="true"></div>
                    </div>
                    <button type="button" class="button alt wbi-pwoq-global-bar__button" disabled>
                        AGREGAR SELECCIONADOS AL CARRITO
                    </button>
                </div>
            </div>
        <?php endif; ?>
        <?php
    }

    private function build_product_data( WC_Product $product ) {
        $variants   = array();
        $attributes = array();

        if ( $product->is_type( 'variable' ) ) {
            foreach ( $product->get_available_variations() as $variation_data ) {
                if ( empty( $variation_data['variation_id'] ) ) {
                    continue;
                }

                $variation = wc_get_product( $variation_data['variation_id'] );
                if ( ! $variation instanceof WC_Product_Variation || ! $variation->is_purchasable() ) {
                    continue;
                }

                $in_stock  = $variation->is_in_stock();
                $rules     = $this->get_purchase_rules( $variation, $product );
                $attr_data = $variation->get_variation_attributes();
                $attr_str  = wc_get_formatted_variation( $variation, true, false, true );

                foreach ( $attr_data as $attr_key => $attr_value ) {
                    if ( '' === $attr_value ) {
                        continue;
                    }

                    $clean_key = str_replace( 'attribute_', '', $attr_key );
                    if ( ! isset( $attributes[ $clean_key ] ) ) {
                        $attributes[ $clean_key ] = array();
                    }

                    $term          = get_term_by( 'slug', $attr_value, $clean_key );
                    $display_value = ( $term && ! is_wp_error( $term ) ) ? $term->name : $attr_value;
                    $chip_value    = array(
                        'value' => $display_value,
                        'slug'  => $attr_value,
                    );

                    if ( ! in_array( $chip_value, $attributes[ $clean_key ], true ) ) {
                        $attributes[ $clean_key ][] = $chip_value;
                    }
                }

                $variants[] = array(
                    'id'          => $variation->get_id(),
                    'label'       => wp_strip_all_tags( $attr_str ? $attr_str : $variation->get_name() ),
                    'attributes'  => $attr_data,
                    'in_stock'    => $in_stock,
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
                'attributes'  => array(),
                'in_stock'    => true,
                'min_qty'     => $rules['min_qty'],
                'step_qty'    => $rules['step_qty'],
                'default_qty' => $rules['default_qty'],
                'rule_text'   => $this->build_rules_message( $rules ),
            );
        }

        $valid_variants = array_filter(
            $variants,
            function ( $variant ) {
                return ! empty( $variant['in_stock'] );
            }
        );

        $auto_select = ( 1 === count( $valid_variants ) ) ? (int) array_values( $valid_variants )[0]['id'] : 0;

        return array(
            'product_id'     => $product->get_id(),
            'product_name'   => $product->get_name(),
            'has_variations' => $product->is_type( 'variable' ),
            'auto_select'    => $auto_select,
            'variants'       => array_values( $variants ),
            'attributes'     => $attributes,
        );
    }

    private function get_default_variant_for_ui( array $product_data ) {
        if ( ! empty( $product_data['auto_select'] ) ) {
            foreach ( $product_data['variants'] as $variant ) {
                if ( (int) $variant['id'] === (int) $product_data['auto_select'] ) {
                    return $variant;
                }
            }
        }

        foreach ( $product_data['variants'] as $variant ) {
            if ( ! empty( $variant['in_stock'] ) ) {
                return $variant;
            }
        }

        return reset( $product_data['variants'] );
    }

    private function build_meta_parts( WC_Product $product ) {
        $parts = array();

        if ( $this->settings['show_sku'] ) {
            $sku = $product->get_sku();
            if ( $sku ) {
                $parts[] = 'SKU: ' . $sku;
            }
        }

        if ( $this->settings['show_dimensions'] ) {
            $dims = wc_format_dimensions( $product->get_dimensions( false ) );
            if ( $dims && '-' !== $dims ) {
                $parts[] = $dims;
            }
        }

        if ( $this->settings['show_color_count'] && $product->is_type( 'variable' ) ) {
            $color_attr = $product->get_attribute( 'pa_color' );
            if ( ! $color_attr ) {
                $color_attr = $product->get_attribute( 'pa_colour' );
            }
            if ( $color_attr ) {
                $colors = array_filter( explode( ', ', $color_attr ) );
                $count  = count( $colors );
                if ( $count > 0 ) {
                    $parts[] = $count . ' ' . ( 1 === $count ? 'color' : 'colores' );
                }
            }
        }

        return $parts;
    }

    private function get_purchase_rules( WC_Product $product, WC_Product $parent_product = null ) {
        $meta_keys_min  = array( '_wbi_min_qty', '_wbi_wholesale_min_qty', '_min_qty', 'wbi_min_qty' );
        $meta_keys_step = array( '_wbi_pack_multiple', '_wbi_qty_multiple', '_qty_step', '_quantity_step', 'wbi_pack_multiple' );

        $min_qty  = $this->settings['enforce_min_qty'] ? $this->read_numeric_meta( $product, $meta_keys_min ) : 0;
        $step_qty = $this->settings['enforce_pack_multiples'] ? $this->read_numeric_meta( $product, $meta_keys_step ) : 0;

        if ( $parent_product instanceof WC_Product ) {
            if ( $min_qty <= 0 && $this->settings['enforce_min_qty'] ) {
                $min_qty = $this->read_numeric_meta( $parent_product, $meta_keys_min );
            }

            if ( $step_qty <= 0 && $this->settings['enforce_pack_multiples'] ) {
                $step_qty = $this->read_numeric_meta( $parent_product, $meta_keys_step );
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

    private function get_initial_quantity_value( array $rules ) {
        return $this->settings['initial_qty_zero'] ? 0 : (int) $rules['default_qty'];
    }

    public function enqueue_quantity_sync_js() {
        if ( is_admin() || ! function_exists( 'wc_enqueue_js' ) ) {
            return;
        }

        if ( ! function_exists( 'is_shop' ) || ! function_exists( 'is_product' ) ) {
            return;
        }

        if ( ! ( $this->should_load_assets() || is_product() ) ) {
            return;
        }

        wc_enqueue_js(
            '(function($){$(document).on("change input","form.cart input.qty",function(){$(this.form).find("[data-quantity]").attr("data-quantity",this.value);});})(jQuery);'
        );
    }

    public function maybe_change_single_add_to_cart_text( $text, $product ) {
        if ( ! $product instanceof WC_Product ) {
            return $text;
        }

        if ( ! $product->is_purchasable() ) {
            return $text;
        }

        if ( ! $product->is_in_stock() && ! $product->backorders_allowed() ) {
            return $text;
        }

        return __( 'Agregar', 'woocommerce' );
    }

    public function validate_quick_order_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
        if ( ! $this->is_quick_order_request() ) {
            return $passed;
        }

        $qty = (int) $quantity;

        if ( $variation_id <= 0 ) {
            $parent_product = wc_get_product( $product_id );
            if ( $parent_product instanceof WC_Product && $parent_product->is_type( 'variable' ) ) {
                wc_add_notice( 'Seleccioná una opción', 'error' );
                return false;
            }
        }

        $product = $variation_id ? wc_get_product( $variation_id ) : wc_get_product( $product_id );
        if ( ! $product instanceof WC_Product ) {
            return false;
        }

        $parent_product = ( $product instanceof WC_Product_Variation ) ? wc_get_product( $product->get_parent_id() ) : null;
        $rules          = $this->get_purchase_rules( $product, $parent_product );
        $validation     = $this->validate_quantity( $qty, $rules );

        if ( is_wp_error( $validation ) ) {
            wc_add_notice( $validation->get_error_message(), 'error' );
            return false;
        }

        return $passed;
    }

    private function is_quick_order_request() {
        if ( ! isset( $_REQUEST['wbi_pwoq_request'] ) ) {
            return false;
        }

        return '1' === sanitize_text_field( wp_unslash( $_REQUEST['wbi_pwoq_request'] ) );
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

    private function validate_quantity( $quantity, array $rules ) {
        if ( $quantity <= 0 ) {
            return new WP_Error( 'invalid_quantity', 'Ingresá una cantidad válida para continuar.' );
        }

        if ( $this->settings['enforce_min_qty'] && $quantity < $rules['min_qty'] ) {
            return new WP_Error(
                'minimum_quantity',
                sprintf(
                    'Te faltan %1$d para completar el mínimo. Mínimo: %2$d unidades.',
                    $rules['min_qty'] - $quantity,
                    $rules['min_qty']
                )
            );
        }

        if ( $this->settings['enforce_pack_multiples'] && $rules['step_qty'] > 1 && 0 !== $quantity % $rules['step_qty'] ) {
            return new WP_Error(
                'pack_multiple',
                sprintf( 'Elegí múltiplos de %1$d unidades para este producto.', $rules['step_qty'] )
            );
        }

        return true;
    }
}
