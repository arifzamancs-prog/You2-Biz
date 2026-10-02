<?php

require_once __DIR__ . '/branch_helper.php';

function ensure_branch_accounting_columns($conn, $company_id)
{
    static $done = [];
    $company_id = (int)$company_id;
    if ($company_id <= 0 || isset($done[$company_id])) return;
    $done[$company_id] = true;

    ensure_head_office_branch($conn, $company_id);

    // An older live database may reach the navbar before any Staff page runs.
    // Provision branch ownership here as part of the common bootstrap as well.
    $staff_table = mysqli_query($conn, "SHOW TABLES LIKE 'staff'");
    if ($staff_table && mysqli_num_rows($staff_table) > 0) {
        $staff_branch_column = mysqli_query($conn, "SHOW COLUMNS FROM staff LIKE 'branch_id'");
        if ($staff_branch_column && mysqli_num_rows($staff_branch_column) === 0) {
            mysqli_query($conn, 'ALTER TABLE staff ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER designation');
        }
        $staff_branch_index = mysqli_query($conn, "SHOW INDEX FROM staff WHERE Key_name='idx_staff_user_branch'");
        if ($staff_branch_index && mysqli_num_rows($staff_branch_index) === 0) {
            mysqli_query($conn, 'ALTER TABLE staff ADD INDEX idx_staff_user_branch (user_id, branch_id)');
        }
    }

    $head_stmt = mysqli_prepare($conn, 'SELECT id FROM branches WHERE user_id=? AND is_head_office=1 LIMIT 1');
    mysqli_stmt_bind_param($head_stmt, 'i', $company_id);
    mysqli_stmt_execute($head_stmt);
    $head_id = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($head_stmt))['id'] ?? 0);

    if ($head_id > 0 && $staff_table && mysqli_num_rows($staff_table) > 0) {
        mysqli_query($conn, "UPDATE staff SET branch_id={$head_id} WHERE user_id={$company_id} AND (branch_id IS NULL OR branch_id=0)");
    }

    $tables = ['wallets', 'invoices', 'booking_invoices', 'expenses', 'money_ins', 'transfers', 'transactions', 'customer_payments', 'staff_ledger_entries', 'purchases', 'supplier_payments'];
    foreach ($tables as $table) {
        $exists = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
        if (!$exists || mysqli_num_rows($exists) === 0) continue;
        $column = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE 'branch_id'");
        if ($column && mysqli_num_rows($column) === 0) {
            mysqli_query($conn, "ALTER TABLE `{$table}` ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER user_id");
            mysqli_query($conn, "ALTER TABLE `{$table}` ADD INDEX `idx_{$table}_user_branch` (user_id, branch_id)");
        }
        if ($head_id > 0) {
            if ($table === 'wallets') {
                // Legacy databases can already contain both an unassigned Cash
                // wallet and a Head Office Cash wallet. Moving both to the same
                // branch would violate unique_user_branch_wallet.
                mysqli_query(
                    $conn,
                    "UPDATE wallets legacy
                     LEFT JOIN wallets assigned
                       ON assigned.user_id=legacy.user_id
                      AND assigned.branch_id={$head_id}
                      AND assigned.wallet_name=legacy.wallet_name
                      AND assigned.id<>legacy.id
                     SET legacy.branch_id={$head_id}
                     WHERE legacy.user_id={$company_id}
                       AND (legacy.branch_id IS NULL OR legacy.branch_id=0)
                       AND assigned.id IS NULL"
                );
            } else {
                mysqli_query($conn, "UPDATE `{$table}` SET branch_id={$head_id} WHERE user_id={$company_id} AND (branch_id IS NULL OR branch_id=0)");
            }
        }
    }

    $wallet_index = mysqli_query($conn, "SHOW INDEX FROM wallets WHERE Key_name='unique_user_wallet'");
    if ($wallet_index && mysqli_num_rows($wallet_index) > 0) {
        mysqli_query($conn, 'ALTER TABLE wallets DROP INDEX unique_user_wallet');
    }
    $wallet_branch_index = mysqli_query($conn, "SHOW INDEX FROM wallets WHERE Key_name='unique_user_branch_wallet'");
    if ($wallet_branch_index && mysqli_num_rows($wallet_branch_index) === 0) {
        mysqli_query($conn, 'ALTER TABLE wallets ADD UNIQUE INDEX unique_user_branch_wallet (user_id, branch_id, wallet_name)');
    }

    // Every branch owns its own system Cash wallet. This also provisions wallets
    // for branches created before branch-wise accounting was introduced.
    mysqli_query(
        $conn,
        "INSERT IGNORE INTO wallets (user_id, branch_id, wallet_name, description, balance, status, is_system)
         SELECT b.user_id, b.id, 'Cash', CONCAT(b.branch_name, ' cash wallet'), 0, 'active', 1
         FROM branches b
         WHERE b.user_id={$company_id} AND b.status='active'"
    );
}

