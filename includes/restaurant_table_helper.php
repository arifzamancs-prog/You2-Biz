<?php

require_once __DIR__ . '/company_settings_helper.php';

function ensure_restaurant_tables_table($conn)
{
    $column = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'table_system_enabled'");
    if ($column && mysqli_num_rows($column) === 0) {
        mysqli_query($conn, "ALTER TABLE users ADD COLUMN table_system_enabled TINYINT(1) NOT NULL DEFAULT 1");
    }
    $reference_column = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'restaurant_invoice_reference_type'");
    if ($reference_column && mysqli_num_rows($reference_column) === 0) {
        mysqli_query($conn, "ALTER TABLE users ADD COLUMN restaurant_invoice_reference_type VARCHAR(10) NOT NULL DEFAULT 'staff'");
    }
    $reference_enabled_column = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'restaurant_invoice_reference_enabled'");
    if ($reference_enabled_column && mysqli_num_rows($reference_enabled_column) === 0) {
        mysqli_query($conn, "ALTER TABLE users ADD COLUMN restaurant_invoice_reference_enabled TINYINT(1) NOT NULL DEFAULT 1");
    }
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS restaurant_tables (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        staff_id BIGINT UNSIGNED NULL,
        table_name VARCHAR(100) NOT NULL,
        capacity INT UNSIGNED NULL,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_restaurant_table_name (user_id, table_name),
        INDEX idx_restaurant_tables_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $column = mysqli_query($conn, "SHOW COLUMNS FROM restaurant_tables LIKE 'staff_id'");
    if ($column && mysqli_num_rows($column) === 0) {
        mysqli_query($conn, "ALTER TABLE restaurant_tables ADD COLUMN staff_id BIGINT UNSIGNED NULL AFTER user_id");
        mysqli_query($conn, "ALTER TABLE restaurant_tables ADD INDEX idx_restaurant_tables_staff (staff_id)");
    }
}

function restaurant_invoice_reference_type($conn, $user_id)
{
    $stmt = mysqli_prepare($conn, 'SELECT restaurant_invoice_reference_type FROM users WHERE id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return ($row['restaurant_invoice_reference_type'] ?? 'staff') === 'table' ? 'table' : 'staff';
}

function restaurant_invoice_reference_enabled($conn, $user_id)
{
    $stmt = mysqli_prepare($conn, 'SELECT restaurant_invoice_reference_enabled FROM users WHERE id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return !isset($row['restaurant_invoice_reference_enabled']) || (int)$row['restaurant_invoice_reference_enabled'] === 1;
}

function table_system_enabled($conn, $user_id)
{
    $user_id = (int)$user_id;
    if ($user_id <= 0) return false;

    $stmt = mysqli_prepare($conn, 'SELECT company_type FROM users WHERE id=? AND role=\'admin\' LIMIT 1');
    if (!$stmt) return false;

    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $company = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    return $company
        && normalize_company_type($company['company_type'] ?? '') === 'Restaurant & Cafe';
}
