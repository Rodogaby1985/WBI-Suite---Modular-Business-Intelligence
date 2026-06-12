<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * WPOA_Thankyou_Page v2.1
 *
 * Personaliza la página de agradecimiento (Order Received) y ahora también la página de Ver Pedido.
 */
class WPOA_Thankyou_Page {

    private $whatsapp_number;
    // private $payment_page_slug; // Reservado para uso futuro
    // private $amount_threshold;   // Reservado para uso futuro

    public function __construct() {
        // Hook para la página de agradecimiento
        add_action( 'woocommerce_thankyou_wpoa_manual_payment', array( $this, 'display_payment_instructions' ) );
        
        // Nuevo hook para la página de "Ver Pedido" en la cuenta del cliente
        add_action( 'woocommerce_view_order', array( $this, 'display_whatsapp_button_on_view_order' ), 20 );

        $this->whatsapp_number = get_option( 'wpoa_whatsapp_number', '' );
        // $this->payment_page_slug = get_option( 'wpoa_pagos_offline_avanzados_payment_page_slug', 'pagos' );
        // $this->amount_threshold = floatval( get_option( 'wpoa_amount_threshold', 400000 ) );
    }

    /**
     * Muestra las instrucciones de pago en la página de agradecimiento.
     */
    public function display_payment_instructions( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return;
        }

        // Solo mostrar si el método de pago es el nuestro y el estado es "on-hold"
        if ( $order->get_payment_method() !== 'wpoa_manual_payment' || $order->get_status() !== 'on-hold' ) {
            return;
        }

        $assigned_account = $order->get_meta( '_wpoa_assigned_bank_account' );
        $expiration_timestamp = $order->get_meta( '_wpoa_payment_link_expiration' );

        // Generar el mensaje de WhatsApp usando la función auxiliar centralizada
        $whatsapp_raw_message = WPOA_Pagos_Offline_Avanzados::generate_whatsapp_raw_message( $order, 'thankyou' );
        $whatsapp_url = WPOA_Pagos_Offline_avanzados::wpoa_get_whatsapp_url( $this->whatsapp_number, $whatsapp_raw_message );
        
        ?>
        <div class="wpoa-payment-instructions" style="background-color: #f8f8f8; border: 1px solid #eee; padding: 20px; margin-top: 20px; text-align: center;">
            <h2><?php esc_html_e( '¡Gracias por tu compra!', 'woo-pagos-offline-avanzados' ); ?></h2>
            <p><?php esc_html_e( 'Para completar tu pedido, por favor realiza una transferencia bancaria con los siguientes datos:', 'woo-pagos-offline-avanzados' ); ?></p>

            <div class="wpoa-bank-details" style="background-color: #fff; border: 1px solid #ddd; padding: 15px; margin: 20px auto; max-width: 400px; text-align: left;">
                <p><strong><?php esc_html_e( 'Total a Pagar:', 'woo-pagos-offline-avanzados' ); ?></strong> <?php echo $order->get_formatted_order_total(); ?></p>
                <?php if ( ! empty( $assigned_account ) ) : ?>
                    <p><strong><?php esc_html_e( 'Titular:', 'woo-pagos-offline-avanzados' ); ?></strong> <span id="wpoa_titular"><?php echo esc_html( $assigned_account['titular'] ); ?></span></p>
                    <p><strong><?php esc_html_e( 'Banco:', 'woo-pagos-offline-avanzados' ); ?></strong> <span id="wpoa_banco"><?php echo esc_html( $assigned_account['banco'] ); ?></span></p>
                    <p><strong><?php esc_html_e( 'Alias/CVU:', 'woo-pagos-offline-avanzados' ); ?></strong> <span id="wpoa_alias"><?php echo esc_html( $assigned_account['alias'] ); ?></span> <button type="button" class="button" onclick="wpoaCopyToClipboard('wpoa_alias')"><?php esc_html_e( 'Copiar', 'woo-pagos-offline-avanzados' ); ?></button></p>
                    <p><strong><?php esc_html_e( 'CBU/Cuenta:', 'woo-pagos-offline-avanzados' ); ?></strong> <span id="wpoa_cbu"><?php echo esc_html( $assigned_account['cbu'] ); ?></span> <button type="button" class="button" onclick="wpoaCopyToClipboard('wpoa_cbu')"><?php esc_html_e( 'Copiar', 'woo-pagos-offline-avanzados' ); ?></button></p>
                <?php endif; ?>
            </div>

