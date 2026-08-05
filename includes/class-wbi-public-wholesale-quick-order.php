<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI Public Wholesale Quick Order Module
 *
 * Enables a public-facing quick-order card on WooCommerce catalog pages,
 * allowing customers to add products (with or without variants) directly
 * from the listing without visiting the single-product page.
 *
 * Settings (stored in wbi_modules_settings):
 *   wbi_pwoq_variant_selector_mode  string  'inline' | 'modal'
 *   wbi_pwoq_show_sku               bool
 *   wbi_pwoq_show_dimensions        bool
 *   wbi_pwoq_show_color_count       bool
 *   wbi_pwoq_enforce_min_qty        bool
 *   wbi_pwoq_enforce_pack_multiples bool
 *   wbi_pwoq_global_add_enabled     bool
 *   wbi_pwoq_initial_qty_zero       bool
 *   wbi_pwoq_hide_native_add_to_cart bool
 */
class WBI_Public_Wholesale_Quick_Order_Module {

    const ASSET_VERSION = '1.4.0';

    /** @var array Cached module settings */
    private $settings;

    public function __construct() {
        $this->settings = $this->load_settings();

        add_action( 'wp_enqueue_scripts',                             array( $this, 'enqueue_assets' ) );
        add_action( 'woocommerce_after_shop_loop_item',               array( $this, 'render_quick_order_ui' ), 15 );
        add_action( 'wp_footer',                                      array( $this, 'render_feedback_shell' ) );
        add_filter( 'woocommerce_loop_add_to_cart_link',               array( $this, 'maybe_hide_native_add_to_cart' ), 10, 3 );
        add_action( 'wp_ajax_wbi_public_quick_order_add',             array( $this, 'ajax_add_to_cart' ) );
        add_action( 'wp_ajax_nopriv_wbi_public_quick_order_add',      array( $this, 'ajax_add_to_cart' ) );
        add_action( 'wp_ajax_wbi_public_quick_order_variants',        array( $this, 'ajax_get_variants' ) );
        add_action( 'wp_ajax_nopriv_wbi_public_quick_order_variants', array( $this, 'ajax_get_variants' ) );
    }

    // -----------------------------------------------------------------------
    // Settings helpers
    // -----------------------------------------------------------------------

    private function load_settings() {
        $opts = get_option( 'wbi_modules_settings', array() );
        return array(
            'variant_selector_mode'  => isset( $opts['wbi_pwoq_variant_selector_mode'] ) ? $opts['wbi_pwoq_variant_selector_mode'] : 'modal',
            'show_sku'               => ! empty( $opts['wbi_pwoq_show_sku'] ),
            'show_dimensions'        => ! empty( $opts['wbi_pwoq_show_dimensions'] ),
            'show_color_count'       => ! empty( $opts['wbi_pwoq_show_color_count'] ),
            'enforce_min_qty'        => isset( $opts['wbi_pwoq_enforce_min_qty'] ) ? (bool) $opts['wbi_pwoq_enforce_min_qty'] : true,
            'enforce_pack_multiples' => isset( $opts['wbi_pwoq_enforce_pack_multiples'] ) ? (bool) $opts['wbi_pwoq_enforce_pack_multiples'] : true,
            'global_add_enabled'              => ! empty( $opts['wbi_pwoq_global_add_enabled'] ),
            'initial_qty_zero'                => ! empty( $opts['wbi_pwoq_initial_qty_zero'] ),
            'hide_native_add_to_cart'         => ! empty( $opts['wbi_pwoq_hide_native_add_to_cart'] ),
            'force_reload_on_fragment_fail'   => ! empty( $opts['wbi_pwoq_force_reload_on_fragment_fail'] ),
        );
    }

