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
            status ENUM('lead','successful','customer','not_qualified','visited','indecision') NOT NULL DEFAULT 'lead',
            created_by_user_id BIGINT UNSIGNED NULL,
            created_by_name VARCHAR(150) NULL,
            reference_user_id BIGINT UNSIGNED NULL,
            reference_name VARCHAR(150) NULL,
            converted_customer_id BIGINT UNSIGNED NULL,
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
    if($status_info && (stripos((string)$status_info['COLUMN_TYPE'], "'visited'") === false || stripos((string)$status_info['COLUMN_TYPE'], "'indecision'") === false)){
        mysqli_query($conn, "ALTER TABLE leads MODIFY status ENUM('lead','successful','customer','not_qualified','visited','indecision') NOT NULL DEFAULT 'lead'");
    }

    $creator_column = mysqli_query($conn, "SHOW COLUMNS FROM leads LIKE 'created_by_name'");
    if($creator_column && mysqli_num_rows($creator_column) === 0){
        mysqli_query($conn, "ALTER TABLE leads ADD COLUMN created_by_name VARCHAR(150) NULL AFTER status");
    }

    $creator_user_column = mysqli_query($conn, "SHOW COLUMNS FROM leads LIKE 'created_by_user_id'");
    if($creator_user_column && mysqli_num_rows($creator_user_column) === 0){
        mysqli_query($conn, "ALTER TABLE leads ADD COLUMN created_by_user_id BIGINT UNSIGNED NULL AFTER status");
        mysqli_query($conn, "ALTER TABLE leads ADD INDEX idx_leads_creator (user_id, created_by_user_id)");
    }

    // A lead's maintainer and its final referral are different responsibilities.
    // Keep the maintainer in created_by_* and store the referral independently.
    $reference_user_column = mysqli_query($conn, "SHOW COLUMNS FROM leads LIKE 'reference_user_id'");
    if($reference_user_column && mysqli_num_rows($reference_user_column) === 0){
        mysqli_query($conn, "ALTER TABLE leads ADD COLUMN reference_user_id BIGINT UNSIGNED NULL AFTER created_by_user_id");
        mysqli_query($conn, "ALTER TABLE leads ADD INDEX idx_leads_reference (user_id, reference_user_id)");
    }

    $reference_name_column = mysqli_query($conn, "SHOW COLUMNS FROM leads LIKE 'reference_name'");
    if($reference_name_column && mysqli_num_rows($reference_name_column) === 0){
        mysqli_query($conn, "ALTER TABLE leads ADD COLUMN reference_name VARCHAR(150) NULL AFTER created_by_name");
    }

    $converted_column = mysqli_query($conn, "SHOW COLUMNS FROM leads LIKE 'converted_customer_id'");
    if($converted_column && mysqli_num_rows($converted_column) === 0){
        mysqli_query($conn, "ALTER TABLE leads ADD COLUMN converted_customer_id BIGINT UNSIGNED NULL AFTER created_by_name");
        mysqli_query($conn, "ALTER TABLE leads ADD INDEX idx_leads_converted_customer (converted_customer_id)");
    }

    $phone_index = mysqli_query($conn, "SHOW INDEX FROM leads WHERE Key_name='uniq_leads_user_phone'");
    if($phone_index && mysqli_num_rows($phone_index) === 0){
        $duplicate_phone = mysqli_query(
            $conn,
            'SELECT user_id, phone FROM leads GROUP BY user_id, phone HAVING COUNT(*) > 1 LIMIT 1'
        );
        if($duplicate_phone && mysqli_num_rows($duplicate_phone) === 0){
            mysqli_query($conn, 'ALTER TABLE leads ADD UNIQUE KEY uniq_leads_user_phone (user_id, phone)');
        }
    }

    mysqli_query(
        $conn,
        "UPDATE leads l
         INNER JOIN users u ON u.id=l.user_id
         SET l.created_by_name=u.name
         WHERE (l.created_by_name IS NULL OR TRIM(l.created_by_name)='')"
    );

    // Existing leads used created_by_* as their referral. Retain that history on
    // first migration while allowing future referral changes without changing
    // the staff member who maintains the lead.
    mysqli_query(
        $conn,
        "UPDATE leads
         SET reference_user_id=created_by_user_id,
             reference_name=created_by_name
         WHERE (reference_user_id IS NULL OR reference_user_id=0)
           AND (reference_name IS NULL OR TRIM(reference_name)='')"
    );
}

function lead_reference_options($conn, $company_user_id, $current_login_user_id = 0, $current_login_name = '')
{
    $options = [];
    $stmt = mysqli_prepare(
        $conn,
        "SELECT u.id AS login_user_id,
                COALESCE(NULLIF(TRIM(s.name), ''), u.name) AS name,
                s.designation
         FROM users u
         LEFT JOIN staff s ON s.id=u.staff_id AND s.user_id=u.owner_id
         WHERE u.owner_id=?
           AND u.role='manager'
           AND u.status='active'
           AND (s.id IS NULL OR s.status='active')
         ORDER BY name ASC"
    );
    if($stmt){
        mysqli_stmt_bind_param($stmt, 'i', $company_user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while($result && $row = mysqli_fetch_assoc($result)){
            $options[(int)$row['login_user_id']] = $row;
        }
        mysqli_stmt_close($stmt);
    }

    if($current_login_user_id > 0 && !isset($options[$current_login_user_id])){
        $current_name = trim((string)$current_login_name);
        if($current_name === ''){
            $current_stmt = mysqli_prepare($conn, 'SELECT name FROM users WHERE id=? LIMIT 1');
            if($current_stmt){
                mysqli_stmt_bind_param($current_stmt, 'i', $current_login_user_id);
                mysqli_stmt_execute($current_stmt);
                $current = mysqli_fetch_assoc(mysqli_stmt_get_result($current_stmt));
                $current_name = trim((string)($current['name'] ?? ''));
                mysqli_stmt_close($current_stmt);
            }
        }
        if($current_name !== ''){
            $options[$current_login_user_id] = [
                'login_user_id' => $current_login_user_id,
                'name' => $current_name,
                'designation' => '',
            ];
        }
    }

    return $options;
}

function ensure_lead_followup_notification_table($conn)
{
    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS lead_followup_notification_reads (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            lead_id BIGINT UNSIGNED NOT NULL,
            followup_date DATE NOT NULL,
            read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_lead_followup_notification (user_id, lead_id, followup_date),
            INDEX idx_lead_followup_notification_lead (lead_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function lead_management_filters()
{
    return [
        'lead' => 'New Lead',
        'successful' => 'Qualified List',
        'not_qualified' => 'Not Qualified List',
        'visited' => 'Visited List',
        'indecision' => 'Indecision List',
        'customer' => 'Successful List',
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
    return str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}
