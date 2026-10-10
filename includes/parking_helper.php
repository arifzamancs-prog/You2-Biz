<?php

require_once __DIR__ . '/company_settings_helper.php';

function parking_company_enabled($conn, $company_id)
{
    $company_id = (int)$company_id;
    if ($company_id <= 0) {
        return false;
    }

    $stmt = mysqli_prepare($conn, "SELECT company_type FROM users WHERE id=? AND role='admin' LIMIT 1");
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'i', $company_id);
    mysqli_stmt_execute($stmt);
    $company = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return $company && normalize_company_type($company['company_type'] ?? '') === 'Car Parking';
}

function parking_ensure_schema($conn)
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $tables = [
        "CREATE TABLE IF NOT EXISTS parking_settings (
            company_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            slot_management_enabled TINYINT(1) NOT NULL DEFAULT 0,
            payment_mode ENUM('pay_at_exit','prepaid_fixed','hybrid','free') NOT NULL DEFAULT 'pay_at_exit',
            entry_print_enabled TINYINT(1) NOT NULL DEFAULT 1,
            exit_print_enabled TINYINT(1) NOT NULL DEFAULT 0,
            barcode_format ENUM('barcode','qr','both') NOT NULL DEFAULT 'barcode',
            vehicle_number_required TINYINT(1) NOT NULL DEFAULT 0,
            barrier_integration_enabled TINYINT(1) NOT NULL DEFAULT 0,
            grace_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            lost_ticket_fee DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS parking_vehicle_types (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id BIGINT UNSIGNED NOT NULL,
            vehicle_code VARCHAR(40) NOT NULL,
            vehicle_name VARCHAR(100) NOT NULL,
            default_capacity INT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_parking_vehicle_type (company_id, vehicle_code),
            KEY idx_parking_vehicle_type_company_status (company_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS parking_rate_plans (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id BIGINT UNSIGNED NOT NULL,
            vehicle_type_id BIGINT UNSIGNED NOT NULL,
            plan_name VARCHAR(120) NOT NULL,
            rate_mode ENUM('fixed','duration','free') NOT NULL DEFAULT 'duration',
            fixed_fee DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            initial_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            initial_fee DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            extra_minutes INT UNSIGNED NOT NULL DEFAULT 60,
            extra_fee DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            daily_max_fee DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            effective_from DATETIME NULL,
            effective_to DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_parking_rate_plan_company (company_id, vehicle_type_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS parking_gates (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id BIGINT UNSIGNED NOT NULL,
            branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            gate_name VARCHAR(120) NOT NULL,
            gate_code VARCHAR(50) NOT NULL,
            gate_type ENUM('entry','exit','both') NOT NULL DEFAULT 'both',
            terminal_name VARCHAR(120) NOT NULL DEFAULT '',
            printer_name VARCHAR(180) NOT NULL DEFAULT '',
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_parking_gate_code (company_id, branch_id, gate_code),
            KEY idx_parking_gate_company_status (company_id, branch_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS parking_gate_staff_assignments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id BIGINT UNSIGNED NOT NULL,
            gate_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            access_type ENUM('entry','exit','both') NOT NULL DEFAULT 'both',
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_parking_gate_staff (company_id, gate_id, user_id),
            KEY idx_parking_gate_staff_user (company_id, user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS parking_zones (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id BIGINT UNSIGNED NOT NULL,
            branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            zone_name VARCHAR(120) NOT NULL,
            zone_code VARCHAR(50) NOT NULL,
            vehicle_type_id BIGINT UNSIGNED NULL,
            capacity INT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_parking_zone_code (company_id, branch_id, zone_code),
            KEY idx_parking_zone_company_status (company_id, branch_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS parking_slots (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id BIGINT UNSIGNED NOT NULL,
            branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            zone_id BIGINT UNSIGNED NOT NULL,
            slot_code VARCHAR(50) NOT NULL,
            vehicle_type_id BIGINT UNSIGNED NULL,
            status ENUM('available','occupied','reserved','maintenance','inactive') NOT NULL DEFAULT 'available',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_parking_slot_code (company_id, branch_id, slot_code),
            KEY idx_parking_slot_zone_status (zone_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS parking_tickets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id BIGINT UNSIGNED NOT NULL,
            branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ticket_no VARCHAR(80) NOT NULL,
            barcode_value VARCHAR(150) NOT NULL,
            vehicle_type_id BIGINT UNSIGNED NOT NULL,
            vehicle_number VARCHAR(60) NOT NULL DEFAULT '',
            entry_gate_id BIGINT UNSIGNED NULL,
            exit_gate_id BIGINT UNSIGNED NULL,
            zone_id BIGINT UNSIGNED NULL,
            slot_id BIGINT UNSIGNED NULL,
            rate_plan_id BIGINT UNSIGNED NULL,
            rate_snapshot_json LONGTEXT NULL,
            entry_operator_id BIGINT UNSIGNED NULL,
            exit_operator_id BIGINT UNSIGNED NULL,
            entry_at DATETIME NOT NULL,
            exit_at DATETIME NULL,
            duration_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            calculated_fee DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            payment_status ENUM('unpaid','partial','paid','waived') NOT NULL DEFAULT 'unpaid',
            ticket_status ENUM('active','payment_pending','paid','exited','lost','cancelled') NOT NULL DEFAULT 'active',
            entry_printed_at DATETIME NULL,
            exit_printed_at DATETIME NULL,
            notes VARCHAR(500) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_parking_ticket_no (company_id, ticket_no),
            UNIQUE KEY uniq_parking_barcode (barcode_value),
            KEY idx_parking_ticket_active (company_id, branch_id, ticket_status, entry_at),
            KEY idx_parking_ticket_vehicle (company_id, vehicle_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS parking_payments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id BIGINT UNSIGNED NOT NULL,
            branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ticket_id BIGINT UNSIGNED NOT NULL,
            wallet_id BIGINT UNSIGNED NULL,
            received_by BIGINT UNSIGNED NULL,
            payment_method ENUM('cash','card','bkash','nagad','bank','wallet','other') NOT NULL DEFAULT 'cash',
            amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            payment_reference VARCHAR(150) NOT NULL DEFAULT '',
            payment_note VARCHAR(500) NOT NULL DEFAULT '',
            paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_parking_payment_ticket (ticket_id),
            KEY idx_parking_payment_company_date (company_id, branch_id, paid_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS parking_gate_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id BIGINT UNSIGNED NOT NULL,
            branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            gate_id BIGINT UNSIGNED NULL,
            ticket_id BIGINT UNSIGNED NULL,
            operator_id BIGINT UNSIGNED NULL,
            event_type ENUM('entry_created','barcode_scanned','exit_confirmed','print','barrier_opened','override') NOT NULL,
            event_message VARCHAR(500) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_parking_gate_log_company_date (company_id, branch_id, created_at),
            KEY idx_parking_gate_log_ticket (ticket_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS parking_shifts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id BIGINT UNSIGNED NOT NULL,
            branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            gate_id BIGINT UNSIGNED NOT NULL,
            operator_id BIGINT UNSIGNED NOT NULL,
            opening_cash DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            expected_cash DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            closing_cash DECIMAL(15,2) NULL,
            cash_difference DECIMAL(15,2) NULL,
            status ENUM('open','closed') NOT NULL DEFAULT 'open',
            opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME NULL,
            note VARCHAR(500) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_parking_shift_operator (company_id, operator_id, status),
            KEY idx_parking_shift_gate (company_id, gate_id, opened_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($tables as $sql) {
        mysqli_query($conn, $sql);
    }

    $lost_fee_column = mysqli_query($conn, "SHOW COLUMNS FROM parking_settings LIKE 'lost_ticket_fee'");
    if ($lost_fee_column && mysqli_num_rows($lost_fee_column) === 0) {
        mysqli_query($conn, 'ALTER TABLE parking_settings ADD COLUMN lost_ticket_fee DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER grace_minutes');
    }

    $ready = true;
}

function parking_ensure_company_defaults($conn, $company_id)
{
    $company_id = (int)$company_id;
    if ($company_id <= 0 || !parking_company_enabled($conn, $company_id)) {
        return;
    }

    parking_ensure_schema($conn);

    $setting_stmt = mysqli_prepare($conn, 'INSERT IGNORE INTO parking_settings (company_id) VALUES (?)');
    if ($setting_stmt) {
        mysqli_stmt_bind_param($setting_stmt, 'i', $company_id);
        mysqli_stmt_execute($setting_stmt);
        mysqli_stmt_close($setting_stmt);
    }
}

function parking_new_ticket_code()
{
    return 'PK-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

function parking_code39_svg($value, $height = 58)
{
    $value = strtoupper(trim((string)$value));
    $patterns = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww',
        'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '$' => 'nwnwnwnnn',
        '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn', '*' => 'nwnnwnwnn',
    ];

    if ($value === '' || preg_match('/[^0-9A-Z .\-\$\/\+%]/', $value)) {
        return '';
    }

    $encoded = '*' . $value . '*';
    $narrow = 2;
    $wide = 6;
    $gap = 2;
    $quiet = 12;
    $width = $quiet * 2;
    foreach (str_split($encoded) as $character) {
        foreach (str_split($patterns[$character]) as $bar) {
            $width += $bar === 'w' ? $wide : $narrow;
        }
        $width += $gap;
    }

    $x = $quiet;
    $bars = '';
    foreach (str_split($encoded) as $character) {
        foreach (str_split($patterns[$character]) as $index => $bar) {
            $bar_width = $bar === 'w' ? $wide : $narrow;
            if ($index % 2 === 0) {
                $bars .= '<rect x="' . $x . '" y="0" width="' . $bar_width . '" height="' . (int)$height . '"/>';
            }
            $x += $bar_width;
        }
        $x += $gap;
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Barcode ' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" viewBox="0 0 ' . $width . ' ' . (int)$height . '" preserveAspectRatio="none"><rect width="100%" height="100%" fill="#fff"/>' . $bars . '</svg>';
}

function parking_calculate_ticket_fee(array $ticket, $grace_minutes = 0, $at_time = null)
{
    $entry_timestamp = strtotime((string)($ticket['entry_at'] ?? ''));
    $exit_timestamp = $at_time === null ? time() : (is_int($at_time) ? $at_time : strtotime((string)$at_time));
    if (!$entry_timestamp || !$exit_timestamp || $exit_timestamp < $entry_timestamp) {
        return ['duration_minutes' => 0, 'billable_minutes' => 0, 'fee' => 0.00];
    }

    $duration = (int)ceil(($exit_timestamp - $entry_timestamp) / 60);
    $grace_minutes = max(0, (int)$grace_minutes);
    $billable_minutes = max(0, $duration - $grace_minutes);
    $snapshot = json_decode((string)($ticket['rate_snapshot_json'] ?? ''), true);
    $snapshot = is_array($snapshot) ? $snapshot : [];
    $mode = (string)($snapshot['rate_mode'] ?? 'duration');
    $fee = 0.00;

    if ($mode === 'fixed') {
        $fee = (float)($snapshot['fixed_fee'] ?? 0);
    } elseif ($mode === 'duration' && $billable_minutes > 0) {
        $initial_minutes = max(0, (int)($snapshot['initial_minutes'] ?? 0));
        $initial_fee = max(0, (float)($snapshot['initial_fee'] ?? 0));
        $extra_minutes = max(1, (int)($snapshot['extra_minutes'] ?? 60));
        $extra_fee = max(0, (float)($snapshot['extra_fee'] ?? 0));
        if ($billable_minutes <= $initial_minutes) {
            $fee = $initial_fee;
        } else {
            $extra_blocks = (int)ceil(($billable_minutes - $initial_minutes) / $extra_minutes);
            $fee = $initial_fee + ($extra_blocks * $extra_fee);
        }
    }

    $daily_max = max(0, (float)($snapshot['daily_max_fee'] ?? 0));
    if ($daily_max > 0 && $fee > $daily_max) {
        $fee = $daily_max;
    }

    return [
        'duration_minutes' => $duration,
        'billable_minutes' => $billable_minutes,
        'fee' => round(max(0, $fee), 2),
    ];
}

function parking_operator_can_use_gate($conn, $company_id, $gate_id, $operation)
{
    if (!function_exists('is_manager_user') || !is_manager_user()) {
        return true;
    }

    $operation = $operation === 'exit' ? 'exit' : 'entry';
    $operator_id = (int)($_SESSION['login_user_id'] ?? 0);
    if ($operator_id <= 0 || (int)$gate_id <= 0) {
        return false;
    }
    $stmt = mysqli_prepare($conn, "SELECT id FROM parking_gate_staff_assignments WHERE company_id=? AND gate_id=? AND user_id=? AND status='active' AND access_type IN (?, 'both') LIMIT 1");
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'iiis', $company_id, $gate_id, $operator_id, $operation);
    mysqli_stmt_execute($stmt);
    $allowed = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) !== null;
    mysqli_stmt_close($stmt);
    return $allowed;
}

function parking_operator_gate_filter($conn, $company_id, $operation, $column = 'id')
{
    if (!function_exists('is_manager_user') || !is_manager_user()) {
        return '';
    }

    $operation = in_array($operation, ['entry', 'exit', 'both'], true) ? $operation : 'entry';
    $operator_id = (int)($_SESSION['login_user_id'] ?? 0);
    $sql = "SELECT gate_id FROM parking_gate_staff_assignments WHERE company_id=? AND user_id=? AND status='active'";
    if ($operation !== 'both') {
        $sql .= " AND access_type IN (?, 'both')";
    }
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ' AND 1=0';
    }
    if ($operation === 'both') {
        mysqli_stmt_bind_param($stmt, 'ii', $company_id, $operator_id);
    } else {
        mysqli_stmt_bind_param($stmt, 'iis', $company_id, $operator_id, $operation);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $ids = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $ids[] = (int)$row['gate_id'];
    }
    mysqli_stmt_close($stmt);
    if (!$ids) {
        return ' AND 1=0';
    }
    return ' AND ' . $column . ' IN (' . implode(',', array_unique($ids)) . ')';
}
