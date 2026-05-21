/**
 * DaleCafe Facturas — Subscription Edit Page JS
 * Maneja el boton "Procesar Factura" para preparar FEL de renovaciones.
 */
/* global dfcSubscriptionEdit, jQuery */
(function ($) {
    'use strict';

    $(document).on('click', '#dfc-process-subscription-invoice', function () {
        var $btn = $(this);
        var $result = $('#dfc-process-subscription-result');
        var subscriptionId = $btn.data('subscription-id');
        var nonce = $('input[name="dfc_subscription_invoice_nonce"]').val();

        if (!subscriptionId || !nonce) {
            $result.text(dfcSubscriptionEdit.i18n.error).css('color', '#dc3232');
            return;
        }

        $btn.prop('disabled', true);
        $result.text(dfcSubscriptionEdit.i18n.processing).css('color', '#666');

        $.post(dfcSubscriptionEdit.ajaxUrl, {
            action: 'dfc_process_subscription_invoice',
            nonce: nonce,
            subscription_id: subscriptionId,
        })
            .done(function (response) {
                if (response.success) {
                    $result.text(response.data.message).css('color', '#46b450');
                } else {
                    $result
                        .text(dfcSubscriptionEdit.i18n.error + ': ' + ((response.data && response.data.message) || ''))
                        .css('color', '#dc3232');
                }
            })
            .fail(function () {
                $result.text(dfcSubscriptionEdit.i18n.error).css('color', '#dc3232');
            })
            .always(function () {
                $btn.prop('disabled', false);
            });
    });
}(jQuery));
