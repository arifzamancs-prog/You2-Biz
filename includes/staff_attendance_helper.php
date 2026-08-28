<?php

require_once __DIR__ . '/staff_helper.php';

function ensure_staff_attendance_tables($conn)
{
    ensure_staff_table($conn);

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS staff_attendance_settings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        office_start_time TIME NOT NULL DEFAULT '09:00:00',
        late_after_time TIME NOT NULL DEFAULT '09:15:00',
        absent_after_time TIME NOT NULL DEFAULT '18:00:00',
        late_days_for_salary_cut INT NOT NULL DEFAULT 3,
        salary_cut_type ENUM('none','fixed','percentage') NOT NULL DEFAULT 'none',
        salary_cut_value DECIMAL(12,2) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_attendance_settings_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $absent_time_column = mysqli_query($conn, "SHOW COLUMNS FROM staff_attendance_settings LIKE 'absent_after_time'");
    if (!$absent_time_column || mysqli_num_rows($absent_time_column) === 0) {
        mysqli_query($conn, "ALTER TABLE staff_attendance_settings ADD COLUMN absent_after_time TIME NOT NULL DEFAULT '18:00:00' AFTER late_after_time");
    }

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
        attendance_status ENUM('present','late','absent','closed_day') NOT NULL DEFAULT 'present',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_staff_attendance_day (user_id, staff_id, attendance_date),
        INDEX idx_attendance_user_date (user_id, attendance_date),
        INDEX idx_attendance_staff_date (staff_id, attendance_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $attendance_status_column = mysqli_query($conn, "SHOW COLUMNS FROM staff_attendance_logs LIKE 'attendance_status'");
    if ($attendance_status_column && ($attendance_status_info = mysqli_fetch_assoc($attendance_status_column))
        && stripos($attendance_status_info['Type'], "'absent'") === false) {
        mysqli_query($conn, "ALTER TABLE staff_attendance_logs MODIFY attendance_status ENUM('present','late','absent','closed_day') NOT NULL DEFAULT 'present'");
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
    $absent_after_time = $settings['absent_after_time'] ?? '18:00:00';
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
