<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WBI_Promo_Pricing_Module {
    public function __construct() {
        $module_file = dirname( __DIR__ ) . '/modules/woo-precio-promo/woo-precio-promo.php';

        if ( file_exists( $module_file ) ) {
            require_once $module_file;
        }
    }
}
