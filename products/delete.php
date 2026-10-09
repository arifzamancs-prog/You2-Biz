<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_category_helper.php';
header('Content-Type: application/json');
function product_delete_response($status, $success, $message) {
    http_response_code($status);
    echo json_encode(['success'=>$success,'message'=>$message]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') product_delete_response(405, false, 'Use the product list to delete a product.');
$is_restaurant_catalog = restaurant_catalog_enabled($conn, (int)($_SESSION['user_id'] ?? 0));
// Restaurant & Cafe exposes product management without the warehouse module.
// Keep the warehouse permission requirement for every other company type.
if (!manager_can_modify() || (!$is_restaurant_catalog && !stock_can_manage_warehouse($conn))) {
    product_delete_response(403, false, 'Permission denied.');
}
if (empty($_SESSION['product_delete_csrf']) || !hash_equals($_SESSION['product_delete_csrf'], (string)($_POST['csrf'] ?? ''))) product_delete_response(403, false, 'Please reload the page and try again.');
$user_id = (int)$_SESSION['user_id'];
$id = (int)($_POST['id'] ?? 0);
ensure_fifo_inventory_tables($conn);
mysqli_begin_transaction($conn);
try {
    $stmt = mysqli_prepare($conn, 'SELECT id FROM products WHERE id=? AND user_id=? FOR UPDATE');
    mysqli_stmt_bind_param($stmt, 'ii', $id, $user_id);
    mysqli_stmt_execute($stmt);
    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        mysqli_rollback($conn);
        product_delete_response(404, false, 'Product not found.');
    }
    if (product_has_transactions($conn, $id, $user_id)) {
        mysqli_rollback($conn);
        product_delete_response(409, false, 'This product cannot be deleted because it already has transactions.');
    }
    if (!fifo_inventory_remove_product_opening_batches($conn, $id)) throw new RuntimeException('Batch removal failed');
    $stmt = mysqli_prepare($conn, 'DELETE FROM products WHERE id=? AND user_id=?');
    mysqli_stmt_bind_param($stmt, 'ii', $id, $user_id);
    if (!mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt)!==1) throw new RuntimeException('Delete failed');
    mysqli_commit($conn);
    product_delete_response(200, true, 'Product deleted.');
} catch (Throwable $e) {
    mysqli_rollback($conn);
    product_delete_response(500, false, 'Product could not be deleted.');
}
