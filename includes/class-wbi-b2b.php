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
            // Opcional: Enviar email al usuario avisando
        } elseif ( $action === 'reject' && check_admin_referer( 'wbi_reject_user' ) ) {
            update_user_meta( $user_id, 'wbi_status', 'rejected' );
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
            if ( $status !== 'approved' ) {
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
}