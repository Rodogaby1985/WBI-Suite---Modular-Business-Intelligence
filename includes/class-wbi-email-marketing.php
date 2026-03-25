<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI Email Marketing Module
 *
 * Campañas masivas de email, templates, segmentación de audiencia,
 * seguimiento de aperturas/clics y unsubscribe one-click.
 *
 * Toggle key : wbi_enable_email_marketing (en wbi_modules_settings)
 * Admin slug  : wbi-email-marketing
 * Perm key    : wbi_permissions_email_marketing
 */
class WBI_Email_Marketing_Module {

    /** @var wpdb */
    private $db;

    // Table names (without prefix)
    private $tbl_campaigns;
    private $tbl_subscribers;
    private $tbl_sends;
    private $tbl_templates;

    // Batch size for email sending
    const BATCH_SIZE = 50;

    public function __construct() {
        global $wpdb;
        $this->db              = $wpdb;
        $this->tbl_campaigns   = $wpdb->prefix . 'wbi_email_campaigns';
        $this->tbl_subscribers = $wpdb->prefix . 'wbi_email_subscribers';
        $this->tbl_sends       = $wpdb->prefix . 'wbi_email_sends';
        $this->tbl_templates   = $wpdb->prefix . 'wbi_email_templates';

        // DB install / upgrade
        add_action( 'init', array( $this, 'maybe_create_tables' ) );

        // Admin pages
        add_action( 'admin_menu', array( $this, 'register_pages' ), 100 );

        // WP Cron: batch sending every 5 minutes
        add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
        add_action( 'wbi_email_send_batch', array( $this, 'process_send_batch' ) );
        if ( ! wp_next_scheduled( 'wbi_email_send_batch' ) ) {
            wp_schedule_event( time(), 'wbi_5min', 'wbi_email_send_batch' );
        }

        // Tracking & unsubscribe via init
        add_action( 'init', array( $this, 'handle_tracking' ), 5 );

        // WooCommerce integration
        add_action( 'woocommerce_created_customer', array( $this, 'sync_new_wc_customer' ), 10, 3 );
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'handle_checkout_optin' ), 10, 2 );
        add_filter( 'woocommerce_checkout_fields', array( $this, 'add_checkout_optin_field' ) );

        // AJAX endpoints
        add_action( 'wp_ajax_wbi_email_save_campaign',       array( $this, 'ajax_save_campaign' ) );
        add_action( 'wp_ajax_wbi_email_send_test',           array( $this, 'ajax_send_test' ) );
        add_action( 'wp_ajax_wbi_email_start_campaign',      array( $this, 'ajax_start_campaign' ) );
        add_action( 'wp_ajax_wbi_email_pause_campaign',      array( $this, 'ajax_pause_campaign' ) );
        add_action( 'wp_ajax_wbi_email_import_subscribers',  array( $this, 'ajax_import_subscribers' ) );
        add_action( 'wp_ajax_wbi_email_get_recipient_count', array( $this, 'ajax_get_recipient_count' ) );
        add_action( 'wp_ajax_wbi_email_save_template',       array( $this, 'ajax_save_template' ) );
        add_action( 'wp_ajax_wbi_email_delete_subscriber',   array( $this, 'ajax_delete_subscriber' ) );
    }

    // =========================================================================
    // DATABASE
    // =========================================================================

    public function maybe_create_tables() {
        $installed_version = get_option( 'wbi_email_marketing_db_version', '0' );
        if ( version_compare( $installed_version, '1.0', '>=' ) ) {
            return;
        }
        $this->create_tables();
        update_option( 'wbi_email_marketing_db_version', '1.0' );
    }

    public function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$this->tbl_campaigns} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            from_name VARCHAR(255) DEFAULT '',
            from_email VARCHAR(255) DEFAULT '',
            template_id BIGINT UNSIGNED DEFAULT 0,
            content LONGTEXT,
            segment VARCHAR(100) DEFAULT 'all',
            segment_filter TEXT,
            status ENUM('draft','scheduled','sending','sent','paused') NOT NULL DEFAULT 'draft',
            scheduled_at DATETIME DEFAULT NULL,
            sent_at DATETIME DEFAULT NULL,
            total_recipients INT UNSIGNED DEFAULT 0,
            total_sent INT UNSIGNED DEFAULT 0,
            total_opened INT UNSIGNED DEFAULT 0,
            total_clicked INT UNSIGNED DEFAULT 0,
            total_bounced INT UNSIGNED DEFAULT 0,
            total_unsubscribed INT UNSIGNED DEFAULT 0,
            created_by BIGINT UNSIGNED DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;";
        dbDelta( $sql );

        $sql = "CREATE TABLE {$this->tbl_subscribers} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(255) NOT NULL,
            first_name VARCHAR(100) DEFAULT '',
            last_name VARCHAR(100) DEFAULT '',
            customer_id BIGINT UNSIGNED DEFAULT 0,
            source VARCHAR(50) DEFAULT 'manual',
            lists TEXT,
            status ENUM('subscribed','unsubscribed','bounced') NOT NULL DEFAULT 'subscribed',
            subscribed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            unsubscribed_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY email (email)
        ) $charset;";
        dbDelta( $sql );

        $sql = "CREATE TABLE {$this->tbl_sends} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id BIGINT UNSIGNED NOT NULL,
            subscriber_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(255) DEFAULT '',
            status ENUM('queued','sent','opened','clicked','bounced','failed') NOT NULL DEFAULT 'queued',
            sent_at DATETIME DEFAULT NULL,
            opened_at DATETIME DEFAULT NULL,
            clicked_at DATETIME DEFAULT NULL,
            open_count INT UNSIGNED DEFAULT 0,
            click_count INT UNSIGNED DEFAULT 0,
            PRIMARY KEY (id),
            KEY campaign_id (campaign_id),
            KEY subscriber_id (subscriber_id)
        ) $charset;";
        dbDelta( $sql );

        $sql = "CREATE TABLE {$this->tbl_templates} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            content LONGTEXT,
            thumbnail VARCHAR(500) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;";
        dbDelta( $sql );

        $this->install_default_templates();
    }

    private function install_default_templates() {
        $count = (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->tbl_templates}" );
        if ( $count > 0 ) {
            return;
        }
        $shop_name = get_bloginfo( 'name' );
        $defaults  = array(
            array(
                'name'    => 'Bienvenida',
                'content' => '<h1>¡Bienvenido/a, {{first_name}}!</h1><p>Gracias por suscribirte a ' . esc_html( $shop_name ) . '. Pronto recibirás nuestras mejores ofertas.</p><p><a href="{{shop_url}}">Visitar la tienda →</a></p><p style="font-size:11px;color:#999;"><a href="{{unsubscribe_link}}">Cancelar suscripción</a></p>',
            ),
            array(
                'name'    => 'Promoción',
                'content' => '<h1>🎉 ¡Oferta especial para vos, {{first_name}}!</h1><p>No te pierdas nuestras promociones exclusivas en <a href="{{shop_url}}">{{shop_name}}</a>.</p><p><a href="{{shop_url}}" style="background:#0071a1;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;">Ver ofertas</a></p><p style="font-size:11px;color:#999;"><a href="{{unsubscribe_link}}">Cancelar suscripción</a></p>',
            ),
            array(
                'name'    => 'Newsletter',
                'content' => '<h1>Newsletter — {{shop_name}}</h1><p>Hola {{first_name}}, te compartimos las novedades del mes.</p><p>[Escribí aquí el contenido del newsletter]</p><p><a href="{{shop_url}}">Ver más en la tienda →</a></p><p style="font-size:11px;color:#999;"><a href="{{unsubscribe_link}}">Cancelar suscripción</a></p>',
            ),
            array(
                'name'    => 'Recuperación',
                'content' => '<h1>¡Te extrañamos, {{first_name}}!</h1><p>Hace tiempo que no nos visitás. Tenemos novedades esperándote en <a href="{{shop_url}}">{{shop_name}}</a>.</p><p><a href="{{shop_url}}" style="background:#d63638;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;">Volver a la tienda</a></p><p style="font-size:11px;color:#999;"><a href="{{unsubscribe_link}}">Cancelar suscripción</a></p>',
            ),
        );
        foreach ( $defaults as $tpl ) {
            $this->db->insert(
                $this->tbl_templates,
                array(
                    'name'    => $tpl['name'],
                    'content' => $tpl['content'],
                ),
                array( '%s', '%s' )
            );
        }
    }

    // =========================================================================
    // ADMIN MENU
    // =========================================================================

    public function register_pages() {
        add_submenu_page(
            'wbi-dashboard-view',
            'Email Marketing',
            '📧 Email Marketing',
            'manage_options',
            'wbi-email-marketing',
            array( $this, 'render_page' )
        );
    }

    // =========================================================================
    // ADMIN PAGE ROUTER
    // =========================================================================

    public function render_page() {
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
        switch ( $action ) {
            case 'new':
            case 'edit':
                $this->render_campaign_editor();
                break;
            case 'report':
                $this->render_campaign_report();
                break;
            case 'subscribers':
                $this->render_subscribers_page();
                break;
            case 'templates':
                $this->render_templates_page();
                break;
            default:
                $this->render_campaigns_list();
                break;
        }
    }

    // =========================================================================
    // CAMPAIGNS LIST
    // =========================================================================

    private function render_campaigns_list() {
        $status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
        $where         = '';
        $params        = array();
        if ( $status_filter ) {
            $where    = 'WHERE status = %s';
            $params[] = $status_filter;
        }

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $query      = "SELECT * FROM {$this->tbl_campaigns} {$where} ORDER BY created_at DESC";
        $campaigns  = $params
            ? $this->db->get_results( $this->db->prepare( $query, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            : $this->db->get_results( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $base_url   = admin_url( 'admin.php?page=wbi-email-marketing' );
        $new_url    = $base_url . '&action=new';
        $subs_url   = $base_url . '&action=subscribers';
        $tpls_url   = $base_url . '&action=templates';

        $status_labels = array(
            'draft'     => array( 'label' => 'Borrador',    'color' => '#a7aaad' ),
            'scheduled' => array( 'label' => 'Programada',  'color' => '#72aee6' ),
            'sending'   => array( 'label' => 'Enviando',    'color' => '#f0c33c' ),
            'sent'      => array( 'label' => 'Enviada',     'color' => '#00a32a' ),
            'paused'    => array( 'label' => 'Pausada',     'color' => '#dba617' ),
        );
        ?>
        <div class="wrap">
            <h1 style="display:flex;align-items:center;gap:10px;">
                📧 Email Marketing
                <a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action">+ Nueva Campaña</a>
                <a href="<?php echo esc_url( $subs_url ); ?>" class="page-title-action">👥 Suscriptores</a>
                <a href="<?php echo esc_url( $tpls_url ); ?>" class="page-title-action">📄 Templates</a>
            </h1>

            <!-- Status filter tabs -->
            <ul class="subsubsub">
                <li><a href="<?php echo esc_url( $base_url ); ?>" <?php echo ! $status_filter ? 'class="current"' : ''; ?>>Todas</a> |</li>
                <?php foreach ( $status_labels as $s => $sl ) : ?>
                    <li>
                        <a href="<?php echo esc_url( add_query_arg( 'status', $s, $base_url ) ); ?>"
                           <?php echo $status_filter === $s ? 'class="current"' : ''; ?>>
                            <?php echo esc_html( $sl['label'] ); ?>
                        </a>
                        <?php echo $s !== 'paused' ? ' |' : ''; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <br class="clear">

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:22%;">Nombre</th>
                        <th>Asunto</th>
                        <th style="width:100px;">Estado</th>
                        <th style="width:90px;">Destinatarios</th>
                        <th style="width:80px;">Enviados</th>
                        <th style="width:80px;">Aperturas %</th>
                        <th style="width:80px;">Clics %</th>
                        <th style="width:120px;">Fecha Envío</th>
                        <th style="width:120px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $campaigns ) ) : ?>
                    <tr><td colspan="9" style="text-align:center;padding:30px;color:#999;">
                        No hay campañas todavía. <a href="<?php echo esc_url( $new_url ); ?>">Crear la primera</a>.
                    </td></tr>
                <?php else : ?>
                    <?php foreach ( $campaigns as $c ) :
                        $sl        = $status_labels[ $c->status ] ?? array( 'label' => $c->status, 'color' => '#ccc' );
                        $open_rate = $c->total_sent > 0 ? round( ( $c->total_opened / $c->total_sent ) * 100, 1 ) : 0;
                        $click_rate= $c->total_sent > 0 ? round( ( $c->total_clicked / $c->total_sent ) * 100, 1 ) : 0;
                        $edit_url  = add_query_arg( array( 'action' => 'edit', 'campaign_id' => $c->id ), $base_url );
                        $report_url= add_query_arg( array( 'action' => 'report', 'campaign_id' => $c->id ), $base_url );
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $c->name ); ?></strong></td>
                        <td><?php echo esc_html( $c->subject ); ?></td>
                        <td>
                            <span style="background:<?php echo esc_attr( $sl['color'] ); ?>;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;">
                                <?php echo esc_html( $sl['label'] ); ?>
                            </span>
                        </td>
                        <td><?php echo intval( $c->total_recipients ); ?></td>
                        <td><?php echo intval( $c->total_sent ); ?></td>
                        <td><?php echo esc_html( $open_rate ); ?>%</td>
                        <td><?php echo esc_html( $click_rate ); ?>%</td>
                        <td><?php echo $c->sent_at ? esc_html( date_i18n( 'd/m/Y H:i', strtotime( $c->sent_at ) ) ) : '—'; ?></td>
                        <td>
                            <a href="<?php echo esc_url( $edit_url ); ?>">Editar</a> |
                            <a href="<?php echo esc_url( $report_url ); ?>">Reporte</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // =========================================================================
    // CAMPAIGN EDITOR (4 steps)
    // =========================================================================

    private function render_campaign_editor() {
        $campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
        $campaign    = $campaign_id
            ? $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->tbl_campaigns} WHERE id = %d", $campaign_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            : null;

        $templates   = $this->db->get_results( "SELECT id, name FROM {$this->tbl_templates} ORDER BY name" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $base_url    = admin_url( 'admin.php?page=wbi-email-marketing' );

        $seg_filter  = $campaign ? json_decode( $campaign->segment_filter, true ) : array();
        $seg_filter  = is_array( $seg_filter ) ? $seg_filter : array();

        $crm_active     = class_exists( 'WBI_CRM_Module' );
        $scoring_active = class_exists( 'WBI_Scoring_Module' );

        $rfm_segments = array( 'champions', 'loyal', 'potential_loyal', 'at_risk', 'cant_lose', 'lost' );
        ?>
        <div class="wrap">
            <h1>
                📧 <?php echo $campaign_id ? 'Editar Campaña' : 'Nueva Campaña'; ?>
                <a href="<?php echo esc_url( $base_url ); ?>" class="page-title-action">← Volver</a>
            </h1>

            <div id="wbi-campaign-editor" data-campaign-id="<?php echo intval( $campaign_id ); ?>">

                <!-- Step tabs -->
                <nav class="nav-tab-wrapper wbi-steps" id="wbi-step-tabs">
                    <a href="#step1" class="nav-tab nav-tab-active wbi-step-tab" data-step="1">1. Configuración</a>
                    <a href="#step2" class="nav-tab wbi-step-tab" data-step="2">2. Audiencia</a>
                    <a href="#step3" class="nav-tab wbi-step-tab" data-step="3">3. Contenido</a>
                    <a href="#step4" class="nav-tab wbi-step-tab" data-step="4">4. Revisión & Envío</a>
                </nav>

                <!-- Step 1: Setup -->
                <div id="step1" class="wbi-step-panel" style="display:block;">
                    <table class="form-table">
                        <tr>
                            <th><label for="wbi_em_name">Nombre de campaña *</label></th>
                            <td><input type="text" id="wbi_em_name" class="large-text" value="<?php echo $campaign ? esc_attr( $campaign->name ) : ''; ?>" required></td>
                        </tr>
                        <tr>
                            <th><label for="wbi_em_subject">Asunto del email *</label></th>
                            <td><input type="text" id="wbi_em_subject" class="large-text" value="<?php echo $campaign ? esc_attr( $campaign->subject ) : ''; ?>" required></td>
                        </tr>
                        <tr>
                            <th><label for="wbi_em_from_name">Nombre del remitente</label></th>
                            <td><input type="text" id="wbi_em_from_name" class="regular-text" value="<?php echo $campaign ? esc_attr( $campaign->from_name ) : esc_attr( get_bloginfo( 'name' ) ); ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="wbi_em_from_email">Email del remitente</label></th>
                            <td><input type="email" id="wbi_em_from_email" class="regular-text" value="<?php echo $campaign ? esc_attr( $campaign->from_email ) : esc_attr( get_option( 'admin_email' ) ); ?>"></td>
                        </tr>
                    </table>
                    <p><button class="button button-primary" id="wbi-step1-next">Siguiente →</button></p>
                </div>

                <!-- Step 2: Audience -->
                <div id="step2" class="wbi-step-panel" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th><label for="wbi_em_segment">Segmento</label></th>
                            <td>
                                <select id="wbi_em_segment">
                                    <option value="all" <?php selected( $campaign ? $campaign->segment : 'all', 'all' ); ?>>Todos los suscriptores</option>
                                    <option value="customers" <?php selected( $campaign ? $campaign->segment : '', 'customers' ); ?>>Solo clientes WooCommerce</option>
                                    <?php if ( $crm_active ) : ?>
                                    <option value="leads" <?php selected( $campaign ? $campaign->segment : '', 'leads' ); ?>>Leads CRM</option>
                                    <?php endif; ?>
                                    <?php if ( $scoring_active ) : ?>
                                    <option value="rfm_segment" <?php selected( $campaign ? $campaign->segment : '', 'rfm_segment' ); ?>>Segmento RFM</option>
                                    <?php endif; ?>
                                    <option value="custom" <?php selected( $campaign ? $campaign->segment : '', 'custom' ); ?>>Filtro personalizado</option>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <!-- RFM sub-filter -->
                    <div id="wbi_em_rfm_section" style="display:none;margin-left:200px;">
                        <label>Segmento RFM:
                            <select id="wbi_em_rfm_segment">
                                <?php foreach ( $rfm_segments as $rs ) : ?>
                                <option value="<?php echo esc_attr( $rs ); ?>" <?php selected( $seg_filter['rfm_segment'] ?? '', $rs ); ?>>
                                    <?php echo esc_html( ucfirst( str_replace( '_', ' ', $rs ) ) ); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <!-- Custom filter -->
                    <div id="wbi_em_custom_section" style="display:none;margin-left:200px;">
                        <table class="form-table" style="width:auto;">
                            <tr>
                                <th style="width:180px;">Pedidos mínimos</th>
                                <td><input type="number" id="wbi_em_min_orders" value="<?php echo intval( $seg_filter['min_orders'] ?? 0 ); ?>" min="0" style="width:80px;"></td>
                            </tr>
                            <tr>
                                <th>Último pedido (días)</th>
                                <td><input type="number" id="wbi_em_last_order_days" value="<?php echo intval( $seg_filter['last_order_days'] ?? 0 ); ?>" min="0" style="width:80px;">
                                <span class="description">Dejar en 0 para ignorar</span></td>
                            </tr>
                            <tr>
                                <th>Gasto total mínimo ($)</th>
                                <td><input type="number" id="wbi_em_min_spent" value="<?php echo floatval( $seg_filter['min_spent'] ?? 0 ); ?>" min="0" step="0.01" style="width:100px;"></td>
                            </tr>
                        </table>
                    </div>

                    <p id="wbi-recipient-count" style="font-style:italic;color:#666;">
                        <button type="button" class="button" id="wbi-calc-recipients">Calcular destinatarios</button>
                        <span id="wbi-recipient-count-val"></span>
                    </p>

                    <p>
                        <button class="button" id="wbi-step2-prev">← Anterior</button>
                        <button class="button button-primary" id="wbi-step2-next">Siguiente →</button>
                    </p>
                </div>

                <!-- Step 3: Content -->
                <div id="step3" class="wbi-step-panel" style="display:none;">
                    <p>
                        <strong>Plantilla:</strong>
                        <select id="wbi_em_template_id">
                            <option value="0">— Ninguna (HTML propio) —</option>
                            <?php foreach ( $templates as $tpl ) : ?>
                            <option value="<?php echo intval( $tpl->id ); ?>" <?php selected( $campaign ? $campaign->template_id : 0, $tpl->id ); ?>>
                                <?php echo esc_html( $tpl->name ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="button" id="wbi-load-template">Cargar plantilla</button>
                    </p>
                    <p class="description">
                        Etiquetas disponibles: <code>{{first_name}}</code> <code>{{last_name}}</code> <code>{{email}}</code>
                        <code>{{shop_name}}</code> <code>{{shop_url}}</code> <code>{{unsubscribe_link}}</code>
                    </p>
                    <?php
                    $content = $campaign ? $campaign->content : '';
                    wp_editor(
                        $content,
                        'wbi_em_content',
                        array(
                            'textarea_name' => 'wbi_em_content',
                            'media_buttons' => true,
                            'tinymce'       => true,
                            'quicktags'     => true,
                        )
                    );
                    ?>
                    <p style="margin-top:15px;">
                        <button class="button" id="wbi-step3-prev">← Anterior</button>
                        <button class="button button-secondary" id="wbi-send-test">✉ Enviar prueba al admin</button>
                        <button class="button button-primary" id="wbi-step3-next">Siguiente →</button>
                    </p>
                    <span id="wbi-test-result" style="margin-left:10px;font-style:italic;"></span>
                </div>

                <!-- Step 4: Review & Send -->
                <div id="step4" class="wbi-step-panel" style="display:none;">
                    <div id="wbi-preview-container" style="border:1px solid #ddd;padding:20px;max-height:400px;overflow:auto;background:#fff;margin-bottom:20px;">
                        <p style="color:#999;">Cargá el contenido en el paso anterior para ver la vista previa aquí.</p>
                    </div>

                    <table class="form-table" style="width:auto;">
                        <tr>
                            <th style="width:180px;">Envío</th>
                            <td>
                                <label><input type="radio" name="wbi_em_send_mode" value="now" checked> Enviar ahora</label>
                                <label style="margin-left:20px;"><input type="radio" name="wbi_em_send_mode" value="schedule"> Programar</label>
                            </td>
                        </tr>
                        <tr id="wbi_em_schedule_row" style="display:none;">
                            <th>Fecha y hora</th>
                            <td>
                                <input type="datetime-local" id="wbi_em_scheduled_at"
                                    value="<?php echo $campaign && $campaign->scheduled_at ? esc_attr( str_replace( ' ', 'T', $campaign->scheduled_at ) ) : ''; ?>">
                            </td>
                        </tr>
                    </table>

                    <p id="wbi-final-count" style="font-weight:bold;"></p>

                    <p>
                        <button class="button" id="wbi-step4-prev">← Anterior</button>
                        <button class="button button-primary" id="wbi-save-draft">💾 Guardar borrador</button>
                        <button class="button button-primary" id="wbi-launch-campaign" style="background:#00a32a;border-color:#00a32a;">🚀 Lanzar Campaña</button>
                    </p>
                    <div id="wbi-launch-result" style="margin-top:10px;"></div>
                </div><!-- /step4 -->

            </div><!-- /wbi-campaign-editor -->
        </div><!-- /wrap -->

        <?php wp_nonce_field( 'wbi_email_nonce', 'wbi_email_nonce_field', false ); ?>
        <script>
        (function($){
            var nonce = '<?php echo esc_js( wp_create_nonce( 'wbi_email_nonce' ) ); ?>';

            // Tab navigation
            function showStep(n) {
                $('.wbi-step-panel').hide();
                $('#step' + n).show();
                $('.wbi-step-tab').removeClass('nav-tab-active');
                $('.wbi-step-tab[data-step="' + n + '"]').addClass('nav-tab-active');
            }

            $('.wbi-step-tab').on('click', function(e){
                e.preventDefault();
                showStep($(this).data('step'));
            });

            $('#wbi-step1-next').on('click', function(){ showStep(2); });
            $('#wbi-step2-prev').on('click', function(){ showStep(1); });
            $('#wbi-step2-next').on('click', function(){ showStep(3); });
            $('#wbi-step3-prev').on('click', function(){ showStep(2); });
            $('#wbi-step3-next').on('click', function(){
                var content = '';
                if (typeof tinymce !== 'undefined' && tinymce.get('wbi_em_content')) {
                    content = tinymce.get('wbi_em_content').getContent();
                } else {
                    content = $('#wbi_em_content').val();
                }
                $('#wbi-preview-container').html(content || '<p style="color:#999;">Sin contenido.</p>');
                showStep(4);
            });
            $('#wbi-step4-prev').on('click', function(){ showStep(3); });

            // Segment-dependent sections
            $('#wbi_em_segment').on('change', function(){
                var val = $(this).val();
                $('#wbi_em_rfm_section').toggle(val === 'rfm_segment');
                $('#wbi_em_custom_section').toggle(val === 'custom');
            }).trigger('change');

            // Schedule toggle
            $('input[name="wbi_em_send_mode"]').on('change', function(){
                $('#wbi_em_schedule_row').toggle($(this).val() === 'schedule');
            });

            // Load template content
            $('#wbi-load-template').on('click', function(){
                var tid = $('#wbi_em_template_id').val();
                if (!tid || tid == '0') return;
                $.post(ajaxurl, { action: 'wbi_email_get_template', id: tid, _wpnonce: nonce }, function(r){
                    if (r.success && r.data.content) {
                        if (typeof tinymce !== 'undefined' && tinymce.get('wbi_em_content')) {
                            tinymce.get('wbi_em_content').setContent(r.data.content);
                        } else {
                            $('#wbi_em_content').val(r.data.content);
                        }
                    }
                });
            });

            // Calculate recipients
            $('#wbi-calc-recipients').on('click', function(){
                $.post(ajaxurl, {
                    action: 'wbi_email_get_recipient_count',
                    _wpnonce: nonce,
                    segment: $('#wbi_em_segment').val(),
                    rfm_segment: $('#wbi_em_rfm_segment').val(),
                    min_orders: $('#wbi_em_min_orders').val(),
                    last_order_days: $('#wbi_em_last_order_days').val(),
                    min_spent: $('#wbi_em_min_spent').val()
                }, function(r){
                    if (r.success) {
                        $('#wbi-recipient-count-val').text(' → ' + r.data.count + ' destinatarios estimados');
                        $('#wbi-final-count').text('Destinatarios: ' + r.data.count);
                    }
                });
            });

            // Send test
            $('#wbi-send-test').on('click', function(){
                var content = '';
                if (typeof tinymce !== 'undefined' && tinymce.get('wbi_em_content')) {
                    content = tinymce.get('wbi_em_content').getContent();
                } else {
                    content = $('#wbi_em_content').val();
                }
                $('#wbi-test-result').text('Enviando...');
                $.post(ajaxurl, {
                    action: 'wbi_email_send_test',
                    _wpnonce: nonce,
                    subject: $('#wbi_em_subject').val(),
                    from_name: $('#wbi_em_from_name').val(),
                    from_email: $('#wbi_em_from_email').val(),
                    content: content
                }, function(r){
                    $('#wbi-test-result').text(r.success ? '✅ Prueba enviada.' : '❌ ' + (r.data.message || 'Error'));
                });
            });

            function collectData() {
                var content = '';
                if (typeof tinymce !== 'undefined' && tinymce.get('wbi_em_content')) {
                    content = tinymce.get('wbi_em_content').getContent();
                } else {
                    content = $('#wbi_em_content').val();
                }
                var segment_filter = {};
                var seg = $('#wbi_em_segment').val();
                if (seg === 'rfm_segment') {
                    segment_filter.rfm_segment = $('#wbi_em_rfm_segment').val();
                } else if (seg === 'custom') {
                    segment_filter.min_orders = $('#wbi_em_min_orders').val();
                    segment_filter.last_order_days = $('#wbi_em_last_order_days').val();
                    segment_filter.min_spent = $('#wbi_em_min_spent').val();
                }
                return {
                    action: 'wbi_email_save_campaign',
                    _wpnonce: nonce,
                    campaign_id: <?php echo intval( $campaign_id ); ?>,
                    name: $('#wbi_em_name').val(),
                    subject: $('#wbi_em_subject').val(),
                    from_name: $('#wbi_em_from_name').val(),
                    from_email: $('#wbi_em_from_email').val(),
                    template_id: $('#wbi_em_template_id').val(),
                    content: content,
                    segment: seg,
                    segment_filter: JSON.stringify(segment_filter),
                    scheduled_at: $('input[name="wbi_em_send_mode"]:checked').val() === 'schedule' ? $('#wbi_em_scheduled_at').val() : ''
                };
            }

            // Save draft
            $('#wbi-save-draft').on('click', function(){
                var data = collectData();
                data.status = 'draft';
                $.post(ajaxurl, data, function(r){
                    if (r.success) {
                        $('#wbi-launch-result').html('<div class="notice notice-success inline"><p>✅ Borrador guardado (ID ' + r.data.campaign_id + ')</p></div>');
                        if (!<?php echo intval( $campaign_id ); ?>) {
                            history.replaceState({}, '', '?page=wbi-email-marketing&action=edit&campaign_id=' + r.data.campaign_id);
                        }
                    } else {
                        $('#wbi-launch-result').html('<div class="notice notice-error inline"><p>❌ ' + (r.data.message || 'Error') + '</p></div>');
                    }
                });
            });

            // Launch campaign
            $('#wbi-launch-campaign').on('click', function(){
                if (!confirm('¿Estás seguro que querés lanzar esta campaña?')) return;
                var data = collectData();
                // First save, then start
                $.post(ajaxurl, data, function(r){
                    if (!r.success) {
                        $('#wbi-launch-result').html('<div class="notice notice-error inline"><p>❌ ' + (r.data.message || 'Error al guardar') + '</p></div>');
                        return;
                    }
                    var cid = r.data.campaign_id;
                    var send_mode = $('input[name="wbi_em_send_mode"]:checked').val();
                    if (send_mode === 'schedule') {
                        $.post(ajaxurl, {
                            action: 'wbi_email_save_campaign',
                            _wpnonce: nonce,
                            campaign_id: cid,
                            status: 'scheduled'
                        }, function(r2){
                            $('#wbi-launch-result').html(r2.success
                                ? '<div class="notice notice-success inline"><p>✅ Campaña programada.</p></div>'
                                : '<div class="notice notice-error inline"><p>❌ Error al programar.</p></div>'
                            );
                        });
                    } else {
                        $.post(ajaxurl, {
                            action: 'wbi_email_start_campaign',
                            _wpnonce: nonce,
                            campaign_id: cid
                        }, function(r2){
                            if (r2.success) {
                                $('#wbi-launch-result').html('<div class="notice notice-success inline"><p>✅ ' + r2.data.message + '</p></div>');
                            } else {
                                $('#wbi-launch-result').html('<div class="notice notice-error inline"><p>❌ ' + (r2.data.message || 'Error') + '</p></div>');
                            }
                        });
                    }
                });
            });

        }(jQuery));
        </script>
        <?php
    }

    // =========================================================================
    // CAMPAIGN REPORT
    // =========================================================================

    private function render_campaign_report() {
        $campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
        if ( ! $campaign_id ) {
            wp_die( 'Campaña no encontrada.' );
        }
        $campaign = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->tbl_campaigns} WHERE id = %d", $campaign_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( ! $campaign ) {
            wp_die( 'Campaña no encontrada.' );
        }

        $base_url = admin_url( 'admin.php?page=wbi-email-marketing' );

        $open_rate  = $campaign->total_sent > 0 ? round( ( $campaign->total_opened / $campaign->total_sent ) * 100, 1 ) : 0;
        $click_rate = $campaign->total_sent > 0 ? round( ( $campaign->total_clicked / $campaign->total_sent ) * 100, 1 ) : 0;

        // Recipients who opened
        $openers = $this->db->get_results( $this->db->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT s.email, s.first_name, s.last_name, sd.open_count, sd.click_count, sd.opened_at
             FROM {$this->tbl_sends} sd
             JOIN {$this->tbl_subscribers} s ON s.id = sd.subscriber_id
             WHERE sd.campaign_id = %d AND sd.open_count > 0
             ORDER BY sd.opened_at DESC LIMIT 100",
            $campaign_id
        ) );
        ?>
        <div class="wrap">
            <h1>📊 Reporte: <?php echo esc_html( $campaign->name ); ?>
                <a href="<?php echo esc_url( $base_url ); ?>" class="page-title-action">← Volver</a>
            </h1>

            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin:20px 0;">
                <?php
                $metrics = array(
                    array( 'Enviados',       $campaign->total_sent,          '#2196f3' ),
                    array( 'Abiertos',       $campaign->total_opened,        '#4caf50' ),
                    array( 'Tasa apertura',  $open_rate . '%',               '#4caf50' ),
                    array( 'Clicados',       $campaign->total_clicked,       '#ff9800' ),
                    array( 'Tasa clic',      $click_rate . '%',              '#ff9800' ),
                    array( 'Rebotados',      $campaign->total_bounced,       '#f44336' ),
                    array( 'Desuscriptos',   $campaign->total_unsubscribed,  '#9c27b0' ),
                );
                foreach ( $metrics as $m ) :
                ?>
                <div style="background:#fff;border:1px solid #ddd;border-top:4px solid <?php echo esc_attr( $m[2] ); ?>;padding:20px;border-radius:4px;text-align:center;">
                    <div style="font-size:28px;font-weight:bold;color:<?php echo esc_attr( $m[2] ); ?>;"><?php echo esc_html( $m[1] ); ?></div>
                    <div style="color:#666;font-size:13px;"><?php echo esc_html( $m[0] ); ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Progress bars -->
            <div style="background:#fff;border:1px solid #ddd;padding:20px;margin-bottom:20px;border-radius:4px;">
                <h3>Tasas de conversión</h3>
                <p>Tasa de apertura <strong><?php echo esc_html( $open_rate ); ?>%</strong></p>
                <div style="background:#eee;height:20px;border-radius:10px;overflow:hidden;">
                    <div style="width:<?php echo esc_attr( min( $open_rate, 100 ) ); ?>%;height:100%;background:#4caf50;"></div>
                </div>
                <p style="margin-top:15px;">Tasa de clic <strong><?php echo esc_html( $click_rate ); ?>%</strong></p>
                <div style="background:#eee;height:20px;border-radius:10px;overflow:hidden;">
                    <div style="width:<?php echo esc_attr( min( $click_rate, 100 ) ); ?>%;height:100%;background:#ff9800;"></div>
                </div>
            </div>

            <!-- Openers list -->
            <h3>Suscriptores que abrieron (últimos 100)</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Nombre</th>
                        <th>Aperturas</th>
                        <th>Clics</th>
                        <th>Primera apertura</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $openers ) ) : ?>
                    <tr><td colspan="5" style="text-align:center;padding:20px;color:#999;">Sin aperturas registradas aún.</td></tr>
                <?php else : ?>
                    <?php foreach ( $openers as $o ) : ?>
                    <tr>
                        <td><?php echo esc_html( $o->email ); ?></td>
                        <td><?php echo esc_html( trim( $o->first_name . ' ' . $o->last_name ) ); ?></td>
                        <td><?php echo intval( $o->open_count ); ?></td>
                        <td><?php echo intval( $o->click_count ); ?></td>
                        <td><?php echo $o->opened_at ? esc_html( date_i18n( 'd/m/Y H:i', strtotime( $o->opened_at ) ) ) : '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // =========================================================================
    // SUBSCRIBERS PAGE
    // =========================================================================

    private function render_subscribers_page() {
        $base_url   = admin_url( 'admin.php?page=wbi-email-marketing' );
        $subs_url   = $base_url . '&action=subscribers';
        $search     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $status_f   = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';

        $where  = array( '1=1' );
        $params = array();
        if ( $search ) {
            $where[]  = '(email LIKE %s OR first_name LIKE %s OR last_name LIKE %s)';
            $like     = '%' . $this->db->esc_like( $search ) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ( $status_f ) {
            $where[]  = 'status = %s';
            $params[] = $status_f;
        }
        $where_sql = implode( ' AND ', $where );

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $query = "SELECT * FROM {$this->tbl_subscribers} WHERE {$where_sql} ORDER BY subscribed_at DESC LIMIT 200";
        $subs  = $params
            ? $this->db->get_results( $this->db->prepare( $query, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            : $this->db->get_results( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $total = (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->tbl_subscribers}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $nonce = wp_create_nonce( 'wbi_email_nonce' );
        ?>
        <div class="wrap">
            <h1>👥 Suscriptores (<?php echo intval( $total ); ?>)
                <a href="<?php echo esc_url( $base_url ); ?>" class="page-title-action">← Campañas</a>
            </h1>

            <!-- Import / Export -->
            <div style="background:#fff;border:1px solid #ddd;padding:15px;margin-bottom:20px;display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap;">
                <div>
                    <h4 style="margin:0 0 8px;">Importar CSV</h4>
                    <input type="file" id="wbi-subs-csv-file" accept=".csv">
                    <button class="button" id="wbi-import-csv">Importar</button>
                    <p class="description">Columnas: email, first_name, last_name (cabecera requerida)</p>
                    <div id="wbi-import-result"></div>
                </div>
                <div>
                    <h4 style="margin:0 0 8px;">Exportar CSV</h4>
                    <a href="<?php echo esc_url( add_query_arg( array( 'wbi_export_subscribers' => 1, '_wpnonce' => wp_create_nonce( 'wbi_export_subs' ) ), admin_url( 'admin.php' ) ) ); ?>" class="button">
                        ⬇ Exportar suscriptores
                    </a>
                </div>
                <div style="flex:1;min-width:250px;">
                    <h4 style="margin:0 0 8px;">Agregar suscriptor</h4>
                    <table class="form-table" style="width:auto;margin:0;">
                        <tr>
                            <td><input type="email" id="wbi-new-sub-email" placeholder="email@ejemplo.com" class="regular-text"></td>
                            <td><input type="text" id="wbi-new-sub-fname" placeholder="Nombre" style="width:120px;"></td>
                            <td><input type="text" id="wbi-new-sub-lname" placeholder="Apellido" style="width:120px;"></td>
                            <td><button class="button button-primary" id="wbi-add-subscriber">Agregar</button></td>
                        </tr>
                    </table>
                    <div id="wbi-add-result"></div>
                </div>
            </div>

            <!-- Search -->
            <form method="get" action="">
                <input type="hidden" name="page" value="wbi-email-marketing">
                <input type="hidden" name="action" value="subscribers">
                <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Buscar por email o nombre...">
                <select name="status">
                    <option value="">Todos</option>
                    <option value="subscribed" <?php selected( $status_f, 'subscribed' ); ?>>Suscriptos</option>
                    <option value="unsubscribed" <?php selected( $status_f, 'unsubscribed' ); ?>>Desuscriptos</option>
                    <option value="bounced" <?php selected( $status_f, 'bounced' ); ?>>Rebotados</option>
                </select>
                <button class="button" type="submit">Filtrar</button>
            </form>
            <br>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Nombre</th>
                        <th>Fuente</th>
                        <th>Estado</th>
                        <th>Listas</th>
                        <th>Suscripto</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $subs ) ) : ?>
                    <tr><td colspan="7" style="text-align:center;padding:20px;color:#999;">Sin suscriptores.</td></tr>
                <?php else : ?>
                    <?php foreach ( $subs as $s ) : ?>
                    <tr id="sub-row-<?php echo intval( $s->id ); ?>">
                        <td><?php echo esc_html( $s->email ); ?></td>
                        <td><?php echo esc_html( trim( $s->first_name . ' ' . $s->last_name ) ); ?></td>
                        <td><?php echo esc_html( $s->source ); ?></td>
                        <td>
                            <?php
                            $status_badge = array(
                                'subscribed'   => array( '#00a32a', '✓ Suscripto' ),
                                'unsubscribed' => array( '#999', '✗ Desuscripto' ),
                                'bounced'      => array( '#d63638', '⚡ Rebotado' ),
                            );
                            $sb = $status_badge[ $s->status ] ?? array( '#ccc', $s->status );
                            echo '<span style="color:' . esc_attr( $sb[0] ) . ';">' . esc_html( $sb[1] ) . '</span>';
                            ?>
                        </td>
                        <td><?php echo esc_html( $s->lists ?: '—' ); ?></td>
                        <td><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $s->subscribed_at ) ) ); ?></td>
                        <td>
                            <button class="button button-small wbi-delete-sub" data-id="<?php echo intval( $s->id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
                                Eliminar
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <script>
        (function($){
            var nonce = '<?php echo esc_js( $nonce ); ?>';

            // Import CSV
            $('#wbi-import-csv').on('click', function(){
                var file = $('#wbi-subs-csv-file')[0].files[0];
                if (!file) { alert('Seleccioná un archivo CSV.'); return; }
                var formData = new FormData();
                formData.append('action', 'wbi_email_import_subscribers');
                formData.append('_wpnonce', nonce);
                formData.append('csv_file', file);
                $('#wbi-import-result').text('Importando...');
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(r){
                        if (r.success) {
                            $('#wbi-import-result').html('<span style="color:#00a32a;">✅ ' + r.data.message + '</span>');
                        } else {
                            $('#wbi-import-result').html('<span style="color:#d63638;">❌ ' + (r.data.message || 'Error') + '</span>');
                        }
                    }
                });
            });

            // Add subscriber
            $('#wbi-add-subscriber').on('click', function(){
                var email = $('#wbi-new-sub-email').val();
                if (!email) { alert('Ingresá un email.'); return; }
                $.post(ajaxurl, {
                    action: 'wbi_email_add_subscriber',
                    _wpnonce: nonce,
                    email: email,
                    first_name: $('#wbi-new-sub-fname').val(),
                    last_name: $('#wbi-new-sub-lname').val()
                }, function(r){
                    if (r.success) {
                        $('#wbi-add-result').html('<span style="color:#00a32a;">✅ ' + r.data.message + '</span>');
                        $('#wbi-new-sub-email,#wbi-new-sub-fname,#wbi-new-sub-lname').val('');
                    } else {
                        $('#wbi-add-result').html('<span style="color:#d63638;">❌ ' + (r.data.message || 'Error') + '</span>');
                    }
                });
            });

            // Delete subscriber
            $('.wbi-delete-sub').on('click', function(){
                if (!confirm('¿Eliminar este suscriptor?')) return;
                var id = $(this).data('id');
                var btn = $(this);
                $.post(ajaxurl, {
                    action: 'wbi_email_delete_subscriber',
                    _wpnonce: nonce,
                    subscriber_id: id
                }, function(r){
                    if (r.success) {
                        $('#sub-row-' + id).fadeOut();
                    } else {
                        alert('Error: ' + (r.data.message || 'No se pudo eliminar'));
                    }
                });
            });
        }(jQuery));
        </script>
        <?php

        // Handle CSV export
        if ( isset( $_GET['wbi_export_subscribers'] ) && isset( $_GET['_wpnonce'] ) ) {
            if ( wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'wbi_export_subs' ) ) {
                $this->export_subscribers_csv();
            }
        }
    }

    private function export_subscribers_csv() {
        $all = $this->db->get_results( "SELECT email, first_name, last_name, source, status, subscribed_at FROM {$this->tbl_subscribers} ORDER BY subscribed_at DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="wbi-subscribers-' . gmdate( 'Y-m-d' ) . '.csv"' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'email', 'first_name', 'last_name', 'source', 'status', 'subscribed_at' ) );
        foreach ( $all as $s ) {
            fputcsv( $out, array( $s->email, $s->first_name, $s->last_name, $s->source, $s->status, $s->subscribed_at ) );
        }
        fclose( $out );
        exit;
    }

    // =========================================================================
    // TEMPLATES PAGE
    // =========================================================================

    private function render_templates_page() {
        $base_url  = admin_url( 'admin.php?page=wbi-email-marketing' );
        $tpls_url  = $base_url . '&action=templates';
        $templates = $this->db->get_results( "SELECT * FROM {$this->tbl_templates} ORDER BY name" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $nonce     = wp_create_nonce( 'wbi_email_nonce' );
        $edit_id   = isset( $_GET['edit_template'] ) ? absint( $_GET['edit_template'] ) : 0;
        $edit_tpl  = $edit_id ? $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->tbl_templates} WHERE id = %d", $edit_id ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ?>
        <div class="wrap">
            <h1>📄 Templates de Email
                <a href="<?php echo esc_url( $base_url ); ?>" class="page-title-action">← Campañas</a>
            </h1>

            <!-- Template editor -->
            <div style="background:#fff;border:1px solid #ddd;padding:20px;margin-bottom:25px;">
                <h3><?php echo $edit_tpl ? 'Editar template: ' . esc_html( $edit_tpl->name ) : 'Nuevo template'; ?></h3>
                <table class="form-table">
                    <tr>
                        <th><label for="wbi_tpl_name">Nombre *</label></th>
                        <td><input type="text" id="wbi_tpl_name" class="regular-text" value="<?php echo $edit_tpl ? esc_attr( $edit_tpl->name ) : ''; ?>"></td>
                    </tr>
                </table>
                <p class="description">Etiquetas: <code>{{first_name}}</code> <code>{{last_name}}</code> <code>{{email}}</code> <code>{{shop_name}}</code> <code>{{shop_url}}</code> <code>{{unsubscribe_link}}</code></p>
                <?php
                wp_editor(
                    $edit_tpl ? $edit_tpl->content : '',
                    'wbi_tpl_content',
                    array(
                        'textarea_name' => 'wbi_tpl_content',
                        'media_buttons' => true,
                    )
                );
                ?>
                <p style="margin-top:15px;">
                    <button class="button button-primary" id="wbi-save-template">💾 Guardar template</button>
                    <?php if ( $edit_tpl ) : ?>
                    <a href="<?php echo esc_url( $tpls_url ); ?>" class="button">Cancelar</a>
                    <?php endif; ?>
                </p>
                <div id="wbi-tpl-result"></div>
            </div>

            <!-- Templates list -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Creado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $templates as $t ) : ?>
                <tr>
                    <td><?php echo esc_html( $t->name ); ?></td>
                    <td><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $t->created_at ) ) ); ?></td>
                    <td>
                        <a href="<?php echo esc_url( add_query_arg( array( 'edit_template' => $t->id ), $tpls_url ) ); ?>">Editar</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <script>
        (function($){
            var nonce = '<?php echo esc_js( $nonce ); ?>';
            $('#wbi-save-template').on('click', function(){
                var content = '';
                if (typeof tinymce !== 'undefined' && tinymce.get('wbi_tpl_content')) {
                    content = tinymce.get('wbi_tpl_content').getContent();
                } else {
                    content = $('#wbi_tpl_content').val();
                }
                $.post(ajaxurl, {
                    action: 'wbi_email_save_template',
                    _wpnonce: nonce,
                    template_id: <?php echo intval( $edit_id ); ?>,
                    name: $('#wbi_tpl_name').val(),
                    content: content
                }, function(r){
                    if (r.success) {
                        $('#wbi-tpl-result').html('<div class="notice notice-success inline"><p>✅ Template guardado.</p></div>');
                    } else {
                        $('#wbi-tpl-result').html('<div class="notice notice-error inline"><p>❌ ' + (r.data.message || 'Error') + '</p></div>');
                    }
                });
            });
        }(jQuery));
        </script>
        <?php
    }

    // =========================================================================
    // CRON SCHEDULE
    // =========================================================================

    public function add_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['wbi_5min'] ) ) {
            $schedules['wbi_5min'] = array(
                'interval' => 300,
                'display'  => 'Every 5 Minutes (WBI)',
            );
        }
        return $schedules;
    }

    // =========================================================================
    // BATCH EMAIL SENDING
    // =========================================================================

    public function process_send_batch() {
        // Find campaigns in 'sending' status
        $campaigns = $this->db->get_results( "SELECT * FROM {$this->tbl_campaigns} WHERE status = 'sending'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        foreach ( $campaigns as $campaign ) {
            $this->send_batch_for_campaign( $campaign );
        }

        // Check if any scheduled campaigns are due
        $now        = current_time( 'mysql' );
        $scheduled  = $this->db->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $this->db->prepare(
                "SELECT * FROM {$this->tbl_campaigns} WHERE status = 'scheduled' AND scheduled_at <= %s",
                $now
            )
        );
        foreach ( $scheduled as $campaign ) {
            $this->start_campaign( $campaign->id );
        }
    }

    private function send_batch_for_campaign( $campaign ) {
        $sends = $this->db->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $this->db->prepare(
                "SELECT * FROM {$this->tbl_sends} WHERE campaign_id = %d AND status = 'queued' LIMIT %d",
                $campaign->id,
                self::BATCH_SIZE
            )
        );

        if ( empty( $sends ) ) {
            // All sent — mark campaign done
            $this->db->update(
                $this->tbl_campaigns,
                array(
                    'status'  => 'sent',
                    'sent_at' => current_time( 'mysql' ),
                ),
                array( 'id' => $campaign->id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
            $this->notify_campaign_complete( $campaign );
            return;
        }

        $from_name  = $campaign->from_name ?: get_bloginfo( 'name' );
        $from_email = $campaign->from_email ?: get_option( 'admin_email' );
        $headers    = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_email . '>',
        );

        $sent_count = 0;
        foreach ( $sends as $send ) {
            $subscriber = $this->db->get_row( $this->db->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$this->tbl_subscribers} WHERE id = %d",
                $send->subscriber_id
            ) );
            if ( ! $subscriber || $subscriber->status !== 'subscribed' ) {
                $this->db->update(
                    $this->tbl_sends,
                    array( 'status' => 'failed' ),
                    array( 'id' => $send->id ),
                    array( '%s' ),
                    array( '%d' )
                );
                continue;
            }

            $body = $this->render_email_body( $campaign->content, $subscriber, $send->id );
            $sent = wp_mail( $subscriber->email, $campaign->subject, $body, $headers );

            if ( $sent ) {
                $this->db->update(
                    $this->tbl_sends,
                    array( 'status' => 'sent', 'sent_at' => current_time( 'mysql' ) ),
                    array( 'id' => $send->id ),
                    array( '%s', '%s' ),
                    array( '%d' )
                );
                $sent_count++;
            } else {
                $this->db->update(
                    $this->tbl_sends,
                    array( 'status' => 'failed' ),
                    array( 'id' => $send->id ),
                    array( '%s' ),
                    array( '%d' )
                );
            }
        }

        // Update campaign counters
        $this->db->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $this->db->prepare(
                "UPDATE {$this->tbl_campaigns} SET total_sent = total_sent + %d WHERE id = %d",
                $sent_count,
                $campaign->id
            )
        );
    }

    private function render_email_body( $content, $subscriber, $send_id ) {
        $unsubscribe_token = wp_hash( $subscriber->id . $subscriber->email );
        $unsubscribe_url   = add_query_arg( array(
            'wbi_email_unsubscribe' => $subscriber->id,
            'hash'                  => $unsubscribe_token,
        ), home_url( '/' ) );

        $tracking_pixel = '<img src="' . esc_url( add_query_arg( array(
            'wbi_email_track' => 'open',
            'sid'             => $send_id,
            'hash'            => wp_hash( 'open' . $send_id ),
        ), home_url( '/' ) ) ) . '" width="1" height="1" style="display:block;border:0;" alt="">';

        $tags = array(
            '{{first_name}}'      => $subscriber->first_name ?: '',
            '{{last_name}}'       => $subscriber->last_name ?: '',
            '{{email}}'           => $subscriber->email,
            '{{shop_name}}'       => get_bloginfo( 'name' ),
            '{{shop_url}}'        => home_url( '/' ),
            '{{unsubscribe_link}}'=> $unsubscribe_url,
        );

        $body = str_replace( array_keys( $tags ), array_values( $tags ), $content );

        // Rewrite links for click tracking
        $body = preg_replace_callback(
            '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i',
            function ( $matches ) use ( $send_id ) {
                $original_url  = $matches[1];
                // Skip tracking/unsubscribe links
                if ( strpos( $original_url, 'wbi_email_track' ) !== false ||
                     strpos( $original_url, 'wbi_email_unsubscribe' ) !== false ) {
                    return $matches[0];
                }
                $track_url = add_query_arg( array(
                    'wbi_email_track' => 'click',
                    'sid'             => $send_id,
                    'url'             => rawurlencode( $original_url ),
                    'hash'            => wp_hash( 'click' . $send_id . $original_url ),
                ), home_url( '/' ) );
                return str_replace( $original_url, $track_url, $matches[0] );
            },
            $body
        );

        // Append tracking pixel
        $body .= $tracking_pixel;

        return $body;
    }

    // =========================================================================
    // TRACKING & UNSUBSCRIBE
    // =========================================================================

    public function handle_tracking() {
        $track = isset( $_GET['wbi_email_track'] ) ? sanitize_key( $_GET['wbi_email_track'] ) : '';
        $unsub = isset( $_GET['wbi_email_unsubscribe'] ) ? absint( $_GET['wbi_email_unsubscribe'] ) : 0;

        if ( $track === 'open' ) {
            $send_id = isset( $_GET['sid'] ) ? absint( $_GET['sid'] ) : 0;
            $hash    = isset( $_GET['hash'] ) ? sanitize_text_field( wp_unslash( $_GET['hash'] ) ) : '';
            if ( $send_id && hash_equals( wp_hash( 'open' . $send_id ), $hash ) ) {
                $this->db->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $this->db->prepare(
                        "UPDATE {$this->tbl_sends} SET status='opened', opened_at=COALESCE(opened_at,%s), open_count=open_count+1 WHERE id=%d",
                        current_time( 'mysql' ),
                        $send_id
                    )
                );
                // Update campaign counter (only on first open per send)
                $send = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->tbl_sends} WHERE id = %d", $send_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                if ( $send && $send->open_count == 1 ) {
                    $this->db->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $this->db->prepare(
                            "UPDATE {$this->tbl_campaigns} SET total_opened=total_opened+1 WHERE id=%d",
                            $send->campaign_id
                        )
                    );
                }
            }
            // Return 1x1 transparent GIF
            header( 'Content-Type: image/gif' );
            header( 'Cache-Control: no-cache, no-store, must-revalidate' );
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );
            exit;
        }

        if ( $track === 'click' ) {
            $send_id      = isset( $_GET['sid'] ) ? absint( $_GET['sid'] ) : 0;
            $hash         = isset( $_GET['hash'] ) ? sanitize_text_field( wp_unslash( $_GET['hash'] ) ) : '';
            $redirect_url = isset( $_GET['url'] ) ? rawurldecode( wp_unslash( $_GET['url'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $redirect_url = esc_url_raw( $redirect_url );

            if ( $send_id && $redirect_url ) {
                $expected_hash = wp_hash( 'click' . $send_id . rawurldecode( isset( $_GET['url'] ) ? wp_unslash( $_GET['url'] ) : '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                if ( hash_equals( $expected_hash, $hash ) ) {
                    $this->db->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $this->db->prepare(
                            "UPDATE {$this->tbl_sends} SET status='clicked', clicked_at=COALESCE(clicked_at,%s), click_count=click_count+1 WHERE id=%d",
                            current_time( 'mysql' ),
                            $send_id
                        )
                    );
                    $send = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->tbl_sends} WHERE id = %d", $send_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    if ( $send && $send->click_count == 1 ) {
                        $this->db->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                            $this->db->prepare(
                                "UPDATE {$this->tbl_campaigns} SET total_clicked=total_clicked+1 WHERE id=%d",
                                $send->campaign_id
                            )
                        );
                    }
                }
                wp_redirect( $redirect_url );
                exit;
            }
        }

        if ( $unsub ) {
            $hash = isset( $_GET['hash'] ) ? sanitize_text_field( wp_unslash( $_GET['hash'] ) ) : '';
            $sub  = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->tbl_subscribers} WHERE id = %d", $unsub ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ( $sub ) {
                $expected = wp_hash( $sub->id . $sub->email );
                if ( hash_equals( $expected, $hash ) ) {
                    $this->db->update(
                        $this->tbl_subscribers,
                        array( 'status' => 'unsubscribed', 'unsubscribed_at' => current_time( 'mysql' ) ),
                        array( 'id' => $sub->id ),
                        array( '%s', '%s' ),
                        array( '%d' )
                    );
                    // Increment campaign counter
                    $this->db->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        "UPDATE {$this->tbl_campaigns} SET total_unsubscribed=total_unsubscribed+1 WHERE id IN (SELECT campaign_id FROM {$this->tbl_sends} WHERE subscriber_id={$sub->id} AND status='sent')" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    );
                    wp_die(
                        '<p style="font-family:sans-serif;text-align:center;padding:60px;">✅ Fuiste desuscripto/a correctamente. No recibirás más emails.</p>',
                        'Desuscripto',
                        array( 'response' => 200 )
                    );
                }
            }
            wp_die( '<p style="font-family:sans-serif;text-align:center;padding:60px;">El enlace de desuscripción no es válido.</p>', 'Error', array( 'response' => 400 ) );
        }
    }

    // =========================================================================
    // WOOCOMMERCE INTEGRATION
    // =========================================================================

    public function sync_new_wc_customer( $customer_id, $new_customer_data, $password_generated ) {
        if ( empty( $new_customer_data['user_email'] ) ) {
            return;
        }
        $email      = sanitize_email( $new_customer_data['user_email'] );
        $first_name = isset( $new_customer_data['first_name'] ) ? sanitize_text_field( $new_customer_data['first_name'] ) : '';
        $last_name  = isset( $new_customer_data['last_name'] ) ? sanitize_text_field( $new_customer_data['last_name'] ) : '';
        $this->add_or_update_subscriber( $email, $first_name, $last_name, 'woocommerce', $customer_id );
    }

    public function handle_checkout_optin( $order_id, $data ) {
        $opts = get_option( 'wbi_modules_settings', array() );
        if ( empty( $opts['wbi_email_checkout_optin'] ) ) {
            return;
        }
        $optin = isset( $_POST['wbi_email_optin'] ) ? (bool) sanitize_text_field( wp_unslash( $_POST['wbi_email_optin'] ) ) : false;
        if ( ! $optin ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $email      = $order->get_billing_email();
        $first_name = $order->get_billing_first_name();
        $last_name  = $order->get_billing_last_name();
        $this->add_or_update_subscriber( $email, $first_name, $last_name, 'checkout', $order->get_customer_id() );
    }

    public function add_checkout_optin_field( $fields ) {
        $opts = get_option( 'wbi_modules_settings', array() );
        if ( empty( $opts['wbi_email_checkout_optin'] ) ) {
            return $fields;
        }
        $fields['billing']['wbi_email_optin'] = array(
            'type'     => 'checkbox',
            'label'    => 'Suscribirme a novedades y ofertas',
            'required' => false,
            'class'    => array( 'form-row-wide' ),
            'priority' => 120,
        );
        return $fields;
    }

    private function add_or_update_subscriber( $email, $first_name, $last_name, $source, $customer_id = 0 ) {
        $email = sanitize_email( $email );
        if ( ! is_email( $email ) ) {
            return;
        }
        $existing = $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->tbl_subscribers} WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $existing ) {
            return; // Already exists — don't overwrite status
        }
        $this->db->insert(
            $this->tbl_subscribers,
            array(
                'email'       => $email,
                'first_name'  => sanitize_text_field( $first_name ),
                'last_name'   => sanitize_text_field( $last_name ),
                'customer_id' => absint( $customer_id ),
                'source'      => sanitize_text_field( $source ),
                'status'      => 'subscribed',
            ),
            array( '%s', '%s', '%s', '%d', '%s', '%s' )
        );
    }

    // =========================================================================
    // CAMPAIGN MANAGEMENT HELPERS
    // =========================================================================

    private function start_campaign( $campaign_id ) {
        $campaign = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->tbl_campaigns} WHERE id = %d", $campaign_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( ! $campaign ) {
            return false;
        }

        $subscribers = $this->get_segment_subscribers( $campaign->segment, json_decode( $campaign->segment_filter, true ) );

        // Queue sends
        foreach ( $subscribers as $sub ) {
            $existing = $this->db->get_var( $this->db->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT id FROM {$this->tbl_sends} WHERE campaign_id=%d AND subscriber_id=%d",
                $campaign_id,
                $sub->id
            ) );
            if ( $existing ) {
                continue;
            }
            $this->db->insert(
                $this->tbl_sends,
                array(
                    'campaign_id'   => $campaign_id,
                    'subscriber_id' => $sub->id,
                    'email'         => $sub->email,
                    'status'        => 'queued',
                ),
                array( '%d', '%d', '%s', '%s' )
            );
        }

        $total = count( $subscribers );
        $this->db->update(
            $this->tbl_campaigns,
            array( 'status' => 'sending', 'total_recipients' => $total ),
            array( 'id' => $campaign_id ),
            array( '%s', '%d' ),
            array( '%d' )
        );

        return $total;
    }

    private function get_segment_subscribers( $segment, $filter ) {
        $filter = is_array( $filter ) ? $filter : array();

        switch ( $segment ) {
            case 'customers':
                return $this->db->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    "SELECT * FROM {$this->tbl_subscribers} WHERE status='subscribed' AND customer_id > 0"
                );

            case 'leads':
                if ( ! class_exists( 'WBI_CRM_Module' ) ) {
                    return array();
                }
                global $wpdb;
                $leads_tbl = $wpdb->prefix . 'wbi_crm_leads';
                if ( $wpdb->get_var( "SHOW TABLES LIKE '$leads_tbl'" ) !== $leads_tbl ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    return array();
                }
                $lead_emails = $wpdb->get_col( "SELECT DISTINCT email FROM $leads_tbl WHERE email != ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                if ( empty( $lead_emails ) ) return array();
                $placeholders = implode( ',', array_fill( 0, count( $lead_emails ), '%s' ) );
                return $this->db->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $this->db->prepare( "SELECT * FROM {$this->tbl_subscribers} WHERE status='subscribed' AND email IN ($placeholders)", $lead_emails ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                );

            case 'rfm_segment':
                if ( ! class_exists( 'WBI_Scoring_Module' ) || empty( $filter['rfm_segment'] ) ) {
                    return array();
                }
                global $wpdb;
                $scoring_tbl = $wpdb->prefix . 'wbi_customer_scores';
                if ( $wpdb->get_var( "SHOW TABLES LIKE '$scoring_tbl'" ) !== $scoring_tbl ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    return array();
                }
                $rfm_emails = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    "SELECT DISTINCT u.user_email FROM $scoring_tbl sc JOIN {$wpdb->users} u ON u.ID=sc.customer_id WHERE sc.rfm_segment=%s",
                    $filter['rfm_segment']
                ) );
                if ( empty( $rfm_emails ) ) return array();
                $placeholders = implode( ',', array_fill( 0, count( $rfm_emails ), '%s' ) );
                return $this->db->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $this->db->prepare( "SELECT * FROM {$this->tbl_subscribers} WHERE status='subscribed' AND email IN ($placeholders)", $rfm_emails ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                );

            case 'custom':
                $conditions = array( "status='subscribed'" );
                $params     = array();
                if ( ! empty( $filter['min_orders'] ) && intval( $filter['min_orders'] ) > 0 ) {
                    $conditions[] = 'customer_id IN (SELECT customer_id FROM ' . $this->db->prefix . "wc_order_stats WHERE order_count >= %d AND customer_id > 0)";
                    $params[] = intval( $filter['min_orders'] );
                }
                if ( ! empty( $filter['min_spent'] ) && floatval( $filter['min_spent'] ) > 0 ) {
                    $conditions[] = 'customer_id IN (SELECT customer_id FROM ' . $this->db->prefix . "wc_order_stats WHERE net_total >= %f AND customer_id > 0)";
                    $params[] = floatval( $filter['min_spent'] );
                }
                $where = implode( ' AND ', $conditions );
                $sql   = "SELECT * FROM {$this->tbl_subscribers} WHERE {$where}";
                return $params
                    ? $this->db->get_results( $this->db->prepare( $sql, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    : $this->db->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            case 'all':
            default:
                return $this->db->get_results( "SELECT * FROM {$this->tbl_subscribers} WHERE status='subscribed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    }

    private function notify_campaign_complete( $campaign ) {
        if ( class_exists( 'WBI_Notifications_Module' ) && method_exists( 'WBI_Notifications_Module', 'add_notification' ) ) {
            WBI_Notifications_Module::add_notification(
                'email_marketing',
                '📧 Campaña "' . esc_html( $campaign->name ) . '" finalizada. Enviados: ' . intval( $campaign->total_sent ),
                admin_url( 'admin.php?page=wbi-email-marketing&action=report&campaign_id=' . $campaign->id )
            );
        }
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    private function check_nonce_and_permissions() {
        if ( ! check_ajax_referer( 'wbi_email_nonce', '_wpnonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Nonce inválido.' ), 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
        }
    }

    public function ajax_save_campaign() {
        $this->check_nonce_and_permissions();

        $campaign_id    = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
        $name           = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $subject        = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
        $from_name      = isset( $_POST['from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['from_name'] ) ) : '';
        $from_email     = isset( $_POST['from_email'] ) ? sanitize_email( wp_unslash( $_POST['from_email'] ) ) : '';
        $template_id    = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
        $content        = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '';
        $segment        = isset( $_POST['segment'] ) ? sanitize_key( $_POST['segment'] ) : 'all';
        $segment_filter = isset( $_POST['segment_filter'] ) ? sanitize_text_field( wp_unslash( $_POST['segment_filter'] ) ) : '{}';
        $status         = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'draft';
        $scheduled_at   = isset( $_POST['scheduled_at'] ) ? sanitize_text_field( wp_unslash( $_POST['scheduled_at'] ) ) : '';

        if ( ! $name || ! $subject ) {
            wp_send_json_error( array( 'message' => 'Nombre y asunto son requeridos.' ) );
        }

        $allowed_statuses = array( 'draft', 'scheduled', 'sending', 'sent', 'paused' );
        if ( ! in_array( $status, $allowed_statuses, true ) ) {
            $status = 'draft';
        }

        $data = array(
            'name'            => $name,
            'subject'         => $subject,
            'from_name'       => $from_name,
            'from_email'      => $from_email,
            'template_id'     => $template_id,
            'content'         => $content,
            'segment'         => $segment,
            'segment_filter'  => $segment_filter,
            'status'          => $status,
        );
        $formats = array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' );

        if ( $scheduled_at ) {
            $data['scheduled_at'] = str_replace( 'T', ' ', sanitize_text_field( $scheduled_at ) );
            $formats[] = '%s';
        }

        if ( $campaign_id ) {
            $this->db->update( $this->tbl_campaigns, $data, array( 'id' => $campaign_id ), $formats, array( '%d' ) );
        } else {
            $data['created_by'] = get_current_user_id();
            $formats[]          = '%d';
            $this->db->insert( $this->tbl_campaigns, $data, $formats );
            $campaign_id = $this->db->insert_id;
        }

        wp_send_json_success( array( 'campaign_id' => $campaign_id ) );
    }

    public function ajax_send_test() {
        $this->check_nonce_and_permissions();

        $subject    = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : 'Prueba';
        $from_name  = isset( $_POST['from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['from_name'] ) ) : get_bloginfo( 'name' );
        $from_email = isset( $_POST['from_email'] ) ? sanitize_email( wp_unslash( $_POST['from_email'] ) ) : get_option( 'admin_email' );
        $content    = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '';

        $admin_email = get_option( 'admin_email' );
        $headers     = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_email . '>',
        );
        $test_body = str_replace(
            array( '{{first_name}}', '{{last_name}}', '{{email}}', '{{shop_name}}', '{{shop_url}}', '{{unsubscribe_link}}' ),
            array( 'Admin', '', $admin_email, get_bloginfo( 'name' ), home_url( '/' ), '#' ),
            $content
        );

        $sent = wp_mail( $admin_email, '[PRUEBA] ' . $subject, $test_body, $headers );
        if ( $sent ) {
            wp_send_json_success( array( 'message' => 'Email de prueba enviado a ' . $admin_email ) );
        } else {
            wp_send_json_error( array( 'message' => 'Error al enviar. Revisá la configuración de email.' ) );
        }
    }

    public function ajax_start_campaign() {
        $this->check_nonce_and_permissions();

        $campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
        if ( ! $campaign_id ) {
            wp_send_json_error( array( 'message' => 'ID de campaña requerido.' ) );
        }

        $total = $this->start_campaign( $campaign_id );
        if ( false === $total ) {
            wp_send_json_error( array( 'message' => 'Campaña no encontrada.' ) );
        }

        wp_send_json_success( array( 'message' => "Campaña iniciada. {$total} destinatarios en cola." ) );
    }

    public function ajax_pause_campaign() {
        $this->check_nonce_and_permissions();

        $campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
        if ( ! $campaign_id ) {
            wp_send_json_error( array( 'message' => 'ID de campaña requerido.' ) );
        }
        $this->db->update(
            $this->tbl_campaigns,
            array( 'status' => 'paused' ),
            array( 'id' => $campaign_id ),
            array( '%s' ),
            array( '%d' )
        );
        wp_send_json_success( array( 'message' => 'Campaña pausada.' ) );
    }

    public function ajax_import_subscribers() {
        $this->check_nonce_and_permissions();

        if ( empty( $_FILES['csv_file'] ) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( array( 'message' => 'No se recibió el archivo.' ) );
        }

        $tmp_file = $_FILES['csv_file']['tmp_name'];
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle   = fopen( $tmp_file, 'r' );
        if ( ! $handle ) {
            wp_send_json_error( array( 'message' => 'No se pudo leer el archivo.' ) );
        }

        $headers    = array_map( 'trim', fgetcsv( $handle ) );
        $email_idx  = array_search( 'email', $headers, true );
        $fname_idx  = array_search( 'first_name', $headers, true );
        $lname_idx  = array_search( 'last_name', $headers, true );

        if ( $email_idx === false ) {
            fclose( $handle );
            wp_send_json_error( array( 'message' => 'El CSV debe tener una columna "email".' ) );
        }

        $imported = 0;
        $skipped  = 0;
        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            $email = isset( $row[ $email_idx ] ) ? sanitize_email( trim( $row[ $email_idx ] ) ) : '';
            if ( ! is_email( $email ) ) {
                $skipped++;
                continue;
            }

            $fname = $fname_idx !== false && isset( $row[ $fname_idx ] ) ? sanitize_text_field( trim( $row[ $fname_idx ] ) ) : '';
            $lname = $lname_idx !== false && isset( $row[ $lname_idx ] ) ? sanitize_text_field( trim( $row[ $lname_idx ] ) ) : '';

            $existing = $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->tbl_subscribers} WHERE email=%s", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ( $existing ) {
                $skipped++;
                continue;
            }

            $this->db->insert(
                $this->tbl_subscribers,
                array(
                    'email'      => $email,
                    'first_name' => $fname,
                    'last_name'  => $lname,
                    'source'     => 'import',
                    'status'     => 'subscribed',
                ),
                array( '%s', '%s', '%s', '%s', '%s' )
            );
            $imported++;
        }
        fclose( $handle );

        wp_send_json_success( array( 'message' => "Importados: {$imported}. Omitidos: {$skipped}." ) );
    }

    public function ajax_get_recipient_count() {
        $this->check_nonce_and_permissions();

        $segment        = isset( $_POST['segment'] ) ? sanitize_key( $_POST['segment'] ) : 'all';
        $filter         = array();
        $filter['rfm_segment'] = isset( $_POST['rfm_segment'] ) ? sanitize_text_field( wp_unslash( $_POST['rfm_segment'] ) ) : '';
        $filter['min_orders']  = isset( $_POST['min_orders'] ) ? absint( $_POST['min_orders'] ) : 0;
        $filter['last_order_days'] = isset( $_POST['last_order_days'] ) ? absint( $_POST['last_order_days'] ) : 0;
        $filter['min_spent']   = isset( $_POST['min_spent'] ) ? floatval( $_POST['min_spent'] ) : 0;

        $subs  = $this->get_segment_subscribers( $segment, $filter );
        $count = count( $subs );

        wp_send_json_success( array( 'count' => $count ) );
    }

    public function ajax_save_template() {
        $this->check_nonce_and_permissions();

        $template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
        $name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $content     = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '';

        if ( ! $name ) {
            wp_send_json_error( array( 'message' => 'El nombre es requerido.' ) );
        }

        if ( $template_id ) {
            $this->db->update(
                $this->tbl_templates,
                array( 'name' => $name, 'content' => $content ),
                array( 'id' => $template_id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
        } else {
            $this->db->insert(
                $this->tbl_templates,
                array( 'name' => $name, 'content' => $content ),
                array( '%s', '%s' )
            );
        }

        wp_send_json_success();
    }

    public function ajax_delete_subscriber() {
        $this->check_nonce_and_permissions();

        $sub_id = isset( $_POST['subscriber_id'] ) ? absint( $_POST['subscriber_id'] ) : 0;
        if ( ! $sub_id ) {
            wp_send_json_error( array( 'message' => 'ID requerido.' ) );
        }
        $this->db->delete( $this->tbl_subscribers, array( 'id' => $sub_id ), array( '%d' ) );
        wp_send_json_success();
    }
}
