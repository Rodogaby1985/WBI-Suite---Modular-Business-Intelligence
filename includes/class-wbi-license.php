<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_License_Manager {

    // Clave secreta interna para generar/validar hashes (NO debe ser pública)
    const SECRET_SALT = 'WBI_2026_R0dr1g0_C4st4n3r4_S3cr3t';

    // Prefijo de las claves
    const KEY_PREFIX = 'WBI';

    // Option names en wp_options
    const OPTION_KEY          = 'wbi_license_key';
    const OPTION_STATUS       = 'wbi_license_status'; // 'active' or ''
    const OPTION_ACTIVATED_AT = 'wbi_license_activated_at';

    // Plan definitions: code => [ name, days (0 = lifetime), emoji ]
    private static $plans = array(
        'T3' => array( 'name' => 'Trial (3 días)',  'days' => 3,   'emoji' => '🧪' ),
        'M1' => array( 'name' => 'Mensual',         'days' => 30,  'emoji' => '📅' ),
        'A1' => array( 'name' => 'Anual',           'days' => 365, 'emoji' => '📆' ),
        'LF' => array( 'name' => 'Lifetime',        'days' => 0,   'emoji' => '♾️' ),
    );

    /**
     * Get the full plans array.
     * @return array
     */
    public static function get_plans() {
        return self::$plans;
    }

    /**
     * Check if the current license is valid and active (not expired).
     * @return bool
     */
    public static function is_active() {
        $status = get_option( self::OPTION_STATUS, '' );
        if ( $status !== 'active' ) return false;

        $key = get_option( self::OPTION_KEY, '' );
        if ( ! self::validate_key( $key ) ) return false;

        // Check expiration
        $plan_info = self::get_plan_info();
        if ( $plan_info['is_expired'] ) {
            return false;
        }

        return true;
    }

    /**
     * Check if there IS a license stored but it has expired.
     * Useful for showing different UI messages.
     *
     * @return bool
     */
    public static function is_expired() {
        $status = get_option( self::OPTION_STATUS, '' );
        if ( $status !== 'active' ) return false;

        $key = get_option( self::OPTION_KEY, '' );
        if ( ! self::validate_key( $key ) ) return false;

        $plan_info = self::get_plan_info();
        return $plan_info['is_expired'];
    }

    /**
     * Validate a license key format and hash.
     *
     * Key format: WBI-XXXX-XXXX-XXXX-HASH
     * Where HASH is derived from the first 3 segments + secret salt.
     *
     * @param string $key
     * @return bool
     */
    public static function validate_key( $key ) {
        $key = strtoupper( trim( $key ) );

        // Must match format: WBI-XXXX-XXXX-XXXX-XXXX
        if ( ! preg_match( '/^WBI-([A-Z0-9]{4})-([A-Z0-9]{4})-([A-Z0-9]{4})-([A-Z0-9]{4})$/', $key, $matches ) ) {
            return false;
        }

        // The first 3 segments are the "payload", the 4th is the checksum
        $payload        = $matches[1] . $matches[2] . $matches[3];
        $provided_check = $matches[4];

        // Compute expected checksum: first 4 chars of uppercase MD5 of payload+salt
        $expected_check = strtoupper( substr( md5( $payload . self::SECRET_SALT ), 0, 4 ) );

        return hash_equals( $expected_check, $provided_check );
    }

    /**
     * Activate a license key. Saves to database if valid.
     * @param string $key
     * @return bool
     */
    public static function activate( $key ) {
        $key = strtoupper( trim( $key ) );

        if ( ! self::validate_key( $key ) ) {
            return false;
        }

        update_option( self::OPTION_KEY, $key );
        update_option( self::OPTION_STATUS, 'active' );
        update_option( self::OPTION_ACTIVATED_AT, current_time( 'mysql' ) );

        return true;
    }

    /**
     * Deactivate and remove the current license.
     */
    public static function deactivate() {
        delete_option( self::OPTION_KEY );
        delete_option( self::OPTION_STATUS );
        delete_option( self::OPTION_ACTIVATED_AT );
    }

    /**
     * Get the currently stored license key (masked for display).
     * @return string
     */
    public static function get_masked_key() {
        $key = get_option( self::OPTION_KEY, '' );
        if ( empty( $key ) ) return '';
        // Show first and last segment, mask middle: WBI-A1B2-****-****-HASH
        $parts = explode( '-', $key );
        if ( count( $parts ) !== 5 ) return '****';
        return $parts[0] . '-' . $parts[1] . '-****-****-' . $parts[4];
    }

    /**
     * Extract the plan code from a license key.
     *
     * @param string|null $key  License key. If null, uses stored key.
     * @return string  Plan code (T3, M1, A1, LF) or empty string if invalid.
     */
    public static function get_plan_from_key( $key = null ) {
        if ( $key === null ) {
            $key = get_option( self::OPTION_KEY, '' );
        }
        $key = strtoupper( trim( $key ) );

        if ( ! preg_match( '/^WBI-([A-Z0-9]{4})-([A-Z0-9]{4})-([A-Z0-9]{4})-([A-Z0-9]{4})$/', $key, $matches ) ) {
            return '';
        }

        $plan_code   = substr( $matches[1], 0, 2 );
        $valid_plans = array( 'T3', 'M1', 'A1', 'LF' );

        return in_array( $plan_code, $valid_plans, true ) ? $plan_code : '';
    }

    /**
     * Get full plan information for the current license.
     *
     * @return array  With keys: code, name, emoji, days, expires_at, days_remaining, is_expired
     */
    public static function get_plan_info() {
        $code  = self::get_plan_from_key();
        $plans = self::$plans;

        if ( empty( $code ) || ! isset( $plans[ $code ] ) ) {
            return array(
                'code'           => '',
                'name'           => 'Desconocido',
                'emoji'          => '❓',
                'days'           => 0,
                'expires_at'     => '',
                'days_remaining' => 0,
                'is_expired'     => true,
            );
        }

        $plan         = $plans[ $code ];
        $activated_at = get_option( self::OPTION_ACTIVATED_AT, '' );

        // Lifetime never expires
        if ( $plan['days'] === 0 ) {
            return array(
                'code'           => $code,
                'name'           => $plan['name'],
                'emoji'          => $plan['emoji'],
                'days'           => 0,
                'expires_at'     => '',
                'days_remaining' => -1, // -1 means infinite
                'is_expired'     => false,
            );
        }

        if ( empty( $activated_at ) ) {
            return array(
                'code'           => $code,
                'name'           => $plan['name'],
                'emoji'          => $plan['emoji'],
                'days'           => $plan['days'],
                'expires_at'     => '',
                'days_remaining' => 0,
                'is_expired'     => true,
            );
        }

        $activated_ts = strtotime( $activated_at );
        $expires_ts   = $activated_ts + ( $plan['days'] * DAY_IN_SECONDS );
        $now_ts       = current_time( 'timestamp' );
        $remaining    = max( 0, (int) ceil( ( $expires_ts - $now_ts ) / DAY_IN_SECONDS ) );
        $is_expired   = ( $now_ts >= $expires_ts );

        return array(
            'code'           => $code,
            'name'           => $plan['name'],
            'emoji'          => $plan['emoji'],
            'days'           => $plan['days'],
            'expires_at'     => gmdate( 'Y-m-d', $expires_ts ),
            'days_remaining' => $remaining,
            'is_expired'     => $is_expired,
        );
    }

    /**
     * Generate a valid license key for a specific plan.
     *
     * @param string      $plan     Plan code: 'T3', 'M1', 'A1', 'LF'
     * @param string|null $payload  Optional remaining 10-char alphanumeric. If null, generates random.
     * @return string  e.g. WBI-T3A2-B4C6-D8E0-HASH
     */
    public static function generate_key( $plan = 'LF', $payload = null ) {
        // Validate plan code
        $valid_plans = array( 'T3', 'M1', 'A1', 'LF' );
        $plan        = strtoupper( trim( $plan ) );
        if ( ! in_array( $plan, $valid_plans, true ) ) {
            $plan = 'LF';
        }

        if ( $payload === null ) {
            $chars   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $payload = '';
            for ( $i = 0; $i < 10; $i++ ) {
                $payload .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
            }
        }
        $payload = strtoupper( $payload );

        // Full 12-char payload = plan code (2 chars) + random (10 chars)
        $full_payload = $plan . $payload;

        $seg1  = substr( $full_payload, 0, 4 );
        $seg2  = substr( $full_payload, 4, 4 );
        $seg3  = substr( $full_payload, 8, 4 );
        $check = strtoupper( substr( md5( $full_payload . self::SECRET_SALT ), 0, 4 ) );

        return self::KEY_PREFIX . '-' . $seg1 . '-' . $seg2 . '-' . $seg3 . '-' . $check;
    }
}

// WP-CLI command for generating license keys
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'wbi generate-key', function( $args, $assoc_args ) {
        $plan  = isset( $assoc_args['plan'] ) ? strtoupper( $assoc_args['plan'] ) : 'LF';
        $valid = array( 'T3', 'M1', 'A1', 'LF' );
        if ( ! in_array( $plan, $valid, true ) ) {
            WP_CLI::error( 'Plan inválido. Opciones: T3 (trial 3d), M1 (mensual), A1 (anual), LF (lifetime)' );
            return;
        }
        $key   = WBI_License_Manager::generate_key( $plan );
        $plans = WBI_License_Manager::get_plans();
        $info  = $plans[ $plan ];
        WP_CLI::success( "{$info['emoji']} Plan: {$info['name']}" );
        WP_CLI::success( "Clave generada: $key" );
    } );
}
