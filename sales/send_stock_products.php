<?php
// Products in stock at the user's own location, for the Send Product form.
// The location is never taken from the request, so a branch only sees its own stock.
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/fifo_inventory_helper.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
$warehouse_mode = !empty($_GET['warehouse']) && stock_can_manage_warehouse($conn);
$_GET['branch_id'] = $warehouse_mode ? (int)stock_warehouse_id($conn, $user_id) : (int)selected_branch_id($conn, true);

require __DIR__ . '/../warehouse/get_branch_products.php';
