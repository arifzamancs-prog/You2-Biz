<?php

function ensure_fifo_inventory_tables($conn)
{
    static $checked = false;

    if($checked){
        return;
    }

    $checked = true;

    $remaining_column = mysqli_query($conn, "SHOW COLUMNS FROM purchase_items LIKE 'remaining_quantity'");

    if($remaining_column && mysqli_num_rows($remaining_column) === 0){
        mysqli_query(
            $conn,
            "ALTER TABLE purchase_items
             ADD COLUMN remaining_quantity DOUBLE NOT NULL DEFAULT 0 AFTER quantity"
        );
        mysqli_query(
            $conn,
            "UPDATE purchase_items
             SET remaining_quantity = quantity
             WHERE remaining_quantity = 0"
        );
    }

    $cost_column = mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'cost_amount'");

    if($cost_column && mysqli_num_rows($cost_column) === 0){
        mysqli_query(
            $conn,
            "ALTER TABLE invoice_items
             ADD COLUMN cost_amount DOUBLE NOT NULL DEFAULT 0 AFTER total_price"
        );
    }

    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS stock_batches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            product_id INT NOT NULL,
            source_type VARCHAR(30) NOT NULL DEFAULT 'purchase',
            source_id INT NOT NULL DEFAULT 0,
            source_no VARCHAR(100) NULL,
            quantity DOUBLE NOT NULL DEFAULT 0,
            remaining_quantity DOUBLE NOT NULL DEFAULT 0,
            unit_cost DOUBLE NOT NULL DEFAULT 0,
            batch_date DATE NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );

    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS invoice_item_allocations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_item_id INT NOT NULL,
            stock_batch_id INT NOT NULL,
            quantity DOUBLE NOT NULL DEFAULT 0,
            unit_cost DOUBLE NOT NULL DEFAULT 0,
            total_cost DOUBLE NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );

    $opening_stock_column = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'opening_stock_quantity'");

    if($opening_stock_column && mysqli_num_rows($opening_stock_column) === 0){
        mysqli_query(
            $conn,
            "ALTER TABLE products
             ADD COLUMN opening_stock_quantity DOUBLE NOT NULL DEFAULT 0 AFTER current_stock"
        );
        mysqli_query(
            $conn,
            "UPDATE products
             SET opening_stock_quantity = current_stock
             WHERE opening_stock_quantity = 0"
        );
    }

    $opening_cost_column = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'opening_stock_unit_cost'");

    if($opening_cost_column && mysqli_num_rows($opening_cost_column) === 0){
        mysqli_query(
            $conn,
            "ALTER TABLE products
             ADD COLUMN opening_stock_unit_cost DOUBLE NOT NULL DEFAULT 0 AFTER opening_stock_quantity"
        );
        mysqli_query(
            $conn,
            "UPDATE products
             SET opening_stock_unit_cost = purchase_price
             WHERE opening_stock_unit_cost = 0"
        );
    }
}

function fifo_inventory_create_batch($conn, $user_id, $product_id, $quantity, $unit_cost, $source_type, $source_id, $source_no, $batch_date)
{
    ensure_fifo_inventory_tables($conn);

    $quantity = (float)$quantity;
    $unit_cost = (float)$unit_cost;
    $source_type = trim((string)$source_type);
    $source_no = trim((string)$source_no);
    $batch_date = trim((string)$batch_date);

    if($quantity <= 0){
        return true;
    }

    $sql = "INSERT INTO stock_batches
            (
                user_id,
                product_id,
                source_type,
                source_id,
                source_no,
                quantity,
                remaining_quantity,
                unit_cost,
                batch_date
            )
            VALUES
            (
                ?, ?, ?, ?, ?, ?, ?, ?, ?
            )";

    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return false;
    }

    mysqli_stmt_bind_param(
        $stmt,
        "iisissdds",
        $user_id,
        $product_id,
        $source_type,
        $source_id,
        $source_no,
        $quantity,
        $quantity,
        $unit_cost,
        $batch_date
    );

    return mysqli_stmt_execute($stmt);
}

