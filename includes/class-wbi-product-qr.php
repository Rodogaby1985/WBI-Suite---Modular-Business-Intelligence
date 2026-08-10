<?php
/**
 * WBI Product QR Module
 * QR únicos por producto/variación para agregado rápido al carrito (POS y Web),
 * con etiquetas imprimibles.
 *
 * Token format (versioned, HMAC-signed):
 *   base64url( "1|{context}|{product_id}|{variation_id}|{nonce}|{hmac}" )
 *   - context: pos|web
 *   - nonce: random hex, guarantees uniqueness/non-guessability
 *   - hmac: sha256 over "1|context|pid|vid|nonce" with a per-site secret
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Product_QR_Module {

    const SETTINGS_OPTION = 'wbi_product_qr_settings';
    const SECRET_OPTION   = 'wbi_product_qr_secret';
    const TOKEN_VERSION   = '1';

    const META_POS = '_wbi_qr_token_pos';
    const META_WEB = '_wbi_qr_token_web';

    const PAGE_SLUG = 'wbi-product-qr';

    public function __construct() {
        // Generation lifecycle
        add_action( 'woocommerce_new_product',           array( $this, 'ensure_product_tokens' ) );
        add_action( 'woocommerce_update_product',        array( $this, 'ensure_product_tokens' ) );
        add_action( 'woocommerce_save_product_variation', array( $this, 'ensure_variation_tokens' ), 10, 1 );

        // Public resolver (web context)
        add_action( 'template_redirect', array( $this, 'maybe_resolve_web_scan' ), 5 );

        // POS resolver (AJAX) + POS localize data
        add_action( 'wp_ajax_wbi_qr_pos_resolve', array( $this, 'ajax_pos_resolve' ) );
        add_filter( 'wbi_pos_localize_data',      array( $this, 'filter_pos_localize' ) );

        // Admin
        add_action( 'admin_menu',            array( $this, 'register_page' ), 100 );
        add_action( 'add_meta_boxes',        array( $this, 'register_metabox' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        // Admin AJAX
        add_action( 'wp_ajax_wbi_qr_regenerate', array( $this, 'ajax_regenerate' ) );

        // Admin actions (settings save + backfill)
        add_action( 'admin_post_wbi_qr_save_settings', array( $this, 'handle_save_settings' ) );
        add_action( 'admin_post_wbi_qr_backfill',      array( $this, 'handle_backfill' ) );

        // Bulk actions on product list
        add_filter( 'bulk_actions-edit-product',        array( $this, 'register_bulk_actions' ) );
        add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk_actions' ), 10, 3 );
        add_action( 'admin_notices',                    array( $this, 'bulk_admin_notices' ) );
    }

    // =========================================================================
    // Settings
    // =========================================================================

    public static function get_settings() {
        $defaults = array(
            'variation_qrs'       => 1,          // Generate variation-level QRs
            'default_qty'         => 1,          // Default scan quantity
            'web_redirect'        => 'cart',     // cart|shop|stay
            'label_include_price' => 0,          // Include price on labels
            'label_size'          => '50x30',    // 50x30|60x40|80x50 (mm)
            'allow_regenerate'    => 1,          // Allow token regeneration (capability-gated)
        );
        $saved = get_option( self::SETTINGS_OPTION, array() );
        return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
    }

    private static function label_size_presets() {
        return array(
            '50x30' => '50 × 30 mm',
            '60x40' => '60 × 40 mm',
            '80x50' => '80 × 50 mm',
        );
    }

    private function current_user_can_manage() {
        return current_user_can( 'manage_woocommerce' );
    }

    // =========================================================================
    // Token core
    // =========================================================================

    private static function get_secret() {
        $secret = get_option( self::SECRET_OPTION );
        if ( empty( $secret ) ) {
            $secret = wp_generate_password( 64, true, true );
            update_option( self::SECRET_OPTION, $secret, false );
        }
        return $secret . wp_salt( 'auth' );
    }

    private static function b64url_encode( $data ) {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    private static function b64url_decode( $data ) {
        $data = strtr( $data, '-_', '+/' );
        $pad  = strlen( $data ) % 4;
        if ( $pad ) {
            $data .= str_repeat( '=', 4 - $pad );
        }
        return base64_decode( $data, true );
    }

    private static function build_token( $context, $product_id, $variation_id = 0 ) {
        $nonce   = bin2hex( random_bytes( 6 ) );
        $payload = implode( '|', array( self::TOKEN_VERSION, $context, (int) $product_id, (int) $variation_id, $nonce ) );
        $sig     = substr( hash_hmac( 'sha256', $payload, self::get_secret() ), 0, 16 );
        return self::b64url_encode( $payload . '|' . $sig );
    }

    /**
     * Decodes + validates a token. Returns array or WP_Error.
     * Result: [ version, context, product_id, variation_id, target_id, token ]
     */
    public static function parse_token( $token ) {
        $token = trim( (string) $token );
        if ( '' === $token || strlen( $token ) > 200 || ! preg_match( '/^[A-Za-z0-9_-]+$/', $token ) ) {
            return new WP_Error( 'wbi_qr_invalid', __( 'Código QR inválido.', 'wbi-suite' ) );
        }

        $raw = self::b64url_decode( $token );
        if ( false === $raw ) {
            return new WP_Error( 'wbi_qr_invalid', __( 'Código QR inválido.', 'wbi-suite' ) );
        }

        $parts = explode( '|', $raw );
        if ( 6 !== count( $parts ) ) {
            return new WP_Error( 'wbi_qr_invalid', __( 'Código QR inválido.', 'wbi-suite' ) );
        }

        list( $version, $context, $product_id, $variation_id, $nonce, $sig ) = $parts;

        if ( self::TOKEN_VERSION !== $version || ! in_array( $context, array( 'pos', 'web' ), true ) ) {
            return new WP_Error( 'wbi_qr_invalid', __( 'Código QR inválido o de una versión no soportada.', 'wbi-suite' ) );
        }

        $payload  = implode( '|', array( $version, $context, $product_id, $variation_id, $nonce ) );
        $expected = substr( hash_hmac( 'sha256', $payload, self::get_secret() ), 0, 16 );
        if ( ! hash_equals( $expected, (string) $sig ) ) {
            return new WP_Error( 'wbi_qr_invalid', __( 'Código QR inválido (firma incorrecta).', 'wbi-suite' ) );
        }

        $product_id   = absint( $product_id );
        $variation_id = absint( $variation_id );
        $target_id    = $variation_id ? $variation_id : $product_id;

        // Token must match the currently stored token (regeneration invalidates old ones).
        $meta_key = ( 'pos' === $context ) ? self::META_POS : self::META_WEB;
        $stored   = get_post_meta( $target_id, $meta_key, true );
        if ( ! $stored || ! hash_equals( (string) $stored, $token ) ) {
            return new WP_Error( 'wbi_qr_revoked', __( 'Este código QR ya no es válido. Fue regenerado o eliminado.', 'wbi-suite' ) );
        }

        return array(
            'version'      => $version,
            'context'      => $context,
            'product_id'   => $product_id,
            'variation_id' => $variation_id,
            'target_id'    => $target_id,
            'token'        => $token,
        );
    }

    /**
     * Returns the token for a product/variation + context, lazily generating it if missing.
     */
    public static function get_token( $target_id, $context, $regenerate = false ) {
        $target_id = absint( $target_id );
        $post      = get_post( $target_id );
        if ( ! $post || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
            return '';
        }

        $meta_key = ( 'pos' === $context ) ? self::META_POS : self::META_WEB;
        $token    = get_post_meta( $target_id, $meta_key, true );
        if ( $token && ! $regenerate ) {
            return $token;
        }

        if ( 'product_variation' === $post->post_type ) {
            $product_id   = $post->post_parent;
            $variation_id = $target_id;
        } else {
            $product_id   = $target_id;
            $variation_id = 0;
        }

        // Random nonce makes collision virtually impossible; retry defensively anyway.
        for ( $i = 0; $i < 3; $i++ ) {
            $token    = self::build_token( $context, $product_id, $variation_id );
            $existing = get_posts( array(
                'post_type'      => array( 'product', 'product_variation' ),
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_key'       => $meta_key,
                'meta_value'     => $token,
            ) );
            if ( empty( $existing ) ) {
                break;
            }
        }

        update_post_meta( $target_id, $meta_key, $token );
        return $token;
    }

    public static function get_scan_url( $token ) {
        return add_query_arg( 'wbi_qr', rawurlencode( $token ), home_url( '/' ) );
    }

    // =========================================================================
    // Generation lifecycle
    // =========================================================================

    public function ensure_product_tokens( $product_id ) {
        $product_id = absint( $product_id );
        if ( ! $product_id ) return;
        self::get_token( $product_id, 'pos' );
        self::get_token( $product_id, 'web' );
    }

    public function ensure_variation_tokens( $variation_id ) {
        $settings = self::get_settings();
        if ( empty( $settings['variation_qrs'] ) ) return;
        $variation_id = absint( $variation_id );
        if ( ! $variation_id ) return;
        self::get_token( $variation_id, 'pos' );
        self::get_token( $variation_id, 'web' );
    }

    /**
     * Backfill tokens for the whole catalog. Returns number of posts processed.
     */
    public static function backfill_tokens() {
        $settings   = self::get_settings();
        $post_types = array( 'product' );
        if ( ! empty( $settings['variation_qrs'] ) ) {
            $post_types[] = 'product_variation';
        }

        $processed = 0;
        $paged     = 1;

        do {
            $ids = get_posts( array(
                'post_type'      => $post_types,
                'post_status'    => 'any',
                'posts_per_page' => 200,
                'paged'          => $paged,
                'fields'         => 'ids',
            ) );

            foreach ( $ids as $id ) {
                self::get_token( $id, 'pos' );
                self::get_token( $id, 'web' );
                $processed++;
            }

            $paged++;
        } while ( count( $ids ) === 200 );

        return $processed;
    }

    // =========================================================================
    // Resolution helpers
    // =========================================================================

    /**
     * Validates purchasability of the token target. Returns WC_Product or WP_Error.
     */
    private static function validate_purchasable( $resolved ) {
        $product = wc_get_product( $resolved['target_id'] );
        if ( ! $product ) {
            return new WP_Error( 'wbi_qr_missing', __( 'El producto de este QR ya no existe.', 'wbi-suite' ) );
        }

        $parent_status = get_post_status( $resolved['product_id'] );
        if ( 'publish' !== $parent_status ) {
            return new WP_Error( 'wbi_qr_unavailable', __( 'El producto de este QR no está publicado.', 'wbi-suite' ) );
        }

        if ( ! $product->is_purchasable() ) {
            return new WP_Error( 'wbi_qr_unavailable', __( 'El producto de este QR no está disponible para la venta.', 'wbi-suite' ) );
        }

        if ( ! $product->is_in_stock() ) {
            return new WP_Error( 'wbi_qr_out_of_stock', __( 'El producto de este QR está sin stock.', 'wbi-suite' ) );
        }

        return $product;
    }

    /**
     * Extracts a token from a raw scanner string (full URL or bare token).
     */
    public static function extract_token( $raw ) {
        $raw = trim( (string) wp_unslash( $raw ) );
        if ( '' === $raw ) return '';

        if ( false !== strpos( $raw, 'wbi_qr=' ) ) {
            $query = wp_parse_url( $raw, PHP_URL_QUERY );
            if ( $query ) {
                parse_str( $query, $params );
                if ( ! empty( $params['wbi_qr'] ) ) {
                    return sanitize_text_field( $params['wbi_qr'] );
                }
            }
            // Fallback: raw string that contains wbi_qr= but is not a parseable URL.
            if ( preg_match( '/wbi_qr=([A-Za-z0-9_-]+)/', $raw, $m ) ) {
                return $m[1];
            }
            return '';
        }

        return preg_match( '/^[A-Za-z0-9_-]{24,200}$/', $raw ) ? $raw : '';
    }

    private static function is_rate_limited() {
        $ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $key   = 'wbi_qr_rl_' . md5( $ip );
        $count = (int) get_transient( $key );
        if ( $count >= 30 ) {
            return true;
        }
        set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
        return false;
    }

    // =========================================================================
    // Web scan resolver (?wbi_qr=<token>)
    // =========================================================================

    public function maybe_resolve_web_scan() {
        if ( empty( $_GET['wbi_qr'] ) || is_admin() ) {
            return;
        }

        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return;
        }

        $settings = self::get_settings();
        $token    = sanitize_text_field( wp_unslash( $_GET['wbi_qr'] ) );

        if ( self::is_rate_limited() ) {
            wc_add_notice( __( 'Demasiados intentos de escaneo. Esperá un momento e intentá de nuevo.', 'wbi-suite' ), 'error' );
            wp_safe_redirect( wc_get_page_permalink( 'shop' ) );
            exit;
        }

        $resolved = self::parse_token( $token );
        if ( is_wp_error( $resolved ) ) {
            wc_add_notice( $resolved->get_error_message(), 'error' );
            wp_safe_redirect( wc_get_page_permalink( 'shop' ) );
            exit;
        }

        if ( 'web' !== $resolved['context'] ) {
            wc_add_notice( __( 'Este código QR es de uso interno (POS) y no puede usarse en la tienda.', 'wbi-suite' ), 'error' );
            wp_safe_redirect( wc_get_page_permalink( 'shop' ) );
            exit;
        }

        $product = self::validate_purchasable( $resolved );
        if ( is_wp_error( $product ) ) {
            wc_add_notice( $product->get_error_message(), 'error' );
            wp_safe_redirect( wc_get_page_permalink( 'shop' ) );
            exit;
        }

        $qty = max( 1, (int) $settings['default_qty'] );
        if ( isset( $_GET['wbi_qr_qty'] ) ) {
            $requested = absint( $_GET['wbi_qr_qty'] );
            if ( $requested >= 1 && $requested <= 999 ) {
                $qty = $requested;
            }
        }

        $variation_id = $resolved['variation_id'];
        $added        = WC()->cart->add_to_cart( $resolved['product_id'], $qty, $variation_id );

        if ( ! $added ) {
            wc_add_notice( __( 'No se pudo agregar el producto al carrito.', 'wbi-suite' ), 'error' );
            wp_safe_redirect( get_permalink( $resolved['product_id'] ) );
            exit;
        }

        wc_add_to_cart_message( array( $resolved['product_id'] => $qty ), true );

        switch ( $settings['web_redirect'] ) {
            case 'shop':
                $redirect = wc_get_page_permalink( 'shop' );
                break;
            case 'stay':
                $redirect = get_permalink( $resolved['product_id'] );
                break;
            case 'cart':
            default:
                $redirect = wc_get_cart_url();
                break;
        }

        wp_safe_redirect( $redirect );
        exit;
    }

    // =========================================================================
    // POS resolver (AJAX)
    // =========================================================================

    public function filter_pos_localize( $data ) {
        $data['qr'] = array(
            'enabled' => true,
            'action'  => 'wbi_qr_pos_resolve',
        );
        return $data;
    }

    public function ajax_pos_resolve() {
        check_ajax_referer( 'wbi_pos_nonce', 'nonce' );

        if ( ! current_user_can( 'wbi_pos_access' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'wbi-suite' ) ), 403 );
        }

        $raw   = isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : '';
        $token = self::extract_token( $raw );
        if ( '' === $token ) {
            wp_send_json_error( array( 'message' => __( 'Código QR inválido.', 'wbi-suite' ), 'not_qr' => true ) );
        }

        $resolved = self::parse_token( $token );
        if ( is_wp_error( $resolved ) ) {
            wp_send_json_error( array( 'message' => $resolved->get_error_message() ) );
        }

        // POS scanners may read either the POS or the Web label; both resolve in POS.
        $product = self::validate_purchasable( $resolved );
        if ( is_wp_error( $product ) ) {
            wp_send_json_error( array( 'message' => $product->get_error_message() ) );
        }

        $settings  = self::get_settings();
        $stock_qty = $product->get_stock_quantity();

        wp_send_json_success( array(
            'product' => array(
                'id'           => $product->get_id(),
                'product_id'   => $resolved['product_id'],
                'variation_id' => $resolved['variation_id'],
                'name'         => $product->get_name(),
                'sku'          => $product->get_sku(),
                'price'        => (float) $product->get_price(),
                'stock'        => null !== $stock_qty ? (int) $stock_qty : null,
                'stock_status' => $product->get_stock_status(),
                'image'        => wp_get_attachment_url( $product->get_image_id() ) ?: '',
            ),
            'qty'     => max( 1, (int) $settings['default_qty'] ),
            'context' => $resolved['context'],
        ) );
    }

    // =========================================================================
    // Admin — regenerate AJAX
    // =========================================================================

    public function ajax_regenerate() {
        check_ajax_referer( 'wbi_qr_admin', 'nonce' );

        $settings = self::get_settings();
        if ( ! $this->current_user_can_manage() || empty( $settings['allow_regenerate'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Sin permisos para regenerar códigos QR.', 'wbi-suite' ) ), 403 );
        }

        $target_id = absint( $_POST['target_id'] ?? 0 );
        $post      = get_post( $target_id );
        if ( ! $post || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
            wp_send_json_error( array( 'message' => __( 'Producto inválido.', 'wbi-suite' ) ) );
        }

        $pos = self::get_token( $target_id, 'pos', true );
        $web = self::get_token( $target_id, 'web', true );

        wp_send_json_success( array(
            'pos_url' => self::get_scan_url( $pos ),
            'web_url' => self::get_scan_url( $web ),
        ) );
    }

    // =========================================================================
    // Admin — metabox on product edit
    // =========================================================================

    public function register_metabox() {
        add_meta_box(
            'wbi_product_qr',
            '🔳 QR de Producto (WBI)',
            array( $this, 'render_metabox' ),
            'product',
            'side',
            'default'
        );
    }

    public function render_metabox( $post ) {
        $settings = self::get_settings();
        $targets  = array(
            array( 'id' => $post->ID, 'label' => __( 'Producto', 'wbi-suite' ) ),
        );

        if ( ! empty( $settings['variation_qrs'] ) ) {
            $product = wc_get_product( $post->ID );
            if ( $product && $product->is_type( 'variable' ) ) {
                foreach ( $product->get_children() as $variation_id ) {
                    $variation = wc_get_product( $variation_id );
                    if ( ! $variation ) continue;
                    $targets[] = array(
                        'id'    => $variation_id,
                        'label' => sprintf( __( 'Variación #%d', 'wbi-suite' ), $variation_id ) . ' — ' . wp_strip_all_tags( wc_get_formatted_variation( $variation, true, false ) ),
                    );
                }
            }
        }

        $can_regen = $this->current_user_can_manage() && ! empty( $settings['allow_regenerate'] );

        echo '<div class="wbi-qr-metabox">';
        foreach ( $targets as $target ) {
            $pos_url = self::get_scan_url( self::get_token( $target['id'], 'pos' ) );
            $web_url = self::get_scan_url( self::get_token( $target['id'], 'web' ) );
            ?>
            <div class="wbi-qr-target" data-target-id="<?php echo esc_attr( $target['id'] ); ?>">
                <strong><?php echo esc_html( $target['label'] ); ?></strong>
                <div class="wbi-qr-previews">
                    <div class="wbi-qr-preview">
                        <div class="wbi-qr-canvas" data-context="pos" data-url="<?php echo esc_attr( $pos_url ); ?>"></div>
                        <span class="wbi-qr-caption">POS</span>
                        <button type="button" class="button button-small wbi-qr-download" data-context="pos"><?php esc_html_e( 'Descargar PNG', 'wbi-suite' ); ?></button>
                    </div>
                    <div class="wbi-qr-preview">
                        <div class="wbi-qr-canvas" data-context="web" data-url="<?php echo esc_attr( $web_url ); ?>"></div>
                        <span class="wbi-qr-caption">Web</span>
                        <button type="button" class="button button-small wbi-qr-download" data-context="web"><?php esc_html_e( 'Descargar PNG', 'wbi-suite' ); ?></button>
                    </div>
                </div>
                <div class="wbi-qr-actions">
                    <a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&wbi_qr_labels=1&ids=' . $target['id'] ) ); ?>" target="_blank"><?php esc_html_e( 'Imprimir etiqueta', 'wbi-suite' ); ?></a>
                    <?php if ( $can_regen ) : ?>
                        <button type="button" class="button button-small wbi-qr-regenerate"><?php esc_html_e( 'Regenerar QR', 'wbi-suite' ); ?></button>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        }
        echo '</div>';
    }

    // =========================================================================
    // Admin — assets
    // =========================================================================

    public function enqueue_admin_assets( $hook ) {
        $screen   = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $is_product_edit = $screen && 'product' === $screen->post_type && in_array( $screen->base, array( 'post', 'edit' ), true );
        $is_qr_page      = false !== strpos( (string) $hook, self::PAGE_SLUG );

        if ( ! $is_product_edit && ! $is_qr_page ) {
            return;
        }

        $base = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/';

        wp_enqueue_script( 'wbi-qrcode-lib', $base . 'js/qrcode.min.js', array(), '1.0.0', true );
        wp_enqueue_script( 'wbi-qr-admin', $base . 'js/wbi-qr-admin.js', array( 'jquery', 'wbi-qrcode-lib' ), '1.0.0', true );
        wp_enqueue_style( 'wbi-qr-admin', $base . 'css/wbi-qr-admin.css', array(), '1.0.0' );

        wp_localize_script( 'wbi-qr-admin', 'wbiQrAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wbi_qr_admin' ),
            'i18n'    => array(
                'confirmRegenerate' => __( 'Al regenerar, los QR impresos anteriores dejarán de funcionar. ¿Continuar?', 'wbi-suite' ),
                'regenerated'       => __( 'Códigos QR regenerados.', 'wbi-suite' ),
                'error'             => __( 'Ocurrió un error.', 'wbi-suite' ),
            ),
        ) );
    }

    // =========================================================================
    // Admin — bulk actions
    // =========================================================================

    public function register_bulk_actions( $actions ) {
        $actions['wbi_qr_labels']     = __( '🔳 Generar etiquetas QR', 'wbi-suite' );
        $settings = self::get_settings();
        if ( $this->current_user_can_manage() && ! empty( $settings['allow_regenerate'] ) ) {
            $actions['wbi_qr_regenerate'] = __( '🔳 Regenerar códigos QR', 'wbi-suite' );
        }
        return $actions;
    }

    public function handle_bulk_actions( $redirect, $action, $ids ) {
        $ids = array_filter( array_map( 'absint', (array) $ids ) );
        if ( empty( $ids ) ) {
            return $redirect;
        }

        if ( 'wbi_qr_labels' === $action ) {
            return admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&wbi_qr_labels=1&ids=' . implode( ',', $ids ) );
        }

        if ( 'wbi_qr_regenerate' === $action ) {
            $settings = self::get_settings();
            if ( ! $this->current_user_can_manage() || empty( $settings['allow_regenerate'] ) ) {
                return $redirect;
            }
            foreach ( $ids as $id ) {
                self::get_token( $id, 'pos', true );
                self::get_token( $id, 'web', true );
            }
            return add_query_arg( 'wbi_qr_regenerated', count( $ids ), $redirect );
        }

        return $redirect;
    }

    public function bulk_admin_notices() {
        if ( ! empty( $_GET['wbi_qr_regenerated'] ) ) {
            $count = absint( $_GET['wbi_qr_regenerated'] );
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html( sprintf( __( 'Códigos QR regenerados para %d producto(s).', 'wbi-suite' ), $count ) )
            );
        }
        if ( ! empty( $_GET['wbi_qr_backfilled'] ) ) {
            $count = absint( $_GET['wbi_qr_backfilled'] );
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html( sprintf( __( 'Backfill completado: %d producto(s)/variación(es) con QR generado.', 'wbi-suite' ), $count ) )
            );
        }
        if ( isset( $_GET['wbi_qr_settings_saved'] ) ) {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html__( 'Configuración de QR de Productos guardada.', 'wbi-suite' )
            );
        }
    }

    // =========================================================================
    // Admin — page (settings + backfill + labels)
    // =========================================================================

    public function register_page() {
        add_submenu_page(
            'wbi-suite',
            'QR de Productos',
            '🔳 QR de Productos',
            'manage_woocommerce',
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );
    }

    public function render_page() {
        if ( ! $this->current_user_can_manage() ) {
            wp_die( esc_html__( 'Sin permisos.', 'wbi-suite' ) );
        }

        if ( ! empty( $_GET['wbi_qr_labels'] ) ) {
            $this->render_labels_view();
            return;
        }

        $this->render_settings_view();
    }

    private function render_settings_view() {
        $settings = self::get_settings();
        ?>
        <div class="wrap">
            <h1>🔳 QR de Productos</h1>
            <p><?php esc_html_e( 'QR únicos por producto y variación para agregado rápido al carrito en POS y en la tienda web, con etiquetas imprimibles.', 'wbi-suite' ); ?></p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="wbi_qr_save_settings">
                <?php wp_nonce_field( 'wbi_qr_save_settings' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'QR por variación', 'wbi-suite' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="variation_qrs" value="1" <?php checked( ! empty( $settings['variation_qrs'] ) ); ?>>
                            <?php esc_html_e( 'Generar códigos QR a nivel de variación', 'wbi-suite' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Cantidad por escaneo', 'wbi-suite' ); ?></th>
                        <td>
                            <input type="number" name="default_qty" min="1" max="999" value="<?php echo esc_attr( (int) $settings['default_qty'] ); ?>" class="small-text">
                            <p class="description"><?php esc_html_e( 'Cantidad agregada al carrito por cada escaneo (por defecto 1).', 'wbi-suite' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Redirección tras escaneo web', 'wbi-suite' ); ?></th>
                        <td>
                            <select name="web_redirect">
                                <option value="cart" <?php selected( $settings['web_redirect'], 'cart' ); ?>><?php esc_html_e( 'Ir al carrito', 'wbi-suite' ); ?></option>
                                <option value="shop" <?php selected( $settings['web_redirect'], 'shop' ); ?>><?php esc_html_e( 'Ir a la tienda', 'wbi-suite' ); ?></option>
                                <option value="stay" <?php selected( $settings['web_redirect'], 'stay' ); ?>><?php esc_html_e( 'Quedarse en el producto (con aviso)', 'wbi-suite' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Precio en etiquetas', 'wbi-suite' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="label_include_price" value="1" <?php checked( ! empty( $settings['label_include_price'] ) ); ?>>
                            <?php esc_html_e( 'Incluir precio en las etiquetas impresas', 'wbi-suite' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Tamaño de etiqueta', 'wbi-suite' ); ?></th>
                        <td>
                            <select name="label_size">
                                <?php foreach ( self::label_size_presets() as $key => $label ) : ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['label_size'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Regeneración de tokens', 'wbi-suite' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="allow_regenerate" value="1" <?php checked( ! empty( $settings['allow_regenerate'] ) ); ?>>
                            <?php esc_html_e( 'Permitir regenerar códigos QR (invalida los impresos anteriores)', 'wbi-suite' ); ?></label>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Guardar configuración', 'wbi-suite' ) ); ?>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Backfill del catálogo', 'wbi-suite' ); ?></h2>
            <p><?php esc_html_e( 'Genera los tokens QR faltantes para todos los productos (y variaciones, si está habilitado) existentes.', 'wbi-suite' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="wbi_qr_backfill">
                <?php wp_nonce_field( 'wbi_qr_backfill' ); ?>
                <?php submit_button( __( 'Generar QR faltantes', 'wbi-suite' ), 'secondary', 'submit', false ); ?>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Etiquetas', 'wbi-suite' ); ?></h2>
            <p><?php esc_html_e( 'Para imprimir etiquetas en lote, seleccioná productos en el listado de productos y usá la acción en lote "Generar etiquetas QR".', 'wbi-suite' ); ?></p>
        </div>
        <?php
    }

    private function render_labels_view() {
        $settings = self::get_settings();
        $ids      = isset( $_GET['ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['ids'] ) ) ) ) ) : array();
        $size     = $settings['label_size'];
        if ( ! array_key_exists( $size, self::label_size_presets() ) ) {
            $size = '50x30';
        }
        list( $w, $h ) = array_map( 'intval', explode( 'x', $size ) );

        // Expand variable products into variations when enabled.
        $targets = array();
        foreach ( $ids as $id ) {
            $product = wc_get_product( $id );
            if ( ! $product ) continue;

            if ( $product->is_type( 'variable' ) && ! empty( $settings['variation_qrs'] ) ) {
                foreach ( $product->get_children() as $variation_id ) {
                    $variation = wc_get_product( $variation_id );
                    if ( $variation ) {
                        $targets[] = $variation;
                    }
                }
            } else {
                $targets[] = $product;
            }
        }
        ?>
        <div class="wrap wbi-qr-labels-wrap">
            <h1 class="wbi-qr-no-print">🖨️ <?php esc_html_e( 'Etiquetas QR', 'wbi-suite' ); ?></h1>
            <p class="wbi-qr-no-print">
                <?php echo esc_html( sprintf( __( '%d etiqueta(s) — tamaño %s mm. Usá "Imprimir" y elegí "Guardar como PDF" para exportar.', 'wbi-suite' ), count( $targets ), str_replace( 'x', ' × ', $size ) ) ); ?>
                <button type="button" class="button button-primary" onclick="window.print();"><?php esc_html_e( 'Imprimir / Guardar PDF', 'wbi-suite' ); ?></button>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Volver', 'wbi-suite' ); ?></a>
            </p>

            <div class="wbi-qr-label-grid" style="--wbi-label-w:<?php echo esc_attr( $w ); ?>mm; --wbi-label-h:<?php echo esc_attr( $h ); ?>mm;">
                <?php foreach ( $targets as $product ) :
                    $target_id = $product->get_id();
                    $web_url   = self::get_scan_url( self::get_token( $target_id, 'web' ) );
                    $short     = strtoupper( substr( md5( $web_url ), 0, 6 ) );
                    ?>
                    <div class="wbi-qr-label">
                        <div class="wbi-qr-label-qr wbi-qr-canvas" data-url="<?php echo esc_attr( $web_url ); ?>"></div>
                        <div class="wbi-qr-label-info">
                            <div class="wbi-qr-label-name"><?php echo esc_html( $product->get_name() ); ?></div>
                            <?php if ( $product->get_sku() ) : ?>
                                <div class="wbi-qr-label-sku">SKU: <?php echo esc_html( $product->get_sku() ); ?></div>
                            <?php endif; ?>
                            <?php if ( ! empty( $settings['label_include_price'] ) ) : ?>
                                <div class="wbi-qr-label-price"><?php echo wp_kses_post( wc_price( (float) $product->get_price() ) ); ?></div>
                            <?php endif; ?>
                            <div class="wbi-qr-label-code"><?php echo esc_html( $short ); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // Admin — form handlers
    // =========================================================================

    public function handle_save_settings() {
        if ( ! $this->current_user_can_manage() ) {
            wp_die( esc_html__( 'Sin permisos.', 'wbi-suite' ) );
        }
        check_admin_referer( 'wbi_qr_save_settings' );

        $redirect_options = array( 'cart', 'shop', 'stay' );
        $web_redirect     = isset( $_POST['web_redirect'] ) ? sanitize_key( $_POST['web_redirect'] ) : 'cart';
        $label_size       = isset( $_POST['label_size'] ) ? sanitize_text_field( wp_unslash( $_POST['label_size'] ) ) : '50x30';

        $settings = array(
            'variation_qrs'       => empty( $_POST['variation_qrs'] ) ? 0 : 1,
            'default_qty'         => min( 999, max( 1, absint( $_POST['default_qty'] ?? 1 ) ) ),
            'web_redirect'        => in_array( $web_redirect, $redirect_options, true ) ? $web_redirect : 'cart',
            'label_include_price' => empty( $_POST['label_include_price'] ) ? 0 : 1,
            'label_size'          => array_key_exists( $label_size, self::label_size_presets() ) ? $label_size : '50x30',
            'allow_regenerate'    => empty( $_POST['allow_regenerate'] ) ? 0 : 1,
        );

        update_option( self::SETTINGS_OPTION, $settings );

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&wbi_qr_settings_saved=1' ) );
        exit;
    }

    public function handle_backfill() {
        if ( ! $this->current_user_can_manage() ) {
            wp_die( esc_html__( 'Sin permisos.', 'wbi-suite' ) );
        }
        check_admin_referer( 'wbi_qr_backfill' );

        $processed = self::backfill_tokens();

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&wbi_qr_backfilled=' . $processed ) );
        exit;
    }
}
