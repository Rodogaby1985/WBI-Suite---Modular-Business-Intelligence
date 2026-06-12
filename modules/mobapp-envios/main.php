<?php
/**
* Plugin Name: MOBAPP ENVÍOS POR CORREO
* Plugin URI: https://mobappexpress.com
* Description: Envíos cotizados Correo Argentino, Andreani, OCA y Urbano
* Version: 2.1.4
* Author: MOBAPP EXPRESS
* Author URI: https://mobappexpress.com
* Requires at least: 4.0
* Tested up to: 6.3
*
* Text Domain: MOBAPP ENVÍOS
* Domain path: /languages/
*
*/

defined( "ABSPATH" ) or die( "¡sin trampas!" );

/* ========== MEJORA: PROTECCIÓN FLUSH REDIS/CACHE ========== */
add_action( 'wp', 'mobapp_setup_schedule' );
function mobapp_setup_schedule() {
    if ( ! wp_next_scheduled( 'mobapp_daily_event' ) ) {
        wp_schedule_event( time(), 'hourly', 'mobapp_daily_event');
    }
}
add_action( 'mobapp_daily_event', 'mobapp_do_this_daily' );

function mobapp_do_this_daily() {
    $csvs = array(
        array('url' => 'https://docs.google.com/spreadsheets/d/e/2PACX-1vSm0Q6LN6JIWXLXyX6OJx6YKfdL1PZ1ZXbXYJ8k6TLeKuon1VSiE5mFppvbK3v4-kX5SSOPteA7dzq6/pub?gid=506491561&single=true&output=csv', 'transient' => 'datos_csv_andreani_dom'),
        array('url' => 'https://docs.google.com/spreadsheets/d/e/2PACX-1vSm0Q6LN6JIWXLXyX6OJx6YKfdL1PZ1ZXbXYJ8k6TLeKuon1VSiE5mFppvbK3v4-kX5SSOPteA7dzq6/pub?gid=2067361143&single=true&output=csv', 'transient' => 'datos_csv_andreani_suc'),
        array('url' => 'https://docs.google.com/spreadsheets/d/e/2PACX-1vSm0Q6LN6JIWXLXyX6OJx6YKfdL1PZ1ZXbXYJ8k6TLeKuon1VSiE5mFppvbK3v4-kX5SSOPteA7dzq6/pub?gid=0&single=true&output=csv', 'transient' => 'datos_csv_ca_dom'),
        array('url' => 'https://docs.google.com/spreadsheets/d/e/2PACX-1vSm0Q6LN6JIWXLXyX6OJx6YKfdL1PZ1ZXbXYJ8k6TLeKuon1VSiE5mFppvbK3v4-kX5SSOPteA7dzq6/pub?gid=1897873008&single=true&output=csv', 'transient' => 'datos_csv_ca_suc'),
        array('url' => 'https://docs.google.com/spreadsheets/d/e/2PACX-1vT6l-Z2nmlhlTRQp5Aaki1Mwpao8XKHrSTRllymp8UiUP7dZ20hVitvqSvRl72GwDnXsGh9P31mq0vi/pub?gid=98567282&single=true&output=csv', 'transient' => 'datos_csv_oca_dom'),
        array('url' => 'https://docs.google.com/spreadsheets/d/e/2PACX-1vT6l-Z2nmlhlTRQp5Aaki1Mwpao8XKHrSTRllymp8UiUP7dZ20hVitvqSvRl72GwDnXsGh9P31mq0vi/pub?gid=1766360152&single=true&output=csv', 'transient' => 'datos_csv_oca_suc'),
        array('url' => 'https://docs.google.com/spreadsheets/d/e/2PACX-1vT6l-Z2nmlhlTRQp5Aaki1Mwpao8XKHrSTRllymp8UiUP7dZ20hVitvqSvRl72GwDnXsGh9P31mq0vi/pub?gid=1666417641&single=true&output=csv', 'transient' => 'datos_csv_urbano_dom'),
        array('url' => 'https://docs.google.com/spreadsheets/d/e/2PACX-1vR10pelt-jk2dKh38-ar_pgGsXo2fADUjHOMkBK7jt3uV8Y-zQJSRGcaZSk3S_kL6GP33IAw6Mjd3LA/pub?gid=1928763953&single=true&output=csv', 'transient' => 'datos_csv_flash_cp'),
        array('url' => 'https://docs.google.com/spreadsheets/d/e/2PACX-1vR10pelt-jk2dKh38-ar_pgGsXo2fADUjHOMkBK7jt3uV8Y-zQJSRGcaZSk3S_kL6GP33IAw6Mjd3LA/pub?gid=1451646784&single=true&output=csv', 'transient' => 'datos_csv_flash_tarifa'),
    );
    foreach ($csvs as $csv) {
        $csv_data = file_get_contents_curl($csv['url']);
        if ($csv_data) set_transient($csv['transient'], $csv_data, DAY_IN_SECONDS);
    }
}
function eliminar_cron_diario() {
    $timestamp = wp_next_scheduled('mobapp_daily_event');
    wp_unschedule_event($timestamp, 'mobapp_daily_event');
}
register_deactivation_hook(__FILE__, 'eliminar_cron_diario');

function file_get_contents_curl($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_AUTOREFERER, TRUE);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}
function mobapp_get_tarifa_csv($transient_name, $url) {
    $csv_data = get_transient($transient_name);
    if (!$csv_data) {
        $csv_data = file_get_contents_curl($url);
        if ($csv_data) set_transient($transient_name, $csv_data, DAY_IN_SECONDS);
    }
    return $csv_data ? $csv_data : '';
}
/* ========== FIN MEJORA REDIS ========== */

function ocultar_envios($rates, $package) {
    $zonas = array();
    $delivery_zones = WC_Shipping_Zones::get_zones();
    foreach ((array) $delivery_zones as $key => $the_zone ) {
        $zonas[] = $the_zone['zone_name'];
    }
    $zona_del_carrito = WC_Shipping_Zones::get_zone_matching_package( $package );
    $zona_del_carrito_nombre = $zona_del_carrito->get_zone_name();
    $correos =  WC()->shipping->get_shipping_methods();
    foreach($correos as $correo){
        $id = $correo->id;
        $zonas_posiciones = $correo->get_option('ocultar_para_zonas');
        if($zonas_posiciones == null) continue;
        if(is_array($zonas_posiciones)){
            foreach($zonas_posiciones as $zona_pos){
                if(isset($zonas[(int)$zona_pos]) && $zonas[(int)$zona_pos] == $zona_del_carrito_nombre){
                    unset($rates[$id]);
                }
            }
        } else {
            if(isset($zonas[(int)$zonas_posiciones]) && $zonas[(int)$zonas_posiciones] == $zona_del_carrito_nombre){
                unset($rates[$id]);
            }
        }
    }
    return $rates;
}
add_filter('woocommerce_package_rates', 'ocultar_envios', 10, 2);

/* ========== MEJORA: DESTACADO EN NEGRITA Y ICONOS EN EL ADMIN ========== */
function mobapp_add_common_fields(&$form_fields) {
    $form_fields['featured'] = array(
        'title'   => esc_html__('Destacar este método', 'mobapp-envios'),
        'type'    => 'checkbox',
        'label'   => esc_html__('Mostrar como método recomendado', 'mobapp-envios'),
        'default' => 'no'
    );
    $form_fields['featured_text'] = array(
        'title'       => esc_html__('Leyenda descriptiva destacada', 'mobapp-envios'),
        'type'        => 'text',
        'description' => esc_html__('Leyenda que se mostrará en el checkout en negrita. Puedes copiar y pegar estos iconos: ', 'mobapp-envios') . '🔥❤️⭐❤️‍🔥🥇🏆✔️💎',
        'default'     => '',
        'desc_tip'    => true
    );
}
function mobapp_append_featured_tooltip(&$titulo, $method_object) {
    $featured = $method_object->get_option('featured');
    $featured_text = $method_object->get_option('featured_text');
    if ($featured === 'yes' && !empty($featured_text)) {
        $featured_text = '<strong>' . esc_html($featured_text) . '</strong>';
        $titulo .= ' ' . $featured_text;
    }
}
/* ========== FIN MEJORA NEGRITA E ICONOS ========== */

/* Permitir HTML en el label en el checkout */
add_filter('woocommerce_shipping_rate_label', function($label, $rate) {
    return $label;
}, 10, 2);
add_filter('woocommerce_cart_shipping_method_full_label', function($label, $method) {
    return $label;
}, 10, 2);

/* ========== NUEVO: FUNCIONES GENERALES PARA EMBALAJE ========== */
function mobapp_set_packing_session($method_id, $base, $packing, $label, $total) {
    if ( function_exists('WC') && WC()->session ) {
        WC()->session->set('mobapp_packing', array(
            'method'  => $method_id,
            'base'    => floatval($base),
            'packing' => floatval($packing),
            'label'   => sanitize_text_field($label),
            'total'   => floatval($total),
        ));
    }
}
function mobapp_get_packing_session() {
    if ( function_exists('WC') && WC()->session ) {
        return WC()->session->get('mobapp_packing');
    }
    return null;
}
function mobapp_clear_packing_session() {
    if ( function_exists('WC') && WC()->session ) {
        WC()->session->set('mobapp_packing', null);
    }
}

/* ========== SPLIT EMBALAJE MEJORADO ========== */

