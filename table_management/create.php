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
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $table_name = trim($_POST['table_name'] ?? '');
    $capacity = trim($_POST['capacity'] ?? '');
    $capacity = $capacity === '' ? null : max(1, (int)$capacity);
    $staff_id = max(0, (int)($_POST['staff_id'] ?? 0));

    if ($table_name === '') {
        $error = 'Table name or number is required.';
    } elseif ($staff_id > 0) {
        $staff_check = mysqli_prepare($conn, "SELECT id FROM staff WHERE id=? AND user_id=? AND status='active'");
        mysqli_stmt_bind_param($staff_check, 'ii', $staff_id, $user_id);
        mysqli_stmt_execute($staff_check);
        if (mysqli_num_rows(mysqli_stmt_get_result($staff_check)) === 0) $error = 'Please select an active staff member.';
    }
    if ($error === '') {
        $capacity_value = $capacity === null ? 0 : $capacity;
        $stmt = mysqli_prepare($conn, "INSERT INTO restaurant_tables (user_id, table_name, capacity, staff_id) VALUES (?, ?, NULLIF(?, 0), NULLIF(?, 0))");
        mysqli_stmt_bind_param($stmt, 'isii', $user_id, $table_name, $capacity_value, $staff_id);
        if (mysqli_stmt_execute($stmt)) {
            header('Location: index.php');
            exit;
        }
        $error = 'This table name or number already exists.';
    }
}

$staff_stmt = mysqli_prepare($conn, "SELECT id, name, staff_code FROM staff WHERE user_id=? AND status='active' ORDER BY name ASC");
mysqli_stmt_bind_param($staff_stmt, 'i', $user_id);
mysqli_stmt_execute($staff_stmt);
$staff_members = mysqli_stmt_get_result($staff_stmt);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-plus-circle mr-2"></i>Create Restaurant Table</h3></div><div class="card-body">
<?php if($error){ ?><div class="alert alert-danger"><?=htmlspecialchars($error)?></div><?php } ?>
<form method="post"><div class="form-group"><label>Table Name / No.</label><input class="form-control" name="table_name" value="<?=htmlspecialchars($_POST['table_name'] ?? '')?>" placeholder="e.g. Table 1" required></div><div class="form-group"><label>Capacity <small class="text-muted">(optional)</small></label><input class="form-control" type="number" min="1" name="capacity" value="<?=htmlspecialchars($_POST['capacity'] ?? '')?>" placeholder="e.g. 4"></div><div class="form-group"><label>Assign Staff <small class="text-muted">(optional)</small></label><select class="form-control" name="staff_id"><option value="0">Unassigned</option><?php while($staff=mysqli_fetch_assoc($staff_members)){ ?><option value="<?= (int)$staff['id'] ?>" <?= (int)($_POST['staff_id'] ?? 0) === (int)$staff['id'] ? 'selected' : '' ?>><?=htmlspecialchars($staff['name'])?><?= $staff['staff_code'] ? ' (' . htmlspecialchars($staff['staff_code']) . ')' : '' ?></option><?php } ?></select></div><button class="btn btn-primary"><i class="fas fa-save"></i> Save Table</button> <a href="index.php" class="btn btn-secondary">Back</a></form>
</div></div>
<?php require_once '../includes/footer.php'; ?>
