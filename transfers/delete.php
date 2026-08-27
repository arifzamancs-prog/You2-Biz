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

$stmt = mysqli_prepare($conn, "SELECT * FROM transfers WHERE id=? AND user_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, "ii", $id, $user_id);
mysqli_stmt_execute($stmt);
$entry = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if(!$entry){
    die("Transfer entry not found.");
}

mysqli_begin_transaction($conn);

try{
    $from_wallet_exists = false;
    $to_wallet_exists = false;

    if((int)($entry['from_wallet_id'] ?? 0) > 0){
        $wallet_stmt = mysqli_prepare($conn, "SELECT id FROM wallets WHERE id=? AND user_id=? LIMIT 1");
        $from_wallet_id = (int)$entry['from_wallet_id'];
        mysqli_stmt_bind_param($wallet_stmt, "ii", $from_wallet_id, $user_id);
        mysqli_stmt_execute($wallet_stmt);
        $from_wallet_exists = mysqli_num_rows(mysqli_stmt_get_result($wallet_stmt)) > 0;
    }

    if((int)($entry['to_wallet_id'] ?? 0) > 0){
        $wallet_stmt = mysqli_prepare($conn, "SELECT id FROM wallets WHERE id=? AND user_id=? LIMIT 1");
        $to_wallet_id = (int)$entry['to_wallet_id'];
        mysqli_stmt_bind_param($wallet_stmt, "ii", $to_wallet_id, $user_id);
        mysqli_stmt_execute($wallet_stmt);
        $to_wallet_exists = mysqli_num_rows(mysqli_stmt_get_result($wallet_stmt)) > 0;
    }

    if(($entry['approval_status'] ?? '') === 'approved'){
        if($from_wallet_exists){
            credit_wallet($conn, (int)$entry['from_wallet_id'], $user_id, (float)$entry['amount']);
        }

        if($to_wallet_exists){
            debit_wallet($conn, (int)$entry['to_wallet_id'], $user_id, (float)$entry['amount']);
        }
    }

    $delete_txn = mysqli_prepare(
        $conn,
        "DELETE FROM transactions
         WHERE user_id=?
         AND transaction_type='transfer'
         AND reference_id=?"
    );
    mysqli_stmt_bind_param($delete_txn, "ii", $user_id, $id);
    mysqli_stmt_execute($delete_txn);

    $delete = mysqli_prepare($conn, "DELETE FROM transfers WHERE id=? AND user_id=?");
    mysqli_stmt_bind_param($delete, "ii", $id, $user_id);
    mysqli_stmt_execute($delete);

    mysqli_commit($conn);
    header("Location:index.php");
    exit;
}catch(Exception $e){
    mysqli_rollback($conn);
    die("Error : " . $e->getMessage());
}