// Reemplaza esta función en main.php (hook woocommerce_checkout_update_order_meta)
add_action( 'woocommerce_checkout_update_order_meta', 'mobapp_split_packing_into_order_meta', 20, 2 );
function mobapp_split_packing_into_order_meta( $order_id, $data ) {
    // Intentar primero obtener packing desde la sesión (compatibilidad)
    $packing_session = mobapp_get_packing_session();

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    $found = false;

    // Obtenemos el listado de métodos registrados (clases del plugin)
    $registered_methods = array();
    if ( function_exists( 'WC' ) && WC()->shipping ) {
        $registered_methods = WC()->shipping->get_shipping_methods();
    }

    // Recorremos los shipping items del pedido (normalmente hay 1)
    foreach ( $order->get_items( 'shipping' ) as $shipping_item_id => $shipping_item ) {
        // Usar SOLO getters públicos para evitar warnings de metadatos internos
        $method_id = method_exists( $shipping_item, 'get_method_id' ) ? (string) $shipping_item->get_method_id() : '';
        $method_title = method_exists( $shipping_item, 'get_method_title' ) ? (string) $shipping_item->get_method_title() : '';
        $shipping_total = floatval( $shipping_item->get_total() );

        // Si el método está registrado en WC()->shipping->get_shipping_methods, leer sus opciones
        if ( $method_id && isset( $registered_methods[ $method_id ] ) ) {
            $method_obj = $registered_methods[ $method_id ];

            // Intentar leer costo de embalaje y label desde las opciones del método
            $packing_cost = 0;
            if ( method_exists( $method_obj, 'get_option' ) ) {
                $packing_cost = floatval( $method_obj->get_option( 'costo_embalaje', 0 ) );
                $packing_label = $method_obj->get_option( 'label_embalaje', 'Costo embalaje' );
            } else {
                // Fallback si no existe get_option (muy raro)
                $packing_cost = 0;
                $packing_label = 'Costo embalaje';
            }

            // Si hay costo de embalaje configurado para ese método, lo guardamos en meta
            if ( $packing_cost > 0 ) {
                update_post_meta( $order_id, '_mobapp_packing_amount', $packing_cost );
                update_post_meta( $order_id, '_mobapp_packing_label', sanitize_text_field( $packing_label ) );
                update_post_meta( $order_id, '_mobapp_packing_formatted', wc_price( $packing_cost, array( 'currency' => $order->get_currency() ) ) );

                // Guardar también paquete mínimo de compatibilidad
                $packing_arr = array(
                    'method'  => $method_id,
                    'base'    => max( 0, $shipping_total - $packing_cost ), // info orientativa
                    'packing' => $packing_cost,
                    'label'   => sanitize_text_field( $packing_label ),
                    'total'   => $shipping_total,
                );
                update_post_meta( $order_id, '_mobapp_packing', maybe_serialize( $packing_arr ) );

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( "mobapp: saved packing meta from method options for order {$order_id} -> method_id={$method_id}, packing_cost={$packing_cost}" );
                }

                $found = true;
                break; // procesamos solo el primer shipping coincidente
            }
        }
    }

    // Si no encontramos packing a partir del shipping item, fallback a la sesión (si existe)
    if ( ! $found && ! empty( $packing_session ) && is_array( $packing_session ) ) {
        $amount = isset( $packing_session['packing'] ) ? floatval( $packing_session['packing'] ) : 0;
        $label  = ! empty( $packing_session['label'] ) ? sanitize_text_field( $packing_session['label'] ) : 'Costo embalaje';
        update_post_meta( $order_id, '_mobapp_packing_amount', $amount );
        update_post_meta( $order_id, '_mobapp_packing_label', $label );
        update_post_meta( $order_id, '_mobapp_packing_formatted', wc_price( $amount, array( 'currency' => $order->get_currency() ) ) );
        update_post_meta( $order_id, '_mobapp_packing', maybe_serialize( $packing_session ) );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "mobapp: saved packing meta from session for order {$order_id} -> session_packing: " . print_r( $packing_session, true ) );
        }
    }

    // No tocamos shipping_item->set_total ni añadimos fees visibles para el cliente:
    // esto evita que aparezca el item en thank-you/emails. Las metas quedan para backend/PDF.
}
/* limpiar la sesión */
add_action( 'woocommerce_checkout_order_processed', 'mobapp_clear_packing_session_on_processed', 10, 1 );
function mobapp_clear_packing_session_on_processed( $order_id ) {
    mobapp_clear_packing_session();
}

/* añadir fila "Costo embalaje" en totales */
// Reemplaza la función mobapp_add_packing_row_to_order_totals por esta (solo muestra en admin)
add_filter( 'woocommerce_get_order_item_totals', 'mobapp_add_packing_row_to_order_totals', 10, 3 );
function mobapp_add_packing_row_to_order_totals( $total_rows, $order, $tax_display ) {
    // Mostrar esta fila SOLO EN EL ADMIN (backend). Evitamos que se muestre en emails/thank-you.
    if ( ! is_admin() ) {
        return $total_rows;
    }

    if ( ! $order instanceof WC_Order ) {
        return $total_rows;
    }

    $packing_amount = 0;
    $packing_label  = 'Costo embalaje';

    // Primero, buscar en items de tipo fee (por compatibilidad si existiera)
    foreach ( $order->get_items( 'fee' ) as $fee_item ) {
        $name = $fee_item->get_name();
        if ( $name && ( stripos( $name, 'embalaje' ) !== false || stripos( $name, 'pack' ) !== false ) ) {
            $packing_amount = floatval( $fee_item->get_total() );
            $packing_label  = $name;
            break;
        }
    }

    // Si no hay fee, usar meta formateado como fallback
    if ( $packing_amount == 0 ) {
        $pm_formatted = $order->get_meta( '_mobapp_packing_formatted' );
        $pm_amount = $order->get_meta( '_mobapp_packing_amount' );
        $pm_label = $order->get_meta( '_mobapp_packing_label' );

        if ( $pm_amount ) {
            $packing_amount = floatval( $pm_amount );
            if ( $pm_label ) $packing_label = sanitize_text_field( $pm_label );
        } elseif ( $pm_formatted ) {
            $total_rows['mobapp_packing'] = array(
                'label' => esc_html( $packing_label ) . ':',
                'value' => $pm_formatted
            );
            return $total_rows;
        }
    }

    if ( $packing_amount <= 0 ) {
        return $total_rows;
    }

    $formatted = wc_price( $packing_amount, array( 'currency' => $order->get_currency() ) );

    // Insertar la fila después del shipping si existe, sino antes del order_total
    $new_rows = array();
    $inserted = false;

    foreach ( $total_rows as $key => $row ) {
        $new_rows[ $key ] = $row;

        if ( ! $inserted && ( strpos( $key, 'shipping' ) !== false || $key === 'shipping' ) ) {
            $new_rows['mobapp_packing'] = array(
                'label' => esc_html( $packing_label ) . ':',
                'value' => $formatted
            );
            $inserted = true;
        }
    }

    if ( ! $inserted ) {
        $new_rows = array();
        foreach ( $total_rows as $key => $row ) {
            if ( ! $inserted && $key === 'order_total' ) {
                $new_rows['mobapp_packing'] = array(
                    'label' => esc_html( $packing_label ) . ':',
                    'value' => $formatted
                );
                $inserted = true;
            }
            $new_rows[ $key ] = $row;
        }
        if ( ! $inserted ) {
            $new_rows['mobapp_packing'] = array(
                'label' => esc_html( $packing_label ) . ':',
                'value' => $formatted
            );
        }
    }

    return $new_rows;
}

/* ========== MÉTODOS DE ENVÍO ========== */

