<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/restaurant_table_helper.php';
require_once '../includes/staff_helper.php';

require_admin_user();
ensure_staff_table($conn);
ensure_restaurant_tables_table($conn);
$user_id = (int)$_SESSION['user_id'];
if (!table_system_enabled($conn, $user_id)) { header('Location: ../dashboard.php'); exit; }
$id = max(0, (int)($_GET['id'] ?? 0));
$find = mysqli_prepare($conn, 'SELECT * FROM restaurant_tables WHERE id=? AND user_id=?');
mysqli_stmt_bind_param($find, 'ii', $id, $user_id); mysqli_stmt_execute($find);
$table = mysqli_fetch_assoc(mysqli_stmt_get_result($find));
if (!$table) die('Table not found.');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $table_name = trim($_POST['table_name'] ?? '');
    $capacity = trim($_POST['capacity'] ?? '');
    $capacity = $capacity === '' ? 0 : max(1, (int)$capacity);
    $staff_id = max(0, (int)($_POST['staff_id'] ?? 0));
    if ($table_name === '') {
        $error = 'Table name or number is required.';
    } elseif ($staff_id > 0) {
        $check = mysqli_prepare($conn, "SELECT id FROM staff WHERE id=? AND user_id=? AND status='active'");
        mysqli_stmt_bind_param($check, 'ii', $staff_id, $user_id); mysqli_stmt_execute($check);
        if (mysqli_num_rows(mysqli_stmt_get_result($check)) === 0) $error = 'Please select an active staff member.';
    }
    if ($error === '') {
        $update = mysqli_prepare($conn, 'UPDATE restaurant_tables SET table_name=?, capacity=NULLIF(?,0), staff_id=NULLIF(?,0) WHERE id=? AND user_id=?');
        mysqli_stmt_bind_param($update, 'siiii', $table_name, $capacity, $staff_id, $id, $user_id);
        if (mysqli_stmt_execute($update)) { header('Location: index.php'); exit; }
        $error = 'This table name or number already exists.';
    }
    $table['table_name'] = $table_name;
    $table['capacity'] = $capacity ?: null;
    $table['staff_id'] = $staff_id ?: null;
}
$staff_stmt = mysqli_prepare($conn, 'SELECT id, name, staff_code FROM staff WHERE user_id=? AND status=\'active\' ORDER BY name ASC');
mysqli_stmt_bind_param($staff_stmt, 'i', $user_id); mysqli_stmt_execute($staff_stmt); $staff_members = mysqli_stmt_get_result($staff_stmt);
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-edit mr-2"></i>Edit Restaurant Table</h3></div><div class="card-body">
<?php if($error){ ?><div class="alert alert-danger"><?=htmlspecialchars($error)?></div><?php } ?>
<form method="post"><div class="form-group"><label>Table Name / No.</label><input class="form-control" name="table_name" value="<?=htmlspecialchars($table['table_name'])?>" required></div><div class="form-group"><label>Capacity <small class="text-muted">(optional)</small></label><input class="form-control" type="number" min="1" name="capacity" value="<?= $table['capacity'] !== null ? (int)$table['capacity'] : '' ?>"></div><div class="form-group"><label>Assign Staff <small class="text-muted">(optional)</small></label><select class="form-control" name="staff_id"><option value="0">Unassigned</option><?php while($staff=mysqli_fetch_assoc($staff_members)){ ?><option value="<?= (int)$staff['id'] ?>" <?= (int)$table['staff_id'] === (int)$staff['id'] ? 'selected' : '' ?>><?=htmlspecialchars($staff['name'])?><?= $staff['staff_code'] ? ' (' . htmlspecialchars($staff['staff_code']) . ')' : '' ?></option><?php } ?></select></div><button class="btn btn-primary"><i class="fas fa-save"></i> Update Table</button> <a href="index.php" class="btn btn-secondary">Back</a></form>
</div></div>
<?php require_once '../includes/footer.php'; ?>
