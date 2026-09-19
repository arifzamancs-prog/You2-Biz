<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/staff_attendance_helper.php';

require_staff_manage_access();
$user_id = (int)$_SESSION['user_id'];
ensure_staff_attendance_tables($conn);
ensure_head_office_branch($conn, $user_id);

$staff_id = max(0, (int)($_GET['attendance_staff_id'] ?? 0));
$summary_month = max(0, min(12, (int)($_GET['attendance_summary_month'] ?? 0)));
$year = max(2020, min(2100, (int)($_GET['attendance_year'] ?? date('Y'))));
$start = $summary_month ? sprintf('%04d-%02d-01', $year, $summary_month) : sprintf('%04d-01-01', $year);
$end = $summary_month ? date('Y-m-t', strtotime($start)) : sprintf('%04d-12-31', $year);
$staff_filter = $staff_id > 0 ? ' AND s.id=' . $staff_id : '';
$query = "SELECT s.name, s.staff_code, s.designation, COALESCE(NULLIF(b.branch_name,''), 'Head Office') AS branch_name,
    COALESCE(SUM(a.attendance_status='present'),0) AS present_count,
    COALESCE(SUM(a.attendance_status='late'),0) AS late_count,
    COALESCE(SUM(a.attendance_status='absent'),0) AS absent_count,
    COALESCE(SUM(a.attendance_status='casual_leave'),0) AS casual_leave_count,
    COALESCE(SUM(a.attendance_status='medical_leave'),0) AS medical_leave_count,
    COALESCE(SUM(a.attendance_status='closed_day'),0) AS closed_day_count
    FROM staff s LEFT JOIN branches b ON b.id=s.branch_id AND b.user_id=s.user_id LEFT JOIN staff_attendance_logs a ON a.staff_id=s.id AND a.user_id=s.user_id AND a.attendance_date BETWEEN ? AND ?
    WHERE s.user_id=?{$staff_filter} GROUP BY s.id, s.name, s.staff_code, s.designation, b.branch_name ORDER BY s.name ASC";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, 'ssi', $start, $end, $user_id);
mysqli_stmt_execute($stmt);
$summary = mysqli_stmt_get_result($stmt);
$company_name = htmlspecialchars($_SESSION['company_name'] ?? $_SESSION['name'] ?? 'Company');
$period = $summary_month ? date('F Y', strtotime($start)) : (string)$year;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Attendance Summary — <?= htmlspecialchars($period) ?></title><style>
body{margin:0;background:#eef2f7;font-family:Arial,sans-serif;color:#172033}.report{box-sizing:border-box;width:297mm;min-height:210mm;margin:18px auto;background:#fff;padding:16mm;box-shadow:0 2px 12px #0002}.heading{border-bottom:3px solid #1677e8;padding-bottom:14px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:flex-start}.heading h1{font-size:24px;margin:0 0 5px}.heading p{margin:0;color:#5a677a}.period{text-align:right}.period strong{display:block;font-size:18px;margin-bottom:5px}table{width:100%;border-collapse:collapse;font-size:12px}th{background:#1677e8;color:#fff;text-align:center;padding:9px 7px}th:first-child,th:nth-child(2){text-align:left}td{padding:8px;border-bottom:1px solid #dce3ec;text-align:center}td:first-child,td:nth-child(2){text-align:left}tr:nth-child(even) td{background:#f7f9fc}.print-button{position:fixed;right:22px;top:20px;border:0;border-radius:5px;background:#1677e8;color:#fff;padding:10px 16px;font-size:14px;cursor:pointer}@media print{body{background:#fff}.report{width:auto;min-height:0;margin:0;box-shadow:none;padding:0}.print-button{display:none}@page{size:A4 landscape;margin:12mm}}</style></head><body><button class="print-button" onclick="window.print()">Print Report</button><main class="report"><header class="heading"><div><h1>Yearly Attendance Summary</h1><p><?= $company_name ?></p></div><div class="period"><strong><?= htmlspecialchars($period) ?></strong><p>Generated: <?= date('d-m-Y h:i A') ?></p></div></header><table><thead><tr><th>Staff</th><th>Designation</th><th>Branch</th><th>Present</th><th>Late</th><th>Absent</th><th>Casual Leave</th><th>Medical Leave</th><th>Office Closed Day</th></tr></thead><tbody><?php if(mysqli_num_rows($summary) === 0): ?><tr><td colspan="9" style="text-align:center;color:#697586">No staff records found.</td></tr><?php else: while($row=mysqli_fetch_assoc($summary)): ?><tr><td><?= htmlspecialchars($row['name']) ?><br><small><?= htmlspecialchars($row['staff_code']) ?></small></td><td><?= htmlspecialchars($row['designation'] ?: '-') ?></td><td><?= htmlspecialchars($row['branch_name']) ?></td><td><?= (int)$row['present_count'] ?></td><td><?= (int)$row['late_count'] ?></td><td><?= (int)$row['absent_count'] ?></td><td><?= (int)$row['casual_leave_count'] ?></td><td><?= (int)$row['medical_leave_count'] ?></td><td><?= (int)$row['closed_day_count'] ?></td></tr><?php endwhile; endif; ?></tbody></table></main></body></html>
