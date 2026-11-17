        </div>
    </section>
</div>

<footer class="main-footer">

    <strong>
You2 Biz &copy; <?= date('Y'); ?>
    </strong>

</footer>

</div>

<?php $app_root_path = function_exists('app_root_path') ? app_root_path() : ''; ?>

<!-- jQuery -->
<script src="<?= htmlspecialchars(($app_root_path === '' ? '' : $app_root_path) . '/adminlte/plugins/jquery/jquery.min.js'); ?>"></script>

<!-- Bootstrap -->
<script src="<?= htmlspecialchars(($app_root_path === '' ? '' : $app_root_path) . '/adminlte/plugins/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>

<!-- DataTables -->
<script src="<?= htmlspecialchars(($app_root_path === '' ? '' : $app_root_path) . '/adminlte/plugins/datatables/jquery.dataTables.min.js'); ?>"></script>

<script src="<?= htmlspecialchars(($app_root_path === '' ? '' : $app_root_path) . '/adminlte/plugins/datatables-bs4/js/dataTables.bootstrap4.min.js'); ?>"></script>

<script src="<?= htmlspecialchars(($app_root_path === '' ? '' : $app_root_path) . '/adminlte/plugins/datatables-responsive/js/dataTables.responsive.min.js'); ?>"></script>

<script src="<?= htmlspecialchars(($app_root_path === '' ? '' : $app_root_path) . '/adminlte/plugins/datatables-responsive/js/responsive.bootstrap4.min.js'); ?>"></script>

<!-- Select2 -->
<script src="<?= htmlspecialchars(($app_root_path === '' ? '' : $app_root_path) . '/adminlte/plugins/select2/js/select2.full.min.js'); ?>"></script>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- AdminLTE -->
<script src="<?= htmlspecialchars(($app_root_path === '' ? '' : $app_root_path) . '/adminlte/dist/js/adminlte.min.js'); ?>"></script>

<script>

$(function () {

    $("table.table").each(function(){

        if(!$(this).parent().hasClass("table-responsive")){

            $(this).wrap(
                '<div class="table-responsive"></div>'
            );

        }

    });

    $("#example1, #example2").each(function(){
        if ($.fn.DataTable.isDataTable(this)) return;

        var tableResponsive = !$(this).is("[data-desktop-table]");

        $(this).DataTable({

            "responsive": tableResponsive,
            "autoWidth": false,
            "order": []

        });
    });

});

</script>
<?php if (function_exists('is_manager_user') && is_manager_user()) { ?>
<script>
(function () {
    const removeDeleteControls = function (root) {
        (root || document).querySelectorAll('a, button, input[type="submit"], input[type="button"]').forEach(function (control) {
            const href = (control.getAttribute('href') || '').toLowerCase();
            const label = [control.getAttribute('title'), control.getAttribute('name'), control.getAttribute('value'), control.dataset.action, control.dataset.staffAction, control.dataset.formAction].filter(Boolean).join(' ').toLowerCase();
            const isDeleteControl = control.dataset.staffAction === 'delete'
                || control.dataset.action === 'delete'
                || control.dataset.formAction === 'delete'
                || /(^|\/)delete[^/]*\.php(?:\?|$)/.test(href)
                || /\b(delete|remove|destroy)\b/.test(label);
            if (isDeleteControl) control.remove();
        });
    };
    removeDeleteControls(document);
    new MutationObserver(function (changes) {
        changes.forEach(function (change) { change.addedNodes.forEach(function (node) { if (node.nodeType === 1) removeDeleteControls(node); }); });
    }).observe(document.body, {childList: true, subtree: true});
})();
</script>
<?php } ?>
<?php
if (isset($page_script)) {
    echo $page_script;
}
?>

