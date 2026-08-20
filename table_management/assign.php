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
$selected_staff_id = (int)($_GET['staff_id'] ?? $_POST['staff_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $table_ids = array_unique(array_filter(array_map('intval', $_POST['table_ids'] ?? [])));
    $staff_ok = false;
    if ($selected_staff_id > 0) {
        $stmt = mysqli_prepare($conn, "SELECT id FROM staff WHERE id=? AND user_id=? AND status='active'");
        mysqli_stmt_bind_param($stmt, 'ii', $selected_staff_id, $user_id);
        mysqli_stmt_execute($stmt);
        $staff_ok = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
    }
    if (!$staff_ok) {
        $error = 'Please select an active staff member.';
    } else {
        $stmt = mysqli_prepare($conn, "UPDATE restaurant_tables SET staff_id=NULL WHERE user_id=? AND staff_id=?");
        mysqli_stmt_bind_param($stmt, 'ii', $user_id, $selected_staff_id);
        mysqli_stmt_execute($stmt);
        if ($table_ids) {
            $stmt = mysqli_prepare($conn, "UPDATE restaurant_tables SET staff_id=? WHERE id=? AND user_id=? AND status='active'");
            foreach ($table_ids as $table_id) {
                mysqli_stmt_bind_param($stmt, 'iii', $selected_staff_id, $table_id, $user_id);
                mysqli_stmt_execute($stmt);
            }
        }
        header('Location: assign.php?staff_id=' . $selected_staff_id . '&updated=1');
        exit;
    }
}

if ($selected_staff_id === 0) {
    $default_staff_stmt = mysqli_prepare($conn, "SELECT id FROM staff WHERE user_id=? AND status='active' ORDER BY id ASC LIMIT 1");
    mysqli_stmt_bind_param($default_staff_stmt, 'i', $user_id);
    mysqli_stmt_execute($default_staff_stmt);
    $default_staff = mysqli_fetch_assoc(mysqli_stmt_get_result($default_staff_stmt));
    $selected_staff_id = (int)($default_staff['id'] ?? 0);
}

$staff_stmt = mysqli_prepare($conn, "SELECT id, staff_code, name FROM staff WHERE user_id=? AND status='active' ORDER BY name");
mysqli_stmt_bind_param($staff_stmt, 'i', $user_id); mysqli_stmt_execute($staff_stmt); $staff_members = mysqli_stmt_get_result($staff_stmt);
$table_stmt = mysqli_prepare($conn, "SELECT rt.*, s.name AS staff_name FROM restaurant_tables rt LEFT JOIN staff s ON s.id=rt.staff_id AND s.user_id=rt.user_id WHERE rt.user_id=? AND rt.status='active' ORDER BY rt.table_name");
mysqli_stmt_bind_param($table_stmt, 'i', $user_id); mysqli_stmt_execute($table_stmt); $tables = mysqli_stmt_get_result($table_stmt);
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-user-check mr-2"></i>Assign Tables to Staff</h3></div><div class="card-body">
<?php if(isset($_GET['updated'])){ ?><div class="alert alert-success">Table assignments updated successfully.</div><?php } ?><?php if($error){ ?><div class="alert alert-danger"><?=htmlspecialchars($error)?></div><?php } ?>
<form method="post"><div class="form-group"><label>Staff</label><select class="form-control" name="staff_id" required onchange="if(this.value){window.location='assign.php?staff_id='+this.value;}"><option value="">Select Staff</option><?php while($staff=mysqli_fetch_assoc($staff_members)){ ?><option value="<?=$staff['id']?>" <?=$selected_staff_id===(int)$staff['id']?'selected':''?>><?=htmlspecialchars($staff['name'])?><?= $staff['staff_code'] ? ' (' . htmlspecialchars($staff['staff_code']) . ')' : '' ?></option><?php } ?></select></div>
<?php if($selected_staff_id){ ?><label>Select Table(s) <small class="text-muted">(checked tables are assigned to the selected staff)</small></label><table class="table table-bordered"><thead><tr><th style="width:70px"><input type="checkbox" id="select-all"></th><th>Table Name / No.</th><th>Capacity</th><th>Currently Assigned</th></tr></thead><tbody><?php while($table=mysqli_fetch_assoc($tables)){ $is_assigned=(int)$table['staff_id']===$selected_staff_id; ?><tr class="<?=$is_assigned?'table-success':''?>"><td><input class="table-check" type="checkbox" name="table_ids[]" value="<?=$table['id']?>" <?=$is_assigned?'checked':''?>></td><td><?=htmlspecialchars($table['table_name'])?></td><td><?= $table['capacity'] !== null ? (int)$table['capacity'] : '-' ?></td><td><?=htmlspecialchars($table['staff_name'] ?: 'Unassigned')?><?=$is_assigned?' <span class="badge badge-success">Assigned</span>':''?></td></tr><?php } ?></tbody></table><button class="btn btn-success"><i class="fas fa-save"></i> Update Table Assignment</button><?php } ?> <a href="index.php" class="btn btn-secondary">Back</a></form>
</div></div>
<?php if($selected_staff_id){ ?><script>document.getElementById('select-all').addEventListener('change',function(){document.querySelectorAll('.table-check').forEach((box)=>box.checked=this.checked);});</script><?php } ?>
<?php require_once '../includes/footer.php'; ?>
