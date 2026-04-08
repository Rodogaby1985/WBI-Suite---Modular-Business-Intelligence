<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_B2B_Module {

    public function __construct() {
        // 1. Crear Rol Mayorista
        add_action( 'init', array( $this, 'create_role' ) );

        // 2. Lógica Frontend (Ocultar precios / aplicar precio mayorista)
        add_filter( 'woocommerce_get_price_html', array( $this, 'hide_prices' ), 10, 2 );
        add_filter( 'woocommerce_is_purchasable', array( $this, 'restrict_purchase' ), 10, 2 );
        add_filter( 'woocommerce_product_get_price', array( $this, 'apply_wholesale_price' ), 10, 2 );
        add_filter( 'woocommerce_product_get_regular_price', array( $this, 'apply_wholesale_price' ), 10, 2 );
        add_filter( 'woocommerce_product_variation_get_price', array( $this, 'apply_wholesale_price' ), 10, 2 );
        add_filter( 'woocommerce_product_variation_get_regular_price', array( $this, 'apply_wholesale_price' ), 10, 2 );
        add_action( 'woocommerce_account_dashboard', array( $this, 'show_status_message' ) );

        // 3. ADMIN UI - Lista de Usuarios
        add_filter( 'manage_users_columns', array( $this, 'add_status_column' ) );
        add_filter( 'manage_users_custom_column', array( $this, 'show_status_column_content' ), 10, 3 );
        add_filter( 'user_row_actions', array( $this, 'add_approval_actions' ), 10, 2 );

        // 4. Procesar la acción de activar/desactivar
        add_action( 'admin_init', array( $this, 'process_approval_action' ) );

        // 5. Precio Mayorista por Producto (Simple)
        add_action( 'woocommerce_product_options_pricing', array( $this, 'add_wholesale_price_field' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_wholesale_price_field' ) );

        // 6. Precio Mayorista por Variación
        add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'add_variation_wholesale_price_field' ), 10, 3 );
        add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_wholesale_price_field' ), 10, 2 );

        // 7. Monto mínimo de compra mayorista
        add_action( 'woocommerce_check_cart_items', array( $this, 'check_minimum_order' ) );
    }

    /**
     * CREAR ROL
     */
    public function create_role() {
        if ( ! get_role( 'mayorista' ) ) {
            // Copiamos capacidades del cliente normal
            $customer_role = get_role( 'customer' );
            $caps = $customer_role ? $customer_role->capabilities : array();
            
            add_role( 'mayorista', 'Cliente Mayorista', $caps );
        }
    }

    /**
     * COLUMNA EN TABLA DE USUARIOS
     */
    public function add_status_column( $columns ) {
        $columns['wbi_status'] = 'Estado B2B';
        return $columns;
    }

    public function show_status_column_content( $value, $column_name, $user_id ) {
        if ( 'wbi_status' !== $column_name ) return $value;

        $user = get_userdata( $user_id );
        if ( ! $user ) return '<span style="color:#aaa;">-</span>';

        // Skip administrators — they always have full access
        if ( in_array( 'administrator', (array) $user->roles, true ) ) {
            return '<span style="color:#aaa;">-</span>';
        }

        if ( $this->user_is_authorized( $user ) ) {
            return '<span style="color:green; font-weight:bold;">✅ Activado</span>';
        }

        return '<span style="color:orange; font-weight:bold;">⏳ Pendiente de activación</span>';
    }

    /**
     * BOTONES DE ACCIÓN (Activar / Desactivar)
     */
    public function add_approval_actions( $actions, $user_object ) {
        if ( ! current_user_can( 'edit_users' ) ) return $actions;

        // Never show buttons for administrators
        if ( in_array( 'administrator', (array) $user_object->roles, true ) ) return $actions;

        $base_url = add_query_arg( array( 'user_id' => $user_object->ID ), admin_url( 'users.php' ) );

        if ( $this->user_is_authorized( $user_object ) ) {
            // User is activated — show Deactivate button
            $deactivate_url = wp_nonce_url( add_query_arg( 'wbi_action', 'deactivate', $base_url ), 'wbi_deactivate_user' );
            $actions['wbi_deactivate'] = '<a href="' . esc_url( $deactivate_url ) . '" style="color:red;">❌ Desactivar</a>';
        } else {
            // User is not activated — show Activate button
            $activate_url = wp_nonce_url( add_query_arg( 'wbi_action', 'activate', $base_url ), 'wbi_activate_user' );
            $actions['wbi_activate'] = '<a href="' . esc_url( $activate_url ) . '" style="color:green;">✅ Activar cliente</a>';
        }

        return $actions;
    }

    /**
     * PROCESAR CLIC EN BOTONES
     */
    public function process_approval_action() {
        if ( ! isset( $_GET['wbi_action'] ) || ! isset( $_GET['user_id'] ) ) return;

        $action  = sanitize_text_field( $_GET['wbi_action'] );
        $user_id = intval( $_GET['user_id'] );

        if ( ! current_user_can( 'edit_users' ) ) return;

        if ( 'activate' === $action && check_admin_referer( 'wbi_activate_user' ) ) {
            $user = new WP_User( $user_id );
            $user->set_role( 'mayorista' );
            $this->send_activation_email( $user_id, 'activated' );
        } elseif ( 'deactivate' === $action && check_admin_referer( 'wbi_deactivate_user' ) ) {
            $user = new WP_User( $user_id );
            $user->set_role( 'customer' );
            delete_user_meta( $user_id, 'wbi_status' );
            $this->send_activation_email( $user_id, 'deactivated' );
        }

        wp_redirect( remove_query_arg( array( 'wbi_action', 'user_id', '_wpnonce' ), wp_get_referer() ) );
        exit;
    }

    // --- LÓGICA DE AUTORIZACIÓN ---

    /**
     * Retorna los roles que están autorizados para ver precios y comprar.
     * Lee la configuración del admin; siempre incluye 'administrator'.
     *
     * @return array Array de slugs de roles autorizados.
     */
    private function get_authorized_roles() {
        $opts = get_option( 'wbi_modules_settings', array() );
        if ( ! empty( $opts['wbi_b2b_authorized_roles'] ) && is_array( $opts['wbi_b2b_authorized_roles'] ) ) {
            $roles = $opts['wbi_b2b_authorized_roles'];
            if ( ! in_array( 'administrator', $roles, true ) ) {
                $roles[] = 'administrator';
            }
            return $roles;
        }
        return array( 'administrator', 'mayorista' );
    }

    /**
     * Verifica si un usuario tiene al menos uno de los roles autorizados.
     *
     * @param WP_User|null $user Usuario a verificar. Si es null usa el usuario actual.
     * @return bool True si el usuario está autorizado.
     */
    private function user_is_authorized( $user = null ) {
        if ( ! $user ) {
            if ( ! is_user_logged_in() ) return false;
            $user = wp_get_current_user();
        }
        $authorized_roles = $this->get_authorized_roles();
        return (bool) array_intersect( (array) $user->roles, $authorized_roles );
    }

    private function is_authorized() {
        if ( ! is_user_logged_in() ) return false;
        return $this->user_is_authorized();
    }

    // --- LÓGICA DE PRECIOS ---

    public function apply_wholesale_price( $price, $product ) {
        if ( ! is_user_logged_in() ) return $price;
        $user = wp_get_current_user();
        if ( ! in_array( 'mayorista', (array) $user->roles, true ) ) return $price;
        if ( ! $this->user_is_authorized( $user ) ) return $price;

        $wholesale = get_post_meta( $product->get_id(), '_wbi_wholesale_price', true );
        if ( $wholesale !== '' && $wholesale !== false && is_numeric( $wholesale ) ) {
            return $wholesale;
        }
        return $price;
    }

    public function hide_prices( $price, $product ) {
        $opts        = get_option( 'wbi_modules_settings', array() );
        $hidden_text = esc_html( ! empty( $opts['wbi_b2b_hidden_price_text'] ) ? $opts['wbi_b2b_hidden_price_text'] : 'PRECIO MAYORISTA OCULTO' );
        $hidden_span = '<span class="price-hidden" style="color:#d63638; font-weight:bold;">' . $hidden_text . '</span>';

        // Not logged in: hide prices + show register link
        if ( ! is_user_logged_in() ) {
            $register_url = ! empty( $opts['wbi_b2b_hidden_price_url'] ) ? $opts['wbi_b2b_hidden_price_url'] : get_permalink( get_option( 'woocommerce_myaccount_page_id' ) );
            return $hidden_span . '<br><a href="' . esc_url( $register_url ) . '" style="font-size:12px;">Regístrate para ver precios &rarr;</a>';
        }

        $user = wp_get_current_user();

        // User has an authorized role: show prices
        if ( $this->user_is_authorized( $user ) ) {
            // If user is mayorista, check for wholesale price override
            if ( in_array( 'mayorista', (array) $user->roles, true ) ) {
                $wholesale = get_post_meta( $product->get_id(), '_wbi_wholesale_price', true );
                if ( $wholesale !== '' && $wholesale !== false && is_numeric( $wholesale ) ) {
                    return '<span class="woocommerce-Price-amount amount">' . wc_price( $wholesale ) . '</span> <small style="color:#888;">(Precio Mayorista)</small>';
                }
            }
            return $price;
        }

        // User is logged in but NOT authorized: hide prices + show pending message
        return '<span class="price-hidden" style="color:#d63638; font-weight:bold;">⏳ Tu cuenta está pendiente de activación.</span>';
    }

    // --- CAMPO PRECIO MAYORISTA (Producto Simple) ---
    public function add_wholesale_price_field() {
        woocommerce_wp_text_input( array(
            'id'          => '_wbi_wholesale_price',
            'label'       => __( 'Precio Mayorista ($)', 'wbi-suite' ),
            'placeholder' => __( 'Dejar vacío para usar precio regular', 'wbi-suite' ),
            'desc_tip'    => true,
            'description' => __( 'Precio especial para clientes mayoristas aprobados. Si se configura, reemplaza al precio regular para esos clientes.', 'wbi-suite' ),
            'type'        => 'number',
            'custom_attributes' => array(
                'step' => 'any',
                'min'  => '0',
            ),
        ) );
    }

    public function save_wholesale_price_field( $post_id ) {
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        if ( isset( $_POST['_wbi_wholesale_price'] ) ) {
            $price = sanitize_text_field( $_POST['_wbi_wholesale_price'] );
            update_post_meta( $post_id, '_wbi_wholesale_price', $price );
        }
    }

    // --- CAMPO PRECIO MAYORISTA (Variaciones) ---
    public function add_variation_wholesale_price_field( $loop, $variation_data, $variation ) {
        woocommerce_wp_text_input( array(
            'id'            => '_wbi_wholesale_price[' . $loop . ']',
            'name'          => '_wbi_wholesale_price[' . $loop . ']',
            'value'         => get_post_meta( $variation->ID, '_wbi_wholesale_price', true ),
            'label'         => __( 'Precio Mayorista ($)', 'wbi-suite' ),
            'placeholder'   => __( 'Opcional', 'wbi-suite' ),
            'desc_tip'      => true,
            'description'   => __( 'Precio especial mayorista para esta variación.', 'wbi-suite' ),
            'type'          => 'number',
            'custom_attributes' => array(
                'step' => 'any',
                'min'  => '0',
            ),
            'wrapper_class' => 'form-row form-row-first',
        ) );
    }

    public function save_variation_wholesale_price_field( $variation_id, $i ) {
        if ( ! current_user_can( 'edit_post', $variation_id ) ) return;
        if ( isset( $_POST['_wbi_wholesale_price'][$i] ) ) {
            $price = sanitize_text_field( $_POST['_wbi_wholesale_price'][$i] );
            update_post_meta( $variation_id, '_wbi_wholesale_price', $price );
        }
    }

    public function restrict_purchase( $purchasable, $product ) {
        return $this->is_authorized() ? $purchasable : false;
    }

    public function show_status_message() {
        $user = wp_get_current_user();
        if ( $this->user_is_authorized( $user ) ) {
            if ( in_array( 'mayorista', (array) $user->roles, true ) ) {
                echo '<div class="woocommerce-message">✅ Cuenta Mayorista Activa.</div>';
            }
            return;
        }
        echo '<div class="woocommerce-info">⏳ Tu cuenta está pendiente de activación por un administrador. Te notificaremos por email cuando sea activada.</div>';
    }

    // --- MONTO MÍNIMO DE COMPRA ---
    public function check_minimum_order() {
        if ( ! is_user_logged_in() ) return;
        $user = wp_get_current_user();
        if ( ! in_array( 'mayorista', (array) $user->roles, true ) ) return;

        $opts    = get_option( 'wbi_modules_settings', array() );
        $minimum = floatval( ! empty( $opts['wbi_b2b_minimum_order'] ) ? $opts['wbi_b2b_minimum_order'] : 0 );
        if ( $minimum <= 0 ) return;

        $cart_total = floatval( WC()->cart->get_subtotal() );
        if ( $cart_total < $minimum ) {
            wc_add_notice(
                sprintf(
                    'El monto mínimo de compra mayorista es de %s. Tu carrito actual es de %s.',
                    wc_price( $minimum ),
                    wc_price( $cart_total )
                ),
                'error'
            );
        }
    }

    /**
     * Envía email al usuario cuando su cuenta es activada o desactivada.
     *
     * @param int    $user_id ID del usuario.
     * @param string $status  'activated' o 'deactivated'.
     */
    private function send_activation_email( $user_id, $status ) {
        $user      = get_userdata( $user_id );
        $site_name = get_bloginfo( 'name' );
        $shop_url  = get_permalink( wc_get_page_id( 'shop' ) );
        $user_name = $this->get_user_display_name( $user );

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        if ( 'activated' === $status ) {
            $subject = '✅ Tu cuenta ha sido activada — ' . $site_name;
            $body    = '<!DOCTYPE html><html><body style="font-family:sans-serif; color:#333;">';
            $body   .= '<h2 style="color:#2e7d32;">✅ ¡Tu cuenta fue activada!</h2>';
            $body   .= '<p>Hola <strong>' . esc_html( $user_name ) . '</strong>,</p>';
            $body   .= '<p>Tu cuenta en <strong>' . esc_html( $site_name ) . '</strong> ha sido <strong>activada</strong> por un administrador.</p>';
            $body   .= '<p>Ya podés ingresar a la tienda y ver los precios.</p>';
            $body   .= '<p style="margin-top:20px;"><a href="' . esc_url( $shop_url ) . '" style="background:#2e7d32; color:#fff; padding:10px 18px; text-decoration:none; border-radius:4px;">Ir a la tienda</a></p>';
            $body   .= '</body></html>';
        } else {
            $subject = 'ℹ️ Tu cuenta ha sido desactivada — ' . $site_name;
            $body    = '<!DOCTYPE html><html><body style="font-family:sans-serif; color:#333;">';
            $body   .= '<h2 style="color:#c62828;">ℹ️ Tu cuenta fue desactivada</h2>';
            $body   .= '<p>Hola <strong>' . esc_html( $user_name ) . '</strong>,</p>';
            $body   .= '<p>Tu acceso especial en <strong>' . esc_html( $site_name ) . '</strong> ha sido desactivado por un administrador.</p>';
            $body   .= '<p>Si tenés alguna consulta, por favor comunicate directamente con la tienda.</p>';
            $body   .= '</body></html>';
        }

        $sent = wp_mail( $user->user_email, $subject, $body, $headers );
        if ( ! $sent ) {
            error_log( sprintf( 'WBI B2B: No se pudo enviar email de %s para usuario ID %d (%s)', $status, $user_id, $user->user_email ) );
        }
    }

    /**
     * Retorna el nombre a mostrar de un usuario WP.
     *
     * @param WP_User $user Objeto usuario.
     * @return string Nombre del usuario.
     */
    private function get_user_display_name( $user ) {
        return $user->display_name ? $user->display_name : $user->user_login;
    }

    /**
     * Obtiene el email de notificación para nuevas solicitudes mayoristas.
     * Prioridad: campo específico B2B → email de "Nuevo pedido" de WC → admin email.
     *
     * @return string Email del destinatario.
     */
    private function get_notification_email() {
        $opts = get_option( 'wbi_modules_settings', array() );
        // 1. Primero: campo específico B2B
        if ( ! empty( $opts['wbi_b2b_notification_email'] ) ) {
            return sanitize_email( $opts['wbi_b2b_notification_email'] );
        }
        // 2. Segundo: email de "Nuevo pedido" de WooCommerce
        $wc_settings = get_option( 'woocommerce_new_order_settings', array() );
        if ( ! empty( $wc_settings['recipient'] ) ) {
            return $wc_settings['recipient'];
        }
        // 3. Fallback: admin email de WordPress
        return get_option( 'admin_email' );
    }
}