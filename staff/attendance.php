<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/staff_attendance_helper.php';

require_admin_user();
$user_id = (int)$_SESSION['user_id'];
ensure_staff_attendance_tables($conn);
$message = '';
$message_type = 'success';
$is_ajax = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === '1';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $action = $_POST['action'] ?? '';

    if($action === 'save_settings'){
        $start = $_POST['office_start_time'] ?? '10:00';
        $late_after = $_POST['late_after_time'] ?? '10:15';
        $absent_after = $_POST['absent_after_time'] ?? '12:00';
        $late_days = max(1, (int)($_POST['late_days_for_salary_cut'] ?? 3));
        // One salary-cut day equals the percentage represented by one calendar day this month.
        $cut_type = 'percentage';
        $cut_value = round(100 / (int)date('t'), 2);
        $stmt = mysqli_prepare($conn, "UPDATE staff_attendance_settings SET office_start_time=?, late_after_time=?, absent_after_time=?, late_days_for_salary_cut=?, salary_cut_type=?, salary_cut_value=? WHERE user_id=?");
        mysqli_stmt_bind_param($stmt, 'sssisdi', $start, $late_after, $absent_after, $late_days, $cut_type, $cut_value, $user_id);
        mysqli_stmt_execute($stmt);
        $message = 'Attendance rules updated.';
    }

    if($action === 'add_closed_day'){
        $date = $_POST['closed_date'] ?? '';
        $title = trim($_POST['closed_title'] ?? '') ?: 'Office Closed';
        if($date !== ''){
            $stmt = mysqli_prepare($conn, 'INSERT INTO staff_office_closed_days (user_id, closed_date, title) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title)');
            mysqli_stmt_bind_param($stmt, 'iss', $user_id, $date, $title);
            mysqli_stmt_execute($stmt);
            $message = 'Office closed day saved.';

            $saved_day_stmt = mysqli_prepare($conn, 'SELECT id, closed_date, title FROM staff_office_closed_days WHERE user_id=? AND closed_date=? LIMIT 1');
            mysqli_stmt_bind_param($saved_day_stmt, 'is', $user_id, $date);
            mysqli_stmt_execute($saved_day_stmt);
            $saved_day = mysqli_fetch_assoc(mysqli_stmt_get_result($saved_day_stmt)) ?: null;
        }
    }

    if($action === 'delete_closed_day'){
        $id = (int)($_POST['closed_day_id'] ?? 0);
        $day_stmt = mysqli_prepare($conn, 'SELECT closed_date FROM staff_office_closed_days WHERE id=? AND user_id=? LIMIT 1');
        mysqli_stmt_bind_param($day_stmt, 'ii', $id, $user_id);
        mysqli_stmt_execute($day_stmt);
        $day_record = mysqli_fetch_assoc(mysqli_stmt_get_result($day_stmt));
        if(!$day_record || $day_record['closed_date'] < date('Y-m-d')){
            $message = 'Past office closed days cannot be removed.';
            $message_type = 'danger';
        }else{
            $stmt = mysqli_prepare($conn, 'DELETE FROM staff_office_closed_days WHERE id=? AND user_id=?');
            mysqli_stmt_bind_param($stmt, 'ii', $id, $user_id);
            mysqli_stmt_execute($stmt);
            $message = 'Closed day removed.';
        }
    }

    if($action === 'update_attendance_status'){
        $attendance_id = (int)($_POST['attendance_id'] ?? 0);
        $status = $_POST['attendance_status'] ?? '';
        $admin_password = (string)($_POST['admin_password'] ?? '');
        $allowed_statuses = ['present', 'late', 'absent', 'closed_day', 'casual_leave', 'medical_leave'];
        $admin_stmt = mysqli_prepare($conn, "SELECT password FROM users WHERE id=? AND role='admin' LIMIT 1");
        mysqli_stmt_bind_param($admin_stmt, 'i', $user_id);
        mysqli_stmt_execute($admin_stmt);
        $admin = mysqli_fetch_assoc(mysqli_stmt_get_result($admin_stmt));
        if($attendance_id <= 0 || !in_array($status, $allowed_statuses, true) || !$admin || !password_verify($admin_password, $admin['password'])){
            $message = 'Admin password or attendance status is invalid.';
            $message_type = 'danger';
        }else{
            $stmt = mysqli_prepare($conn, 'UPDATE staff_attendance_logs SET attendance_status=? WHERE id=? AND user_id=?');
            mysqli_stmt_bind_param($stmt, 'sii', $status, $attendance_id, $user_id);
            mysqli_stmt_execute($stmt);
            $message = 'Attendance status updated.';
        }
    }

    if($is_ajax){
        header('Content-Type: application/json; charset=utf-8');
        if(($action === 'add_closed_day' && empty($saved_day)) || $message_type === 'danger'){
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $message ?: 'Request could not be completed.']);
        }else{
            echo json_encode([
                'success' => true,
                'message' => $message,
                'action' => $action,
                'closed_day' => $saved_day ?? null,
                'closed_day_id' => $id ?? null
            ]);
        }
        exit;
    }
}

