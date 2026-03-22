<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI Abandoned Carts Module
 *
 * Recuperación de carritos abandonados con captura de contacto,
 * exit-intent popup, y seguimiento por email y WhatsApp.
 */
class WBI_Abandoned_Carts_Module {

    /** @var string Nombre de la tabla custom */
    private $table;

    /** @var array Configuración del módulo */
    private $settings;

    public function __construct() {
        global $wpdb;
        $this->table    = $wpdb->prefix . 'wbi_abandoned_carts';
        $this->settings = get_option( 'wbi_abandoned_cart_settings', array() );

        // Crear tabla si no existe
        $this->maybe_create_table();

        // Menú de administración
        add_action( 'admin_menu', array( $this, 'add_submenu' ), 100 );

        // Settings del módulo
        add_action( 'admin_init', array( $this, 'register_module_settings' ) );

        // Frontend: encolar JS/CSS solo en páginas relevantes
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

        // AJAX: captura de contacto (con y sin login)
        add_action( 'wp_ajax_wbi_capture_cart_contact',        array( $this, 'ajax_capture_cart_contact' ) );
        add_action( 'wp_ajax_nopriv_wbi_capture_cart_contact', array( $this, 'ajax_capture_cart_contact' ) );

        // AJAX: actualizar datos del carrito
        add_action( 'wp_ajax_wbi_update_cart_data',        array( $this, 'ajax_update_cart_data' ) );
        add_action( 'wp_ajax_nopriv_wbi_update_cart_data', array( $this, 'ajax_update_cart_data' ) );

        // AJAX: acciones de admin
        add_action( 'wp_ajax_wbi_send_manual_reminder',   array( $this, 'ajax_send_manual_reminder' ) );
        add_action( 'wp_ajax_wbi_delete_abandoned_cart',  array( $this, 'ajax_delete_abandoned_cart' ) );
        add_action( 'wp_ajax_wbi_get_cart_detail',        array( $this, 'ajax_get_cart_detail' ) );

        // WooCommerce hooks
        add_action( 'woocommerce_cart_updated', array( $this, 'on_cart_updated' ) );
        add_action( 'woocommerce_thankyou',     array( $this, 'on_order_complete' ), 10, 1 );

        // Recovery URL
        add_action( 'init', array( $this, 'handle_recovery_url' ), 5 );

        // WP-Cron
        add_action( 'wbi_mark_abandoned_carts',   array( $this, 'cron_mark_abandoned' ) );
        add_action( 'wbi_send_cart_reminders',    array( $this, 'cron_send_reminders' ) );
        add_action( 'wbi_cleanup_expired_carts',  array( $this, 'cron_cleanup_expired' ) );

        // Registrar eventos cron al activar
        $this->schedule_crons();

        // Limpiar eventos cron al desactivar plugin (hook en shutdown si el toggle se desactiva)
        add_action( 'wbi_abandoned_carts_deactivate', array( $this, 'unschedule_crons' ) );
    }

    // =========================================================================
    // BASE DE DATOS
    // =========================================================================

    private function maybe_create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(100) NOT NULL DEFAULT '',
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            email VARCHAR(200) NOT NULL DEFAULT '',
            phone VARCHAR(50) NOT NULL DEFAULT '',
            contact_channel ENUM('email','whatsapp','both') NOT NULL DEFAULT 'email',
            cart_contents LONGTEXT NOT NULL,
            cart_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(10) NOT NULL DEFAULT '',
            recovery_url VARCHAR(500) NOT NULL DEFAULT '',
            recovery_token VARCHAR(64) NOT NULL DEFAULT '',
            status ENUM('abandoned','recovered','expired','sent_reminder_1','sent_reminder_2','sent_reminder_3') NOT NULL DEFAULT 'abandoned',
            reminder_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
            last_reminder_at DATETIME DEFAULT NULL,
            recovered_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_session (session_id),
            KEY idx_email (email),
            KEY idx_status (status),
            KEY idx_created (created_at),
            KEY idx_recovery_token (recovery_token)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // =========================================================================
    // WP-CRON
    // =========================================================================

