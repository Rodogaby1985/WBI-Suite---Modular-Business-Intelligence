<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI POS Cash Movements
 *
 * Manages the custom DB table {prefix}wbi_pos_cash_movements.
 * Each row represents one financial movement inside a cash session.
 *
 * Types:
 *   sale_income     — automated: sale from POS order
 *   manual_income   — manual inflow registered by cashier
 *   manual_expense  — manual outflow registered by cashier
 *   withdrawal      — partial cash withdrawal during shift
 *   deposit         — cash deposit added during shift
 *
 * Methods:
 *   cash | card | transfer | other
 *
 * Table schema:
 *   id           BIGINT UNSIGNED AUTO_INCREMENT PK
 *   session_id   BIGINT UNSIGNED NOT NULL
 *   type         VARCHAR(20) NOT NULL
 *   method       VARCHAR(20) NOT NULL DEFAULT 'cash'
 *   amount       DECIMAL(12,2) NOT NULL DEFAULT 0
 *   reference    VARCHAR(255) NULL
 *   notes        TEXT NULL
 *   created_by   BIGINT UNSIGNED NOT NULL
 *   created_at   DATETIME NOT NULL
 */
class WBI_POS_Cash_Movements {

    const TABLE_VERSION_OPTION = 'wbi_pos_cash_movements_db_version';
    const TABLE_VERSION        = '1.0';

    // Movement types
    const TYPE_SALE_INCOME    = 'sale_income';
    const TYPE_MANUAL_INCOME  = 'manual_income';
    const TYPE_MANUAL_EXPENSE = 'manual_expense';
    const TYPE_WITHDRAWAL     = 'withdrawal';
    const TYPE_DEPOSIT        = 'deposit';

    // Inflow types (positive impact on cash balance)
    const INCOME_TYPES = array(
        self::TYPE_SALE_INCOME,
        self::TYPE_MANUAL_INCOME,
        self::TYPE_DEPOSIT,
    );

    // Outflow types (negative impact on cash balance)
    const EXPENSE_TYPES = array(
        self::TYPE_MANUAL_EXPENSE,
        self::TYPE_WITHDRAWAL,
    );

    // =========================================================================
    // DB TABLE MANAGEMENT
    // =========================================================================

    /**
     * Create or upgrade the cash movements table using dbDelta.
     * Safe to call multiple times.
     */
    public static function create_table() {
        global $wpdb;

        $table           = $wpdb->prefix . 'wbi_pos_cash_movements';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL,
            type varchar(20) NOT NULL,
            method varchar(20) NOT NULL DEFAULT 'cash',
            amount decimal(12,2) NOT NULL DEFAULT '0.00',
            reference varchar(255) DEFAULT NULL,
            notes text DEFAULT NULL,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY session_id (session_id),
            KEY type (type),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( self::TABLE_VERSION_OPTION, self::TABLE_VERSION );
    }

    /**
     * Ensure the table exists (cheap version check).
     */
    public static function maybe_create_table() {
        if ( get_option( self::TABLE_VERSION_OPTION ) !== self::TABLE_VERSION ) {
            self::create_table();
        }
    }

    // =========================================================================
    // WRITE OPERATIONS
    // =========================================================================

    /**
     * Insert a new movement.
     *
     * @param int    $session_id
     * @param string $type       One of the TYPE_* constants.
     * @param string $method     cash|card|transfer|other
     * @param float  $amount     Absolute value (always positive).
     * @param string $reference  Optional order ID or external reference.
     * @param string $notes      Optional free-text note.
     * @param int    $created_by WordPress user ID of the creator.
     * @return int|false  New movement ID or false on error.
     */
    public static function add_movement( $session_id, $type, $method = 'cash', $amount = 0.0, $reference = '', $notes = '', $created_by = 0 ) {
        global $wpdb;

        $allowed_types   = array(
            self::TYPE_SALE_INCOME,
            self::TYPE_MANUAL_INCOME,
            self::TYPE_MANUAL_EXPENSE,
            self::TYPE_WITHDRAWAL,
            self::TYPE_DEPOSIT,
        );
        $allowed_methods = array( 'cash', 'card', 'transfer', 'other', 'debit', 'credit', 'qr' );

        if ( ! in_array( $type, $allowed_types, true ) ) {
            return false;
        }

        $method = in_array( $method, $allowed_methods, true ) ? $method : 'other';

        $table = $wpdb->prefix . 'wbi_pos_cash_movements';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert(
            $table,
            array(
                'session_id' => absint( $session_id ),
                'type'       => $type,
                'method'     => $method,
                'amount'     => round( abs( (float) $amount ), 2 ),
                'reference'  => sanitize_text_field( $reference ),
                'notes'      => sanitize_textarea_field( $notes ),
                'created_by' => absint( $created_by ) ?: get_current_user_id(),
                'created_at' => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%f', '%s', '%s', '%d', '%s' )
        );
        // phpcs:enable

        return $result ? (int) $wpdb->insert_id : false;
    }

    // =========================================================================
    // GETTERS
    // =========================================================================

    /**
     * Get all movements for a session.
     *
     * @param int $session_id
     * @return array
     */
    public static function get_movements( $session_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wbi_pos_cash_movements';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE session_id = %d ORDER BY created_at ASC",
                absint( $session_id )
            )
        );
        // phpcs:enable
    }

    /**
     * Aggregate movements for a session: total by type and by method.
     *
     * Returns:
     *   'by_method'       => [ 'cash' => 1500.00, 'card' => 300.00, ... ]   (only incomes)
     *   'income_cash'     => float  (all cash inflows: sale_income + manual_income + deposit)
     *   'expense_cash'    => float  (all cash outflows: manual_expense + withdrawal)
     *   'total_income'    => float  (sum of all income amounts, all methods)
     *   'total_expense'   => float  (sum of all expense amounts, all methods)
     *   'expected_cash'   => float  (opening_cash + income_cash - expense_cash)
     *
     * @param int   $session_id
     * @param float $opening_cash  The session's opening_cash value.
     * @return array
     */
    public static function get_session_totals( $session_id, $opening_cash = 0.0 ) {
        $movements = self::get_movements( $session_id );

        $by_method    = array();
        $income_cash  = 0.0;
        $expense_cash = 0.0;
        $total_income = 0.0;
        $total_expense = 0.0;

        foreach ( $movements as $m ) {
            $amount = (float) $m->amount;
            $type   = $m->type;
            $method = $m->method;

            if ( in_array( $type, self::INCOME_TYPES, true ) ) {
                $by_method[ $method ] = ( $by_method[ $method ] ?? 0.0 ) + $amount;
                $total_income += $amount;
                if ( 'cash' === $method ) {
                    $income_cash += $amount;
                }
            } elseif ( in_array( $type, self::EXPENSE_TYPES, true ) ) {
                $total_expense += $amount;
                if ( 'cash' === $method ) {
                    $expense_cash += $amount;
                }
            }
        }

        $expected_cash = (float) $opening_cash + $income_cash - $expense_cash;

        return array(
            'by_method'      => $by_method,
            'income_cash'    => round( $income_cash, 2 ),
            'expense_cash'   => round( $expense_cash, 2 ),
            'total_income'   => round( $total_income, 2 ),
            'total_expense'  => round( $total_expense, 2 ),
            'expected_cash'  => round( $expected_cash, 2 ),
        );
    }
}
