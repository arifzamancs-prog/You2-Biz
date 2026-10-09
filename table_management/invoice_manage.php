<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/restaurant_table_helper.php';

require_admin_user();
ensure_restaurant_tables_table($conn);
$user_id = (int)$_SESSION['user_id'];
if (!table_system_enabled($conn, $user_id)) { header('Location: ../dashboard.php'); exit; }
$message = '';
$reference_type = restaurant_invoice_reference_type($conn, $user_id);
$reference_enabled = restaurant_invoice_reference_enabled($conn, $user_id);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reference_type = ($_POST['reference_type'] ?? '') === 'table' ? 'table' : 'staff';
    $reference_enabled = ($_POST['reference_enabled'] ?? '') === '1';
    $enabled_value = $reference_enabled ? 1 : 0;
    $stmt = mysqli_prepare($conn, 'UPDATE users SET restaurant_invoice_reference_type=?, restaurant_invoice_reference_enabled=? WHERE id=?');
    mysqli_stmt_bind_param($stmt, 'sii', $reference_type, $enabled_value, $user_id);
    mysqli_stmt_execute($stmt);
    $message = 'Invoice reference setting saved.';
}
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-file-invoice mr-2"></i>Invoice Manage</h3></div><div class="card-body">
<?php if($message){ ?><div class="alert alert-success"><?=htmlspecialchars($message)?></div><?php } ?>
<form method="post"><div class="form-group"><label>Ref. Mode</label><select class="form-control" name="reference_enabled"><option value="1" <?= $reference_enabled ? 'selected' : '' ?>>Active</option><option value="0" <?= !$reference_enabled ? 'selected' : '' ?>>Inactive</option></select></div><div class="form-group"><label>Invoice Ref. Selection</label><select class="form-control" name="reference_type"><option value="staff" <?= $reference_type === 'staff' ? 'selected' : '' ?>>Staff</option><option value="table" <?= $reference_type === 'table' ? 'selected' : '' ?>>Table</option></select></div><button class="btn btn-primary"><i class="fas fa-save"></i> Save Setting</button></form>
</div></div>
<?php require_once '../includes/footer.php'; ?>
