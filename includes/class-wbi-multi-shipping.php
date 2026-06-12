<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WBI_Multi_Shipping_Module {
    private $booted = false;

    public function __construct() {
        add_action( 'plugins_loaded', array( $this, 'bootstrap' ), 20 );
    }

    public function bootstrap() {
        if ( $this->booted ) {
            return;
        }

        $this->booted = true;

        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( $this, 'missing_wc_notice' ) );
            return;
        }

        $module_file = dirname( __DIR__ ) . '/modules/transporte-multiopciones/MOBAPP-LOGISTICA-INTELIGENTE-TRANSPORTES-Y-MICROS-PERSONALIZADO.php';
        if ( file_exists( $module_file ) ) {
            require_once $module_file;
        }
    }

    public function missing_wc_notice() {
        echo '<div class="notice notice-warning"><p>' . esc_html__( 'El módulo WBI Transporte Multiopciones requiere que WooCommerce esté instalado y activo.', 'wbi-suite' ) . '</p></div>';
    }
}
