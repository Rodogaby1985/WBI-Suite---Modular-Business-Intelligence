<?php
/*
Plugin Name: MOBAPP LOGISTICA INTELIGENTE TRANSPORTES Y MICROS PERSONALIZADO
Description: Método de envío para WooCommerce con selección de transportista o personalizado. Guarda la selección por instancia (AJAX + sesión + dedupe), muestra solo el desplegable del método seleccionado y ocupa todo el ancho. Todo ejecutado desde el plugin.
Version: 3.0
Author: Mobapp Express
License: GPL2
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Enqueue scripts + styles (cart & checkout)
 * Includes:
 * - JS: debounce + AJAX save + visibility behavior (show selects only for selected radio)
 * - CSS: place selectors below radio and full-width
 */
add_action( 'wp_enqueue_scripts', 'mobapp_enqueue_scripts' );
function mobapp_enqueue_scripts() {
    if ( ! ( is_cart() || is_checkout() ) ) {
        return;
    }

    // Register a no-file script handle and enqueue inline JS
    wp_register_script( 'mobapp-transportes', false, array( 'jquery' ), '3.0', true );
    wp_enqueue_script( 'mobapp-transportes' );

    // Localize for AJAX
    wp_localize_script( 'mobapp-transportes', 'mobappTransportes', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'mobapp-save-carrier' ),
    ) );

    // Inline JS: save logic (debounce + dedupe), and visibility UI behavior
    $js = <<<'JS'
(function($){
    var MobappState = {};
    function ensureState(iid) {
        if (!MobappState[iid]) MobappState[iid] = { timer: null, lastPayload: null, inFlight: false };
        return MobappState[iid];
    }
    function payloadHash(iid, carrier, custom) { return iid + '|' + carrier + '|' + custom; }
    function sendSave(iid, carrier, custom) {
        var state = ensureState(iid);
        var hash = payloadHash(iid, carrier, custom);

        // Don't send if there's nothing useful to save - MODIFIED: allow saving if custom has value even if carrier is empty
        if ((carrier === '' || typeof carrier === 'undefined') && (custom === '' || typeof custom === 'undefined')) {
            return;
        }

        // Avoid resending identical payload
        if (state.lastPayload === hash && !state.inFlight) return;
        if (state.inFlight && state.lastPayload === hash) return;

        state.inFlight = true;
        state.lastPayload = hash;

        $.post(mobappTransportes.ajax_url, {
            action: 'mobapp_save_carrier',
            nonce: mobappTransportes.nonce,
            instance_id: iid,
            carrier: carrier,
            custom: custom
        }, function(response){
            // optional: handle response
        }, 'json').always(function(){
            state.inFlight = false;
        });
    }
    function scheduleSave(iid, carrier, custom) {
        var state = ensureState(iid);
        if (state.timer) clearTimeout(state.timer);
        state.timer = setTimeout(function(){
            sendSave(iid, carrier, custom);
            state.timer = null;
        }, 300);
    }

    // When select changes: attempt to save (sendSave will skip empties)
    $(document).on('change', '.mobapp-carrier-select', function(){
        var $container = $(this).closest('.mobapp-carrier-selector');
        var iid = $container.data('instance');
        var val = $(this).val() || '';
        var custom = $container.find('.mobapp-custom-carrier').val() || '';
        // No ocultar el campo custom - siempre visible
        scheduleSave(iid, val, custom);
    });

    // When custom input changes: debounced save
    $(document).on('input', '.mobapp-custom-carrier', function(){
        var $container = $(this).closest('.mobapp-carrier-selector');
        var iid = $container.data('instance');
        var val = $container.find('select.mobapp-carrier-select').val() || '';
        var custom = $(this).val() || '';
        scheduleSave(iid, val, custom);
    });

    // UI: show selectors only for the currently selected shipping radio (and hide others).
    function updateSelectorsVisibility() {
        $('.mobapp-carrier-selector').hide(); // hide all by default
        // For each checked shipping radio, show the matched selector
        $('input[type=radio][name^="shipping_method"]:checked').each(function(){
            var val = $(this).val() || '';
            var m = val.match(/:([0-9]+)$/);
            if ( m && m[1] ) {
                var iid = m[1];
                $('.mobapp-carrier-selector[data-instance="' + iid + '"]').show();
            }
        });
    }

    // When shipping method radio changes, schedule showing of the associated selector (if it has value)
    $(document).on('change', 'input[name^="shipping_method"], input[name="shipping_method[0]"], input[name="shipping_method"]', function(){
        // Allow WooCommerce to update rates first, then update visibility
        setTimeout(function(){
            updateSelectorsVisibility();
            // Additionally, only schedule a save if selected selector has a non-empty value
            $('.mobapp-carrier-selector').each(function(){
                var $container = $(this);
                var iid = $container.data('instance');
                if (!iid) return;
                var selector = 'input[type=radio][name^="shipping_method"][value*=":' + iid + '"]';
                var isChecked = $(selector).filter(':checked').length > 0;
                if (!isChecked) return;
                var val = $container.find('select.mobapp-carrier-select').val() || '';
                var custom = $container.find('.mobapp-custom-carrier').val() || '';
                if ( val !== '' || custom !== '' ) {
                    scheduleSave(iid, val, custom);
                }
            });
        }, 60);
    });

    // Also respond to WC AJAX updates (rates re-render)
    $(document.body).on('updated_shipping_method updated_checkout updated_shipping_method', function() {
        setTimeout(updateSelectorsVisibility, 80);
    });

    // Initialize on DOM ready
    $(function(){
        updateSelectorsVisibility();
    });
})(jQuery);
JS;

    wp_add_inline_script( 'mobapp-transportes', $js );

    // Register and enqueue a dummy style handle, then add inline CSS to control layout/visibility
    wp_register_style( 'mobapp-transportes-style', false );
    wp_enqueue_style( 'mobapp-transportes-style' );

    $css = <<<'CSS'
