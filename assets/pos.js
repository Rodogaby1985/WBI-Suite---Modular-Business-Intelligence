/**
 * WBI POS — Point of Sale JavaScript
 * Assets: assets/pos.js
 *
 * Depends on: jQuery, wbiPos (wp_localize_script)
 */
/* global wbiPos */
(function ($) {
    'use strict';

    // ── State ──────────────────────────────────────────────────────────────
    var cart       = [];          // [ { id, name, sku, price, qty, image } ]
    var payments   = [];          // [ { method, amount, reference } ]
    var adjustments = [];         // [ { type, mode, value, reason, amount } ]
    var customer   = null;        // { id, name, email, customer_type, is_guest, guest_data } or null = consumidor final
    var isConsumerFinal = false;
    var paymentIdx = 0;           // counter for unique payment row IDs
    var scannerMode = false;
    var priceDraftsByIdx = {};
    var productSearchTimer = null;
    var customerSearchTimer = null;
    var productDropdownState = {
        query: '',
        page: 1,
        perPage: 20,
        hasMore: false,
        isLoading: false,
        mode: 'catalog',
        requestId: 0
    };

    // Seller / cash session state
    var activeSeller  = null;   // { id, name } — selected seller
    var cashSession   = null;   // { session_id, status, opening_cash, opened_at } or null

    var DRAFT_KEY = 'wbi_pos_draft';
    var PRICE_DECIMALS = Math.max(0, parseInt((wbiPos && wbiPos.priceDecimals), 10) || 2);

    // ── Init ───────────────────────────────────────────────────────────────
    $(function () {
        bindEvents();
        loadSellers();
        maybeRecoverDraft();
        updateTotals();
        initSettingsUI();
    });

    // ── Event binding ──────────────────────────────────────────────────────
    function bindEvents() {
        // Product search
        $('#pos-product-search').on('input', function () {
            handleProductQueryChange($(this).val());
        });

        $('#pos-product-search').on('focus click', function () {
            if ($(this).val().trim().length === 0) {
                loadProducts('', 1, false);
            }
        });

        $('#pos-product-results').on('scroll', function () {
            if (!productDropdownState.hasMore || productDropdownState.isLoading) return;
            if (!$(this).hasClass('open')) return;

            var threshold = 48;
            var nearBottom = this.scrollTop + this.clientHeight >= this.scrollHeight - threshold;
            if (nearBottom) {
                loadProducts(productDropdownState.query, productDropdownState.page + 1, true);
            }
        });

        // Close dropdowns on outside click
        $(document).on('click', function (e) {
            if (!$(e.target).closest('.pos-search-bar').length) {
                closeDropdown('#pos-product-results');
            }
            if (!$(e.target).closest('.pos-customer-wrap').length) {
                closeDropdown('#pos-customer-results');
            }
        });

        // Scanner mode toggle
        $('#pos-scanner-mode').on('change', function () {
            scannerMode = this.checked;
            if (scannerMode) {
                $('#pos-product-search').closest('.pos-search-bar').addClass('scanner-active');
                $('#pos-product-search').attr('placeholder', wbiPos.i18n.scannerHint).focus();
            } else {
                $('#pos-product-search').closest('.pos-search-bar').removeClass('scanner-active');
                $('#pos-product-search').attr('placeholder', wbiPos.i18n.searchPlaceholder);
            }
        });

        // Scanner: pressing Enter immediately adds the first result
        $('#pos-product-search').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var val = String($(this).val() || '').trim();
                if (looksLikeQrCode(val)) {
                    clearTimeout(productSearchTimer);
                    resolveQrCode(val);
                    return;
                }
                var $first = $('#pos-product-results .pos-dropdown-item').first();
                if ($first.length) {
                    $first.trigger('click');
                }
            } else if (e.key === 'ArrowDown') {
                var $items = $('#pos-product-results .pos-dropdown-item');
                if ($items.length) {
                    e.preventDefault();
                    $items.first().focus();
                }
            } else if (e.key === 'Escape') {
                closeDropdown('#pos-product-results');
            }
        });

        // Add payment button
        $('#pos-btn-add-payment').on('click', function () {
            addPaymentRow();
        });

        // New order button
        $('#pos-btn-new').on('click', function () {
            if (cart.length > 0 || payments.length > 0) {
                if (!window.confirm(wbiPos.i18n.confirmNewOrder)) return;
            }
            resetPos();
        });

        // Consumidor Final button
        $('#pos-btn-consumer').on('click', function () {
            customer = null;
            isConsumerFinal = true;
            $('#pos-customer-search').val('');
            $('#pos-customer-selected')
                .html('<span class="pos-customer-type-badge pos-badge-consumer">Consumidor Final</span>' +
                      '<span class="pos-clear-customer" title="Quitar">✕</span>')
                .show();
            $('#pos-customer-selected .pos-clear-customer').on('click', clearCustomer);
            closeDropdown('#pos-customer-results');
            updateTotals();
            saveDraft();
        });

        // + Nuevo cliente button
        $('#pos-btn-new-customer').on('click', function () {
            openCreateCustomerModal();
        });

        // Quick create customer confirm
        $('#pos-btn-cc-confirm').on('click', function () {
            submitCreateCustomer();
        });

        // Adjustment add button
        $('#pos-btn-add-adjustment').on('click', function () {
            openAdjustmentModal();
        });

        // Adjustment confirm
        $('#pos-btn-adj-confirm').on('click', function () {
            submitAddAdjustment();
        });

        // Adjustment type change: show/hide reason group based on require setting
        $('#pos-adj-type').on('change', function () {
            updateAdjReasonVisibility();
        });

        // Customer search
        $('#pos-customer-search').on('input', function () {
            clearTimeout(customerSearchTimer);
            var q = $(this).val().trim();
            if (q.length < 2) {
                closeDropdown('#pos-customer-results');
                return;
            }
            customerSearchTimer = setTimeout(function () {
                searchCustomers(q);
            }, 300);
        });

        // Confirm order
        $('#pos-btn-confirm').on('click', function () {
            if ($(this).prop('disabled')) return;
            createOrder();
        });

        // Seller selector change
        $('#pos-seller-select').on('change', function () {
            var sellerId = parseInt($(this).val(), 10);
            if (!sellerId) {
                activeSeller = null;
                cashSession  = null;
                updateCashStatusUI();
                updateTotals();
                return;
            }
            var sellerName = $(this).find('option:selected').text();
            activeSeller = { id: sellerId, name: sellerName };
            cashSession  = null;
            loadCashStatus(sellerId);
        });

        // Open cash button
        $('#pos-btn-open-cash').on('click', function () {
            openModal('pos-modal-open-cash');
        });

        // Add movement button
        $('#pos-btn-add-movement').on('click', function () {
            if (!cashSession || cashSession.status !== 'open') {
                alert(wbiPos.i18n.noCashForMovement);
                return;
            }
            // Reset movement form
            $('#pos-movement-type').val('manual_income');
            $('#pos-movement-method').val('cash');
            $('#pos-movement-amount').val('');
            $('#pos-movement-reference').val('');
            $('#pos-movement-notes').val('');
            openModal('pos-modal-movement');
        });

        // Confirm add movement
        $('#pos-btn-movement-confirm').on('click', function () {
            submitAddMovement();
        });

        // Close cash button
        $('#pos-btn-close-cash').on('click', function () {
            if (!cashSession || !cashSession.session_id) return;
            loadCloseSummary(cashSession.session_id, function () {
                openModal('pos-modal-close-cash');
            });
        });

        // Confirm open cash
        $('#pos-btn-open-cash-confirm').on('click', function () {
            submitOpenCash();
        });

        // Confirm close cash
        $('#pos-btn-close-cash-confirm').on('click', function () {
            submitCloseCash();
        });

        // Modal close buttons
        $(document).on('click', '.pos-modal-close, .pos-modal-backdrop', function () {
            var modal = $(this).data('modal') || $(this).closest('.pos-modal').attr('id');
            if (modal) closeModal(modal);
        });

        // ESC key closes modals
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape') {
                $('.pos-modal:visible').each(function () {
                    closeModal($(this).attr('id'));
                });
            }
        });
    }

    // ── Product Search ─────────────────────────────────────────────────────
    function handleProductQueryChange(rawValue) {
        clearTimeout(productSearchTimer);
        var q = String(rawValue || '').trim();

        // QR scan detection: full resolver URL or bare signed token
        if (looksLikeQrCode(q)) {
            productSearchTimer = setTimeout(function () {
                resolveQrCode(q);
            }, 220);
            return;
        }

        productSearchTimer = setTimeout(function () {
            loadProducts(q, 1, false);
        }, 220);
    }

    // ── QR scan support ─────────────────────────────────────────────────────
    function looksLikeQrCode(q) {
        if (!wbiPos.qr || !wbiPos.qr.enabled || !q) return false;
        if (q.indexOf('wbi_qr=') !== -1) return true;
        // Bare v1 token: base64url of "1|…" always starts with "MXw"
        return /^MXw[A-Za-z0-9_-]{21,197}$/.test(q);
    }

    function resolveQrCode(code) {
        $.post(wbiPos.ajaxUrl, {
            action: wbiPos.qr.action,
            nonce: wbiPos.nonce,
            code: code
        }).done(function (resp) {
            if (resp && resp.success && resp.data && resp.data.product) {
                var p   = resp.data.product;
                var qty = Math.max(1, parseInt(resp.data.qty || 1, 10));
                addToCart({
                    id:    p.id,
                    name:  p.name,
                    sku:   p.sku || '',
                    price: parseFloat(p.price || 0) || 0,
                    image: p.image || ''
                }, qty);
                $('#pos-product-search').val('').focus();
                closeDropdown('#pos-product-results');
                showPosToast('✅ ' + p.name, 'success');
            } else if (resp && resp.data && resp.data.not_qr) {
                // Not actually a QR token — fall back to normal search
                loadProducts(code, 1, false);
            } else {
                showPosToast('⚠️ ' + ((resp && resp.data && resp.data.message) || 'QR inválido'), 'error');
                $('#pos-product-search').val('').focus();
            }
        }).fail(function () {
            loadProducts(code, 1, false);
        });
    }

    function showPosToast(message, type) {
        var $toast = $('<div class="pos-qr-toast"></div>')
            .text(message)
            .css({
                position: 'fixed',
                top: '48px',
                right: '24px',
                zIndex: 100000,
                padding: '10px 16px',
                borderRadius: '6px',
                color: '#fff',
                fontSize: '14px',
                boxShadow: '0 2px 8px rgba(0,0,0,.25)',
                background: type === 'error' ? '#d63638' : '#00a32a'
            });
        $('body').append($toast);
        setTimeout(function () {
            $toast.fadeOut(300, function () { $(this).remove(); });
        }, 2500);
    }

    function loadProducts(q, page, append) {
        q = String(q || '').trim();
        page = Math.max(1, parseInt(page || 1, 10));
        append = !!append;

        if (productDropdownState.isLoading) {
            if (append) return;
            productDropdownState.requestId += 1;
        }

        productDropdownState.isLoading = true;
        if (!append) {
            productDropdownState.query = q;
            productDropdownState.page = 1;
            productDropdownState.hasMore = false;
            productDropdownState.mode = q ? 'search' : 'catalog';
            showProductDropdownLoading(false);
        } else {
            showProductDropdownLoading(true);
        }

        var requestId = ++productDropdownState.requestId;

        $.ajax({
            url: wbiPos.ajaxUrl,
            type: 'GET',
            data: {
                action: 'wbi_pos_search_products',
                nonce: wbiPos.nonce,
                q: q,
                page: page,
                per_page: productDropdownState.perPage
            },
            success: function (resp) {
                if (requestId !== productDropdownState.requestId) return;
                if (!resp || !resp.success || !resp.data) {
                    showProductDropdown([], false, q ? 'search' : 'catalog');
                    productDropdownState.isLoading = false;
                    return;
                }

                var payload = resp.data || {};
                var items = $.isArray(payload) ? payload : (payload.items || []);
                var normalized = [];

                $.each(items, function (i, p) {
                    var id = parseInt((p && (p.product_id || p.id)) || 0, 10);
                    if (!id) return;
                    normalized.push({
                        id: id,
                        name: p.title || p.name || '',
                        sku: p.sku || '',
                        price: parseFloat(p.price || 0) || 0,
                        stock: (typeof p.stock === 'undefined') ? null : p.stock,
                        stock_status: p.stock_status || '',
                        image: p.image_url || p.image || ''
                    });
                });

                var hasMore = !!(payload.pagination && payload.pagination.has_more);
                productDropdownState.query = q;
                productDropdownState.page = page;
                productDropdownState.hasMore = hasMore;
                productDropdownState.mode = q ? 'search' : 'catalog';
                showProductDropdown(normalized, append, productDropdownState.mode);
                productDropdownState.isLoading = false;
            },
            error: function () {
                if (requestId !== productDropdownState.requestId) return;
                productDropdownState.isLoading = false;
                showProductDropdown([], false, q ? 'search' : 'catalog');
            }
        });
    }

    function showProductDropdownLoading(append) {
        var $d = $('#pos-product-results');
        if (!append) {
            $d.empty().append('<div class="pos-dropdown-loading">' + escHtml(wbiPos.i18n.loadingProducts || 'Cargando productos…') + '</div>').addClass('open');
            return;
        }

        $d.find('.pos-dropdown-loading-more').remove();
        $d.append('<div class="pos-dropdown-loading-more">' + escHtml(wbiPos.i18n.loadingMoreProducts || 'Cargando más…') + '</div>');
    }

    function showProductDropdown(products, append, mode) {
        var $d = $('#pos-product-results');
        append = !!append;
        mode = mode || 'catalog';
        $d.find('.pos-dropdown-loading-more').remove();

        if (!append) {
            $d.empty();
        }

        if (!products || products.length === 0) {
            if (append) {
                productDropdownState.hasMore = false;
                return;
            }
            var emptyText = mode === 'search'
                ? (wbiPos.i18n.noSearchResults || 'Sin resultados')
                : wbiPos.i18n.noProducts;
            $d.append('<div class="pos-dropdown-empty">' + escHtml(emptyText) + '</div>');
            $d.addClass('open');
            return;
        }

        var $items = [];
        $.each(products, function (i, p) {
            var imgHtml = p.image
                ? '<img src="' + escAttr(p.image) + '" alt="">'
                : '<div class="pos-item-no-img">📦</div>';

            var stockHtml = p.stock !== null
                ? ' &bull; Stock: ' + parseInt(p.stock, 10)
                : '';

            var stockStatus = p.stock_status ? ' &bull; ' + escHtml(p.stock_status) : '';

            var $item = $('<div class="pos-dropdown-item" tabindex="0" role="option">')
                .data('product', p)
                .html(
                    imgHtml +
                    '<div class="pos-dropdown-item-info">' +
                        '<div class="pos-dropdown-item-name">' + escHtml(p.name) + '</div>' +
                        '<div class="pos-dropdown-item-meta">SKU: ' + escHtml(p.sku || '—') + stockHtml + stockStatus + '</div>' +
                    '</div>' +
                    '<span class="pos-dropdown-item-price">' + wbiPos.currency + formatNumber(p.price) + '</span>'
                );

            $item.on('click', function () {
                addToCart($(this).data('product'));
                $('#pos-product-search').val('').focus();
                closeDropdown('#pos-product-results');
            });

            $item.on('keydown', function (e) {
                if (e.key === 'Enter') {
                    addToCart($(this).data('product'));
                    $('#pos-product-search').val('').focus();
                    closeDropdown('#pos-product-results');
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    $(this).nextAll('.pos-dropdown-item').first().focus();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    var $prev = $(this).prevAll('.pos-dropdown-item').first();
                    if ($prev.length) {
                        $prev.focus();
                    } else {
                        $('#pos-product-search').focus();
                    }
                } else if (e.key === 'Escape') {
                    closeDropdown('#pos-product-results');
                    $('#pos-product-search').focus();
                }
            });

            $d.append($item);
            $items.push($item);
        });

        $('#pos-product-search').off('keydown.productNav').on('keydown.productNav', function (e) {
            if (e.key === 'ArrowDown' && $items.length) {
                e.preventDefault();
                $items[0].focus();
            } else if (e.key === 'Escape') {
                closeDropdown('#pos-product-results');
            }
        });

        $d.addClass('open');
    }

    // ── Cart ───────────────────────────────────────────────────────────────
    function addToCart(product, qty) {
        qty = Math.max(1, parseInt(qty || 1, 10));
        var existing = null;
        $.each(cart, function (i, item) {
            if (item.id === product.id) {
                existing = item;
                return false;
            }
        });

        if (existing) {
            existing.qty += qty;
        } else {
            cart.push({
                id:    product.id,
                name:  product.name,
                sku:   product.sku,
                price: product.price,
                qty:   qty,
                image: product.image
            });
        }

        renderCart();
        updateTotals();
        saveDraft();
    }

    function removeFromCart(idx) {
        cart.splice(idx, 1);
        renderCart();
        updateTotals();
        saveDraft();
    }

    function renderCart() {
        var $tbody = $('#pos-cart-body');
        $tbody.empty();

        if (cart.length === 0) {
            $tbody.append(
                '<tr id="pos-cart-empty"><td colspan="5" class="pos-cart-empty-msg">' +
                'El carrito está vacío. Buscá productos arriba.</td></tr>'
            );
            return;
        }

        $.each(cart, function (idx, item) {
            var subtotal = item.qty * item.price;
            var $row = $('<tr>').attr('data-idx', idx);
            var priceErrorId = 'pos-cart-price-error-' + idx;

            $row.html(
                '<td>' + escHtml(item.name) + (item.sku ? '<br><small style="color:#888">SKU: ' + escHtml(item.sku) + '</small>' : '') + '</td>' +
                '<td><input type="number" class="pos-cart-qty-input" min="1" step="1" value="' + parseInt(item.qty, 10) + '" data-idx="' + idx + '"></td>' +
                '<td><input type="text" class="pos-cart-price-input" inputmode="decimal" aria-describedby="' + escAttr(priceErrorId) + '" value="' + escAttr(getCartPriceDisplayValue(idx, item.price)) + '" data-idx="' + idx + '">' +
                '<div id="' + escAttr(priceErrorId) + '" class="pos-cart-inline-error" aria-live="polite"></div></td>' +
                '<td class="pos-cart-subtotal">' + wbiPos.currency + formatNumber(subtotal) + '</td>' +
                '<td><button class="pos-btn-remove" data-idx="' + idx + '" title="Quitar">✕</button></td>'
            );

            $tbody.append($row);
        });

        // Qty change
        $tbody.find('.pos-cart-qty-input').off('change input').on('change input', function () {
            var idx = parseInt($(this).data('idx'), 10);
            var val = Math.max(1, parseInt($(this).val(), 10) || 1);
            cart[idx].qty = val;
            renderCart();
            updateTotals();
            saveDraft();
        });

        // Price change
        $tbody.find('.pos-cart-price-input')
            .off('.priceEdit')
            .on('mousedown.priceEdit touchstart.priceEdit click.priceEdit', function (e) {
                e.stopPropagation();
            })
            .on('focus.priceEdit', function () {
                var idx = parseInt($(this).data('idx'), 10);
                priceDraftsByIdx[idx] = $(this).val();
                clearPriceInputError($(this));
            })
            .on('input.priceEdit', function () {
                var idx = parseInt($(this).data('idx'), 10);
                priceDraftsByIdx[idx] = $(this).val();
                clearPriceInputError($(this));
            })
            .on('keydown.priceEdit', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    commitCartPriceInput($(this));
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    resetCartPriceInput($(this));
                    $(this).blur();
                }
            })
            .on('blur.priceEdit', function () {
                commitCartPriceInput($(this));
            });

        // Remove
        $tbody.find('.pos-btn-remove').off('click').on('click', function () {
            removeFromCart(parseInt($(this).data('idx'), 10));
        });
    }

    // ── Payments ───────────────────────────────────────────────────────────
    function addPaymentRow(method, amount, reference) {
        var id = 'pay_' + (paymentIdx++);

        // Build method options
        var methods = wbiPos.i18n.methods;
        var opts = '';
        $.each(methods, function (key, label) {
            var sel = (method === key) ? ' selected' : '';
            opts += '<option value="' + escAttr(key) + '"' + sel + '>' + escHtml(label) + '</option>';
        });

        var $row = $('<div class="pos-payment-row" data-pay-id="' + id + '">').html(
            '<select class="pos-pay-method">' + opts + '</select>' +
            '<input type="number" class="pos-payment-amount" min="0" step="0.01" placeholder="Monto" value="' + (amount || '') + '">' +
            '<input type="text" class="pos-payment-ref" placeholder="Ref." value="' + escAttr(reference || '') + '">' +
            '<button class="pos-btn-remove-payment" title="Quitar">✕</button>'
        );

        $row.find('.pos-btn-remove-payment').on('click', function () {
            $row.remove();
            updateTotals();
            saveDraft();
        });

        $row.find('.pos-pay-method, .pos-payment-amount, .pos-payment-ref').on('change input', function () {
            updateTotals();
            saveDraft();
        });

        $('#pos-payments-list').append($row);
        updateTotals();
    }

    function collectPayments() {
        var result = [];
        $('#pos-payments-list .pos-payment-row').each(function () {
            var method    = $(this).find('.pos-pay-method').val();
            var amount    = parseFloat($(this).find('.pos-payment-amount').val()) || 0;
            var reference = $(this).find('.pos-payment-ref').val().trim();
            if (amount > 0) {
                result.push({ method: method, amount: amount, reference: reference });
            }
        });
        return result;
    }

    // ── Totals ─────────────────────────────────────────────────────────────
    function getCartTotal() {
        var t = 0;
        $.each(cart, function (i, item) {
            t += item.qty * item.price;
        });
        return t;
    }

    function getAdjustmentsNet(cartSubtotal) {
        var net = 0;
        $.each(adjustments, function (i, adj) {
            var amount = adj.mode === 'percent'
                ? Math.round(cartSubtotal * adj.value / 100 * 100) / 100
                : adj.value;
            if (adj.type === 'discount') {
                net -= amount;
            } else {
                net += amount;
            }
        });
        return net;
    }

    function getPaidTotal() {
        var p = 0;
        $('#pos-payments-list .pos-payment-row').each(function () {
            p += parseFloat($(this).find('.pos-payment-amount').val()) || 0;
        });
        return p;
    }

    function updateTotals() {
        var subtotal = getCartTotal();
        var adjNet   = getAdjustmentsNet(subtotal);
        var total    = Math.max(0, subtotal + adjNet);
        var paid     = getPaidTotal();
        var balance  = Math.max(0, total - paid);

        $('#pos-subtotal').text(wbiPos.currency + formatNumber(subtotal));
        $('#pos-total').text(wbiPos.currency + formatNumber(total));
        $('#pos-paid').text(wbiPos.currency + formatNumber(paid));
        $('#pos-balance').text(wbiPos.currency + formatNumber(balance));

        // Show adjustments row when there are any
        if (adjustments.length > 0) {
            var adjDisplay = adjNet >= 0
                ? '+' + wbiPos.currency + formatNumber(adjNet)
                : '-' + wbiPos.currency + formatNumber(Math.abs(adjNet));
            $('#pos-adjustments-total').text(adjDisplay).toggleClass('pos-adj-negative', adjNet < 0);
            $('.pos-adjustments-row').show();
        } else {
            $('.pos-adjustments-row').hide();
        }

        if (balance <= 0) {
            $('.pos-balance-row').addClass('zero');
        } else {
            $('.pos-balance-row').removeClass('zero');
        }

        // Enable confirm button
        var cashOk = cashSession && cashSession.status === 'open';
        var customerOk = true;
        var settings = wbiPos.settings || {};
        if (settings.requireCustomer && !customer && !isConsumerFinal) {
            customerOk = false;
        }
        $('#pos-btn-confirm').prop('disabled', cart.length === 0 || !cashOk || !customerOk);
    }

    // ── Customer Search ────────────────────────────────────────────────────
    function searchCustomers(q) {
        $.ajax({
            url: wbiPos.ajaxUrl,
            type: 'GET',
            data: {
                action: 'wbi_pos_search_customers',
                nonce: wbiPos.nonce,
                q: q
            },
            success: function (resp) {
                if (!resp.success) {
                    showCustomerDropdown([]);
                    return;
                }
                showCustomerDropdown(resp.data);
            },
            error: function () {
                showCustomerDropdown([]);
            }
        });
    }

    function showCustomerDropdown(customers) {
        var $d = $('#pos-customer-results');
        $d.empty();

        if (!customers || customers.length === 0) {
            $d.append('<div class="pos-dropdown-empty">' + wbiPos.i18n.noCustomers + '</div>');
            $d.addClass('open');
            return;
        }

        var $items = [];
        $.each(customers, function (i, c) {
            var typeLabel = c.customer_type === 'wholesale'
                ? '<span class="pos-customer-type-badge pos-badge-wholesale">' + wbiPos.i18n.wholesale + '</span>'
                : '<span class="pos-customer-type-badge pos-badge-retail">' + wbiPos.i18n.retail + '</span>';

            var metaLine = escHtml(c.email || '');
            if (c.phone) metaLine += (metaLine ? ' · ' : '') + escHtml(c.phone);
            if (c.doc_number) metaLine += ' · ' + escHtml(c.doc_number);

            var $item = $('<div class="pos-dropdown-item" tabindex="0" role="option">')
                .data('customer', c)
                .html(
                    '<div class="pos-item-no-img">👤</div>' +
                    '<div class="pos-dropdown-item-info">' +
                        '<div class="pos-dropdown-item-name">' + escHtml(c.name) + ' ' + typeLabel + '</div>' +
                        '<div class="pos-dropdown-item-meta">' + metaLine + '</div>' +
                    '</div>'
                );

            $item.on('click', function () {
                selectCustomer($(this).data('customer'));
                closeDropdown('#pos-customer-results');
            });

            $item.on('keydown', function (e) {
                if (e.key === 'Enter') {
                    selectCustomer($(this).data('customer'));
                    closeDropdown('#pos-customer-results');
                    $('#pos-customer-search').focus();
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    $(this).next().focus();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    var $prev = $(this).prev();
                    if ($prev.length) {
                        $prev.focus();
                    } else {
                        $('#pos-customer-search').focus();
                    }
                } else if (e.key === 'Escape') {
                    closeDropdown('#pos-customer-results');
                    $('#pos-customer-search').focus();
                }
            });

            $items.push($item);
            $d.append($item);
        });

        // Keyboard nav from input: ArrowDown focuses first result
        $('#pos-customer-search').off('keydown.nav').on('keydown.nav', function (e) {
            if (e.key === 'ArrowDown' && $items.length) {
                e.preventDefault();
                $items[0].focus();
            } else if (e.key === 'Escape') {
                closeDropdown('#pos-customer-results');
            }
        });

        $d.addClass('open');
    }

    function selectCustomer(c) {
        customer = c;
        isConsumerFinal = false;
        $('#pos-customer-search').val('');

        var typeBadge = c.customer_type === 'wholesale'
            ? '<span class="pos-customer-type-badge pos-badge-wholesale">' + wbiPos.i18n.wholesale + '</span>'
            : '<span class="pos-customer-type-badge pos-badge-retail">' + wbiPos.i18n.retail + '</span>';

        var info = escHtml(c.name) + ' ' + typeBadge;
        if (c.email) info += '<br><small>' + escHtml(c.email) + '</small>';
        if (c.phone) info += '<small> · ' + escHtml(c.phone) + '</small>';

        $('#pos-customer-selected')
            .html(info + '<span class="pos-clear-customer" title="Quitar">✕</span>')
            .show();
        $('#pos-customer-selected .pos-clear-customer').on('click', clearCustomer);

        // Show wholesale notice
        if (c.customer_type === 'wholesale' && wbiPos.i18n.wholesalePrices) {
            showResultPanel('success', wbiPos.i18n.wholesalePrices);
            setTimeout(function () { $('#pos-result-panel').hide(); }, 2500);
        }

        updateTotals();
        saveDraft();
    }

    function clearCustomer() {
        customer = null;
        isConsumerFinal = false;
        $('#pos-customer-selected').hide().empty();
        updateTotals();
        saveDraft();
    }

    // ── Create Order ───────────────────────────────────────────────────────
    function createOrder() {
        if (!cashSession || cashSession.status !== 'open') {
            showResultPanel('error', '⚠️ ' + wbiPos.i18n.noCashToConfirm);
            return;
        }

        var settings = wbiPos.settings || {};
        if (settings.requireCustomer && !customer && !isConsumerFinal) {
            showResultPanel('error', '⚠️ ' + wbiPos.i18n.customerRequired);
            return;
        }

        var $btn = $('#pos-btn-confirm');
        $btn.prop('disabled', true).html('<span class="pos-spinner"></span> Procesando…');

        var payload = {
            items:              cart,
            payments:           collectPayments(),
            customer_id:        customer && !customer.is_guest ? (customer.id || 0) : 0,
            customer_type:      customer ? (customer.customer_type || '') : '',
            is_guest:           customer && customer.is_guest ? 1 : 0,
            is_consumer_final:  isConsumerFinal ? 1 : 0,
            guest_data:         customer && customer.is_guest && customer.guest_data
                                    ? JSON.stringify(customer.guest_data) : '',
            adjustments:        adjustments,
            note:               $('#pos-order-note').val().trim(),
            seller_user_id:     activeSeller ? activeSeller.id : 0,
            cash_session_id:    cashSession ? cashSession.session_id : 0
        };

        $.ajax({
            url: wbiPos.ajaxUrl,
            type: 'POST',
            data: $.extend({ action: 'wbi_pos_create_order', nonce: wbiPos.nonce }, flattenPayload(payload)),
            success: function (resp) {
                if (!resp.success) {
                    showResultPanel('error', '❌ ' + escHtml(resp.data.message || wbiPos.i18n.orderError));
                    $btn.prop('disabled', false).text('✅ ' + wbiPos.i18n.confirmOrder);
                    return;
                }
                clearDraft();
                showOrderSuccess(resp.data);
            },
            error: function () {
                showResultPanel('error', '❌ ' + wbiPos.i18n.orderError);
                $btn.prop('disabled', false).text('✅ ' + wbiPos.i18n.confirmOrder);
            }
        });
    }

    /**
     * Flatten nested payload into form-data-compatible object.
     */
    function flattenPayload(payload) {
        var flat = {};
        flat.customer_id      = payload.customer_id;
        flat.customer_type    = payload.customer_type || '';
        flat.is_guest         = payload.is_guest || 0;
        flat.is_consumer_final = payload.is_consumer_final || 0;
        flat.guest_data       = payload.guest_data || '';
        flat.note             = payload.note;
        flat.seller_user_id   = payload.seller_user_id || 0;
        flat.cash_session_id  = payload.cash_session_id || 0;

        $.each(payload.items, function (i, item) {
            flat['items[' + i + '][id]']    = item.id;
            flat['items[' + i + '][name]']  = item.name;
            flat['items[' + i + '][qty]']   = item.qty;
            flat['items[' + i + '][price]'] = item.price;
        });

        $.each(payload.payments, function (i, p) {
            flat['payments[' + i + '][method]']    = p.method;
            flat['payments[' + i + '][amount]']    = p.amount;
            flat['payments[' + i + '][reference]'] = p.reference;
        });

        $.each(payload.adjustments, function (i, adj) {
            flat['adjustments[' + i + '][type]']   = adj.type;
            flat['adjustments[' + i + '][mode]']   = adj.mode;
            flat['adjustments[' + i + '][value]']  = adj.value;
            flat['adjustments[' + i + '][reason]'] = adj.reason || '';
        });

        return flat;
    }

    function showOrderSuccess(data) {
        var orderId   = data.order_id;
        var orderUrl  = data.order_url;
        var balance   = parseFloat(data.balance_due) || 0;

        var html = '<strong>✅ ' + wbiPos.i18n.orderCreated + '</strong><br>' +
            'Pedido #' + parseInt(orderId, 10) + ' — ' +
            'Total: ' + wbiPos.currency + formatNumber(data.total) + ' — ' +
            'Pagado: ' + wbiPos.currency + formatNumber(data.paid_total);

        if (balance > 0) {
            html += '<br>⚠️ Saldo pendiente: ' + wbiPos.currency + formatNumber(balance) + ' (cuenta corriente)';
        }

        var actions =
            '<div class="pos-result-actions">' +
            '<a href="' + escAttr(orderUrl) + '" target="_blank" class="pos-btn pos-btn-outline pos-btn-sm">🔗 ' + wbiPos.i18n.viewOrder + '</a>' +
            '<button id="pos-btn-invoice" class="pos-btn pos-btn-success pos-btn-sm" data-order-id="' + parseInt(orderId, 10) + '">' +
            '📑 ' + wbiPos.i18n.invoiceNow + '</button>' +
            '<button id="pos-btn-after-new" class="pos-btn pos-btn-secondary pos-btn-sm">🔄 ' + wbiPos.i18n.newOrder + '</button>' +
            '</div>';

        showResultPanel('success', html + actions);

        // Invoice button
        $('#pos-btn-invoice').on('click', function () {
            tryInvoice($(this).data('order-id'));
        });

        // New order after success
        $('#pos-btn-after-new').on('click', function () {
            resetPos();
        });

        // Reset the main confirm button text but keep it disabled
        $('#pos-btn-confirm').text('✅ ' + wbiPos.i18n.confirmOrder);
    }

    // ── Try Invoice ────────────────────────────────────────────────────────
    function tryInvoice(orderId) {
        var $btn = $('#pos-btn-invoice');
        $btn.prop('disabled', true).html('<span class="pos-spinner"></span>');

        $.ajax({
            url: wbiPos.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wbi_pos_try_invoice',
                nonce: wbiPos.nonce,
                order_id: orderId
            },
            success: function (resp) {
                $btn.prop('disabled', false).text('📑 ' + wbiPos.i18n.invoiceNow);
                if (!resp.success) {
                    showResultPanel('error',
                        '<strong>⚠️ ' + wbiPos.i18n.invoiceError + '</strong><br>' +
                        escHtml(resp.data.message || '') +
                        (resp.data.order_url ? ' <a href="' + escAttr(resp.data.order_url) + '" target="_blank">Ver pedido</a>' : '')
                    );
                    return;
                }
                if (resp.data.status === 'redirect') {
                    window.open(resp.data.docs_url, '_blank');
                }
            },
            error: function () {
                $btn.prop('disabled', false).text('📑 ' + wbiPos.i18n.invoiceNow);
            }
        });
    }

    // ── Result panel ───────────────────────────────────────────────────────
    function showResultPanel(type, html) {
        $('#pos-result-panel')
            .removeClass('success error')
            .addClass(type)
            .html(html)
            .show();
    }

    // ── Sellers ────────────────────────────────────────────────────────────
    function loadSellers() {
        $.ajax({
            url: wbiPos.ajaxUrl,
            type: 'GET',
            data: { action: 'wbi_pos_get_sellers', nonce: wbiPos.nonce },
            success: function (resp) {
                if (!resp.success || !resp.data) return;
                var $sel = $('#pos-seller-select');
                $sel.find('option:not(:first)').remove();
                $.each(resp.data, function (i, seller) {
                    $sel.append('<option value="' + parseInt(seller.id, 10) + '">' + escHtml(seller.name) + '</option>');
                });

                // Auto-select if only one seller
                if (resp.data.length === 1) {
                    $sel.val(resp.data[0].id).trigger('change');
                }
            }
        });
    }

    // ── Cash status ────────────────────────────────────────────────────────
    function loadCashStatus(sellerId) {
        updateCashStatusUI('loading');
        $.ajax({
            url: wbiPos.ajaxUrl,
            type: 'GET',
            data: { action: 'wbi_pos_get_cash_status', nonce: wbiPos.nonce, seller_id: sellerId },
            success: function (resp) {
                if (!resp.success) {
                    cashSession = null;
                    updateCashStatusUI('error');
                    return;
                }
                cashSession = resp.data;
                updateCashStatusUI();
                updateTotals();
            },
            error: function () {
                cashSession = null;
                updateCashStatusUI('error');
            }
        });
    }

    function updateCashStatusUI(loading) {
        var $badge    = $('#pos-cash-status-badge');
        var $open     = $('#pos-btn-open-cash');
        var $movement = $('#pos-btn-add-movement');
        var $close    = $('#pos-btn-close-cash');

        if (loading === 'loading') {
            $badge.text(wbiPos.i18n.cashLoading).attr('class', 'pos-cash-badge');
            $open.hide();
            $movement.hide();
            $close.hide();
            return;
        }

        if (!cashSession || cashSession.status !== 'open') {
            $badge.text(wbiPos.i18n.cashClosed).attr('class', 'pos-cash-badge cash-closed');
            $open.show();
            $movement.hide();
            $close.hide();
        } else {
            var since = cashSession.opened_at ? ' (' + cashSession.opened_at.substring(11, 16) + ')' : '';
            $badge.text(wbiPos.i18n.cashOpen + since).attr('class', 'pos-cash-badge cash-open');
            $open.hide();
            $movement.show();
            $close.show();
        }
    }

    // ── Open Cash ──────────────────────────────────────────────────────────
    function submitOpenCash() {
        if (!activeSeller) {
            alert(wbiPos.i18n.selectSeller);
            return;
        }
        var $btn  = $('#pos-btn-open-cash-confirm');
        var cash  = parseFloat($('#pos-open-cash-amount').val()) || 0;
        var note  = $('#pos-open-cash-note').val().trim();

        $btn.prop('disabled', true).html('<span class="pos-spinner"></span>');

        $.ajax({
            url: wbiPos.ajaxUrl,
            type: 'POST',
            data: {
                action:       'wbi_pos_open_cash',
                nonce:        wbiPos.nonce,
                seller_id:    activeSeller.id,
                opening_cash: cash,
                note:         note
            },
            success: function (resp) {
                $btn.prop('disabled', false).text(wbiPos.i18n.openCashBtn);
                if (!resp.success) {
                    alert(resp.data.message || wbiPos.i18n.cashError);
                    return;
                }
                cashSession = {
                    status:       'open',
                    session_id:   resp.data.session_id,
                    opening_cash: resp.data.opening_cash,
                    opened_at:    resp.data.opened_at
                };
                // Reset form
                $('#pos-open-cash-amount').val('0');
                $('#pos-open-cash-note').val('');
                closeModal('pos-modal-open-cash');
                updateCashStatusUI();
                updateTotals();
            },
            error: function () {
                $btn.prop('disabled', false).text(wbiPos.i18n.openCashBtn);
                alert(wbiPos.i18n.cashError);
            }
        });
    }

    // ── Close Cash ─────────────────────────────────────────────────────────
    function loadCloseSummary(sessionId, callback) {
        // Fetch movements totals to show in the close modal
        $.ajax({
            url: wbiPos.ajaxUrl,
            type: 'GET',
            data: { action: 'wbi_pos_get_movements', nonce: wbiPos.nonce, session_id: sessionId },
            success: function (resp) {
                var totals = (resp.success && resp.data && resp.data.totals) ? resp.data.totals : null;
                renderCloseSummaryPlaceholder(totals);
                if (typeof callback === 'function') callback();
            },
            error: function () {
                renderCloseSummaryPlaceholder(null);
                if (typeof callback === 'function') callback();
            }
        });
    }

    function renderCloseSummaryPlaceholder(totals) {
        if (!cashSession) return;
        var expectedCash = totals ? totals.expected_cash : null;
        var byMethod     = totals ? (totals.by_method || {}) : {};
        var methods      = wbiPos.i18n.methods || {};

        var rows = '';
        $.each(byMethod, function (method, amount) {
            var label = methods[method] || method;
            rows += '<tr><td>' + escHtml(label) + '</td><td>' + wbiPos.currency + formatNumber(amount) + '</td></tr>';
        });

        var html =
            '<div class="pos-close-summary">' +
            '<p><strong>' + wbiPos.i18n.openedAt + ':</strong> ' + escHtml(cashSession.opened_at || '—') + '</p>' +
            '<p><strong>' + wbiPos.i18n.cashIn + ':</strong> ' + wbiPos.currency + formatNumber(cashSession.opening_cash || 0) + '</p>';

        if (rows) {
            html += '<table class="pos-summary-table" style="margin:8px 0;">' + rows + '</table>';
        }

        if (expectedCash !== null) {
            html += '<p><strong>' + (wbiPos.i18n.expectedCash || 'Efectivo esperado') + ':</strong> <strong>' + wbiPos.currency + formatNumber(expectedCash) + '</strong></p>';
        }

        html += '</div>';
        $('#pos-close-cash-summary').html(html);
    }

    function submitCloseCash() {
        if (!cashSession || !cashSession.session_id) return;
        var $btn  = $('#pos-btn-close-cash-confirm');
        var cash  = parseFloat($('#pos-close-cash-amount').val()) || 0;
        var note  = $('#pos-close-cash-note').val().trim();

        $btn.prop('disabled', true).html('<span class="pos-spinner"></span>');

        $.ajax({
            url: wbiPos.ajaxUrl,
            type: 'POST',
            data: {
                action:       'wbi_pos_close_cash',
                nonce:        wbiPos.nonce,
                session_id:   cashSession.session_id,
                closing_cash: cash,
                note:         note
            },
            success: function (resp) {
                $btn.prop('disabled', false).text(wbiPos.i18n.closeCashBtn);
                if (!resp.success) {
                    alert(resp.data.message || wbiPos.i18n.cashError);
                    return;
                }
                // Show final summary
                renderFinalSummary(resp.data);
                cashSession = { status: 'closed' };
                $('#pos-close-cash-amount').val('0');
                $('#pos-close-cash-note').val('');
                closeModal('pos-modal-close-cash');
                updateCashStatusUI();
                updateTotals();
            },
            error: function () {
                $btn.prop('disabled', false).text(wbiPos.i18n.closeCashBtn);
                alert(wbiPos.i18n.cashError);
            }
        });
    }

    function renderFinalSummary(data) {
        var methods     = wbiPos.i18n.methods || {};
        var movTotals   = data.mov_totals || {};
        var byMethod    = movTotals.by_method || {};
        var summary     = data.summary || {};

        var rows = '';
        $.each(byMethod, function (method, amount) {
            var label = methods[method] || method;
            rows += '<tr><td>' + escHtml(label) + '</td><td>' + wbiPos.currency + formatNumber(amount) + '</td></tr>';
        });

        var expectedCash = parseFloat(data.expected_cash || movTotals.expected_cash || 0);

        var html =
            '<div class="pos-close-summary-final">' +
            '<h3>📊 ' + wbiPos.i18n.closeSummaryTitle + '</h3>' +
            '<table class="pos-summary-table">' +
            '<tr><td><strong>' + wbiPos.i18n.orderCount + '</strong></td><td>' + parseInt(summary.order_count || 0, 10) + '</td></tr>' +
            '<tr><td><strong>' + wbiPos.i18n.totalSold + '</strong></td><td>' + wbiPos.currency + formatNumber(summary.total_sold || 0) + '</td></tr>' +
            '<tr><td><strong>' + wbiPos.i18n.totalPaid + '</strong></td><td>' + wbiPos.currency + formatNumber(summary.total_paid || 0) + '</td></tr>' +
            (parseFloat(summary.total_balance) > 0 ? '<tr><td><strong>' + wbiPos.i18n.totalBalance + '</strong></td><td>' + wbiPos.currency + formatNumber(summary.total_balance) + '</td></tr>' : '') +
            rows +
            '<tr><td><strong>' + wbiPos.i18n.cashIn + '</strong></td><td>' + wbiPos.currency + formatNumber(data.opening_cash || 0) + '</td></tr>' +
            '<tr><td><strong>' + (wbiPos.i18n.expectedCash || 'Efectivo esperado') + '</strong></td><td>' + wbiPos.currency + formatNumber(expectedCash) + '</td></tr>' +
            '<tr><td><strong>' + wbiPos.i18n.closingCash + '</strong></td><td>' + wbiPos.currency + formatNumber(data.closing_cash || 0) + '</td></tr>' +
            '<tr class="' + (parseFloat(data.difference || 0) < 0 ? 'pos-diff-neg' : 'pos-diff-pos') + '"><td><strong>' + wbiPos.i18n.difference + '</strong></td><td>' + wbiPos.currency + formatNumber(data.difference || 0) + '</td></tr>' +
            '</table>' +
            '</div>';

        showResultPanel('success', html);
    }

    // ── Add Manual Movement ────────────────────────────────────────────────
    function submitAddMovement() {
        if (!cashSession || cashSession.status !== 'open') {
            alert(wbiPos.i18n.noCashForMovement);
            return;
        }

        var $btn      = $('#pos-btn-movement-confirm');
        var type      = $('#pos-movement-type').val();
        var method    = $('#pos-movement-method').val();
        var amount    = parseFloat($('#pos-movement-amount').val()) || 0;
        var reference = $('#pos-movement-reference').val().trim();
        var notes     = $('#pos-movement-notes').val().trim();

        if (amount <= 0) {
            alert(wbiPos.i18n.movementError || 'El monto debe ser mayor a cero.');
            return;
        }

        $btn.prop('disabled', true).html('<span class="pos-spinner"></span>');

        $.ajax({
            url: wbiPos.ajaxUrl,
            type: 'POST',
            data: {
                action:     'wbi_pos_add_movement',
                nonce:      wbiPos.nonce,
                session_id: cashSession.session_id,
                type:       type,
                method:     method,
                amount:     amount,
                reference:  reference,
                notes:      notes
            },
            success: function (resp) {
                $btn.prop('disabled', false).text(wbiPos.i18n.movementConfirm || 'Registrar');
                if (!resp.success) {
                    alert(resp.data.message || wbiPos.i18n.movementError);
                    return;
                }
                closeModal('pos-modal-movement');
                showResultPanel('success', '✅ ' + (wbiPos.i18n.movementOk || 'Movimiento registrado correctamente.'));
            },
            error: function () {
                $btn.prop('disabled', false).text(wbiPos.i18n.movementConfirm || 'Registrar');
                alert(wbiPos.i18n.movementError || 'Error al registrar el movimiento.');
            }
        });
    }

    // ── Modal helpers ──────────────────────────────────────────────────────
    function openModal(id) {
        $('#' + id).show();
        setTimeout(function () {
            $('#' + id).find('input[type="number"], input[type="text"], textarea').first().focus();
        }, 50);
    }

    function closeModal(id) {
        $('#' + id).hide();
    }

    // ── Reset ──────────────────────────────────────────────────────────────
    function resetPos() {
        cart           = [];
        payments       = [];
        adjustments    = [];
        customer       = null;
        isConsumerFinal = false;
        paymentIdx     = 0;

        renderCart();
        renderAdjustmentsList();
        $('#pos-payments-list').empty();
        $('#pos-customer-search').val('');
        $('#pos-customer-selected').hide().empty();
        $('#pos-order-note').val('');
        $('#pos-product-search').val('').focus();
        $('#pos-result-panel').hide().empty().removeClass('success error');
        updateTotals();
        clearDraft();
    }

    // ── Settings-driven UI ─────────────────────────────────────────────────
    function initSettingsUI() {
        var s = wbiPos.settings || {};
        // Show/hide "new customer" button
        if (s.allowQuickCreate) {
            $('#pos-btn-new-customer').show();
        }
        // Show/hide adjustments panel
        if (s.enableAdjustments) {
            $('#pos-adjustments-wrap').show();
            // Hide adjustment types that are disabled
            if (!s.enableDiscount)   $('#pos-adj-type option[value="discount"]').remove();
            if (!s.enableSurcharge)  $('#pos-adj-type option[value="surcharge"]').remove();
            if (!s.enableShipping)   $('#pos-adj-type option[value="shipping"]').remove();
            if (!s.enableManualTax)  $('#pos-adj-type option[value="manual_tax"]').remove();
        }
        updateAdjReasonVisibility();
    }

    function updateAdjReasonVisibility() {
        var s    = wbiPos.settings || {};
        var type = $('#pos-adj-type').val();
        if (type === 'discount' || !s.requireDiscountReason) {
            // Show reason for discounts always; show for all types when not required
            $('#pos-adj-reason-group').show();
        } else {
            // Non-discount type and requireDiscountReason is on: hide reason
            $('#pos-adj-reason-group').hide();
        }
    }

    // ── Quick Create Customer ──────────────────────────────────────────────
    function openCreateCustomerModal() {
        // Reset form
        $('#pos-cc-first-name, #pos-cc-last-name, #pos-cc-phone, #pos-cc-email').val('');
        $('#pos-cc-document-type, #pos-cc-document-number, #pos-cc-company-name').val('');
        $('#pos-cc-address-1, #pos-cc-city, #pos-cc-postcode').val('');
        $('#pos-cc-customer-type').val('');
        $('#pos-cc-error').hide().empty();
        openModal('pos-modal-create-customer');
    }

    function submitCreateCustomer() {
        var firstName    = $('#pos-cc-first-name').val().trim();
        var lastName     = $('#pos-cc-last-name').val().trim();
        var phone        = $('#pos-cc-phone').val().trim();
        var customerType = $('#pos-cc-customer-type').val();
        var email        = $('#pos-cc-email').val().trim();

        // Client-side validation
        if (!firstName) { showCCError(wbiPos.i18n.firstNameRequired); return; }
        if (!lastName)  { showCCError(wbiPos.i18n.lastNameRequired);  return; }
        if (!phone)     { showCCError(wbiPos.i18n.phoneRequired);      return; }
        if (!customerType) { showCCError(wbiPos.i18n.customerTypeRequired); return; }
        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showCCError(wbiPos.i18n.emailInvalid);
            return;
        }

        var $btn = $('#pos-btn-cc-confirm');
        $btn.prop('disabled', true).html('<span class="pos-spinner"></span>');

        $.ajax({
            url: wbiPos.ajaxUrl,
            type: 'POST',
            data: {
                action:          'wbi_pos_create_customer',
                nonce:           wbiPos.nonce,
                first_name:      firstName,
                last_name:       lastName,
                phone:           phone,
                customer_type:   customerType,
                email:           email,
                document_type:   $('#pos-cc-document-type').val(),
                document_number: $('#pos-cc-document-number').val().trim(),
                company_name:    $('#pos-cc-company-name').val().trim(),
                address_1:       $('#pos-cc-address-1').val().trim(),
                city:            $('#pos-cc-city').val().trim(),
                postcode:        $('#pos-cc-postcode').val().trim()
            },
            success: function (resp) {
                $btn.prop('disabled', false).text('👤 ' + wbiPos.i18n.createCustomer);
                if (!resp.success) {
                    // If existing customer was found, offer to select them
                    if (resp.data && resp.data.existing) {
                        if (window.confirm(resp.data.message + '\n¿Deseas seleccionar ese cliente?')) {
                            selectCustomer({
                                id:            resp.data.existing.id,
                                name:          resp.data.existing.name,
                                email:         resp.data.existing.email,
                                customer_type: customerType,
                                is_guest:      false,
                                guest_data:    null
                            });
                            closeModal('pos-modal-create-customer');
                        }
                        return;
                    }
                    showCCError(resp.data.message || wbiPos.i18n.customerError);
                    return;
                }
                var d = resp.data;
                closeModal('pos-modal-create-customer');
                selectCustomer({
                    id:            d.customer_id || 0,
                    name:          d.name,
                    email:         d.email || '',
                    phone:         d.phone || '',
                    customer_type: d.customer_type,
                    is_guest:      d.is_guest,
                    guest_data:    d.guest_data || null
                });
                showResultPanel('success', '✅ ' + wbiPos.i18n.customerCreated);
                setTimeout(function () { $('#pos-result-panel').hide(); }, 3000);
            },
            error: function () {
                $btn.prop('disabled', false).text('👤 ' + wbiPos.i18n.createCustomer);
                showCCError(wbiPos.i18n.customerError);
            }
        });
    }

    function showCCError(msg) {
        $('#pos-cc-error').text(msg).show();
    }

    // ── Adjustments ────────────────────────────────────────────────────────
    function openAdjustmentModal() {
        $('#pos-adj-value').val('');
        $('#pos-adj-reason').val('');
        updateAdjReasonVisibility();
        openModal('pos-modal-adjustment');
    }

    function submitAddAdjustment() {
        var s      = wbiPos.settings || {};
        var type   = $('#pos-adj-type').val();
        var mode   = $('#pos-adj-mode').val();
        var value  = parseFloat($('#pos-adj-value').val()) || 0;
        var reason = $('#pos-adj-reason').val().trim();

        if (value <= 0) {
            alert(wbiPos.i18n.adjustmentValueRequired);
            return;
        }

        // Validate discount reason
        if (type === 'discount' && s.requireDiscountReason && !reason) {
            alert(wbiPos.i18n.adjustmentReasonRequired);
            return;
        }

        // Validate max discount
        if (type === 'discount' && mode === 'percent' && s.maxDiscountPct && value > s.maxDiscountPct) {
            alert((wbiPos.i18n.adjustmentMaxDiscount || 'El descuento supera el máximo permitido (%s%).').replace('%s', s.maxDiscountPct));
            return;
        }
        if (type === 'discount' && mode === 'fixed' && s.maxDiscountPct && s.maxDiscountPct < 100) {
            var subtotal = getCartTotal();
            if (subtotal > 0 && (value / subtotal * 100) > s.maxDiscountPct) {
                alert((wbiPos.i18n.adjustmentMaxDiscount || 'El descuento supera el máximo permitido (%s%).').replace('%s', s.maxDiscountPct));
                return;
            }
        }

        adjustments.push({ type: type, mode: mode, value: value, reason: reason });
        renderAdjustmentsList();
        closeModal('pos-modal-adjustment');
        updateTotals();
        saveDraft();
    }

    function renderAdjustmentsList() {
        var $list = $('#pos-adjustments-list');
        $list.empty();

        if (!adjustments.length) {
            return;
        }

        var subtotal = getCartTotal();
        var typeLabels = wbiPos.i18n.adjustmentTypes || {};

        $.each(adjustments, function (i, adj) {
            var amount = adj.mode === 'percent'
                ? Math.round(subtotal * adj.value / 100 * 100) / 100
                : adj.value;
            var isReduction = adj.type === 'discount';
            var sign = isReduction ? '-' : '+';
            var label = typeLabels[adj.type] || adj.type;
            if (adj.reason) label += ' (' + escHtml(adj.reason) + ')';

            var $row = $('<div class="pos-adjustment-row" data-idx="' + i + '">').html(
                '<span class="pos-adj-label">' + label + '</span>' +
                '<span class="pos-adj-amount ' + (isReduction ? 'pos-adj-negative' : '') + '">' +
                    sign + wbiPos.currency + formatNumber(amount) +
                '</span>' +
                '<button class="pos-btn-remove pos-btn-remove-adj" data-idx="' + i + '" title="Quitar">✕</button>'
            );

            $list.append($row);
        });

        $list.find('.pos-btn-remove-adj').on('click', function () {
            adjustments.splice(parseInt($(this).data('idx'), 10), 1);
            renderAdjustmentsList();
            updateTotals();
            saveDraft();
        });
    }

    // ── Draft (localStorage) ───────────────────────────────────────────────
    function saveDraft() {
        try {
            localStorage.setItem(DRAFT_KEY, JSON.stringify({
                cart:           cart,
                customer:       customer,
                isConsumerFinal: isConsumerFinal,
                adjustments:    adjustments,
                payments:       collectPayments()
            }));
        } catch (e) {}
    }

    function clearDraft() {
        try {
            localStorage.removeItem(DRAFT_KEY);
        } catch (e) {}
    }

    function maybeRecoverDraft() {
        try {
            var raw = localStorage.getItem(DRAFT_KEY);
            if (!raw) return;
            var draft = JSON.parse(raw);
            if (!draft || !draft.cart || draft.cart.length === 0) {
                clearDraft();
                return;
            }
            if (window.confirm(wbiPos.i18n.recoverDraft)) {
                cart            = draft.cart || [];
                customer        = draft.customer || null;
                isConsumerFinal = draft.isConsumerFinal || false;
                adjustments     = draft.adjustments || [];

                renderCart();
                renderAdjustmentsList();

                if (customer) {
                    selectCustomer(customer);
                } else if (isConsumerFinal) {
                    $('#pos-customer-selected')
                        .html('<span class="pos-customer-type-badge pos-badge-consumer">Consumidor Final</span>' +
                              '<span class="pos-clear-customer" title="Quitar">✕</span>')
                        .show();
                    $('#pos-customer-selected .pos-clear-customer').on('click', clearCustomer);
                }

                if (draft.payments && draft.payments.length) {
                    $.each(draft.payments, function (i, p) {
                        addPaymentRow(p.method, p.amount, p.reference);
                    });
                }

                updateTotals();
            } else {
                clearDraft();
            }
        } catch (e) {
            clearDraft();
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────
    function closeDropdown(selector) {
        $(selector).removeClass('open').empty();
    }

    function getCartPriceDisplayValue(idx, fallbackPrice) {
        if (Object.prototype.hasOwnProperty.call(priceDraftsByIdx, idx)) {
            return String(priceDraftsByIdx[idx] || '');
        }
        return formatNumber(fallbackPrice);
    }

    function resetCartPriceInput($input, keepError) {
        var idx = parseInt($input.data('idx'), 10);
        if (!isFinite(idx) || !cart[idx]) return;
        delete priceDraftsByIdx[idx];
        $input.val(formatNumber(cart[idx].price));
        if (!keepError) {
            clearPriceInputError($input);
        }
        updateCartRowSubtotal(idx);
    }

    function commitCartPriceInput($input) {
        var idx = parseInt($input.data('idx'), 10);
        if (!isFinite(idx) || !cart[idx]) return;

        var raw = Object.prototype.hasOwnProperty.call(priceDraftsByIdx, idx)
            ? priceDraftsByIdx[idx]
            : $input.val();
        var parsed = parseCommittedPrice(raw);

        if (!parsed.valid) {
            setPriceInputError($input, wbiPos.i18n.priceInvalid || 'Precio inválido.');
            resetCartPriceInput($input, true);
            return;
        }

        clearPriceInputError($input);
        delete priceDraftsByIdx[idx];
        cart[idx].price = parsed.value;
        $input.val(formatNumber(parsed.value));
        updateCartRowSubtotal(idx);
        updateTotals();
        saveDraft();
    }

    function parseCommittedPrice(rawValue) {
        var raw = String(rawValue || '').trim();
        if (!raw) {
            return { valid: false };
        }

        raw = raw.replace(/\s+/g, '').replace(/[^0-9.,]/g, '');
        if (!raw) {
            return { valid: false };
        }

        var decimalSeparator = (wbiPos && wbiPos.decimalSeparator === ',') ? ',' : '.';
        var thousandSeparator = decimalSeparator === ',' ? '.' : ',';
        var escapedThousand = thousandSeparator === '.' ? '\\.' : thousandSeparator;
        var hasDecimalSep = raw.indexOf(decimalSeparator) !== -1;
        var hasThousandSep = raw.indexOf(thousandSeparator) !== -1;
        var normalized;

        if (hasDecimalSep) {
            var decimalParts = raw.split(decimalSeparator);
            var decimalTail = decimalParts.pop().replace(new RegExp('[^0-9]', 'g'), '');
            var decimalHead = decimalParts.join('').replace(new RegExp('[' + escapedThousand + ']', 'g'), '');
            decimalHead = decimalHead.replace(new RegExp('[^0-9]', 'g'), '');
            normalized = decimalHead + (decimalTail.length ? '.' + decimalTail : '');
        } else if (hasThousandSep) {
            var parts = raw.split(thousandSeparator);
            var tail = parts.length > 1 ? String(parts[parts.length - 1] || '') : '';
            var looksLikeGroupedThousands = parts.length === 2 && parts[0].length > 0 && tail.length === 3;
            var canUseAsAltDecimal = parts.length === 2 && PRICE_DECIMALS > 0 && tail.length > 0 && tail.length <= PRICE_DECIMALS && !looksLikeGroupedThousands;
            if (canUseAsAltDecimal) {
                normalized = parts[0].replace(new RegExp('[^0-9]', 'g'), '') + '.' + tail.replace(new RegExp('[^0-9]', 'g'), '');
            } else {
                normalized = raw.replace(new RegExp('[' + escapedThousand + ']', 'g'), '').replace(new RegExp('[^0-9]', 'g'), '');
            }
        } else {
            normalized = raw.replace(new RegExp('[^0-9]', 'g'), '');
        }

        if (!normalized || normalized === '.') {
            return { valid: false };
        }

        var value = parseFloat(normalized);
        if (!isFinite(value) || value < 0) {
            return { valid: false };
        }

        var factor = Math.pow(10, PRICE_DECIMALS);
        value = Math.round(value * factor) / factor;

        return { valid: true, value: value };
    }

    function updateCartRowSubtotal(idx) {
        var item = cart[idx];
        if (!item) return;
        $('#pos-cart-body tr[data-idx="' + idx + '"] .pos-cart-subtotal')
            .text(wbiPos.currency + formatNumber(item.qty * item.price));
    }

    function setPriceInputError($input, message) {
        var $cell = $input.closest('td');
        $input.addClass('pos-input-error').attr('aria-invalid', 'true');
        $cell.find('.pos-cart-inline-error').text(message || '').show();
    }

    function clearPriceInputError($input) {
        var $cell = $input.closest('td');
        $input.removeClass('pos-input-error').removeAttr('aria-invalid');
        $cell.find('.pos-cart-inline-error').text('').hide();
    }

    function formatNumber(n) {
        return parseFloat(n || 0).toFixed(PRICE_DECIMALS);
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function escAttr(str) {
        return escHtml(str);
    }

}(jQuery));
