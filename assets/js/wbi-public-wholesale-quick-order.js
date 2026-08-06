(function () {
  'use strict';

  var cfg = window.WBIPublicQuickOrder || {};
  var i18n = cfg.i18n || {};
  var mode = cfg.variantSelectorMode || 'modal';
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

  function getQty(form) {
    var input = form.querySelector('input.qty');
    if (!input) return 0;
    var qty = parseInt(input.value, 10);
    return Number.isNaN(qty) ? 0 : qty;
  }

  function setStatus(form, message, isError) {
    var status = form.closest('.wbi-pwoq').querySelector('.wbi-pwoq__status');
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
    var qtyInput = form.querySelector('input.qty');
    var button = form.querySelector('.wbi-pwoq__submit');
    if (!qtyInput || !button) return;

    var qty = getQty(form);
    button.setAttribute('data-quantity', String(Math.max(1, qty)));
  }

  function getWcAjaxAddUrl() {
    var params = window.wc_add_to_cart_params || {};
    if (!params.wc_ajax_url) return '';
    return params.wc_ajax_url.toString().replace('%%endpoint%%', 'add_to_cart');
  }

  function triggerAddedToCartEvent(response, buttonEl) {
    if (typeof jQuery === 'undefined') return;

    var fragments = response && response.fragments ? response.fragments : null;
    var cartHash = response && response.cart_hash ? response.cart_hash : '';
    jQuery(document.body).trigger('added_to_cart', [fragments, cartHash, jQuery(buttonEl)]);
  }

  function triggerFragmentRefreshFallback() {
    if (typeof jQuery === 'undefined') return;
    jQuery(document.body).trigger('wc_fragment_refresh');
  }

  function postNativeAddToCart(payload, buttonEl) {
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

      triggerAddedToCartEvent(data, buttonEl);
      if (!data.fragments && forceReloadOnFragmentFail) {
        window.location.reload();
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

    if (inStockMatches.length === 1) {
      return inStockMatches[0];
    }

    return null;
  }

  function updateRules(form, variant) {
    var rulesEl = form.closest('.wbi-pwoq').querySelector('.wbi-pwoq__rules-line');
    if (!rulesEl) return;

    if (variant && variant.rule_text) {
      rulesEl.textContent = variant.rule_text;
      return;
    }

    rulesEl.textContent = 'Elegí la cantidad y agregá al pedido.';
  }

  function updateQtyConstraints(form, variant) {
    var qtyInput = form.querySelector('input.qty');
    if (!qtyInput || !variant) return;

    var minQty = parseInt(variant.min_qty, 10) || 1;
    var stepQty = parseInt(variant.step_qty, 10) || 1;

    qtyInput.step = String(Math.max(1, stepQty));
    qtyInput.min = String(initialQtyZero ? 0 : minQty);

    if (!qtyInput.value || parseInt(qtyInput.value, 10) <= 0) {
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

  function validateQty(form, variant, qty) {
    if (qty <= 0) {
      return i18n.qtyPositive || 'Ingresá una cantidad mayor a 0 para continuar.';
    }

    if (!variant) {
      return null;
    }

    var minQty = parseInt(variant.min_qty, 10) || 1;
    var stepQty = parseInt(variant.step_qty, 10) || 1;

    if (qty < minQty) {
      var msg = i18n.missingMin || 'Te faltan %d para completar el mínimo. Mínimo: %d unidades.';
      return msg.replace('%d', String(minQty - qty)).replace('%d', String(minQty));
    }

    if (stepQty > 1 && qty % stepQty !== 0) {
      return (i18n.packMultiple || 'Elegí múltiplos de %d unidades.').replace('%d', String(stepQty));
    }

    return null;
  }

  function getRequestPayload(form) {
    var card = form.closest('.wbi-pwoq');
    var productData = parseJSON(card.getAttribute('data-product'), {});
    var selectedAttrs = getSelectedAttributes(card);
    var variant = resolveVariant(productData, selectedAttrs);
    var isVariable = !!productData.has_variations;

    setVariationOnForm(form, variant, selectedAttrs);

    var qty = getQty(form);
    var validationMsg = validateQty(form, variant || productData.variants && productData.variants[0], qty);
    if (validationMsg) {
      return { error: validationMsg };
    }

    if (isVariable && (!variant || !variant.id)) {
      return { error: i18n.selectVariation || 'Elegí una variante válida antes de agregar este producto.' };
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
    var tpl = products === 1
      ? (i18n.counterSingular || '%1$d producto · %2$d unidad')
      : (i18n.counterPlural || '%1$d productos · %2$d unidades');

    return tpl.replace('%1$d', String(products)).replace('%2$d', String(units));
  }

  function collectMassSelections() {
    var valid = [];
    var invalid = [];
    var totalProducts = 0;
    var totalUnits = 0;

    document.querySelectorAll('.wbi-pwoq-loop-cart').forEach(function (form) {
      var qty = getQty(form);
      if (qty <= 0) return;

      totalProducts += 1;
      totalUnits += qty;

      var payload = getRequestPayload(form);
      if (payload.error) {
        invalid.push({ form: form, message: payload.error });
        return;
      }

      valid.push(payload);
    });

    return {
      valid: valid,
      invalid: invalid,
      totalProducts: totalProducts,
      totalUnits: totalUnits
    };
  }

  function updateGlobalBar() {
    if (!globalAddEnabled) return;

    var bar = document.querySelector('.wbi-pwoq-global-bar');
    if (!bar) return;

    var summaryEl = bar.querySelector('.wbi-pwoq-global-bar__summary');
    var button = bar.querySelector('.wbi-pwoq-global-bar__button');
    var state = collectMassSelections();

    if (state.totalUnits <= 0) {
      bar.hidden = true;
      if (button) button.disabled = true;
      return;
    }

    bar.hidden = false;
    if (summaryEl) {
      summaryEl.textContent = formatSelectedSummary(state.totalProducts, state.totalUnits);
    }

    if (button) {
      button.disabled = state.valid.length === 0;
      button.textContent = i18n.globalAdd || 'AGREGAR SELECCIONADOS AL CARRITO';
    }
  }

  function bindAttributeControls(form) {
    var card = form.closest('.wbi-pwoq');
    var productData = parseJSON(card.getAttribute('data-product'), {});

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
          });

          chip.classList.add('is-selected');
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
                });
                targetChip.classList.add('is-selected');
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

      if (button) {
        button.disabled = true;
        button.textContent = i18n.adding || 'Agregando…';
      }

      setStatus(form, '', false);

      postNativeAddToCart(payload, button)
        .then(function () {
          var successMessage = (payload.quantity === 1)
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
            button.textContent = originalLabel || (i18n.addLabel || 'Agregar');
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
        showToast(i18n.selectVariation || 'Elegí una variante válida antes de agregar este producto.', true);
        return;
      }

      button.disabled = true;
      button.textContent = i18n.adding || 'Agregando…';

      var successProducts = 0;
      var successUnits = 0;
      var hadFragmentPayload = false;

      var sequence = state.valid.reduce(function (promise, payload) {
        return promise.then(function () {
          var buttonEl = payload.form.querySelector('.wbi-pwoq__submit');
          return postNativeAddToCart(payload, buttonEl).then(function (response) {
            if (response && response.fragments) {
              hadFragmentPayload = true;
            }

            successProducts += 1;
            successUnits += payload.quantity;
            setStatus(payload.form, '', false);

            var qtyInput = payload.form.querySelector('input.qty');
            if (qtyInput) {
              qtyInput.value = initialQtyZero ? '0' : qtyInput.value;
            }

            syncButtonQuantity(payload.form);
          });
        });
      }, Promise.resolve());

      sequence
        .then(function () {
          if (state.invalid.length) {
            var skippedMessage = (i18n.globalSkipped || 'Se omitieron %d productos sin variante válida.').replace('%d', String(state.invalid.length));
            showToast(skippedMessage, true);
          }

          if (!hadFragmentPayload) {
            triggerFragmentRefreshFallback();
            if (forceReloadOnFragmentFail) {
              window.location.reload();
              return;
            }
          }

          var successMessage = (i18n.globalSuccess || 'Se agregaron %1$d productos por %2$d unidades.')
            .replace('%1$d', String(successProducts))
            .replace('%2$d', String(successUnits));
          showToast(successMessage, false);
          updateGlobalBar();
        })
        .catch(function (error) {
          var message = error && error.message ? error.message : (i18n.errorGeneric || 'Error');
          showToast(message, true);
          triggerFragmentRefreshFallback();
        })
        .finally(function () {
          button.disabled = false;
          button.textContent = i18n.globalAdd || 'AGREGAR SELECCIONADOS AL CARRITO';
          updateGlobalBar();
        });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.wbi-pwoq-loop-cart').forEach(function (form) {
      bindAttributeControls(form);
      bindFormSubmit(form);
      syncButtonQuantity(form);

      var qtyInput = form.querySelector('input.qty');
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