/* ANDREANI DOMICILIO */
add_action( 'woocommerce_shipping_init', 'mobapp_andreani_domicilio_envios_init' );
function mobapp_andreani_domicilio_envios_init() {
    if ( ! class_exists( 'WC_MOBAPP_ANDREANI_DOMICILIO_ENVIOS' ) ) {
        class WC_MOBAPP_ANDREANI_DOMICILIO_ENVIOS extends WC_Shipping_Method{
            public function __construct(){
                $this->id                 = 'mobapp-andreani-domicilio-envios';
                $this->method_title       = __( 'ANDREANI DOMICILIO');
                $this->method_description = __( 'Cotiza tus envíos con MOBAPP ENVÍOS' );
                $this->init();
                $this->enabled            = $this->get_option( 'enabled' );
                $this->title              = $this->get_option('title', 'ANDREANI DOMICILIO');
            }
            public function init(){
                $this->init_form_fields();
                add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
            }
            public function calculate_shipping( $package = Array() ){
                $csv_data = mobapp_get_tarifa_csv(
                    'datos_csv_andreani_dom',
                    'https://docs.google.com/spreadsheets/d/e/2PACX-1vSm0Q6LN6JIWXLXyX6OJx6YKfdL1PZ1ZXbXYJ8k6TLeKuon1VSiE5mFppvbK3v4-kX5SSOPteA7dzq6/pub?gid=506491561&single=true&output=csv'
                );
                $provincia = isset($package['destination']['state']) ? $package['destination']['state'] : '';
                $peso_carrito = WC()->cart ? WC()->cart->get_cart_contents_weight() : 0;
                $base_cost = '0';
                $titulo = $this->get_option('title');
                if ($csv_data) {
                    $filas = explode("\n", $csv_data);
                    foreach ($filas as $fila) {
                        $columnas = str_getcsv($fila);
                        $titulo_tabla = isset($columnas[0]) ? $columnas[0] : '';
                        $codigo = isset($columnas[2]) ? $columnas[2] : '';
                        $peso_min = isset($columnas[3]) ? floatval($columnas[3]) : 0;
                        $peso_max = isset($columnas[4]) ? floatval($columnas[4]) : 0;
                        $precio = isset($columnas[5]) ? $columnas[5] : 0;
                        if ( $provincia == $codigo && $peso_carrito <= $peso_max && $peso_carrito > $peso_min){
                            $titulo = $titulo_tabla;
                            $base_cost = $precio;
                        }
                    }
                }

                $packing_cost = floatval( $this->get_option('costo_embalaje', 0) );
                $packing_label = $this->get_option('label_embalaje', 'Costo embalaje');
                $total_cost = floatval($base_cost) + $packing_cost;

                mobapp_append_featured_tooltip($titulo, $this);

                $this->add_rate( array(
                    'id'     => $this->id,
                    'label'  => $titulo,
                    'cost'   => $total_cost
                ));

                mobapp_set_packing_session( $this->id, $base_cost, $packing_cost, $packing_label, $total_cost );
            }
            public function init_form_fields() {
                $zonas = array();
                $delivery_zones = WC_Shipping_Zones::get_zones();
                foreach ((array) $delivery_zones as $key => $the_zone ) {
                    $zonas[] = $the_zone['zone_name'];
                }
                $form_fields = array(
                  'enabled' => array(
                     'title'   => esc_html__('Activar/Desactivar', 'mobapp-andreani-domicilio-envios' ),
                     'type'    => 'checkbox',
                     'label'   => esc_html__('Activar método de envío', 'mobapp-andreani-domicilio-envios'  ),
                     'default' => 'yes'
                  ),
                  'title' => array(
                     'title'       => esc_html__('Título del envío en caso de peso excedente', 'mobapp-andreani-domicilio-envios' ),
                     'type'        => 'text',
                     'description' => esc_html__('Ingresar título del envío', 'mobapp-andreani-domicilio-envios'  ),
                     'default'     => esc_html__('A COTIZAR - PESO SUPERIOR A 30KG - ANDREANI A DOMICILIO', 'mobapp-andreani-domicilio-envios' ),
                     'desc_tip'    => true
                  ),
                  'ocultar_para_zonas' => array(
                    'title'             => __( 'Ocultar envío para zonas específicas', 'mobapp-andreani-domicilio-envios' ),
                    'type'              => 'multiselect',
                    'class'             => 'wc-enhanced-select',
                    'css'               => 'width: 400px;',
                    'default'           => '',
                    'description'       => __( 'Seleccionar zonas en las que se ocultará este envío', 'mobapp-andreani-domicilio-envios' ),
                    'options'           => $zonas,
                    'desc_tip'          => true,
                    'custom_attributes' => array(
                        'data-placeholder' => __( 'Seleccionar zonas', 'mobapp-andreani-domicilio-envios' )
                    ),
                 ),
                 'costo_embalaje' => array(
                    'title'       => esc_html__('Costo de embalaje (valor fijo)', 'mobapp-andreani-domicilio-envios' ),
                    'type'        => 'text',
                    'description' => esc_html__('Valor fijo en la misma moneda que la tienda. Se suma a la tarifa tomada del CSV y se muestra unificada al cliente como costo de envío.', 'mobapp-andreani-domicilio-envios'  ),
                    'default'     => '0',
                    'desc_tip'    => true
                 ),
                 'label_embalaje' => array(
                    'title'       => esc_html__('Etiqueta para costo de embalaje (interno)', 'mobapp-andreani-domicilio-envios' ),
                    'type'        => 'text',
                    'description' => esc_html__('Etiqueta que se utilizará en el pedido como nombre del fee (ej: "Costo embalaje").', 'mobapp-andreani-domicilio-envios'  ),
                    'default'     => esc_html__('Costo embalaje', 'mobapp-andreani-domicilio-envios' ),
                    'desc_tip'    => true
                 )
                );
                mobapp_add_common_fields($form_fields);
                $this->form_fields = $form_fields;
            }
        }
    }
}
add_filter('woocommerce_shipping_methods','agregar_mobapp_andreani_domicilio_envios_method');
function agregar_mobapp_andreani_domicilio_envios_method( $methods ){
    $methods['mobapp-andreani-domicilio-envios'] = 'WC_MOBAPP_ANDREANI_DOMICILIO_ENVIOS';
    return $methods;
}

/* ANDREANI SUCURSAL */
add_action( 'woocommerce_shipping_init', 'mobapp_andreani_sucursal_envios_init' );
function mobapp_andreani_sucursal_envios_init() {
    if ( ! class_exists( 'WC_MOBAPP_ANDREANI_SUCURSAL_ENVIOS' ) ) {
        class WC_MOBAPP_ANDREANI_SUCURSAL_ENVIOS extends WC_Shipping_Method{
            public function __construct(){
                $this->id                 = 'mobapp-andreani-sucursal-envios';
                $this->method_title       = __( 'ANDREANI SUCURSAL');
                $this->method_description = __( 'Cotiza tus envíos con MOBAPP ENVÍOS' );
                $this->init();
                $this->enabled            = $this->get_option( 'enabled' );
                $this->title              = $this->get_option('title', 'ANDREANI SUCURSAL');
            }
            public function init(){
                $this->init_form_fields();
                add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
            }
            public function calculate_shipping( $package = Array() ){
                $csv_data = mobapp_get_tarifa_csv(
                    'datos_csv_andreani_suc',
                    'https://docs.google.com/spreadsheets/d/e/2PACX-1vSm0Q6LN6JIWXLXyX6OJx6YKfdL1PZ1ZXbXYJ8k6TLeKuon1VSiE5mFppvbK3v4-kX5SSOPteA7dzq6/pub?gid=2067361143&single=true&output=csv'
                );
                $provincia = isset($package['destination']['state']) ? $package['destination']['state'] : '';
                $peso_carrito = WC()->cart ? WC()->cart->get_cart_contents_weight() : 0;
                $base_cost = '0';
                $titulo = $this->get_option('title');
                if ($csv_data) {
                    $filas = explode("\n", $csv_data);
                    foreach ($filas as $fila) {
                        $columnas = str_getcsv($fila);
                        $titulo_tabla = isset($columnas[0]) ? $columnas[0] : '';
                        $codigo = isset($columnas[2]) ? $columnas[2] : '';
                        $peso_min = isset($columnas[3]) ? floatval($columnas[3]) : 0;
                        $peso_max = isset($columnas[4]) ? floatval($columnas[4]) : 0;
                        $precio = isset($columnas[5]) ? $columnas[5] : 0;
                        if ( $provincia == $codigo && $peso_carrito <= $peso_max && $peso_carrito > $peso_min){
                            $titulo = $titulo_tabla;
                            $base_cost = $precio;
                        }
                    }
                }
                $packing_cost = floatval( $this->get_option('costo_embalaje', 0) );
                $packing_label = $this->get_option('label_embalaje', 'Costo embalaje');
                $total_cost = floatval($base_cost) + $packing_cost;

                mobapp_append_featured_tooltip($titulo, $this);

                $this->add_rate( array(
                    'id'     => $this->id,
                    'label'  => $titulo,
                    'cost'   => $total_cost
                ));

                mobapp_set_packing_session( $this->id, $base_cost, $packing_cost, $packing_label, $total_cost );
            }
            public function init_form_fields() {
                $zonas = array();
                $delivery_zones = WC_Shipping_Zones::get_zones();
                foreach ((array) $delivery_zones as $key => $the_zone ) {
                    $zonas[] = $the_zone['zone_name'];
                }
                $form_fields = array(
                  'enabled' => array(
                     'title'   => esc_html__('Activar/Desactivar', 'mobapp-andreani-sucursal-envios' ),
                     'type'    => 'checkbox',
                     'label'   => esc_html__('Activar método de envío', 'mobapp-andreani-sucursal-envios'  ),
                     'default' => 'yes'
                  ),
                  'title' => array(
                     'title'       => esc_html__('Título del envío en caso de peso excedente', 'mobapp-andreani-sucursal-envios' ),
                     'type'        => 'text',
                     'description' => esc_html__('Ingresar título del envío', 'mobapp-andreani-sucursal-envios'  ),
                     'default'     => esc_html__('A COTIZAR - PESO SUPERIOR A 30KG - ANDREANI A SUCURSAL', 'mobapp-andreani-sucursal-envios' ),
                     'desc_tip'    => true
                  ),
                  'ocultar_para_zonas' => array(
                    'title'             => __( 'Ocultar envío para zonas específicas', 'mobapp-andreani-sucursal-envios' ),
                    'type'              => 'multiselect',
                    'class'             => 'wc-enhanced-select',
                    'css'               => 'width: 400px;',
                    'default'           => '',
                    'description'       => __( 'Seleccionar zonas en las que se ocultará este envío', 'mobapp-andreani-sucursal-envios' ),
                    'options'           => $zonas,
                    'desc_tip'          => true,
                    'custom_attributes' => array(
                        'data-placeholder' => __( 'Seleccionar zonas', 'mobapp-andreani-sucursal-envios' )
                    ),
                 ),
                 'costo_embalaje' => array(
                    'title'       => esc_html__('Costo de embalaje (valor fijo)', 'mobapp-andreani-sucursal-envios' ),
                    'type'        => 'text',
                    'description' => esc_html__('Valor fijo en la misma moneda que la tienda. Se suma a la tarifa tomada del CSV y se muestra unificada al cliente como costo de envío.', 'mobapp-andreani-sucursal-envios'  ),
                    'default'     => '0',
                    'desc_tip'    => true
                 ),
                 'label_embalaje' => array(
                    'title'       => esc_html__('Etiqueta para costo de embalaje (interno)', 'mobapp-andreani-sucursal-envios' ),
                    'type'        => 'text',
                    'description' => esc_html__('Etiqueta que se utilizará en el pedido como nombre del fee (ej: "Costo embalaje").', 'mobapp-andreani-sucursal-envios'  ),
                    'default'     => esc_html__('Costo embalaje', 'mobapp-andreani-sucursal-envios' ),
                    'desc_tip'    => true
                 )
                );
                mobapp_add_common_fields($form_fields);
                $this->form_fields = $form_fields;
            }
        }
    }
}
add_filter('woocommerce_shipping_methods','agregar_mobapp_andreani_sucursal_envios_method');
function agregar_mobapp_andreani_sucursal_envios_method( $methods ){
    $methods['mobapp-andreani-sucursal-envios'] = 'WC_MOBAPP_ANDREANI_SUCURSAL_ENVIOS';
    return $methods;
}

