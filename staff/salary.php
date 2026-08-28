<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/staff_attendance_helper.php';

require_staff_manage_access();
$user_id = (int)$_SESSION['user_id'];
ensure_staff_attendance_tables($conn);

$year = max(2020, min(2100, (int)($_GET['year'] ?? date('Y'))));
$month = max(1, min(12, (int)($_GET['month'] ?? date('n'))));
$salary_cut_preview = staff_attendance_monthly_salary_rows($conn, $user_id, $year, $month, true, true);
$stmt = mysqli_prepare($conn, "SELECT ms.*, s.name, s.staff_code, s.designation
    FROM staff_monthly_salaries ms
    INNER JOIN staff s ON s.id=ms.staff_id AND s.user_id=ms.user_id
    WHERE ms.user_id=? AND ms.salary_year=? AND ms.salary_month=?
    ORDER BY s.name ASC");
mysqli_stmt_bind_param($stmt, 'iii', $user_id, $year, $month);
mysqli_stmt_execute($stmt);
$salaries = mysqli_stmt_get_result($stmt);
$totals = ['assigned' => 0, 'prorated' => 0, 'cut' => 0, 'generated' => 0];
$salary_rows = [];
while ($row = mysqli_fetch_assoc($salaries)) {
    $salary_rows[] = $row;
    $totals['assigned'] += (float)$row['assigned_salary'];
    $totals['prorated'] += (float)($row['prorated_salary'] > 0 ? $row['prorated_salary'] : $row['assigned_salary']);
    $totals['cut'] += (float)$row['salary_cut_amount'];
    $totals['generated'] += (float)$row['generated_salary'];
}

require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card">
 <div class="card-header"><h3 class="card-title"><i class="fas fa-calculator mr-2"></i>Salary Cut Preview</h3><div class="card-tools"><span class="text-muted"><?= date('F Y', mktime(0,0,0,$month,1,$year)) ?></span></div></div>
 <div class="card-body">
  <form class="row align-items-end mb-3" method="get">
   <div class="col-md-3"><label>Month</label><select name="month" class="form-control"><?php for($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= $m===$month?'selected':'' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option><?php endfor; ?></select></div>
   <div class="col-md-3"><label>Year</label><select name="year" class="form-control"><?php for($y=(int)date('Y')-2;$y<=(int)date('Y')+2;$y++): ?><option value="<?= $y ?>" <?= $y===$year?'selected':'' ?>><?= $y ?></option><?php endfor; ?></select></div>
   <div class="col-md-2"><button class="btn btn-primary btn-block"><i class="fas fa-filter"></i> View</button></div>
  </form>
  <div class="table-responsive"><table class="table table-bordered table-sm mb-0"><thead><tr><th>Staff Name</th><th>Salary</th><th>Start Date</th><th>Payable Days</th><th>Late</th><th>Absent</th><th>Cut Days</th><th>Est. Cut</th><th>Payable Salary</th></tr></thead><tbody><?php foreach($salary_cut_preview as $cut): ?><tr><td><?= htmlspecialchars($cut['name']) ?> <small class="text-muted">(<?= htmlspecialchars($cut['staff_code']) ?>)</small></td><td>BDT <?= number_format($cut['salary'], 2) ?></td><td><?= $cut['salary_start_date'] ? date('d-m-Y', strtotime($cut['salary_start_date'])) : '<span class="text-muted">Not started</span>' ?></td><td><?= (int)$cut['payable_days'] ?></td><td><?= (int)$cut['late_days'] ?></td><td><?= (int)$cut['absent_days'] ?></td><td><?= (int)$cut['cut_days'] ?></td><td class="font-weight-bold">BDT <?= number_format($cut['cut_amount'], 2) ?></td><td class="font-weight-bold">BDT <?= number_format($cut['generated_salary'], 2) ?></td></tr><?php endforeach; ?><?php if(empty($salary_cut_preview)): ?><tr><td colspan="9" class="text-center text-muted">No active staff found.</td></tr><?php endif; ?></tbody></table><small class="text-muted d-block mt-2">Salary begins from the staff member's first desktop login and is pro-rated up to month end. Only recorded Late and Absent statuses affect the cut; no-login days are not automatically counted.</small></div>
 </div>
</div>
<div class="card">
 <div class="card-header"><h3 class="card-title"><i class="fas fa-money-check-alt mr-2"></i>Staff Salary</h3></div>
 <div class="card-body">
  <div class="table-responsive"><table id="example1" class="table table-bordered table-striped"><thead><tr><th>Staff Name</th><th>Role</th><th class="text-right">Salary</th><th>Start Date</th><th class="text-center">Payable Days</th><th class="text-right">Before Cut</th><th class="text-center">Late</th><th class="text-center">Absent</th><th class="text-center">Cut Days</th><th class="text-right">Est. Cut</th><th class="text-right">Payable Salary</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($salary_rows as $row): $prorated_salary=(float)($row['prorated_salary']>0 ? $row['prorated_salary'] : $row['assigned_salary']); ?><tr><td><?= htmlspecialchars($row['name']) ?> <small class="text-muted">(<?= htmlspecialchars($row['staff_code']) ?>)</small></td><td><?= htmlspecialchars($row['designation']) ?></td><td class="text-right">BDT <?= number_format((float)$row['assigned_salary'],2) ?></td><td><?= !empty($row['salary_start_date']) ? date('d-m-Y',strtotime($row['salary_start_date'])) : '—' ?></td><td class="text-center"><?= (int)($row['payable_days'] ?: date('t', mktime(0,0,0,$month,1,$year))) ?></td><td class="text-right">BDT <?= number_format($prorated_salary,2) ?></td><td class="text-center"><?= (int)$row['late_days'] ?></td><td class="text-center"><?= (int)$row['absent_days'] ?></td><td class="text-center"><?= (int)$row['salary_cut_days'] ?></td><td class="text-right text-danger">BDT <?= number_format((float)$row['salary_cut_amount'],2) ?></td><td class="text-right font-weight-bold text-success">BDT <?= number_format((float)$row['generated_salary'],2) ?></td><td><?php if($row['payment_status']==='paid'): ?><span class="badge badge-success">Paid</span><br><small><?= $row['paid_at'] ? date('d-m-Y h:i A',strtotime($row['paid_at'])) : '' ?></small><?php else: ?><span class="badge badge-warning">Pending</span><?php endif; ?></td><td><?php if($row['payment_status']==='pending'): ?><a class="btn btn-success btn-sm" target="_blank" href="salary_cashout.php?id=<?= (int)$row['id'] ?>" title="Cash Out Salary"><i class="fas fa-money-bill-wave"></i></a><?php else: ?><a class="btn btn-info btn-sm" target="_blank" href="salary_voucher.php?id=<?= (int)$row['id'] ?>" title="Print Salary Voucher"><i class="fas fa-print"></i></a><?php endif; ?></td></tr><?php endforeach; ?><?php if(empty($salary_rows)): ?><tr><td colspan="13" class="text-center text-muted">No generated salary found for this month. A salary is generated only after the staff member has recorded a desktop login.</td></tr><?php endif; ?></tbody></table></div>
 </div>
</div>
<?php require_once '../includes/footer.php'; ?>
