<?php

function ensure_multi_branch_column($conn)
{
    static $checked = false;
    if ($checked) return;
    $result = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'multi_branch_enabled'");
    if (mysqli_num_rows($result) === 0) {
        mysqli_query($conn, 'ALTER TABLE users ADD COLUMN multi_branch_enabled TINYINT(1) NOT NULL DEFAULT 0');
    }
    $checked = true;
}

function company_multi_branch_enabled($conn, $company_id)
{
    ensure_multi_branch_column($conn);
    $stmt = mysqli_prepare($conn, "SELECT multi_branch_enabled FROM users WHERE id=? AND role='admin' LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $company_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return (int)($row['multi_branch_enabled'] ?? 0) === 1;
}

function require_company_multi_branch($conn, $company_id)
{
    if (!company_multi_branch_enabled($conn, $company_id)) {
        http_response_code(403);
        exit('Multi Branch is inactive for this company.');
    }
}
