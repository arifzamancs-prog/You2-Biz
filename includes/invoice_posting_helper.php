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

// Pure calculation shared with Sales posting; positive balance is previous due,
// negative balance is customer credit. This does not move money or post invoices.
function invoice_posting_payment_breakdown($invoice_total, $paid_amount, $customer_balance)
{
    $previous_due_total = max($customer_balance, 0);
    $outstanding_amount_total = abs(min($customer_balance, 0));
    $current_invoice_cash_payment = min($paid_amount, $invoice_total);
    $applied_outstanding_amount = min($outstanding_amount_total, max($invoice_total - $current_invoice_cash_payment, 0));
    $current_invoice_payment = min($current_invoice_cash_payment + $applied_outstanding_amount, $invoice_total);
    $remaining_after_current = max(($paid_amount + $applied_outstanding_amount) - $current_invoice_payment, 0);
    $previous_due_payment = min($remaining_after_current, $previous_due_total);
    $outstanding_payable = max($remaining_after_current - $previous_due_payment, 0);
    $new_due_amount = $invoice_total - $current_invoice_payment;
    if ($outstanding_payable > 0.01) $new_due_amount = -$outstanding_payable;
    if ($new_due_amount <= 0) {
        $new_payment_status = 'paid';
        if (abs($new_due_amount) <= 0.01) $new_due_amount = 0;
    } elseif ($paid_amount > 0 || $applied_outstanding_amount > 0) {
        $new_payment_status = 'partial';
    } else {
        $new_payment_status = 'due';
    }
    return compact('current_invoice_cash_payment', 'previous_due_payment', 'outstanding_payable', 'new_due_amount', 'new_payment_status');
}