/* CORREO ARGENTINO DOMICILIO */
add_action( 'woocommerce_shipping_init', 'mobapp_correoargentino_domicilio_envios_init' );
function mobapp_correoargentino_domicilio_envios_init() {
    if ( ! class_exists( 'WC_MOBAPP_CORREOARGENTINO_DOMICILIO_ENVIOS' ) ) {
        class WC_MOBAPP_CORREOARGENTINO_DOMICILIO_ENVIOS extends WC_Shipping_Method{
            public function __construct(){
                $this->id                 = 'mobapp-correoargentino-domicilio-envios';
                $this->method_title       = __( 'CORREO ARGENTINO DOMICILIO');
                $this->method_description = __( 'Cotiza tus envíos con MOBAPP ENVÍOS' );
                $this->init();
                $this->enabled            = $this->get_option( 'enabled' );
                $this->title              = $this->get_option('title', 'CORREO ARGENTINO DOMICILIO');
            }
            public function init(){
                $this->init_form_fields();
                add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
            }
            public function calculate_shipping( $package = Array() ){
                $csv_data = mobapp_get_tarifa_csv(
                    'datos_csv_ca_dom',
                    'https://docs.google.com/spreadsheets/d/e/2PACX-1vSm0Q6LN6JIWXLXyX6OJx6YKfdL1PZ1ZXbXYJ8k6TLeKuon1VSiE5mFppvbK3v4-kX5SSOPteA7dzq6/pub?gid=0&single=true&output=csv'
                );
                $provincia = isset($package['destination']['state']) ? $package['destination']['state'] : '';
                $peso_carrito = WC()->cart ? WC()->cart->get_cart_contents_weight() : 0;
                $base_cost = '0';
                $titulo = $this->get_option('title');
                if ($csv_data) {
                    $filas = explode("\n", $csv_data);
                    foreach ($filas as $fila) {
                        $columnas = str_getcsv($fila);
                        $titulo_tabla = isset($columnas[0]) ? $columnas[0] : '';
                        $codigo = isset($columnas[2]) ? $columnas[2] : '';
                        $peso_min = isset($columnas[3]) ? floatval($columnas[3]) : 0;
                        $peso_max = isset($columnas[4]) ? floatval($columnas[4]) : 0;
                        $precio = isset($columnas[5]) ? $columnas[5] : 0;
                        if ( $provincia == $codigo && $peso_carrito <= $peso_max && $peso_carrito > $peso_min){
                            $titulo = $titulo_tabla;
                            $base_cost = $precio;
                        }
                    }
                }
                $packing_cost = floatval( $this->get_option('costo_embalaje', 0) );
                $packing_label = $this->get_option('label_embalaje', 'Costo embalaje');
                $total_cost = floatval($base_cost) + $packing_cost;

                mobapp_append_featured_tooltip($titulo, $this);

                $this->add_rate( array(
                    'id'     => $this->id,
                    'label'  => $titulo,
                    'cost'   => $total_cost
                ));

                mobapp_set_packing_session( $this->id, $base_cost, $packing_cost, $packing_label, $total_cost );
            }
            public function init_form_fields() {
                $zonas = array();
                $delivery_zones = WC_Shipping_Zones::get_zones();
                foreach ((array) $delivery_zones as $key => $the_zone ) {
                    $zonas[] = $the_zone['zone_name'];
                }
                $form_fields = array(
                  'enabled' => array(
                     'title'   => esc_html__('Activar/Desactivar', 'mobapp-correoargentino-domicilio-envios' ),
                     'type'    => 'checkbox',
                     'label'   => esc_html__('Activar método de envío', 'mobapp-correoargentino-domicilio-envios'  ),
                     'default' => 'yes'
                  ),
                  'title' => array(
                     'title'       => esc_html__('Título del envío en caso de peso excedente', 'mobapp-correoargentino-domicilio-envios' ),
                     'type'        => 'text',
                     'description' => esc_html__('Ingresar título del envío', 'mobapp-correoargentino-domicilio-envios'  ),
                     'default'     => esc_html__('A COTIZAR - PESO SUPERIOR A 30KG - CORREO ARGENTINO A DOMICILIO', 'mobapp-correoargentino-domicilio-envios' ),
                     'desc_tip'    => true
                  ),
                  'ocultar_para_zonas' => array(
                    'title'             => __( 'Ocultar envío para zonas específicas', 'mobapp-correoargentino-domicilio-envios' ),
                    'type'              => 'multiselect',
                    'class'             => 'wc-enhanced-select',
                    'css'               => 'width: 400px;',
                    'default'           => '',
                    'description'       => __( 'Seleccionar zonas en las que se ocultará este envío', 'mobapp-correoargentino-domicilio-envios' ),
                    'options'           => $zonas,
                    'desc_tip'          => true,
                    'custom_attributes' => array(
                        'data-placeholder' => __( 'Seleccionar zonas', 'mobapp-correoargentino-domicilio-envios' )
                    ),
                 ),
                 'costo_embalaje' => array(
                    'title'       => esc_html__('Costo de embalaje (valor fijo)', 'mobapp-correoargentino-domicilio-envios' ),
                    'type'        => 'text',
                    'description' => esc_html__('Valor fijo en la misma moneda que la tienda. Se suma a la tarifa tomada del CSV y se muestra unificada al cliente como costo de envío.', 'mobapp-correoargentino-domicilio-envios'  ),
                    'default'     => '0',
                    'desc_tip'    => true
                 ),
                 'label_embalaje' => array(
                    'title'       => esc_html__('Etiqueta para costo de embalaje (interno)', 'mobapp-correoargentino-domicilio-envios' ),
                    'type'        => 'text',
                    'description' => esc_html__('Etiqueta que se utilizará en el pedido como nombre del fee (ej: "Costo embalaje").', 'mobapp-correoargentino-domicilio-envios'  ),
                    'default'     => esc_html__('Costo embalaje', 'mobapp-correoargentino-domicilio-envios' ),
                    'desc_tip'    => true
                 )
                );
                mobapp_add_common_fields($form_fields);
                $this->form_fields = $form_fields;
            }
        }
    }
}
add_filter('woocommerce_shipping_methods','agregar_mobapp_correoargentino_domicilio_envios_method');
function agregar_mobapp_correoargentino_domicilio_envios_method( $methods ){
    $methods['mobapp-correoargentino-domicilio-envios'] = 'WC_MOBAPP_CORREOARGENTINO_DOMICILIO_ENVIOS';
    return $methods;
}

