<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Metrics_Engine {

    private static $instance = null;

    private $wpdb;

    /** Cached result of is_hpos_active() to avoid repeated SHOW TABLES queries. */
    private $hpos_active = null;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;

        // Invalidate transient cache whenever an order status changes.
        add_action( 'woocommerce_order_status_changed', array( $this, 'invalidate_transients' ) );
    }

    /**
     * Singleton accessor — always returns the same instance.
     *
     * @return self
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Delete all WBI transients from the options table.
     */
    public function invalidate_transients() {
        $this->wpdb->query(
            "DELETE FROM {$this->wpdb->options}
             WHERE option_name LIKE '_transient_wbi_%'
                OR option_name LIKE '_transient_timeout_wbi_%'"
        );
    }

    /**
     * Retrieve a cached query result from a WordPress transient, or compute and
     * store it when the transient is absent.
     *
     * @param string   $cache_key Unique key (will be prefixed with "wbi_").
     * @param callable $callback  Zero-argument callable that returns the data.
     * @param int      $ttl       Cache lifetime in seconds (default 300 = 5 min).
     * @return mixed
     */
    private function cached_query( $cache_key, $callback, $ttl = 300 ) {
        $result = get_transient( $cache_key );
        if ( false !== $result ) {
            return $result;
        }
        $result = $callback();
        set_transient( $cache_key, $result, $ttl );
        return $result;
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
        return $this->cached_query( 'wbi_order_status_counts', function() {
            $sql = "SELECT post_status, COUNT(ID) as count 
                    FROM {$this->wpdb->posts} 
                    WHERE post_type = 'shop_order' 
                    AND post_status IN ('wc-completed','wc-processing','wc-on-hold','wc-pending','wc-cancelled','wc-failed','wc-refunded') 
                    GROUP BY post_status";
            return $this->wpdb->get_results( $sql, OBJECT_K );
        } );
    }

    public function get_revenue( $s, $e, $statuses = null ) {
        $key = 'wbi_revenue_' . md5( $s . $e . serialize( $statuses ) );
        return $this->cached_query( $key, function() use ( $s, $e, $statuses ) {
            $d           = $this->get_date_query( $s, $e );
            $statuses_in = $this->build_statuses_in( $statuses );
            return $this->wpdb->get_var( "SELECT SUM(meta_value) FROM {$this->wpdb->postmeta} pm JOIN {$this->wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_order_total' AND p.post_status IN {$statuses_in} $d" ) ?: 0;
        } );
    }

    public function get_units_sold( $s, $e, $statuses = null ) {
        $key = 'wbi_units_sold_' . md5( $s . $e . serialize( $statuses ) );
        return $this->cached_query( $key, function() use ( $s, $e, $statuses ) {
            $d           = $this->get_date_query( $s, $e, 'p' );
            $statuses_in = $this->build_statuses_in( $statuses );
            return $this->wpdb->get_var( "SELECT SUM(oim.meta_value) FROM {$this->wpdb->prefix}woocommerce_order_itemmeta oim JOIN {$this->wpdb->prefix}woocommerce_order_items oi ON oim.order_item_id=oi.order_item_id JOIN {$this->wpdb->posts} p ON oi.order_id=p.ID WHERE oim.meta_key='_qty' AND p.post_status IN {$statuses_in} $d" ) ?: 0;
        } );
    }

    public function get_average_order_value( $s, $e ) {
        $rev = $this->get_revenue($s, $e);
        $d = $this->get_date_query($s, $e, 'p');
        $c = $this->wpdb->get_var("SELECT COUNT(ID) FROM {$this->wpdb->posts} p WHERE post_type='shop_order' AND post_status IN ('wc-completed','wc-processing') $d");
        return ($c > 0) ? $rev / $c : 0;
    }

    // --- 2. PRODUCTOS ---
    
    public function get_best_sellers( $s, $e, $statuses = null ) {
        $key = 'wbi_best_sellers_' . md5( $s . $e . serialize( $statuses ) );
        return $this->cached_query( $key, function() use ( $s, $e, $statuses ) {
            $d           = $this->get_date_query( $s, $e, 'posts' );
            $statuses_in = $this->build_statuses_in( $statuses );
            $sql         = "SELECT order_item_name as name, SUM(meta.meta_value) as qty 
                    FROM {$this->wpdb->prefix}woocommerce_order_items i 
                    JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta meta ON i.order_item_id=meta.order_item_id 
                    JOIN {$this->wpdb->posts} posts ON i.order_id=posts.ID 
                    WHERE posts.post_status IN {$statuses_in} 
                    AND meta.meta_key='_qty' $d 
                    GROUP BY name ORDER BY qty DESC LIMIT 10";
            return $this->wpdb->get_results( $sql );
        } );
    }

    public function get_least_sold( $s, $e, $statuses = null ) {
        $key = 'wbi_least_sold_' . md5( $s . $e . serialize( $statuses ) );
        return $this->cached_query( $key, function() use ( $s, $e, $statuses ) {
            $d           = $this->get_date_query( $s, $e, 'posts' );
            $statuses_in = $this->build_statuses_in( $statuses );
            $sql         = "SELECT order_item_name as name, SUM(meta.meta_value) as qty 
                    FROM {$this->wpdb->prefix}woocommerce_order_items i 
                    JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta meta ON i.order_item_id=meta.order_item_id 
                    JOIN {$this->wpdb->posts} posts ON i.order_id=posts.ID 
                    WHERE posts.post_status IN {$statuses_in} 
                    AND meta.meta_key='_qty' $d 
                    GROUP BY name ORDER BY qty ASC LIMIT 10";
            return $this->wpdb->get_results( $sql );
        } );
    }
    
    /**
     * Check if WooCommerce HPOS (High-Performance Order Storage) is active.
     * HPOS stores orders in wp_wc_orders instead of wp_posts.
     * Result is cached in a property to avoid repeated SHOW TABLES queries.
     *
     * @return bool
     */
    private function is_hpos_active() {
        if ( null !== $this->hpos_active ) {
            return $this->hpos_active;
        }
        $table            = $this->wpdb->prefix . 'wc_orders';
        $this->hpos_active = (bool) $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        return $this->hpos_active;
    }

    // --- 3. STOCK (Funciones necesarias para los otros tabs) ---
    public function get_realtime_stock() {
        $results = $this->wpdb->get_results( "SELECT p.post_title, pm.meta_value as stock FROM {$this->wpdb->posts} p JOIN {$this->wpdb->postmeta} pm ON p.ID=pm.post_id WHERE p.post_type IN ('product','product_variation') AND p.post_status='publish' AND pm.meta_key='_stock' ORDER BY CAST(stock AS SIGNED) DESC LIMIT 100" );
        return is_array( $results ) ? $results : array();
    }

    public function get_committed_stock() {
        // Try HPOS-compatible query first (WooCommerce 7.1+ with HPOS enabled)
        if ( $this->is_hpos_active() ) {
            $sql = "SELECT i.order_item_name as name, m.meta_value as qty, o.id as order_id
                    FROM {$this->wpdb->prefix}woocommerce_order_items i
                    JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta m ON i.order_item_id = m.order_item_id
                    JOIN {$this->wpdb->prefix}wc_orders o ON i.order_id = o.id
                    WHERE m.meta_key = '_qty'
                    AND o.status IN ('wc-processing','wc-on-hold')
                    LIMIT 500";
            $results = $this->wpdb->get_results( $sql );
            return is_array( $results ) ? $results : array();
        }

        // Fallback: traditional wp_posts query
        $sql = "SELECT i.order_item_name as name, m.meta_value as qty, p.ID as order_id
                FROM {$this->wpdb->prefix}woocommerce_order_items i
                JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta m ON i.order_item_id = m.order_item_id
                JOIN {$this->wpdb->posts} p ON i.order_id = p.ID
                WHERE m.meta_key = '_qty'
                AND p.post_type = 'shop_order'
                AND p.post_status IN ('wc-processing','wc-on-hold')
                LIMIT 500";
        $results = $this->wpdb->get_results( $sql );
        return is_array( $results ) ? $results : array();
    }

    public function get_dormant_stock() {
        $results = $this->wpdb->get_results( "SELECT p.post_title, pm.meta_value as stock, p.post_modified FROM {$this->wpdb->posts} p JOIN {$this->wpdb->postmeta} pm ON p.ID=pm.post_id WHERE p.post_type IN ('product','product_variation') AND pm.meta_key='_stock' AND pm.meta_value > 0 AND p.post_modified < DATE_SUB(NOW(), INTERVAL 90 DAY) LIMIT 200" );
        return is_array( $results ) ? $results : array();
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

    public function get_customers_by_city( $city ) {
        $sql = $this->wpdb->prepare(
            "SELECT u.ID, u.display_name, u.user_email, u.user_registered,
                    um.meta_value as city
             FROM {$this->wpdb->users} u
             JOIN {$this->wpdb->usermeta} um
                  ON u.ID = um.user_id AND um.meta_key = 'billing_city'
             WHERE um.meta_value = %s
             AND u.user_registered >= DATE_SUB(NOW(), INTERVAL 60 DAY)
             ORDER BY u.user_registered DESC",
            $city
        );
        return $this->wpdb->get_results( $sql );
    }

    public function get_orders_by_province( $province_code, $start, $end, $statuses = null ) {
        $d = $this->get_date_query( $start, $end, 'p' );
        $statuses_in = $this->build_statuses_in( $statuses );
        $sql = $this->wpdb->prepare(
            "SELECT p.ID as order_id,
                    p.post_date,
                    p.post_status,
                    pm_total.meta_value as total,
                    pm_first.meta_value as first_name,
                    pm_last.meta_value as last_name,
                    pm_email.meta_value as email
             FROM {$this->wpdb->posts} p
             JOIN {$this->wpdb->postmeta} pm_state
                  ON p.ID = pm_state.post_id AND pm_state.meta_key = '_billing_state'
             JOIN {$this->wpdb->postmeta} pm_total
                  ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
             LEFT JOIN {$this->wpdb->postmeta} pm_first
                  ON p.ID = pm_first.post_id AND pm_first.meta_key = '_billing_first_name'
             LEFT JOIN {$this->wpdb->postmeta} pm_last
                  ON p.ID = pm_last.post_id AND pm_last.meta_key = '_billing_last_name'
             LEFT JOIN {$this->wpdb->postmeta} pm_email
                  ON p.ID = pm_email.post_id AND pm_email.meta_key = '_billing_email'
             WHERE p.post_type = 'shop_order'
             AND p.post_status IN {$statuses_in}
             AND pm_state.meta_value = %s
             {$d}
             ORDER BY p.post_date DESC",
            $province_code
        );
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

    // =========================================================================
    // COSTS & MARGINS METHODS
    // =========================================================================

    /**
     * Returns the average margin (%) across all published products that have
     * both a price and a cost price set. Result is cached for 10 minutes.
     *
     * @return float
     */
    public function get_avg_margin() {
        return $this->cached_query( 'wbi_avg_margin', function() {
            $rows = $this->wpdb->get_results(
                "SELECT pm_price.meta_value AS price,
                        pm_cost.meta_value  AS cost
                 FROM {$this->wpdb->posts} p
                 JOIN {$this->wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = '_price'
                 JOIN {$this->wpdb->postmeta} pm_cost  ON p.ID = pm_cost.post_id  AND pm_cost.meta_key  = '_wbi_cost_price'
                 WHERE p.post_type   IN ('product','product_variation')
                   AND p.post_status = 'publish'
                   AND pm_price.meta_value > 0
                   AND pm_cost.meta_value  > 0"
            );
            if ( empty( $rows ) ) return 0;
            $total = 0;
            $count = 0;
            foreach ( $rows as $row ) {
                $price = floatval( $row->price );
                $cost  = floatval( $row->cost );
                if ( $price > 0 && $cost > 0 ) {
                    $total += ( ( $price - $cost ) / $price ) * 100;
                    $count++;
                }
            }
            return $count > 0 ? round( $total / $count, 2 ) : 0;
        }, 10 * MINUTE_IN_SECONDS );
    }

    /**
     * Returns a paginated list of products with their cost and price data,
     * optionally filtered by category and margin range.
     *
     * @param int   $per_page
     * @param int   $offset
     * @param int   $category_id  0 = all categories
     * @param float $min_margin   Minimum margin % (-999 = no lower bound)
     * @param float $max_margin   Maximum margin % (999 = no upper bound)
     * @return array
     */
    public function get_products_with_costs( $per_page = 20, $offset = 0, $category_id = 0, $min_margin = -999, $max_margin = 999 ) {
        $per_page = max( 1, intval( $per_page ) );
        $offset   = max( 0, intval( $offset ) );

        $cat_join_sql  = '';
        $cat_where_sql = '';
        if ( $category_id > 0 ) {
            $cat_join_sql  = " JOIN {$this->wpdb->term_relationships} tr ON p.ID = tr.object_id
                              JOIN {$this->wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'product_cat'";
            $cat_where_sql = ' AND tt.term_id = ' . intval( $category_id );
        }

        $min_m = floatval( $min_margin );
        $max_m = floatval( $max_margin );

        $query = "SELECT p.ID, p.post_title,
                         pm_price.meta_value AS price,
                         pm_cost.meta_value  AS cost,
                         pm_sku.meta_value   AS sku,
                         ROUND( ((CAST(pm_price.meta_value AS DECIMAL(12,4)) - CAST(pm_cost.meta_value AS DECIMAL(12,4))) / CAST(pm_price.meta_value AS DECIMAL(12,4))) * 100, 2 ) AS margin
                  FROM {$this->wpdb->posts} p
                  JOIN {$this->wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = '_price'
                  JOIN {$this->wpdb->postmeta} pm_cost  ON p.ID = pm_cost.post_id  AND pm_cost.meta_key  = '_wbi_cost_price'
                  LEFT JOIN {$this->wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
                  {$cat_join_sql}
                  WHERE p.post_type   = 'product'
                    AND p.post_status = 'publish'
                    AND CAST(pm_price.meta_value AS DECIMAL(12,4)) > 0
                    AND CAST(pm_cost.meta_value  AS DECIMAL(12,4)) > 0
                    {$cat_where_sql}
                  HAVING margin >= {$min_m} AND margin <= {$max_m}
                  ORDER BY p.post_title ASC
                  LIMIT " . intval( $per_page ) . " OFFSET " . intval( $offset );

        return $this->wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Returns the total count of products with cost data (for pagination).
     *
     * @param int   $category_id
     * @param float $min_margin
     * @param float $max_margin
     * @return int
     */
    public function get_products_with_costs_count( $category_id = 0, $min_margin = -999, $max_margin = 999 ) {
        $cat_join_sql  = '';
        $cat_where_sql = '';
        if ( $category_id > 0 ) {
            $cat_join_sql  = " JOIN {$this->wpdb->term_relationships} tr ON p.ID = tr.object_id
                              JOIN {$this->wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'product_cat'";
            $cat_where_sql = ' AND tt.term_id = ' . intval( $category_id );
        }

        $min_m = floatval( $min_margin );
        $max_m = floatval( $max_margin );

        $query = "SELECT COUNT(*) FROM (
                    SELECT p.ID,
                           ROUND( ((CAST(pm_price.meta_value AS DECIMAL(12,4)) - CAST(pm_cost.meta_value AS DECIMAL(12,4))) / CAST(pm_price.meta_value AS DECIMAL(12,4))) * 100, 2 ) AS margin
                    FROM {$this->wpdb->posts} p
                    JOIN {$this->wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = '_price'
                    JOIN {$this->wpdb->postmeta} pm_cost  ON p.ID = pm_cost.post_id  AND pm_cost.meta_key  = '_wbi_cost_price'
                    {$cat_join_sql}
                    WHERE p.post_type   = 'product'
                      AND p.post_status = 'publish'
                      AND CAST(pm_price.meta_value AS DECIMAL(12,4)) > 0
                      AND CAST(pm_cost.meta_value  AS DECIMAL(12,4)) > 0
                      {$cat_where_sql}
                    HAVING margin >= {$min_m} AND margin <= {$max_m}
                  ) AS sub";

        return (int) $this->wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    // =========================================================================
    // TAX SUMMARY METHOD
    // =========================================================================

    /**
     * Returns per-province tax breakdown for a given date range and order statuses.
     * Uses the _billing_state meta to group orders by Argentine province.
     *
     * @param string     $start    Start date (Y-m-d).
     * @param string     $end      End date (Y-m-d).
     * @param array|null $statuses Order statuses to include.
     * @return array  Each row: province, orders, total, iva, percepciones, iibb
     */
    public function get_tax_summary( $start, $end, $statuses = null ) {
        $key = 'wbi_tax_summary_' . md5( $start . $end . serialize( $statuses ) );
        return $this->cached_query( $key, function() use ( $start, $end, $statuses ) {
            $d           = $this->get_date_query( $start, $end, 'p' );
            $statuses_in = $this->build_statuses_in( $statuses );

            $sql = "SELECT pm_state.meta_value AS province,
                           COUNT(p.ID) AS orders,
                           SUM(CAST(pm_total.meta_value AS DECIMAL(12,2))) AS total
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

            $rows       = $this->wpdb->get_results( $sql );
            $tax_config = get_option( 'wbi_tax_config', array() );
            $result     = array();

            foreach ( $rows as $row ) {
                $code        = strtoupper( trim( $row->province ) );
                $config      = isset( $tax_config[ $code ] ) ? $tax_config[ $code ] : array();
                $iva_rate    = isset( $config['iva'] )            ? floatval( $config['iva'] )            : 21.0;
                $perc_rate   = isset( $config['percepciones'] )   ? floatval( $config['percepciones'] )   : 0.0;
                $iibb_rate   = isset( $config['iibb'] )           ? floatval( $config['iibb'] )           : 0.0;
                $total       = floatval( $row->total );
                $base        = $total / ( 1 + $iva_rate / 100 );
                $iva_amount  = $total - $base;
                $result[]    = (object) array(
                    'province'      => $code,
                    'province_name' => self::get_province_name( $code ),
                    'orders'        => intval( $row->orders ),
                    'total'         => $total,
                    'iva'           => round( $iva_amount, 2 ),
                    'percepciones'  => round( $total * $perc_rate / 100, 2 ),
                    'iibb'          => round( $total * $iibb_rate / 100, 2 ),
                );
            }

            return $result;
        } );
    }
}