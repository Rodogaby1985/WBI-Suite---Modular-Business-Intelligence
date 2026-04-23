/* global WBI, jQuery */
(function($){
    'use strict';

    var $overlay, captured = false, exitShown = false;

    function getCookie(name) {
        var v = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
        return v ? v.pop() : '';
    }
    function setCookie(name, val, days) {
        var d = new Date(); d.setTime(d.getTime() + days*86400000);
        var secure = WBI.isHttps ? ';Secure' : '';
        document.cookie = name + '=' + val + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax' + secure;
    }

    function isDismissed() {
        return getCookie('wbi_cart_contact_dismissed') === '1' ||
               sessionStorage.getItem('wbi_cart_dismissed') === '1';
    }

    function focusFirstField() {
        // Delay allows the popup animation to complete and the DOM to stabilize
        // before attempting to set focus.
        setTimeout(function(){
            var $field = $('#wbi-popup-email');
            if ( !$field.length || !$field.is(':visible') ) {
                $field = $('#wbi-cart-popup').find('input:visible').first();
            }
            if ( $field.length ) {
                $field[0].focus();
                // .click() is required on iOS to trigger the virtual keyboard;
                // .focus() alone does not reliably show the keyboard on iOS devices.
                $field[0].click();
            }
        }, 50);
    }

    function neutralizeOffCanvas() {
        var drawerSelectors = [
            '.off-canvas-close',
            '.offcanvas-close',
            '.drawer-close',
            '.close-offcanvas',
            '[data-dismiss="offcanvas"]',
            '.mfp-close'
        ];
        var i, $closeBtn;
        for ( i = 0; i < drawerSelectors.length; i++ ) {
            $closeBtn = $( drawerSelectors[i] + ':visible' ).first();
            if ( $closeBtn.length ) {
                $closeBtn.trigger('click');
                break;
            }
        }
    }

    function buildPopup() {
        if ( $('#wbi-cart-popup-overlay').length ) return;
        $('body').append(WBI.popupHtml);
        $overlay = $('#wbi-cart-popup-overlay');

        $overlay.on('click', function(e){ if($(e.target).is($overlay)) dismissPopup(); });
        $('#wbi-popup-close, #wbi-popup-skip').on('click', dismissPopup);
        $('#wbi-popup-save').on('click', saveContact);
    }

    function openPopup(type) {
        if ( getCookie('wbi_cart_contact_captured') === '1' ) return;
        if ( isDismissed() ) return;
        neutralizeOffCanvas();
        buildPopup();
        var title = type === 'exit' ? WBI.titleExit : WBI.titleAdd;
        var body  = type === 'exit' ? WBI.bodyExit  : WBI.bodyAdd;
        $('#wbi-popup-title').text(title);
        $('#wbi-popup-body').text(body);
        $overlay.addClass('wbi-show');
        focusFirstField();
        if ( type === 'exit' ) {
            sessionStorage.setItem('wbi_exit_shown','1');
        }
    }

    function closePopup() {
        if ( $overlay ) $overlay.removeClass('wbi-show');
    }

    function dismissPopup() {
        setCookie('wbi_cart_contact_dismissed', '1', 1);
        sessionStorage.setItem('wbi_cart_dismissed', '1');
        closePopup();
    }

    function refreshNonce(callback) {
        $.get(WBI.ajaxUrl, { action: 'wbi_refresh_nonce' }, function(res){
            if ( res && res.success && res.data && res.data.nonce ) {
                WBI.nonce = res.data.nonce;
                if ( callback ) callback();
            }
        });
    }

    function saveContact() {
        var email = $.trim($('#wbi-popup-email').val());
        var phone = $.trim($('#wbi-popup-phone').val());
        if ( !email && !phone ) {
            $('#wbi-popup-error').text('Por favor ingresá al menos un email o WhatsApp.').show();
            return;
        }
        $('#wbi-popup-error').hide();
        $.post(WBI.ajaxUrl, {
            action: 'wbi_capture_cart_contact',
            nonce:  WBI.nonce,
            email:  email,
            phone:  phone
        }, function(res){
            if ( res && res.success ) {
                setCookie('wbi_cart_contact_captured','1',30);
                captured = true;
                closePopup();
            } else {
                // Cart was empty or error — close popup and set a short cookie so
                // the popup doesn't reappear immediately on this visit.
                setCookie('wbi_cart_contact_captured','1',1);
                closePopup();
            }
        }).fail(function(xhr){
            if ( xhr.status === 403 ) {
                refreshNonce(function(){
                    $.post(WBI.ajaxUrl, {
                        action: 'wbi_capture_cart_contact',
                        nonce:  WBI.nonce,
                        email:  email,
                        phone:  phone
                    }, function(res){
                        if ( res && res.success ) {
                            setCookie('wbi_cart_contact_captured','1',30);
                            captured = true;
                        } else {
                            setCookie('wbi_cart_contact_captured','1',1);
                        }
                        closePopup();
                    }).fail(function(){
                        setCookie('wbi_cart_contact_captured','1',1);
                        closePopup();
                    });
                });
            } else {
                setCookie('wbi_cart_contact_captured','1',1);
                closePopup();
            }
        });
    }

    // --- Agregar al carrito ---
    if ( WBI.showAddPopup ) {

        // 1. MÉTODO PRINCIPAL: interceptar CUALQUIER AJAX exitoso de add-to-cart
        $(document).ajaxComplete(function(event, xhr, settings){
            if ( getCookie('wbi_cart_contact_captured') === '1' ) return;
            if ( !settings || !settings.url ) return;

            var url  = settings.url || '';
            var data = settings.data || '';
            var isAddToCart = (
                url.indexOf('wc-ajax=add_to_cart') !== -1
                || (typeof data === 'string' && data.indexOf('add-to-cart') !== -1)
                || url.indexOf('add_to_cart') !== -1
            );

            if ( isAddToCart && xhr.status === 200 ) {
                setTimeout(function(){ openPopup('add'); }, 600);
            }
        });

        // 2. FALLBACK: evento nativo de WooCommerce (funciona en catálogo con AJAX)
        $(document.body).on('added_to_cart', function(){
            if ( getCookie('wbi_cart_contact_captured') !== '1' ) {
                setTimeout(function(){ openPopup('add'); }, 600);
            }
        });

        // 3. REDIRECT add-to-cart: interceptar el form submit y marcar sessionStorage
        $(document).on('submit', 'form.cart', function(){
            if ( getCookie('wbi_cart_contact_captured') !== '1' ) {
                sessionStorage.setItem('wbi_show_add_popup', '1');
            }
        });

        // 4. Al cargar cualquier página, verificar si venimos de un redirect de add-to-cart
        $(function(){
            if ( sessionStorage.getItem('wbi_show_add_popup') === '1' ) {
                sessionStorage.removeItem('wbi_show_add_popup');
                setTimeout(function(){ openPopup('add'); }, 800);
                return;
            }
            var urlParams = new URLSearchParams(window.location.search);
            if ( urlParams.has('add-to-cart') && getCookie('wbi_cart_contact_captured') !== '1' ) {
                setTimeout(function(){ openPopup('add'); }, 800);
                return;
            }
        });

        // 5. Interceptar botones .add_to_cart_button que hacen redirect (no-AJAX)
        $(document).on('click', 'a.add_to_cart_button[href*="add-to-cart"]', function(){
            if ( getCookie('wbi_cart_contact_captured') !== '1' ) {
                sessionStorage.setItem('wbi_show_add_popup', '1');
            }
        });
    }

    // --- Exit intent ---
    if ( WBI.showExitPopup ) {
        document.addEventListener('mouseleave', function(e){
            if ( e.clientY <= 0 && !sessionStorage.getItem('wbi_exit_shown') && getCookie('wbi_cart_contact_captured') !== '1' ) {
                openPopup('exit');
            }
        });
        document.addEventListener('visibilitychange', function(){
            if ( document.visibilityState === 'hidden' && !sessionStorage.getItem('wbi_exit_shown') && getCookie('wbi_cart_contact_captured') !== '1' ) {
                sessionStorage.setItem('wbi_exit_pending','1');
                sessionStorage.setItem('wbi_exit_shown','1');
            }
            if ( document.visibilityState === 'visible' && sessionStorage.getItem('wbi_exit_pending') === '1' ) {
                sessionStorage.removeItem('wbi_exit_pending');
                openPopup('exit');
            }
        });
        window.addEventListener('beforeunload', function(){
            if ( !sessionStorage.getItem('wbi_exit_shown') && getCookie('wbi_cart_contact_captured') !== '1' ) {
                if ( navigator.sendBeacon ) {
                    var fd = new FormData();
                    fd.append('action', 'wbi_update_cart_data');
                    fd.append('nonce', WBI.nonce);
                    navigator.sendBeacon(WBI.ajaxUrl, fd);
                }
            }
        });
    }

    // --- Captura email de checkout con debounce ---
    var billingDebounce;
    $(document).on('change blur keyup', '#billing_email', function(){
        clearTimeout(billingDebounce);
        var val = $.trim($(this).val());
        if ( !val ) return;
        billingDebounce = setTimeout(function(){
            $.post(WBI.ajaxUrl, {
                action: 'wbi_capture_cart_contact',
                nonce:  WBI.nonce,
                email:  val,
                phone:  ''
            });
        }, 2000);
    });

})(jQuery);