/* CORREO ARGENTINO SUCURSAL */
add_action( 'woocommerce_shipping_init', 'mobapp_correoargentino_sucursal_envios_init' );
function mobapp_correoargentino_sucursal_envios_init() {
    if ( ! class_exists( 'WC_MOBAPP_CORREOARGENTINO_SUCURSAL_ENVIOS' ) ) {
        class WC_MOBAPP_CORREOARGENTINO_SUCURSAL_ENVIOS extends WC_Shipping_Method{
            public function __construct(){
                $this->id                 = 'mobapp-correoargentino-sucursal-envios';
                $this->method_title       = __( 'CORREO ARGENTINO SUCURSAL');
                $this->method_description = __( 'Cotiza tus envíos con MOBAPP ENVÍOS' );
                $this->init();
                $this->enabled            = $this->get_option( 'enabled' );
                $this->title              = $this->get_option('title', 'CORREO ARGENTINO SUCURSAL');
            }
            public function init(){
                $this->init_form_fields();
                add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
            }
            public function calculate_shipping( $package = Array() ){
                $csv_data = mobapp_get_tarifa_csv(
                    'datos_csv_ca_suc',
                    'https://docs.google.com/spreadsheets/d/e/2PACX-1vSm0Q6LN6JIWXLXyX6OJx6YKfdL1PZ1ZXbXYJ8k6TLeKuon1VSiE5mFppvbK3v4-kX5SSOPteA7dzq6/pub?gid=1897873008&single=true&output=csv'
                );
                $provincia = isset($package['destination']['state']) ? $package['destination']['state'] : '';
                $peso_carrito = WC()->cart ? WC()->cart->get_cart_contents_weight() : 0;
                $base_cost = '0';
                $titulo = $this->get_option('title');
                if ($csv_data) {
                    $filas = explode("\n", $csv_data);
                    foreach ($filas as $fila) {
                        $columnas = str_getcsv($fila);
                        $titulo_tabla = isset($columnas[0]) ? $columnas[0] : '';
                        $codigo = isset($columnas[2]) ? $columnas[2] : '';
                        $peso_min = isset($columnas[3]) ? floatval($columnas[3]) : 0;
                        $peso_max = isset($columnas[4]) ? floatval($columnas[4]) : 0;
                        $precio = isset($columnas[5]) ? $columnas[5] : 0;
                        if ( $provincia == $codigo && $peso_carrito <= $peso_max && $peso_carrito > $peso_min){
                            $titulo = $titulo_tabla;
                            $base_cost = $precio;
                        }
                    }
                }
                $packing_cost = floatval( $this->get_option('costo_embalaje', 0) );
                $packing_label = $this->get_option('label_embalaje', 'Costo embalaje');
                $total_cost = floatval($base_cost) + $packing_cost;

                mobapp_append_featured_tooltip($titulo, $this);

                $this->add_rate( array(
                    'id'     => $this->id,
                    'label'  => $titulo,
                    'cost'   => $total_cost
                ));

                mobapp_set_packing_session( $this->id, $base_cost, $packing_cost, $packing_label, $total_cost );
            }
            public function init_form_fields() {
                $zonas = array();
                $delivery_zones = WC_Shipping_Zones::get_zones();
                foreach ((array) $delivery_zones as $key => $the_zone ) {
                    $zonas[] = $the_zone['zone_name'];
                }
                $form_fields = array(
                  'enabled' => array(
                     'title'   => esc_html__('Activar/Desactivar', 'mobapp-correoargentino-sucursal-envios' ),
                     'type'    => 'checkbox',
                     'label'   => esc_html__('Activar método de envío', 'mobapp-correoargentino-sucursal-envios'  ),
                     'default' => 'yes'
                  ),
                  'title' => array(
                     'title'       => esc_html__('Título del envío en caso de peso excedente', 'mobapp-correoargentino-sucursal-envios' ),
                     'type'        => 'text',
                     'description' => esc_html__('Ingresar título del envío', 'mobapp-correoargentino-sucursal-envios'  ),
                     'default'     => esc_html__('A COTIZAR - PESO SUPERIOR A 30KG - CORREO ARGENTINO A SUCURSAL', 'mobapp-correoargentino-sucursal-envios' ),
                     'desc_tip'    => true
                  ),
                  'ocultar_para_zonas' => array(
                    'title'             => __( 'Ocultar envío para zonas específicas', 'mobapp-correoargentino-sucursal-envios' ),
                    'type'              => 'multiselect',
                    'class'             => 'wc-enhanced-select',
                    'css'               => 'width: 400px;',
                    'default'           => '',
                    'description'       => __( 'Seleccionar zonas en las que se ocultará este envío', 'mobapp-correoargentino-sucursal-envios' ),
                    'options'           => $zonas,
                    'desc_tip'          => true,
                    'custom_attributes' => array(
                        'data-placeholder' => __( 'Seleccionar zonas', 'mobapp-correoargentino-sucursal-envios' )
                    ),
                 ),
                 'costo_embalaje' => array(
                    'title'       => esc_html__('Costo de embalaje (valor fijo)', 'mobapp-correoargentino-sucursal-envios' ),
                    'type'        => 'text',
                    'description' => esc_html__('Valor fijo en la misma moneda que la tienda. Se suma a la tarifa tomada del CSV y se muestra unificada al cliente como costo de envío.', 'mobapp-correoargentino-sucursal-envios'  ),
                    'default'     => '0',
                    'desc_tip'    => true
                 ),
                 'label_embalaje' => array(
                    'title'       => esc_html__('Etiqueta para costo de embalaje (interno)', 'mobapp-correoargentino-sucursal-envios' ),
                    'type'        => 'text',
                    'description' => esc_html__('Etiqueta que se utilizará en el pedido como nombre del fee (ej: "Costo embalaje").', 'mobapp-correoargentino-sucursal-envios'  ),
                    'default'     => esc_html__('Costo embalaje', 'mobapp-correoargentino-sucursal-envios' ),
                    'desc_tip'    => true
                 )
                );
                mobapp_add_common_fields($form_fields);
                $this->form_fields = $form_fields;
            }
        }
    }
}
add_filter('woocommerce_shipping_methods','agregar_mobapp_correoargentino_sucursal_envios_method');
function agregar_mobapp_correoargentino_sucursal_envios_method( $methods ){
    $methods['mobapp-correoargentino-sucursal-envios'] = 'WC_MOBAPP_CORREOARGENTINO_SUCURSAL_ENVIOS';
    return $methods;
}

/* OCA DOMICILIO */
add_action( 'woocommerce_shipping_init', 'mobapp_oca_domicilio_envios_init' );
function mobapp_oca_domicilio_envios_init() {
    if ( ! class_exists( 'WC_MOBAPP_OCA_DOMICILIO_ENVIOS' ) ) {
        class WC_MOBAPP_OCA_DOMICILIO_ENVIOS extends WC_Shipping_Method{
            public function __construct(){
                $this->id                 = 'mobapp-oca-domicilio-envios';
                $this->method_title       = __( 'OCA DOMICILIO');
                $this->method_description = __( 'Cotiza tus envíos con MOBAPP ENVÍOS' );
                $this->init();
                $this->enabled            = $this->get_option( 'enabled' );
                $this->title              = $this->get_option('title', 'OCA DOMICILIO');
            }
            public function init(){
                $this->init_form_fields();
                add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
            }
            public function calculate_shipping( $package = Array() ){
                $csv_data = mobapp_get_tarifa_csv(
                    'datos_csv_oca_dom',
                    'https://docs.google.com/spreadsheets/d/e/2PACX-1vT6l-Z2nmlhlTRQp5Aaki1Mwpao8XKHrSTRllymp8UiUP7dZ20hVitvqSvRl72GwDnXsGh9P31mq0vi/pub?gid=98567282&single=true&output=csv'
                );
                $provincia = isset($package['destination']['state']) ? $package['destination']['state'] : '';
                $peso_carrito = WC()->cart ? WC()->cart->get_cart_contents_weight() : 0;
                $base_cost = '0';
                $titulo = $this->get_option('title');
                if ($csv_data) {
                    $filas = explode("\n", $csv_data);
                    foreach ($filas as $fila) {
                        $columnas = str_getcsv($fila);
                        $titulo_tabla = isset($columnas[0]) ? $columnas[0] : '';
                        $codigo = isset($columnas[2]) ? $columnas[2] : '';
                        $peso_min = isset($columnas[3]) ? floatval($columnas[3]) : 0;
                        $peso_max = isset($columnas[4]) ? floatval($columnas[4]) : 0;
                        $precio = isset($columnas[5]) ? $columnas[5] : 0;
                        if ( $provincia == $codigo && $peso_carrito <= $peso_max && $peso_carrito > $peso_min){
                            $titulo = $titulo_tabla;
                            $base_cost = $precio;
                        }
                    }
                }
                $packing_cost = floatval( $this->get_option('costo_embalaje', 0) );
                $packing_label = $this->get_option('label_embalaje', 'Costo embalaje');
                $total_cost = floatval($base_cost) + $packing_cost;

                mobapp_append_featured_tooltip($titulo, $this);

                $this->add_rate( array(
                    'id'     => $this->id,
                    'label'  => $titulo,
                    'cost'   => $total_cost
                ));

                mobapp_set_packing_session( $this->id, $base_cost, $packing_cost, $packing_label, $total_cost );
            }
            public function init_form_fields() {
                $zonas = array();
                $delivery_zones = WC_Shipping_Zones::get_zones();
                foreach ((array) $delivery_zones as $key => $the_zone ) {
                    $zonas[] = $the_zone['zone_name'];
                }
                $form_fields = array(
                  'enabled' => array(
                     'title'   => esc_html__('Activar/Desactivar', 'mobapp-oca-domicilio-envios' ),
                     'type'    => 'checkbox',
                     'label'   => esc_html__('Activar método de envío', 'mobapp-oca-domicilio-envios'  ),
                     'default' => 'yes'
                  ),
                  'title' => array(
                     'title'       => esc_html__('Título del envío en caso de peso excedente', 'mobapp-oca-domicilio-envios' ),
                     'type'        => 'text',
                     'description' => esc_html__('Ingresar título del envío', 'mobapp-oca-domicilio-envios'  ),
                     'default'     => esc_html__('A COTIZAR - PESO SUPERIOR A 30KG - OCA A DOMICILIO', 'mobapp-oca-domicilio-envios' ),
                     'desc_tip'    => true
                  ),
                  'ocultar_para_zonas' => array(
                    'title'             => __( 'Ocultar envío para zonas específicas', 'mobapp-oca-domicilio-envios' ),
                    'type'              => 'multiselect',
                    'class'             => 'wc-enhanced-select',
                    'css'               => 'width: 400px;',
                    'default'           => '',
                    'description'       => __( 'Seleccionar zonas en las que se ocultará este envío', 'mobapp-oca-domicilio-envios' ),
                    'options'           => $zonas,
                    'desc_tip'          => true,
                    'custom_attributes' => array(
                        'data-placeholder' => __( 'Seleccionar zonas', 'mobapp-oca-domicilio-envios' )
                    ),
                 ),
                 'costo_embalaje' => array(
                    'title'       => esc_html__('Costo de embalaje (valor fijo)', 'mobapp-oca-domicilio-envios' ),
                    'type'        => 'text',
                    'description' => esc_html__('Valor fijo en la misma moneda que la tienda. Se suma a la tarifa tomada del CSV y se muestra unificada al cliente como costo de envío.', 'mobapp-oca-domicilio-envios'  ),
                    'default'     => '0',
                    'desc_tip'    => true
                 ),
                 'label_embalaje' => array(
                    'title'       => esc_html__('Etiqueta para costo de embalaje (interno)', 'mobapp-oca-domicilio-envios' ),
                    'type'        => 'text',
                    'description' => esc_html__('Etiqueta que se utilizará en el pedido como nombre del fee (ej: "Costo embalaje").', 'mobapp-oca-domicilio-envios'  ),
                    'default'     => esc_html__('Costo embalaje', 'mobapp-oca-domicilio-envios' ),
                    'desc_tip'    => true
                 )
                );
                mobapp_add_common_fields($form_fields);
                $this->form_fields = $form_fields;
            }
        }
    }
}
add_filter('woocommerce_shipping_methods','agregar_mobapp_oca_domicilio_envios_method');
function agregar_mobapp_oca_domicilio_envios_method( $methods ){
    $methods['mobapp-oca-domicilio-envios'] = 'WC_MOBAPP_OCA_DOMICILIO_ENVIOS';
    return $methods;
}

