<?php
/**
 * WBI CRM Module — Sales Pipeline
 *
 * Módulo de CRM con pipeline de ventas tipo Kanban, gestión de leads,
 * actividades programadas y conversión a clientes WooCommerce.
 *
 * @package WBI_Suite
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_CRM_Module {

    /** @var string Nonce action for general CRM forms */
    const NONCE_ACTION = 'wbi_crm_nonce';

    public function __construct() {
        // Admin menu
        add_action( 'admin_menu', array( $this, 'register_pages' ), 100 );

        // DB tables on admin_init (once)
        add_action( 'admin_init', array( $this, 'maybe_create_tables' ) );

        // Enqueue assets
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // AJAX handlers
        add_action( 'wp_ajax_wbi_crm_move_lead',         array( $this, 'ajax_move_lead' ) );
        add_action( 'wp_ajax_wbi_crm_quick_add',          array( $this, 'ajax_quick_add' ) );
        add_action( 'wp_ajax_wbi_crm_mark_won',           array( $this, 'ajax_mark_won' ) );
        add_action( 'wp_ajax_wbi_crm_mark_lost',          array( $this, 'ajax_mark_lost' ) );
        add_action( 'wp_ajax_wbi_crm_complete_activity',  array( $this, 'ajax_complete_activity' ) );
        add_action( 'wp_ajax_wbi_crm_add_activity',       array( $this, 'ajax_add_activity' ) );
        add_action( 'wp_ajax_wbi_crm_delete_lead',        array( $this, 'ajax_delete_lead' ) );
        add_action( 'wp_ajax_wbi_crm_reorder_stages',     array( $this, 'ajax_reorder_stages' ) );
        add_action( 'wp_ajax_wbi_crm_convert_customer',   array( $this, 'ajax_convert_customer' ) );

        // WooCommerce auto-capture
        add_action( 'woocommerce_new_order', array( $this, 'maybe_auto_capture_lead' ) );

        // Dashboard widget integration
        add_action( 'wbi_dashboard_widgets', array( $this, 'register_dashboard_widget' ) );

        // Email Reports integration
        add_filter( 'wbi_email_report_sections', array( $this, 'add_email_report_section' ) );
    }

    // =========================================================================
    // DATABASE SETUP
    // =========================================================================

    /**
     * Create DB tables if they don't exist yet (stored in a version option).
     */
    public function maybe_create_tables() {
        $installed = get_option( 'wbi_crm_db_version', '' );
        if ( '1.0' === $installed ) {
            return;
        }
        $this->create_tables();
        update_option( 'wbi_crm_db_version', '1.0' );
    }

    public function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Stages
        $sql_stages = "CREATE TABLE {$wpdb->prefix}wbi_crm_stages (
            id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name     VARCHAR(100) NOT NULL,
            position INT          NOT NULL DEFAULT 0,
            is_won   TINYINT(1)   NOT NULL DEFAULT 0,
            color    VARCHAR(7)   NOT NULL DEFAULT '#0073aa',
            PRIMARY KEY (id)
        ) $charset;";
        dbDelta( $sql_stages );

        // Leads
        $sql_leads = "CREATE TABLE {$wpdb->prefix}wbi_crm_leads (
            id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title              VARCHAR(255)    NOT NULL DEFAULT '',
            contact_name       VARCHAR(255)    NOT NULL DEFAULT '',
            contact_email      VARCHAR(255)    NOT NULL DEFAULT '',
            contact_phone      VARCHAR(50)     NOT NULL DEFAULT '',
            company            VARCHAR(255)    NOT NULL DEFAULT '',
            customer_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
            source             VARCHAR(100)    NOT NULL DEFAULT 'manual',
            stage_id           BIGINT UNSIGNED NOT NULL DEFAULT 0,
            priority           VARCHAR(10)     NOT NULL DEFAULT 'medium',
            expected_revenue   DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
            probability        INT             NOT NULL DEFAULT 50,
            assigned_to        BIGINT UNSIGNED NOT NULL DEFAULT 0,
            notes              LONGTEXT,
            status             VARCHAR(10)     NOT NULL DEFAULT 'active',
            lost_reason        VARCHAR(255)    NOT NULL DEFAULT '',
            next_activity_date DATE            DEFAULT NULL,
            next_activity_type VARCHAR(100)    NOT NULL DEFAULT '',
            next_activity_note TEXT,
            created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            won_at             DATETIME        DEFAULT NULL,
            lost_at            DATETIME        DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_stage   (stage_id),
            KEY idx_status  (status),
            KEY idx_assigned(assigned_to)
        ) $charset;";
        dbDelta( $sql_leads );

        // Activities
        $sql_activities = "CREATE TABLE {$wpdb->prefix}wbi_crm_activities (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lead_id     BIGINT UNSIGNED NOT NULL,
            user_id     BIGINT UNSIGNED NOT NULL,
            type        VARCHAR(50)     NOT NULL DEFAULT 'note',
            summary     VARCHAR(255)    NOT NULL DEFAULT '',
            description TEXT,
            due_date    DATE            DEFAULT NULL,
            done        TINYINT(1)      NOT NULL DEFAULT 0,
            done_at     DATETIME        DEFAULT NULL,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_lead (lead_id),
            KEY idx_done (done)
        ) $charset;";
        dbDelta( $sql_activities );

        // Seed default stages if none exist
        $count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wbi_crm_stages" );
        if ( 0 === (int) $count ) {
            $defaults = array(
                array( 'Nuevo',        0, 0, '#6366f1' ),
                array( 'Calificado',   1, 0, '#0284c7' ),
                array( 'Propuesta',    2, 0, '#d97706' ),
                array( 'Negociación',  3, 0, '#7c3aed' ),
                array( 'Ganado',       4, 1, '#059669' ),
            );
            foreach ( $defaults as $s ) {
                $wpdb->insert(
                    "{$wpdb->prefix}wbi_crm_stages",
                    array( 'name' => $s[0], 'position' => $s[1], 'is_won' => $s[2], 'color' => $s[3] ),
                    array( '%s', '%d', '%d', '%s' )
                );
            }
        }
    }

    // =========================================================================
    // ADMIN MENU
    // =========================================================================

    public function register_pages() {
        $can = $this->user_can_access();

        add_submenu_page(
            'wbi-dashboard-view',
            'CRM / Pipeline de Ventas',
            '<span class="dashicons dashicons-businessman" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> CRM',
            $can,
            'wbi-crm',
            array( $this, 'render_page' )
        );
    }

    private function user_can_access() {
        $perms = get_option( 'wbi_permissions_crm', array() );
        if ( empty( $perms ) ) {
            return 'manage_woocommerce';
        }
        foreach ( $perms as $cap ) {
            if ( current_user_can( $cap ) ) {
                return $cap;
            }
        }
        return 'manage_woocommerce';
    }

    // =========================================================================
    // ASSET ENQUEUEING
    // =========================================================================

    public function enqueue_assets( $hook ) {
        // Only load on CRM pages
        if ( false === strpos( $hook, 'wbi-crm' ) && false === strpos( $hook, 'page_wbi-crm' ) ) {
            $screen = get_current_screen();
            if ( ! $screen || false === strpos( $screen->id, 'wbi-crm' ) ) {
                return;
            }
        }

        // Inline JS for kanban drag-and-drop (no external deps)
        $ajax_url = admin_url( 'admin-ajax.php' );
        $nonce    = wp_create_nonce( self::NONCE_ACTION );

        wp_add_inline_script(
            'jquery',
            $this->get_kanban_js( $ajax_url, $nonce )
        );
    }

    // =========================================================================
    // PAGE ROUTER
    // =========================================================================

    public function render_page() {
        $cap = $this->user_can_access();
        if ( ! current_user_can( $cap ) ) {
            wp_die( esc_html__( 'No tenés permisos para acceder a esta página.', 'wbi-suite' ) );
        }

        $action  = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
        $lead_id = isset( $_GET['lead_id'] ) ? absint( $_GET['lead_id'] ) : 0;

        switch ( $action ) {
            case 'view':
                $this->render_lead_detail( $lead_id );
                break;
            case 'stages':
                $this->render_stages_page();
                break;
            case 'reports':
                $this->render_reports_page();
                break;
            case 'settings':
                $this->render_settings_page();
                break;
            default:
                $this->render_kanban_board();
                break;
        }
    }

    // =========================================================================
    // KANBAN BOARD
    // =========================================================================

    private function render_kanban_board() {
        global $wpdb;

        // Filters
        $filter_assigned = isset( $_GET['assigned_to'] ) ? absint( $_GET['assigned_to'] ) : 0;
        $filter_priority = isset( $_GET['priority'] )    ? sanitize_key( $_GET['priority'] ) : '';
        $filter_source   = isset( $_GET['source'] )      ? sanitize_key( $_GET['source'] ) : '';
        $filter_search   = isset( $_GET['s'] )           ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

        $stages = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wbi_crm_stages ORDER BY position ASC" );

        // Build WHERE clause for leads
        $where  = "WHERE l.status = 'active'";
        $params = array();

        if ( $filter_assigned ) {
            $where   .= ' AND l.assigned_to = %d';
            $params[] = $filter_assigned;
        }
        if ( $filter_priority ) {
            $where   .= ' AND l.priority = %s';
            $params[] = $filter_priority;
        }
        if ( $filter_source ) {
            $where   .= ' AND l.source = %s';
            $params[] = $filter_source;
        }
        if ( $filter_search ) {
            $where   .= ' AND (l.title LIKE %s OR l.contact_name LIKE %s OR l.company LIKE %s OR l.contact_email LIKE %s)';
            $like     = '%' . $wpdb->esc_like( $filter_search ) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $query = "SELECT l.*, u.display_name as assigned_name, u.user_email as assigned_email
                  FROM {$wpdb->prefix}wbi_crm_leads l
                  LEFT JOIN {$wpdb->users} u ON l.assigned_to = u.ID
                  $where
                  ORDER BY l.created_at DESC";

        if ( ! empty( $params ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $leads = $wpdb->get_results( $wpdb->prepare( $query, $params ) );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $leads = $wpdb->get_results( $query );
        }

        // Group leads by stage
        $leads_by_stage = array();
        foreach ( $leads as $lead ) {
            $leads_by_stage[ $lead->stage_id ][] = $lead;
        }

        // Users for filter
        $users = get_users( array( 'orderby' => 'display_name' ) );

        $base_url    = admin_url( 'admin.php?page=wbi-crm' );
        $stages_url  = admin_url( 'admin.php?page=wbi-crm&action=stages' );
        $reports_url = admin_url( 'admin.php?page=wbi-crm&action=reports' );
        $settings_url = admin_url( 'admin.php?page=wbi-crm&action=settings' );
        $nonce       = wp_create_nonce( self::NONCE_ACTION );

        ?>
        <div class="wbi-wrap">
            <div class="wbi-header">
                <div>
                    <h1>🎯 CRM / Pipeline de Ventas</h1>
                    <p style="margin:4px 0 0;color:var(--wbi-muted);font-size:13px;">
                        <?php echo esc_html( count( $leads ) ); ?> leads activos
                        · Valor: <strong><?php echo esc_html( wc_price( array_sum( array_column( $leads, 'expected_revenue' ) ) ) ); ?></strong>
                    </p>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <a href="<?php echo esc_url( $reports_url ); ?>" class="wbi-btn wbi-btn-secondary">📊 Reportes</a>
                    <a href="<?php echo esc_url( $stages_url ); ?>" class="wbi-btn wbi-btn-secondary">⚙️ Etapas</a>
                    <a href="<?php echo esc_url( $settings_url ); ?>" class="wbi-btn wbi-btn-secondary">🔧 Config</a>
                    <button type="button" class="wbi-btn wbi-btn-primary" id="wbi-crm-quick-add-btn">+ Agregar Lead</button>
                </div>
            </div>

            <?php // Filter bar ?>
            <div class="wbi-filter-bar" style="margin-bottom:16px;">
                <form method="get" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;width:100%;">
                    <input type="hidden" name="page" value="wbi-crm">
                    <input type="search" name="s" placeholder="Buscar lead…" value="<?php echo esc_attr( $filter_search ); ?>" style="min-width:180px;">
                    <select name="assigned_to">
                        <option value="">— Asignado a —</option>
                        <?php foreach ( $users as $u ) : ?>
                            <option value="<?php echo esc_attr( $u->ID ); ?>" <?php selected( $filter_assigned, $u->ID ); ?>><?php echo esc_html( $u->display_name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="priority">
                        <option value="">— Prioridad —</option>
                        <?php foreach ( array( 'low' => 'Baja', 'medium' => 'Media', 'high' => 'Alta', 'urgent' => 'Urgente' ) as $k => $v ) : ?>
                            <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filter_priority, $k ); ?>><?php echo esc_html( $v ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="source">
                        <option value="">— Fuente —</option>
                        <?php foreach ( array( 'manual' => 'Manual', 'woocommerce' => 'WooCommerce', 'website' => 'Sitio Web', 'referral' => 'Referido', 'import' => 'Importado' ) as $k => $v ) : ?>
                            <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filter_source, $k ); ?>><?php echo esc_html( $v ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="wbi-btn wbi-btn-secondary">Filtrar</button>
                    <?php if ( $filter_search || $filter_assigned || $filter_priority || $filter_source ) : ?>
                        <a href="<?php echo esc_url( $base_url ); ?>" class="wbi-btn wbi-btn-secondary">✕ Limpiar</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php // Kanban board ?>
            <div class="wbi-crm-board" id="wbi-crm-board">
                <?php foreach ( $stages as $stage ) : ?>
                    <?php
                    $stage_leads = isset( $leads_by_stage[ $stage->id ] ) ? $leads_by_stage[ $stage->id ] : array();
                    $stage_value = array_sum( array_column( $stage_leads, 'expected_revenue' ) );
                    ?>
                    <div class="wbi-crm-column" data-stage="<?php echo esc_attr( $stage->id ); ?>">
                        <div class="wbi-crm-column-header" style="border-top-color:<?php echo esc_attr( $stage->color ); ?>">
                            <span class="wbi-crm-stage-name"><?php echo esc_html( $stage->name ); ?></span>
                            <span class="wbi-crm-stage-count"><?php echo esc_html( count( $stage_leads ) ); ?></span>
                            <?php if ( $stage_value > 0 ) : ?>
                                <span class="wbi-crm-stage-value"><?php echo esc_html( wc_price( $stage_value ) ); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="wbi-crm-cards" data-stage="<?php echo esc_attr( $stage->id ); ?>">
                            <?php foreach ( $stage_leads as $lead ) : ?>
                                <?php $this->render_kanban_card( $lead ); ?>
                            <?php endforeach; ?>
                            <div class="wbi-crm-drop-placeholder"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php // Quick-add modal ?>
            <div id="wbi-crm-modal" class="wbi-crm-modal" style="display:none;">
                <div class="wbi-crm-modal-inner">
                    <div class="wbi-crm-modal-header">
                        <h3>Agregar Lead</h3>
                        <button type="button" class="wbi-crm-modal-close" id="wbi-crm-modal-close">✕</button>
                    </div>
                    <form id="wbi-crm-quick-form">
                        <input type="hidden" name="action" value="wbi_crm_quick_add">
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
                        <div class="wbi-crm-form-row">
                            <label>Nombre del Lead *</label>
                            <input type="text" name="title" required placeholder="Ej: Proyecto de marketing digital">
                        </div>
                        <div class="wbi-crm-form-row">
                            <label>Contacto</label>
                            <input type="text" name="contact_name" placeholder="Nombre del contacto">
                        </div>
                        <div class="wbi-crm-form-row">
                            <label>Email</label>
                            <input type="email" name="contact_email" placeholder="email@empresa.com">
                        </div>
                        <div class="wbi-crm-form-row">
                            <label>Empresa</label>
                            <input type="text" name="company" placeholder="Nombre de empresa">
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div class="wbi-crm-form-row">
                                <label>Etapa</label>
                                <select name="stage_id">
                                    <?php foreach ( $stages as $stage ) : ?>
                                        <option value="<?php echo esc_attr( $stage->id ); ?>"><?php echo esc_html( $stage->name ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="wbi-crm-form-row">
                                <label>Prioridad</label>
                                <select name="priority">
                                    <option value="low">Baja</option>
                                    <option value="medium" selected>Media</option>
                                    <option value="high">Alta</option>
                                    <option value="urgent">Urgente</option>
                                </select>
                            </div>
                        </div>
                        <div class="wbi-crm-form-row">
                            <label>Valor estimado ($)</label>
                            <input type="number" name="expected_revenue" value="0" min="0" step="0.01">
                        </div>
                        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
                            <button type="button" class="wbi-btn wbi-btn-secondary" id="wbi-crm-modal-cancel">Cancelar</button>
                            <button type="submit" class="wbi-btn wbi-btn-primary">Guardar Lead</button>
                        </div>
                        <p id="wbi-crm-quick-msg" style="display:none;margin:8px 0 0;"></p>
                    </form>
                </div>
            </div>

        </div><!-- .wbi-wrap -->
        <?php
    }

    private function render_kanban_card( $lead ) {
        $detail_url = admin_url( 'admin.php?page=wbi-crm&action=view&lead_id=' . intval( $lead->id ) );
        $priority_labels = array(
            'low'    => array( 'label' => 'Baja',    'class' => 'wbi-crm-priority-low' ),
            'medium' => array( 'label' => 'Media',   'class' => 'wbi-crm-priority-medium' ),
            'high'   => array( 'label' => 'Alta',    'class' => 'wbi-crm-priority-high' ),
            'urgent' => array( 'label' => 'Urgente', 'class' => 'wbi-crm-priority-urgent' ),
        );
        $prio = isset( $priority_labels[ $lead->priority ] ) ? $priority_labels[ $lead->priority ] : $priority_labels['medium'];

        $avatar = '';
        if ( $lead->assigned_to ) {
            $avatar = get_avatar( $lead->assigned_to, 24, '', '', array( 'class' => 'wbi-crm-avatar' ) );
        }

        $has_activity = ! empty( $lead->next_activity_date );
        $activity_overdue = $has_activity && strtotime( $lead->next_activity_date ) < time();

        ?>
        <div class="wbi-crm-card"
             draggable="true"
             data-lead="<?php echo esc_attr( $lead->id ); ?>"
             data-stage="<?php echo esc_attr( $lead->stage_id ); ?>">
            <div class="wbi-crm-card-header">
                <span class="<?php echo esc_attr( $prio['class'] ); ?> wbi-crm-badge"><?php echo esc_html( $prio['label'] ); ?></span>
                <?php if ( $has_activity ) : ?>
                    <span class="wbi-crm-activity-dot <?php echo $activity_overdue ? 'overdue' : ''; ?>" title="<?php echo esc_attr( $lead->next_activity_type . ': ' . $lead->next_activity_date ); ?>">📅</span>
                <?php endif; ?>
            </div>
            <a href="<?php echo esc_url( $detail_url ); ?>" class="wbi-crm-card-title"><?php echo esc_html( $lead->title ); ?></a>
            <?php if ( $lead->contact_name ) : ?>
                <div class="wbi-crm-card-contact">👤 <?php echo esc_html( $lead->contact_name ); ?></div>
            <?php endif; ?>
            <?php if ( $lead->company ) : ?>
                <div class="wbi-crm-card-company">🏢 <?php echo esc_html( $lead->company ); ?></div>
            <?php endif; ?>
            <div class="wbi-crm-card-footer">
                <span class="wbi-crm-card-value"><?php echo esc_html( wc_price( $lead->expected_revenue ) ); ?></span>
                <?php if ( $avatar ) : ?>
                    <span class="wbi-crm-card-avatar"><?php echo $avatar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // LEAD DETAIL VIEW
    // =========================================================================

    private function render_lead_detail( $lead_id ) {
        global $wpdb;

        $lead = null;
        if ( $lead_id ) {
            $lead = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wbi_crm_leads WHERE id = %d",
                $lead_id
            ) );
        }

        if ( ! $lead ) {
            echo '<div class="wbi-wrap"><div class="wbi-card"><p>Lead no encontrado. <a href="' . esc_url( admin_url( 'admin.php?page=wbi-crm' ) ) . '">Volver al pipeline</a></p></div></div>';
            return;
        }

        // Handle form submission
        if ( isset( $_POST['wbi_crm_save_lead'] ) ) {
            check_admin_referer( self::NONCE_ACTION );
            $this->save_lead_detail( $lead_id );
            $lead = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wbi_crm_leads WHERE id = %d",
                $lead_id
            ) );
        }

        $stages     = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wbi_crm_stages ORDER BY position ASC" );
        $activities = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.*, u.display_name as user_name FROM {$wpdb->prefix}wbi_crm_activities a
             LEFT JOIN {$wpdb->users} u ON a.user_id = u.ID
             WHERE a.lead_id = %d ORDER BY a.created_at DESC",
            $lead_id
        ) );
        $users       = get_users( array( 'orderby' => 'display_name' ) );
        $back_url    = admin_url( 'admin.php?page=wbi-crm' );
        $nonce       = wp_create_nonce( self::NONCE_ACTION );

        // WhatsApp integration
        $whatsapp_opts = get_option( 'wbi_modules_settings', array() );
        $show_whatsapp = ! empty( $whatsapp_opts['wbi_enable_whatsapp'] ) && ! empty( $lead->contact_phone );

        $priority_labels = array( 'low' => 'Baja', 'medium' => 'Media', 'high' => 'Alta', 'urgent' => 'Urgente' );
        $source_labels   = array( 'manual' => 'Manual', 'woocommerce' => 'WooCommerce', 'website' => 'Sitio Web', 'referral' => 'Referido', 'import' => 'Importado' );
        $activity_types  = array( 'call' => '📞 Llamada', 'email' => '📧 Email', 'meeting' => '🤝 Reunión', 'task' => '✅ Tarea', 'note' => '📝 Nota' );

        ?>
        <div class="wbi-wrap">
            <div class="wbi-header">
                <div>
                    <a href="<?php echo esc_url( $back_url ); ?>" class="wbi-btn wbi-btn-secondary" style="margin-bottom:8px;">← Volver al Pipeline</a>
                    <h1><?php echo esc_html( $lead->title ); ?></h1>
                    <p style="margin:4px 0 0;color:var(--wbi-muted);font-size:13px;">
                        Creado: <?php echo esc_html( wp_date( 'd/m/Y', strtotime( $lead->created_at ) ) ); ?>
                        · Fuente: <?php echo esc_html( isset( $source_labels[ $lead->source ] ) ? $source_labels[ $lead->source ] : $lead->source ); ?>
                    </p>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <?php if ( 'active' === $lead->status ) : ?>
                        <button type="button" class="wbi-btn wbi-btn-primary" id="wbi-crm-mark-won"
                                data-lead="<?php echo esc_attr( $lead->id ); ?>"
                                data-nonce="<?php echo esc_attr( $nonce ); ?>">
                            ✅ Marcar como Ganado
                        </button>
                        <button type="button" class="wbi-btn wbi-btn-danger" id="wbi-crm-mark-lost"
                                data-lead="<?php echo esc_attr( $lead->id ); ?>"
                                data-nonce="<?php echo esc_attr( $nonce ); ?>">
                            ✕ Marcar como Perdido
                        </button>
                    <?php else : ?>
                        <span class="wbi-badge <?php echo 'won' === $lead->status ? 'wbi-badge-success' : 'wbi-badge-danger'; ?>">
                            <?php echo 'won' === $lead->status ? '✅ GANADO' : '✕ PERDIDO'; ?>
                        </span>
                        <?php if ( 'lost' === $lead->status && $lead->lost_reason ) : ?>
                            <span style="color:var(--wbi-muted);font-size:13px;">Razón: <?php echo esc_html( $lead->lost_reason ); ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ( $show_whatsapp ) : ?>
                        <a href="https://wa.me/<?php echo esc_attr( preg_replace( '/\D/', '', $lead->contact_phone ) ); ?>"
                           target="_blank" rel="noopener noreferrer" class="wbi-btn wbi-btn-secondary">
                            💬 WhatsApp
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="wbi-crm-detail-layout">
                <?php // Main form ?>
                <div class="wbi-crm-detail-main">
                    <div class="wbi-card">
                        <h3 class="wbi-section-title">Información del Lead</h3>
                        <form method="post">
                            <?php wp_nonce_field( self::NONCE_ACTION ); ?>
                            <input type="hidden" name="wbi_crm_save_lead" value="1">
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                                <div>
                                    <label><strong>Nombre del Lead *</strong></label><br>
                                    <input type="text" name="title" value="<?php echo esc_attr( $lead->title ); ?>" required style="width:100%">
                                </div>
                                <div>
                                    <label><strong>Empresa</strong></label><br>
                                    <input type="text" name="company" value="<?php echo esc_attr( $lead->company ); ?>" style="width:100%">
                                </div>
                                <div>
                                    <label><strong>Contacto</strong></label><br>
                                    <input type="text" name="contact_name" value="<?php echo esc_attr( $lead->contact_name ); ?>" style="width:100%">
                                </div>
                                <div>
                                    <label><strong>Email</strong></label><br>
                                    <input type="email" name="contact_email" value="<?php echo esc_attr( $lead->contact_email ); ?>" style="width:100%">
                                </div>
                                <div>
                                    <label><strong>Teléfono</strong></label><br>
                                    <input type="text" name="contact_phone" value="<?php echo esc_attr( $lead->contact_phone ); ?>" style="width:100%">
                                </div>
                                <div>
                                    <label><strong>Etapa</strong></label><br>
                                    <select name="stage_id" style="width:100%">
                                        <?php foreach ( $stages as $stage ) : ?>
                                            <option value="<?php echo esc_attr( $stage->id ); ?>" <?php selected( $lead->stage_id, $stage->id ); ?>><?php echo esc_html( $stage->name ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label><strong>Prioridad</strong></label><br>
                                    <select name="priority" style="width:100%">
                                        <?php foreach ( $priority_labels as $k => $v ) : ?>
                                            <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $lead->priority, $k ); ?>><?php echo esc_html( $v ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label><strong>Fuente</strong></label><br>
                                    <select name="source" style="width:100%">
                                        <?php foreach ( $source_labels as $k => $v ) : ?>
                                            <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $lead->source, $k ); ?>><?php echo esc_html( $v ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label><strong>Valor Estimado ($)</strong></label><br>
                                    <input type="number" name="expected_revenue" value="<?php echo esc_attr( $lead->expected_revenue ); ?>" min="0" step="0.01" style="width:100%">
                                </div>
                                <div>
                                    <label><strong>Probabilidad (%)</strong></label><br>
                                    <input type="number" name="probability" value="<?php echo esc_attr( $lead->probability ); ?>" min="0" max="100" style="width:100%">
                                </div>
                                <div>
                                    <label><strong>Asignado a</strong></label><br>
                                    <select name="assigned_to" style="width:100%">
                                        <option value="0">— Sin asignar —</option>
                                        <?php foreach ( $users as $u ) : ?>
                                            <option value="<?php echo esc_attr( $u->ID ); ?>" <?php selected( $lead->assigned_to, $u->ID ); ?>><?php echo esc_html( $u->display_name ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label><strong>Próxima actividad</strong></label><br>
                                    <input type="date" name="next_activity_date" value="<?php echo esc_attr( $lead->next_activity_date ); ?>" style="width:100%">
                                </div>
                            </div>
                            <div style="margin-top:16px;">
                                <label><strong>Notas</strong></label><br>
                                <textarea name="notes" rows="5" style="width:100%"><?php echo esc_textarea( $lead->notes ); ?></textarea>
                            </div>
                            <div style="margin-top:16px;text-align:right;">
                                <button type="submit" class="wbi-btn wbi-btn-primary">💾 Guardar Cambios</button>
                            </div>
                        </form>
                    </div>

                    <?php // Convert to customer ?>
                    <?php if ( ! $lead->customer_id ) : ?>
                    <div class="wbi-card" style="margin-top:16px;">
                        <h3 class="wbi-section-title">Convertir a Cliente</h3>
                        <p style="color:var(--wbi-muted);margin-bottom:12px;">Crea un usuario de WordPress con rol <em>customer</em> de WooCommerce a partir de los datos de este lead.</p>
                        <?php if ( $lead->contact_email ) : ?>
                            <button type="button" class="wbi-btn wbi-btn-secondary"
                                    id="wbi-crm-convert-customer"
                                    data-lead="<?php echo esc_attr( $lead->id ); ?>"
                                    data-nonce="<?php echo esc_attr( $nonce ); ?>">
                                👤 Convertir a Cliente WooCommerce
                            </button>
                            <span id="wbi-crm-convert-msg" style="margin-left:12px;"></span>
                        <?php else : ?>
                            <p style="color:var(--wbi-muted);">Completá el email del contacto para poder convertirlo a cliente.</p>
                        <?php endif; ?>
                    </div>
                    <?php else : ?>
                    <div class="wbi-card" style="margin-top:16px;">
                        <h3 class="wbi-section-title">Cliente WooCommerce</h3>
                        <?php
                        $customer_edit = get_edit_user_link( $lead->customer_id );
                        $linked_user   = get_user_by( 'id', $lead->customer_id );
                        ?>
                        <p>✅ Vinculado a <a href="<?php echo esc_url( $customer_edit ); ?>"><?php echo esc_html( $linked_user ? $linked_user->display_name : '#' . $lead->customer_id ); ?></a></p>
                    </div>
                    <?php endif; ?>
                </div>

                <?php // Activity timeline sidebar ?>
                <div class="wbi-crm-detail-sidebar">
                    <div class="wbi-card">
                        <h3 class="wbi-section-title">📋 Actividades</h3>
                        <form id="wbi-crm-add-activity-form" style="margin-bottom:20px;">
                            <input type="hidden" name="action" value="wbi_crm_add_activity">
                            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
                            <input type="hidden" name="lead_id" value="<?php echo esc_attr( $lead->id ); ?>">
                            <select name="type" style="width:100%;margin-bottom:8px;">
                                <?php foreach ( $activity_types as $k => $v ) : ?>
                                    <option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $v ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="summary" placeholder="Resumen…" style="width:100%;margin-bottom:8px;">
                            <input type="date" name="due_date" style="width:100%;margin-bottom:8px;">
                            <textarea name="description" placeholder="Descripción…" rows="3" style="width:100%;margin-bottom:8px;"></textarea>
                            <button type="submit" class="wbi-btn wbi-btn-primary" style="width:100%;">+ Programar Actividad</button>
                            <p id="wbi-crm-activity-msg" style="display:none;margin:8px 0 0;"></p>
                        </form>

                        <div class="wbi-crm-timeline" id="wbi-crm-timeline">
                            <?php if ( empty( $activities ) ) : ?>
                                <p style="color:var(--wbi-muted);text-align:center;">Sin actividades registradas.</p>
                            <?php endif; ?>
                            <?php foreach ( $activities as $act ) : ?>
                                <?php $this->render_activity_item( $act, $nonce ); ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php // Lost reason modal ?>
            <div id="wbi-crm-lost-modal" class="wbi-crm-modal" style="display:none;">
                <div class="wbi-crm-modal-inner" style="max-width:420px;">
                    <div class="wbi-crm-modal-header">
                        <h3>Marcar como Perdido</h3>
                        <button type="button" class="wbi-crm-modal-close" id="wbi-crm-lost-modal-close">✕</button>
                    </div>
                    <p>Ingresá el motivo por el que se perdió este lead:</p>
                    <input type="text" id="wbi-crm-lost-reason" placeholder="Ej: Precio, competencia, sin presupuesto…" style="width:100%;margin-bottom:12px;">
                    <div style="display:flex;gap:8px;justify-content:flex-end;">
                        <button type="button" class="wbi-btn wbi-btn-secondary" id="wbi-crm-lost-cancel">Cancelar</button>
                        <button type="button" class="wbi-btn wbi-btn-danger" id="wbi-crm-lost-confirm">Confirmar Pérdida</button>
                    </div>
                </div>
            </div>
        </div><!-- .wbi-wrap -->
        <?php
    }

    private function render_activity_item( $act, $nonce ) {
        $icons = array( 'call' => '📞', 'email' => '📧', 'meeting' => '🤝', 'task' => '✅', 'note' => '📝' );
        $icon  = isset( $icons[ $act->type ] ) ? $icons[ $act->type ] : '📝';
        $done  = (bool) $act->done;
        ?>
        <div class="wbi-crm-timeline-item <?php echo $done ? 'done' : ''; ?>" id="wbi-crm-act-<?php echo esc_attr( $act->id ); ?>">
            <span class="wbi-crm-timeline-icon"><?php echo esc_html( $icon ); ?></span>
            <div class="wbi-crm-timeline-body">
                <strong><?php echo esc_html( $act->summary ); ?></strong>
                <?php if ( $act->due_date ) : ?>
                    <span class="wbi-crm-timeline-date"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $act->due_date ) ) ); ?></span>
                <?php endif; ?>
                <div class="wbi-crm-timeline-meta"><?php echo esc_html( $act->user_name ); ?> · <?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $act->created_at ) ) ); ?></div>
                <?php if ( $act->description ) : ?>
                    <div class="wbi-crm-timeline-desc"><?php echo esc_html( $act->description ); ?></div>
                <?php endif; ?>
                <?php if ( ! $done ) : ?>
                    <button type="button" class="wbi-crm-complete-activity" style="margin-top:6px;"
                            data-activity="<?php echo esc_attr( $act->id ); ?>"
                            data-nonce="<?php echo esc_attr( $nonce ); ?>">
                        ✓ Marcar como hecha
                    </button>
                <?php else : ?>
                    <span style="color:var(--wbi-success);font-size:12px;">✅ Completada <?php echo esc_html( $act->done_at ? wp_date( 'd/m/Y', strtotime( $act->done_at ) ) : '' ); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // SAVE LEAD DETAIL (form post)
    // =========================================================================

    private function save_lead_detail( $lead_id ) {
        global $wpdb;

        $data = array(
            'title'              => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
            'contact_name'       => sanitize_text_field( wp_unslash( $_POST['contact_name'] ?? '' ) ),
            'contact_email'      => sanitize_email( wp_unslash( $_POST['contact_email'] ?? '' ) ),
            'contact_phone'      => sanitize_text_field( wp_unslash( $_POST['contact_phone'] ?? '' ) ),
            'company'            => sanitize_text_field( wp_unslash( $_POST['company'] ?? '' ) ),
            'stage_id'           => absint( $_POST['stage_id'] ?? 0 ),
            'priority'           => sanitize_key( $_POST['priority'] ?? 'medium' ),
            'source'             => sanitize_key( $_POST['source'] ?? 'manual' ),
            'expected_revenue'   => floatval( $_POST['expected_revenue'] ?? 0 ),
            'probability'        => min( 100, max( 0, absint( $_POST['probability'] ?? 50 ) ) ),
            'assigned_to'        => absint( $_POST['assigned_to'] ?? 0 ),
            'notes'              => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
            'next_activity_date' => ! empty( $_POST['next_activity_date'] ) ? sanitize_text_field( wp_unslash( $_POST['next_activity_date'] ) ) : null,
        );

        // Validate priority
        if ( ! in_array( $data['priority'], array( 'low', 'medium', 'high', 'urgent' ), true ) ) {
            $data['priority'] = 'medium';
        }

        // Check if won stage
        if ( $data['stage_id'] ) {
            $is_won = $wpdb->get_var( $wpdb->prepare(
                "SELECT is_won FROM {$wpdb->prefix}wbi_crm_stages WHERE id = %d",
                $data['stage_id']
            ) );
            if ( $is_won ) {
                $data['status'] = 'won';
                $data['won_at'] = current_time( 'mysql' );
            }
        }

        $wpdb->update(
            "{$wpdb->prefix}wbi_crm_leads",
            $data,
            array( 'id' => $lead_id ),
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%f', '%d', '%d', '%s', '%s' ),
            array( '%d' )
        );
    }

    // =========================================================================
    // STAGES MANAGEMENT
    // =========================================================================

    private function render_stages_page() {
        global $wpdb;

        $nonce    = wp_create_nonce( self::NONCE_ACTION );
        $board_url = admin_url( 'admin.php?page=wbi-crm' );

        // Handle save new stage
        if ( isset( $_POST['wbi_crm_save_stage'] ) ) {
            check_admin_referer( self::NONCE_ACTION );
            $stage_id   = absint( $_POST['stage_id'] ?? 0 );
            $stage_name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
            $stage_pos  = absint( $_POST['position'] ?? 0 );
            $stage_is_won = isset( $_POST['is_won'] ) ? 1 : 0;
            $stage_color = sanitize_hex_color( wp_unslash( $_POST['color'] ?? '#0073aa' ) );
            if ( ! $stage_color ) {
                $stage_color = '#0073aa';
            }

            if ( $stage_id ) {
                $wpdb->update(
                    "{$wpdb->prefix}wbi_crm_stages",
                    array( 'name' => $stage_name, 'position' => $stage_pos, 'is_won' => $stage_is_won, 'color' => $stage_color ),
                    array( 'id' => $stage_id ),
                    array( '%s', '%d', '%d', '%s' ),
                    array( '%d' )
                );
            } else {
                $wpdb->insert(
                    "{$wpdb->prefix}wbi_crm_stages",
                    array( 'name' => $stage_name, 'position' => $stage_pos, 'is_won' => $stage_is_won, 'color' => $stage_color ),
                    array( '%s', '%d', '%d', '%s' )
                );
            }
        }

        // Handle delete stage
        if ( isset( $_POST['wbi_crm_delete_stage'] ) ) {
            check_admin_referer( self::NONCE_ACTION );
            $del_id = absint( $_POST['stage_id'] ?? 0 );
            if ( $del_id ) {
                // Move leads from deleted stage to stage 0
                $wpdb->update( "{$wpdb->prefix}wbi_crm_leads", array( 'stage_id' => 0 ), array( 'stage_id' => $del_id ), array( '%d' ), array( '%d' ) );
                $wpdb->delete( "{$wpdb->prefix}wbi_crm_stages", array( 'id' => $del_id ), array( '%d' ) );
            }
        }

        $stages = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wbi_crm_stages ORDER BY position ASC" );

        ?>
        <div class="wbi-wrap">
            <div class="wbi-header">
                <div>
                    <a href="<?php echo esc_url( $board_url ); ?>" class="wbi-btn wbi-btn-secondary" style="margin-bottom:8px;">← Volver al Pipeline</a>
                    <h1>⚙️ Gestión de Etapas</h1>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;">
                <?php // Stages list ?>
                <div class="wbi-card">
                    <h3 class="wbi-section-title">Etapas del Pipeline</h3>
                    <table class="wbi-table wbi-sortable" id="wbi-crm-stages-table">
                        <thead>
                            <tr>
                                <th>Orden</th>
                                <th>Nombre</th>
                                <th>Color</th>
                                <th>¿Ganado?</th>
                                <th>Leads</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $stages as $stage ) : ?>
                                <?php
                                $lead_count = $wpdb->get_var( $wpdb->prepare(
                                    "SELECT COUNT(*) FROM {$wpdb->prefix}wbi_crm_leads WHERE stage_id = %d AND status = 'active'",
                                    $stage->id
                                ) );
                                ?>
                                <tr>
                                    <td style="text-align:center;">
                                        <span class="wbi-crm-drag-handle" style="cursor:grab;">⠿</span>
                                        <?php echo esc_html( $stage->position ); ?>
                                    </td>
                                    <td>
                                        <span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:<?php echo esc_attr( $stage->color ); ?>;vertical-align:middle;margin-right:6px;"></span>
                                        <?php echo esc_html( $stage->name ); ?>
                                    </td>
                                    <td><?php echo esc_html( $stage->color ); ?></td>
                                    <td><?php echo $stage->is_won ? '✅' : '—'; ?></td>
                                    <td><?php echo esc_html( $lead_count ); ?></td>
                                    <td>
                                        <a href="#" class="wbi-crm-edit-stage"
                                           data-id="<?php echo esc_attr( $stage->id ); ?>"
                                           data-name="<?php echo esc_attr( $stage->name ); ?>"
                                           data-position="<?php echo esc_attr( $stage->position ); ?>"
                                           data-is-won="<?php echo esc_attr( $stage->is_won ); ?>"
                                           data-color="<?php echo esc_attr( $stage->color ); ?>">Editar</a>
                                        <?php if ( count( $stages ) > 1 ) : ?>
                                            <form method="post" style="display:inline;">
                                                <?php wp_nonce_field( self::NONCE_ACTION ); ?>
                                                <input type="hidden" name="stage_id" value="<?php echo esc_attr( $stage->id ); ?>">
                                                <input type="hidden" name="wbi_crm_delete_stage" value="1">
                                                <button type="submit" class="wbi-btn wbi-btn-link wbi-btn-danger-link"
                                                        onclick="return confirm('¿Eliminar esta etapa? Los leads serán desvinculados.');">
                                                    Eliminar
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php // Add/Edit stage form ?>
                <div class="wbi-card" id="wbi-crm-stage-form-card">
                    <h3 class="wbi-section-title" id="wbi-crm-stage-form-title">Nueva Etapa</h3>
                    <form method="post">
                        <?php wp_nonce_field( self::NONCE_ACTION ); ?>
                        <input type="hidden" name="wbi_crm_save_stage" value="1">
                        <input type="hidden" name="stage_id" id="wbi-crm-stage-id" value="0">
                        <div class="wbi-crm-form-row">
                            <label>Nombre *</label>
                            <input type="text" name="name" id="wbi-crm-stage-name" required style="width:100%">
                        </div>
                        <div class="wbi-crm-form-row">
                            <label>Posición (orden)</label>
                            <input type="number" name="position" id="wbi-crm-stage-position" value="<?php echo esc_attr( count( $stages ) ); ?>" min="0" style="width:100%">
                        </div>
                        <div class="wbi-crm-form-row">
                            <label>Color</label>
                            <input type="color" name="color" id="wbi-crm-stage-color" value="#0073aa" style="width:100%;height:40px;">
                        </div>
                        <div class="wbi-crm-form-row">
                            <label>
                                <input type="checkbox" name="is_won" id="wbi-crm-stage-is-won" value="1">
                                Etapa de "Ganado"
                            </label>
                        </div>
                        <button type="submit" class="wbi-btn wbi-btn-primary" style="width:100%;margin-top:8px;">Guardar Etapa</button>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // REPORTS
    // =========================================================================

    private function render_reports_page() {
        global $wpdb;

        $board_url = admin_url( 'admin.php?page=wbi-crm' );

        // Stats
        $total_active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wbi_crm_leads WHERE status = 'active'" );
        $total_won    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wbi_crm_leads WHERE status = 'won'" );
        $total_lost   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wbi_crm_leads WHERE status = 'lost'" );
        $total_all    = $total_active + $total_won + $total_lost;

        $pipeline_value = (float) $wpdb->get_var( "SELECT SUM(expected_revenue) FROM {$wpdb->prefix}wbi_crm_leads WHERE status = 'active'" );
        $won_revenue    = (float) $wpdb->get_var( "SELECT SUM(expected_revenue) FROM {$wpdb->prefix}wbi_crm_leads WHERE status = 'won'" );
        $forecast       = (float) $wpdb->get_var( "SELECT SUM(expected_revenue * probability / 100) FROM {$wpdb->prefix}wbi_crm_leads WHERE status = 'active'" );

        $conv_rate = $total_all > 0 ? round( ( $total_won / $total_all ) * 100, 1 ) : 0;

        // Won this month
        $won_month = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wbi_crm_leads WHERE status = 'won' AND won_at >= %s",
            gmdate( 'Y-m-01 00:00:00' )
        ) );

        // By stage
        $by_stage = $wpdb->get_results(
            "SELECT s.name, s.color, COUNT(l.id) as cnt, COALESCE(SUM(l.expected_revenue),0) as total_value
             FROM {$wpdb->prefix}wbi_crm_stages s
             LEFT JOIN {$wpdb->prefix}wbi_crm_leads l ON l.stage_id = s.id AND l.status = 'active'
             GROUP BY s.id ORDER BY s.position ASC"
        );

        // By source
        $by_source = $wpdb->get_results(
            "SELECT source, COUNT(*) as cnt FROM {$wpdb->prefix}wbi_crm_leads GROUP BY source ORDER BY cnt DESC"
        );
        $source_labels = array( 'manual' => 'Manual', 'woocommerce' => 'WooCommerce', 'website' => 'Sitio Web', 'referral' => 'Referido', 'import' => 'Importado' );

        // Won/Lost per month (last 6 months)
        $monthly = $wpdb->get_results(
            "SELECT DATE_FORMAT(won_at,'%Y-%m') as ym, status, COUNT(*) as cnt
             FROM {$wpdb->prefix}wbi_crm_leads
             WHERE status IN ('won','lost') AND won_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
             GROUP BY ym, status ORDER BY ym ASC"
        );

        // Max bar width
        $max_stage_value = 0;
        foreach ( $by_stage as $s ) {
            if ( $s->total_value > $max_stage_value ) $max_stage_value = $s->total_value;
        }
        $max_source_cnt = 0;
        foreach ( $by_source as $s ) {
            if ( $s->cnt > $max_source_cnt ) $max_source_cnt = $s->cnt;
        }

        ?>
        <div class="wbi-wrap">
            <div class="wbi-header">
                <div>
                    <a href="<?php echo esc_url( $board_url ); ?>" class="wbi-btn wbi-btn-secondary" style="margin-bottom:8px;">← Volver al Pipeline</a>
                    <h1>📊 Reportes CRM</h1>
                </div>
            </div>

            <?php // KPI cards ?>
            <div class="wbi-stats-grid" style="margin-bottom:24px;">
                <div class="wbi-stat-card">
                    <div class="wbi-stat-label">Leads Activos</div>
                    <div class="wbi-number"><?php echo esc_html( $total_active ); ?></div>
                </div>
                <div class="wbi-stat-card">
                    <div class="wbi-stat-label">Valor del Pipeline</div>
                    <div class="wbi-number"><?php echo esc_html( wc_price( $pipeline_value ) ); ?></div>
                </div>
                <div class="wbi-stat-card">
                    <div class="wbi-stat-label">Ganados este Mes</div>
                    <div class="wbi-number"><?php echo esc_html( $won_month ); ?></div>
                </div>
                <div class="wbi-stat-card">
                    <div class="wbi-stat-label">Tasa de Conversión</div>
                    <div class="wbi-number"><?php echo esc_html( $conv_rate ); ?>%</div>
                </div>
                <div class="wbi-stat-card">
                    <div class="wbi-stat-label">Revenue Ganado</div>
                    <div class="wbi-number"><?php echo esc_html( wc_price( $won_revenue ) ); ?></div>
                </div>
                <div class="wbi-stat-card">
                    <div class="wbi-stat-label">Forecast (prob.)</div>
                    <div class="wbi-number"><?php echo esc_html( wc_price( $forecast ) ); ?></div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
                <?php // Pipeline by stage ?>
                <div class="wbi-card">
                    <h3 class="wbi-section-title">Pipeline por Etapa</h3>
                    <?php foreach ( $by_stage as $s ) : ?>
                        <?php $pct = $max_stage_value > 0 ? ( $s->total_value / $max_stage_value * 100 ) : 0; ?>
                        <div style="margin-bottom:14px;">
                            <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                                <span><?php echo esc_html( $s->name ); ?> <small style="color:var(--wbi-muted);">(<?php echo esc_html( $s->cnt ); ?> leads)</small></span>
                                <strong><?php echo esc_html( wc_price( $s->total_value ) ); ?></strong>
                            </div>
                            <div style="background:var(--wbi-border);border-radius:4px;height:10px;overflow:hidden;">
                                <div style="background:<?php echo esc_attr( $s->color ); ?>;width:<?php echo esc_attr( round( $pct ) ); ?>%;height:100%;border-radius:4px;transition:width .3s;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php // Leads by source ?>
                <div class="wbi-card">
                    <h3 class="wbi-section-title">Leads por Fuente</h3>
                    <?php foreach ( $by_source as $s ) : ?>
                        <?php $pct = $max_source_cnt > 0 ? ( $s->cnt / $max_source_cnt * 100 ) : 0; ?>
                        <div style="margin-bottom:14px;">
                            <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                                <span><?php echo esc_html( isset( $source_labels[ $s->source ] ) ? $source_labels[ $s->source ] : $s->source ); ?></span>
                                <strong><?php echo esc_html( $s->cnt ); ?></strong>
                            </div>
                            <div style="background:var(--wbi-border);border-radius:4px;height:10px;overflow:hidden;">
                                <div style="background:var(--wbi-primary);width:<?php echo esc_attr( round( $pct ) ); ?>%;height:100%;border-radius:4px;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php // Won/Lost ratio ?>
            <?php if ( ! empty( $monthly ) ) : ?>
            <div class="wbi-card" style="margin-top:24px;">
                <h3 class="wbi-section-title">Ganados vs Perdidos (últimos 6 meses)</h3>
                <table class="wbi-table">
                    <thead>
                        <tr>
                            <th>Mes</th>
                            <th style="color:var(--wbi-success);">Ganados</th>
                            <th style="color:var(--wbi-danger);">Perdidos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $monthly_indexed = array();
                        foreach ( $monthly as $m ) {
                            $monthly_indexed[ $m->ym ][ $m->status ] = $m->cnt;
                        }
                        foreach ( $monthly_indexed as $ym => $data ) :
                        ?>
                            <tr>
                                <td><?php echo esc_html( $ym ); ?></td>
                                <td><?php echo esc_html( isset( $data['won'] ) ? $data['won'] : 0 ); ?></td>
                                <td><?php echo esc_html( isset( $data['lost'] ) ? $data['lost'] : 0 ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // =========================================================================
    // CRM SETTINGS (auto-capture toggle)
    // =========================================================================

    private function render_settings_page() {
        $board_url = admin_url( 'admin.php?page=wbi-crm' );
        $nonce     = wp_create_nonce( self::NONCE_ACTION );

        if ( isset( $_POST['wbi_crm_save_settings'] ) ) {
            check_admin_referer( self::NONCE_ACTION );
            update_option( 'wbi_crm_auto_capture', isset( $_POST['wbi_crm_auto_capture'] ) ? 1 : 0 );
            echo '<div class="notice notice-success is-dismissible"><p>Configuración guardada.</p></div>';
        }

        $auto_capture = (int) get_option( 'wbi_crm_auto_capture', 0 );

        ?>
        <div class="wbi-wrap">
            <div class="wbi-header">
                <div>
                    <a href="<?php echo esc_url( $board_url ); ?>" class="wbi-btn wbi-btn-secondary" style="margin-bottom:8px;">← Volver al Pipeline</a>
                    <h1>🔧 Configuración CRM</h1>
                </div>
            </div>
            <div class="wbi-card" style="max-width:540px;">
                <form method="post">
                    <?php wp_nonce_field( self::NONCE_ACTION ); ?>
                    <input type="hidden" name="wbi_crm_save_settings" value="1">
                    <h3 class="wbi-section-title">Captura automática desde WooCommerce</h3>
                    <label style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
                        <input type="checkbox" name="wbi_crm_auto_capture" value="1" <?php checked( 1, $auto_capture ); ?>>
                        <span>Auto-crear lead cuando se registra un nuevo pedido WooCommerce (si el email no existe como lead activo)</span>
                    </label>
                    <button type="submit" class="wbi-btn wbi-btn-primary">Guardar</button>
                </form>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    private function verify_ajax_nonce() {
        if ( ! check_ajax_referer( self::NONCE_ACTION, '_wpnonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Nonce inválido.' ), 403 );
        }
        $cap = $this->user_can_access();
        if ( ! current_user_can( $cap ) ) {
            wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
        }
    }

    public function ajax_move_lead() {
        $this->verify_ajax_nonce();
        global $wpdb;

        $lead_id  = absint( $_POST['lead_id'] ?? 0 );
        $stage_id = absint( $_POST['stage_id'] ?? 0 );

        if ( ! $lead_id || ! $stage_id ) {
            wp_send_json_error( array( 'message' => 'Datos inválidos.' ) );
        }

        // Check if the destination stage is a "won" stage
        $is_won = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT is_won FROM {$wpdb->prefix}wbi_crm_stages WHERE id = %d",
            $stage_id
        ) );

        $data = array( 'stage_id' => $stage_id );
        if ( $is_won ) {
            $data['status'] = 'won';
            $data['won_at'] = current_time( 'mysql' );
        }

        $updated = $wpdb->update(
            "{$wpdb->prefix}wbi_crm_leads",
            $data,
            array( 'id' => $lead_id )
        );

        if ( false === $updated ) {
            wp_send_json_error( array( 'message' => 'Error al mover el lead.' ) );
        }

        wp_send_json_success( array( 'message' => 'Lead movido.', 'won' => $is_won ) );
    }

    public function ajax_quick_add() {
        $this->verify_ajax_nonce();
        global $wpdb;

        $title   = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
        $stage   = absint( $_POST['stage_id'] ?? 0 );
        $prio    = sanitize_key( $_POST['priority'] ?? 'medium' );
        $revenue = floatval( $_POST['expected_revenue'] ?? 0 );

        if ( ! $title ) {
            wp_send_json_error( array( 'message' => 'El nombre es obligatorio.' ) );
        }
        if ( ! in_array( $prio, array( 'low', 'medium', 'high', 'urgent' ), true ) ) {
            $prio = 'medium';
        }

        // If no stage, use the first one
        if ( ! $stage ) {
            $stage = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}wbi_crm_stages ORDER BY position ASC LIMIT 1" );
        }

        $inserted = $wpdb->insert(
            "{$wpdb->prefix}wbi_crm_leads",
            array(
                'title'            => $title,
                'contact_name'     => sanitize_text_field( wp_unslash( $_POST['contact_name'] ?? '' ) ),
                'contact_email'    => sanitize_email( wp_unslash( $_POST['contact_email'] ?? '' ) ),
                'company'          => sanitize_text_field( wp_unslash( $_POST['company'] ?? '' ) ),
                'stage_id'         => $stage,
                'priority'         => $prio,
                'expected_revenue' => $revenue,
                'source'           => 'manual',
                'assigned_to'      => get_current_user_id(),
                'status'           => 'active',
            ),
            array( '%s', '%s', '%s', '%s', '%d', '%s', '%f', '%s', '%d', '%s' )
        );

        if ( ! $inserted ) {
            wp_send_json_error( array( 'message' => 'Error al crear el lead.' ) );
        }

        $lead_id = $wpdb->insert_id;
        $detail_url = admin_url( 'admin.php?page=wbi-crm&action=view&lead_id=' . $lead_id );

        wp_send_json_success( array(
            'message'    => 'Lead creado.',
            'lead_id'    => $lead_id,
            'detail_url' => $detail_url,
            'html'       => $this->get_card_html( $lead_id ),
        ) );
    }

    public function ajax_mark_won() {
        $this->verify_ajax_nonce();
        global $wpdb;

        $lead_id = absint( $_POST['lead_id'] ?? 0 );
        if ( ! $lead_id ) {
            wp_send_json_error( array( 'message' => 'Lead inválido.' ) );
        }

        // Move to the won stage
        $won_stage = $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}wbi_crm_stages WHERE is_won = 1 LIMIT 1" );

        $wpdb->update(
            "{$wpdb->prefix}wbi_crm_leads",
            array( 'status' => 'won', 'won_at' => current_time( 'mysql' ), 'stage_id' => $won_stage ?: 0 ),
            array( 'id' => $lead_id )
        );

        wp_send_json_success( array( 'message' => 'Lead marcado como ganado.' ) );
    }

    public function ajax_mark_lost() {
        $this->verify_ajax_nonce();
        global $wpdb;

        $lead_id    = absint( $_POST['lead_id'] ?? 0 );
        $lost_reason = sanitize_text_field( wp_unslash( $_POST['lost_reason'] ?? '' ) );

        if ( ! $lead_id ) {
            wp_send_json_error( array( 'message' => 'Lead inválido.' ) );
        }

        $wpdb->update(
            "{$wpdb->prefix}wbi_crm_leads",
            array( 'status' => 'lost', 'lost_at' => current_time( 'mysql' ), 'lost_reason' => $lost_reason ),
            array( 'id' => $lead_id )
        );

        wp_send_json_success( array( 'message' => 'Lead marcado como perdido.' ) );
    }

    public function ajax_complete_activity() {
        $this->verify_ajax_nonce();
        global $wpdb;

        $activity_id = absint( $_POST['activity_id'] ?? 0 );
        if ( ! $activity_id ) {
            wp_send_json_error( array( 'message' => 'Actividad inválida.' ) );
        }

        $wpdb->update(
            "{$wpdb->prefix}wbi_crm_activities",
            array( 'done' => 1, 'done_at' => current_time( 'mysql' ) ),
            array( 'id' => $activity_id )
        );

        wp_send_json_success( array( 'message' => 'Actividad completada.' ) );
    }

    public function ajax_add_activity() {
        $this->verify_ajax_nonce();
        global $wpdb;

        $lead_id     = absint( $_POST['lead_id'] ?? 0 );
        $type        = sanitize_key( $_POST['type'] ?? 'note' );
        $summary     = sanitize_text_field( wp_unslash( $_POST['summary'] ?? '' ) );
        $description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
        $due_date    = ! empty( $_POST['due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['due_date'] ) ) : null;

        if ( ! $lead_id ) {
            wp_send_json_error( array( 'message' => 'Lead inválido.' ) );
        }
        if ( ! in_array( $type, array( 'call', 'email', 'meeting', 'task', 'note' ), true ) ) {
            $type = 'note';
        }

        $wpdb->insert(
            "{$wpdb->prefix}wbi_crm_activities",
            array(
                'lead_id'     => $lead_id,
                'user_id'     => get_current_user_id(),
                'type'        => $type,
                'summary'     => $summary,
                'description' => $description,
                'due_date'    => $due_date,
            ),
            array( '%d', '%d', '%s', '%s', '%s', '%s' )
        );

        $activity_id = $wpdb->insert_id;

        // Update next activity date on the lead
        if ( $due_date ) {
            $wpdb->update(
                "{$wpdb->prefix}wbi_crm_leads",
                array( 'next_activity_date' => $due_date, 'next_activity_type' => $type ),
                array( 'id' => $lead_id )
            );
        }

        // Build response HTML
        $act = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.*, u.display_name as user_name FROM {$wpdb->prefix}wbi_crm_activities a
             LEFT JOIN {$wpdb->users} u ON a.user_id = u.ID WHERE a.id = %d",
            $activity_id
        ) );

        $nonce = wp_create_nonce( self::NONCE_ACTION );
        ob_start();
        $this->render_activity_item( $act, $nonce );
        $html = ob_get_clean();

        wp_send_json_success( array( 'message' => 'Actividad agregada.', 'html' => $html ) );
    }

    public function ajax_delete_lead() {
        $this->verify_ajax_nonce();
        global $wpdb;

        $lead_id = absint( $_POST['lead_id'] ?? 0 );
        if ( ! $lead_id ) {
            wp_send_json_error( array( 'message' => 'Lead inválido.' ) );
        }

        $wpdb->delete( "{$wpdb->prefix}wbi_crm_leads", array( 'id' => $lead_id ), array( '%d' ) );
        $wpdb->delete( "{$wpdb->prefix}wbi_crm_activities", array( 'lead_id' => $lead_id ), array( '%d' ) );

        wp_send_json_success( array( 'message' => 'Lead eliminado.' ) );
    }

    public function ajax_reorder_stages() {
        $this->verify_ajax_nonce();
        global $wpdb;

        $order = isset( $_POST['order'] ) ? array_map( 'absint', (array) $_POST['order'] ) : array();
        foreach ( $order as $position => $stage_id ) {
            $wpdb->update(
                "{$wpdb->prefix}wbi_crm_stages",
                array( 'position' => $position ),
                array( 'id' => $stage_id )
            );
        }

        wp_send_json_success( array( 'message' => 'Orden guardado.' ) );
    }

    public function ajax_convert_customer() {
        $this->verify_ajax_nonce();
        global $wpdb;

        $lead_id = absint( $_POST['lead_id'] ?? 0 );
        if ( ! $lead_id ) {
            wp_send_json_error( array( 'message' => 'Lead inválido.' ) );
        }

        $lead = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wbi_crm_leads WHERE id = %d",
            $lead_id
        ) );

        if ( ! $lead || ! $lead->contact_email ) {
            wp_send_json_error( array( 'message' => 'El lead no tiene email.' ) );
        }

        // Check if WP user already exists
        $existing = get_user_by( 'email', $lead->contact_email );
        if ( $existing ) {
            $wpdb->update(
                "{$wpdb->prefix}wbi_crm_leads",
                array( 'customer_id' => $existing->ID ),
                array( 'id' => $lead_id )
            );
            wp_send_json_success( array(
                'message'     => 'Usuario ya existente vinculado.',
                'user_id'     => $existing->ID,
                'edit_url'    => get_edit_user_link( $existing->ID ),
                'user_name'   => $existing->display_name,
            ) );
        }

        // Create new WP user — use a random suffix to avoid predictable usernames
        $base_username = sanitize_user( explode( '@', $lead->contact_email )[0], true );
        $username      = $base_username . '_' . wp_rand( 1000, 9999 );
        while ( username_exists( $username ) ) {
            $username = $base_username . '_' . wp_rand( 1000, 9999 );
        }

        $password = wp_generate_password();
        $user_id  = wp_create_user( $username, $password, $lead->contact_email );

        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
        }

        // Set display name and role
        wp_update_user( array(
            'ID'           => $user_id,
            'display_name' => $lead->contact_name ?: $username,
            'first_name'   => $lead->contact_name,
            'role'         => 'customer',
        ) );

        // Link to lead
        $wpdb->update(
            "{$wpdb->prefix}wbi_crm_leads",
            array( 'customer_id' => $user_id ),
            array( 'id' => $lead_id )
        );

        wp_send_json_success( array(
            'message'   => 'Cliente creado exitosamente.',
            'user_id'   => $user_id,
            'edit_url'  => get_edit_user_link( $user_id ),
            'user_name' => $lead->contact_name ?: $username,
        ) );
    }

    // =========================================================================
    // WOOCOMMERCE AUTO-CAPTURE
    // =========================================================================

    public function maybe_auto_capture_lead( $order_id ) {
        if ( ! get_option( 'wbi_crm_auto_capture', 0 ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        global $wpdb;

        $email = $order->get_billing_email();
        if ( ! $email ) {
            return;
        }

        // Check if lead already exists for this email
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}wbi_crm_leads WHERE contact_email = %s AND status = 'active' LIMIT 1",
            $email
        ) );

        if ( $existing ) {
            return;
        }

        // Get first stage
        $first_stage = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}wbi_crm_stages ORDER BY position ASC LIMIT 1" );

        $title = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $title = trim( $title ) ?: $email;

        $wpdb->insert(
            "{$wpdb->prefix}wbi_crm_leads",
            array(
                'title'            => 'Pedido WC #' . $order_id . ' — ' . $title,
                'contact_name'     => $title,
                'contact_email'    => $email,
                'contact_phone'    => $order->get_billing_phone(),
                'company'          => $order->get_billing_company(),
                'customer_id'      => $order->get_customer_id() ?: 0,
                'source'           => 'woocommerce',
                'stage_id'         => $first_stage,
                'expected_revenue' => floatval( $order->get_total() ),
                'status'           => 'active',
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%f', '%s' )
        );
    }

    // =========================================================================
    // DASHBOARD WIDGET
    // =========================================================================

    public function register_dashboard_widget( $widgets ) {
        global $wpdb;

        $total_active   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wbi_crm_leads WHERE status = 'active'" );
        $pipeline_value = (float) $wpdb->get_var( "SELECT SUM(expected_revenue) FROM {$wpdb->prefix}wbi_crm_leads WHERE status = 'active'" );
        $won_month      = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wbi_crm_leads WHERE status = 'won' AND won_at >= %s",
            gmdate( 'Y-m-01 00:00:00' )
        ) );
        $total_all = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wbi_crm_leads" );
        $total_won = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wbi_crm_leads WHERE status = 'won'" );
        $conv_rate = $total_all > 0 ? round( ( $total_won / $total_all ) * 100, 1 ) : 0;

        $widgets['crm'] = array(
            'icon'  => '🎯',
            'title' => 'CRM Pipeline',
            'url'   => admin_url( 'admin.php?page=wbi-crm' ),
            'stats' => array(
                array( 'label' => 'Leads Activos',       'value' => $total_active ),
                array( 'label' => 'Valor Pipeline',      'value' => wc_price( $pipeline_value ) ),
                array( 'label' => 'Ganados este mes',    'value' => $won_month ),
                array( 'label' => 'Conversión',          'value' => $conv_rate . '%' ),
            ),
        );

        return $widgets;
    }

    // =========================================================================
    // EMAIL REPORTS INTEGRATION
    // =========================================================================

    public function add_email_report_section( $sections ) {
        global $wpdb;

        $total_active   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wbi_crm_leads WHERE status = 'active'" );
        $pipeline_value = (float) $wpdb->get_var( "SELECT SUM(expected_revenue) FROM {$wpdb->prefix}wbi_crm_leads WHERE status = 'active'" );
        $won_month      = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wbi_crm_leads WHERE status = 'won' AND won_at >= %s",
            gmdate( 'Y-m-01 00:00:00' )
        ) );

        $sections['crm'] = array(
            'title'   => '🎯 CRM Pipeline',
            'content' => sprintf(
                'Leads activos: %d | Valor pipeline: %s | Ganados este mes: %d',
                $total_active,
                strip_tags( wc_price( $pipeline_value ) ),
                $won_month
            ),
        );

        return $sections;
    }

    // =========================================================================
    // HELPER: GET CARD HTML (for AJAX quick-add)
    // =========================================================================

    private function get_card_html( $lead_id ) {
        global $wpdb;
        $lead = $wpdb->get_row( $wpdb->prepare(
            "SELECT l.*, u.display_name as assigned_name FROM {$wpdb->prefix}wbi_crm_leads l
             LEFT JOIN {$wpdb->users} u ON l.assigned_to = u.ID WHERE l.id = %d",
            $lead_id
        ) );
        if ( ! $lead ) {
            return '';
        }
        ob_start();
        $this->render_kanban_card( $lead );
        return ob_get_clean();
    }

    // =========================================================================
    // KANBAN DRAG-AND-DROP JAVASCRIPT (vanilla JS, no dependencies)
    // =========================================================================

    private function get_kanban_js( $ajax_url, $nonce ) {
        ob_start();
        ?>
        (function() {
            'use strict';

            var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
            var nonce   = <?php echo wp_json_encode( $nonce ); ?>;

            // ── Kanban drag-and-drop ────────────────────────────────────────
            var draggingCard  = null;
            var draggingStage = null;

            function initKanban() {
                var board = document.getElementById('wbi-crm-board');
                if (!board) return;

                board.addEventListener('dragstart', function(e) {
                    var card = e.target.closest('.wbi-crm-card');
                    if (!card) return;
                    draggingCard  = card;
                    draggingStage = card.dataset.stage;
                    card.classList.add('dragging');
                    e.dataTransfer.effectAllowed = 'move';
                });

                board.addEventListener('dragend', function(e) {
                    var card = e.target.closest('.wbi-crm-card');
                    if (card) card.classList.remove('dragging');
                    document.querySelectorAll('.wbi-crm-drop-placeholder').forEach(function(p) {
                        p.style.display = 'none';
                    });
                    document.querySelectorAll('.wbi-crm-column').forEach(function(c) {
                        c.classList.remove('drag-over');
                    });
                    draggingCard = null;
                });

                board.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    var col = e.target.closest('.wbi-crm-column');
                    if (!col) return;
                    document.querySelectorAll('.wbi-crm-column').forEach(function(c) {
                        c.classList.remove('drag-over');
                    });
                    col.classList.add('drag-over');
                });

                board.addEventListener('dragleave', function(e) {
                    var col = e.target.closest('.wbi-crm-column');
                    if (col && !col.contains(e.relatedTarget)) {
                        col.classList.remove('drag-over');
                    }
                });

                board.addEventListener('drop', function(e) {
                    e.preventDefault();
                    if (!draggingCard) return;

                    var col      = e.target.closest('.wbi-crm-column');
                    if (!col) return;

                    var newStage = col.dataset.stage;
                    var leadId   = draggingCard.dataset.lead;

                    col.classList.remove('drag-over');

                    if (newStage === draggingStage) {
                        // Same column — reorder (visual only, no AJAX needed)
                        var cards      = col.querySelector('.wbi-crm-cards');
                        var targetCard = e.target.closest('.wbi-crm-card');
                        if (cards) {
                            cards.insertBefore(draggingCard, targetCard || null);
                        }
                        return;
                    }

                    // Move to new column
                    var targetCards = col.querySelector('.wbi-crm-cards');
                    if (targetCards) {
                        var placeholder = targetCards.querySelector('.wbi-crm-drop-placeholder');
                        targetCards.insertBefore(draggingCard, placeholder || null);
                    }
                    draggingCard.dataset.stage = newStage;
                    draggingStage = newStage;

                    // AJAX call
                    var fd = new FormData();
                    fd.append('action', 'wbi_crm_move_lead');
                    fd.append('_wpnonce', nonce);
                    fd.append('lead_id',  leadId);
                    fd.append('stage_id', newStage);

                    fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.success && data.data.won) {
                                // Highlight won card
                                draggingCard && draggingCard.classList.add('wbi-crm-card-won');
                            }
                            // Refresh stage counts
                            refreshStageCounts();
                        });
                });
            }

            function refreshStageCounts() {
                document.querySelectorAll('.wbi-crm-column').forEach(function(col) {
                    var count = col.querySelectorAll('.wbi-crm-card').length;
                    var badge = col.querySelector('.wbi-crm-stage-count');
                    if (badge) badge.textContent = count;
                });
            }

            // ── Quick-add modal ─────────────────────────────────────────────
            function initModal() {
                var btn     = document.getElementById('wbi-crm-quick-add-btn');
                var modal   = document.getElementById('wbi-crm-modal');
                var closeBtn= document.getElementById('wbi-crm-modal-close');
                var cancelBtn= document.getElementById('wbi-crm-modal-cancel');
                var form    = document.getElementById('wbi-crm-quick-form');
                var msg     = document.getElementById('wbi-crm-quick-msg');

                if (!btn || !modal) return;

                btn.addEventListener('click', function() { modal.style.display = 'flex'; });
                if (closeBtn) closeBtn.addEventListener('click', function() { modal.style.display = 'none'; });
                if (cancelBtn) cancelBtn.addEventListener('click', function() { modal.style.display = 'none'; });

                modal.addEventListener('click', function(e) {
                    if (e.target === modal) modal.style.display = 'none';
                });

                if (form) {
                    form.addEventListener('submit', function(e) {
                        e.preventDefault();
                        var fd = new FormData(form);

                        fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.success) {
                                    msg.style.color = 'var(--wbi-success)';
                                    msg.textContent = '✅ Lead creado correctamente.';
                                    msg.style.display = 'block';
                                    form.reset();
                                    // Inject card into the board
                                    var stageId = fd.get('stage_id');
                                    var col = document.querySelector('.wbi-crm-cards[data-stage="' + stageId + '"]');
                                    if (col && data.data.html) {
                                        var tmp = document.createElement('div');
                                        tmp.innerHTML = data.data.html;
                                        col.insertBefore(tmp.firstChild, col.firstChild);
                                        initCardDrag(col.firstChild);
                                    }
                                    refreshStageCounts();
                                    setTimeout(function() { modal.style.display = 'none'; msg.style.display = 'none'; }, 1500);
                                } else {
                                    msg.style.color = 'var(--wbi-danger)';
                                    msg.textContent = '✕ ' + (data.data ? data.data.message : 'Error.');
                                    msg.style.display = 'block';
                                }
                            });
                    });
                }
            }

            function initCardDrag(card) {
                // Cards are draggable by default (set in HTML). This function is a no-op placeholder
                // since the board-level event delegation handles all drag events.
            }

            // ── Mark won / lost (lead detail page) ─────────────────────────
            function initDetailActions() {
                var wonBtn  = document.getElementById('wbi-crm-mark-won');
                var lostBtn = document.getElementById('wbi-crm-mark-lost');
                var lostModal     = document.getElementById('wbi-crm-lost-modal');
                var lostClose     = document.getElementById('wbi-crm-lost-modal-close');
                var lostCancel    = document.getElementById('wbi-crm-lost-cancel');
                var lostConfirm   = document.getElementById('wbi-crm-lost-confirm');
                var lostReason    = document.getElementById('wbi-crm-lost-reason');

                if (wonBtn) {
                    wonBtn.addEventListener('click', function() {
                        var leadId    = wonBtn.dataset.lead;
                        var btnNonce  = wonBtn.dataset.nonce;
                        var fd = new FormData();
                        fd.append('action',   'wbi_crm_mark_won');
                        fd.append('_wpnonce', btnNonce);
                        fd.append('lead_id',  leadId);
                        fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                            .then(function(r) { return r.json(); })
                            .then(function(data) { if (data.success) location.reload(); });
                    });
                }

                if (lostBtn && lostModal) {
                    lostBtn.addEventListener('click', function() { lostModal.style.display = 'flex'; });
                    if (lostClose)  lostClose.addEventListener('click',  function() { lostModal.style.display = 'none'; });
                    if (lostCancel) lostCancel.addEventListener('click', function() { lostModal.style.display = 'none'; });

                    if (lostConfirm) {
                        lostConfirm.addEventListener('click', function() {
                            var leadId   = lostBtn.dataset.lead;
                            var btnNonce = lostBtn.dataset.nonce;
                            var reason   = lostReason ? lostReason.value : '';
                            var fd = new FormData();
                            fd.append('action',      'wbi_crm_mark_lost');
                            fd.append('_wpnonce',    btnNonce);
                            fd.append('lead_id',     leadId);
                            fd.append('lost_reason', reason);
                            fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                                .then(function(r) { return r.json(); })
                                .then(function(data) { if (data.success) location.reload(); });
                        });
                    }
                }

                // Add activity form (lead detail)
                var actForm = document.getElementById('wbi-crm-add-activity-form');
                var actMsg  = document.getElementById('wbi-crm-activity-msg');
                var timeline = document.getElementById('wbi-crm-timeline');
                if (actForm) {
                    actForm.addEventListener('submit', function(e) {
                        e.preventDefault();
                        var fd = new FormData(actForm);
                        fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.success) {
                                    actMsg.style.color   = 'var(--wbi-success)';
                                    actMsg.textContent   = '✅ Actividad agregada.';
                                    actMsg.style.display = 'block';
                                    actForm.reset();
                                    if (timeline && data.data.html) {
                                        var tmp = document.createElement('div');
                                        tmp.innerHTML = data.data.html;
                                        timeline.insertBefore(tmp.firstChild, timeline.firstChild);
                                        initCompleteActivity(timeline.firstChild);
                                    }
                                    setTimeout(function() { actMsg.style.display = 'none'; }, 3000);
                                } else {
                                    actMsg.style.color   = 'var(--wbi-danger)';
                                    actMsg.textContent   = '✕ Error.';
                                    actMsg.style.display = 'block';
                                }
                            });
                    });
                }

                // Complete activity buttons
                document.querySelectorAll('.wbi-crm-complete-activity').forEach(initCompleteActivity);

                // Convert to customer
                var convertBtn = document.getElementById('wbi-crm-convert-customer');
                var convertMsg = document.getElementById('wbi-crm-convert-msg');
                if (convertBtn) {
                    convertBtn.addEventListener('click', function() {
                        var leadId   = convertBtn.dataset.lead;
                        var btnNonce = convertBtn.dataset.nonce;
                        var fd = new FormData();
                        fd.append('action',   'wbi_crm_convert_customer');
                        fd.append('_wpnonce', btnNonce);
                        fd.append('lead_id',  leadId);
                        fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.success) {
                                    convertMsg.style.color   = 'var(--wbi-success)';
                                    convertMsg.innerHTML     = '✅ <a href="' + data.data.edit_url + '">' + data.data.user_name + '</a>';
                                    convertBtn.disabled      = true;
                                } else {
                                    convertMsg.style.color = 'var(--wbi-danger)';
                                    convertMsg.textContent = '✕ ' + (data.data ? data.data.message : 'Error.');
                                }
                            });
                    });
                }
            }

            function initCompleteActivity(btn) {
                if (!btn || !btn.classList || !btn.classList.contains('wbi-crm-complete-activity')) return;
                btn.addEventListener('click', function() {
                    var actId    = btn.dataset.activity;
                    var btnNonce = btn.dataset.nonce;
                    var fd = new FormData();
                    fd.append('action',      'wbi_crm_complete_activity');
                    fd.append('_wpnonce',    btnNonce);
                    fd.append('activity_id', actId);
                    fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.success) {
                                var item = document.getElementById('wbi-crm-act-' + actId);
                                if (item) {
                                    item.classList.add('done');
                                    btn.outerHTML = '<span style="color:var(--wbi-success);font-size:12px;">✅ Completada</span>';
                                }
                            }
                        });
                });
            }

            // ── Stage edit buttons ──────────────────────────────────────────
            function initStageEdit() {
                document.querySelectorAll('.wbi-crm-edit-stage').forEach(function(link) {
                    link.addEventListener('click', function(e) {
                        e.preventDefault();
                        document.getElementById('wbi-crm-stage-id').value       = link.dataset.id;
                        document.getElementById('wbi-crm-stage-name').value     = link.dataset.name;
                        document.getElementById('wbi-crm-stage-position').value = link.dataset.position;
                        document.getElementById('wbi-crm-stage-color').value    = link.dataset.color;
                        document.getElementById('wbi-crm-stage-is-won').checked = link.dataset.isWon === '1';
                        document.getElementById('wbi-crm-stage-form-title').textContent = 'Editar Etapa';
                        document.getElementById('wbi-crm-stage-form-card').scrollIntoView({ behavior: 'smooth' });
                    });
                });
            }

            // ── Init ────────────────────────────────────────────────────────
            document.addEventListener('DOMContentLoaded', function() {
                initKanban();
                initModal();
                initDetailActions();
                initStageEdit();
            });
        })();
        <?php
        return ob_get_clean();
    }
}
