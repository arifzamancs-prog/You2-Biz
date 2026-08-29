<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/staff_attendance_helper.php';

require_staff_manage_access();
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
        if(!is_admin_user()){
            $message = 'Only the company Admin can update attendance status.';
            $message_type = 'danger';
        }else{
            // Older company accounts may have an empty legacy role in the database,
            // while the active session is already recognised as Admin.
            $admin_stmt = mysqli_prepare($conn, 'SELECT password FROM users WHERE id=? LIMIT 1');
            mysqli_stmt_bind_param($admin_stmt, 'i', $user_id);
            mysqli_stmt_execute($admin_stmt);
            $admin = mysqli_fetch_assoc(mysqli_stmt_get_result($admin_stmt));
            if($attendance_id <= 0 || !in_array($status, $allowed_statuses, true) || !$admin || !password_verify($admin_password, $admin['password'])){
                $message = 'Admin password or attendance status is invalid.';
                $message_type = 'danger';
            }else{
                // A manual correction must no longer be treated as an automatic absence.
                $stmt = mysqli_prepare($conn, 'UPDATE staff_attendance_logs SET attendance_status=?, is_auto_absent=0 WHERE id=? AND user_id=?');
                if(!$stmt){
                    $message = 'Attendance record could not be updated.';
                    $message_type = 'danger';
                }else{
                    mysqli_stmt_bind_param($stmt, 'sii', $status, $attendance_id, $user_id);
                    if(!mysqli_stmt_execute($stmt)){
                        $message = 'Attendance record could not be updated.';
                        $message_type = 'danger';
                }else{
                    $message = 'Attendance status updated.';
                    if(!$is_ajax){
                        header('Location: attendance.php?status_updated=1');
                        exit;
                    }
                }
                }
            }
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
staff_attendance_auto_mark_absent($conn, $user_id);
$edit_attendance = null;
$edit_attendance_id = (int)($_GET['edit_attendance'] ?? 0);
if(is_admin_user() && $edit_attendance_id > 0){
    $edit_stmt = mysqli_prepare($conn, "SELECT a.id, a.attendance_status, a.attendance_date, s.name, s.staff_code FROM staff_attendance_logs a INNER JOIN staff s ON s.id=a.staff_id AND s.user_id=a.user_id WHERE a.id=? AND a.user_id=? LIMIT 1");
    mysqli_stmt_bind_param($edit_stmt, 'ii', $edit_attendance_id, $user_id);
    mysqli_stmt_execute($edit_stmt);
    $edit_attendance = mysqli_fetch_assoc(mysqli_stmt_get_result($edit_stmt)) ?: null;
}
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

$today_roster_stmt = mysqli_prepare($conn, "SELECT s.id, s.name, s.staff_code, s.designation, a.id AS attendance_id, a.login_at, a.login_ip, a.attendance_status, a.is_auto_absent
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

$attendance_staff_id = (int)($_GET['attendance_staff_id'] ?? 0);
$attendance_summary_month = max(0, min(12, (int)($_GET['attendance_summary_month'] ?? 0)));
$attendance_year = (int)($_GET['attendance_year'] ?? date('Y'));
if ($attendance_year < 2000 || $attendance_year > ((int)date('Y') + 1)) { $attendance_year = (int)date('Y'); }
$attendance_year_start = $attendance_year . '-01-01';
$attendance_year_end = $attendance_year . '-12-31';
$attendance_filter_start = $attendance_summary_month ? sprintf('%04d-%02d-01', $attendance_year, $attendance_summary_month) : $attendance_year_start;
$attendance_filter_end = $attendance_summary_month ? date('Y-m-t', strtotime($attendance_filter_start)) : $attendance_year_end;
$attendance_staffs = mysqli_query($conn, "SELECT id, staff_code, name FROM staff WHERE user_id={$user_id} ORDER BY name ASC");
$attendance_years = mysqli_query($conn, "SELECT DISTINCT YEAR(attendance_date) AS attendance_year FROM staff_attendance_logs WHERE user_id={$user_id} UNION SELECT " . (int)date('Y') . " ORDER BY attendance_year DESC");
$attendance_staff_filter = $attendance_staff_id > 0 ? ' AND s.id=' . $attendance_staff_id : '';
$yearly_attendance_summary = mysqli_query($conn, "SELECT s.id, s.name, s.staff_code, s.designation,
    COALESCE(SUM(a.attendance_status='present'),0) AS present_count,
    COALESCE(SUM(a.attendance_status='late'),0) AS late_count,
    COALESCE(SUM(a.attendance_status='absent'),0) AS absent_count,
    COALESCE(SUM(a.attendance_status='casual_leave'),0) AS casual_leave_count,
    COALESCE(SUM(a.attendance_status='medical_leave'),0) AS medical_leave_count,
    COALESCE(SUM(a.attendance_status='closed_day'),0) AS closed_day_count
    FROM staff s LEFT JOIN staff_attendance_logs a ON a.staff_id=s.id AND a.user_id=s.user_id AND a.attendance_date BETWEEN '" . mysqli_real_escape_string($conn, $attendance_filter_start) . "' AND '" . mysqli_real_escape_string($conn, $attendance_filter_end) . "'
    WHERE s.user_id={$user_id}{$attendance_staff_filter} GROUP BY s.id, s.name, s.staff_code, s.designation ORDER BY s.name ASC");
$yearly_attendance_log = mysqli_query($conn, "SELECT a.*, s.name, s.staff_code, s.designation FROM staff_attendance_logs a INNER JOIN staff s ON s.id=a.staff_id AND s.user_id=a.user_id WHERE a.user_id={$user_id} AND a.attendance_date BETWEEN '" . mysqli_real_escape_string($conn, $attendance_filter_start) . "' AND '" . mysqli_real_escape_string($conn, $attendance_filter_end) . "'" . ($attendance_staff_id > 0 ? ' AND a.staff_id=' . $attendance_staff_id : '') . " ORDER BY a.attendance_date DESC, a.login_at DESC");

require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<?php if(isset($_GET['status_updated'])): ?><div class="alert alert-success">Attendance status updated.</div><?php endif; ?>
<?php if(!is_admin_user()): ?><style>.status-edit{display:none!important}</style><?php endif; ?>
<?php if($edit_attendance): ?><div id="attendance-status-editor" class="card"><div class="card-header"><h3 class="card-title">Update Attendance Status — <?=htmlspecialchars($edit_attendance['name'])?> <small class="text-muted">(<?=htmlspecialchars($edit_attendance['staff_code'])?>, <?=date('d-m-Y', strtotime($edit_attendance['attendance_date']))?>)</small></h3></div><form method="post"><div class="card-body row"><input type="hidden" name="action" value="update_attendance_status"><input type="hidden" name="attendance_id" value="<?= (int)$edit_attendance['id'] ?>"><div class="col-md-4 form-group"><label>Status</label><select name="attendance_status" class="form-control"><option value="present" <?=$edit_attendance['attendance_status']==='present'?'selected':''?>>Present</option><option value="late" <?=$edit_attendance['attendance_status']==='late'?'selected':''?>>Late</option><option value="absent" <?=$edit_attendance['attendance_status']==='absent'?'selected':''?>>Absent</option><option value="closed_day" <?=$edit_attendance['attendance_status']==='closed_day'?'selected':''?>>Office Closed Day</option><option value="casual_leave" <?=$edit_attendance['attendance_status']==='casual_leave'?'selected':''?>>Casual Leave</option><option value="medical_leave" <?=$edit_attendance['attendance_status']==='medical_leave'?'selected':''?>>Medical Leave</option></select></div><div class="col-md-4 form-group"><label>Admin Password</label><input type="password" required name="admin_password" class="form-control" autocomplete="current-password"></div><div class="col-md-4 form-group d-flex align-items-end"><button class="btn btn-primary mr-2"><i class="fas fa-save"></i> Update Status</button><a href="attendance.php" class="btn btn-secondary">Cancel</a></div></div></form></div><?php endif; ?>
<div class="row d-none">
 <div class="col-lg-7"><div class="card"><div class="card-header"><h3 class="card-title">Attendance Rules</h3></div><form id="attendance-rules-form" method="post"><div class="card-body row">
  <input type="hidden" name="action" value="save_settings"><input type="hidden" name="ajax" value="1">
  <div class="col-md-6 form-group"><label>Office Starts</label><input type="time" class="form-control" name="office_start_time" value="<?= htmlspecialchars(substr($settings['office_start_time'],0,5)) ?>"></div>
  <div class="col-md-6 form-group"><label>Late After</label><input type="time" class="form-control" name="late_after_time" value="<?= htmlspecialchars(substr($settings['late_after_time'],0,5)) ?>"></div>
  <div class="col-md-6 form-group"><label>Absent After</label><input type="time" class="form-control" name="absent_after_time" value="<?= htmlspecialchars(substr($settings['absent_after_time'] ?? '12:00:00',0,5)) ?>"><small class="text-muted">Desktop login after this time will be marked Absent.</small></div>
  <div class="col-md-6 form-group"><label>Salary Cut for</label><div class="input-group"><input type="number" min="1" class="form-control" name="late_days_for_salary_cut" value="<?= (int)$settings['late_days_for_salary_cut'] ?>" aria-label="Late days before salary cut"><div class="input-group-append"><span class="input-group-text">Days late</span></div></div><small class="text-muted">Example: 3 means every 3 late days will count as 1 salary-cut day.</small></div>
  <div class="col-md-6 form-group"><label>Salary Cut Type</label><input type="text" class="form-control" value="Percentage of Salary" readonly><small class="text-muted">Salary cut is always calculated from the staff member's assigned salary.</small></div>
  <div class="col-md-6 form-group"><label>Cut for per Day Absent</label><input type="text" class="form-control" value="<?= number_format($salary_cut_display_value, 2, '.', '') ?>%" readonly><small class="text-muted">This percentage is calculated automatically from the <?= (int)date('t') ?> days in the current month.</small></div>
  <div class="col-12"><div class="alert alert-light border mb-0"><i class="fas fa-info-circle text-primary mr-1"></i> Only an attendance record marked <strong>Absent</strong> counts as <strong>1 salary-cut day</strong>. No-login days remain pending until an admin updates their attendance status; office closed days are excluded.</div></div>
 </div><div class="card-footer"><span id="rules-feedback" class="text-success mr-3"></span><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Rules</button></div></form></div></div>
 <div class="col-lg-5"><div class="card"><div class="card-header"><h3 class="card-title">Office Closed Days</h3></div><div class="card-body"><form id="closed-day-form" method="post" class="row"><input type="hidden" name="action" value="add_closed_day"><input type="hidden" name="ajax" value="1"><div class="col-5 form-group"><input type="date" required name="closed_date" class="form-control"></div><div class="col-5 form-group"><input type="text" name="closed_title" class="form-control" placeholder="Holiday name"></div><div class="col-2"><button type="submit" class="btn btn-success" title="Save closed day"><i class="fas fa-plus"></i></button></div></form><table class="table table-sm table-bordered"><thead><tr><th>Date</th><th>Reason</th><th></th></tr></thead><tbody id="closed-days-list"><?php while($day=mysqli_fetch_assoc($closed_days)): $past_closed_day=$day['closed_date'] < $today; ?><tr data-closed-day-id="<?= (int)$day['id'] ?>"><td><?= date('d-m-Y', strtotime($day['closed_date'])) ?></td><td><?= htmlspecialchars($day['title']) ?></td><td><button type="button" class="btn btn-danger btn-xs closed-day-delete" data-closed-day-id="<?= (int)$day['id'] ?>" title="<?= $past_closed_day ? 'Past closed days cannot be removed' : 'Remove' ?>" <?= $past_closed_day ? 'disabled' : '' ?>><i class="fas fa-trash"></i></button></td></tr><?php endwhile; ?></tbody></table></div></div></div>
</div>
<div class="card"><div class="card-header"><h3 class="card-title">Today's Attendance Roster</h3><div class="card-tools"><span class="text-muted"><?= date('d-m-Y') ?></span></div></div><div class="card-body table-responsive"><table class="table table-bordered table-sm mb-0"><thead><tr><th>Staff</th><th>Designation</th><th>Desktop Login</th><th>IP Address</th><th>Status</th></tr></thead><tbody><?php while($member=mysqli_fetch_assoc($today_roster)): $status=$member['attendance_status'] ?: ($today_is_closed ? 'closed_day' : (date('H:i:s') > ($settings['absent_after_time'] ?? '12:00:00') ? 'absent' : 'pending')); $auto_absent=!empty($member['is_auto_absent']) && $status==='absent'; $badge=$status==='present'?'success':($status==='late'?'warning':($status==='absent'?'danger':'secondary')); ?><tr><td><?= htmlspecialchars($member['name']) ?> <small class="text-muted">(<?= htmlspecialchars($member['staff_code']) ?>)</small></td><td><?= htmlspecialchars($member['designation']) ?></td><td><?= $auto_absent ? '—' : ($member['login_at'] ? date('h:i A', strtotime($member['login_at'])) : '—') ?></td><td><?= $auto_absent ? '—' : htmlspecialchars($member['login_ip'] ?: '—') ?></td><td><span class="badge badge-<?= $badge ?>"><?= htmlspecialchars(ucwords(str_replace('_',' ',$status))) ?></span><?php if($member['attendance_id']): ?> <button type="button" class="btn btn-outline-secondary btn-xs status-edit" data-id="<?= (int)$member['attendance_id'] ?>" data-status="<?= htmlspecialchars($member['attendance_status']) ?>" title="Edit status"><i class="fas fa-edit"></i></button><?php endif; ?></td></tr><?php endwhile; ?></tbody></table></div></div>
<div class="card"><div class="card-header"><h3 class="card-title">Desktop Login Attendance Log</h3></div><div class="card-body table-responsive"><table id="example1" class="table table-bordered table-striped"><thead><tr><th>Date</th><th>Staff</th><th>Designation</th><th>Login Time</th><th>IP Address</th><th>Device</th><th>Status</th></tr></thead><tbody><?php while($row=mysqli_fetch_assoc($attendance)): $class=$row['attendance_status']==='present'?'success':($row['attendance_status']==='late'?'warning':($row['attendance_status']==='absent'?'danger':'secondary')); $auto_absent=!empty($row['is_auto_absent']) && $row['attendance_status']==='absent'; ?><tr><td><?= date('d-m-Y', strtotime($row['attendance_date'])) ?></td><td><?= htmlspecialchars($row['staff_name']) ?> <small class="text-muted">(<?= htmlspecialchars($row['staff_code']) ?>)</small></td><td><?= htmlspecialchars($row['designation']) ?></td><td><?= $auto_absent ? '—' : date('h:i A', strtotime($row['login_at'])) ?></td><td><?= $auto_absent ? '—' : htmlspecialchars($row['login_ip']) ?></td><td><?= $auto_absent ? 'Auto' : 'Desktop' ?></td><td><span class="badge badge-<?= $class ?> attendance-status-badge"><?= htmlspecialchars(ucwords(str_replace('_',' ',$row['attendance_status']))) ?></span><?php if(is_admin_user()): ?> <a href="attendance.php?edit_attendance=<?= (int)$row['id'] ?>#attendance-status-editor" class="btn btn-outline-secondary btn-xs" title="Edit status"><i class="fas fa-edit"></i></a><?php endif; ?></td></tr><?php endwhile; ?></tbody></table></div></div>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-calendar-check mr-2"></i>Yearly Attendance Summary</h3></div><div class="card-body">
<form method="get" class="row align-items-end mb-3"><div class="col-md-4 form-group mb-md-0"><label>Staff</label><select name="attendance_staff_id" class="form-control"><option value="0">All Staff</option><?php while($attendance_staff=mysqli_fetch_assoc($attendance_staffs)){ ?><option value="<?=$attendance_staff['id']?>" <?=$attendance_staff_id===(int)$attendance_staff['id']?'selected':''?>><?=htmlspecialchars($attendance_staff['name'])?> (<?=htmlspecialchars($attendance_staff['staff_code'])?>)</option><?php } ?></select></div><div class="col-md-3 form-group mb-md-0"><label>Month</label><select name="attendance_summary_month" class="form-control"><option value="0" <?=$attendance_summary_month===0?'selected':''?>>All Months</option><?php for($summary_month=1;$summary_month<=12;$summary_month++): ?><option value="<?=$summary_month?>" <?=$attendance_summary_month===$summary_month?'selected':''?>><?=date('F', mktime(0,0,0,$summary_month,1))?></option><?php endfor; ?></select></div><div class="col-md-3 form-group mb-md-0"><label>Year</label><select name="attendance_year" class="form-control"><?php while($year_row=mysqli_fetch_assoc($attendance_years)){ $year_value=(int)$year_row['attendance_year']; ?><option value="<?=$year_value?>" <?=$attendance_year===$year_value?'selected':''?>><?=$year_value?></option><?php } ?></select></div><div class="col-md-2 mt-3 mt-md-0"><button class="btn btn-primary"><i class="fas fa-filter"></i> View Attendance</button></div></form>
<div class="table-responsive"><table class="table table-bordered table-striped"><thead><tr><th>Staff</th><th>Designation</th><th class="text-center">Present</th><th class="text-center">Late</th><th class="text-center">Absent</th><th class="text-center">Casual Leave</th><th class="text-center">Medical Leave</th><th class="text-center">Office Closed Day</th></tr></thead><tbody><?php while($yearly_summary=mysqli_fetch_assoc($yearly_attendance_summary)){ ?><tr><td><?=htmlspecialchars($yearly_summary['name'])?> <small class="text-muted">(<?=htmlspecialchars($yearly_summary['staff_code'])?>)</small></td><td><?=htmlspecialchars($yearly_summary['designation'] ?: '-')?></td><td class="text-center"><span class="badge badge-success"><?=(int)$yearly_summary['present_count']?></span></td><td class="text-center"><span class="badge badge-warning"><?=(int)$yearly_summary['late_count']?></span></td><td class="text-center"><span class="badge badge-danger"><?=(int)$yearly_summary['absent_count']?></span></td><td class="text-center"><span class="badge badge-info"><?=(int)$yearly_summary['casual_leave_count']?></span></td><td class="text-center"><span class="badge badge-primary"><?=(int)$yearly_summary['medical_leave_count']?></span></td><td class="text-center"><span class="badge badge-secondary"><?=(int)$yearly_summary['closed_day_count']?></span></td></tr><?php } ?></tbody></table></div>
</div></div>
<div class="card"><div class="card-header"><h3 class="card-title">Attendance History — <?=$attendance_year?></h3></div><div class="card-body table-responsive"><table id="attendance-history" class="table table-bordered table-striped"><thead><tr><th>Date</th><th>Staff</th><th>Login Time</th><th>IP Address</th><th>Status</th></tr></thead><tbody><?php while($attendance_row=mysqli_fetch_assoc($yearly_attendance_log)){ $attendance_status=$attendance_row['attendance_status']; $badge=$attendance_status==='present'?'success':($attendance_status==='late'?'warning':($attendance_status==='absent'?'danger':($attendance_status==='casual_leave'?'info':($attendance_status==='medical_leave'?'primary':'secondary')))); ?><tr><td><?=date('d-m-Y',strtotime($attendance_row['attendance_date']))?></td><td><?=htmlspecialchars($attendance_row['name'])?> <small class="text-muted">(<?=htmlspecialchars($attendance_row['staff_code'])?>)</small></td><td><?=$attendance_row['login_at']?date('h:i A',strtotime($attendance_row['login_at'])):'—'?></td><td><?=htmlspecialchars($attendance_row['login_ip'] ?: '—')?></td><td><span class="badge badge-<?=$badge?>"><?=htmlspecialchars(ucwords(str_replace('_',' ',$attendance_status)))?></span></td></tr><?php } ?></tbody></table></div></div>
<div class="modal fade" id="attendanceStatusModal" tabindex="-1"><div class="modal-dialog"><form id="attendance-status-form" class="modal-content"><div class="modal-header"><h5 class="modal-title">Update Attendance Status</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><div class="modal-body"><input type="hidden" name="action" value="update_attendance_status"><input type="hidden" name="ajax" value="1"><input type="hidden" name="attendance_id" id="attendance-status-id"><div class="form-group"><label>Status</label><select name="attendance_status" id="attendance-status-value" class="form-control"><option value="present">Present</option><option value="late">Late</option><option value="absent">Absent</option><option value="closed_day">Office Closed Day</option><option value="casual_leave">Casual Leave</option><option value="medical_leave">Medical Leave</option></select><small class="text-muted">Casual Leave and Medical Leave do not create a salary cut.</small></div><div class="form-group mb-0"><label>Admin Password</label><input type="password" required name="admin_password" class="form-control" autocomplete="current-password"></div></div><div class="modal-footer"><span id="status-feedback" class="text-danger mr-auto"></span><button type="submit" class="btn btn-primary">Update Status</button></div></form></div></div>
<script>
$(function() {
 if ($.fn.DataTable && !$.fn.DataTable.isDataTable('#attendance-history')) {
  $('#attendance-history').DataTable({responsive:true, autoWidth:false, order:[[0,'desc']]});
 }
});
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
 window.location.href = 'attendance.php?edit_attendance=' + encodeURIComponent(button.dataset.id);
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
