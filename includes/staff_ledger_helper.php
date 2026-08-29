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
            entry_type ENUM('salary','bonus','incentive') NOT NULL,
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
}

function ensure_staff_ledger_transaction_type($conn)
{
    $transaction_type = mysqli_query($conn, "SHOW COLUMNS FROM transactions LIKE 'transaction_type'");
    $transaction_type_row = $transaction_type ? mysqli_fetch_assoc($transaction_type) : null;
    if($transaction_type_row && strpos((string)($transaction_type_row['Type'] ?? ''), "'staff_payment'") === false){
        mysqli_query(
            $conn,
            "ALTER TABLE transactions MODIFY transaction_type ENUM(
                'money_in','expense','transfer','transfer_in','transfer_out',
                'sales_invoice','receive_payment','purchase','supplier_payment',
                'profit_cash_out','staff_payment'
            ) NOT NULL"
        );
    }
}

function staff_ledger_type_label($entry_type)
{
    return ucfirst((string)$entry_type);
}