<style>
@media print {
    .report-print-heading {
        position: relative !important;
        display: block !important;
        text-align: left !important;
        border-top: 7px solid #1677e8 !important;
        border-bottom: 1px solid #ccd8e8 !important;
        padding: 13px 0 9px !important;
        margin: 0 0 12px !important;
    }
    .report-print-heading .print-brand {
        color: #0b2e59 !important;
        font-size: 19px !important;
        font-weight: 800 !important;
        letter-spacing: 1.4px !important;
        line-height: 1.1 !important;
    }
    .report-print-heading .print-brand span {
        color: #1677e8 !important;
        font-weight: 500 !important;
    }
    .report-print-heading .print-document-title {
        color: #14213d !important;
        font-size: 15px !important;
        font-weight: 700 !important;
        letter-spacing: 1.1px !important;
        margin-top: 5px !important;
        text-transform: uppercase !important;
    }
    .report-print-heading .print-report-meta {
        border-top: 1px solid #e2e9f2 !important;
        color: #5d6b82 !important;
        display: flex !important;
        font-size: 9px !important;
        justify-content: space-between !important;
        margin-top: 7px !important;
        padding-top: 6px !important;
    }
    .report-print-heading ~ .row .small-box {
        border-top: 3px solid #1677e8 !important;
        padding: 4px !important;
    }
    .report-print-heading ~ .row .small-box h3 { font-size: 20px !important; }
    .report-print-heading ~ .card .card-header .card-title,
    .report-print-heading ~ .row .card-header .card-title {
        color: #12375d !important;
        font-size: 12px !important;
        font-weight: 700 !important;
    }
    .report-print-heading ~ .card .card-body > div[style],
    .report-print-heading ~ .row .card-body > div[style] { height: 210px !important; }
    .report-print-heading ~ .card,
    .report-print-heading ~ .row .card { page-break-inside: avoid !important; }
    .report-print-heading ~ .card tfoot th,
    .report-print-heading ~ .card tfoot td,
    .report-print-heading ~ .row tfoot th,
    .report-print-heading ~ .row tfoot td {
        background: #edf4fb !important;
        font-weight: 700 !important;
    }
    .report-print-heading ~ .card:last-child { margin-bottom: 0 !important; }
    .report-print-heading ~ .card:last-child:after,
    .report-print-heading ~ .row:last-child:after {
        border-top: 1px solid #dce5ef !important;
        color: #6a7890 !important;
        content: "This report is system generated by You2 Technologies" !important;
        display: block !important;
        font-size: 8px !important;
        margin-top: 8px !important;
        padding-top: 5px !important;
        text-align: center !important;
    }
}
</style>

<?php if(function_exists('is_manager_user') && is_manager_user()){
    $footer_restricted_selectors = [
            '[title*="Delete" i]',
            '[aria-label*="Delete" i]',
            'a[href*="delete" i]',
            'a[href*="del=" i]',
            'input[type="submit"][value*="Delete" i]',
            'button[value*="delete" i]',
            'button[name="action"][value*="delete" i]',
        ];
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const restrictedSelector = <?= json_encode($footer_restricted_selectors); ?>.join(',');

    document.querySelectorAll(restrictedSelector).forEach(function (element) {
        element.remove();
    });
});
</script>
<?php } ?>

<?php if (function_exists('stock_csrf_token')) { ?>
<script>
(function () {
    const token = <?= json_encode(stock_csrf_token()); ?>;
    const localStockUrl = function (value) {
        const url = new URL(value || location.href, location.href);
        return url.origin === location.origin && /\/(sales|products|product_categories|purchases|suppliers|warehouse)\//.test(url.pathname);
    };
    document.querySelectorAll('form').forEach(function (form) {
        if (form.method.toLowerCase() !== 'post' || !localStockUrl(form.action)) return;
        if (!form.querySelector('[name="stock_csrf"]')) {
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = 'stock_csrf'; input.value = token;
            form.appendChild(input);
        }
    });
    if (window.jQuery) $.ajaxPrefilter(function (options) {
        if ((options.type || 'GET').toUpperCase() !== 'POST' || !localStockUrl(options.url)) return;
        if (options.data instanceof FormData) options.data.set('stock_csrf', token);
        else if (typeof options.data === 'string') options.data += '&stock_csrf=' + encodeURIComponent(token);
        else options.data = Object.assign({}, options.data, {stock_csrf: token});
    });
})();
</script>
<?php } ?>
<?php
require_once __DIR__ . '/bill_reminder_helper.php';

