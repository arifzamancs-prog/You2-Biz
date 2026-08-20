<?php

require_once __DIR__ . '/customer_opening_due_helper.php';

function customer_previous_due_total($conn, $user_id, $customer_id, $exclude_invoice_id = 0)
{
    /*
     * `invoices.due_amount` is a cached per-invoice value.  Older invoices
     * can therefore contain stale values after a payment was corrected.
     * Previous Due must always use the customer's ledger balance instead:
     * posted sales + opening due - recorded customer payments.
     */
    return customer_ledger_due_total(
        $conn,
        $user_id,
        $customer_id,
        $exclude_invoice_id
    );
}

function customer_ledger_due_total($conn, $user_id, $customer_id, $exclude_invoice_id = 0)
{
    ensure_customer_opening_due_tables($conn);

    if((int)$customer_id <= 0){
        return 0.0;
    }

    $sales_sql = "SELECT COALESCE(SUM(total_amount),0) AS total_sales
                  FROM invoices
                  WHERE customer_id=?
                  AND user_id=?
                  AND accounting_status='posted'";

    if((int)$exclude_invoice_id > 0){
        $sales_sql .= " AND id<>?";
        $sales_stmt = mysqli_prepare($conn, $sales_sql);
        mysqli_stmt_bind_param(
            $sales_stmt,
            "iii",
            $customer_id,
            $user_id,
            $exclude_invoice_id
        );
    }else{
        $sales_stmt = mysqli_prepare($conn, $sales_sql);
        mysqli_stmt_bind_param($sales_stmt, "ii", $customer_id, $user_id);
    }

    mysqli_stmt_execute($sales_stmt);
    $sales_row = mysqli_fetch_assoc(mysqli_stmt_get_result($sales_stmt));
    $total_sales = (float)($sales_row['total_sales'] ?? 0);

    $opening_stmt = mysqli_prepare(
        $conn,
        "SELECT COALESCE(SUM(amount),0) AS total_sales
         FROM customer_opening_dues
         WHERE customer_id=?
         AND user_id=?"
    );
    mysqli_stmt_bind_param($opening_stmt, "ii", $customer_id, $user_id);
    mysqli_stmt_execute($opening_stmt);
    $opening_row = mysqli_fetch_assoc(mysqli_stmt_get_result($opening_stmt));
    $total_sales += (float)($opening_row['total_sales'] ?? 0);

    $payment_sql = "SELECT COALESCE(SUM(amount),0) AS total_paid
                    FROM customer_payments
                    WHERE customer_id=?
                    AND user_id=?";

    $payment_stmt = mysqli_prepare($conn, $payment_sql);
    mysqli_stmt_bind_param($payment_stmt, "ii", $customer_id, $user_id);
    mysqli_stmt_execute($payment_stmt);
    $payment_row = mysqli_fetch_assoc(mysqli_stmt_get_result($payment_stmt));
    $total_paid = (float)($payment_row['total_paid'] ?? 0);

    $due = $total_sales - $total_paid;

    return $due > 0 ? round($due, 2) : 0.0;
}

function customer_signed_balance_total($conn, $user_id, $customer_id, $exclude_invoice_id = 0)
{
    ensure_customer_opening_due_tables($conn);

    if((int)$customer_id <= 0){
        return 0.0;
    }

    $sales_sql = "SELECT COALESCE(SUM(total_amount),0) AS total_sales
                  FROM invoices
                  WHERE customer_id=?
                  AND user_id=?
                  AND accounting_status='posted'";

    if((int)$exclude_invoice_id > 0){
        $sales_sql .= " AND id<>?";
        $sales_stmt = mysqli_prepare($conn, $sales_sql);
        mysqli_stmt_bind_param(
            $sales_stmt,
            "iii",
            $customer_id,
            $user_id,
            $exclude_invoice_id
        );
    }else{
        $sales_stmt = mysqli_prepare($conn, $sales_sql);
        mysqli_stmt_bind_param($sales_stmt, "ii", $customer_id, $user_id);
    }

    mysqli_stmt_execute($sales_stmt);
    $sales_row = mysqli_fetch_assoc(mysqli_stmt_get_result($sales_stmt));
    $total_sales = (float)($sales_row['total_sales'] ?? 0);

    $opening_stmt = mysqli_prepare(
        $conn,
        "SELECT COALESCE(SUM(amount),0) AS total_sales
         FROM customer_opening_dues
         WHERE customer_id=?
         AND user_id=?"
    );
    mysqli_stmt_bind_param($opening_stmt, "ii", $customer_id, $user_id);
    mysqli_stmt_execute($opening_stmt);
    $opening_row = mysqli_fetch_assoc(mysqli_stmt_get_result($opening_stmt));
    $total_sales += (float)($opening_row['total_sales'] ?? 0);

    $payment_sql = "SELECT COALESCE(SUM(amount),0) AS total_paid
                    FROM customer_payments
                    WHERE customer_id=?
                    AND user_id=?";

    $payment_stmt = mysqli_prepare($conn, $payment_sql);
    mysqli_stmt_bind_param($payment_stmt, "ii", $customer_id, $user_id);
    mysqli_stmt_execute($payment_stmt);
    $payment_row = mysqli_fetch_assoc(mysqli_stmt_get_result($payment_stmt));
    $total_paid = (float)($payment_row['total_paid'] ?? 0);

    return round($total_sales - $total_paid, 2);
}