/* Ensure selector is hidden by default and placed below radio; full width */
.mobapp-carrier-selector {
  display: none;
  width: 100% !important;
  box-sizing: border-box;
  clear: both;
  margin-top: 6px;
}

/* Full width select and input */
.mobapp-carrier-selector .mobapp-carrier-select,
.mobapp-carrier-selector .mobapp-custom-carrier {
  display: block !important;
  width: 100% !important;
  max-width: none !important;
  box-sizing: border-box;
  margin: 0;
  padding: .45em;
}

/* Force the selector to sit below the shipping label/radio */
li.shipping_method .mobapp-carrier-selector,
.woocommerce-shipping-methods .mobapp-carrier-selector {
  float: none !important;
  position: relative !important;
}

/* Optional: small indent to align with label text (adjust if needed) */
.woocommerce-shipping-methods li .mobapp-carrier-selector {
  margin-left: 0;
}

/* Mobile tweaks */
@media (max-width: 768px) {
  .mobapp-carrier-selector { margin-top: 10px; }
}
CSS;

    wp_add_inline_style( 'mobapp-transportes-style', $css );
}

/* --------------------------------------------------------------------
 * Shipping method class (instance-based)
 * -------------------------------------------------------------------- */
add_action( 'woocommerce_shipping_init', function() {
    if ( class_exists( 'Mobapp_Envio_Personalizado' ) ) {
        return;
    }

    class Mobapp_Envio_Personalizado extends WC_Shipping_Method {

        public $cost;
        public $free_shipping;
        public $carriers;
        public $allow_custom_carrier;
        public $carrier_field;

        public function __construct( $instance_id = 0 ) {
            $this->id = 'mobapp_envio_personalizado';
            $this->instance_id = absint( $instance_id );
            $this->method_title = 'Envío Personalizado';
            $this->method_description = 'Elige entre varios transportistas o añade uno nuevo';
            $this->supports = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );
            $this->init();
        }

        public function init() {
            $this->init_form_fields();
            $this->init_settings();

            $this->title                = $this->get_option( 'title', 'Elija su método de envío' );
            $this->cost                 = $this->get_option( 'cost', '0' );
            $this->free_shipping        = $this->get_option( 'free_shipping', 'no' );
            $this->carriers             = $this->get_option( 'carriers', '' );
            $this->allow_custom_carrier = $this->get_option( 'allow_custom_carrier', 'yes' );
            $this->carrier_field        = $this->get_option( 'carrier_field', 'Transportista personalizado' );

            add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
        }

        public function init_form_fields() {
            $this->instance_form_fields = array(
                'title' => array(
                    'title' => 'Título',
                    'type' => 'text',
                    'description' => 'Título del método mostrado al cliente.',
                    'default' => 'Elija su método de envío',
                    'desc_tip' => true,
                ),
                'cost' => array(
                    'title' => 'Costo fijo',
                    'type' => 'price',
                    'description' => 'Costo del envío. Puede ser 0.',
                    'default' => '0',
                    'desc_tip' => true,
                ),
                'free_shipping' => array(
                    'title' => '¿Es gratis?',
                    'type' => 'checkbox',
                    'label' => 'Envío sin costo',
                    'default' => 'no',
                ),
                'carriers' => array(
                    'title' => 'Opciones de transportistas',
                    'type' => 'textarea',
                    'description' => "Enumera las opciones separadas por salto de línea. Ejemplo:\nOCA\nAndreani\nCorreo Argentino",
                    'default' => '',
                    'desc_tip' => true,
                ),
                'allow_custom_carrier' => array(
                    'title' => '¿Permitir transportista personalizado?',
                    'type' => 'checkbox',
                    'label' => 'El cliente puede ingresar su propia opción de transporte.',
                    'default' => 'yes',
                ),
                'carrier_field' => array(
                    'title' => 'Texto para opción personalizada',
                    'type' => 'text',
                    'description' => 'Texto del campo para "Otro (especifique)".',
                    'default' => 'Transportista personalizado',
                    'desc_tip' => true,
                ),
            );
        }

        // Append selected carrier from session to the rate label if present
        public function calculate_shipping( $package = array() ) {
            $label = $this->title;

            if ( isset( $this->instance_id ) && $this->instance_id ) {
                $selected = WC()->session->get( 'mobapp_carrier_' . $this->instance_id, '' );
                $custom   = WC()->session->get( 'mobapp_custom_carrier_' . $this->instance_id, '' );

                // MODIFICADO: Si selected es 'custom' O está vacío, usar el valor de custom si existe
                if ( ( $selected === 'custom' || $selected === '' ) && ! empty( $custom ) ) {
                    $selected_label = sanitize_text_field( $custom );
                } else {
                    $selected_label = sanitize_text_field( $selected );
                }

                if ( ! empty( $selected_label ) ) {
                    $label = $label . ' - ' . $selected_label;
                }
            }

            $rate = array(
                'id'    => $this->get_rate_id(),
                'label' => $label,
                'cost'  => ($this->free_shipping === 'yes') ? 0 : floatval( $this->cost ),
                'package' => $package,
            );
            $this->add_rate( $rate );
        }
    }
});

