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
</body>
</html>
