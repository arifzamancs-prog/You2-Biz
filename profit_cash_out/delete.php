<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/profit_cash_out_helper.php';

require_admin_user();
$user_id = (int)$_SESSION['user_id'];
ensure_profit_cash_out_table($conn);

$id = (int)($_GET['id'] ?? 0);

if($id <= 0){
    header('Location: index.php');
    exit;
}

$entry_stmt = mysqli_prepare($conn, "SELECT * FROM profit_cash_outs WHERE id=? AND user_id=? LIMIT 1");
mysqli_stmt_bind_param($entry_stmt, 'ii', $id, $user_id);
mysqli_stmt_execute($entry_stmt);
$entry = mysqli_fetch_assoc(mysqli_stmt_get_result($entry_stmt));

if(!$entry){
    header('Location: index.php');
    exit;
}

mysqli_begin_transaction($conn);

try{
    $wallet_exists = false;

    if((int)$entry['wallet_id'] > 0){
        $wallet_stmt = mysqli_prepare($conn, "SELECT id FROM wallets WHERE id=? AND user_id=? LIMIT 1");
        $wallet_id = (int)$entry['wallet_id'];
        mysqli_stmt_bind_param($wallet_stmt, 'ii', $wallet_id, $user_id);
        mysqli_stmt_execute($wallet_stmt);
        $wallet_exists = mysqli_num_rows(mysqli_stmt_get_result($wallet_stmt)) > 0;
    }

    if($wallet_exists){
        credit_wallet($conn, (int)$entry['wallet_id'], $user_id, (float)$entry['amount']);
    }

    $delete_txn_stmt = mysqli_prepare(
        $conn,
        "DELETE FROM transactions
         WHERE user_id=?
         AND transaction_type='profit_cash_out'
         AND reference_id=?"
    );
    mysqli_stmt_bind_param($delete_txn_stmt, 'ii', $user_id, $id);
    mysqli_stmt_execute($delete_txn_stmt);

    $delete_stmt = mysqli_prepare($conn, "DELETE FROM profit_cash_outs WHERE id=? AND user_id=?");
    mysqli_stmt_bind_param($delete_stmt, 'ii', $id, $user_id);

    if(!mysqli_stmt_execute($delete_stmt)){
        throw new Exception(mysqli_stmt_error($delete_stmt));
    }

    mysqli_commit($conn);
    header('Location: index.php?deleted=1');
    exit;
}catch(Exception $exception){
    mysqli_rollback($conn);
    header('Location: index.php?error=' . urlencode($exception->getMessage()));
    exit;
}
