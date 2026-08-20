<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';

$user_id = $_SESSION['user_id'];

$product_id = isset($_POST['product_id'])
    ? (int)$_POST['product_id']
    : 0;

$sql = "SELECT
            purchase_price,
            sale_price,
            current_stock
        FROM products
        WHERE id=?
        AND user_id=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $product_id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$product = mysqli_fetch_assoc($result);

echo json_encode([

    "cost_price" => $product['purchase_price'] ?? 0,
    "sale_price" => $product['sale_price'] ?? 0,

    "stock"      => (int)($product['current_stock'] ?? 0)

]);
