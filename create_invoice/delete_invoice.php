<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/booking_invoice_helper.php';
require_once '../includes/branch_context_helper.php';
require_once '../includes/customer_portal_helper.php';

require_admin_user();
$user_id = (int)$_SESSION['user_id'];
ensure_branch_accounting_columns($conn, $user_id);
$branch_scope = branch_scope_sql($conn, 'booking_invoices');
ensure_booking_invoice_table($conn);
ensure_invoice_request_table($conn);

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    header('Location: invoice_list.php');
    exit;
}

$invoice_id = (int)($_POST['invoice_id'] ?? 0);
mysqli_begin_transaction($conn);
try{
    $stmt = mysqli_prepare($conn, "SELECT * FROM booking_invoices WHERE id=? AND user_id=? {$branch_scope} FOR UPDATE");
    mysqli_stmt_bind_param($stmt, 'ii', $invoice_id, $user_id);
    mysqli_stmt_execute($stmt);
    $invoice = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if(!$invoice){
        throw new Exception('Invoice not found.');
    }

    booking_invoice_reverse_wallet_effect($conn, $invoice, $user_id);
    $delete_stmt = mysqli_prepare($conn, 'DELETE FROM booking_invoices WHERE id=? AND user_id=?');
    mysqli_stmt_bind_param($delete_stmt, 'ii', $invoice_id, $user_id);
    if(!mysqli_stmt_execute($delete_stmt)){
        throw new Exception(mysqli_stmt_error($delete_stmt));
    }

    $request_stmt = mysqli_prepare($conn, "UPDATE invoice_requests SET status='rejected', completed_invoice_id=NULL, completed_at=NULL WHERE completed_invoice_id=? AND user_id=? AND status='completed'");
    mysqli_stmt_bind_param($request_stmt, 'ii', $invoice_id, $user_id);
    mysqli_stmt_execute($request_stmt);

    mysqli_commit($conn);
    header('Location: invoice_list.php?deleted=1');
}catch(Throwable $error){
    mysqli_rollback($conn);
    header('Location: invoice_list.php?error=' . urlencode($error->getMessage()));
}
exit;
