<?php
// Product list → printer button: the labels of one product (and of each of its variants)
// as JSON for the barcode print popup (assets/js/barcode_print_popup.js). Barcodes and
// stock follow the same rules as the Barcode Labels page.
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/branch_context_helper.php';
require_once '../includes/barcode_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user_id = (int)($_SESSION['user_id'] ?? 0);
$product_id = (int)($_GET['id'] ?? 0);

$stmt = mysqli_prepare($conn, 'SELECT id, product_name, sku, sale_price FROM products WHERE id=? AND user_id=? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'ii', $product_id, $user_id);
mysqli_stmt_execute($stmt);
$product = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$product) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Product not found.']);
    exit;
}

$company = mysqli_fetch_assoc(mysqli_query($conn, "SELECT name, currency_code FROM users WHERE id={$user_id}")) ?: [];

$variants = array_values(array_filter(
    array_map('strval', product_variant_names_by_product($conn, $user_id)[$product_id] ?? []),
    fn($name) => $name !== ''
));

// Stock of the selected branch, or of every branch when none is selected (as on the Barcode Labels page).
$stock_branch_id = selected_branch_id($conn, false);
$stock = [];
$stock_rows = mysqli_query(
    $conn,
    "SELECT variant_name, SUM(remaining_quantity) AS available
     FROM stock_batches
     WHERE user_id={$user_id} AND product_id={$product_id} AND remaining_quantity > 0" . ($stock_branch_id > 0 ? " AND branch_id={$stock_branch_id}" : '') . "
     GROUP BY variant_name"
);
while ($stock_rows && ($stock_row = mysqli_fetch_assoc($stock_rows))) {
    $stock[(string)$stock_row['variant_name']] = (float)$stock_row['available'];
}

$items = [['product' => $product, 'variant' => '', 'position' => 0]];
foreach ($variants as $position => $variant) {
    $items[] = ['product' => $product, 'variant' => $variant, 'position' => $position];
}
$barcodes = product_barcodes_for_items($conn, $user_id, $items);

$labels = [];
foreach ($items as $item) {
    $variant = $item['variant'];
    $barcode = $barcodes[$product_id][$variant] ?? '';
    if ($barcode === '') continue;
    // The product-level label of a product with variants counts the stock of all its variants.
    $available = $variant === '' && $variants ? array_sum($stock) : ($stock[$variant] ?? 0);
    $labels[] = [
        'name' => $product['product_name'],
        'variant' => $variant,
        'barcode' => $barcode,
        'price' => number_format((float)$product['sale_price'], 2, '.', ''),
        'stock' => max(0, (int)floor($available)),
    ];
}

echo json_encode([
    'ok' => true,
    'shop' => trim((string)($company['name'] ?? '')),
    'currency' => trim((string)($company['currency_code'] ?? '')) ?: 'BDT',
    'has_variants' => (bool)$variants,
    'items' => $labels,
], JSON_UNESCAPED_UNICODE);