function latest_existing_customer_invoice_id($conn, $user_id, $customer_id)
{
    if((int)$customer_id <= 0){
        return 0;
    }

    $sql = "SELECT MAX(id) AS latest_invoice_id
            FROM invoices
            WHERE user_id=?
            AND customer_id=?";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $user_id, $customer_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    return (int)($row['latest_invoice_id'] ?? 0);
}

function can_modify_customer_invoice($conn, $user_id, $invoice_id, $customer_id)
{
    if((int)$invoice_id <= 0){
        return false;
    }

    if((int)$customer_id <= 0){
        return true;
    }

    return latest_existing_customer_invoice_id(
        $conn,
        $user_id,
        $customer_id
    ) === (int)$invoice_id;
}

function customer_invoice_modify_lock_message()
{
    return "For existing customers, only the last invoice can be edited or deleted.";
}

function customer_source_invoice_payment_total(
    $conn,
    $user_id,
    $customer_id,
    $source_invoice_id,
    $source_invoice_no
) {
    if((int)$customer_id <= 0 || (int)$source_invoice_id <= 0){
        return 0.0;
    }

    $payment_note_prefix = "Invoice Payment - " . $source_invoice_no . " (Previous Due - ";

    $sql = "SELECT COALESCE(SUM(amount),0) AS source_paid
            FROM customer_payments
            WHERE customer_id=?
            AND user_id=?
            AND note LIKE CONCAT(?, '%')";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param(
        $stmt,
        "iis",
        $customer_id,
        $user_id,
        $payment_note_prefix
    );
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    return (float)($row['source_paid'] ?? 0);
}

function customer_source_invoice_all_payment_total(
    $conn,
    $user_id,
    $customer_id,
    $source_invoice_id,
    $source_invoice_no
) {
    if((int)$customer_id <= 0 || (int)$source_invoice_id <= 0){
        return 0.0;
    }

    $invoice_payment_note = "Invoice Payment - " . $source_invoice_no;
    $previous_due_note_prefix = "Invoice Payment - " . $source_invoice_no . " (Previous Due - ";
    $outstanding_note = "Outstanding Amount - " . $source_invoice_no;

    $sql = "SELECT COALESCE(SUM(amount),0) AS source_paid
            FROM customer_payments
            WHERE customer_id=?
            AND user_id=?
            AND (
                (invoice_id=? AND note IN (?, ?))
                OR note LIKE CONCAT(?, '%')
            )";

    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return 0.0;
    }

    mysqli_stmt_bind_param(
        $stmt,
        "iiisss",
        $customer_id,
        $user_id,
        $source_invoice_id,
        $invoice_payment_note,
        $outstanding_note,
        $previous_due_note_prefix
    );
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    return (float)($row['source_paid'] ?? 0);
}

function customer_returnable_balance_rows($conn, $user_id, $customer_id, $exclude_invoice_id = 0)
{
    if((int)$customer_id <= 0){
        return [];
    }

    $sql = "SELECT
                ii.product_id,
                p.product_name,
                SUM(ii.quantity) AS net_quantity
            FROM invoice_items ii
            INNER JOIN invoices i
                ON i.id = ii.invoice_id
            LEFT JOIN products p
                ON p.id = ii.product_id
            WHERE i.customer_id=?
            AND i.user_id=?
            AND i.accounting_status='posted'
            AND ii.unit_price = 0";

    if((int)$exclude_invoice_id > 0){
        $sql .= " AND i.id<>?";
    }

    $sql .= " GROUP BY ii.product_id, p.product_name
              HAVING ABS(net_quantity) > 0.00001
              ORDER BY p.product_name ASC";

    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return [];
    }

    if((int)$exclude_invoice_id > 0){
        mysqli_stmt_bind_param(
            $stmt,
            "iii",
            $customer_id,
            $user_id,
            $exclude_invoice_id
        );
    }else{
        mysqli_stmt_bind_param($stmt, "ii", $customer_id, $user_id);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];

    while($result && $row = mysqli_fetch_assoc($result)){
        $net_quantity = (float)($row['net_quantity'] ?? 0);

        if(abs($net_quantity) <= 0.00001){
            continue;
        }

        $rows[] = [
            'product_name' => trim((string)($row['product_name'] ?? 'Returnable Product')),
            'remaining_qty' => $net_quantity,
        ];
    }

    return $rows;
}

