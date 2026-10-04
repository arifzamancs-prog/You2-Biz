<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = $_SESSION['user_id'];

$sql = "SELECT

            p.*,
            s.supplier_name,
            COALESCE(SUM(pi.quantity), 0) AS total_quantity,
            GROUP_CONCAT(DISTINCT CONCAT(pr.product_name, ' [', COALESCE(NULLIF(pr.sku, ''), '—'), ']') ORDER BY pr.product_name SEPARATOR ', ') AS product_names,
            GROUP_CONCAT(DISTINCT NULLIF(pc.category_name, '') ORDER BY pc.category_name SEPARATOR ', ') AS category_names,
            GROUP_CONCAT(DISTINCT NULLIF(pr.sub_category, '') ORDER BY pr.sub_category SEPARATOR ', ') AS sub_category_names,
            GROUP_CONCAT(
                CASE
                    WHEN COALESCE(pi.variant_name, '') <> '' THEN CONCAT(pi.variant_name, ': ', pi.quantity)
                    ELSE NULL
                END
                ORDER BY pr.product_name, pi.variant_name
                SEPARATOR ' || '
            ) AS variant_details

        FROM purchases p

        LEFT JOIN suppliers s
        ON s.id = p.supplier_id
        AND s.user_id = p.user_id

        LEFT JOIN purchase_items pi
        ON pi.purchase_id = p.id

        LEFT JOIN products pr
        ON pr.id = pi.product_id
        AND pr.user_id = p.user_id

        LEFT JOIN product_categories pc
        ON pc.id = pr.category_id
        AND pc.user_id = p.user_id

        WHERE p.user_id=?

        GROUP BY p.id, s.supplier_name

        ORDER BY p.id DESC";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

?>

<?php if(isset($_SESSION['success'])){ ?>

<div class="alert alert-success alert-dismissible fade show">

    <?= $_SESSION['success']; ?>

    <?php unset($_SESSION['success']); ?>

</div>

<?php } ?>

<?php if(isset($_SESSION['error'])){ ?>

<div class="alert alert-danger alert-dismissible fade show">

    <?= htmlspecialchars($_SESSION['error']); ?>

    <?php unset($_SESSION['error']); ?>

</div>

<?php } ?>

<div class="card">

<div class="card-header">

<h3 class="card-title">

Purchase List

</h3>

<div class="card-tools">

<a href="create.php"
class="btn btn-primary btn-sm">

Create Purchase

</a>

</div>

</div>

<div class="card-body">

<table
id="example1"
class="table table-bordered table-striped">

<thead>

<tr>

<th width="16%">Purchase No</th>
<th width="10%">Date</th>
<th width="10%"><?= supplier_display_text('Supplier'); ?></th>
<th width="17%">Product</th>
<th width="12%">Category</th>
<th width="16%">Qty</th>
<th width="12%">Total</th>
<th width="12%">Paid</th>
<th>Status</th>
<th width="130">Action</th>

</tr>

</thead>

<tbody>

<?php
while(
$row =
mysqli_fetch_assoc($result)
){
?>

<tr>

<td>
<?= $row['purchase_no']; ?>
</td>

<td>
<?= app_date($row['purchase_date']); ?>
</td>

<td>
<?= htmlspecialchars(
$row['supplier_name'] ?: (supplier_display_text('Missing Supplier #') . (int)$row['supplier_id'])
); ?>
</td>

<td>
<?= htmlspecialchars($row['product_names'] ?: 'Missing Product Link'); ?>
</td>

<td><?= htmlspecialchars($row['category_names'] ?: '—'); ?><?php if(!empty($row['sub_category_names'])){ ?><div class="small text-muted mt-1"><?= htmlspecialchars($row['sub_category_names']); ?></div><?php } ?></td>

<td>
<?= number_format(
$row['total_quantity'],
0
); ?>
<?php if(!empty($row['variant_details'])){ ?>
    <div class="small text-muted mt-1"><?= htmlspecialchars($row['variant_details']); ?></div>
<?php } ?>
</td>

<td>
<?= number_format(
$row['total_amount'],
2
); ?>
</td>

<td>
<?= number_format(
$row['paid_amount'],
2
); ?>
</td>

<td>

<?php
if(
$row['payment_status']
==
'paid'
){
?>

<span class="badge badge-success">
Paid
</span>

<?php
}elseif(
$row['payment_status']
==
'partial'
){
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
        class="btn btn-info btn-sm"
        title="View Purchase"
        aria-label="View Purchase">
        <i class="fas fa-eye"></i>
    </a>

    <?php if(manager_can_modify()){ ?>

    <a href="edit.php?id=<?= $row['id']; ?>"
    class="btn btn-warning btn-sm"
    title="Edit Purchase"
    aria-label="Edit Purchase">
        <i class="fas fa-edit"></i>
    </a>

    <a href="delete.php?id=<?= $row['id']; ?>"
    class="btn btn-danger btn-sm"
    title="Delete Purchase"
    aria-label="Delete Purchase"
    onclick="return confirm('Delete this Purchase?');">
        <i class="fas fa-trash"></i>
    </a>

    <?php } ?>

</td>

</tr>

<?php } ?>

</tbody>

</table>

</div>

</div>

<?php
require_once '../includes/footer.php';
?>
