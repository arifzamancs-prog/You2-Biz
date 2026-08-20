<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = $_SESSION['user_id'];

$purchase_id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;

/* Purchase */

$sql = "SELECT

            p.*,
            s.supplier_name,
            s.phone

        FROM purchases p

        LEFT JOIN suppliers s
        ON s.id = p.supplier_id

        WHERE p.id=?
        AND p.user_id=?";

$stmt = mysqli_prepare(
    $conn,
    $sql
);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $purchase_id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result =
    mysqli_stmt_get_result($stmt);

$purchase =
    mysqli_fetch_assoc($result);

if(!$purchase){

    die("Purchase Not Found");

}

/* Items */

$sql = "SELECT

            pi.*,
            p.product_name

        FROM purchase_items pi

        LEFT JOIN products p
        ON p.id = pi.product_id

        WHERE pi.purchase_id=?";

$stmt = mysqli_prepare(
    $conn,
    $sql
);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $purchase_id
);

mysqli_stmt_execute($stmt);

$items =
    mysqli_stmt_get_result($stmt);

?>

<div class="card">

<div class="card-header">

<h3 class="card-title">

Purchase Details

</h3>

<div class="card-tools">

<a
href="print_purchase.php?id=<?= (int)$purchase_id; ?>"
target="_blank"
class="btn btn-primary btn-sm">

Print

</a>

</div>

</div>

<div class="card-body">

<div class="row">

<div class="col-md-6">

<table class="table table-bordered">

<tr>

<th>
Purchase No
</th>

<td>
<?= htmlspecialchars($purchase['purchase_no']); ?>
</td>

</tr>

<tr>

<th>
Supplier
</th>

<td>
<?= htmlspecialchars($purchase['supplier_name']); ?>
</td>

</tr>

<tr>

<th>
Phone
</th>

<td>
<?= htmlspecialchars($purchase['phone']); ?>
</td>

</tr>

</table>

</div>

<div class="col-md-6">

<table class="table table-bordered">

<tr>

<th>
Purchase Date
</th>

<td>
<?= app_date($purchase['purchase_date']); ?>
</td>

</tr>

<tr>

<th>
Status
</th>

<td>
<?= ucfirst($purchase['payment_status']); ?>
</td>

</tr>

</table>

</div>

</div>

<hr>

<table
class="table table-bordered">

<thead>

<tr>

<th>Product</th>
<th>Qty</th>
<th>Cost Price</th>
<th>Total</th>

</tr>

</thead>

<tbody>

<?php
while(
$row =
mysqli_fetch_assoc($items)
){
?>

<tr>

<td>
<?= htmlspecialchars($row['product_name']); ?>
</td>

<td>
<?= $row['quantity']; ?>
</td>

<td>
<?= number_format(
$row['unit_cost'],
2
); ?>
</td>

<td>
<?= number_format(
$row['total_cost'],
2
); ?>
</td>

</tr>

<?php } ?>

</tbody>

</table>

<?php if(!empty($purchase['notes'])){ ?>

<div class="alert alert-secondary">

<strong>
Notes:
</strong>

<?= nl2br(
htmlspecialchars(
$purchase['notes']
)
); ?>

</div>

<?php } ?>

</div>

</div>

<?php
require_once '../includes/footer.php';
?>
