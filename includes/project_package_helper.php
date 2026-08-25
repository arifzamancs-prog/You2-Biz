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