$bill_reminder = null;
$bill_countdown = 0;
$bill_reminder_flash = $_SESSION['bill_reminder_flash'] ?? null;
unset($_SESSION['bill_reminder_flash']);

if(!empty($_SESSION['bill_reminder_notice_pending']) && isset($conn) && $conn instanceof mysqli){
    try {
        $bill_reminder = bill_reminder_status_for_session($conn);
    } catch(Throwable $error) {
        // A notice must never prevent normal application pages from loading.
        error_log('Bill reminder popup: ' . $error->getMessage());
    }

    if($bill_reminder && $bill_reminder['mode'] === 'countdown'){
        // The countdown end is kept in the session, so reloading or opening
        // another page continues it instead of skipping it.
        if(empty($_SESSION['bill_reminder_countdown_until'])){
            $_SESSION['bill_reminder_countdown_until'] = time() + BILL_REMINDER_COUNTDOWN_SECONDS;
        }

        $bill_countdown = max(0, (int)$_SESSION['bill_reminder_countdown_until'] - time());

        if($bill_countdown === 0){
            $bill_reminder = null;
        }
    }

    // A locked company never reaches this point: header.php sends it to the payment page.
    if(!$bill_reminder || $bill_reminder['mode'] !== 'countdown'){
        unset($_SESSION['bill_reminder_notice_pending'], $_SESSION['bill_reminder_countdown_until']);
    }

    if($bill_reminder && $bill_reminder['mode'] === 'locked'){
        $bill_reminder = null;
    }
}

if($bill_reminder):
    $bill_qr_url = bill_reminder_qr_url($conn);
    $return_to = $_SERVER['REQUEST_URI'] ?? app_path('dashboard.php');
