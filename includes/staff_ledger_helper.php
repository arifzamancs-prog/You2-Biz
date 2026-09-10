<?php

function ensure_staff_ledger_table($conn)
{
    // Loading the ledger must not run an ALTER on the shared transactions
    // table. Older live databases can reject that migration and return 500.
    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS staff_ledger_entries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            txn_no VARCHAR(100) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            staff_id BIGINT UNSIGNED NOT NULL,
            wallet_id BIGINT UNSIGNED NOT NULL,
            entry_type VARCHAR(80) NOT NULL,
            entry_date DATE NOT NULL,
            amount DOUBLE NOT NULL DEFAULT 0,
            note VARCHAR(500) NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_staff_ledger_txn (txn_no),
            INDEX idx_staff_ledger_user_date (user_id, entry_date),
            INDEX idx_staff_ledger_staff (staff_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $entry_type_column = mysqli_query($conn, "SHOW COLUMNS FROM staff_ledger_entries LIKE 'entry_type'");
    $entry_type_info = $entry_type_column ? mysqli_fetch_assoc($entry_type_column) : null;
    if($entry_type_info && stripos((string)($entry_type_info['Type'] ?? ''), 'enum') !== false){
        mysqli_query($conn, "ALTER TABLE staff_ledger_entries MODIFY entry_type VARCHAR(80) NOT NULL");
    }

    ensure_staff_ledger_payment_type_table($conn);
}

function ensure_staff_ledger_payment_type_table($conn)
{
    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS staff_ledger_payment_types (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            type_key VARCHAR(80) NOT NULL,
            type_name VARCHAR(120) NOT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_staff_payment_type_user_key (user_id, type_key),
            INDEX idx_staff_payment_type_user_status (user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function staff_ledger_payment_type_key($type_name)
{
    $key = strtolower(trim((string)$type_name));
    $key = preg_replace('/[^a-z0-9]+/', '_', $key);
    $key = trim($key, '_');

    return $key !== '' ? substr($key, 0, 80) : '';
}

function ensure_default_staff_ledger_payment_types($conn, $user_id)
{
    ensure_staff_ledger_payment_type_table($conn);

    foreach(['Bonus', 'Incentive'] as $type_name){
        $type_key = staff_ledger_payment_type_key($type_name);
        $stmt = mysqli_prepare(
            $conn,
            "INSERT IGNORE INTO staff_ledger_payment_types (user_id, type_key, type_name)
             VALUES (?, ?, ?)"
        );
        if($stmt){
            mysqli_stmt_bind_param($stmt, 'iss', $user_id, $type_key, $type_name);
            mysqli_stmt_execute($stmt);
        }
    }
}

function staff_ledger_payment_types($conn, $user_id, $active_only = true)
{
    ensure_default_staff_ledger_payment_types($conn, $user_id);

    $sql = "SELECT *
            FROM staff_ledger_payment_types
            WHERE user_id=?";
    if($active_only){
        $sql .= " AND status='active'";
    }
    $sql .= " ORDER BY type_name ASC";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);

    return mysqli_stmt_get_result($stmt);
}

function staff_ledger_payment_type_exists($conn, $user_id, $type_key)
{
    ensure_default_staff_ledger_payment_types($conn, $user_id);

    if($type_key === 'salary'){
        return true;
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT id
         FROM staff_ledger_payment_types
         WHERE user_id=?
         AND type_key=?
         AND status='active'
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'is', $user_id, $type_key);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    return $result && mysqli_num_rows($result) > 0;
}

function staff_ledger_payment_type_used($conn, $user_id, $type_key)
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT id
         FROM staff_ledger_entries
         WHERE user_id=?
         AND entry_type=?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'is', $user_id, $type_key);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    return $result && mysqli_num_rows($result) > 0;
}

function ensure_staff_ledger_transaction_type($conn)
{
    $transaction_type = mysqli_query($conn, "SHOW COLUMNS FROM transactions LIKE 'transaction_type'");
    $transaction_type_row = $transaction_type ? mysqli_fetch_assoc($transaction_type) : null;
    $type_definition = (string)($transaction_type_row['Type'] ?? '');
    if($type_definition === '' || strpos($type_definition, "'staff_payment'") !== false){
        return;
    }

    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type_definition, $matches);
    $types = array_map('stripslashes', $matches[1] ?? []);
    $types[] = 'staff_payment';
    $types = array_values(array_unique(array_filter($types, static function($type){
        return trim((string)$type) !== '';
    })));
    $enum_values = "'" . implode("','", array_map(static function($type){
        return str_replace("'", "''", $type);
    }, $types)) . "'";

    if(!mysqli_query($conn, "ALTER TABLE transactions MODIFY transaction_type ENUM({$enum_values}) NOT NULL")){
        throw new Exception('Transaction type update failed: ' . mysqli_error($conn));
    }
}

function staff_ledger_type_label($entry_type)
{
    $entry_type = trim((string)$entry_type);
    if($entry_type === ''){
        return 'Payment';
    }

    return ucwords(str_replace('_', ' ', $entry_type));
}
