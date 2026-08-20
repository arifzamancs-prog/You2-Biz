<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = $_SESSION['user_id'];

$sql = "SELECT *
        FROM suppliers
        WHERE user_id=?
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

Suppliers

</h3>

<?php if(manager_can_modify()){ ?>

<div class="card-tools">

<a href="create.php"
class="btn btn-primary btn-sm">

Add Supplier

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

<th>Name</th>
<th>Phone</th>
<th>Address</th>
<th>Email</th>
<th>Status</th>
<?php if(manager_can_modify()){ ?>
<th width="120">Action</th>
<?php } ?>

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

<?php if(manager_can_modify()){ ?>
<td>

<a
href="edit.php?id=<?= (int)$row['id']; ?>"
class="btn btn-warning btn-sm"
title="Edit Supplier">

<i class="fas fa-edit"></i>

</a>

<a
href="delete.php?id=<?= (int)$row['id']; ?>"
class="btn btn-danger btn-sm"
title="Delete Supplier"
onclick="return confirm('Delete this supplier?');">

<i class="fas fa-trash"></i>

</a>

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
