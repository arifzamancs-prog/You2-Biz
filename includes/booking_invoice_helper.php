<?php

function ensure_booking_invoice_table($conn)
{
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
            invoice_type ENUM('booking','installment','cancel_return','profit_return') NOT NULL DEFAULT 'booking',
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
}

function booking_invoice_types()
{
    return [
        'booking' => 'Booking',
        'installment' => 'Installment',
        'cancel_return' => 'Cancel/Return',
        'profit_return' => 'Profit Return',
    ];
}

function normalize_booking_invoice_type($type)
{
    $types = booking_invoice_types();
    $type = strtolower(trim((string)$type));

    return array_key_exists($type, $types) ? $type : 'booking';
}

function booking_invoice_type_label($type)
{
    $types = booking_invoice_types();
    return $types[normalize_booking_invoice_type($type)];
}

function booking_invoice_page_title($type)
{
    return booking_invoice_type_label($type) . ' Invoice';
}

function booking_invoice_recent_title($type)
{
    return 'Recent ' . booking_invoice_type_label($type) . ' Invoices';
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
