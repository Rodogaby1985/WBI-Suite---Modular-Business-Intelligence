/**
 * WBI Public Wholesale Quick Order — JavaScript
 * Rebuilt from scratch. All previous PWOQ JS replaced.
 *
 * Behaviour:
 *  - Simple products: qty (starts 0) + AGREGAR → AJAX add → reset qty to 0 → refresh fragments
 *  - Variable products: dropdowns resolve variation → qty + AGREGAR → same flow
 *  - Sticky bar: updates on every qty change; AGREGAR AL CARRITO does mass add → fragment refresh / fallback reload
 *  - No chips, no perpetual loading, no "Variación inválida" for valid selections
 */
(function ( $ ) {
    'use strict';

    var cfg = window.WBIPwoq || {};
    var i18n = cfg.i18n || {};

    /* -----------------------------------------------------------------------
     * Helpers
     * --------------------------------------------------------------------- */

    function wcAjaxUrl( endpoint ) {
        return ( cfg.wcAjaxUrl || '' ).replace( '%%endpoint%%', endpoint );
    }

    function showToast( msg, type ) {
        var $t = $( '.wbi-pwoq-toast' );
        if ( ! $t.length ) return;
        $t.text( msg ).attr( 'class', 'wbi-pwoq-toast wbi-pwoq-toast--' + ( type || 'info' ) ).prop( 'hidden', false );
        clearTimeout( $t.data( 'timer' ) );
        $t.data( 'timer', setTimeout( function () { $t.prop( 'hidden', true ); }, 4000 ) );
    }

    function refreshFragments() {
        $( document.body ).trigger( 'wc_fragment_refresh' );
        $( document.body ).trigger( 'added_to_cart', [ {}, '', null ] );
    }

    /* -----------------------------------------------------------------------
     * Variation resolver — finds matching variation_id from selected attrs
     * --------------------------------------------------------------------- */

    function resolveVariation( card ) {
        var data = card.data( 'product' );
        if ( ! data || ! data.has_variations ) return { id: 0, ok: true };

        var selected = {};
        card.find( '.wbi-pwoq-select' ).each( function () {
            var attr = $( this ).data( 'attr' );
            var val  = $( this ).val();
            if ( attr ) selected[ 'attribute_' + attr ] = val || '';
        } );

        var variants = data.variants || [];
        for ( var i = 0; i < variants.length; i++ ) {
            var v    = variants[ i ];
            var attrs = v.attributes || {};
            var match = true;
            for ( var k in attrs ) {
                if ( ! attrs.hasOwnProperty( k ) ) continue;
                // '' in variation means "any" — accept
                if ( attrs[ k ] !== '' && attrs[ k ] !== selected[ k ] ) {
                    match = false;
                    break;
                }
            }
            if ( match ) return { id: v.id, min_qty: v.min_qty || 1, step_qty: v.step_qty || 1, ok: true };
        }

        return { id: 0, ok: false };
    }

    /* -----------------------------------------------------------------------
     * AJAX add to cart
     * --------------------------------------------------------------------- */

    function addToCart( productId, variationId, qty, attributes, done, fail ) {
        var params = {
            action      : 'woocommerce_add_to_cart',
            product_id  : productId,
            quantity    : qty,
            security    : cfg.nonce || ''
        };

        if ( variationId ) {
            params.variation_id = variationId;
            $.extend( params, attributes || {} );
        }

        $.post( wcAjaxUrl( 'add_to_cart' ), params )
            .done( function ( res ) {
                if ( res && res.error ) {
                    if ( fail ) fail( res.error_message || i18n.errorGeneric );
                } else {
                    if ( done ) done( res );
                }
            } )
            .fail( function () {
                if ( fail ) fail( i18n.errorGeneric );
            } );
    }

    /* -----------------------------------------------------------------------
     * Individual AGREGAR handler
     * --------------------------------------------------------------------- */

    function handleIndividualAdd( $btn ) {
        var $card   = $btn.closest( '.wbi-pwoq-card' );
        var $qty    = $card.find( '.wbi-pwoq-qty' );
        var $status = $card.find( '.wbi-pwoq-status' );
        var qty     = parseInt( $qty.val(), 10 ) || 0;

        $status.text( '' ).removeClass( 'wbi-pwoq-status--error' );

        if ( qty <= 0 ) {
            $status.text( i18n.qtyPositive ).addClass( 'wbi-pwoq-status--error' );
            return;
        }

        var productId = parseInt( $card.data( 'product-id' ), 10 );
        var varResult = resolveVariation( $card );

        if ( ! varResult.ok ) {
            $status.text( i18n.selectVar ).addClass( 'wbi-pwoq-status--error' );
            return;
        }

        var variationId = varResult.id;

        // Collect selected attributes for variation
        var attributes = {};
        if ( variationId ) {
            $card.find( '.wbi-pwoq-select' ).each( function () {
                var attr = $( this ).data( 'attr' );
                if ( attr ) attributes[ 'attribute_' + attr ] = $( this ).val() || '';
            } );
        }

        // Enter loading state
        $btn.prop( 'disabled', true ).text( i18n.adding );

        addToCart(
            productId,
            variationId,
            qty,
            attributes,
            function () {
                // Success
                $qty.val( 0 );
                $btn.prop( 'disabled', false ).text( i18n.agregar );
                $status.text( '' );
                refreshFragments();
                updateStickyBar();
            },
            function ( errMsg ) {
                $btn.prop( 'disabled', false ).text( i18n.agregar );
                $status.text( errMsg || i18n.errorGeneric ).addClass( 'wbi-pwoq-status--error' );
            }
        );
    }

    /* -----------------------------------------------------------------------
     * Sticky bar
     * --------------------------------------------------------------------- */

    function updateStickyBar() {
        if ( ! cfg.globalAddEnabled ) return;

        var $bar = $( '.wbi-pwoq-sticky-bar' );
        if ( ! $bar.length ) return;

        var totalProducts = 0;
        var totalUnits    = 0;

        $( '.wbi-pwoq-card' ).each( function () {
            var qty = parseInt( $( this ).find( '.wbi-pwoq-qty' ).val(), 10 ) || 0;
            if ( qty > 0 ) {
                totalProducts++;
                totalUnits += qty;
            }
        } );

        if ( totalProducts > 0 ) {
            $bar.find( '.wbi-pwoq-sticky-bar__summary' ).text(
                totalProducts + ' ' + i18n.products + ' · ' + totalUnits + ' ' + i18n.units
            );
            $bar.prop( 'hidden', false );
        } else {
            $bar.prop( 'hidden', true );
        }
    }

    /* -----------------------------------------------------------------------
     * Mass add (sticky bar button)
     * --------------------------------------------------------------------- */

    function handleMassAdd( $btn ) {
        var items = [];

        $( '.wbi-pwoq-card' ).each( function () {
            var $card   = $( this );
            var qty     = parseInt( $card.find( '.wbi-pwoq-qty' ).val(), 10 ) || 0;
            if ( qty <= 0 ) return;

            var productId = parseInt( $card.data( 'product-id' ), 10 );
            var varResult = resolveVariation( $card );
            if ( ! varResult.ok ) return; // skip unresolved variations silently

            var variationId = varResult.id;
            var attributes  = {};
            if ( variationId ) {
                $card.find( '.wbi-pwoq-select' ).each( function () {
                    var attr = $( this ).data( 'attr' );
                    if ( attr ) attributes[ 'attribute_' + attr ] = $( this ).val() || '';
                } );
            }

            items.push( { productId: productId, variationId: variationId, qty: qty, attributes: attributes, $card: $card } );
        } );

        if ( ! items.length ) return;

        $btn.prop( 'disabled', true ).text( i18n.adding );

        var idx     = 0;
        var success = 0;

        function next() {
            if ( idx >= items.length ) {
                // All done
                $btn.prop( 'disabled', false ).text( i18n.massAdd );

                if ( success > 0 ) {
                    // Reset qtys
                    $( '.wbi-pwoq-card .wbi-pwoq-qty' ).val( 0 );
                    updateStickyBar();

                    // Try fragment refresh; fallback to reload
                    var reloaded = false;
                    if ( cfg.forceReload ) {
                        window.location.reload();
                        return;
                    }
                    $( document.body ).one( 'wc_fragments_refreshed', function () {
                        reloaded = true;
                    } );
                    refreshFragments();
                    setTimeout( function () {
                        if ( ! reloaded ) {
                            window.location.reload();
                        }
                    }, 3000 );
                }
                return;
            }

            var item = items[ idx++ ];
            addToCart(
                item.productId,
                item.variationId,
                item.qty,
                item.attributes,
                function () {
                    success++;
                    next();
                },
                function () {
                    // Continue on error
                    next();
                }
            );
        }

        next();
    }

    /* -----------------------------------------------------------------------
     * Event binding
     * --------------------------------------------------------------------- */

    function init() {
        // Individual AGREGAR
        $( document ).on( 'click', '.wbi-pwoq-add-btn', function ( e ) {
            e.preventDefault();
            handleIndividualAdd( $( this ) );
        } );

        // Mass add (sticky bar)
        $( document ).on( 'click', '.wbi-pwoq-mass-add-btn', function ( e ) {
            e.preventDefault();
            handleMassAdd( $( this ) );
        } );

        // Qty change → update sticky bar
        $( document ).on( 'change input', '.wbi-pwoq-qty', function () {
            updateStickyBar();
        } );

        // Dropdown change → clear status
        $( document ).on( 'change', '.wbi-pwoq-select', function () {
            $( this ).closest( '.wbi-pwoq-card' ).find( '.wbi-pwoq-status' ).text( '' ).removeClass( 'wbi-pwoq-status--error' );
        } );
    }

    $( function () { init(); } );

} )( jQuery );