?>
<div class="modal fade" id="billReminderModal" tabindex="-1" role="dialog" aria-labelledby="billReminderModalLabel" aria-hidden="true" <?= $bill_countdown > 0 ? 'data-backdrop="static" data-keyboard="false"' : ''; ?>>
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header <?= $bill_countdown > 0 ? 'bg-danger' : 'bg-primary'; ?>">
                <h5 class="modal-title" id="billReminderModalLabel"><i class="fas fa-receipt mr-1"></i> Bill Payment Reminder</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" id="billReminderClose" <?= $bill_countdown > 0 ? 'style="display:none"' : ''; ?>><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body text-center">
                <?php if(is_array($bill_reminder_flash)): ?>
                    <div class="alert alert-<?= htmlspecialchars($bill_reminder_flash['type'] ?? 'info'); ?> text-left">
                        <?= htmlspecialchars($bill_reminder_flash['message'] ?? ''); ?>
                    </div>
                <?php endif; ?>
                <?php if($bill_countdown > 0): ?>
                    <div class="alert alert-danger text-left mb-3">
                        Your bill is overdue by <?= (int)$bill_reminder['days_since_bill']; ?> days. Please pay before the next bill is generated, otherwise the software will be locked.
                        <div class="font-weight-bold mt-1" id="billReminderCountdown" data-seconds="<?= (int)$bill_countdown; ?>">You can close this window in <span><?= sprintf('%d:%02d', intdiv($bill_countdown, 60), $bill_countdown % 60); ?></span></div>
                    </div>
                <?php endif; ?>
                <p class="mb-1">Your company bill is due.</p>
                <div class="h3 font-weight-bold text-primary mb-1">BDT <?= number_format((float)$bill_reminder['service_charge_amount'], 2); ?></div>
                <p class="text-muted mb-3">Current bill date: <?= htmlspecialchars(date('d-m-Y', strtotime($bill_reminder['bill_cycle_date']))); ?></p>

                <?php if($bill_qr_url !== ''): ?>
                    <p class="font-weight-bold mb-2">Scan to pay (Bangla QR)</p>
                    <img src="<?= htmlspecialchars($bill_qr_url); ?>" class="img-fluid border rounded p-2 mb-3" alt="Bill receive QR code" style="max-width:230px; max-height:230px;">
                <?php else: ?>
                    <div class="alert alert-warning text-left">Payment QR code has not been configured yet. Please contact support.</div>
                <?php endif; ?>

                <?php $bill_is_pending = ($bill_reminder['reference_status'] === 'pending'); ?>
                <?php $bill_is_rejected = ($bill_reminder['reference_status'] === 'rejected'); ?>
                <form method="post" action="<?= htmlspecialchars(app_path('billing/save_bill_payment_reference.php')); ?>" class="text-left">
                    <input type="hidden" name="return_to" value="<?= htmlspecialchars($return_to); ?>">
                    <label for="bill_payment_reference" class="font-weight-bold">Payment Reference No.</label>
                    <input type="text" class="form-control" id="bill_payment_reference" name="reference_no" maxlength="150" <?= $bill_is_pending ? 'readonly' : 'required'; ?> value="<?= $bill_is_rejected ? '' : htmlspecialchars($bill_reminder['reference_no']); ?>" placeholder="Enter payment reference number">
                    <small class="form-text text-danger">Use the correct reference to avoid an access ban.</small>
                    <?php if($bill_is_pending): ?>
                        <small class="form-text text-warning font-weight-bold"><?= htmlspecialchars($bill_reminder['reference_no']); ?> || Waiting for Approval</small>
                    <?php elseif($bill_is_rejected): ?>
                        <small class="form-text text-danger"><?= htmlspecialchars($bill_reminder['reference_no']); ?> || Rejected. Please submit the correct reference.</small>
                    <?php endif; ?>
                    <?php if(!$bill_is_pending): ?>
                        <button type="submit" class="btn btn-success btn-block mt-3"><i class="fas fa-paper-plane mr-1"></i> Submit Reference No.</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
