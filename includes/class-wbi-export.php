<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Export_Module {

    private $engine;

    public function __construct() {
        require_once plugin_dir_path( __FILE__ ) . 'class-wbi-metrics.php';
        $this->engine = new WBI_Metrics_Engine();

        // Acciones existentes
        add_action( 'admin_post_wbi_export_customers', array( $this, 'process_customer_export' ) );
        add_action( 'admin_post_wbi_export_sales_report', array( $this, 'process_sales_report' ) );
        
        // NUEVA ACCIÓN UNIVERSAL PARA REPORTES ESPECÍFICOS
        add_action( 'admin_post_wbi_export_dynamic', array( $this, 'process_dynamic_export' ) );
    }

    private function clean_output_buffer() {
        if ( ob_get_length() ) ob_end_clean();
    }

    private function prepare_csv( $filename ) {
        $this->clean_output_buffer();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename . '_' . date('Y-m-d') . '.csv' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        $output = fopen( 'php://output', 'w' );
        fputs( $output, "\xEF\xBB\xBF" ); // BOM
        return $output;
    }

    // --- PROCESADOR DINÁMICO ---
    public function process_dynamic_export() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permisos' );

        $type  = isset($_GET['report_type']) ? sanitize_text_field($_GET['report_type']) : '';
        $start = isset($_GET['start']) ? sanitize_text_field($_GET['start']) : date('Y-m-01');
        $end   = isset($_GET['end']) ? sanitize_text_field($_GET['end']) : date('Y-m-d');

        $output = $this->prepare_csv( 'wbi_reporte_' . $type );

        switch ( $type ) {
            // --- PRODUCTOS Y STOCK ---
            case 'stock_real':
                fputcsv($output, ['Producto', 'Tipo', 'Stock Actual']);
                $data = $this->engine->get_realtime_stock();
                foreach($data as $r) fputcsv($output, [$r->post_title, 'N/A', $r->stock]);
                break;

            case 'stock_committed':
                fputcsv($output, ['Producto', 'Cant. Comprometida', 'ID Pedido']);
                $data = $this->engine->get_committed_stock();
                foreach($data as $r) fputcsv($output, [$r->name, $r->qty, $r->order_id]);
                break;

            case 'stock_dormant':
                fputcsv($output, ['Producto', 'Stock Inmovilizado', 'Último Movimiento']);
                $data = $this->engine->get_dormant_stock();
                foreach($data as $r) fputcsv($output, [$r->post_title, $r->stock, $r->post_modified]);
                break;

            case 'best_sellers':
                fputcsv($output, ['Reporte', 'Productos Más Vendidos', $start . ' al ' . $end]);
                fputcsv($output, ['Producto', 'Unidades Vendidas']);
                $data = $this->engine->get_best_sellers($start, $end);
                foreach($data as $r) fputcsv($output, [$r->name, $r->qty]);
                break;

            case 'worst_sellers':
                fputcsv($output, ['Reporte', 'Productos Menos Vendidos', $start . ' al ' . $end]);
                fputcsv($output, ['Producto', 'Unidades Vendidas']);
                $data = $this->engine->get_least_sold($start, $end);
                foreach($data as $r) fputcsv($output, [$r->name, $r->qty]);
                break;

            // --- CLIENTES ---
            case 'clients_ranking':
                fputcsv($output, ['Reporte', 'Ranking Clientes', $start . ' al ' . $end]);
                fputcsv($output, ['Cliente', 'Email', 'Total Facturado', 'Cant. Pedidos']);
                $data = $this->engine->get_clients_ranking('revenue', $start, $end);
                foreach($data as $r) fputcsv($output, [$r->display_name, $r->user_email, $r->total_val, $r->count_val]);
                break;

            case 'clients_active':
                fputcsv($output, ['Cliente', 'Email', 'Última Compra (Reciente)']);
                $data = $this->engine->get_active_customers_list();
                foreach($data as $r) fputcsv($output, [$r->display_name, $r->user_email, $r->last_buy]);
                break;
             
             // --- VENTAS ---
            case 'sales_period':
                fputcsv($output, ['Fecha', 'Pedidos', 'Total']);
                $data = $this->engine->get_sales_by_period('day', $start, $end);
                foreach($data as $r) fputcsv($output, [$r->period, $r->orders, $r->total]);
                break;
        }

        fclose( $output );
        exit;
    }

    // Mantenemos las funciones antiguas por compatibilidad con el dashboard principal
    public function process_customer_export() {
        $output = $this->prepare_csv('wbi_base_clientes_completa');
        fputcsv( $output, array( 'ID', 'Nombre', 'Email', 'Rol', 'Empresa', 'CUIT', 'Estado', 'Gasto Total', 'Pedidos', 'Registro' ) );
        $users = get_users( array( 'role__in' => array( 'mayorista', 'customer', 'subscriber' ) ) );
        foreach ( $users as $user ) {
            $customer = new WC_Customer( $user->ID );
            fputcsv( $output, array( $user->ID, $user->display_name, $user->user_email, implode(', ', $user->roles), get_user_meta($user->ID,'wbi_company',true), get_user_meta($user->ID,'wbi_tax_id',true), get_user_meta($user->ID,'wbi_status',true), $customer->get_total_spent(), $customer->get_order_count(), $user->user_registered ) );
        }
        fclose( $output ); exit;
    }

    public function process_sales_report() {
        $this->process_dynamic_export(); // Redirigimos al dinámico si es necesario, o mantenemos la lógica anterior
    }
}