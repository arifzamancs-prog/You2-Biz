<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/customer_due_allocation_helper.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = (int)$_SESSION['user_id'];
ensure_invoice_posting_columns($conn);
ensure_customer_opening_due_tables($conn);
$show_actions = !is_agent_user();
$agent_user_id = (int)($_SESSION['login_user_id'] ?? 0);
$agent_customer_scope_join = is_agent_user()
    ? " INNER JOIN (
            SELECT DISTINCT customer_id
            FROM invoices
            WHERE user_id=?
            AND created_by_user_id=?
            AND customer_id > 0
        ) agent_customer
            ON agent_customer.customer_id = c.id"
    : "";
$agent_instant_invoice_filter = is_agent_user()
    ? " AND created_by_user_id=" . $agent_user_id
    : "";
$rows = [];

$sql = "SELECT
            c.id AS customer_id,
            c.customer_name,
            c.address,
            COALESCE(inv.total_amount,0) + COALESCE(open_due.total_amount,0) AS total_amount,
            COALESCE(pay.total_paid,0) AS paid_amount,
            GREATEST(
                COALESCE(inv.total_amount,0) + COALESCE(open_due.total_amount,0) - COALESCE(pay.total_paid,0),
                0
            ) AS due_amount,
            COALESCE(inv.invoice_count,0) + COALESCE(open_due.due_count,0) AS invoice_count
        FROM customers c
        LEFT JOIN (
            SELECT
                customer_id,
                COUNT(id) AS invoice_count,
                SUM(total_amount) AS total_amount
            FROM invoices
            WHERE user_id=?
            AND accounting_status='posted'
            AND customer_id > 0
            GROUP BY customer_id
        ) inv
            ON inv.customer_id = c.id
        LEFT JOIN (
            SELECT
                customer_id,
                COUNT(id) AS due_count,
                SUM(amount) AS total_amount
            FROM customer_opening_dues
            WHERE user_id=?
            GROUP BY customer_id
        ) open_due
            ON open_due.customer_id = c.id
        LEFT JOIN (
            SELECT
                customer_id,
                SUM(amount) AS total_paid
            FROM customer_payments
            WHERE user_id=?
            GROUP BY customer_id
        ) pay
            ON pay.customer_id = c.id
        " . $agent_customer_scope_join . "
        WHERE c.user_id=?
        AND GREATEST(
            COALESCE(inv.total_amount,0) + COALESCE(open_due.total_amount,0) - COALESCE(pay.total_paid,0),
            0
        ) > 0
        ORDER BY c.customer_name ASC";

$stmt = mysqli_prepare($conn, $sql);
if(is_agent_user()){
    mysqli_stmt_bind_param(
        $stmt,
        "iiiiii",
        $user_id,
        $user_id,
        $user_id,
        $user_id,
        $agent_user_id,
        $user_id
    );
}else{
    mysqli_stmt_bind_param($stmt, "iiii", $user_id, $user_id, $user_id, $user_id);
}
mysqli_stmt_execute($stmt);
$registered_due = mysqli_stmt_get_result($stmt);

while($row = mysqli_fetch_assoc($registered_due)){
    $row['row_type'] = 'customer';
    $rows[] = $row;
}

$sql = "SELECT
            id AS invoice_id,
            invoice_no,
            customer_name,
            '' AS address,
            total_amount,
            paid_amount,
            due_amount
        FROM invoices
        WHERE user_id=?
        AND accounting_status='posted'
        AND customer_id = 0
        AND due_amount > 0
        " . $agent_instant_invoice_filter . "
        ORDER BY id DESC";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$instant_due = mysqli_stmt_get_result($stmt);

while($row = mysqli_fetch_assoc($instant_due)){
    $row['row_type'] = 'instant';
    $rows[] = $row;
}

?>

<section class="content-header">
<div class="container-fluid">
    <?php if(isset($_GET['success'])){ ?>

<div class="alert alert-success">
    Due Payment Received Successfully.
</div>

<?php } ?>

<?php if(!empty($_SESSION['error'])){ ?>

<div class="alert alert-danger">
    <?= htmlspecialchars($_SESSION['error']); ?>
</div>

<?php unset($_SESSION['error']); } ?>

<h1>Due Payment</h1>

</div>
</section>

<section class="content">
<div class="container-fluid">

<div class="card">

<div class="card-body">

<table
id="example1"
class="table table-bordered table-striped">

<thead>

<tr>

<th>Reference</th>
<th>Customer</th>
<th>Address</th>
<th>Total</th>
<th>Paid</th>
<th>Due</th>
<?php if($show_actions){ ?>
<th>Action</th>
<?php } ?>

</tr>

</thead>

<tbody>

<?php foreach($rows as $row){ ?>

<tr>

<td>
<?php if($row['row_type'] === 'customer'){ ?>
    All Due Entries (<?= (int)$row['invoice_count']; ?>)
<?php }else{ ?>
    <?= htmlspecialchars($row['invoice_no']); ?>
<?php } ?>
</td>

<td><?= htmlspecialchars($row['customer_name']); ?></td>
<td><?= htmlspecialchars($row['address'] ?: '-'); ?></td>
<td><?= number_format((float)$row['total_amount'],2); ?></td>
<td><?= number_format((float)$row['paid_amount'],2); ?></td>
<td><?= number_format((float)$row['due_amount'],2); ?></td>

<?php if($show_actions){ ?>
<td>

<?php if($row['row_type'] === 'customer'){ ?>
    <a href="create_invoice.php?pay_customer_id=<?= (int)$row['customer_id']; ?>"
       class="btn btn-primary btn-sm">
        Pay
    </a>

                                <a href="<?= htmlspecialchars(app_path('customers/customer_ledger.php?id=' . (int)$row['customer_id'])); ?>"
       class="btn btn-success btn-sm">
        Ledger
    </a>
<?php }else{ ?>
    <a href="payment_entry.php?id=<?= (int)$row['invoice_id']; ?>"
       class="btn btn-success btn-sm">
        Ledger
    </a>
<?php } ?>

</td>
<?php } ?>

</tr>

<?php } ?>

</tbody>

</table>

</div>

</div>

</div>
</section>

</div>

<?php require_once '../includes/footer.php'; ?>
