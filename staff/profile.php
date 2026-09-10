<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/staff_helper.php';
require_once '../includes/staff_ledger_helper.php';
require_once '../includes/staff_attendance_helper.php';

$user_id = (int)$_SESSION['user_id'];
$current_staff_id = current_manager_staff_id($conn);
$staff_id = (int)($_GET['id'] ?? 0);
if(is_manager_user() && $staff_id <= 0){
    $staff_id = $current_staff_id;
}
if(is_manager_user() && !manager_has_permission('staff') && ($staff_id <= 0 || $staff_id !== $current_staff_id)){
    header('Location: ' . app_path('dashboard.php?error=Permission denied'));
    exit;
}
ensure_staff_table($conn);
ensure_staff_ledger_table($conn);
ensure_staff_attendance_tables($conn);

$ref_staff_column = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'ref_staff_id'");
if($ref_staff_column && mysqli_num_rows($ref_staff_column) === 0){
    mysqli_query($conn, "ALTER TABLE customers ADD COLUMN ref_staff_id BIGINT UNSIGNED NULL AFTER customer_name");
    mysqli_query($conn, "ALTER TABLE customers ADD INDEX idx_customers_ref_staff (ref_staff_id)");
}

$staff_stmt = mysqli_prepare($conn, 'SELECT * FROM staff WHERE id=? AND user_id=? LIMIT 1');
mysqli_stmt_bind_param($staff_stmt, 'ii', $staff_id, $user_id);
mysqli_stmt_execute($staff_stmt);
$staff = mysqli_fetch_assoc(mysqli_stmt_get_result($staff_stmt));
if(!$staff){ header('Location:index.php'); exit; }

$ledger_stmt = mysqli_prepare($conn, 'SELECT l.*, w.wallet_name FROM staff_ledger_entries l LEFT JOIN wallets w ON w.id=l.wallet_id AND w.user_id=l.user_id WHERE l.user_id=? AND l.staff_id=? ORDER BY l.entry_date DESC,l.id DESC');
mysqli_stmt_bind_param($ledger_stmt, 'ii', $user_id, $staff_id);
mysqli_stmt_execute($ledger_stmt);
$ledger = mysqli_stmt_get_result($ledger_stmt);

$attendance_stmt = mysqli_prepare(
    $conn,
    "SELECT attendance_date, login_at, login_ip, login_device, attendance_status, is_auto_absent
     FROM staff_attendance_logs
     WHERE user_id=?
     AND staff_id=?
     ORDER BY attendance_date DESC, login_at DESC"
);
mysqli_stmt_bind_param($attendance_stmt, 'ii', $user_id, $staff_id);
mysqli_stmt_execute($attendance_stmt);
$attendance = mysqli_stmt_get_result($attendance_stmt);

$referred_customers = [];
$customer_stmt = mysqli_prepare($conn, "SELECT id, customer_code, customer_name, phone, email, address, status FROM customers WHERE user_id=? AND ref_staff_id=? ORDER BY customer_name ASC");
mysqli_stmt_bind_param($customer_stmt, 'ii', $user_id, $staff_id);
mysqli_stmt_execute($customer_stmt);
$customer_result = mysqli_stmt_get_result($customer_stmt);
while($customer_result && $customer = mysqli_fetch_assoc($customer_result)){
    $referred_customers[] = $customer;
}

