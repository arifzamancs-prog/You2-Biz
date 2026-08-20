<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/customer_due_allocation_helper.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = (int)$_SESSION['user_id'];
ensure_invoice_posting_columns($conn);
ensure_customer_opening_due_tables($conn);
$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$invoice_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$mode = $customer_id > 0 ? 'customer' : 'invoice';

if($mode === 'customer'){
    $sql = "SELECT
                c.id,
                c.customer_name,
                COALESCE(due_inv.invoice_count,0) + COALESCE(open_due.due_count,0) AS invoice_count,
                GREATEST(
                    COALESCE(inv.total_amount,0) + COALESCE(open_total.total_amount,0) - COALESCE(pay.paid_amount,0),
                    0
                ) AS due_amount
            FROM customers c
            LEFT JOIN (
                SELECT customer_id, SUM(total_amount) AS total_amount
                FROM invoices
                WHERE user_id=?
                AND accounting_status='posted'
                GROUP BY customer_id
            ) inv
                ON inv.customer_id = c.id
            LEFT JOIN (
                SELECT customer_id, SUM(amount) AS total_amount
                FROM customer_opening_dues
                WHERE user_id=?
                GROUP BY customer_id
            ) open_total
                ON open_total.customer_id = c.id
            LEFT JOIN (
                SELECT customer_id, SUM(amount) AS paid_amount
                FROM customer_payments
                WHERE user_id=?
                GROUP BY customer_id
            ) pay
                ON pay.customer_id = c.id
            LEFT JOIN (
                SELECT customer_id, COUNT(id) AS invoice_count
                FROM invoices
                WHERE user_id=?
                AND accounting_status='posted'
                AND due_amount > 0
                GROUP BY customer_id
            ) due_inv
                ON due_inv.customer_id = c.id
            LEFT JOIN (
                SELECT customer_id, COUNT(id) AS due_count
                FROM customer_opening_dues
                WHERE user_id=?
                AND due_amount > 0
                GROUP BY customer_id
            ) open_due
                ON open_due.customer_id = c.id
            WHERE c.id=?
            AND c.user_id=?
            HAVING due_amount > 0";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param(
        $stmt,
        "iiiiiii",
        $user_id,
        $user_id,
        $user_id,
        $user_id,
        $user_id,
        $customer_id,
        $user_id
    );
    mysqli_stmt_execute($stmt);
    $payment_target = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if(!$payment_target){
        die("Customer due not found.");
    }

    $reference_label = "Due Entries";
    $reference_value = "All Due Entries (" . (int)$payment_target['invoice_count'] . ")";
    $customer_name = $payment_target['customer_name'];
    $current_due = (float)$payment_target['due_amount'];
}else{
    $sql = "SELECT *
            FROM invoices
            WHERE id=?
            AND user_id=?
            AND accounting_status='posted'
            AND due_amount > 0";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $invoice_id, $user_id);
    mysqli_stmt_execute($stmt);
    $invoice = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if(!$invoice){
        die("Invoice due not found.");
    }

    $reference_label = "Invoice No";
    $reference_value = $invoice['invoice_no'];
    $customer_name = $invoice['customer_name'];
    $current_due = (float)$invoice['due_amount'];
}

$wallets = active_wallets_result($conn, $user_id);

?>

<section class="content-header">

<div class="container-fluid">

<h1>
Receive Due Payment
</h1>

</div>

</section>

<section class="content">

<div class="container-fluid">

<div class="card">

<div class="card-body">

<form
method="post"
action="payment_save.php">

<input
type="hidden"
name="payment_mode"
value="<?= htmlspecialchars($mode); ?>">

<?php if($mode === 'customer'){ ?>
<input
type="hidden"
name="customer_id"
value="<?= (int)$customer_id; ?>">
<?php }else{ ?>
<input
type="hidden"
name="invoice_id"
value="<?= (int)$invoice_id; ?>">
<?php } ?>

<div class="form-group">

<label>
<?= htmlspecialchars($reference_label); ?>
</label>

<input
type="text"
class="form-control"
value="<?= htmlspecialchars($reference_value); ?>"
readonly>

</div>

<div class="form-group">

<label>
Customer
</label>

<input
type="text"
class="form-control"
value="<?= htmlspecialchars($customer_name); ?>"
readonly>

</div>

<div class="form-group">

<label>
Current Due
</label>

<input
type="text"
class="form-control"
value="<?= number_format($current_due, 2); ?>"
readonly>

</div>

<div class="form-group">

<label>
Receive Amount
</label>

<input
type="number"
step="0.01"
min="0.01"
name="amount"
max="<?= htmlspecialchars((string)$current_due); ?>"
class="form-control"
required>

</div>

<div class="form-group">

<label>
Receive To Wallet
</label>

<select
    name="receive_wallet_id"
    class="form-control"
    required>

<?php while($wallet=mysqli_fetch_assoc($wallets)){ ?>

<option
value="<?= (int)$wallet['id']; ?>"
<?= $wallet['is_system']==1 ? 'selected' : ''; ?>>
<?= htmlspecialchars($wallet['wallet_name']); ?>
</option>

<?php } ?>

</select>

</div>

<button
type="submit"
class="btn btn-success">

Save Payment

</button>

<a
href="receive_payment.php"
class="btn btn-secondary">

Back

</a>

</form>

</div>

</div>

</div>

</section>

</div>

<?php
require_once '../includes/footer.php';
?>
