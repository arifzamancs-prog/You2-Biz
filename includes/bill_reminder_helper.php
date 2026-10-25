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
        // First bill date counted as due. Earlier months are never billed.
        'bill_reminder_start_date' => "ALTER TABLE users ADD COLUMN bill_reminder_start_date DATE NULL",
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

// Shown on the locked payment page so a company can ask for urgent help.
const BILL_REMINDER_EMERGENCY_CONTACT = '+8801977592783';
// Days after a bill is generated during which the popup can be closed at once.
const BILL_REMINDER_GRACE_DAYS = 3;
// After the grace days the popup stays open this long on every login.
const BILL_REMINDER_COUNTDOWN_SECONDS = 300;

// Schema checks run once per session; the status is read on every page.
function bill_reminder_schema_once($conn)
{
    static $ready = false;

    if($ready || !empty($_SESSION['bill_reminder_schema_v2'])){
        return;
    }

    bill_reminder_ensure_columns($conn);
    ensure_company_bill_payment_references_table($conn);
    $ready = true;

    if(session_status() === PHP_SESSION_ACTIVE){
        $_SESSION['bill_reminder_schema_v2'] = true;
    }
}

// The bill date inside the month of $month, clamped for short months.
function bill_reminder_cycle_date(DateTimeImmutable $month, $day)
{
    $month = $month->modify('first day of this month');
    return $month->setDate((int)$month->format('Y'), (int)$month->format('m'), min((int)$day, (int)$month->format('t')));
}

