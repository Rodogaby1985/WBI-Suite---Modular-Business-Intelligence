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
        if ( ! wp_verify_nonce( WBI_Admin_Query_Helper::get_string( $_GET, '_wpnonce', '' ), 'wbi_export_dynamic' ) ) wp_die( 'Nonce inválido' );

        $allowed_types = array(
            'stock_real',
            'stock_committed',
            'stock_dormant',
            'best_sellers',
            'worst_sellers',
            'clients_ranking',
            'clients_active',
            'clients_zone_detail',
            'sales_period',
            'sales_province',
            'sales_province_detail',
            'costs_margins',
            'scoring',
            'taxes_summary',
        );
        $type = WBI_Admin_Query_Helper::get_enum( $_GET, 'report_type', $allowed_types, '' );
        if ( '' === $type ) {
            wp_die( 'Tipo de reporte inválido.' );
        }
        list( $start, $end ) = WBI_Admin_Query_Helper::normalize_date_range(
            $_GET,
            'start',
            'end',
            WBI_Admin_Query_Helper::get_site_date_ymd( 'first day of this month' ),
            WBI_Admin_Query_Helper::get_site_date_ymd()
        );
        $statuses = WBI_Admin_Query_Helper::get_string_array(
            $_GET,
            'statuses',
            array( 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending', 'wc-cancelled', 'wc-failed', 'wc-refunded' )
        );
        if ( empty( $statuses ) ) {
            $statuses = null;
        }

        $output = $this->prepare_csv( 'wbi_reporte_' . $type );

        switch ( $type ) {
            // --- PRODUCTOS Y STOCK ---
            case 'stock_real':
                fputcsv($output, ['Producto', 'Tipo', 'Stock Actual']);
                $batch_size = 500;
                $offset     = 0;
                do {
                    $data = $this->engine->get_realtime_stock( $batch_size, $offset );
                    foreach ( $data as $r ) {
                        fputcsv( $output, array( $r->post_title, 'N/A', $r->stock ) );
                    }
                    $offset += count( $data );
                } while ( count( $data ) === $batch_size );
                break;

            case 'stock_committed':
                fputcsv($output, ['Producto', 'Cant. Comprometida', 'ID Pedido']);
                $batch_size = 500;
                $offset     = 0;
                do {
                    $data = $this->engine->get_committed_stock( $batch_size, $offset );
                    foreach ( $data as $r ) {
                        fputcsv( $output, array( $r->name, $r->qty, $r->order_id ) );
                    }
                    $offset += count( $data );
                } while ( count( $data ) === $batch_size );
                break;

            case 'stock_dormant':
                fputcsv($output, ['Producto', 'Stock Inmovilizado', 'Último Movimiento']);
                $batch_size = 500;
                $offset     = 0;
                do {
                    $data = $this->engine->get_dormant_stock( $batch_size, $offset );
                    foreach ( $data as $r ) {
                        fputcsv( $output, array( $r->post_title, $r->stock, $r->post_modified ) );
                    }
                    $offset += count( $data );
                } while ( count( $data ) === $batch_size );
                break;

            case 'best_sellers':
                fputcsv($output, ['Reporte', 'Productos Más Vendidos', $start . ' al ' . $end]);
                fputcsv($output, ['Producto', 'Unidades Vendidas']);
                $batch_size = 500;
                $offset     = 0;
                do {
                    $data = $this->engine->get_best_sellers( $start, $end, $statuses, $batch_size, $offset );
                    foreach ( $data as $r ) {
                        fputcsv( $output, array( $r->name, $r->qty ) );
                    }
                    $offset += count( $data );
                } while ( count( $data ) === $batch_size );
                break;

            case 'worst_sellers':
                fputcsv($output, ['Reporte', 'Productos Menos Vendidos', $start . ' al ' . $end]);
                fputcsv($output, ['Producto', 'Unidades Vendidas']);
                $batch_size = 500;
                $offset     = 0;
                do {
                    $data = $this->engine->get_least_sold( $start, $end, $statuses, $batch_size, $offset );
                    foreach ( $data as $r ) {
                        fputcsv( $output, array( $r->name, $r->qty ) );
                    }
                    $offset += count( $data );
                } while ( count( $data ) === $batch_size );
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

            // --- COSTOS Y MÁRGENES ---
            case 'costs_margins':
                fputcsv($output, ['Producto', 'SKU', 'Precio Venta', 'Costo', 'Margen %', 'Estado']);
                $alert_threshold = floatval( get_option( 'wbi_margin_alert_threshold', 20 ) );
                $category_id = WBI_Admin_Query_Helper::get_absint( $_GET, 'category_id', 0 );
                $min_margin  = WBI_Admin_Query_Helper::get_float( $_GET, 'min_margin', -999 );
                $max_margin  = WBI_Admin_Query_Helper::get_float( $_GET, 'max_margin', 999 );
                if ( $min_margin > $max_margin ) {
                    $swap       = $min_margin;
                    $min_margin = $max_margin;
                    $max_margin = $swap;
                }
                $batch_size = 500;
                $offset     = 0;
                do {
                    $data = $this->engine->get_products_with_costs( $batch_size, $offset, $category_id, $min_margin, $max_margin );
                    foreach ( $data as $row ) {
                        $price  = floatval( $row->price );
                        $cost   = floatval( $row->cost );
                        $margin = ( $cost > 0 && $price > 0 ) ? round( ( ( $price - $cost ) / $price ) * 100, 2 ) : 0;
                        if ( $margin < 0 ) {
                            $estado = 'Negativo';
                        } elseif ( $margin < $alert_threshold ) {
                            $estado = 'Bajo';
                        } else {
                            $estado = 'OK';
                        }
                        fputcsv($output, [$row->post_title, $row->sku, $row->price, $row->cost, $margin, $estado]);
                    }
                    $offset += count( $data );
                } while ( count( $data ) === $batch_size );
                break;

            // --- SCORING DE CLIENTES ---
            case 'scoring':
                fputcsv($output, ['Nombre', 'Email', 'Score', 'Clase', 'Fecha Score']);
                $score_class = strtoupper( WBI_Admin_Query_Helper::get_enum( $_GET, 'score_class', array( 'a', 'b', 'c', 'd' ), '' ) );
                if ( class_exists( 'WBI_Scoring_Module' ) && method_exists( 'WBI_Scoring_Module', 'get_scored_users_for_export_batch' ) ) {
                    $batch_size  = 500;
                    $offset      = 0;
                    do {
                        $scored_users = WBI_Scoring_Module::get_scored_users_for_export_batch( $score_class, $batch_size, $offset );
                        foreach ( $scored_users as $u ) {
                            fputcsv($output, [
                                $u->display_name,
                                $u->user_email,
                                $u->score,
                                $u->class,
                                $u->score_date ? date_i18n( 'd/m/Y', strtotime( $u->score_date ) ) : '',
                            ]);
                        }
                        $offset += count( $scored_users );
                    } while ( count( $scored_users ) === $batch_size );
                } elseif ( class_exists( 'WBI_Scoring_Module' ) ) {
                    $scored_users = WBI_Scoring_Module::get_all_scored_users_for_export( $score_class );
                    foreach ( $scored_users as $u ) {
                        fputcsv($output, [
                            $u->display_name,
                            $u->user_email,
                            $u->score,
                            $u->class,
                            $u->score_date ? date_i18n( 'd/m/Y', strtotime( $u->score_date ) ) : '',
                        ]);
                    }
                }
                break;

            // --- IMPUESTOS ---
            case 'taxes_summary':
                fputcsv($output, ['Reporte', 'Resumen de Impuestos por Provincia', $start . ' al ' . $end]);
                fputcsv($output, ['Código', 'Provincia', 'Pedidos', 'Total Facturado', 'IVA Estimado', 'Percepciones', 'IIBB']);
                $data = $this->engine->get_tax_summary($start, $end, $statuses);
                foreach ( $data as $r ) {
                    fputcsv($output, [
                        $r->province,
                        $r->province_name,
                        $r->orders,
                        number_format( $r->total, 2, '.', '' ),
                        number_format( $r->iva, 2, '.', '' ),
                        number_format( $r->percepciones, 2, '.', '' ),
                        number_format( $r->iibb, 2, '.', '' ),
                    ]);
                }
                break;
            default:
                wp_die( 'Tipo de reporte inválido.' );
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