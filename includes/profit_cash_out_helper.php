<?php

function ensure_profit_cash_out_table($conn)
{
    $transaction_type = mysqli_query($conn, "SHOW COLUMNS FROM transactions LIKE 'transaction_type'");
    $transaction_type_row = $transaction_type ? mysqli_fetch_assoc($transaction_type) : null;
    if($transaction_type_row && strpos((string)($transaction_type_row['Type'] ?? ''), "'profit_cash_out'") === false){
        mysqli_query(
            $conn,
            "ALTER TABLE transactions MODIFY transaction_type ENUM(
                'money_in','expense','transfer','transfer_in','transfer_out',
                'sales_invoice','receive_payment','purchase','supplier_payment',
                'profit_cash_out'
            ) NOT NULL"
        );
    }

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