$settings = staff_attendance_settings($conn, $user_id);
$salary_cut_display_value = round(100 / (int)date('t'), 2);
$month = max(1, min(12, (int)($_GET['month'] ?? date('n'))));
$year = max(2020, min(2100, (int)($_GET['year'] ?? date('Y'))));
$month_start = sprintf('%04d-%02d-01', $year, $month);
$month_end = date('Y-m-t', strtotime($month_start));
$today = date('Y-m-d');

$summary_stmt = mysqli_prepare($conn, "SELECT
    COUNT(*) AS total_logins,
    SUM(attendance_status='present') AS present_count,
    SUM(attendance_status='late') AS late_count,
    SUM(attendance_status='closed_day') AS closed_day_count
    FROM staff_attendance_logs WHERE user_id=? AND attendance_date BETWEEN ? AND ?");
mysqli_stmt_bind_param($summary_stmt, 'iss', $user_id, $month_start, $month_end);
mysqli_stmt_execute($summary_stmt);
$summary = mysqli_fetch_assoc(mysqli_stmt_get_result($summary_stmt)) ?: [];

$attendance_stmt = mysqli_prepare($conn, "SELECT a.*, s.name AS staff_name, s.staff_code, s.designation
    FROM staff_attendance_logs a INNER JOIN staff s ON s.id=a.staff_id
    WHERE a.user_id=? AND a.attendance_date BETWEEN ? AND ?
    ORDER BY a.attendance_date DESC, a.login_at DESC");
mysqli_stmt_bind_param($attendance_stmt, 'iss', $user_id, $month_start, $month_end);
mysqli_stmt_execute($attendance_stmt);
$attendance = mysqli_stmt_get_result($attendance_stmt);

$closed_stmt = mysqli_prepare($conn, 'SELECT * FROM staff_office_closed_days WHERE user_id=? ORDER BY closed_date DESC LIMIT 30');
mysqli_stmt_bind_param($closed_stmt, 'i', $user_id);
mysqli_stmt_execute($closed_stmt);
$closed_days = mysqli_stmt_get_result($closed_stmt);

$today_roster_stmt = mysqli_prepare($conn, "SELECT s.id, s.name, s.staff_code, s.designation, a.id AS attendance_id, a.login_at, a.login_ip, a.attendance_status
    FROM staff s
    LEFT JOIN staff_attendance_logs a ON a.staff_id=s.id AND a.user_id=s.user_id AND a.attendance_date=?
    WHERE s.user_id=? AND s.status='active'
    ORDER BY s.name ASC");
mysqli_stmt_bind_param($today_roster_stmt, 'si', $today, $user_id);
mysqli_stmt_execute($today_roster_stmt);
$today_roster = mysqli_stmt_get_result($today_roster_stmt);
$today_is_closed_stmt = mysqli_prepare($conn, 'SELECT id FROM staff_office_closed_days WHERE user_id=? AND closed_date=? LIMIT 1');
mysqli_stmt_bind_param($today_is_closed_stmt, 'is', $user_id, $today);
mysqli_stmt_execute($today_is_closed_stmt);
$today_is_closed = mysqli_num_rows(mysqli_stmt_get_result($today_is_closed_stmt)) > 0;

// Salary remains the assigned monthly salary. This preview calculates attendance
// deductions without overwriting the staff member's assigned salary record.
$days_in_selected_month = (int)date('t', strtotime($month_start));
$cut_preview_end = $month_start > $today ? null : min($month_end, $today);
$closed_date_map = [];
$closed_month_stmt = mysqli_prepare($conn, 'SELECT closed_date FROM staff_office_closed_days WHERE user_id=? AND closed_date BETWEEN ? AND ?');
mysqli_stmt_bind_param($closed_month_stmt, 'iss', $user_id, $month_start, $month_end);
mysqli_stmt_execute($closed_month_stmt);
$closed_month_result = mysqli_stmt_get_result($closed_month_stmt);
while($closed_date = mysqli_fetch_assoc($closed_month_result)){
    $closed_date_map[$closed_date['closed_date']] = true;
}

$attendance_map = [];
$attendance_map_stmt = mysqli_prepare($conn, 'SELECT staff_id, attendance_date, attendance_status FROM staff_attendance_logs WHERE user_id=? AND attendance_date BETWEEN ? AND ?');
mysqli_stmt_bind_param($attendance_map_stmt, 'iss', $user_id, $month_start, $month_end);
mysqli_stmt_execute($attendance_map_stmt);
$attendance_map_result = mysqli_stmt_get_result($attendance_map_stmt);
while($attendance_day = mysqli_fetch_assoc($attendance_map_result)){
    $attendance_map[(int)$attendance_day['staff_id']][$attendance_day['attendance_date']] = $attendance_day['attendance_status'];
}

$staff_cut_stmt = mysqli_prepare($conn, "SELECT id, name, staff_code, salary, created_at FROM staff WHERE user_id=? AND status='active' ORDER BY name ASC");
mysqli_stmt_bind_param($staff_cut_stmt, 'i', $user_id);
mysqli_stmt_execute($staff_cut_stmt);
$staff_cut_result = mysqli_stmt_get_result($staff_cut_stmt);
$salary_cut_preview = [];
while($staff_cut = mysqli_fetch_assoc($staff_cut_result)){
    $staff_id = (int)$staff_cut['id'];
    $late_days_count = 0;
    $absent_days_count = 0;
    $staff_start = max($month_start, date('Y-m-d', strtotime($staff_cut['created_at'])));

    if($cut_preview_end && $staff_start <= $cut_preview_end){
        $cursor = new DateTime($staff_start);
        $end_cursor = new DateTime($cut_preview_end);
        while($cursor <= $end_cursor){
            $day_key = $cursor->format('Y-m-d');
            if(empty($closed_date_map[$day_key])){
                $day_status = $attendance_map[$staff_id][$day_key] ?? null;
                if($day_status === 'late'){
                    $late_days_count++;
                }elseif($day_status === 'absent'){
                    $absent_days_count++;
                }elseif($day_status === 'casual_leave' || $day_status === 'medical_leave'){
                    // Approved leave is recorded but never creates a salary cut.
                }
            }
            $cursor->modify('+1 day');
        }
    }

    $late_cut_days = intdiv($late_days_count, max(1, (int)$settings['late_days_for_salary_cut']));
    $salary_cut_days = $late_cut_days + $absent_days_count;
    $assigned_salary = (float)$staff_cut['salary'];
    $cut_amount = min($assigned_salary, round(($assigned_salary / $days_in_selected_month) * $salary_cut_days, 2));
    $salary_cut_preview[] = [
        'name' => $staff_cut['name'],
        'staff_code' => $staff_cut['staff_code'],
        'salary' => $assigned_salary,
        'late_days' => $late_days_count,
        'absent_days' => $absent_days_count,
        'cut_days' => $salary_cut_days,
        'cut_amount' => $cut_amount,
    ];
}

require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card">
 <div class="card-header"><h3 class="card-title"><i class="fas fa-user-clock mr-2"></i>Staff Attendance</h3></div>
 <div class="card-body">
 <?php if($message): ?><div class="alert alert-<?= $message_type ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
 <form class="row align-items-end mb-3" method="get">
  <div class="col-md-3"><label>Month</label><select name="month" class="form-control"><?php for($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= $m===$month?'selected':'' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option><?php endfor; ?></select></div>
  <div class="col-md-3"><label>Year</label><select name="year" class="form-control"><?php for($y=(int)date('Y')-2;$y<=(int)date('Y')+2;$y++): ?><option <?= $y===$year?'selected':'' ?>><?= $y ?></option><?php endfor; ?></select></div>
  <div class="col-md-2"><button class="btn btn-primary btn-block"><i class="fas fa-filter"></i> View</button></div>
 </form>
 <div class="row">
  <div class="col-md-3"><div class="small-box bg-info"><div class="inner"><h3><?= (int)($summary['total_logins'] ?? 0) ?></h3><p>Desktop Check-ins</p></div><div class="icon"><i class="fas fa-desktop"></i></div></div></div>
  <div class="col-md-3"><div class="small-box bg-success"><div class="inner"><h3><?= (int)($summary['present_count'] ?? 0) ?></h3><p>Present</p></div><div class="icon"><i class="fas fa-check-circle"></i></div></div></div>
  <div class="col-md-3"><div class="small-box bg-warning"><div class="inner"><h3><?= (int)($summary['late_count'] ?? 0) ?></h3><p>Late</p></div><div class="icon"><i class="fas fa-clock"></i></div></div></div>
  <div class="col-md-3"><div class="small-box bg-secondary"><div class="inner"><h3><?= (int)($summary['closed_day_count'] ?? 0) ?></h3><p>Closed-day Logins</p></div><div class="icon"><i class="fas fa-calendar-times"></i></div></div></div>
 </div>
 </div>
</div>
<div class="row">
 <div class="col-lg-7"><div class="card"><div class="card-header"><h3 class="card-title">Attendance Rules</h3></div><form id="attendance-rules-form" method="post"><div class="card-body row">
  <input type="hidden" name="action" value="save_settings"><input type="hidden" name="ajax" value="1">
  <div class="col-md-6 form-group"><label>Office Starts</label><input type="time" class="form-control" name="office_start_time" value="<?= htmlspecialchars(substr($settings['office_start_time'],0,5)) ?>"></div>
  <div class="col-md-6 form-group"><label>Late After</label><input type="time" class="form-control" name="late_after_time" value="<?= htmlspecialchars(substr($settings['late_after_time'],0,5)) ?>"></div>
  <div class="col-md-6 form-group"><label>Absent After</label><input type="time" class="form-control" name="absent_after_time" value="<?= htmlspecialchars(substr($settings['absent_after_time'] ?? '12:00:00',0,5)) ?>"><small class="text-muted">Desktop login after this time will be marked Absent.</small></div>
  <div class="col-md-6 form-group"><label>Salary Cut for</label><div class="input-group"><input type="number" min="1" class="form-control" name="late_days_for_salary_cut" value="<?= (int)$settings['late_days_for_salary_cut'] ?>" aria-label="Late days before salary cut"><div class="input-group-append"><span class="input-group-text">Days late</span></div></div><small class="text-muted">Example: 3 means every 3 late days will count as 1 salary-cut day.</small></div>
  <div class="col-md-6 form-group"><label>Salary Cut Type</label><input type="text" class="form-control" value="Percentage of Salary" readonly><small class="text-muted">Salary cut is always calculated from the staff member's assigned salary.</small></div>
  <div class="col-md-6 form-group"><label>Percentage of Salary Per Cut Day</label><input type="text" class="form-control" value="<?= number_format($salary_cut_display_value, 2, '.', '') ?>%" readonly><small class="text-muted">This percentage is calculated automatically from the <?= (int)date('t') ?> days in the current month.</small></div>
  <div class="col-12"><div class="alert alert-light border mb-0"><i class="fas fa-info-circle text-primary mr-1"></i> Only an attendance record marked <strong>Absent</strong> counts as <strong>1 salary-cut day</strong>. No-login days remain pending until an admin updates their attendance status; office closed days are excluded.</div></div>
 </div><div class="card-footer"><span id="rules-feedback" class="text-success mr-3"></span><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Rules</button></div></form></div></div>
 <div class="col-lg-5"><div class="card"><div class="card-header"><h3 class="card-title">Office Closed Days</h3></div><div class="card-body"><form id="closed-day-form" method="post" class="row"><input type="hidden" name="action" value="add_closed_day"><input type="hidden" name="ajax" value="1"><div class="col-5 form-group"><input type="date" required name="closed_date" class="form-control"></div><div class="col-5 form-group"><input type="text" name="closed_title" class="form-control" placeholder="Holiday name"></div><div class="col-2"><button type="submit" class="btn btn-success" title="Save closed day"><i class="fas fa-plus"></i></button></div></form><table class="table table-sm table-bordered"><thead><tr><th>Date</th><th>Reason</th><th></th></tr></thead><tbody id="closed-days-list"><?php while($day=mysqli_fetch_assoc($closed_days)): $past_closed_day=$day['closed_date'] < $today; ?><tr data-closed-day-id="<?= (int)$day['id'] ?>"><td><?= date('d-m-Y', strtotime($day['closed_date'])) ?></td><td><?= htmlspecialchars($day['title']) ?></td><td><button type="button" class="btn btn-danger btn-xs closed-day-delete" data-closed-day-id="<?= (int)$day['id'] ?>" title="<?= $past_closed_day ? 'Past closed days cannot be removed' : 'Remove' ?>" <?= $past_closed_day ? 'disabled' : '' ?>><i class="fas fa-trash"></i></button></td></tr><?php endwhile; ?></tbody></table></div></div></div>
</div>
<div class="card"><div class="card-header"><h3 class="card-title">Salary Cut Preview</h3><div class="card-tools"><span class="text-muted"><?= date('F Y', strtotime($month_start)) ?></span></div></div><div class="card-body table-responsive"><table class="table table-bordered table-sm mb-0"><thead><tr><th>Staff</th><th class="text-right">Assigned Salary</th><th class="text-center">Late Days</th><th class="text-center">Recorded Absent Days</th><th class="text-center">Salary-cut Days</th><th class="text-right">Estimated Salary Cut</th></tr></thead><tbody><?php foreach($salary_cut_preview as $cut): ?><tr><td><?= htmlspecialchars($cut['name']) ?> <small class="text-muted">(<?= htmlspecialchars($cut['staff_code']) ?>)</small></td><td class="text-right">BDT <?= number_format($cut['salary'], 2) ?></td><td class="text-center"><?= (int)$cut['late_days'] ?></td><td class="text-center"><?= (int)$cut['absent_days'] ?></td><td class="text-center"><?= (int)$cut['cut_days'] ?></td><td class="text-right font-weight-bold">BDT <?= number_format($cut['cut_amount'], 2) ?></td></tr><?php endforeach; ?><?php if(empty($salary_cut_preview)): ?><tr><td colspan="6" class="text-center text-muted">No active staff found.</td></tr><?php endif; ?></tbody></table><small class="text-muted d-block mt-2">Only recorded Late and Absent statuses affect this preview. No-login days are not automatically counted.</small></div></div>
<div class="card"><div class="card-header"><h3 class="card-title">Today's Attendance Roster</h3><div class="card-tools"><span class="text-muted"><?= date('d-m-Y') ?></span></div></div><div class="card-body table-responsive"><table class="table table-bordered table-sm mb-0"><thead><tr><th>Staff</th><th>Designation</th><th>Desktop Login</th><th>IP Address</th><th>Status</th></tr></thead><tbody><?php while($member=mysqli_fetch_assoc($today_roster)): $status=$member['attendance_status'] ?: ($today_is_closed ? 'closed_day' : (date('H:i:s') > ($settings['absent_after_time'] ?? '12:00:00') ? 'absent' : 'pending')); $badge=$status==='present'?'success':($status==='late'?'warning':($status==='absent'?'danger':'secondary')); ?><tr><td><?= htmlspecialchars($member['name']) ?> <small class="text-muted">(<?= htmlspecialchars($member['staff_code']) ?>)</small></td><td><?= htmlspecialchars($member['designation']) ?></td><td><?= $member['login_at'] ? date('h:i A', strtotime($member['login_at'])) : '—' ?></td><td><?= htmlspecialchars($member['login_ip'] ?: '—') ?></td><td><span class="badge badge-<?= $badge ?>"><?= htmlspecialchars(ucwords(str_replace('_',' ',$status))) ?></span><?php if($member['attendance_id']): ?> <button type="button" class="btn btn-outline-secondary btn-xs status-edit" data-id="<?= (int)$member['attendance_id'] ?>" data-status="<?= htmlspecialchars($member['attendance_status']) ?>" title="Edit status"><i class="fas fa-edit"></i></button><?php endif; ?></td></tr><?php endwhile; ?></tbody></table></div></div>
<div class="card"><div class="card-header"><h3 class="card-title">Desktop Login Attendance Log</h3></div><div class="card-body table-responsive"><table id="example1" class="table table-bordered table-striped"><thead><tr><th>Date</th><th>Staff</th><th>Designation</th><th>Login Time</th><th>IP Address</th><th>Device</th><th>Status</th></tr></thead><tbody><?php while($row=mysqli_fetch_assoc($attendance)): $class=$row['attendance_status']==='present'?'success':($row['attendance_status']==='late'?'warning':($row['attendance_status']==='absent'?'danger':'secondary')); ?><tr><td><?= date('d-m-Y', strtotime($row['attendance_date'])) ?></td><td><?= htmlspecialchars($row['staff_name']) ?> <small class="text-muted">(<?= htmlspecialchars($row['staff_code']) ?>)</small></td><td><?= htmlspecialchars($row['designation']) ?></td><td><?= date('h:i A', strtotime($row['login_at'])) ?></td><td><?= htmlspecialchars($row['login_ip']) ?></td><td>Desktop</td><td><span class="badge badge-<?= $class ?> attendance-status-badge"><?= htmlspecialchars(ucwords(str_replace('_',' ',$row['attendance_status']))) ?></span> <button type="button" class="btn btn-outline-secondary btn-xs status-edit" data-id="<?= (int)$row['id'] ?>" data-status="<?= htmlspecialchars($row['attendance_status']) ?>" title="Edit status"><i class="fas fa-edit"></i></button></td></tr><?php endwhile; ?></tbody></table></div></div>
<div class="modal fade" id="attendanceStatusModal" tabindex="-1"><div class="modal-dialog"><form id="attendance-status-form" class="modal-content"><div class="modal-header"><h5 class="modal-title">Update Attendance Status</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><div class="modal-body"><input type="hidden" name="action" value="update_attendance_status"><input type="hidden" name="ajax" value="1"><input type="hidden" name="attendance_id" id="attendance-status-id"><div class="form-group"><label>Status</label><select name="attendance_status" id="attendance-status-value" class="form-control"><option value="present">Present</option><option value="late">Late</option><option value="absent">Absent</option><option value="closed_day">Office Closed Day</option><option value="casual_leave">Casual Leave</option><option value="medical_leave">Medical Leave</option></select><small class="text-muted">Casual Leave and Medical Leave do not create a salary cut.</small></div><div class="form-group mb-0"><label>Admin Password</label><input type="password" required name="admin_password" class="form-control" autocomplete="current-password"></div></div><div class="modal-footer"><span id="status-feedback" class="text-danger mr-auto"></span><button type="submit" class="btn btn-primary">Update Status</button></div></form></div></div>
<script>
const attendancePost = async (formData) => {
 const response = await fetch('attendance.php', {method: 'POST', body: formData});
 const data = await response.json();
 if (!response.ok || !data.success) throw new Error(data.message || 'Request could not be completed.');
 return data;
};
const setRulesFeedback = (message, isError = false) => {
 const box = document.getElementById('rules-feedback');
 box.className = isError ? 'text-danger mr-3' : 'text-success mr-3';
 box.textContent = message;
};
document.getElementById('attendance-rules-form').addEventListener('submit', async function(event) {
 event.preventDefault();
 const button = this.querySelector('button[type="submit"]'); button.disabled = true;
 try { const data = await attendancePost(new FormData(this)); setRulesFeedback(data.message); }
 catch (error) { setRulesFeedback(error.message, true); }
 finally { button.disabled = false; }
});
document.getElementById('closed-day-form').addEventListener('submit', async function(event) {
 event.preventDefault();
 const form = this, button = form.querySelector('button[type="submit"]'); button.disabled = true;
 try {
  const data = await attendancePost(new FormData(form));
  const day = data.closed_day;
  const row = document.querySelector('[data-closed-day-id="' + day.id + '"]');
  const parts = day.closed_date.split('-'); const dateText = parts[2] + '-' + parts[1] + '-' + parts[0];
  const html = '<tr data-closed-day-id="' + day.id + '"><td>' + dateText + '</td><td></td><td><button type="button" class="btn btn-danger btn-xs closed-day-delete" data-closed-day-id="' + day.id + '" title="Remove"><i class="fas fa-trash"></i></button></td></tr>';
  if (row) { row.children[0].textContent = dateText; row.children[1].textContent = day.title; } else { document.getElementById('closed-days-list').insertAdjacentHTML('afterbegin', html); document.querySelector('[data-closed-day-id="' + day.id + '"] td:nth-child(2)').textContent = day.title; }
  form.reset();
 } catch (error) { alert(error.message); } finally { button.disabled = false; }
});
document.getElementById('closed-days-list').addEventListener('click', async function(event) {
 const button = event.target.closest('.closed-day-delete'); if (!button || button.disabled) return;
 if (!confirm('Remove this office closed day?')) return;
 button.disabled = true;
 const formData = new FormData(); formData.append('action', 'delete_closed_day'); formData.append('ajax', '1'); formData.append('closed_day_id', button.dataset.closedDayId);
 try { await attendancePost(formData); button.closest('tr').remove(); }
 catch (error) { alert(error.message); button.disabled = false; }
});
document.addEventListener('click', function(event) {
 const button = event.target.closest('.status-edit'); if (!button) return;
 document.getElementById('attendance-status-id').value = button.dataset.id;
 document.getElementById('attendance-status-value').value = button.dataset.status;
 document.getElementById('status-feedback').textContent = '';
 $('#attendanceStatusModal').modal('show');
});
document.getElementById('attendance-status-form').addEventListener('submit', async function(event) {
 event.preventDefault();
 const form = this, button = form.querySelector('button[type="submit"]'); button.disabled = true;
 try {
  const data = await attendancePost(new FormData(form));
  $('#attendanceStatusModal').modal('hide');
  window.location.reload();
 } catch (error) { document.getElementById('status-feedback').textContent = error.message; }
 finally { button.disabled = false; }
});
</script>
<?php require_once '../includes/footer.php'; ?>