function customer_returnable_balance_summary_text($conn, $user_id, $customer_id, $exclude_invoice_id = 0)
{
    $rows = customer_returnable_balance_rows($conn, $user_id, $customer_id, $exclude_invoice_id);

    if(empty($rows)){
        return '';
    }

    $parts = [];

    foreach($rows as $row){
        $qty = rtrim(rtrim(number_format((float)$row['remaining_qty'], 2, '.', ''), '0'), '.');
        $parts[] = $row['product_name'] . ' (' . $qty . ')';
    }

    return implode(', ', $parts);
}

function customer_due_report_rows($conn, $user_id)
{
    ensure_customer_opening_due_tables($conn);

    $sql = "SELECT
                c.id,
                c.customer_name,
                c.address,
                c.phone,
                COALESCE(due_inv.due_invoice_count,0) + COALESCE(open_due.due_entry_count,0) AS due_invoice_count,
                GREATEST(
                    COALESCE(inv.total_sales,0) + COALESCE(open_total.total_sales,0) - COALESCE(pay.total_paid,0),
                    0
                ) AS total_due
            FROM customers c
            LEFT JOIN (
                SELECT customer_id, SUM(total_amount) AS total_sales
                FROM invoices
                WHERE user_id=?
                AND accounting_status='posted'
                GROUP BY customer_id
            ) inv
                ON inv.customer_id = c.id
            LEFT JOIN (
                SELECT customer_id, SUM(amount) AS total_sales
                FROM customer_opening_dues
                WHERE user_id=?
                GROUP BY customer_id
            ) open_total
                ON open_total.customer_id = c.id
            LEFT JOIN (
                SELECT customer_id, SUM(amount) AS total_paid
                FROM customer_payments
                WHERE user_id=?
                GROUP BY customer_id
            ) pay
                ON pay.customer_id = c.id
            LEFT JOIN (
                SELECT customer_id, COUNT(id) AS due_invoice_count
                FROM invoices
                WHERE user_id=?
                AND accounting_status='posted'
                AND due_amount > 0
                GROUP BY customer_id
            ) due_inv
                ON due_inv.customer_id = c.id
            LEFT JOIN (
                SELECT customer_id, COUNT(id) AS due_entry_count
                FROM customer_opening_dues
                WHERE user_id=?
                AND due_amount > 0
                GROUP BY customer_id
            ) open_due
                ON open_due.customer_id = c.id
            WHERE c.user_id = ?
            HAVING total_due > 0
            ORDER BY total_due DESC";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param(
        $stmt,
        "iiiiii",
        $user_id,
        $user_id,
        $user_id,
        $user_id,
        $user_id,
        $user_id
    );
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $rows = [];

    while($row = mysqli_fetch_assoc($result)){
        $rows[] = $row;
    }

    return $rows;
}

function customer_due_report_total($conn, $user_id)
{
    $rows = customer_due_report_rows($conn, $user_id);
    $total_due = 0.0;

    foreach($rows as $row){
        $total_due += (float)($row['total_due'] ?? 0);
    }

    return round($total_due, 2);
}

