<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI Checkout Validator — Validación de Código Postal vs Provincia (Argentina)
 *
 * Valida en el checkout de WooCommerce que el código postal ingresado
 * corresponda a la provincia seleccionada, evitando errores de zona de envío.
 * Soporta el formato viejo de 4 dígitos y el CPA alfanumérico.
 * Los códigos de provincia de WooCommerce Argentina son de una sola letra
 * (C = CABA, B = Buenos Aires Provincia, etc.).
 */
class WBI_Checkout_Validator {

    public function __construct() {
        // Validación server-side: bloquea la orden si el CP no coincide con la provincia
        add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_postcode' ), 10, 2 );

        // Validación en tiempo real via JavaScript en el footer del checkout
        add_action( 'wp_footer', array( $this, 'inject_js_validation' ) );
    }

    /**
     * Mapa completo de provincias argentinas.
     * Clave: código de una sola letra usado por WooCommerce Argentina.
     * 'name':   nombre de la provincia para los mensajes de error.
     * 'ranges': rangos de CP numérico de 4 dígitos válidos para la provincia.
     */
    private function get_province_map() {
        return array(
            'C' => array( 'name' => 'Ciudad Autónoma de Buenos Aires (CABA)', 'ranges' => array( array( 1000, 1499 ) ) ),
            'B' => array( 'name' => 'Buenos Aires (Provincia)',               'ranges' => array( array( 1500, 2999 ), array( 6000, 6699 ), array( 7000, 7999 ) ) ),
            'K' => array( 'name' => 'Catamarca',                              'ranges' => array( array( 4700, 4751 ) ) ),
            'H' => array( 'name' => 'Chaco',                                  'ranges' => array( array( 3500, 3515 ), array( 3700, 3722 ) ) ),
            'U' => array( 'name' => 'Chubut',                                 'ranges' => array( array( 9000, 9220 ) ) ),
            'X' => array( 'name' => 'Córdoba',                                'ranges' => array( array( 5000, 5999 ) ) ),
            'W' => array( 'name' => 'Corrientes',                             'ranges' => array( array( 3200, 3485 ) ) ),
            'E' => array( 'name' => 'Entre Ríos',                             'ranges' => array( array( 3100, 3289 ) ) ),
            'P' => array( 'name' => 'Formosa',                                'ranges' => array( array( 3600, 3636 ) ) ),
            'Y' => array( 'name' => 'Jujuy',                                  'ranges' => array( array( 4600, 4655 ) ) ),
            'L' => array( 'name' => 'La Pampa',                               'ranges' => array( array( 6300, 6399 ) ) ),
            'F' => array( 'name' => 'La Rioja',                               'ranges' => array( array( 5300, 5385 ) ) ),
            'M' => array( 'name' => 'Mendoza',                                'ranges' => array( array( 5500, 5613 ) ) ),
            'N' => array( 'name' => 'Misiones',                               'ranges' => array( array( 3300, 3389 ) ) ),
            'Q' => array( 'name' => 'Neuquén',                                'ranges' => array( array( 8300, 8353 ) ) ),
            'R' => array( 'name' => 'Río Negro',                              'ranges' => array( array( 8200, 8521 ) ) ),
            'A' => array( 'name' => 'Salta',                                  'ranges' => array( array( 4400, 4568 ) ) ),
            'J' => array( 'name' => 'San Juan',                               'ranges' => array( array( 5400, 5467 ) ) ),
            'D' => array( 'name' => 'San Luis',                               'ranges' => array( array( 5700, 5777 ) ) ),
            'Z' => array( 'name' => 'Santa Cruz',                             'ranges' => array( array( 9400, 9420 ) ) ),
            'S' => array( 'name' => 'Santa Fe',                               'ranges' => array( array( 2000, 3099 ), array( 3516, 3599 ) ) ),
            'G' => array( 'name' => 'Santiago del Estero',                    'ranges' => array( array( 4200, 4356 ) ) ),
            'V' => array( 'name' => 'Tierra del Fuego',                       'ranges' => array( array( 9400, 9421 ) ) ),
            'T' => array( 'name' => 'Tucumán',                                'ranges' => array( array( 4000, 4197 ) ) ),
        );
    }

