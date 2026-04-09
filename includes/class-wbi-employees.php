<?php
/**
 * WBI Employees Module — Gestión de Empleados / RRHH
 *
 * Módulo completo de Recursos Humanos: empleados, departamentos, contratos,
 * habilidades, reclutamiento y configuración organizativa.
 *
 * @package WBI_Suite
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Employees_Module {

    const NONCE_ACTION = 'wbi_employees_nonce';
    const DB_VERSION   = '1.0';

    public function __construct() {
        add_action( 'admin_menu',            array( $this, 'register_pages' ), 100 );
        add_action( 'admin_init',            array( $this, 'maybe_create_tables' ) );
        add_action( 'admin_init',            array( $this, 'handle_forms' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    // =========================================================================
    // DATABASE SETUP
    // =========================================================================

    public function maybe_create_tables() {
        if ( get_option( 'wbi_employees_db_version' ) === self::DB_VERSION ) {
            return;
        }
        $this->create_tables();
        update_option( 'wbi_employees_db_version', self::DB_VERSION );
    }

    public function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE {$wpdb->prefix}wbi_departments (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(150) NOT NULL DEFAULT '',
            parent_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
            manager_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
            color       VARCHAR(7)   NOT NULL DEFAULT '#0073aa',
            description TEXT,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}wbi_work_locations (
            id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name    VARCHAR(150) NOT NULL DEFAULT '',
            address VARCHAR(255) NOT NULL DEFAULT '',
            type    VARCHAR(20)  NOT NULL DEFAULT 'oficina',
            PRIMARY KEY (id)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}wbi_work_schedules (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name           VARCHAR(150) NOT NULL DEFAULT '',
            hours_per_week DECIMAL(5,2) NOT NULL DEFAULT 40.00,
            description    TEXT,
            PRIMARY KEY (id)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}wbi_departure_reasons (
            id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(150) NOT NULL DEFAULT '',
            PRIMARY KEY (id)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}wbi_skill_types (
            id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(150) NOT NULL DEFAULT '',
            PRIMARY KEY (id)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}wbi_employee_tags (
            id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name  VARCHAR(100) NOT NULL DEFAULT '',
            color VARCHAR(7)   NOT NULL DEFAULT '#0073aa',
            PRIMARY KEY (id)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}wbi_job_positions (
            id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name               VARCHAR(200) NOT NULL DEFAULT '',
            department_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            description        TEXT,
            expected_employees INT NOT NULL DEFAULT 1,
            current_employees  INT NOT NULL DEFAULT 0,
            status             VARCHAR(10) NOT NULL DEFAULT 'abierto',
            created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}wbi_contract_templates (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name             VARCHAR(200) NOT NULL DEFAULT '',
            salary_structure VARCHAR(150) NOT NULL DEFAULT '',
            work_schedule_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            description      TEXT,
            created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}wbi_employees (
            id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            first_name              VARCHAR(100) NOT NULL DEFAULT '',
            last_name               VARCHAR(100) NOT NULL DEFAULT '',
            email                   VARCHAR(200) NOT NULL DEFAULT '',
            phone                   VARCHAR(50)  NOT NULL DEFAULT '',
            mobile                  VARCHAR(50)  NOT NULL DEFAULT '',
            job_title               VARCHAR(200) NOT NULL DEFAULT '',
            department_id           BIGINT UNSIGNED NOT NULL DEFAULT 0,
            work_location_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
            manager_id              BIGINT UNSIGNED NOT NULL DEFAULT 0,
            coach_id                BIGINT UNSIGNED NOT NULL DEFAULT 0,
            photo_url               VARCHAR(500) NOT NULL DEFAULT '',
            date_of_birth           DATE DEFAULT NULL,
            gender                  VARCHAR(20)  NOT NULL DEFAULT '',
            marital_status          VARCHAR(20)  NOT NULL DEFAULT '',
            identification_number   VARCHAR(50)  NOT NULL DEFAULT '',
            address                 VARCHAR(255) NOT NULL DEFAULT '',
            city                    VARCHAR(100) NOT NULL DEFAULT '',
            state                   VARCHAR(100) NOT NULL DEFAULT '',
            country                 VARCHAR(100) NOT NULL DEFAULT '',
            postal_code             VARCHAR(20)  NOT NULL DEFAULT '',
            emergency_contact_name  VARCHAR(200) NOT NULL DEFAULT '',
            emergency_contact_phone VARCHAR(50)  NOT NULL DEFAULT '',
            hire_date               DATE DEFAULT NULL,
            departure_date          DATE DEFAULT NULL,
            departure_reason_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            work_schedule_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status                  VARCHAR(20)  NOT NULL DEFAULT 'active',
            notes                   LONGTEXT,
            created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}wbi_employee_tag_relations (
            employee_id BIGINT UNSIGNED NOT NULL,
            tag_id      BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (employee_id, tag_id)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}wbi_employee_skills (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id    BIGINT UNSIGNED NOT NULL,
            skill_type_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
            skill_name     VARCHAR(200) NOT NULL DEFAULT '',
            level          VARCHAR(20)  NOT NULL DEFAULT 'básico',
            PRIMARY KEY (id)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}wbi_employee_resume_lines (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            section     VARCHAR(50)  NOT NULL DEFAULT 'experiencia',
            title       VARCHAR(200) NOT NULL DEFAULT '',
            subtitle    VARCHAR(200) NOT NULL DEFAULT '',
            date_start  DATE DEFAULT NULL,
            date_end    DATE DEFAULT NULL,
            description TEXT,
            PRIMARY KEY (id)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}wbi_employee_contracts (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id         BIGINT UNSIGNED NOT NULL,
            contract_template_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            salary              DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            start_date          DATE DEFAULT NULL,
            end_date            DATE DEFAULT NULL,
            status              VARCHAR(20)  NOT NULL DEFAULT 'draft',
            notes               LONGTEXT,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;" );

        $this->maybe_seed_data();
    }

    private function maybe_seed_data() {
        global $wpdb;

        // Departments
        if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wbi_departments" ) ) {
            $departments = array( 'Administración', 'Ventas', 'Operaciones', 'RRHH', 'IT' );
            $colors      = array( '#0073aa', '#46b450', '#e65054', '#9b59b6', '#00a0d2' );
            foreach ( $departments as $i => $name ) {
                $wpdb->insert( $wpdb->prefix . 'wbi_departments', array(
                    'name'  => $name,
                    'color' => $colors[ $i ],
                ) );
            }
        }

        // Work locations
        if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wbi_work_locations" ) ) {
            $wpdb->insert( $wpdb->prefix . 'wbi_work_locations', array( 'name' => 'Oficina Central', 'type' => 'oficina', 'address' => '' ) );
            $wpdb->insert( $wpdb->prefix . 'wbi_work_locations', array( 'name' => 'Remoto',          'type' => 'remoto',  'address' => '' ) );
        }

        // Work schedules
        if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wbi_work_schedules" ) ) {
            $wpdb->insert( $wpdb->prefix . 'wbi_work_schedules', array( 'name' => 'Jornada Completa', 'hours_per_week' => 40, 'description' => 'Lunes a viernes, 8 horas diarias' ) );
            $wpdb->insert( $wpdb->prefix . 'wbi_work_schedules', array( 'name' => 'Media Jornada',    'hours_per_week' => 20, 'description' => 'Lunes a viernes, 4 horas diarias' ) );
        }

        // Departure reasons
        if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wbi_departure_reasons" ) ) {
            foreach ( array( 'Renuncia', 'Despido', 'Fin de contrato', 'Jubilación' ) as $reason ) {
                $wpdb->insert( $wpdb->prefix . 'wbi_departure_reasons', array( 'name' => $reason ) );
            }
        }

        // Skill types
        if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wbi_skill_types" ) ) {
            foreach ( array( 'Idiomas', 'Programación', 'Gestión', 'Comunicación' ) as $skill ) {
                $wpdb->insert( $wpdb->prefix . 'wbi_skill_types', array( 'name' => $skill ) );
            }
        }
    }

    // =========================================================================
    // ADMIN MENU
    // =========================================================================

    public function register_pages() {
        $loader = $GLOBALS['wbi_suite_loader'] ?? null;
        if ( $loader && ! $loader->user_can_access_module( 'employees' ) ) {
            return;
        }

        $cap = current_user_can( 'manage_options' ) ? 'manage_options' : 'manage_woocommerce';

        add_submenu_page(
            'wbi-dashboard-view',
            'Empleados / RRHH',
            '<span class="dashicons dashicons-groups" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> Empleados',
            $cap,
            'wbi-employees',
            array( $this, 'render_page' )
        );
    }

    // =========================================================================
    // PAGE ROUTER
    // =========================================================================

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'No tenés permisos para acceder a esta página.', 'wbi-suite' ) );
        }

        $tab    = sanitize_key( $_GET['tab']    ?? 'employees' );
        $action = sanitize_key( $_GET['action'] ?? '' );

        // Form actions render full-page (with their own wrap + back button)
        if ( in_array( $action, array( 'new', 'edit' ), true ) ) {
            switch ( $tab ) {
                case 'departments':
                    $this->render_department_form( $action );
                    return;
                case 'contracts':
                    $this->render_contract_form( $action );
                    return;
                default:
                    $this->render_employee_form( $action );
                    return;
            }
        }

        $base_url = admin_url( 'admin.php?page=wbi-employees' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-groups" style="font-size:22px;vertical-align:middle;margin-right:6px;"></span>
                Empleados / RRHH
            </h1>

            <nav class="nav-tab-wrapper" style="margin-top:12px;">
                <a href="<?php echo esc_url( $base_url . '&tab=employees' ); ?>"
                   class="nav-tab <?php echo 'employees' === $tab ? 'nav-tab-active' : ''; ?>">
                    👥 Empleados
                </a>
                <a href="<?php echo esc_url( $base_url . '&tab=departments' ); ?>"
                   class="nav-tab <?php echo 'departments' === $tab ? 'nav-tab-active' : ''; ?>">
                    🏢 Departamentos
                </a>
                <a href="<?php echo esc_url( $base_url . '&tab=contracts' ); ?>"
                   class="nav-tab <?php echo 'contracts' === $tab ? 'nav-tab-active' : ''; ?>">
                    📋 Contratos
                </a>
                <a href="<?php echo esc_url( $base_url . '&tab=settings' ); ?>"
                   class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>">
                    ⚙️ Configuración
                </a>
            </nav>

            <div style="margin-top:16px;">
                <?php
                switch ( $tab ) {
                    case 'departments':
                        $this->render_department_list();
                        break;
                    case 'contracts':
                        $this->render_contract_list();
                        break;
                    case 'settings':
                        $this->page_config();
                        break;
                    default:
                        $this->render_employee_list();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // ASSETS
    // =========================================================================

    public function enqueue_assets( $hook ) {
        if ( false === strpos( $hook, 'wbi-employees' ) ) {
            return;
        }
        wp_add_inline_style( 'wp-admin', $this->get_inline_css() );
    }

    private function get_inline_css() {
        return '
        .wbi-emp-header { display:flex; align-items:center; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
        .wbi-emp-header h1 { margin:0; }
        .wbi-emp-actions { display:flex; gap:8px; flex-wrap:wrap; }
        .wbi-emp-search { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .wbi-emp-card-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:16px; margin-top:16px; }
        .wbi-emp-card { background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:16px; position:relative; transition:box-shadow .2s; }
        .wbi-emp-card:hover { box-shadow:0 2px 8px rgba(0,0,0,.12); }
        .wbi-emp-card .emp-avatar { width:56px; height:56px; border-radius:50%; background:#e0e0e0; display:flex; align-items:center; justify-content:center; font-size:20px; font-weight:700; color:#fff; overflow:hidden; flex-shrink:0; text-transform:uppercase; }
        .wbi-emp-card .emp-avatar img { width:100%; height:100%; object-fit:cover; }
        .wbi-emp-card .emp-name { font-size:15px; font-weight:700; margin:0 0 2px; color:#1d2327; }
        .wbi-emp-card .emp-title { font-size:12px; color:#50575e; margin:0 0 4px; }
        .wbi-emp-card .emp-dept { font-size:12px; color:#0073aa; }
        .wbi-emp-card .emp-info { display:flex; align-items:center; gap:12px; }
        .wbi-emp-card .emp-meta { flex:1; }
        .wbi-emp-card .emp-actions { margin-top:12px; display:flex; gap:6px; justify-content:flex-end; }
        .wbi-emp-card.archived { opacity:.65; border-style:dashed; }
        .wbi-emp-status { display:inline-block; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:600; }
        .wbi-emp-status.active { background:#d4edda; color:#155724; }
        .wbi-emp-status.archived { background:#e2e3e5; color:#383d41; }
        .wbi-emp-status.departed { background:#f8d7da; color:#721c24; }
        .wbi-dept-card { background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:16px 18px; display:flex; align-items:center; gap:14px; transition:box-shadow .2s; }
        .wbi-dept-card:hover { box-shadow:0 2px 8px rgba(0,0,0,.1); }
        .wbi-dept-color-badge { width:14px; height:50px; border-radius:4px; flex-shrink:0; }
        .wbi-dept-name { font-size:15px; font-weight:700; margin:0 0 2px; }
        .wbi-dept-count { font-size:13px; color:#50575e; }
        .wbi-dept-actions { margin-left:auto; display:flex; gap:6px; }
        .wbi-status-counters { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px; align-items:center; }
        .wbi-status-counter { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:14px; font-size:12px; font-weight:600; }
        .wbi-status-counter.active { background:#d4edda; color:#155724; }
        .wbi-status-counter.archived { background:#e2e3e5; color:#383d41; }
        .wbi-status-counter.departed { background:#f8d7da; color:#721c24; }
        .wbi-contract-status { display:inline-block; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:600; }
        .wbi-contract-status.draft { background:#fff3cd; color:#856404; }
        .wbi-contract-status.active { background:#d4edda; color:#155724; }
        .wbi-contract-status.expired { background:#f8d7da; color:#721c24; }
        .wbi-contract-status.cancelled { background:#e2e3e5; color:#383d41; }
        .wbi-tabs { display:flex; gap:0; border-bottom:2px solid #c3c4c7; margin-bottom:20px; flex-wrap:wrap; }
        .wbi-tab { padding:10px 18px; cursor:pointer; font-size:13px; font-weight:600; color:#50575e; border:none; background:none; border-bottom:3px solid transparent; margin-bottom:-2px; }
        .wbi-tab.active { color:#0073aa; border-bottom-color:#0073aa; }
        .wbi-tab-content { display:none; }
        .wbi-tab-content.active { display:block; }
        .wbi-view-toggle { display:flex; gap:4px; }
        .wbi-view-toggle button { padding:5px 10px; border:1px solid #c3c4c7; background:#fff; border-radius:4px; cursor:pointer; font-size:13px; }
        .wbi-view-toggle button.active { background:#0073aa; color:#fff; border-color:#0073aa; }
        .wbi-list-view, .wbi-card-view { }
        .wbi-filter-bar { background:#fff; border:1px solid #c3c4c7; border-radius:6px; padding:12px 16px; margin-bottom:16px; display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
        .wbi-section-title { font-size:15px; font-weight:700; margin:20px 0 8px; border-bottom:1px solid #e0e0e0; padding-bottom:6px; }
        ';
    }

    // =========================================================================
    // FORM HANDLERS
    // =========================================================================

    public function handle_forms() {
        if ( ! isset( $_POST['wbi_emp_action'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', self::NONCE_ACTION ) ) {
            wp_die( 'Verificación de seguridad fallida.' );
        }

        $action = sanitize_text_field( $_POST['wbi_emp_action'] );

        switch ( $action ) {
            case 'save_employee':      $this->handle_save_employee(); break;
            case 'delete_employee':    $this->handle_delete_employee(); break;
            case 'save_department':    $this->handle_save_department(); break;
            case 'delete_department':  $this->handle_delete_department(); break;
            case 'save_contract':      $this->handle_save_contract(); break;
            case 'delete_contract':    $this->handle_delete_contract(); break;
            case 'save_config_item':   $this->handle_save_config_item(); break;
            case 'delete_config_item': $this->handle_delete_config_item(); break;
        }
    }

    // ── Employees ─────────────────────────────────────────────────────────────

    private function handle_save_employee() {
        global $wpdb;
        $id = intval( $_POST['employee_id'] ?? 0 );

        $data = array(
            'first_name'              => sanitize_text_field( $_POST['first_name'] ?? '' ),
            'last_name'               => sanitize_text_field( $_POST['last_name'] ?? '' ),
            'email'                   => sanitize_email( $_POST['email'] ?? '' ),
            'phone'                   => sanitize_text_field( $_POST['phone'] ?? '' ),
            'mobile'                  => sanitize_text_field( $_POST['mobile'] ?? '' ),
            'job_title'               => sanitize_text_field( $_POST['job_title'] ?? '' ),
            'department_id'           => intval( $_POST['department_id'] ?? 0 ),
            'work_location_id'        => intval( $_POST['work_location_id'] ?? 0 ),
            'manager_id'              => intval( $_POST['manager_id'] ?? 0 ),
            'coach_id'                => intval( $_POST['coach_id'] ?? 0 ),
            'photo_url'               => esc_url_raw( $_POST['photo_url'] ?? '' ),
            'date_of_birth'           => sanitize_text_field( $_POST['date_of_birth'] ?? '' ) ?: null,
            'gender'                  => sanitize_text_field( $_POST['gender'] ?? '' ),
            'marital_status'          => sanitize_text_field( $_POST['marital_status'] ?? '' ),
            'identification_number'   => sanitize_text_field( $_POST['identification_number'] ?? '' ),
            'address'                 => sanitize_text_field( $_POST['address'] ?? '' ),
            'city'                    => sanitize_text_field( $_POST['city'] ?? '' ),
            'state'                   => sanitize_text_field( $_POST['state'] ?? '' ),
            'country'                 => sanitize_text_field( $_POST['country'] ?? '' ),
            'postal_code'             => sanitize_text_field( $_POST['postal_code'] ?? '' ),
            'emergency_contact_name'  => sanitize_text_field( $_POST['emergency_contact_name'] ?? '' ),
            'emergency_contact_phone' => sanitize_text_field( $_POST['emergency_contact_phone'] ?? '' ),
            'hire_date'               => sanitize_text_field( $_POST['hire_date'] ?? '' ) ?: null,
            'departure_date'          => sanitize_text_field( $_POST['departure_date'] ?? '' ) ?: null,
            'departure_reason_id'     => intval( $_POST['departure_reason_id'] ?? 0 ),
            'work_schedule_id'        => intval( $_POST['work_schedule_id'] ?? 0 ),
            'status'                  => sanitize_text_field( $_POST['emp_status'] ?? 'active' ),
            'notes'                   => wp_kses_post( $_POST['notes'] ?? '' ),
        );

        if ( $id > 0 ) {
            $wpdb->update( $wpdb->prefix . 'wbi_employees', $data, array( 'id' => $id ) );
        } else {
            $wpdb->insert( $wpdb->prefix . 'wbi_employees', $data );
            $id = $wpdb->insert_id;
        }

        // Tags
        if ( $id > 0 ) {
            $wpdb->delete( $wpdb->prefix . 'wbi_employee_tag_relations', array( 'employee_id' => $id ) );
            $tags = isset( $_POST['tags'] ) ? array_map( 'intval', (array) $_POST['tags'] ) : array();
            foreach ( $tags as $tag_id ) {
                if ( $tag_id > 0 ) {
                    $wpdb->replace( $wpdb->prefix . 'wbi_employee_tag_relations', array( 'employee_id' => $id, 'tag_id' => $tag_id ) );
                }
            }

            // Skills
            if ( isset( $_POST['skills'] ) && is_array( $_POST['skills'] ) ) {
                $wpdb->delete( $wpdb->prefix . 'wbi_employee_skills', array( 'employee_id' => $id ) );
                foreach ( $_POST['skills'] as $skill ) {
                    if ( empty( $skill['skill_name'] ) ) continue;
                    $wpdb->insert( $wpdb->prefix . 'wbi_employee_skills', array(
                        'employee_id'   => $id,
                        'skill_type_id' => intval( $skill['skill_type_id'] ?? 0 ),
                        'skill_name'    => sanitize_text_field( $skill['skill_name'] ),
                        'level'         => sanitize_text_field( $skill['level'] ?? 'básico' ),
                    ) );
                }
            }

            // Resume lines
            if ( isset( $_POST['resume'] ) && is_array( $_POST['resume'] ) ) {
                $wpdb->delete( $wpdb->prefix . 'wbi_employee_resume_lines', array( 'employee_id' => $id ) );
                foreach ( $_POST['resume'] as $line ) {
                    if ( empty( $line['title'] ) ) continue;
                    $wpdb->insert( $wpdb->prefix . 'wbi_employee_resume_lines', array(
                        'employee_id' => $id,
                        'section'     => sanitize_text_field( $line['section'] ?? 'experiencia' ),
                        'title'       => sanitize_text_field( $line['title'] ),
                        'subtitle'    => sanitize_text_field( $line['subtitle'] ?? '' ),
                        'date_start'  => sanitize_text_field( $line['date_start'] ?? '' ) ?: null,
                        'date_end'    => sanitize_text_field( $line['date_end'] ?? '' ) ?: null,
                        'description' => wp_kses_post( $line['description'] ?? '' ),
                    ) );
                }
            }
        }

        wp_redirect( admin_url( 'admin.php?page=wbi-employees&saved=1' ) );
        exit;
    }

    private function handle_delete_employee() {
        global $wpdb;
        $id = intval( $_POST['employee_id'] ?? 0 );
        if ( $id > 0 ) {
            $wpdb->delete( $wpdb->prefix . 'wbi_employees', array( 'id' => $id ) );
            $wpdb->delete( $wpdb->prefix . 'wbi_employee_tag_relations', array( 'employee_id' => $id ) );
            $wpdb->delete( $wpdb->prefix . 'wbi_employee_skills', array( 'employee_id' => $id ) );
            $wpdb->delete( $wpdb->prefix . 'wbi_employee_resume_lines', array( 'employee_id' => $id ) );
        }
        wp_redirect( admin_url( 'admin.php?page=wbi-employees&deleted=1' ) );
        exit;
    }

    // ── Departments ───────────────────────────────────────────────────────────

    private function handle_save_department() {
        global $wpdb;
        $id   = intval( $_POST['department_id'] ?? 0 );
        $data = array(
            'name'        => sanitize_text_field( $_POST['dept_name'] ?? '' ),
            'parent_id'   => intval( $_POST['parent_id'] ?? 0 ),
            'manager_id'  => intval( $_POST['manager_id'] ?? 0 ),
            'color'       => sanitize_hex_color( $_POST['dept_color'] ?? '#0073aa' ) ?: '#0073aa',
            'description' => wp_kses_post( $_POST['description'] ?? '' ),
        );
        if ( $id > 0 ) {
            $wpdb->update( $wpdb->prefix . 'wbi_departments', $data, array( 'id' => $id ) );
        } else {
            $wpdb->insert( $wpdb->prefix . 'wbi_departments', $data );
        }
        wp_redirect( admin_url( 'admin.php?page=wbi-employees&tab=departments&saved=1' ) );
        exit;
    }

    private function handle_delete_department() {
        global $wpdb;
        $id = intval( $_POST['department_id'] ?? 0 );
        if ( $id > 0 ) {
            $wpdb->delete( $wpdb->prefix . 'wbi_departments', array( 'id' => $id ) );
        }
        wp_redirect( admin_url( 'admin.php?page=wbi-employees&tab=departments&deleted=1' ) );
        exit;
    }

    // ── Contracts ─────────────────────────────────────────────────────────────

    private function handle_save_contract() {
        global $wpdb;
        $id   = intval( $_POST['contract_id'] ?? 0 );
        $data = array(
            'employee_id'          => intval( $_POST['contract_employee_id'] ?? 0 ),
            'contract_template_id' => intval( $_POST['contract_template_id'] ?? 0 ),
            'salary'               => floatval( $_POST['salary'] ?? 0 ),
            'start_date'           => sanitize_text_field( $_POST['start_date'] ?? '' ) ?: null,
            'end_date'             => sanitize_text_field( $_POST['end_date'] ?? '' ) ?: null,
            'status'               => sanitize_text_field( $_POST['contract_status'] ?? 'draft' ),
            'notes'                => wp_kses_post( $_POST['contract_notes'] ?? '' ),
        );
        if ( $id > 0 ) {
            $wpdb->update( $wpdb->prefix . 'wbi_employee_contracts', $data, array( 'id' => $id ) );
        } else {
            $wpdb->insert( $wpdb->prefix . 'wbi_employee_contracts', $data );
        }
        wp_redirect( admin_url( 'admin.php?page=wbi-employees&tab=contracts&saved=1' ) );
        exit;
    }

    private function handle_delete_contract() {
        global $wpdb;
        $id = intval( $_POST['contract_id'] ?? 0 );
        if ( $id > 0 ) {
            $wpdb->delete( $wpdb->prefix . 'wbi_employee_contracts', array( 'id' => $id ) );
        }
        wp_redirect( admin_url( 'admin.php?page=wbi-employees&tab=contracts&deleted=1' ) );
        exit;
    }

    // ── Config items ──────────────────────────────────────────────────────────

    private function handle_save_config_item() {
        global $wpdb;
        $type = sanitize_text_field( $_POST['config_type'] ?? '' );
        $id   = intval( $_POST['config_id'] ?? 0 );

        $table_map = array(
            'location'         => $wpdb->prefix . 'wbi_work_locations',
            'schedule'         => $wpdb->prefix . 'wbi_work_schedules',
            'departure_reason' => $wpdb->prefix . 'wbi_departure_reasons',
            'skill_type'       => $wpdb->prefix . 'wbi_skill_types',
            'tag'              => $wpdb->prefix . 'wbi_employee_tags',
            'job_position'     => $wpdb->prefix . 'wbi_job_positions',
            'contract_template'=> $wpdb->prefix . 'wbi_contract_templates',
        );

        if ( ! isset( $table_map[ $type ] ) ) {
            wp_redirect( admin_url( 'admin.php?page=wbi-employees&tab=settings' ) );
            exit;
        }

        $data = $this->sanitize_config_data( $type );
        $table = $table_map[ $type ];

        if ( $id > 0 ) {
            $wpdb->update( $table, $data, array( 'id' => $id ) );
        } else {
            $wpdb->insert( $table, $data );
        }

        wp_redirect( admin_url( 'admin.php?page=wbi-employees&tab=settings&subtab=' . $type . '&saved=1' ) );
        exit;
    }

    private function sanitize_config_data( $type ) {
        switch ( $type ) {
            case 'location':
                return array(
                    'name'    => sanitize_text_field( $_POST['config_name'] ?? '' ),
                    'address' => sanitize_text_field( $_POST['location_address'] ?? '' ),
                    'type'    => sanitize_text_field( $_POST['location_type'] ?? 'oficina' ),
                );
            case 'schedule':
                return array(
                    'name'           => sanitize_text_field( $_POST['config_name'] ?? '' ),
                    'hours_per_week' => floatval( $_POST['hours_per_week'] ?? 40 ),
                    'description'    => wp_kses_post( $_POST['config_description'] ?? '' ),
                );
            case 'departure_reason':
            case 'skill_type':
                return array( 'name' => sanitize_text_field( $_POST['config_name'] ?? '' ) );
            case 'tag':
                return array(
                    'name'  => sanitize_text_field( $_POST['config_name'] ?? '' ),
                    'color' => sanitize_hex_color( $_POST['tag_color'] ?? '#0073aa' ) ?: '#0073aa',
                );
            case 'job_position':
                return array(
                    'name'               => sanitize_text_field( $_POST['config_name'] ?? '' ),
                    'department_id'      => intval( $_POST['department_id'] ?? 0 ),
                    'description'        => wp_kses_post( $_POST['config_description'] ?? '' ),
                    'expected_employees' => intval( $_POST['expected_employees'] ?? 1 ),
                    'status'             => sanitize_text_field( $_POST['job_status'] ?? 'abierto' ),
                );
            case 'contract_template':
                return array(
                    'name'             => sanitize_text_field( $_POST['config_name'] ?? '' ),
                    'salary_structure' => sanitize_text_field( $_POST['salary_structure'] ?? '' ),
                    'work_schedule_id' => intval( $_POST['work_schedule_id'] ?? 0 ),
                    'description'      => wp_kses_post( $_POST['config_description'] ?? '' ),
                );
            default:
                return array();
        }
    }

    private function handle_delete_config_item() {
        global $wpdb;
        $type = sanitize_text_field( $_POST['config_type'] ?? '' );
        $id   = intval( $_POST['config_id'] ?? 0 );

        $table_map = array(
            'location'          => $wpdb->prefix . 'wbi_work_locations',
            'schedule'          => $wpdb->prefix . 'wbi_work_schedules',
            'departure_reason'  => $wpdb->prefix . 'wbi_departure_reasons',
            'skill_type'        => $wpdb->prefix . 'wbi_skill_types',
            'tag'               => $wpdb->prefix . 'wbi_employee_tags',
            'job_position'      => $wpdb->prefix . 'wbi_job_positions',
            'contract_template' => $wpdb->prefix . 'wbi_contract_templates',
        );

        if ( isset( $table_map[ $type ] ) && $id > 0 ) {
            $wpdb->delete( $table_map[ $type ], array( 'id' => $id ) );
        }

        wp_redirect( admin_url( 'admin.php?page=wbi-employees&tab=settings&subtab=' . $type . '&deleted=1' ) );
        exit;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function get_departments() {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wbi_departments ORDER BY name ASC" );
    }

    private function get_department( $id ) {
        global $wpdb;
        if ( ! $id ) return null;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wbi_departments WHERE id = %d", $id ) );
    }

    private function get_employees( $filters = array() ) {
        global $wpdb;
        $where  = '1=1';
        $params = array();

        if ( ! empty( $filters['search'] ) ) {
            $s = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
            $where .= $wpdb->prepare( " AND (first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR job_title LIKE %s)", $s, $s, $s, $s );
        }
        if ( ! empty( $filters['department_id'] ) ) {
            $where .= $wpdb->prepare( ' AND department_id = %d', $filters['department_id'] );
        }
        if ( ! empty( $filters['status'] ) ) {
            $where .= $wpdb->prepare( ' AND status = %s', $filters['status'] );
        }

        return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wbi_employees WHERE $where ORDER BY last_name, first_name ASC" );
    }

    private function get_employee( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wbi_employees WHERE id = %d", $id ) );
    }

    private function get_department_employee_count( $dept_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wbi_employees WHERE department_id = %d AND status = 'active'", $dept_id ) );
    }

    private function get_department_name( $id ) {
        global $wpdb;
        if ( ! $id ) return '—';
        return $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}wbi_departments WHERE id = %d", $id ) ) ?: '—';
    }

    private function get_employee_name( $id ) {
        global $wpdb;
        if ( ! $id ) return '—';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT first_name, last_name FROM {$wpdb->prefix}wbi_employees WHERE id = %d", $id ) );
        return $row ? esc_html( $row->first_name . ' ' . $row->last_name ) : '—';
    }

    private function get_employee_tags( $employee_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT t.* FROM {$wpdb->prefix}wbi_employee_tags t
             INNER JOIN {$wpdb->prefix}wbi_employee_tag_relations r ON r.tag_id = t.id
             WHERE r.employee_id = %d",
            $employee_id
        ) );
    }

    private function get_employee_skills( $employee_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT s.*, st.name AS type_name FROM {$wpdb->prefix}wbi_employee_skills s
             LEFT JOIN {$wpdb->prefix}wbi_skill_types st ON st.id = s.skill_type_id
             WHERE s.employee_id = %d",
            $employee_id
        ) );
    }

    private function get_employee_resume( $employee_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wbi_employee_resume_lines WHERE employee_id = %d ORDER BY section, date_start DESC",
            $employee_id
        ) );
    }

    private function status_label( $status ) {
        $map = array( 'active' => 'Activo', 'archived' => 'Archivado', 'departed' => 'Egresado' );
        return $map[ $status ] ?? ucfirst( $status );
    }

    private function nonce_field() {
        wp_nonce_field( self::NONCE_ACTION );
    }

    private function notice( $type, $msg ) {
        echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
    }

    private function get_avatar_color( $name ) {
        $colors = array( '#0073aa', '#46b450', '#e65054', '#9b59b6', '#00a0d2', '#f39c12', '#27ae60', '#e74c3c', '#2980b9', '#8e44ad' );
        return $colors[ abs( crc32( $name ) ) % count( $colors ) ];
    }

    // =========================================================================
    // PAGE: EMPLOYEES
    // =========================================================================

    public function page_employees() {
        $action = sanitize_text_field( $_GET['action'] ?? 'list' );

        if ( 'new' === $action || 'edit' === $action ) {
            $this->render_employee_form( $action );
        } else {
            $this->render_employee_list();
        }
    }

    private function render_employee_list() {
        global $wpdb;
        $departments = $this->get_departments();
        $filters     = array(
            'search'        => sanitize_text_field( $_GET['search'] ?? '' ),
            'department_id' => intval( $_GET['department_id'] ?? 0 ),
            'status'        => sanitize_text_field( $_GET['status'] ?? '' ),
        );
        $employees   = $this->get_employees( $filters );
        $view        = sanitize_text_field( $_GET['view'] ?? 'list' );

        // Count employees by status (unfiltered)
        $counts = array( 'active' => 0, 'archived' => 0, 'departed' => 0 );
        foreach ( $wpdb->get_results( "SELECT status, COUNT(*) as cnt FROM {$wpdb->prefix}wbi_employees GROUP BY status" ) as $row ) {
            if ( isset( $counts[ $row->status ] ) ) {
                $counts[ $row->status ] = (int) $row->cnt;
            }
        }
        ?>
        <div class="wbi-emp-header">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-employees&tab=employees&action=new' ) ); ?>" class="button button-primary">+ Nuevo Empleado</a>
        </div>

        <?php
        if ( ! empty( $_GET['saved'] ) )   $this->notice( 'success', 'Empleado guardado correctamente.' );
        if ( ! empty( $_GET['deleted'] ) ) $this->notice( 'success', 'Empleado eliminado.' );
        ?>

        <!-- Status counters -->
        <div class="wbi-status-counters">
            <span class="wbi-status-counter active">✓ Activos: <?php echo esc_html( $counts['active'] ); ?></span>
            <span class="wbi-status-counter archived">◎ Archivados: <?php echo esc_html( $counts['archived'] ); ?></span>
            <span class="wbi-status-counter departed">✕ Egresados: <?php echo esc_html( $counts['departed'] ); ?></span>
        </div>

        <!-- Filters -->
        <form method="get" class="wbi-filter-bar" id="emp-filter-form">
            <input type="hidden" name="page" value="wbi-employees">
            <input type="hidden" name="tab" value="employees">
            <input type="text" name="search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="Buscar empleado…" style="width:220px;">
            <select name="department_id">
                <option value="">Todos los departamentos</option>
                <?php foreach ( $departments as $d ) : ?>
                    <option value="<?php echo esc_attr( $d->id ); ?>" <?php selected( $filters['department_id'], $d->id ); ?>><?php echo esc_html( $d->name ); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status">
                <option value="">Todos los estados</option>
                <option value="active"   <?php selected( $filters['status'], 'active' ); ?>>Activos</option>
                <option value="archived" <?php selected( $filters['status'], 'archived' ); ?>>Archivados</option>
                <option value="departed" <?php selected( $filters['status'], 'departed' ); ?>>Egresados</option>
            </select>
            <button type="submit" class="button">Filtrar</button>
            <?php if ( $filters['search'] || $filters['department_id'] || $filters['status'] ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-employees&tab=employees' ) ); ?>" class="button">Limpiar</a>
                <?php endif; ?>
                <span style="margin-left:auto; color:#50575e; font-size:13px;"><?php echo count( $employees ); ?> empleados</span>
                <div class="wbi-view-toggle">
                    <button type="button" class="<?php echo 'list' === $view ? 'active' : ''; ?>" onclick="setView('list')">☰ Lista</button>
                    <button type="button" class="<?php echo 'card' === $view ? 'active' : ''; ?>" onclick="setView('card')">⊞ Tarjetas</button>
                    <input type="hidden" name="view" id="view-input" value="<?php echo esc_attr( $view ); ?>">
                </div>
            </form>

            <!-- List view -->
            <div id="emp-list-view" style="<?php echo 'card' === $view ? 'display:none;' : ''; ?>">
                <table class="widefat wbi-sortable">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Puesto</th>
                            <th>Departamento</th>
                            <th>Email</th>
                            <th>Teléfono</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $employees ) ) : ?>
                            <tr><td colspan="7" style="text-align:center; color:#50575e; padding:24px;">No se encontraron empleados.</td></tr>
                        <?php else : ?>
                            <?php foreach ( $employees as $emp ) :
                                $initials  = strtoupper( mb_substr( $emp->first_name, 0, 1 ) . mb_substr( $emp->last_name, 0, 1 ) );
                                $avatar_bg = $this->get_avatar_color( $emp->first_name . $emp->last_name );
                            ?>
                            <tr>
                                <td>
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <div style="width:32px;height:32px;border-radius:50%;background:<?php echo esc_attr( $avatar_bg ); ?>;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff;flex-shrink:0;overflow:hidden;">
                                            <?php if ( $emp->photo_url ) : ?>
                                                <img src="<?php echo esc_url( $emp->photo_url ); ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
                                            <?php else : ?>
                                                <?php echo esc_html( $initials ?: '?' ); ?>
                                            <?php endif; ?>
                                        </div>
                                        <strong><?php echo esc_html( $emp->first_name . ' ' . $emp->last_name ); ?></strong>
                                    </div>
                                </td>
                                <td><?php echo esc_html( $emp->job_title ); ?></td>
                                <td><?php echo esc_html( $this->get_department_name( $emp->department_id ) ); ?></td>
                                <td><?php echo esc_html( $emp->email ); ?></td>
                                <td><?php echo esc_html( $emp->phone ?: $emp->mobile ); ?></td>
                                <td>
                                    <span class="wbi-emp-status <?php echo esc_attr( $emp->status ); ?>">
                                        <?php echo esc_html( $this->status_label( $emp->status ) ); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-employees&tab=employees&action=edit&id=' . $emp->id ) ); ?>" class="button button-small">Editar</a>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar este empleado?');">
                                        <?php $this->nonce_field(); ?>
                                        <input type="hidden" name="wbi_emp_action" value="delete_employee">
                                        <input type="hidden" name="employee_id" value="<?php echo esc_attr( $emp->id ); ?>">
                                        <button type="submit" class="button button-small" style="color:#a00;">Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Card view -->
            <div id="emp-card-view" class="wbi-emp-card-grid" style="<?php echo 'list' === $view ? 'display:none;' : ''; ?>">
                <?php if ( empty( $employees ) ) : ?>
                    <p style="color:#50575e;">No se encontraron empleados.</p>
                <?php else : ?>
                    <?php foreach ( $employees as $emp ) :
                        $initials  = strtoupper( mb_substr( $emp->first_name, 0, 1 ) . mb_substr( $emp->last_name, 0, 1 ) );
                        $avatar_bg = $this->get_avatar_color( $emp->first_name . $emp->last_name );
                    ?>
                    <div class="wbi-emp-card <?php echo 'archived' === $emp->status ? 'archived' : ''; ?>">
                        <div class="emp-info">
                            <div class="emp-avatar" style="background:<?php echo esc_attr( $avatar_bg ); ?>;">
                                <?php if ( $emp->photo_url ) : ?>
                                    <img src="<?php echo esc_url( $emp->photo_url ); ?>" alt="">
                                <?php else : ?>
                                    <?php echo esc_html( $initials ?: '?' ); ?>
                                <?php endif; ?>
                            </div>
                            <div class="emp-meta">
                                <p class="emp-name"><?php echo esc_html( $emp->first_name . ' ' . $emp->last_name ); ?></p>
                                <p class="emp-title"><?php echo esc_html( $emp->job_title ); ?></p>
                                <p class="emp-dept"><?php echo esc_html( $this->get_department_name( $emp->department_id ) ); ?></p>
                            </div>
                        </div>
                        <div style="margin-top:8px;">
                            <span class="wbi-emp-status <?php echo esc_attr( $emp->status ); ?>"><?php echo esc_html( $this->status_label( $emp->status ) ); ?></span>
                            <?php if ( $emp->email ) : ?>
                                <span style="font-size:11px; color:#50575e; margin-left:6px;">✉ <?php echo esc_html( $emp->email ); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="emp-actions">
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-employees&tab=employees&action=edit&id=' . $emp->id ) ); ?>" class="button button-small">Editar</a>
                            <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar este empleado?');">
                                <?php $this->nonce_field(); ?>
                                <input type="hidden" name="wbi_emp_action" value="delete_employee">
                                <input type="hidden" name="employee_id" value="<?php echo esc_attr( $emp->id ); ?>">
                                <button type="submit" class="button button-small" style="color:#a00;">✕</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        <script>
        function setView(v) {
            document.getElementById('view-input').value = v;
            document.getElementById('emp-list-view').style.display = v === 'list' ? '' : 'none';
            document.getElementById('emp-card-view').style.display = v === 'card' ? '' : 'none';
            document.querySelectorAll('.wbi-view-toggle button').forEach(function(b, i) {
                b.classList.toggle('active', (i === 0 && v === 'list') || (i === 1 && v === 'card'));
            });
            document.getElementById('emp-filter-form').submit();
        }
        </script>
        <?php
    }

    private function render_employee_form( $action ) {
        global $wpdb;
        $id       = intval( $_GET['id'] ?? 0 );
        $emp      = $id ? $this->get_employee( $id ) : null;
        $depts    = $this->get_departments();
        $locations = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wbi_work_locations ORDER BY name" );
        $schedules = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wbi_work_schedules ORDER BY name" );
        $dep_reasons = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wbi_departure_reasons ORDER BY name" );
        $all_tags  = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wbi_employee_tags ORDER BY name" );
        $all_types = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wbi_skill_types ORDER BY name" );
        $emp_tags  = $emp ? array_column( $this->get_employee_tags( $id ), 'id' ) : array();
        $skills    = $emp ? $this->get_employee_skills( $id ) : array();
        $resume    = $emp ? $this->get_employee_resume( $id ) : array();
        $all_emps  = $this->get_employees();

        $title = 'new' === $action ? 'Nuevo Empleado' : 'Editar Empleado';
        ?>
        <div class="wrap">
            <div class="wbi-emp-header">
                <h1><?php echo esc_html( $title ); ?></h1>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-employees&tab=employees' ) ); ?>" class="button">← Volver</a>
            </div>

            <form method="post">
                <?php $this->nonce_field(); ?>
                <input type="hidden" name="wbi_emp_action" value="save_employee">
                <input type="hidden" name="employee_id" value="<?php echo esc_attr( $id ); ?>">

                <!-- Tab navigation -->
                <div class="wbi-tabs" id="emp-tabs">
                    <button type="button" class="wbi-tab active" data-tab="personal">👤 Info Personal</button>
                    <button type="button" class="wbi-tab" data-tab="work">💼 Info Laboral</button>
                    <button type="button" class="wbi-tab" data-tab="resume">📄 Currículum</button>
                    <button type="button" class="wbi-tab" data-tab="notes">📝 Notas</button>
                </div>

                <!-- Tab: Personal -->
                <div class="wbi-tab-content active" id="tab-personal">
                    <table class="form-table">
                        <tr><th>Nombre *</th><td><input type="text" name="first_name" value="<?php echo esc_attr( $emp->first_name ?? '' ); ?>" class="regular-text" required></td></tr>
                        <tr><th>Apellido *</th><td><input type="text" name="last_name" value="<?php echo esc_attr( $emp->last_name ?? '' ); ?>" class="regular-text" required></td></tr>
                        <tr><th>Email</th><td><input type="email" name="email" value="<?php echo esc_attr( $emp->email ?? '' ); ?>" class="regular-text"></td></tr>
                        <tr><th>Teléfono</th><td><input type="text" name="phone" value="<?php echo esc_attr( $emp->phone ?? '' ); ?>" class="regular-text"></td></tr>
                        <tr><th>Celular</th><td><input type="text" name="mobile" value="<?php echo esc_attr( $emp->mobile ?? '' ); ?>" class="regular-text"></td></tr>
                        <tr><th>Fecha de nacimiento</th><td><input type="date" name="date_of_birth" value="<?php echo esc_attr( $emp->date_of_birth ?? '' ); ?>"></td></tr>
                        <tr><th>Género</th><td>
                            <select name="gender">
                                <option value="">— Seleccionar —</option>
                                <?php foreach ( array( 'masculino' => 'Masculino', 'femenino' => 'Femenino', 'no_binario' => 'No binario', 'otro' => 'Otro' ) as $v => $l ) : ?>
                                    <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $emp->gender ?? '', $v ); ?>><?php echo esc_html( $l ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td></tr>
                        <tr><th>Estado civil</th><td>
                            <select name="marital_status">
                                <option value="">— Seleccionar —</option>
                                <?php foreach ( array( 'soltero' => 'Soltero/a', 'casado' => 'Casado/a', 'divorciado' => 'Divorciado/a', 'viudo' => 'Viudo/a', 'union_libre' => 'Unión libre' ) as $v => $l ) : ?>
                                    <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $emp->marital_status ?? '', $v ); ?>><?php echo esc_html( $l ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td></tr>
                        <tr><th>DNI / CUIL</th><td><input type="text" name="identification_number" value="<?php echo esc_attr( $emp->identification_number ?? '' ); ?>" class="regular-text"></td></tr>
                        <tr><th>Dirección</th><td><input type="text" name="address" value="<?php echo esc_attr( $emp->address ?? '' ); ?>" class="regular-text"></td></tr>
                        <tr><th>Ciudad</th><td><input type="text" name="city" value="<?php echo esc_attr( $emp->city ?? '' ); ?>" class="regular-text"></td></tr>
                        <tr><th>Provincia / Estado</th><td><input type="text" name="state" value="<?php echo esc_attr( $emp->state ?? '' ); ?>" class="regular-text"></td></tr>
                        <tr><th>País</th><td><input type="text" name="country" value="<?php echo esc_attr( $emp->country ?? 'Argentina' ); ?>" class="regular-text"></td></tr>
                        <tr><th>Código Postal</th><td><input type="text" name="postal_code" value="<?php echo esc_attr( $emp->postal_code ?? '' ); ?>"></td></tr>
                        <tr><th>Contacto de emergencia</th><td><input type="text" name="emergency_contact_name" value="<?php echo esc_attr( $emp->emergency_contact_name ?? '' ); ?>" class="regular-text" placeholder="Nombre"></td></tr>
                        <tr><th>Teléfono de emergencia</th><td><input type="text" name="emergency_contact_phone" value="<?php echo esc_attr( $emp->emergency_contact_phone ?? '' ); ?>" class="regular-text"></td></tr>
                        <tr><th>URL Foto</th><td><input type="url" name="photo_url" value="<?php echo esc_attr( $emp->photo_url ?? '' ); ?>" class="regular-text" placeholder="https://…"></td></tr>
                    </table>
                </div>

                <!-- Tab: Work -->
                <div class="wbi-tab-content" id="tab-work">
                    <table class="form-table">
                        <tr><th>Puesto</th><td><input type="text" name="job_title" value="<?php echo esc_attr( $emp->job_title ?? '' ); ?>" class="regular-text"></td></tr>
                        <tr><th>Departamento</th><td>
                            <select name="department_id">
                                <option value="0">— Sin departamento —</option>
                                <?php foreach ( $depts as $d ) : ?>
                                    <option value="<?php echo esc_attr( $d->id ); ?>" <?php selected( $emp->department_id ?? 0, $d->id ); ?>><?php echo esc_html( $d->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td></tr>
                        <tr><th>Ubicación de trabajo</th><td>
                            <select name="work_location_id">
                                <option value="0">— Sin ubicación —</option>
                                <?php foreach ( $locations as $loc ) : ?>
                                    <option value="<?php echo esc_attr( $loc->id ); ?>" <?php selected( $emp->work_location_id ?? 0, $loc->id ); ?>><?php echo esc_html( $loc->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td></tr>
                        <tr><th>Responsable / Manager</th><td>
                            <select name="manager_id">
                                <option value="0">— Sin manager —</option>
                                <?php foreach ( $all_emps as $e ) : if ( $e->id == $id ) continue; ?>
                                    <option value="<?php echo esc_attr( $e->id ); ?>" <?php selected( $emp->manager_id ?? 0, $e->id ); ?>><?php echo esc_html( $e->first_name . ' ' . $e->last_name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td></tr>
                        <tr><th>Coach</th><td>
                            <select name="coach_id">
                                <option value="0">— Sin coach —</option>
                                <?php foreach ( $all_emps as $e ) : if ( $e->id == $id ) continue; ?>
                                    <option value="<?php echo esc_attr( $e->id ); ?>" <?php selected( $emp->coach_id ?? 0, $e->id ); ?>><?php echo esc_html( $e->first_name . ' ' . $e->last_name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td></tr>
                        <tr><th>Horario de trabajo</th><td>
                            <select name="work_schedule_id">
                                <option value="0">— Sin horario —</option>
                                <?php foreach ( $schedules as $s ) : ?>
                                    <option value="<?php echo esc_attr( $s->id ); ?>" <?php selected( $emp->work_schedule_id ?? 0, $s->id ); ?>><?php echo esc_html( $s->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td></tr>
                        <tr><th>Fecha de ingreso</th><td><input type="date" name="hire_date" value="<?php echo esc_attr( $emp->hire_date ?? '' ); ?>"></td></tr>
                        <tr><th>Fecha de egreso</th><td><input type="date" name="departure_date" value="<?php echo esc_attr( $emp->departure_date ?? '' ); ?>"></td></tr>
                        <tr><th>Motivo de egreso</th><td>
                            <select name="departure_reason_id">
                                <option value="0">— Sin motivo —</option>
                                <?php foreach ( $dep_reasons as $r ) : ?>
                                    <option value="<?php echo esc_attr( $r->id ); ?>" <?php selected( $emp->departure_reason_id ?? 0, $r->id ); ?>><?php echo esc_html( $r->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td></tr>
                        <tr><th>Estado</th><td>
                            <select name="emp_status">
                                <option value="active"   <?php selected( $emp->status ?? 'active', 'active' ); ?>>Activo</option>
                                <option value="archived" <?php selected( $emp->status ?? '', 'archived' ); ?>>Archivado</option>
                                <option value="departed" <?php selected( $emp->status ?? '', 'departed' ); ?>>Egresado</option>
                            </select>
                        </td></tr>
                        <tr><th>Etiquetas</th><td>
                            <div style="display:flex;flex-wrap:wrap;gap:8px;">
                            <?php foreach ( $all_tags as $tag ) : ?>
                                <label>
                                    <input type="checkbox" name="tags[]" value="<?php echo esc_attr( $tag->id ); ?>" <?php checked( in_array( $tag->id, $emp_tags, false ) ); ?>>
                                    <span style="background:<?php echo esc_attr( $tag->color ); ?>; color:#fff; padding:2px 8px; border-radius:12px; font-size:12px;"><?php echo esc_html( $tag->name ); ?></span>
                                </label>
                            <?php endforeach; ?>
                            </div>
                        </td></tr>
                    </table>
                </div>

                <!-- Tab: Resume -->
                <div class="wbi-tab-content" id="tab-resume">
                    <div class="wbi-section-title">🎓 Habilidades</div>
                    <table class="widefat" id="skills-table">
                        <thead><tr><th>Tipo</th><th>Habilidad</th><th>Nivel</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ( $skills as $i => $skill ) : ?>
                            <tr>
                                <td>
                                    <select name="skills[<?php echo $i; ?>][skill_type_id]">
                                        <option value="0">— General —</option>
                                        <?php foreach ( $all_types as $st ) : ?>
                                            <option value="<?php echo esc_attr( $st->id ); ?>" <?php selected( $skill->skill_type_id, $st->id ); ?>><?php echo esc_html( $st->name ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="text" name="skills[<?php echo $i; ?>][skill_name]" value="<?php echo esc_attr( $skill->skill_name ); ?>" class="regular-text"></td>
                                <td>
                                    <select name="skills[<?php echo $i; ?>][level]">
                                        <?php foreach ( array( 'básico', 'intermedio', 'avanzado', 'experto' ) as $lv ) : ?>
                                            <option value="<?php echo esc_attr( $lv ); ?>" <?php selected( $skill->level, $lv ); ?>><?php echo esc_html( ucfirst( $lv ) ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><button type="button" class="button button-small remove-row">✕</button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <button type="button" class="button" id="add-skill" style="margin-top:8px;">+ Agregar habilidad</button>

                    <div class="wbi-section-title" style="margin-top:24px;">📋 Experiencia / Educación</div>
                    <table class="widefat" id="resume-table">
                        <thead><tr><th>Sección</th><th>Título</th><th>Subtítulo</th><th>Desde</th><th>Hasta</th><th>Descripción</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ( $resume as $i => $line ) : ?>
                            <tr>
                                <td>
                                    <select name="resume[<?php echo $i; ?>][section]">
                                        <?php foreach ( array( 'experiencia' => 'Experiencia', 'educación' => 'Educación', 'certificaciones' => 'Certificaciones' ) as $sv => $sl ) : ?>
                                            <option value="<?php echo esc_attr( $sv ); ?>" <?php selected( $line->section, $sv ); ?>><?php echo esc_html( $sl ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="text" name="resume[<?php echo $i; ?>][title]" value="<?php echo esc_attr( $line->title ); ?>"></td>
                                <td><input type="text" name="resume[<?php echo $i; ?>][subtitle]" value="<?php echo esc_attr( $line->subtitle ); ?>"></td>
                                <td><input type="date" name="resume[<?php echo $i; ?>][date_start]" value="<?php echo esc_attr( $line->date_start ?? '' ); ?>"></td>
                                <td><input type="date" name="resume[<?php echo $i; ?>][date_end]" value="<?php echo esc_attr( $line->date_end ?? '' ); ?>"></td>
                                <td><textarea name="resume[<?php echo $i; ?>][description]" rows="2"><?php echo esc_textarea( $line->description ); ?></textarea></td>
                                <td><button type="button" class="button button-small remove-row">✕</button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <button type="button" class="button" id="add-resume" style="margin-top:8px;">+ Agregar línea</button>
                </div>

                <!-- Tab: Notes -->
                <div class="wbi-tab-content" id="tab-notes">
                    <table class="form-table">
                        <tr>
                            <th>Notas internas</th>
                            <td><textarea name="notes" rows="8" style="width:100%;"><?php echo esc_textarea( $emp->notes ?? '' ); ?></textarea></td>
                        </tr>
                    </table>
                </div>

                <?php submit_button( 'Guardar Empleado' ); ?>
            </form>
        </div>

        <script>
        // Tab navigation
        document.querySelectorAll('#emp-tabs .wbi-tab').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.wbi-tab').forEach(function(b) { b.classList.remove('active'); });
                document.querySelectorAll('.wbi-tab-content').forEach(function(c) { c.classList.remove('active'); });
                btn.classList.add('active');
                document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
            });
        });

        // Remove row
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-row')) {
                e.target.closest('tr').remove();
            }
        });

        // Add skill row
        var skillIdx = <?php echo max( count( $skills ), 1 ); ?>;
        document.getElementById('add-skill').addEventListener('click', function() {
            var types = <?php echo json_encode( array_map( function($t) { return array( 'id' => $t->id, 'name' => $t->name ); }, $all_types ) ); ?>;
            var opts = '<option value="0">— General —</option>';
            types.forEach(function(t) { opts += '<option value="' + t.id + '">' + t.name + '</option>'; });
            var row = '<tr>' +
                '<td><select name="skills[' + skillIdx + '][skill_type_id]">' + opts + '</select></td>' +
                '<td><input type="text" name="skills[' + skillIdx + '][skill_name]" class="regular-text"></td>' +
                '<td><select name="skills[' + skillIdx + '][level]"><option value="básico">Básico</option><option value="intermedio">Intermedio</option><option value="avanzado">Avanzado</option><option value="experto">Experto</option></select></td>' +
                '<td><button type="button" class="button button-small remove-row">✕</button></td>' +
                '</tr>';
            document.querySelector('#skills-table tbody').insertAdjacentHTML('beforeend', row);
            skillIdx++;
        });

        // Add resume row
        var resumeIdx = <?php echo max( count( $resume ), 1 ); ?>;
        document.getElementById('add-resume').addEventListener('click', function() {
            var row = '<tr>' +
                '<td><select name="resume[' + resumeIdx + '][section]"><option value="experiencia">Experiencia</option><option value="educación">Educación</option><option value="certificaciones">Certificaciones</option></select></td>' +
                '<td><input type="text" name="resume[' + resumeIdx + '][title]"></td>' +
                '<td><input type="text" name="resume[' + resumeIdx + '][subtitle]"></td>' +
                '<td><input type="date" name="resume[' + resumeIdx + '][date_start]"></td>' +
                '<td><input type="date" name="resume[' + resumeIdx + '][date_end]"></td>' +
                '<td><textarea name="resume[' + resumeIdx + '][description]" rows="2"></textarea></td>' +
                '<td><button type="button" class="button button-small remove-row">✕</button></td>' +
                '</tr>';
            document.querySelector('#resume-table tbody').insertAdjacentHTML('beforeend', row);
            resumeIdx++;
        });
        </script>
        <?php
    }

    // =========================================================================
    // PAGE: DEPARTMENTS
    // =========================================================================

    public function page_departments() {
        $action = sanitize_text_field( $_GET['action'] ?? 'list' );
        if ( in_array( $action, array( 'new', 'edit' ), true ) ) {
            $this->render_department_form( $action );
        } else {
            $this->render_department_list();
        }
    }

    private function render_department_list() {
        $depts = $this->get_departments();
        ?>
        <div class="wbi-emp-header">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-employees&tab=departments&action=new' ) ); ?>" class="button button-primary">+ Nuevo Departamento</a>
        </div>

        <?php
        if ( ! empty( $_GET['saved'] ) )   $this->notice( 'success', 'Departamento guardado.' );
        if ( ! empty( $_GET['deleted'] ) ) $this->notice( 'success', 'Departamento eliminado.' );
        ?>

        <div class="wbi-emp-card-grid">
            <?php if ( empty( $depts ) ) : ?>
                <p style="color:#50575e;">No hay departamentos registrados.</p>
            <?php else : ?>
                <?php foreach ( $depts as $d ) : ?>
                <?php $count = $this->get_department_employee_count( $d->id ); ?>
                <div class="wbi-dept-card">
                    <div class="wbi-dept-color-badge" style="background:<?php echo esc_attr( $d->color ); ?>;"></div>
                    <div style="flex:1;">
                        <p class="wbi-dept-name"><?php echo esc_html( $d->name ); ?></p>
                        <p class="wbi-dept-count">👥 <?php echo esc_html( $count ); ?> empleado<?php echo 1 !== $count ? 's' : ''; ?> activo<?php echo 1 !== $count ? 's' : ''; ?></p>
                        <?php if ( $d->parent_id ) : ?>
                            <p style="font-size:12px; color:#50575e;">↳ <?php echo esc_html( $this->get_department_name( $d->parent_id ) ); ?></p>
                        <?php endif; ?>
                        <?php if ( $d->manager_id ) : ?>
                            <p style="font-size:12px; color:#50575e;">Manager: <?php echo $this->get_employee_name( $d->manager_id ); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="wbi-dept-actions">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-employees&tab=departments&action=edit&id=' . $d->id ) ); ?>" class="button button-small">Editar</a>
                        <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar este departamento?');">
                            <?php $this->nonce_field(); ?>
                            <input type="hidden" name="wbi_emp_action" value="delete_department">
                            <input type="hidden" name="department_id" value="<?php echo esc_attr( $d->id ); ?>">
                            <button type="submit" class="button button-small" style="color:#a00;">✕</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_department_form( $action ) {
        $id   = intval( $_GET['id'] ?? 0 );
        $dept = $id ? $this->get_department( $id ) : null;
        $all_depts = $this->get_departments();
        $all_emps  = $this->get_employees();
        $title = 'new' === $action ? 'Nuevo Departamento' : 'Editar Departamento';
        ?>
        <div class="wrap">
            <div class="wbi-emp-header">
                <h1><?php echo esc_html( $title ); ?></h1>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-employees&tab=departments' ) ); ?>" class="button">← Volver</a>
            </div>
            <form method="post">
                <?php $this->nonce_field(); ?>
                <input type="hidden" name="wbi_emp_action" value="save_department">
                <input type="hidden" name="department_id" value="<?php echo esc_attr( $id ); ?>">
                <table class="form-table">
                    <tr><th>Nombre *</th><td><input type="text" name="dept_name" value="<?php echo esc_attr( $dept->name ?? '' ); ?>" class="regular-text" required></td></tr>
                    <tr><th>Departamento padre</th><td>
                        <select name="parent_id">
                            <option value="0">— Sin padre —</option>
                            <?php foreach ( $all_depts as $d ) : if ( $d->id == $id ) continue; ?>
                                <option value="<?php echo esc_attr( $d->id ); ?>" <?php selected( $dept->parent_id ?? 0, $d->id ); ?>><?php echo esc_html( $d->name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th>Manager</th><td>
                        <select name="manager_id">
                            <option value="0">— Sin manager —</option>
                            <?php foreach ( $all_emps as $e ) : ?>
                                <option value="<?php echo esc_attr( $e->id ); ?>" <?php selected( $dept->manager_id ?? 0, $e->id ); ?>><?php echo esc_html( $e->first_name . ' ' . $e->last_name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th>Color</th><td><input type="color" name="dept_color" value="<?php echo esc_attr( $dept->color ?? '#0073aa' ); ?>"></td></tr>
                    <tr><th>Descripción</th><td><textarea name="description" rows="4" style="width:100%;"><?php echo esc_textarea( $dept->description ?? '' ); ?></textarea></td></tr>
                </table>
                <?php submit_button( 'Guardar Departamento' ); ?>
            </form>
        </div>
        <?php
    }

    // =========================================================================
    // PAGE: CONTRACTS
    // =========================================================================

    public function page_contracts() {
        $action = sanitize_text_field( $_GET['action'] ?? 'list' );
        if ( in_array( $action, array( 'new', 'edit' ), true ) ) {
            $this->render_contract_form( $action );
        } else {
            $this->render_contract_list();
        }
    }

    private function render_contract_list() {
        global $wpdb;
        $status_labels = array( 'draft' => 'Borrador', 'active' => 'Activo', 'expired' => 'Vencido', 'cancelled' => 'Cancelado' );
        $filter_status = sanitize_text_field( $_GET['contract_status'] ?? '' );
        $filter_emp    = intval( $_GET['contract_employee'] ?? 0 );

        $where  = '1=1';
        if ( $filter_status && isset( $status_labels[ $filter_status ] ) ) {
            $where .= $wpdb->prepare( ' AND c.status = %s', $filter_status );
        }
        if ( $filter_emp > 0 ) {
            $where .= $wpdb->prepare( ' AND c.employee_id = %d', $filter_emp );
        }

        $contracts = $wpdb->get_results( "SELECT c.*, e.first_name, e.last_name FROM {$wpdb->prefix}wbi_employee_contracts c LEFT JOIN {$wpdb->prefix}wbi_employees e ON e.id = c.employee_id WHERE $where ORDER BY c.created_at DESC" );
        $all_employees = $this->get_employees();
        ?>
        <div class="wbi-emp-header">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-employees&tab=contracts&action=new' ) ); ?>" class="button button-primary">+ Nuevo Contrato</a>
        </div>
        <?php
        if ( ! empty( $_GET['saved'] ) )   $this->notice( 'success', 'Contrato guardado.' );
        if ( ! empty( $_GET['deleted'] ) ) $this->notice( 'success', 'Contrato eliminado.' );
        ?>

        <!-- Filters -->
        <form method="get" class="wbi-filter-bar" style="margin-bottom:16px;">
            <input type="hidden" name="page" value="wbi-employees">
            <input type="hidden" name="tab" value="contracts">
            <select name="contract_status">
                <option value="">Todos los estados</option>
                <?php foreach ( $status_labels as $sv => $sl ) : ?>
                    <option value="<?php echo esc_attr( $sv ); ?>" <?php selected( $filter_status, $sv ); ?>><?php echo esc_html( $sl ); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="contract_employee">
                <option value="0">Todos los empleados</option>
                <?php foreach ( $all_employees as $e ) : ?>
                    <option value="<?php echo esc_attr( $e->id ); ?>" <?php selected( $filter_emp, $e->id ); ?>><?php echo esc_html( $e->first_name . ' ' . $e->last_name ); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button">Filtrar</button>
            <?php if ( $filter_status || $filter_emp ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-employees&tab=contracts' ) ); ?>" class="button">Limpiar</a>
            <?php endif; ?>
            <span style="margin-left:auto; color:#50575e; font-size:13px;"><?php echo count( $contracts ); ?> contrato<?php echo 1 !== count( $contracts ) ? 's' : ''; ?></span>
        </form>

        <table class="widefat wbi-sortable">
            <thead>
                <tr><th>Empleado</th><th>Salario</th><th>Inicio</th><th>Fin</th><th>Estado</th><th>Acciones</th></tr>
            </thead>
            <tbody>
                <?php if ( empty( $contracts ) ) : ?>
                    <tr><td colspan="6" style="text-align:center; color:#50575e; padding:24px;">No hay contratos registrados.</td></tr>
                <?php else : ?>
                    <?php foreach ( $contracts as $c ) : ?>
                    <tr>
                        <td><?php echo esc_html( trim( $c->first_name . ' ' . $c->last_name ) ?: '—' ); ?></td>
                        <td>$<?php echo number_format( $c->salary, 2, ',', '.' ); ?></td>
                        <td><?php echo esc_html( $c->start_date ?? '—' ); ?></td>
                        <td><?php echo esc_html( $c->end_date ?? '—' ); ?></td>
                        <td><span class="wbi-contract-status <?php echo esc_attr( $c->status ); ?>"><?php echo esc_html( $status_labels[ $c->status ] ?? $c->status ); ?></span></td>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-employees&tab=contracts&action=edit&id=' . $c->id ) ); ?>" class="button button-small">Editar</a>
                            <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar este contrato?');">
                                <?php $this->nonce_field(); ?>
                                <input type="hidden" name="wbi_emp_action" value="delete_contract">
                                <input type="hidden" name="contract_id" value="<?php echo esc_attr( $c->id ); ?>">
                                <button type="submit" class="button button-small" style="color:#a00;">Eliminar</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_contract_form( $action ) {
        global $wpdb;
        $id       = intval( $_GET['id'] ?? 0 );
        $contract = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wbi_employee_contracts WHERE id = %d", $id ) ) : null;
        $employees = $this->get_employees();
        $templates = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wbi_contract_templates ORDER BY name" );
        $title = 'new' === $action ? 'Nuevo Contrato' : 'Editar Contrato';
        $status_opts = array( 'draft' => 'Borrador', 'active' => 'Activo', 'expired' => 'Vencido', 'cancelled' => 'Cancelado' );
        ?>
        <div class="wrap">
            <div class="wbi-emp-header">
                <h1><?php echo esc_html( $title ); ?></h1>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-employees&tab=contracts' ) ); ?>" class="button">← Volver</a>
            </div>
            <form method="post">
                <?php $this->nonce_field(); ?>
                <input type="hidden" name="wbi_emp_action" value="save_contract">
                <input type="hidden" name="contract_id" value="<?php echo esc_attr( $id ); ?>">
                <table class="form-table">
                    <tr><th>Empleado *</th><td>
                        <select name="contract_employee_id" required>
                            <option value="0">— Seleccionar —</option>
                            <?php foreach ( $employees as $e ) : ?>
                                <option value="<?php echo esc_attr( $e->id ); ?>" <?php selected( $contract->employee_id ?? 0, $e->id ); ?>><?php echo esc_html( $e->first_name . ' ' . $e->last_name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th>Plantilla de contrato</th><td>
                        <select name="contract_template_id">
                            <option value="0">— Sin plantilla —</option>
                            <?php foreach ( $templates as $t ) : ?>
                                <option value="<?php echo esc_attr( $t->id ); ?>" <?php selected( $contract->contract_template_id ?? 0, $t->id ); ?>><?php echo esc_html( $t->name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th>Salario</th><td><input type="number" name="salary" value="<?php echo esc_attr( $contract->salary ?? 0 ); ?>" min="0" step="0.01" style="width:150px;"></td></tr>
                    <tr><th>Fecha de inicio</th><td><input type="date" name="start_date" value="<?php echo esc_attr( $contract->start_date ?? '' ); ?>"></td></tr>
                    <tr><th>Fecha de fin</th><td><input type="date" name="end_date" value="<?php echo esc_attr( $contract->end_date ?? '' ); ?>"></td></tr>
                    <tr><th>Estado</th><td>
                        <select name="contract_status">
                            <?php foreach ( $status_opts as $v => $l ) : ?>
                                <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $contract->status ?? 'draft', $v ); ?>><?php echo esc_html( $l ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th>Notas</th><td><textarea name="contract_notes" rows="4" style="width:100%;"><?php echo esc_textarea( $contract->notes ?? '' ); ?></textarea></td></tr>
                </table>
                <?php submit_button( 'Guardar Contrato' ); ?>
            </form>
        </div>
        <?php
    }

    // =========================================================================
    // PAGE: CONFIGURATION
    // =========================================================================

    public function page_config() {
        $subtab = sanitize_key( $_GET['subtab'] ?? 'location' );
        $tabs   = array(
            'location'          => '📍 Ubicaciones',
            'schedule'          => '🕐 Horarios',
            'departure_reason'  => '🚪 Motivos de egreso',
            'skill_type'        => '🎓 Tipos de habilidad',
            'tag'               => '🏷️ Etiquetas',
            'job_position'      => '💼 Puestos de trabajo',
            'contract_template' => '📄 Plantillas de contrato',
        );

        if ( ! empty( $_GET['saved'] ) )   $this->notice( 'success', 'Guardado correctamente.' );
        if ( ! empty( $_GET['deleted'] ) ) $this->notice( 'success', 'Eliminado correctamente.' );

        $base = admin_url( 'admin.php?page=wbi-employees&tab=settings' );
        ?>
        <nav class="nav-tab-wrapper" style="margin-top:8px; margin-bottom:16px;">
            <?php foreach ( $tabs as $key => $label ) : ?>
                <a href="<?php echo esc_url( $base . '&subtab=' . $key ); ?>"
                   class="nav-tab <?php echo $subtab === $key ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html( $label ); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php
        switch ( $subtab ) {
            case 'location':          $this->render_config_locations(); break;
            case 'schedule':          $this->render_config_schedules(); break;
            case 'departure_reason':  $this->render_config_departure_reasons(); break;
            case 'skill_type':        $this->render_config_skill_types(); break;
            case 'tag':               $this->render_config_tags(); break;
            case 'job_position':      $this->render_config_job_positions(); break;
            case 'contract_template': $this->render_config_contract_templates(); break;
        }
    }

    // ── Config: Locations ─────────────────────────────────────────────────────

    private function render_config_locations() {
        global $wpdb;
        $edit_id = intval( $_GET['edit'] ?? 0 );
        $item    = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wbi_work_locations WHERE id = %d", $edit_id ) ) : null;
        $items   = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wbi_work_locations ORDER BY name" );
        ?>
        <div style="display:grid; grid-template-columns:1fr 340px; gap:20px; align-items:start;">
            <div>
                <h3>Ubicaciones de trabajo</h3>
                <table class="widefat">
                    <thead><tr><th>Nombre</th><th>Tipo</th><th>Dirección</th><th></th></tr></thead>
                    <tbody>
                    <?php if ( empty( $items ) ) : ?>
                        <tr><td colspan="4" style="color:#50575e;">No hay ubicaciones.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $items as $it ) : ?>
                        <tr>
                            <td><?php echo esc_html( $it->name ); ?></td>
                            <td><?php echo esc_html( ucfirst( $it->type ) ); ?></td>
                            <td><?php echo esc_html( $it->address ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-employees&tab=settings&subtab=location&edit=' . $it->id ) ); ?>" class="button button-small">Editar</a>
                                <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar?');">
                                    <?php $this->nonce_field(); ?>
                                    <input type="hidden" name="wbi_emp_action" value="delete_config_item">
                                    <input type="hidden" name="config_type" value="location">
                                    <input type="hidden" name="config_id" value="<?php echo esc_attr( $it->id ); ?>">
                                    <button type="submit" class="button button-small" style="color:#a00;">✕</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div style="background:#fff; border:1px solid #c3c4c7; border-radius:6px; padding:16px;">
                <h3 style="margin-top:0;"><?php echo $item ? 'Editar' : 'Nueva'; ?> ubicación</h3>
                <form method="post">
                    <?php $this->nonce_field(); ?>
                    <input type="hidden" name="wbi_emp_action" value="save_config_item">
                    <input type="hidden" name="config_type" value="location">
                    <input type="hidden" name="config_id" value="<?php echo esc_attr( $edit_id ); ?>">
                    <p><label>Nombre *<br><input type="text" name="config_name" value="<?php echo esc_attr( $item->name ?? '' ); ?>" class="widefat" required></label></p>
                    <p><label>Tipo<br>
                        <select name="location_type" class="widefat">
                            <?php foreach ( array( 'oficina' => 'Oficina', 'remoto' => 'Remoto', 'híbrido' => 'Híbrido' ) as $v => $l ) : ?>
                                <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $item->type ?? 'oficina', $v ); ?>><?php echo esc_html( $l ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label></p>
                    <p><label>Dirección<br><input type="text" name="location_address" value="<?php echo esc_attr( $item->address ?? '' ); ?>" class="widefat"></label></p>
                    <?php submit_button( 'Guardar', 'primary', 'submit', false ); ?>
                    <?php if ( $item ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-employees&tab=settings&subtab=location' ) ); ?>" class="button" style="margin-left:6px;">Cancelar</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <?php
    }

    // ── Config: Schedules ─────────────────────────────────────────────────────

    private function render_config_schedules() {
        global $wpdb;
        $edit_id = intval( $_GET['edit'] ?? 0 );
        $item    = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wbi_work_schedules WHERE id = %d", $edit_id ) ) : null;
        $items   = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wbi_work_schedules ORDER BY name" );
        ?>
        <div style="display:grid; grid-template-columns:1fr 340px; gap:20px; align-items:start;">
            <div>
                <h3>Horarios de trabajo</h3>
                <table class="widefat">
                    <thead><tr><th>Nombre</th><th>Hs/semana</th><th>Descripción</th><th></th></tr></thead>
                    <tbody>
                    <?php if ( empty( $items ) ) : ?>
                        <tr><td colspan="4" style="color:#50575e;">No hay horarios.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $items as $it ) : ?>
                        <tr>
                            <td><?php echo esc_html( $it->name ); ?></td>
                            <td><?php echo esc_html( $it->hours_per_week ); ?></td>
                            <td><?php echo esc_html( $it->description ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-employees&tab=settings&subtab=schedule&edit=' . $it->id ) ); ?>" class="button button-small">Editar</a>
                                <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar?');">
                                    <?php $this->nonce_field(); ?>
                                    <input type="hidden" name="wbi_emp_action" value="delete_config_item">
                                    <input type="hidden" name="config_type" value="schedule">
                                    <input type="hidden" name="config_id" value="<?php echo esc_attr( $it->id ); ?>">
                                    <button type="submit" class="button button-small" style="color:#a00;">✕</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div style="background:#fff; border:1px solid #c3c4c7; border-radius:6px; padding:16px;">
                <h3 style="margin-top:0;"><?php echo $item ? 'Editar' : 'Nuevo'; ?> horario</h3>
                <form method="post">
                    <?php $this->nonce_field(); ?>
                    <input type="hidden" name="wbi_emp_action" value="save_config_item">
                    <input type="hidden" name="config_type" value="schedule">
                    <input type="hidden" name="config_id" value="<?php echo esc_attr( $edit_id ); ?>">
                    <p><label>Nombre *<br><input type="text" name="config_name" value="<?php echo esc_attr( $item->name ?? '' ); ?>" class="widefat" required></label></p>
                    <p><label>Horas por semana<br><input type="number" name="hours_per_week" value="<?php echo esc_attr( $item->hours_per_week ?? 40 ); ?>" min="0" step="0.5" class="widefat"></label></p>
                    <p><label>Descripción<br><textarea name="config_description" rows="3" class="widefat"><?php echo esc_textarea( $item->description ?? '' ); ?></textarea></label></p>
                    <?php submit_button( 'Guardar', 'primary', 'submit', false ); ?>
                    <?php if ( $item ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-employees&tab=settings&subtab=schedule' ) ); ?>" class="button" style="margin-left:6px;">Cancelar</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <?php
    }

    // ── Config: generic simple name-only tables ───────────────────────────────

    private function render_config_simple( $type, $title, $table_name ) {
        global $wpdb;
        $edit_id = intval( $_GET['edit'] ?? 0 );
        $item    = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $edit_id ) ) : null;
        $items   = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY name" );
        ?>
        <div style="display:grid; grid-template-columns:1fr 300px; gap:20px; align-items:start;">
            <div>
                <h3><?php echo esc_html( $title ); ?></h3>
                <table class="widefat">
                    <thead><tr><th>Nombre</th><th></th></tr></thead>
                    <tbody>
                    <?php if ( empty( $items ) ) : ?>
                        <tr><td colspan="2" style="color:#50575e;">No hay registros.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $items as $it ) : ?>
                        <tr>
                            <td><?php echo esc_html( $it->name ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-employees&tab=settings&subtab=' . $type . '&edit=' . $it->id ) ); ?>" class="button button-small">Editar</a>
                                <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar?');">
                                    <?php $this->nonce_field(); ?>
                                    <input type="hidden" name="wbi_emp_action" value="delete_config_item">
                                    <input type="hidden" name="config_type" value="<?php echo esc_attr( $type ); ?>">
                                    <input type="hidden" name="config_id" value="<?php echo esc_attr( $it->id ); ?>">
                                    <button type="submit" class="button button-small" style="color:#a00;">✕</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div style="background:#fff; border:1px solid #c3c4c7; border-radius:6px; padding:16px;">
                <h3 style="margin-top:0;"><?php echo $item ? 'Editar' : 'Nuevo'; ?></h3>
                <form method="post">
                    <?php $this->nonce_field(); ?>
                    <input type="hidden" name="wbi_emp_action" value="save_config_item">
                    <input type="hidden" name="config_type" value="<?php echo esc_attr( $type ); ?>">
                    <input type="hidden" name="config_id" value="<?php echo esc_attr( $edit_id ); ?>">
                    <p><label>Nombre *<br><input type="text" name="config_name" value="<?php echo esc_attr( $item->name ?? '' ); ?>" class="widefat" required></label></p>
                    <?php submit_button( 'Guardar', 'primary', 'submit', false ); ?>
                    <?php if ( $item ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-employees&tab=settings&subtab=' . $type ) ); ?>" class="button" style="margin-left:6px;">Cancelar</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <?php
    }

    private function render_config_departure_reasons() {
        global $wpdb;
        $this->render_config_simple( 'departure_reason', 'Motivos de egreso', $wpdb->prefix . 'wbi_departure_reasons' );
    }

    private function render_config_skill_types() {
        global $wpdb;
        $this->render_config_simple( 'skill_type', 'Tipos de habilidad', $wpdb->prefix . 'wbi_skill_types' );
    }

    // ── Config: Tags ──────────────────────────────────────────────────────────

    private function render_config_tags() {
        global $wpdb;
        $edit_id = intval( $_GET['edit'] ?? 0 );
        $item    = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wbi_employee_tags WHERE id = %d", $edit_id ) ) : null;
        $items   = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wbi_employee_tags ORDER BY name" );
        ?>
        <div style="display:grid; grid-template-columns:1fr 320px; gap:20px; align-items:start;">
            <div>
                <h3>Etiquetas de empleado</h3>
                <table class="widefat">
                    <thead><tr><th>Etiqueta</th><th>Color</th><th></th></tr></thead>
                    <tbody>
                    <?php if ( empty( $items ) ) : ?>
                        <tr><td colspan="3" style="color:#50575e;">No hay etiquetas.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $items as $it ) : ?>
                        <tr>
                            <td><span style="background:<?php echo esc_attr( $it->color ); ?>; color:#fff; padding:2px 10px; border-radius:12px; font-size:12px;"><?php echo esc_html( $it->name ); ?></span></td>
                            <td><?php echo esc_html( $it->color ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-employees&tab=settings&subtab=tag&edit=' . $it->id ) ); ?>" class="button button-small">Editar</a>
                                <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar?');">
                                    <?php $this->nonce_field(); ?>
                                    <input type="hidden" name="wbi_emp_action" value="delete_config_item">
                                    <input type="hidden" name="config_type" value="tag">
                                    <input type="hidden" name="config_id" value="<?php echo esc_attr( $it->id ); ?>">
                                    <button type="submit" class="button button-small" style="color:#a00;">✕</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div style="background:#fff; border:1px solid #c3c4c7; border-radius:6px; padding:16px;">
                <h3 style="margin-top:0;"><?php echo $item ? 'Editar' : 'Nueva'; ?> etiqueta</h3>
                <form method="post">
                    <?php $this->nonce_field(); ?>
                    <input type="hidden" name="wbi_emp_action" value="save_config_item">
                    <input type="hidden" name="config_type" value="tag">
                    <input type="hidden" name="config_id" value="<?php echo esc_attr( $edit_id ); ?>">
                    <p><label>Nombre *<br><input type="text" name="config_name" value="<?php echo esc_attr( $item->name ?? '' ); ?>" class="widefat" required></label></p>
                    <p><label>Color<br><input type="color" name="tag_color" value="<?php echo esc_attr( $item->color ?? '#0073aa' ); ?>"></label></p>
                    <?php submit_button( 'Guardar', 'primary', 'submit', false ); ?>
                    <?php if ( $item ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-employees&tab=settings&subtab=tag' ) ); ?>" class="button" style="margin-left:6px;">Cancelar</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <?php
    }

    // ── Config: Job Positions ─────────────────────────────────────────────────

    private function render_config_job_positions() {
        global $wpdb;
        $edit_id = intval( $_GET['edit'] ?? 0 );
        $item    = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wbi_job_positions WHERE id = %d", $edit_id ) ) : null;
        $items   = $wpdb->get_results( "SELECT j.*, d.name as dept_name FROM {$wpdb->prefix}wbi_job_positions j LEFT JOIN {$wpdb->prefix}wbi_departments d ON d.id = j.department_id ORDER BY j.name" );
        $depts   = $this->get_departments();
        ?>
        <div style="display:grid; grid-template-columns:1fr 360px; gap:20px; align-items:start;">
            <div>
                <h3>Puestos de trabajo</h3>
                <table class="widefat">
                    <thead><tr><th>Nombre</th><th>Departamento</th><th>Estado</th><th>Esperados</th><th></th></tr></thead>
                    <tbody>
                    <?php if ( empty( $items ) ) : ?>
                        <tr><td colspan="5" style="color:#50575e;">No hay puestos.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $items as $it ) : ?>
                        <tr>
                            <td><?php echo esc_html( $it->name ); ?></td>
                            <td><?php echo esc_html( $it->dept_name ?? '—' ); ?></td>
                            <td><?php echo esc_html( 'abierto' === $it->status ? 'Abierto' : 'Cerrado' ); ?></td>
                            <td><?php echo esc_html( $it->expected_employees ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-employees&tab=settings&subtab=job_position&edit=' . $it->id ) ); ?>" class="button button-small">Editar</a>
                                <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar?');">
                                    <?php $this->nonce_field(); ?>
                                    <input type="hidden" name="wbi_emp_action" value="delete_config_item">
                                    <input type="hidden" name="config_type" value="job_position">
                                    <input type="hidden" name="config_id" value="<?php echo esc_attr( $it->id ); ?>">
                                    <button type="submit" class="button button-small" style="color:#a00;">✕</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div style="background:#fff; border:1px solid #c3c4c7; border-radius:6px; padding:16px;">
                <h3 style="margin-top:0;"><?php echo $item ? 'Editar' : 'Nuevo'; ?> puesto</h3>
                <form method="post">
                    <?php $this->nonce_field(); ?>
                    <input type="hidden" name="wbi_emp_action" value="save_config_item">
                    <input type="hidden" name="config_type" value="job_position">
                    <input type="hidden" name="config_id" value="<?php echo esc_attr( $edit_id ); ?>">
                    <p><label>Nombre *<br><input type="text" name="config_name" value="<?php echo esc_attr( $item->name ?? '' ); ?>" class="widefat" required></label></p>
                    <p><label>Departamento<br>
                        <select name="department_id" class="widefat">
                            <option value="0">— Sin departamento —</option>
                            <?php foreach ( $depts as $d ) : ?>
                                <option value="<?php echo esc_attr( $d->id ); ?>" <?php selected( $item->department_id ?? 0, $d->id ); ?>><?php echo esc_html( $d->name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label></p>
                    <p><label>Empleados esperados<br><input type="number" name="expected_employees" value="<?php echo esc_attr( $item->expected_employees ?? 1 ); ?>" min="0" class="widefat"></label></p>
                    <p><label>Estado<br>
                        <select name="job_status" class="widefat">
                            <option value="abierto" <?php selected( $item->status ?? 'abierto', 'abierto' ); ?>>Abierto</option>
                            <option value="cerrado" <?php selected( $item->status ?? '', 'cerrado' ); ?>>Cerrado</option>
                        </select>
                    </label></p>
                    <p><label>Descripción<br><textarea name="config_description" rows="3" class="widefat"><?php echo esc_textarea( $item->description ?? '' ); ?></textarea></label></p>
                    <?php submit_button( 'Guardar', 'primary', 'submit', false ); ?>
                    <?php if ( $item ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-employees&tab=settings&subtab=job_position' ) ); ?>" class="button" style="margin-left:6px;">Cancelar</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <?php
    }

    // ── Config: Contract Templates ────────────────────────────────────────────

    private function render_config_contract_templates() {
        global $wpdb;
        $edit_id   = intval( $_GET['edit'] ?? 0 );
        $item      = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wbi_contract_templates WHERE id = %d", $edit_id ) ) : null;
        $items     = $wpdb->get_results( "SELECT ct.*, ws.name as schedule_name FROM {$wpdb->prefix}wbi_contract_templates ct LEFT JOIN {$wpdb->prefix}wbi_work_schedules ws ON ws.id = ct.work_schedule_id ORDER BY ct.name" );
        $schedules = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wbi_work_schedules ORDER BY name" );
        ?>
        <div style="display:grid; grid-template-columns:1fr 360px; gap:20px; align-items:start;">
            <div>
                <h3>Plantillas de contrato</h3>
                <table class="widefat">
                    <thead><tr><th>Nombre</th><th>Estructura salarial</th><th>Horario</th><th></th></tr></thead>
                    <tbody>
                    <?php if ( empty( $items ) ) : ?>
                        <tr><td colspan="4" style="color:#50575e;">No hay plantillas.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $items as $it ) : ?>
                        <tr>
                            <td><?php echo esc_html( $it->name ); ?></td>
                            <td><?php echo esc_html( $it->salary_structure ); ?></td>
                            <td><?php echo esc_html( $it->schedule_name ?? '—' ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-employees&tab=settings&subtab=contract_template&edit=' . $it->id ) ); ?>" class="button button-small">Editar</a>
                                <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar?');">
                                    <?php $this->nonce_field(); ?>
                                    <input type="hidden" name="wbi_emp_action" value="delete_config_item">
                                    <input type="hidden" name="config_type" value="contract_template">
                                    <input type="hidden" name="config_id" value="<?php echo esc_attr( $it->id ); ?>">
                                    <button type="submit" class="button button-small" style="color:#a00;">✕</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div style="background:#fff; border:1px solid #c3c4c7; border-radius:6px; padding:16px;">
                <h3 style="margin-top:0;"><?php echo $item ? 'Editar' : 'Nueva'; ?> plantilla</h3>
                <form method="post">
                    <?php $this->nonce_field(); ?>
                    <input type="hidden" name="wbi_emp_action" value="save_config_item">
                    <input type="hidden" name="config_type" value="contract_template">
                    <input type="hidden" name="config_id" value="<?php echo esc_attr( $edit_id ); ?>">
                    <p><label>Nombre *<br><input type="text" name="config_name" value="<?php echo esc_attr( $item->name ?? '' ); ?>" class="widefat" required></label></p>
                    <p><label>Estructura salarial<br><input type="text" name="salary_structure" value="<?php echo esc_attr( $item->salary_structure ?? '' ); ?>" class="widefat"></label></p>
                    <p><label>Horario de trabajo<br>
                        <select name="work_schedule_id" class="widefat">
                            <option value="0">— Sin horario —</option>
                            <?php foreach ( $schedules as $s ) : ?>
                                <option value="<?php echo esc_attr( $s->id ); ?>" <?php selected( $item->work_schedule_id ?? 0, $s->id ); ?>><?php echo esc_html( $s->name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label></p>
                    <p><label>Descripción<br><textarea name="config_description" rows="3" class="widefat"><?php echo esc_textarea( $item->description ?? '' ); ?></textarea></label></p>
                    <?php submit_button( 'Guardar', 'primary', 'submit', false ); ?>
                    <?php if ( $item ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-employees&tab=settings&subtab=contract_template' ) ); ?>" class="button" style="margin-left:6px;">Cancelar</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <?php
    }
}