/* OCA SUCURSAL */
add_action( 'woocommerce_shipping_init', 'mobapp_oca_sucursal_envios_init' );
function mobapp_oca_sucursal_envios_init() {
    if ( ! class_exists( 'WC_MOBAPP_OCA_SUCURSAL_ENVIOS' ) ) {
        class WC_MOBAPP_OCA_SUCURSAL_ENVIOS extends WC_Shipping_Method{
            public function __construct(){
                $this->id                 = 'mobapp-oca-sucursal-envios';
                $this->method_title       = __( 'OCA SUCURSAL');
                $this->method_description = __( 'Cotiza tus envíos con MOBAPP ENVÍOS' );
                $this->init();
                $this->enabled            = $this->get_option( 'enabled' );
                $this->title              = $this->get_option('title', 'OCA SUCURSAL');
            }
            public function init(){
                $this->init_form_fields();
                add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
            }
            public function calculate_shipping( $package = Array() ){
                $csv_data = mobapp_get_tarifa_csv(
                    'datos_csv_oca_suc',
                    'https://docs.google.com/spreadsheets/d/e/2PACX-1vT6l-Z2nmlhlTRQp5Aaki1Mwpao8XKHrSTRllymp8UiUP7dZ20hVitvqSvRl72GwDnXsGh9P31mq0vi/pub?gid=1766360152&single=true&output=csv'
                );
                $provincia = isset($package['destination']['state']) ? $package['destination']['state'] : '';
                $peso_carrito = WC()->cart ? WC()->cart->get_cart_contents_weight() : 0;
                $base_cost = '0';
                $titulo = $this->get_option('title');
                if ($csv_data) {
                    $filas = explode("\n", $csv_data);
                    foreach ($filas as $fila) {
                        $columnas = str_getcsv($fila);
                        $titulo_tabla = isset($columnas[0]) ? $columnas[0] : '';
                        $codigo = isset($columnas[2]) ? $columnas[2] : '';
                        $peso_min = isset($columnas[3]) ? floatval($columnas[3]) : 0;
                        $peso_max = isset($columnas[4]) ? floatval($columnas[4]) : 0;
                        $precio = isset($columnas[5]) ? $columnas[5] : 0;
                        if ( $provincia == $codigo && $peso_carrito <= $peso_max && $peso_carrito > $peso_min){
                            $titulo = $titulo_tabla;
                            $base_cost = $precio;
                        }
                    }
                }
                $packing_cost = floatval( $this->get_option('costo_embalaje', 0) );
                $packing_label = $this->get_option('label_embalaje', 'Costo embalaje');
                $total_cost = floatval($base_cost) + $packing_cost;

                mobapp_append_featured_tooltip($titulo, $this);

                $this->add_rate( array(
                    'id'     => $this->id,
                    'label'  => $titulo,
                    'cost'   => $total_cost
                ));

                mobapp_set_packing_session( $this->id, $base_cost, $packing_cost, $packing_label, $total_cost );
            }
            public function init_form_fields() {
                $zonas = array();
                $delivery_zones = WC_Shipping_Zones::get_zones();
                foreach ((array) $delivery_zones as $key => $the_zone ) {
                    $zonas[] = $the_zone['zone_name'];
                }
                $form_fields = array(
                  'enabled' => array(
                     'title'   => esc_html__('Activar/Desactivar', 'mobapp-oca-sucursal-envios' ),
                     'type'    => 'checkbox',
                     'label'   => esc_html__('Activar método de envío', 'mobapp-oca-sucursal-envios'  ),
                     'default' => 'yes'
                  ),
                  'title' => array(
                     'title'       => esc_html__('Título del envío en caso de peso excedente', 'mobapp-oca-sucursal-envios' ),
                     'type'        => 'text',
                     'description' => esc_html__('Ingresar título del envío', 'mobapp-oca-sucursal-envios'  ),
                     'default'     => esc_html__('A COTIZAR - PESO SUPERIOR A 30KG - OCA A SUCURSAL', 'mobapp-oca-sucursal-envios' ),
                     'desc_tip'    => true
                  ),
                  'ocultar_para_zonas' => array(
                    'title'             => __( 'Ocultar envío para zonas específicas', 'mobapp-oca-sucursal-envios' ),
                    'type'              => 'multiselect',
                    'class'             => 'wc-enhanced-select',
                    'css'               => 'width: 400px;',
                    'default'           => '',
                    'description'       => __( 'Seleccionar zonas en las que se ocultará este envío', 'mobapp-oca-sucursal-envios' ),
                    'options'           => $zonas,
                    'desc_tip'          => true,
                    'custom_attributes' => array(
                        'data-placeholder' => __( 'Seleccionar zonas', 'mobapp-oca-sucursal-envios' )
                    ),
                 ),
                 'costo_embalaje' => array(
                    'title'       => esc_html__('Costo de embalaje (valor fijo)', 'mobapp-oca-sucursal-envios' ),
                    'type'        => 'text',
                    'description' => esc_html__('Valor fijo en la misma moneda que la tienda. Se suma a la tarifa tomada del CSV y se muestra unificada al cliente como costo de envío.', 'mobapp-oca-sucursal-envios'  ),
                    'default'     => '0',
                    'desc_tip'    => true
                 ),
                 'label_embalaje' => array(
                    'title'       => esc_html__('Etiqueta para costo de embalaje (interno)', 'mobapp-oca-sucursal-envios' ),
                    'type'        => 'text',
                    'description' => esc_html__('Etiqueta que se utilizará en el pedido como nombre del fee (ej: "Costo embalaje").', 'mobapp-oca-sucursal-envios'  ),
                    'default'     => esc_html__('Costo embalaje', 'mobapp-oca-sucursal-envios' ),
                    'desc_tip'    => true
                 )
                );
                mobapp_add_common_fields($form_fields);
                $this->form_fields = $form_fields;
            }
        }
    }
}
add_filter('woocommerce_shipping_methods','agregar_mobapp_oca_sucursal_envios_method');
function agregar_mobapp_oca_sucursal_envios_method( $methods ){
    $methods['mobapp-oca-sucursal-envios'] = 'WC_MOBAPP_OCA_SUCURSAL_ENVIOS';
    return $methods;
}

/* URBANO DOMICILIO */
add_action( 'woocommerce_shipping_init', 'mobapp_urbano_domicilio_envios_init' );
function mobapp_urbano_domicilio_envios_init() {
    if ( ! class_exists( 'WC_MOBAPP_URBANO_DOMICILIO_ENVIOS' ) ) {
        class WC_MOBAPP_URBANO_DOMICILIO_ENVIOS extends WC_Shipping_Method{
            public function __construct(){
                $this->id                 = 'mobapp-urbano-domicilio-envios';
                $this->method_title       = __( 'URBANO DOMICILIO');
                $this->method_description = __( 'Cotiza tus envíos con MOBAPP ENVÍOS' );
                $this->init();
                $this->enabled            = $this->get_option( 'enabled' );
                $this->title              = $this->get_option('title', 'URBANO DOMICILIO');
            }
            public function init(){
                $this->init_form_fields();
                add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
            }
            public function calculate_shipping( $package = Array() ){
                $csv_data = mobapp_get_tarifa_csv(
                    'datos_csv_urbano_dom',
                    'https://docs.google.com/spreadsheets/d/e/2PACX-1vT6l-Z2nmlhlTRQp5Aaki1Mwpao8XKHrSTRllymp8UiUP7dZ20hVitvqSvRl72GwDnXsGh9P31mq0vi/pub?gid=1666417641&single=true&output=csv'
                );
                $provincia = isset($package['destination']['state']) ? $package['destination']['state'] : '';
                $peso_carrito = WC()->cart ? WC()->cart->get_cart_contents_weight() : 0;
                $base_cost = '0';
                $titulo = $this->get_option('title');
                if ($csv_data) {
                    $filas = explode("\n", $csv_data);
                    foreach ($filas as $fila) {
                        $columnas = str_getcsv($fila);
                        $titulo_tabla = isset($columnas[0]) ? $columnas[0] : '';
                        $codigo = isset($columnas[2]) ? $columnas[2] : '';
                        $peso_min = isset($columnas[3]) ? floatval($columnas[3]) : 0;
                        $peso_max = isset($columnas[4]) ? floatval($columnas[4]) : 0;
                        $precio = isset($columnas[5]) ? $columnas[5] : 0;
                        if ( $provincia == $codigo && $peso_carrito <= $peso_max && $peso_carrito > $peso_min){
                            $titulo = $titulo_tabla;
                            $base_cost = $precio;
                        }
                    }
                }
                $packing_cost = floatval( $this->get_option('costo_embalaje', 0) );
                $packing_label = $this->get_option('label_embalaje', 'Costo embalaje');
                $total_cost = floatval($base_cost) + $packing_cost;

                mobapp_append_featured_tooltip($titulo, $this);

                $this->add_rate( array(
                    'id'     => $this->id,
                    'label'  => $titulo,
                    'cost'   => $total_cost
                ));

                mobapp_set_packing_session( $this->id, $base_cost, $packing_cost, $packing_label, $total_cost );
            }
            public function init_form_fields() {
                $zonas = array();
                $delivery_zones = WC_Shipping_Zones::get_zones();
                foreach ((array) $delivery_zones as $key => $the_zone ) {
                    $zonas[] = $the_zone['zone_name'];
                }
                $form_fields = array(
                  'enabled' => array(
                     'title'   => esc_html__('Activar/Desactivar', 'mobapp-urbano-domicilio-envios' ),
                     'type'    => 'checkbox',
                     'label'   => esc_html__('Activar método de envío', 'mobapp-urbano-domicilio-envios'  ),
                     'default' => 'yes'
                  ),
                  'title' => array(
                     'title'       => esc_html__('Título del envío en caso de peso excedente', 'mobapp-urbano-domicilio-envios' ),
                     'type'        => 'text',
                     'description' => esc_html__('Ingresar título del envío', 'mobapp-urbano-domicilio-envios'  ),
                     'default'     => esc_html__('A COTIZAR - PESO SUPERIOR A 30KG - URBANO A DOMICILIO', 'mobapp-urbano-domicilio-envios' ),
                     'desc_tip'    => true
                  ),
                  'ocultar_para_zonas' => array(
                    'title'             => __( 'Ocultar envío para zonas específicas', 'mobapp-urbano-domicilio-envios' ),
                    'type'              => 'multiselect',
                    'class'             => 'wc-enhanced-select',
                    'css'               => 'width: 400px;',
                    'default'           => '',
                    'description'       => __( 'Seleccionar zonas en las que se ocultará este envío', 'mobapp-urbano-domicilio-envios' ),
                    'options'           => $zonas,
                    'desc_tip'          => true,
                    'custom_attributes' => array(
                        'data-placeholder' => __( 'Seleccionar zonas', 'mobapp-urbano-domicilio-envios' )
                    ),
                 ),
                 'costo_embalaje' => array(
                    'title'       => esc_html__('Costo de embalaje (valor fijo)', 'mobapp-urbano-domicilio-envios' ),
                    'type'        => 'text',
                    'description' => esc_html__('Valor fijo en la misma moneda que la tienda. Se suma a la tarifa tomada del CSV y se muestra unificada al cliente como costo de envío.', 'mobapp-urbano-domicilio-envios'  ),
                    'default'     => '0',
                    'desc_tip'    => true
                 ),
                 'label_embalaje' => array(
                    'title'       => esc_html__('Etiqueta para costo de embalaje (interno)', 'mobapp-urbano-domicilio-envios' ),
                    'type'        => 'text',
                    'description' => esc_html__('Etiqueta que se utilizará en el pedido como nombre del fee (ej: "Costo embalaje").', 'mobapp-urbano-domicilio-envios'  ),
                    'default'     => esc_html__('Costo embalaje', 'mobapp-urbano-domicilio-envios' ),
                    'desc_tip'    => true
                 )
                );
                mobapp_add_common_fields($form_fields);
                $this->form_fields = $form_fields;
            }
        }
    }
}
add_filter('woocommerce_shipping_methods','agregar_mobapp_urbano_domicilio_envios_method');
function agregar_mobapp_urbano_domicilio_envios_method( $methods ){
    $methods['mobapp-urbano-domicilio-envios'] = 'WC_MOBAPP_URBANO_DOMICILIO_ENVIOS';
    return $methods;
}

