<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/transaction_helper.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/invoice_charge_helper.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/customer_due_allocation_helper.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/pending_invoice_stock_helper.php';
require_once '../includes/product_category_helper.php';
require_once '../includes/staff_helper.php';
require_once '../includes/restaurant_table_helper.php';
require_once '../includes/invoice_reference_helper.php';

$user_id = $_SESSION['user_id'];
ensure_invoice_posting_columns($conn);
ensure_invoice_charge_columns($conn);
ensure_fifo_inventory_tables($conn);
ensure_staff_table($conn);
ensure_restaurant_tables_table($conn);
ensure_invoice_reference_columns($conn);
$table_system_is_enabled = table_system_enabled($conn, $user_id);

if($_SERVER['REQUEST_METHOD'] != 'POST'){
    header("Location: invoice_list.php");
    exit;
}

mysqli_begin_transaction($conn);

try{

    $invoice_id     = (int)$_POST['invoice_id'];
    $customer_id    = (int)$_POST['customer_id'];
    $invoice_date   = $_POST['invoice_date'];

    $grand_total    = (float)$_POST['grand_total'];
    $paid_amount    = (float)$_POST['paid_amount'];

    if($paid_amount < 0){
        throw new Exception("Paid Amount cannot be negative.");
    }

    $receive_wallet_id =
    (int)($_POST['receive_wallet_id']);
    $due_amount     = (float)$_POST['due_amount'];

    $payment_status = $_POST['payment_status'];
    $notes          = trim($_POST['notes']);
    $staff_id       = (int)($_POST['staff_id'] ?? 0);
    $restaurant_table_id = (int)($_POST['restaurant_table_id'] ?? 0);
    if(!$table_system_is_enabled){ $staff_id = 0; $restaurant_table_id = 0; }

    if($restaurant_table_id > 0 && $staff_id === 0){
        throw new Exception('Select the staff before selecting a table.');
    }
    if($staff_id > 0){
        $stmt = mysqli_prepare($conn, "SELECT id FROM staff WHERE id=? AND user_id=?");
        mysqli_stmt_bind_param($stmt, 'ii', $staff_id, $user_id); mysqli_stmt_execute($stmt);
        if(mysqli_num_rows(mysqli_stmt_get_result($stmt)) === 0){ throw new Exception('Selected staff is not valid.'); }
    }
    if($restaurant_table_id > 0){
        $stmt = mysqli_prepare($conn, "SELECT id FROM restaurant_tables WHERE id=? AND user_id=? AND staff_id=? AND status='active'");
        mysqli_stmt_bind_param($stmt, 'iii', $restaurant_table_id, $user_id, $staff_id); mysqli_stmt_execute($stmt);
        if(mysqli_num_rows(mysqli_stmt_get_result($stmt)) === 0){ throw new Exception('Selected table is not assigned to the selected staff.'); }
    }

    $invoice_sql = mysqli_query(
    $conn,
    "SELECT
    invoice_no,
    customer_id,
    paid_amount,
    receive_wallet_id,
    accounting_status
FROM invoices
WHERE id={$invoice_id}
AND user_id={$user_id}"
);

$invoice_row = mysqli_fetch_assoc($invoice_sql);

if(!$invoice_row){
    throw new Exception("Invoice Not Found.");
}

if(!can_modify_customer_invoice(
    $conn,
    $user_id,
    $invoice_id,
    (int)$invoice_row['customer_id']
)){
    throw new Exception(customer_invoice_modify_lock_message());
}

$invoice_no = $invoice_row['invoice_no'];

$old_customer_id = (int)$invoice_row['customer_id'];
$old_paid_amount = (float)$invoice_row['paid_amount'];
$old_wallet_id =
    (int)$invoice_row['receive_wallet_id'];
$is_posted = !invoice_is_pending($invoice_row);

if($is_posted && $old_customer_id > 0){
    rollback_customer_previous_due_payment_allocation(
        $conn,
        $user_id,
        $old_customer_id,
        $invoice_no
    );
}

$customer_balance_before_invoice = $customer_id > 0
    ? customer_signed_balance_total($conn, $user_id, $customer_id, $invoice_id)
    : 0;
$customer_balance_before_invoice += customer_source_invoice_all_payment_total(
    $conn,
    $user_id,
    $customer_id,
    $invoice_id,
    $invoice_no
);
$previous_due_total = max($customer_balance_before_invoice, 0);
$outstanding_amount_total = abs(min($customer_balance_before_invoice, 0));

if($customer_id === 0 && abs($paid_amount - $grand_total) > 0.01){
    throw new Exception("For instant customers, the Paid Amount must be equal to the Grand Total.");
}
// Existing customers may overpay; any extra amount becomes outstanding payable credit.

$current_invoice_cash_payment = min($paid_amount, $grand_total);
$applied_outstanding_amount = min(
    $outstanding_amount_total,
    max($grand_total - $current_invoice_cash_payment, 0)
);
$current_invoice_payment = min(
    $current_invoice_cash_payment + $applied_outstanding_amount,
    $grand_total
);
$remaining_after_current = max(
    ($paid_amount + $applied_outstanding_amount) - $current_invoice_payment,
    0
);
$previous_due_payment = min($remaining_after_current, $previous_due_total);
$outstanding_payable = max($remaining_after_current - $previous_due_payment, 0);
$due_amount = $grand_total - $current_invoice_payment;

if($outstanding_payable > 0.01){
    $due_amount = -$outstanding_payable;
}

if($due_amount <= 0){
    $payment_status = 'paid';
    if(abs($due_amount) <= 0.01){
        $due_amount = 0;
    }
}elseif($current_invoice_cash_payment > 0 || $applied_outstanding_amount > 0){
    $payment_status = 'partial';
}else{
    $payment_status = 'due';
}

    /*
    ==========================================
    OLD STOCK RESTORE
    ==========================================
    */

    $sql = "SELECT id, product_id, quantity
            FROM invoice_items
            WHERE invoice_id=?";

    $stmt = mysqli_prepare($conn,$sql);

    mysqli_stmt_bind_param(
        $stmt,
        "i",
        $invoice_id
    );

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    while($row = mysqli_fetch_assoc($result)){

        if(!$is_posted){
            continue;
        }

        if(!product_uses_stock($conn, (int)$row['product_id'], $user_id)){
            continue;
        }

        fifo_inventory_restore_invoice_item($conn, (int)$row['id']);

        $sql2 = "UPDATE products
                 SET current_stock =
                     current_stock + ?
                 WHERE id=?
                 AND user_id=?";

        $stmt2 = mysqli_prepare($conn,$sql2);

        mysqli_stmt_bind_param(
            $stmt2,
            "dii",
            $row['quantity'],
            $row['product_id'],
            $user_id
        );

        mysqli_stmt_execute($stmt2);
    }

    /*
    ==========================================
    DELETE OLD DATA
    ==========================================
    */

    mysqli_query(
    $conn,
    "DELETE FROM customer_payments
     WHERE invoice_id={$invoice_id}"
    );

    mysqli_query(
        $conn,
        "DELETE FROM invoice_items
         WHERE invoice_id={$invoice_id}"
    );

    mysqli_query(
        $conn,
        "DELETE FROM invoice_charges
         WHERE invoice_id={$invoice_id}"
    );

mysqli_query(

    $conn,

    "DELETE FROM stock_transactions

     WHERE user_id={$user_id}

     AND reference_no='".$invoice_no."'

     AND transaction_type IN ('stock_out','stock_in')"

);

    /*
    ==========================================
    UPDATE INVOICE
    ==========================================
    */

$sql = "UPDATE invoices SET

            customer_id=?,
            receive_wallet_id=?,
            invoice_date=?,
            total_amount=?,
            notes=?,
            staff_id=?,
            restaurant_table_id=?,
            paid_amount=?,
            due_amount=?,
            payment_status=?

        WHERE id=?
        AND user_id=?";

    $stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(

    $stmt,

    "iisdsiiddsii",

        $customer_id,
        $receive_wallet_id,
        $invoice_date,
        $grand_total,
        $notes,
        $staff_id,
        $restaurant_table_id,
        $paid_amount,
        $due_amount,
        $payment_status,
        $invoice_id,
        $user_id

);

    mysqli_stmt_execute($stmt);

    /*
    ==========================================
    SAVE NEW ITEMS
    ==========================================
    */

    $product_ids = $_POST['product_id'];
    $qtys        = $_POST['qty'];
    $prices      = $_POST['price'];
    $totals      = $_POST['line_total'];
    $positive_qty_totals = [];

    foreach($product_ids as $key=>$product_id){

        if(empty($product_id)){
            continue;
        }

        $product_id = (int)$product_id;
        $line_qty = (float)($qtys[$key] ?? 0);

        if($line_qty > 0){
            if(!isset($positive_qty_totals[$product_id])){
                $positive_qty_totals[$product_id] = 0.0;
            }

            $positive_qty_totals[$product_id] += $line_qty;
        }
    }

    foreach($positive_qty_totals as $product_id => $requested_qty){
        $product_snapshot = product_stock_snapshot_for_invoice(
            $conn,
            $user_id,
            (int)$product_id,
            $invoice_id
        );

        if(!$product_snapshot){
            throw new Exception("Product not found.");
        }

        if(!$product_snapshot['is_stock_product']){
            continue;
        }

        if($requested_qty > ((float)$product_snapshot['available_stock'] + 0.0001)){
            throw new Exception("Not enough available stock for selected product. Pending voucher reserved this product.");
        }
    }

    foreach($product_ids as $key=>$product_id){

        if(empty($product_id)){
            continue;
        }

        $product_id = (int)$product_id;
        $qty   = (int)$qtys[$key];
        $price = (float)$prices[$key];
        $total = (float)$totals[$key];

        if($qty === 0){

            throw new Exception(
                "Quantity cannot be zero."
            );

        }

        /*
==========================================
CHECK AVAILABLE STOCK
==========================================
*/

$sql = "SELECT current_stock
        FROM products
        WHERE id=?
        AND user_id=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $product_id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$product = mysqli_fetch_assoc($result);

if(!$product){

    throw new Exception(
        "Product not found."
    );

}

$is_stock_product = product_uses_stock($conn, $product_id, $user_id);

        $sql = "INSERT INTO invoice_items(

                    invoice_id,
                    product_id,
                    quantity,
                    unit_price,
                    total_price

                )

                VALUES(

                    ?,
                    ?,
                    ?,
                    ?,
                    ?

                )";

        $stmt = mysqli_prepare($conn,$sql);

        mysqli_stmt_bind_param(

            $stmt,

            "iiddd",

            $invoice_id,
            $product_id,
            $qty,
            $price,
            $total

        );

        mysqli_stmt_execute($stmt);

        $invoice_item_id = mysqli_insert_id($conn);

        if($is_posted && $is_stock_product){

        /*
        STOCK DEDUCT AGAIN
        */

        $sql = "UPDATE products

                SET current_stock =
                    current_stock - ?

                WHERE id=?
                AND user_id=?";

        $stmt = mysqli_prepare($conn,$sql);

        mysqli_stmt_bind_param(

            $stmt,

            "dii",

            $qty,
            $product_id,
            $user_id

        );

        mysqli_stmt_execute($stmt);

        /* -----------------------------
   STOCK TRANSACTION
------------------------------ */

$stock_transaction_type = $qty < 0 ? 'stock_in' : 'stock_out';
$stock_quantity = abs($qty);

$sql = "INSERT INTO stock_transactions(

            user_id,
            product_id,
            transaction_type,
            quantity,
            note,
            txn_date,
            reference_no

        )

        VALUES(

            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?

        )";

$stmt = mysqli_prepare($conn,$sql);

$note = $qty < 0 ? "Sales Invoice Return" : "Sales Invoice";

mysqli_stmt_bind_param(

    $stmt,

    "iisdsss",

    $user_id,
    $product_id,
    $stock_transaction_type,
    $stock_quantity,
    $note,
    $invoice_date,
    $invoice_no

);

mysqli_stmt_execute($stmt);

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
                $invoice_no,
                $invoice_date
            )){
                throw new Exception("FIFO return batch failed.");
            }
        }

        }

    }

    /*
    ==========================================
    SAVE CHARGES
    ==========================================
    */

    if(isset($_POST['charge_id'])){

        foreach($_POST['charge_id'] as $key=>$charge_id){

            $charge_id = (int)$charge_id;

            $amount =
                (float)
                ($_POST['charge_amount'][$key] ?? 0);

            if($charge_id <= 0 || $amount <= 0){
                continue;
            }

            // A charge must belong to this company.  The type may later be
            // hidden/inactivated, but charges already saved on this invoice
            // remain editable when the invoice itself can be edited.
            $charge_check_stmt = mysqli_prepare(
                $conn,
                "SELECT id
                 FROM invoice_charge_types
                 WHERE id=?
                 AND user_id=?
                 LIMIT 1"
            );

            if(!$charge_check_stmt){
                throw new Exception("Invoice charge could not be validated.");
            }

            mysqli_stmt_bind_param($charge_check_stmt, "ii", $charge_id, $user_id);
            mysqli_stmt_execute($charge_check_stmt);
            $charge_check = mysqli_fetch_assoc(mysqli_stmt_get_result($charge_check_stmt));

            if(!$charge_check){
                continue;
            }

            $sql = "INSERT INTO invoice_charges(

                        invoice_id,
                        charge_type_id,
                        amount

                    )

                    VALUES(

                        ?,
                        ?,
                        ?

                    )";

            $stmt = mysqli_prepare(
                $conn,
                $sql
            );

            mysqli_stmt_bind_param(

                $stmt,

                "iid",

                $invoice_id,
                $charge_id,
                $amount

            );

            mysqli_stmt_execute($stmt);

        }

    }

    /*
    ==========================================
    CUSTOMER PAYMENT
    ==========================================
    */

    if(
        $is_posted &&
        $customer_id > 0 &&
        $paid_amount > 0
    ){

        $payment_note =
        'Invoice Payment - '.$invoice_no;

        if($current_invoice_cash_payment > 0){

        $sql = "INSERT INTO customer_payments(

                    user_id,
                    customer_id,
                    invoice_id,
                    amount,
                    payment_date,
                    note

                )

                VALUES(

                    ?,
                    ?,
                    ?,
                    ?,
                    CURDATE(),
                    ?

                )";

        $stmt = mysqli_prepare(
            $conn,
            $sql
        );

        mysqli_stmt_bind_param(

            $stmt,

            "iiids",

            $user_id,
            $customer_id,
            $invoice_id,
            $current_invoice_cash_payment,
            $payment_note

        );

        mysqli_stmt_execute($stmt);

        }

    }

    if(
        $is_posted &&
        $customer_id > 0 &&
        $previous_due_payment > 0.01
    ){

        allocate_customer_previous_due_payment(
            $conn,
            $user_id,
            $customer_id,
            $invoice_id,
            $invoice_no,
            $previous_due_payment
        );

    }

    if(
        $is_posted &&
        $customer_id > 0 &&
        $outstanding_payable > 0.01
    ){

        $payment_note =
        'Outstanding Amount - '.$invoice_no;

        $sql = "INSERT INTO customer_payments(

                    user_id,
                    customer_id,
                    invoice_id,
                    amount,
                    payment_date,
                    note

                )

                VALUES(

                    ?,
                    ?,
                    ?,
                    ?,
                    CURDATE(),
                    ?

                )";

        $stmt = mysqli_prepare(
            $conn,
            $sql
        );

        mysqli_stmt_bind_param(

            $stmt,

            "iiids",

            $user_id,
            $customer_id,
            $invoice_id,
            $outstanding_payable,
            $payment_note

        );

        mysqli_stmt_execute($stmt);

    }

