<?php

function ensure_profit_cash_out_table($conn)
{
    // Creating the ledger table is required to render the page.  Do not alter
    // the shared transactions table during a normal page view: old live
    // databases can reject that migration and make the entire page fail.
    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS profit_cash_outs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            txn_no VARCHAR(100) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            wallet_id BIGINT UNSIGNED NOT NULL,
            txn_date DATE NOT NULL,
            amount DOUBLE NOT NULL DEFAULT 0,
            note VARCHAR(500) NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_profit_cash_out_txn (txn_no),
            INDEX idx_profit_cash_out_user_date (user_id, txn_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ensure_profit_cash_out_transaction_type($conn)
{
    $transaction_type = mysqli_query($conn, "SHOW COLUMNS FROM transactions LIKE 'transaction_type'");
    $transaction_type_row = $transaction_type ? mysqli_fetch_assoc($transaction_type) : null;
    if($transaction_type_row && stripos((string)($transaction_type_row['Type'] ?? ''), 'enum(') === 0){
        // Preserve all existing transaction values on live databases; the
        // previous narrow ENUM caused data-truncation during ALTER TABLE.
        mysqli_query($conn, "ALTER TABLE transactions MODIFY transaction_type VARCHAR(50) NOT NULL");
    }
}