add_filter( 'woocommerce_shipping_methods', function( $methods ) {
    $methods['mobapp_envio_personalizado'] = 'Mobapp_Envio_Personalizado';
    return $methods;
} );

/* --------------------------------------------------------------------
 * FRONTEND: show selector per instance (data-instance) — session-backed
 * -------------------------------------------------------------------- */
add_action( 'woocommerce_after_shipping_rate', 'mobapp_mostrar_campo_transportista', 10, 2 );
function mobapp_mostrar_campo_transportista( $method, $index ) {
    if ( $method->get_method_id() !== 'mobapp_envio_personalizado' ) {
        return;
    }

    $instance_id = $method->get_instance_id();
    if ( ! $instance_id ) {
        return;
    }

    $shipping_method = new Mobapp_Envio_Personalizado( $instance_id );

    $carriers_raw  = $shipping_method->get_option( 'carriers', '' );
    $allow_custom  = $shipping_method->get_option( 'allow_custom_carrier', 'yes' );
    $custom_label  = $shipping_method->get_option( 'carrier_field', 'Transportista personalizado' );

    $carrier_options = array();
    if ( strlen( trim( $carriers_raw ) ) > 0 ) {
        $lines = array_map( 'trim', preg_split('/\r\n|\r|\n/', $carriers_raw) );
        foreach ( $lines as $line ) {
            if ( $line === '' ) continue;
            $label = ( preg_match('/^\d+$/', $line) ) ? 'Opción ' . $line : $line;
            if ( ! in_array( $label, $carrier_options, true ) ) $carrier_options[] = $label;
        }
    }

    if ( empty( $carrier_options ) && $allow_custom !== 'yes' ) {
        return;
    }

    $selected_carrier = WC()->session->get( 'mobapp_carrier_' . $instance_id, '' );
    $custom_carrier   = WC()->session->get( 'mobapp_custom_carrier_' . $instance_id, '' );

    // Output container with data-instance so JS can match it to radio
    echo '<div class="mobapp-carrier-selector" data-instance="' . esc_attr( $instance_id ) . '" style="margin-top:10px;">';

    if ( ! empty( $carrier_options ) ) {
        echo '<select name="mobapp_carrier[' . esc_attr( $instance_id ) . ']" class="mobapp-carrier-select" style="width:100%; max-width:100%;">';
        echo '<option value="">' . esc_html__( 'Seleccione transportista', 'mobapp-transportes' ) . '</option>';
        foreach ( $carrier_options as $carrier ) {
            $sel = ( $selected_carrier === $carrier ) ? 'selected' : '';
            echo '<option value="' . esc_attr( $carrier ) . '" ' . $sel . '>' . esc_html( $carrier ) . '</option>';
        }
        if ( $allow_custom === 'yes' ) {
            $sel = ( $selected_carrier === 'custom' ) ? 'selected' : '';
            echo '<option value="custom" ' . $sel . '>' . esc_html__( 'Otro (especifique)', 'mobapp-transportes' ) . '</option>';
        }
        echo '</select>';
    }

    if ( $allow_custom === 'yes' ) {
        // MODIFICADO: Campo siempre visible (display: block)
        echo '<div style="margin-top:6px;">';
        echo '<input type="text" name="mobapp_custom_carrier[' . esc_attr( $instance_id ) . ']" class="mobapp-custom-carrier" placeholder="' . esc_attr( $custom_label ) . '" value="' . esc_attr( $custom_carrier ) . '" style="width:100%; max-width:100%; display:block;">';
        echo '</div>';
    }

    echo '</div>';
}

