<?php

function ensure_booking_invoice_table($conn)
{
    $transaction_type_column = mysqli_query($conn, "SHOW COLUMNS FROM transactions LIKE 'transaction_type'");
    $transaction_type = $transaction_type_column ? mysqli_fetch_assoc($transaction_type_column) : null;
    if($transaction_type && stripos((string)($transaction_type['Type'] ?? ''), 'enum(') === 0){
        mysqli_query($conn, "ALTER TABLE transactions MODIFY transaction_type VARCHAR(50) NOT NULL");
    }

    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS booking_invoices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            invoice_no VARCHAR(50) NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            project_id BIGINT UNSIGNED NOT NULL,
            package_id BIGINT UNSIGNED NOT NULL,
            wallet_id BIGINT UNSIGNED NOT NULL,
            invoice_type VARCHAR(100) NOT NULL DEFAULT 'booking',
            invoice_date DATE NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_booking_invoice_no (invoice_no),
            INDEX idx_booking_invoice_user_type (user_id, invoice_type),
            INDEX idx_booking_invoice_customer (customer_id),
            INDEX idx_booking_invoice_project (project_id),
            INDEX idx_booking_invoice_package (package_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $column_query = mysqli_query(
        $conn,
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE()
         AND TABLE_NAME='booking_invoices'
         AND COLUMN_NAME='invoice_type'"
    );
    $column = $column_query ? mysqli_fetch_assoc($column_query) : null;

    if($column && stripos((string)$column['COLUMN_TYPE'], 'enum(') === 0){
        mysqli_query($conn, "ALTER TABLE booking_invoices MODIFY invoice_type VARCHAR(100) NOT NULL DEFAULT 'booking'");
    }

    $status_column = mysqli_query($conn, "SHOW COLUMNS FROM booking_invoices LIKE 'status'");
    if(!$status_column || mysqli_num_rows($status_column) === 0){
        mysqli_query($conn, "ALTER TABLE booking_invoices ADD COLUMN status ENUM('pending','confirmed') NOT NULL DEFAULT 'pending' AFTER notes");
    }

    $effect_column = mysqli_query($conn, "SHOW COLUMNS FROM booking_invoices LIKE 'wallet_effect_applied'");
    if(!$effect_column || mysqli_num_rows($effect_column) === 0){
        mysqli_query($conn, "ALTER TABLE booking_invoices ADD COLUMN wallet_effect_applied TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    }

    $confirmed_column = mysqli_query($conn, "SHOW COLUMNS FROM booking_invoices LIKE 'confirmed_at'");
    if(!$confirmed_column || mysqli_num_rows($confirmed_column) === 0){
        mysqli_query($conn, "ALTER TABLE booking_invoices ADD COLUMN confirmed_at DATETIME NULL AFTER wallet_effect_applied");
    }
}

function booking_default_invoice_types()
{
    return [
        'booking' => 'Booking',
        'installment' => 'Installment',
        'cancel_return' => 'Cancel/Return',
        'profit_return' => 'Profit Return',
    ];
}

function booking_default_invoice_type_behaviors()
{
    return [
        'booking' => 'income',
        'installment' => 'income',
        'cancel_return' => 'expense',
        'profit_return' => 'expense',
    ];
}

function ensure_booking_invoice_type_table($conn, $user_id)
{
    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS booking_invoice_types (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            type_key VARCHAR(100) NOT NULL,
            type_name VARCHAR(100) NOT NULL,
            behavior ENUM('income','expense') NOT NULL DEFAULT 'income',
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_booking_invoice_type_user_key (user_id, type_key),
            INDEX idx_booking_invoice_type_user_status (user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $behavior_column = mysqli_query(
        $conn,
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE()
         AND TABLE_NAME='booking_invoice_types'
         AND COLUMN_NAME='behavior'"
    );
    if(!$behavior_column || mysqli_num_rows($behavior_column) === 0){
        mysqli_query($conn, "ALTER TABLE booking_invoice_types ADD COLUMN behavior ENUM('income','expense') NOT NULL DEFAULT 'income' AFTER type_name");
    }

    foreach(booking_default_invoice_types() as $type_key => $type_name){
        $behaviors = booking_default_invoice_type_behaviors();
        $behavior = $behaviors[$type_key] ?? 'income';
        $seed_stmt = mysqli_prepare(
            $conn,
            "INSERT IGNORE INTO booking_invoice_types (user_id, type_key, type_name, behavior)
             VALUES (?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param($seed_stmt, 'isss', $user_id, $type_key, $type_name, $behavior);
        mysqli_stmt_execute($seed_stmt);
    }

    $default_behaviors = booking_default_invoice_type_behaviors();
    foreach($default_behaviors as $type_key => $behavior){
        $behavior_stmt = mysqli_prepare($conn, "UPDATE booking_invoice_types SET behavior=? WHERE user_id=? AND type_key=?");
        mysqli_stmt_bind_param($behavior_stmt, 'sis', $behavior, $user_id, $type_key);
        mysqli_stmt_execute($behavior_stmt);
    }
}

function booking_invoice_types($conn = null, $user_id = 0, $active_only = true)
{
    if(!$conn || $user_id <= 0){
        return booking_default_invoice_types();
    }

    ensure_booking_invoice_type_table($conn, $user_id);
    $sql = "SELECT type_key, type_name FROM booking_invoice_types WHERE user_id=?";
    if($active_only){
        $sql .= " AND status='active'";
    }
    $sql .= " ORDER BY type_name";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $types = [];
    while($result && $row = mysqli_fetch_assoc($result)){
        $types[$row['type_key']] = $row['type_name'];
    }

    return $types ?: booking_default_invoice_types();
}

function normalize_booking_invoice_type($type, $types = null)
{
    $types = $types ?? booking_default_invoice_types();
    $type = strtolower(trim((string)$type));

    return array_key_exists($type, $types) ? $type : (array_key_first($types) ?: 'booking');
}

function booking_invoice_type_label($type, $types = null)
{
    $types = $types ?? booking_default_invoice_types();
    $normalized_type = normalize_booking_invoice_type($type, $types);
    return $types[$normalized_type] ?? ucfirst(str_replace('_', ' ', $type));
}

function booking_invoice_page_title($type, $types = null)
{
    return booking_invoice_type_label($type, $types) . ' Invoice';
}

function booking_invoice_recent_title($type, $types = null)
{
    return 'Recent ' . booking_invoice_type_label($type, $types) . ' Invoices';
}

function booking_invoice_behavior($conn, $user_id, $type)
{
    $stmt = mysqli_prepare($conn, "SELECT behavior FROM booking_invoice_types WHERE user_id=? AND type_key=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'is', $user_id, $type);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return ($row['behavior'] ?? 'income') === 'expense' ? 'expense' : 'income';
}

function confirm_booking_invoice($conn, $invoice_id, $user_id)
{
    require_once __DIR__ . '/wallet_helper.php';
    require_once __DIR__ . '/transaction_helper.php';

    mysqli_begin_transaction($conn);
    try {
        $stmt = mysqli_prepare($conn, "SELECT * FROM booking_invoices WHERE id=? AND user_id=? FOR UPDATE");
        mysqli_stmt_bind_param($stmt, 'ii', $invoice_id, $user_id);
        mysqli_stmt_execute($stmt);
        $invoice = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if(!$invoice){
            throw new Exception('Invoice not found.');
        }

        if(($invoice['status'] ?? 'pending') === 'confirmed'){
            mysqli_commit($conn);
            return $invoice;
        }

        booking_invoice_apply_wallet_effect($conn, $invoice, $user_id);
        mysqli_commit($conn);
        $invoice['status'] = 'confirmed';
        $invoice['wallet_effect_applied'] = 1;
        return $invoice;
    } catch(Throwable $error) {
        mysqli_rollback($conn);
        throw $error;
    }
}

function booking_invoice_apply_wallet_effect($conn, $invoice, $user_id)
{
    require_once __DIR__ . '/wallet_helper.php';
    require_once __DIR__ . '/transaction_helper.php';

    $invoice_id = (int)$invoice['id'];
    $behavior = booking_invoice_behavior($conn, $user_id, $invoice['invoice_type']);
    $amount = (float)$invoice['amount'];

    if($amount <= 0){
        throw new Exception('Invoice amount must be greater than zero.');
    }

    if($behavior === 'expense'){
        debit_wallet($conn, (int)$invoice['wallet_id'], $user_id, $amount);
    }else{
        credit_wallet($conn, (int)$invoice['wallet_id'], $user_id, $amount);
    }

    $transaction_type = $behavior === 'expense' ? 'invoice_expense' : 'invoice_income';
    $txn_no = generate_short_unique_txn_no($conn, 'INV', 'transactions', 'txn_no');
    $note = 'Invoice ' . $invoice['invoice_no'] . ' confirmed';
    record_wallet_transaction($conn, $txn_no, $user_id, (int)$invoice['wallet_id'], $transaction_type, $invoice_id, $amount, $note, $invoice['invoice_date']);

    $update_stmt = mysqli_prepare($conn, "UPDATE booking_invoices SET status='confirmed', wallet_effect_applied=1, confirmed_at=NOW() WHERE id=? AND user_id=?");
    mysqli_stmt_bind_param($update_stmt, 'ii', $invoice_id, $user_id);
    if(!mysqli_stmt_execute($update_stmt)){
        throw new Exception(mysqli_stmt_error($update_stmt));
    }
}

function booking_invoice_reverse_wallet_effect($conn, $invoice, $user_id)
{
    require_once __DIR__ . '/wallet_helper.php';

    if(($invoice['status'] ?? 'pending') !== 'confirmed' && empty($invoice['wallet_effect_applied'])){
        return;
    }

    $behavior = booking_invoice_behavior($conn, $user_id, $invoice['invoice_type']);
    $amount = (float)$invoice['amount'];
    $wallet_id = (int)$invoice['wallet_id'];

    $wallet_stmt = mysqli_prepare(
        $conn,
        "SELECT balance
         FROM wallets
         WHERE id=?
         AND user_id=?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($wallet_stmt, 'ii', $wallet_id, $user_id);
    mysqli_stmt_execute($wallet_stmt);
    $wallet_result = mysqli_stmt_get_result($wallet_stmt);
    $wallet_row = $wallet_result ? mysqli_fetch_assoc($wallet_result) : null;

    if($wallet_row){
        $wallet_balance = (float)($wallet_row['balance'] ?? 0);

        // Reverse exactly the posting made on confirmation when the wallet still exists.
        if($behavior === 'expense'){
            credit_wallet($conn, $wallet_id, $user_id, $amount);
        }elseif($wallet_balance >= $amount){
            debit_wallet($conn, $wallet_id, $user_id, $amount);
        }
    }

    $transaction_stmt = mysqli_prepare(
        $conn,
        "DELETE FROM transactions
         WHERE user_id=?
         AND reference_id=?
         AND transaction_type IN ('invoice_income', 'invoice_expense')"
    );
    $invoice_id = (int)$invoice['id'];
    mysqli_stmt_bind_param($transaction_stmt, 'ii', $user_id, $invoice_id);
    if(!mysqli_stmt_execute($transaction_stmt)){
        throw new Exception(mysqli_stmt_error($transaction_stmt));
    }
}

function generate_booking_invoice_no($conn)
{
    for($i = 0; $i < 5; $i++){
        $invoice_no = 'INV-' . date('ymdHis') . random_int(1000, 9999);
        $stmt = mysqli_prepare(
            $conn,
            "SELECT id
             FROM booking_invoices
             WHERE invoice_no=?
             LIMIT 1"
        );
        mysqli_stmt_bind_param($stmt, 's', $invoice_no);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if(!$result || mysqli_num_rows($result) === 0){
            return $invoice_no;
        }
    }

    return 'INV-' . date('ymdHis') . random_int(10000, 99999);
}
