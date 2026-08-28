<?php

function ensure_notice_board_table($conn)
{
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS notice_board (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(180) NOT NULL,
        message TEXT NOT NULL,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_notice_board_company_status (user_id, status, published_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
