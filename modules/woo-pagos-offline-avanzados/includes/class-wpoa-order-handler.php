<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// ✅ VERIFICACIÓN ESENCIAL - Esto previene el error de clase duplicada
if ( ! class_exists( 'WPOA_Order_Handler' ) ) {

/**
 * WPOA_Order_Handler
 *
 * Maneja la lógica de asignación de cuentas bancarias y expiración de pedidos.
 */
class WPOA_Order_Handler {

    private $amount_threshold;

    public function __construct() {
        $this->amount_threshold = floatval( get_option( 'wpoa_amount_threshold', 400000 ) );
        
        // Asignar cuenta bancaria cuando se crea el pedido
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'assign_bank_account_to_order' ), 10, 3 );
        
        // Gancho para procesar pedidos expirados
        add_action( 'wpoa_check_expired_orders', array( $this, 'check_and_notify_expired_orders' ) );
        
        error_log( 'WPOA_Order_Handler: Clase inicializada correctamente' );
    }

    /**
     * Asigna una cuenta bancaria al pedido basado en el monto total.
     */
    public function assign_bank_account_to_order( $order_id, $posted_data, $order ) {
        // Verificar que sea un objeto WC_Order válido
        if ( ! $order instanceof WC_Order ) {
            error_log( 'WPOA_Order_Handler: No es instancia de WC_Order' );
            return;
        }
        
        $order_total = $order->get_total();
        error_log( 'WPOA_Order_Handler: Total del pedido ' . $order_id . ' = ' . $order_total );
        error_log( 'WPOA_Order_Handler: Umbral configurado = ' . $this->amount_threshold );
        
        // Determinar qué cuenta usar basado en el monto límite
        if ( $order_total < $this->amount_threshold ) {
            $account_to_use = get_option( 'wpoa_bank_account_1', array() );
            error_log( 'WPOA_Order_Handler: Usando cuenta 1 (menor al límite)' );
        } else {
            $account_to_use = get_option( 'wpoa_bank_account_2', array() );
            error_log( 'WPOA_Order_Handler: Usando cuenta 2 (mayor o igual al límite)' );
        }
        
        // Guardar la cuenta asignada en los metadatos del pedido
        if ( ! empty( $account_to_use ) ) {
            $order->update_meta_data( '_wpoa_assigned_bank_account', $account_to_use );
            error_log( 'WPOA_Order_Handler: Cuenta asignada - ' . print_r( $account_to_use, true ) );
        } else {
            error_log( 'WPOA_Order_Handler: No se encontraron cuentas bancarias configuradas' );
        }
        
        // Si la caducidad está habilitada, establecer timestamp de expiración
        if ( 'yes' === get_option( 'wpoa_link_expiration_enabled', 'no' ) ) {
            $expiration_hours = absint( get_option( 'wpoa_expiration_hours', 24 ) );
            $expiration_timestamp = current_time( 'timestamp' ) + ( $expiration_hours * HOUR_IN_SECONDS );
            $order->update_meta_data( '_wpoa_payment_link_expiration', $expiration_timestamp );
            error_log( 'WPOA_Order_Handler: Expiración configurada - ' . date( 'Y-m-d H:i:s', $expiration_timestamp ) );
        }
        
        $order->save();
        error_log( 'WPOA_Order_Handler: Pedido ' . $order_id . ' procesado correctamente' );
    }

    /**
     * Verifica y notifica sobre pedidos expirados.
     */
    public function check_and_notify_expired_orders() {
        error_log( 'WPOA_Order_Handler: Ejecutando verificación de pedidos expirados' );
        
        $args = array(
            'status' => 'on-hold',
            'limit' => -1,
            'meta_key' => '_wpoa_payment_link_expiration',
            'meta_compare' => 'EXISTS'
        );
        
        $orders = wc_get_orders( $args );
        $current_time = current_time( 'timestamp' );
        
        error_log( 'WPOA_Order_Handler: Pedidos en revisión: ' . count( $orders ) );
        
        foreach ( $orders as $order ) {
            $expiration_timestamp = $order->get_meta( '_wpoa_payment_link_expiration' );
            
            if ( $expiration_timestamp && $current_time > $expiration_timestamp ) {
                // El pedido ha expirado
                $order->update_status( 'cancelled', __( 'Pedido cancelado por expiración del tiempo de pago.', 'woo-pagos-offline-avanzados' ) );
                error_log( 'WPOA_Order_Handler: Pedido ' . $order->get_id() . ' cancelado por expiración' );
                
                // Opcional: enviar email de notificación
                // $this->send_expiration_notification( $order );
            }
        }
        
        error_log( 'WPOA_Order_Handler: Verificación de pedidos expirados completada' );
    }
    
    /**
     * Envía notificación de expiración (opcional).
     */
    private function send_expiration_notification( $order ) {
        // Lógica para enviar email de notificación de expiración
        // Puedes implementar esto si necesitas notificar a los clientes
    }
}

} // ✅ CIERRA la verificación class_exists - ESTO ES ESENCIAL

// ✅ Mensaje de debug para verificar que el archivo se cargó
error_log( 'WPOA: Archivo class-wpoa-order-handler.php cargado correctamente' );