    // -----------------------------------------------------------------------
    // Asset enqueue
    // -----------------------------------------------------------------------

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
                'ajaxUrl'                     => admin_url( 'admin-ajax.php' ),
                'nonce'                       => wp_create_nonce( 'wbi_public_quick_order' ),
                'currencySymbol'              => get_woocommerce_currency_symbol(),
                'variantSelectorMode'         => $this->settings['variant_selector_mode'],
                'globalAddEnabled'            => $this->settings['global_add_enabled'],
                'initialQtyZero'              => $this->settings['initial_qty_zero'],
                'forceReloadOnFragmentFail'   => $this->settings['force_reload_on_fragment_fail'],
                'i18n'                        => array(
                    'adding'           => 'Agregando…',
                    'defaultButton'    => 'AGREGAR AL PEDIDO',
                    'added'            => 'Agregado al pedido',
                    'errorGeneric'     => 'No pudimos agregar este producto. Intentá nuevamente.',
                    'successSingle'    => 'Agregaste %1$s unidad de %2$s.',
                    'successPlural'    => 'Agregaste %1$s unidades de %2$s.',
                    'counterSingular'  => '%1$s producto · %2$s unidad',
                    'counterPlural'    => '%1$s productos · %2$s unidades',
                    'selectOption'     => 'Seleccioná una opción',
                    'selectVariation'  => 'Elegí una variante para continuar.',
                    'confirmAdd'       => 'AGREGAR AL PEDIDO',
                    'cancel'           => 'Cancelar',
                    'quantity'         => 'Cantidad',
                    'minQty'           => 'Mínimo: %d unidades',
                    'missingMin'       => 'Te faltan %d para completar el mínimo',
                    'packMultiple'     => 'Múltiplos de %d',
                    'noStock'          => 'Sin stock',
                    'close'            => 'Cerrar',
                    'globalAdd'        => 'AGREGAR SELECCIONADOS AL CARRITO',
                    'globalEmpty'      => 'Seleccioná cantidades para agregar al carrito.',
                    'globalSuccess'    => 'Se agregaron %1$s productos por %2$s unidades.',
                ),
            )
        );
    }

    private function should_load_assets() {
        return is_shop() || is_product_taxonomy() || is_product_category() || is_product_tag();
    }

    // -----------------------------------------------------------------------
    // Front-end rendering — product card UI
    // -----------------------------------------------------------------------

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
        $has_variations  = ! empty( $product_data['has_variations'] );
        $mode            = $this->settings['variant_selector_mode'];

        // Secondary info line
        $meta_parts = $this->build_meta_parts( $product );
        ?>
        <div class="wbi-pwoq" data-product="<?php echo esc_attr( wp_json_encode( $product_data ) ); ?>" data-mode="<?php echo esc_attr( $mode ); ?>">

            <?php if ( ! empty( $meta_parts ) ) : ?>
            <div class="wbi-pwoq__meta-line">
                <?php echo esc_html( implode( ' · ', $meta_parts ) ); ?>
            </div>
            <?php endif; ?>

            <?php // ---- INLINE mode: show chips directly in the card ---- ?>
            <?php if ( $has_variations && 'inline' === $mode ) : ?>
            <div class="wbi-pwoq__inline-selector">
                <?php echo $this->render_attribute_chips( $product_data ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </div>
            <?php endif; ?>

            <?php if ( ! $has_variations ) : ?>
            <div class="wbi-pwoq__presentation-row">
                <div class="wbi-pwoq__variant-static">
                    <?php echo esc_html( $default_variant['label'] ); ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="wbi-pwoq__action-row">
                <label class="screen-reader-text" for="wbi-pwoq-qty-<?php echo esc_attr( $product->get_id() ); ?>">Cantidad</label>
                <div class="wbi-pwoq__stepper">
                    <button type="button" class="wbi-pwoq__stepper-dec" aria-label="Reducir cantidad">&#8722;</button>
                    <input
                        id="wbi-pwoq-qty-<?php echo esc_attr( $product->get_id() ); ?>"
                        class="wbi-pwoq__qty"
                        type="number"
                        min="<?php echo esc_attr( $default_variant['min_qty'] ); ?>"
                        step="<?php echo esc_attr( $default_variant['step_qty'] ); ?>"
                        value="<?php echo esc_attr( $this->get_initial_quantity_value( $default_variant ) ); ?>"
                        inputmode="numeric"
                    />
                    <button type="button" class="wbi-pwoq__stepper-inc" aria-label="Aumentar cantidad">&#43;</button>
                </div>

                <button type="button" class="button alt wbi-pwoq__button">
                    AGREGAR
                </button>
            </div>

            <div class="wbi-pwoq__rules-line" aria-live="polite">
                <?php echo esc_html( $this->build_rules_message( $default_variant ) ); ?>
            </div>
            <div class="wbi-pwoq__status" aria-live="polite"></div>
        </div>
        <?php
    }

    /**
     * Render attribute chip groups for inline/modal variant selection.
     * Returns escaped HTML string.
     */
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
            foreach ( $attr_values as $value ) {
                $out .= '<button type="button" class="wbi-pwoq__chip" data-value="' . esc_attr( $value ) . '">' . esc_html( $value ) . '</button>';
            }
            $out .= '</div>';
            $out .= '</div>';
        }
        return $out;
    }

    public function render_feedback_shell() {
        if ( is_admin() || ! $this->should_load_assets() ) {
            return;
        }

        $summary = $this->get_cart_summary();
        $mode    = $this->settings['variant_selector_mode'];
        ?>
        <div class="wbi-pwoq-toast" hidden></div>
        <div class="wbi-pwoq-summary" data-items="<?php echo esc_attr( $summary['items'] ); ?>" data-units="<?php echo esc_attr( $summary['units'] ); ?>">
            <?php echo esc_html( $summary['label'] ); ?>
        </div>

        <?php if ( $this->settings['global_add_enabled'] ) : ?>
        <div class="wbi-pwoq-global-bar" hidden aria-live="polite">
            <div class="wbi-pwoq-global-bar__inner">
                <div class="wbi-pwoq-global-bar__summary" aria-atomic="true"></div>
                <div class="wbi-pwoq-global-bar__detail"></div>
                <button type="button" class="button alt wbi-pwoq-global-bar__button" disabled>AGREGAR SELECCIONADOS AL CARRITO</button>
            </div>
        </div>
        <?php endif; ?>

        <?php if ( 'modal' === $mode ) : ?>
        <div class="wbi-pwoq-modal" hidden role="dialog" aria-modal="true" aria-label="Elegí variantes">
            <div class="wbi-pwoq-modal__backdrop"></div>
            <div class="wbi-pwoq-modal__box">
                <button type="button" class="wbi-pwoq-modal__close" aria-label="Cerrar">&#x2715;</button>
                <div class="wbi-pwoq-modal__product-name"></div>
                <div class="wbi-pwoq-modal__attrs"></div>
                <div class="wbi-pwoq-modal__qty-row">
                    <label class="wbi-pwoq-modal__qty-label">Cantidad</label>
                    <input class="wbi-pwoq-modal__qty" type="number" min="1" step="1" value="<?php echo esc_attr( $this->settings['initial_qty_zero'] ? 0 : 1 ); ?>" inputmode="numeric" />
                </div>
                <div class="wbi-pwoq-modal__rules" aria-live="polite"></div>
                <button type="button" class="button alt wbi-pwoq-modal__confirm">AGREGAR AL PEDIDO</button>
                <div class="wbi-pwoq-modal__status" aria-live="polite"></div>
            </div>
        </div>
        <?php endif; ?>
        <?php
    }

    // -----------------------------------------------------------------------
    // Product data builder
    // -----------------------------------------------------------------------

    private function build_product_data( WC_Product $product ) {
        $variants    = array();
        $attributes  = array();

        if ( $product->is_type( 'variable' ) ) {
            // Collect unique attribute values across in-stock variations
            foreach ( $product->get_available_variations() as $variation_data ) {
                if ( empty( $variation_data['variation_id'] ) || empty( $variation_data['is_purchasable'] ) ) {
                    continue;
                }

                $variation = wc_get_product( $variation_data['variation_id'] );
                if ( ! $variation instanceof WC_Product_Variation ) {
                    continue;
                }

                $in_stock  = $variation->is_in_stock();
                $rules     = $this->get_purchase_rules( $variation, $product );
                $attr_data = $variation->get_variation_attributes();
                $attr_str  = wc_get_formatted_variation( $variation, true, false, true );

                // Build attribute chip maps
                foreach ( $attr_data as $attr_key => $attr_value ) {
                    if ( '' === $attr_value ) continue;
                    $clean_key = str_replace( 'attribute_', '', $attr_key );
                    if ( ! isset( $attributes[ $clean_key ] ) ) {
                        $attributes[ $clean_key ] = array();
                    }
                    $term = get_term_by( 'slug', $attr_value, $clean_key );
                    $display_value = ( $term && ! is_wp_error( $term ) ) ? $term->name : $attr_value;
                    if ( ! in_array( $display_value, $attributes[ $clean_key ], true ) ) {
                        $attributes[ $clean_key ][] = $display_value;
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
                    'price'       => $in_stock ? wc_price( $variation->get_price() ) : '',
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
                'price'       => wc_price( $product->get_price() ),
            );
        }

        // If only one valid variant, mark it as pre-selected
        $valid_variants = array_filter( $variants, function ( $v ) { return $v['in_stock']; } );
        $auto_select    = ( 1 === count( $valid_variants ) ) ? array_values( $valid_variants )[0]['id'] : null;

        return array(
            'product_id'     => $product->get_id(),
            'product_name'   => $product->get_name(),
            'has_variations' => $product->is_type( 'variable' ),
            'auto_select'    => $auto_select,
            'variants'       => array_values( $variants ),
            'attributes'     => $attributes,
        );
    }

    /**
     * Build the secondary meta-info line for the card.
     */
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
            if ( ! $color_attr ) {
                $color_attr = $product->get_attribute( 'pa_color' );
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

    // -----------------------------------------------------------------------
    // Purchase rules
    // -----------------------------------------------------------------------

    private function get_purchase_rules( WC_Product $product, WC_Product $parent_product = null ) {
        $meta_keys_min  = array( '_wbi_min_qty', '_wbi_wholesale_min_qty', '_min_qty', 'wbi_min_qty' );
        $meta_keys_step = array( '_wbi_pack_multiple', '_wbi_qty_multiple', '_qty_step', '_quantity_step', 'wbi_pack_multiple' );

        $min_qty  = $this->settings['enforce_min_qty']        ? $this->read_numeric_meta( $product, $meta_keys_min )  : 0;
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
        return $this->settings['initial_qty_zero'] ? 0 : $rules['default_qty'];
    }

    public function maybe_hide_native_add_to_cart( $html, $product, $args ) {
        if ( ! $this->should_load_assets() || ! $this->settings['hide_native_add_to_cart'] ) {
            return $html;
        }

        return '';
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

    // -----------------------------------------------------------------------
    // AJAX: add to cart
    // -----------------------------------------------------------------------

    public function ajax_add_to_cart() {
        check_ajax_referer( 'wbi_public_quick_order', 'nonce' );

        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            wp_send_json_error( array( 'message' => 'El carrito no está disponible en este momento.' ), 400 );
        }

        $product_id   = isset( $_POST['product_id'] )   ? absint( wp_unslash( $_POST['product_id'] ) )   : 0;
        $variation_id = isset( $_POST['variation_id'] ) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0;
        $quantity     = isset( $_POST['quantity'] )     ? (int) wp_unslash( $_POST['quantity'] )         : 0;

        if ( $variation_id <= 0 ) {
            $parent_product = wc_get_product( $product_id );
            if ( $parent_product instanceof WC_Product && $parent_product->is_type( 'variable' ) ) {
                wp_send_json_error( array( 'message' => 'Elegí una variante válida antes de agregar este producto.' ), 400 );
            }
        }

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

    // -----------------------------------------------------------------------
    // AJAX: get variants for modal
    // -----------------------------------------------------------------------

    public function ajax_get_variants() {
        check_ajax_referer( 'wbi_public_quick_order', 'nonce' );

        $product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
        $product    = wc_get_product( $product_id );

        if ( ! $product instanceof WC_Product ) {
            wp_send_json_error( array( 'message' => 'Producto no encontrado.' ), 404 );
        }

        $data = $this->build_product_data( $product );
        wp_send_json_success( $data );
    }

    // -----------------------------------------------------------------------
    // Quantity validation
    // -----------------------------------------------------------------------

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
                sprintf(
                    'Elegí múltiplos de %1$d unidades para este producto.',
                    $rules['step_qty']
                )
            );
        }

        return true;
    }

    // -----------------------------------------------------------------------
    // Cart summary
    // -----------------------------------------------------------------------

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
