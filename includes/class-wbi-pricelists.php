<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI Pricelists Module — Listas de Precios por Rol
 *
 * Gestiona múltiples listas de precios con descuentos porcentuales
 * asignadas a roles de WordPress. Se aplican automáticamente en
 * WooCommerce según el rol del usuario logueado.
 */
class WBI_Pricelists_Module {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_page' ), 100 );
        add_action( 'admin_post_wbi_save_pricelist', array( $this, 'handle_save' ) );
        add_action( 'admin_post_wbi_delete_pricelist', array( $this, 'handle_delete' ) );

        // WooCommerce price filters
        add_filter( 'woocommerce_product_get_price',      array( $this, 'apply_price' ), 10, 2 );
        add_filter( 'woocommerce_product_get_sale_price', array( $this, 'apply_price' ), 10, 2 );
    }

    // -------------------------------------------------------------------------
    // ADMIN MENU
    // -------------------------------------------------------------------------

    public function register_page() {
        add_submenu_page(
            'wbi-dashboard-view',
            'Listas de Precios',
            '<span class="dashicons dashicons-list-view" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> Listas de Precios',
            'manage_options',
            'wbi-pricelists',
            array( $this, 'render' )
        );
    }

    // -------------------------------------------------------------------------
    // CRUD HELPERS
    // -------------------------------------------------------------------------

    private function get_all_lists() {
        return get_option( 'wbi_pricelists', array() );
    }

    private function save_all_lists( $lists ) {
        update_option( 'wbi_pricelists', $lists );
        delete_transient( 'wbi_active_pricelists' );
    }

    private function get_active_lists_cached() {
        $cached = get_transient( 'wbi_active_pricelists' );
        if ( false !== $cached ) return $cached;

        $all    = $this->get_all_lists();
        $today  = date( 'Y-m-d' );
        $active = array();
        foreach ( $all as $list ) {
            if ( empty( $list['active'] ) ) continue;
            if ( ! empty( $list['valid_from'] ) && $today < $list['valid_from'] ) continue;
            if ( ! empty( $list['valid_to'] )   && $today > $list['valid_to'] )   continue;
            $active[] = $list;
        }
        // Cached for 10 minutes. Note: if a price list expires mid-day, prices
        // may remain discounted for up to 10 minutes after expiry.
        set_transient( 'wbi_active_pricelists', $active, 10 * MINUTE_IN_SECONDS );
        return $active;
    }

    // -------------------------------------------------------------------------
    // HANDLE SAVE
    // -------------------------------------------------------------------------

    public function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permisos.' );
        check_admin_referer( 'wbi_pricelist_nonce', '_wbi_pl_nonce' );

        $lists = $this->get_all_lists();
        $id    = sanitize_text_field( isset( $_POST['pl_id'] ) ? wp_unslash( $_POST['pl_id'] ) : '' );

        $entry = array(
            'id'         => $id ?: uniqid( 'wbi_pl_', true ),
            'name'       => sanitize_text_field( wp_unslash( isset( $_POST['pl_name'] ) ? $_POST['pl_name'] : '' ) ),
            'slug'       => sanitize_title( wp_unslash( isset( $_POST['pl_name'] ) ? $_POST['pl_name'] : '' ) ),
            'discount'   => max( 0, min( 100, floatval( isset( $_POST['pl_discount'] ) ? $_POST['pl_discount'] : 0 ) ) ),
            'roles'      => isset( $_POST['pl_roles'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['pl_roles'] ) ) : array(),
            'valid_from' => sanitize_text_field( wp_unslash( isset( $_POST['pl_valid_from'] ) ? $_POST['pl_valid_from'] : '' ) ),
            'valid_to'   => sanitize_text_field( wp_unslash( isset( $_POST['pl_valid_to'] )   ? $_POST['pl_valid_to']   : '' ) ),
            'active'     => ! empty( $_POST['pl_active'] ),
        );

        // Update existing or add new
        $found = false;
        foreach ( $lists as $k => $l ) {
            if ( $l['id'] === $entry['id'] ) {
                $lists[ $k ] = $entry;
                $found = true;
                break;
            }
        }
        if ( ! $found ) {
            $lists[] = $entry;
        }

        $this->save_all_lists( $lists );
        wp_safe_redirect( admin_url( 'admin.php?page=wbi-pricelists&saved=1' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // HANDLE DELETE
    // -------------------------------------------------------------------------

    public function handle_delete() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permisos.' );
        check_admin_referer( 'wbi_delete_pricelist' );

        $id    = sanitize_text_field( wp_unslash( isset( $_GET['pl_id'] ) ? $_GET['pl_id'] : '' ) );
        $lists = $this->get_all_lists();
        $lists = array_values( array_filter( $lists, function( $l ) use ( $id ) {
            return $l['id'] !== $id;
        } ) );
        $this->save_all_lists( $lists );
        wp_safe_redirect( admin_url( 'admin.php?page=wbi-pricelists&deleted=1' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // RENDER ADMIN PAGE
    // -------------------------------------------------------------------------

    public function render() {
        $lists   = $this->get_all_lists();
        $editing = null;
        $edit_id = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';
        if ( $edit_id ) {
            foreach ( $lists as $l ) {
                if ( $l['id'] === $edit_id ) {
                    $editing = $l;
                    break;
                }
            }
        }

        // Get all WP roles
        global $wp_roles;
        $all_roles = $wp_roles->get_names();
        ?>
        <div class="wrap">
            <h1>💲 Listas de Precios</h1>

            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>✅ Lista guardada correctamente.</p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['deleted'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>🗑️ Lista eliminada correctamente.</p></div>
            <?php endif; ?>

            <div style="display:grid; grid-template-columns: 1fr 380px; gap:24px; margin-top:20px; align-items:start;">

                <!-- TABLA DE LISTAS -->
                <div>
                    <h2 style="margin-top:0;">Listas configuradas</h2>
                    <?php if ( ! empty( $lists ) ) : ?>
                    <table class="widefat striped wbi-sortable">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Descuento</th>
                                <th>Roles Asignados</th>
                                <th>Desde</th>
                                <th>Hasta</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $lists as $l ) :
                            $edit_url   = esc_url( admin_url( 'admin.php?page=wbi-pricelists&edit=' . rawurlencode( $l['id'] ) ) );
                            $delete_url = esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wbi_delete_pricelist&pl_id=' . rawurlencode( $l['id'] ) ), 'wbi_delete_pricelist' ) );
                            $role_names = array();
                            foreach ( (array) $l['roles'] as $r ) {
                                $role_names[] = isset( $all_roles[ $r ] ) ? translate_user_role( $all_roles[ $r ] ) : esc_html( $r );
                            }
                        ?>
                            <tr>
                                <td><strong><?php echo esc_html( $l['name'] ); ?></strong></td>
                                <td><?php echo esc_html( $l['discount'] ); ?>%</td>
                                <td><?php echo esc_html( implode( ', ', $role_names ) ?: '—' ); ?></td>
                                <td><?php echo esc_html( $l['valid_from'] ?: '—' ); ?></td>
                                <td><?php echo esc_html( $l['valid_to']   ?: '—' ); ?></td>
                                <td>
                                    <?php if ( ! empty( $l['active'] ) ) : ?>
                                        <span style="color:#00a32a; font-weight:600;">✅ Activa</span>
                                    <?php else : ?>
                                        <span style="color:#646970;">⏸ Inactiva</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo $edit_url; ?>" class="button button-small">✏️ Editar</a>
                                    <a href="<?php echo $delete_url; ?>" class="button button-small"
                                       onclick="return confirm('¿Eliminar esta lista de precios?');">🗑️</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else : ?>
                        <p style="color:#646970;">No hay listas de precios configuradas aún.</p>
                    <?php endif; ?>
                </div>

                <!-- FORMULARIO AGREGAR / EDITAR -->
                <div style="background:#fff; padding:20px; border:1px solid #c3c4c7;">
                    <h2 style="margin-top:0;"><?php echo $editing ? '✏️ Editar Lista' : '➕ Nueva Lista'; ?></h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'wbi_pricelist_nonce', '_wbi_pl_nonce' ); ?>
                        <input type="hidden" name="action" value="wbi_save_pricelist">
                        <input type="hidden" name="pl_id" value="<?php echo esc_attr( $editing ? $editing['id'] : '' ); ?>">

                        <table class="form-table" style="margin:0;">
                            <tr>
                                <th style="padding:8px 0;"><label for="pl_name">Nombre</label></th>
                                <td style="padding:8px 0;">
                                    <input type="text" id="pl_name" name="pl_name" class="regular-text"
                                           value="<?php echo esc_attr( $editing ? $editing['name'] : '' ); ?>" required>
                                </td>
                            </tr>
                            <tr>
                                <th style="padding:8px 0;"><label for="pl_discount">Descuento (%)</label></th>
                                <td style="padding:8px 0;">
                                    <input type="number" id="pl_discount" name="pl_discount" min="0" max="100" step="0.01"
                                           class="small-text"
                                           value="<?php echo esc_attr( $editing ? $editing['discount'] : '' ); ?>" required>
                                </td>
                            </tr>
                            <tr>
                                <th style="padding:8px 0;"><label for="pl_roles">Roles</label></th>
                                <td style="padding:8px 0;">
                                    <select id="pl_roles" name="pl_roles[]" multiple size="5" style="min-width:200px;">
                                        <?php foreach ( $all_roles as $role_key => $role_name ) : ?>
                                            <option value="<?php echo esc_attr( $role_key ); ?>"
                                                <?php echo ( $editing && in_array( $role_key, (array) $editing['roles'], true ) ) ? 'selected' : ''; ?>>
                                                <?php echo esc_html( translate_user_role( $role_name ) ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Ctrl/Cmd para múltiple</p>
                                </td>
                            </tr>
                            <tr>
                                <th style="padding:8px 0;"><label for="pl_valid_from">Válida desde</label></th>
                                <td style="padding:8px 0;">
                                    <input type="date" id="pl_valid_from" name="pl_valid_from"
                                           value="<?php echo esc_attr( $editing ? $editing['valid_from'] : '' ); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th style="padding:8px 0;"><label for="pl_valid_to">Válida hasta</label></th>
                                <td style="padding:8px 0;">
                                    <input type="date" id="pl_valid_to" name="pl_valid_to"
                                           value="<?php echo esc_attr( $editing ? $editing['valid_to'] : '' ); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th style="padding:8px 0;">Activa</th>
                                <td style="padding:8px 0;">
                                    <input type="checkbox" name="pl_active" value="1"
                                           <?php checked( $editing ? ! empty( $editing['active'] ) : false ); ?>>
                                </td>
                            </tr>
                        </table>

                        <p style="margin-top:15px;">
                            <button type="submit" class="button button-primary">
                                <?php echo $editing ? '💾 Actualizar' : '➕ Agregar Lista'; ?>
                            </button>
                            <?php if ( $editing ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-pricelists' ) ); ?>"
                               class="button" style="margin-left:5px;">Cancelar</a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

            </div><!-- end grid -->
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // WOOCOMMERCE PRICE FILTER
    // -------------------------------------------------------------------------

    /**
     * Apply the most-discount matching price list to the product price.
     *
     * @param string     $price   Original price.
     * @param WC_Product $product Product object.
     * @return string Modified price.
     */
    public function apply_price( $price, $product ) {
        if ( ! is_user_logged_in() ) return $price;

        $user         = wp_get_current_user();
        $user_roles   = (array) $user->roles;
        $active_lists = $this->get_active_lists_cached();

        $best_discount = 0;
        foreach ( $active_lists as $list ) {
            $list_roles = (array) $list['roles'];
            $matches    = array_intersect( $user_roles, $list_roles );
            if ( ! empty( $matches ) ) {
                $disc = floatval( $list['discount'] );
                if ( $disc > $best_discount ) {
                    $best_discount = $disc;
                }
            }
        }

        if ( $best_discount <= 0 ) return $price;

        $original = floatval( $price );
        if ( $original <= 0 ) return $price;

        return (string) round( $original * ( 1 - $best_discount / 100 ), 2 );
    }
}
