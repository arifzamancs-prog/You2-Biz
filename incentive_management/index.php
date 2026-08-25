<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/staff_helper.php';
require_once '../includes/staff_incentive_helper.php';
require_once '../includes/restaurant_table_helper.php';

require_admin_user();
if (!incentive_system_enabled()) { header('Location: ../dashboard.php'); exit; }
ensure_staff_table($conn);
ensure_staff_incentives_table($conn);
$user_id = (int)$_SESSION['user_id'];
ensure_restaurant_tables_table($conn);
if (!table_system_enabled($conn, $user_id)) { header('Location: ../dashboard.php'); exit; }
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $staff_id = (int)($_POST['staff_id'] ?? 0);
    $commission_percent = (float)($_POST['commission_percent'] ?? 0);
    if ($staff_id <= 0 || $commission_percent < 0 || $commission_percent > 100) {
        $error = 'Select a staff member and enter a commission between 0 and 100.';
    } else {
        $check = mysqli_prepare($conn, "SELECT id FROM staff WHERE id=? AND user_id=?");
        mysqli_stmt_bind_param($check, 'ii', $staff_id, $user_id);
        mysqli_stmt_execute($check);
        if (mysqli_num_rows(mysqli_stmt_get_result($check)) === 0) {
            $error = 'Selected staff is not valid.';
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO staff_incentives (user_id, staff_id, commission_percent) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE commission_percent=VALUES(commission_percent)");
            mysqli_stmt_bind_param($stmt, 'iid', $user_id, $staff_id, $commission_percent);
            mysqli_stmt_execute($stmt);
            header('Location: index.php?success=1');
            exit;
        }
    }
}

$staff_stmt = mysqli_prepare($conn, "SELECT s.id, s.staff_code, s.name, COALESCE(si.commission_percent, 0) AS commission_percent FROM staff s LEFT JOIN staff_incentives si ON si.staff_id=s.id AND si.user_id=s.user_id WHERE s.user_id=? ORDER BY s.name");
mysqli_stmt_bind_param($staff_stmt, 'i', $user_id); mysqli_stmt_execute($staff_stmt); $staff_members = mysqli_stmt_get_result($staff_stmt);
$list_stmt = mysqli_prepare($conn, "SELECT s.staff_code, s.name, s.status, COALESCE(si.commission_percent, 0) AS commission_percent FROM staff s LEFT JOIN staff_incentives si ON si.staff_id=s.id AND si.user_id=s.user_id WHERE s.user_id=? ORDER BY s.name");
mysqli_stmt_bind_param($list_stmt, 'i', $user_id); mysqli_stmt_execute($list_stmt); $incentives = mysqli_stmt_get_result($list_stmt);
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-percent mr-2"></i>Incentive Management</h3></div><div class="card-body">
<?php if(isset($_GET['success'])){ ?><div class="alert alert-success">Staff commission updated successfully.</div><?php } ?><?php if($error){ ?><div class="alert alert-danger"><?=htmlspecialchars($error)?></div><?php } ?>
<form method="post" class="mb-4"><div class="row"><div class="col-md-6"><label>Staff</label><select name="staff_id" id="incentive_staff_id" class="form-control" required><option value="">Select Staff</option><?php while($staff=mysqli_fetch_assoc($staff_members)){ ?><option value="<?=$staff['id']?>" data-commission="<?=htmlspecialchars($staff['commission_percent'])?>"><?=htmlspecialchars($staff['name'])?><?= $staff['staff_code'] ? ' (' . htmlspecialchars($staff['staff_code']) . ')' : '' ?></option><?php } ?></select></div><div class="col-md-4"><label>Daily Ref. Sale Commission (%)</label><input type="number" name="commission_percent" id="commission_percent" class="form-control" min="0" max="100" step="0.01" value="0" required></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary btn-block"><i class="fas fa-save"></i> Save</button></div></div></form>
<table id="example1" class="table table-bordered table-striped"><thead><tr><th>Staff ID</th><th>Staff Name</th><th>Status</th><th>Daily Ref. Sale Commission</th></tr></thead><tbody><?php while($row=mysqli_fetch_assoc($incentives)){ ?><tr><td><?=htmlspecialchars($row['staff_code'] ?: '-')?></td><td><?=htmlspecialchars($row['name'])?></td><td><span class="badge badge-<?=$row['status']==='active'?'success':'secondary'?>"><?=htmlspecialchars(ucfirst($row['status']))?></span></td><td><strong><?=number_format((float)$row['commission_percent'], 2)?>%</strong></td></tr><?php } ?></tbody></table>
</div></div>
<script>document.addEventListener('DOMContentLoaded',function(){const staff=document.getElementById('incentive_staff_id'), commission=document.getElementById('commission_percent');function loadCommission(){const option=staff.options[staff.selectedIndex];commission.value=option && option.value ? parseFloat(option.dataset.commission || 0).toFixed(2) : 0;}staff.addEventListener('change',loadCommission);loadCommission();});</script>
<?php require_once '../includes/footer.php'; ?>
