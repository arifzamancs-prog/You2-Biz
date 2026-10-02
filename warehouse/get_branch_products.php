<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/fifo_inventory_helper.php';

header('Content-Type: application/json; charset=utf-8');

$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_GET['branch_id'] ?? 0);

if ($user_id <= 0 || $branch_id <= 0) {
    echo json_encode([]);
    exit;
}

$branch = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM branches WHERE id={$branch_id} AND user_id={$user_id} AND status='active'"));
if (!$branch) {
    echo json_encode([]);
    exit;
}

$sql = "SELECT p.id, p.product_name, p.sku, sb.variant_name,
    COALESCE(SUM(sb.remaining_quantity),0) AS on_hand,
    (SELECT COALESCE(SUM(ii.quantity),0)
       FROM invoice_items ii
       INNER JOIN invoices i ON i.id=ii.invoice_id
      WHERE i.user_id=p.user_id AND i.branch_id={$branch_id}
        AND i.accounting_status='pending' AND ii.product_id=p.id AND COALESCE(ii.variant_name,'')=COALESCE(sb.variant_name,'') AND ii.quantity>0) AS reserved
    FROM products p
    INNER JOIN product_categories c ON c.id=p.category_id
    INNER JOIN stock_batches sb ON sb.user_id=p.user_id AND sb.product_id=p.id AND sb.branch_id={$branch_id} AND sb.remaining_quantity>0
    WHERE p.user_id={$user_id} AND p.status='active' AND c.category_type='stock_product'
    GROUP BY p.id, p.product_name, p.sku, p.user_id, sb.variant_name
    HAVING on_hand > 0
    ORDER BY p.product_name";

$result = mysqli_query($conn, $sql);
$products = [];
while ($row = $result ? mysqli_fetch_assoc($result) : null) {
    $available = max(0, (float)$row['on_hand'] - (float)$row['reserved']);
    if ($available > 0) {
        $product_id = (int)$row['id'];
        if(!isset($products[$product_id])){
            $products[$product_id] = ['id' => $product_id, 'name' => product_option_label($row['product_name'], $row['sku'] ?? ''), 'available' => 0, 'variants' => []];
        }
        $products[$product_id]['available'] += $available;
        $variant_name = (string)($row['variant_name'] ?? '');
        if($variant_name !== ''){
            $products[$product_id]['variants'][] = ['name' => $variant_name, 'available' => $available];
        }
    }
}

foreach ($products as &$product) {
    $variant_order = array_flip(product_variant_names($conn, (int)$product['id'], $user_id));
    usort($product['variants'], static function ($left, $right) use ($variant_order) {
        return ($variant_order[$left['name']] ?? PHP_INT_MAX) <=> ($variant_order[$right['name']] ?? PHP_INT_MAX);
    });
}
unset($product);

echo json_encode(array_values($products));