require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-user mr-2"></i>Staff Profile</h3><div class="card-tools"><a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back</a></div></div><div class="card-body"><div class="row"><div class="col-md-3"><strong>Staff ID</strong><p><?=htmlspecialchars($staff['staff_code'] ?: staff_code_from_id($staff['id']))?></p></div><div class="col-md-3"><strong>Name</strong><p><?=htmlspecialchars($staff['name'])?></p></div><div class="col-md-2"><strong>Email</strong><p><?=htmlspecialchars($staff['email'] ?? '-')?></p></div><div class="col-md-2"><strong>Phone</strong><p><?=htmlspecialchars($staff['phone'] ?? '-')?></p></div><div class="col-md-2"><strong>Designation</strong><p><?=htmlspecialchars($staff['designation'] ?? '-')?></p></div></div><div class="row"><div class="col-md-3"><strong>Status</strong><p><span class="badge badge-<?=$staff['status']==='active'?'success':'secondary'?>"><?=htmlspecialchars(ucfirst($staff['status']))?></span></p></div><div class="col-md-9"><strong>Address</strong><p><?=nl2br(htmlspecialchars($staff['address'] ?? '-'))?></p></div></div></div></div>
<?php if(isset($_GET['updated'])){ ?><div class="alert alert-success">Ledger entry updated and wallet balance adjusted.</div><?php } ?><?php if(isset($_GET['deleted'])){ ?><div class="alert alert-success">Ledger entry deleted and wallet balance restored.</div><?php } ?><?php if(isset($_GET['error'])){ ?><div class="alert alert-danger"><?= htmlspecialchars($_GET['error']); ?></div><?php } ?>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-user-clock mr-2"></i>Attendance History</h3></div><div class="card-body"><table id="attendance-history" class="table table-bordered table-striped"><thead><tr><th>Date</th><th>Login Time</th><th>IP Address</th><th>Device</th><th>Status</th></tr></thead><tbody><?php while($row=mysqli_fetch_assoc($attendance)){ $status=$row['attendance_status'] ?? 'present'; $class=$status==='present'?'success':($status==='late'?'warning':($status==='absent'?'danger':($status==='closed_day'?'secondary':'info'))); $auto_absent=!empty($row['is_auto_absent']) && $status==='absent'; ?><tr><td><?=htmlspecialchars(app_date($row['attendance_date']))?></td><td><?=$auto_absent ? '-' : htmlspecialchars(date('h:i A', strtotime($row['login_at'])))?></td><td><?=$auto_absent ? '-' : htmlspecialchars($row['login_ip'] ?? '-')?></td><td><?=$auto_absent ? 'Auto' : htmlspecialchars(ucfirst($row['login_device'] ?? 'desktop'))?></td><td><span class="badge badge-<?=$class?>"><?=htmlspecialchars(ucwords(str_replace('_',' ',$status)))?></span></td></tr><?php } ?></tbody></table></div></div>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-book mr-2"></i>Salary, Bonus & Incentive Ledger History</h3></div><div class="card-body"><table id="example1" class="table table-bordered table-striped"><thead><tr><th>Transaction No.</th><th>Date</th><th>Type</th><th>Wallet</th><th>Amount</th><th>Note</th><?php if(manager_can_modify()){ ?><th width="100">Action</th><?php } ?></tr></thead><tbody><?php while($row=mysqli_fetch_assoc($ledger)){ ?><tr><td><?=htmlspecialchars($row['txn_no'])?></td><td><?=htmlspecialchars(app_date($row['entry_date']))?></td><td><span class="badge badge-info"><?=htmlspecialchars(staff_ledger_type_label($row['entry_type']))?></span></td><td><?=htmlspecialchars($row['wallet_name'] ?? '-')?></td><td>BDT <?=number_format((float)$row['amount'],2)?></td><td><?=htmlspecialchars($row['note'] ?? '')?></td><?php if(manager_can_modify()){ ?><td><a href="ledger_edit.php?id=<?=(int)$row['id']?>&return_to=profile" class="btn btn-warning btn-sm" title="Edit"><i class="fas fa-edit"></i></a><button type="button" class="btn btn-danger btn-sm profile-ledger-delete" data-entry-id="<?=(int)$row['id']?>" title="Delete"><i class="fas fa-trash"></i></button></td><?php } ?></tr><?php } ?></tbody></table></div></div>
<script>document.addEventListener('click',async function(event){const button=event.target.closest('.profile-ledger-delete');if(!button||button.disabled)return;if(!confirm('Delete this ledger entry? The wallet balance will be restored.'))return;button.disabled=true;try{const response=await fetch('ledger_delete.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:new URLSearchParams({action:'ajax_delete',id:button.dataset.entryId})});const data=await response.json();if(!response.ok||!data.success)throw new Error(data.message||'Delete failed.');const row=button.closest('tr');if(window.jQuery&&jQuery.fn.DataTable&&jQuery.fn.DataTable.isDataTable('#example1'))jQuery('#example1').DataTable().row(row).remove().draw(false);else row.remove();}catch(error){alert(error.message||'Delete failed.');button.disabled=false;}});</script>
<?php if(!empty($referred_customers)){ ?>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-users mr-2"></i>Referred Customers</h3><div class="card-tools"><span class="badge badge-primary"><?= count($referred_customers); ?> Customer<?= count($referred_customers) === 1 ? '' : 's'; ?></span></div></div><div class="card-body"><table class="table table-bordered table-striped"><thead><tr><th>Customer ID</th><th>Customer Name</th><th>Phone</th><th>Email</th><th>Address</th><th>Status</th></tr></thead><tbody><?php foreach($referred_customers as $customer){ ?><tr><td><?= htmlspecialchars($customer['customer_code'] ?: '-'); ?></td><td><?= htmlspecialchars($customer['customer_name']); ?></td><td><?= htmlspecialchars($customer['phone'] ?: '-'); ?></td><td><?= htmlspecialchars($customer['email'] ?: '-'); ?></td><td><?= htmlspecialchars($customer['address'] ?: '-'); ?></td><td><span class="badge badge-<?= ($customer['status'] ?? 'active') === 'active' ? 'success' : 'secondary'; ?>"><?= htmlspecialchars(ucfirst($customer['status'] ?? 'active')); ?></span></td></tr><?php } ?></tbody></table></div></div>
<?php } ?>
<?php require_once '../includes/footer.php'; ?>
