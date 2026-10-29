<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/supplier_helper.php';
require_once '../includes/project_package_helper.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = $_SESSION['user_id'];
$is_fashion_retail = project_package_company_type($conn, $user_id) === 'Fashion house';
$is_restaurant_catalog = project_package_company_type($conn, $user_id) === 'Restaurant & Cafe';
$supplier_ids_enabled = supplier_uses_auto_code($conn, $user_id);
$show_supplier_code = $is_fashion_retail || $is_restaurant_catalog || $supplier_ids_enabled;
if ($show_supplier_code) {
    ensure_supplier_code_column($conn);
}
if ($supplier_ids_enabled) {
    // Older suppliers receive an ID before the list shows the column.
    supplier_code_fill_missing($conn, (int)$user_id);
}
$show_action_column = manager_can_modify() || $supplier_ids_enabled;
$supplier_can_add = is_admin_user() || (is_manager_user() && manager_has_permission('suppliers'));

$sql = "SELECT *
        FROM suppliers
        WHERE user_id=?" . restaurant_catalog_scope_sql($conn) . "
        ORDER BY id DESC";

$stmt = mysqli_prepare(
    $conn,
    $sql
);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $user_id
);

mysqli_stmt_execute($stmt);

$result =
    mysqli_stmt_get_result($stmt);

?>

<?php if(isset($_SESSION['success'])){ ?>

<div class="alert alert-success">
    <?= htmlspecialchars($_SESSION['success']); ?>
</div>

<?php unset($_SESSION['success']); ?>

<?php } ?>

<div class="card">

<div class="card-header">

<h3 class="card-title">

<?= supplier_display_text('Suppliers'); ?>

</h3>

<?php if($supplier_can_add){ ?>

<div class="card-tools">

<a href="create.php"
class="btn btn-primary btn-sm">

Add <?= supplier_display_text('Supplier'); ?>

</a>

</div>

<?php } ?>

</div>

<div class="card-body">

<table
id="example1"
class="table table-bordered table-striped">

<thead>

<tr>

<?php if ($show_supplier_code) { ?><th style="width:110px"><?= htmlspecialchars(supplier_code_label()); ?></th><?php } ?>
<th>Name</th>
<th style="width:145px">Phone</th>
<th>Address</th>
<th>Email</th>
<th style="width:95px">Status</th>
<?php if($show_action_column){ ?>
<th width="<?= $supplier_ids_enabled ? 190 : 120; ?>">Action</th>
<?php } ?>

</tr>

</thead>

<tbody>

<?php
while(
$row =
mysqli_fetch_assoc($result)
){
    $used = supplier_has_transactions($conn, $row['id'], $user_id);
?>

<tr>

<?php if ($show_supplier_code) { ?>
<td><?= htmlspecialchars($row['supplier_code'] ?: '-'); ?></td>
<?php } ?>

<td>

<?= htmlspecialchars(
$row['supplier_name']
); ?>

</td>

<td>

<?= htmlspecialchars(
$row['phone']
); ?>

</td>

<td>

<?= htmlspecialchars(
$row['address'] ?: '-'
); ?>

</td>

<td>

<?= htmlspecialchars(
$row['email']
); ?>

</td>

<td>

<?php
if(
$row['status']
==
'active'
){
?>

<span class="badge badge-success">

Active

</span>

<?php } else { ?>

<span class="badge badge-danger">

Inactive

</span>

<?php } ?>

</td>

<?php if($show_action_column){ ?>
<td style="white-space:nowrap">

<?php if($supplier_ids_enabled){ ?>
<a
href="supplier_ledger.php?id=<?= (int)$row['id']; ?>"
class="btn btn-info btn-sm"
title="Ledger">

<i class="fas fa-book"></i> Ledger

</a>
<?php } ?>

<?php if(manager_can_modify()){ ?>
<a
href="edit.php?id=<?= (int)$row['id']; ?>"
class="btn btn-warning btn-sm"
title="Edit <?= supplier_display_text('Supplier'); ?>">

<i class="fas fa-edit"></i>

</a>

<?php if(!$used){ ?>
<a
href="delete.php?id=<?= (int)$row['id']; ?>"
class="btn btn-danger btn-sm"
title="Delete <?= supplier_display_text('Supplier'); ?>"
onclick="return confirm('Delete this <?= supplier_display_text('supplier'); ?>?');">

<i class="fas fa-trash"></i>

</a>
<?php }else{ ?>
<button type="button" class="btn btn-secondary btn-sm" disabled title="This <?= supplier_display_text('supplier'); ?> has transactions and cannot be deleted">
<i class="fas fa-trash"></i>
</button>
<?php } ?>
<?php } ?>

</td>
<?php } ?>

</tr>

<?php } ?>

</tbody>

</table>

</div>

</div>

<?php
require_once '../includes/footer.php';
?>
