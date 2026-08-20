<?php

function ensure_trial_leads_table($conn)
{
    static $ensured = false;

    if($ensured){
        return;
    }

    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS trial_leads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(255) NOT NULL DEFAULT '',
            phone VARCHAR(50) NOT NULL DEFAULT '',
            business_name VARCHAR(255) NOT NULL DEFAULT '',
            landing_page VARCHAR(500) NOT NULL DEFAULT '',
            referrer_url VARCHAR(500) NOT NULL DEFAULT '',
            utm_source VARCHAR(100) NOT NULL DEFAULT '',
            utm_medium VARCHAR(100) NOT NULL DEFAULT '',
            utm_campaign VARCHAR(150) NOT NULL DEFAULT '',
            utm_content VARCHAR(150) NOT NULL DEFAULT '',
            utm_term VARCHAR(150) NOT NULL DEFAULT '',
            fbclid VARCHAR(255) NOT NULL DEFAULT '',
            ip_address VARCHAR(64) NOT NULL DEFAULT '',
            user_agent TEXT NULL,
            lead_status VARCHAR(30) NOT NULL DEFAULT 'new',
            notes TEXT NULL,
            next_follow_up_date DATE NULL,
            contacted_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_trial_leads_status (lead_status),
            INDEX idx_trial_leads_created_at (created_at),
            INDEX idx_trial_leads_phone (phone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = [
        'notes' => "ALTER TABLE trial_leads ADD COLUMN notes TEXT NULL AFTER lead_status",
        'next_follow_up_date' => "ALTER TABLE trial_leads ADD COLUMN next_follow_up_date DATE NULL AFTER notes",
        'contacted_at' => "ALTER TABLE trial_leads ADD COLUMN contacted_at DATETIME NULL AFTER next_follow_up_date",
        'updated_at' => "ALTER TABLE trial_leads ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER contacted_at",
    ];

    foreach($columns as $column => $sql){
        $escaped_column = mysqli_real_escape_string($conn, $column);
        $check = mysqli_query($conn, "SHOW COLUMNS FROM trial_leads LIKE '{$escaped_column}'");

        if($check && mysqli_num_rows($check) === 0){
            mysqli_query($conn, $sql);
        }
    }

    $ensured = true;
}

function trial_lead_clean_value($value, $max_length = 255)
{
    $value = trim((string)$value);

    if($max_length > 0 && mb_strlen($value) > $max_length){
        $value = mb_substr($value, 0, $max_length);
    }

    return $value;
}

function trial_lead_redirect_url()
{
    return rtrim(app_base_url(), '/') . '/';
}

function trial_lead_error_redirect_url()
{
    return rtrim(app_base_url(), '/') . app_path('trial-offer/?trial=error');
}

function trial_lead_status_options()
{
    return [
        'new' => 'New',
        'contacted' => 'Contacted',
        'follow_up' => 'Follow Up',
        'converted' => 'Converted',
        'closed' => 'Closed',
    ];
}

function trial_lead_normalize_status($status)
{
    $status = strtolower(trim((string)$status));
    $allowed = trial_lead_status_options();

    return isset($allowed[$status]) ? $status : 'new';
}

function trial_lead_status_label($status)
{
    $status = trial_lead_normalize_status($status);
    $allowed = trial_lead_status_options();

    return $allowed[$status] ?? 'New';
}

function trial_lead_status_badge($status)
{
    $status = trial_lead_normalize_status($status);

    $badges = [
        'new' => 'secondary',
        'contacted' => 'info',
        'follow_up' => 'warning',
        'converted' => 'success',
        'closed' => 'dark',
    ];

    return $badges[$status] ?? 'secondary';
}
