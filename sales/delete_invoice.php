<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/branch_context_helper.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/customer_due_allocation_helper.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_category_helper.php';
require_once '../includes/sales_refund_helper.php';

$user_id = $_SESSION['user_id'];
ensure_invoice_posting_columns($conn);
ensure_fifo_inventory_tables($conn);

$invoice_id = (int)($_GET['id'] ?? 0);
require_branch_record_access($conn, 'invoices', $invoice_id, 'invoice_list.php');

if($invoice_id <= 0){
    die('Invalid Invoice');
}

/*
------------------------------------------
GET INVOICE
------------------------------------------
*/

$sql = "SELECT
                invoice_no,
                customer_id,
                paid_amount,
                receive_wallet_id,
                accounting_status
            FROM invoices
        WHERE id=?
        AND user_id=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $invoice_id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$invoice = mysqli_fetch_assoc($result);

if(!$invoice){

    die("Invoice Not Found");

}

if(!can_modify_customer_invoice(
    $conn,
    $user_id,
    $invoice_id,
    (int)$invoice['customer_id']
)){
    header(
        "Location: invoice_list.php?error=" .
        urlencode(customer_invoice_modify_lock_message())
    );
    exit;
}

$invoice_no = $invoice['invoice_no'];
$customer_id = (int)$invoice['customer_id'];
$is_posted = !invoice_is_pending($invoice);

$paid_amount = (float)$invoice['paid_amount'];
$receive_wallet_id =
    (int)$invoice['receive_wallet_id'];

// Validate the cash reversal before touching the invoice, stock, or payment
// records. A sale cannot be deleted after its collected money has already
// been spent from the receiving wallet.
if ($is_posted && $paid_amount > 0) {
    $wallet_stmt = mysqli_prepare($conn, 'SELECT balance FROM wallets WHERE id=? AND user_id=? AND status=\'active\' LIMIT 1');
    mysqli_stmt_bind_param($wallet_stmt, 'ii', $receive_wallet_id, $user_id);
    mysqli_stmt_execute($wallet_stmt);
    $wallet = mysqli_fetch_assoc(mysqli_stmt_get_result($wallet_stmt));
    if (!$wallet || (float)$wallet['balance'] + 0.0001 < $paid_amount) {
        header('Location: invoice_list.php?error=' . urlencode('Invoice cannot be deleted because the received payment is no longer available in its wallet.'));
        exit;
    }
}

mysqli_begin_transaction($conn);

try {
    // Serialize deletion with refunds before touching items or money.
    sales_refund_query($conn,'SELECT id FROM invoices WHERE id=? AND user_id=? FOR UPDATE','ii',[$invoice_id,(int)$user_id]);
    sales_refund_protect_invoice($conn,(int)$user_id,$invoice_id);

/*
|--------------------------------------------------
| Get Invoice Items
|--------------------------------------------------
*/

$sql = "
SELECT *
FROM invoice_items
WHERE invoice_id=?
";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $invoice_id
);

mysqli_stmt_execute($stmt);

$items = mysqli_stmt_get_result($stmt);

/*
|--------------------------------------------------
| Restore Stock
|--------------------------------------------------
*/

while($item = mysqli_fetch_assoc($items)){

    if(!$is_posted){
        continue;
    }

    if(!product_uses_stock($conn, (int)$item['product_id'], $user_id)){
        continue;
    }

    fifo_inventory_restore_invoice_item($conn, (int)$item['id']);

    mysqli_query(
        $conn,
        "UPDATE products
         SET current_stock =
         current_stock + {$item['quantity']}
         WHERE id={$item['product_id']}"
    );

}

/*
|--------------------------------------------------
| Delete Invoice Items
|--------------------------------------------------
*/

mysqli_query(
    $conn,
    "DELETE FROM invoice_items
     WHERE invoice_id='$invoice_id'"
);

/*
|--------------------------------------------------
| Delete Charges
|--------------------------------------------------
*/

mysqli_query(
    $conn,
    "DELETE FROM invoice_charges
     WHERE invoice_id='$invoice_id'"
);

/*
|--------------------------------------------------
| Delete Customer Payments
|--------------------------------------------------
*/

if($is_posted && $customer_id > 0){
    rollback_customer_previous_due_payment_allocation(
        $conn,
        $user_id,
        $customer_id,
        $invoice_no
    );
}

$sql = "DELETE t
        FROM transactions t
        INNER JOIN customer_payments cp
            ON cp.id = t.reference_id
        WHERE t.user_id=?
        AND t.transaction_type='receive_payment'
        AND cp.invoice_id=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $user_id,
    $invoice_id
);

mysqli_stmt_execute($stmt);

mysqli_query(
    $conn,
    "DELETE FROM customer_payments
     WHERE invoice_id='$invoice_id'"
);

$sql = "DELETE FROM transactions
        WHERE user_id=?
        AND transaction_type='sales_invoice'
        AND reference_id=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $user_id,
    $invoice_id
);

mysqli_stmt_execute($stmt);

/*
------------------------------------------
DELETE STOCK TRANSACTION
------------------------------------------
*/

mysqli_query(

    $conn,

    "DELETE FROM stock_transactions

     WHERE user_id={$user_id}

     AND reference_no='".$invoice_no."'

     AND transaction_type IN ('stock_out','stock_in')"

);

/*
------------------------------------------
UPDATE CASH BOX
------------------------------------------
*/

if($is_posted && $paid_amount > 0){

    debit_wallet(
        $conn,
        $receive_wallet_id,
        $user_id,
        $paid_amount
    );

}

/*
|--------------------------------------------------
| Delete Invoice
|--------------------------------------------------
*/

mysqli_query(
    $conn,
    "DELETE FROM invoices
     WHERE id='$invoice_id'
     AND user_id='$user_id'"
);

mysqli_commit($conn);

header("Location: invoice_list.php");
exit;

} catch (Throwable $e) {
    mysqli_rollback($conn);
    header('Location: invoice_list.php?error=' . urlencode('Invoice could not be deleted. ' . $e->getMessage()));
    exit;
}
