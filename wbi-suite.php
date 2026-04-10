<?php
/**
 * Plugin Name: wooErp — Suite de Gestión para WooCommerce
 * Description: Suite modular de gestión integral: B2B, BI, Stock, Facturación, Picking y más.
 * Version: 9.0.18
 * Author: Rodrigo Castañera
 */


if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Suite_Loader {

    private $options;

    public function __construct() {
        // Cargar opciones guardadas en la base de datos
        $this->options = get_option( 'wbi_modules_settings' );

        // Process license actions
        add_action( 'admin_init', array( $this, 'handle_license_action' ) );

        // Admin Menu para Configuración (Aparece bajo WooCommerce)
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // Admin notice and redirect when license is not active
        add_action( 'admin_notices', array( $this, 'license_admin_notice' ) );
        add_action( 'admin_init', array( $this, 'maybe_redirect_to_license' ) );

        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_css' ) );

        // Ensure the armador role exists
        add_action( 'init', array( $this, 'ensure_armador_role' ) );

        // Cargar Módulos Activos según configuración
        $this->load_modules();
    }

    public function load_modules() {
        // ALWAYS load the license manager first
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-license.php';

        // If license is NOT active, don't load any modules
        if ( ! WBI_License_Manager::is_active() ) {
            return; // Stop here — only the license activation page will show
        }

        // Módulo de Campos de Registro (Provincia, Localidad, Teléfono) — siempre activo
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-registration-fields.php';
        new WBI_Registration_Fields();

        // 1. Módulo B2B (Roles, Precios Ocultos, Aprobación)
        if ( ! empty( $this->options['wbi_enable_b2b'] ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-b2b.php';
            new WBI_B2B_Module();
        }

        // 2. Módulo de Datos (Campos extra: Origen de Venta, Taxonomías)
        if ( ! empty( $this->options['wbi_enable_data'] ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-data.php';
            new WBI_Data_Module();
        }

        // 3. Suite de Métricas & Reportes
        if ( ! empty( $this->options['wbi_enable_dashboard'] ) ) {
            
            // A. Motor de Cálculos (Base para todo lo demás)
            require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-metrics.php';
            
            // B. Dashboard Principal (Resumen Ejecutivo)
            require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-dashboard.php';
            new WBI_Dashboard_View();
            
            // C. Módulo de Exportación CSV
            require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-export.php';
            new WBI_Export_Module();

            // D. Reporte Detallado: VENTAS
            if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-report-sales.php' ) ) {
                require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-report-sales.php';
                new WBI_Report_Sales();
            }

            // E. Reporte Detallado: CLIENTES
            if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-report-clients.php' ) ) {
                require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-report-clients.php';
                new WBI_Report_Clients();
            }

            // F. Reporte Detallado: PRODUCTOS & STOCK
            if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-report-products.php' ) ) {
                require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-report-products.php';
                new WBI_Report_Products();
            }

            // G. Sistema de Alertas de Stock
            if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-stock-alerts.php' ) ) {
                require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-stock-alerts.php';
                new WBI_Stock_Alerts();
            }

            // H. Reportes Automáticos por Email
            if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-email-reports.php' ) ) {
                require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-email-reports.php';
                new WBI_Email_Reports();
            }

            // I. Módulo de Costos y Márgenes
            if ( ! empty( $this->options['wbi_enable_costs'] ) ) {
                if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-costs.php' ) ) {
                    require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-costs.php';
                    new WBI_Costs_Module();
                }
            }

            // J. Módulo de Proveedores
            if ( ! empty( $this->options['wbi_enable_suppliers'] ) ) {
                if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-suppliers.php' ) ) {
                    require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-suppliers.php';
                    new WBI_Suppliers_Module();
                }
            }

            // J2. Módulo de Órdenes de Compra
            if ( ! empty( $this->options['wbi_enable_purchase'] ) ) {
                if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-purchase.php' ) ) {
                    require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-purchase.php';
                    new WBI_Purchase_Module();
                }
            }

            // K. Módulo de Scoring de Clientes
            if ( ! empty( $this->options['wbi_enable_scoring'] ) ) {
                if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-scoring.php' ) ) {
                    require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-scoring.php';
                    new WBI_Scoring_Module();
                }
            }

            // L. (merged into O+L — see Módulo Unificado de Documentos below)

            // M. Módulo de Impuestos Avanzado
            if ( ! empty( $this->options['wbi_enable_taxes'] ) ) {
                if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-taxes.php' ) ) {
                    require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-taxes.php';
                    new WBI_Taxes_Module();
                }
            }

            // N. Módulo de Flujo de Caja
            if ( ! empty( $this->options['wbi_enable_cashflow'] ) ) {
                if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-cashflow.php' ) ) {
                    require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-cashflow.php';
                    new WBI_Cashflow_Module();
                }
            }

            // O+L. Módulo Unificado de Documentos (Facturación + Remitos)
            if ( ! empty( $this->options['wbi_enable_invoice'] ) || ! empty( $this->options['wbi_enable_remitos'] ) ) {
                if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-documents.php' ) ) {
                    require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-documents.php';
                    new WBI_Documents_Module();
                }
            }

            // R. Módulo de Notas de Crédito / Débito
            if ( ! empty( $this->options['wbi_enable_credit_notes'] ) ) {
                if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-credit-notes.php' ) ) {
                    require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-credit-notes.php';
                    new WBI_Credit_Notes_Module();
                }
            }

            // P. Centro de Notificaciones
            if ( ! empty( $this->options['wbi_enable_notifications'] ) ) {
                if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-notifications.php' ) ) {
                    require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-notifications.php';
                    new WBI_Notifications_Module();
                }
            }

            // Q. API REST
            if ( ! empty( $this->options['wbi_enable_api'] ) ) {
                if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-api.php' ) ) {
                    require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-api.php';
                    new WBI_API_Module();
                }
            }

            // R. Reportes Contables
            if ( ! empty( $this->options['wbi_enable_accounting_reports'] ) ) {
                if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-accounting-reports.php' ) ) {
                    require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-accounting-reports.php';
                    new WBI_Accounting_Reports_Module();
                }
            }
        }

        // 4. Módulo de Códigos de Barra
        if ( ! empty( $this->options['wbi_enable_barcode'] ) ) {
            if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-barcode.php' ) ) {
                require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-barcode.php';
                new WBI_Barcode_Module();
            }
        }

        // 5. Módulo de Picking & Armado de Pedidos
        if ( ! empty( $this->options['wbi_enable_picking'] ) ) {
            if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-picking.php' ) ) {
                require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-picking.php';
                new WBI_Picking_Module();
            }
        }

        // 6. Módulo de Listas de Precios
        if ( ! empty( $this->options['wbi_enable_pricelists'] ) ) {
            if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-pricelists.php' ) ) {
                require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-pricelists.php';
                new WBI_Pricelists_Module();
            }
        }

        // 7. Módulo de WhatsApp
        if ( ! empty( $this->options['wbi_enable_whatsapp'] ) ) {
            if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-whatsapp.php' ) ) {
                require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-whatsapp.php';
                new WBI_Whatsapp_Module();
            }
        }

        // S. Módulo de Carritos Abandonados
        if ( ! empty( $this->options['wbi_enable_abandoned_carts'] ) ) {
            if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-abandoned-carts.php' ) ) {
                require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-abandoned-carts.php';
                new WBI_Abandoned_Carts_Module();
            }
        }

        // 8. Módulo de Validación de Checkout (CP vs Provincia)
        if ( ! empty( $this->options['wbi_enable_checkout_validator'] ) ) {
            if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-checkout-validator.php' ) ) {
                require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-checkout-validator.php';
                new WBI_Checkout_Validator();
            }
        }

        // R2. Módulo de Email Marketing
        if ( ! empty( $this->options['wbi_enable_email_marketing'] ) ) {
            if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-email-marketing.php' ) ) {
                require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-email-marketing.php';
                new WBI_Email_Marketing_Module();
            }
        }

        // 20. Módulo de Reglas de Reabastecimiento
        if ( ! empty( $this->options['wbi_enable_reorder'] ) ) {
            if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-reorder.php' ) ) {
                require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-reorder.php';
                new WBI_Reorder_Module();
            }
        }

        // 19. Módulo CRM / Pipeline de Ventas
        if ( ! empty( $this->options['wbi_enable_crm'] ) ) {
            if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-crm.php' ) ) {
                require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-crm.php';
                new WBI_CRM_Module();
            }
        }

        // 21. Módulo de Campos Personalizados de Registro/Checkout
        if ( ! empty( $this->options['wbi_enable_custom_fields'] ) ) {
            if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-custom-fields.php' ) ) {
                require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-custom-fields.php';
                new WBI_Custom_Fields_Module();
            }
        }

        // 22. Módulo de Empleados / RRHH
        if ( ! empty( $this->options['wbi_enable_employees'] ) ) {
            if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-employees.php' ) ) {
                require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-employees.php';
                new WBI_Employees_Module();
            }
        }
    }

    // --- CONFIGURACIÓN EN WP-ADMIN ---

    public function add_settings_page() {
        // License page — always visible
        add_menu_page(
            'wooErp',
            'wooErp',
            'manage_options',
            'wbi-license',
            array( $this, 'render_license_page' ),
            'dashicons-lock',
            58
        );

        // Only show config submenu if license is active
        if ( WBI_License_Manager::is_active() ) {
            add_submenu_page(
                'woocommerce',
                'wooErp Config',
                'wooErp Config',
                'manage_options',
                'wbi-settings',
                array( $this, 'render_settings_page' )
            );
        }
    }

    public function handle_license_action() {
        if ( ! isset( $_POST['wbi_license_action'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! wp_verify_nonce( $_POST['_wbi_license_nonce'] ?? '', 'wbi_license_nonce' ) ) return;

        $action = sanitize_text_field( $_POST['wbi_license_action'] );

        if ( $action === 'activate' ) {
            $key = sanitize_text_field( isset( $_POST['wbi_license_key'] ) ? $_POST['wbi_license_key'] : '' );
            if ( WBI_License_Manager::activate( $key ) ) {
                add_settings_error( 'wbi_license', 'activated', '✅ Licencia activada correctamente. ¡Bienvenido a wooErp!', 'success' );
            } else {
                add_settings_error( 'wbi_license', 'invalid', '❌ Clave de licencia inválida. Verificá el formato e intentá nuevamente.', 'error' );
            }
        } elseif ( $action === 'deactivate' ) {
            WBI_License_Manager::deactivate();
            add_settings_error( 'wbi_license', 'deactivated', '🔓 Licencia desactivada.', 'updated' );
        }
    }

    public function license_admin_notice() {
        if ( WBI_License_Manager::is_active() ) {
            // Show warning when license is about to expire (7 days or less)
            $plan_info = WBI_License_Manager::get_plan_info();
            if ( $plan_info['days_remaining'] > 0 && $plan_info['days_remaining'] <= 7 ) {
                $license_url = admin_url( 'admin.php?page=wbi-license' );
                echo '<div class="notice notice-warning is-dismissible">';
                echo '<p><strong>⚠️ wooErp:</strong> Tu licencia vence en <strong>' . intval( $plan_info['days_remaining'] ) . ' días</strong>. ';
                echo '<a href="' . esc_url( $license_url ) . '">Renovar aquí</a>.</p>';
                echo '</div>';
            }
            return;
        }

        // Only show to admins
        if ( ! current_user_can( 'manage_options' ) ) return;

        $license_url = admin_url( 'admin.php?page=wbi-license' );

        if ( WBI_License_Manager::is_expired() ) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>⏰ wooErp:</strong> Tu licencia ha expirado. Los módulos están desactivados. ';
            echo '<a href="' . esc_url( $license_url ) . '">Renovar licencia</a>.</p>';
            echo '</div>';
        } else {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>🔒 wooErp:</strong> El plugin requiere una licencia válida para funcionar. ';
            echo '<a href="' . esc_url( $license_url ) . '">Activar licencia aquí</a>.</p>';
            echo '</div>';
        }
    }

    public function maybe_redirect_to_license() {
        if ( WBI_License_Manager::is_active() ) return;

        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

        // If trying to access any WBI page that's not the license page
        if ( strpos( $page, 'wbi-' ) === 0 && $page !== 'wbi-license' ) {
            wp_redirect( admin_url( 'admin.php?page=wbi-license' ) );
            exit;
        }
    }

    public function render_license_page() {
        $is_active  = WBI_License_Manager::is_active();
        $is_expired = WBI_License_Manager::is_expired();
        ?>
        <div class="wrap">
            <h1>🔐 wooErp — Licencia</h1>
            <?php settings_errors( 'wbi_license' ); ?>

            <div style="background:#fff; padding:30px; border:1px solid #c3c4c7; max-width:600px; margin-top:20px;">

                <?php if ( $is_active ) : ?>
                    <!-- LICENSE ACTIVE STATE -->
                    <?php $plan_info = WBI_License_Manager::get_plan_info(); ?>
                    <div style="text-align:center; padding:20px 0;">
                        <div style="font-size:48px; margin-bottom:10px;">✅</div>
                        <h2 style="color:#00a32a; margin:0;">Licencia Activa</h2>
                        <p style="color:#50575e; font-size:14px;">
                            Clave: <code><?php echo esc_html( WBI_License_Manager::get_masked_key() ); ?></code>
                        </p>
                        <p style="font-size:16px;">
                            <?php echo esc_html( $plan_info['emoji'] ); ?> Plan: <strong><?php echo esc_html( $plan_info['name'] ); ?></strong>
                        </p>
                        <p style="color:#50575e;">
                            Activada: <?php echo esc_html( get_option( 'wbi_license_activated_at', 'N/A' ) ); ?>
                        </p>
                        <?php if ( $plan_info['days_remaining'] === -1 ) : ?>
                            <p style="color:#00a32a; font-weight:bold;">♾️ Licencia de por vida — Nunca expira</p>
                        <?php else : ?>
                            <p style="color:<?php echo esc_attr( $plan_info['days_remaining'] <= 7 ? '#d63638' : '#50575e' ); ?>;">
                                Vence: <strong><?php echo esc_html( $plan_info['expires_at'] ); ?></strong>
                                (<?php echo intval( $plan_info['days_remaining'] ); ?> días restantes)
                            </p>
                        <?php endif; ?>
                    </div>

                    <hr>

                    <p>Todos los módulos están habilitados. Podés configurarlos desde
                       <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-settings' ) ); ?>">wooErp Config</a>.
                    </p>

                    <form method="post" style="margin-top:15px;">
                        <?php wp_nonce_field( 'wbi_license_nonce', '_wbi_license_nonce' ); ?>
                        <input type="hidden" name="wbi_license_action" value="deactivate">
                        <button type="submit" class="button"
                                onclick="return confirm('¿Estás seguro? Se desactivará la licencia y los módulos dejarán de funcionar.');">
                            🔓 Desactivar Licencia
                        </button>
                    </form>

                <?php elseif ( $is_expired ) : ?>
                    <?php $plan_info = WBI_License_Manager::get_plan_info(); ?>
                    <!-- LICENSE EXPIRED STATE -->
                    <div style="text-align:center; padding:20px 0;">
                        <div style="font-size:48px; margin-bottom:10px;">⏰</div>
                        <h2 style="color:#dba617; margin:0;">Licencia Expirada</h2>
                        <p style="color:#50575e; font-size:14px;">
                            Tu licencia <strong><?php echo esc_html( $plan_info['name'] ); ?></strong> venció el
                            <strong><?php echo esc_html( $plan_info['expires_at'] ); ?></strong>.
                        </p>
                        <p style="color:#50575e;">
                            Contactá al desarrollador para renovar tu licencia.
                        </p>
                    </div>

                    <hr>

                    <form method="post">
                        <?php wp_nonce_field( 'wbi_license_nonce', '_wbi_license_nonce' ); ?>
                        <input type="hidden" name="wbi_license_action" value="activate">

                        <table class="form-table">
                            <tr>
                                <th><label for="wbi_license_key">Nueva Clave de Licencia</label></th>
                                <td>
                                    <input type="text" id="wbi_license_key" name="wbi_license_key"
                                           placeholder="WBI-XXXX-XXXX-XXXX-XXXX"
                                           class="regular-text"
                                           style="font-family:monospace; font-size:16px; letter-spacing:1px; text-transform:uppercase;"
                                           maxlength="23"
                                           required>
                                    <p class="description">Ingresá tu nueva clave para renovar.</p>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" class="button button-primary button-hero">
                                🔑 Renovar Licencia
                            </button>
                        </p>
                    </form>

                    <form method="post" style="margin-top:10px;">
                        <?php wp_nonce_field( 'wbi_license_nonce', '_wbi_license_nonce' ); ?>
                        <input type="hidden" name="wbi_license_action" value="deactivate">
                        <button type="submit" class="button button-link-delete">
                            Eliminar licencia expirada
                        </button>
                    </form>

                <?php else : ?>
                    <!-- LICENSE INACTIVE STATE -->
                    <div style="text-align:center; padding:20px 0;">
                        <div style="font-size:48px; margin-bottom:10px;">🔒</div>
                        <h2 style="color:#d63638; margin:0;">Licencia Requerida</h2>
                        <p style="color:#50575e; font-size:14px;">
                            Ingresá tu clave de licencia para activar wooErp y acceder a todos los módulos.
                        </p>
                    </div>

                    <form method="post">
                        <?php wp_nonce_field( 'wbi_license_nonce', '_wbi_license_nonce' ); ?>
                        <input type="hidden" name="wbi_license_action" value="activate">

                        <table class="form-table">
                            <tr>
                                <th><label for="wbi_license_key">Clave de Licencia</label></th>
                                <td>
                                    <input type="text" id="wbi_license_key" name="wbi_license_key"
                                           placeholder="WBI-XXXX-XXXX-XXXX-XXXX"
                                           class="regular-text"
                                           style="font-family:monospace; font-size:16px; letter-spacing:1px; text-transform:uppercase;"
                                           maxlength="23"
                                           required>
                                    <p class="description">Formato: WBI-XXXX-XXXX-XXXX-XXXX</p>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" class="button button-primary button-hero">
                                🔑 Activar Licencia
                            </button>
                        </p>
                    </form>

                    <hr>
                    <p style="color:#50575e; font-size:12px;">
                        ¿No tenés una licencia? Contactá al desarrollador para adquirir una.<br>
                        <strong>wooErp</strong> — Suite de Gestión para WooCommerce.
                    </p>

                <?php endif; ?>
            </div>
        </div>
        <?php
        // Secret key generator for the plugin author
        if ( isset( $_GET['wbi_gen'] ) && $_GET['wbi_gen'] === 'castanera2026' && current_user_can( 'manage_options' ) ) {
            $gen_plan    = isset( $_GET['plan'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['plan'] ) ) ) : 'LF';
            $valid_plans = array( 'T3', 'M1', 'A1', 'LF' );
            if ( ! in_array( $gen_plan, $valid_plans, true ) ) {
                $gen_plan = 'LF';
            }

            $new_key   = WBI_License_Manager::generate_key( $gen_plan );
            $plans     = WBI_License_Manager::get_plans();
            $plan_info = $plans[ $gen_plan ];

            echo '<div style="background:#fef8e7; border:1px solid #d4a900; padding:15px; margin-top:20px; max-width:600px;">';
            echo '<h3>🔑 Generador de Claves (Herramienta del Desarrollador)</h3>';

            echo '<p><strong>Seleccionar plan:</strong></p>';
            echo '<div style="display:flex; gap:8px; margin-bottom:15px; flex-wrap:wrap;">';
            $base_url = admin_url( 'admin.php?page=wbi-license&wbi_gen=castanera2026' );
            foreach ( $plans as $p_code => $p_info ) {
                $is_selected = ( $p_code === $gen_plan );
                $style       = $is_selected ? 'background:#0073aa; color:#fff; border-color:#0073aa;' : '';
                echo '<a href="' . esc_url( $base_url . '&plan=' . $p_code ) . '" class="button" style="' . esc_attr( $style ) . '">';
                echo esc_html( $p_info['emoji'] . ' ' . $p_info['name'] );
                if ( $p_info['days'] > 0 ) {
                    echo ' (' . intval( $p_info['days'] ) . 'd)';
                }
                echo '</a>';
            }
            echo '</div>';

            echo '<p>Plan seleccionado: <strong>' . esc_html( $plan_info['emoji'] . ' ' . $plan_info['name'] ) . '</strong></p>';
            echo '<p>Clave generada:</p>';
            echo '<input type="text" value="' . esc_attr( $new_key ) . '" class="regular-text" style="font-family:monospace; font-size:18px;" readonly onclick="this.select();">';
            echo '<p class="description">Copiá esta clave y entrégala a tu cliente.</p>';
            echo '</div>';
        }
    }

    public function enqueue_admin_css( $hook ) {
        // Load admin CSS only on WBI admin pages.
        $is_wbi_page = ( 'toplevel_page_wbi-dashboard-view' === $hook )
            || ( strpos( $hook, '_page_wbi-' ) !== false )
            || ( strpos( $hook, 'woocommerce_page_wbi-' ) !== false );

        if ( ! $is_wbi_page ) {
            return;
        }
        wp_enqueue_style(
            'wbi-admin',
            plugin_dir_url( __FILE__ ) . 'assets/admin.css',
            array(),
            '8.0.0'
        );
    }

    /**
     * Sanitize callback for wbi_modules_settings option.
     * Ensures the B2B URL field is properly sanitized.
     *
     * @param array $input Raw input array.
     * @return array Sanitized array.
     */
    public function sanitize_modules_settings( $input ) {
        if ( ! is_array( $input ) ) {
            return array();
        }
        // Sanitize B2B URL field specifically
        if ( isset( $input['wbi_b2b_hidden_price_url'] ) ) {
            $input['wbi_b2b_hidden_price_url'] = esc_url_raw( $input['wbi_b2b_hidden_price_url'] );
        }
        // Sanitize B2B notification email
        if ( isset( $input['wbi_b2b_notification_email'] ) ) {
            $input['wbi_b2b_notification_email'] = sanitize_email( $input['wbi_b2b_notification_email'] );
        }
        // Sanitize B2B authorized roles (array of role slugs)
        if ( isset( $input['wbi_b2b_authorized_roles'] ) && is_array( $input['wbi_b2b_authorized_roles'] ) ) {
            $input['wbi_b2b_authorized_roles'] = array_map( 'sanitize_key', $input['wbi_b2b_authorized_roles'] );
        } else {
            // When no checkboxes are checked, the field is absent from POST — default to empty array
            $input['wbi_b2b_authorized_roles'] = array();
        }
        return $input;
    }

    public function register_settings() {
        register_setting( 'wbi_group', 'wbi_modules_settings', array( 'sanitize_callback' => array( $this, 'sanitize_modules_settings' ) ) );
        
        add_settings_section( 'wbi_main_section', 'Módulos Disponibles', null, 'wbi-settings' );
        
        add_settings_field( 'wbi_enable_b2b', 'Modo Mayorista B2B', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_b2b'] );
        add_settings_field( 'wbi_enable_data', 'Modelo de Datos Extra (Origen)', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_data'] );
        add_settings_field( 'wbi_enable_dashboard', 'Suite de BI (Dashboard + Reportes + Stock)', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_dashboard'] );
        add_settings_field( 'wbi_enable_barcode', 'Módulo de Códigos de Barra', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_barcode'] );
        add_settings_field( 'wbi_enable_picking', 'Módulo de Picking & Armado', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_picking'] );
        add_settings_field( 'wbi_enable_costs', 'Módulo de Costos y Márgenes', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_costs'] );
        add_settings_field( 'wbi_enable_suppliers', 'Módulo de Proveedores', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_suppliers'] );
        add_settings_field( 'wbi_enable_purchase', 'Módulo de Órdenes de Compra', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_purchase'] );
        add_settings_field( 'wbi_enable_scoring', 'Módulo de Scoring de Clientes', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_scoring'] );
        add_settings_field( 'wbi_enable_remitos', 'Módulo de Remitos', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_remitos'] );
        add_settings_field( 'wbi_enable_pricelists', 'Módulo de Listas de Precios', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_pricelists'] );
        add_settings_field( 'wbi_enable_taxes', 'Módulo de Gestión de Impuestos', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_taxes'] );
        add_settings_field( 'wbi_enable_cashflow', 'Módulo de Flujo de Caja', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_cashflow'] );
        add_settings_field( 'wbi_enable_whatsapp', 'Módulo de Notificaciones WhatsApp', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_whatsapp'] );
        add_settings_field( 'wbi_enable_invoice', 'Módulo de Facturación AFIP', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_invoice'] );
        add_settings_field( 'wbi_enable_notifications', 'Centro de Notificaciones', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_notifications'] );
        add_settings_field( 'wbi_enable_api', 'API REST', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_api'] );
        add_settings_field( 'wbi_enable_abandoned_carts', 'Carritos Abandonados', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_abandoned_carts'] );
        add_settings_field( 'wbi_enable_checkout_validator', 'Validación de Checkout (CP vs Provincia)', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_checkout_validator'] );
        add_settings_field( 'wbi_enable_accounting_reports', 'Módulo de Reportes Contables', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_accounting_reports'] );
        add_settings_field( 'wbi_enable_credit_notes', 'Notas de Crédito / Débito', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_credit_notes'] );
        add_settings_field( 'wbi_enable_email_marketing', 'Email Marketing', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_email_marketing'] );
        add_settings_field( 'wbi_enable_reorder', 'Reglas de Reabastecimiento', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_reorder'] );
        add_settings_field( 'wbi_enable_crm', 'CRM / Pipeline de Ventas', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_crm'] );
        add_settings_field( 'wbi_enable_custom_fields', 'Campos Personalizados (Registro/Checkout)', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_custom_fields'] );
        add_settings_field( 'wbi_enable_employees', 'Módulo de Empleados / RRHH', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_employees'] );

        // B2B config fields (minimum order, hidden price text, registration URL)
        add_settings_field( 'wbi_b2b_minimum_order',    'B2B: Monto mínimo de compra',  array($this, 'number_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_b2b_minimum_order'] );
        add_settings_field( 'wbi_b2b_hidden_price_text','B2B: Texto precio oculto',      array($this, 'text_field'),   'wbi-settings', 'wbi_main_section', ['id' => 'wbi_b2b_hidden_price_text', 'default' => 'PRECIO MAYORISTA OCULTO'] );
        add_settings_field( 'wbi_b2b_hidden_price_url', 'B2B: URL registro mayorista',   array($this, 'text_field'),   'wbi-settings', 'wbi_main_section', ['id' => 'wbi_b2b_hidden_price_url'] );
        add_settings_field( 'wbi_b2b_notification_email', 'B2B: Email notificación solicitudes', array($this, 'text_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_b2b_notification_email'] );

        // Permissions section
        add_settings_section( 'wbi_permissions_section', 'Permisos por Módulo', array( $this, 'permissions_section_desc' ), 'wbi-settings' );

        $module_slugs = array(
            'wbi_enable_b2b'           => 'Modo Mayorista B2B',
            'wbi_enable_dashboard'     => 'Dashboard BI Suite',
            'wbi_enable_barcode'       => 'Códigos de Barra',
            'wbi_enable_picking'       => 'Picking & Armado',
            'wbi_enable_costs'         => 'Costos y Márgenes',
            'wbi_enable_suppliers'     => 'Proveedores',
            'wbi_enable_purchase'      => 'Órdenes de Compra',
            'wbi_enable_scoring'       => 'Scoring de Clientes',
            'wbi_enable_pricelists'    => 'Listas de Precios',
            'wbi_enable_cashflow'      => 'Flujo de Caja',
            'wbi_enable_taxes'         => 'Impuestos Avanzado',
            'wbi_enable_whatsapp'      => 'WhatsApp',
            'wbi_enable_notifications' => 'Notificaciones',
            'wbi_enable_api'           => 'API REST',
            // Virtual key for the unified documents module (invoice + remitos merged)
            'wbi_enable_documents'         => 'Documentos',
            'wbi_enable_checkout_validator'=> 'Validación de Checkout',
            'wbi_enable_accounting_reports'=> 'Reportes Contables',
            'wbi_enable_credit_notes'      => 'Notas de Crédito / Débito',
            'wbi_enable_email_marketing'   => 'Email Marketing',
            'wbi_enable_reorder'           => 'Reglas de Reabastecimiento',
            'wbi_enable_crm'               => 'CRM / Pipeline de Ventas',
            'wbi_enable_custom_fields'     => 'Campos Personalizados',
            'wbi_enable_employees'         => 'Empleados / RRHH',
        );

        foreach ( $module_slugs as $module_key => $module_name ) {
            $perm_id = 'wbi_permissions_' . str_replace( 'wbi_enable_', '', $module_key );
            add_settings_field(
                $perm_id,
                'Acceso: ' . $module_name,
                array( $this, 'permissions_field' ),
                'wbi-settings',
                'wbi_permissions_section',
                array( 'module_key' => $perm_id )
            );
        }
    }

    public function permissions_section_desc() {
        echo '<p>Seleccioná qué roles de WordPress pueden acceder a cada módulo. Por defecto, solo Administrador.</p>';
    }

    public function permissions_field( $args ) {
        $id       = $args['module_key'];
        $opts     = get_option( 'wbi_modules_settings', array() );
        $selected = isset( $opts[ $id ] ) ? (array) $opts[ $id ] : array( 'administrator' );
        $roles    = wp_roles()->roles;
        echo '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
        foreach ( $roles as $role_slug => $role_data ) {
            $checked = in_array( $role_slug, $selected, true ) ? 'checked' : '';
            echo '<label style="display:flex;align-items:center;gap:4px;">';
            echo '<input type="checkbox" name="wbi_modules_settings[' . esc_attr( $id ) . '][]" value="' . esc_attr( $role_slug ) . '" ' . $checked . '>';
            echo esc_html( $role_data['name'] );
            echo '</label>';
        }
        echo '</div>';
    }

    /**
     * Check if the current user can access a given module.
     *
     * @param string $module_slug The module slug with the 'wbi_enable_' prefix removed
     *                            (e.g. 'picking' for 'wbi_enable_picking').
     * @return bool
     */
    public function user_can_access_module( $module_slug ) {
        $user     = wp_get_current_user();
        $perm_key = 'wbi_permissions_' . $module_slug;
        $opts     = get_option( 'wbi_modules_settings', array() );
        // Fall back to administrator-only when option is unset OR saved as empty array.
        $permissions = ( isset( $opts[ $perm_key ] ) && ! empty( $opts[ $perm_key ] ) )
            ? (array) $opts[ $perm_key ]
            : array( 'administrator' );
        return (bool) array_intersect( $user->roles, $permissions );
    }

    public function checkbox_field( $args ) {
        $id = $args['id'];
        $checked = isset( $this->options[$id] ) ? checked( $this->options[$id], 1, false ) : '';
        echo "<input type='checkbox' name='wbi_modules_settings[$id]' value='1' $checked /> <b>Activar</b>";
    }

    public function number_field( $args ) {
        $id    = $args['id'];
        $value = isset( $this->options[ $id ] ) ? $this->options[ $id ] : '';
        echo "<input type='number' name='wbi_modules_settings[" . esc_attr( $id ) . "]' value='" . esc_attr( $value ) . "' min='0' step='0.01' style='width:120px;'>";
    }

    public function text_field( $args ) {
        $id      = $args['id'];
        $default = isset( $args['default'] ) ? $args['default'] : '';
        $value   = isset( $this->options[ $id ] ) ? $this->options[ $id ] : $default;
        echo "<input type='text' name='wbi_modules_settings[" . esc_attr( $id ) . "]' value='" . esc_attr( $value ) . "' style='width:300px;'>";
    }

    public function render_settings_page() {
        $opts          = $this->options ?: array();
        $active_count  = 0;
        $toggle_keys   = array(
            'wbi_enable_b2b','wbi_enable_data','wbi_enable_dashboard','wbi_enable_barcode',
            'wbi_enable_picking','wbi_enable_costs','wbi_enable_suppliers','wbi_enable_purchase','wbi_enable_scoring',
            'wbi_enable_remitos','wbi_enable_pricelists','wbi_enable_taxes','wbi_enable_cashflow',
            'wbi_enable_whatsapp','wbi_enable_invoice','wbi_enable_notifications','wbi_enable_api',
            'wbi_enable_abandoned_carts','wbi_enable_checkout_validator',
            'wbi_enable_accounting_reports','wbi_enable_credit_notes',
            'wbi_enable_email_marketing','wbi_enable_reorder','wbi_enable_crm',
            'wbi_enable_custom_fields','wbi_enable_employees',
        );
        $total_modules = count( $toggle_keys );
        foreach ( $toggle_keys as $k ) {
            if ( ! empty( $opts[ $k ] ) ) $active_count++;
        }

        $license_active = class_exists( 'WBI_License_Manager' ) && WBI_License_Manager::is_active();
        $version        = '9.0.18';

        // Module definitions: key, icon, name, description, page_slug, group
        $modules = array(
            array( 'wbi_enable_b2b',           '🏢', 'Modo Mayorista B2B',       'Roles mayoristas, precios ocultos y aprobación de clientes',              null,             'comercial'    ),
            array( 'wbi_enable_pricelists',     '💲', 'Listas de Precios',        'Listas de precios por cliente, rol o grupo',                              'wbi-pricelists', 'comercial'    ),
            array( 'wbi_enable_costs',          '💰', 'Costos y Márgenes',        'Costo de adquisición y cálculo de márgenes por producto',                 'wbi-costs',      'comercial'    ),
            array( 'wbi_enable_abandoned_carts','🛒', 'Carritos Abandonados',     'Recuperación de ventas perdidas con seguimiento por email y WhatsApp',    'wbi-abandoned-carts', 'comercial' ),
            array( 'wbi_enable_checkout_validator','📍', 'Validación de Checkout', 'Valida que el CP coincida con la provincia seleccionada en el checkout',   null,                  'comercial' ),
            array( 'wbi_enable_crm',               '🎯', 'CRM / Pipeline de Ventas', 'Pipeline de ventas tipo Kanban, leads, actividades y conversión a clientes', 'wbi-crm',             'comercial' ),
            array( 'wbi_enable_dashboard',      '📊', 'Dashboard BI Suite',       'Dashboard ejecutivo, reportes y alertas de stock',                        'wbi-dashboard-view', 'inteligencia' ),
            array( 'wbi_enable_scoring',        '⭐', 'Scoring de Clientes',      'Scoring RFM de clientes con recálculo automático diario',                 'wbi-scoring',    'inteligencia' ),
            array( 'wbi_enable_barcode',        '📊', 'Códigos de Barra',         'Gestión de códigos de barra EAN/UPC para productos',                      'wbi-barcode',    'operaciones'  ),
            array( 'wbi_enable_picking',        '📦', 'Picking & Armado',         'Armado de pedidos con escaneo de códigos de barra',                       'wbi-picking',    'operaciones'  ),
            array( 'wbi_enable_remitos',        '📄', 'Remitos',                  'Generación de remitos PDF vinculados a pedidos',                          'wbi-documents',  'operaciones'  ),
            array( 'wbi_enable_suppliers',      '👥', 'Proveedores',              'Gestión de proveedores y vinculación con productos',                      'wbi-suppliers',  'operaciones'  ),
            array( 'wbi_enable_reorder',        '🔄', 'Reglas de Reabastecimiento','Punto de reorden automático con generación de órdenes de compra',          'wbi-reorder',    'operaciones'  ),
            array( 'wbi_enable_purchase',       '🛒', 'Órdenes de Compra',        'Gestión completa de órdenes de compra y recepción de mercadería',          'wbi-purchase',   'operaciones'  ),
            array( 'wbi_enable_employees',      '👥', 'Empleados / RRHH',         'Gestión de empleados, departamentos, contratos, habilidades y reclutamiento', 'wbi-employees',  'operaciones'  ),
            array( 'wbi_enable_data',           '📁', 'Modelo de Datos Extra',    'Campos extra: origen de venta y taxonomías personalizadas',               null,             'datos'        ),
            array( 'wbi_enable_custom_fields',  '📋', 'Campos Personalizados',    'Campos custom en registro y checkout con validación de formato y duplicados', 'wbi-custom-fields', 'datos'     ),
            array( 'wbi_enable_invoice',        '📑', 'Facturación AFIP',         'Facturación tipo A/B/C con formato AFIP',                                 'wbi-documents',    'finanzas'     ),
            array( 'wbi_enable_credit_notes',   '💳', 'Notas de Crédito/Débito',  'Emisión de NC/ND vinculadas a facturas AFIP (tipo A/B/C)',                 'wbi-credit-notes', 'finanzas'     ),
            array( 'wbi_enable_taxes',          '🏛️','Gestión de Impuestos',      'Cálculo de IVA, percepciones e impuestos internos',                       'wbi-taxes',      'finanzas'     ),
            array( 'wbi_enable_cashflow',       '💰', 'Flujo de Caja',            'Proyección de flujo de caja y análisis financiero',                       'wbi-cashflow',   'finanzas'     ),
            array( 'wbi_enable_accounting_reports','📊','Reportes Contables',     'Libro IVA, Estado de Resultados, Posición IVA y Rentabilidad',            'wbi-accounting-reports', 'finanzas' ),
            array( 'wbi_enable_whatsapp',       '💬', 'WhatsApp',                 'Notificaciones automáticas por WhatsApp al cliente',                      'wbi-whatsapp',   'integraciones'),
            array( 'wbi_enable_api',            '📱', 'API REST',                 'Endpoints REST para integración con apps externas',                       'wbi-api',        'integraciones'),
            array( 'wbi_enable_notifications',  '🔔', 'Notificaciones',           'Centro de alertas unificado con badge en admin',                          'wbi-notifications','integraciones'),
            array( 'wbi_enable_email_marketing','📧', 'Email Marketing',          'Campañas masivas de email, templates, suscriptores y métricas',           'wbi-email-marketing','integraciones'),
        );

        $groups = array(
            'comercial'    => array( 'label' => '🏢 Comercial',                    'modules' => array() ),
            'inteligencia' => array( 'label' => '📊 Inteligencia de Negocio',       'modules' => array() ),
            'operaciones'  => array( 'label' => '📦 Operaciones',                   'modules' => array() ),
            'finanzas'     => array( 'label' => '💰 Finanzas',                      'modules' => array() ),
            'integraciones'=> array( 'label' => '🔗 Integraciones',                 'modules' => array() ),
            'datos'        => array( 'label' => '📁 Datos',                         'modules' => array() ),
        );
        foreach ( $modules as $m ) {
            $groups[ $m[5] ]['modules'][] = $m;
        }
        ?>
        <div class="wrap">

            <!-- Header -->
            <div class="wbi-config-header">
                <span style="font-size:36px;">🧠</span>
                <div>
                    <h1>wooErp — Configuración</h1>
                    <p style="margin:4px 0 0; color:#50575e; font-size:13px;">Suite de Gestión para WooCommerce</p>
                </div>
                <span class="wbi-version-badge">v<?php echo esc_html( $version ); ?></span>
                <span class="wbi-license-badge <?php echo $license_active ? 'active' : 'inactive'; ?>">
                    <?php echo $license_active ? '✅ Licencia activa' : '🔒 Sin licencia'; ?>
                </span>
                <div class="wbi-stats">
                    <strong><?php echo intval( $active_count ); ?></strong> de <?php echo intval( $total_modules ); ?> módulos activos
                </div>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'wbi_group' ); ?>

                <?php foreach ( $groups as $group_key => $group ) : ?>
                    <?php if ( empty( $group['modules'] ) ) continue; ?>
                    <div class="wbi-group-title"><?php echo esc_html( $group['label'] ); ?></div>
                    <div class="wbi-card-grid">
                    <?php foreach ( $group['modules'] as $m ) :
                        $key        = $m[0];
                        $icon       = $m[1];
                        $name       = $m[2];
                        $desc       = $m[3];
                        $page_slug  = $m[4];
                        $is_active  = ! empty( $opts[ $key ] );
                        $card_class = $is_active ? 'wbi-module-card active' : 'wbi-module-card';
                    ?>
                    <div class="<?php echo esc_attr( $card_class ); ?>">
                        <div style="display:flex; align-items:flex-start; gap:10px;">
                            <span class="card-icon"><?php echo esc_html( $icon ); ?></span>
                            <div style="flex:1;">
                                <p class="card-name"><?php echo esc_html( $name ); ?></p>
                                <p class="card-desc"><?php echo esc_html( $desc ); ?></p>
                            </div>
                        </div>
                        <div class="card-footer">
                            <label class="wbi-toggle">
                                <input type="checkbox"
                                       name="wbi_modules_settings[<?php echo esc_attr( $key ); ?>]"
                                       value="1"
                                       <?php checked( $is_active, true ); ?>>
                                <span class="wbi-toggle-slider"></span>
                            </label>
                            <?php if ( $page_slug ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page_slug ) ); ?>" class="card-link">
                                    Ir al módulo →
                                </a>
                            <?php endif; ?>
                        </div>
                        <?php if ( 'wbi_enable_b2b' === $key ) : ?>
                        <div class="wbi-b2b-config" style="margin-top:12px; padding-top:10px; border-top:1px solid #e0e0e0; font-size:12px;">
                            <p style="margin:0 0 6px; font-weight:600; color:#50575e;">⚙️ Configuración B2B</p>
                            <label style="display:block; margin-bottom:4px;">
                                Monto mínimo ($):
                                <input type="number" name="wbi_modules_settings[wbi_b2b_minimum_order]"
                                    value="<?php echo esc_attr( $opts['wbi_b2b_minimum_order'] ?? '' ); ?>"
                                    min="0" step="0.01" style="width:90px; margin-left:4px;">
                            </label>
                            <label style="display:block; margin-bottom:4px;">
                                Texto precio oculto:
                                <input type="text" name="wbi_modules_settings[wbi_b2b_hidden_price_text]"
                                    value="<?php echo esc_attr( $opts['wbi_b2b_hidden_price_text'] ?? 'PRECIO MAYORISTA OCULTO' ); ?>"
                                    style="width:100%; margin-top:2px;">
                            </label>
                            <label style="display:block;">
                                URL registro mayorista:
                                <input type="url" name="wbi_modules_settings[wbi_b2b_hidden_price_url]"
                                    value="<?php echo esc_attr( $opts['wbi_b2b_hidden_price_url'] ?? '' ); ?>"
                                    style="width:100%; margin-top:2px;">
                            </label>
                            <?php
                            $wc_new_order = get_option( 'woocommerce_new_order_settings', array() );
                            $wc_recipient = ! empty( $wc_new_order['recipient'] ) ? $wc_new_order['recipient'] : get_option( 'admin_email' );
                            ?>
                            <label style="display:block; margin-top:4px;">
                                Email notificación B2B:
                                <input type="email" name="wbi_modules_settings[wbi_b2b_notification_email]"
                                    value="<?php echo esc_attr( $opts['wbi_b2b_notification_email'] ?? '' ); ?>"
                                    placeholder="<?php echo esc_attr( $wc_recipient ); ?>"
                                    style="width:100%; margin-top:2px;">
                                <span style="color:#888; font-size:11px;">Dejá vacío para usar el email de pedidos de WooCommerce (<?php echo esc_html( $wc_recipient ); ?>)</span>
                            </label>
                            <label style="display:block; margin-top:8px;">
                                Roles autorizados para ver precios y comprar:
                                <div style="margin-top:4px;">
                                <?php
                                $authorized = isset( $opts['wbi_b2b_authorized_roles'] ) ? (array) $opts['wbi_b2b_authorized_roles'] : array( 'administrator', 'mayorista' );
                                $all_roles  = wp_roles()->roles;
                                foreach ( $all_roles as $role_slug => $role_data ) :
                                    $checked = in_array( $role_slug, $authorized, true ) ? 'checked' : '';
                                ?>
                                    <label style="display:inline-block; margin-right:10px;">
                                        <input type="checkbox" name="wbi_modules_settings[wbi_b2b_authorized_roles][]" value="<?php echo esc_attr( $role_slug ); ?>" <?php echo $checked; ?>>
                                        <?php echo esc_html( $role_data['name'] ); ?>
                                    </label>
                                <?php endforeach; ?>
                                </div>
                                <span style="color:#888; font-size:11px;">Solo estos roles podrán ver precios y comprar. El resto verá "Precio oculto".</span>
                            </label>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

                <?php
                // ── Permissions per Module ─────────────────────────────────
                $roles = wp_roles()->roles;

                // All modules with their enable key and permission key
                $perm_module_map = array(
                    array( 'enable_key' => 'wbi_enable_b2b',           'perm_key' => 'wbi_permissions_b2b',           'name' => 'Modo Mayorista B2B' ),
                    array( 'enable_key' => 'wbi_enable_dashboard',     'perm_key' => 'wbi_permissions_dashboard',     'name' => 'Dashboard BI Suite' ),
                    array( 'enable_key' => 'wbi_enable_barcode',       'perm_key' => 'wbi_permissions_barcode',       'name' => 'Códigos de Barra' ),
                    array( 'enable_key' => 'wbi_enable_picking',       'perm_key' => 'wbi_permissions_picking',       'name' => 'Picking & Armado' ),
                    array( 'enable_key' => 'wbi_enable_costs',         'perm_key' => 'wbi_permissions_costs',         'name' => 'Costos y Márgenes' ),
                    array( 'enable_key' => 'wbi_enable_suppliers',     'perm_key' => 'wbi_permissions_suppliers',     'name' => 'Proveedores' ),
                    array( 'enable_key' => 'wbi_enable_purchase',      'perm_key' => 'wbi_permissions_purchase',      'name' => 'Órdenes de Compra' ),
                    array( 'enable_key' => 'wbi_enable_scoring',       'perm_key' => 'wbi_permissions_scoring',       'name' => 'Scoring de Clientes' ),
                    array( 'enable_key' => 'wbi_enable_pricelists',    'perm_key' => 'wbi_permissions_pricelists',    'name' => 'Listas de Precios' ),
                    array( 'enable_key' => 'wbi_enable_cashflow',      'perm_key' => 'wbi_permissions_cashflow',      'name' => 'Flujo de Caja' ),
                    array( 'enable_key' => 'wbi_enable_taxes',         'perm_key' => 'wbi_permissions_taxes',         'name' => 'Impuestos Avanzado' ),
                    array( 'enable_key' => 'wbi_enable_whatsapp',      'perm_key' => 'wbi_permissions_whatsapp',      'name' => 'WhatsApp' ),
                    array( 'enable_key' => 'wbi_enable_notifications', 'perm_key' => 'wbi_permissions_notifications', 'name' => 'Notificaciones' ),
                    array( 'enable_key' => 'wbi_enable_api',           'perm_key' => 'wbi_permissions_api',           'name' => 'API REST' ),
                    array( 'enable_key' => 'wbi_enable_abandoned_carts','perm_key' => 'wbi_permissions_abandoned_carts','name' => 'Carritos Abandonados' ),
                    array( 'enable_key' => 'wbi_enable_accounting_reports','perm_key' => 'wbi_permissions_accounting_reports','name' => 'Reportes Contables' ),
                    array( 'enable_key' => 'wbi_enable_credit_notes',   'perm_key' => 'wbi_permissions_credit_notes',   'name' => 'Notas de Crédito / Débito' ),
                    array( 'enable_key' => 'wbi_enable_reorder',        'perm_key' => 'wbi_permissions_reorder',        'name' => 'Reglas de Reabastecimiento' ),
                    array( 'enable_key' => 'wbi_enable_crm',           'perm_key' => 'wbi_permissions_crm',           'name' => 'CRM / Pipeline de Ventas' ),
                    array( 'enable_key' => 'wbi_enable_custom_fields', 'perm_key' => 'wbi_permissions_custom_fields', 'name' => 'Campos Personalizados' ),
                    array( 'enable_key' => 'wbi_enable_employees',     'perm_key' => 'wbi_permissions_employees',     'name' => 'Empleados / RRHH' ),
                );
                // Unified documents module: show when invoice or remitos is active
                if ( ! empty( $opts['wbi_enable_invoice'] ) || ! empty( $opts['wbi_enable_remitos'] ) ) {
                    $perm_module_map[] = array( 'enable_key' => null, 'perm_key' => 'wbi_permissions_documents', 'name' => 'Documentos' );
                }

                // Keep only currently enabled modules
                $active_perm_modules = array();
                foreach ( $perm_module_map as $pm ) {
                    if ( null === $pm['enable_key'] || ! empty( $opts[ $pm['enable_key'] ] ) ) {
                        $active_perm_modules[] = $pm;
                    }
                }
                ?>

                <?php if ( ! empty( $active_perm_modules ) ) : ?>
                <style>
                .wbi-perm-details { margin-top:24px; background:#fff; border:1px solid #c3c4c7; border-radius:6px; overflow:hidden; }
                .wbi-perm-details summary { cursor:pointer; padding:16px 20px; font-size:15px; font-weight:bold; color:#1d2327; list-style:none; display:flex; align-items:center; gap:8px; }
                .wbi-perm-details summary::-webkit-details-marker { display:none; }
                .wbi-perm-details summary::after { content:'▸'; font-size:12px; color:#50575e; margin-left:auto; }
                .wbi-perm-details[open] summary::after { content:'▾'; }
                .wbi-perm-details[open] summary { border-bottom:1px solid #e0e0e0; }
                .wbi-perm-body { padding:16px 20px 20px; }
                .wbi-perm-body > p { color:#50575e; font-size:13px; margin:0 0 16px; }
                .wbi-perm-table-wrap { overflow-x:auto; }
                .wbi-perm-table { border-collapse:collapse; width:100%; font-size:12px; min-width:400px; }
                .wbi-perm-table th, .wbi-perm-table td { border:1px solid #e0e0e0; padding:8px 10px; }
                .wbi-perm-table thead th { background:#f6f7f7; font-weight:600; white-space:nowrap; }
                .wbi-perm-table thead th:first-child { text-align:left; min-width:150px; }
                .wbi-perm-table thead th:not(:first-child) { text-align:center; min-width:80px; }
                .wbi-perm-table tbody td:first-child { font-weight:500; white-space:nowrap; }
                .wbi-perm-table tbody td:not(:first-child) { text-align:center; }
                .wbi-perm-table tbody tr:nth-child(even) { background:#f9f9f9; }
                </style>
                <details class="wbi-perm-details">
                    <summary>🔐 Permisos por Módulo <span style="color:#50575e; font-size:12px; font-weight:normal;">— clic para expandir</span></summary>
                    <div class="wbi-perm-body">
                        <p>Seleccioná qué roles de WordPress pueden acceder a cada módulo. Por defecto, solo Administrador.</p>
                        <div class="wbi-perm-table-wrap">
                            <table class="wbi-perm-table">
                                <thead>
                                    <tr>
                                        <th>Módulo</th>
                                        <?php foreach ( $roles as $role_slug => $role_data ) : ?>
                                            <th><?php echo esc_html( $role_data['name'] ); ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $active_perm_modules as $pm ) :
                                        $perm_key = $pm['perm_key'];
                                        $saved    = ( isset( $opts[ $perm_key ] ) && ! empty( $opts[ $perm_key ] ) )
                                                    ? (array) $opts[ $perm_key ]
                                                    : array( 'administrator' );
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html( $pm['name'] ); ?></td>
                                        <?php foreach ( $roles as $role_slug => $role_data ) : ?>
                                            <td>
                                                <input type="checkbox"
                                                       name="wbi_modules_settings[<?php echo esc_attr( $perm_key ); ?>][]"
                                                       value="<?php echo esc_attr( $role_slug ); ?>"
                                                       <?php checked( in_array( $role_slug, $saved, true ), true ); ?>>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </details>
                <?php endif; ?>

                <p style="margin-top:24px;">
                    <?php submit_button( 'Guardar configuración', 'primary', 'submit', false ); ?>
                </p>
            </form>
        </div>
        <?php
    }

    public function ensure_armador_role() {
        self::maybe_create_armador_role();
    }

    public static function on_activate() {
        self::maybe_create_armador_role();
    }

    private static function maybe_create_armador_role() {
        if ( ! get_role( 'wbi_armador' ) ) {
            add_role( 'wbi_armador', 'Armador WBI', array( 'read' => true ) );
        }
    }
}

// Activation hook
register_activation_hook( __FILE__, array( 'WBI_Suite_Loader', 'on_activate' ) );

// Iniciar Plugin
new WBI_Suite_Loader();

// Deactivation hook: clear scoring and reorder cron events
register_deactivation_hook( __FILE__, function() {
    if ( class_exists( 'WBI_Scoring_Module' ) ) {
        WBI_Scoring_Module::deactivate();
    }
    if ( class_exists( 'WBI_Reorder_Module' ) ) {
        WBI_Reorder_Module::deactivate();
    }
} );

// Inyectar CSS/JS de ordenamiento de tablas en todas las páginas WBI
add_action( 'admin_footer', function() {
    $screen = get_current_screen();
    if ( ! $screen ) return;
    // Solo en páginas del plugin (el ID de pantalla contiene 'wbi')
    if ( strpos( $screen->id, 'wbi' ) === false ) return;
    ?>
    <style>
    .wbi-sortable thead th { cursor: pointer; user-select: none; white-space: nowrap; }
    .wbi-sortable thead th:hover { background-color: #f0f0f1; }
    .wbi-sortable thead th.wbi-sort-asc::after  { content: ' \25b2'; font-size: 10px; opacity: 0.7; }
    .wbi-sortable thead th.wbi-sort-desc::after { content: ' \25bc'; font-size: 10px; opacity: 0.7; }
    </style>
    <script>
    (function() {
        function wbiParseNum(str) {
            // Remove currency symbols/spaces, then handle thousands/decimal separators.
            // Supports formats like "$1.234,56" (AR) or "1,234.56" (US)
            var s = str.replace(/[^\d,.\-]/g, '');
            if (s.indexOf('.') !== -1 && s.indexOf(',') !== -1) {
                // Both separators present: assume last one is decimal (e.g. 1.234,56 -> 1234.56)
                var lastDot   = s.lastIndexOf('.');
                var lastComma = s.lastIndexOf(',');
                if (lastComma > lastDot) {
                    s = s.replace(/\./g, '').replace(',', '.');
                } else {
                    s = s.replace(/,/g, '');
                }
            } else if (s.indexOf(',') !== -1) {
                s = s.replace(',', '.');
            }
            return parseFloat(s);
        }
        function wbiSortByCol(table, colIdx, asc) {
            var tbody = table.querySelector('tbody');
            if (!tbody) return;
            var rows = Array.from(tbody.querySelectorAll('tr'));
            rows.sort(function(a, b) {
                var aCells = a.querySelectorAll('td');
                var bCells = b.querySelectorAll('td');
                if (!aCells[colIdx] || !bCells[colIdx]) return 0;
                var aText = aCells[colIdx].textContent.trim();
                var bText = bCells[colIdx].textContent.trim();
                var aNum = wbiParseNum(aText);
                var bNum = wbiParseNum(bText);
                if (!isNaN(aNum) && !isNaN(bNum)) {
                    return asc ? aNum - bNum : bNum - aNum;
                }
                // Try date in dd/mm/yyyy format
                var dParts = aText.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
                var eParts = bText.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
                if (dParts && eParts) {
                    var aDate = new Date(dParts[3], dParts[2]-1, dParts[1]);
                    var bDate = new Date(eParts[3], eParts[2]-1, eParts[1]);
                    return asc ? aDate - bDate : bDate - aDate;
                }
                return asc ? aText.localeCompare(bText, 'es') : bText.localeCompare(aText, 'es');
            });
            rows.forEach(function(row) { tbody.appendChild(row); });
        }
        function wbiInitSortable() {
            document.querySelectorAll('table.wbi-sortable').forEach(function(table) {
                var headers = table.querySelectorAll('thead tr:first-child th');
                headers.forEach(function(th, colIdx) {
                    th.setAttribute('tabindex', '0');
                    th.setAttribute('role', 'button');
                    function doSort() {
                        var isAsc = th.classList.contains('wbi-sort-asc');
                        headers.forEach(function(h) {
                            h.classList.remove('wbi-sort-asc', 'wbi-sort-desc');
                        });
                        th.classList.add(isAsc ? 'wbi-sort-desc' : 'wbi-sort-asc');
                        wbiSortByCol(table, colIdx, !isAsc);
                    }
                    th.addEventListener('click', doSort);
                    th.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            doSort();
                        }
                    });
                });
            });
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', wbiInitSortable);
        } else {
            wbiInitSortable();
        }
    })();
    </script>
    <?php
} );
