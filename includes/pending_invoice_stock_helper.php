<?php

require_once __DIR__ . '/fifo_inventory_helper.php';

function pending_invoice_reserved_quantity($conn, $user_id, $product_id, $exclude_invoice_id = 0, $variant_name = '')
{
    $user_id = (int)$user_id;
    $product_id = (int)$product_id;
    $exclude_invoice_id = (int)$exclude_invoice_id;
    $variant_name = trim((string)$variant_name);
    $branch_id = $exclude_invoice_id > 0
        ? stock_invoice_branch($conn, $user_id, $exclude_invoice_id)
        : (int)($GLOBALS['stock_wallet_branch_id'] ?? selected_branch_id($conn, true));

    $sql = "SELECT COALESCE(SUM(ii.quantity), 0) AS reserved_quantity
            FROM invoice_items ii
            INNER JOIN invoices i
                ON i.id = ii.invoice_id
            WHERE i.user_id=?
            AND i.branch_id={$branch_id}
            AND ii.product_id=?
            AND ii.variant_name=?
            AND i.accounting_status='pending'
            AND ii.quantity > 0";

    if($exclude_invoice_id > 0){
        $sql .= " AND i.id <> ?";
    }

    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return 0.0;
    }

    if($exclude_invoice_id > 0){
        mysqli_stmt_bind_param($stmt, "iisi", $user_id, $product_id, $variant_name, $exclude_invoice_id);
    }else{
        mysqli_stmt_bind_param($stmt, "iis", $user_id, $product_id, $variant_name);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    return (float)($row['reserved_quantity'] ?? 0);
}

function product_stock_snapshot_for_invoice($conn, $user_id, $product_id, $exclude_invoice_id = 0, $variant_name = '')
{
    $user_id = (int)$user_id;
    $product_id = (int)$product_id;
    $exclude_invoice_id = (int)$exclude_invoice_id;
    $variant_name = trim((string)$variant_name);

    $sql = "SELECT
                p.id,
                p.product_name,
                p.sale_price,
                p.current_stock,
                c.category_type
            FROM products p
            LEFT JOIN product_categories c ON c.id=p.category_id
            WHERE p.id=?
            AND p.user_id=?
            LIMIT 1 FOR UPDATE";

    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return null;
    }

    mysqli_stmt_bind_param($stmt, "ii", $product_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $product = $result ? mysqli_fetch_assoc($result) : null;

    if(!$product){
        return null;
    }

    $is_stock_product = ($product['category_type'] ?? 'non_stock') === 'stock_product';
    $branch_id = $exclude_invoice_id > 0
        ? stock_invoice_branch($conn, $user_id, $exclude_invoice_id)
        : (int)($GLOBALS['stock_wallet_branch_id'] ?? selected_branch_id($conn, true));
    $current_stock = $is_stock_product ? fifo_inventory_get_available_stock($conn, $user_id, $product_id, $branch_id, $variant_name) : 0;
    $reserved_stock = $is_stock_product ? pending_invoice_reserved_quantity(
        $conn,
        $user_id,
        $product_id,
        $exclude_invoice_id,
        $variant_name
    ) : 0;

    $product['current_stock'] = $current_stock;
    $product['reserved_stock'] = $reserved_stock;
    $product['available_stock'] = $is_stock_product ? max($current_stock - $reserved_stock, 0) : null;
    $product['is_stock_product'] = $is_stock_product;

    return $product;
}
