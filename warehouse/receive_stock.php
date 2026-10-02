<?php
// Use the shared receipt screen with an explicitly authorized warehouse scope.
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/fifo_inventory_helper.php';
if (!stock_can_manage_warehouse($conn)) {
    http_response_code(403);
    exit('Warehouse receipt permission is required.');
}
$warehouse_receipt_mode = true;
require __DIR__ . '/../sales/receive_stock.php';
