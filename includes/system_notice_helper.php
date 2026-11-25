<?php
/*
 * System notices from the Super Admin.
 *   login: shown on the login page to everyone who opens it.
 *   admin: shown to each company admin once, on the dashboard after logging in.
 * A notice may have a date range (From / To, either optional): it shows only on those days.
 * Where ranges overlap, one notice of each kind is visible at a time.
 */

function system_notice_audiences()
{
    return [
        'login' => 'Login Page',
        'admin' => 'Admin Login',
    ];
}

function ensure_system_notice_table($conn)
{
    static $ready = false;
    if ($ready) return true;
    $ready = (bool)mysqli_query($conn, "CREATE TABLE IF NOT EXISTS system_notices (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        audience ENUM('login','admin') NOT NULL,
        title VARCHAR(180) NOT NULL,
        message TEXT NOT NULL,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        show_from DATE NULL,
        show_to DATE NULL,
        published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_system_notices_audience (audience, status, published_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $range = $ready ? mysqli_query($conn, "SHOW COLUMNS FROM system_notices LIKE 'show_from'") : false;
    if ($range && mysqli_num_rows($range) === 0) {
        mysqli_query($conn, 'ALTER TABLE system_notices ADD COLUMN show_from DATE NULL AFTER status, ADD COLUMN show_to DATE NULL AFTER show_from');
    }
    return $ready;
}

// Where a notice stands today: visible, scheduled (starts later), expired or hidden.
function system_notice_state($notice, $today = null)
{
    $today = $today ?? date('Y-m-d');
    if (($notice['status'] ?? '') !== 'active') return 'hidden';
    if (!empty($notice['show_from']) && $notice['show_from'] > $today) return 'scheduled';
    if (!empty($notice['show_to']) && $notice['show_to'] < $today) return 'expired';
    return 'visible';
}

// The notice of one kind that shows today, or null.
function system_notice_active($conn, $audience)
{
    if (!($conn instanceof mysqli) || !isset(system_notice_audiences()[$audience]) || !ensure_system_notice_table($conn)) return null;
    $stmt = mysqli_prepare($conn, "SELECT id, title, message, published_at FROM system_notices
        WHERE audience=? AND status='active'
        AND (show_from IS NULL OR show_from <= ?)
        AND (show_to IS NULL OR show_to >= ?)
        ORDER BY published_at DESC, id DESC LIMIT 1");
    if (!$stmt) return null;
    // Today in the app's time zone (MySQL's CURDATE() may run on another zone).
    $today = date('Y-m-d');
    mysqli_stmt_bind_param($stmt, 'sss', $audience, $today, $today);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

// Records that a company has seen a notice. True only the first time, so the
// notice pops up once per company and is not shown again on later logins.
function system_notice_mark_seen($conn, $notice_id, $company_id)
{
    if ($notice_id <= 0 || $company_id <= 0) return false;
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS system_notice_views (
        notice_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (notice_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $stmt = mysqli_prepare($conn, 'INSERT IGNORE INTO system_notice_views (notice_id, user_id) VALUES (?, ?)');
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 'ii', $notice_id, $company_id);
    mysqli_stmt_execute($stmt);
    $first = mysqli_stmt_affected_rows($stmt) === 1;
    mysqli_stmt_close($stmt);
    return $first;
}