function fifo_inventory_get_available_stock($conn, $user_id, $product_id)
{
    ensure_fifo_inventory_tables($conn);

    $sql = "SELECT COALESCE(SUM(remaining_quantity), 0) AS available_stock
            FROM stock_batches
            WHERE user_id=?
            AND product_id=?
            AND remaining_quantity > 0";

    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return 0;
    }

    mysqli_stmt_bind_param($stmt, "ii", $user_id, $product_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    return (float)($row['available_stock'] ?? 0);
}

function fifo_inventory_allocate_sale($conn, $user_id, $invoice_item_id, $product_id, $quantity)
{
    ensure_fifo_inventory_tables($conn);

    $quantity = (float)$quantity;

    if($quantity <= 0){
        return [
            'success' => true,
            'cost_amount' => 0,
        ];
    }

    $available_stock = fifo_inventory_get_available_stock($conn, $user_id, $product_id);

    if($available_stock + 0.0001 < $quantity){
        return [
            'success' => false,
            'error' => 'Insufficient FIFO stock.',
            'cost_amount' => 0,
        ];
    }

    $sql = "SELECT id, remaining_quantity, unit_cost
            FROM stock_batches
            WHERE user_id=?
            AND product_id=?
            AND remaining_quantity > 0
            ORDER BY COALESCE(batch_date, DATE(created_at)) ASC, id ASC";

    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return [
            'success' => false,
            'error' => 'Stock batch query failed.',
            'cost_amount' => 0,
        ];
    }

    mysqli_stmt_bind_param($stmt, "ii", $user_id, $product_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $needed = $quantity;
    $cost_amount = 0;

    while($batch = mysqli_fetch_assoc($result)){
        if($needed <= 0){
            break;
        }

        $batch_id = (int)$batch['id'];
        $batch_remaining = (float)$batch['remaining_quantity'];
        $unit_cost = (float)$batch['unit_cost'];
        $used_quantity = min($needed, $batch_remaining);
        $line_cost = $used_quantity * $unit_cost;

        $update_stmt = mysqli_prepare(
            $conn,
            "UPDATE stock_batches
             SET remaining_quantity = remaining_quantity - ?
             WHERE id=?"
        );

        if(!$update_stmt){
            return [
                'success' => false,
                'error' => 'Stock batch update failed.',
                'cost_amount' => 0,
            ];
        }

        mysqli_stmt_bind_param($update_stmt, "di", $used_quantity, $batch_id);
        mysqli_stmt_execute($update_stmt);

        $alloc_stmt = mysqli_prepare(
            $conn,
            "INSERT INTO invoice_item_allocations
             (
                invoice_item_id,
                stock_batch_id,
                quantity,
                unit_cost,
                total_cost
             )
             VALUES
             (
                ?, ?, ?, ?, ?
             )"
        );

        if(!$alloc_stmt){
            return [
                'success' => false,
                'error' => 'Allocation save failed.',
                'cost_amount' => 0,
            ];
        }

        mysqli_stmt_bind_param(
            $alloc_stmt,
            "iiddd",
            $invoice_item_id,
            $batch_id,
            $used_quantity,
            $unit_cost,
            $line_cost
        );
        mysqli_stmt_execute($alloc_stmt);

        $cost_amount += $line_cost;
        $needed -= $used_quantity;
    }

    if($needed > 0.0001){
        return [
            'success' => false,
            'error' => 'FIFO allocation incomplete.',
            'cost_amount' => 0,
        ];
    }

    $cost_stmt = mysqli_prepare(
        $conn,
        "UPDATE invoice_items
         SET cost_amount=?
         WHERE id=?"
    );

    if($cost_stmt){
        mysqli_stmt_bind_param($cost_stmt, "di", $cost_amount, $invoice_item_id);
        mysqli_stmt_execute($cost_stmt);
    }

    return [
        'success' => true,
        'cost_amount' => $cost_amount,
    ];
}

function fifo_inventory_add_return_batch($conn, $user_id, $invoice_item_id, $product_id, $quantity, $unit_cost, $source_no, $batch_date)
{
    ensure_fifo_inventory_tables($conn);

    $quantity = abs((float)$quantity);
    $unit_cost = max(0, (float)$unit_cost);

    if($quantity <= 0){
        return true;
    }

    $created = fifo_inventory_create_batch(
        $conn,
        $user_id,
        $product_id,
        $quantity,
        $unit_cost,
        'sales_return',
        $invoice_item_id,
        $source_no,
        $batch_date
    );

    if(!$created){
        return false;
    }

    $cost_amount = -($quantity * $unit_cost);
    $stmt = mysqli_prepare($conn, "UPDATE invoice_items SET cost_amount=? WHERE id=?");

    if($stmt){
        mysqli_stmt_bind_param($stmt, "di", $cost_amount, $invoice_item_id);
        mysqli_stmt_execute($stmt);
    }

    return true;
}

