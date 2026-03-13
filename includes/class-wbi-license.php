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

    /**
     * Check if the current license is valid and active.
     * @return bool
     */
    public static function is_active() {
        $status = get_option( self::OPTION_STATUS, '' );
        if ( $status !== 'active' ) return false;

        $key = get_option( self::OPTION_KEY, '' );
        return self::validate_key( $key );
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
     * UTILITY: Generate a valid license key (for the plugin author to use).
     *
     * @param string|null $payload  Optional 12-char alphanumeric payload. If null, generates random.
     * @return string  A valid license key like WBI-A1B2-C3D4-E5F6-HASH
     */
    public static function generate_key( $payload = null ) {
        if ( $payload === null ) {
            $chars   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $payload = '';
            for ( $i = 0; $i < 12; $i++ ) {
                $payload .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
            }
        }
        $payload = strtoupper( $payload );
        $seg1    = substr( $payload, 0, 4 );
        $seg2    = substr( $payload, 4, 4 );
        $seg3    = substr( $payload, 8, 4 );
        $check   = strtoupper( substr( md5( $payload . self::SECRET_SALT ), 0, 4 ) );
        return self::KEY_PREFIX . '-' . $seg1 . '-' . $seg2 . '-' . $seg3 . '-' . $check;
    }
}

// WP-CLI command for generating license keys
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'wbi generate-key', function() {
        $key = WBI_License_Manager::generate_key();
        WP_CLI::success( "Generated license key: $key" );
    } );
}
