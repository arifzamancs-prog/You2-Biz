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
        $start = $_POST['office_start_time'] ?? '09:00';
        $late_after = $_POST['late_after_time'] ?? '09:15';
        $late_days = max(1, (int)($_POST['late_days_for_salary_cut'] ?? 3));
        // Every completed late-day threshold creates one salary-cut day.
        // The cut value is a fixed BDT amount for each such day.
        $cut_type = 'fixed';
        $cut_value = max(0, (float)($_POST['salary_cut_value'] ?? 0));
        $stmt = mysqli_prepare($conn, "UPDATE staff_attendance_settings SET office_start_time=?, late_after_time=?, late_days_for_salary_cut=?, salary_cut_type=?, salary_cut_value=? WHERE user_id=?");
        mysqli_stmt_bind_param($stmt, 'ssisdi', $start, $late_after, $late_days, $cut_type, $cut_value, $user_id);
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
        $stmt = mysqli_prepare($conn, 'DELETE FROM staff_office_closed_days WHERE id=? AND user_id=?');
        mysqli_stmt_bind_param($stmt, 'ii', $id, $user_id);
        mysqli_stmt_execute($stmt);
        $message = 'Closed day removed.';
    }

    if($is_ajax){
        header('Content-Type: application/json; charset=utf-8');
        if($action === 'add_closed_day' && empty($saved_day)){
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Please select a closed date.']);
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

$closed_stmt = mysqli_prepare($conn, 'SELECT * FROM staff_office_closed_days WHERE user_id=? AND closed_date>=? ORDER BY closed_date ASC LIMIT 12');
mysqli_stmt_bind_param($closed_stmt, 'is', $user_id, $today);
mysqli_stmt_execute($closed_stmt);
$closed_days = mysqli_stmt_get_result($closed_stmt);

$today_roster_stmt = mysqli_prepare($conn, "SELECT s.id, s.name, s.staff_code, s.designation, a.login_at, a.login_ip, a.attendance_status
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
  <div class="col-md-6 form-group"><label>Late Days Per Salary Cut</label><input type="number" min="1" class="form-control" name="late_days_for_salary_cut" value="<?= (int)$settings['late_days_for_salary_cut'] ?>"><small class="text-muted">Example: 3 means every 3 late days will count as 1 salary-cut day.</small></div>
  <div class="col-md-6 form-group"><label>Cut Value Per Day (BDT)</label><input type="number" min="0" step="0.01" class="form-control" name="salary_cut_value" value="<?= htmlspecialchars($settings['salary_cut_value']) ?>"><small class="text-muted">This amount will be deducted for each salary-cut day.</small></div>
 </div><div class="card-footer"><span id="rules-feedback" class="text-success mr-3"></span><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Rules</button></div></form></div></div>
 <div class="col-lg-5"><div class="card"><div class="card-header"><h3 class="card-title">Office Closed Days</h3></div><div class="card-body"><form id="closed-day-form" method="post" class="row"><input type="hidden" name="action" value="add_closed_day"><input type="hidden" name="ajax" value="1"><div class="col-5 form-group"><input type="date" required name="closed_date" class="form-control"></div><div class="col-5 form-group"><input type="text" name="closed_title" class="form-control" placeholder="Holiday name"></div><div class="col-2"><button type="submit" class="btn btn-success" title="Save closed day"><i class="fas fa-plus"></i></button></div></form><table class="table table-sm table-bordered"><thead><tr><th>Date</th><th>Reason</th><th></th></tr></thead><tbody id="closed-days-list"><?php while($day=mysqli_fetch_assoc($closed_days)): ?><tr data-closed-day-id="<?= (int)$day['id'] ?>"><td><?= date('d-m-Y', strtotime($day['closed_date'])) ?></td><td><?= htmlspecialchars($day['title']) ?></td><td><button type="button" class="btn btn-danger btn-xs closed-day-delete" data-closed-day-id="<?= (int)$day['id'] ?>" title="Remove"><i class="fas fa-trash"></i></button></td></tr><?php endwhile; ?></tbody></table></div></div></div>
</div>
<div class="card"><div class="card-header"><h3 class="card-title">Today's Attendance Roster</h3><div class="card-tools"><span class="text-muted"><?= date('d-m-Y') ?></span></div></div><div class="card-body table-responsive"><table class="table table-bordered table-sm mb-0"><thead><tr><th>Staff</th><th>Designation</th><th>Desktop Login</th><th>IP Address</th><th>Status</th></tr></thead><tbody><?php while($member=mysqli_fetch_assoc($today_roster)): $status=$member['attendance_status'] ?: ($today_is_closed ? 'closed_day' : (date('H:i:s') > $settings['late_after_time'] ? 'absent' : 'pending')); $badge=$status==='present'?'success':($status==='late'?'warning':($status==='absent'?'danger':'secondary')); ?><tr><td><?= htmlspecialchars($member['name']) ?> <small class="text-muted">(<?= htmlspecialchars($member['staff_code']) ?>)</small></td><td><?= htmlspecialchars($member['designation']) ?></td><td><?= $member['login_at'] ? date('h:i A', strtotime($member['login_at'])) : '—' ?></td><td><?= htmlspecialchars($member['login_ip'] ?: '—') ?></td><td><span class="badge badge-<?= $badge ?>"><?= htmlspecialchars(ucwords(str_replace('_',' ',$status))) ?></span></td></tr><?php endwhile; ?></tbody></table></div></div>
<div class="card"><div class="card-header"><h3 class="card-title">Desktop Login Attendance Log</h3></div><div class="card-body table-responsive"><table id="example1" class="table table-bordered table-striped"><thead><tr><th>Date</th><th>Staff</th><th>Designation</th><th>Login Time</th><th>IP Address</th><th>Device</th><th>Status</th></tr></thead><tbody><?php while($row=mysqli_fetch_assoc($attendance)): $class=$row['attendance_status']==='present'?'success':($row['attendance_status']==='late'?'warning':'secondary'); ?><tr><td><?= date('d-m-Y', strtotime($row['attendance_date'])) ?></td><td><?= htmlspecialchars($row['staff_name']) ?> <small class="text-muted">(<?= htmlspecialchars($row['staff_code']) ?>)</small></td><td><?= htmlspecialchars($row['designation']) ?></td><td><?= date('h:i A', strtotime($row['login_at'])) ?></td><td><?= htmlspecialchars($row['login_ip']) ?></td><td>Desktop</td><td><span class="badge badge-<?= $class ?>"><?= htmlspecialchars(ucwords(str_replace('_',' ',$row['attendance_status']))) ?></span></td></tr><?php endwhile; ?></tbody></table></div></div>
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
</script>
<?php require_once '../includes/footer.php'; ?>
