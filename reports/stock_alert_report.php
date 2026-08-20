<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = $_SESSION['user_id'];

$sql = "SELECT
            p.id,
            p.product_name,
            p.sku,
            p.current_stock,
            p.minimum_stock,
            c.category_name
        FROM products p
        LEFT JOIN product_categories c
            ON c.id = p.category_id
        WHERE p.user_id = ?
        AND p.status = 'active'
        AND p.current_stock <= p.minimum_stock
        ORDER BY p.current_stock ASC,
                 p.product_name ASC";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $user_id
);

mysqli_stmt_execute($stmt);

$result =
    mysqli_stmt_get_result($stmt);

$products = [];
$low_stock_count = 0;
$out_of_stock_count = 0;

while($row = mysqli_fetch_assoc($result)){

    $products[] = $row;
    $low_stock_count++;

    if((float)$row['current_stock'] <= 0){

        $out_of_stock_count++;

    }

}

?>

<section class="content-header">

<div class="container-fluid">

<h1>
Stock Alert Report
</h1>

</div>

</section>

<section class="content">

<div class="container-fluid">

<div class="card">

<div class="card-header">

<h3 class="card-title">
Low Stock Products
</h3>

</div>

<div class="card-body">

<table
id="example1"
class="table table-bordered table-striped">

<thead>

<tr>

<th>Product</th>
<th>Category</th>
<th>SKU</th>
<th>Current Stock</th>
<th>Minimum Stock</th>
<th>Status</th>

</tr>

</thead>

<tbody>

<?php foreach($products as $row){ ?>

<tr>

<td>
<?php echo htmlspecialchars($row['product_name']); ?>
</td>

<td>
<?php echo htmlspecialchars($row['category_name'] ?? ''); ?>
</td>

<td>
<?php echo htmlspecialchars($row['sku']); ?>
</td>

<td>
<?php echo number_format($row['current_stock'],0); ?>
</td>

<td>
<?php echo number_format($row['minimum_stock'],0); ?>
</td>

<td>

<?php if((float)$row['current_stock'] <= 0){ ?>

<span class="badge badge-danger">
Out Of Stock
</span>

<?php }else{ ?>

<span class="badge badge-danger">
Low Stock
</span>

<?php } ?>

</td>

</tr>

<?php } ?>

</tbody>

<tfoot>

<tr>

<th colspan="5" class="text-right">
Total Low Stock Products
</th>

<th><?= $low_stock_count; ?></th>

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