function allocate_customer_previous_due_payment(
    $conn,
    $user_id,
    $customer_id,
    $source_invoice_id,
    $source_invoice_no,
    $amount
) {
    ensure_customer_opening_due_tables($conn);

    $amount = round((float)$amount, 2);

    if($amount <= 0){
        return 0.0;
    }

    $remaining_amount = $amount;
    $allocated_amount = 0.0;

    $sql = "SELECT id, invoice_no, invoice_date, paid_amount, due_amount
            FROM invoices
            WHERE customer_id=?
            AND user_id=?
            AND accounting_status='posted'
            AND due_amount > 0
            AND id<>?
            ORDER BY invoice_date ASC, id ASC";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param(
        $stmt,
        "iii",
        $customer_id,
        $user_id,
        $source_invoice_id
    );
    mysqli_stmt_execute($stmt);
    $invoices = mysqli_stmt_get_result($stmt);

    $due_entries = [];

    while($invoice = mysqli_fetch_assoc($invoices)){
        $due_entries[] = [
            'entry_type' => 'invoice',
            'id' => (int)$invoice['id'],
            'reference_no' => $invoice['invoice_no'],
            'entry_date' => $invoice['invoice_date'],
            'paid_amount' => (float)$invoice['paid_amount'],
            'due_amount' => (float)$invoice['due_amount'],
        ];
    }

    $opening_stmt = mysqli_prepare(
        $conn,
        "SELECT id, due_no, entry_date, paid_amount, due_amount
         FROM customer_opening_dues
         WHERE customer_id=?
         AND user_id=?
         AND due_amount > 0
         ORDER BY entry_date ASC, id ASC"
    );
    mysqli_stmt_bind_param($opening_stmt, "ii", $customer_id, $user_id);
    mysqli_stmt_execute($opening_stmt);
    $opening_rows = mysqli_stmt_get_result($opening_stmt);

    while($opening_due = mysqli_fetch_assoc($opening_rows)){
        $due_entries[] = [
            'entry_type' => 'opening_due',
            'id' => (int)$opening_due['id'],
            'reference_no' => $opening_due['due_no'],
            'entry_date' => $opening_due['entry_date'],
            'paid_amount' => (float)$opening_due['paid_amount'],
            'due_amount' => (float)$opening_due['due_amount'],
        ];
    }

    usort($due_entries, function($a, $b){
        $date_compare = strcmp((string)$a['entry_date'], (string)$b['entry_date']);

        if($date_compare !== 0){
            return $date_compare;
        }

        return (int)$a['id'] <=> (int)$b['id'];
    });

    foreach($due_entries as $entry){
        if($remaining_amount <= 0.01){
            break;
        }

        $entry_due = (float)$entry['due_amount'];
        $pay_amount = min($remaining_amount, $entry_due);
        $new_paid = (float)$entry['paid_amount'] + $pay_amount;
        $new_due = $entry_due - $pay_amount;
        $status = $new_due <= 0.01 ? 'paid' : 'partial';

        if($new_due <= 0.01){
            $new_due = 0;
        }

        $note = "Invoice Payment - " . $source_invoice_no .
            " (Previous Due - " . $entry['reference_no'] . ")";

        if($entry['entry_type'] === 'invoice'){
            $payment_sql = "INSERT INTO customer_payments
                            (
                                user_id,
                                customer_id,
                                invoice_id,
                                amount,
                                payment_date,
                                note
                            )
                            VALUES
                            (
                                ?,
                                ?,
                                ?,
                                ?,
                                CURDATE(),
                                ?
                            )";

            $payment_stmt = mysqli_prepare($conn, $payment_sql);
            mysqli_stmt_bind_param(
                $payment_stmt,
                "iiids",
                $user_id,
                $customer_id,
                $entry['id'],
                $pay_amount,
                $note
            );
            mysqli_stmt_execute($payment_stmt);

            $update_sql = "UPDATE invoices
                           SET paid_amount=?,
                               due_amount=?,
                               payment_status=?
                           WHERE id=?
                           AND user_id=?";

            $update_stmt = mysqli_prepare($conn, $update_sql);
            mysqli_stmt_bind_param(
                $update_stmt,
                "ddsii",
                $new_paid,
                $new_due,
                $status,
                $entry['id'],
                $user_id
            );
            mysqli_stmt_execute($update_stmt);
        }else{
            $payment_sql = "INSERT INTO customer_payments
                            (
                                user_id,
                                customer_id,
                                invoice_id,
                                opening_due_id,
                                amount,
                                payment_date,
                                note
                            )
                            VALUES
                            (
                                ?,
                                ?,
                                NULL,
                                ?,
                                ?,
                                CURDATE(),
                                ?
                            )";

            $payment_stmt = mysqli_prepare($conn, $payment_sql);
            mysqli_stmt_bind_param(
                $payment_stmt,
                "iiids",
                $user_id,
                $customer_id,
                $entry['id'],
                $pay_amount,
                $note
            );
            mysqli_stmt_execute($payment_stmt);

            $update_sql = "UPDATE customer_opening_dues
                           SET paid_amount=?,
                               due_amount=?,
                               status=?
                           WHERE id=?
                           AND user_id=?";

            $update_stmt = mysqli_prepare($conn, $update_sql);
            mysqli_stmt_bind_param(
                $update_stmt,
                "ddsii",
                $new_paid,
                $new_due,
                $status,
                $entry['id'],
                $user_id
            );
            mysqli_stmt_execute($update_stmt);
        }

        $remaining_amount -= $pay_amount;
        $allocated_amount += $pay_amount;
    }

    if($remaining_amount > 0.01){
        throw new Exception("Previous due payment allocation failed.");
    }

    return round($allocated_amount, 2);
}

