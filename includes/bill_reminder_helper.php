<?php

require_once __DIR__ . '/system_settings_helper.php';
require_once __DIR__ . '/branding_helper.php';

function bill_reminder_ensure_columns($conn)
{
    $columns = [
        'bill_reminder_enabled' => "ALTER TABLE users ADD COLUMN bill_reminder_enabled TINYINT(1) NOT NULL DEFAULT 0",
        'bill_reminder_date' => "ALTER TABLE users ADD COLUMN bill_reminder_date DATE NULL",
        'bill_reminder_day' => "ALTER TABLE users ADD COLUMN bill_reminder_day TINYINT UNSIGNED NULL",
        'service_charge_amount' => "ALTER TABLE users ADD COLUMN service_charge_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00",
    ];

    foreach($columns as $column => $sql){
        $result = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE '" . mysqli_real_escape_string($conn, $column) . "'");

        if($result && mysqli_num_rows($result) === 0 && !mysqli_query($conn, $sql)){
            throw new Exception('Company billing settings could not be prepared: ' . mysqli_error($conn));
        }
    }

    // Existing one-time dates become the matching monthly billing day, so no
    // company loses its reminder setting during this upgrade.
    mysqli_query(
        $conn,
        "UPDATE users
         SET bill_reminder_day = DAY(bill_reminder_date)
         WHERE bill_reminder_day IS NULL
           AND bill_reminder_date IS NOT NULL"
    );
}

