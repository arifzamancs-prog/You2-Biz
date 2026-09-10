<?php

function ensure_pricing_plan_visibility_column($conn)
{
    static $checked = false;
    if ($checked || !($conn instanceof mysqli)) {
        return;
    }
    $checked = true;
    $result = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'show_pricing_plan'");
    if ($result && mysqli_num_rows($result) === 0) {
        mysqli_query($conn, "ALTER TABLE users ADD COLUMN show_pricing_plan TINYINT(1) NOT NULL DEFAULT 1");
    }
}

function company_pricing_plan_visible($conn, $company_id)
{
    ensure_pricing_plan_visibility_column($conn);
    $stmt = mysqli_prepare($conn, "SELECT show_pricing_plan FROM users WHERE id=? AND role='admin' LIMIT 1");
    if (!$stmt) {
        return true;
    }
    mysqli_stmt_bind_param($stmt, 'i', $company_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return $row ? ((int)$row['show_pricing_plan'] === 1) : true;
}
