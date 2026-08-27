<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';

if(!manager_can_modify()){
    header("Location:index.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$id = (int)($_GET['id'] ?? 0);

$stmt = mysqli_prepare($conn, "SELECT * FROM money_ins WHERE id=? AND user_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, "ii", $id, $user_id);
mysqli_stmt_execute($stmt);
$entry = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if(!$entry){
    die("Money In entry not found.");
}

mysqli_begin_transaction($conn);

try{
    $wallet_exists = false;
    $wallet_balance = 0;

    if((int)($entry['wallet_id'] ?? 0) > 0){
        $wallet_stmt = mysqli_prepare(
            $conn,
            "SELECT balance
             FROM wallets
             WHERE id=?
             AND user_id=?
             LIMIT 1"
        );
        $wallet_id = (int)$entry['wallet_id'];
        mysqli_stmt_bind_param($wallet_stmt, "ii", $wallet_id, $user_id);
        mysqli_stmt_execute($wallet_stmt);
        $wallet_result = mysqli_stmt_get_result($wallet_stmt);
        $wallet_row = $wallet_result ? mysqli_fetch_assoc($wallet_result) : null;

        if($wallet_row){
            $wallet_exists = true;
            $wallet_balance = (float)($wallet_row['balance'] ?? 0);
        }
    }

    if(($entry['approval_status'] ?? '') === 'approved'){
        if($wallet_exists && $wallet_balance >= (float)$entry['amount']){
            debit_wallet($conn, (int)$entry['wallet_id'], $user_id, (float)$entry['amount']);
        }
    }

    $delete_txn = mysqli_prepare(
        $conn,
        "DELETE FROM transactions
         WHERE user_id=?
         AND transaction_type='money_in'
         AND reference_id=?"
    );
    mysqli_stmt_bind_param($delete_txn, "ii", $user_id, $id);
    mysqli_stmt_execute($delete_txn);

    $delete = mysqli_prepare($conn, "DELETE FROM money_ins WHERE id=? AND user_id=?");
    mysqli_stmt_bind_param($delete, "ii", $id, $user_id);
    mysqli_stmt_execute($delete);

    mysqli_commit($conn);
    header("Location:index.php");
    exit;
}catch(Exception $e){
    mysqli_rollback($conn);
    die("Error : " . $e->getMessage());
}