/* --------------------------------------------------------------------
 * AJAX handler: save selection in session with server dedupe; ignore empty payloads
 * -------------------------------------------------------------------- */
add_action( 'wp_ajax_mobapp_save_carrier', 'mobapp_ajax_save_carrier' );
add_action( 'wp_ajax_nopriv_mobapp_save_carrier', 'mobapp_ajax_save_carrier' );
function mobapp_ajax_save_carrier() {
    $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'mobapp-save-carrier' ) ) {
        wp_send_json_error( array( 'message' => 'Nonce inválido' ), 403 );
    }

    $instance_id = isset( $_POST['instance_id'] ) ? absint( $_POST['instance_id'] ) : 0;
    $carrier     = isset( $_POST['carrier'] ) ? sanitize_text_field( wp_unslash( $_POST['carrier'] ) ) : '';
    $custom      = isset( $_POST['custom'] ) ? sanitize_text_field( wp_unslash( $_POST['custom'] ) ) : '';

    if ( $instance_id <= 0 ) {
        wp_send_json_error( array( 'message' => 'Instance ID inválido' ), 400 );
    }

    // MODIFICADO: Permitir guardar si custom tiene valor aunque carrier esté vacío
    if ( $carrier === '' && $custom === '' ) {
        wp_send_json_success( array( 'saved' => false, 'reason' => 'empty' ) );
    }

    $session_key = 'mobapp_last_save_' . $instance_id;
    $last = WC()->session->get( $session_key, array( 'hash' => '', 'time' => 0 ) );

    $hash = md5( $instance_id . '|' . $carrier . '|' . $custom );
    $now = microtime( true );
    $threshold = 0.8;

    if ( isset( $last['hash'] ) && $last['hash'] === $hash && ( $now - floatval( $last['time'] ) ) < $threshold ) {
        wp_send_json_success( array( 'saved' => true, 'duplicate' => true ) );
    }

    // Guardar carrier (puede estar vacío)
    if ( $carrier !== '' ) {
        WC()->session->set( 'mobapp_carrier_' . $instance_id, $carrier );
    } else {
        WC()->session->__unset( 'mobapp_carrier_' . $instance_id );
    }

    // Guardar custom (siempre si tiene valor)
    if ( $custom !== '' ) {
        WC()->session->set( 'mobapp_custom_carrier_' . $instance_id, $custom );
    } else {
        WC()->session->__unset( 'mobapp_custom_carrier_' . $instance_id );
    }

    WC()->session->set( $session_key, array( 'hash' => $hash, 'time' => $now ) );

    wp_send_json_success( array( 'saved' => true, 'duplicate' => false ) );
}

/* --------------------------------------------------------------------
 * Fallbacks, validation, collect & save into order - same robust flow as before
 * -------------------------------------------------------------------- */
