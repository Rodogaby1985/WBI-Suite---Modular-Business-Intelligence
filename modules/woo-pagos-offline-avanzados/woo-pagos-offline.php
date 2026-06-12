<?php
/**
 * Plugin Name: Woo Pagos Offline v2.0
 * Plugin URI:  https://frankielenceriamayorista.com
 * Description: Sistema de cobro manual con lógica de cuentas bancarias variables y link de pago único para WooCommerce.
 * Version:     1.1.2
 * Author:      Mobapp 
 * Author URI:  https://mobappexpress.com
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: woo-pagos-offline-v2.0
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Define constantes
define( 'WPOA_PAGOS_OFFLINE_AVANZADOS_VERSION', '1.1.2' );
define( 'WPOA_PAGOS_OFFLINE_AVANZADOS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPOA_PAGOS_OFFLINE_AVANZADOS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Verificación para prevenir declaración duplicada de la clase principal
if ( ! class_exists( 'WPOA_Pagos_Offline_Avanzados' ) ) {

/**
 * Clase principal del plugin.
 */
class WPOA_Pagos_Offline_Avanzados {

    public function __construct() {
        // Inicializar funcionalidades después de que todos los plugins estén cargados
        add_action( 'plugins_loaded', array( $this, 'init_plugin' ) );
    }

    /**
     * Inicializa las clases y funcionalidades del plugin.
     */
    public function init_plugin() {
        // Verificar si WooCommerce está activo
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            return;
        }

        // Cargar y requerir las clases NECESARIAS DESPUÉS de que WooCommerce esté disponible.
        // ✅ Asegúrate de que estos archivos también tengan verificaciones de existencia de clase
        require_once WPOA_PAGOS_OFFLINE_AVANZADOS_PLUGIN_DIR . 'includes/class-wpoa-payment-gateway.php';
        require_once WPOA_PAGOS_OFFLINE_AVANZADOS_PLUGIN_DIR . 'includes/class-wpoa-admin-settings.php';
        require_once WPOA_PAGOS_OFFLINE_AVANZADOS_PLUGIN_DIR . 'includes/class-wpoa-order-handler.php';
        require_once WPOA_PAGOS_OFFLINE_AVANZADOS_PLUGIN_DIR . 'includes/class-wpoa-thankyou-page.php';
        require_once WPOA_PAGOS_OFFLINE_AVANZADOS_PLUGIN_DIR . 'includes/class-wpoa-email-handler.php';
        
        // Registrar la pasarela de pago
        add_filter( 'woocommerce_payment_gateways', array( $this, 'add_wpoa_payment_gateway' ) );

        // Inicializar las demás clases (instanciar los objetos)
        new WPOA_Admin_Settings();
        new WPOA_Order_Handler();
        new WPOA_Thankyou_Page();
        new WPOA_Email_Handler();

        // Registrar scripts y estilos
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    /**
     * Añade la pasarela de pago personalizada.
     */
    public function add_wpoa_payment_gateway( $gateways ) {
        $gateways[] = 'WPOA_Payment_Gateway';
        return $gateways;
    }

    /**
     * Muestra aviso si WooCommerce no está activo.
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e( 'Woo Pagos Offline Avanzados requiere que WooCommerce esté instalado y activo.', 'woo-pagos-offline-avanzados' ); ?></p>
        </div>
        <?php
    }

    /**
     * Carga los assets (CSS/JS).
     */
    public function enqueue_assets() {
        wp_register_style( 'wpoa-pagos-offline-avanzados-css', WPOA_PAGOS_OFFLINE_AVANZADOS_PLUGIN_URL . 'assets/css/wpoa-pagos.css', array(), WPOA_PAGOS_OFFLINE_AVANZADOS_VERSION );
        wp_register_script( 'wpoa-pagos-offline-avanzados-js', WPOA_PAGOS_OFFLINE_AVANZADOS_PLUGIN_URL . 'assets/js/wpoa-pagos.js', array( 'jquery' ), WPOA_PAGOS_OFFLINE_AVANZADOS_VERSION, true );

        // Cargar assets solo en la página de agradecimiento y en "Ver Pedido"
        if ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'view-order' ) ) {
            wp_enqueue_style( 'wpoa-pagos-offline-avanzados-css' );
            wp_enqueue_script( 'wpoa-pagos-offline-avanzados-js' );
        }
    }

    /**
     * Determina la URL base de WhatsApp según el dispositivo y la configuración.
     */
    public static function wpoa_get_whatsapp_url( $phone_number, $message ) {
        $mobile_base_option = get_option( 'wpoa_whatsapp_base_url_mobile', 'api' );
        $desktop_base_option = get_option( 'wpoa_whatsapp_base_url_desktop', 'web' );

        $base_url = '';

        if ( !wp_is_mobile() ) {
            if ( $desktop_base_option === 'web' ) {
                $base_url = 'https://web.whatsapp.com/send?';
            } elseif ( $desktop_base_option === 'api' ) {
                $base_url = 'https://api.whatsapp.com/send?';
            } elseif ( $desktop_base_option === 'protocol' ) {
                $base_url = 'whatsapp://send?';
            } else {
                $base_url = 'https://web.whatsapp.com/send?';
            }
        } else {
            if ( $mobile_base_option === 'protocol' ) {
                $base_url = 'whatsapp://send?';
            } else {
                $base_url = 'https://api.whatsapp.com/send?';
            }
        }
        
        $encoded_phone = urlencode( $phone_number );
        $encoded_message = rawurlencode( $message );
        
        return $base_url . 'phone=' . $encoded_phone . '&text=' . $encoded_message;
    }

    /**
     * Genera el contenido del mensaje de WhatsApp.
     */
    public static function generate_whatsapp_raw_message( $order, $context = 'general' ) {
        $whatsapp_message = "";
        $view_order_link = $order->get_view_order_url();

        // Primera línea del mensaje
        $whatsapp_message .= "Hola envio el comprobante de mi *pedido n°*: #" . $order->get_id();
        $whatsapp_message .= "\r\n\r\n";

        return $whatsapp_message;
    }
}

} // ✅ Cierra la verificación class_exists para la clase principal

// Inicializar el plugin
new WPOA_Pagos_Offline_Avanzados();

// Para la funcionalidad de cron job (caducidad)
register_activation_hook( __FILE__, 'wpoa_pagos_offline_avanzados_activate' );
register_deactivation_hook( __FILE__, 'wpoa_pagos_offline_avanzados_deactivate' );

function wpoa_pagos_offline_avanzados_activate() {
    if ( ! wp_next_scheduled( 'wpoa_check_expired_orders' ) ) {
        wp_schedule_event( time(), 'hourly', 'wpoa_check_expired_orders' );
    }
}

function wpoa_pagos_offline_avanzados_deactivate() {
    wp_clear_scheduled_hook( 'wpoa_check_expired_orders' );
}

add_action( 'wpoa_check_expired_orders', 'wpoa_process_expired_orders' );

function wpoa_process_expired_orders() {
    $order_handler = new WPOA_Order_Handler();
    $order_handler->check_and_notify_expired_orders();
}