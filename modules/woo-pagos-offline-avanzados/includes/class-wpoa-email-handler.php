<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * WPOA_Email_Handler
 *
 * Personaliza los emails de WooCommerce.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Agrega esta verificación
 if ( ! class_exists( 'WPOA_Email_Handler' ) ) {

class WPOA_Email_Handler {

    private $whatsapp_number;

    public function __construct() {
        // Añadir contenido al email del cliente cuando el pago es nuestro método
        add_action( 'woocommerce_email_before_order_table', array( $this, 'add_payment_instructions_to_email' ), 10, 4 );
        $this->whatsapp_number = get_option( 'wpoa_whatsapp_number', '' );
    }

    /**
     * Añade instrucciones de pago y link al email de nuevo pedido.
     */
    public function add_payment_instructions_to_email( $order, $sent_to_admin, $plain_text, $email ) {
        // Solo para emails de cliente y si el método de pago es el nuestro
        if ( $sent_to_admin || $order->get_payment_method() !== 'wpoa_manual_payment' ) {
            return;
        }

        $assigned_account = $order->get_meta( '_wpoa_assigned_bank_account' );
        $expiration_timestamp = $order->get_meta( '_wpoa_payment_link_expiration' );

        // Obtenemos el link de "Ver Pedido" de WooCommerce (necesario para el mensaje de WhatsApp)
        $view_order_link = $order->get_view_order_url();

        // Generar el mensaje de WhatsApp usando la función auxiliar centralizada
        $whatsapp_raw_message = WPOA_Pagos_Offline_Avanzados::generate_whatsapp_raw_message( $order, 'email' );
        $whatsapp_url = WPOA_Pagos_Offline_Avanzados::wpoa_get_whatsapp_url( $this->whatsapp_number, $whatsapp_raw_message );
        
        if ( $plain_text ) {
            // Versión de texto plano del email
            echo "\n\n" . esc_html__( 'INSTRUCCIONES DE PAGO', 'woo-pagos-offline-avanzados' ) . "\n";
            echo "---------------------------------\n";
            echo esc_html__( 'Total a Pagar:', 'woo-pagos-offline-avanzados' ) . ' ' . $order->get_formatted_order_total() . "\n";
            if ( ! empty( $assigned_account ) ) {
                echo esc_html__( 'Titular:', 'woo-pagos-offline-avanzados' ) . ' ' . esc_html( $assigned_account['titular'] ) . "\n";
                echo esc_html__( 'Banco:', 'woo-pagos-offline-avanzados' ) . ' ' . esc_html( $assigned_account['banco'] ) . "\n";
                echo esc_html__( 'Alias/CVU:', 'woo-pagos-offline-avanzados' ) . ' ' . esc_html( $assigned_account['alias'] ) . "\n";
                echo esc_html__( 'CBU/Cuenta:', 'woo-pagos-offline-avanzados' ) . ' ' . esc_html( $assigned_account['cbu'] ) . "\n";
            }
            echo esc_html__( 'Puedes ver todos los detalles aquí:', 'woo-pagos-offline-avanzados' ) . ' ' . esc_url( $view_order_link ) . "\n"; // Usa view_order_link
            if ( $expiration_timestamp && 'yes' === get_option( 'wpoa_link_expiration_enabled', 'no' ) ) {
                 echo esc_html__( '¡Importante! Por favor, completa tu transferencia antes del:', 'woo-pagos-offline-avanzados' ) . "\n" . esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expiration_timestamp ) ) . "\n";
            }
            echo esc_html__( 'Una vez realizada la transferencia, envíanos el comprobante por WhatsApp:', 'woo-pagos-offline-avanzados' ) . ' ' . esc_url( $whatsapp_url ) . "\n";
            echo esc_html__( 'Tu pedido se comenzará a preparar una vez recibido el comprobante.', 'woo-pagos-offline-avanzados' ) . "\n\n";

        } else {
            // Versión HTML del email
            ?>
            <div style="background-color: #f8f8f8; border: 1px solid #eee; padding: 20px; margin-top: 20px; text-align: center;">
                <h2><?php esc_html_e( 'Instrucciones para tu pago por Transferencia', 'woo-pagos-offline-avanzados' ); ?></h2>
                <p><?php esc_html_e( 'Para completar tu pedido, por favor realiza una transferencia bancaria con los siguientes datos:', 'woo-pagos-offline-avanzados' ); ?></p>

                <div style="background-color: #fff; border: 1px solid #ddd; padding: 15px; margin: 20px auto; max-width: 400px; text-align: left;">
                    <p><strong><?php esc_html_e( 'Total a Pagar:', 'woo-pagos-offline-avanzados' ); ?></strong> <?php echo $order->get_formatted_order_total(); ?></p>
                    <?php if ( ! empty( $assigned_account ) ) : ?>
                        <p><strong><?php esc_html_e( 'Titular:', 'woo-pagos-offline-avanzados' ); ?></strong> <?php echo esc_html( $assigned_account['titular'] ); ?></p>
                        <p><strong><?php esc_html_e( 'Banco:', 'woo-pagos-offline-avanzados' ); ?></strong> <?php echo esc_html( $assigned_account['banco'] ); ?></p>
                        <p><strong><?php esc_html_e( 'Alias/CVU:', 'woo-pagos-offline-avanzados' ); ?></strong> <?php echo esc_html( $assigned_account['alias'] ); ?></p>
                        <p><strong><?php esc_html_e( 'CBU/Cuenta:', 'woo-pagos-offline-avanzados' ); ?></strong> <?php echo esc_html( $assigned_account['cbu'] ); ?></p>
                    <?php endif; ?>
                </div>

                <p><?php esc_html_e( 'Puedes ver todos los detalles de pago y cuenta en tu link de pedido:', 'woo-pagos-offline-avanzados' ); ?></p>
                <p><a href="<?php echo esc_url( $view_order_link ); ?>" style="font-weight: bold; color: #0073aa; text-decoration: underline;"><?php echo esc_html( $view_order_link ); ?></a></p>

                <?php if ( $expiration_timestamp && 'yes' === get_option( 'wpoa_link_expiration_enabled', 'no' ) ) : ?>
                    <p style="color: #c00; font-weight: bold;"><?php esc_html_e( '¡Importante! Por favor, completa tu transferencia antes del:', 'woo-pagos-offline-avanzados' ); ?><br>
                    <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expiration_timestamp ) ); ?>
                    </p>
                <?php endif; ?>

                <p style="font-weight: bold; margin-top: 25px;"><?php esc_html_e( 'Una vez realizada la transferencia, por favor, envíanos el comprobante por WhatsApp haciendo clic en el botón de abajo. ¡Tu pedido se comenzará a preparar una vez recibido el comprobante!', 'woo-pagos-offline-avanzados' ); ?></p>

                <?php if ( ! empty( $this->whatsapp_number ) ) : ?>
                    <a href="<?php echo esc_url( $whatsapp_url ); ?>" style="background-color: #25d366; color: #fff; padding: 10px 20px; font-size: 1.2em; text-decoration: none; display: inline-block; margin-top: 15px;" target="_blank">
                        <?php esc_html_e( 'Enviar Comprobante y Datos de Pedido por WhatsApp', 'woo-pagos-offline-avanzados' ); ?>
                    </a>
                    <p style="font-size: 0.9em; color: #777; margin-top: 5px;"><?php esc_html_e( 'Se abrirá tu WhatsApp con un mensaje pre-cargado.', 'woo-pagos-offline-avanzados' ); ?></p>
                <?php else : ?>
                    <p style="color: #c00;"><?php esc_html_e( 'El número de WhatsApp no está configurado. Por favor, contacta al vendedor para enviar tu comprobante.', 'woo-pagos-offline-avanzados' ); ?></p>
                <?php endif; ?>
            </div>
            <?php
			}
		}
	}
}