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

// Keep each variant breakdown with its own product in a multi-product purchase.
$product_stmt = mysqli_prepare($conn, "SELECT pi.purchase_id,pi.product_id,pr.product_name,pr.sku,
        COALESCE(pi.variant_name,'') AS variant_name,SUM(pi.quantity) AS quantity
    FROM purchase_items pi
    INNER JOIN purchases p ON p.id=pi.purchase_id AND p.user_id=?
    LEFT JOIN products pr ON pr.id=pi.product_id AND pr.user_id=p.user_id
    GROUP BY pi.purchase_id,pi.product_id,pr.product_name,pr.sku,COALESCE(pi.variant_name,'')
    ORDER BY pi.purchase_id,MIN(pi.id)");
mysqli_stmt_bind_param($product_stmt, 'i', $user_id);
mysqli_stmt_execute($product_stmt);
$product_result = mysqli_stmt_get_result($product_stmt);
$purchase_products = [];
while ($product = mysqli_fetch_assoc($product_result)) {
    $purchase_key = (int)$product['purchase_id'];
    $product_key = (int)$product['product_id'];
    if (!isset($purchase_products[$purchase_key][$product_key])) {
        $purchase_products[$purchase_key][$product_key] = [
            'name' => $product['product_name'] ?: 'Missing Product Link',
            'code' => trim((string)$product['sku']) ?: '—',
            'variants' => []
        ];
    }
    if ($product['variant_name'] !== '') {
        $purchase_products[$purchase_key][$product_key]['variants'][] = $product['variant_name'] . ': ' . number_format((float)$product['quantity'], 0);
    }
}

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

<style>
#example1{width:100%;table-layout:auto}
#example1 th,#example1 td{vertical-align:middle}
#example1 th{white-space:nowrap}
#example1 th:nth-child(1),#example1 td:nth-child(1),
#example1 th:nth-child(2),#example1 td:nth-child(2),
#example1 th:nth-child(n+6),#example1 td:nth-child(n+6){width:1%;white-space:nowrap}
#example1 td:nth-child(4){min-width:230px}
#example1 td:nth-child(3),#example1 td:nth-child(4),#example1 td:nth-child(5){overflow-wrap:anywhere}
#example1 td:last-child .btn{margin-bottom:2px}
</style>
<div class="table-responsive">
<table
id="example1"
data-desktop-table
class="table table-bordered table-striped">

<thead>

<tr>

<th>Purchase No</th>
<th>Date</th>
<th><?= supplier_display_text('Supplier'); ?></th>
<th>Product</th>
<th>Category</th>
<th>Qty</th>
<th>Total</th>
<th>Paid</th>
<th>Status</th>
<th>Action</th>

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
<?php foreach ($purchase_products[(int)$row['id']] ?? [] as $product) { ?>
    <div class="mb-2">
        <div><?= htmlspecialchars($product['name'] . ' [' . $product['code'] . ']'); ?></div>
        <?php if ($product['variants']) { ?><small class="d-block text-muted mt-1"><?= htmlspecialchars(implode(' || ', $product['variants'])); ?></small><?php } ?>
    </div>
<?php } ?>
<?php if (empty($purchase_products[(int)$row['id']])) { ?>Missing Product Link<?php } ?>
</td>

<td><?= htmlspecialchars($row['category_names'] ?: '—'); ?><?php if(!empty($row['sub_category_names'])){ ?><div class="small text-muted mt-1"><?= htmlspecialchars($row['sub_category_names']); ?></div><?php } ?></td>

<td>
<?= number_format(
$row['total_quantity'],
0
); ?>
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

</div>

<?php
require_once '../includes/footer.php';
?>
