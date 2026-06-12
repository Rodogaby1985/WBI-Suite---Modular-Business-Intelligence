<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * WPOA_Payment_Gateway 2.1
 *
 * Extiende WC_Payment_Gateway para crear un método de pago personalizado.
 */
class WPOA_Payment_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        // Verificar que WooCommerce esté cargado
        if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
            return;
        }
        
        $this->id                 = 'wpoa_manual_payment';
        $this->icon               = ''; // Puedes poner URL de un icono si quieres
        $this->has_fields         = false;
        $this->method_title       = __( 'Transferencia Única con Link de Pago', 'woo-pagos-offline-avanzados' );
        $this->method_description = __( 'Permite a los clientes pagar por transferencia bancaria con un link único y datos variables según el monto.', 'woo-pagos-offline-avanzados' );

        // Carga los ajustes del plugin (habilitado, título, descripción)
        $this->init_form_fields();
        $this->init_settings();

        $this->title        = $this->get_option( 'title' );
        $this->description  = $this->get_option( 'description' );
        $this->enabled      = $this->get_option( 'enabled' );
        $this->instructions = $this->get_option( 'instructions', '' );

        // Ganchos para guardar los ajustes
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        
        // Gancho para mostrar instrucciones en la página de agradecimiento
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
    }

    /**
     * Define los campos de ajustes para esta pasarela en el admin de WooCommerce.
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Habilitar/Deshabilitar', 'woo-pagos-offline-avanzados' ),
                'type'    => 'checkbox',
                'label'   => __( 'Habilitar Transferencia Única con Link de Pago', 'woo-pagos-offline-avanzados' ),
                'default' => 'yes',
            ),
            'title' => array(
                'title'       => __( 'Título', 'woo-pagos-offline-avanzados' ),
                'type'        => 'text',
                'description' => __( 'Esto es lo que el cliente verá en el checkout.', 'woo-pagos-offline-avanzados' ),
                'default'     => __( 'Transferencia Bancaria con Link de Pago', 'woo-pagos-offline-avanzados' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Descripción', 'woo-pagos-offline-avanzados' ),
                'type'        => 'textarea',
                'description' => __( 'Descripción del método de pago que el cliente verá en el checkout.', 'woo-pagos-offline-avanzados' ),
                'default'     => __( 'Serás redirigido a una página con los datos de transferencia. Tu pedido se procesará al recibir el comprobante.', 'woo-pagos-offline-avanzados' ),
                'desc_tip'    => true,
            ),
            'instructions' => array(
                'title'       => __( 'Instrucciones', 'woo-pagos-offline-avanzados' ),
                'type'        => 'textarea',
                'description' => __( 'Instrucciones adicionales que se mostrarán en la página de agradecimiento.', 'woo-pagos-offline-avanzados' ),
                'default'     => __( 'Por favor realiza la transferencia bancaria y envía el comprobante por WhatsApp para procesar tu pedido.', 'woo-pagos-offline-avanzados' ),
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * Procesa el pago y redirige al cliente.
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        // Marcar como "on-hold" (esperando pago)
        $order->update_status( 'on-hold', __( 'Esperando confirmación de pago por transferencia manual.', 'woo-pagos-offline-avanzados' ) );

        // Reducir stock (opcional, dependiendo de tu flujo de trabajo)
        wc_reduce_stock_levels( $order_id );

        // Vaciar carrito
        WC()->cart->empty_cart();

        // Redirigir a la página de agradecimiento
        return array(
            'result'    => 'success',
            'redirect'  => $this->get_return_url( $order ),
        );
    }

    /**
     * Muestra instrucciones en la página de agradecimiento.
     */
    public function thankyou_page( $order_id ) {
        if ( $this->instructions ) {
            echo wpautop( wptexturize( $this->instructions ) );
        }
    }
}