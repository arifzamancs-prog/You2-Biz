<?php

require_once __DIR__ . '/schema_cache_helper.php';

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
        "SELECT s.id AS login_user_id, s.name, s.designation
         FROM staff s
         WHERE s.user_id=?
           AND s.status='active'
           AND TRIM(s.name)<>''
         ORDER BY s.name ASC"
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

    return $options;
}

function ensure_lead_followup_notification_table($conn)
{
    if(!schema_once('ensure_lead_followup_notification_table')) return;
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

// A customer added directly (Customer Manage > Add Customer) with a Ref. staff
// member also appears in that staff member's Successful List as "Converted to
// Customer". A pending lead with the same phone is converted instead, because
// a company keeps one lead per phone. Returns the lead id, or 0 when none.
function lead_record_direct_customer($conn, $company_user_id, $customer_id, $name, $phone, $email, $ref_staff_id, $lead_date = '')
{
    $company_user_id = (int)$company_user_id;
    $customer_id = (int)$customer_id;
    $ref_staff_id = (int)$ref_staff_id;
    $phone = trim((string)$phone);

    if($company_user_id <= 0 || $customer_id <= 0 || $ref_staff_id <= 0 || $phone === ''){
        return 0;
    }

    ensure_lead_management_table($conn);

    $staff_stmt = mysqli_prepare($conn, "SELECT name FROM staff WHERE id=? AND user_id=? LIMIT 1");
    mysqli_stmt_bind_param($staff_stmt, 'ii', $ref_staff_id, $company_user_id);
    mysqli_stmt_execute($staff_stmt);
    $reference_name = trim((string)(mysqli_fetch_assoc(mysqli_stmt_get_result($staff_stmt))['name'] ?? ''));
    mysqli_stmt_close($staff_stmt);

    if($reference_name === ''){
        return 0;
    }

    // The staff member's own manager login owns the lead, so it shows in their Successful List.
    $owner_stmt = mysqli_prepare($conn, "SELECT id, name FROM users WHERE owner_id=? AND staff_id=? AND role='manager' ORDER BY (status='active') DESC, id ASC LIMIT 1");
    mysqli_stmt_bind_param($owner_stmt, 'ii', $company_user_id, $ref_staff_id);
    mysqli_stmt_execute($owner_stmt);
    $owner = mysqli_fetch_assoc(mysqli_stmt_get_result($owner_stmt));
    mysqli_stmt_close($owner_stmt);
    $creator_user_id = $owner ? (int)$owner['id'] : (int)($_SESSION['login_user_id'] ?? $company_user_id);
    $creator_name = $owner ? trim((string)$owner['name']) : trim((string)($_SESSION['login_name'] ?? ''));

    $existing_stmt = mysqli_prepare($conn, "SELECT id, converted_customer_id FROM leads WHERE user_id=? AND phone=? LIMIT 1");
    mysqli_stmt_bind_param($existing_stmt, 'is', $company_user_id, $phone);
    mysqli_stmt_execute($existing_stmt);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($existing_stmt));
    mysqli_stmt_close($existing_stmt);

    if($existing){
        if((int)($existing['converted_customer_id'] ?? 0) > 0){
            return 0; // Already belongs to another customer.
        }

        $lead_id = (int)$existing['id'];
        $update = mysqli_prepare(
            $conn,
            "UPDATE leads
             SET name=?, email=?, status='customer', converted_customer_id=?, reference_user_id=?, reference_name=?
             WHERE id=? AND user_id=? AND (converted_customer_id IS NULL OR converted_customer_id=0)"
        );
        mysqli_stmt_bind_param($update, 'ssiisii', $name, $email, $customer_id, $ref_staff_id, $reference_name, $lead_id, $company_user_id);
        mysqli_stmt_execute($update);
        mysqli_stmt_close($update);
    }else{
        $note = 'Added directly as customer';
        $today = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$lead_date) ? substr((string)$lead_date, 0, 10) : date('Y-m-d');
        $insert = mysqli_prepare(
            $conn,
            "INSERT INTO leads
             (user_id, name, phone, email, note, followup_date, status, created_by_user_id, created_by_name, reference_user_id, reference_name, converted_customer_id)
             VALUES (?, ?, ?, ?, ?, ?, 'customer', ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param($insert, 'isssssisisi', $company_user_id, $name, $phone, $email, $note, $today, $creator_user_id, $creator_name, $ref_staff_id, $reference_name, $customer_id);

        if(!mysqli_stmt_execute($insert)){
            return 0;
        }

        $lead_id = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($insert);
    }

    $link = mysqli_prepare($conn, "UPDATE customers SET lead_id=?, lead_ref_name=? WHERE id=? AND user_id=? AND (lead_id IS NULL OR lead_id=0)");
    mysqli_stmt_bind_param($link, 'isii', $lead_id, $reference_name, $customer_id, $company_user_id);
    mysqli_stmt_execute($link);
    mysqli_stmt_close($link);

    return $lead_id;
}

// Customers added directly with a Ref. before the Successful List tracked them
// get their lead too, oldest first. Runs once per login session per company.
function lead_backfill_direct_customers($conn, $company_user_id)
{
    $company_user_id = (int)$company_user_id;
    $flag = 'lead_direct_customer_backfill_' . $company_user_id;

    if($company_user_id <= 0 || !empty($_SESSION[$flag])){
        return 0;
    }

    $_SESSION[$flag] = true;

    foreach(['ref_staff_id', 'lead_id'] as $column){
        $exists = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE '" . $column . "'");
        if(!$exists || mysqli_num_rows($exists) === 0){
            return 0;
        }
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, customer_name, phone, email, ref_staff_id, created_at
         FROM customers
         WHERE user_id=? AND ref_staff_id>0 AND (lead_id IS NULL OR lead_id=0)
         ORDER BY id ASC"
    );
    mysqli_stmt_bind_param($stmt, 'i', $company_user_id);
    mysqli_stmt_execute($stmt);
    $customers = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    $created = 0;

    foreach($customers as $customer){
        try {
            if(lead_record_direct_customer($conn, $company_user_id, (int)$customer['id'], (string)$customer['customer_name'], (string)$customer['phone'], (string)($customer['email'] ?? ''), (int)$customer['ref_staff_id'], (string)($customer['created_at'] ?? '')) > 0){
                $created++;
            }
        } catch(Throwable $error) {
            error_log('Lead backfill for customer ' . (int)$customer['id'] . ': ' . $error->getMessage());
        }
    }

    return $created;
}