function fifo_inventory_restore_invoice_item($conn, $invoice_item_id)
{
    ensure_fifo_inventory_tables($conn);

    $invoice_item_id = (int)$invoice_item_id;

    $sql = "SELECT stock_batch_id, quantity
            FROM invoice_item_allocations
            WHERE invoice_item_id=?";

    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return false;
    }

    mysqli_stmt_bind_param($stmt, "i", $invoice_item_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while($row = mysqli_fetch_assoc($result)){
        $update_stmt = mysqli_prepare(
            $conn,
            "UPDATE stock_batches
             SET remaining_quantity = remaining_quantity + ?
             WHERE id=?"
        );

        if($update_stmt){
            mysqli_stmt_bind_param($update_stmt, "di", $row['quantity'], $row['stock_batch_id']);
            mysqli_stmt_execute($update_stmt);
        }
    }

    mysqli_query($conn, "DELETE FROM invoice_item_allocations WHERE invoice_item_id=" . $invoice_item_id);
    mysqli_query($conn, "DELETE FROM stock_batches WHERE source_type='sales_return' AND source_id=" . $invoice_item_id);
    mysqli_query($conn, "UPDATE invoice_items SET cost_amount=0 WHERE id=" . $invoice_item_id);

    return true;
}

function fifo_inventory_purchase_is_editable($conn, $purchase_id)
{
    ensure_fifo_inventory_tables($conn);

    $purchase_id = (int)$purchase_id;

    $sql = "SELECT COUNT(*) AS used_batches
            FROM stock_batches
            WHERE source_type='purchase'
            AND source_id=?
            AND ABS(quantity - remaining_quantity) > 0.0001";

    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return false;
    }

    mysqli_stmt_bind_param($stmt, "i", $purchase_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    return (int)($row['used_batches'] ?? 0) === 0;
}

function fifo_inventory_remove_purchase_batches($conn, $purchase_id)
{
    ensure_fifo_inventory_tables($conn);

    $purchase_id = (int)$purchase_id;

    return mysqli_query(
        $conn,
        "DELETE FROM stock_batches
         WHERE source_type='purchase'
         AND source_id={$purchase_id}"
    ) !== false;
}

function fifo_inventory_remove_product_opening_batches($conn, $product_id)
{
    ensure_fifo_inventory_tables($conn);

    $product_id = (int)$product_id;

    return mysqli_query(
        $conn,
        "DELETE FROM stock_batches
         WHERE source_type='product_opening'
         AND source_id={$product_id}"
    ) !== false;
}

function fifo_inventory_product_opening_is_editable($conn, $user_id, $product_id)
{
    ensure_fifo_inventory_tables($conn);

    $user_id = (int)$user_id;
    $product_id = (int)$product_id;

    $sql = "SELECT COUNT(*) AS restricted_count
            FROM stock_batches
            WHERE user_id=?
            AND product_id=?
            AND (
                (source_type='product_opening' AND source_id=? AND ABS(quantity - remaining_quantity) > 0.0001)
                OR
                NOT (source_type='product_opening' AND source_id=?)
            )";

    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return false;
    }

    mysqli_stmt_bind_param($stmt, "iiii", $user_id, $product_id, $product_id, $product_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    return (int)($row['restricted_count'] ?? 0) === 0;
}

function fifo_inventory_product_is_editable_before_sale($conn, $user_id, $product_id)
{
    ensure_fifo_inventory_tables($conn);

    $user_id = (int)$user_id;
    $product_id = (int)$product_id;

    $sql = "SELECT COUNT(*) AS consumed_batches
            FROM stock_batches
            WHERE user_id=?
            AND product_id=?
            AND ABS(quantity - remaining_quantity) > 0.0001";

    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return false;
    }

    mysqli_stmt_bind_param($stmt, "ii", $user_id, $product_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    return (int)($row['consumed_batches'] ?? 0) === 0;
}