add_action( 'woocommerce_cart_updated', 'mobapp_guardar_carrier_session' );
add_action( 'woocommerce_checkout_update_order_review', 'mobapp_guardar_carrier_session' );
function mobapp_guardar_carrier_session() {
    if ( ! empty( $_POST['mobapp_carrier'] ) && is_array( $_POST['mobapp_carrier'] ) ) {
        foreach ( $_POST['mobapp_carrier'] as $instance_id => $carrier ) {
            $iid = absint( $instance_id );
            WC()->session->set( 'mobapp_carrier_' . $iid, sanitize_text_field( wp_unslash( $carrier ) ) );
        }
    }
    if ( ! empty( $_POST['mobapp_custom_carrier'] ) && is_array( $_POST['mobapp_custom_carrier'] ) ) {
        foreach ( $_POST['mobapp_custom_carrier'] as $instance_id => $custom ) {
            $iid = absint( $instance_id );
            WC()->session->set( 'mobapp_custom_carrier_' . $iid, sanitize_text_field( wp_unslash( $custom ) ) );
        }
    }
}

add_action( 'woocommerce_checkout_process', 'mobapp_validar_carrier' );
function mobapp_validar_carrier() {
    $chosen_methods = WC()->session->get( 'chosen_shipping_methods', array() );
    if ( empty( $chosen_methods ) || ! is_array( $chosen_methods ) ) return;
    foreach ( $chosen_methods as $chosen ) {
        if ( strpos( $chosen, 'mobapp_envio_personalizado' ) === 0 ) {
            $parts = explode( ':', $chosen );
            $instance_id = isset( $parts[1] ) ? absint( $parts[1] ) : 0;
            if ( $instance_id <= 0 ) continue;
            
            $carrier = isset( $_POST['mobapp_carrier'][ $instance_id ] ) ? sanitize_text_field( wp_unslash( $_POST['mobapp_carrier'][ $instance_id ] ) ) : WC()->session->get( 'mobapp_carrier_' . $instance_id, '' );
            $custom  = isset( $_POST['mobapp_custom_carrier'][ $instance_id ] ) ? sanitize_text_field( wp_unslash( $_POST['mobapp_custom_carrier'][ $instance_id ] ) ) : WC()->session->get( 'mobapp_custom_carrier_' . $instance_id, '' );
            
            // MODIFICADO: Validar que haya algo seleccionado O escrito en el campo personalizado
            if ( empty( $carrier ) && empty( $custom ) ) {
                wc_add_notice( __( 'Por favor seleccione o ingrese un transportista.' , 'mobapp-transportes' ), 'error' );
            }
            // Solo mostrar error de custom vacío si explícitamente eligió "custom" pero no escribió nada
            if ( $carrier === 'custom' && empty( $custom ) ) {
                wc_add_notice( __( 'Por favor especifique el transportista personalizado.' , 'mobapp-transportes' ), 'error' );
            }
        }
    }
}

