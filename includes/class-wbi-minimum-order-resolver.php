<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI Minimum Order Resolver — Single source of truth for minimum purchase amounts.
 *
 * Precedence (highest → lowest):
 *   1. User override  — user meta `wbi_minimum_order_amount_override`
 *   2. Role override  — B2B setting `wbi_b2b_role_minimum_map` (keyed by role slug)
 *   3. Price List     — field `minimum_order_amount` on the user's best matching list
 *   4. Global fallback — B2B setting `wbi_b2b_minimum_order`
 *   5. None (no minimum enforced)
 *
 * Usage:
 *   $result = WBI_Minimum_Order_Resolver::resolve_for_user( $user_id );
 *   // $result['amount']        float|null
 *   // $result['source']        string  (user_override|role_override|price_list|global_fallback|none)
 *   // $result['price_list_id'] string|null
 *   // $result['role']          string|null
 */
class WBI_Minimum_Order_Resolver {

    /**
     * Resolve the effective minimum order amount for a given user.
     *
     * Pass 0 to get the guest / not-logged-in result.
     *
     * @param int $user_id  WordPress user ID. 0 for guests.
     * @return array{amount: float|null, source: string, price_list_id: string|null, role: string|null}
     */
    public static function resolve_for_user( $user_id ) {
        $result = array(
            'amount'        => null,
            'source'        => 'none',
            'price_list_id' => null,
            'role'          => null,
        );

        $user_id = absint( $user_id );

        // ── 1. User override ────────────────────────────────────────────────
        if ( $user_id > 0 ) {
            $override = get_user_meta( $user_id, 'wbi_minimum_order_amount_override', true );
            if ( '' !== $override && null !== $override && is_numeric( $override ) ) {
                $amount = floatval( $override );
                if ( $amount > 0 ) {
                    $result['amount'] = $amount;
                    $result['source'] = 'user_override';
                    return $result;
                }
                // 0 or negative — treat as "no override"
            }
        }

        $user_roles = $user_id > 0 ? (array) ( get_userdata( $user_id ) )->roles : array();
        $opts       = get_option( 'wbi_modules_settings', array() );

        // ── 2. Role override ─────────────────────────────────────────────────
        $role_map = isset( $opts['wbi_b2b_role_minimum_map'] ) && is_array( $opts['wbi_b2b_role_minimum_map'] )
            ? $opts['wbi_b2b_role_minimum_map']
            : array();

        if ( ! empty( $role_map ) && ! empty( $user_roles ) ) {
            $best_role_amount = null;
            $best_role_slug   = null;
            foreach ( $user_roles as $role_slug ) {
                if ( isset( $role_map[ $role_slug ] ) ) {
                    $candidate = floatval( $role_map[ $role_slug ] );
                    if ( $candidate > 0 ) {
                        // Use the highest role-level minimum among all user roles.
                        if ( null === $best_role_amount || $candidate > $best_role_amount ) {
                            $best_role_amount = $candidate;
                            $best_role_slug   = $role_slug;
                        }
                    }
                }
            }
            if ( null !== $best_role_amount ) {
                $result['amount'] = $best_role_amount;
                $result['source'] = 'role_override';
                $result['role']   = $best_role_slug;
                return $result;
            }
        }

        // ── 3. Price List minimum ────────────────────────────────────────────
        if ( ! empty( $user_roles ) ) {
            $all_lists = get_option( 'wbi_pricelists', array() );
            $today     = date( 'Y-m-d' );

            $best_list_amount   = null;
            $best_list_id       = null;
            $best_list_discount = -1; // used to break ties (most-discounted list wins)

            foreach ( $all_lists as $list ) {
                // Must be active and within validity window.
                if ( empty( $list['active'] ) ) continue;
                if ( ! empty( $list['valid_from'] ) && $today < $list['valid_from'] ) continue;
                if ( ! empty( $list['valid_to'] )   && $today > $list['valid_to'] )   continue;

                // Must match at least one of the user's roles.
                $list_roles = (array) ( isset( $list['roles'] ) ? $list['roles'] : array() );
                if ( empty( array_intersect( $user_roles, $list_roles ) ) ) continue;

                // List must have a valid minimum_order_amount.
                if ( ! isset( $list['minimum_order_amount'] ) || '' === $list['minimum_order_amount'] ) continue;
                $list_amount = floatval( $list['minimum_order_amount'] );
                if ( $list_amount <= 0 ) continue;

                // Among matching lists use the one with the highest discount;
                // if equal discount, use the first encountered (stable sort).
                $disc = floatval( isset( $list['discount'] ) ? $list['discount'] : 0 );
                if ( null === $best_list_amount || $disc > $best_list_discount ) {
                    $best_list_amount   = $list_amount;
                    $best_list_id       = isset( $list['id'] ) ? $list['id'] : null;
                    $best_list_discount = $disc;
                }
            }

            if ( null !== $best_list_amount ) {
                $result['amount']        = $best_list_amount;
                $result['source']        = 'price_list';
                $result['price_list_id'] = $best_list_id;
                return $result;
            }
        }

        // ── 4. Global fallback ───────────────────────────────────────────────
        $global = isset( $opts['wbi_b2b_minimum_order'] ) ? floatval( $opts['wbi_b2b_minimum_order'] ) : 0;
        if ( $global > 0 ) {
            $result['amount'] = $global;
            $result['source'] = 'global_fallback';
            return $result;
        }

        // ── 5. No minimum ────────────────────────────────────────────────────
        return $result;
    }
}
