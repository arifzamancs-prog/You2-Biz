<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/staff_attendance_helper.php';

require_staff_manage_access();
$user_id = (int)$_SESSION['user_id'];
ensure_staff_attendance_tables($conn);

$year = max(2020, min(2100, (int)($_GET['year'] ?? date('Y'))));
$month = max(0, min(12, (int)($_GET['month'] ?? date('n'))));
$staff_id = max(0, (int)($_GET['staff_id'] ?? 0));
$staffs = mysqli_query($conn, "SELECT id, staff_code, name FROM staff WHERE user_id={$user_id} AND status='active' ORDER BY name ASC");
$salary_cut_preview = [];
if ($month > 0) {
    $salary_cut_preview = staff_attendance_monthly_salary_rows($conn, $user_id, $year, $month, true, true);
} else {
    // All Months is an annual summary: aggregate each monthly preview per staff.
    foreach (range(1, 12) as $summary_month) {
        foreach (staff_attendance_monthly_salary_rows($conn, $user_id, $year, $summary_month, true, true) as $row) {
            $id = (int)$row['staff_id'];
            if (!isset($salary_cut_preview[$id])) {
                $salary_cut_preview[$id] = $row;
                $salary_cut_preview[$id]['salary_start_date'] = null;
                foreach (['payable_days', 'late_days', 'absent_days', 'cut_days', 'cut_amount', 'generated_salary'] as $field) $salary_cut_preview[$id][$field] = 0;
            }
            foreach (['payable_days', 'late_days', 'absent_days', 'cut_days', 'cut_amount', 'generated_salary'] as $field) $salary_cut_preview[$id][$field] += $row[$field];
        }
    }
    $salary_cut_preview = array_values($salary_cut_preview);
}
if ($staff_id > 0) $salary_cut_preview = array_values(array_filter($salary_cut_preview, fn($row) => (int)$row['staff_id'] === $staff_id));
$salary_month_filter = $month > 0 ? ' AND ms.salary_month=' . $month : '';
$salary_staff_filter = $staff_id > 0 ? ' AND ms.staff_id=' . $staff_id : '';
$stmt = mysqli_prepare($conn, "SELECT ms.*, s.name, s.staff_code, s.designation, COALESCE(NULLIF(b.branch_name,''), 'Head Office') AS branch_name
    FROM staff_monthly_salaries ms
    INNER JOIN staff s ON s.id=ms.staff_id AND s.user_id=ms.user_id
    LEFT JOIN branches b ON b.id=s.branch_id AND b.user_id=s.user_id
    WHERE ms.user_id=? AND ms.salary_year=?{$salary_month_filter}{$salary_staff_filter}
    ORDER BY ms.salary_month DESC, s.name ASC");
mysqli_stmt_bind_param($stmt, 'ii', $user_id, $year);
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
 <div class="card-header"><h3 class="card-title"><i class="fas fa-calculator mr-2"></i>Salary Summary</h3><div class="card-tools"><span class="text-muted"><?= $month > 0 ? date('F Y', mktime(0,0,0,$month,1,$year)) : 'All Months ' . $year ?></span></div></div>
 <div class="card-body">
  <form class="row align-items-end mb-3" method="get">
   <div class="col-md-3"><label>Staff</label><select name="staff_id" class="form-control"><option value="0">All Staff</option><?php while($staff=mysqli_fetch_assoc($staffs)): ?><option value="<?= (int)$staff['id'] ?>" <?= $staff_id===(int)$staff['id']?'selected':'' ?>><?= htmlspecialchars($staff['name']) ?> (<?= htmlspecialchars($staff['staff_code']) ?>)</option><?php endwhile; ?></select></div>
   <div class="col-md-3"><label>Month</label><select name="month" class="form-control"><option value="0" <?= $month===0?'selected':'' ?>>All Months</option><?php for($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= $m===$month?'selected':'' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option><?php endfor; ?></select></div>
   <div class="col-md-3"><label>Year</label><select name="year" class="form-control"><?php for($y=(int)date('Y')-2;$y<=(int)date('Y')+2;$y++): ?><option value="<?= $y ?>" <?= $y===$year?'selected':'' ?>><?= $y ?></option><?php endfor; ?></select></div>
   <div class="col-md-2"><button class="btn btn-primary btn-block"><i class="fas fa-filter"></i> View</button></div>
  </form>
  <div class="table-responsive"><table id="salary-summary" class="salary-table table table-bordered table-sm mb-0"><thead><tr><th class="staff-sl">SL</th><th>Staff Details</th><th>Salary</th><th>Start Date</th><th>Payable Days</th><th>Late</th><th>Absent</th><th>Cut Days</th><th>Est. Cut</th><th>Payable Salary</th></tr></thead><tbody><?php $staff_sl = 0; foreach($salary_cut_preview as $cut): ?><tr><td class="staff-sl"><?= ++$staff_sl ?></td><td><strong><?= htmlspecialchars($cut['name']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($cut['designation'] ?? '-') ?></small><br><small class="text-muted"><?= htmlspecialchars($cut['branch_name'] ?? 'Head Office') ?></small></td><td>BDT <?= number_format($cut['salary'], 2) ?></td><td><?= $cut['salary_start_date'] ? date('d-m-Y', strtotime($cut['salary_start_date'])) : '<span class="text-muted">Not started</span>' ?></td><td><?= (int)$cut['payable_days'] ?></td><td><?= (int)$cut['late_days'] ?></td><td><?= (int)$cut['absent_days'] ?></td><td><?= (int)$cut['cut_days'] ?></td><td class="font-weight-bold">BDT <?= number_format($cut['cut_amount'], 2) ?></td><td class="font-weight-bold">BDT <?= number_format($cut['generated_salary'], 2) ?></td></tr><?php endforeach; ?></tbody></table><small class="text-muted d-block mt-2">Salary begins from the staff member's first desktop login and is pro-rated up to month end. Only recorded Late and Absent statuses affect the cut; no-login days are not automatically counted.</small></div>
 </div>
</div>
<div class="card">
 <div class="card-header"><h3 class="card-title"><i class="fas fa-money-check-alt mr-2"></i>Salary Payment Records</h3></div>
 <div class="card-body">
  <div class="table-responsive"><table id="salary-history" class="salary-table table table-bordered table-striped"><thead><tr><th class="staff-sl">SL</th><th>Staff Details</th><th class="text-right">Salary</th><th>Start Date</th><th class="text-center">Payable Days</th><th class="text-right">Before Cut</th><th class="text-center">Late</th><th class="text-center">Absent</th><th class="text-center">Cut Days</th><th class="text-right">Est. Cut</th><th class="text-right">Payable Salary</th><th>Status</th><th>Action</th></tr></thead><tbody><?php $staff_sl = 0; foreach($salary_rows as $row): $prorated_salary=(float)($row['prorated_salary']>0 ? $row['prorated_salary'] : $row['assigned_salary']); ?><tr><td class="staff-sl"><?= ++$staff_sl ?></td><td><strong><?= htmlspecialchars($row['name']) ?></strong><br><small class="text-muted"> <?= htmlspecialchars($row['designation'] ?? '-') ?></small><br><small class="text-muted"><?= htmlspecialchars($row['branch_name'] ?? 'Head Office') ?></small></td><td class="text-right">BDT <?= number_format((float)$row['assigned_salary'],2) ?></td><td><?= !empty($row['salary_start_date']) ? date('d-m-Y',strtotime($row['salary_start_date'])) : '—' ?></td><td class="text-center"><?= (int)($row['payable_days'] ?: date('t', mktime(0,0,0,(int)$row['salary_month'],1,(int)$row['salary_year']))) ?></td><td class="text-right">BDT <?= number_format($prorated_salary,2) ?></td><td class="text-center"><?= (int)$row['late_days'] ?></td><td class="text-center"><?= (int)$row['absent_days'] ?></td><td class="text-center"><?= (int)$row['salary_cut_days'] ?></td><td class="text-right text-danger">BDT <?= number_format((float)$row['salary_cut_amount'],2) ?></td><td class="text-right font-weight-bold text-success">BDT <?= number_format((float)$row['generated_salary'],2) ?></td><td><?php if($row['payment_status']==='paid'): ?><span class="badge badge-success">Paid</span><br><small><?= $row['paid_at'] ? date('d-m-Y h:i A',strtotime($row['paid_at'])) : '' ?></small><?php else: ?><span class="badge badge-warning">Pending</span><?php endif; ?></td><td><?php if($row['payment_status']==='pending'): ?><a class="btn btn-success btn-sm" target="_blank" href="salary_cashout.php?id=<?= (int)$row['id'] ?>" title="Cash Out Salary"><i class="fas fa-money-bill-wave"></i></a><?php else: ?><a class="btn btn-info btn-sm" target="_blank" href="salary_voucher.php?id=<?= (int)$row['id'] ?>" title="Print Salary Voucher"><i class="fas fa-print"></i></a><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
 </div>
</div>
<?php
$page_script = <<<'HTML'
<style>
.salary-table { width:100% !important; table-layout:auto; }
.salary-table th, .salary-table td { vertical-align:middle; }
.salary-table th { white-space:nowrap; }
.salary-table td:not(:nth-child(2)) { white-space:nowrap; }
.salary-table .staff-sl { width:1%; text-align:center; }
#salary-summary .staff-sl { width:5%; }
#salary-summary th:nth-child(3), #salary-summary td:nth-child(3),
#salary-summary th:nth-child(5), #salary-summary td:nth-child(5) { width:1%; }
</style>
<script>
$(function () {
 $('.salary-table').each(function () {
  $(this).DataTable({
   pageLength: 10, lengthMenu: [[10,25,50,100,-1],[10,25,50,100,'All']],
   autoWidth: false, responsive: false, order: [],
   columnDefs: [{targets: 0, orderable: false, searchable: false}],
   drawCallback: function () {
    var api = this.api(), start = api.page.info().start;
    api.column(0, {page: 'current'}).nodes().each(function(cell, index) {
     cell.textContent = start + index + 1;
    });
   }
  });
 });
});
</script>
HTML;
require_once '../includes/footer.php';
?>
