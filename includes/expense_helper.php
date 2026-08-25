<?php

function reserved_expense_category_names()
{
    return [
        'Salary',
        'Bonus',
        'Incentive',
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
