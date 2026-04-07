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
        
        // 4. Procesar la acción de aprobar/rechazar
        add_action( 'admin_init', array( $this, 'process_approval_action' ) );

        // 5. Precio Mayorista por Producto (Simple)
        add_action( 'woocommerce_product_options_pricing', array( $this, 'add_wholesale_price_field' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_wholesale_price_field' ) );

        // 6. Precio Mayorista por Variación
        add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'add_variation_wholesale_price_field' ), 10, 3 );
        add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_wholesale_price_field' ), 10, 2 );

        // 7. Monto mínimo de compra mayorista
        add_action( 'woocommerce_check_cart_items', array( $this, 'check_minimum_order' ) );

        // 8. Flujo de registro mayorista
        add_action( 'woocommerce_register_form', array( $this, 'add_wholesale_register_field' ) );
        add_action( 'woocommerce_created_customer', array( $this, 'handle_wholesale_registration' ) );
        add_filter( 'woocommerce_registration_redirect', array( $this, 'wholesale_registration_redirect' ) );
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
        $columns['wbi_status'] = '¿Es mayorista?';
        return $columns;
    }

    public function show_status_column_content( $value, $column_name, $user_id ) {
        if ( 'wbi_status' !== $column_name ) return $value;

        $user = get_userdata( $user_id );
        if ( ! in_array( 'mayorista', (array) $user->roles ) ) {
            return '<span style="color:#aaa;">-</span>'; // No es mayorista
        }

        $status = get_user_meta( $user_id, 'wbi_status', true );
        
        if ( $status === 'approved' ) {
            return '<span style="color:green; font-weight:bold;">✅ Aprobado</span>';
        } elseif ( $status === 'rejected' ) {
            return '<span style="color:red;">❌ Rechazado</span>';
        } else {
            return '<span style="color:orange; font-weight:bold;">⏳ Pendiente de aprobacion</span>';
        }
    }

    /**
     * BOTONES DE ACCIÓN (Aprobar / Rechazar)
     */
    public function add_approval_actions( $actions, $user_object ) {
        // Solo mostrar acciones si es mayorista
        if ( ! in_array( 'mayorista', (array) $user_object->roles ) ) return $actions;

        $current_status = get_user_meta( $user_object->ID, 'wbi_status', true );

        // URL base para ejecutar la acción
        $base_url = add_query_arg( array( 'user_id' => $user_object->ID ), admin_url( 'users.php' ) );

        if ( $current_status !== 'approved' ) {
            $approve_url = wp_nonce_url( add_query_arg( 'wbi_action', 'approve', $base_url ), 'wbi_approve_user' );
            $actions['wbi_approve'] = "<a href='{$approve_url}' style='color:green;'>Aprobar</a>";
        }

        if ( $current_status !== 'rejected' ) {
            $reject_url = wp_nonce_url( add_query_arg( 'wbi_action', 'reject', $base_url ), 'wbi_reject_user' );
            $actions['wbi_reject'] = "<a href='{$reject_url}' style='color:red;'>Rechazar</a>";
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

        // Seguridad: Verificar permisos y nonce
        if ( ! current_user_can( 'edit_users' ) ) return;
        
        if ( $action === 'approve' && check_admin_referer( 'wbi_approve_user' ) ) {
            update_user_meta( $user_id, 'wbi_status', 'approved' );
            $this->send_approval_email( $user_id, 'approved' );
        } elseif ( $action === 'reject' && check_admin_referer( 'wbi_reject_user' ) ) {
            update_user_meta( $user_id, 'wbi_status', 'rejected' );
            $this->send_approval_email( $user_id, 'rejected' );
        }

        // Redireccionar para limpiar la URL
        wp_redirect( remove_query_arg( array( 'wbi_action', 'user_id', '_wpnonce' ), wp_get_referer() ) );
        exit;
    }

    // --- LÓGICA DE PRECIOS ---
    private function is_authorized() {
        if ( ! is_user_logged_in() ) return false;
        $user = wp_get_current_user();
        if ( in_array( 'administrator', $user->roles ) ) return true;
        if ( in_array( 'mayorista', $user->roles ) ) {
            return get_user_meta( $user->ID, 'wbi_status', true ) === 'approved';
        }
        return true; 
    }

    public function apply_wholesale_price( $price, $product ) {
        if ( ! is_user_logged_in() ) return $price;
        $user = wp_get_current_user();
        if ( ! in_array( 'mayorista', $user->roles ) ) return $price;
        if ( get_user_meta( $user->ID, 'wbi_status', true ) !== 'approved' ) return $price;

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

        if ( ! is_user_logged_in() ) {
            $register_url = ! empty( $opts['wbi_b2b_hidden_price_url'] ) ? $opts['wbi_b2b_hidden_price_url'] : get_permalink( get_option( 'woocommerce_myaccount_page_id' ) );
            return $hidden_span . '<br><a href="' . esc_url( $register_url ) . '" style="font-size:12px;">Regístrate para ver precios &rarr;</a>';
        }
        $user = wp_get_current_user();
        if ( in_array( 'administrator', $user->roles ) ) return $price;

        if ( in_array( 'mayorista', $user->roles ) ) {
            if ( get_user_meta( $user->ID, 'wbi_status', true ) !== 'approved' ) {
                return $hidden_span;
            }
            // Show wholesale price if set
            $wholesale = get_post_meta( $product->get_id(), '_wbi_wholesale_price', true );
            if ( $wholesale !== '' && $wholesale !== false && is_numeric( $wholesale ) ) {
                return '<span class="woocommerce-Price-amount amount">' . wc_price( $wholesale ) . '</span> <small style="color:#888;">(Precio Mayorista)</small>';
            }
        }
        return $price;
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
        if ( in_array( 'mayorista', $user->roles ) ) {
            $status = get_user_meta( $user->ID, 'wbi_status', true );
            if ( $status === 'pending' ) {
                echo '<div class="woocommerce-info">⏳ Tu solicitud de cuenta mayorista está siendo revisada. Te notificaremos por email cuando sea aprobada.</div>';
            } elseif ( $status !== 'approved' ) {
                echo '<div class="woocommerce-error">⚠️ Tu cuenta mayorista está en revisión. No verás precios hasta ser aprobado.</div>';
            } else {
                echo '<div class="woocommerce-message">✅ Cuenta Mayorista Activa.</div>';
            }
        }
    }

    // --- MONTO MÍNIMO DE COMPRA ---
    public function check_minimum_order() {
        if ( ! is_user_logged_in() ) return;
        $user = wp_get_current_user();
        if ( ! in_array( 'mayorista', $user->roles ) ) return;
        if ( get_user_meta( $user->ID, 'wbi_status', true ) !== 'approved' ) return;

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

    // --- FLUJO DE REGISTRO MAYORISTA ---

    /**
     * Agrega un campo oculto en el formulario de registro de WooCommerce
     * cuando la URL contiene el parámetro ?wholesale=1.
     */
    public function add_wholesale_register_field() {
        if ( ! empty( $_GET['wholesale'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['wholesale'] ) ) ) {
            echo '<input type="hidden" name="wbi_wholesale_register" value="1">';
            wp_nonce_field( 'wbi_wholesale_register', 'wbi_wholesale_nonce' );
        }
    }

    /**
     * Procesa el registro mayorista: asigna rol, establece status pendiente
     * y notifica al responsable de la tienda.
     *
     * @param int $customer_id ID del usuario recién creado.
     */
    public function handle_wholesale_registration( $customer_id ) {
        if ( empty( $_POST['wbi_wholesale_register'] ) || '1' !== sanitize_text_field( wp_unslash( $_POST['wbi_wholesale_register'] ) ) ) {
            return;
        }

        if ( empty( $_POST['wbi_wholesale_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wbi_wholesale_nonce'] ) ), 'wbi_wholesale_register' ) ) {
            return;
        }

        $user = new WP_User( $customer_id );

        // Remover rol customer por defecto y asignar mayorista
        $user->remove_role( 'customer' );
        $user->add_role( 'mayorista' );

        // Establecer status pendiente
        update_user_meta( $customer_id, 'wbi_status', 'pending' );

        // Marcar que es un registro mayorista (para la redirección)
        update_user_meta( $customer_id, 'wbi_wholesale_registered', '1' );

        // Enviar email de notificación al responsable
        $this->send_new_wholesale_request_email( $customer_id );
    }

    /**
     * Redirige al dashboard de Mi Cuenta después del registro mayorista.
     *
     * @param string $url URL de redirección original.
     * @return string URL de redirección modificada.
     */
    public function wholesale_registration_redirect( $url ) {
        $user = wp_get_current_user();
        if ( $user->ID && in_array( 'mayorista', (array) $user->roles ) ) {
            $pending = get_user_meta( $user->ID, 'wbi_wholesale_registered', true );
            if ( '1' === $pending ) {
                delete_user_meta( $user->ID, 'wbi_wholesale_registered' );
                return wc_get_page_permalink( 'myaccount' );
            }
        }
        return $url;
    }

    /**
     * Envía email de notificación al responsable de la tienda cuando
     * un nuevo usuario se registra como mayorista.
     *
     * @param int $customer_id ID del usuario que se registró.
     */
    private function send_new_wholesale_request_email( $customer_id ) {
        $user       = get_userdata( $customer_id );
        $recipient  = $this->get_notification_email();
        $site_name  = get_bloginfo( 'name' );
        $user_name  = $this->get_user_display_name( $user );
        $user_email = $user->user_email;
        $date       = wp_date( 'd/m/Y H:i' );
        $users_url  = admin_url( 'users.php?role=mayorista' );

        $subject = sprintf( '🏢 Nueva solicitud de cuenta mayorista — %s', $user_name );

        $body = '<!DOCTYPE html><html><body style="font-family:sans-serif; color:#333;">';
        $body .= '<h2 style="color:#0071a1;">🏢 Nueva solicitud de cuenta mayorista</h2>';
        $body .= '<p>Se ha registrado un nuevo usuario solicitando una cuenta mayorista en <strong>' . esc_html( $site_name ) . '</strong>.</p>';
        $body .= '<table style="border-collapse:collapse; width:100%; max-width:500px;">';
        $body .= '<tr><td style="padding:6px; font-weight:bold;">Nombre:</td><td style="padding:6px;">' . esc_html( $user_name ) . '</td></tr>';
        $body .= '<tr style="background:#f9f9f9;"><td style="padding:6px; font-weight:bold;">Email:</td><td style="padding:6px;">' . esc_html( $user_email ) . '</td></tr>';
        $body .= '<tr><td style="padding:6px; font-weight:bold;">Fecha de registro:</td><td style="padding:6px;">' . esc_html( $date ) . '</td></tr>';
        $body .= '</table>';
        $body .= '<p style="margin-top:20px;"><a href="' . esc_url( $users_url ) . '" style="background:#0071a1; color:#fff; padding:10px 18px; text-decoration:none; border-radius:4px;">Ver solicitudes pendientes</a></p>';
        $body .= '</body></html>';

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        $sent = wp_mail( $recipient, $subject, $body, $headers );
        if ( ! $sent ) {
            error_log( sprintf( 'WBI B2B: No se pudo enviar email de nueva solicitud mayorista para usuario ID %d al destinatario %s', $customer_id, $recipient ) );
        }
    }

    /**
     * Envía email al usuario cuando su solicitud es aprobada o rechazada.
     *
     * @param int    $user_id ID del usuario.
     * @param string $status  'approved' o 'rejected'.
     */
    private function send_approval_email( $user_id, $status ) {
        $user      = get_userdata( $user_id );
        $site_name = get_bloginfo( 'name' );
        $shop_url  = get_permalink( wc_get_page_id( 'shop' ) );
        $user_name = $this->get_user_display_name( $user );

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        if ( 'approved' === $status ) {
            $subject = '✅ Tu cuenta mayorista ha sido aprobada';
            $body    = '<!DOCTYPE html><html><body style="font-family:sans-serif; color:#333;">';
            $body   .= '<h2 style="color:#2e7d32;">✅ ¡Tu cuenta mayorista fue aprobada!</h2>';
            $body   .= '<p>Hola <strong>' . esc_html( $user_name ) . '</strong>,</p>';
            $body   .= '<p>Tu solicitud de cuenta mayorista en <strong>' . esc_html( $site_name ) . '</strong> ha sido <strong>aprobada</strong>.</p>';
            $body   .= '<p>Ya podés ingresar a la tienda y ver los precios mayoristas exclusivos para vos.</p>';
            $body   .= '<p style="margin-top:20px;"><a href="' . esc_url( $shop_url ) . '" style="background:#2e7d32; color:#fff; padding:10px 18px; text-decoration:none; border-radius:4px;">Ir a la tienda</a></p>';
            $body   .= '</body></html>';
        } else {
            $subject = '❌ Solicitud de cuenta mayorista';
            $body    = '<!DOCTYPE html><html><body style="font-family:sans-serif; color:#333;">';
            $body   .= '<h2 style="color:#c62828;">❌ Solicitud de cuenta mayorista</h2>';
            $body   .= '<p>Hola <strong>' . esc_html( $user_name ) . '</strong>,</p>';
            $body   .= '<p>Lamentablemente, tu solicitud de cuenta mayorista en <strong>' . esc_html( $site_name ) . '</strong> no pudo ser procesada en este momento.</p>';
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