/* Helper: collect selected carriers (prefer POST, fallback session) */
function mobapp_collect_selected_carriers() {
    $saved = array();
    $chosen_methods = WC()->session->get( 'chosen_shipping_methods', array() );
    $get_instance_labels = function( $iid ) {
        $shipping_method = new Mobapp_Envio_Personalizado( $iid );
        $carriers_raw = $shipping_method->get_option( 'carriers', '' );
        if ( strlen( trim( $carriers_raw ) ) === 0 ) return array();
        $lines = array_map( 'trim', preg_split('/\r\n|\r|\n/', $carriers_raw) );
        $labels = array();
        foreach ( $lines as $line ) {
            if ( $line === '' ) continue;
            $labels[] = ( preg_match('/^\d+$/', $line) ) ? 'Opción ' . $line : $line;
        }
        return array_values( array_unique( $labels ) );
    };

    if ( empty( $chosen_methods ) || ! is_array( $chosen_methods ) ) {
        if ( ! empty( $_POST['mobapp_carrier'] ) && is_array( $_POST['mobapp_carrier'] ) ) {
            foreach ( $_POST['mobapp_carrier'] as $instance_id => $carrier ) {
                $iid = absint( $instance_id );
                $carrier_final = sanitize_text_field( wp_unslash( $carrier ) );
                
                // MODIFICADO: Si carrier es 'custom' O está vacío, revisar el campo personalizado
                if ( $carrier_final === 'custom' || $carrier_final === '' ) {
                    if ( ! empty( $_POST['mobapp_custom_carrier'][ $iid ] ) ) {
                        $carrier_final = sanitize_text_field( wp_unslash( $_POST['mobapp_custom_carrier'][ $iid ] ) );
                    }
                }
                
                if ( is_numeric( $carrier_final ) ) {
                    $labels = $get_instance_labels( $iid );
                    $idx = intval( $carrier_final );
                    if ( isset( $labels[ $idx ] ) ) $carrier_final = $labels[ $idx ];
                }
                if ( $carrier_final !== '' ) $saved[ $iid ] = $carrier_final;
            }
        } else {
            if ( is_object( WC()->session ) && method_exists( WC()->session, '__get_session' ) ) {
                $session_all = WC()->session->__get_session();
                foreach ( $session_all as $k => $v ) {
                    if ( strpos( $k, 'mobapp_carrier_' ) === 0 ) {
                        $iid = intval( str_replace( 'mobapp_carrier_', '', $k ) );
                        if ( $iid > 0 ) {
                            $val = WC()->session->get( $k, '' );
                            // MODIFICADO: Si val es 'custom' o vacío, buscar en custom_carrier
                            if ( $val === 'custom' || $val === '' ) {
                                $custom_val = WC()->session->get( 'mobapp_custom_carrier_' . $iid, '' );
                                if ( ! empty( $custom_val ) ) {
                                    $val = $custom_val;
                                }
                            }
                            if ( $val !== '' ) $saved[ $iid ] = sanitize_text_field( $val );
                        }
                    }
                    // MODIFICADO: También revisar directamente los campos custom_carrier
                    if ( strpos( $k, 'mobapp_custom_carrier_' ) === 0 ) {
                        $iid = intval( str_replace( 'mobapp_custom_carrier_', '', $k ) );
                        if ( $iid > 0 && ! isset( $saved[ $iid ] ) ) {
                            $val = WC()->session->get( $k, '' );
                            if ( $val !== '' ) $saved[ $iid ] = sanitize_text_field( $val );
                        }
                    }
                }
            }
        }
    } else {
        foreach ( $chosen_methods as $chosen ) {
            if ( strpos( $chosen, 'mobapp_envio_personalizado' ) === 0 ) {
                $parts = explode( ':', $chosen );
                $instance_id = isset( $parts[1] ) ? absint( $parts[1] ) : 0;
                if ( $instance_id <= 0 ) continue;
                
                $carrier = isset( $_POST['mobapp_carrier'][ $instance_id ] ) ? sanitize_text_field( wp_unslash( $_POST['mobapp_carrier'][ $instance_id ] ) ) : WC()->session->get( 'mobapp_carrier_' . $instance_id, '' );
                
                // MODIFICADO: Si carrier es 'custom' O está vacío, buscar en custom_carrier
                if ( $carrier === 'custom' || $carrier === '' ) {
                    $custom = isset( $_POST['mobapp_custom_carrier'][ $instance_id ] ) ? sanitize_text_field( wp_unslash( $_POST['mobapp_custom_carrier'][ $instance_id ] ) ) : WC()->session->get( 'mobapp_custom_carrier_' . $instance_id, '' );
                    if ( ! empty( $custom ) ) {
                        $carrier = $custom;
                    }
                }
                
                if ( is_numeric( $carrier ) ) {
                    $labels = $get_instance_labels( $instance_id );
                    $idx = intval( $carrier );
                    if ( isset( $labels[ $idx ] ) ) $carrier = $labels[ $idx ];
                }
                if ( $carrier !== '' ) $saved[ $instance_id ] = $carrier;
            }
        }
    }

    return $saved;
}

