<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/pending_invoice_stock_helper.php';
require_once '../includes/product_category_helper.php';

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];

$product_id = isset($_POST['product_id'])
    ? (int)$_POST['product_id']
    : 0;
$exclude_invoice_id = isset($_POST['exclude_invoice_id'])
    ? (int)$_POST['exclude_invoice_id']
    : 0;
$variant_name = trim((string)($_POST['variant_name'] ?? ''));
$product = product_stock_snapshot_for_invoice(
    $conn,
    $user_id,
    $product_id,
    $exclude_invoice_id,
    $variant_name
);

if($product){
    $product['current_stock'] = (float)$product['current_stock'];
    $product['reserved_stock'] = (float)$product['reserved_stock'];
    $product['available_stock'] = $product['is_stock_product']
        ? (float)$product['available_stock']
        : null;
    $product['variants'] = product_variant_names($conn, $product_id, $user_id);

    // Each variant's available stock (same branch and pending-invoice rules), so an invoice line can
    // show the stock of all variants and of the ticked one.
    $product['variant_stock'] = [];
    if($product['is_stock_product']){
        foreach($product['variants'] as $variant_option){
            $variant_snapshot = product_stock_snapshot_for_invoice($conn, $user_id, $product_id, $exclude_invoice_id, $variant_option);
            $product['variant_stock'][(string)$variant_option] = (float)($variant_snapshot['available_stock'] ?? 0);
        }
    }
    $product['variant_stock'] = (object)$product['variant_stock'];

    echo json_encode([
        'success' => true,
        'product' => $product
    ]);

}else{

    echo json_encode([
        'success' => false
    ]);
}
