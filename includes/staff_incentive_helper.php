<?php

function ensure_staff_incentives_table($conn)
{
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS staff_incentives (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        staff_id BIGINT UNSIGNED NOT NULL,
        commission_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_staff_incentive (user_id, staff_id),
        INDEX idx_staff_incentives_staff (staff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
