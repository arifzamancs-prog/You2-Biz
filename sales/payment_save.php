<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/transaction_helper.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/customer_due_allocation_helper.php';

$user_id = (int)$_SESSION['user_id'];
$stock_due_scope = stock_customer_scope($conn);
ensure_invoice_posting_columns($conn);
ensure_customer_opening_due_tables($conn);
$payment_mode = $_POST['payment_mode'] ?? 'invoice';
$amount = (float)($_POST['amount'] ?? 0);
$receive_wallet_id = (int)($_POST['receive_wallet_id'] ?? 0);
$payment_date = date('Y-m-d');
$extra_note = trim(mb_substr((string)($_POST['note'] ?? ''), 0, 255));
$print_receipt = !empty($_POST['print_receipt']);
// E-shop "Received" opens this from the invoice list and returns there.
$return_to_list = ($_POST['return_to'] ?? '') === 'invoice_list';
$received_invoice_no = '';
// A registered customer's due is company-wide: it can be cleared from any branch.
$customer_due_scope = '';
$collect_branch_id = (int)stock_customer_branch($conn);

mysqli_begin_transaction($conn);

try{

    stock_require_wallet($conn, $receive_wallet_id, $user_id, stock_customer_branch($conn));
    if(!is_finite($amount) || $amount < 0){
        throw new Exception("Amount cannot be negative.");
    }

    if($amount <= 0){
        throw new Exception("Invalid Amount");
    }

    // One receipt number ties the wallet entry to every due entry this payment clears.
    $receipt_no = generate_short_unique_txn_no($conn, 'RCV');
    $remaining_amount = $amount;
    $first_payment_id = 0;
    $wallet_note = '';
    $wallet_reference_id = 0;

    if($payment_mode === 'customer'){
        $customer_id = (int)($_POST['customer_id'] ?? 0);

        $sql = "SELECT id, customer_name
                FROM customers
                WHERE id=?
                AND user_id=? FOR UPDATE";

        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ii", $customer_id, $user_id);
        mysqli_stmt_execute($stmt);
        $customer = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if(!$customer){
            throw new Exception("Customer Not Found");
        }

        $total_due_sql = "SELECT GREATEST(
                              COALESCE(inv.total_sales,0) + COALESCE(open_due.total_sales,0) - COALESCE(pay.total_paid,0),
                              0
                          ) AS total_due
                          FROM customers c
                          LEFT JOIN (
                              SELECT customer_id, SUM(total_amount) AS total_sales
                              FROM invoices
                              WHERE user_id=? {$customer_due_scope}
                              AND accounting_status='posted'
                              GROUP BY customer_id
                          ) inv
                              ON inv.customer_id = c.id
                          LEFT JOIN (
                              SELECT customer_id, SUM(amount) AS total_sales
                              FROM customer_opening_dues
                              WHERE user_id=? {$customer_due_scope}
                              GROUP BY customer_id
                          ) open_due
                              ON open_due.customer_id = c.id
                          LEFT JOIN (
                              SELECT customer_id, SUM(amount) AS total_paid
                              FROM customer_payments
                              WHERE user_id=? {$customer_due_scope}
                              GROUP BY customer_id
                          ) pay
                              ON pay.customer_id = c.id
                          WHERE c.id=?
                          AND c.user_id=?";

        $stmt = mysqli_prepare($conn, $total_due_sql);
        mysqli_stmt_bind_param(
            $stmt,
            "iiiii",
            $user_id,
            $user_id,
            $user_id,
            $customer_id,
            $user_id
        );
        mysqli_stmt_execute($stmt);
        $due_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        $total_due = (float)($due_row['total_due'] ?? 0);

        if($total_due <= 0){
            throw new Exception("No due entry found.");
        }

        if($amount > $total_due){
            throw new Exception("Payment cannot exceed Due Amount");
        }

        $sql = "SELECT id, branch_id, invoice_no, invoice_date, paid_amount, due_amount
                FROM invoices
                WHERE customer_id=?
                AND user_id=? {$customer_due_scope}
                AND accounting_status='posted'
                AND due_amount > 0
                ORDER BY invoice_date ASC, id ASC FOR UPDATE";

        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ii", $customer_id, $user_id);
        mysqli_stmt_execute($stmt);
        $invoices = mysqli_stmt_get_result($stmt);

        $due_entries = [];

        while($invoice = mysqli_fetch_assoc($invoices)){
            $due_entries[] = [
                'entry_type' => 'invoice',
                'id' => (int)$invoice['id'],
                'branch_id' => (int)$invoice['branch_id'],
                'reference_no' => $invoice['invoice_no'],
                'entry_date' => $invoice['invoice_date'],
                'paid_amount' => (float)$invoice['paid_amount'],
                'due_amount' => (float)$invoice['due_amount'],
            ];
        }

        $opening_stmt = mysqli_prepare(
            $conn,
            "SELECT id, branch_id, due_no, entry_date, paid_amount, due_amount
             FROM customer_opening_dues
             WHERE customer_id=?
             AND user_id=? {$customer_due_scope}
             AND due_amount > 0
             ORDER BY entry_date ASC, id ASC FOR UPDATE"
        );
        mysqli_stmt_bind_param($opening_stmt, "ii", $customer_id, $user_id);
        mysqli_stmt_execute($opening_stmt);
        $opening_rows = mysqli_stmt_get_result($opening_stmt);

        while($opening_due = mysqli_fetch_assoc($opening_rows)){
            $due_entries[] = [
                'entry_type' => 'opening_due',
                'id' => (int)$opening_due['id'],
                'branch_id' => (int)$opening_due['branch_id'],
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
            if($remaining_amount <= 0){
                break;
            }

            $entry_due = (float)$entry['due_amount'];
            $pay_amount = min($remaining_amount, $entry_due);
            $new_paid = (float)$entry['paid_amount'] + $pay_amount;
            $new_due = $entry_due - $pay_amount;
            $status = $new_due <= 0 ? 'paid' : 'partial';

            if($new_due <= 0){
                $new_due = 0;
            }

            if($entry['entry_type'] === 'invoice'){
                $note = 'Due Collection - ' . $entry['reference_no'] . ($extra_note !== '' ? ' - ' . $extra_note : '');

                $sql = "INSERT INTO customer_payments (branch_id,
                            user_id,
                            customer_id,
                            invoice_id,
                            amount,
                            payment_date,
                            note,
                            receipt_no
                        ) VALUES (" . (int)($entry['branch_id'] > 0 ? $entry['branch_id'] : $collect_branch_id) . ",
                            ?,
                            ?,
                            ?,
                            ?,
                            CURDATE(),
                            ?,
                            ?
                        )";

                $payment_stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param(
                    $payment_stmt,
                    "iiidss",
                    $user_id,
                    $customer_id,
                    $entry['id'],
                    $pay_amount,
                    $note,
                    $receipt_no
                );
                mysqli_stmt_execute($payment_stmt);
            }else{
                $note = 'Previous Due Collection - ' . $entry['reference_no'] . ($extra_note !== '' ? ' - ' . $extra_note : '');

                $sql = "INSERT INTO customer_payments (branch_id,
                            user_id,
                            customer_id,
                            invoice_id,
                            opening_due_id,
                            amount,
                            payment_date,
                            note,
                            receipt_no
                        ) VALUES (" . (int)($entry['branch_id'] > 0 ? $entry['branch_id'] : $collect_branch_id) . ",
                            ?,
                            ?,
                            NULL,
                            ?,
                            ?,
                            CURDATE(),
                            ?,
                            ?
                        )";

                $payment_stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param(
                    $payment_stmt,
                    "iiidss",
                    $user_id,
                    $customer_id,
                    $entry['id'],
                    $pay_amount,
                    $note,
                    $receipt_no
                );
                mysqli_stmt_execute($payment_stmt);
            }

            if($first_payment_id === 0){
                $first_payment_id = mysqli_insert_id($conn);
            }

            if($entry['entry_type'] === 'invoice'){
                $sql = "UPDATE invoices
                        SET paid_amount=?,
                            due_amount=?,
                            payment_status=?
                        WHERE id=?
                        AND user_id=? {$customer_due_scope}";

                $update_stmt = mysqli_prepare($conn, $sql);
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
                $sql = "UPDATE customer_opening_dues
                        SET paid_amount=?,
                            due_amount=?,
                            status=?
                        WHERE id=?
                        AND user_id=? {$customer_due_scope}";

                $update_stmt = mysqli_prepare($conn, $sql);
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
        }

        $wallet_note = 'Customer Due Collection - ' . $customer['customer_name'];
        $wallet_reference_id = $first_payment_id > 0 ? $first_payment_id : $customer_id;

    }else{
        $invoice_id = (int)($_POST['invoice_id'] ?? 0);

        $sql = "SELECT *
                FROM invoices
                WHERE id=?
                AND user_id=? {$stock_due_scope}
                AND accounting_status='posted'
                AND due_amount > 0 FOR UPDATE";

        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ii", $invoice_id, $user_id);
        mysqli_stmt_execute($stmt);
        $invoice = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if(!$invoice){
            throw new Exception("Invoice Not Found");
        }

        if($amount > (float)$invoice['due_amount']){
            throw new Exception("Payment cannot exceed Due Amount");
        }

        $new_paid = (float)$invoice['paid_amount'] + $amount;
        $new_due = (float)$invoice['due_amount'] - $amount;
        $status = $new_due <= 0 ? 'paid' : 'partial';

        if($new_due <= 0){
            $new_due = 0;
        }

        $payment_id = 0;
        $note = 'Due Collection - ' . $invoice['invoice_no'] . ($extra_note !== '' ? ' - ' . $extra_note : '');

        if((int)$invoice['customer_id'] > 0){
            $sql = "INSERT INTO customer_payments (branch_id,
                        user_id,
                        customer_id,
                        invoice_id,
                        amount,
                        payment_date,
                        note,
                        receipt_no
                    ) VALUES (" . (int)(function_exists('stock_customer_branch') ? stock_customer_branch($conn) : selected_branch_id($conn, true)) . ",
                        ?,
                        ?,
                        ?,
                        ?,
                        CURDATE(),
                        ?,
                        ?
                    )";

            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param(
                $stmt,
                "iiidss",
                $user_id,
                $invoice['customer_id'],
                $invoice_id,
                $amount,
                $note,
                $receipt_no
            );
            mysqli_stmt_execute($stmt);
            $payment_id = mysqli_insert_id($conn);
        }

        $sql = "UPDATE invoices
                SET paid_amount=?,
                    due_amount=?,
                    payment_status=?
                WHERE id=?
                AND user_id=? {$stock_due_scope}";

        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param(
            $stmt,
            "ddsii",
            $new_paid,
            $new_due,
            $status,
            $invoice_id,
            $user_id
        );
        mysqli_stmt_execute($stmt);

        $wallet_note = $note;
        $wallet_reference_id = $payment_id > 0 ? $payment_id : $invoice_id;
        $received_invoice_no = (string)$invoice['invoice_no'];
    }

    if(abs($remaining_amount) > 0.01 && $payment_mode === 'customer'){
        throw new Exception("Payment allocation failed.");
    }

    $sql = "UPDATE wallets
            SET balance = balance + ?
            WHERE id=?
            AND user_id=? {$stock_due_scope}";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param(
        $stmt,
        "dii",
        $amount,
        $receive_wallet_id,
        $user_id
    );

    if(!mysqli_stmt_execute($stmt)){
        throw new Exception(mysqli_stmt_error($stmt));
    }

    record_wallet_transaction(
        $conn,
        $receipt_no,
        $user_id,
        $receive_wallet_id,
        'receive_payment',
        $wallet_reference_id,
        $amount,
        $wallet_note,
        $payment_date,
        $collect_branch_id
    );

    mysqli_commit($conn);

    if($return_to_list && !$print_receipt){
        header('Location: invoice_list.php?success=1&received=' . urlencode($received_invoice_no));
        exit;
    }

    header("Location:" . ($print_receipt
        ? "print_money_receipt.php?no=" . urlencode($receipt_no) . "&back=1"
        : "receive_payment.php?success=1&receipt=" . urlencode($receipt_no)));
    exit;

}catch(Throwable $e){

    mysqli_rollback($conn);

    $_SESSION['error'] = $e->getMessage();

    header($return_to_list ? 'Location: invoice_list.php?error=' . urlencode($e->getMessage()) : "Location:receive_payment.php");
    exit;
}
