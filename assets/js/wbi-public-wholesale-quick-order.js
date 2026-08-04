(function () {
  function parseProductData(container) {
    try {
      return JSON.parse(container.getAttribute('data-product') || '{}');
    } catch (e) {
      return {};
    }
  }

  function updateVariantState(container, variantId) {
    const data = parseProductData(container);
    const variants = Array.isArray(data.variants) ? data.variants : [];
    const selected = variants.find((item) => String(item.id) === String(variantId)) || variants[0];
    if (!selected) return;

    const qtyInput = container.querySelector('.wbi-public-quick-order__qty');
    const rules = container.querySelector('.wbi-public-quick-order__rules');
    const status = container.querySelector('.wbi-public-quick-order__status');

    qtyInput.min = selected.min_qty;
    qtyInput.step = selected.step_qty;
    qtyInput.value = selected.default_qty;
    rules.textContent = selected.rule_text || '';
    status.textContent = '';
  }

  function updateSummary(summary) {
    const el = document.querySelector('.wbi-public-quick-order-summary');
    if (!el || !summary) return;
    el.dataset.items = summary.items;
    el.dataset.units = summary.units;
    el.textContent = summary.label;
  }

  function showToast(message, isError) {
    const toast = document.querySelector('.wbi-public-quick-order-toast');
    if (!toast) return;
    toast.textContent = message;
    toast.hidden = false;
    toast.classList.toggle('is-error', !!isError);
    toast.classList.add('is-visible');
    window.clearTimeout(showToast._timer);
    showToast._timer = window.setTimeout(() => {
      toast.classList.remove('is-visible');
      window.setTimeout(() => { toast.hidden = true; }, 180);
    }, 2800);
  }

  function bindQuickOrder(container) {
    const data = parseProductData(container);
    const button = container.querySelector('.wbi-public-quick-order__button');
    const qtyInput = container.querySelector('.wbi-public-quick-order__qty');
    const variantSelect = container.querySelector('.wbi-public-quick-order__variant');
    const status = container.querySelector('.wbi-public-quick-order__status');

    if (variantSelect && variantSelect.tagName === 'SELECT') {
      variantSelect.addEventListener('change', function () {
        updateVariantState(container, variantSelect.value);
      });
    }

    button.addEventListener('click', function () {
      const currentData = parseProductData(container);
      const variants = Array.isArray(currentData.variants) ? currentData.variants : [];
      const variant = variants.find((item) => String(item.id) === String(variantSelect && variantSelect.value ? variantSelect.value : 0)) || variants[0];

      if (!variant) {
        status.textContent = WBIPublicQuickOrder.i18n.selectVariation;
        return;
      }

      const originalLabel = button.textContent;
      button.disabled = true;
      button.textContent = WBIPublicQuickOrder.i18n.adding;
      status.textContent = '';

      const body = new URLSearchParams();
      body.append('action', 'wbi_public_quick_order_add');
      body.append('nonce', WBIPublicQuickOrder.nonce);
      body.append('product_id', currentData.product_id || 0);
      body.append('variation_id', variant.id || 0);
      body.append('quantity', qtyInput.value || variant.default_qty || 1);

      fetch(WBIPublicQuickOrder.ajaxUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: body.toString(),
        credentials: 'same-origin'
      })
        .then((response) => response.json())
        .then((payload) => {
          if (!payload || !payload.success) {
            const message = payload && payload.data && payload.data.message ? payload.data.message : WBIPublicQuickOrder.i18n.errorGeneric;
            status.textContent = message;
            showToast(message, true);
            return;
          }

          status.textContent = payload.data.message;
          showToast(payload.data.message, false);
          updateSummary(payload.data.summary);
        })
        .catch(() => {
          status.textContent = WBIPublicQuickOrder.i18n.errorGeneric;
          showToast(WBIPublicQuickOrder.i18n.errorGeneric, true);
        })
        .finally(() => {
          button.disabled = false;
          button.textContent = originalLabel || WBIPublicQuickOrder.i18n.defaultButton;
        });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.wbi-public-quick-order').forEach(bindQuickOrder);
  });
})();
