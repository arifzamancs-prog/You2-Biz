<?php

function ensure_branches_table($conn)
{
    static $checked = false;

    if ($checked || !($conn instanceof mysqli)) {
        return;
    }

    $checked = true;

    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS branches (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            branch_name VARCHAR(150) NOT NULL,
            address VARCHAR(255) NULL,
            phone VARCHAR(50) NULL,
            is_head_office TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_branch_name_per_company (user_id, branch_name),
            KEY idx_branches_user_status (user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ensure_head_office_branch($conn, $user_id)
{
    ensure_branches_table($conn);
    $user_id = (int)$user_id;

    if ($user_id <= 0) {
        return;
    }

    $profile_stmt = mysqli_prepare($conn, 'SELECT address, phone FROM users WHERE id=? LIMIT 1');
    $profile = ['address' => '', 'phone' => ''];
    if ($profile_stmt) {
        mysqli_stmt_bind_param($profile_stmt, 'i', $user_id);
        mysqli_stmt_execute($profile_stmt);
        $profile = mysqli_fetch_assoc(mysqli_stmt_get_result($profile_stmt)) ?: $profile;
    }

    $check = mysqli_prepare($conn, 'SELECT id, address, phone FROM branches WHERE user_id=? AND is_head_office=1 LIMIT 1');
    if (!$check) {
        return;
    }

    mysqli_stmt_bind_param($check, 'i', $user_id);
    mysqli_stmt_execute($check);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($check));

    if (!$existing) {
        $name = 'Head Office';
        $insert = mysqli_prepare(
            $conn,
            "INSERT INTO branches (user_id, branch_name, address, phone, is_head_office, status)
             VALUES (?, ?, ?, ?, 1, 'active')"
        );

        if ($insert) {
            $address = trim((string)($profile['address'] ?? ''));
            $phone = trim((string)($profile['phone'] ?? ''));
            mysqli_stmt_bind_param($insert, 'isss', $user_id, $name, $address, $phone);
            mysqli_stmt_execute($insert);
        }

        mysqli_stmt_execute($check);
        $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
    } else {
        // One-time migration for companies that already had a profile before branches existed.
        // Later changes are kept in sync explicitly from either edit screen.
        $address = trim((string)($profile['address'] ?? ''));
        $phone = trim((string)($profile['phone'] ?? ''));
        $branch_address = trim((string)($existing['address'] ?? ''));
        $branch_phone = trim((string)($existing['phone'] ?? ''));
        if (($branch_address === '' && $address !== '') || ($branch_phone === '' && $phone !== '')) {
            $new_address = $branch_address === '' ? $address : $branch_address;
            $new_phone = $branch_phone === '' ? $phone : $branch_phone;
            $sync = mysqli_prepare($conn, 'UPDATE branches SET address=?, phone=? WHERE id=? AND user_id=?');
            if ($sync) {
                $head_office_id = (int)$existing['id'];
                mysqli_stmt_bind_param($sync, 'ssii', $new_address, $new_phone, $head_office_id, $user_id);
                mysqli_stmt_execute($sync);
            }
        }
    }

    $head_office_id = (int)($existing['id'] ?? 0);
    $staff_branch_column = mysqli_query($conn, "SHOW COLUMNS FROM staff LIKE 'branch_id'");
    if ($head_office_id > 0 && $staff_branch_column && mysqli_num_rows($staff_branch_column) > 0) {
        $assign_staff = mysqli_prepare(
            $conn,
            'UPDATE staff SET branch_id=? WHERE user_id=? AND (branch_id IS NULL OR branch_id=0)'
        );
        if ($assign_staff) {
            mysqli_stmt_bind_param($assign_staff, 'ii', $head_office_id, $user_id);
            mysqli_stmt_execute($assign_staff);
        }
    }
}
