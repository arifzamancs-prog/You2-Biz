// Product list: the small pencil beside a Sale Price opens an inline box; Enter or the
// tick saves the new price by AJAX (no page reload), Esc or the cross cancels.
// window.PRODUCT_PRICE_EDIT = { url: "update_sale_price.php", token: "..." }
(function ($) {
    'use strict';
    var config = window.PRODUCT_PRICE_EDIT || {};
    if (!$ || !config.url) return;

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // Sorting and Search of the DataTable follow the new price.
    function refreshTable(cell) {
        var table = $(cell).closest('table');
        if ($.fn.DataTable && $.fn.DataTable.isDataTable(table)) {
            table.DataTable().cell(cell).invalidate('dom').draw(false);
        }
    }

    function closeEditor(cell, display) {
        var box = $(cell).find('.ppe-box');
        if (!box.length) return;
        if (display !== undefined) $(cell).find('.ppe-value').html(esc(display));
        box.remove();
        $(cell).find('.ppe-value, .product-price-edit').removeClass('d-none');
        refreshTable(cell);
    }

    function save(cell) {
        var button = $(cell).find('.product-price-edit');
        var input = $(cell).find('.ppe-input');
        var message = $(cell).find('.ppe-message');
        var value = $.trim(input.val());
        if (value === '' || isNaN(Number(value)) || Number(value) < 0) {
            message.text('Enter a price of 0 or more.');
            input.focus();
            return;
        }
        if (Number(value).toFixed(2) === Number(button.attr('data-price')).toFixed(2)) {
            closeEditor(cell);
            return;
        }
        $(cell).find('.ppe-box button, .ppe-input').prop('disabled', true);
        message.removeClass('text-danger').addClass('text-muted').text('Saving…');
        var showError = function (error) {
            $(cell).find('.ppe-box button, .ppe-input').prop('disabled', false);
            message.removeClass('text-muted').addClass('text-danger').text(error);
            input.focus();
        };
        $.ajax({ url: config.url, method: 'POST', dataType: 'json',
            data: { id: button.attr('data-id'), sale_price: value, token: config.token } })
            .done(function (result) {
                if (!result || !result.ok) {
                    showError((result && result.error) || 'Could not save. Please try again.');
                    return;
                }
                button.attr('data-price', result.price);
                closeEditor(cell, result.display);
                $(cell).addClass('table-success');
                setTimeout(function () { $(cell).removeClass('table-success'); }, 1200);
            })
            .fail(function (xhr) {
                showError((xhr.responseJSON && xhr.responseJSON.error) || 'Could not save. Please try again.');
            });
    }

    $(document).on('click', '.product-price-edit', function (event) {
        event.preventDefault();
        var cell = $(this).closest('td')[0];
        if ($(cell).find('.ppe-box').length) return;
        $(cell).find('.ppe-value, .product-price-edit').addClass('d-none');
        $(cell).append(
            '<div class="ppe-box">' +
                '<div class="input-group input-group-sm" style="width:165px">' +
                    '<input type="number" class="form-control ppe-input" min="0" step="0.01" value="' + esc(this.getAttribute('data-price')) + '" aria-label="New sale price">' +
                    '<div class="input-group-append">' +
                        '<button type="button" class="btn btn-success ppe-save" title="Save (Enter)" aria-label="Save"><i class="fas fa-check"></i></button>' +
                        '<button type="button" class="btn btn-outline-secondary ppe-cancel" title="Cancel (Esc)" aria-label="Cancel"><i class="fas fa-times"></i></button>' +
                    '</div>' +
                '</div>' +
                '<small class="d-block ppe-message text-danger"></small>' +
            '</div>');
        $(cell).find('.ppe-input').trigger('focus').trigger('select');
    });
    $(document).on('click', '.ppe-save', function () { save($(this).closest('td')[0]); });
    $(document).on('click', '.ppe-cancel', function () { closeEditor($(this).closest('td')[0]); });
    $(document).on('keydown', '.ppe-input', function (event) {
        if (event.key === 'Enter') { event.preventDefault(); save($(this).closest('td')[0]); }
        if (event.key === 'Escape') { event.preventDefault(); closeEditor($(this).closest('td')[0]); }
    });
})(window.jQuery);
