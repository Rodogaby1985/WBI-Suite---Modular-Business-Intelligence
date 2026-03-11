<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_B2B_Module {

    public function __construct() {
        // 1. Crear Rol Mayorista
        add_action( 'init', array( $this, 'create_role' ) );

        // 2. Lógica Frontend (Ocultar precios)
        add_filter( 'woocommerce_get_price_html', array( $this, 'hide_prices' ), 10, 2 );
        add_filter( 'woocommerce_is_purchasable', array( $this, 'restrict_purchase' ), 10, 2 );
        add_action( 'woocommerce_account_dashboard', array( $this, 'show_status_message' ) );

        // 3. ADMIN UI - Lista de Usuarios (Lo que te faltaba)
        add_filter( 'manage_users_columns', array( $this, 'add_status_column' ) );
        add_filter( 'manage_users_custom_column', array( $this, 'show_status_column_content' ), 10, 3 );
        add_filter( 'user_row_actions', array( $this, 'add_approval_actions' ), 10, 2 );
        
        // 4. Procesar la acción de aprobar/rechazar
        add_action( 'admin_init', array( $this, 'process_approval_action' ) );
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

    // --- LÓGICA DE PRECIOS (Igual que antes) ---
    private function is_authorized() {
        if ( ! is_user_logged_in() ) return false;
        $user = wp_get_current_user();
        if ( in_array( 'administrator', $user->roles ) ) return true;
        if ( in_array( 'mayorista', $user->roles ) ) {
            return get_user_meta( $user->ID, 'wbi_status', true ) === 'approved';
        }
        return true; 
    }

    public function hide_prices( $price, $product ) {
        if ( ! $this->is_authorized() ) {
            return '<span class="price-hidden" style="color:#d63638; font-weight:bold;">PRECIO MAYORISTA OCULTO</span>';
        }
        return $price;
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
}