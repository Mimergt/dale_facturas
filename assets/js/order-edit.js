/**
 * DaleCafe Facturas — Order Edit Page JS
 * Maneja el botón "Regenerar Factura" en la página de editar pedido.
 */
/* global dfcOrderEdit, jQuery */
(function ($) {
    'use strict';

    $(document).ready(function () {
        // ---- Regenerar Factura ----
        $(document).on('click', '#dfc-regenerate-invoice', function () {
            var $btn    = $(this);
            var $result = $('#dfc-regenerate-result');
            var orderId = $btn.data('order-id');
            var nonce   = $('input[name="dfc_regenerate_nonce"]').val();

            if (!orderId || !nonce) {
                $result.text(dfcOrderEdit.i18n.error).css('color', '#dc3232');
                return;
            }

            $btn.prop('disabled', true);
            $result.text(dfcOrderEdit.i18n.regenerating).css('color', '#666');

            $.post(dfcOrderEdit.ajaxUrl, {
                action: 'dfc_regenerate_invoice',
                nonce:  nonce,
                order_id: orderId,
            })
                .done(function (response) {
                    if (response.success) {
                        $result.text(response.data.message).css('color', '#46b450');
                        // Recargar la página después de 2 segundos para mostrar nuevos datos
                        setTimeout(function () {
                            location.reload();
                        }, 2000);
                    } else {
                        $result.text(dfcOrderEdit.i18n.error + ': ' + (response.data && response.data.message || '')).css('color', '#dc3232');
                    }
                })
                .fail(function () {
                    $result.text(dfcOrderEdit.i18n.error).css('color', '#dc3232');
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        });
    });

}(jQuery));
