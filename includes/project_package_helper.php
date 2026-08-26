<?php

function ensure_project_package_tables($conn)
{
    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS projects (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            project_name VARCHAR(150) NOT NULL,
            project_code VARCHAR(100) NOT NULL,
            description TEXT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_project_code_per_user (user_id, project_code),
            INDEX idx_projects_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS packages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            project_id BIGINT UNSIGNED NOT NULL,
            package_name VARCHAR(180) NOT NULL,
            package_code VARCHAR(100) NULL,
            price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            description TEXT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_package_code_per_user (user_id, package_code),
            INDEX idx_packages_user (user_id),
            INDEX idx_packages_project (project_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function project_has_transactions($conn, $project_id, $user_id)
{
    $project_id = (int)$project_id;
    $user_id = (int)$user_id;

    $package_stmt = mysqli_prepare($conn, "SELECT id FROM packages WHERE project_id=? AND user_id=? LIMIT 1");
    mysqli_stmt_bind_param($package_stmt, 'ii', $project_id, $user_id);
    mysqli_stmt_execute($package_stmt);
    if(mysqli_num_rows(mysqli_stmt_get_result($package_stmt)) > 0){
        return true;
    }

    $table_result = mysqli_query($conn, "SHOW TABLES LIKE 'booking_invoices'");
    if($table_result && mysqli_num_rows($table_result) > 0){
        $invoice_stmt = mysqli_prepare($conn, "SELECT id FROM booking_invoices WHERE project_id=? AND user_id=? LIMIT 1");
        mysqli_stmt_bind_param($invoice_stmt, 'ii', $project_id, $user_id);
        mysqli_stmt_execute($invoice_stmt);
        return mysqli_num_rows(mysqli_stmt_get_result($invoice_stmt)) > 0;
    }

    return false;
}

function package_has_transactions($conn, $package_id, $user_id)
{
    $package_id = (int)$package_id;
    $user_id = (int)$user_id;
    $table_result = mysqli_query($conn, "SHOW TABLES LIKE 'booking_invoices'");

    if(!$table_result || mysqli_num_rows($table_result) === 0){
        return false;
    }

    $stmt = mysqli_prepare($conn, "SELECT id FROM booking_invoices WHERE package_id=? AND user_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $package_id, $user_id);
    mysqli_stmt_execute($stmt);
    return mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
}
