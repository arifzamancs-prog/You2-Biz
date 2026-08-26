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
            status ENUM('lead','successful','customer','not_qualified') NOT NULL DEFAULT 'lead',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_leads_user_status (user_id, status),
            INDEX idx_leads_followup (followup_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $status_column = mysqli_query(
        $conn,
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE()
         AND TABLE_NAME='leads'
         AND COLUMN_NAME='status'"
    );
    $status_info = $status_column ? mysqli_fetch_assoc($status_column) : null;
    if($status_info && stripos((string)$status_info['COLUMN_TYPE'], "'not_qualified'") === false){
        mysqli_query($conn, "ALTER TABLE leads MODIFY status ENUM('lead','successful','customer','not_qualified') NOT NULL DEFAULT 'lead'");
    }
}

function lead_management_filters()
{
    return [
        'lead' => 'New Lead',
        'successful' => 'Qualified List',
        'not_qualified' => 'Not Qualified List',
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
