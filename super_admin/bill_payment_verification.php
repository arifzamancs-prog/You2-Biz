<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/app_config.php';
require_once '../includes/bill_reminder_helper.php';

require_super_admin_user();
ensure_company_bill_payment_references_table($conn);

$token = trim((string)($_REQUEST['token'] ?? ''));
$token_hash = bill_reminder_verification_token_hash($token);
$message = '';
$message_type = 'danger';

if($token === ''){
    $message = 'Verification link is invalid.';
} else {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT r.*, u.name AS company_name, u.email AS company_email
         FROM company_bill_payment_references r
         INNER JOIN users u ON u.id = r.company_id
         WHERE r.verification_token_hash = ?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 's', $token_hash);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $reference = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    if(!$reference){
        $message = 'Verification link is invalid or has been replaced.';
    } elseif($reference['status'] !== 'pending') {
        $message = 'This payment reference has already been ' . htmlspecialchars($reference['status']) . '.';
        $message_type = 'info';
    } elseif(empty($reference['verification_expires_at']) || strtotime($reference['verification_expires_at']) < time()) {
        $message = 'This verification link has expired.';
    } elseif($_SERVER['REQUEST_METHOD'] === 'POST') {
        $decision = ($_POST['decision'] ?? '') === 'approved' ? 'approved' : 'rejected';
        $super_admin_id = (int)($_SESSION['login_user_id'] ?? 0);
        $update = mysqli_prepare(
            $conn,
            "UPDATE company_bill_payment_references
             SET status=?, verified_by_user_id=?, verified_at=NOW(), verification_token_hash=NULL, verification_expires_at=NULL
             WHERE id=? AND status='pending' AND verification_token_hash=?"
        );
        mysqli_stmt_bind_param($update, 'siis', $decision, $super_admin_id, $reference['id'], $token_hash);
        mysqli_stmt_execute($update);
        $changed = mysqli_stmt_affected_rows($update) === 1;
        mysqli_stmt_close($update);
        $message = $changed ? 'Payment reference ' . $decision . ' successfully.' : 'This payment reference could not be updated.';
        $message_type = $changed ? 'success' : 'danger';
        $reference['status'] = $changed ? $decision : $reference['status'];
    } else {
        $message = 'Please review the payment details below, then approve or reject.';
        $message_type = 'info';
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title">Bill Payment Verification</h3></div><div class="card-body">
    <div class="alert alert-<?= htmlspecialchars($message_type); ?>"><?= $message; ?></div>
    <?php if(isset($reference) && $reference && $reference['status'] === 'pending' && $message_type !== 'danger'): ?>
        <dl class="row mb-4">
            <dt class="col-sm-3">Company</dt><dd class="col-sm-9"><?= htmlspecialchars($reference['company_name']); ?></dd>
            <dt class="col-sm-3">Amount</dt><dd class="col-sm-9">BDT <?= number_format((float)$reference['service_charge_amount'], 2); ?></dd>
            <dt class="col-sm-3">Bill Date</dt><dd class="col-sm-9"><?= htmlspecialchars($reference['bill_reminder_date']); ?></dd>
            <dt class="col-sm-3">Reference No.</dt><dd class="col-sm-9"><?= htmlspecialchars($reference['reference_no']); ?></dd>
        </dl>
        <form method="post" class="d-flex">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token); ?>">
            <button name="decision" value="approved" class="btn btn-success mr-2" type="submit"><i class="fas fa-check mr-1"></i> Approve Payment</button>
            <button name="decision" value="rejected" class="btn btn-danger" type="submit" onclick="return confirm('Reject this payment reference?');"><i class="fas fa-times mr-1"></i> Reject</button>
        </form>
    <?php endif; ?>
</div></div>
<?php require_once '../includes/footer.php'; ?>
