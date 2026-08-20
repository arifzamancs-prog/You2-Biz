<?php

function ensure_invoice_reference_columns($conn)
{
    $columns = [
        'staff_id' => "ALTER TABLE invoices ADD COLUMN staff_id BIGINT UNSIGNED NULL AFTER notes",
        'restaurant_table_id' => "ALTER TABLE invoices ADD COLUMN restaurant_table_id BIGINT UNSIGNED NULL AFTER staff_id",
    ];
    foreach ($columns as $column => $sql) {
        $result = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE '" . mysqli_real_escape_string($conn, $column) . "'");
        if ($result && mysqli_num_rows($result) === 0) {
            mysqli_query($conn, $sql);
        }
    }
}
