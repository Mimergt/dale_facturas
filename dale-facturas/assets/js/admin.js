/**
 * DaleCafe Facturas — Admin JS
 * Maneja los botones interactivos en la página de settings.
 */
/* global dfcAdmin, jQuery */
(function ($) {
    'use strict';

    // ---- Tabla PLU: agregar fila ----
    $('#dfc-add-plu-row').on('click', function () {
        var row = '<tr>' +
            '<td><input type="text" name="dfc_plu_sku[]" value="" class="regular-text"></td>' +
            '<td><input type="number" name="dfc_plu_plu[]" value="" style="width:80px;"></td>' +
            '<td>' +
            '<select name="dfc_plu_type[]">' +
            '<option value="sku">SKU de producto</option>' +
            '<option value="blend">Mezcla (blend)</option>' +
            '<option value="grind">Molienda (grind)</option>' +
            '<option value="special">Especial</option>' +
            '</select>' +
            '</td>' +
            '<td><button type="button" class="button dfc-remove-row">Eliminar</button></td>' +
            '</tr>';
        $('#dfc-plu-rows').append(row);
    });

    // ---- Tabla PLU: eliminar fila ----
    $(document).on('click', '.dfc-remove-row', function () {
        $(this).closest('tr').remove();
    });

    // ---- Guardar mapa PLU ----
    $('#dfc-save-plu-map').on('click', function () {
        var $btn    = $(this);
        var $result = $('#dfc-plu-save-result');

        var skus  = $('input[name="dfc_plu_sku[]"]').map(function () { return $(this).val(); }).get();
        var plus  = $('input[name="dfc_plu_plu[]"]').map(function () { return $(this).val(); }).get();
        var types = $('select[name="dfc_plu_type[]"]').map(function () { return $(this).val(); }).get();

        $btn.prop('disabled', true);
        $result.text(dfcAdmin.i18n.saving).css('color', '#666');

        $.post(dfcAdmin.ajaxUrl, {
            action: 'dfc_save_plu_map',
            nonce:  dfcAdmin.noncePlu,
            skus:   skus,
            plus:   plus,
            types:  types,
        })
            .done(function (response) {
                if (response.success) {
                    $result.text(dfcAdmin.i18n.saved).css('color', '#46b450');
                } else {
                    $result.text(dfcAdmin.i18n.error + ': ' + (response.data && response.data.message || '')).css('color', '#dc3232');
                }
            })
            .fail(function () {
                $result.text(dfcAdmin.i18n.error).css('color', '#dc3232');
            })
            .always(function () {
                $btn.prop('disabled', false);
            });
    });

    // ---- Test API ----
    $('#dfc-test-api').on('click', function () {
        var $btn    = $(this);
        var $result = $('#dfc-test-api-result');

        $btn.prop('disabled', true);
        $result.text(dfcAdmin.i18n.testing).css('color', '#666');

        $.post(dfcAdmin.ajaxUrl, {
            action: 'dfc_test_api',
            nonce:  dfcAdmin.nonceTestApi,
        })
            .done(function (response) {
                if (response.success) {
                    $result.text(response.data.message).css('color', '#46b450');
                } else {
                    $result.text(dfcAdmin.i18n.error + ': ' + (response.data && response.data.message || '')).css('color', '#dc3232');
                }
            })
            .fail(function () {
                $result.text(dfcAdmin.i18n.error).css('color', '#dc3232');
            })
            .always(function () {
                $btn.prop('disabled', false);
            });
    });

}(jQuery));
