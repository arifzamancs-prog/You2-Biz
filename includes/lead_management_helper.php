<?php

function ensure_lead_management_table($conn)
{
    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS leads (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(150) NOT NULL,
            phone VARCHAR(40) NOT NULL,
            email VARCHAR(150) NULL,
            note TEXT NULL,
            followup_date DATE NULL,
            status ENUM('lead','successful','customer') NOT NULL DEFAULT 'lead',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_leads_user_status (user_id, status),
            INDEX idx_leads_followup (followup_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function lead_management_filters()
{
    return [
        'lead' => 'New Lead',
        'successful' => 'Successful List',
        'customer' => 'Convert to Customer List',
    ];
}

function normalize_lead_filter($filter)
{
    $filters = lead_management_filters();
    $filter = strtolower(trim((string)$filter));

    return array_key_exists($filter, $filters) ? $filter : 'lead';
}

function lead_management_title($filter)
{
    $filters = lead_management_filters();
    return $filters[normalize_lead_filter($filter)];
}

function lead_code_from_id($id)
{
    return 'LD-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}
