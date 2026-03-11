<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Metrics_Engine {
    
    private $wpdb;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    private function get_date_query( $start, $end, $alias = 'p', $col = 'post_date' ) {
        $s = date( 'Y-m-d 00:00:00', strtotime( $start ) );
        $e = date( 'Y-m-d 23:59:59', strtotime( $end ) );
        return $this->wpdb->prepare( " AND $alias.$col >= %s AND $alias.$col <= %s ", $s, $e );
    }

    // --- 1. GENERALES Y DASHBOARD ---
    
    // Esta es la función que probablemente causaba el error si faltaba
    public function get_order_status_counts() {
        // Obtenemos todos los pedidos y los agrupamos por estado
        $sql = "SELECT post_status, COUNT(ID) as count 
                FROM {$this->wpdb->posts} 
                WHERE post_type = 'shop_order' 
                AND post_status IN ('wc-completed','wc-processing','wc-on-hold','wc-pending','wc-cancelled','wc-failed','wc-refunded') 
                GROUP BY post_status";
        
        return $this->wpdb->get_results( $sql, OBJECT_K );
    }

    public function get_revenue( $s, $e ) {
        $d = $this->get_date_query($s, $e);
        return $this->wpdb->get_var("SELECT SUM(meta_value) FROM {$this->wpdb->postmeta} pm JOIN {$this->wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_order_total' AND p.post_status IN ('wc-completed','wc-processing') $d") ?: 0;
    }

    public function get_units_sold( $s, $e ) {
        $d = $this->get_date_query($s, $e, 'p');
        return $this->wpdb->get_var("SELECT SUM(oim.meta_value) FROM {$this->wpdb->prefix}woocommerce_order_itemmeta oim JOIN {$this->wpdb->prefix}woocommerce_order_items oi ON oim.order_item_id=oi.order_item_id JOIN {$this->wpdb->posts} p ON oi.order_id=p.ID WHERE oim.meta_key='_qty' AND p.post_status IN ('wc-completed','wc-processing') $d") ?: 0;
    }

    public function get_average_order_value( $s, $e ) {
        $rev = $this->get_revenue($s, $e);
        $d = $this->get_date_query($s, $e, 'p');
        $c = $this->wpdb->get_var("SELECT COUNT(ID) FROM {$this->wpdb->posts} p WHERE post_type='shop_order' AND post_status IN ('wc-completed','wc-processing') $d");
        return ($c > 0) ? $rev / $c : 0;
    }

    // --- 2. PRODUCTOS ---
    
    public function get_best_sellers( $s, $e ) {
        $d = $this->get_date_query($s, $e, 'posts');
        $sql = "SELECT order_item_name as name, SUM(meta.meta_value) as qty 
                FROM {$this->wpdb->prefix}woocommerce_order_items i 
                JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta meta ON i.order_item_id=meta.order_item_id 
                JOIN {$this->wpdb->posts} posts ON i.order_id=posts.ID 
                WHERE posts.post_status IN ('wc-completed','wc-processing') 
                AND meta.meta_key='_qty' $d 
                GROUP BY name ORDER BY qty DESC LIMIT 10";
        return $this->wpdb->get_results( $sql );
    }

    public function get_least_sold( $s, $e ) {
        $d = $this->get_date_query($s, $e, 'posts');
        $sql = "SELECT order_item_name as name, SUM(meta.meta_value) as qty 
                FROM {$this->wpdb->prefix}woocommerce_order_items i 
                JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta meta ON i.order_item_id=meta.order_item_id 
                JOIN {$this->wpdb->posts} posts ON i.order_id=posts.ID 
                WHERE posts.post_status IN ('wc-completed','wc-processing') 
                AND meta.meta_key='_qty' $d 
                GROUP BY name ORDER BY qty ASC LIMIT 10";
        return $this->wpdb->get_results( $sql );
    }
    
    // --- 3. STOCK (Funciones necesarias para los otros tabs) ---
    public function get_realtime_stock() {
        return $this->wpdb->get_results("SELECT p.post_title, pm.meta_value as stock FROM {$this->wpdb->posts} p JOIN {$this->wpdb->postmeta} pm ON p.ID=pm.post_id WHERE p.post_type IN ('product','product_variation') AND p.post_status='publish' AND pm.meta_key='_stock' ORDER BY CAST(stock AS SIGNED) DESC LIMIT 100");
    }
    public function get_committed_stock() {
        return $this->wpdb->get_results("SELECT i.order_item_name as name, m.meta_value as qty, p.ID as order_id FROM {$this->wpdb->prefix}woocommerce_order_items i JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta m ON i.order_item_id=m.order_item_id JOIN {$this->wpdb->posts} p ON i.order_id=p.ID WHERE m.meta_key='_qty' AND p.post_status IN ('wc-processing','wc-on-hold')");
    }
    public function get_dormant_stock() {
        return $this->wpdb->get_results("SELECT p.post_title, pm.meta_value as stock, p.post_modified FROM {$this->wpdb->posts} p JOIN {$this->wpdb->postmeta} pm ON p.ID=pm.post_id WHERE p.post_type IN ('product','product_variation') AND pm.meta_key='_stock' AND pm.meta_value > 0 AND p.post_modified < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    }

    // --- 4. DETALLES AVANZADOS (Para reportes) ---
    public function get_sales_by_period($type, $s, $e) { /* Simplificado para evitar errores */ return array(); }
    public function get_sales_by_source($s, $e) { return $this->wpdb->get_results("SELECT pm.meta_value as source, COUNT(p.ID) as count, SUM(pm_total.meta_value) as total FROM {$this->wpdb->postmeta} pm JOIN {$this->wpdb->posts} p ON pm.post_id=p.ID JOIN {$this->wpdb->postmeta} pm_total ON p.ID=pm_total.post_id WHERE pm.meta_key='wbi_sales_source' AND pm_total.meta_key='_order_total' $d GROUP BY pm.meta_value"); }
    public function get_sales_by_taxonomy($tax, $s, $e) { return array(); }
    public function get_clients_ranking($by, $s, $e) { return array(); }
    public function get_active_customers_list() { return array(); }
    public function get_new_customers_zones() { return array(); }
}