    private function schedule_crons() {
        if ( ! wp_next_scheduled( 'wbi_mark_abandoned_carts' ) ) {
            wp_schedule_event( time(), 'wbi_15min', 'wbi_mark_abandoned_carts' );
        }
        if ( ! wp_next_scheduled( 'wbi_send_cart_reminders' ) ) {
            wp_schedule_event( time(), 'hourly', 'wbi_send_cart_reminders' );
        }
        if ( ! wp_next_scheduled( 'wbi_cleanup_expired_carts' ) ) {
            wp_schedule_event( time(), 'daily', 'wbi_cleanup_expired_carts' );
        }

        // Registrar intervalo de 15 minutos si no existe
        add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );
    }

    public function add_cron_interval( $schedules ) {
        if ( ! isset( $schedules['wbi_15min'] ) ) {
            $schedules['wbi_15min'] = array(
                'interval' => 900,
                'display'  => 'Cada 15 minutos',
            );
        }
        return $schedules;
    }

    public function unschedule_crons() {
        wp_clear_scheduled_hook( 'wbi_mark_abandoned_carts' );
        wp_clear_scheduled_hook( 'wbi_send_cart_reminders' );
        wp_clear_scheduled_hook( 'wbi_cleanup_expired_carts' );
    }

    // =========================================================================
    // FRONTEND: CSS / JS INLINE
    // =========================================================================

    public function enqueue_frontend_assets() {
        if ( ! function_exists( 'is_woocommerce' ) ) return;
        // Load on core WooCommerce pages and any WooCommerce taxonomy/tag pages
        $load = is_cart() || is_checkout() || is_product() || is_shop() || is_woocommerce();
        // Also load on the front page (may have product shortcodes/blocks)
        if ( ! $load ) {
            $load = is_front_page();
        }
        // Also load on any page that contains WooCommerce shortcodes or blocks
        if ( ! $load ) {
            global $post;
            if ( $post && (
                has_shortcode( $post->post_content, 'products' )
                || has_shortcode( $post->post_content, 'sale_products' )
                || has_shortcode( $post->post_content, 'best_selling_products' )
                || has_shortcode( $post->post_content, 'recent_products' )
                || has_shortcode( $post->post_content, 'featured_products' )
                || has_block( 'woocommerce/all-products', $post )
                || has_block( 'woocommerce/handpicked-products', $post )
                || has_block( 'woocommerce/product-best-sellers', $post )
                || has_block( 'woocommerce/product-new', $post )
                || has_block( 'woocommerce/product-on-sale', $post )
                || has_block( 'woocommerce/product-top-rated', $post )
            ) ) {
                $load = true;
            }
        }
        if ( ! $load ) return;

        $popup_title_add  = $this->get_setting( 'popup_title_add',  '🛒 ¡Guardamos tu carrito!' );
        $popup_title_exit = $this->get_setting( 'popup_title_exit', '⚠️ ¡Esperá! Tenés productos en tu carrito' );
        $popup_body_add   = $this->get_setting( 'popup_body_add',   'Dejanos tu email o WhatsApp para que puedas recuperar tu carrito si lo necesitás.' );
        $popup_body_exit  = $this->get_setting( 'popup_body_exit',  'No pierdas tus productos. Dejá tu email o WhatsApp y te enviamos un recordatorio.' );
        $show_add_popup   = $this->get_setting( 'show_add_popup',   1 );
        $show_exit_popup  = $this->get_setting( 'show_exit_popup',  1 );

        // CSS inline
        $css = '
        #wbi-cart-popup-overlay {
            display:none; position:fixed; top:0; left:0; width:100%; height:100%;
            background:rgba(0,0,0,.55); z-index:99999; align-items:center; justify-content:center;
        }
        #wbi-cart-popup-overlay.wbi-show { display:flex; }
        #wbi-cart-popup {
            background:#fff; border-radius:12px; padding:32px 28px 24px;
            max-width:420px; width:90%; box-shadow:0 8px 40px rgba(0,0,0,.22);
            position:relative; animation:wbiSlideIn .25s ease;
        }
        @keyframes wbiSlideIn {
            from { opacity:0; transform:translateY(-24px); }
            to   { opacity:1; transform:translateY(0); }
        }
        #wbi-cart-popup h3 { margin:0 0 8px; font-size:18px; color:#1d2327; }
        #wbi-cart-popup p  { margin:0 0 20px; color:#50575e; font-size:14px; line-height:1.55; }
        .wbi-popup-field   { position:relative; margin-bottom:14px; }
        .wbi-popup-field span { position:absolute; left:12px; top:50%; transform:translateY(-50%); font-size:16px; }
        .wbi-popup-field input {
            width:100%; padding:10px 12px 10px 36px; border:1px solid #c3c4c7;
            border-radius:6px; font-size:14px; box-sizing:border-box;
            transition:border-color .15s;
        }
        .wbi-popup-field input:focus { border-color:#2271b1; outline:none; box-shadow:0 0 0 2px rgba(34,113,177,.15); }
        #wbi-popup-save {
            width:100%; padding:12px; background:#2271b1; color:#fff; border:none;
            border-radius:6px; font-size:15px; font-weight:600; cursor:pointer;
            transition:background .15s;
        }
        #wbi-popup-save:hover { background:#135e96; }
        #wbi-popup-skip {
            display:block; text-align:center; margin-top:14px; color:#787c82;
            font-size:12px; cursor:pointer; text-decoration:underline;
        }
        #wbi-popup-skip:hover { color:#1d2327; }
        #wbi-popup-close {
            position:absolute; top:12px; right:14px; background:none; border:none;
            font-size:22px; cursor:pointer; color:#787c82; line-height:1;
        }
        .wbi-popup-divider { text-align:center; color:#c3c4c7; font-size:12px; margin:12px 0 0; }
        ';
        wp_register_style( 'wbi-abandoned-carts', false );
        wp_enqueue_style( 'wbi-abandoned-carts' );
        wp_add_inline_style( 'wbi-abandoned-carts', $css );

        // JS inline
        wp_register_script( 'wbi-abandoned-carts', false, array( 'jquery' ), null, true );
        wp_enqueue_script( 'wbi-abandoned-carts' );

        $js_vars = array(
            'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( 'wbi_cart_nonce' ),
            'showAddPopup'     => (bool) $show_add_popup,
            'showExitPopup'    => (bool) $show_exit_popup,
            'titleAdd'         => esc_js( $popup_title_add ),
            'titleExit'        => esc_js( $popup_title_exit ),
            'bodyAdd'          => esc_js( $popup_body_add ),
            'bodyExit'         => esc_js( $popup_body_exit ),
            'isHttps'          => is_ssl(),
        );

        $popup_html = '
<div id="wbi-cart-popup-overlay">
  <div id="wbi-cart-popup">
    <button id="wbi-popup-close" aria-label="Cerrar">&times;</button>
    <h3 id="wbi-popup-title"></h3>
    <p id="wbi-popup-body"></p>
    <div class="wbi-popup-field"><span>📧</span><input type="email" id="wbi-popup-email" placeholder="Email"></div>
    <div class="wbi-popup-field"><span>📱</span><input type="tel" id="wbi-popup-phone" placeholder="WhatsApp (ej: 1150001234)"></div>
    <button id="wbi-popup-save">Guardar ✓</button>
    <div class="wbi-popup-divider">─── o continuar sin guardar ───</div>
    <span id="wbi-popup-skip">No, gracias</span>
  </div>
</div>';

        $js = '
(function($){
    var WBI = ' . wp_json_encode( $js_vars ) . ';
    var $overlay, captured = false, exitShown = false;

    function getCookie(name) {
        var v = document.cookie.match("(^|;)\\s*" + name + "\\s*=\\s*([^;]+)");
        return v ? v.pop() : "";
    }
    function setCookie(name, val, days) {
        var d = new Date(); d.setTime(d.getTime() + days*86400000);
        var secure = WBI.isHttps ? ";Secure" : "";
        document.cookie = name + "=" + val + ";expires=" + d.toUTCString() + ";path=/;SameSite=Lax" + secure;
    }

    function buildPopup() {
        if ( $("#wbi-cart-popup-overlay").length ) return;
        $("body").append(' . wp_json_encode( $popup_html ) . ');
        $overlay = $("#wbi-cart-popup-overlay");

        $overlay.on("click", function(e){ if($(e.target).is($overlay)) closePopup(); });
        $("#wbi-popup-close, #wbi-popup-skip").on("click", closePopup);
        $("#wbi-popup-save").on("click", saveContact);
    }

    function openPopup(type) {
        if ( getCookie("wbi_cart_contact_captured") === "1" ) return;
        buildPopup();
        var title = type === "exit" ? WBI.titleExit : WBI.titleAdd;
        var body  = type === "exit" ? WBI.bodyExit  : WBI.bodyAdd;
        $("#wbi-popup-title").text(title);
        $("#wbi-popup-body").text(body);
        $overlay.addClass("wbi-show");
        if ( type === "exit" ) {
            sessionStorage.setItem("wbi_exit_shown","1");
        }
    }

    function closePopup() {
        if ( $overlay ) $overlay.removeClass("wbi-show");
    }

    function saveContact() {
        var email = $.trim($("#wbi-popup-email").val());
        var phone = $.trim($("#wbi-popup-phone").val());
        if ( !email && !phone ) { alert("Por favor ingresá al menos un email o WhatsApp."); return; }
        $.post(WBI.ajaxUrl, {
            action: "wbi_capture_cart_contact",
            nonce:  WBI.nonce,
            email:  email,
            phone:  phone
        }, function(res){
            if ( res.success ) {
                setCookie("wbi_cart_contact_captured","1",30);
                captured = true;
                closePopup();
            }
        });
    }

    // --- Agregar al carrito ---
    if ( WBI.showAddPopup ) {
        // AJAX add-to-cart (catalog/shop pages)
        $(document.body).on("added_to_cart", function(){
            if ( getCookie("wbi_cart_contact_captured") !== "1" ) {
                setTimeout(function(){ openPopup("add"); }, 600);
            }
        });
        // Page-reload add-to-cart (single product page — form submit with redirect)
        var urlParams = new URLSearchParams(window.location.search);
        if ( urlParams.has("add-to-cart") && getCookie("wbi_cart_contact_captured") !== "1" ) {
            $(function(){ setTimeout(function(){ openPopup("add"); }, 800); });
        }
        // Fragment refresh signals (mini-cart update after AJAX add-to-cart)
        $(document.body).on("wc_fragments_refreshed wc_fragments_loaded", function(){
            if ( getCookie("wbi_cart_contact_captured") !== "1" ) {
                var $badge = $(".cart-contents .count, .mini-cart-count, .cart-count");
                if ( $badge.length && parseInt($badge.text()) > 0 ) {
                    setTimeout(function(){ openPopup("add"); }, 600);
                }
            }
        });
    }

    // --- Exit intent ---
    if ( WBI.showExitPopup ) {
        document.addEventListener("mouseleave", function(e){
            if ( e.clientY <= 0 && !sessionStorage.getItem("wbi_exit_shown") && getCookie("wbi_cart_contact_captured") !== "1" ) {
                openPopup("exit");
            }
        });
        document.addEventListener("visibilitychange", function(){
            if ( document.visibilityState === "hidden" && !sessionStorage.getItem("wbi_exit_shown") && getCookie("wbi_cart_contact_captured") !== "1" ) {
                // Mark intent to show popup when user returns to the page
                sessionStorage.setItem("wbi_exit_pending","1");
                sessionStorage.setItem("wbi_exit_shown","1");
            }
            if ( document.visibilityState === "visible" && sessionStorage.getItem("wbi_exit_pending") === "1" ) {
                sessionStorage.removeItem("wbi_exit_pending");
                openPopup("exit");
            }
        });
    }

    // --- Captura email de checkout con debounce ---
    var billingDebounce;
    $(document).on("change blur keyup", "#billing_email", function(){
        clearTimeout(billingDebounce);
        var val = $.trim($(this).val());
        if ( !val ) return;
        billingDebounce = setTimeout(function(){
            $.post(WBI.ajaxUrl, {
                action: "wbi_capture_cart_contact",
                nonce:  WBI.nonce,
                email:  val,
                phone:  ""
            });
        }, 2000);
    });

})(jQuery);
';
        wp_add_inline_script( 'wbi-abandoned-carts', $js );
    }

    // =========================================================================
    // AJAX: CAPTURA DE CONTACTO
    // =========================================================================

    public function ajax_capture_cart_contact() {
        check_ajax_referer( 'wbi_cart_nonce', 'nonce' );

        $email = sanitize_email( wp_unslash( isset( $_POST['email'] ) ? $_POST['email'] : '' ) );
        $phone = sanitize_text_field( wp_unslash( isset( $_POST['phone'] ) ? $_POST['phone'] : '' ) );

        if ( empty( $email ) && empty( $phone ) ) {
            wp_send_json_error( array( 'msg' => 'Email o teléfono requerido.' ) );
        }

        // Determinar canal
        $channel = 'email';
        if ( ! empty( $email ) && ! empty( $phone ) ) {
            $channel = 'both';
        } elseif ( ! empty( $phone ) ) {
            $channel = 'whatsapp';
        }

        global $wpdb;
        $session_id = $this->get_session_id();
        $user_id    = get_current_user_id();
        $now        = gmdate( 'Y-m-d H:i:s' );

        // Buscar registro existente por session_id
        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE session_id = %s ORDER BY created_at DESC LIMIT 1",
            $session_id
        ) );

        $cart_contents = $this->serialize_cart();
        $cart_total    = function_exists( 'WC' ) && WC()->cart ? (float) WC()->cart->get_total( '' ) : 0.0;
        $currency      = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
        $token         = bin2hex( random_bytes( 32 ) );
        $recovery_url  = add_query_arg( 'wbi_recover_cart', $token, home_url( '/' ) );

        if ( $existing_id ) {
            $wpdb->update(
                $this->table,
                array(
                    'email'           => $email,
                    'phone'           => $phone,
                    'contact_channel' => $channel,
                    'cart_contents'   => $cart_contents,
                    'cart_total'      => $cart_total,
                    'currency'        => $currency,
                    'updated_at'      => $now,
                ),
                array( 'id' => intval( $existing_id ) ),
                array( '%s', '%s', '%s', '%s', '%f', '%s', '%s' ),
                array( '%d' )
            );
        } else {
            $wpdb->insert(
                $this->table,
                array(
                    'session_id'      => $session_id,
                    'user_id'         => $user_id,
                    'email'           => $email,
                    'phone'           => $phone,
                    'contact_channel' => $channel,
                    'cart_contents'   => $cart_contents,
                    'cart_total'      => $cart_total,
                    'currency'        => $currency,
                    'recovery_url'    => $recovery_url,
                    'recovery_token'  => $token,
                    'status'          => 'abandoned',
                    'reminder_count'  => 0,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ),
                array( '%s', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
            );
        }

        wp_send_json_success( array( 'captured' => true ) );
    }

    // =========================================================================
    // AJAX: ACTUALIZAR DATOS DEL CARRITO
    // =========================================================================

    public function ajax_update_cart_data() {
        check_ajax_referer( 'wbi_cart_nonce', 'nonce' );

        global $wpdb;
        $session_id = $this->get_session_id();

        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE session_id = %s ORDER BY created_at DESC LIMIT 1",
            $session_id
        ) );

        if ( ! $existing_id ) {
            wp_send_json_error( array( 'msg' => 'No hay carrito registrado para esta sesión.' ) );
        }

        $cart_contents = $this->serialize_cart();
        $cart_total    = function_exists( 'WC' ) && WC()->cart ? (float) WC()->cart->get_total( '' ) : 0.0;
        $currency      = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';

        $wpdb->update(
            $this->table,
            array(
                'cart_contents' => $cart_contents,
                'cart_total'    => $cart_total,
                'currency'      => $currency,
                'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
            ),
            array( 'id' => intval( $existing_id ) ),
            array( '%s', '%f', '%s', '%s' ),
            array( '%d' )
        );

        wp_send_json_success();
    }

    // =========================================================================
    // AJAX: ACCIONES DE ADMIN
    // =========================================================================

    public function ajax_send_manual_reminder() {
        check_ajax_referer( 'wbi_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'msg' => 'Sin permiso.' ) );
        }

        $id = absint( isset( $_POST['cart_id'] ) ? $_POST['cart_id'] : 0 );
        if ( ! $id ) {
            wp_send_json_error( array( 'msg' => 'ID inválido.' ) );
        }

        global $wpdb;
        $cart = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id
        ) );

        if ( ! $cart ) {
            wp_send_json_error( array( 'msg' => 'Carrito no encontrado.' ) );
        }

        $result = $this->send_reminder( $cart, 1, true );
        if ( $result ) {
            wp_send_json_success( array( 'msg' => 'Recordatorio enviado correctamente.' ) );
        } else {
            wp_send_json_error( array( 'msg' => 'No se pudo enviar el recordatorio.' ) );
        }
    }

    public function ajax_delete_abandoned_cart() {
        check_ajax_referer( 'wbi_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'msg' => 'Sin permiso.' ) );
        }

        $id = absint( isset( $_POST['cart_id'] ) ? $_POST['cart_id'] : 0 );
        if ( ! $id ) {
            wp_send_json_error( array( 'msg' => 'ID inválido.' ) );
        }

        global $wpdb;
        $wpdb->delete( $this->table, array( 'id' => $id ), array( '%d' ) );
        wp_send_json_success( array( 'msg' => 'Registro eliminado.' ) );
    }

    public function ajax_get_cart_detail() {
        check_ajax_referer( 'wbi_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'msg' => 'Sin permiso.' ) );
        }

        $id = absint( isset( $_GET['cart_id'] ) ? $_GET['cart_id'] : 0 );
        if ( ! $id ) {
            wp_send_json_error( array( 'msg' => 'ID inválido.' ) );
        }

        global $wpdb;
        $cart = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id
        ), ARRAY_A );

        if ( ! $cart ) {
            wp_send_json_error( array( 'msg' => 'Carrito no encontrado.' ) );
        }

        $items = json_decode( $cart['cart_contents'], true );
        ob_start();
        echo '<h3 style="margin-top:0">Detalle del carrito #' . intval( $cart['id'] ) . '</h3>';
        echo '<p><strong>Email:</strong> ' . esc_html( $cart['email'] ) . ' | <strong>Teléfono:</strong> ' . esc_html( $cart['phone'] ) . '</p>';
        echo '<p><strong>Canal:</strong> ' . esc_html( $cart['contact_channel'] ) . ' | <strong>Estado:</strong> ' . esc_html( $cart['status'] ) . '</p>';
        echo '<p><strong>Total:</strong> ' . wp_kses_post( wc_price( $cart['cart_total'] ) ) . '</p>';
        if ( is_array( $items ) && ! empty( $items ) ) {
            echo '<table class="widefat striped"><thead><tr><th>Producto</th><th>Qty</th><th>Precio</th></tr></thead><tbody>';
            foreach ( $items as $item ) {
                echo '<tr>';
                echo '<td>' . esc_html( $item['name'] ?? '' ) . '</td>';
                echo '<td>' . intval( $item['qty'] ?? 0 ) . '</td>';
                echo '<td>' . wp_kses_post( wc_price( $item['price'] ?? 0 ) ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>Sin productos registrados.</p>';
        }
        $html = ob_get_clean();
        wp_send_json_success( array( 'html' => $html ) );
    }

    // =========================================================================
    // WOOCOMMERCE HOOKS
    // =========================================================================

    public function on_cart_updated() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) return;

        global $wpdb;
        $session_id = $this->get_session_id();

        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE session_id = %s ORDER BY created_at DESC LIMIT 1",
            $session_id
        ) );

        if ( ! $existing_id ) return;

        $cart_contents = $this->serialize_cart();
        $cart_total    = (float) WC()->cart->get_total( '' );
        $currency      = get_woocommerce_currency();

        $wpdb->update(
            $this->table,
            array(
                'cart_contents' => $cart_contents,
                'cart_total'    => $cart_total,
                'currency'      => $currency,
                'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
            ),
            array( 'id' => intval( $existing_id ) ),
            array( '%s', '%f', '%s', '%s' ),
            array( '%d' )
        );
    }

    public function on_order_complete( $order_id ) {
        if ( ! $order_id ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        global $wpdb;

        $email      = $order->get_billing_email();
        $session_id = $this->get_session_id();

        // Buscar por email o session_id
        $cart = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$this->table}
             WHERE (email = %s OR session_id = %s)
               AND status IN ('abandoned','sent_reminder_1','sent_reminder_2','sent_reminder_3')
             ORDER BY created_at DESC LIMIT 1",
            $email,
            $session_id
        ) );

        if ( $cart ) {
            $wpdb->update(
                $this->table,
                array(
                    'status'       => 'recovered',
                    'recovered_at' => gmdate( 'Y-m-d H:i:s' ),
                    'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
                ),
                array( 'id' => intval( $cart->id ) ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );
        }
    }

    // =========================================================================
    // RECOVERY URL
    // =========================================================================

    public function handle_recovery_url() {
        $token = sanitize_text_field( isset( $_GET['wbi_recover_cart'] ) ? wp_unslash( $_GET['wbi_recover_cart'] ) : '' );
        if ( empty( $token ) ) return;

        global $wpdb;
        $cart = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE recovery_token = %s LIMIT 1",
            $token
        ) );

        if ( ! $cart ) return;

        // Restaurar carrito
        if ( function_exists( 'WC' ) && WC()->cart ) {
            WC()->cart->empty_cart();
            $items = json_decode( $cart->cart_contents, true );
            if ( is_array( $items ) ) {
                foreach ( $items as $item ) {
                    $product_id   = absint( $item['product_id'] ?? 0 );
                    $variation_id = absint( $item['variation_id'] ?? 0 );
                    $qty          = absint( $item['qty'] ?? 1 );
                    if ( $product_id ) {
                        WC()->cart->add_to_cart( $product_id, $qty, $variation_id );
                    }
                }
            }

            // Marcar como recuperado
            $wpdb->update(
                $this->table,
                array(
                    'status'       => 'recovered',
                    'recovered_at' => gmdate( 'Y-m-d H:i:s' ),
                    'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
                ),
                array( 'id' => intval( $cart->id ) ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );

            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }
    }

    // =========================================================================
    // WP-CRON JOBS
    // =========================================================================

    public function cron_mark_abandoned() {
        global $wpdb;
        $minutes = absint( $this->get_setting( 'abandonment_threshold', 30 ) );
        $cutoff  = gmdate( 'Y-m-d H:i:s', time() - $minutes * 60 );

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table}
             SET status = 'abandoned', updated_at = %s
             WHERE status = 'abandoned'
               AND updated_at <= %s
               AND cart_contents != '[]'
               AND cart_contents != ''",
            gmdate( 'Y-m-d H:i:s' ),
            $cutoff
        ) );
    }

    public function cron_send_reminders() {
        global $wpdb;
        $now = time();

        for ( $num = 1; $num <= 3; $num++ ) {
            $enabled = $this->get_setting( "reminder_{$num}_enabled", 1 );
            if ( ! $enabled ) continue;

            $hours  = absint( $this->get_setting( "reminder_{$num}_hours", $num === 1 ? 1 : ( $num === 2 ? 24 : 72 ) ) );
            $cutoff = gmdate( 'Y-m-d H:i:s', $now - $hours * 3600 );

            $prev_status = $num === 1 ? 'abandoned' : "sent_reminder_" . ( $num - 1 );
            $new_status  = "sent_reminder_{$num}";

            $carts = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE status = %s
                   AND updated_at <= %s
                 LIMIT 50",
                $prev_status,
                $cutoff
            ) );

            foreach ( $carts as $cart ) {
                $this->send_reminder( $cart, $num );
                $wpdb->update(
                    $this->table,
                    array(
                        'status'          => $new_status,
                        'reminder_count'  => intval( $cart->reminder_count ) + 1,
                        'last_reminder_at'=> gmdate( 'Y-m-d H:i:s' ),
                        'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
                    ),
                    array( 'id' => intval( $cart->id ) ),
                    array( '%s', '%d', '%s', '%s' ),
                    array( '%d' )
                );
            }
        }
    }

    public function cron_cleanup_expired() {
        global $wpdb;
        $days   = absint( $this->get_setting( 'expiration_days', 30 ) );
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * 86400 );

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table} SET status = 'expired', updated_at = %s
             WHERE status NOT IN ('recovered','expired')
               AND created_at <= %s",
            gmdate( 'Y-m-d H:i:s' ),
            $cutoff
        ) );

        // Limpiar cupones auto-generados y expirados
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $expired_coupons = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wbi_auto_generated' AND pm.meta_value = '1'
                 INNER JOIN {$wpdb->postmeta} pe ON p.ID = pe.post_id AND pe.meta_key = 'date_expires'
                 WHERE p.post_type = 'shop_coupon'
                   AND pe.meta_value != ''
                   AND CAST(pe.meta_value AS UNSIGNED) < %d",
                time()
            )
        );
        foreach ( $expired_coupons as $coupon_id ) {
            wp_delete_post( intval( $coupon_id ), true );
        }
    }

    // =========================================================================
    // ENVÍO DE RECORDATORIOS
    // =========================================================================

    private function send_reminder( $cart, $num, $manual = false ) {
        $channel = $cart->contact_channel;
        $sent    = false;

        if ( in_array( $channel, array( 'email', 'both' ), true ) && ! empty( $cart->email ) ) {
            $sent = $this->send_email_reminder( $cart, $num );
        }

        if ( in_array( $channel, array( 'whatsapp', 'both' ), true ) && ! empty( $cart->phone ) ) {
            $this->store_whatsapp_reminder( $cart, $num );
            $sent = true;
        }

        return $sent;
    }

    private function send_email_reminder( $cart, $num ) {
        $default_templates = array(
            1 => '¡Hola {name}! Dejaste productos en tu carrito en {site_name}. ¿Querés completar tu compra? {recovery_url}',
            2 => '¡{name}, tus productos te están esperando! No los pierdas: {recovery_url}',
            3 => 'Último aviso: tu carrito en {site_name} vence pronto. Aprovechá ahora con un {coupon_discount} usando el código {coupon_code}: {recovery_url}',
        );
        $default_subjects = array(
            1 => '¡Dejaste productos en tu carrito!',
            2 => 'Tus productos te están esperando 🛒',
            3 => 'Último aviso: tu carrito vence pronto ⚠️',
        );

        $message_template = $this->get_setting( "reminder_{$num}_email_template", $default_templates[ $num ] );
        $subject_template = $this->get_setting( "reminder_{$num}_subject", $default_subjects[ $num ] );

        $from_name  = $this->get_setting( 'sender_name',  get_bloginfo( 'name' ) );
        $from_email = $this->get_setting( 'sender_email', get_option( 'admin_email' ) );

        $items       = json_decode( $cart->cart_contents, true );
        $items_html  = $this->build_items_html( $items );
        $items_text  = $this->build_items_text( $items );
        $user_name   = $this->get_user_display_name( $cart );

        $placeholders = array(
            '{name}'             => $user_name,
            '{email}'            => $cart->email,
            '{cart_items}'       => $items_text,
            '{cart_total}'       => strip_tags( wc_price( $cart->cart_total ) ),
            '{recovery_url}'     => $cart->recovery_url,
            '{site_name}'        => get_bloginfo( 'name' ),
            '{site_url}'         => home_url( '/' ),
            '{coupon_code}'      => $this->generate_coupon( $cart, $num ),
            '{coupon_discount}'  => $this->get_coupon_discount_text( $num ),
        );

        $subject = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $subject_template );
        $message_body = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $message_template );

        $html = $this->build_email_html( $user_name, $items_html, $cart, $message_body );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . sanitize_text_field( $from_name ) . ' <' . sanitize_email( $from_email ) . '>',
        );

        return wp_mail( $cart->email, $subject, $html, $headers );
    }

    private function store_whatsapp_reminder( $cart, $num ) {
        // Si el módulo WhatsApp está activo, podría usarse su sistema de plantillas.
        // Por ahora almacenamos el link wa.me para envío manual desde admin.
        $default_templates = array(
            1 => 'Hola {name}, dejaste productos en {site_name}. Completá tu compra: {recovery_url}',
            2 => '{name}, tus productos te están esperando en {site_name}: {recovery_url}',
            3 => 'Último aviso {name}: tu carrito en {site_name} vence pronto. Usá el código {coupon_code} para {coupon_discount}. Aprovechá: {recovery_url}',
        );

        $template = $this->get_setting( "reminder_{$num}_whatsapp_template", $default_templates[ $num ] );
        $user_name = $this->get_user_display_name( $cart );

        $placeholders = array(
            '{name}'             => $user_name,
            '{email}'            => $cart->email,
            '{recovery_url}'     => $cart->recovery_url,
            '{site_name}'        => get_bloginfo( 'name' ),
            '{site_url}'         => home_url( '/' ),
            '{coupon_code}'      => $this->generate_coupon( $cart, $num ),
            '{coupon_discount}'  => $this->get_coupon_discount_text( $num ),
        );

        $message  = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $template );
        $wa_phone = preg_replace( '/[^0-9]/', '', $cart->phone );
        $wa_link  = 'https://wa.me/' . $wa_phone . '?text=' . rawurlencode( $message );

        // Guardamos el link en recovery_url como referencia (o podría guardarse en metadata)
        // Para esta implementación almacenamos en un campo de notas via meta update si fuera necesario.
        // El link wa.me queda disponible en el panel de administración.
        return $wa_link;
    }

    // =========================================================================
    // CUPONES DE DESCUENTO
    // =========================================================================

    /**
     * Genera un cupón WooCommerce para el carrito y reminder indicados.
     * Si ya existe un cupón para ese carrito+reminder, devuelve el código existente.
     *
     * @param object $cart Registro del carrito abandonado.
     * @param int    $num  Número de reminder (1, 2 o 3).
     * @return string Código del cupón, o '' si los cupones están desactivados para este reminder.
     */
    private function generate_coupon( $cart, $num ) {
        if ( ! $this->get_setting( "reminder_{$num}_coupon_enabled", 0 ) ) {
            return '';
        }

        // Verificar si ya existe un cupón para este carrito y reminder
        $existing = get_posts( array(
            'post_type'      => 'shop_coupon',
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'   => '_wbi_abandoned_cart_id',
                    'value' => intval( $cart->id ),
                    'type'  => 'NUMERIC',
                ),
                array(
                    'key'   => '_wbi_reminder_num',
                    'value' => intval( $num ),
                    'type'  => 'NUMERIC',
                ),
            ),
        ) );

        if ( ! empty( $existing ) ) {
            return $existing[0]->post_title;
        }

        // Generar código único
        $prefix = $this->get_setting( "reminder_{$num}_coupon_prefix", 'WBI-CART-' );
        do {
            $code = strtoupper( $prefix . wp_generate_password( 8, false ) );
        } while ( get_page_by_title( $code, OBJECT, 'shop_coupon' ) );

        $discount_type  = $this->get_setting( "reminder_{$num}_coupon_type", 'percent' );
        $amount         = $this->get_setting( "reminder_{$num}_coupon_amount", 10 );
        $expiry_days    = absint( $this->get_setting( "reminder_{$num}_coupon_expiry_days", 3 ) );
        $min_amount     = $this->get_setting( "reminder_{$num}_coupon_min_amount", 0 );
        $expiry_date    = gmdate( 'Y-m-d', time() + $expiry_days * DAY_IN_SECONDS );

        $coupon_id = wp_insert_post( array(
            'post_title'   => $code,
            'post_type'    => 'shop_coupon',
            'post_status'  => 'publish',
            'post_excerpt' => sprintf(
                'Cupón auto-generado por wooErp para carrito abandonado #%d',
                intval( $cart->id )
            ),
        ) );

        if ( is_wp_error( $coupon_id ) || ! $coupon_id ) {
            return '';
        }

        update_post_meta( $coupon_id, 'discount_type',          $discount_type );
        update_post_meta( $coupon_id, 'coupon_amount',          floatval( $amount ) );
        update_post_meta( $coupon_id, 'minimum_amount',         floatval( $min_amount ) );
        update_post_meta( $coupon_id, 'date_expires',           strtotime( $expiry_date ) );
        update_post_meta( $coupon_id, 'individual_use',         'yes' );
        update_post_meta( $coupon_id, 'usage_limit',            1 );
        update_post_meta( $coupon_id, 'usage_limit_per_user',   1 );
        update_post_meta( $coupon_id, '_wbi_abandoned_cart_id', intval( $cart->id ) );
        update_post_meta( $coupon_id, '_wbi_reminder_num',      intval( $num ) );
        update_post_meta( $coupon_id, '_wbi_auto_generated',    1 );

        return $code;
    }

    /**
     * Retorna el texto descriptivo del descuento para un reminder.
     *
     * @param int $num Número de reminder.
     * @return string Ej: "10% de descuento" o "$500,00 de descuento".
     */
    private function get_coupon_discount_text( $num ) {
        if ( ! $this->get_setting( "reminder_{$num}_coupon_enabled", 0 ) ) {
            return '';
        }
        $type   = $this->get_setting( "reminder_{$num}_coupon_type", 'percent' );
        $amount = $this->get_setting( "reminder_{$num}_coupon_amount", 10 );
        if ( $type === 'percent' ) {
            return $amount . '% de descuento';
        }
        return strip_tags( wc_price( $amount ) ) . ' de descuento';
    }

    private function build_email_html( $user_name, $items_html, $cart, $message_body ) {
        $site_name    = esc_html( get_bloginfo( 'name' ) );
        $recovery_url = esc_url( $cart->recovery_url );
        $total        = wc_price( $cart->cart_total );
        $unsubscribe  = esc_url( add_query_arg( array(
            'wbi_unsub'  => '1',
            'wbi_token'  => $cart->recovery_token,
        ), home_url( '/' ) ) );

        $logo_url = '';
        $logo_id  = get_theme_mod( 'custom_logo' );
        if ( $logo_id ) {
            $logo_data = wp_get_attachment_image_src( $logo_id, 'full' );
            if ( $logo_data ) {
                $logo_url = $logo_data[0];
            }
        }

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tu carrito te espera</title></head>
<body style="margin:0;padding:0;background:#f1f1f1;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f1f1;padding:30px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">
      <!-- Header -->
      <tr><td style="background:#2271b1;padding:28px;text-align:center;">
        <?php if ( $logo_url ) : ?>
          <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo $site_name; ?>" style="max-height:60px;max-width:200px;" />
        <?php else : ?>
          <h2 style="color:#fff;margin:0;font-size:24px;"><?php echo $site_name; ?></h2>
        <?php endif; ?>
      </td></tr>
      <!-- Body -->
      <tr><td style="padding:32px 28px 20px;">
        <h2 style="color:#1d2327;margin:0 0 12px;font-size:22px;">¡Hola, <?php echo esc_html( $user_name ); ?>!</h2>
        <p style="color:#50575e;font-size:15px;line-height:1.6;margin:0 0 24px;"><?php echo nl2br( esc_html( $message_body ) ); ?></p>
        <!-- Products -->
        <?php echo $items_html; ?>
        <!-- Total -->
        <table width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0;">
          <tr>
            <td style="font-size:16px;font-weight:bold;color:#1d2327;padding:12px 0;border-top:2px solid #e0e0e0;">Total del carrito:</td>
            <td align="right" style="font-size:18px;font-weight:bold;color:#2271b1;padding:12px 0;border-top:2px solid #e0e0e0;"><?php echo $total; ?></td>
          </tr>
        </table>
        <!-- CTA Button -->
        <table width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;">
          <tr><td align="center">
            <a href="<?php echo $recovery_url; ?>" style="display:inline-block;background:#2271b1;color:#fff;text-decoration:none;padding:16px 40px;border-radius:6px;font-size:16px;font-weight:bold;">
              🛒 Completar mi compra
            </a>
          </td></tr>
        </table>
      </td></tr>
      <!-- Footer -->
      <tr><td style="background:#f6f7f7;padding:20px 28px;text-align:center;border-top:1px solid #e0e0e0;">
        <p style="color:#787c82;font-size:12px;margin:0 0 8px;"><?php echo $site_name; ?> | <a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="color:#2271b1;">Ir a la tienda</a></p>
        <p style="color:#c3c4c7;font-size:11px;margin:0;"><a href="<?php echo $unsubscribe; ?>" style="color:#c3c4c7;">No quiero más recordatorios</a></p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    private function build_items_html( $items ) {
        if ( ! is_array( $items ) || empty( $items ) ) return '';
        $html = '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">';
        foreach ( $items as $item ) {
            $name     = esc_html( $item['name'] ?? '' );
            $qty      = intval( $item['qty'] ?? 0 );
            $price    = isset( $item['price'] ) ? wc_price( $item['price'] ) : '';
            $img      = isset( $item['image_url'] ) ? '<img src="' . esc_url( $item['image_url'] ) . '" style="width:50px;height:50px;object-fit:cover;border-radius:4px;" />' : '';
            $html .= '<tr style="border-bottom:1px solid #f0f0f0;">';
            $html .= '<td style="padding:10px 0;width:60px;">' . $img . '</td>';
            $html .= '<td style="padding:10px;color:#1d2327;font-size:14px;">' . $name . '<br><span style="color:#787c82;font-size:12px;">Cantidad: ' . $qty . '</span></td>';
            $html .= '<td style="padding:10px;text-align:right;font-size:14px;font-weight:bold;color:#2271b1;">' . $price . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
        return $html;
    }

    private function build_items_text( $items ) {
        if ( ! is_array( $items ) || empty( $items ) ) return '';
        $lines = array();
        foreach ( $items as $item ) {
            $lines[] = sprintf( '%s x%d', $item['name'] ?? '', intval( $item['qty'] ?? 0 ) );
        }
        return implode( ', ', $lines );
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function get_session_id() {
        if ( function_exists( 'WC' ) && WC()->session ) {
            $sid = WC()->session->get_customer_id();
            if ( $sid ) return (string) $sid;
        }
        // Fallback: use a first-party cookie managed by WordPress conventions
        $cookie_name = 'wbi_cart_sid';
        if ( isset( $_COOKIE[ $cookie_name ] ) ) {
            return sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
        }
        $new_sid = bin2hex( random_bytes( 16 ) );
        $cookie_path   = defined( 'COOKIEPATH' )   ? COOKIEPATH   : '/';
        $cookie_domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
        setcookie( $cookie_name, $new_sid, time() + 86400 * 30, $cookie_path, $cookie_domain, is_ssl(), true );
        return $new_sid;
    }

    private function serialize_cart() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) return '[]';
        $items = array();
        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            $product    = $cart_item['data'];
            $product_id = $cart_item['product_id'];
            $image_url  = '';
            $thumb_id   = get_post_thumbnail_id( $product_id );
            if ( $thumb_id ) {
                $img      = wp_get_attachment_image_src( $thumb_id, 'thumbnail' );
                $image_url = $img ? $img[0] : '';
            }
            $items[] = array(
                'product_id'   => $product_id,
                'variation_id' => $cart_item['variation_id'] ?? 0,
                'name'         => $product->get_name(),
                'qty'          => $cart_item['quantity'],
                'price'        => $product->get_price(),
                'image_url'    => $image_url,
            );
        }
        return wp_json_encode( $items );
    }

    private function get_user_display_name( $cart ) {
        if ( $cart->user_id ) {
            $user = get_userdata( $cart->user_id );
            if ( $user ) return $user->first_name ? $user->first_name : $user->display_name;
        }
        if ( ! empty( $cart->email ) ) {
            return explode( '@', $cart->email )[0];
        }
        return 'cliente';
    }

    private function get_setting( $key, $default = '' ) {
        return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $default;
    }

    // =========================================================================
    // ADMIN MENU & SETTINGS
    // =========================================================================

    public function add_submenu() {
        add_submenu_page(
            'wbi-dashboard-view',
            'Carritos Abandonados',
            '🛒 Carritos Abandonados',
            'manage_woocommerce',
            'wbi-abandoned-carts',
            array( $this, 'render_admin_page' )
        );
    }

    public function register_module_settings() {
        register_setting( 'wbi_abandoned_cart_group', 'wbi_abandoned_cart_settings', array(
            'sanitize_callback' => array( $this, 'sanitize_module_settings' ),
        ) );
    }

    public function sanitize_module_settings( $input ) {
        $clean = array();

        // Single-line text fields
        $single_fields = array(
            'sender_name', 'sender_email',
            'popup_title_add', 'popup_title_exit',
            'reminder_1_subject', 'reminder_2_subject', 'reminder_3_subject',
        );
        foreach ( $single_fields as $f ) {
            $clean[ $f ] = sanitize_text_field( wp_unslash( $input[ $f ] ?? '' ) );
        }

        // Multi-line text fields (templates, popup body)
        $textarea_fields = array(
            'popup_body_add', 'popup_body_exit',
            'reminder_1_email_template', 'reminder_2_email_template', 'reminder_3_email_template',
            'reminder_1_whatsapp_template', 'reminder_2_whatsapp_template', 'reminder_3_whatsapp_template',
        );
        foreach ( $textarea_fields as $f ) {
            $clean[ $f ] = sanitize_textarea_field( wp_unslash( $input[ $f ] ?? '' ) );
        }

        $int_fields = array(
            'abandonment_threshold', 'expiration_days',
            'reminder_1_hours', 'reminder_2_hours', 'reminder_3_hours',
            'reminder_1_coupon_expiry_days', 'reminder_2_coupon_expiry_days', 'reminder_3_coupon_expiry_days',
        );
        foreach ( $int_fields as $f ) {
            $clean[ $f ] = absint( $input[ $f ] ?? 0 );
        }

        // Montos monetarios/porcentaje: permiten decimales
        foreach ( array( 1, 2, 3 ) as $n ) {
            $clean[ "reminder_{$n}_coupon_amount" ]     = abs( floatval( $input[ "reminder_{$n}_coupon_amount" ] ?? 10 ) );
            $clean[ "reminder_{$n}_coupon_min_amount" ] = abs( floatval( $input[ "reminder_{$n}_coupon_min_amount" ] ?? 0 ) );
        }

        $bool_fields = array(
            'show_add_popup', 'show_exit_popup',
            'reminder_1_enabled', 'reminder_2_enabled', 'reminder_3_enabled',
            'reminder_1_coupon_enabled', 'reminder_2_coupon_enabled', 'reminder_3_coupon_enabled',
        );
        foreach ( $bool_fields as $f ) {
            $clean[ $f ] = ! empty( $input[ $f ] ) ? 1 : 0;
        }

        // Tipo de descuento del cupón
        $valid_types = array( 'percent', 'fixed_cart' );
        foreach ( array( 1, 2, 3 ) as $n ) {
            $type = sanitize_text_field( wp_unslash( $input[ "reminder_{$n}_coupon_type" ] ?? 'percent' ) );
            $clean[ "reminder_{$n}_coupon_type" ] = in_array( $type, $valid_types, true ) ? $type : 'percent';
        }

        // Prefijo del cupón
        foreach ( array( 1, 2, 3 ) as $n ) {
            $clean[ "reminder_{$n}_coupon_prefix" ] = sanitize_text_field( wp_unslash( $input[ "reminder_{$n}_coupon_prefix" ] ?? 'WBI-CART-' ) );
        }

        return $clean;
    }

    // =========================================================================
    // ADMIN PAGE
    // =========================================================================

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Sin permiso.' );
        }

        // Handle settings save
        if ( isset( $_POST['wbi_save_cart_settings'] ) ) {
            if ( ! isset( $_POST['_wbi_cart_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wbi_cart_nonce'] ) ), 'wbi_cart_settings_save' ) ) {
                wp_die( 'Nonce inválido.' );
            }
            $data = isset( $_POST['wbi_ac'] ) ? $_POST['wbi_ac'] : array();
            $this->settings = $this->sanitize_module_settings( $data );
            update_option( 'wbi_abandoned_cart_settings', $this->settings );
            echo '<div class="notice notice-success is-dismissible"><p>✅ Configuración guardada correctamente.</p></div>';
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'activos';
        $base_url = admin_url( 'admin.php?page=wbi-abandoned-carts' );

        echo '<div class="wrap">';
        echo '<h1>🛒 Carritos Abandonados</h1>';

        // Tabs
        $tabs = array(
            'activos'       => '📋 Activos',
            'recuperados'   => '✅ Recuperados',
            'estadisticas'  => '📊 Estadísticas',
            'configuracion' => '⚙️ Configuración',
        );
        echo '<nav class="nav-tab-wrapper" style="margin-bottom:16px;">';
        foreach ( $tabs as $t_key => $t_label ) {
            $active = $tab === $t_key ? ' nav-tab-active' : '';
            echo '<a href="' . esc_url( $base_url . '&tab=' . $t_key ) . '" class="nav-tab' . esc_attr( $active ) . '">' . esc_html( $t_label ) . '</a>';
        }
        echo '</nav>';

        switch ( $tab ) {
            case 'activos':
                $this->render_tab_activos();
                break;
            case 'recuperados':
                $this->render_tab_recuperados();
                break;
            case 'estadisticas':
                $this->render_tab_estadisticas();
                break;
            case 'configuracion':
                $this->render_tab_configuracion();
                break;
        }

        echo '</div>';

        // Modal para detalles
        $this->render_detail_modal();
    }

    // -------------------------------------------------------------------------
    // TAB: ACTIVOS
    // -------------------------------------------------------------------------

    private function render_tab_activos() {
        global $wpdb;

        $per_page    = 20;
        $current_page = max( 1, absint( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) );
        $offset      = ( $current_page - 1 ) * $per_page;

        // Filtros
        $filter_channel = isset( $_GET['channel'] ) ? sanitize_text_field( $_GET['channel'] ) : '';
        $filter_from    = isset( $_GET['date_from'] ) ? sanitize_text_field( $_GET['date_from'] ) : '';
        $filter_to      = isset( $_GET['date_to'] ) ? sanitize_text_field( $_GET['date_to'] ) : '';

        $where   = " WHERE status IN ('abandoned','sent_reminder_1','sent_reminder_2','sent_reminder_3') ";
        $params  = array();
        if ( $filter_channel ) {
            $where  .= ' AND contact_channel = %s ';
            $params[] = $filter_channel;
        }
        if ( $filter_from ) {
            $where  .= ' AND DATE(created_at) >= %s ';
            $params[] = $filter_from;
        }
        if ( $filter_to ) {
            $where  .= ' AND DATE(created_at) <= %s ';
            $params[] = $filter_to;
        }

        if ( ! empty( $params ) ) {
            $total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table}" . $where, $params ) );
            $items = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$this->table}" . $where . " ORDER BY created_at DESC LIMIT %d OFFSET %d",
                array_merge( $params, array( $per_page, $offset ) )
            ) );
        } else {
            $total = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" . $where );
            $items = $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM {$this->table}" . $where . " ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset )
            );
        }

        $admin_nonce = wp_create_nonce( 'wbi_admin_nonce' );
        $base_url    = admin_url( 'admin.php?page=wbi-abandoned-carts&tab=activos' );

        // Filtros UI
        echo '<form method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">';
        echo '<input type="hidden" name="page" value="wbi-abandoned-carts">';
        echo '<input type="hidden" name="tab" value="activos">';
        echo '<label>Desde: <input type="date" name="date_from" value="' . esc_attr( $filter_from ) . '"></label>';
        echo '<label>Hasta: <input type="date" name="date_to" value="' . esc_attr( $filter_to ) . '"></label>';
        echo '<label>Canal: <select name="channel"><option value="">Todos</option>';
        foreach ( array( 'email' => 'Email', 'whatsapp' => 'WhatsApp', 'both' => 'Ambos' ) as $v => $l ) {
            echo '<option value="' . esc_attr( $v ) . '"' . selected( $filter_channel, $v, false ) . '>' . esc_html( $l ) . '</option>';
        }
        echo '</select></label>';
        echo '<button type="submit" class="button">Filtrar</button>';
        if ( $filter_channel || $filter_from || $filter_to ) {
            echo '<a href="' . esc_url( $base_url ) . '" class="button">Limpiar</a>';
        }
        echo '</form>';

        echo '<table class="widefat striped">';
        echo '<thead><tr>
            <th>Fecha</th><th>Email / Teléfono</th><th>Canal</th><th>Productos</th>
            <th>Total</th><th>Recordatorios</th><th>Último envío</th><th>Acciones</th>
        </tr></thead><tbody>';

        if ( empty( $items ) ) {
            echo '<tr><td colspan="8" style="text-align:center;padding:24px;color:#787c82;">No hay carritos abandonados.</td></tr>';
        } else {
            foreach ( $items as $row ) {
                $items_json   = json_decode( $row->cart_contents, true );
                $items_count  = is_array( $items_json ) ? count( $items_json ) : 0;
                if ( is_array( $items_json ) ) {
                    $sliced      = array_slice( $items_json, 0, 2 );
                    $item_names  = array_map( function( $i ) { return esc_html( $i['name'] ?? '' ); }, $sliced );
                    $items_names = implode( ', ', $item_names );
                } else {
                    $items_names = '—';
                }
                if ( $items_count > 2 ) $items_names .= ' + ' . ( $items_count - 2 ) . ' más';
                $last_reminder = $row->last_reminder_at
                    ? esc_html( date_i18n( 'd/m/Y H:i', strtotime( $row->last_reminder_at ) ) )
                    : '—';
                $wa_phone  = preg_replace( '/[^0-9]/', '', $row->phone );
                $wa_link   = ! empty( $row->phone ) ? 'https://wa.me/' . $wa_phone : '#';

                // Verificar si hay cupones asociados a este carrito
                $has_coupons = (bool) get_posts( array(
                    'post_type'      => 'shop_coupon',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'meta_query'     => array(
                        array(
                            'key'   => '_wbi_abandoned_cart_id',
                            'value' => intval( $row->id ),
                            'type'  => 'NUMERIC',
                        ),
                    ),
                ) );

                echo '<tr>';
                echo '<td>' . esc_html( date_i18n( 'd/m/Y H:i', strtotime( $row->created_at ) ) ) . '</td>';
                echo '<td>' . esc_html( $row->email ?: '—' ) . '<br><small>' . esc_html( $row->phone ?: '' ) . '</small></td>';
                echo '<td><span style="text-transform:uppercase;font-size:11px;background:#e0e0e0;padding:2px 6px;border-radius:3px;">' . esc_html( $row->contact_channel ) . '</span></td>';
                echo '<td>' . esc_html( $items_names ) . '</td>';
                echo '<td>' . wp_kses_post( wc_price( $row->cart_total ) ) . '</td>';
                echo '<td style="text-align:center;">' . intval( $row->reminder_count ) . ( $has_coupons ? ' <span title="Tiene cupón generado">🎟️</span>' : '' ) . '</td>';
                echo '<td>' . esc_html( $last_reminder ) . '</td>';
                echo '<td>';
                echo '<button class="button button-small wbi-send-reminder" data-id="' . intval( $row->id ) . '" data-nonce="' . esc_attr( $admin_nonce ) . '" title="Enviar recordatorio">📧</button> ';
                if ( $row->phone ) {
                    echo '<a href="' . esc_url( $wa_link ) . '" target="_blank" class="button button-small" title="Enviar WhatsApp">📱</a> ';
                }
                echo '<button class="button button-small wbi-view-detail" data-id="' . intval( $row->id ) . '" data-nonce="' . esc_attr( $admin_nonce ) . '" title="Ver detalle">👁️</button> ';
                echo '<button class="button button-small wbi-delete-cart" data-id="' . intval( $row->id ) . '" data-nonce="' . esc_attr( $admin_nonce ) . '" title="Eliminar" style="color:#a00;">🗑️</button>';
                echo '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        // Paginación
        $total_pages = ceil( intval( $total ) / $per_page );
        if ( $total_pages > 1 ) {
            $paginate_args = array(
                'base'      => $base_url . '&paged=%#%',
                'format'    => '',
                'current'   => $current_page,
                'total'     => $total_pages,
                'prev_text' => '«',
                'next_text' => '»',
            );
            echo '<div style="margin-top:16px;">' . paginate_links( $paginate_args ) . '</div>';
        }

        // JS para acciones de tabla
        $this->enqueue_admin_js( $admin_nonce );
    }

    // -------------------------------------------------------------------------
    // TAB: RECUPERADOS
    // -------------------------------------------------------------------------

    private function render_tab_recuperados() {
        global $wpdb;

        $total_recovered = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE status = 'recovered'" );
        $total_amount    = (float) $wpdb->get_var( "SELECT SUM(cart_total) FROM {$this->table} WHERE status = 'recovered'" );
        $total_abandoned = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE status != 'expired'" );
        $recovery_rate   = $total_abandoned > 0 ? round( $total_recovered / $total_abandoned * 100, 1 ) : 0;

        // KPI cards
        echo '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:24px;">';
        $this->render_kpi_card( '✅ Total recuperados', $total_recovered );
        $this->render_kpi_card( '💰 Monto recuperado', wc_price( $total_amount ) );
        $this->render_kpi_card( '📈 Tasa de recuperación', $recovery_rate . '%' );
        echo '</div>';

        $per_page     = 20;
        $current_page = max( 1, absint( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) );
        $offset       = ( $current_page - 1 ) * $per_page;
        $total        = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE status = 'recovered'" );
        $items        = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE status = 'recovered' ORDER BY recovered_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ) );

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Fecha abandono</th><th>Fecha recuperación</th><th>Email</th><th>Total carrito</th></tr></thead><tbody>';

        if ( empty( $items ) ) {
            echo '<tr><td colspan="4" style="text-align:center;padding:24px;color:#787c82;">No hay carritos recuperados aún.</td></tr>';
        } else {
            foreach ( $items as $row ) {
                echo '<tr>';
                echo '<td>' . esc_html( date_i18n( 'd/m/Y H:i', strtotime( $row->created_at ) ) ) . '</td>';
                echo '<td>' . esc_html( $row->recovered_at ? date_i18n( 'd/m/Y H:i', strtotime( $row->recovered_at ) ) : '—' ) . '</td>';
                echo '<td>' . esc_html( $row->email ?: '—' ) . '</td>';
                echo '<td>' . wp_kses_post( wc_price( $row->cart_total ) ) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';

        $total_pages = ceil( intval( $total ) / $per_page );
        if ( $total_pages > 1 ) {
            $base_url = admin_url( 'admin.php?page=wbi-abandoned-carts&tab=recuperados' );
            echo '<div style="margin-top:16px;">' . paginate_links( array(
                'base'    => $base_url . '&paged=%#%',
                'format'  => '',
                'current' => $current_page,
                'total'   => $total_pages,
            ) ) . '</div>';
        }
    }

    // -------------------------------------------------------------------------
    // TAB: ESTADÍSTICAS
    // -------------------------------------------------------------------------

    private function render_tab_estadisticas() {
        global $wpdb;

        $total_abandoned  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE status NOT IN ('expired')" );
        $total_recovered  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE status = 'recovered'" );
        $recovery_rate    = $total_abandoned > 0 ? round( $total_recovered / $total_abandoned * 100, 1 ) : 0;
        $avg_cart         = (float) $wpdb->get_var( "SELECT AVG(cart_total) FROM {$this->table} WHERE status NOT IN ('expired')" );
        $total_rev        = (float) $wpdb->get_var( "SELECT SUM(cart_total) FROM {$this->table} WHERE status = 'recovered'" );
        $by_channel       = $wpdb->get_results( "SELECT contact_channel, COUNT(*) as cnt FROM {$this->table} GROUP BY contact_channel" );

        echo '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:24px;">';
        $this->render_kpi_card( '🛒 Total abandonados', $total_abandoned );
        $this->render_kpi_card( '✅ Total recuperados', $total_recovered );
        $this->render_kpi_card( '📈 Tasa recuperación', $recovery_rate . '%' );
        $this->render_kpi_card( '💰 Ticket promedio', wc_price( $avg_cart ) );
        $this->render_kpi_card( '💵 Monto recuperado', wc_price( $total_rev ) );
        echo '</div>';

        // Datos para charts (últimas 8 semanas)
        $weeks_data = array();
        // Find the most recent Monday relative to today
        $today      = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
        $day_of_week = (int) $today->format( 'N' ); // 1=Mon, 7=Sun
        $days_since_monday = $day_of_week - 1;
        $current_monday = clone $today;
        $current_monday->modify( "-{$days_since_monday} days" );

        for ( $i = 7; $i >= 0; $i-- ) {
            $week_monday = clone $current_monday;
            $week_monday->modify( "-{$i} weeks" );
            $week_sunday = clone $week_monday;
            $week_sunday->modify( '+6 days' );

            $start = $week_monday->format( 'Y-m-d' );
            $end   = $week_sunday->format( 'Y-m-d' );
            $label = date_i18n( 'd/m', $week_monday->getTimestamp() );

            $aband    = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE DATE(created_at) BETWEEN %s AND %s AND status != 'expired'",
                $start, $end
            ) );
            $recov    = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE DATE(created_at) BETWEEN %s AND %s AND status = 'recovered'",
                $start, $end
            ) );
            $weeks_data[] = array( 'label' => $label, 'abandoned' => $aband, 'recovered' => $recov );
        }

        $channel_labels = array();
        $channel_values = array();
        foreach ( $by_channel as $row ) {
            $channel_labels[] = ucfirst( $row->contact_channel );
            $channel_values[] = intval( $row->cnt );
        }

        // Enqueue Chart.js with Subresource Integrity for security
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            array(),
            null,
            true
        );
        add_filter( 'script_loader_tag', function( $tag, $handle ) {
            if ( 'chartjs' === $handle ) {
                $tag = str_replace(
                    '<script ',
                    '<script integrity="sha256-oFRLExpPzLU3sFSPMiGQNIPw8JdObYMnQlOkPYnSOsE=" crossorigin="anonymous" ',
                    $tag
                );
            }
            return $tag;
        }, 10, 2 );
        ?>
        <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;margin-top:16px;">
          <div style="background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:20px;">
            <h3 style="margin-top:0;">Abandonados vs Recuperados (últimas 8 semanas)</h3>
            <div style="position:relative;height:300px;">
              <canvas id="wbiAbandonedWeekly"></canvas>
            </div>
          </div>
          <div style="background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:20px;">
            <h3 style="margin-top:0;">Distribución por canal</h3>
            <div style="position:relative;height:300px;">
              <canvas id="wbiAbandonedChannel"></canvas>
            </div>
          </div>
        </div>
        <script>
        document.addEventListener("DOMContentLoaded", function(){
            var weeklyData = <?php echo wp_json_encode( $weeks_data ); ?>;
            var weekLabels    = weeklyData.map(function(d){ return d.label; });
            var weekAbandoned = weeklyData.map(function(d){ return d.abandoned; });
            var weekRecovered = weeklyData.map(function(d){ return d.recovered; });

            new Chart(document.getElementById("wbiAbandonedWeekly"), {
                type: "bar",
                data: {
                    labels: weekLabels,
                    datasets: [
                        { label: "Abandonados", data: weekAbandoned, backgroundColor: "rgba(220,53,69,.7)" },
                        { label: "Recuperados", data: weekRecovered, backgroundColor: "rgba(40,167,69,.7)" }
                    ]
                },
                options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:"top" } } }
            });

            new Chart(document.getElementById("wbiAbandonedChannel"), {
                type: "pie",
                data: {
                    labels: <?php echo wp_json_encode( $channel_labels ); ?>,
                    datasets: [{ data: <?php echo wp_json_encode( $channel_values ); ?>, backgroundColor: ["#2271b1","#25ae88","#f0b849"] }]
                },
                options: { responsive:true, maintainAspectRatio:false }
            });
        });
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // TAB: CONFIGURACIÓN
    // -------------------------------------------------------------------------

    private function render_tab_configuracion() {
        $s = $this->settings;
        ?>
        <form method="post">
            <?php wp_nonce_field( 'wbi_cart_settings_save', '_wbi_cart_nonce' ); ?>
            <input type="hidden" name="wbi_save_cart_settings" value="1">

            <div style="max-width:820px;">

            <!-- General -->
            <h2>⚙️ General</h2>
            <table class="form-table">
                <tr>
                    <th>Tiempo para marcar como abandonado</th>
                    <td><input type="number" name="wbi_ac[abandonment_threshold]" value="<?php echo esc_attr( $this->get_setting('abandonment_threshold', 30) ); ?>" min="5" style="width:80px"> minutos</td>
                </tr>
                <tr>
                    <th>Expiración de carritos</th>
                    <td><input type="number" name="wbi_ac[expiration_days]" value="<?php echo esc_attr( $this->get_setting('expiration_days', 30) ); ?>" min="1" style="width:80px"> días (auto-limpieza)</td>
                </tr>
                <tr>
                    <th>Nombre del remitente (email)</th>
                    <td><input type="text" name="wbi_ac[sender_name]" value="<?php echo esc_attr( $this->get_setting('sender_name', get_bloginfo('name')) ); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Email del remitente</th>
                    <td><input type="email" name="wbi_ac[sender_email]" value="<?php echo esc_attr( $this->get_setting('sender_email', get_option('admin_email')) ); ?>" class="regular-text"></td>
                </tr>
            </table>

            <!-- Popups -->
            <h2>💬 Popups</h2>
            <table class="form-table">
                <tr>
                    <th>Popup al agregar al carrito</th>
                    <td><label><input type="checkbox" name="wbi_ac[show_add_popup]" value="1" <?php checked( $this->get_setting('show_add_popup',1), 1 ); ?>> Activado</label></td>
                </tr>
                <tr>
                    <th>Título popup (agregar)</th>
                    <td><input type="text" name="wbi_ac[popup_title_add]" value="<?php echo esc_attr( $this->get_setting('popup_title_add','🛒 ¡Guardamos tu carrito!') ); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Texto popup (agregar)</th>
                    <td><textarea name="wbi_ac[popup_body_add]" rows="2" class="large-text"><?php echo esc_textarea( $this->get_setting('popup_body_add','Dejanos tu email o WhatsApp para que puedas recuperar tu carrito si lo necesitás.') ); ?></textarea></td>
                </tr>
                <tr>
                    <th>Exit-intent popup</th>
                    <td><label><input type="checkbox" name="wbi_ac[show_exit_popup]" value="1" <?php checked( $this->get_setting('show_exit_popup',1), 1 ); ?>> Activado</label></td>
                </tr>
                <tr>
                    <th>Título popup (exit-intent)</th>
                    <td><input type="text" name="wbi_ac[popup_title_exit]" value="<?php echo esc_attr( $this->get_setting('popup_title_exit','⚠️ ¡Esperá! Tenés productos en tu carrito') ); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Texto popup (exit-intent)</th>
                    <td><textarea name="wbi_ac[popup_body_exit]" rows="2" class="large-text"><?php echo esc_textarea( $this->get_setting('popup_body_exit','No pierdas tus productos. Dejá tu email o WhatsApp y te enviamos un recordatorio.') ); ?></textarea></td>
                </tr>
            </table>

            <!-- Recordatorios -->
            <?php
            $reminder_defaults = array(
                1 => array( 'hours' => 1,  'subject' => '¡Dejaste productos en tu carrito!',          'email' => '¡Hola {name}! Dejaste productos en tu carrito en {site_name}. ¿Querés completar tu compra? {recovery_url}', 'whatsapp' => 'Hola {name}, dejaste productos en {site_name}. Completá tu compra: {recovery_url}' ),
                2 => array( 'hours' => 24, 'subject' => 'Tus productos te están esperando 🛒',         'email' => '¡{name}, tus productos te están esperando! No los pierdas: {recovery_url}',                                 'whatsapp' => '{name}, tus productos te están esperando en {site_name}: {recovery_url}' ),
                3 => array( 'hours' => 72, 'subject' => 'Último aviso: tu carrito vence pronto ⚠️',    'email' => 'Último aviso: tu carrito en {site_name} vence pronto. Aprovechá ahora con un {coupon_discount} usando el código {coupon_code}: {recovery_url}', 'whatsapp' => 'Último aviso {name}: tu carrito en {site_name} vence pronto. Usá el código {coupon_code} para {coupon_discount}. Aprovechá: {recovery_url}' ),
            );
            for ( $num = 1; $num <= 3; $num++ ) :
                $d = $reminder_defaults[ $num ];
            ?>
            <h2>📩 Recordatorio #<?php echo intval( $num ); ?></h2>
            <table class="form-table">
                <tr>
                    <th>Activado</th>
                    <td><label><input type="checkbox" name="wbi_ac[reminder_<?php echo intval( $num ); ?>_enabled]" value="1" <?php checked( $this->get_setting("reminder_{$num}_enabled", 1), 1 ); ?>> Activado</label></td>
                </tr>
                <tr>
                    <th>Tiempo desde abandono</th>
                    <td><input type="number" name="wbi_ac[reminder_<?php echo intval( $num ); ?>_hours]" value="<?php echo esc_attr( $this->get_setting("reminder_{$num}_hours", $d['hours']) ); ?>" min="1" style="width:80px"> horas</td>
                </tr>
                <tr>
                    <th>Asunto del email</th>
                    <td><input type="text" name="wbi_ac[reminder_<?php echo intval( $num ); ?>_subject]" value="<?php echo esc_attr( $this->get_setting("reminder_{$num}_subject", $d['subject']) ); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Template email</th>
                    <td><textarea name="wbi_ac[reminder_<?php echo intval( $num ); ?>_email_template]" rows="3" class="large-text"><?php echo esc_textarea( $this->get_setting("reminder_{$num}_email_template", $d['email']) ); ?></textarea></td>
                </tr>
                <tr>
                    <th>Template WhatsApp</th>
                    <td><textarea name="wbi_ac[reminder_<?php echo intval( $num ); ?>_whatsapp_template]" rows="2" class="large-text"><?php echo esc_textarea( $this->get_setting("reminder_{$num}_whatsapp_template", $d['whatsapp']) ); ?></textarea></td>
                </tr>
            </table>

            <h3 style="margin-top:0;">🎟️ Cupón de descuento</h3>
            <table class="form-table" style="margin-top:0;">
                <tr>
                    <th>Incluir cupón en este recordatorio</th>
                    <td><label><input type="checkbox" name="wbi_ac[reminder_<?php echo intval( $num ); ?>_coupon_enabled]" value="1" <?php checked( $this->get_setting("reminder_{$num}_coupon_enabled", $num === 3 ? 1 : 0), 1 ); ?>> Activado</label></td>
                </tr>
                <tr>
                    <th>Tipo de descuento</th>
                    <td>
                        <select name="wbi_ac[reminder_<?php echo intval( $num ); ?>_coupon_type]">
                            <option value="percent" <?php selected( $this->get_setting("reminder_{$num}_coupon_type", 'percent'), 'percent' ); ?>>% Porcentaje</option>
                            <option value="fixed_cart" <?php selected( $this->get_setting("reminder_{$num}_coupon_type", 'percent'), 'fixed_cart' ); ?>>$ Monto fijo</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Valor del descuento</th>
                    <td><input type="number" name="wbi_ac[reminder_<?php echo intval( $num ); ?>_coupon_amount]" value="<?php echo esc_attr( $this->get_setting("reminder_{$num}_coupon_amount", 10) ); ?>" min="0" step="0.01" style="width:100px"></td>
                </tr>
                <tr>
                    <th>Expiración del cupón</th>
                    <td><input type="number" name="wbi_ac[reminder_<?php echo intval( $num ); ?>_coupon_expiry_days]" value="<?php echo esc_attr( $this->get_setting("reminder_{$num}_coupon_expiry_days", 3) ); ?>" min="1" style="width:80px"> días después de enviar</td>
                </tr>
                <tr>
                    <th>Monto mínimo de compra</th>
                    <td><input type="number" name="wbi_ac[reminder_<?php echo intval( $num ); ?>_coupon_min_amount]" value="<?php echo esc_attr( $this->get_setting("reminder_{$num}_coupon_min_amount", 0) ); ?>" min="0" step="0.01" style="width:100px"> (0 = sin mínimo)</td>
                </tr>
                <tr>
                    <th>Prefijo del cupón</th>
                    <td><input type="text" name="wbi_ac[reminder_<?php echo intval( $num ); ?>_coupon_prefix]" value="<?php echo esc_attr( $this->get_setting("reminder_{$num}_coupon_prefix", 'WBI-CART-') ); ?>" class="regular-text" placeholder="WBI-CART-"></td>
                </tr>
            </table>
            <?php endfor; ?>

            <!-- Placeholders -->
            <div style="background:#f6f7f7;border:1px solid #c3c4c7;border-radius:6px;padding:14px;margin-top:8px;">
                <strong>Placeholders disponibles:</strong>
                <code>{name}</code>, <code>{email}</code>, <code>{cart_items}</code>, <code>{cart_total}</code>,
                <code>{recovery_url}</code>, <code>{site_name}</code>, <code>{site_url}</code>,
                <code>{coupon_code}</code>, <code>{coupon_discount}</code>
            </div>

            <p class="submit">
                <button type="submit" class="button button-primary button-large">💾 Guardar configuración</button>
            </p>

            </div>
        </form>
        <?php
    }

    // =========================================================================
    // HELPERS DE UI
    // =========================================================================

    private function render_kpi_card( $label, $value ) {
        echo '<div style="background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:20px 24px;min-width:160px;flex:1;">';
        echo '<p style="margin:0 0 4px;color:#787c82;font-size:13px;">' . esc_html( $label ) . '</p>';
        echo '<p style="margin:0;font-size:24px;font-weight:bold;color:#1d2327;">' . wp_kses_post( $value ) . '</p>';
        echo '</div>';
    }

    private function render_detail_modal() {
        ?>
        <div id="wbi-cart-detail-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:99999;align-items:center;justify-content:center;">
          <div style="background:#fff;border-radius:8px;padding:28px;max-width:600px;width:90%;max-height:80vh;overflow-y:auto;position:relative;">
            <button id="wbi-modal-close" style="position:absolute;top:12px;right:14px;background:none;border:none;font-size:22px;cursor:pointer;">&times;</button>
            <div id="wbi-modal-content">Cargando...</div>
          </div>
        </div>
        <?php
    }

    private function enqueue_admin_js( $nonce ) {
        ?>
        <script>
        (function($){
            var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var nonce   = <?php echo wp_json_encode( $nonce ); ?>;

            // Enviar recordatorio
            $(document).on("click", ".wbi-send-reminder", function(){
                var id = $(this).data("id");
                if ( !confirm("¿Enviar recordatorio a este carrito?") ) return;
                $.post(ajaxUrl, { action:"wbi_send_manual_reminder", nonce:nonce, cart_id:id }, function(res){
                    if ( res.success ) { alert( res.data.msg ); }
                    else { alert( "Error: " + (res.data ? res.data.msg : "desconocido") ); }
                });
            });

            // Eliminar carrito
            $(document).on("click", ".wbi-delete-cart", function(){
                var id = $(this).data("id");
                var $row = $(this).closest("tr");
                if ( !confirm("¿Eliminar este registro? Esta acción no se puede deshacer.") ) return;
                $.post(ajaxUrl, { action:"wbi_delete_abandoned_cart", nonce:nonce, cart_id:id }, function(res){
                    if ( res.success ) { $row.fadeOut(300, function(){ $(this).remove(); }); }
                    else { alert( "Error al eliminar." ); }
                });
            });

            // Ver detalle
            $(document).on("click", ".wbi-view-detail", function(){
                var id = $(this).data("id");
                var $modal = $("#wbi-cart-detail-modal");
                $("#wbi-modal-content").html("Cargando...");
                $modal.css("display","flex");
                $.get(ajaxUrl, { action:"wbi_get_cart_detail", nonce:nonce, cart_id:id }, function(res){
                    if ( res.success ) { $("#wbi-modal-content").html( res.data.html ); }
                    else { $("#wbi-modal-content").html("Error al cargar los datos."); }
                });
            });

            // Cerrar modal
            $(document).on("click", "#wbi-modal-close, #wbi-cart-detail-modal", function(e){
                if ( $(e.target).is("#wbi-cart-detail-modal") || $(e.target).is("#wbi-modal-close") ) {
                    $("#wbi-cart-detail-modal").hide();
                }
            });

        })(jQuery);
        </script>
        <?php
    }
}
