<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WBI_Advanced_Offline_Payments_Module {
    public function __construct() {
        $module_file = dirname( __DIR__ ) . '/modules/woo-pagos-offline-avanzados/woo-pagos-offline.php';

        if ( file_exists( $module_file ) ) {
            require_once $module_file;
            add_action( 'init', array( $this, 'ensure_cron_schedule' ) );
        }
    }

    public function ensure_cron_schedule() {
        if ( ! wp_next_scheduled( 'wpoa_check_expired_orders' ) && function_exists( 'wpoa_pagos_offline_avanzados_activate' ) ) {
            wpoa_pagos_offline_avanzados_activate();
        }
    }
}