function rollback_customer_previous_due_payment_allocation(
    $conn,
    $user_id,
    $customer_id,
    $source_invoice_no
) {
    ensure_customer_opening_due_tables($conn);

    $payment_note_prefix = "Invoice Payment - " . $source_invoice_no . " (Previous Due - ";

    $sql = "SELECT id, invoice_id, opening_due_id, amount
            FROM customer_payments
            WHERE user_id=?
            AND customer_id=?
            AND note LIKE CONCAT(?, '%')
            ORDER BY id DESC";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param(
        $stmt,
        "iis",
        $user_id,
        $customer_id,
        $payment_note_prefix
    );
    mysqli_stmt_execute($stmt);
    $payments = mysqli_stmt_get_result($stmt);

    while($payment = mysqli_fetch_assoc($payments)){
        $amount = (float)$payment['amount'];
        $invoice_id = (int)$payment['invoice_id'];
        $opening_due_id = (int)($payment['opening_due_id'] ?? 0);

        if($invoice_id > 0){
            $invoice_sql = "SELECT total_amount, paid_amount, due_amount
                            FROM invoices
                            WHERE id=?
                            AND user_id=?";

            $invoice_stmt = mysqli_prepare($conn, $invoice_sql);
            mysqli_stmt_bind_param($invoice_stmt, "ii", $invoice_id, $user_id);
            mysqli_stmt_execute($invoice_stmt);
            $invoice = mysqli_fetch_assoc(mysqli_stmt_get_result($invoice_stmt));

            if($invoice){
                $new_paid = (float)$invoice['paid_amount'] - $amount;
                $new_due = (float)$invoice['due_amount'] + $amount;

                if($new_paid < 0){
                    $new_paid = 0;
                }

                $status = $new_due <= 0.01
                    ? 'paid'
                    : ($new_paid > 0 ? 'partial' : 'due');

                if($new_due <= 0.01){
                    $new_due = 0;
                }

                $update_sql = "UPDATE invoices
                               SET paid_amount=?,
                                   due_amount=?,
                                   payment_status=?
                               WHERE id=?
                               AND user_id=?";

                $update_stmt = mysqli_prepare($conn, $update_sql);
                mysqli_stmt_bind_param(
                    $update_stmt,
                    "ddsii",
                    $new_paid,
                    $new_due,
                    $status,
                    $invoice_id,
                    $user_id
                );
                mysqli_stmt_execute($update_stmt);
            }
        }elseif($opening_due_id > 0){
            $opening_sql = "SELECT amount, paid_amount, due_amount
                            FROM customer_opening_dues
                            WHERE id=?
                            AND user_id=?";

            $opening_stmt = mysqli_prepare($conn, $opening_sql);
            mysqli_stmt_bind_param($opening_stmt, "ii", $opening_due_id, $user_id);
            mysqli_stmt_execute($opening_stmt);
            $opening_due = mysqli_fetch_assoc(mysqli_stmt_get_result($opening_stmt));

            if($opening_due){
                $new_paid = (float)$opening_due['paid_amount'] - $amount;
                $new_due = (float)$opening_due['due_amount'] + $amount;

                if($new_paid < 0){
                    $new_paid = 0;
                }

                $status = $new_due <= 0.01
                    ? 'paid'
                    : ($new_paid > 0 ? 'partial' : 'due');

                if($new_due <= 0.01){
                    $new_due = 0;
                }

                $update_sql = "UPDATE customer_opening_dues
                               SET paid_amount=?,
                                   due_amount=?,
                                   status=?
                               WHERE id=?
                               AND user_id=?";

                $update_stmt = mysqli_prepare($conn, $update_sql);
                mysqli_stmt_bind_param(
                    $update_stmt,
                    "ddsii",
                    $new_paid,
                    $new_due,
                    $status,
                    $opening_due_id,
                    $user_id
                );
                mysqli_stmt_execute($update_stmt);
            }
        }

        $delete_sql = "DELETE FROM customer_payments
                       WHERE id=?
                       AND user_id=?";

        $delete_stmt = mysqli_prepare($conn, $delete_sql);
        mysqli_stmt_bind_param(
            $delete_stmt,
            "ii",
            $payment['id'],
            $user_id
        );
        mysqli_stmt_execute($delete_stmt);
    }
}