function ensure_company_bill_payment_references_table($conn)
{
    $sql = "CREATE TABLE IF NOT EXISTS company_bill_payment_references (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        bill_reminder_date DATE NOT NULL,
        service_charge_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        reference_no VARCHAR(150) NOT NULL DEFAULT '',
        submitted_by_user_id INT NOT NULL DEFAULT 0,
        submitted_at DATETIME NULL,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_company_bill_cycle (company_id, bill_reminder_date),
        KEY idx_company_bill_status (company_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if(!mysqli_query($conn, $sql)){
        throw new Exception('Bill payment references could not be prepared: ' . mysqli_error($conn));
    }

    $columns = [
        'verification_token_hash' => "ALTER TABLE company_bill_payment_references ADD COLUMN verification_token_hash VARCHAR(64) NULL",
        'verification_expires_at' => "ALTER TABLE company_bill_payment_references ADD COLUMN verification_expires_at DATETIME NULL",
        'verified_by_user_id' => "ALTER TABLE company_bill_payment_references ADD COLUMN verified_by_user_id INT NOT NULL DEFAULT 0",
        'verified_at' => "ALTER TABLE company_bill_payment_references ADD COLUMN verified_at DATETIME NULL",
    ];

    foreach($columns as $column => $alter_sql){
        $result = mysqli_query($conn, "SHOW COLUMNS FROM company_bill_payment_references LIKE '" . mysqli_real_escape_string($conn, $column) . "'");

        if($result && mysqli_num_rows($result) === 0 && !mysqli_query($conn, $alter_sql)){
            throw new Exception('Bill payment verification could not be prepared: ' . mysqli_error($conn));
        }
    }

    // Older builds allowed only one row per company and bill date. Rejected
    // submissions must stay in history, so that unique key is replaced with a
    // normal index.
    $unique_key = mysqli_query($conn, "SHOW INDEX FROM company_bill_payment_references WHERE Key_name = 'uq_company_bill_reminder'");

    if($unique_key && mysqli_num_rows($unique_key) > 0){
        mysqli_query($conn, "ALTER TABLE company_bill_payment_references DROP INDEX uq_company_bill_reminder");
    }

    $cycle_index = mysqli_query($conn, "SHOW INDEX FROM company_bill_payment_references WHERE Key_name = 'idx_company_bill_cycle'");

    if($cycle_index && mysqli_num_rows($cycle_index) === 0){
        mysqli_query($conn, "ALTER TABLE company_bill_payment_references ADD INDEX idx_company_bill_cycle (company_id, bill_reminder_date)");
    }

    $status_column = mysqli_query($conn, "SHOW COLUMNS FROM company_bill_payment_references LIKE 'status'");
    $status_row = $status_column ? mysqli_fetch_assoc($status_column) : null;
    $status_type = strtolower((string)($status_row['Type'] ?? ''));

    // Older builds used "verified". Preserve those submitted records while
    // moving to the user-facing status name: Approved.
    if(strpos($status_type, "'verified'") !== false){
        mysqli_query($conn, "ALTER TABLE company_bill_payment_references MODIFY COLUMN status ENUM('pending','verified','approved','rejected') NOT NULL DEFAULT 'pending'");
        mysqli_query($conn, "UPDATE company_bill_payment_references SET status='approved' WHERE status='verified'");
        mysqli_query($conn, "ALTER TABLE company_bill_payment_references MODIFY COLUMN status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
    }
}

function bill_reminder_verification_token()
{
    return bin2hex(random_bytes(32));
}

function bill_reminder_verification_token_hash($token)
{
    return hash('sha256', (string)$token);
}

function bill_reminder_current_for_company($conn, $company_id)
{
    $company_id = (int)$company_id;

    if($company_id <= 0){
        return null;
    }

    bill_reminder_ensure_columns($conn);
    ensure_company_bill_payment_references_table($conn);

    $settings_stmt = mysqli_prepare(
        $conn,
        "SELECT u.id, u.name, u.bill_reminder_day, u.service_charge_amount
         FROM users u
         WHERE u.id = ?
           AND u.role = 'admin'
           AND u.bill_reminder_enabled = 1
           AND u.bill_reminder_day BETWEEN 1 AND 31
           AND u.service_charge_amount > 0
         LIMIT 1"
    );

    if(!$settings_stmt){
        return null;
    }

    mysqli_stmt_bind_param($settings_stmt, 'i', $company_id);
    mysqli_stmt_execute($settings_stmt);
    $settings_result = mysqli_stmt_get_result($settings_stmt);
    $reminder = $settings_result ? mysqli_fetch_assoc($settings_result) : null;
    mysqli_stmt_close($settings_stmt);

    if(!$reminder){
        return null;
    }

    $today = new DateTimeImmutable('today');
    $monthly_day = min((int)$reminder['bill_reminder_day'], (int)$today->format('t'));
    $cycle_date = $today->setDate((int)$today->format('Y'), (int)$today->format('m'), $monthly_day);

    if($today < $cycle_date){
        return null;
    }

    $bill_cycle_date = $cycle_date->format('Y-m-d');
    $reference_stmt = mysqli_prepare(
        $conn,
        "SELECT COALESCE(reference_no, '') AS reference_no,
                COALESCE(status, '') AS reference_status
         FROM company_bill_payment_references
         WHERE company_id = ? AND bill_reminder_date = ?
         ORDER BY (status = 'approved') DESC, id DESC
         LIMIT 1"
    );

    if($reference_stmt){
        mysqli_stmt_bind_param($reference_stmt, 'is', $company_id, $bill_cycle_date);
        mysqli_stmt_execute($reference_stmt);
        $reference_result = mysqli_stmt_get_result($reference_stmt);
        $reference = $reference_result ? mysqli_fetch_assoc($reference_result) : null;
        mysqli_stmt_close($reference_stmt);
        $reminder['reference_no'] = $reference['reference_no'] ?? '';
        $reminder['reference_status'] = $reference['reference_status'] ?? '';
    }else{
        $reminder['reference_no'] = '';
        $reminder['reference_status'] = '';
    }

    $reminder['bill_cycle_date'] = $bill_cycle_date;

    if($reminder['reference_status'] === 'approved'){
        return null;
    }

    return $reminder;
}

function bill_reminder_current_for_session($conn)
{
    $role = $_SESSION['user_role'] ?? '';

    if(!in_array($role, ['admin', 'manager'], true)){
        return null;
    }

    return bill_reminder_current_for_company($conn, (int)($_SESSION['user_id'] ?? 0));
}

function bill_reminder_qr_url($conn)
{
    return branding_file_url(
        system_setting($conn, 'bill_receive_qr_file', ''),
        ''
    );
}

function bill_reminder_safe_return_url($url)
{
    $url = trim((string)$url);

    if($url === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) || substr($url, 0, 2) === '//'){
        return app_path('dashboard.php');
    }

    return $url;
}
