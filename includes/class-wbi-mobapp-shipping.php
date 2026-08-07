<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WBI_MobApp_Shipping_Module {
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

        // Detect legacy MOBAPP plugin conflict before loading the module
        if ( function_exists( 'mobapp_setup_schedule' ) ) {
            error_log( '[WBI MobApp] Legacy MOBAPP plugin conflict detected in bootstrap. Loading module in compatibility mode.' );
            add_action( 'admin_notices', array( $this, 'legacy_conflict_notice' ) );
        }

        $module_file = dirname( __DIR__ ) . '/modules/mobapp-envios/main.php';
        if ( file_exists( $module_file ) ) {
            require_once $module_file;
        }
    }

    public function missing_wc_notice() {
        echo '<div class="notice notice-warning"><p>' . esc_html__( 'El módulo WBI MobApp Shipping requiere que WooCommerce esté instalado y activo.', 'wbi-suite' ) . '</p></div>';
    }

    public function legacy_conflict_notice() {
        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'MOBAPP legacy plugin detected; WBI MOBAPP compatibility mode enabled.', 'wbi-suite' ) . '</p></div>';
    }
}
