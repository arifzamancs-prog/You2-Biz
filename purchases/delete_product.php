<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_category_helper.php';

$user_id = (int)$_SESSION['user_id'];
$product_id = (int)($_GET['id'] ?? 0);

if(!manager_can_modify() || $product_id <= 0){
    header('Location: create.php');
    exit;
}

if(product_has_transactions($conn, $product_id, $user_id)){
    $_SESSION['error'] = 'This product is already used and cannot be deleted.';
    header('Location: create.php');
    exit;
}

ensure_fifo_inventory_tables($conn);
mysqli_begin_transaction($conn);

$remove_batches_ok = fifo_inventory_remove_product_opening_batches($conn, $product_id);
$stmt = mysqli_prepare($conn, 'DELETE FROM products WHERE id=? AND user_id=?');
mysqli_stmt_bind_param($stmt, 'ii', $product_id, $user_id);
$delete_ok = mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) === 1;

if($remove_batches_ok && $delete_ok){
    mysqli_commit($conn);
    $_SESSION['success'] = 'Product deleted.';
}else{
    mysqli_rollback($conn);
    $_SESSION['error'] = 'Product could not be deleted.';
}

header('Location: create.php');
exit;
