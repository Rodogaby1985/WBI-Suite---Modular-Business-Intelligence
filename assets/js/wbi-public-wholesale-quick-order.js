/**
 * WBI Public Wholesale Quick Order — front-end controller
 *
 * Supports two variant-selector modes (configured server-side):
 *   "inline"  — chip selectors rendered directly inside each card
 *   "modal"   — lightweight modal opened on "Agregar al pedido" click
 */
(function () {
  'use strict';

  var cfg = window.WBIPublicQuickOrder || {};
  var i18n = cfg.i18n || {};
  var mode = cfg.variantSelectorMode || 'modal';
  var globalAddEnabled = !!cfg.globalAddEnabled;
  var initialQtyZero = !!cfg.initialQtyZero;
  var forceReloadOnFragmentFail = !!cfg.forceReloadOnFragmentFail;
  var globalBarSpaceClass = 'wbi-pwoq-has-global-bar-space';

  // -------------------------------------------------------------------------
  // Utilities
  // -------------------------------------------------------------------------

  function parseProductData(container) {
    try {
      return JSON.parse(container.getAttribute('data-product') || '{}');
    } catch (e) {
      return {};
    }
  }

  function showToast(message, isError) {
    var toast = document.querySelector('.wbi-pwoq-toast');
    if (!toast) return;
    toast.textContent = message;
    toast.hidden = false;
    toast.classList.toggle('is-error', !!isError);
    toast.classList.add('is-visible');
    clearTimeout(showToast._timer);
    showToast._timer = setTimeout(function () {
      toast.classList.remove('is-visible');
      setTimeout(function () { toast.hidden = true; }, 200);
    }, 3000);
  }

  function updateSummary(summary) {
    var el = document.querySelector('.wbi-pwoq-summary');
    if (!el || !summary) return;
    el.dataset.items = summary.items;
    el.dataset.units = summary.units;
    el.textContent = summary.label;
  }

  function setStatus(el, message, isError) {
    if (!el) return;
    el.textContent = message || '';
    el.classList.toggle('is-error', !!isError);
  }

  function getNormalizedQty(input) {
    if (!input) return initialQtyZero ? 0 : 1;
    var parsed = parseInt(input.value, 10);
    if (Number.isNaN(parsed)) return initialQtyZero ? 0 : 1;
    return parsed;
  }

  function resetQtyInput(input, variant) {
    if (!input) return;
    input.min = variant.min_qty || 1;
    input.step = variant.step_qty || 1;
    input.value = initialQtyZero ? 0 : (variant.default_qty || variant.min_qty || 1);
    // Sync stepper button states
    syncStepperButtons(input);
  }

  function setChipSelected(chip, selected) {
    if (!chip) return;
    chip.classList.toggle('is-selected', !!selected);
    chip.setAttribute('aria-pressed', selected ? 'true' : 'false');
    if (selected) {
      chip.dataset.selected = 'true';
    } else {
      delete chip.dataset.selected;
    }
  }

  function setCurrentVariant(container, variant) {
    if (!container) return;
    var variantId = variant && variant.id ? parseInt(variant.id, 10) || 0 : 0;
    container.dataset.currentVariantId = String(variantId);
  }

  function hasValidVariantSelection(data, variantId) {
    return !data.has_variations || !!variantId;
  }

  function getMissingVariationMessage() {
    return i18n.selectVariation || i18n.selectOption;
  }

  function getBatchSelections() {
    var selections = [];
    document.querySelectorAll('.wbi-pwoq').forEach(function (container) {
      var qtyInput = container.querySelector('.wbi-pwoq__qty');
      var qty = getNormalizedQty(qtyInput);
      if (qty <= 0) return;
      var data = parseProductData(container);
      var variantId = container.dataset.currentVariantId ? parseInt(container.dataset.currentVariantId, 10) || 0 : 0;
      if (data.has_variations && !variantId) return;
      selections.push({
        container: container,
        productId: data.product_id,
        variationId: variantId,
        quantity: qty
      });
    });
    return selections;
  }

  function toggleGlobalBarSpacing(enabled) {
    document.body.classList.toggle(globalBarSpaceClass, !!enabled);
  }

  // -------------------------------------------------------------------------
  // WooCommerce fragment / mini-cart refresh
  // -------------------------------------------------------------------------

  /**
   * Trigger WooCommerce fragment refresh so the header cart count and
   * mini-cart update without a page reload.
   * Fires both the standard wc_fragment_refresh and added_to_cart events
   * (the latter is expected by some Flatsome/WooCommerce integrations).
   *
   * If forceReloadOnFragmentFail is enabled, after 1.5 s we check whether
   * the header cart count has changed. If not, reload as last resort.
   */
  function triggerWooFragmentRefresh() {
    if (typeof jQuery === 'undefined') return;

    var $body = jQuery(document.body);

    // Capture current cart count before refresh (best-effort)
    var countBefore = getHeaderCartCount();

    $body.trigger('wc_fragment_refresh');
    $body.trigger('added_to_cart', [[], '', null]);

    if (forceReloadOnFragmentFail) {
      setTimeout(function () {
        var countAfter = getHeaderCartCount();
        if (countAfter === countBefore) {
          window.location.reload();
        }
      }, 1500);
    }
  }

  function getHeaderCartCount() {
    // Try common Flatsome/WooCommerce selectors for cart item count
    var selectors = [
      '.cart-count',
      '.woocommerce-mini-cart-item-count',
      '.header-cart .count',
      'a.cart-contents .count',
      '[class*="cart-count"]'
    ];
    for (var i = 0; i < selectors.length; i++) {
      var el = document.querySelector(selectors[i]);
      if (el) {
        var num = parseInt(el.textContent.replace(/\D/g, ''), 10);
        if (!Number.isNaN(num)) return num;
      }
    }
    return -1; // unknown — treat as changed so reload is not triggered
  }

  // -------------------------------------------------------------------------
  // Stepper buttons
  // -------------------------------------------------------------------------

  function syncStepperButtons(qtyInput) {
    if (!qtyInput) return;
    var min = parseFloat(qtyInput.min) || 0;
    var val = parseFloat(qtyInput.value) || 0;
    var dec = qtyInput.closest('.wbi-pwoq__stepper');
    if (!dec) return;
    var decBtn = dec.querySelector('.wbi-pwoq__stepper-dec');
    if (decBtn) decBtn.disabled = val <= min;
  }

  function bindStepper(qtyInput) {
    if (!qtyInput) return;
    var stepperEl = qtyInput.closest('.wbi-pwoq__stepper');
    if (!stepperEl) return;

    var decBtn = stepperEl.querySelector('.wbi-pwoq__stepper-dec');
    var incBtn = stepperEl.querySelector('.wbi-pwoq__stepper-inc');

    if (decBtn) {
      decBtn.addEventListener('click', function () {
        var step = parseInt(qtyInput.step, 10) || 1;
        var min  = parseInt(qtyInput.min, 10) || 0;
        var val  = parseInt(qtyInput.value, 10) || 0;
        var next = val - step;
        if (next < min) next = min;
        qtyInput.value = next;
        qtyInput.dispatchEvent(new Event('change', { bubbles: true }));
        syncStepperButtons(qtyInput);
      });
    }

    if (incBtn) {
      incBtn.addEventListener('click', function () {
        var step = parseInt(qtyInput.step, 10) || 1;
        var val  = parseInt(qtyInput.value, 10) || 0;
        qtyInput.value = val + step;
        qtyInput.dispatchEvent(new Event('change', { bubbles: true }));
        syncStepperButtons(qtyInput);
      });
    }

    // Sanitise manual input: clamp to min, not below 0
    qtyInput.addEventListener('change', function () {
      var min  = parseInt(qtyInput.min, 10) || 0;
      var val  = parseInt(qtyInput.value, 10);
      if (Number.isNaN(val) || val < 0) qtyInput.value = 0;
      else if (val < min && val !== 0) qtyInput.value = min;
      syncStepperButtons(qtyInput);
    });

    syncStepperButtons(qtyInput);
  }

  // -------------------------------------------------------------------------
  // Global bar
  // -------------------------------------------------------------------------

  function updateGlobalBar() {
    if (!globalAddEnabled) return;
    var bar = document.querySelector('.wbi-pwoq-global-bar');
    var button = bar ? bar.querySelector('.wbi-pwoq-global-bar__button') : null;
    var summaryEl = bar ? bar.querySelector('.wbi-pwoq-global-bar__summary') : null;
    var detailEl  = bar ? bar.querySelector('.wbi-pwoq-global-bar__detail')  : null;
    if (!bar || !button) return;
    var selections = getBatchSelections();
    var floatSummary = document.querySelector('.wbi-pwoq-summary');
    if (!selections.length) {
      bar.hidden = true;
      toggleGlobalBarSpacing(false);
      if (floatSummary) floatSummary.classList.remove('wbi-pwoq-summary--with-global-bar');
      button.disabled = true;
      button.textContent = i18n.globalAdd;
      if (summaryEl) summaryEl.textContent = '';
      if (detailEl)  detailEl.textContent  = '';
      return;
    }
    bar.hidden = false;
    toggleGlobalBarSpacing(true);
    if (floatSummary) floatSummary.classList.add('wbi-pwoq-summary--with-global-bar');
    button.disabled = false;
    button.textContent = i18n.globalAdd;

    // Build left-zone summary text: "N producto(s) · M unidad(es)"
    var totalProducts = selections.length;
    var totalUnits    = selections.reduce(function (sum, s) { return sum + s.quantity; }, 0);
    var tpl = totalProducts === 1 ? (i18n.counterSingular || '%1$s producto · %2$s unidad')
                                  : (i18n.counterPlural   || '%1$s productos · %2$s unidades');
    var summaryText = tpl.replace('%1$s', totalProducts).replace('%2$s', totalUnits);
    if (summaryEl) summaryEl.textContent = summaryText;

    // Middle-zone detail: list product names (up to 3, then "+N más")
    if (detailEl) {
      var names = selections.map(function (s) {
        var data = parseProductData(s.container);
        return data.product_name || '';
      }).filter(Boolean);
      var maxNames = 3;
      var detail = names.slice(0, maxNames).join(', ');
      if (names.length > maxNames) {
        detail += ' +' + (names.length - maxNames) + ' más';
      }
      detailEl.textContent = detail;
    }
  }

  function addSelectionsSequentially(selections, button) {
    var index = 0;
    var totalUnits = 0;
    var totalProducts = 0;
    var lastSummary = null;
    var anySuccess = false;

    function runNext() {
      if (index >= selections.length) {
        if (lastSummary) updateSummary(lastSummary);
        showToast(i18n.globalSuccess.replace('%1$s', totalProducts).replace('%2$s', totalUnits), false);
        selections.forEach(function (item) {
          var qtyInput = item.container.querySelector('.wbi-pwoq__qty');
          if (qtyInput) {
            qtyInput.value = 0;
            syncStepperButtons(qtyInput);
          }
          setStatus(item.container.querySelector('.wbi-pwoq__status'), '', false);
        });
        if (button) {
          button.disabled = false;
          button.textContent = i18n.globalAdd;
        }
        updateGlobalBar();
        // Refresh WooCommerce header/mini-cart fragments
        if (anySuccess) {
          triggerWooFragmentRefresh();
        }
        return;
      }

      var item = selections[index++];
      doAddToCart(
        { productId: item.productId, variationId: item.variationId, quantity: item.quantity },
        function (data) {
          totalUnits += item.quantity;
          totalProducts += 1;
          anySuccess = true;
          lastSummary = data.summary;
          setStatus(item.container.querySelector('.wbi-pwoq__status'), data.message, false);
          runNext();
        },
        function (msg) {
          setStatus(item.container.querySelector('.wbi-pwoq__status'), msg, true);
          showToast(msg, true);
          if (button) {
            button.disabled = false;
            button.textContent = i18n.globalAdd;
          }
          updateGlobalBar();
        }
      );
    }

    runNext();
  }

  /**
   * Find the matching variant given the current attribute selections.
   * Returns null if no unique match is found (or if a required attr is missing).
   */
  function resolveVariant(variants, selectedAttrs) {
    var matches = variants.filter(function (v) {
      if (!v.in_stock) return false;
      var attrs = v.attributes || {};
      return Object.keys(selectedAttrs).every(function (attrKey) {
        var varAttrKey = 'attribute_' + attrKey;
        // An empty string on the variation side means "any" (catch-all)
        return attrs[varAttrKey] === '' || attrs[varAttrKey] === selectedAttrs[attrKey];
      });
    });
    return matches.length === 1 ? matches[0] : null;
  }

  /**
   * Build a map { attrName: selectedValue } from chips inside a given root.
   * Values come from chip.dataset.value (raw slug/value used in attributes map).
   */
  function getSelectedAttrs(root) {
    var result = {};
    root.querySelectorAll('.wbi-pwoq__attr-group').forEach(function (group) {
      var attrName = group.dataset.attr;
      var selected = group.querySelector('.wbi-pwoq__chip.is-selected');
      if (selected) result[attrName] = selected.dataset.value;
    });
    return result;
  }

  /**
   * Disable chips whose combination with current selections is fully out of stock.
   */
  function refreshChipAvailability(root, variants) {
    var currentAttrs = getSelectedAttrs(root);

    root.querySelectorAll('.wbi-pwoq__attr-group').forEach(function (group) {
      var attrName = group.dataset.attr;
      group.querySelectorAll('.wbi-pwoq__chip').forEach(function (chip) {
        // Build a hypothetical selection with this chip chosen
        var testAttrs = Object.assign({}, currentAttrs);
        testAttrs[attrName] = chip.dataset.value;

        // Is there at least one in-stock variant compatible with this selection?
        var possible = variants.some(function (v) {
          if (!v.in_stock) return false;
          var attrs = v.attributes || {};
          return Object.keys(testAttrs).every(function (k) {
            var key = 'attribute_' + k;
            return attrs[key] === '' || attrs[key] === testAttrs[k];
          });
        });

        chip.classList.toggle('is-disabled', !possible);
      });
    });
  }

  /**
   * Bind chip-click interactions on a given root element.
   * When a chip is selected, update qty constraints and refresh availability.
   * `onVariantResolved(variant)` is called each time a unique variant is matched.
   */
  function bindChips(root, variants, onVariantResolved) {
    // Auto-select the single valid option per attribute when possible
    var attrGroups = root.querySelectorAll('.wbi-pwoq__attr-group');
    attrGroups.forEach(function (group) {
      var chips = group.querySelectorAll('.wbi-pwoq__chip:not(.is-disabled)');
      if (chips.length === 1) chips[0].classList.add('is-selected');
    });

    refreshChipAvailability(root, variants);

    root.addEventListener('click', function (e) {
      var chip = e.target.closest('.wbi-pwoq__chip');
      if (!chip || chip.classList.contains('is-disabled')) return;

      var group = chip.closest('.wbi-pwoq__attr-group');
      if (!group) return;

      // Deselect siblings
      group.querySelectorAll('.wbi-pwoq__chip').forEach(function (c) {
        setChipSelected(c, false);
      });
      setChipSelected(chip, true);

      refreshChipAvailability(root, variants);

      var selectedAttrs = getSelectedAttrs(root);
      var resolved = resolveVariant(variants, selectedAttrs);

      // Sync any hidden attribute inputs (Woo-style: attribute_pa_xxx)
      syncHiddenAttributeInputs(root, selectedAttrs, resolved);

      if (onVariantResolved) onVariantResolved(resolved || null);
    });
  }

  /**
   * Sync hidden WooCommerce-style attribute inputs and variation_id input
   * that may be present in the DOM (e.g., in Flatsome product blocks).
   * This ensures native Woo JS also has the correct state when PWOQ resolves
   * a variant combination.
   */
  function syncHiddenAttributeInputs(root, selectedAttrs, resolvedVariant) {
    // Sync attribute selects / hidden inputs
    Object.keys(selectedAttrs).forEach(function (attrKey) {
      var value = selectedAttrs[attrKey];
      // attribute_pa_xxx or attribute_xxx
      var inputName = 'attribute_' + attrKey;
      var inputEl = root.querySelector('[name="' + inputName + '"]');
      if (inputEl) {
        inputEl.value = value;
      }
    });

    // Sync variation_id hidden input if present
    var variationIdInput = root.querySelector('[name="variation_id"]');
    if (variationIdInput) {
      variationIdInput.value = resolvedVariant ? resolvedVariant.id : 0;
    }
  }

  // -------------------------------------------------------------------------
  // AJAX helpers
  // -------------------------------------------------------------------------

  function doAddToCart(params, onSuccess, onError, onFinally) {
    var body = new URLSearchParams();
    body.append('action', 'wbi_public_quick_order_add');
    body.append('nonce', cfg.nonce);
    body.append('product_id', params.productId || 0);
    body.append('variation_id', params.variationId || 0);
    body.append('quantity', params.quantity || 1);

    fetch(cfg.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString(),
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        if (!payload || !payload.success) {
          var msg = (payload && payload.data && payload.data.message) ? payload.data.message : i18n.errorGeneric;
          if (onError) onError(msg);
          return;
        }
        if (onSuccess) onSuccess(payload.data);
      })
      .catch(function () {
        if (onError) onError(i18n.errorGeneric);
      })
      .finally(function () {
        if (onFinally) onFinally();
      });
  }

  // -------------------------------------------------------------------------
  // Modal variant selector
  // -------------------------------------------------------------------------

  var modal = null;
  var modalCurrentData = null;
  var modalCurrentVariant = null;

  function openModal(productData) {
    modal = document.querySelector('.wbi-pwoq-modal');
    if (!modal) return;

    modalCurrentData = productData;
    modalCurrentVariant = null;

    // Fill product name
    var nameEl = modal.querySelector('.wbi-pwoq-modal__product-name');
    if (nameEl) nameEl.textContent = productData.product_name || '';

    // Clear previous attrs & status
    var attrsEl = modal.querySelector('.wbi-pwoq-modal__attrs');
    var statusEl = modal.querySelector('.wbi-pwoq-modal__status');
    var rulesEl = modal.querySelector('.wbi-pwoq-modal__rules');
    if (attrsEl) attrsEl.innerHTML = '';
    if (statusEl) { statusEl.textContent = ''; statusEl.classList.remove('is-error'); }
    if (rulesEl) rulesEl.textContent = '';

    var qtyInput = modal.querySelector('.wbi-pwoq-modal__qty');

    // Build attribute chip groups
    var attributes = productData.attributes || {};
    Object.keys(attributes).forEach(function (attrName) {
      var values = attributes[attrName];
      var group = document.createElement('div');
      group.className = 'wbi-pwoq__attr-group';
      group.dataset.attr = attrName;

      var label = document.createElement('span');
      label.className = 'wbi-pwoq__attr-label';
      label.textContent = attrName.replace(/^pa_/, '').replace(/[-_]/g, ' ');
      label.textContent = label.textContent.charAt(0).toUpperCase() + label.textContent.slice(1);
      group.appendChild(label);

      var chipsRow = document.createElement('div');
      chipsRow.className = 'wbi-pwoq__attr-chips';
      values.forEach(function (val) {
        var chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'wbi-pwoq__chip';
        chip.dataset.value = val;
        chip.textContent = val;
        chipsRow.appendChild(chip);
      });
      group.appendChild(chipsRow);
      if (attrsEl) attrsEl.appendChild(group);
    });

    var variants = productData.variants || [];

    bindChips(modal, variants, function (variant) {
      modalCurrentVariant = variant;
      if (qtyInput) {
        resetQtyInput(qtyInput, variant || {});
      }
      if (rulesEl) rulesEl.textContent = variant && variant.rule_text ? variant.rule_text : '';
    });

    // Auto-select single valid option per attr
    var autoSelectId = productData.auto_select;
    if (autoSelectId !== null && autoSelectId !== undefined) {
      var autoVariant = variants.find(function (v) { return String(v.id) === String(autoSelectId); });
      if (autoVariant) {
        modalCurrentVariant = autoVariant;
        if (qtyInput) {
          resetQtyInput(qtyInput, autoVariant);
        }
        if (rulesEl) rulesEl.textContent = autoVariant.rule_text || '';
      }
    }

    // Set default qty if no variation yet
    if (!modalCurrentVariant && variants.length === 1) {
      modalCurrentVariant = variants[0];
      if (qtyInput) {
        resetQtyInput(qtyInput, variants[0]);
      }
      if (rulesEl) rulesEl.textContent = variants[0].rule_text || '';
    }

    modal.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    if (modal) {
      modal.hidden = true;
      document.body.style.overflow = '';
    }
    modalCurrentData = null;
    modalCurrentVariant = null;
  }

  function initModal() {
    var m = document.querySelector('.wbi-pwoq-modal');
    if (!m) return;

    // Close button
    var closeBtn = m.querySelector('.wbi-pwoq-modal__close');
    if (closeBtn) closeBtn.addEventListener('click', closeModal);

    // Backdrop
    var backdrop = m.querySelector('.wbi-pwoq-modal__backdrop');
    if (backdrop) backdrop.addEventListener('click', closeModal);

    // Keyboard ESC
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal && !modal.hidden) closeModal();
    });

    // Confirm button
    var confirmBtn = m.querySelector('.wbi-pwoq-modal__confirm');
    var statusEl   = m.querySelector('.wbi-pwoq-modal__status');
    var qtyInput   = m.querySelector('.wbi-pwoq-modal__qty');

    if (confirmBtn) {
      confirmBtn.addEventListener('click', function () {
        if (!modalCurrentData) return;

        if (modalCurrentData.has_variations && !modalCurrentVariant && getNormalizedQty(qtyInput) > 0) {
          setStatus(statusEl, getMissingVariationMessage(), true);
          return;
        }

        var variantId  = modalCurrentVariant ? modalCurrentVariant.id : 0;
        var qty        = qtyInput ? getNormalizedQty(qtyInput) : 1;

        var originalLabel = confirmBtn.textContent;
        confirmBtn.disabled = true;
        confirmBtn.textContent = i18n.adding;
        setStatus(statusEl, '', false);

        doAddToCart(
          { productId: modalCurrentData.product_id, variationId: variantId, quantity: qty },
          function (data) {
            setStatus(statusEl, data.message, false);
            showToast(data.message, false);
            updateSummary(data.summary);
            triggerWooFragmentRefresh();
            setTimeout(function () { closeModal(); }, 1200);
          },
          function (msg) {
            setStatus(statusEl, msg, true);
            showToast(msg, true);
          },
          function () {
            confirmBtn.disabled = false;
            confirmBtn.textContent = originalLabel || i18n.confirmAdd;
          }
        );
      });
    }
  }

  // -------------------------------------------------------------------------
  // Card binding (inline mode)
  // -------------------------------------------------------------------------

  function bindInlineCard(container) {
    var data = parseProductData(container);
    var variants = data.variants || [];
    var button   = container.querySelector('.wbi-pwoq__button');
    var qtyInput = container.querySelector('.wbi-pwoq__qty');
    var rulesEl  = container.querySelector('.wbi-pwoq__rules-line');
    var statusEl = container.querySelector('.wbi-pwoq__status');
    var currentVariant = null;

    // Init stepper
    bindStepper(qtyInput);

    if (data.has_variations) {
      bindChips(container, variants, function (variant) {
        currentVariant = variant;
        if (variant && qtyInput) {
          resetQtyInput(qtyInput, variant);
        }
        if (rulesEl) rulesEl.textContent = variant && variant.rule_text ? variant.rule_text : '';
        setCurrentVariant(container, variant);
        setStatus(statusEl, '', false);
        updateGlobalBar();
      });

      // Auto-select single variant
      if (data.auto_select !== null && data.auto_select !== undefined) {
        var auto = variants.find(function (v) { return String(v.id) === String(data.auto_select); });
        if (auto) {
          currentVariant = auto;
          if (qtyInput) {
            resetQtyInput(qtyInput, auto);
          }
          if (rulesEl) rulesEl.textContent = auto.rule_text || '';
          setCurrentVariant(container, auto);
        }
      }
    } else {
      currentVariant = variants[0] || null;
      setCurrentVariant(container, currentVariant);
    }

    if (!button) return;

    if (currentVariant) {
      setCurrentVariant(container, currentVariant);
    }

    button.addEventListener('click', function () {
      if (globalAddEnabled) {
        updateGlobalBar();
      }
      if (!hasValidVariantSelection(data, currentVariant ? currentVariant.id : 0) && getNormalizedQty(qtyInput) > 0) {
        setStatus(statusEl, getMissingVariationMessage(), true);
        return;
      }

      var variantId = currentVariant ? currentVariant.id : 0;
      var qty       = qtyInput ? getNormalizedQty(qtyInput) : 1;

      var originalLabel = button.textContent;
      button.disabled   = true;
      button.textContent = i18n.adding;
      setStatus(statusEl, '', false);

      doAddToCart(
        { productId: data.product_id, variationId: variantId, quantity: qty },
        function (responseData) {
          setStatus(statusEl, responseData.message, false);
          showToast(responseData.message, false);
          updateSummary(responseData.summary);
          triggerWooFragmentRefresh();
        },
        function (msg) {
          setStatus(statusEl, msg, true);
          showToast(msg, true);
        },
        function () {
          button.disabled = false;
          button.textContent = originalLabel || i18n.defaultButton;
        }
      );
    });
  }

  // -------------------------------------------------------------------------
  // Card binding (modal mode)
  // -------------------------------------------------------------------------

  function bindModalCard(container) {
    var data    = parseProductData(container);
    var button  = container.querySelector('.wbi-pwoq__button');
    var qtyInput = container.querySelector('.wbi-pwoq__qty');
    var statusEl = container.querySelector('.wbi-pwoq__status');

    // Init stepper
    bindStepper(qtyInput);

    if (!button) return;

    button.addEventListener('click', function () {
      if (globalAddEnabled) {
        updateGlobalBar();
      }
      if (data.has_variations) {
        openModal(data);
      } else {
        // Simple product — add directly
        var qty = qtyInput ? getNormalizedQty(qtyInput) : 1;
        var originalLabel = button.textContent;
        button.disabled   = true;
        button.textContent = i18n.adding;
        setStatus(statusEl, '', false);

        doAddToCart(
          { productId: data.product_id, variationId: 0, quantity: qty },
          function (responseData) {
            setStatus(statusEl, responseData.message, false);
            showToast(responseData.message, false);
            updateSummary(responseData.summary);
            triggerWooFragmentRefresh();
          },
          function (msg) {
            setStatus(statusEl, msg, true);
            showToast(msg, true);
          },
          function () {
            button.disabled = false;
            button.textContent = originalLabel || i18n.defaultButton;
          }
        );
      }
    });
  }

  // -------------------------------------------------------------------------
  // Init
  // -------------------------------------------------------------------------

  document.addEventListener('DOMContentLoaded', function () {
    if (mode === 'modal') {
      initModal();
    }

    document.querySelectorAll('.wbi-pwoq').forEach(function (container) {
      if (mode === 'inline') {
        bindInlineCard(container);
      } else {
        bindModalCard(container);
      }

      var qtyInput = container.querySelector('.wbi-pwoq__qty');
      if (qtyInput) {
        qtyInput.addEventListener('input', updateGlobalBar);
        qtyInput.addEventListener('change', updateGlobalBar);
      }
    });

    if (globalAddEnabled) {
      var globalButton = document.querySelector('.wbi-pwoq-global-bar__button');
      if (globalButton) {
        globalButton.addEventListener('click', function () {
          var selections = getBatchSelections();
          if (!selections.length) {
            showToast(i18n.globalEmpty, true);
            return;
          }
          globalButton.disabled = true;
          globalButton.textContent = i18n.adding;
          addSelectionsSequentially(selections, globalButton);
        });
      }
      updateGlobalBar();
    }
  });
})();
