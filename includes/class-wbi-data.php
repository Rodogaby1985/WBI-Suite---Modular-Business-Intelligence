<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Data_Module {

    public function __construct() {
        // Crear Taxonomía "Colección"
        add_action( 'init', array( $this, 'register_collection_taxonomy' ) );
        
        // Campo "Origen de Venta" en el Admin del Pedido
        add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'add_order_source_field' ) );
        add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_order_source_field' ) );
    }

    public function register_collection_taxonomy() {
        register_taxonomy( 'coleccion', 'product', array(
            'label'        => 'Colecciones',
            'rewrite'      => array( 'slug' => 'coleccion' ),
            'hierarchical' => true,
        ) );
    }

    public function add_order_source_field( $order ) {
        echo '<div class="form-field form-field-wide"><h4>Información BI</h4>';
        woocommerce_wp_select( array(
            'id'      => 'wbi_sales_source',
            'label'   => 'Origen de Venta:',
            'value'   => $order->get_meta( 'wbi_sales_source' ),
            'options' => array(
                'web'      => 'Web / E-commerce',
                'local'    => 'Local Físico',
                'showroom' => 'Showroom',
                'whatsapp' => 'WhatsApp / Directo'
            )
        ) );
        echo '</div>';
    }

    public function save_order_source_field( $post_id ) {
        if ( ! empty( $_POST['wbi_sales_source'] ) ) {
            update_post_meta( $post_id, 'wbi_sales_source', sanitize_text_field( $_POST['wbi_sales_source'] ) );
        }
    }
}