// Where billing begins after Super Admin enables it or changes the bill day:
// the selected day this month if it has not passed yet, otherwise next month.
// A month that is already paid is not billed again under the new day.
function bill_reminder_resolve_start_date($conn, $company_id, $enabled, $day, $today = null)
{
    $company_id = (int)$company_id;
    $stmt = mysqli_prepare($conn, "SELECT bill_reminder_enabled, bill_reminder_day, bill_reminder_start_date FROM users WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $company_id);
    mysqli_stmt_execute($stmt);
    $current = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [];
    mysqli_stmt_close($stmt);
    $old_start = $current['bill_reminder_start_date'] ?? null;

    if(!$enabled || $day < 1 || $day > 31){
        return $old_start;
    }

    if((int)($current['bill_reminder_enabled'] ?? 0) === 1 && (int)($current['bill_reminder_day'] ?? 0) === (int)$day && $old_start){
        return $old_start;
    }

    $today = $today instanceof DateTimeImmutable ? $today->setTime(0, 0) : new DateTimeImmutable('today');
    $month_start = $today->modify('first day of this month')->format('Y-m-d');
    $month_end = $today->modify('last day of this month')->format('Y-m-d');
    $paid = mysqli_prepare($conn, "SELECT 1 FROM company_bill_payment_references WHERE company_id = ? AND status = 'approved' AND bill_reminder_date BETWEEN ? AND ? LIMIT 1");
    mysqli_stmt_bind_param($paid, 'iss', $company_id, $month_start, $month_end);
    mysqli_stmt_execute($paid);
    $paid_this_month = (bool)mysqli_fetch_row(mysqli_stmt_get_result($paid));
    mysqli_stmt_close($paid);

    $this_month_bill = bill_reminder_cycle_date($today, $day);

    if($paid_this_month || $this_month_bill < $today){
        return bill_reminder_cycle_date($today->modify('first day of next month'), $day)->format('Y-m-d');
    }

    return $this_month_bill->format('Y-m-d');
}

// Every unpaid bill of a company and how the reminder must behave:
//   notice    - closable popup (first grace days, or the bill awaits approval)
//   countdown - popup that can be closed only after the countdown
//   locked    - an older bill is still unpaid; the software is blocked
function bill_reminder_status_for_company($conn, $company_id, $today = null)
{
    $company_id = (int)$company_id;

    if($company_id <= 0){
        return null;
    }

    bill_reminder_schema_once($conn);

    $settings_stmt = mysqli_prepare(
        $conn,
        "SELECT u.id, u.name, u.bill_reminder_day, u.service_charge_amount, u.bill_reminder_start_date
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
    $company = $settings_result ? mysqli_fetch_assoc($settings_result) : null;
    mysqli_stmt_close($settings_stmt);

    if(!$company){
        return null;
    }

    $today = $today instanceof DateTimeImmutable ? $today->setTime(0, 0) : new DateTimeImmutable('today');
    $day = (int)$company['bill_reminder_day'];
    $start = !empty($company['bill_reminder_start_date']) ? new DateTimeImmutable($company['bill_reminder_start_date']) : null;

    if(!$start){
        // Companies enabled before start dates existed begin with this month's bill.
        $start = bill_reminder_cycle_date($today, $day);
        $start_text = $start->format('Y-m-d');
        $update = mysqli_prepare($conn, "UPDATE users SET bill_reminder_start_date = ? WHERE id = ? AND bill_reminder_start_date IS NULL");
        mysqli_stmt_bind_param($update, 'si', $start_text, $company_id);
        mysqli_stmt_execute($update);
        mysqli_stmt_close($update);
    }

    $cycles = [];
    $month = $start->modify('first day of this month');

    for($i = 0; $i < 36; $i++){
        $cycle = bill_reminder_cycle_date($month, $day);

        if($cycle > $today){
            break;
        }

        if($cycle >= $start){
            $cycles[] = $cycle->format('Y-m-d');
        }

        $month = $month->modify('+1 month');
    }

    if(!$cycles){
        return null;
    }

    // Latest reference per bill date; an approved one always wins.
    $references = [];
    $first = $cycles[0];
    $last = end($cycles);
    $reference_stmt = mysqli_prepare(
        $conn,
        "SELECT bill_reminder_date, COALESCE(reference_no, '') AS reference_no, COALESCE(status, '') AS status
         FROM company_bill_payment_references
         WHERE company_id = ? AND bill_reminder_date BETWEEN ? AND ?
         ORDER BY (status = 'approved') DESC, id DESC"
    );
    mysqli_stmt_bind_param($reference_stmt, 'iss', $company_id, $first, $last);
    mysqli_stmt_execute($reference_stmt);
    $reference_result = mysqli_stmt_get_result($reference_stmt);

    while($reference_result && $row = mysqli_fetch_assoc($reference_result)){
        if(!isset($references[$row['bill_reminder_date']])){
            $references[$row['bill_reminder_date']] = $row;
        }
    }

    mysqli_stmt_close($reference_stmt);

    $amount = (float)$company['service_charge_amount'];
    $unpaid = [];

    foreach($cycles as $cycle){
        $status = $references[$cycle]['status'] ?? '';

        if($status === 'approved'){
            continue;
        }

        $unpaid[] = [
            'bill_cycle_date' => $cycle,
            'amount' => $amount,
            'reference_status' => $status,
            'reference_no' => $references[$cycle]['reference_no'] ?? '',
        ];
    }

    if(!$unpaid){
        return null;
    }

    $current_cycle = $last;
    $current = end($unpaid);
    $days_since_bill = (int)(new DateTimeImmutable($current_cycle))->diff($today)->days;
    $all_pending = !array_filter($unpaid, function($bill){ return $bill['reference_status'] !== 'pending'; });

    if($unpaid[0]['bill_cycle_date'] < $current_cycle){
        $mode = 'locked';
    }elseif($current['reference_status'] === 'pending' || $days_since_bill < BILL_REMINDER_GRACE_DAYS){
        $mode = 'notice';
    }else{
        $mode = 'countdown';
    }

    return [
        'id' => (int)$company['id'],
        'name' => $company['name'],
        'mode' => $mode,
        'bills' => $unpaid,
        'total' => round($amount * count($unpaid), 2),
        'all_pending' => $all_pending,
        'days_since_bill' => $days_since_bill,
        // Single-bill fields used by the popup.
        'bill_cycle_date' => $current['bill_cycle_date'],
        'service_charge_amount' => $amount,
        'reference_status' => $current['reference_status'],
        'reference_no' => $current['reference_no'],
    ];
}

function bill_reminder_status_for_session($conn)
{
    $role = $_SESSION['user_role'] ?? '';

    if(!in_array($role, ['admin', 'manager'], true)){
        return null;
    }

    return bill_reminder_status_for_company($conn, (int)($_SESSION['user_id'] ?? 0));
}

function bill_reminder_lock_path()
{
    return app_path('billing/payment_required.php');
}

// Called before any page output: a locked company sees only the payment page.
function bill_reminder_enforce_lock($conn)
{
    if(!($conn instanceof mysqli) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'manager'], true)){
        return;
    }

    try {
        $status = bill_reminder_status_for_session($conn);
    } catch(Throwable $error) {
        error_log('Bill reminder lock: ' . $error->getMessage());
        return;
    }

    if(!$status || $status['mode'] !== 'locked'){
        return;
    }

    $target = bill_reminder_lock_path();

    if(!headers_sent()){
        header('Location: ' . $target);
    }else{
        echo '<script>location.replace(' . json_encode($target) . ');</script>';
    }

    exit;
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
