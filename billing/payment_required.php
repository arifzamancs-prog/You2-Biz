<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/app_config.php';
require_once '../includes/branding_helper.php';
require_once '../includes/bill_reminder_helper.php';

// Shown instead of every page while an older service charge bill is unpaid.
$status = null;

try {
    $status = bill_reminder_status_for_session($conn);
} catch(Throwable $error) {
    error_log('Bill payment page: ' . $error->getMessage());
}

if(!$status || $status['mode'] !== 'locked'){
    header('Location: ' . app_path('dashboard.php'));
    exit;
}

$flash = $_SESSION['bill_reminder_flash'] ?? null;
unset($_SESSION['bill_reminder_flash']);
$qr_url = bill_reminder_qr_url($conn);
$can_submit = !$status['all_pending'];
$emergency = BILL_REMINDER_EMERGENCY_CONTACT;
$emergency_digits = preg_replace('/\D/', '', $emergency);
$whatsapp_text = 'Emergency access request: ' . $status['name'] . ' (service charge BDT ' . number_format($status['total'], 2) . ')';
$app_root_path = app_root_path();
$asset = function($path) use ($app_root_path){ return htmlspecialchars($app_root_path . '/' . $path); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Service Charge Payment Required</title>
<link rel="icon" href="<?= htmlspecialchars(branding_favicon_url($conn)); ?>">
<link rel="stylesheet" href="<?= $asset('adminlte/plugins/fontawesome-free/css/all.min.css'); ?>">
<link rel="stylesheet" href="<?= $asset('adminlte/dist/css/adminlte.min.css'); ?>">
<style>
body{background:#f4f6f9;min-height:100vh}
.payment-wrap{max-width:760px;margin:0 auto;padding:32px 16px}
.bill-total{font-size:2.2rem;font-weight:700;color:#dc3545}
.emergency{border:2px dashed #dc3545;border-radius:10px;background:#fff5f5}
.qr{max-width:230px;max-height:230px}
</style>
</head>
<body>
<div class="payment-wrap">
    <div class="card card-outline card-danger shadow-sm">
        <div class="card-header text-center">
            <h3 class="card-title float-none"><i class="fas fa-lock mr-1 text-danger"></i> Service Charge Payment Required</h3>
        </div>
        <div class="card-body">
            <?php if(is_array($flash)): ?>
                <div class="alert alert-<?= htmlspecialchars($flash['type'] ?? 'info'); ?>"><?= htmlspecialchars($flash['message'] ?? ''); ?></div>
            <?php endif; ?>

            <p class="text-center mb-1"><strong><?= htmlspecialchars($status['name']); ?></strong>, your software access is paused because a previous month's service charge is unpaid.</p>
            <p class="text-center text-muted">Access is restored automatically after Super Admin approves your payment.</p>

            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead class="thead-light"><tr><th>Bill Date</th><th class="text-right">Amount</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach($status['bills'] as $bill): ?>
                        <tr>
                            <td><?= htmlspecialchars(date('d-m-Y', strtotime($bill['bill_cycle_date']))); ?></td>
                            <td class="text-right">BDT <?= number_format((float)$bill['amount'], 2); ?></td>
                            <td>
                                <?php if($bill['reference_status'] === 'pending'): ?><span class="badge badge-warning">Waiting for Approval</span> <small><?= htmlspecialchars($bill['reference_no']); ?></small>
                                <?php elseif($bill['reference_status'] === 'rejected'): ?><span class="badge badge-danger">Rejected</span> <small><?= htmlspecialchars($bill['reference_no']); ?></small>
                                <?php else: ?><span class="badge badge-secondary">Unpaid</span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr><th>Total Due</th><th class="text-right bill-total">BDT <?= number_format((float)$status['total'], 2); ?></th><th></th></tr></tfoot>
                </table>
            </div>

            <div class="row align-items-center">
                <div class="col-md-5 text-center mb-3">
                    <?php if($qr_url !== ''): ?>
                        <p class="font-weight-bold mb-2">Scan to pay (Bangla QR)</p>
                        <img src="<?= htmlspecialchars($qr_url); ?>" class="img-fluid border rounded p-2 qr" alt="Bill receive QR code">
                    <?php else: ?>
                        <div class="alert alert-warning">Payment QR code has not been configured yet. Please contact support.</div>
                    <?php endif; ?>
                </div>
                <div class="col-md-7 mb-3">
                    <?php if($can_submit): ?>
                        <form method="post" action="<?= htmlspecialchars(app_path('billing/save_bill_payment_reference.php')); ?>">
                            <input type="hidden" name="return_to" value="<?= htmlspecialchars(bill_reminder_lock_path()); ?>">
                            <label for="bill_payment_reference" class="font-weight-bold">Payment Reference No.</label>
                            <input type="text" class="form-control" id="bill_payment_reference" name="reference_no" maxlength="150" required placeholder="Enter payment reference number">
                            <small class="form-text text-muted">One reference covers the total of all unpaid bills above.</small>
                            <small class="form-text text-danger">Use the correct reference to avoid an access ban.</small>
                            <button type="submit" class="btn btn-success btn-block mt-3"><i class="fas fa-paper-plane mr-1"></i> Submit Reference No.</button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-warning mb-0"><i class="fas fa-hourglass-half mr-1"></i> Your payment reference is waiting for Super Admin approval. Access will be restored once it is approved.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="emergency p-3 text-center">
                <h5 class="text-danger mb-1"><i class="fas fa-phone-alt mr-1"></i> Emergency Contact</h5>
                <p class="mb-2">Need urgent access? Send a request to <strong><?= htmlspecialchars($emergency); ?></strong></p>
                <a class="btn btn-danger mr-2 mb-1" href="tel:<?= htmlspecialchars($emergency); ?>"><i class="fas fa-phone mr-1"></i> Call</a>
                <a class="btn btn-success mb-1" target="_blank" rel="noopener" href="https://wa.me/<?= htmlspecialchars($emergency_digits); ?>?text=<?= rawurlencode($whatsapp_text); ?>"><i class="fab fa-whatsapp mr-1"></i> WhatsApp Request</a>
            </div>
        </div>
        <div class="card-footer text-center">
            <a href="<?= htmlspecialchars(app_path('logout.php')); ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-sign-out-alt mr-1"></i> Logout</a>
        </div>
    </div>
</div>
</body>
</html>