/* MOBAPP FLASH DOMICILIO */
add_action( 'woocommerce_shipping_init', 'mobapp_flash_domicilio_envios_init' );
function mobapp_flash_domicilio_envios_init() {
    if ( ! class_exists( 'WC_MOBAPP_FLASH_DOMICILIO_ENVIOS' ) ) {
        class WC_MOBAPP_FLASH_DOMICILIO_ENVIOS extends WC_Shipping_Method{
            public function __construct(){
                $this->id                 = 'mobapp-flash-domicilio-envios';
                $this->method_title       = __( 'MOBAPP FLASH DOMICILIO');
                $this->method_description = __( 'Servicio de última milla MOBAPP en CABA y GBA (4 zonas por código postal). Cotiza tus envíos con MOBAPP ENVÍOS' );
                $this->init();
                $this->enabled            = $this->get_option( 'enabled' );
                $this->title              = $this->get_option('title', 'MOBAPP FLASH DOMICILIO');
            }
            public function init(){
                $this->init_form_fields();
                add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
            }
            public function calculate_shipping( $package = Array() ){
                // 1. Cargar CSV de CPs (mapeo CP → zona)
                $csv_cp = mobapp_get_tarifa_csv(
                    'datos_csv_flash_cp',
                    'https://docs.google.com/spreadsheets/d/e/2PACX-1vR10pelt-jk2dKh38-ar_pgGsXo2fADUjHOMkBK7jt3uV8Y-zQJSRGcaZSk3S_kL6GP33IAw6Mjd3LA/pub?gid=1928763953&single=true&output=csv'
                );
                // 2. Cargar CSV de tarifas (mapeo zona+peso → precio)
                $csv_tarifa = mobapp_get_tarifa_csv(
                    'datos_csv_flash_tarifa',
                    'https://docs.google.com/spreadsheets/d/e/2PACX-1vR10pelt-jk2dKh38-ar_pgGsXo2fADUjHOMkBK7jt3uV8Y-zQJSRGcaZSk3S_kL6GP33IAw6Mjd3LA/pub?gid=1451646784&single=true&output=csv'
                );

                // 3. Obtener CP destino y normalizar a 4 dígitos
                $cp = isset($package['destination']['postcode']) ? trim((string) $package['destination']['postcode']) : '';
                if ( empty($cp) ) return;
                $cp = preg_replace('/\D/', '', $cp);
                $cp = str_pad($cp, 4, '0', STR_PAD_LEFT);

                // 4. Obtener peso del carrito
                $peso_carrito = WC()->cart ? floatval(WC()->cart->get_cart_contents_weight()) : 0;

                // 5. Buscar la zona del CP en el CSV de CPs (saltar header, soportar BOM y CPs con decimales)
                $zona = null;
                if ($csv_cp) {
                    $csv_cp = ltrim($csv_cp, "\xEF\xBB\xBF"); // BOM UTF-8
                    $filas = explode("\n", $csv_cp);
                    $primera = true;
                    foreach ($filas as $fila) {
                        if ($primera) { $primera = false; continue; }
                        $cols = str_getcsv($fila);
                        if ( ! isset($cols[0]) ) continue;
                        $cp_csv = preg_replace('/\D/', '', (string) $cols[0]);
                        $cp_csv = str_pad($cp_csv, 4, '0', STR_PAD_LEFT);
                        if ($cp_csv === $cp) {
                            $zona = isset($cols[1]) ? trim($cols[1]) : null;
                            break;
                        }
                    }
                }

                // Si CP no está en zona MOBAPP FLASH, no ofrecer rate
                if ( empty($zona) ) return;

                // 6. Buscar la tarifa por zona+peso en el CSV de tarifas
                $base_cost = '0';
                $titulo = $this->get_option('title');
                if ($csv_tarifa) {
                    $csv_tarifa = ltrim($csv_tarifa, "\xEF\xBB\xBF");
                    $filas = explode("\n", $csv_tarifa);
                    $primera = true;
                    foreach ($filas as $fila) {
                        if ($primera) { $primera = false; continue; }
                        $cols = str_getcsv($fila);
                        if ( count($cols) < 6 ) continue;
                        $titulo_tabla = isset($cols[0]) ? $cols[0] : '';
                        $col_zona     = isset($cols[2]) ? trim($cols[2]) : '';
                        $peso_min     = isset($cols[3]) ? floatval($cols[3]) : 0;
                        $peso_max     = isset($cols[4]) ? floatval($cols[4]) : 0;
                        $precio       = isset($cols[5]) ? $cols[5] : 0;
                        if ( $col_zona === $zona && $peso_carrito <= $peso_max && $peso_carrito > $peso_min ) {
                            $titulo = $titulo_tabla;
                            $base_cost = $precio;
                            break;
                        }
                    }
                }

                // Si no se encontró tarifa, mostrar el título fallback (peso excedente) con costo 0,
                // o no ofrecer rate si base_cost sigue en '0'. Para mantener consistencia con los demás
                // métodos del plugin, mostramos siempre la opción con el título fallback.
                $packing_cost = floatval( $this->get_option('costo_embalaje', 0) );
                $packing_label = $this->get_option('label_embalaje', 'Costo embalaje');
                $total_cost = floatval($base_cost) + $packing_cost;

                mobapp_append_featured_tooltip($titulo, $this);

                $this->add_rate( array(
                    'id'     => $this->id,
                    'label'  => $titulo,
                    'cost'   => $total_cost
                ));

                mobapp_set_packing_session( $this->id, $base_cost, $packing_cost, $packing_label, $total_cost );
            }
            public function init_form_fields() {
                $zonas = array();
                $delivery_zones = WC_Shipping_Zones::get_zones();
                foreach ((array) $delivery_zones as $key => $the_zone ) {
                    $zonas[] = $the_zone['zone_name'];
                }
                $form_fields = array(
                  'enabled' => array(
                     'title'   => esc_html__('Activar/Desactivar', 'mobapp-flash-domicilio-envios' ),
                     'type'    => 'checkbox',
                     'label'   => esc_html__('Activar método de envío', 'mobapp-flash-domicilio-envios'  ),
                     'default' => 'yes'
                  ),
                  'title' => array(
                     'title'       => esc_html__('Título del envío en caso de peso excedente', 'mobapp-flash-domicilio-envios' ),
                     'type'        => 'text',
                     'description' => esc_html__('Ingresar título del envío', 'mobapp-flash-domicilio-envios'  ),
                     'default'     => esc_html__('A COTIZAR - PESO SUPERIOR A 50KG - MOBAPP FLASH DOMICILIO', 'mobapp-flash-domicilio-envios' ),
                     'desc_tip'    => true
                  ),
                  'ocultar_para_zonas' => array(
                    'title'             => __( 'Ocultar envío para zonas específicas', 'mobapp-flash-domicilio-envios' ),
                    'type'              => 'multiselect',
                    'class'             => 'wc-enhanced-select',
                    'css'               => 'width: 400px;',
                    'default'           => '',
                    'description'       => __( 'Seleccionar zonas en las que se ocultará este envío', 'mobapp-flash-domicilio-envios' ),
                    'options'           => $zonas,
                    'desc_tip'          => true,
                    'custom_attributes' => array(
                        'data-placeholder' => __( 'Seleccionar zonas', 'mobapp-flash-domicilio-envios' )
                    ),
                 ),
                 'costo_embalaje' => array(
                    'title'       => esc_html__('Costo de embalaje (valor fijo)', 'mobapp-flash-domicilio-envios' ),
                    'type'        => 'text',
                    'description' => esc_html__('Valor fijo en la misma moneda que la tienda. Se suma a la tarifa tomada del CSV y se muestra unificada al cliente como costo de envío.', 'mobapp-flash-domicilio-envios' ),
                    'default'     => '0',
                    'desc_tip'    => true
                 ),
                 'label_embalaje' => array(
                    'title'       => esc_html__('Etiqueta para costo de embalaje (interno)', 'mobapp-flash-domicilio-envios' ),
                    'type'        => 'text',
                    'description' => esc_html__('Etiqueta que se utilizará en el pedido como nombre del fee (ej: "Costo embalaje").', 'mobapp-flash-domicilio-envios'  ),
                    'default'     => esc_html__('Costo embalaje', 'mobapp-flash-domicilio-envios' ),
                    'desc_tip'    => true
                 )
                );
                mobapp_add_common_fields($form_fields);
                $this->form_fields = $form_fields;
            }
        }
    }
}
add_filter('woocommerce_shipping_methods','agregar_mobapp_flash_domicilio_envios_method');
function agregar_mobapp_flash_domicilio_envios_method( $methods ){
    $methods['mobapp-flash-domicilio-envios'] = 'WC_MOBAPP_FLASH_DOMICILIO_ENVIOS';
    return $methods;
}