    /**
     * Verifica si un CP coincide con los rangos o la letra CPA de una provincia.
     *
     * @param string $postcode  CP en mayúsculas, sin espacios.
     * @param array  $province  Entrada del mapa de provincias (name + ranges).
     * @param string $state     Código de provincia de una sola letra.
     * @return bool|null  true = válido, false = inválido, null = formato no reconocido.
     */
    private function postcode_matches( $postcode, $province, $state ) {
        // CPA alfanumérico: letra + 4 dígitos + hasta 3 letras (ej: C1425ABC o B1900)
        if ( preg_match( '/^([A-Z])(\d{4})([A-Z]{0,3})$/', $postcode, $m ) ) {
            return $m[1] === $state;
        }

        // CP viejo de 4 dígitos
        if ( preg_match( '/^\d{4}$/', $postcode ) ) {
            $numeric = intval( $postcode );
            foreach ( $province['ranges'] as $range ) {
                if ( $numeric >= $range[0] && $numeric <= $range[1] ) {
                    return true;
                }
            }
            return false;
        }

        // Formato no reconocido
        return null;
    }

    /**
     * Validación server-side en el checkout.
     * Usa woocommerce_after_checkout_validation para bloquear la orden.
     */
    public function validate_postcode( $data, $errors ) {
        if ( 'AR' !== $data['billing_country'] ) {
            return;
        }

        $state    = isset( $data['billing_state'] )    ? sanitize_text_field( $data['billing_state'] )    : '';
        $postcode = isset( $data['billing_postcode'] )  ? strtoupper( trim( sanitize_text_field( $data['billing_postcode'] ) ) ) : '';

        if ( empty( $state ) || empty( $postcode ) ) {
            return;
        }

        $map = $this->get_province_map();

        if ( ! isset( $map[ $state ] ) ) {
            return;
        }

        $province = $map[ $state ];
        $result   = $this->postcode_matches( $postcode, $province, $state );

        if ( null === $result ) {
            $errors->add(
                'validation',
                '⚠️ El código postal ingresado no tiene un formato válido. Usá 4 dígitos (ej: 1900) o el formato CPA (ej: B1900ABC).'
            );
            return;
        }

        if ( false === $result ) {
            $errors->add(
                'validation',
                sprintf(
                    '⚠️ El código postal <strong>%s</strong> no corresponde a <strong>%s</strong>. Por favor, verificá tu código postal o cambiá la provincia.',
                    esc_html( $postcode ),
                    esc_html( $province['name'] )
                )
            );
        }
    }

    /**
     * Inyecta la validación JavaScript en tiempo real en el footer del checkout.
     */
    public function inject_js_validation() {
        if ( ! is_checkout() ) {
            return;
        }

        $map = $this->get_province_map();

        // Convertir el mapa PHP a JSON para usarlo en JavaScript
        $js_map = array();
        foreach ( $map as $code => $data ) {
            $js_map[ $code ] = array(
                'name'   => $data['name'],
                'ranges' => $data['ranges'],
            );
        }
        $js_map_json = wp_json_encode( $js_map );
        ?>
        <style>
        .wbi-cp-error {
            color: #e2401c;
            font-size: 13px;
            display: block;
            margin-top: 5px;
        }
        </style>
        <script type="text/javascript">
        jQuery(function($) {
            var wbiProvinceMap = <?php echo $js_map_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;

            function wbiValidatePostcode() {
                $('.wbi-cp-error').remove();

                var country  = $('#billing_country').val();
                if (country !== 'AR') return;

                var state    = $('#billing_state').val();
                var postcode = ($('#billing_postcode').val() || '').toUpperCase().trim();

                if (!state || !postcode || !wbiProvinceMap[state]) return;

                var province = wbiProvinceMap[state];
                var match    = null;

                // CPA alfanumérico (ej: C1425ABC o B1900)
                var cpaMatcher = postcode.match(/^([A-Z])(\d{4})([A-Z]{0,3})$/);
                if (cpaMatcher) {
                    match = (cpaMatcher[1] === state);
                } else if (/^\d{4}$/.test(postcode)) {
                    // CP viejo de 4 dígitos
                    var num = parseInt(postcode, 10);
                    match = false;
                    for (var i = 0; i < province.ranges.length; i++) {
                        if (num >= province.ranges[i][0] && num <= province.ranges[i][1]) {
                            match = true;
                            break;
                        }
                    }
                }

                if (match === false && postcode.length >= 4) {
                    $('#billing_postcode_field').append(
                        '<span class="wbi-cp-error">' +
                        '⚠️ El código postal no parece corresponder a <strong>' +
                        $('<div>').text(province.name).html() +
                        '</strong>. Verificá tus datos.' +
                        '</span>'
                    );
                }
            }

            $(document.body).on('change keyup', '#billing_postcode', wbiValidatePostcode);
            $(document.body).on('change', '#billing_state', wbiValidatePostcode);
            $(document.body).on('updated_checkout', wbiValidatePostcode);
        });
        </script>
        <?php
    }
}
