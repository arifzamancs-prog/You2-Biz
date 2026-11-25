/*
 * Small image cropper for a file input (no external library).
 * ImageCropper.attach(input, { preview: img, square: false })
 * Choosing a file opens a crop box: drag to move, drag a corner to resize,
 * "Square" locks 1:1. "Apply" puts the cropped image back into the input,
 * so the normal form upload sends it.
 */
(function (window, document) {
    'use strict';

    var MAX_SIDE = 1200;
    var MIN_BOX = 24;

    function el(tag, attrs, html) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (key) { node.setAttribute(key, attrs[key]); });
        if (html) node.innerHTML = html;
        return node;
    }

    function injectStyle() {
        if (document.getElementById('image-cropper-style')) return;
        var style = el('style', { id: 'image-cropper-style' });
        style.textContent =
            '.ic-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.72);z-index:2000;display:flex;align-items:center;justify-content:center;padding:16px}' +
            '.ic-dialog{background:#fff;border-radius:8px;max-width:760px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.35);overflow:hidden}' +
            '.ic-head{padding:12px 16px;border-bottom:1px solid #e5e7eb;font-weight:600;display:flex;justify-content:space-between;align-items:center}' +
            '.ic-body{padding:16px;text-align:center;background:#f8fafc}' +
            '.ic-stage{position:relative;display:inline-block;user-select:none;touch-action:none;line-height:0;' +
            'background-image:linear-gradient(45deg,#e5e7eb 25%,transparent 25%),linear-gradient(-45deg,#e5e7eb 25%,transparent 25%),linear-gradient(45deg,transparent 75%,#e5e7eb 75%),linear-gradient(-45deg,transparent 75%,#e5e7eb 75%);background-size:16px 16px;background-position:0 0,0 8px,8px -8px,-8px 0}' +
            '.ic-stage img{display:block;max-width:100%;max-height:60vh;pointer-events:none}' +
            '.ic-box{position:absolute;box-sizing:border-box;border:2px solid #fff;outline:1px solid rgba(0,0,0,.5);box-shadow:0 0 0 9999px rgba(0,0,0,.5);cursor:move}' +
            '.ic-box:before,.ic-box:after{content:"";position:absolute;border:0 dashed rgba(255,255,255,.6)}' +
            '.ic-box:before{left:33.33%;right:33.33%;top:0;bottom:0;border-left-width:1px;border-right-width:1px}' +
            '.ic-box:after{top:33.33%;bottom:33.33%;left:0;right:0;border-top-width:1px;border-bottom-width:1px}' +
            '.ic-handle{position:absolute;width:14px;height:14px;background:#fff;border:2px solid #2563eb;border-radius:2px;z-index:1}' +
            '.ic-handle[data-h=nw]{left:-8px;top:-8px;cursor:nwse-resize}.ic-handle[data-h=ne]{right:-8px;top:-8px;cursor:nesw-resize}' +
            '.ic-handle[data-h=sw]{left:-8px;bottom:-8px;cursor:nesw-resize}.ic-handle[data-h=se]{right:-8px;bottom:-8px;cursor:nwse-resize}' +
            '.ic-foot{padding:12px 16px;border-top:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap}';
        document.head.appendChild(style);
    }

    function attach(input, options) {
        if (!input || !window.FileReader || !window.HTMLCanvasElement) return;
        options = options || {};
        injectStyle();

        var preview = options.preview || null;
        var originalFile = null;

        // Setting input.files from script fires no change event, so Apply does not reopen this.
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file || !/^image\//.test(file.type || '')) return;
            originalFile = file;
            open(file);
        });

        if (options.recropButton) {
            options.recropButton.addEventListener('click', function () {
                if (originalFile) open(originalFile);
            });
        }

        function open(file) {
            var reader = new FileReader();
            reader.onload = function (event) { show(file, event.target.result); };
            reader.readAsDataURL(file);
        }

        function show(file, dataUrl) {
            var backdrop = el('div', { 'class': 'ic-backdrop' });
            var dialog = el('div', { 'class': 'ic-dialog', role: 'dialog', 'aria-label': 'Crop photo' });
            dialog.appendChild(el('div', { 'class': 'ic-head' }, '<span>Crop Photo</span><small class="text-muted font-weight-normal">Drag to move, drag a corner to resize</small>'));
            var body = el('div', { 'class': 'ic-body' });
            var stage = el('div', { 'class': 'ic-stage' });
            var img = el('img', { alt: '' });
            var box = el('div', { 'class': 'ic-box' });
            ['nw', 'ne', 'sw', 'se'].forEach(function (h) { box.appendChild(el('span', { 'class': 'ic-handle', 'data-h': h })); });
            stage.appendChild(img);
            stage.appendChild(box);
            body.appendChild(stage);
            dialog.appendChild(body);

            var foot = el('div', { 'class': 'ic-foot' });
            var squareId = 'ic-square-' + Date.now();
            foot.appendChild(el('div', { 'class': 'custom-control custom-checkbox' },
                '<input type="checkbox" class="custom-control-input" id="' + squareId + '"' + (options.square ? ' checked' : '') + '>' +
                '<label class="custom-control-label" for="' + squareId + '">Square</label>'));
            var buttons = el('div', {},
                '<button type="button" class="btn btn-outline-secondary btn-sm mr-1" data-act="full">Full Image</button>' +
                '<button type="button" class="btn btn-secondary btn-sm mr-1" data-act="cancel">Cancel</button>' +
                '<button type="button" class="btn btn-primary btn-sm" data-act="apply"><i class="fas fa-crop-alt mr-1"></i>Apply</button>');
            foot.appendChild(buttons);
            dialog.appendChild(foot);
            backdrop.appendChild(dialog);
            document.body.appendChild(backdrop);

            var square = foot.querySelector('#' + squareId);
            var crop = { x: 0, y: 0, w: 0, h: 0 }; // in displayed pixels
            var stageW = 0, stageH = 0;

            function render() {
                box.style.left = crop.x + 'px';
                box.style.top = crop.y + 'px';
                box.style.width = crop.w + 'px';
                box.style.height = crop.h + 'px';
            }

            function reset(full) {
                stageW = img.clientWidth;
                stageH = img.clientHeight;
                if (square.checked) {
                    var side = Math.min(stageW, stageH) * (full ? 1 : 0.9);
                    crop = { x: (stageW - side) / 2, y: (stageH - side) / 2, w: side, h: side };
                } else if (full) {
                    crop = { x: 0, y: 0, w: stageW, h: stageH };
                } else {
                    crop = { x: stageW * 0.05, y: stageH * 0.05, w: stageW * 0.9, h: stageH * 0.9 };
                }
                render();
            }

            img.onload = function () { reset(false); };
            img.src = dataUrl;

            square.addEventListener('change', function () { reset(false); });

            var drag = null;
            box.addEventListener('pointerdown', function (event) {
                event.preventDefault();
                drag = {
                    handle: event.target.getAttribute('data-h') || 'move',
                    startX: event.clientX,
                    startY: event.clientY,
                    start: { x: crop.x, y: crop.y, w: crop.w, h: crop.h }
                };
                box.setPointerCapture(event.pointerId);
            });

            box.addEventListener('pointermove', function (event) {
                if (!drag) return;
                var dx = event.clientX - drag.startX;
                var dy = event.clientY - drag.startY;
                var s = drag.start;

                if (drag.handle === 'move') {
                    crop.x = Math.min(Math.max(0, s.x + dx), stageW - s.w);
                    crop.y = Math.min(Math.max(0, s.y + dy), stageH - s.h);
                    render();
                    return;
                }

                // The opposite corner stays fixed while this one moves.
                var west = drag.handle.indexOf('w') !== -1;
                var north = drag.handle.indexOf('n') !== -1;
                var fixedX = west ? s.x + s.w : s.x;
                var fixedY = north ? s.y + s.h : s.y;
                var maxW = west ? fixedX : stageW - fixedX;
                var maxH = north ? fixedY : stageH - fixedY;
                var w = Math.min(Math.max(MIN_BOX, (west ? -dx : dx) + s.w), maxW);
                var h = Math.min(Math.max(MIN_BOX, (north ? -dy : dy) + s.h), maxH);

                if (square.checked) {
                    var side = Math.min(Math.max(w, h), maxW, maxH);
                    w = h = side;
                }

                crop.w = w;
                crop.h = h;
                crop.x = west ? fixedX - w : fixedX;
                crop.y = north ? fixedY - h : fixedY;
                render();
            });

            function endDrag() { drag = null; }
            box.addEventListener('pointerup', endDrag);
            box.addEventListener('pointercancel', endDrag);

            function close() {
                document.removeEventListener('keydown', onKey);
                backdrop.remove();
            }

            function onKey(event) { if (event.key === 'Escape') cancel(); }
            document.addEventListener('keydown', onKey);

            function cancel() {
                close();
            }

            function apply() {
                var scale = img.naturalWidth / stageW;
                var sx = Math.round(crop.x * scale);
                var sy = Math.round(crop.y * scale);
                var sw = Math.max(1, Math.round(crop.w * scale));
                var sh = Math.max(1, Math.round(crop.h * scale));
                var outScale = Math.min(1, MAX_SIDE / Math.max(sw, sh));
                var canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(sw * outScale));
                canvas.height = Math.max(1, Math.round(sh * outScale));
                var ctx = canvas.getContext('2d');
                var isJpeg = /jpe?g$/i.test(file.type);
                if (isJpeg) {
                    ctx.fillStyle = '#fff';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                }
                ctx.imageSmoothingQuality = 'high';
                ctx.drawImage(img, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);

                var type = isJpeg ? 'image/jpeg' : 'image/png';
                var baseName = (file.name || 'photo').replace(/\.[^.]+$/, '');
                canvas.toBlob(function (blob) {
                    if (!blob) { close(); return; }
                    var cropped = new File([blob], baseName + (isJpeg ? '.jpg' : '.png'), { type: type });
                    try {
                        var transfer = new DataTransfer();
                        transfer.items.add(cropped);
                        input.files = transfer.files;
                    } catch (error) {
                        // Very old browsers: the original file is uploaded uncropped.
                    }
                    if (preview) preview.src = canvas.toDataURL(type, 0.92);
                    if (options.recropButton) options.recropButton.classList.remove('d-none');
                    close();
                }, type, 0.92);
            }

            buttons.addEventListener('click', function (event) {
                var action = event.target.closest('[data-act]');
                if (!action) return;
                var act = action.getAttribute('data-act');
                if (act === 'cancel') cancel();
                else if (act === 'full') reset(true);
                else if (act === 'apply') apply();
            });

            // Without cropping, the chosen file still shows in the preview.
            if (preview) {
                preview.src = dataUrl;
            }
        }
    }

    window.ImageCropper = { attach: attach };
})(window, document);