/* ========== BLOQUE FORZADO DE GUARDADO DE METAS (fallbacks) ========== */
/* Helper para escribir las metas */
if ( ! function_exists( 'mobapp_write_packing_meta_to_order' ) ) {
    function mobapp_write_packing_meta_to_order( $order_id, $packing ) {
        if ( empty( $packing ) || ! is_array( $packing ) ) return false;
        $amount = isset( $packing['packing'] ) ? floatval( $packing['packing'] ) : 0;
        $label  = isset( $packing['label'] ) ? sanitize_text_field( $packing['label'] ) : 'Costo embalaje';
        $currency = '';
        if ( function_exists( 'wc_get_order' ) ) {
            $order = wc_get_order( $order_id );
            if ( $order ) $currency = $order->get_currency();
        }
        $formatted = wc_price( $amount, array( 'currency' => $currency ) );
        update_post_meta( $order_id, '_mobapp_packing_amount', $amount );
        update_post_meta( $order_id, '_mobapp_packing_label', $label );
        update_post_meta( $order_id, '_mobapp_packing_formatted', $formatted );
        update_post_meta( $order_id, '_mobapp_packing', maybe_serialize( $packing ) );
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) error_log( "mobapp: guardadas metas de embalaje en order {$order_id} => amount={$amount}, label={$label}" );
        return true;
    }
}

add_action( 'woocommerce_checkout_create_order', 'mobapp_force_save_on_create_order', 25, 2 );
function mobapp_force_save_on_create_order( $order, $data ) {
    $order_id = $order->get_id();
    $packing = null;
    if ( function_exists('mobapp_get_packing_session') ) $packing = mobapp_get_packing_session();
    if ( empty($packing) && function_exists('WC') && WC()->session ) $packing = WC()->session->get('mobapp_packing');
    if ( empty($packing) && function_exists('WC') && WC()->cart ) {
        foreach ( WC()->cart->get_fees() as $fee ) {
            $name = isset($fee->name)?$fee->name:(method_exists($fee,'get_name')?$fee->get_name():'');
            $amount_fee = isset($fee->amount)?$fee->amount:(property_exists($fee,'amount')?$fee->amount:0);
            if ($name && (stripos($name,'embalaj')!==false || stripos($name,'pack')!==false)) {
                $packing = array('method'=>'','base'=>0,'packing'=>floatval($amount_fee),'label'=>$name,'total'=>floatval($amount_fee));
                break;
            }
        }
    }
    if ( ! empty( $packing ) && is_array( $packing ) ) {
        $currency = $order->get_currency();
        $amount = isset( $packing['packing'] ) ? floatval( $packing['packing'] ) : 0;
        $packing['formatted'] = wc_price( $amount, array( 'currency' => $currency ) );
        mobapp_write_packing_meta_to_order( $order_id, $packing );
    } else {
        foreach ( $order->get_items( 'fee' ) as $fee_item ) {
            $name = method_exists($fee_item,'get_name') ? $fee_item->get_name() : '';
            if ($name && (stripos($name,'embalaj')!==false || stripos($name,'pack')!==false)) {
                $amount = floatval(method_exists($fee_item,'get_total') ? $fee_item->get_total() : 0);
                $packing = array('method'=>'','base'=>0,'packing'=>$amount,'label'=>$name,'total'=>$amount,'formatted'=>wc_price($amount,array('currency'=>$order->get_currency())));
                mobapp_write_packing_meta_to_order( $order_id, $packing );
                return;
            }
        }
    }
}

add_action( 'save_post_shop_order', 'mobapp_force_save_on_admin_save', 25, 3 );
function mobapp_force_save_on_admin_save( $post_id, $post, $update ) {
    if ( wp_is_post_revision( $post_id ) ) return;
    if ( get_post_meta( $post_id, '_mobapp_packing_formatted', true ) ) return;
    $order = wc_get_order( $post_id );
    if ( ! $order ) return;
    foreach ( $order->get_items( 'fee' ) as $fee_item ) {
        $name = method_exists($fee_item,'get_name') ? $fee_item->get_name() : '';
        if ($name && (stripos($name,'embalaj')!==false || stripos($name,'pack')!==false)) {
            $amount = floatval(method_exists($fee_item,'get_total') ? $fee_item->get_total() : 0);
            $packing = array('method'=>'','base'=>0,'packing'=>$amount,'label'=>$name,'total'=>$amount,'formatted'=>wc_price($amount,array('currency'=>$order->get_currency())));
            mobapp_write_packing_meta_to_order( $post_id, $packing );
            return;
        }
    }
}

add_action( 'woocommerce_checkout_order_processed', 'mobapp_force_save_on_processed', 20, 1 );
function mobapp_force_save_on_processed( $order_id ) {
    if ( get_post_meta( $order_id, '_mobapp_packing_formatted', true ) ) return;
    $packing = null;
    if ( function_exists('mobapp_get_packing_session') ) $packing = mobapp_get_packing_session();
    if ( empty($packing) && function_exists('WC') && WC()->session ) $packing = WC()->session->get('mobapp_packing');
    if ( ! empty($packing) && is_array($packing) ) {
        $order = wc_get_order( $order_id );
        $currency = $order ? $order->get_currency() : '';
        $amount = isset( $packing['packing'] ) ? floatval( $packing['packing'] ) : 0;
        $packing['formatted'] = wc_price( $amount, array( 'currency' => $currency ) );
        mobapp_write_packing_meta_to_order( $order_id, $packing );
    }
}

/* debug metabox */
add_action('add_meta_boxes', function(){
    add_meta_box('mobapp_packing_meta','Mobapp Packing','mobapp_packing_meta_box','shop_order','side','high');
});
function mobapp_packing_meta_box($post){
    echo '<p><strong>_mobapp_packing_formatted</strong>: '.esc_html(get_post_meta($post->ID,'_mobapp_packing_formatted',true)).'</p>';
    echo '<p><strong>_mobapp_packing_amount</strong>: '.esc_html(get_post_meta($post->ID,'_mobapp_packing_amount',true)).'</p>';
    echo '<p><strong>_mobapp_packing_label</strong>: '.esc_html(get_post_meta($post->ID,'_mobapp_packing_label',true)).'</p>';
}

// --- Mostrar "Costo embalaje" en la pantalla de edición de pedido (ADMIN) ---
// Pegar este bloque al final de main.php (o antes del cierre del archivo). Solo se ejecuta en admin.
add_action( 'woocommerce_admin_order_totals_after_shipping', 'mobapp_admin_order_packing_row' );
add_action( 'woocommerce_admin_order_totals_after_order_total', 'mobapp_admin_order_packing_row' );

function mobapp_admin_order_packing_row( $order ) {
    // El hook pasa $order (WC_Order) en versiones modernas; soportamos ambos (ID o objeto)
    if ( ! $order ) return;
    if ( is_numeric( $order ) ) {
        $order = wc_get_order( intval( $order ) );
    }
    if ( ! $order || ! ( $order instanceof WC_Order ) ) return;

    // Leemos la meta formateada (si existe)
    $formatted = $order->get_meta( '_mobapp_packing_formatted' );
    $label     = $order->get_meta( 'Descontar del envio Costo de embalaje' );
    if ( ! $label ) $label = 'Descontar del envio Costo de embalaje';

    if ( $formatted ) {
        // Imprimimos una fila similar al resto de totales del admin
        // Uso wp_kses_post para permitir que $formatted contenga markup de wc_price()
        echo '<tr>';
        echo '<td class="label">' . esc_html( $label ) . ':</td>';
        echo '<td width="1%"> </td>';
        echo '<td class="total" style="text-align:right;">' . wp_kses_post( $formatted ) . '</td>';
        echo '</tr>';
    }
}
/* FIN PLUGIN */
