<?php
/**
 * Public Wholesale Quick Order module bootstrap.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WBI_Public_Wholesale_Quick_Order_Module', false ) ) {
    return;
}

new WBI_Public_Wholesale_Quick_Order_Module();