function manager_branch_id($conn, $company_id)
{
    $staff_id = current_manager_staff_id($conn);
    if ($staff_id <= 0) return 0;
    $stmt = mysqli_prepare($conn, 'SELECT branch_id FROM staff WHERE id=? AND user_id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $staff_id, $company_id);
    mysqli_stmt_execute($stmt);
    return (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['branch_id'] ?? 0);
}

function manager_is_head_office($conn, $company_id)
{
    if (!is_manager_user()) return false;
    $staff_id = current_manager_staff_id($conn);
    if ($staff_id <= 0) return false;
    $stmt = mysqli_prepare(
        $conn,
        'SELECT b.is_head_office FROM staff s INNER JOIN branches b ON b.id=s.branch_id AND b.user_id=s.user_id WHERE s.id=? AND s.user_id=? LIMIT 1'
    );
    mysqli_stmt_bind_param($stmt, 'ii', $staff_id, $company_id);
    mysqli_stmt_execute($stmt);
    return (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['is_head_office'] ?? 0) === 1;
}

function manager_can_view_all_branches($conn, $company_id)
{
    if (!is_manager_user() || !manager_has_permission('all_branches')) return false;
    return manager_is_head_office($conn, $company_id);
}

function selected_branch_id($conn, $for_write = false)
{
    $company_id = (int)($_SESSION['user_id'] ?? 0);
    if ($company_id <= 0 || is_super_admin_user()) return 0;
    ensure_branch_accounting_columns($conn, $company_id);

    if (!company_multi_branch_enabled($conn, $company_id)) {
        $stmt = mysqli_prepare($conn, 'SELECT id FROM branches WHERE user_id=? AND is_head_office=1 LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'i', $company_id);
        mysqli_stmt_execute($stmt);
        $head_office_id = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['id'] ?? 0);
        $_SESSION['selected_branch_id'] = $head_office_id;
        return $head_office_id;
    }

    if (is_manager_user()) {
        $manager_branch = manager_branch_id($conn, $company_id);

        // Under Multi Branch, branch staff are always local. Head Office staff
        // are global; All Branches only controls use of the navbar selector.
        if (!manager_is_head_office($conn, $company_id)) {
            unset($_SESSION['selected_branch_id']);
            return $manager_branch;
        }

        if (!manager_can_view_all_branches($conn, $company_id)) {
            unset($_SESSION['selected_branch_id']);
            return $for_write ? $manager_branch : 0;
        }
    }

    $default_branch = 0;
    if (!is_manager_user() && !array_key_exists('selected_branch_id', $_SESSION)) {
        $stmt = mysqli_prepare($conn, 'SELECT id FROM branches WHERE user_id=? AND is_head_office=1 LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'i', $company_id);
        mysqli_stmt_execute($stmt);
        $default_branch = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['id'] ?? 0);
        mysqli_stmt_close($stmt);
        $_SESSION['selected_branch_id'] = $default_branch;
    }
    $selected = array_key_exists('selected_branch_id', $_SESSION)
        ? max(0, (int)$_SESSION['selected_branch_id'])
        : $default_branch;
    if (!$for_write || $selected > 0) return $selected;

    $stmt = mysqli_prepare($conn, 'SELECT id FROM branches WHERE user_id=? AND is_head_office=1 LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $company_id);
    mysqli_stmt_execute($stmt);
    return (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['id'] ?? 0);
}

function branch_scope_sql($conn, $alias = '')
{
    $branch_id = selected_branch_id($conn, false);
    if ($branch_id <= 0) return '';
    $column = $alias === '' ? 'branch_id' : $alias . '.branch_id';
    return ' AND ' . $column . '=' . $branch_id;
}

function current_branch_label($conn)
{
    $branch_id = selected_branch_id($conn, false);
    if ($branch_id <= 0) return 'All Branches';
    $company_id = (int)($_SESSION['user_id'] ?? 0);
    $stmt = mysqli_prepare($conn, 'SELECT branch_name FROM branches WHERE id=? AND user_id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $branch_id, $company_id);
    mysqli_stmt_execute($stmt);
    return (string)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['branch_name'] ?? 'Branch');
}

function require_branch_record_access($conn, $table, $record_id, $redirect = '')
{
    $allowed = ['invoices', 'booking_invoices', 'expenses', 'money_ins', 'transfers', 'staff_ledger_entries'];
    if (!in_array($table, $allowed, true)) return;
    $branch_id = selected_branch_id($conn, false);
    if ($branch_id <= 0) return; // Head Office administrator is viewing All Branches.
    $company_id = (int)($_SESSION['user_id'] ?? 0);
    $record_id = (int)$record_id;
    $stmt = mysqli_prepare($conn, "SELECT id FROM `{$table}` WHERE id=? AND user_id=? AND branch_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'iii', $record_id, $company_id, $branch_id);
    mysqli_stmt_execute($stmt);
    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        if ($redirect !== '') {
            header('Location: ' . $redirect);
            exit;
        }
        http_response_code(403);
        exit('This record belongs to another branch.');
    }
}
