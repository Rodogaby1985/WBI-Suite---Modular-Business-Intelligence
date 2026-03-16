<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Suppliers_Module {

    public function __construct() {
        // Register CPT
        add_action( 'init', array( $this, 'register_cpt' ) );

        // Metabox for supplier data
        add_action( 'add_meta_boxes', array( $this, 'add_metaboxes' ) );
        add_action( 'save_post_wbi_supplier', array( $this, 'save_supplier_meta' ) );

        // Product → Supplier relationship
        add_action( 'woocommerce_product_options_general_product_data', array( $this, 'add_supplier_field_to_product' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_supplier_field_on_product' ) );

        // Admin submenu
        add_action( 'admin_menu', array( $this, 'add_submenus' ), 100 );

        // Invalidate suppliers transient when a supplier is saved
        add_action( 'save_post_wbi_supplier', array( $this, 'invalidate_suppliers_cache' ) );

        // Export handler
        add_action( 'admin_post_wbi_suppliers_export', array( $this, 'handle_suppliers_export' ) );

        // Info notice on suppliers CPT list
        add_action( 'admin_notices', array( $this, 'suppliers_list_notice' ) );
    }

    // -------------------------------------------------------------------------
    // Custom Post Type
    // -------------------------------------------------------------------------

    public function register_cpt() {
        register_post_type( 'wbi_supplier', array(
            'labels'       => array(
                'name'               => 'Proveedores',
                'singular_name'      => 'Proveedor',
                'add_new'            => 'Añadir nuevo',
                'add_new_item'       => 'Añadir nuevo Proveedor',
                'edit_item'          => 'Editar Proveedor',
                'new_item'           => 'Nuevo Proveedor',
                'view_item'          => 'Ver Proveedor',
                'search_items'       => 'Buscar Proveedores',
                'not_found'          => 'No se encontraron proveedores',
                'not_found_in_trash' => 'No hay proveedores en la papelera',
            ),
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => false,
            'supports'     => array( 'title' ),
            'rewrite'      => false,
        ) );
    }

    // -------------------------------------------------------------------------
    // Metaboxes
    // -------------------------------------------------------------------------

    public function add_metaboxes() {
        add_meta_box(
            'wbi_supplier_data',
            'Datos del Proveedor',
            array( $this, 'render_supplier_metabox' ),
            'wbi_supplier',
            'normal',
            'high'
        );
    }

    public function render_supplier_metabox( $post ) {
        wp_nonce_field( 'wbi_supplier_save', 'wbi_supplier_nonce' );

        $fields = array(
            '_wbi_supplier_cuit'          => array( 'label' => 'CUIT / CUIL',         'type' => 'text'     ),
            '_wbi_supplier_contact'       => array( 'label' => 'Nombre de Contacto',  'type' => 'text'     ),
            '_wbi_supplier_email'         => array( 'label' => 'Email',               'type' => 'email'    ),
            '_wbi_supplier_phone'         => array( 'label' => 'Teléfono',            'type' => 'text'     ),
            '_wbi_supplier_payment_terms' => array( 'label' => 'Condiciones de Pago', 'type' => 'text'     ),
            '_wbi_supplier_lead_time'     => array( 'label' => 'Lead Time (días)',     'type' => 'number'   ),
            '_wbi_supplier_notes'         => array( 'label' => 'Notas',               'type' => 'textarea' ),
        );

        echo '<table class="form-table"><tbody>';
        foreach ( $fields as $key => $field ) {
            $value = get_post_meta( $post->ID, $key, true );
            echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( $field['label'] ) . '</label></th><td>';
            if ( 'textarea' === $field['type'] ) {
                echo '<textarea id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" rows="4" class="large-text">' . esc_textarea( $value ) . '</textarea>';
            } else {
                echo '<input type="' . esc_attr( $field['type'] ) . '" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="regular-text">';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        // Mini-table of assigned products
        $this->render_assigned_products( $post->ID );
    }

    private function render_assigned_products( $supplier_id ) {
        global $wpdb;

        $products = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title,
                    pm_sku.meta_value   AS sku,
                    pm_stock.meta_value AS stock,
                    pm_cost.meta_value  AS cost
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm_sku   ON p.ID = pm_sku.post_id   AND pm_sku.meta_key   = '_sku'
             LEFT JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock'
             LEFT JOIN {$wpdb->postmeta} pm_cost  ON p.ID = pm_cost.post_id  AND pm_cost.meta_key  = '_wbi_cost_price'
             JOIN {$wpdb->postmeta} pm_sup         ON p.ID = pm_sup.post_id  AND pm_sup.meta_key   = '_wbi_supplier_id'
             WHERE p.post_type   = 'product'
               AND p.post_status = 'publish'
               AND pm_sup.meta_value = %d
             ORDER BY p.post_title ASC
             LIMIT 50",
            $supplier_id
        ) );

        if ( empty( $products ) ) {
            echo '<p style="color:#888;margin-top:20px;">Sin productos asignados a este proveedor.</p>';
            return;
        }

        echo '<h3 style="margin-top:25px;">Productos asignados</h3>';
        echo '<table class="widefat striped" style="max-width:700px;">';
        echo '<thead><tr><th>Producto</th><th>SKU</th><th>Stock</th><th>Costo</th></tr></thead><tbody>';
        foreach ( $products as $p ) {
            $edit_url = get_edit_post_link( $p->ID );
            echo '<tr>';
            echo '<td><a href="' . esc_url( $edit_url ) . '">' . esc_html( $p->post_title ) . '</a></td>';
            echo '<td>' . esc_html( $p->sku ?: '—' ) . '</td>';
            echo '<td>' . esc_html( $p->stock !== null ? $p->stock : '—' ) . '</td>';
            echo '<td>' . ( $p->cost ? wc_price( $p->cost ) : '—' ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    public function save_supplier_meta( $post_id ) {
        if ( ! isset( $_POST['wbi_supplier_nonce'] ) || ! wp_verify_nonce( $_POST['wbi_supplier_nonce'], 'wbi_supplier_save' ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;

        $text_fields = array( '_wbi_supplier_cuit', '_wbi_supplier_contact', '_wbi_supplier_phone', '_wbi_supplier_payment_terms' );
        foreach ( $text_fields as $key ) {
            if ( isset( $_POST[ $key ] ) ) {
                update_post_meta( $post_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
            }
        }
        if ( isset( $_POST['_wbi_supplier_email'] ) ) {
            update_post_meta( $post_id, '_wbi_supplier_email', sanitize_email( wp_unslash( $_POST['_wbi_supplier_email'] ) ) );
        }
        if ( isset( $_POST['_wbi_supplier_lead_time'] ) ) {
            update_post_meta( $post_id, '_wbi_supplier_lead_time', absint( $_POST['_wbi_supplier_lead_time'] ) );
        }
        if ( isset( $_POST['_wbi_supplier_notes'] ) ) {
            update_post_meta( $post_id, '_wbi_supplier_notes', sanitize_textarea_field( wp_unslash( $_POST['_wbi_supplier_notes'] ) ) );
        }
    }

    // -------------------------------------------------------------------------
    // Product → Supplier relationship
    // -------------------------------------------------------------------------

    public function add_supplier_field_to_product() {
        $suppliers = $this->get_suppliers_cached();
        if ( empty( $suppliers ) ) return;

        $options = array( '' => '— Sin proveedor —' );
        foreach ( $suppliers as $s ) {
            $options[ $s->ID ] = $s->post_title;
        }

        woocommerce_wp_select( array(
            'id'      => '_wbi_supplier_id',
            'label'   => 'Proveedor',
            'options' => $options,
        ) );
    }

    public function save_supplier_field_on_product( $post_id ) {
        if ( ! current_user_can( 'edit_products' ) ) return;
        if ( isset( $_POST['_wbi_supplier_id'] ) ) {
            $supplier_id = absint( $_POST['_wbi_supplier_id'] );
            if ( $supplier_id > 0 ) {
                update_post_meta( $post_id, '_wbi_supplier_id', $supplier_id );
            } else {
                delete_post_meta( $post_id, '_wbi_supplier_id' );
            }
        }
    }

    private function get_suppliers_cached() {
        $cached = get_transient( 'wbi_suppliers_list' );
        if ( false !== $cached ) return $cached;

        $suppliers = get_posts( array(
            'post_type'      => 'wbi_supplier',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
        set_transient( 'wbi_suppliers_list', $suppliers, 10 * MINUTE_IN_SECONDS );
        return $suppliers;
    }

    public function invalidate_suppliers_cache() {
        delete_transient( 'wbi_suppliers_list' );
    }

    // -------------------------------------------------------------------------
    // Admin submenus
    // -------------------------------------------------------------------------

    public function add_submenus() {
        add_submenu_page(
            'wbi-dashboard-view',
            'Proveedores',
            '<span class="dashicons dashicons-store" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> Proveedores',
            'manage_woocommerce',
            'wbi-suppliers',
            array( $this, 'redirect_to_suppliers_list' )
        );
        add_submenu_page(
            'wbi-dashboard-view',
            'Nuevo Proveedor',
            '<span class="dashicons dashicons-plus-alt" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> Nuevo Proveedor',
            'manage_woocommerce',
            'wbi-new-supplier',
            array( $this, 'redirect_to_new_supplier' )
        );
    }

    public function redirect_to_suppliers_list() {
        wp_safe_redirect( admin_url( 'edit.php?post_type=wbi_supplier' ) );
        exit;
    }

    public function redirect_to_new_supplier() {
        wp_safe_redirect( admin_url( 'post-new.php?post_type=wbi_supplier' ) );
        exit;
    }

    public function suppliers_list_notice() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'edit-wbi_supplier' ) return;

        $export_url = add_query_arg( array(
            'action'   => 'wbi_suppliers_export',
            '_wpnonce' => wp_create_nonce( 'wbi_suppliers_export' ),
        ), admin_url( 'admin-post.php' ) );
        ?>
        <div class="notice notice-info"><p><strong>Gestión de Proveedores</strong>: Registrá tus proveedores con sus datos de contacto y condiciones comerciales. Luego podés vincular cada proveedor a tus productos desde la ficha del producto en WooCommerce. Esto te permite analizar costos por proveedor y tiempos de reabastecimiento.
        &nbsp; <a href="<?php echo esc_url( $export_url ); ?>" class="button button-small">Exportar CSV</a></p></div>
        <?php
    }

    public function handle_suppliers_export() {
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'wbi_suppliers_export' ) ) wp_die( 'Nonce inválido' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Sin permisos' );

        $suppliers = get_posts( array(
            'post_type'      => 'wbi_supplier',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="wbi-suppliers-export-' . date( 'Y-m-d' ) . '.csv"' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'name', 'cuit', 'contact', 'email', 'phone', 'payment_terms', 'lead_time' ) );
        foreach ( $suppliers as $s ) {
            fputcsv( $out, array(
                $s->post_title,
                get_post_meta( $s->ID, '_wbi_supplier_cuit', true ),
                get_post_meta( $s->ID, '_wbi_supplier_contact', true ),
                get_post_meta( $s->ID, '_wbi_supplier_email', true ),
                get_post_meta( $s->ID, '_wbi_supplier_phone', true ),
                get_post_meta( $s->ID, '_wbi_supplier_payment_terms', true ),
                get_post_meta( $s->ID, '_wbi_supplier_lead_time', true ),
            ) );
        }
        fclose( $out );
        exit;
    }
}
