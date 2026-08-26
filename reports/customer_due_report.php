<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';

header('Location: ' . app_path('dashboard.php?error=Customer%20Due%20report%20is%20disabled'));
exit;

require_once '../includes/invoice_posting_helper.php';
require_once '../includes/customer_opening_due_helper.php';
require_once '../includes/customer_due_allocation_helper.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = $_SESSION['user_id'];
ensure_invoice_posting_columns($conn);
ensure_customer_opening_due_tables($conn);

$total_due_all = 0;
$total_due_customer = 0;
$total_due_invoice = 0;
$customers = customer_due_report_rows($conn, $user_id);

foreach($customers as $row){

    $total_due_all += (float)$row['total_due'];
    $total_due_invoice += (int)$row['due_invoice_count'];

    $total_due_customer++;
}

?>

<section class="content-header">

<div class="container-fluid">

<h1>
Customer Due Report
</h1>

</div>

</section>

<section class="content">

<div class="container-fluid">

<div class="card">

<div class="card-header">

<h3 class="card-title">
Outstanding Customer Dues
</h3>

</div>

<div class="card-body">

<table
id="example1"
class="table table-bordered table-striped">

<thead>

<tr>

<th>Customer</th>
<th>Address</th>
<th>Phone</th>
<th>Due Entries</th>
<th>Total Due</th>
<th>Action</th>

</tr>

</thead>

<tbody>

<?php if(empty($customers)){ ?>

<tr>

<td colspan="6" class="text-center text-muted">
No customer dues found.
</td>

</tr>

<?php } ?>

<?php foreach($customers as $row){ ?>

<tr>

<td>

<?php
echo htmlspecialchars(
    $row['customer_name']
);
?>

</td>

<td>

<?php
echo htmlspecialchars(
    $row['address'] ?: '-'
);
?>

</td>

<td>

<?php
echo htmlspecialchars(
    $row['phone']
);
?>

</td>

<td>

<?php
echo (int)$row['due_invoice_count'];
?>

</td>

<td>

<strong>

<?php
echo number_format(
    $row['total_due'],
    2
);
?>

</strong>

</td>

<td>

<a
href="../customers/customer_ledger.php?id=<?php echo $row['id']; ?>"
class="btn btn-info btn-sm">

Ledger

</a>

</td>

</tr>

<?php } ?>

</tbody>

<tfoot>

<tr>

<th colspan="3">
Total Outstanding Due
</th>

<th>
<?php echo $total_due_invoice; ?>
</th>

<th>

<?php
echo number_format(
    $total_due_all,
    2
);
?>

</th>

<th></th>

</tr>

 </tfoot>

</table>

</div>

</div>

</div>

</section>

<?php
require_once '../includes/footer.php';
?>
