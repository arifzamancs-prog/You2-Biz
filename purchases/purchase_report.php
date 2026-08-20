<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = $_SESSION['user_id'];

$from_date =
    $_GET['from_date']
    ?? date('Y-m-01');

$to_date =
    $_GET['to_date']
    ?? date('Y-m-d');

$sql = "SELECT

            p.*,
            s.supplier_name

        FROM purchases p

        LEFT JOIN suppliers s
        ON s.id = p.supplier_id

        WHERE p.user_id=?
        AND p.purchase_date
        BETWEEN ? AND ?

        ORDER BY p.purchase_date DESC";

$stmt = mysqli_prepare(
    $conn,
    $sql
);

mysqli_stmt_bind_param(

    $stmt,

    "iss",

    $user_id,
    $from_date,
    $to_date

);

mysqli_stmt_execute($stmt);

$result =
    mysqli_stmt_get_result($stmt);

$total_purchase = 0;
$total_paid = 0;
$total_due = 0;
$total_count = 0;

?>
<section class="content-header">

<div class="container-fluid">

<h1>

Purchase Report

</h1>

</div>

</section>

<section class="content">

<div class="container-fluid">

<div class="card">

<div class="card-body">

<form method="GET">

<div class="row">

<div class="col-md-3">

<label>

From Date

</label>

<input
type="date"
name="from_date"
class="form-control"
value="<?= $from_date; ?>">

</div>

<div class="col-md-3">

<label>

To Date

</label>

<input
type="date"
name="to_date"
class="form-control"
value="<?= $to_date; ?>">

</div>

<div class="col-md-2">

<label>&nbsp;</label>

<button
type="submit"
class="btn btn-primary btn-block">

Search

</button>

</div>

</div>

</form>

</div>

</div>
<div class="card">

<div class="card-header">

<h3 class="card-title">

Purchase Report

</h3>

</div>

<div class="card-body">

<table
id="example1"
class="table table-bordered table-striped">

<thead>

<tr>

<th>Purchase No</th>
<th>Date</th>
<th>Supplier</th>
<th>Total</th>
<th>Paid</th>
<th>Due</th>
<th>Status</th>
<th>Action</th>

</tr>

</thead>

<tbody>

<?php
while($row = mysqli_fetch_assoc($result)){

    $total_purchase += $row['total_amount'];
    $total_paid += $row['paid_amount'];
    $total_due += $row['due_amount'];
    $total_count++;
?>

<tr>

<td>
<?= htmlspecialchars($row['purchase_no']); ?>
</td>

<td>
<?= htmlspecialchars(app_date($row['purchase_date'])); ?>
</td>

<td>
<?= htmlspecialchars($row['supplier_name']); ?>
</td>

<td>
<?= number_format($row['total_amount'],2); ?>
</td>

<td>
<?= number_format($row['paid_amount'],2); ?>
</td>

<td>
<?= number_format($row['due_amount'],2); ?>
</td>

<td>

<?php
if($row['payment_status']=="paid"){
?>

<span class="badge badge-success">

Paid

</span>

<?php
}elseif($row['payment_status']=="partial"){
?>

<span class="badge badge-warning">

Partial

</span>

<?php }else{ ?>

<span class="badge badge-danger">

Due

</span>

<?php } ?>

</td>

<td>

<a
href="view.php?id=<?= $row['id']; ?>"
class="btn btn-info btn-sm">

View

</a>

</td>

</tr>

<?php } ?>

</tbody>

</table>

</div>

</div>
<div class="row">

<div class="col-md-3">

<div class="small-box bg-info">

<div class="inner">

<h3><?= $total_count; ?></h3>

<p>Total Purchases</p>

</div>

</div>

</div>

<div class="col-md-3">

<div class="small-box bg-success">

<div class="inner">

<h3><?= number_format($total_purchase,2); ?></h3>

<p>Total Purchase</p>

</div>

</div>

</div>

<div class="col-md-3">

<div class="small-box bg-primary">

<div class="inner">

<h3><?= number_format($total_paid,2); ?></h3>

<p>Total Paid</p>

</div>

</div>

</div>

<div class="col-md-3">

<div class="small-box bg-danger">

<div class="inner">

<h3><?= number_format($total_due,2); ?></h3>

<p>Total Due</p>

</div>

</div>

</div>

</div>

</div>

</section>
<?php
require_once '../includes/footer.php';
?>