$(function () {
    $('#billReminderModal').modal('show');
    var countdown = document.getElementById('billReminderCountdown');
    if (!countdown) return;
    // The window can be closed only when the countdown reaches zero.
    var seconds = parseInt(countdown.getAttribute('data-seconds'), 10) || 0;
    var label = countdown.querySelector('span');
    var timer = setInterval(function () {
        seconds = Math.max(0, seconds - 1);
        label.textContent = Math.floor(seconds / 60) + ':' + ('0' + (seconds % 60)).slice(-2);
        if (seconds === 0) {
            clearInterval(timer);
            countdown.textContent = 'You can close this window now.';
            document.getElementById('billReminderClose').style.display = '';
            var modal = $('#billReminderModal').data('bs.modal');
            if (modal) { modal._config.backdrop = true; modal._config.keyboard = true; }
        }
    }, 1000);
});
</script>
<?php endif; ?>
<script>
// Instant photo preview: choosing an image in any file field shows it at once.
// On edit pages the current photo just before the field is replaced by the new one.
// Pages that already draw their own preview are left alone.
(function () {
    var URLS = window.URL || window.webkitURL;
    if (!URLS || !URLS.createObjectURL) return;
    var previewSrcs = function () {
        return Array.prototype.map.call(document.images, function (img) { return img.getAttribute('src') || ''; });
    };
    var currentPhoto = function (input) {
        var prev = input.previousElementSibling;
        if (!prev) return null;
        if (prev.tagName === 'IMG') return prev;
        var imgs = prev.querySelectorAll ? prev.querySelectorAll('img') : [];
        return imgs.length === 1 && prev.children.length === 1 ? imgs[0] : null;
    };
    var anchorOf = function (input) {
        // Put the preview after the field's wrapper (custom-file / input-group), else after the field.
        var wrap = input.closest('.custom-file, .input-group');
        return wrap || input;
    };
    document.addEventListener('change', function (event) {
        var input = event.target;
        if (!input || input.tagName !== 'INPUT' || input.type !== 'file' || input.hasAttribute('data-no-instant-preview')) return;
        var files = Array.prototype.filter.call(input.files || [], function (file) { return /^image\//.test(file.type); });
        var before = previewSrcs();
        var box = input._instantPreviewBox;
        var photo = input._instantPreviewPhoto || currentPhoto(input);
        if (photo && !input._instantPreviewPhoto) { input._instantPreviewPhoto = photo; photo.setAttribute('data-original-src', photo.getAttribute('src') || ''); }
        // Wait a moment: if the page itself put a new image preview on screen, keep only that one.
        setTimeout(function () {
            var after = previewSrcs();
            var pageDrewPreview = after.some(function (src, i) { return src !== before[i] && /^(data:|blob:)/.test(src) && !(box && box.contains(document.images[i])) && document.images[i] !== photo; });
            if (box) { box.innerHTML = ''; box.style.display = 'none'; }
            if (photo && !files.length) photo.setAttribute('src', photo.getAttribute('data-original-src') || '');
            if (!files.length || pageDrewPreview) return;
            if (photo && files.length === 1) { photo.setAttribute('src', URLS.createObjectURL(files[0])); photo.title = files[0].name; return; }
            if (!box) {
                box = document.createElement('div');
                box.className = 'instant-photo-preview';
                box.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;margin-top:8px';
                var anchor = anchorOf(input);
                anchor.parentNode.insertBefore(box, anchor.nextSibling);
                input._instantPreviewBox = box;
            }
            box.style.display = 'flex';
            files.slice(0, 6).forEach(function (file) {
                var link = document.createElement('a');
                var url = URLS.createObjectURL(file);
                link.href = url; link.target = '_blank'; link.rel = 'noopener'; link.title = file.name;
                var img = document.createElement('img');
                img.src = url; img.alt = file.name;
                img.style.cssText = 'width:110px;height:110px;object-fit:cover;border-radius:8px;border:1px solid #d1d5db;box-shadow:0 1px 3px rgba(0,0,0,.08)';
                link.appendChild(img);
                box.appendChild(link);
            });
            // A page preview that shows up a little later wins: remove ours then.
            if (window.MutationObserver) {
                var watcher = new MutationObserver(function (changes) {
                    var drawn = function (el) { return el && el.tagName === 'IMG' && el !== photo && !box.contains(el) && /^(data:|blob:)/.test(el.getAttribute('src') || ''); };
                    changes.forEach(function (change) {
                        var hit = drawn(change.target);
                        Array.prototype.forEach.call(change.addedNodes || [], function (node) {
                            if (drawn(node) || (node.querySelectorAll && Array.prototype.some.call(node.querySelectorAll('img'), drawn))) hit = true;
                        });
                        if (hit) { box.innerHTML = ''; box.style.display = 'none'; watcher.disconnect(); }
                    });
                });
                watcher.observe(document.body, {attributes: true, attributeFilter: ['src'], childList: true, subtree: true});
                setTimeout(function () { watcher.disconnect(); }, 3000);
            }
        }, 350);
    }, true);
})();
</script>
<style>
.table td img:not(.no-zoom),.form-group img:not(.no-zoom){cursor:zoom-in}
.table td a img,.table td button img,.form-group a img,.form-group button img{cursor:pointer}
.photo-zoom-overlay{position:fixed;inset:0;z-index:2050;display:flex;align-items:center;justify-content:center;flex-direction:column;background:rgba(10,18,32,.86);padding:24px;cursor:zoom-out}
.photo-zoom-overlay img{max-width:92vw;max-height:80vh;object-fit:contain;border-radius:8px;background:#fff;box-shadow:0 18px 60px rgba(0,0,0,.45);cursor:default}
.photo-zoom-bar{display:flex;align-items:center;gap:14px;margin-top:14px;color:#e8eef7;font-size:14px;max-width:92vw}
.photo-zoom-bar span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.photo-zoom-bar a,.photo-zoom-bar button{color:#fff;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.25);border-radius:6px;padding:5px 12px;font-size:13px;text-decoration:none;cursor:pointer}
.photo-zoom-bar a:hover,.photo-zoom-bar button:hover{background:rgba(255,255,255,.25)}
</style>
<script>
// Photo zoom: clicking a small photo in a table (or a current photo in a form) opens it big.
// Photos that already open something (inside a link or button) keep their own behaviour.
(function () {
    var overlay = null;
    var close = function () { if (overlay) { overlay.remove(); overlay = null; document.removeEventListener('keydown', onKey); } };
    var onKey = function (e) { if (e.key === 'Escape') close(); };
    var open = function (src, title) {
        close();
        overlay = document.createElement('div');
        overlay.className = 'photo-zoom-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        var img = document.createElement('img');
        img.src = src; img.alt = title || '';
        var bar = document.createElement('div');
        bar.className = 'photo-zoom-bar';
        var name = document.createElement('span'); name.textContent = title || '';
        var link = document.createElement('a'); link.href = src; link.target = '_blank'; link.rel = 'noopener'; link.textContent = 'Open original';
        var btn = document.createElement('button'); btn.type = 'button'; btn.textContent = 'Close (Esc)';
        bar.append(name, link, btn);
        overlay.append(img, bar);
        overlay.addEventListener('click', function (e) { if (e.target === overlay || e.target === btn) close(); });
        document.body.appendChild(overlay);
        document.addEventListener('keydown', onKey);
        btn.focus();
    };
    document.addEventListener('click', function (e) {
        var img = e.target;
        if (!img || img.tagName !== 'IMG' || img.classList.contains('no-zoom')) return;
        if (img.closest('a, button, .photo-zoom-overlay, [data-toggle="modal"]')) return;
        if (!img.closest('.table td, .form-group')) return;
        var src = img.currentSrc || img.src;
        if (!src || /\.svg(\?|$)/i.test(src)) return;
        e.preventDefault();
        open(src, img.getAttribute('alt') || img.getAttribute('title') || '');
    });
})();
</script>
<?php $photo_placeholder_src = function_exists('branding_logo_url') ? branding_logo_url($conn ?? null) : ''; ?>
<script>
// photo-col-auto-hide: a table "Photo" column is hidden when no row has a real product
// photo (every image is the default logo).
(function(){
    var placeholder = <?= json_encode((string)$photo_placeholder_src) ?>;
    if (!placeholder) return;
    var css = [];
    document.querySelectorAll('table').forEach(function(table, t){
        var head = table.tHead && table.tHead.rows[table.tHead.rows.length - 1];
        if (!head) return;
        var index = Array.prototype.findIndex.call(head.cells, function(th){ return th.textContent.trim().toLowerCase() === 'photo'; });
        if (index < 0 || head.cells[index].classList.contains('photo-column') || table.hasAttribute('data-keep-photo')) return;
        var images = [];
        Array.prototype.forEach.call(table.tBodies, function(body){
            Array.prototype.forEach.call(body.rows, function(row){
                if (row.cells.length === 1 && row.cells[0].colSpan > 1) return;
                var cell = row.cells[index];
                if (cell) cell.querySelectorAll('img').forEach(function(img){ images.push(img.getAttribute('src') || ''); });
            });
        });
        if (images.some(function(src){ return src !== '' && src !== placeholder; })) return;
        var key = 'photo-col-hidden-' + t;
        table.classList.add(key);
        var col = table.querySelector('colgroup col:nth-child(' + (index + 1) + ')');
        if (col) col.remove();
        css.push('table.' + key + ' > * > tr > *:nth-child(' + (index + 1) + '):not(:only-child){display:none}');
    });
    if (css.length) { var style = document.createElement('style'); style.textContent = css.join('\n'); document.head.appendChild(style); }
})();
</script>
</body>
</html>
