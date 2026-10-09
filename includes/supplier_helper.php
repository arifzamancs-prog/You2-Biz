<?php

function ensure_restaurant_supplier_code_column($conn)
{
    $column = mysqli_query($conn, "SHOW COLUMNS FROM suppliers LIKE 'supplier_code'");
    if($column && mysqli_num_rows($column) === 0){
        mysqli_query($conn, "ALTER TABLE suppliers ADD COLUMN supplier_code VARCHAR(100) NULL AFTER user_id");
        mysqli_query($conn, "ALTER TABLE suppliers ADD UNIQUE KEY uniq_supplier_code_per_user (user_id, supplier_code)");
    }
}

function supplier_has_transactions($conn, $supplier_id, $user_id)
{
    $supplier_id = (int)$supplier_id;
    $user_id = (int)$user_id;

    if($supplier_id <= 0 || $user_id <= 0){
        return false;
    }

    foreach(['purchases', 'supplier_payments'] as $table){
        $table_result = mysqli_query($conn, "SHOW TABLES LIKE '" . $table . "'");
        if(!$table_result || mysqli_num_rows($table_result) === 0){
            continue;
        }

        $stmt = mysqli_prepare($conn, "SELECT id FROM " . $table . " WHERE supplier_id=? AND user_id=? LIMIT 1");
        if(!$stmt){
            continue;
        }

        mysqli_stmt_bind_param($stmt, 'ii', $supplier_id, $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if($result && mysqli_num_rows($result) > 0){
            return true;
        }
    }

    return false;
}