/* Save carriers to order (meta + update shipping item titles idempotently) */
function mobapp_save_carriers_to_order( $order_id, $saved ) {
    if ( empty( $saved ) || ! $order_id ) return;

    update_post_meta( $order_id, '_mobapp_transportistas', $saved );
    foreach ( $saved as $iid => $carrier_name ) {
        update_post_meta( $order_id, '_mobapp_transportista_' . $iid, $carrier_name );
        $shipping_method = new Mobapp_Envio_Personalizado( $iid );
        $method_title = $shipping_method ? $shipping_method->get_option( 'title', '' ) : '';
        if ( $method_title ) {
            update_post_meta( $order_id, '_mobapp_method_title_' . $iid, sanitize_text_field( $method_title ) );
        }
    }

    // Update shipping item titles idempotently
    $order = wc_get_order( $order_id );
    if ( $order ) {
        $shipping_items = $order->get_items( 'shipping' );
        foreach ( $shipping_items as $item_id => $shipping_item ) {
            $item_instance = 0;
            if ( method_exists( $shipping_item, 'get_instance_id' ) ) {
                $item_instance = (int) $shipping_item->get_instance_id();
            } else {
                $method_id = $shipping_item->get_method_id();
                if ( strpos( $method_id, ':' ) !== false ) {
                    $parts = explode( ':', $method_id );
                    $item_instance = isset( $parts[1] ) ? absint( $parts[1] ) : 0;
                }
            }

            if ( $item_instance && isset( $saved[ $item_instance ] ) ) {
                $selection = $saved[ $item_instance ];
                $current_title = (string) $shipping_item->get_method_title();
                $suffix = ' - ' . $selection;
                if ( strpos( $current_title, $suffix ) === false ) {
                    $pattern = '/' . preg_quote($suffix, '/') . '(?:\s*' . preg_quote($suffix, '/') . ')*$/u';
                    $normalized = preg_replace( $pattern, $suffix, $current_title );
                    if ( $normalized === null ) $normalized = rtrim( $current_title );
                    $new_title = rtrim( $normalized ) . $suffix;
                    $shipping_item->set_method_title( $new_title );
                    $shipping_item->save();
                }
            }
        }
        $order->save();
    }
}

/* Hook into order creation and other flows */
add_action( 'woocommerce_checkout_create_order', 'mobapp_checkout_create_order_save', 20, 2 );
function mobapp_checkout_create_order_save( $order, $data ) {
    $saved = mobapp_collect_selected_carriers();
    if ( ! empty( $saved ) ) {
        mobapp_save_carriers_to_order( $order->get_id(), $saved );
    }
}

add_action( 'woocommerce_checkout_order_processed', 'mobapp_checkout_order_processed_save', 20, 2 );
function mobapp_checkout_order_processed_save( $order_id, $posted_data ) {
    if ( ! $order_id ) {
        return;
    }
    $existing = get_post_meta( $order_id, '_mobapp_transportistas', true );
    if ( ! empty( $existing ) ) {
        return;
    }
    $saved = mobapp_collect_selected_carriers();
    if ( ! empty( $saved ) ) {
        mobapp_save_carriers_to_order( $order_id, $saved );
    }
}

add_action( 'woocommerce_thankyou', 'mobapp_thankyou_final_save', 20 );
function mobapp_thankyou_final_save( $order_id ) {
    if ( ! $order_id ) {
        return;
    }
    $existing = get_post_meta( $order_id, '_mobapp_transportistas', true );
    if ( ! empty( $existing ) ) {
        return;
    }
    $saved = mobapp_collect_selected_carriers();
    if ( ! empty( $saved ) ) {
        mobapp_save_carriers_to_order( $order_id, $saved );
    }
}

/* --------------------------------------------------------------------
 * Admin / order details / email display (if not already defined above)
 * These are idempotent checks so they can be safely included even if
 * the functions exist earlier in the file.
 * -------------------------------------------------------------------- */
if ( ! function_exists( 'mobapp_mostrar_carrier_admin_fixed' ) ) {
    add_action( 'woocommerce_admin_order_data_after_shipping_address', 'mobapp_mostrar_carrier_admin_fixed', 11, 1 );
    function mobapp_mostrar_carrier_admin_fixed( $order ) {
        $saved = $order->get_meta( '_mobapp_transportistas', true );
        if ( is_array( $saved ) && ! empty( $saved ) ) {
            echo '<div class="mobapp-transportistas-admin" style="margin-top:10px;"><h4>' . esc_html__( 'Transportistas (Mobapp)', 'mobapp-transportes' ) . '</h4>';
            foreach ( $saved as $iid => $carrier ) {
                $method_title = $order->get_meta( '_mobapp_method_title_' . $iid, true );
                if ( ! $method_title ) {
                    $sm = new Mobapp_Envio_Personalizado( $iid );
                    $method_title = $sm ? $sm->get_option( 'title', '' ) : '';
                }
                $label = $method_title ? sprintf( '%s (instancia %d)', esc_html( $method_title ), intval( $iid ) ) : sprintf( 'Instancia %d', intval( $iid ) );
                echo '<p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $carrier ) . '</p>';
            }
            echo '</div>';
        }
    }
}

