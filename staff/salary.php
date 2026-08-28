<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/staff_attendance_helper.php';

require_admin_user();
$user_id = (int)$_SESSION['user_id'];
ensure_staff_attendance_tables($conn);

$year = max(2020, min(2100, (int)($_GET['year'] ?? date('Y'))));
$month = max(1, min(12, (int)($_GET['month'] ?? date('n'))));
$stmt = mysqli_prepare($conn, "SELECT ms.*, s.name, s.staff_code, s.designation
    FROM staff_monthly_salaries ms
    INNER JOIN staff s ON s.id=ms.staff_id AND s.user_id=ms.user_id
    WHERE ms.user_id=? AND ms.salary_year=? AND ms.salary_month=?
    ORDER BY s.name ASC");
mysqli_stmt_bind_param($stmt, 'iii', $user_id, $year, $month);
mysqli_stmt_execute($stmt);
$salaries = mysqli_stmt_get_result($stmt);
$totals = ['assigned' => 0, 'cut' => 0, 'generated' => 0];
$salary_rows = [];
while ($row = mysqli_fetch_assoc($salaries)) {
    $salary_rows[] = $row;
    $totals['assigned'] += (float)$row['assigned_salary'];
    $totals['cut'] += (float)$row['salary_cut_amount'];
    $totals['generated'] += (float)$row['generated_salary'];
}

require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card">
 <div class="card-header"><h3 class="card-title"><i class="fas fa-money-check-alt mr-2"></i>Staff Salary</h3></div>
 <div class="card-body">
  <form class="row align-items-end mb-3" method="get">
   <div class="col-md-3"><label>Month</label><select name="month" class="form-control"><?php for($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= $m===$month?'selected':'' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option><?php endfor; ?></select></div>
   <div class="col-md-3"><label>Year</label><select name="year" class="form-control"><?php for($y=(int)date('Y')-2;$y<=(int)date('Y')+2;$y++): ?><option value="<?= $y ?>" <?= $y===$year?'selected':'' ?>><?= $y ?></option><?php endfor; ?></select></div>
   <div class="col-md-2"><button class="btn btn-primary btn-block"><i class="fas fa-filter"></i> View</button></div>
  </form>
  <div class="row mb-3">
   <div class="col-md-4"><div class="small-box bg-info"><div class="inner"><h3>BDT <?= number_format($totals['assigned'], 2) ?></h3><p>Assigned Salary</p></div><div class="icon"><i class="fas fa-wallet"></i></div></div></div>
   <div class="col-md-4"><div class="small-box bg-warning"><div class="inner"><h3>BDT <?= number_format($totals['cut'], 2) ?></h3><p>Attendance Salary Cut</p></div><div class="icon"><i class="fas fa-calendar-times"></i></div></div></div>
   <div class="col-md-4"><div class="small-box bg-success"><div class="inner"><h3>BDT <?= number_format($totals['generated'], 2) ?></h3><p>Generated Salary</p></div><div class="icon"><i class="fas fa-money-check-alt"></i></div></div></div>
  </div>
  <div class="table-responsive"><table id="example1" class="table table-bordered table-striped"><thead><tr><th>Staff</th><th>Designation</th><th class="text-right">Assigned Salary</th><th class="text-center">Late</th><th class="text-center">Absent</th><th class="text-center">Casual Leave</th><th class="text-center">Medical Leave</th><th class="text-center">Cut Days</th><th class="text-right">Salary Cut</th><th class="text-right">Generated Salary</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($salary_rows as $row): ?><tr><td><?= htmlspecialchars($row['name']) ?> <small class="text-muted">(<?= htmlspecialchars($row['staff_code']) ?>)</small></td><td><?= htmlspecialchars($row['designation']) ?></td><td class="text-right">BDT <?= number_format((float)$row['assigned_salary'],2) ?></td><td class="text-center"><?= (int)$row['late_days'] ?></td><td class="text-center"><?= (int)$row['absent_days'] ?></td><td class="text-center"><?= (int)$row['casual_leave_days'] ?></td><td class="text-center"><?= (int)$row['medical_leave_days'] ?></td><td class="text-center"><?= (int)$row['salary_cut_days'] ?></td><td class="text-right text-danger">BDT <?= number_format((float)$row['salary_cut_amount'],2) ?></td><td class="text-right font-weight-bold text-success">BDT <?= number_format((float)$row['generated_salary'],2) ?></td><td><?php if($row['payment_status']==='paid'): ?><span class="badge badge-success">Paid</span><br><small><?= $row['paid_at'] ? date('d-m-Y h:i A',strtotime($row['paid_at'])) : '' ?></small><?php else: ?><span class="badge badge-warning">Pending</span><?php endif; ?></td><td><?php if($row['payment_status']==='pending'): ?><a class="btn btn-success btn-sm" target="_blank" href="salary_cashout.php?id=<?= (int)$row['id'] ?>" title="Cash Out Salary"><i class="fas fa-money-bill-wave"></i></a><?php else: ?><a class="btn btn-info btn-sm" target="_blank" href="salary_voucher.php?id=<?= (int)$row['id'] ?>" title="Print Salary Voucher"><i class="fas fa-print"></i></a><?php endif; ?></td></tr><?php endforeach; ?><?php if(empty($salary_rows)): ?><tr><td colspan="12" class="text-center text-muted">No generated salary found for this month. Salary is generated automatically on the first login of a new month.</td></tr><?php endif; ?></tbody></table></div>
 </div>
</div>
<?php require_once '../includes/footer.php'; ?>
