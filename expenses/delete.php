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

$stmt = mysqli_prepare($conn, "SELECT * FROM expenses WHERE id=? AND user_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, "ii", $id, $user_id);
mysqli_stmt_execute($stmt);
$entry = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if(!$entry){
    die("Expense entry not found.");
}

mysqli_begin_transaction($conn);

try{
    if(($entry['approval_status'] ?? '') === 'approved'){
        credit_wallet($conn, (int)$entry['wallet_id'], $user_id, (float)$entry['amount']);
    }

    $delete_txn = mysqli_prepare(
        $conn,
        "DELETE FROM transactions
         WHERE user_id=?
         AND transaction_type='expense'
         AND reference_id=?"
    );
    mysqli_stmt_bind_param($delete_txn, "ii", $user_id, $id);
    mysqli_stmt_execute($delete_txn);

    $delete = mysqli_prepare($conn, "DELETE FROM expenses WHERE id=? AND user_id=?");
    mysqli_stmt_bind_param($delete, "ii", $id, $user_id);
    mysqli_stmt_execute($delete);

    mysqli_commit($conn);
    header("Location:index.php");
    exit;
}catch(Exception $e){
    mysqli_rollback($conn);
    die("Error : " . $e->getMessage());
}
