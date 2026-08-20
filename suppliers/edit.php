<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = $_SESSION['user_id'];
$id = (int)$_GET['id'];

$sql = "SELECT *
        FROM suppliers
        WHERE id=?
        AND user_id=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$row = mysqli_fetch_assoc($result);

if(!$row){
    die("Supplier Not Found");
}

?>

<div class="card">

<div class="card-header">

<h3 class="card-title">
Edit Supplier
</h3>

</div>

<div class="card-body">

<?php if(isset($_SESSION['error'])){ ?>
    <div class="alert alert-danger">
        <?= htmlspecialchars($_SESSION['error']); ?>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php } ?>

<form method="post"
      action="update.php">

<input type="hidden"
       name="id"
       value="<?= $row['id']; ?>">

<div class="form-group">

<label>Supplier Name</label>

<input type="text"
       name="supplier_name"
       class="form-control"
       value="<?= htmlspecialchars($row['supplier_name']); ?>"
       required>

</div>

<div class="form-group">

<label>Phone</label>

<input type="text"
       name="phone"
       class="form-control"
       value="<?= htmlspecialchars($row['phone']); ?>">

</div>

<div class="form-group">

<label>Email</label>

<input type="email"
       name="email"
       class="form-control"
       value="<?= htmlspecialchars($row['email']); ?>">

</div>

<div class="form-group">

<label>Address</label>

<textarea
name="address"
class="form-control"><?= htmlspecialchars($row['address']); ?></textarea>

</div>

<button
type="submit"
class="btn btn-primary">

Update Supplier

</button>

</form>

</div>

</div>

<?php
require_once '../includes/footer.php';
?>
