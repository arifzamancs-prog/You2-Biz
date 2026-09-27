<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/product_expiry_helper.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_category_helper.php';

header('Content-Type: application/json; charset=utf-8');

function product_setup_reply($ok, $message, $data = []) {
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $data));
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0 || !stock_can_manage_warehouse($conn) || !manager_can_modify()) {
    http_response_code(403);
    product_setup_reply(false, 'Warehouse product setup permission is required.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals((string)($_SESSION['stock_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    product_setup_reply(false, 'Invalid request. Refresh the page and try again.');
}

ensure_product_management_columns($conn);
ensure_fifo_inventory_tables($conn);
ensure_fifo_only_product_categories($conn, $user_id);
$action = (string)($_POST['action'] ?? '');

try {
    if ($action === 'add_category') {
        $name = trim((string)($_POST['category_name'] ?? ''));
        $status = (string)($_POST['status'] ?? 'active');
        if ($name === '') throw new RuntimeException('Category name is required.');
        if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';
        $stmt = mysqli_prepare($conn, "INSERT INTO product_categories (user_id,category_name,category_type,status) VALUES (?,?,'stock_product',?)");
        mysqli_stmt_bind_param($stmt, 'iss', $user_id, $name, $status);
        if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('This category already exists or could not be saved.');
        product_setup_reply(true, 'Category added successfully.', ['category' => ['id' => (int)mysqli_insert_id($conn), 'name' => $name, 'status' => $status]]);
    }

    if ($action === 'add_product') {
        $category_id = (int)($_POST['category_id'] ?? 0);
        $name = trim((string)($_POST['product_name'] ?? ''));
        $sku = trim((string)($_POST['sku'] ?? ''));
        $purchase_price = max(0, (float)($_POST['purchase_price'] ?? 0));
        $sale_price = max(0, (float)($_POST['sale_price'] ?? 0));
        $opening_stock = max(0, (int)($_POST['opening_stock'] ?? 0));
        $minimum_stock = max(0, (int)($_POST['minimum_stock'] ?? 0));
        $status = (string)($_POST['status'] ?? 'active');
        $expired_on = trim((string)($_POST['expired_on'] ?? ''));
        $expired_on = $expired_on === '' ? null : $expired_on;
        if ($name === '') throw new RuntimeException('Product name is required.');
        if (!product_category_is_stock($conn, $category_id, $user_id)) throw new RuntimeException('Select an active FIFO product category.');
        if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';
        $limit = mysqli_fetch_assoc(mysqli_query($conn, "SELECT u.max_products, COUNT(p.id) AS product_count FROM users u LEFT JOIN products p ON p.user_id=u.id WHERE u.id={$user_id} GROUP BY u.id,u.max_products"));
        if ($limit && (int)$limit['product_count'] >= (int)$limit['max_products']) throw new RuntimeException('Product limit reached for your subscription. ' . subscription_support_message());
        mysqli_begin_transaction($conn);
        $stmt = mysqli_prepare($conn, 'INSERT INTO products (user_id,category_id,product_name,sku,purchase_price,sale_price,expired_on,current_stock,opening_stock_quantity,opening_stock_unit_cost,minimum_stock,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        mysqli_stmt_bind_param($stmt, 'iissddsdddis', $user_id, $category_id, $name, $sku, $purchase_price, $sale_price, $expired_on, $opening_stock, $opening_stock, $purchase_price, $minimum_stock, $status);
        if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Product could not be saved.');
        $product_id = (int)mysqli_insert_id($conn);
        if (!fifo_inventory_create_batch($conn, $user_id, $product_id, $opening_stock, $purchase_price, 'product_opening', $product_id, 'OPEN-' . $product_id, date('Y-m-d'))) throw new RuntimeException('Opening stock batch could not be saved.');
        mysqli_commit($conn);
        $category = mysqli_fetch_assoc(mysqli_query($conn, "SELECT category_name FROM product_categories WHERE id={$category_id}"));
        product_setup_reply(true, 'Product added successfully.', ['product' => ['id' => $product_id, 'name' => $name, 'sku' => $sku, 'category' => $category['category_name'] ?? '-', 'purchase_price' => number_format($purchase_price, 2), 'sale_price' => number_format($sale_price, 2), 'status' => $status]]);
    }
    throw new RuntimeException('Unknown product setup action.');
} catch (Throwable $e) {
    if (mysqli_errno($conn)) @mysqli_rollback($conn);
    product_setup_reply(false, $e->getMessage());
}
