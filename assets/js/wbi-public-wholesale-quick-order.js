(function () {
  'use strict';

  var cfg = window.WBIPublicQuickOrder || {};
  var i18n = cfg.i18n || {};
  var globalAddEnabled = !!cfg.globalAddEnabled;
  var initialQtyZero = !!cfg.initialQtyZero;
  var forceReloadOnFragmentFail = !!cfg.forceReloadOnFragmentFail;

  function parseJSON(value, fallback) {
    try {
      return JSON.parse(value || '');
    } catch (e) {
      return fallback;
    }
  }

  function normalize(value) {
    return String(value || '').trim().toLowerCase();
  }

  function getCard(form) {
    return form ? form.closest('.wbi-pwoq') : null;
  }

  function getMode(card) {
    return card && card.dataset && card.dataset.mode ? card.dataset.mode : (cfg.variantSelectorMode || 'modal');
  }

  function getQtyInput(form) {
    return form ? form.querySelector('input.qty') : null;
  }

  function getLoopForms() {
    return Array.prototype.slice.call(document.querySelectorAll('.wbi-pwoq form.cart, .wbi-pwoq-loop-cart'));
  }

  function getQty(form) {
    var input = getQtyInput(form);
    if (!input) return 0;

    var qty = parseInt(input.value, 10);
    return Number.isNaN(qty) ? 0 : qty;
  }

  function setStatus(form, message, isError) {
    var card = getCard(form);
    var status = card ? card.querySelector('.wbi-pwoq__status') : null;
    if (!status) return;

    status.textContent = message || '';
    status.classList.toggle('is-error', !!isError);
  }

  function showToast(message, isError) {
    var toast = document.querySelector('.wbi-pwoq-toast');
    if (!toast || !message) return;

    toast.textContent = message;
    toast.classList.toggle('is-error', !!isError);
    toast.hidden = false;
    toast.classList.add('is-visible');

    clearTimeout(showToast._timer);
    showToast._timer = setTimeout(function () {
      toast.classList.remove('is-visible');
      setTimeout(function () {
        toast.hidden = true;
      }, 180);
    }, 3000);
  }

  function syncButtonQuantity(form) {
    var qtyInput = getQtyInput(form);
    var button = form ? form.querySelector('.wbi-pwoq__submit') : null;
    if (!qtyInput || !button) return;

    button.setAttribute('data-quantity', String(Math.max(0, getQty(form))));
  }

  function getWcAjaxAddUrl() {
    var params = window.wc_add_to_cart_params || {};
    if (!params.wc_ajax_url) return '';

    return params.wc_ajax_url.toString().replace('%%endpoint%%', 'add_to_cart');
  }

  function readCartCount() {
    var selectors = [
      '.header-cart-count',
      '.ux-cart-count',
      '.cart-contents .count',
      '.cart-item-count',
      '.cart-icon strong',
      '.cart-icon .count',
      '[data-cart-count]'
    ];

    for (var i = 0; i < selectors.length; i += 1) {
      var nodes = document.querySelectorAll(selectors[i]);
      for (var j = 0; j < nodes.length; j += 1) {
        var text = (nodes[j].textContent || '').replace(/[^\d]/g, '');
        if (!text) continue;

        var count = parseInt(text, 10);
        if (!Number.isNaN(count)) {
          return count;
        }
      }
    }

    return null;
  }

  function triggerAddedToCartEvent(response, buttonEl) {
    if (typeof jQuery === 'undefined') return;

    var fragments = response && response.fragments ? response.fragments : null;
    var cartHash = response && response.cart_hash ? response.cart_hash : '';
    jQuery(document.body).trigger('added_to_cart', [fragments, cartHash, jQuery(buttonEl)]);
  }

  function triggerFragmentRefresh() {
    if (typeof jQuery === 'undefined') return;
    jQuery(document.body).trigger('wc_fragment_refresh');
  }

  function scheduleFragmentFallback(beforeCount) {
    if (!forceReloadOnFragmentFail) return;

    clearTimeout(scheduleFragmentFallback._timer);
    scheduleFragmentFallback._timer = setTimeout(function () {
      var afterCount = readCartCount();
      if (beforeCount === null || afterCount === null || beforeCount === afterCount) {
        window.location.reload();
      }
    }, 1500);
  }

  function refreshMiniCart(response, buttonEl, beforeCount) {
    if (response) {
      triggerAddedToCartEvent(response, buttonEl);
    }

    triggerFragmentRefresh();
    scheduleFragmentFallback(beforeCount);
  }

  function postNativeAddToCart(payload) {
    var url = getWcAjaxAddUrl();
    if (!url) {
      return Promise.reject(new Error(i18n.errorGeneric || 'Error'));
    }

    var body = new URLSearchParams();
    body.append('product_id', payload.productId || 0);
    body.append('quantity', payload.quantity || 1);
    body.append('variation_id', payload.variationId || 0);
    body.append('wbi_pwoq_request', '1');

    Object.keys(payload.attributes || {}).forEach(function (key) {
      body.append('attribute_' + key, payload.attributes[key]);
    });

    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: body.toString()
    }).then(function (res) {
      return res.json();
    }).then(function (data) {
      if (!data || data.error) {
        var message = (data && data.product_url)
          ? (i18n.selectVariation || i18n.errorGeneric)
          : (i18n.errorGeneric || 'Error');
        throw new Error(message);
      }

      return data;
    });
  }

  function matchesSelection(variantAttrs, selectedAttrs) {
    return Object.keys(selectedAttrs).every(function (key) {
      var selected = normalize(selectedAttrs[key]);
      if (!selected) return true;

      var variantKey = Object.keys(variantAttrs || {}).find(function (attrKey) {
        return normalize(attrKey.replace(/^attribute_/, '')) === normalize(key);
      });

      if (!variantKey) return false;

      var value = normalize(variantAttrs[variantKey]);
      return !value || value === selected;
    });
  }

  function resolveVariant(productData, selectedAttrs) {
    var variants = (productData && productData.variants) || [];
    var inStockMatches = variants.filter(function (variant) {
      return !!variant.in_stock && matchesSelection(variant.attributes || {}, selectedAttrs);
    });

    return inStockMatches.length === 1 ? inStockMatches[0] : null;
  }

  function getDefaultRules(productData) {
    var variants = (productData && productData.variants) || [];
    for (var i = 0; i < variants.length; i += 1) {
      if (variants[i] && variants[i].in_stock) {
        return variants[i];
      }
    }

    return variants.length ? variants[0] : null;
  }

  function updateRules(form, variant) {
    var card = getCard(form);
    var rulesEl = card ? card.querySelector('.wbi-pwoq__rules-line') : null;
    if (!rulesEl) return;

    rulesEl.textContent = variant && variant.rule_text
      ? variant.rule_text
      : 'Elegí la cantidad y agregá al pedido.';
  }

  function updateQtyConstraints(form, variant) {
    var qtyInput = getQtyInput(form);
    if (!qtyInput || !variant) return;

    var minQty = parseInt(variant.min_qty, 10) || 1;
    var stepQty = parseInt(variant.step_qty, 10) || 1;
    var currentQty = getQty(form);

    qtyInput.step = String(Math.max(1, stepQty));
    qtyInput.min = String(initialQtyZero ? 0 : minQty);

    if (currentQty <= 0) {
      qtyInput.value = String(initialQtyZero ? 0 : (parseInt(variant.default_qty, 10) || minQty));
    }

    syncButtonQuantity(form);
  }

  function setVariationOnForm(form, variant, selectedAttrs) {
    var variationInput = form.querySelector('.wbi-pwoq__variation-id');
    if (variationInput) {
      variationInput.value = variant ? String(variant.id) : '0';
    }

    form.querySelectorAll('.wbi-pwoq__attr-hidden').forEach(function (hiddenInput) {
      var key = hiddenInput.dataset.attr || '';
      hiddenInput.value = selectedAttrs[key] || '';
    });

    updateRules(form, variant);
    updateQtyConstraints(form, variant);
  }

  function getSelectedAttributes(card) {
    var selected = {};
    var mode = getMode(card);

    if (mode === 'inline') {
      card.querySelectorAll('.wbi-pwoq__attr-group').forEach(function (group) {
        var key = group.dataset.attr;
        var chip = group.querySelector('.wbi-pwoq__chip.is-selected');
        if (key && chip) {
          selected[key] = chip.dataset.slug || chip.dataset.value || '';
        }
      });

      return selected;
    }

    card.querySelectorAll('.wbi-pwoq__select').forEach(function (select) {
      var key = select.dataset.attr;
      if (key && select.value) {
        selected[key] = select.value;
      }
    });

    return selected;
  }

  function validateQty(qty, rules) {
    if (qty <= 0) {
      return i18n.qtyPositive || 'Ingresá una cantidad mayor a 0 para continuar.';
    }

    if (!rules) {
      return null;
    }

    var minQty = parseInt(rules.min_qty, 10) || 1;
    var stepQty = parseInt(rules.step_qty, 10) || 1;

    if (qty < minQty) {
      return (i18n.missingMin || 'Te faltan %d para completar el mínimo. Mínimo: %d unidades.')
        .replace('%d', String(minQty - qty))
        .replace('%d', String(minQty));
    }

    if (stepQty > 1 && qty % stepQty !== 0) {
      return (i18n.packMultiple || 'Elegí múltiplos de %d unidades para este producto.')
        .replace('%d', String(stepQty));
    }

    return null;
  }

  function getRequestPayload(form) {
    var card = getCard(form);
    var productData = parseJSON(card ? card.getAttribute('data-product') : '', {});
    var selectedAttrs = getSelectedAttributes(card);
    var variant = resolveVariant(productData, selectedAttrs);
    var isVariable = !!productData.has_variations;
    var qty = getQty(form);

    setVariationOnForm(form, variant, selectedAttrs);

    if (qty <= 0) {
      return { error: i18n.qtyPositive || 'Ingresá una cantidad mayor a 0 para continuar.' };
    }

    if (isVariable && (!variant || !variant.id)) {
      return { error: i18n.selectVariation || 'Seleccioná una opción' };
    }

    var validationMsg = validateQty(qty, variant || getDefaultRules(productData));
    if (validationMsg) {
      return { error: validationMsg };
    }

    return {
      productId: parseInt(productData.product_id, 10) || parseInt(form.dataset.productId, 10) || 0,
      variationId: variant ? (parseInt(variant.id, 10) || 0) : 0,
      quantity: qty,
      attributes: selectedAttrs,
      productName: productData.product_name || '',
      form: form
    };
  }

  function formatSelectedSummary(products, units) {
    var template = products === 1
      ? (i18n.counterSingular || '%1$d producto · %2$d unidad')
      : (i18n.counterPlural || '%1$d productos · %2$d unidades');

    return template.replace('%1$d', String(products)).replace('%2$d', String(units));
  }

  function formatSelectedDetail(names) {
    if (!names.length) return '';
    if (names.length === 1) return names[0];

    return (i18n.selectedDetail || '%1$s · +%2$d más')
      .replace('%1$s', names[0])
      .replace('%2$d', String(names.length - 1));
  }

  function collectMassSelections() {
    var valid = [];
    var invalid = [];
    var names = [];
    var totalProducts = 0;
    var totalUnits = 0;

    getLoopForms().forEach(function (form) {
      var qty = getQty(form);
      if (qty <= 0) return;

      totalProducts += 1;
      totalUnits += qty;

      var payload = getRequestPayload(form);
      if (payload.error) {
        var card = getCard(form);
        var productData = parseJSON(card ? card.getAttribute('data-product') : '', {});
        invalid.push({
          form: form,
          message: payload.error,
          productName: productData.product_name || ''
        });
        if (productData.product_name) {
          names.push(productData.product_name);
        }
        return;
      }

      names.push(payload.productName);
      valid.push(payload);
    });

    return {
      valid: valid,
      invalid: invalid,
      totalProducts: totalProducts,
      totalUnits: totalUnits,
      names: names
    };
  }

  function updateGlobalBar() {
    if (!globalAddEnabled) return;

    var bar = document.querySelector('.wbi-pwoq-global-bar');
    if (!bar) return;

    var summaryEl = bar.querySelector('.wbi-pwoq-global-bar__summary');
    var detailEl = bar.querySelector('.wbi-pwoq-global-bar__detail');
    var button = bar.querySelector('.wbi-pwoq-global-bar__button');
    var state = collectMassSelections();

    if (state.totalUnits <= 0) {
      bar.hidden = true;
      if (button) button.disabled = true;
      if (detailEl) detailEl.textContent = '';
      return;
    }

    bar.hidden = false;

    if (summaryEl) {
      summaryEl.textContent = formatSelectedSummary(state.totalProducts, state.totalUnits);
    }

    if (detailEl) {
      detailEl.textContent = formatSelectedDetail(state.names);
    }

    if (button) {
      button.disabled = false;
      button.textContent = i18n.globalAdd || 'AGREGAR SELECCIONADOS AL CARRITO';
    }
  }

  function bindAttributeControls(form) {
    var card = getCard(form);
    var productData = parseJSON(card ? card.getAttribute('data-product') : '', {});
    var mode = getMode(card);

    if (!productData.has_variations) {
      return;
    }

    function syncVariantFromSelection() {
      var selectedAttrs = getSelectedAttributes(card);
      var variant = resolveVariant(productData, selectedAttrs);
      setVariationOnForm(form, variant, selectedAttrs);
      setStatus(form, '', false);
      updateGlobalBar();
    }

    if (mode === 'inline') {
      card.querySelectorAll('.wbi-pwoq__chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
          var group = chip.closest('.wbi-pwoq__attr-group');
          if (!group) return;

          group.querySelectorAll('.wbi-pwoq__chip').forEach(function (sibling) {
            sibling.classList.remove('is-selected');
            sibling.setAttribute('aria-pressed', 'false');
          });

          chip.classList.add('is-selected');
          chip.setAttribute('aria-pressed', 'true');
          syncVariantFromSelection();
        });
      });
    } else {
      card.querySelectorAll('.wbi-pwoq__select').forEach(function (select) {
        select.addEventListener('change', syncVariantFromSelection);
      });
    }

    if (productData.auto_select) {
      var variant = (productData.variants || []).find(function (item) {
        return parseInt(item.id, 10) === parseInt(productData.auto_select, 10);
      });

      if (variant) {
        Object.keys(variant.attributes || {}).forEach(function (key) {
          var attrKey = key.replace(/^attribute_/, '');
          var value = variant.attributes[key];

          if (mode === 'inline') {
            var group = card.querySelector('.wbi-pwoq__attr-group[data-attr="' + attrKey + '"]');
            if (group) {
              var targetChip = Array.prototype.find.call(group.querySelectorAll('.wbi-pwoq__chip'), function (chip) {
                return normalize(chip.dataset.slug || chip.dataset.value) === normalize(value);
              });

              if (targetChip) {
                group.querySelectorAll('.wbi-pwoq__chip').forEach(function (chip) {
                  chip.classList.remove('is-selected');
                  chip.setAttribute('aria-pressed', 'false');
                });

                targetChip.classList.add('is-selected');
                targetChip.setAttribute('aria-pressed', 'true');
              }
            }
          } else {
            var select = card.querySelector('.wbi-pwoq__select[data-attr="' + attrKey + '"]');
            if (select) {
              select.value = value;
            }
          }
        });

        setVariationOnForm(form, variant, getSelectedAttributes(card));
      }
    }
  }

  function bindFormSubmit(form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();

      var payload = getRequestPayload(form);
      if (payload.error) {
        setStatus(form, payload.error, true);
        showToast(payload.error, true);
        return;
      }

      var button = form.querySelector('.wbi-pwoq__submit');
      var originalLabel = button ? button.textContent : '';
      var beforeCount = readCartCount();

      if (button) {
        button.disabled = true;
        button.textContent = i18n.adding || 'Agregando…';
      }

      setStatus(form, '', false);

      postNativeAddToCart(payload)
        .then(function (response) {
          refreshMiniCart(response, button, beforeCount);

          var successMessage = payload.quantity === 1
            ? 'Agregaste 1 unidad de ' + payload.productName + '.'
            : 'Agregaste ' + payload.quantity + ' unidades de ' + payload.productName + '.';

          setStatus(form, successMessage, false);
          showToast(successMessage, false);
          updateGlobalBar();
        })
        .catch(function (error) {
          var message = error && error.message ? error.message : (i18n.errorGeneric || 'Error');
          setStatus(form, message, true);
          showToast(message, true);
        })
        .finally(function () {
          if (button) {
            button.disabled = false;
            button.textContent = originalLabel || (i18n.addLabel || 'AGREGAR');
          }

          syncButtonQuantity(form);
        });
    });
  }

  function bindGlobalMassAdd() {
    if (!globalAddEnabled) return;

    var button = document.querySelector('.wbi-pwoq-global-bar__button');
    if (!button) return;

    button.addEventListener('click', function () {
      var state = collectMassSelections();
      if (state.totalUnits <= 0) {
        showToast(i18n.globalEmpty || 'Seleccioná cantidades para agregar al carrito.', true);
        return;
      }

      state.invalid.forEach(function (row) {
        setStatus(row.form, row.message, true);
      });

      if (!state.valid.length) {
        showToast(state.invalid[0] ? state.invalid[0].message : (i18n.selectVariation || 'Seleccioná una opción'), true);
        return;
      }

      var originalLabel = button.textContent;
      var beforeCount = readCartCount();
      var successProducts = 0;
      var successUnits = 0;
      var failed = [];
      var lastResponse = null;

      button.disabled = true;
      button.textContent = i18n.adding || 'Agregando…';

      var sequence = state.valid.reduce(function (promise, payload) {
        return promise.then(function () {
          return postNativeAddToCart(payload)
            .then(function (response) {
              lastResponse = response;
              successProducts += 1;
              successUnits += payload.quantity;
              setStatus(payload.form, '', false);

              var qtyInput = getQtyInput(payload.form);
              if (qtyInput && initialQtyZero) {
                qtyInput.value = '0';
              }

              syncButtonQuantity(payload.form);
            })
            .catch(function (error) {
              var message = error && error.message ? error.message : (i18n.errorGeneric || 'Error');
              failed.push({ form: payload.form, message: message });
              setStatus(payload.form, message, true);
            });
        });
      }, Promise.resolve());

      sequence
        .then(function () {
          if (successProducts > 0) {
            refreshMiniCart(lastResponse, button, beforeCount);
          }

          if (state.invalid.length) {
            showToast(
              (i18n.globalSkipped || 'Se omitieron %d productos sin variante válida.')
                .replace('%d', String(state.invalid.length)),
              true
            );
          } else if (!successProducts && failed.length) {
            showToast(failed[0].message, true);
          } else if (successProducts > 0) {
            showToast(
              (i18n.globalSuccess || 'Se agregaron %1$d productos por %2$d unidades.')
                .replace('%1$d', String(successProducts))
                .replace('%2$d', String(successUnits)),
              false
            );
          }

          updateGlobalBar();
        })
        .finally(function () {
          button.disabled = false;
          button.textContent = originalLabel || (i18n.globalAdd || 'AGREGAR SELECCIONADOS AL CARRITO');
          updateGlobalBar();
        });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    getLoopForms().forEach(function (form) {
      bindAttributeControls(form);
      bindFormSubmit(form);
      syncButtonQuantity(form);

      var qtyInput = getQtyInput(form);
      if (qtyInput) {
        qtyInput.addEventListener('input', function () {
          syncButtonQuantity(form);
          updateGlobalBar();
        });

        qtyInput.addEventListener('change', function () {
          syncButtonQuantity(form);
          updateGlobalBar();
        });
      }
    });

    bindGlobalMassAdd();
    updateGlobalBar();
  });
})();
