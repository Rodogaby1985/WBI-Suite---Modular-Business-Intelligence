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

    /**
     * Build a safe SQL IN clause for order statuses.
     * Whitelists against known WooCommerce statuses.
     *
     * @param array|null $statuses
     * @return string  e.g. "('wc-completed','wc-processing')"
     */
    private function build_statuses_in( $statuses = null ) {
        $default = array( 'wc-completed', 'wc-processing' );
        $allowed = array( 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending', 'wc-cancelled', 'wc-failed', 'wc-refunded' );
        if ( empty( $statuses ) ) {
            $statuses = $default;
        } else {
            $statuses = array_filter( (array) $statuses, function( $s ) use ( $allowed ) {
                return in_array( $s, $allowed, true );
            } );
            if ( empty( $statuses ) ) {
                $statuses = $default;
            }
        }
        $escaped = array_map( 'esc_sql', array_values( $statuses ) );
        return "('" . implode( "','", $escaped ) . "')";
    }

    /**
     * Translate an Argentine province ISO code to its full name.
     *
     * @param string $code
     * @return string
     */
    public static function get_province_name( $code ) {
        $map = array(
            'C' => 'Ciudad Autónoma de Buenos Aires',
            'B' => 'Buenos Aires',
            'K' => 'Catamarca',
            'H' => 'Chaco',
            'U' => 'Chubut',
            'X' => 'Córdoba',
            'W' => 'Corrientes',
            'E' => 'Entre Ríos',
            'P' => 'Formosa',
            'Y' => 'Jujuy',
            'L' => 'La Pampa',
            'F' => 'La Rioja',
            'M' => 'Mendoza',
            'N' => 'Misiones',
            'Q' => 'Neuquén',
            'R' => 'Río Negro',
            'A' => 'Salta',
            'J' => 'San Juan',
            'D' => 'San Luis',
            'Z' => 'Santa Cruz',
            'S' => 'Santa Fe',
            'G' => 'Santiago del Estero',
            'V' => 'Tierra del Fuego',
            'T' => 'Tucumán',
        );
        $code = strtoupper( trim( $code ) );
        return isset( $map[ $code ] ) ? $map[ $code ] : $code;
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

    public function get_revenue( $s, $e, $statuses = null ) {
        $d = $this->get_date_query($s, $e);
        $statuses_in = $this->build_statuses_in( $statuses );
        return $this->wpdb->get_var("SELECT SUM(meta_value) FROM {$this->wpdb->postmeta} pm JOIN {$this->wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_order_total' AND p.post_status IN {$statuses_in} $d") ?: 0;
    }

    public function get_units_sold( $s, $e, $statuses = null ) {
        $d = $this->get_date_query($s, $e, 'p');
        $statuses_in = $this->build_statuses_in( $statuses );
        return $this->wpdb->get_var("SELECT SUM(oim.meta_value) FROM {$this->wpdb->prefix}woocommerce_order_itemmeta oim JOIN {$this->wpdb->prefix}woocommerce_order_items oi ON oim.order_item_id=oi.order_item_id JOIN {$this->wpdb->posts} p ON oi.order_id=p.ID WHERE oim.meta_key='_qty' AND p.post_status IN {$statuses_in} $d") ?: 0;
    }

    public function get_average_order_value( $s, $e ) {
        $rev = $this->get_revenue($s, $e);
        $d = $this->get_date_query($s, $e, 'p');
        $c = $this->wpdb->get_var("SELECT COUNT(ID) FROM {$this->wpdb->posts} p WHERE post_type='shop_order' AND post_status IN ('wc-completed','wc-processing') $d");
        return ($c > 0) ? $rev / $c : 0;
    }

    // --- 2. PRODUCTOS ---
    
    public function get_best_sellers( $s, $e, $statuses = null ) {
        $d = $this->get_date_query($s, $e, 'posts');
        $statuses_in = $this->build_statuses_in( $statuses );
        $sql = "SELECT order_item_name as name, SUM(meta.meta_value) as qty 
                FROM {$this->wpdb->prefix}woocommerce_order_items i 
                JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta meta ON i.order_item_id=meta.order_item_id 
                JOIN {$this->wpdb->posts} posts ON i.order_id=posts.ID 
                WHERE posts.post_status IN {$statuses_in} 
                AND meta.meta_key='_qty' $d 
                GROUP BY name ORDER BY qty DESC LIMIT 10";
        return $this->wpdb->get_results( $sql );
    }

    public function get_least_sold( $s, $e, $statuses = null ) {
        $d = $this->get_date_query($s, $e, 'posts');
        $statuses_in = $this->build_statuses_in( $statuses );
        $sql = "SELECT order_item_name as name, SUM(meta.meta_value) as qty 
                FROM {$this->wpdb->prefix}woocommerce_order_items i 
                JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta meta ON i.order_item_id=meta.order_item_id 
                JOIN {$this->wpdb->posts} posts ON i.order_id=posts.ID 
                WHERE posts.post_status IN {$statuses_in} 
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

    public function get_sales_by_period( $type, $s, $e, $statuses = null ) {
        $d = $this->get_date_query( $s, $e, 'p' );
        $statuses_in = $this->build_statuses_in( $statuses );
        switch ( $type ) {
            case 'week':
                $group = "DATE_FORMAT(p.post_date, '%Y-%u')";
                $label = "DATE_FORMAT(p.post_date, '%Y-Sem%u')";
                break;
            case 'month':
                $group = "DATE_FORMAT(p.post_date, '%Y-%m')";
                $label = "DATE_FORMAT(p.post_date, '%Y-%m')";
                break;
            default: // day
                $group = 'DATE(p.post_date)';
                $label = 'DATE(p.post_date)';
        }
        $sql = "SELECT {$label} as period, COUNT(p.ID) as orders, SUM(pm.meta_value) as total
                FROM {$this->wpdb->posts} p
                JOIN {$this->wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN {$statuses_in}
                {$d}
                GROUP BY {$group}
                ORDER BY {$group} ASC";
        return $this->wpdb->get_results( $sql );
    }

    public function get_sales_by_source( $s, $e, $statuses = null ) {
        $d = $this->get_date_query( $s, $e, 'p' );
        $statuses_in = $this->build_statuses_in( $statuses );
        $sql = "SELECT pm.meta_value as source, COUNT(p.ID) as count, SUM(pm_total.meta_value) as total
                FROM {$this->wpdb->postmeta} pm
                JOIN {$this->wpdb->posts} p ON pm.post_id = p.ID
                JOIN {$this->wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
                WHERE pm.meta_key = 'wbi_sales_source'
                AND p.post_type = 'shop_order'
                AND p.post_status IN {$statuses_in}
                {$d}
                GROUP BY pm.meta_value
                ORDER BY total DESC";
        return $this->wpdb->get_results( $sql );
    }

    public function get_sales_by_taxonomy( $tax, $s, $e, $statuses = null ) {
        $d   = $this->get_date_query( $s, $e, 'p' );
        $statuses_in = $this->build_statuses_in( $statuses );
        $sql = $this->wpdb->prepare(
            "SELECT t.name,
                    SUM(oim_qty.meta_value) as qty,
                    SUM(oim_total.meta_value) as total
             FROM {$this->wpdb->prefix}woocommerce_order_items oi
             JOIN {$this->wpdb->posts} p ON oi.order_id = p.ID
             JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta oim_prod
                  ON oi.order_item_id = oim_prod.order_item_id AND oim_prod.meta_key = '_product_id'
             JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta oim_qty
                  ON oi.order_item_id = oim_qty.order_item_id AND oim_qty.meta_key = '_qty'
             JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta oim_total
                  ON oi.order_item_id = oim_total.order_item_id AND oim_total.meta_key = '_line_total'
             JOIN {$this->wpdb->term_relationships} tr ON tr.object_id = oim_prod.meta_value
             JOIN {$this->wpdb->term_taxonomy} tt
                  ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = %s
             JOIN {$this->wpdb->terms} t ON tt.term_id = t.term_id
             WHERE p.post_type = 'shop_order'
             AND p.post_status IN {$statuses_in}
             {$d}
             GROUP BY t.term_id
             ORDER BY total DESC",
            $tax
        );
        return $this->wpdb->get_results( $sql );
    }

    public function get_clients_ranking( $by, $s, $e, $statuses = null ) {
        $d   = $this->get_date_query( $s, $e, 'p' );
        $statuses_in = $this->build_statuses_in( $statuses );
        $sql = "SELECT u.display_name, u.user_email,
                       SUM(pm_total.meta_value) as total_val,
                       COUNT(p.ID) as count_val
                FROM {$this->wpdb->posts} p
                JOIN {$this->wpdb->postmeta} pm_cust
                     ON p.ID = pm_cust.post_id AND pm_cust.meta_key = '_customer_user'
                JOIN {$this->wpdb->postmeta} pm_total
                     ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
                JOIN {$this->wpdb->users} u ON u.ID = pm_cust.meta_value
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN {$statuses_in}
                AND pm_cust.meta_value > 0
                {$d}
                GROUP BY u.ID
                ORDER BY total_val DESC
                LIMIT 50";
        return $this->wpdb->get_results( $sql );
    }

    public function get_active_customers_list() {
        $sql = "SELECT u.display_name, u.user_email, MAX(p.post_date) as last_buy
                FROM {$this->wpdb->posts} p
                JOIN {$this->wpdb->postmeta} pm
                     ON p.ID = pm.post_id AND pm.meta_key = '_customer_user'
                JOIN {$this->wpdb->users} u ON u.ID = pm.meta_value
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN ('wc-completed','wc-processing')
                AND pm.meta_value > 0
                AND p.post_date >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                GROUP BY u.ID
                ORDER BY last_buy DESC";
        return $this->wpdb->get_results( $sql );
    }

    public function get_new_customers_zones() {
        $sql = "SELECT um.meta_value as city, COUNT(u.ID) as count
                FROM {$this->wpdb->users} u
                JOIN {$this->wpdb->usermeta} um
                     ON u.ID = um.user_id AND um.meta_key = 'billing_city'
                WHERE u.user_registered >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                GROUP BY um.meta_value
                ORDER BY count DESC";
        return $this->wpdb->get_results( $sql );
    }

    public function get_sales_by_province( $s, $e, $statuses = null ) {
        $d   = $this->get_date_query( $s, $e, 'p' );
        $statuses_in = $this->build_statuses_in( $statuses );
        $sql = "SELECT pm_state.meta_value as province,
                       COUNT(p.ID) as orders,
                       SUM(pm_total.meta_value) as total
                FROM {$this->wpdb->posts} p
                JOIN {$this->wpdb->postmeta} pm_state
                     ON p.ID = pm_state.post_id AND pm_state.meta_key = '_billing_state'
                JOIN {$this->wpdb->postmeta} pm_total
                     ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN {$statuses_in}
                {$d}
                GROUP BY pm_state.meta_value
                ORDER BY total DESC";
        return $this->wpdb->get_results( $sql );
    }

    public function get_low_stock_products( $threshold = 5 ) {
        $threshold = intval( $threshold );
        $sql = $this->wpdb->prepare(
            "SELECT p.ID, p.post_title,
                    pm_stock.meta_value as stock,
                    pm_sku.meta_value as sku
             FROM {$this->wpdb->posts} p
             JOIN {$this->wpdb->postmeta} pm_stock
                  ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock'
             LEFT JOIN {$this->wpdb->postmeta} pm_sku
                  ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
             WHERE p.post_type IN ('product','product_variation')
             AND p.post_status = 'publish'
             AND pm_stock.meta_value > 0
             AND CAST(pm_stock.meta_value AS SIGNED) <= %d
             ORDER BY CAST(pm_stock.meta_value AS SIGNED) ASC",
            $threshold
        );
        return $this->wpdb->get_results( $sql );
    }
}