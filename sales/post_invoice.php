<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/transaction_helper.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/customer_due_allocation_helper.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_category_helper.php';

$user_id = (int)$_SESSION['user_id'];
$invoice_id = (int)($_GET['id'] ?? 0);
$reload_parent = isset($_GET['reload_parent'])
    ? trim((string)$_GET['reload_parent'])
    : '';

ensure_invoice_posting_columns($conn);
ensure_fifo_inventory_tables($conn);

if($invoice_id <= 0){
    die("Invalid Invoice");
}

mysqli_begin_transaction($conn);

try{
    $sql = "SELECT *
            FROM invoices
            WHERE id=?
            AND user_id=?
            LIMIT 1";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $invoice_id, $user_id);
    mysqli_stmt_execute($stmt);
    $invoice = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if(!$invoice){
        throw new Exception("Invoice Not Found");
    }

    if(!invoice_is_pending($invoice)){
        mysqli_commit($conn);
        $print_redirect = "print_invoice.php?id=" . $invoice_id;

        if($reload_parent !== ''){
            $print_redirect .= "&reload_parent=" . urlencode($reload_parent);
        }

        header("Location: " . $print_redirect);
        exit;
    }

    $sql = "SELECT *
            FROM invoice_items
            WHERE invoice_id=?";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $invoice_id);
    mysqli_stmt_execute($stmt);
    $items = mysqli_stmt_get_result($stmt);

    while($item = mysqli_fetch_assoc($items)){
        $invoice_item_id = (int)$item['id'];
        $product_id = (int)$item['product_id'];
        $qty = (int)$item['quantity'];

        if($qty === 0){
            throw new Exception("Quantity cannot be zero.");
        }

        $sql = "SELECT current_stock
                FROM products
                WHERE id=?
                AND user_id=?";

        $product_stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($product_stmt, "ii", $product_id, $user_id);
        mysqli_stmt_execute($product_stmt);
        $product = mysqli_fetch_assoc(mysqli_stmt_get_result($product_stmt));

        if(!$product){
            throw new Exception("Product Not Found.");
        }

        if(!product_uses_stock($conn, $product_id, $user_id)){
            continue;
        }

        if($qty > (float)$product['current_stock']){
            throw new Exception("Insufficient Stock.");
        }

        $sql = "UPDATE products
                SET current_stock = current_stock - ?
                WHERE id=?
                AND user_id=?";

        $stock_stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stock_stmt, "dii", $qty, $product_id, $user_id);
        mysqli_stmt_execute($stock_stmt);

        $stock_transaction_type = $qty < 0 ? 'stock_in' : 'stock_out';
        $stock_quantity = abs($qty);
        $note = $qty < 0 ? "Sales Invoice Return" : "Sales Invoice";

        $sql = "INSERT INTO stock_transactions
                (
                    user_id,
                    product_id,
                    transaction_type,
                    quantity,
                    note,
                    txn_date,
                    reference_no
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    CURDATE(),
                    ?
                )";

        $stock_txn_stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param(
            $stock_txn_stmt,
            "iisdss",
            $user_id,
            $product_id,
            $stock_transaction_type,
            $stock_quantity,
            $note,
            $invoice['invoice_no']
        );
        mysqli_stmt_execute($stock_txn_stmt);

        if($qty > 0){
            $allocation = fifo_inventory_allocate_sale(
                $conn,
                $user_id,
                $invoice_item_id,
                $product_id,
                $qty
            );

            if(!$allocation['success']){
                throw new Exception($allocation['error'] ?? 'FIFO allocation failed.');
            }
        }elseif($qty < 0){
            $product_cost_stmt = mysqli_prepare(
                $conn,
                "SELECT purchase_price
                 FROM products
                 WHERE id=?
                 AND user_id=?"
            );

            mysqli_stmt_bind_param($product_cost_stmt, "ii", $product_id, $user_id);
            mysqli_stmt_execute($product_cost_stmt);
            $product_cost_row = mysqli_fetch_assoc(mysqli_stmt_get_result($product_cost_stmt));
            $return_unit_cost = (float)($product_cost_row['purchase_price'] ?? 0);

            if(!fifo_inventory_add_return_batch(
                $conn,
                $user_id,
                $invoice_item_id,
                $product_id,
                $qty,
                $return_unit_cost,
                $invoice['invoice_no'],
                $invoice['invoice_date']
            )){
                throw new Exception("FIFO return batch failed.");
            }
        }
    }

    $paid_amount = (float)$invoice['paid_amount'];
    $invoice_total = (float)$invoice['total_amount'];
    $current_invoice_cash_payment = 0.0;
    $previous_due_payment = 0.0;
    $outstanding_payable = 0.0;
    $new_due_amount = (float)$invoice['due_amount'];
    $new_payment_status = $invoice['payment_status'];

    if($paid_amount < 0){
        throw new Exception("Paid Amount cannot be negative.");
    }

    if($paid_amount > 0 && (int)$invoice['customer_id'] <= 0){
        if($paid_amount > $invoice_total + 0.01){
            throw new Exception("Paid Amount cannot exceed current sale.");
        }
    }

    if((int)$invoice['customer_id'] > 0){
        $customer_balance_before_invoice = customer_signed_balance_total(
            $conn,
            $user_id,
            (int)$invoice['customer_id'],
            $invoice_id
        );
        $previous_due_total = max($customer_balance_before_invoice, 0);
        $outstanding_amount_total = abs(min($customer_balance_before_invoice, 0));
        $current_invoice_cash_payment = min($paid_amount, $invoice_total);
        $applied_outstanding_amount = min(
            $outstanding_amount_total,
            max($invoice_total - $current_invoice_cash_payment, 0)
        );
        $current_invoice_payment = min(
            $current_invoice_cash_payment + $applied_outstanding_amount,
            $invoice_total
        );
        $remaining_after_current = max(
            ($paid_amount + $applied_outstanding_amount) - $current_invoice_payment,
            0
        );
        $previous_due_payment = min($remaining_after_current, $previous_due_total);
        $outstanding_payable = max($remaining_after_current - $previous_due_payment, 0);
        $new_due_amount = $invoice_total - $current_invoice_payment;

        if($outstanding_payable > 0.01){
            $new_due_amount = -$outstanding_payable;
        }

        if($new_due_amount <= 0){
            $new_payment_status = 'paid';
            if(abs($new_due_amount) <= 0.01){
                $new_due_amount = 0;
            }
        }elseif($paid_amount > 0 || $applied_outstanding_amount > 0){
            $new_payment_status = 'partial';
        }else{
            $new_payment_status = 'due';
        }
    }

    if($paid_amount > 0 && (int)$invoice['customer_id'] > 0){
        $payment_note = "Invoice Payment - " . $invoice['invoice_no'];

        if($current_invoice_cash_payment > 0.01){
            $sql = "INSERT INTO customer_payments
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

            $payment_stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param(
                $payment_stmt,
                "iiids",
                $user_id,
                $invoice['customer_id'],
                $invoice_id,
                $current_invoice_cash_payment,
                $payment_note
            );
            mysqli_stmt_execute($payment_stmt);
        }

        if($previous_due_payment > 0.01){
            allocate_customer_previous_due_payment(
                $conn,
                $user_id,
                (int)$invoice['customer_id'],
                $invoice_id,
                $invoice['invoice_no'],
                $previous_due_payment
            );
        }

        if($outstanding_payable > 0.01){
            $advance_note = "Outstanding Amount - " . $invoice['invoice_no'];
            $sql = "INSERT INTO customer_payments
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

            $payment_stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param(
                $payment_stmt,
                "iiids",
                $user_id,
                $invoice['customer_id'],
                $invoice_id,
                $outstanding_payable,
                $advance_note
            );
            mysqli_stmt_execute($payment_stmt);
        }
    }

    if($paid_amount > 0){
        if((int)$invoice['receive_wallet_id'] <= 0){
            throw new Exception("Receive wallet is required.");
        }

        $sql = "UPDATE wallets
                SET balance = balance + ?
                WHERE id=?
                AND user_id=?";

        $wallet_stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param(
            $wallet_stmt,
            "dii",
            $paid_amount,
            $invoice['receive_wallet_id'],
            $user_id
        );
        mysqli_stmt_execute($wallet_stmt);

        record_wallet_transaction(
            $conn,
            $invoice['invoice_no'],
            $user_id,
            (int)$invoice['receive_wallet_id'],
            'sales_invoice',
            $invoice_id,
            $paid_amount,
            'Sales Invoice - ' . $invoice['invoice_no'],
            date('Y-m-d')
        );
    }

    $sql = "UPDATE invoices
            SET due_amount=?,
                payment_status=?,
                accounting_status='posted'
            WHERE id=?
            AND user_id=?";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param(
        $stmt,
        "dsii",
        $new_due_amount,
        $new_payment_status,
        $invoice_id,
        $user_id
    );
    mysqli_stmt_execute($stmt);

    mysqli_commit($conn);

    $print_redirect = "print_invoice.php?id=" . $invoice_id;

    if($reload_parent !== ''){
        $print_redirect .= "&reload_parent=" . urlencode($reload_parent);
    }

    header("Location: " . $print_redirect);
    exit;

}catch(Exception $e){
    mysqli_rollback($conn);
    header(
        "Location: invoice_list.php?error=" .
        urlencode($e->getMessage())
    );
    exit;
}
