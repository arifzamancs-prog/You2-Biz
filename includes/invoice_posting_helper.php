<?php

function ensure_invoice_posting_columns($conn)
{
    $result = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'accounting_status'");

    if($result && mysqli_num_rows($result) === 0){
        mysqli_query(
            $conn,
            "ALTER TABLE invoices
             ADD COLUMN accounting_status VARCHAR(20) NOT NULL DEFAULT 'posted'"
        );
    }

    $columns = [
        'created_by_user_id' => "ALTER TABLE invoices
                                 ADD COLUMN created_by_user_id INT NULL
                                 AFTER accounting_status",
        'created_by_name' => "ALTER TABLE invoices
                              ADD COLUMN created_by_name VARCHAR(255) NULL
                              AFTER created_by_user_id",
        'created_by_type' => "ALTER TABLE invoices
                              ADD COLUMN created_by_type VARCHAR(20) NULL
                              AFTER created_by_name"
    ];

    foreach($columns as $column => $alter_sql){
        $result = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE '" . mysqli_real_escape_string($conn, $column) . "'");

        if($result && mysqli_num_rows($result) === 0){
            mysqli_query($conn, $alter_sql);
        }
    }
}

function invoice_is_pending($invoice)
{
    return ($invoice['accounting_status'] ?? 'posted') === 'pending';
}
