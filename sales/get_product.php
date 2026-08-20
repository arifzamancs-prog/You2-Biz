<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/pending_invoice_stock_helper.php';

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];

$product_id = isset($_POST['product_id'])
    ? (int)$_POST['product_id']
    : 0;
$exclude_invoice_id = isset($_POST['exclude_invoice_id'])
    ? (int)$_POST['exclude_invoice_id']
    : 0;
$product = product_stock_snapshot_for_invoice(
    $conn,
    $user_id,
    $product_id,
    $exclude_invoice_id
);

if($product){
    $product['current_stock'] = (float)$product['current_stock'];
    $product['reserved_stock'] = (float)$product['reserved_stock'];
    $product['available_stock'] = $product['is_stock_product']
        ? (float)$product['available_stock']
        : null;

    echo json_encode([
        'success' => true,
        'product' => $product
    ]);

}else{

    echo json_encode([
        'success' => false
    ]);
}
