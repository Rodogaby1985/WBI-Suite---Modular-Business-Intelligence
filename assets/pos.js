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
    var customer   = null;        // { id, name, email } or null = consumidor final
    var paymentIdx = 0;           // counter for unique payment row IDs
    var scannerMode = false;
    var productSearchTimer = null;
    var customerSearchTimer = null;

    // Seller / cash session state
    var activeSeller  = null;   // { id, name } — selected seller
    var cashSession   = null;   // { session_id, status, opening_cash, opened_at } or null

    var DRAFT_KEY = 'wbi_pos_draft';

    // ── Init ───────────────────────────────────────────────────────────────
    $(function () {
        bindEvents();
        loadSellers();
        maybeRecoverDraft();
        updateTotals();
    });

    // ── Event binding ──────────────────────────────────────────────────────
    function bindEvents() {
        // Product search
        $('#pos-product-search').on('input', function () {
            clearTimeout(productSearchTimer);
            var q = $(this).val().trim();
            if (q.length < 1) {
                closeDropdown('#pos-product-results');
                return;
            }
            productSearchTimer = setTimeout(function () {
                searchProducts(q);
            }, 220);
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
                var $first = $('#pos-product-results .pos-dropdown-item').first();
                if ($first.length) {
                    $first.trigger('click');
                }
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
            $('#pos-customer-search').val('');
            $('#pos-customer-selected').hide();
            closeDropdown('#pos-customer-results');
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
    function searchProducts(q) {
        $.ajax({
            url: wbiPos.ajaxUrl,
            type: 'GET',
            data: {
                action: 'wbi_pos_search_products',
                nonce: wbiPos.nonce,
                q: q
            },
            success: function (resp) {
                if (!resp.success) {
                    showProductDropdown([]);
                    return;
                }
                showProductDropdown(resp.data);
            },
            error: function () {
                showProductDropdown([]);
            }
        });
    }

    function showProductDropdown(products) {
        var $d = $('#pos-product-results');
        $d.empty();

        if (!products || products.length === 0) {
            $d.append('<div class="pos-dropdown-empty">' + wbiPos.i18n.noProducts + '</div>');
            $d.addClass('open');
            return;
        }

        $.each(products, function (i, p) {
            var imgHtml = p.image
                ? '<img src="' + escAttr(p.image) + '" alt="">'
                : '<div class="pos-item-no-img">📦</div>';

            var stockHtml = p.stock !== null
                ? ' &bull; Stock: ' + parseInt(p.stock, 10)
                : '';

            var $item = $('<div class="pos-dropdown-item" tabindex="0">')
                .data('product', p)
                .html(
                    imgHtml +
                    '<div class="pos-dropdown-item-info">' +
                        '<div class="pos-dropdown-item-name">' + escHtml(p.name) + '</div>' +
                        '<div class="pos-dropdown-item-meta">SKU: ' + escHtml(p.sku || '—') + stockHtml + '</div>' +
                    '</div>' +
                    '<span class="pos-dropdown-item-price">' + wbiPos.currency + formatNumber(p.price) + '</span>'
                );

            $item.on('click keydown', function (e) {
                if (e.type === 'keydown' && e.key !== 'Enter') return;
                addToCart($(this).data('product'));
                $('#pos-product-search').val('').focus();
                closeDropdown('#pos-product-results');
            });

            $d.append($item);
        });

        $d.addClass('open');
    }

    // ── Cart ───────────────────────────────────────────────────────────────
    function addToCart(product) {
        var existing = null;
        $.each(cart, function (i, item) {
            if (item.id === product.id) {
                existing = item;
                return false;
            }
        });

        if (existing) {
            existing.qty += 1;
        } else {
            cart.push({
                id:    product.id,
                name:  product.name,
                sku:   product.sku,
                price: product.price,
                qty:   1,
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

            $row.html(
                '<td>' + escHtml(item.name) + (item.sku ? '<br><small style="color:#888">SKU: ' + escHtml(item.sku) + '</small>' : '') + '</td>' +
                '<td><input type="number" class="pos-cart-qty-input" min="1" step="1" value="' + parseInt(item.qty, 10) + '" data-idx="' + idx + '"></td>' +
                '<td><input type="number" class="pos-cart-price-input" min="0" step="0.01" value="' + item.price.toFixed(2) + '" data-idx="' + idx + '"></td>' +
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
        $tbody.find('.pos-cart-price-input').off('change input').on('change input', function () {
            var idx = parseInt($(this).data('idx'), 10);
            var val = Math.max(0, parseFloat($(this).val()) || 0);
            cart[idx].price = val;
            renderCart();
            updateTotals();
            saveDraft();
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

    function getPaidTotal() {
        var p = 0;
        $('#pos-payments-list .pos-payment-row').each(function () {
            p += parseFloat($(this).find('.pos-payment-amount').val()) || 0;
        });
        return p;
    }

    function updateTotals() {
        var total   = getCartTotal();
        var paid    = getPaidTotal();
        var balance = Math.max(0, total - paid);

        $('#pos-total').text(wbiPos.currency + formatNumber(total));
        $('#pos-paid').text(wbiPos.currency + formatNumber(paid));
        $('#pos-balance').text(wbiPos.currency + formatNumber(balance));

        if (balance <= 0) {
            $('.pos-balance-row').addClass('zero');
        } else {
            $('.pos-balance-row').removeClass('zero');
        }

        // Enable confirm button only if cart has items AND cash session is open
        var cashOk = cashSession && cashSession.status === 'open';
        $('#pos-btn-confirm').prop('disabled', cart.length === 0 || !cashOk);
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

        $.each(customers, function (i, c) {
            var $item = $('<div class="pos-dropdown-item" tabindex="0">')
                .data('customer', c)
                .html(
                    '<div class="pos-item-no-img">👤</div>' +
                    '<div class="pos-dropdown-item-info">' +
                        '<div class="pos-dropdown-item-name">' + escHtml(c.name) + '</div>' +
                        '<div class="pos-dropdown-item-meta">' + escHtml(c.email) + '</div>' +
                    '</div>'
                );

            $item.on('click keydown', function (e) {
                if (e.type === 'keydown' && e.key !== 'Enter') return;
                selectCustomer($(this).data('customer'));
                closeDropdown('#pos-customer-results');
            });

            $d.append($item);
        });

        $d.addClass('open');
    }

    function selectCustomer(c) {
        customer = c;
        $('#pos-customer-search').val('');
        $('#pos-customer-selected')
            .html(
                '<strong>' + escHtml(c.name) + '</strong> — ' + escHtml(c.email) +
                '<span class="pos-clear-customer" title="Quitar">✕</span>'
            )
            .show();
        $('#pos-customer-selected .pos-clear-customer').on('click', function () {
            customer = null;
            $('#pos-customer-selected').hide().empty();
        });
        saveDraft();
    }

    // ── Create Order ───────────────────────────────────────────────────────
    function createOrder() {
        if (!cashSession || cashSession.status !== 'open') {
            showResultPanel('error', '⚠️ ' + wbiPos.i18n.noCashToConfirm);
            return;
        }

        var $btn = $('#pos-btn-confirm');
        $btn.prop('disabled', true).html('<span class="pos-spinner"></span> Procesando…');

        var payload = {
            items:            cart,
            payments:         collectPayments(),
            customer_id:      customer ? customer.id : 0,
            note:             $('#pos-order-note').val().trim(),
            seller_user_id:   activeSeller ? activeSeller.id : 0,
            cash_session_id:  cashSession ? cashSession.session_id : 0
        };

        // Use a standard form-encoded POST so WordPress handles it properly
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
     * items[0][id], items[0][qty], payments[0][method], etc.
     */
    function flattenPayload(payload) {
        var flat = {};
        flat.customer_id      = payload.customer_id;
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
        var $badge = $('#pos-cash-status-badge');
        var $open  = $('#pos-btn-open-cash');
        var $close = $('#pos-btn-close-cash');

        if (loading === 'loading') {
            $badge.text(wbiPos.i18n.cashLoading).attr('class', 'pos-cash-badge');
            $open.hide();
            $close.hide();
            return;
        }

        if (!cashSession || cashSession.status !== 'open') {
            $badge.text(wbiPos.i18n.cashClosed).attr('class', 'pos-cash-badge cash-closed');
            $open.show();
            $close.hide();
        } else {
            var since = cashSession.opened_at ? ' (' + cashSession.opened_at.substring(11, 16) + ')' : '';
            $badge.text(wbiPos.i18n.cashOpen + since).attr('class', 'pos-cash-badge cash-open');
            $open.hide();
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
        $.ajax({
            url: wbiPos.ajaxUrl,
            type: 'GET',
            data: { action: 'wbi_pos_get_cash_status', nonce: wbiPos.nonce, seller_id: activeSeller ? activeSeller.id : 0 },
            success: function () {
                // Just render current data we have + allow entry
                renderCloseSummaryPlaceholder();
                if (typeof callback === 'function') callback();
            },
            error: function () {
                renderCloseSummaryPlaceholder();
                if (typeof callback === 'function') callback();
            }
        });
    }

    function renderCloseSummaryPlaceholder() {
        if (!cashSession) return;
        var html =
            '<div class="pos-close-summary">' +
            '<p><strong>' + wbiPos.i18n.openedAt + ':</strong> ' + escHtml(cashSession.opened_at || '—') + '</p>' +
            '<p><strong>' + wbiPos.i18n.cashIn + ':</strong> ' + wbiPos.currency + formatNumber(cashSession.opening_cash || 0) + '</p>' +
            '</div>';
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
        var methods  = wbiPos.i18n.methods || {};
        var summary  = data.summary || {};
        var byMethod = summary.totals_by_method || {};

        var rows = '';
        $.each(byMethod, function (method, amount) {
            var label = methods[method] || method;
            rows += '<tr><td>' + escHtml(label) + '</td><td>' + wbiPos.currency + formatNumber(amount) + '</td></tr>';
        });

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
            '<tr><td><strong>' + wbiPos.i18n.cashCollected + '</strong></td><td>' + wbiPos.currency + formatNumber(byMethod['cash'] || 0) + '</td></tr>' +
            '<tr><td><strong>' + wbiPos.i18n.closingCash + '</strong></td><td>' + wbiPos.currency + formatNumber(data.closing_cash || 0) + '</td></tr>' +
            '<tr class="' + (parseFloat(data.difference || 0) < 0 ? 'pos-diff-neg' : 'pos-diff-pos') + '"><td><strong>' + wbiPos.i18n.difference + '</strong></td><td>' + wbiPos.currency + formatNumber(data.difference || 0) + '</td></tr>' +
            '</table>' +
            '</div>';

        showResultPanel('success', html);
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
        cart       = [];
        payments   = [];
        customer   = null;
        paymentIdx = 0;

        renderCart();
        $('#pos-payments-list').empty();
        $('#pos-customer-search').val('');
        $('#pos-customer-selected').hide().empty();
        $('#pos-order-note').val('');
        $('#pos-product-search').val('').focus();
        $('#pos-result-panel').hide().empty().removeClass('success error');
        updateTotals();
        clearDraft();
    }

    // ── Draft (localStorage) ───────────────────────────────────────────────
    function saveDraft() {
        try {
            localStorage.setItem(DRAFT_KEY, JSON.stringify({
                cart: cart,
                customer: customer,
                payments: collectPayments()
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
                cart     = draft.cart || [];
                customer = draft.customer || null;

                renderCart();

                if (customer) {
                    selectCustomer(customer);
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

    function formatNumber(n) {
        return parseFloat(n || 0).toFixed(2);
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
