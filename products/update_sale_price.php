<?php
// Product list → pencil beside Sale Price: saves a product's new sale price (AJAX, JSON).
// Only the sale price changes; already printed barcode labels keep working.
require_once '../includes/auth.php';
require_once '../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sale_price_reply($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sale_price_reply(['ok' => false, 'error' => 'Invalid request.'], 405);
}
if (empty($_SESSION['product_price_token']) || !hash_equals((string)$_SESSION['product_price_token'], (string)($_POST['token'] ?? ''))) {
    sale_price_reply(['ok' => false, 'error' => 'This page expired. Reload it and try again.'], 403);
}
// The same people who see Edit on the product list.
if (!manager_can_modify() || (function_exists('stock_can_manage_warehouse') && !stock_can_manage_warehouse($conn))) {
    sale_price_reply(['ok' => false, 'error' => 'You cannot change product prices.'], 403);
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$product_id = (int)($_POST['id'] ?? 0);
$raw_price = trim((string)($_POST['sale_price'] ?? ''));
if ($raw_price === '' || !is_numeric($raw_price)) {
    sale_price_reply(['ok' => false, 'error' => 'Enter a valid sale price.'], 422);
}
$price = round((float)$raw_price, 2);
if (!is_finite($price) || $price < 0 || $price > 9999999999) {
    sale_price_reply(['ok' => false, 'error' => 'Enter a sale price of 0 or more.'], 422);
}

$stmt = mysqli_prepare($conn, 'SELECT id FROM products WHERE id=? AND user_id=? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'ii', $product_id, $user_id);
mysqli_stmt_execute($stmt);
$exists = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$exists) {
    sale_price_reply(['ok' => false, 'error' => 'Product not found.'], 404);
}

$stmt = mysqli_prepare($conn, 'UPDATE products SET sale_price=? WHERE id=? AND user_id=?');
mysqli_stmt_bind_param($stmt, 'dii', $price, $product_id, $user_id);
$saved = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);
if (!$saved) {
    sale_price_reply(['ok' => false, 'error' => 'The price could not be saved. Please try again.'], 500);
}

sale_price_reply([
    'ok' => true,
    'price' => number_format($price, 2, '.', ''),
    'display' => 'BDT ' . number_format($price, 2),
]);