if ( ! function_exists( 'mobapp_mostrar_carrier_order_details_fixed' ) ) {
    add_action( 'woocommerce_order_details_after_order_table', 'mobapp_mostrar_carrier_order_details_fixed', 11, 1 );
    function mobapp_mostrar_carrier_order_details_fixed( $order ) {
        $saved = $order->get_meta( '_mobapp_transportistas', true );
        if ( is_array( $saved ) && ! empty( $saved ) ) {
            echo '<section class="woocommerce-order-carrier" style="margin-top:1em;"><h2>' . esc_html__( 'Información de transporte', 'mobapp-transportes' ) . '</h2>';
            foreach ( $saved as $iid => $carrier ) {
                $method_title = $order->get_meta( '_mobapp_method_title_' . $iid, true );
                if ( ! $method_title ) {
                    $sm = new Mobapp_Envio_Personalizado( $iid );
                    $method_title = $sm ? $sm->get_option( 'title', '' ) : '';
                }
                $label = $method_title ? sprintf( '%s (instancia %d)', esc_html( $method_title ), intval( $iid ) ) : sprintf( 'Instancia %d', intval( $iid ) );
                echo '<p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $carrier ) . '</p>';
            }
            echo '</section>';
        }
    }
}

if ( ! function_exists( 'mobapp_mostrar_carrier_email' ) ) {
    add_action( 'woocommerce_email_after_order_table', 'mobapp_mostrar_carrier_email', 10, 4 );
    function mobapp_mostrar_carrier_email( $order, $sent_to_admin, $plain_text = false, $email = null ) {
        if ( ! is_a( $order, 'WC_Order' ) ) {
            $order = wc_get_order( $order );
        }
        $saved = $order ? $order->get_meta( '_mobapp_transportistas', true ) : array();
        if ( is_array( $saved ) && ! empty( $saved ) ) {
            if ( $plain_text ) {
                echo "\nTransportistas:\n";
                foreach ( $saved as $iid => $carrier ) {
                    $method_title = $order->get_meta( '_mobapp_method_title_' . $iid, true );
                    $label = $method_title ? sprintf( '%s (instancia %d)', $method_title, intval( $iid ) ) : sprintf( 'Instancia %d', intval( $iid ) );
                    echo sprintf( ' - %s: %s', $label, $carrier ) . "\n";
                }
                echo "\n";
            } else {
                echo '<div class="mobapp-transportistas-email" style="margin-top:1em;">';
                echo '<h3>' . esc_html__( 'Transportistas seleccionados', 'mobapp-transportes' ) . '</h3>';
                foreach ( $saved as $iid => $carrier ) {
                    $method_title = $order->get_meta( '_mobapp_method_title_' . $iid, true );
                    $label = $method_title ? sprintf( '%s (instancia %d)', esc_html( $method_title ), intval( $iid ) ) : sprintf( 'Instancia %d', intval( $iid ) );
                    echo '<p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $carrier ) . '</p>';
                }
                echo '</div>';
            }
        }
    }
}

if ( ! function_exists( 'mobapp_add_meta_to_email_fixed' ) ) {
    add_filter( 'woocommerce_email_order_meta_fields', 'mobapp_add_meta_to_email_fixed', 11, 3 );
    function mobapp_add_meta_to_email_fixed( $fields, $sent_to_admin, $order ) {
        $saved = $order ? $order->get_meta( '_mobapp_transportistas', true ) : array();
        if ( is_array( $saved ) && ! empty( $saved ) ) {
            $i = 1;
            foreach ( $saved as $iid => $carrier ) {
                $method_title = $order->get_meta( '_mobapp_method_title_' . $iid, true );
                if ( ! $method_title ) {
                    $sm = new Mobapp_Envio_Personalizado( $iid );
                    $method_title = $sm ? $sm->get_option( 'title', '' ) : '';
                }
                $label = $method_title ? sprintf( __( 'Transportista (%s)', 'mobapp-transportes' ), $method_title ) : sprintf( __( 'Transportista (instancia %d)', 'mobapp-transportes' ), intval( $iid ) );
                $fields['mobapp_carrier_' . $i] = array(
                    'label' => $label,
                    'value' => esc_html( $carrier ),
                );
                $i++;
            }
        }
        return $fields;
    }
}

// EOF
