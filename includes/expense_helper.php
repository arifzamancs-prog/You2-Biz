<?php

function reserved_expense_category_names()
{
    return [
        'Salary',
        'Bonus',
        'Incentive',
        'Supplier Payment',
    ];
}

function expense_category_is_reserved($category_name)
{
    return in_array(trim((string)$category_name), reserved_expense_category_names(), true);
}

function ensure_expense_support_tables($conn, $user_id)
{
    $staff_column = mysqli_query($conn, "SHOW COLUMNS FROM expenses LIKE 'staff_id'");
    if($staff_column && mysqli_num_rows($staff_column) === 0){
        mysqli_query($conn, "ALTER TABLE expenses ADD COLUMN staff_id BIGINT UNSIGNED NULL AFTER category_id");
        mysqli_query($conn, "ALTER TABLE expenses ADD INDEX idx_expenses_staff (staff_id)");
    }

    $source_type_column = mysqli_query($conn, "SHOW COLUMNS FROM expenses LIKE 'source_type'");
    if($source_type_column && mysqli_num_rows($source_type_column) === 0){
        mysqli_query($conn, "ALTER TABLE expenses ADD COLUMN source_type VARCHAR(40) NULL AFTER note");
    }

    $source_id_column = mysqli_query($conn, "SHOW COLUMNS FROM expenses LIKE 'source_id'");
    if($source_id_column && mysqli_num_rows($source_id_column) === 0){
        mysqli_query($conn, "ALTER TABLE expenses ADD COLUMN source_id BIGINT UNSIGNED NULL AFTER source_type");
        mysqli_query($conn, "ALTER TABLE expenses ADD INDEX idx_expenses_source (user_id, source_type, source_id)");
    }

    $hidden_column = mysqli_query($conn, "SHOW COLUMNS FROM categories LIKE 'is_hidden'");
    if($hidden_column && mysqli_num_rows($hidden_column) === 0){
        mysqli_query($conn, "ALTER TABLE categories ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER category_name");
    }

    ensure_reserved_expense_categories($conn, $user_id);
}

function ensure_reserved_expense_categories($conn, $user_id)
{
    foreach(reserved_expense_category_names() as $category_name){
        $stmt = mysqli_prepare(
            $conn,
            "SELECT id
             FROM categories
             WHERE user_id=?
             AND category_name=?
             LIMIT 1"
        );
        mysqli_stmt_bind_param($stmt, 'is', $user_id, $category_name);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;

        if($row){
            $update_stmt = mysqli_prepare(
                $conn,
                "UPDATE categories
                 SET status='active', is_hidden=1
                 WHERE id=?
                 AND user_id=?"
            );
            $category_id = (int)$row['id'];
            mysqli_stmt_bind_param($update_stmt, 'ii', $category_id, $user_id);
            mysqli_stmt_execute($update_stmt);
            continue;
        }

        $insert_stmt = mysqli_prepare(
            $conn,
            "INSERT INTO categories (user_id, category_name, status, is_hidden)
             VALUES (?, ?, 'active', 1)"
        );
        mysqli_stmt_bind_param($insert_stmt, 'is', $user_id, $category_name);
        mysqli_stmt_execute($insert_stmt);
    }
}

function reserved_expense_category_id($conn, $user_id, $category_name)
{
    ensure_reserved_expense_categories($conn, $user_id);

    $stmt = mysqli_prepare(
        $conn,
        "SELECT id
         FROM categories
         WHERE user_id=?
         AND category_name=?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'is', $user_id, $category_name);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    return (int)($row['id'] ?? 0);
}

function reserved_expense_category_name_from_entry_type($entry_type)
{
    $map = [
        'salary' => 'Salary',
        'bonus' => 'Bonus',
        'incentive' => 'Incentive',
    ];

    return $map[strtolower(trim((string)$entry_type))] ?? '';
}

/**
 * Adds an accounting row for a supplier-related payment. The caller has
 * already debited the wallet, so this function never creates another wallet
 * transaction or changes the wallet balance.
 */
function record_supplier_payment_expense($conn, $user_id, $wallet_id, $amount, $txn_date, $note, $source_type, $source_id)
{
    $user_id = (int)$user_id;
    $wallet_id = (int)$wallet_id;
    $amount = (float)$amount;
    $source_id = (int)$source_id;
    $source_type = trim((string)$source_type);

    if($amount <= 0 || $wallet_id <= 0 || $source_id <= 0 || !in_array($source_type, ['purchase_payment', 'supplier_payment'], true)){
        return 0;
    }

    ensure_expense_support_tables($conn, $user_id);

    $existing_stmt = mysqli_prepare(
        $conn,
        "SELECT id FROM expenses
         WHERE user_id=? AND source_type=? AND source_id=?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($existing_stmt, 'isi', $user_id, $source_type, $source_id);
    mysqli_stmt_execute($existing_stmt);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($existing_stmt));

    if($existing){
        return (int)$existing['id'];
    }

    $category_id = reserved_expense_category_id($conn, $user_id, 'Supplier Payment');
    if($category_id <= 0){
        throw new Exception('Supplier Payment expense category could not be created.');
    }

    $txn_no = function_exists('generate_short_unique_txn_no')
        ? generate_short_unique_txn_no($conn, 'EXP', 'expenses')
        : 'EXP-' . date('ymdHis') . '-' . $source_id;
    $staff_id = 0;
    $approval_status = 'approved';
    $approved_at = date('Y-m-d H:i:s');

    $insert_stmt = mysqli_prepare(
        $conn,
        "INSERT INTO expenses
         (txn_no, user_id, wallet_id, category_id, staff_id, txn_date, amount, note, source_type, source_id, approval_status, created_by, approved_by, approved_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $bind_types = 's' . str_repeat('i', 4) . 's' . 'd' . 's' . 's' . 'i' . 's' . str_repeat('i', 2) . 's';
    mysqli_stmt_bind_param(
        $insert_stmt,
        $bind_types,
        $txn_no,
        $user_id,
        $wallet_id,
        $category_id,
        $staff_id,
        $txn_date,
        $amount,
        $note,
        $source_type,
        $source_id,
        $approval_status,
        $user_id,
        $user_id,
        $approved_at
    );

    if(!mysqli_stmt_execute($insert_stmt)){
        throw new Exception(mysqli_stmt_error($insert_stmt));
    }

    return (int)mysqli_insert_id($conn);
}
