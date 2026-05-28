/**
 * DaleCafe Facturas — Subscription Edit Page JS
 * Maneja el boton "Procesar Factura" para preparar FEL de renovaciones.
 */
/* global dfcSubscriptionEdit, jQuery */
(function ($) {
    'use strict';

    function wireSubscriptionButton(buttonSelector, resultSelector, nonceSelector, actionName) {
        $(document).on('click', buttonSelector, function () {
            var $btn = $(this);
            var $result = $(resultSelector);
            var subscriptionId = $btn.data('subscription-id');
            var nonce = $(nonceSelector).val();

            if (!subscriptionId || !nonce) {
                $result.text(dfcSubscriptionEdit.i18n.error).css('color', '#dc3232');
                return;
            }

            $btn.prop('disabled', true);
            $result.text(dfcSubscriptionEdit.i18n.processing).css('color', '#666');

            $.post(dfcSubscriptionEdit.ajaxUrl, {
                action: actionName,
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
                .fail(function (jqXHR) {
                    var raw = jqXHR && jqXHR.responseText ? String(jqXHR.responseText).replace(/\s+/g, ' ').slice(0, 180) : '';
                    $result
                        .text(dfcSubscriptionEdit.i18n.error + (raw ? ': ' + raw : ''))
                        .css('color', '#dc3232');
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        });
    }

    wireSubscriptionButton(
        '#dfc-process-subscription-invoice',
        '#dfc-process-subscription-result',
        'input[name="dfc_subscription_invoice_nonce"]',
        'dfc_process_subscription_invoice'
    );

    wireSubscriptionButton(
        '#dfc-process-subscription-invoice-v2',
        '#dfc-process-subscription-result-v2',
        'input[name="dfc_subscription_invoice_nonce_v2"]',
        'dfc_process_subscription_invoice_v2'
    );
}(jQuery));
