<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI Custom Fields Module
 *
 * Permite al administrador definir campos personalizados que se muestran
 * en el formulario de registro y/o checkout de WooCommerce.
 * Los campos soportan validación de formato, obligatoriedad y duplicados.
 */
class WBI_Custom_Fields_Module {

    /** @var array Configuración de campos guardada en wp_options */
    private $fields_config;

    public function __construct() {
        $this->fields_config = get_option( 'wbi_custom_fields_config', array() );

        // ── Admin ──────────────────────────────────────────────────────────
        add_action( 'admin_menu', array( $this, 'add_admin_page' ), 100 );
        add_action( 'admin_init', array( $this, 'handle_field_actions' ) );

        // ── Registro ───────────────────────────────────────────────────────
        add_action( 'woocommerce_register_form', array( $this, 'render_registration_fields' ) );
        add_filter( 'woocommerce_registration_errors', array( $this, 'validate_registration_fields' ), 10, 3 );
        add_action( 'woocommerce_created_customer', array( $this, 'save_registration_fields' ) );

        // ── Checkout ───────────────────────────────────────────────────────
        add_filter( 'woocommerce_checkout_fields', array( $this, 'add_checkout_fields' ) );
        add_action( 'woocommerce_checkout_process', array( $this, 'validate_checkout_fields' ) );
        add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'save_checkout_order_meta' ) );
        add_action( 'woocommerce_checkout_update_user_meta', array( $this, 'save_checkout_user_meta' ), 10, 2 );

        // ── Visualización en admin / emails ────────────────────────────────
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_in_order_admin' ) );
        add_action( 'woocommerce_email_after_order_table', array( $this, 'display_in_email' ), 10, 4 );

        // ── Perfil de usuario en admin ──────────────────────────────────────
        add_action( 'show_user_profile', array( $this, 'display_user_profile_fields' ) );
        add_action( 'edit_user_profile', array( $this, 'display_user_profile_fields' ) );
        add_action( 'personal_options_update', array( $this, 'save_user_profile_fields' ) );
        add_action( 'edit_user_profile_update', array( $this, 'save_user_profile_fields' ) );

        // ── Toggle JS ──────────────────────────────────────────────────────
        add_action( 'wp_footer', array( $this, 'render_toggle_js' ) );
    }

    // =========================================================================
    // ADMIN — CRUD de campos
    // =========================================================================

    public function add_admin_page() {
        add_submenu_page(
            'wbi-dashboard-view',
            'Campos Personalizados',
            '<span class="dashicons dashicons-list-view" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> Campos Personalizados',
            'manage_options',
            'wbi-custom-fields',
            array( $this, 'render_admin_page' )
        );
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tenés permisos para acceder a esta página.', 'wbi' ) );
        }

        $fields = $this->fields_config;
        $edit_field = null;
        $edit_index = null;

        if ( isset( $_GET['edit'] ) ) {
            $edit_index = intval( $_GET['edit'] );
            if ( isset( $fields[ $edit_index ] ) ) {
                $edit_field = $fields[ $edit_index ];
            }
        }
        ?>
        <div class="wrap">
            <h1>📋 Campos Personalizados — Registro &amp; Checkout</h1>
            <p style="color:#50575e;">Definí campos extra que aparecerán en el formulario de registro y/o checkout de WooCommerce.</p>

            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>✅ Campo guardado correctamente.</p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['deleted'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>🗑️ Campo eliminado.</p></div>
            <?php endif; ?>

            <!-- Formulario agregar / editar campo -->
            <div style="background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:20px; max-width:700px; margin-bottom:30px;">
                <h2 style="margin-top:0;"><?php echo $edit_field ? 'Editar campo' : 'Agregar nuevo campo'; ?></h2>
                <form method="post" action="">
                    <?php wp_nonce_field( 'wbi_cf_save', 'wbi_cf_nonce' ); ?>
                    <input type="hidden" name="wbi_cf_action" value="save">
                    <?php if ( $edit_index !== null ) : ?>
                        <input type="hidden" name="wbi_cf_index" value="<?php echo intval( $edit_index ); ?>">
                    <?php endif; ?>

                    <table class="form-table"><tbody>
                        <tr>
                            <th><label for="cf_label">Nombre del campo (label) *</label></th>
                            <td><input type="text" id="cf_label" name="cf_label" required style="width:100%;"
                                value="<?php echo $edit_field ? esc_attr( $edit_field['label'] ) : ''; ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="cf_key">Clave única (key) *</label></th>
                            <td>
                                <input type="text" id="cf_key" name="cf_key" required style="width:100%;"
                                    value="<?php echo $edit_field ? esc_attr( $edit_field['key'] ) : ''; ?>"
                                    pattern="[a-z0-9_]+" title="Solo letras minúsculas, números y guión bajo">
                                <p class="description">Solo letras minúsculas, números y guión bajo. Se usará como meta_key. Ej: <code>billing_nro_doc</code></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="cf_type">Tipo de campo</label></th>
                            <td>
                                <select id="cf_type" name="cf_type">
                                    <?php
                                    $types = array( 'text' => 'Texto', 'number' => 'Número', 'email' => 'Email', 'tel' => 'Teléfono', 'select' => 'Select (lista)', 'radio' => 'Radio buttons' );
                                    $sel_type = $edit_field ? $edit_field['type'] : 'text';
                                    foreach ( $types as $val => $lbl ) {
                                        printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $sel_type, $val, false ), esc_html( $lbl ) );
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="cf_options">Opciones (para select/radio)</label></th>
                            <td>
                                <input type="text" id="cf_options" name="cf_options" style="width:100%;"
                                    value="<?php echo $edit_field ? esc_attr( $edit_field['options'] ?? '' ) : ''; ?>">
                                <p class="description">Lista de valores separados por comas. Ej: <code>DNI,CUIT,CUIL</code></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="cf_placeholder">Placeholder</label></th>
                            <td><input type="text" id="cf_placeholder" name="cf_placeholder" style="width:100%;"
                                value="<?php echo $edit_field ? esc_attr( $edit_field['placeholder'] ?? '' ) : ''; ?>"></td>
                        </tr>
                        <tr>
                            <th>Ubicación</th>
                            <td>
                                <?php
                                $sel_loc = $edit_field ? ( $edit_field['location'] ?? 'both' ) : 'both';
                                $locs    = array( 'register' => 'Solo registro', 'checkout' => 'Solo checkout', 'both' => 'Registro y checkout' );
                                foreach ( $locs as $val => $lbl ) {
                                    printf(
                                        '<label style="margin-right:16px;"><input type="radio" name="cf_location" value="%s"%s> %s</label>',
                                        esc_attr( $val ),
                                        checked( $sel_loc, $val, false ),
                                        esc_html( $lbl )
                                    );
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th>¿Obligatorio?</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="cf_required" value="1"
                                        <?php checked( ! empty( $edit_field['required'] ) ); ?>>
                                    Sí, es obligatorio
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>¿Validar duplicados?</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="cf_unique" value="1"
                                        <?php checked( ! empty( $edit_field['unique'] ) ); ?>>
                                    Sí, evitar que dos usuarios tengan el mismo valor
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="cf_validation_regex">Regla de validación (regex)</label></th>
                            <td>
                                <input type="text" id="cf_validation_regex" name="cf_validation_regex" style="width:100%;"
                                    value="<?php echo $edit_field ? esc_attr( $edit_field['validation_regex'] ?? '' ) : ''; ?>">
                                <p class="description">Patrón regex sin delimitadores. Ej: <code>^\d{7,8}$</code> para validar un DNI.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="cf_min_length">Longitud mínima</label></th>
                            <td>
                                <input type="number" id="cf_min_length" name="cf_min_length" min="0" style="width:80px;"
                                    value="<?php echo $edit_field ? intval( $edit_field['min_length'] ?? 0 ) : 0; ?>">
                                &nbsp;&nbsp;<label for="cf_max_length">Longitud máxima</label>&nbsp;
                                <input type="number" id="cf_max_length" name="cf_max_length" min="0" style="width:80px;"
                                    value="<?php echo $edit_field ? intval( $edit_field['max_length'] ?? 0 ) : 0; ?>">
                                <p class="description">Ingresá 0 para no aplicar límite de longitud.</p>
                            </td>
                        </tr>
                    </tbody></table>

                    <?php submit_button( $edit_field ? 'Actualizar campo' : 'Agregar campo', 'primary', 'submit', false ); ?>
                    <?php if ( $edit_field ) : ?>
                        &nbsp;<a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-custom-fields' ) ); ?>" class="button">Cancelar</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Lista de campos existentes -->
            <?php if ( empty( $fields ) ) : ?>
                <p style="color:#888;">No hay campos personalizados definidos todavía.</p>
            <?php else : ?>
                <table class="widefat wbi-sortable" style="max-width:900px;">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Label</th>
                            <th>Key</th>
                            <th>Tipo</th>
                            <th>Ubicación</th>
                            <th>Oblig.</th>
                            <th>Único</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $fields as $idx => $f ) : ?>
                        <tr>
                            <td><?php echo intval( $idx ) + 1; ?></td>
                            <td><?php echo esc_html( $f['label'] ); ?></td>
                            <td><code><?php echo esc_html( $f['key'] ); ?></code></td>
                            <td><?php echo esc_html( $f['type'] ); ?></td>
                            <td>
                                <?php
                                $loc_labels = array( 'register' => 'Registro', 'checkout' => 'Checkout', 'both' => 'Ambos' );
                                echo esc_html( $loc_labels[ $f['location'] ?? 'both' ] ?? '-' );
                                ?>
                            </td>
                            <td><?php echo ! empty( $f['required'] ) ? '✅' : '—'; ?></td>
                            <td><?php echo ! empty( $f['unique'] ) ? '✅' : '—'; ?></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-custom-fields&edit=' . intval( $idx ) ) ); ?>">Editar</a>
                                &nbsp;|&nbsp;
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wbi-custom-fields&wbi_cf_action=delete&wbi_cf_index=' . intval( $idx ) ), 'wbi_cf_delete_' . intval( $idx ) ) ); ?>"
                                   onclick="return confirm('¿Eliminar este campo?');"
                                   style="color:#a00;">Eliminar</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_field_actions() {
        if ( ! isset( $_REQUEST['page'] ) || $_REQUEST['page'] !== 'wbi-custom-fields' ) return;
        if ( ! isset( $_REQUEST['wbi_cf_action'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        $action = sanitize_text_field( $_REQUEST['wbi_cf_action'] );

        if ( 'save' === $action ) {
            if ( ! isset( $_POST['wbi_cf_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wbi_cf_nonce'] ) ), 'wbi_cf_save' ) ) {
                wp_die( 'Nonce inválido.' );
            }

            $fields = $this->fields_config;

            $new_field = array(
                'label'            => sanitize_text_field( wp_unslash( $_POST['cf_label'] ?? '' ) ),
                'key'              => sanitize_key( wp_unslash( $_POST['cf_key'] ?? '' ) ),
                'type'             => sanitize_text_field( wp_unslash( $_POST['cf_type'] ?? 'text' ) ),
                'options'          => sanitize_text_field( wp_unslash( $_POST['cf_options'] ?? '' ) ),
                'placeholder'      => sanitize_text_field( wp_unslash( $_POST['cf_placeholder'] ?? '' ) ),
                'location'         => sanitize_text_field( wp_unslash( $_POST['cf_location'] ?? 'both' ) ),
                'required'         => ! empty( $_POST['cf_required'] ) ? 1 : 0,
                'unique'           => ! empty( $_POST['cf_unique'] ) ? 1 : 0,
                'validation_regex' => sanitize_text_field( wp_unslash( $_POST['cf_validation_regex'] ?? '' ) ),
                'min_length'       => max( 0, intval( $_POST['cf_min_length'] ?? 0 ) ),
                'max_length'       => max( 0, intval( $_POST['cf_max_length'] ?? 0 ) ),
            );

            // Validate: key must not be empty and must not start with underscore (reserved for private meta)
            if ( '' === $new_field['key'] ) {
                wp_die( esc_html( 'La clave del campo no puede estar vacía.' ) );
            }
            if ( strpos( $new_field['key'], '_' ) === 0 ) {
                wp_die( esc_html( 'La clave del campo no puede comenzar con guión bajo (_) ya que es reservado por WordPress.' ) );
            }

            // Validate regex: ensure it compiles correctly to avoid storing broken patterns
            if ( '' !== $new_field['validation_regex'] ) {
                $test_regex = '@' . str_replace( '@', '\@', $new_field['validation_regex'] ) . '@u';
                if ( false === @preg_match( $test_regex, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                    wp_die( esc_html( 'El patrón de validación (regex) ingresado no es válido. Por favor revisalo e intentá nuevamente.' ) );
                }
            }

            if ( isset( $_POST['wbi_cf_index'] ) ) {
                $idx = intval( $_POST['wbi_cf_index'] );
                if ( isset( $fields[ $idx ] ) ) {
                    $fields[ $idx ] = $new_field;
                }
            } else {
                $fields[] = $new_field;
            }

            update_option( 'wbi_custom_fields_config', $fields );
            wp_safe_redirect( admin_url( 'admin.php?page=wbi-custom-fields&saved=1' ) );
            exit;
        }

        if ( 'delete' === $action ) {
            $idx = intval( $_GET['wbi_cf_index'] ?? -1 );
            if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wbi_cf_delete_' . $idx ) ) {
                wp_die( 'Nonce inválido.' );
            }
            $fields = $this->fields_config;
            if ( isset( $fields[ $idx ] ) ) {
                array_splice( $fields, $idx, 1 );
                update_option( 'wbi_custom_fields_config', $fields );
            }
            wp_safe_redirect( admin_url( 'admin.php?page=wbi-custom-fields&deleted=1' ) );
            exit;
        }
    }

    // =========================================================================
    // REGISTRO — renderizado, validación y guardado
    // =========================================================================

    public function render_registration_fields() {
        foreach ( $this->fields_config as $field ) {
            $loc = $field['location'] ?? 'both';
            if ( 'checkout' === $loc ) continue;
            $this->render_field_html( $field, 'register' );
        }
    }

    /**
     * @param WP_Error $errors
     * @param string   $username
     * @param string   $email
     * @return WP_Error
     */
    public function validate_registration_fields( $errors, $username, $email ) {
        foreach ( $this->fields_config as $field ) {
            $loc = $field['location'] ?? 'both';
            if ( 'checkout' === $loc ) continue;

            $key   = sanitize_key( $field['key'] );
            $value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
            $error = $this->validate_field_value( $field, $value );
            if ( $error ) {
                $errors->add( 'wbi_cf_' . $key, $error );
            }
        }
        return $errors;
    }

    public function save_registration_fields( $customer_id ) {
        foreach ( $this->fields_config as $field ) {
            $loc = $field['location'] ?? 'both';
            if ( 'checkout' === $loc ) continue;

            $key   = sanitize_key( $field['key'] );
            $value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
            if ( '' !== $value ) {
                update_user_meta( $customer_id, $key, $value );
            }
        }
    }

    // =========================================================================
    // CHECKOUT — campos, validación y guardado
    // =========================================================================

    public function add_checkout_fields( $fields ) {
        foreach ( $this->fields_config as $field ) {
            $loc = $field['location'] ?? 'both';
            if ( 'register' === $loc ) continue;

            $key  = sanitize_key( $field['key'] );
            $type = $field['type'];

            // WooCommerce soporta tipos básicos; select y radio se añaden como text con opciones
            $wc_type = in_array( $type, array( 'text', 'number', 'email', 'tel', 'password' ), true ) ? $type : 'text';

            $field_args = array(
                'label'       => $field['label'],
                'placeholder' => $field['placeholder'] ?? '',
                'required'    => ! empty( $field['required'] ),
                'class'       => array( 'form-row-wide' ),
                'priority'    => 120,
                'type'        => $wc_type,
            );

            // Para select/radio, usamos el tipo 'select' de WC
            if ( in_array( $type, array( 'select', 'radio' ), true ) ) {
                $options_list = $this->parse_options( $field['options'] ?? '' );
                if ( ! empty( $options_list ) ) {
                    $field_args['type']    = 'select';
                    $field_args['options'] = $options_list;
                }
            }

            $fields['billing'][ $key ] = $field_args;
        }
        return $fields;
    }

    public function validate_checkout_fields() {
        foreach ( $this->fields_config as $field ) {
            $loc = $field['location'] ?? 'both';
            if ( 'register' === $loc ) continue;

            $key   = sanitize_key( $field['key'] );
            $value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
            $error = $this->validate_field_value( $field, $value );
            if ( $error ) {
                wc_add_notice( $error, 'error' );
            }
        }
    }

    public function save_checkout_order_meta( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $values = array();
        foreach ( $this->fields_config as $field ) {
            $loc = $field['location'] ?? 'both';
            if ( 'register' === $loc ) continue;

            $key   = sanitize_key( $field['key'] );
            $value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
            if ( '' !== $value ) {
                $values[ $key ] = $value;
                $order->update_meta_data( $key, $value );
            }
        }
        $order->save();

        // Also save to user meta if the order has a logged-in customer.
        // This ensures user meta is saved even with block-based checkout,
        // where woocommerce_checkout_update_user_meta may not fire.
        $customer_id = $order->get_customer_id();
        if ( $customer_id ) {
            foreach ( $values as $key => $value ) {
                update_user_meta( $customer_id, $key, $value );
            }
        }
    }

    public function save_checkout_user_meta( $customer_id, $data ) {
        if ( ! $customer_id ) return;
        foreach ( $this->fields_config as $field ) {
            $loc = $field['location'] ?? 'both';
            if ( 'register' === $loc ) continue;

            $key   = sanitize_key( $field['key'] );
            $value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
            if ( '' !== $value ) {
                update_user_meta( $customer_id, $key, $value );
            }
        }
    }

    // =========================================================================
    // ADMIN — visualización en orden y emails
    // =========================================================================

    public function display_in_order_admin( $order ) {
        $has_data = false;
        $output   = '<div style="margin-top:10px;">';
        foreach ( $this->fields_config as $field ) {
            $loc = $field['location'] ?? 'both';
            if ( 'register' === $loc ) continue;

            $key   = sanitize_key( $field['key'] );
            $value = $order->get_meta( $key, true );
            if ( '' !== $value ) {
                $has_data = true;
                $output  .= '<p><strong>' . esc_html( $field['label'] ) . ':</strong> ' . esc_html( $value ) . '</p>';
            }
        }
        $output .= '</div>';
        if ( $has_data ) {
            echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    public function display_in_email( $order, $sent_to_admin, $plain_text, $email ) {
        $has_data = false;
        $rows     = '';
        foreach ( $this->fields_config as $field ) {
            $loc = $field['location'] ?? 'both';
            if ( 'register' === $loc ) continue;

            $key   = sanitize_key( $field['key'] );
            $value = $order->get_meta( $key, true );
            if ( '' !== $value ) {
                $has_data = true;
                if ( $plain_text ) {
                    $rows .= esc_html( $field['label'] ) . ': ' . esc_html( $value ) . "\n";
                } else {
                    $rows .= '<tr><td style="padding:6px 0; color:#636363;"><strong>' . esc_html( $field['label'] ) . ':</strong></td>'
                           . '<td style="padding:6px 0;">' . esc_html( $value ) . '</td></tr>';
                }
            }
        }

        if ( $has_data ) {
            if ( $plain_text ) {
                echo "\n--- Campos adicionales ---\n" . $rows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } else {
                echo '<h2>' . esc_html__( 'Campos adicionales', 'wbi' ) . '</h2>'
                   . '<table style="width:100%; border-collapse:collapse;"><tbody>' . $rows . '</tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
        }
    }

    // =========================================================================
    // TOGGLE JS
    // =========================================================================

    /**
     * Si hay campos tipo select/radio con sub-campos dependientes, emite
     * el JavaScript de toggle. Activo en páginas de registro y checkout.
     */
    public function render_toggle_js() {
        if ( ! ( is_account_page() || is_checkout() ) ) return;

        $toggle_fields = array();
        foreach ( $this->fields_config as $field ) {
            if ( in_array( $field['type'], array( 'select', 'radio' ), true ) && ! empty( $field['options'] ) ) {
                $toggle_fields[] = sanitize_key( $field['key'] );
            }
        }

        if ( empty( $toggle_fields ) ) return;
        ?>
        <script>
        (function($) {
            'use strict';
            <?php foreach ( $toggle_fields as $key ) : ?>
            var $trigger_<?php echo esc_js( $key ); ?> = $('[name="<?php echo esc_js( $key ); ?>"]');
            $trigger_<?php echo esc_js( $key ); ?>.on('change', function() {
                var val = $(this).val();
                $('.wbi-cf-sub-<?php echo esc_js( $key ); ?>').hide();
                $('.wbi-cf-sub-<?php echo esc_js( $key ); ?>-' + val.toLowerCase()).show();
            }).trigger('change');
            <?php endforeach; ?>
        })(jQuery);
        </script>
        <?php
    }

    // =========================================================================
    // HELPERS INTERNOS
    // =========================================================================

    /**
     * Renderiza el HTML de un campo en el formulario de registro.
     *
     * @param array  $field  Definición del campo.
     * @param string $context 'register' o 'checkout'.
     */
    private function render_field_html( $field, $context ) {
        $key         = sanitize_key( $field['key'] );
        $type        = $field['type'];
        $label       = $field['label'];
        $placeholder = $field['placeholder'] ?? '';
        $required    = ! empty( $field['required'] );
        $value       = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
        $req_attr    = $required ? ' required' : '';
        $req_mark    = $required ? ' <abbr title="Obligatorio">*</abbr>' : '';

        echo '<p class="woocommerce-FormRow woocommerce-FormRow--wide form-row form-row-wide">';
        echo '<label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . $req_mark . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        if ( 'select' === $type ) {
            $options_list = $this->parse_options( $field['options'] ?? '' );
            echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" class="woocommerce-Input"' . $req_attr . '>';
            echo '<option value="">' . esc_html( $placeholder ?: '— Seleccioná —' ) . '</option>';
            foreach ( $options_list as $val => $lbl ) {
                echo '<option value="' . esc_attr( $val ) . '"' . selected( $value, $val, false ) . '>' . esc_html( $lbl ) . '</option>';
            }
            echo '</select>';
        } elseif ( 'radio' === $type ) {
            $options_list = $this->parse_options( $field['options'] ?? '' );
            foreach ( $options_list as $val => $lbl ) {
                echo '<label style="display:inline-block;margin-right:16px;">'
                   . '<input type="radio" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val ) . '"'
                   . checked( $value, $val, false ) . $req_attr . '> '
                   . esc_html( $lbl ) . '</label>';
            }
        } else {
            echo '<input type="' . esc_attr( $type ) . '"'
               . ' id="' . esc_attr( $key ) . '"'
               . ' name="' . esc_attr( $key ) . '"'
               . ' class="woocommerce-Input woocommerce-Input--text input-text"'
               . ' placeholder="' . esc_attr( $placeholder ) . '"'
               . ' value="' . esc_attr( $value ) . '"'
               . $req_attr . '>';
        }

        echo '</p>';
    }

    /**
     * Valida el valor de un campo según sus reglas configuradas.
     *
     * @param array  $field  Definición del campo.
     * @param string $value  Valor enviado por el usuario.
     * @return string|null   Mensaje de error o null si es válido.
     */
    private function validate_field_value( $field, $value ) {
        $label    = $field['label'];
        $required = ! empty( $field['required'] );
        $unique   = ! empty( $field['unique'] );

        if ( $required && '' === trim( $value ) ) {
            /* translators: %s: field label */
            return sprintf( 'El campo <strong>%s</strong> es obligatorio.', esc_html( $label ) );
        }

        if ( '' === trim( $value ) ) {
            return null; // Campo vacío y no es obligatorio — sin más validaciones.
        }

        // Validación de longitud mínima
        $min = intval( $field['min_length'] ?? 0 );
        if ( $min > 0 && mb_strlen( $value ) < $min ) {
            return sprintf( 'El campo <strong>%s</strong> debe tener al menos %d caracteres.', esc_html( $label ), $min );
        }

        // Validación de longitud máxima
        $max = intval( $field['max_length'] ?? 0 );
        if ( $max > 0 && mb_strlen( $value ) > $max ) {
            return sprintf( 'El campo <strong>%s</strong> no puede tener más de %d caracteres.', esc_html( $label ), $max );
        }

        // Validación de formato (regex)
        $regex = $field['validation_regex'] ?? '';
        if ( '' !== $regex ) {
            $safe_regex = '@' . str_replace( '@', '\@', $regex ) . '@u';
            $match      = @preg_match( $safe_regex, $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            if ( false === $match ) {
                // Regex inválido guardado — tratar como sin restricción de formato.
                $match = 1;
            }
            if ( 0 === $match ) {
                return sprintf( 'El valor ingresado en <strong>%s</strong> no tiene el formato correcto.', esc_html( $label ) );
            }
        }

        // Validación de duplicados
        if ( $unique ) {
            $key            = sanitize_key( $field['key'] );
            $exclude_user   = 0;
            if ( is_user_logged_in() ) {
                $exclude_user = get_current_user_id();
            }
            $existing = get_users( array(
                'meta_key'     => $key,
                'meta_value'   => $value,
                'number'       => 1,
                'exclude'      => $exclude_user ? array( $exclude_user ) : array(),
                'fields'       => 'ids',
            ) );
            if ( ! empty( $existing ) ) {
                return sprintf( 'El valor ingresado en <strong>%s</strong> ya está en uso por otra cuenta.', esc_html( $label ) );
            }
        }

        return null;
    }

    /**
     * Convierte una cadena "Opción1,Opción2,Opción3" en array asociativo.
     *
     * @param string $options_str
     * @return array  key => label (ambos iguales)
     */
    private function parse_options( $options_str ) {
        if ( '' === trim( $options_str ) ) return array();
        $parts  = array_filter( array_map( 'trim', explode( ',', $options_str ) ) );
        $result = array();
        foreach ( $parts as $part ) {
            $result[ $part ] = $part;
        }
        return $result;
    }

    // =========================================================================
    // PERFIL DE USUARIO (wp-admin) — mostrar y guardar campos personalizados
    // =========================================================================

    /**
     * Muestra los campos personalizados en el perfil del usuario (wp-admin).
     *
     * @param WP_User $user El usuario cuyo perfil se está viendo/editando.
     */
    public function display_user_profile_fields( $user ) {
        $fields = $this->fields_config;
        if ( empty( $fields ) ) return;

        echo '<h3>📋 Campos Personalizados WBI</h3>';
        echo '<table class="form-table"><tbody>';

        foreach ( $fields as $field ) {
            $key   = sanitize_key( $field['key'] );
            $value = get_user_meta( $user->ID, $key, true );
            $label = esc_html( $field['label'] );

            echo '<tr>';
            echo '<th><label for="' . esc_attr( $key ) . '">' . $label . '</label></th>';
            echo '<td>';

            $type = $field['type'] ?? 'text';

            if ( 'select' === $type ) {
                $options_list = $this->parse_options( $field['options'] ?? '' );
                echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" class="regular-text">';
                echo '<option value="">— Seleccioná —</option>';
                foreach ( $options_list as $opt_val => $opt_lbl ) {
                    echo '<option value="' . esc_attr( $opt_val ) . '"' . selected( $value, $opt_val, false ) . '>' . esc_html( $opt_lbl ) . '</option>';
                }
                echo '</select>';
            } elseif ( 'radio' === $type ) {
                $options_list = $this->parse_options( $field['options'] ?? '' );
                foreach ( $options_list as $opt_val => $opt_lbl ) {
                    echo '<label style="margin-right:16px;">';
                    echo '<input type="radio" name="' . esc_attr( $key ) . '" value="' . esc_attr( $opt_val ) . '"' . checked( $value, $opt_val, false ) . '> ';
                    echo esc_html( $opt_lbl ) . '</label>';
                }
            } else {
                echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="regular-text">';
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Guarda los campos personalizados desde el perfil del usuario (wp-admin).
     *
     * @param int $user_id ID del usuario.
     */
    public function save_user_profile_fields( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) return;
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-user_' . $user_id ) ) return;

        foreach ( $this->fields_config as $field ) {
            $key = sanitize_key( $field['key'] );
            if ( isset( $_POST[ $key ] ) ) {
                $value = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
                update_user_meta( $user_id, $key, $value );
            }
        }
    }
}
