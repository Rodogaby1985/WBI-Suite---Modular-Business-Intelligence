<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI Public Wholesale Quick Order Module
 *
 * Rebuilt from scratch. All previous PWOQ implementation replaced.
 * Implements validated snippet behavior:
 *  - Simple products: qty input + Agregar
 *  - Variable products: dropdown(s) + qty + Agregar
 *  - Sticky bar summary + mass add
 *  - Qty resets to 0 after successful add
 *  - Mini-cart / header fragment refresh; fallback reload after mass add
 *  - No chips UI — dropdown only for variables
 *
 * Settings (wbi_modules_settings):
 *   wbi_pwoq_show_sku              bool
 *   wbi_pwoq_show_dimensions       bool
 *   wbi_pwoq_enforce_min_qty       bool
 *   wbi_pwoq_enforce_pack_multiples bool
 *   wbi_pwoq_global_add_enabled    bool
 *   wbi_pwoq_hide_native_add_to_cart bool
 *   wbi_pwoq_force_reload_on_fragment_fail bool
 */
class WBI_Public_Wholesale_Quick_Order_Module {

    const ASSET_VERSION = '3.0.0';

    /** @var array Cached module settings */
    private $settings;

    public function __construct() {
        $this->settings = $this->load_settings();

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_footer', array( $this, 'render_footer_elements' ) );

        add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'render_loop_quick_order_form' ), 10, 3 );
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    private function load_settings() {
        $opts = get_option( 'wbi_modules_settings', array() );

        return array(
            'show_sku'                      => ! empty( $opts['wbi_pwoq_show_sku'] ),
            'show_dimensions'               => ! empty( $opts['wbi_pwoq_show_dimensions'] ),
            'enforce_min_qty'               => isset( $opts['wbi_pwoq_enforce_min_qty'] ) ? (bool) $opts['wbi_pwoq_enforce_min_qty'] : true,
            'enforce_pack_multiples'        => isset( $opts['wbi_pwoq_enforce_pack_multiples'] ) ? (bool) $opts['wbi_pwoq_enforce_pack_multiples'] : true,
            'global_add_enabled'            => ! empty( $opts['wbi_pwoq_global_add_enabled'] ),
            'hide_native_add_to_cart'       => ! empty( $opts['wbi_pwoq_hide_native_add_to_cart'] ),
            'force_reload_on_fragment_fail' => ! empty( $opts['wbi_pwoq_force_reload_on_fragment_fail'] ),
        );
    }

    // -------------------------------------------------------------------------
    // Asset enqueue
    // -------------------------------------------------------------------------

    public function enqueue_assets() {
        if ( is_admin() || ! $this->should_load_assets() ) {
            return;
        }

        $root = trailingslashit( plugins_url( '/', dirname( __FILE__ ) ) );

        wp_enqueue_style(
            'wbi-pwoq',
            $root . 'assets/css/wbi-public-wholesale-quick-order.css',
            array(),
            self::ASSET_VERSION
        );

        wp_enqueue_script(
            'wbi-pwoq',
            $root . 'assets/js/wbi-public-wholesale-quick-order.js',
            array( 'jquery' ),
            self::ASSET_VERSION,
            true
        );

        wp_localize_script(
            'wbi-pwoq',
            'WBIPwoq',
            array(
                'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
                'wcAjaxUrl'            => WC_AJAX::get_endpoint( '%%endpoint%%' ),
                'nonce'                => wp_create_nonce( 'wbi_pwoq' ),
                'globalAddEnabled'     => $this->settings['global_add_enabled'],
                'forceReload'          => $this->settings['force_reload_on_fragment_fail'],
                'i18n'                 => array(
                    'adding'       => __( 'Agregando…', 'wbi-suite' ),
                    'agregar'      => __( 'AGREGAR', 'wbi-suite' ),
                    'selectOption' => __( 'Seleccioná una opción', 'wbi-suite' ),
                    'massAdd'      => __( 'AGREGAR AL CARRITO', 'wbi-suite' ),
                    'products'     => __( 'productos', 'wbi-suite' ),
                    'units'        => __( 'unidades', 'wbi-suite' ),
                    'qtyPositive'  => __( 'Ingresá una cantidad mayor a 0.', 'wbi-suite' ),
                    'selectVar'    => __( 'Seleccioná una variante para continuar.', 'wbi-suite' ),
                    'errorGeneric' => __( 'No pudimos agregar el producto. Intentá nuevamente.', 'wbi-suite' ),
                ),
            )
        );
    }

    private function should_load_assets() {
        if ( ! function_exists( 'is_shop' ) ) {
            return false;
        }
        return is_shop() || is_product_taxonomy() || is_product_category() || is_product_tag();
    }

    // -------------------------------------------------------------------------
    // Loop form output
    // -------------------------------------------------------------------------

    public function render_loop_quick_order_form( $html, $product, $args ) {
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

        ob_start();

        echo '<div class="wbi-pwoq-card"'
            . ' data-product="' . esc_attr( wp_json_encode( $product_data ) ) . '"'
            . ' data-product-id="' . esc_attr( $product->get_id() ) . '">';

        // Meta line (SKU / dimensions)
        $meta = $this->build_meta_parts( $product );
        if ( ! empty( $meta ) ) {
            echo '<div class="wbi-pwoq-meta">' . esc_html( implode( ' · ', $meta ) ) . '</div>';
        }

        // Dropdown(s) for variable products
        if ( $product->is_type( 'variable' ) ) {
            echo '<div class="wbi-pwoq-selectors">';
            echo $this->render_attribute_dropdowns( $product_data ); // phpcs:ignore WordPress.Security.EscapeOutput
            echo '</div>';
        }

        // Qty + button row
        echo '<div class="wbi-pwoq-action-row">';
        echo '<input type="number" class="wbi-pwoq-qty" value="0" min="0" step="1" autocomplete="off" />';
        echo '<button type="button" class="wbi-pwoq-add-btn button alt"'
            . ' data-product-id="' . esc_attr( $product->get_id() ) . '">'
            . esc_html__( 'AGREGAR', 'wbi-suite' )
            . '</button>';
        echo '</div>';

        // Rules / status line
        echo '<div class="wbi-pwoq-rules" aria-live="polite"></div>';
        echo '<div class="wbi-pwoq-status" aria-live="polite"></div>';

        echo '</div>';

        $quick_html = ob_get_clean();

        if ( $this->settings['hide_native_add_to_cart'] ) {
            return $quick_html;
        }

        return $quick_html . '<div class="wbi-pwoq-native">' . $html . '</div>';
    }

    // -------------------------------------------------------------------------
    // Attribute dropdowns
    // -------------------------------------------------------------------------

    private function render_attribute_dropdowns( array $product_data ) {
        if ( empty( $product_data['attributes'] ) ) {
            return '';
        }

        $out = '';
        foreach ( $product_data['attributes'] as $attr_name => $attr_values ) {
            $label = ucfirst( str_replace( array( 'pa_', '-', '_' ), array( '', ' ', ' ' ), $attr_name ) );

            $out .= '<div class="wbi-pwoq-select-wrap" data-attr="' . esc_attr( $attr_name ) . '">';
            $out .= '<label class="wbi-pwoq-attr-label">' . esc_html( $label ) . '</label>';
            $out .= '<select class="wbi-pwoq-select" data-attr="' . esc_attr( $attr_name ) . '">';
            $out .= '<option value="">' . esc_html__( 'Seleccioná una opción', 'wbi-suite' ) . '</option>';

            foreach ( $attr_values as $v ) {
                $slug  = is_array( $v ) ? $v['slug']  : $v;
                $label = is_array( $v ) ? $v['value'] : $v;
                $out  .= '<option value="' . esc_attr( $slug ) . '">' . esc_html( $label ) . '</option>';
            }

            $out .= '</select>';
            $out .= '</div>';
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Footer elements (sticky bar)
    // -------------------------------------------------------------------------

    public function render_footer_elements() {
        if ( is_admin() || ! $this->should_load_assets() ) {
            return;
        }

        ?>
        <div class="wbi-pwoq-toast" aria-live="polite" hidden></div>
        <?php if ( $this->settings['global_add_enabled'] ) : ?>
        <div class="wbi-pwoq-sticky-bar" hidden>
            <div class="wbi-pwoq-sticky-bar__inner">
                <span class="wbi-pwoq-sticky-bar__summary"></span>
                <button type="button" class="wbi-pwoq-mass-add-btn button alt">
                    <?php esc_html_e( 'AGREGAR AL CARRITO', 'wbi-suite' ); ?>
                </button>
            </div>
        </div>
        <?php endif; ?>
        <?php
    }

    // -------------------------------------------------------------------------
    // Product data builder
    // -------------------------------------------------------------------------

    private function build_product_data( WC_Product $product ) {
        $variants   = array();
        $attributes = array();

        if ( $product->is_type( 'variable' ) ) {
            foreach ( $product->get_available_variations() as $vdata ) {
                if ( empty( $vdata['variation_id'] ) ) {
                    continue;
                }

                $variation = wc_get_product( $vdata['variation_id'] );
                if ( ! $variation instanceof WC_Product_Variation || ! $variation->is_purchasable() || ! $variation->is_in_stock() ) {
                    continue;
                }

                $attr_data = $variation->get_variation_attributes();
                $rules     = $this->get_purchase_rules( $variation, $product );

                foreach ( $attr_data as $attr_key => $attr_val ) {
                    if ( '' === $attr_val ) {
                        continue;
                    }
                    $clean = str_replace( 'attribute_', '', $attr_key );
                    if ( ! isset( $attributes[ $clean ] ) ) {
                        $attributes[ $clean ] = array();
                    }
                    $term    = get_term_by( 'slug', $attr_val, $clean );
                    $display = ( $term && ! is_wp_error( $term ) ) ? $term->name : $attr_val;
                    $entry   = array( 'slug' => $attr_val, 'value' => $display );
                    if ( ! in_array( $entry, $attributes[ $clean ], true ) ) {
                        $attributes[ $clean ][] = $entry;
                    }
                }

                $variants[] = array(
                    'id'         => $variation->get_id(),
                    'attributes' => $attr_data,
                    'min_qty'    => $rules['min_qty'],
                    'step_qty'   => $rules['step_qty'],
                );
            }
        } else {
            $rules      = $this->get_purchase_rules( $product );
            $variants[] = array(
                'id'         => 0,
                'attributes' => array(),
                'min_qty'    => $rules['min_qty'],
                'step_qty'   => $rules['step_qty'],
            );
        }

        return array(
            'product_id'     => $product->get_id(),
            'has_variations' => $product->is_type( 'variable' ),
            'variants'       => $variants,
            'attributes'     => $attributes,
        );
    }

    // -------------------------------------------------------------------------
    // Purchase rules
    // -------------------------------------------------------------------------

    private function get_purchase_rules( WC_Product $product, WC_Product $parent = null ) {
        $min_keys  = array( '_wbi_min_qty', '_wbi_wholesale_min_qty', '_min_qty', 'wbi_min_qty' );
        $step_keys = array( '_wbi_pack_multiple', '_wbi_qty_multiple', '_qty_step', '_quantity_step', 'wbi_pack_multiple' );

        $min_qty  = $this->settings['enforce_min_qty'] ? $this->read_numeric_meta( $product, $min_keys ) : 0;
        $step_qty = $this->settings['enforce_pack_multiples'] ? $this->read_numeric_meta( $product, $step_keys ) : 0;

        if ( $parent instanceof WC_Product ) {
            if ( $min_qty <= 0 && $this->settings['enforce_min_qty'] ) {
                $min_qty = $this->read_numeric_meta( $parent, $min_keys );
            }
            if ( $step_qty <= 0 && $this->settings['enforce_pack_multiples'] ) {
                $step_qty = $this->read_numeric_meta( $parent, $step_keys );
            }
        }

        return array(
            'min_qty'  => max( 1, (int) $min_qty ),
            'step_qty' => max( 1, (int) $step_qty ),
        );
    }

    private function read_numeric_meta( WC_Product $product, array $keys ) {
        foreach ( $keys as $key ) {
            $val = get_post_meta( $product->get_id(), $key, true );
            if ( '' !== $val && false !== $val && is_numeric( $val ) ) {
                return (int) $val;
            }
        }
        return 0;
    }

    // -------------------------------------------------------------------------
    // Meta parts (SKU / dimensions)
    // -------------------------------------------------------------------------

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

        return $parts;
    }
}
