/*
 * Product list: clicking a product's barcode picture opens this popup. It loads the product's
 * labels (the product and each variant, with barcode, price and stock) by AJAX, shows a preview
 * and prints them. Labels are drawn the same way as on the Barcode Labels page, and the printer
 * settings saved there (resolution, shift, sheet margins, rotation) are used here too.
 *
 * Needs jQuery, Bootstrap's modal and JsBarcode (CODE128).
 * window.BARCODE_PRINT = { url: "barcode_print.php", labelsUrl: "...barcode_labels.php" or "" }
 */
(function ($, window, document) {
    'use strict';

    var config = window.BARCODE_PRINT || {};
    if (!config.url || !$) return;

    var SETTINGS_KEY = 'barcode_label_print_settings';
    var PARTS_KEY = 'barcode_print_popup_parts';
    var LABEL_CSS =
        '.barcode-label{box-sizing:border-box;overflow:hidden;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;font-family:Arial,Helvetica,sans-serif;color:#000;line-height:1.1}' +
        '.barcode-label .bl-shop,.barcode-label .bl-name,.barcode-label .bl-code,.barcode-label .bl-price{flex:0 0 auto}' +
        '.barcode-label .bl-shop{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}' +
        '.barcode-label .bl-name{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}' +
        '.barcode-label .bl-bars{flex:1 1 auto;min-height:0;width:100%;margin:.4mm 0;overflow:hidden;display:flex;align-items:stretch;justify-content:center}' +
        '.barcode-label .bl-bars svg{flex:0 0 auto;height:100%}' +
        '.barcode-label .bl-code{letter-spacing:.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;flex:0 0 auto}' +
        '.barcode-label .bl-price{font-weight:700}' +
        '.barcode-label .bl-extra{flex:0 0 auto;font-size:1em;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}' +
        '.barcode-grid{display:grid;box-sizing:border-box}';
    var POPUP_CSS =
        '#bp-preview{background:#eef1f4;border:1px solid #dee2e6;border-radius:4px;padding:10px;max-height:260px;overflow:auto;display:flex;flex-wrap:wrap;gap:8px;justify-content:center}' +
        '#bp-preview .barcode-sheet{background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.15)}' +
        '#bp-preview .barcode-label{outline:1px dashed #c3c9cf}' +
        '#bp-preview.bp-a4 .barcode-sheet{zoom:.45}' +
        '#bp-items td{vertical-align:middle}' +
        '#bp-items input{max-width:90px}' +
        '.product-barcode-img{display:inline-block;vertical-align:middle;cursor:pointer;line-height:0}' +
        // Every barcode picture in the list has the same size, whatever the length of its number.
        '.product-barcode-img svg{display:block;width:130px;height:34px;background:#fff}' +
        '.product-barcode-img:hover svg,.product-barcode-img:focus svg{outline:2px solid #4dabf7;outline-offset:2px}' +
        '.product-barcode-img:focus{outline:none}';

    var state = { data: null, rows: [] };
    var weak = {};
    var svgCache = {};

    function readJson(key) {
        try { return JSON.parse(window.localStorage.getItem(key) || '{}') || {}; } catch (e) { return {}; }
    }
    function writeJson(key, value) {
        try { window.localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* storage blocked */ }
    }
    function num(value, fallback, min, max) {
        var n = parseFloat(value);
        return isFinite(n) ? Math.min(max, Math.max(min, n)) : fallback;
    }
    function el(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) node.className = cls;
        if (text !== undefined) node.textContent = text;
        return node;
    }
    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // ---------- Popup markup (built once) ----------
    function injectStyle() {
        if (document.getElementById('bp-style')) return;
        var style = el('style');
        style.id = 'bp-style';
        style.textContent = LABEL_CSS + POPUP_CSS;
        document.head.appendChild(style);
    }
    injectStyle();

    function ensureModal() {
        if (document.getElementById('bp-modal')) return;

        var html =
            '<div class="modal fade" id="bp-modal" tabindex="-1" role="dialog" aria-labelledby="bp-title" aria-hidden="true">' +
            '<div class="modal-dialog modal-lg modal-dialog-scrollable" role="document"><div class="modal-content">' +
            '<div class="modal-header"><h5 class="modal-title" id="bp-title"><i class="fas fa-barcode mr-2"></i>Print Barcode</h5>' +
            '<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>' +
            '<div class="modal-body">' +
                '<div id="bp-loading" class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin mr-1"></i> Loading&hellip;</div>' +
                '<div id="bp-error" class="alert alert-danger d-none"></div>' +
                '<div id="bp-content" class="d-none">' +
                    '<div class="row">' +
                        '<div class="col-md-4 form-group"><label for="bp-print-type">Print On</label>' +
                            '<select id="bp-print-type" class="form-control form-control-sm"><option value="thermal">Thermal Label Printer</option><option value="a4">A4 Sticker Sheet</option></select></div>' +
                        '<div class="col-md-4 form-group bp-thermal"><label for="bp-thermal-size">Label Size</label>' +
                            '<select id="bp-thermal-size" class="form-control form-control-sm"><option value="38x25">38 × 25 mm</option><option value="40x30">40 × 30 mm</option><option value="50x25">50 × 25 mm</option><option value="50x30">50 × 30 mm</option><option value="custom">Custom</option></select></div>' +
                        '<div class="col-md-4 form-group bp-thermal bp-custom d-none"><label>Width × Height (mm)</label><div class="input-group input-group-sm">' +
                            '<input type="number" id="bp-thermal-width" class="form-control" min="20" max="120" value="50"><div class="input-group-prepend input-group-append"><span class="input-group-text">×</span></div>' +
                            '<input type="number" id="bp-thermal-height" class="form-control" min="15" max="100" value="25"></div></div>' +
                        '<div class="col-md-4 form-group bp-a4 d-none"><label for="bp-a4-layout">Sheet Layout</label>' +
                            '<select id="bp-a4-layout" class="form-control form-control-sm"><option value="3x7">21 labels (3 × 7)</option><option value="3x8">24 labels (3 × 8)</option><option value="3x10">30 labels (3 × 10)</option><option value="4x10">40 labels (4 × 10)</option><option value="5x13">65 labels (5 × 13)</option></select></div>' +
                        '<div class="col-md-4 form-group bp-a4 d-none"><label for="bp-a4-start">Start From Label No.</label><input type="number" id="bp-a4-start" class="form-control form-control-sm" min="1" value="1"></div>' +
                    '</div>' +
                    '<div class="form-group mb-2"><label class="d-block mb-1">Show On Label</label>' +
                        partBox('shop', 'Shop Name') + partBox('name', 'Product Name') + partBox('variant', 'Variant') +
                        partBox('price', 'Price') + partBox('code', 'Barcode Number') +
                    '</div>' +
                    '<div class="table-responsive"><table class="table table-sm table-bordered mb-2" id="bp-items"><thead><tr>' +
                        '<th>Label</th><th>Barcode</th><th class="text-right">Price</th><th class="text-right">Stock</th><th style="width:110px">Labels</th>' +
                    '</tr></thead><tbody></tbody></table></div>' +
                    '<div class="d-flex align-items-center mb-2">' +
                        '<button type="button" class="btn btn-outline-secondary btn-sm" id="bp-use-stock" title="One label for each unit in stock"><i class="fas fa-boxes mr-1"></i>Labels = Stock</button>' +
                        '<span class="text-muted small ml-auto" id="bp-summary"></span>' +
                    '</div>' +
                    '<div id="bp-warning" class="alert alert-warning py-2 small d-none"></div>' +
                    '<div id="bp-preview"></div>' +
                    '<small class="text-muted d-block mt-2"><i class="fas fa-info-circle mr-1"></i>In the print window set <strong>Margins: None</strong> and <strong>Scale: 100%</strong>.</small>' +
                '</div>' +
            '</div>' +
            '<div class="modal-footer">' +
                (config.labelsUrl ? '<a href="' + esc(config.labelsUrl) + '" class="btn btn-link btn-sm mr-auto" title="Printer resolution, print shift, sheet margins and more"><i class="fas fa-sliders-h mr-1"></i>More print settings</a>' : '') +
                '<button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>' +
                '<button type="button" class="btn btn-success" id="bp-print" disabled><i class="fas fa-print mr-1"></i>Print</button>' +
            '</div>' +
            '</div></div></div>';
        $('body').append(html);

        // Paper choice and the label parts are remembered in this browser.
        var saved = readJson(SETTINGS_KEY);
        if (saved['print-type']) $('#bp-print-type').val(saved['print-type']);
        $('#bp-thermal-size').val(saved['thermal-size'] || '50x25');
        if (saved['thermal-width']) $('#bp-thermal-width').val(saved['thermal-width']);
        if (saved['thermal-height']) $('#bp-thermal-height').val(saved['thermal-height']);
        $('#bp-a4-layout').val(saved['a4-layout'] || '3x10');
        var parts = readJson(PARTS_KEY);
        $('#bp-modal .bp-part').each(function () {
            var part = this.getAttribute('data-part');
            if (Object.prototype.hasOwnProperty.call(parts, part)) this.checked = !!parts[part];
        });

        $('#bp-print-type, #bp-thermal-size').on('change', function () { toggleFields(); saveSettings(); render(); });
        $('#bp-thermal-width, #bp-thermal-height, #bp-a4-layout').on('input change', function () { saveSettings(); render(); });
        $('#bp-a4-start').on('input change', render);
        $('#bp-modal').on('change', '.bp-part', function () {
            var values = {};
            $('#bp-modal .bp-part').each(function () { values[this.getAttribute('data-part')] = this.checked; });
            writeJson(PARTS_KEY, values);
            render();
        });
        $('#bp-items').on('input', '.bp-qty', function () {
            var row = state.rows[+this.getAttribute('data-index')];
            if (row) row.qty = Math.max(0, Math.min(500, parseInt(this.value, 10) || 0));
            render();
        });
        $('#bp-use-stock').on('click', function () {
            state.rows.forEach(function (row) { row.qty = Math.min(500, row.item.stock); });
            renderTable();
            render();
        });
        $('#bp-print').on('click', print);
        toggleFields();
    }

    function partBox(part, label) {
        var checked = part === 'variant' ? '' : ' checked';
        return '<div class="custom-control custom-checkbox custom-control-inline bp-part-wrap" data-part-wrap="' + part + '">' +
            '<input type="checkbox" class="custom-control-input bp-part" id="bp-part-' + part + '" data-part="' + part + '"' + checked + '>' +
            '<label class="custom-control-label" for="bp-part-' + part + '">' + label + '</label></div>';
    }

    function toggleFields() {
        var a4 = $('#bp-print-type').val() === 'a4';
        $('#bp-modal .bp-thermal').toggleClass('d-none', a4);
        $('#bp-modal .bp-custom').toggleClass('d-none', a4 || $('#bp-thermal-size').val() !== 'custom');
        $('#bp-modal .bp-a4').toggleClass('d-none', !a4);
        $('#bp-preview').toggleClass('bp-a4', a4);
    }

    function saveSettings() {
        var saved = readJson(SETTINGS_KEY);
        saved['print-type'] = $('#bp-print-type').val();
        saved['thermal-size'] = $('#bp-thermal-size').val();
        saved['thermal-width'] = $('#bp-thermal-width').val();
        saved['thermal-height'] = $('#bp-thermal-height').val();
        saved['a4-layout'] = $('#bp-a4-layout').val();
        writeJson(SETTINGS_KEY, saved);
    }

    // ---------- Label drawing (same rules as the Barcode Labels page) ----------
    function settings() {
        var saved = readJson(SETTINGS_KEY);
        var rotate = parseInt(saved['label-rotate'], 10);
        return {
            dpi: parseInt(saved['printer-dpi'], 10) || ($('#bp-print-type').val() === 'a4' ? 600 : 203),
            offsetX: num(saved['offset-x'], 0, -10, 10),
            offsetY: num(saved['offset-y'], 0, -10, 10),
            rotate: [90, 180, 270].indexOf(rotate) >= 0 ? rotate : 0,
            padY: num(saved['a4-margin-top'], 10, 0, 40),
            padX: num(saved['a4-margin-left'], 5, 0, 40),
            gapX: num(saved['a4-gap-x'], 0, 0, 20),
            gapY: num(saved['a4-gap-y'], 0, 0, 20)
        };
    }

    function layout(set) {
        if ($('#bp-print-type').val() === 'a4') {
            var parts = ($('#bp-a4-layout').val() || '3x10').split('x').map(Number);
            var cols = parts[0], rows = parts[1];
            return { type: 'a4', cols: cols, rows: rows, padX: set.padX, padY: set.padY, gapX: set.gapX, gapY: set.gapY,
                w: (210 - set.padX * 2 - set.gapX * (cols - 1)) / cols, h: (297 - set.padY * 2 - set.gapY * (rows - 1)) / rows };
        }
        var size = $('#bp-thermal-size').val(), w, h;
        if (size === 'custom') {
            w = num($('#bp-thermal-width').val(), 50, 20, 120);
            h = num($('#bp-thermal-height').val(), 25, 15, 100);
        } else {
            var wh = (size || '50x25').split('x').map(Number);
            w = wh[0]; h = wh[1];
        }
        return { type: 'thermal', w: w, h: h };
    }

    function shown(part) {
        var box = document.getElementById('bp-part-' + part);
        return !!(box && box.checked && !$(box).closest('.bp-part-wrap').hasClass('d-none'));
    }

    // Bars are whole printer dots wide, with a 10-bar quiet zone each side, so they scan.
    function barcodeSvg(value, innerWidthMm, dpi) {
        var key = value + '|' + innerWidthMm + '|' + dpi;
        if (!svgCache[key]) {
            var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            window.JsBarcode(svg, value, { format: 'CODE128', width: 1, height: 60, margin: 0, displayValue: false });
            var modules = parseFloat(svg.getAttribute('width')) || 1;
            var dotMm = 25.4 / dpi;
            var dots = Math.floor(Math.floor(innerWidthMm / dotMm) / (modules + 20));
            var quality = dots >= 2 ? 'good' : (dots === 1 ? 'thin' : 'too-long');
            if (dots < 1) dots = 1;
            var barsMm = Math.min(modules * dots * dotMm, innerWidthMm);
            svg.setAttribute('viewBox', '0 0 ' + modules + ' 60');
            svg.setAttribute('preserveAspectRatio', 'none');
            svg.setAttribute('shape-rendering', 'crispEdges');
            svg.setAttribute('width', barsMm.toFixed(3) + 'mm');
            svg.setAttribute('height', '100%');
            svg.removeAttribute('style');
            svgCache[key] = { svg: svg, quality: quality };
        }
        return { svg: svgCache[key].svg.cloneNode(true), quality: svgCache[key].quality };
    }

    function makeLabel(item, size, set) {
        var data = state.data;
        var label = el('div', 'barcode-label');
        label.style.width = size.w + 'mm';
        label.style.height = size.h + 'mm';
        label.style.padding = '1.2mm 1.5mm';
        label.style.fontSize = Math.max(5, Math.min(9, size.h * 0.27)) + 'pt';
        if (shown('shop') && data.shop) label.appendChild(el('div', 'bl-shop', data.shop));
        var name = (shown('name') ? item.name : '') + (shown('variant') && item.variant ? (shown('name') ? ' - ' : '') + item.variant : '');
        if (name) label.appendChild(el('div', 'bl-name', name));
        var bars = el('div', 'bl-bars');
        var code = barcodeSvg(item.barcode, size.w - 3, set.dpi);
        bars.appendChild(code.svg);
        label.appendChild(bars);
        if (code.quality !== 'good') weak[item.barcode] = code.quality;
        if (shown('code')) label.appendChild(el('div', 'bl-code', item.barcode));
        if (shown('price') && item.price !== '') label.appendChild(el('div', 'bl-price', data.currency + ' ' + Number(item.price).toFixed(2)));
        var extra = '';
        try { extra = window.localStorage.getItem('barcode_label_extra_on') === '1' ? (window.localStorage.getItem('barcode_label_extra_text') || '').trim() : ''; } catch (e) { extra = ''; }
        if (extra) label.appendChild(el('div', 'bl-extra', extra));
        return label;
    }

    function pageSize(size, set) {
        return (set.rotate === 90 || set.rotate === 270) ? { w: size.h, h: size.w } : { w: size.w, h: size.h };
    }

    function buildSheets() {
        var set = settings();
        var size = layout(set);
        var labels = [];
        state.rows.forEach(function (row) { for (var i = 0; i < row.qty; i++) labels.push(row.item); });
        var sheets = [];
        if (size.type === 'thermal') {
            var page = pageSize(size, set);
            var turn = { 0: '', 90: 'translate(' + size.h + 'mm,0) rotate(90deg)',
                180: 'translate(' + size.w + 'mm,' + size.h + 'mm) rotate(180deg)', 270: 'translate(0,' + size.w + 'mm) rotate(-90deg)' }[set.rotate];
            labels.forEach(function (item) {
                var sheet = el('div', 'barcode-sheet');
                sheet.style.position = 'relative';
                sheet.style.width = page.w + 'mm';
                sheet.style.height = (page.h - 0.2) + 'mm';
                var label = makeLabel(item, size, set);
                var transform = ((set.offsetX || set.offsetY) ? 'translate(' + set.offsetX + 'mm,' + set.offsetY + 'mm) ' : '') + turn;
                if (transform.trim()) {
                    label.style.position = 'absolute';
                    label.style.left = '0';
                    label.style.top = '0';
                    label.style.transformOrigin = '0 0';
                    label.style.transform = transform;
                }
                sheet.appendChild(label);
                sheets.push(sheet);
            });
            return { sheets: sheets, size: size, page: page, count: labels.length };
        }
        var perSheet = size.cols * size.rows;
        var skip = Math.min(perSheet - 1, Math.max(0, (parseInt($('#bp-a4-start').val(), 10) || 1) - 1));
        var slots = new Array(skip).fill(null).concat(labels);
        for (var start = 0; start < slots.length; start += perSheet) {
            var sheet = el('div', 'barcode-sheet barcode-grid');
            sheet.style.width = '210mm';
            sheet.style.height = '297mm';
            sheet.style.padding = (size.padY + set.offsetY) + 'mm ' + (size.padX - set.offsetX) + 'mm ' + (size.padY - set.offsetY) + 'mm ' + (size.padX + set.offsetX) + 'mm';
            sheet.style.gridTemplateColumns = 'repeat(' + size.cols + ', ' + size.w + 'mm)';
            sheet.style.gridTemplateRows = 'repeat(' + size.rows + ', ' + size.h + 'mm)';
            sheet.style.columnGap = size.gapX + 'mm';
            sheet.style.rowGap = size.gapY + 'mm';
            slots.slice(start, start + perSheet).forEach(function (item) {
                sheet.appendChild(item ? makeLabel(item, size, set) : el('div', 'barcode-label'));
            });
            sheets.push(sheet);
        }
        return { sheets: sheets, size: size, page: { w: 210, h: 297 }, count: labels.length };
    }

    // ---------- Popup content ----------
    function renderTable() {
        var tbody = document.querySelector('#bp-items tbody');
        tbody.innerHTML = '';
        state.rows.forEach(function (row, index) {
            var item = row.item;
            var tr = el('tr');
            tr.innerHTML =
                '<td>' + (item.variant ? '<span class="badge badge-light border">' + esc(item.variant) + '</span>' : '<strong>' + esc(item.name) + '</strong>' + (state.data.has_variants ? ' <small class="text-muted">(product)</small>' : '')) + '</td>' +
                '<td class="text-monospace">' + esc(item.barcode) + '</td>' +
                '<td class="text-right text-nowrap">' + esc(state.data.currency) + ' ' + Number(item.price).toFixed(2) + '</td>' +
                '<td class="text-right">' + item.stock + '</td>' +
                '<td><input type="number" class="form-control form-control-sm bp-qty" min="0" max="500" data-index="' + index + '" value="' + row.qty + '"></td>';
            tbody.appendChild(tr);
        });
    }

    function render() {
        if (!state.data) return;
        var total = state.rows.reduce(function (sum, row) { return sum + row.qty; }, 0);
        var preview = document.getElementById('bp-preview');
        preview.innerHTML = '';
        weak = {};
        $('#bp-print').prop('disabled', total === 0).html('<i class="fas fa-print mr-1"></i>Print' + (total ? ' ' + total + ' Label' + (total > 1 ? 's' : '') : ''));
        if (!total) {
            preview.appendChild(el('p', 'text-muted text-center mb-0 w-100', 'Set the number of labels to see the preview.'));
            $('#bp-summary').text('');
            $('#bp-warning').addClass('d-none');
            return;
        }
        var built = buildSheets();
        built.sheets.slice(0, built.size.type === 'a4' ? 1 : 6).forEach(function (sheet) { preview.appendChild(sheet); });
        $('#bp-summary').text(built.count + ' label' + (built.count > 1 ? 's' : '') + (built.size.type === 'a4' ? ', ' + built.sheets.length + ' sheet(s)' : ''));
        var codes = Object.keys(weak);
        var tooLong = codes.some(function (code) { return weak[code] === 'too-long'; });
        $('#bp-warning').toggleClass('d-none', !codes.length).text(!codes.length ? '' : (tooLong
            ? 'Some barcodes may not scan (' + codes.join(', ') + '): too long for this label. Use a wider label or a shorter product Code.'
            : 'Thin barcodes (' + codes.join(', ') + '): bars are 1 printer dot wide. A wider label scans more reliably.'));
    }

    function print() {
        var built = buildSheets();
        if (!built.count) return;
        var page = built.size.type === 'a4' ? '210mm 297mm' : built.page.w + 'mm ' + built.page.h + 'mm';
        var css = LABEL_CSS +
            '@page{size:' + page + ';margin:0}' +
            'html,body{margin:0;padding:0;background:#fff}' +
            '.barcode-sheet{overflow:hidden;page-break-after:always;break-after:page}' +
            '.barcode-sheet:last-child{page-break-after:auto;break-after:auto}' +
            '*{-webkit-print-color-adjust:exact;print-color-adjust:exact}';
        var win = window.open('', 'barcodeLabelPrint');
        if (!win) { window.alert('Allow pop-ups for this site to print labels.'); return; }
        win.document.open();
        win.document.write('<!doctype html><html><head><meta charset="utf-8"><title>Barcode Labels</title><style>' + css + '</style></head><body></body></html>');
        win.document.close();
        built.sheets.forEach(function (sheet) { win.document.body.appendChild(win.document.importNode(sheet, true)); });
        win.focus();
        win.setTimeout(function () { win.print(); }, 300);
    }

    function open(productId, productName) {
        ensureModal();
        state.data = null;
        state.rows = [];
        $('#bp-title').html('<i class="fas fa-barcode mr-2"></i>Print Barcode' + (productName ? ' &mdash; ' + esc(productName) : ''));
        $('#bp-loading').removeClass('d-none');
        $('#bp-content, #bp-error').addClass('d-none');
        $('#bp-print').prop('disabled', true);
        $('#bp-modal').modal('show');

        $.ajax({ url: config.url, data: { id: productId }, dataType: 'json', cache: false })
            .done(function (data) {
                if (!data || !data.ok || !data.items || !data.items.length) {
                    $('#bp-error').removeClass('d-none').text((data && data.error) || 'No barcode found for this product.');
                    return;
                }
                state.data = data;
                // A product with variants is labelled per variant; otherwise one product label.
                state.rows = data.items.map(function (item) {
                    return { item: item, qty: data.has_variants ? (item.variant ? 1 : 0) : 1 };
                });
                $('#bp-modal [data-part-wrap="variant"]').toggleClass('d-none', !data.has_variants);
                if (data.has_variants && !Object.prototype.hasOwnProperty.call(readJson(PARTS_KEY), 'variant')) $('#bp-part-variant').prop('checked', true);
                renderTable();
                $('#bp-content').removeClass('d-none');
                render();
            })
            .fail(function (xhr) {
                var message = (xhr.responseJSON && xhr.responseJSON.error) || 'Could not load the barcode. Reload the page and try again.';
                $('#bp-error').removeClass('d-none').text(message);
            })
            .always(function () { $('#bp-loading').addClass('d-none'); });
    }

    // The product list shows each barcode as a picture (its number stays in the cell, hidden,
    // so the table search still finds it). Clicking the picture (or Enter on it) opens the popup.
    function drawListBarcodes() {
        if (!window.JsBarcode) return;
        $('.product-barcode-img').not('.bp-drawn').each(function () {
            var value = this.getAttribute('data-barcode');
            if (!value) return;
            var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            try {
                window.JsBarcode(svg, value, { format: 'CODE128', width: 1, height: 30, margin: 2, displayValue: false });
            } catch (e) {
                return;
            }
            // Stretch the bars to the fixed picture size (CSS), so all barcodes are equally wide.
            var width = parseFloat(svg.getAttribute('width')) || 100;
            var height = parseFloat(svg.getAttribute('height')) || 34;
            svg.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
            svg.setAttribute('preserveAspectRatio', 'none');
            svg.setAttribute('shape-rendering', 'crispEdges');
            svg.removeAttribute('width');
            svg.removeAttribute('height');
            svg.removeAttribute('style');
            this.appendChild(svg);
            this.classList.add('bp-drawn');
        });
    }
    $(document).on('click keydown', '.product-barcode-img', function (event) {
        if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        open(this.getAttribute('data-product-id'), this.getAttribute('data-product-name') || '');
    });
    // Rows of other table pages are drawn when DataTables shows them.
    $(document).on('draw.dt', drawListBarcodes);
    $(drawListBarcodes);
})(window.jQuery, window, document);
