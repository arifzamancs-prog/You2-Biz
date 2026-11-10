<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/purchase_return_helper.php';

$cid = (int)$_SESSION['user_id'];

if(is_agent_user() || !manager_has_permission('suppliers') || !manager_can_modify() || $_SERVER['REQUEST_METHOD'] !== 'POST'){
    header('Location: index.php');
    exit;
}

// Schema work before the transaction: CREATE/ALTER TABLE would commit it.
ensure_fifo_inventory_tables($conn);
purchase_return_schema($conn);
$purchase_id = (int)($_POST['purchase_id'] ?? 0);
require_branch_record_access($conn, 'purchases', $purchase_id, 'purchase_return.php');

mysqli_begin_transaction($conn);

try{
    purchase_return_query($conn, 'SELECT id FROM purchases WHERE id=? AND user_id=? FOR UPDATE', 'ii', [$purchase_id, $cid]);
    $purchase = purchase_return_fetch_purchase($conn, $cid, $purchase_id);

    if(($reason = purchase_return_block_reason($conn, $purchase)) !== ''){
        throw new RuntimeException($reason);
    }

    $GLOBALS['stock_wallet_branch_id'] = (int)$purchase['branch_id'];
    $return = purchase_return_create(
        $conn,
        $cid,
        $purchase,
        $_POST['return_qty'] ?? [],
        [
            'due' => (float)($_POST['due_adjusted'] ?? 0),
            'cash' => (float)($_POST['cash_refund'] ?? 0),
            'wallet_id' => (int)($_POST['refund_wallet_id'] ?? 0),
        ],
        (string)($_POST['note'] ?? ''),
        (int)($_SESSION['login_user_id'] ?? $cid),
        trim((string)($_SESSION['login_name'] ?? ''))
    );

    mysqli_commit($conn);
    header('Location: purchase_return_print.php?id=' . (int)$return['id']);
    exit;

}catch(Throwable $e){
    mysqli_rollback($conn);
    if(!($e instanceof RuntimeException)){
        error_log($e->getMessage());
    }
    $_SESSION['purchase_return_error'] = $e instanceof RuntimeException ? $e->getMessage() : 'The return could not be saved. Nothing was changed. Please try again.';
    header('Location: purchase_return_create.php?purchase_id=' . $purchase_id);
    exit;
}
