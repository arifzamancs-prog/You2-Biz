<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/staff_attendance_helper.php';
require_once '../includes/printing_helper.php';

require_staff_manage_access();
$user_id = (int)$_SESSION['user_id'];
ensure_staff_attendance_tables($conn);
ensure_head_office_branch($conn, $user_id);

$month = max(1, min(12, (int)($_GET['month'] ?? date('n'))));
$year = max(2020, min(2100, (int)($_GET['year'] ?? date('Y'))));
$month_start = sprintf('%04d-%02d-01', $year, $month);
$month_end = date('Y-m-t', strtotime($month_start));

$stmt = mysqli_prepare($conn, "SELECT a.*, s.name AS staff_name, s.staff_code, s.designation, COALESCE(NULLIF(b.branch_name,''), 'Head Office') AS branch_name
    FROM staff_attendance_logs a INNER JOIN staff s ON s.id=a.staff_id AND s.user_id=a.user_id
    LEFT JOIN branches b ON b.id=s.branch_id AND b.user_id=s.user_id
    WHERE a.user_id=? AND a.attendance_date BETWEEN ? AND ?
    ORDER BY a.attendance_date DESC, a.login_at DESC");
mysqli_stmt_bind_param($stmt, 'iss', $user_id, $month_start, $month_end);
mysqli_stmt_execute($stmt);
$attendance = mysqli_stmt_get_result($stmt);
$company_profile = printing_company_profile_data($conn);
$company_name = htmlspecialchars($company_profile['name'] ?? 'Company');
$report_period = date('F Y', strtotime($month_start));
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Staff Attendance Log — <?= htmlspecialchars($report_period) ?></title><style>
body{margin:0;background:#eef2f7;font-family:Arial,sans-serif;color:#172033}.report{box-sizing:border-box;width:210mm;min-height:297mm;margin:18px auto;background:#fff;padding:18mm;box-shadow:0 2px 12px #0002}.heading{border-bottom:3px solid #1677e8;padding-bottom:14px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:flex-start}.heading h1{font-size:24px;margin:0 0 5px}.heading p{margin:0;color:#5a677a}.period{text-align:right}.period strong{display:block;font-size:18px;margin-bottom:5px}table{width:100%;border-collapse:collapse;font-size:12px}th{background:#1677e8;color:#fff;text-align:left;padding:9px 8px}td{padding:8px;border-bottom:1px solid #dce3ec;vertical-align:top}th:nth-child(2),td:nth-child(2){width:10%}th:nth-child(6),td:nth-child(6){width:16%}tr:nth-child(even) td{background:#f7f9fc}.status{font-weight:bold}.print-button{position:fixed;right:22px;top:20px;border:0;border-radius:5px;background:#1677e8;color:#fff;padding:10px 16px;font-size:14px;cursor:pointer}@media print{body{background:#fff}.report{width:auto;min-height:0;margin:0;box-shadow:none;padding:0}.print-button{display:none}@page{size:A4 portrait;margin:14mm}}</style></head><body>
<button class="print-button" onclick="window.print()">Print Report</button><main class="report"><header class="heading"><div><h1>Staff Attendance Log</h1><p><?= $company_name ?></p></div><div class="period"><strong><?= htmlspecialchars($report_period) ?></strong><p>Generated: <?= date('d-m-Y h:i A') ?></p></div></header><table><thead><tr><th>Date</th><th>ID</th><th>Name</th><th>Designation</th><th>Branch</th><th>Login Time</th><th>Status</th></tr></thead><tbody><?php if(mysqli_num_rows($attendance) === 0): ?><tr><td colspan="7" style="text-align:center;color:#697586">No desktop attendance logs found for this month.</td></tr><?php else: while($row=mysqli_fetch_assoc($attendance)): $auto_absent=!empty($row['is_auto_absent']) && $row['attendance_status']==='absent'; ?><tr><td><?= date('d-m-Y', strtotime($row['attendance_date'])) ?></td><td><?= htmlspecialchars($row['staff_code']) ?></td><td><?= htmlspecialchars($row['staff_name']) ?></td><td><?= htmlspecialchars($row['designation'] ?: '-') ?></td><td><?= htmlspecialchars($row['branch_name']) ?></td><td><?= $auto_absent || empty($row['login_at']) ? '—' : date('h:i A', strtotime($row['login_at'])) ?></td><td class="status"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $row['attendance_status']))) ?></td></tr><?php endwhile; endif; ?></tbody></table></main></body></html>
