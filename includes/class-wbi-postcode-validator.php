<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI Postcode-Province Validator
 *
 * Validates that the billing/shipping postcode matches the selected
 * Argentine province during WooCommerce checkout.
 *
 * Uses the old Argentine numeric postal code system (4 digits) which
 * WooCommerce stores in billing_postcode / shipping_postcode.
 * The province is stored as a single-letter ISO code in billing_state
 * from the checkout posted data array.
 */
class WBI_Postcode_Validator {

    /**
     * Map of province ISO codes to their valid postcode numeric ranges.
     * Each province has one or more [min, max] pairs.
     * Based on the Argentine postal code system.
     */
    private static $province_postcode_ranges = array(
        'C' => array( array( 1000, 1499 ) ),                                    // CABA
        'B' => array( array( 1500, 2999 ), array( 6000, 6699 ), array( 7000, 7999 ) ), // Buenos Aires
        'K' => array( array( 4700, 4751 ) ),                                    // Catamarca
        'H' => array( array( 3500, 3722 ) ),                                    // Chaco
        'U' => array( array( 9000, 9220 ) ),                                    // Chubut
        'X' => array( array( 5000, 5999 ) ),                                    // Córdoba
        'W' => array( array( 3200, 3485 ) ),                                    // Corrientes
        'E' => array( array( 3100, 3289 ) ),                                    // Entre Ríos
        'P' => array( array( 3600, 3636 ) ),                                    // Formosa
        'Y' => array( array( 4600, 4655 ) ),                                    // Jujuy
        'L' => array( array( 6300, 6399 ) ),                                    // La Pampa
        'F' => array( array( 5300, 5385 ) ),                                    // La Rioja
        'M' => array( array( 5500, 5613 ) ),                                    // Mendoza
        'N' => array( array( 3300, 3389 ) ),                                    // Misiones
        'Q' => array( array( 8300, 8353 ) ),                                    // Neuquén
        'R' => array( array( 8200, 8521 ) ),                                    // Río Negro
        'A' => array( array( 4400, 4568 ) ),                                    // Salta
        'J' => array( array( 5400, 5467 ) ),                                    // San Juan
        'D' => array( array( 5700, 5777 ) ),                                    // San Luis
        'Z' => array( array( 9300, 9399 ) ),                                    // Santa Cruz
        'S' => array( array( 2000, 3099 ) ),                                    // Santa Fe
        'G' => array( array( 4200, 4356 ) ),                                    // Santiago del Estero
        'V' => array( array( 9400, 9421 ) ),                                    // Tierra del Fuego
        'T' => array( array( 4000, 4197 ) ),                                    // Tucumán
    );

    /**
     * Human-readable province names indexed by ISO code.
     */
    private static $province_names = array(
        'C' => 'Ciudad Autónoma de Buenos Aires (CABA)',
        'B' => 'Buenos Aires (Provincia)',
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

    public function __construct() {
        // Validate billing address during checkout
        add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_postcode_province' ), 10, 2 );
    }

    /**
     * Validate that the billing postcode matches the selected province.
     *
     * @param array    $data   Checkout posted data.
     * @param WP_Error $errors WP_Error object to add validation errors to.
     */
    public function validate_postcode_province( $data, $errors ) {
        // Only validate for Argentina
        $country = isset( $data['billing_country'] ) ? $data['billing_country'] : '';
        if ( 'AR' !== $country ) {
            return;
        }

        $postcode = isset( $data['billing_postcode'] ) ? strtoupper( trim( $data['billing_postcode'] ) ) : '';
        $postcode = preg_replace( '/\s+/', '', $postcode );
        $state    = isset( $data['billing_state'] ) ? strtoupper( trim( $data['billing_state'] ) ) : '';

        // Strip AR- prefix if present (some WooCommerce setups use AR-B, AR-S, etc.)
        if ( 0 === strpos( $state, 'AR-' ) ) {
            $state = substr( $state, 3 );
        }

        // If no postcode or state, let WooCommerce handle required field validation
        if ( empty( $postcode ) || empty( $state ) ) {
            return;
        }

        // If the province is not in the map, skip validation
        if ( ! isset( self::$province_postcode_ranges[ $state ] ) ) {
            return;
        }

        // CASO 1: CPA alfanumérico (ej: C1425ABC) — letter must match province
        if ( preg_match( '/^([A-Z])(\d{4})([A-Z]{0,3})$/', $postcode, $m ) ) {
            if ( $m[1] === $state ) {
                return; // valid
            }
            $this->add_mismatch_error( $errors, $postcode, $state );
            return;
        }

        // CASO 2: CP viejo 4 dígitos (ej: 1657)
        if ( preg_match( '/^\d{4}$/', $postcode ) ) {
            $numeric = intval( $postcode );
            foreach ( self::$province_postcode_ranges[ $state ] as $range ) {
                if ( $numeric >= $range[0] && $numeric <= $range[1] ) {
                    return; // valid
                }
            }
            $this->add_mismatch_error( $errors, $postcode, $state );
            return;
        }

        // Unrecognized format — add format error
        $errors->add(
            'wbi_postcode_invalid_format',
            '⚠️ El código postal ingresado no tiene un formato válido. Usá 4 dígitos (ej: 1900) o formato CPA (ej: B1900ABC).'
        );
    }

    /**
     * Add a postcode/province mismatch error to the WP_Error object.
     *
     * @param WP_Error $errors WP_Error object.
     * @param string   $postcode The postcode entered by the customer.
     * @param string   $state    The province ISO code.
     */
    private function add_mismatch_error( $errors, $postcode, $state ) {
        $province_name = isset( self::$province_names[ $state ] ) ? self::$province_names[ $state ] : $state;

        $errors->add(
            'wbi_postcode_province_mismatch',
            sprintf(
                '⚠️ El código postal <strong>%s</strong> no corresponde a <strong>%s</strong>. Verificá tu código postal o seleccioná la provincia correcta.',
                esc_html( $postcode ),
                esc_html( $province_name )
            )
        );
    }

    /**
     * Check if a given postcode is valid for a province.
     * Utility method for use by other modules.
     *
     * @param string $postcode Numeric postcode or CPA format.
     * @param string $province_code Single-letter province ISO code.
     * @return bool|null True if valid, false if invalid, null if cannot determine.
     */
    public static function is_valid_postcode_for_province( $postcode, $province_code ) {
        $province_code = strtoupper( trim( $province_code ) );
        if ( 0 === strpos( $province_code, 'AR-' ) ) {
            $province_code = substr( $province_code, 3 );
        }

        $postcode = strtoupper( preg_replace( '/\s+/', '', trim( $postcode ) ) );

        if ( ! isset( self::$province_postcode_ranges[ $province_code ] ) ) {
            return null;
        }

        // CPA format: letter must match province
        if ( preg_match( '/^([A-Z])(\d{4})([A-Z]{0,3})$/', $postcode, $m ) ) {
            return $m[1] === $province_code;
        }

        // 4-digit numeric format
        if ( preg_match( '/^\d{4}$/', $postcode ) ) {
            $numeric = intval( $postcode );
            foreach ( self::$province_postcode_ranges[ $province_code ] as $range ) {
                if ( $numeric >= $range[0] && $numeric <= $range[1] ) {
                    return true;
                }
            }
            return false;
        }

        return null;
    }
}
