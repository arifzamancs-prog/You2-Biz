<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/staff_helper.php';
require_once '../includes/staff_ledger_helper.php';

$user_id = (int)$_SESSION['user_id'];
$staff_id = (int)($_GET['id'] ?? 0);
ensure_staff_table($conn);
ensure_staff_ledger_table($conn);

$staff_stmt = mysqli_prepare($conn, 'SELECT * FROM staff WHERE id=? AND user_id=? LIMIT 1');
mysqli_stmt_bind_param($staff_stmt, 'ii', $staff_id, $user_id);
mysqli_stmt_execute($staff_stmt);
$staff = mysqli_fetch_assoc(mysqli_stmt_get_result($staff_stmt));
if(!$staff){ header('Location:index.php'); exit; }

$summary_stmt = mysqli_prepare($conn, "SELECT entry_type, COALESCE(SUM(amount),0) AS total FROM staff_ledger_entries WHERE user_id=? AND staff_id=? GROUP BY entry_type");
mysqli_stmt_bind_param($summary_stmt, 'ii', $user_id, $staff_id);
mysqli_stmt_execute($summary_stmt);
$totals = ['salary'=>0, 'bonus'=>0, 'incentive'=>0];
foreach(mysqli_fetch_all(mysqli_stmt_get_result($summary_stmt), MYSQLI_ASSOC) as $item){ $totals[$item['entry_type']] = (float)$item['total']; }

$ledger_stmt = mysqli_prepare($conn, 'SELECT l.*, w.wallet_name FROM staff_ledger_entries l LEFT JOIN wallets w ON w.id=l.wallet_id AND w.user_id=l.user_id WHERE l.user_id=? AND l.staff_id=? ORDER BY l.entry_date DESC,l.id DESC');
mysqli_stmt_bind_param($ledger_stmt, 'ii', $user_id, $staff_id);
mysqli_stmt_execute($ledger_stmt);
$ledger = mysqli_stmt_get_result($ledger_stmt);

require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-user mr-2"></i>Staff Profile</h3><div class="card-tools"><a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back</a></div></div><div class="card-body"><div class="row"><div class="col-md-3"><strong>Staff ID</strong><p><?=htmlspecialchars($staff['staff_code'] ?: staff_code_from_id($staff['id']))?></p></div><div class="col-md-3"><strong>Name</strong><p><?=htmlspecialchars($staff['name'])?></p></div><div class="col-md-2"><strong>Phone</strong><p><?=htmlspecialchars($staff['phone'] ?? '-')?></p></div><div class="col-md-2"><strong>Designation</strong><p><?=htmlspecialchars($staff['designation'] ?? '-')?></p></div><div class="col-md-2"><strong>Status</strong><p><span class="badge badge-<?=$staff['status']==='active'?'success':'secondary'?>"><?=htmlspecialchars(ucfirst($staff['status']))?></span></p></div></div><div class="row"><div class="col-md-12"><strong>Address</strong><p><?=nl2br(htmlspecialchars($staff['address'] ?? '-'))?></p></div></div></div></div>
<div class="row"><div class="col-md-4"><div class="small-box bg-primary"><div class="inner"><h3>BDT <?=number_format($totals['salary'],2)?></h3><p>Total Salary</p></div><div class="icon"><i class="fas fa-money-bill"></i></div></div></div><div class="col-md-4"><div class="small-box bg-warning"><div class="inner"><h3>BDT <?=number_format($totals['bonus'],2)?></h3><p>Total Bonus</p></div><div class="icon"><i class="fas fa-gift"></i></div></div></div><div class="col-md-4"><div class="small-box bg-success"><div class="inner"><h3>BDT <?=number_format($totals['incentive'],2)?></h3><p>Total Incentive</p></div><div class="icon"><i class="fas fa-percent"></i></div></div></div></div>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-book mr-2"></i>Salary, Bonus & Incentive Ledger History</h3></div><div class="card-body"><table id="example1" class="table table-bordered table-striped"><thead><tr><th>Transaction No.</th><th>Date</th><th>Type</th><th>Wallet</th><th>Amount</th><th>Note</th></tr></thead><tbody><?php while($row=mysqli_fetch_assoc($ledger)){ ?><tr><td><?=htmlspecialchars($row['txn_no'])?></td><td><?=htmlspecialchars(app_date($row['entry_date']))?></td><td><span class="badge badge-info"><?=htmlspecialchars(staff_ledger_type_label($row['entry_type']))?></span></td><td><?=htmlspecialchars($row['wallet_name'] ?? '-')?></td><td>BDT <?=number_format((float)$row['amount'],2)?></td><td><?=htmlspecialchars($row['note'] ?? '')?></td></tr><?php } ?></tbody></table></div></div>
<?php require_once '../includes/footer.php'; ?>
