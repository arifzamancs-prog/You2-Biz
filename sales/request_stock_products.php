<?php
// Products in stock at another location of the company, for the Request Product form.
// Only active locations of the same company, and never the user's own location.
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/fifo_inventory_helper.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
$warehouse_mode = !empty($_GET['warehouse']) && stock_can_manage_warehouse($conn);
$own_location = $warehouse_mode ? (int)stock_warehouse_id($conn, $user_id) : (int)selected_branch_id($conn, true);
$source_location = (int)($_GET['source'] ?? 0);

if ($user_id <= 0 || $source_location <= 0 || $source_location === $own_location || !company_multi_branch_enabled($conn, $user_id)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([]);
    exit;
}

$_GET['branch_id'] = $source_location;
require __DIR__ . '/../warehouse/get_branch_products.php';
