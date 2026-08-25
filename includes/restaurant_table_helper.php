<?php

function ensure_restaurant_tables_table($conn)
{
    $column = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'table_system_enabled'");
    if ($column && mysqli_num_rows($column) === 0) {
        mysqli_query($conn, "ALTER TABLE users ADD COLUMN table_system_enabled TINYINT(1) NOT NULL DEFAULT 1");
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

function table_system_enabled($conn, $user_id)
{
    return false;
}