/*
==========================================
UPDATE WALLET
==========================================
*/

if($is_posted){

if($old_wallet_id == $receive_wallet_id){

    /*
    Same Wallet
    */

    $difference = $paid_amount - $old_paid_amount;

    if($difference != 0){

        if($difference > 0){

            credit_wallet(
                $conn,
                $receive_wallet_id,
                $user_id,
                $difference
            );

        }else{

            debit_wallet(
                $conn,
                $receive_wallet_id,
                $user_id,
                abs($difference)
            );

        }

    }

}else{

    /*
    Return Old Amount
    */

    if($old_paid_amount > 0){

        debit_wallet(
            $conn,
            $old_wallet_id,
            $user_id,
            $old_paid_amount
        );

    }

    /*
    Add New Amount
    */

    if($paid_amount > 0){

        credit_wallet(
            $conn,
            $receive_wallet_id,
            $user_id,
            $paid_amount
        );

    }

}

mysqli_query(
    $conn,
    "DELETE FROM transactions
     WHERE user_id={$user_id}
     AND transaction_type='sales_invoice'
     AND reference_id={$invoice_id}"
);

if($paid_amount > 0){

    record_wallet_transaction(
        $conn,
        $invoice_no,
        $user_id,
        $receive_wallet_id,
        'sales_invoice',
        $invoice_id,
        $paid_amount,
        'Sales Invoice - ' . $invoice_no,
        $invoice_date
    );

}

}

    mysqli_commit($conn);
    $_SESSION['success'] =
    "Invoice Updated Successfully.";

    header(
        "Location:view_invoice.php?id=".$invoice_id
    );

    exit;

}catch(Exception $e){

    mysqli_rollback($conn);

    $_SESSION['error'] = $e->getMessage();

    header("Location:edit_invoice.php?id=" . $invoice_id);
    exit;

}
