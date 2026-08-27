<?php

require_once __DIR__ . '/manager_access_helper.php';
require_once __DIR__ . '/staff_attendance_helper.php';

function complete_user_login($conn, $user, $account, $owner_id, $role)
{
    $role = trim((string)$role);

    $_SESSION['login_user_id'] = (int)$user['id'];
    $_SESSION['login_name'] = $user['name'];
    $_SESSION['login_avatar'] =
        !empty($user['avatar'])
        ? $user['avatar']
        : 'you2biz.png';

    $_SESSION['user_role'] = $role;
    $_SESSION['manager_type'] = $role === 'manager'
        ? normalize_manager_type($user['manager_type'] ?? 'manager')
        : 'admin';
    $_SESSION['access_permissions'] = $role === 'manager'
        ? normalize_manager_permissions(json_decode($user['access_permissions'] ?? '[]', true))
        : [];
    $_SESSION['permissions_configured'] = $role === 'manager'
        && array_key_exists('access_permissions', $user)
        && $user['access_permissions'] !== null;

    $_SESSION['user_id'] = (int)$owner_id;
    $_SESSION['user_name'] = $account['name'];
    $_SESSION['avatar'] =
        !empty($account['avatar'])
        ? $account['avatar']
        : 'you2biz.png';

    $login_user_id = (int)$user['id'];
    mysqli_query(
        $conn,
        "UPDATE users
         SET last_login = NOW()
         WHERE id = {$login_user_id}"
    );

    // Only staff accounts logging in from a desktop create attendance records.
    staff_attendance_record_login($conn, $login_user_id, (int)$owner_id);
}
