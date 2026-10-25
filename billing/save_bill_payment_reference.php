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

$locked = false;

try {
    $status = bill_reminder_status_for_company($conn, $company_id);

    if(!$status){
        $_SESSION['bill_reminder_flash'] = ['type' => 'danger', 'message' => 'There is no active bill reminder to update.'];
        header('Location: ' . $return_to);
        exit;
    }

    $locked = $status['mode'] === 'locked';
    // A pending reference stays locked until Super Admin approves or rejects
    // it; one reference covers every other unpaid bill.
    $bills = array_values(array_filter($status['bills'], function($bill){
        return $bill['reference_status'] !== 'pending';
    }));

    if(!$bills){
        header('Location: ' . $return_to);
        exit;
    }

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

    $amount = 0.0;
    $bill_dates = [];
    mysqli_begin_transaction($conn);

    foreach($bills as $bill){
        $bill_date = $bill['bill_cycle_date'];
        $bill_amount = (float)$bill['amount'];
        mysqli_stmt_bind_param($stmt, 'isdsiss', $company_id, $bill_date, $bill_amount, $reference_no, $login_user_id, $verification_token_hash, $verification_expires_at);

        if(!mysqli_stmt_execute($stmt)){
            mysqli_rollback($conn);
            throw new Exception(mysqli_stmt_error($stmt));
        }

        $amount += $bill_amount;
        $bill_dates[] = $bill_date;
    }

    mysqli_commit($conn);
    mysqli_stmt_close($stmt);

    $verification_url = app_url('super_admin/bill_payment_verification.php?token=' . urlencode($verification_token));
    $email_body = '<p>A company has submitted a bill payment reference for verification.</p>'
        . '<p><strong>Company:</strong> ' . htmlspecialchars($status['name']) . '<br>'
        . '<strong>Amount:</strong> BDT ' . number_format($amount, 2) . '<br>'
        . '<strong>Bill date' . (count($bill_dates) > 1 ? 's' : '') . ':</strong> ' . htmlspecialchars(implode(', ', $bill_dates)) . '<br>'
        . '<strong>Reference No.:</strong> ' . htmlspecialchars($reference_no) . '</p>'
        . ($locked ? '<p><strong>This company is locked until the payment is approved.</strong></p>' : '')
        . '<p><a href="' . htmlspecialchars($verification_url) . '" style="display:inline-block;padding:10px 16px;background:#007bff;color:#fff;text-decoration:none;border-radius:4px;">Verify Payment</a></p>'
        . '<p>This verification link expires in 7 days.</p>';
    // The reference is already saved; a mail failure must not be reported as a save failure.
    try {
        [$sent, $mail_error] = smtp_send_mail(
            super_admin_notify_email(),
            'Super Admin',
            'Bill payment verification: ' . $status['name'],
            $email_body
        );
    } catch(Throwable $mail_exception) {
        $sent = false;
        $mail_error = $mail_exception->getMessage();
    }

    $_SESSION['bill_reminder_flash'] = $sent
        ? ['type' => 'success', 'message' => 'Reference submitted. A payment verification link was sent to Super Admin.']
        : ['type' => 'warning', 'message' => 'Reference submitted, but the verification email could not be sent: ' . $mail_error];
} catch(Throwable $error) {
    $_SESSION['bill_reminder_flash'] = ['type' => 'danger', 'message' => 'Could not save the payment reference: ' . $error->getMessage()];
}

// The locked payment page shows the result itself.
if($locked){
    header('Location: ' . bill_reminder_lock_path());
    exit;
}

// Do not reopen the bill popup immediately after a successful submission.
// A future login sets the reminder again if Super Admin has not approved it.
unset($_SESSION['bill_reminder_notice_pending'], $_SESSION['bill_reminder_flash'], $_SESSION['bill_reminder_countdown_until']);
header('Location: ' . $return_to);
exit;
