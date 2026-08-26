<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/staff_ledger_helper.php';
require_once '../includes/wallet_helper.php';

require_admin_user();
$user_id = (int)$_SESSION['user_id'];
ensure_staff_ledger_table($conn);
$id = (int)($_GET['id'] ?? 0);
$return_to_profile = ($_GET['return_to'] ?? '') === 'profile';
$ajax = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajax_delete';
if($ajax){ $id = (int)($_POST['id'] ?? 0); header('Content-Type: application/json'); }

mysqli_begin_transaction($conn);
try {
    $entry_stmt = mysqli_prepare($conn, "SELECT * FROM staff_ledger_entries WHERE id=? AND user_id=? FOR UPDATE");
    mysqli_stmt_bind_param($entry_stmt, 'ii', $id, $user_id);
    mysqli_stmt_execute($entry_stmt);
    $entry = mysqli_fetch_assoc(mysqli_stmt_get_result($entry_stmt));
    if(!$entry){ throw new Exception('Ledger entry not found.'); }

    credit_wallet($conn, (int)$entry['wallet_id'], $user_id, (float)$entry['amount']);
    $transaction_stmt = mysqli_prepare($conn, "DELETE FROM transactions WHERE txn_no=? AND user_id=? AND transaction_type='staff_payment'");
    mysqli_stmt_bind_param($transaction_stmt, 'si', $entry['txn_no'], $user_id);
    mysqli_stmt_execute($transaction_stmt);
    $expense_stmt = mysqli_prepare($conn, "DELETE FROM expenses WHERE txn_no=? AND user_id=?");
    mysqli_stmt_bind_param($expense_stmt, 'si', $entry['txn_no'], $user_id);
    mysqli_stmt_execute($expense_stmt);
    $delete_stmt = mysqli_prepare($conn, "DELETE FROM staff_ledger_entries WHERE id=? AND user_id=?");
    mysqli_stmt_bind_param($delete_stmt, 'ii', $id, $user_id);
    mysqli_stmt_execute($delete_stmt);
    mysqli_commit($conn);
    if($ajax){ echo json_encode(['success'=>true, 'staff_id'=>(int)$entry['staff_id'], 'entry_type'=>$entry['entry_type'], 'amount'=>(float)$entry['amount']]); }
    else { header('Location: ' . ($return_to_profile ? 'profile.php?id=' . (int)$entry['staff_id'] . '&deleted=1' : 'ledger.php?deleted=1')); }
} catch(Throwable $error) {
    mysqli_rollback($conn);
    if($ajax){ http_response_code(422); echo json_encode(['success'=>false, 'message'=>$error->getMessage()]); }
    else { header('Location: ' . ($return_to_profile ? 'profile.php?id=' . (int)($entry['staff_id'] ?? 0) . '&error=' : 'ledger.php?error=') . urlencode($error->getMessage())); }
}
exit;
