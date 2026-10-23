<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/app_config.php';
require_once '../includes/bill_reminder_helper.php';
require_once '../includes/super_admin_config.php';
require_once '../includes/smtp_mailer.php';

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    header('Location: ' . app_path('dashboard.php'));
    exit;
}

$return_to = bill_reminder_safe_return_url($_POST['return_to'] ?? '');
$role = $_SESSION['user_role'] ?? '';

if(!in_array($role, ['admin', 'manager'], true)){
    $_SESSION['bill_reminder_flash'] = ['type' => 'danger', 'message' => 'You do not have permission to submit a bill reference.'];
    header('Location: ' . $return_to);
    exit;
}

$company_id = (int)($_SESSION['user_id'] ?? 0);
$login_user_id = (int)($_SESSION['login_user_id'] ?? 0);
$reference_no = trim((string)($_POST['reference_no'] ?? ''));

if($reference_no === ''){
    $_SESSION['bill_reminder_flash'] = ['type' => 'danger', 'message' => 'Please enter the payment reference number.'];
    $_SESSION['bill_reminder_notice_pending'] = true;
    header('Location: ' . $return_to);
    exit;
}

if(strlen($reference_no) > 150){
    $_SESSION['bill_reminder_flash'] = ['type' => 'danger', 'message' => 'Reference number must be 150 characters or fewer.'];
    $_SESSION['bill_reminder_notice_pending'] = true;
    header('Location: ' . $return_to);
    exit;
}

try {
    $reminder = bill_reminder_current_for_company($conn, $company_id);

    if(!$reminder){
        $_SESSION['bill_reminder_flash'] = ['type' => 'danger', 'message' => 'There is no active bill reminder to update.'];
        header('Location: ' . $return_to);
        exit;
    }

    // A pending reference is locked until Super Admin approves or rejects it.
    if(($reminder['reference_status'] ?? '') === 'pending'){
        header('Location: ' . $return_to);
        exit;
    }

    $bill_date = $reminder['bill_cycle_date'];
    $amount = (float)$reminder['service_charge_amount'];
    $verification_token = bill_reminder_verification_token();
    $verification_token_hash = bill_reminder_verification_token_hash($verification_token);
    $verification_expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO company_bill_payment_references
            (company_id, bill_reminder_date, service_charge_amount, reference_no, submitted_by_user_id, submitted_at, status, verification_token_hash, verification_expires_at, verified_by_user_id, verified_at)
         VALUES (?, ?, ?, ?, ?, NOW(), 'pending', ?, ?, 0, NULL)"
    );

    if(!$stmt){
        throw new Exception(mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, 'isdsiss', $company_id, $bill_date, $amount, $reference_no, $login_user_id, $verification_token_hash, $verification_expires_at);

    if(!mysqli_stmt_execute($stmt)){
        throw new Exception(mysqli_stmt_error($stmt));
    }

    mysqli_stmt_close($stmt);

    $verification_url = app_url('super_admin/bill_payment_verification.php?token=' . urlencode($verification_token));
    $email_body = '<p>A company has submitted a bill payment reference for verification.</p>'
        . '<p><strong>Company:</strong> ' . htmlspecialchars($reminder['name']) . '<br>'
        . '<strong>Amount:</strong> BDT ' . number_format($amount, 2) . '<br>'
        . '<strong>Bill date:</strong> ' . htmlspecialchars($bill_date) . '<br>'
        . '<strong>Reference No.:</strong> ' . htmlspecialchars($reference_no) . '</p>'
        . '<p><a href="' . htmlspecialchars($verification_url) . '" style="display:inline-block;padding:10px 16px;background:#007bff;color:#fff;text-decoration:none;border-radius:4px;">Verify Payment</a></p>'
        . '<p>This verification link expires in 7 days.</p>';
    [$sent, $mail_error] = smtp_send_mail(
        super_admin_notify_email(),
        'Super Admin',
        'Bill payment verification: ' . $reminder['name'],
        $email_body
    );

    $_SESSION['bill_reminder_flash'] = $sent
        ? ['type' => 'success', 'message' => 'Reference submitted. A payment verification link was sent to Super Admin.']
        : ['type' => 'warning', 'message' => 'Reference submitted, but the verification email could not be sent: ' . $mail_error];
} catch(Throwable $error) {
    $_SESSION['bill_reminder_flash'] = ['type' => 'danger', 'message' => 'Could not save the payment reference: ' . $error->getMessage()];
}

// Do not reopen the bill popup immediately after a successful submission.
// A future login sets the reminder again if Super Admin has not approved it.
unset($_SESSION['bill_reminder_notice_pending'], $_SESSION['bill_reminder_flash']);
header('Location: ' . $return_to);
exit;
