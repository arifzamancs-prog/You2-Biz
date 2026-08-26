<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/product_category_helper.php';

$user_id = (int)$_SESSION['user_id'];

if($_SERVER['REQUEST_METHOD'] !== 'POST' || !manager_can_modify()){
    header('Location: create.php');
    exit;
}

$product_id = (int)($_POST['product_id'] ?? 0);
$purchase_price = (float)($_POST['purchase_price'] ?? -1);

if($product_id <= 0 || $purchase_price < 0){
    $_SESSION['error'] = 'Please provide a valid product cost.';
    header('Location: create.php');
    exit;
}

$stmt = mysqli_prepare(
    $conn,
    "UPDATE products p
     INNER JOIN product_categories c ON c.id=p.category_id
     SET p.purchase_price=?
     WHERE p.id=? AND p.user_id=? AND c.category_type='stock_product'"
);

mysqli_stmt_bind_param($stmt, 'dii', $purchase_price, $product_id, $user_id);

if(mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) >= 0){
    $_SESSION['success'] = 'Product cost updated.';
}else{
    $_SESSION['error'] = 'Product cost could not be updated.';
}

header('Location: create.php');
exit;
