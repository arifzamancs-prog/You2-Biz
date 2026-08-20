<?php

function ensure_customer_opening_due_tables($conn)
{
    static $ensured = false;

    if($ensured){
        return;
    }

    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS customer_opening_dues (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            customer_id INT NOT NULL,
            due_no VARCHAR(50) NOT NULL,
            entry_date DATE NOT NULL,
            amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            due_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(20) NOT NULL DEFAULT 'due',
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_due_no (user_id, due_no),
            KEY idx_user_customer_due (user_id, customer_id, due_amount),
            KEY idx_user_entry_date (user_id, entry_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $column_result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM customer_payments LIKE 'opening_due_id'"
    );

    if($column_result && mysqli_num_rows($column_result) === 0){
        mysqli_query(
            $conn,
            "ALTER TABLE customer_payments
             ADD COLUMN opening_due_id INT NULL AFTER invoice_id"
        );
    }

    $ensured = true;
}

function generate_customer_opening_due_no($conn)
{
    ensure_customer_opening_due_tables($conn);

    for($attempt = 0; $attempt < 10; $attempt++){
        $due_no = 'OD-' . date('ymdHis') . random_int(10, 99);

        $stmt = mysqli_prepare(
            $conn,
            "SELECT COUNT(*) AS total_count
             FROM customer_opening_dues
             WHERE user_id=?
             AND due_no=?"
        );

        if(!$stmt){
            return $due_no;
        }

        $user_id = (int)($_SESSION['user_id'] ?? 0);
        mysqli_stmt_bind_param($stmt, "is", $user_id, $due_no);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if((int)($row['total_count'] ?? 0) === 0){
            return $due_no;
        }
    }

    return 'OD-' . date('ymdHis') . random_int(100, 999);
}

