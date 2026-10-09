<?php
$user_id = (int)$_SESSION['user_id'];
$last_code_stmt = mysqli_prepare($conn, 'SELECT supplier_code FROM suppliers WHERE user_id=? AND supplier_code IS NOT NULL AND supplier_code<>\'\' ORDER BY id DESC LIMIT 1');
mysqli_stmt_bind_param($last_code_stmt, 'i', $user_id);
mysqli_stmt_execute($last_code_stmt);
$last_supplier_code = (string)(mysqli_fetch_assoc(mysqli_stmt_get_result($last_code_stmt))['supplier_code'] ?? '');
mysqli_stmt_close($last_code_stmt);
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">
    <div class="card-header"><h3 class="card-title">Add Supplier</h3></div>
    <div class="card-body">
        <?php if(isset($_SESSION['error'])){ ?>
            <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php } ?>
        <form method="post" action="save.php">
            <div class="form-group">
                <label for="supplier_code">Supplier ID</label>
                <input type="text" name="supplier_code" id="supplier_code" class="form-control">
                <small class="form-text text-muted">Last created Supplier ID: <strong><?= $last_supplier_code !== '' ? htmlspecialchars($last_supplier_code) : 'No previous ID' ?></strong></small>
            </div>
            <div class="form-group"><label for="supplier_name">Supplier Name</label><input type="text" name="supplier_name" id="supplier_name" class="form-control" minlength="2" pattern=".*[A-Za-z].*" required></div>
            <div class="form-group"><label for="phone">Phone</label><input type="text" name="phone" id="phone" class="form-control" inputmode="numeric"></div>
            <div class="form-group"><label for="email">Email</label><input type="email" name="email" id="email" class="form-control"></div>
            <div class="form-group"><label for="address">Address</label><textarea name="address" id="address" class="form-control"></textarea></div>
            <button type="submit" class="btn btn-primary">Save Supplier</button>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
