<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/sales_return_helper.php';

$cid = (int)$_SESSION['user_id'];

if(is_agent_user() || !sales_return_enabled($conn, $cid) || $_SERVER['REQUEST_METHOD'] !== 'POST'){
    header('Location: invoice_list.php');
    exit;
}

// Schema work before the transaction: CREATE/ALTER TABLE would commit it.
sales_return_schema($conn);
$invoice_id = (int)($_POST['invoice_id'] ?? 0);
require_branch_record_access($conn, 'invoices', $invoice_id, 'sales_return.php');
try{
    $branch = stock_invoice_branch($conn, $cid, $invoice_id);
}catch(RuntimeException $e){
    $_SESSION['sales_return_error'] = $e->getMessage();
    header('Location: sales_return.php');
    exit;
}
$GLOBALS['stock_wallet_branch_id'] = $branch;

$cash = round(max(0, (float)($_POST['cash_refund'] ?? 0)), 2);
$due = round(max(0, (float)($_POST['due_adjusted'] ?? 0)), 2);
$advance = round(max(0, (float)($_POST['advance_amount'] ?? 0)), 2);

$conn->begin_transaction();

try{
    sales_refund_query($conn, 'SELECT id FROM invoices WHERE id=? AND user_id=? FOR UPDATE', 'ii', [$invoice_id, $cid])->get_result();
    $invoice = sales_return_fetch_invoice($conn, $cid, $invoice_id);

    if(($reason = sales_return_block_reason($invoice)) !== ''){
        throw new RuntimeException($reason);
    }

    $pick = sales_return_pick($conn, $invoice, $_POST['return_qty'] ?? [], $_POST['item_condition'] ?? []);

    if((int)$invoice['customer_id'] <= 0 && ($due > 0 || $advance > 0)){
        throw new RuntimeException('Instant Customer: the return can only be refunded in cash.');
    }

    $customer_due = sales_return_customer_due($conn, $cid, $branch, (int)$invoice['customer_id']);

    // An advance next to an unpaid due would leave the invoice dues and the ledger apart.
    if($advance > 0 && $due < $customer_due - 0.009){
        throw new RuntimeException('Deduct the customer\'s full due before keeping an advance.');
    }

    $actor_id = (int)($_SESSION['login_user_id'] ?? $cid);
    $actor_name = trim((string)($_SESSION['login_name'] ?? ''));
    $return = sales_return_create($conn, $cid, $branch, $invoice, $pick, $actor_id, $actor_name, (string)($_POST['note'] ?? ''));
    sales_return_settle($conn, $cid, $branch, $invoice, $return, [
        'cash' => $cash,
        'wallet_id' => (int)($_POST['refund_wallet_id'] ?? 0),
        'due' => $due,
        'advance' => $advance,
    ]);

    $conn->commit();
    header('Location: sales_return_print.php?id=' . $return['id']);
    exit;

}catch(Throwable $e){
    $conn->rollback();
    $_SESSION['sales_return_error'] = $e instanceof RuntimeException ? $e->getMessage() : 'The return could not be saved. Nothing was changed. Please try again.';

    if(!($e instanceof RuntimeException)){
        error_log($e->getMessage());
    }

    header('Location: sales_return_create.php?invoice_id=' . $invoice_id);
    exit;
}