            <?php if ( $expiration_timestamp && 'yes' === get_option( 'wpoa_link_expiration_enabled', 'no' ) ) : ?>
                <p style="color: #c00; font-weight: bold;"><?php esc_html_e( '¡Importante! Por favor, completa tu transferencia antes del:', 'woo-pagos-offline-avanzados' ); ?><br>
                <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expiration_timestamp ) ); ?>
                </p>
            <?php endif; ?>

            <p style="font-weight: bold; margin-top: 25px;"><?php esc_html_e( 'Una vez realizada la transferencia, por favor, envíanos el comprobante por WhatsApp haciendo clic en el botón de abajo. ¡Tu pedido se comenzará a preparar una vez recibido el comprobante!', 'woo-pagos-offline-avanzados' ); ?></p>

            <?php if ( ! empty( $this->whatsapp_number ) ) : ?>
                <a href="<?php echo esc_url( $whatsapp_url ); ?>" class="button alt wpoa-whatsapp-button" target="_blank">
                    <?php esc_html_e( 'Enviar Comprobante por WhatsApp', 'woo-pagos-offline-avanzados' ); ?>
                </a>
                <p class="description"><?php esc_html_e( 'Se abrirá tu WhatsApp con un mensaje pre-cargado.', 'woo-pagos-offline-avanzados' ); ?></p>
            <?php else : ?>
                <p style="color: #c00;"><?php esc_html_e( 'El número de WhatsApp no está configurado. Por favor, contacta al vendedor para enviar tu comprobante.', 'woo-pagos-offline-avanzados' ); ?></p>
            <?php endif; ?>
        </div>
        <script>
        function wpoaCopyToClipboard(elementId) {
            var copyText = document.getElementById(elementId);
            var textArea = document.createElement("textarea");
            textArea.value = copyText.textContent || copyText.innerText;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand("copy");
            textArea.remove();
            alert("¡Copiado al portapapeles!");
        }
        </script>
        <?php
    }

    /**
     * Muestra el botón de WhatsApp en la página de "Ver Pedido" del cliente.
     */
    public function display_whatsapp_button_on_view_order( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return;
        }

        // Permitir mostrar el botón en múltiples estados donde sea relevante
        $allowed_statuses = array( 'on-hold', 'pending', 'processing' );
        if ( $order->get_payment_method() !== 'wpoa_manual_payment' || ! in_array( $order->get_status(), $allowed_statuses ) ) {
            return;
        }

        $whatsapp_number = get_option( 'wpoa_whatsapp_number', '' );
        
        // Generar el mensaje de WhatsApp
        $whatsapp_raw_message = WPOA_Pagos_Offline_Avanzados::generate_whatsapp_raw_message( $order, 'view_order' );
        $whatsapp_url = WPOA_Pagos_Offline_Avanzados::wpoa_get_whatsapp_url( $whatsapp_number, $whatsapp_raw_message );
        
        ?>
        <div class="woocommerce-MyAccount-content" style="text-align: center; margin-top: 20px; padding: 20px; border: 1px solid #eee; background-color: #f8f8f8;">
            <p style="font-weight: bold; font-size: 1.1em;"><?php esc_html_e( 'Enviar comprobante de pago por WhatsApp:', 'woo-pagos-offline-avanzados' ); ?></p>
            <?php if ( ! empty( $whatsapp_number ) ) : ?>
                <a href="<?php echo esc_url( $whatsapp_url ); ?>" class="button alt wpoa-whatsapp-button" target="_blank" style="margin-top: 15px;">
                    <?php esc_html_e( 'Enviar Comprobante de Pago por WhatsApp', 'woo-pagos-offline-avanzados' ); ?>
                </a>
                <p class="description" style="font-size: 0.9em; color: #777; margin-top: 5px;"><?php esc_html_e( 'Se abrirá tu WhatsApp con los detalles del pedido pre-cargados.', 'woo-pagos-offline-avanzados' ); ?></p>
            <?php else : ?>
                <p style="color: #c00;"><?php esc_html_e( 'El número de WhatsApp no está configurado. Por favor, contacta al vendedor para enviar tu comprobante.', 'woo-pagos-offline-avanzados' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }
}