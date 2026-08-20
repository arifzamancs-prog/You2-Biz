<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_category_helper.php';

$user_id = $_SESSION['user_id'];
ensure_fifo_inventory_tables($conn);

$id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;

$product_has_transactions = product_has_transactions($conn, $id, $user_id);

if($product_has_transactions){
    $_SESSION['error'] = 'This product cannot be deleted because it already has transactions.';
    header("Location: index.php");
    exit;
}

mysqli_begin_transaction($conn);

$delete_batch_ok = fifo_inventory_remove_product_opening_batches($conn, $id);

$sql = "DELETE
        FROM products
        WHERE id=?
        AND user_id=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $id,
    $user_id
);

$delete_product_ok = mysqli_stmt_execute($stmt);

if($delete_batch_ok && $delete_product_ok){
    mysqli_commit($conn);
}else{
    mysqli_rollback($conn);
    $_SESSION['error'] = 'Product could not be deleted.';
}

header("Location: index.php");
exit;
