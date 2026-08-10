/**
 * WBI Product QR — Admin panel & labels
 * Renders QR previews (client-side, qrcode.min.js), PNG download,
 * label printing and token regeneration.
 */
/* global wbiQrAdmin, QRCode */
(function ($) {
    'use strict';

    function renderQr($el, size) {
        var url = $el.data('url');
        if (!url || $el.data('rendered')) return;
        $el.empty();
        /* eslint-disable no-new */
        new QRCode($el.get(0), {
            text: String(url),
            width: size,
            height: size,
            correctLevel: QRCode.CorrectLevel.M
        });
        $el.data('rendered', true);
    }

    function renderAll() {
        $('.wbi-qr-metabox .wbi-qr-canvas').each(function () {
            renderQr($(this), 110);
        });
        $('.wbi-qr-label .wbi-qr-canvas').each(function () {
            renderQr($(this), 220);
        });
    }

    $(function () {
        if (typeof QRCode === 'undefined') return;

        renderAll();

        // Download PNG
        $(document).on('click', '.wbi-qr-download', function () {
            var $preview = $(this).closest('.wbi-qr-preview');
            var $canvasWrap = $preview.find('.wbi-qr-canvas');
            var img = $canvasWrap.find('img').attr('src') ||
                      ($canvasWrap.find('canvas').length ? $canvasWrap.find('canvas').get(0).toDataURL('image/png') : '');
            if (!img) return;

            var targetId = $(this).closest('.wbi-qr-target').data('target-id');
            var context  = $(this).data('context');
            var a = document.createElement('a');
            a.href = img;
            a.download = 'wbi-qr-' + context + '-' + targetId + '.png';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        });

        // Regenerate
        $(document).on('click', '.wbi-qr-regenerate', function () {
            if (!window.confirm(wbiQrAdmin.i18n.confirmRegenerate)) return;

            var $btn    = $(this);
            var $target = $btn.closest('.wbi-qr-target');

            $btn.prop('disabled', true);

            $.post(wbiQrAdmin.ajaxUrl, {
                action: 'wbi_qr_regenerate',
                nonce: wbiQrAdmin.nonce,
                target_id: $target.data('target-id')
            }).done(function (resp) {
                if (resp && resp.success && resp.data) {
                    $target.find('.wbi-qr-canvas[data-context="pos"]').data('url', resp.data.pos_url).data('rendered', false).attr('data-url', resp.data.pos_url);
                    $target.find('.wbi-qr-canvas[data-context="web"]').data('url', resp.data.web_url).data('rendered', false).attr('data-url', resp.data.web_url);
                    renderAll();
                    window.alert(wbiQrAdmin.i18n.regenerated);
                } else {
                    window.alert((resp && resp.data && resp.data.message) || wbiQrAdmin.i18n.error);
                }
            }).fail(function () {
                window.alert(wbiQrAdmin.i18n.error);
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });
    });
})(jQuery);
