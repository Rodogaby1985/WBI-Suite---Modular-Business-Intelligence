<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Export_Module {

    private $engine;

    public function __construct() {
        $this->engine = WBI_Metrics_Engine::instance();

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

        $type     = isset($_GET['report_type']) ? sanitize_text_field($_GET['report_type']) : '';
        $start    = isset($_GET['start']) ? sanitize_text_field($_GET['start']) : date('Y-m-01');
        $end      = isset($_GET['end']) ? sanitize_text_field($_GET['end']) : date('Y-m-d');
        $statuses = isset($_GET['statuses']) ? array_map('sanitize_text_field', (array)$_GET['statuses']) : null;

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
                $data = $this->engine->get_best_sellers($start, $end, $statuses);
                foreach($data as $r) fputcsv($output, [$r->name, $r->qty]);
                break;

            case 'worst_sellers':
                fputcsv($output, ['Reporte', 'Productos Menos Vendidos', $start . ' al ' . $end]);
                fputcsv($output, ['Producto', 'Unidades Vendidas']);
                $data = $this->engine->get_least_sold($start, $end, $statuses);
                foreach($data as $r) fputcsv($output, [$r->name, $r->qty]);
                break;

            // --- CLIENTES ---
            case 'clients_ranking':
                fputcsv($output, ['Reporte', 'Ranking Clientes', $start . ' al ' . $end]);
                fputcsv($output, ['Cliente', 'Email', 'Total Facturado', 'Cant. Pedidos']);
                $data = $this->engine->get_clients_ranking('revenue', $start, $end, $statuses);
                foreach($data as $r) fputcsv($output, [$r->display_name, $r->user_email, $r->total_val, $r->count_val]);
                break;

            case 'clients_active':
                fputcsv($output, ['Cliente', 'Email', 'Última Compra (Reciente)']);
                $data = $this->engine->get_active_customers_list();
                foreach($data as $r) fputcsv($output, [$r->display_name, $r->user_email, $r->last_buy]);
                break;

            case 'clients_zone_detail':
                $city = isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '';
                fputcsv($output, ['Nombre', 'Email', 'Fecha de Registro', 'Ciudad']);
                if ( $city ) {
                    $data = $this->engine->get_customers_by_city( $city );
                    if ( is_array($data) ) {
                        foreach($data as $u) {
                            fputcsv($output, [
                                $u->display_name,
                                $u->user_email,
                                date('d/m/Y', strtotime($u->user_registered)),
                                $u->city
                            ]);
                        }
                    }
                }
                break;
             
             // --- VENTAS ---
            case 'sales_period':
                fputcsv($output, ['Fecha', 'Pedidos', 'Total']);
                $data = $this->engine->get_sales_by_period('day', $start, $end, $statuses);
                foreach($data as $r) fputcsv($output, [$r->period, $r->orders, $r->total]);
                break;

            case 'sales_province':
                fputcsv($output, ['Reporte', 'Ventas por Provincia', $start . ' al ' . $end]);
                fputcsv($output, ['Provincia', 'Cant. Pedidos', 'Total']);
                $data = $this->engine->get_sales_by_province($start, $end, $statuses);
                foreach($data as $r) {
                    $prov_name = WBI_Metrics_Engine::get_province_name( $r->province ?: '' ) ?: 'Desconocida';
                    fputcsv($output, [$prov_name, $r->orders, $r->total]);
                }
                break;

            case 'sales_province_detail':
                $province = isset($_GET['province']) ? sanitize_text_field($_GET['province']) : '';
                fputcsv($output, ['#Pedido', 'Fecha', 'Cliente', 'Email', 'Total', 'Estado']);
                if ( $province ) {
                    $data = $this->engine->get_orders_by_province( $province, $start, $end, $statuses );
                    if ( is_array($data) ) {
                        foreach($data as $o) {
                            fputcsv($output, [
                                $o->order_id,
                                date('d/m/Y', strtotime($o->post_date)),
                                trim($o->first_name . ' ' . $o->last_name),
                                $o->email,
                                $o->total,
                                $o->post_status
                            ]);
                        }
                    }
                }
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