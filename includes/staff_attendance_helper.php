<?php

require_once __DIR__ . '/staff_helper.php';

function ensure_staff_attendance_tables($conn)
{
    ensure_staff_table($conn);

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS staff_attendance_settings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        office_start_time TIME NOT NULL DEFAULT '10:00:00',
        late_after_time TIME NOT NULL DEFAULT '10:15:00',
        absent_after_time TIME NOT NULL DEFAULT '12:00:00',
        late_days_for_salary_cut INT NOT NULL DEFAULT 3,
        salary_cut_type ENUM('none','fixed','percentage') NOT NULL DEFAULT 'none',
        salary_cut_value DECIMAL(12,2) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_attendance_settings_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $absent_time_column = mysqli_query($conn, "SHOW COLUMNS FROM staff_attendance_settings LIKE 'absent_after_time'");
    if (!$absent_time_column || mysqli_num_rows($absent_time_column) === 0) {
        mysqli_query($conn, "ALTER TABLE staff_attendance_settings ADD COLUMN absent_after_time TIME NOT NULL DEFAULT '12:00:00' AFTER late_after_time");
    }

    // Upgrade only the former application defaults; custom attendance times stay unchanged.
    mysqli_query($conn, "UPDATE staff_attendance_settings
        SET office_start_time='10:00:00', late_after_time='10:15:00', absent_after_time='12:00:00'
        WHERE office_start_time='09:00:00' AND late_after_time='09:15:00' AND absent_after_time='18:00:00'");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS staff_office_closed_days (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        closed_date DATE NOT NULL,
        title VARCHAR(150) NOT NULL DEFAULT 'Office Closed',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_office_closed_day (user_id, closed_date),
        INDEX idx_closed_day_user_date (user_id, closed_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS staff_attendance_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        staff_id BIGINT UNSIGNED NOT NULL,
        login_user_id BIGINT UNSIGNED NOT NULL,
        attendance_date DATE NOT NULL,
        login_at DATETIME NOT NULL,
        login_ip VARCHAR(45) NULL,
        login_device ENUM('desktop','mobile') NOT NULL DEFAULT 'desktop',
        attendance_status ENUM('present','late','absent','closed_day','casual_leave','medical_leave') NOT NULL DEFAULT 'present',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_staff_attendance_day (user_id, staff_id, attendance_date),
        INDEX idx_attendance_user_date (user_id, attendance_date),
        INDEX idx_attendance_staff_date (staff_id, attendance_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $attendance_status_column = mysqli_query($conn, "SHOW COLUMNS FROM staff_attendance_logs LIKE 'attendance_status'");
    if ($attendance_status_column && ($attendance_status_info = mysqli_fetch_assoc($attendance_status_column))
        && (stripos($attendance_status_info['Type'], "'absent'") === false || stripos($attendance_status_info['Type'], "'casual_leave'") === false)) {
        mysqli_query($conn, "ALTER TABLE staff_attendance_logs MODIFY attendance_status ENUM('present','late','absent','closed_day','casual_leave','medical_leave') NOT NULL DEFAULT 'present'");
    }

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS staff_monthly_salaries (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        staff_id BIGINT UNSIGNED NOT NULL,
        salary_year SMALLINT UNSIGNED NOT NULL,
        salary_month TINYINT UNSIGNED NOT NULL,
        assigned_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
        late_days INT NOT NULL DEFAULT 0,
        absent_days INT NOT NULL DEFAULT 0,
        casual_leave_days INT NOT NULL DEFAULT 0,
        medical_leave_days INT NOT NULL DEFAULT 0,
        salary_cut_days INT NOT NULL DEFAULT 0,
        salary_cut_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        generated_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
        payment_status ENUM('pending','paid') NOT NULL DEFAULT 'pending',
        paid_ledger_id BIGINT UNSIGNED NULL,
        paid_wallet_id BIGINT UNSIGNED NULL,
        paid_at DATETIME NULL,
        paid_by BIGINT UNSIGNED NULL,
        generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_staff_monthly_salary (user_id, staff_id, salary_year, salary_month),
        INDEX idx_salary_user_month (user_id, salary_year, salary_month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $salary_payment_columns = [
        'payment_status' => "ALTER TABLE staff_monthly_salaries ADD COLUMN payment_status ENUM('pending','paid') NOT NULL DEFAULT 'pending' AFTER generated_salary",
        'paid_ledger_id' => "ALTER TABLE staff_monthly_salaries ADD COLUMN paid_ledger_id BIGINT UNSIGNED NULL AFTER payment_status",
        'paid_wallet_id' => "ALTER TABLE staff_monthly_salaries ADD COLUMN paid_wallet_id BIGINT UNSIGNED NULL AFTER paid_ledger_id",
        'paid_at' => "ALTER TABLE staff_monthly_salaries ADD COLUMN paid_at DATETIME NULL AFTER paid_wallet_id",
        'paid_by' => "ALTER TABLE staff_monthly_salaries ADD COLUMN paid_by BIGINT UNSIGNED NULL AFTER paid_at",
    ];
    foreach ($salary_payment_columns as $column => $alter_sql) {
        $column_result = mysqli_query($conn, "SHOW COLUMNS FROM staff_monthly_salaries LIKE '" . $column . "'");
        if (!$column_result || mysqli_num_rows($column_result) === 0) {
            mysqli_query($conn, $alter_sql);
        }
    }

    mysqli_query($conn, "INSERT IGNORE INTO staff_attendance_settings (user_id) SELECT id FROM users WHERE role='admin'");
}

function staff_attendance_settings($conn, $user_id)
{
    ensure_staff_attendance_tables($conn);
    $stmt = mysqli_prepare($conn, 'SELECT * FROM staff_attendance_settings WHERE user_id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $settings = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if(!$settings){
        mysqli_query($conn, 'INSERT IGNORE INTO staff_attendance_settings (user_id) VALUES (' . (int)$user_id . ')');
        return staff_attendance_settings($conn, $user_id);
    }

    return $settings;
}

function staff_attendance_is_mobile_request()
{
    $agent = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return (bool)preg_match('/android|iphone|ipad|ipod|mobile|opera mini|iemobile|blackberry/', $agent);
}

function staff_attendance_client_ip()
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return substr($ip, 0, 45);
}

function staff_attendance_record_login($conn, $login_user_id, $company_user_id)
{
    $login_user_id = (int)$login_user_id;
    $company_user_id = (int)$company_user_id;

    if($login_user_id <= 0 || $company_user_id <= 0 || staff_attendance_is_mobile_request()){
        return;
    }

    ensure_staff_attendance_tables($conn);
    $account_stmt = mysqli_prepare($conn, "SELECT u.staff_id
        FROM users u
        INNER JOIN staff s ON s.id=u.staff_id AND s.user_id=u.owner_id AND s.status='active'
        WHERE u.id=? AND u.owner_id=? AND u.role='manager'
        LIMIT 1");
    mysqli_stmt_bind_param($account_stmt, 'ii', $login_user_id, $company_user_id);
    mysqli_stmt_execute($account_stmt);
    $account = mysqli_fetch_assoc(mysqli_stmt_get_result($account_stmt));
    $staff_id = (int)($account['staff_id'] ?? 0);

    if($staff_id <= 0){
        return;
    }

    $settings = staff_attendance_settings($conn, $company_user_id);
    $today = date('Y-m-d');
    $closed_stmt = mysqli_prepare($conn, 'SELECT id FROM staff_office_closed_days WHERE user_id=? AND closed_date=? LIMIT 1');
    mysqli_stmt_bind_param($closed_stmt, 'is', $company_user_id, $today);
    mysqli_stmt_execute($closed_stmt);
    $is_closed = mysqli_num_rows(mysqli_stmt_get_result($closed_stmt)) > 0;

    $now_time = date('H:i:s');
    $absent_after_time = $settings['absent_after_time'] ?? '12:00:00';
    $status = $is_closed ? 'closed_day' : ($now_time > $absent_after_time ? 'absent' : ($now_time > $settings['late_after_time'] ? 'late' : 'present'));
    $ip = staff_attendance_client_ip();
    $device = 'desktop';

    $stmt = mysqli_prepare($conn, "INSERT INTO staff_attendance_logs
        (user_id, staff_id, login_user_id, attendance_date, login_at, login_ip, login_device, attendance_status)
        VALUES (?, ?, ?, ?, NOW(), ?, ?, ?)
        ON DUPLICATE KEY UPDATE login_user_id=VALUES(login_user_id), login_at=VALUES(login_at), login_ip=VALUES(login_ip), login_device=VALUES(login_device), attendance_status=VALUES(attendance_status)");
    mysqli_stmt_bind_param($stmt, 'iiissss', $company_user_id, $staff_id, $login_user_id, $today, $ip, $device, $status);
    mysqli_stmt_execute($stmt);
}

function staff_attendance_monthly_salary_rows($conn, $user_id, $year, $month, $active_only = true)
{
    ensure_staff_attendance_tables($conn);
    $user_id = (int)$user_id;
    $year = (int)$year;
    $month = (int)$month;
    $month_start = sprintf('%04d-%02d-01', $year, $month);
    $month_end = date('Y-m-t', strtotime($month_start));
    $today = date('Y-m-d');
    $period_end = $month_start > $today ? null : min($month_end, $today);
    $settings = staff_attendance_settings($conn, $user_id);
    $days_in_month = (int)date('t', strtotime($month_start));

    $closed_days = [];
    $closed_stmt = mysqli_prepare($conn, 'SELECT closed_date FROM staff_office_closed_days WHERE user_id=? AND closed_date BETWEEN ? AND ?');
    mysqli_stmt_bind_param($closed_stmt, 'iss', $user_id, $month_start, $month_end);
    mysqli_stmt_execute($closed_stmt);
    $closed_result = mysqli_stmt_get_result($closed_stmt);
    while ($closed = mysqli_fetch_assoc($closed_result)) {
        $closed_days[$closed['closed_date']] = true;
    }

    $attendance = [];
    $attendance_stmt = mysqli_prepare($conn, 'SELECT staff_id, attendance_date, attendance_status FROM staff_attendance_logs WHERE user_id=? AND attendance_date BETWEEN ? AND ?');
    mysqli_stmt_bind_param($attendance_stmt, 'iss', $user_id, $month_start, $month_end);
    mysqli_stmt_execute($attendance_stmt);
    $attendance_result = mysqli_stmt_get_result($attendance_stmt);
    while ($log = mysqli_fetch_assoc($attendance_result)) {
        $attendance[(int)$log['staff_id']][$log['attendance_date']] = $log['attendance_status'];
    }

    $staff_sql = "SELECT id, name, staff_code, salary, created_at FROM staff WHERE user_id=?" . ($active_only ? " AND status='active'" : '') . ' ORDER BY name ASC';
    $staff_stmt = mysqli_prepare($conn, $staff_sql);
    mysqli_stmt_bind_param($staff_stmt, 'i', $user_id);
    mysqli_stmt_execute($staff_stmt);
    $staff_result = mysqli_stmt_get_result($staff_stmt);
    $rows = [];

    while ($staff = mysqli_fetch_assoc($staff_result)) {
        $staff_id = (int)$staff['id'];
        $late_days = 0; $absent_days = 0; $casual_leave_days = 0; $medical_leave_days = 0;
        $staff_start = max($month_start, date('Y-m-d', strtotime($staff['created_at'])));
        if ($period_end && $staff_start <= $period_end) {
            $cursor = new DateTime($staff_start);
            $end = new DateTime($period_end);
            while ($cursor <= $end) {
                $day = $cursor->format('Y-m-d');
                if (empty($closed_days[$day])) {
                    $status = $attendance[$staff_id][$day] ?? null;
                    if ($status === 'late') $late_days++;
                    elseif ($status === 'absent') $absent_days++;
                    elseif ($status === 'casual_leave') $casual_leave_days++;
                    elseif ($status === 'medical_leave') $medical_leave_days++;
                }
                $cursor->modify('+1 day');
            }
        }
        $late_cut_days = intdiv($late_days, max(1, (int)$settings['late_days_for_salary_cut']));
        $salary_cut_days = $late_cut_days + $absent_days;
        $assigned_salary = (float)$staff['salary'];
        $cut_amount = min($assigned_salary, round(($assigned_salary / $days_in_month) * $salary_cut_days, 2));
        $rows[] = [
            'staff_id' => $staff_id, 'name' => $staff['name'], 'staff_code' => $staff['staff_code'],
            'salary' => $assigned_salary, 'late_days' => $late_days, 'absent_days' => $absent_days,
            'casual_leave_days' => $casual_leave_days, 'medical_leave_days' => $medical_leave_days,
            'cut_days' => $salary_cut_days, 'cut_amount' => $cut_amount,
            'generated_salary' => max(0, round($assigned_salary - $cut_amount, 2)),
        ];
    }
    return $rows;
}

function staff_attendance_generate_monthly_salaries($conn, $user_id)
{
    if ((int)date('j') !== 1) return 0;
    $period = new DateTime('first day of last month');
    $year = (int)$period->format('Y');
    $month = (int)$period->format('n');
    $rows = staff_attendance_monthly_salary_rows($conn, $user_id, $year, $month, false);
    $created = 0;
    $stmt = mysqli_prepare($conn, 'INSERT IGNORE INTO staff_monthly_salaries (user_id, staff_id, salary_year, salary_month, assigned_salary, late_days, absent_days, casual_leave_days, medical_leave_days, salary_cut_days, salary_cut_amount, generated_salary) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($rows as $row) {
        mysqli_stmt_bind_param($stmt, 'iiiidiiiiidd', $user_id, $row['staff_id'], $year, $month, $row['salary'], $row['late_days'], $row['absent_days'], $row['casual_leave_days'], $row['medical_leave_days'], $row['cut_days'], $row['cut_amount'], $row['generated_salary']);
        mysqli_stmt_execute($stmt);
        $created += mysqli_stmt_affected_rows($stmt) > 0 ? 1 : 0;
    }
    return $created;
}
