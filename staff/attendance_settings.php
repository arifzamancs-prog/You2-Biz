<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/staff_attendance_helper.php';
require_once '../includes/branch_helper.php';

require_admin_user();
$user_id = (int)$_SESSION['user_id'];
ensure_staff_attendance_tables($conn);
ensure_head_office_branch($conn, $user_id);
$multi_branch_enabled = company_multi_branch_enabled($conn, $user_id);
$branch_access_sql = $multi_branch_enabled ? '' : ' AND is_head_office=1';
$message = '';
$message_type = 'success';
$is_ajax = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === '1';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $action = $_POST['action'] ?? '';
    $selected_branch_id = max(0, (int)($_POST['branch_id'] ?? 0));
    if(!$multi_branch_enabled && $selected_branch_id > 0){
        require_company_multi_branch($conn, $user_id);
    }
    if($selected_branch_id > 0){
        $branch_check = mysqli_prepare($conn, "SELECT id FROM branches WHERE id=? AND user_id=? AND status='active'{$branch_access_sql} LIMIT 1");
        mysqli_stmt_bind_param($branch_check, 'ii', $selected_branch_id, $user_id);
        mysqli_stmt_execute($branch_check);
        if(!mysqli_fetch_assoc(mysqli_stmt_get_result($branch_check))){
            $message = 'Selected branch was not found.';
            $message_type = 'danger';
            $selected_branch_id = 0;
        }
    }
    if($action === 'save_settings'){
        $start = $_POST['office_start_time'] ?? '10:00';
        $late_after = $_POST['late_after_time'] ?? '10:15';
        $absent_after = $_POST['absent_after_time'] ?? '12:00';
        $late_days = max(1, (int)($_POST['late_days_for_salary_cut'] ?? 3));
        $cut_value = round(100 / (int)date('t'), 2);
        $cut_type = 'percentage';
        $stmt = mysqli_prepare($conn, "INSERT INTO staff_attendance_settings (user_id, branch_id, office_start_time, late_after_time, absent_after_time, late_days_for_salary_cut, salary_cut_type, salary_cut_value)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE office_start_time=VALUES(office_start_time), late_after_time=VALUES(late_after_time), absent_after_time=VALUES(absent_after_time), late_days_for_salary_cut=VALUES(late_days_for_salary_cut), salary_cut_type=VALUES(salary_cut_type), salary_cut_value=VALUES(salary_cut_value)");
        mysqli_stmt_bind_param($stmt, 'iisssisd', $user_id, $selected_branch_id, $start, $late_after, $absent_after, $late_days, $cut_type, $cut_value);
        mysqli_stmt_execute($stmt);
        $message = $selected_branch_id > 0 ? 'Branch attendance rules updated.' : 'Default attendance rules updated for all branches.';
    }elseif($action === 'add_closed_day'){
        $date = $_POST['closed_date'] ?? '';
        $title = trim($_POST['closed_title'] ?? '') ?: 'Office Closed';
        if($date === ''){
            $message = 'Please select a date.';
            $message_type = 'danger';
        }else{
            $stmt = mysqli_prepare($conn, 'INSERT INTO staff_office_closed_days (user_id, branch_id, closed_date, title) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title)');
            mysqli_stmt_bind_param($stmt, 'iiss', $user_id, $selected_branch_id, $date, $title);
            mysqli_stmt_execute($stmt);
            $saved_day_stmt = mysqli_prepare($conn, 'SELECT id, closed_date, title FROM staff_office_closed_days WHERE user_id=? AND branch_id=? AND closed_date=? LIMIT 1');
            mysqli_stmt_bind_param($saved_day_stmt, 'iis', $user_id, $selected_branch_id, $date);
            mysqli_stmt_execute($saved_day_stmt);
            $saved_day = mysqli_fetch_assoc(mysqli_stmt_get_result($saved_day_stmt)) ?: null;
            $message = 'Office closed day saved.';
        }
    }elseif($action === 'delete_closed_day'){
        $id = (int)($_POST['closed_day_id'] ?? 0);
        $day_stmt = mysqli_prepare($conn, 'SELECT closed_date FROM staff_office_closed_days WHERE id=? AND user_id=? AND branch_id=? LIMIT 1');
        mysqli_stmt_bind_param($day_stmt, 'iii', $id, $user_id, $selected_branch_id);
        mysqli_stmt_execute($day_stmt);
        $day = mysqli_fetch_assoc(mysqli_stmt_get_result($day_stmt));
        if(!$day || $day['closed_date'] < date('Y-m-d')){
            $message = 'Past office closed days cannot be removed.';
            $message_type = 'danger';
        }else{
            $stmt = mysqli_prepare($conn, 'DELETE FROM staff_office_closed_days WHERE id=? AND user_id=? AND branch_id=?');
            mysqli_stmt_bind_param($stmt, 'iii', $id, $user_id, $selected_branch_id);
            mysqli_stmt_execute($stmt);
            $message = 'Closed day removed.';
        }
    }elseif($action === 'reset_branch_settings' && $selected_branch_id > 0){
        $stmt = mysqli_prepare($conn, 'DELETE FROM staff_attendance_settings WHERE user_id=? AND branch_id=?');
        mysqli_stmt_bind_param($stmt, 'ii', $user_id, $selected_branch_id);
        mysqli_stmt_execute($stmt);
        $days_stmt = mysqli_prepare($conn, 'DELETE FROM staff_office_closed_days WHERE user_id=? AND branch_id=?');
        mysqli_stmt_bind_param($days_stmt, 'ii', $user_id, $selected_branch_id);
        mysqli_stmt_execute($days_stmt);
        $message = 'Branch customization removed. Default settings will now apply.';
    }

    if($is_ajax){
        header('Content-Type: application/json; charset=utf-8');
        if($message_type === 'danger'){
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $message]);
        }else{
            echo json_encode(['success' => true, 'message' => $message, 'closed_day' => $saved_day ?? null]);
        }
        exit;
    }
}

$selected_branch_id = max(0, (int)($_GET['branch_id'] ?? $selected_branch_id ?? 0));
$selected_branch_id = $multi_branch_enabled ? $selected_branch_id : 0;
$branches_stmt = mysqli_prepare($conn, "SELECT id, branch_name, is_head_office FROM branches WHERE user_id=? AND status='active'{$branch_access_sql} ORDER BY is_head_office DESC, branch_name ASC");
mysqli_stmt_bind_param($branches_stmt, 'i', $user_id);
mysqli_stmt_execute($branches_stmt);
$branches = mysqli_stmt_get_result($branches_stmt);
$custom_check = null;
if($selected_branch_id > 0){
    $custom_stmt = mysqli_prepare($conn, 'SELECT id FROM staff_attendance_settings WHERE user_id=? AND branch_id=? LIMIT 1');
    mysqli_stmt_bind_param($custom_stmt, 'ii', $user_id, $selected_branch_id);
    mysqli_stmt_execute($custom_stmt);
    $custom_check = mysqli_fetch_assoc(mysqli_stmt_get_result($custom_stmt));
}
$settings = staff_attendance_settings($conn, $user_id, $selected_branch_id);
$salary_cut_display_value = round(100 / (int)date('t'), 2);
$today = date('Y-m-d');
$closed_stmt = mysqli_prepare($conn, 'SELECT * FROM staff_office_closed_days WHERE user_id=? AND branch_id=? ORDER BY closed_date DESC LIMIT 30');
mysqli_stmt_bind_param($closed_stmt, 'ii', $user_id, $selected_branch_id);
mysqli_stmt_execute($closed_stmt);
$closed_days = mysqli_stmt_get_result($closed_stmt);

require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<?php if($message): ?><div class="alert alert-<?= $message_type ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if($multi_branch_enabled): ?><div class="card card-outline card-info"><div class="card-body py-3"><form method="get" class="form-inline"><label class="mr-3 mb-2 mb-sm-0">Attendance Settings For</label><select name="branch_id" class="form-control mr-2" onchange="this.form.submit()"><option value="0" <?= $selected_branch_id === 0 ? 'selected' : '' ?>>All Branches (Default)</option><?php while($branch = mysqli_fetch_assoc($branches)): ?><option value="<?= (int)$branch['id'] ?>" <?= $selected_branch_id === (int)$branch['id'] ? 'selected' : '' ?>><?= htmlspecialchars($branch['branch_name']) ?><?= (int)$branch['is_head_office'] === 1 ? ' (Head Office)' : '' ?></option><?php endwhile; ?></select><noscript><button type="submit" class="btn btn-primary">Load</button></noscript><?php if($selected_branch_id > 0): ?><span class="ml-2 badge badge-<?= $custom_check ? 'warning' : 'secondary' ?>"><?= $custom_check ? 'Custom settings active' : 'Using all-branch default' ?></span><?php endif; ?></form></div></div><?php endif; ?>
<div class="row">
 <div class="col-lg-7"><div class="card"><div class="card-header"><h3 class="card-title">Attendance Rules</h3></div><form id="attendance-rules-form" method="post"><div class="card-body row">
  <input type="hidden" name="action" value="save_settings"><input type="hidden" name="ajax" value="1"><input type="hidden" name="branch_id" value="<?= $selected_branch_id ?>">
  <div class="col-md-6 form-group"><label>Office Starts</label><input type="time" class="form-control" name="office_start_time" value="<?= htmlspecialchars(substr($settings['office_start_time'],0,5)) ?>"></div>
  <div class="col-md-6 form-group"><label>Late After</label><input type="time" class="form-control" name="late_after_time" value="<?= htmlspecialchars(substr($settings['late_after_time'],0,5)) ?>"></div>
  <div class="col-md-6 form-group"><label>Absent After</label><input type="time" class="form-control" name="absent_after_time" value="<?= htmlspecialchars(substr($settings['absent_after_time'] ?? '12:00:00',0,5)) ?>"><small class="text-muted">Desktop login after this time will be marked Absent.</small></div>
  <div class="col-md-6 form-group"><label>Salary Cut for</label><div class="input-group"><input type="number" min="1" class="form-control" name="late_days_for_salary_cut" value="<?= (int)$settings['late_days_for_salary_cut'] ?>"><div class="input-group-append"><span class="input-group-text">Days late</span></div></div><small class="text-muted">Example: 3 means every 3 late days will count as 1 salary-cut day.</small></div>
  <div class="col-md-6 form-group"><label>Salary Cut Type</label><input type="text" class="form-control" value="Percentage of Salary" readonly><small class="text-muted">Salary cut is always calculated from the staff member's assigned salary.</small></div>
  <div class="col-md-6 form-group"><label>Cut for per Day Absent</label><input type="text" class="form-control" value="<?= number_format($salary_cut_display_value, 2, '.', '') ?>%" readonly><small class="text-muted">This percentage is calculated automatically from the <?= (int)date('t') ?> days in the current month.</small></div>
 </div><div class="card-footer"><span id="rules-feedback" class="text-success mr-3"></span><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= $selected_branch_id > 0 ? 'Save Branch Rules' : 'Save Default Rules' ?></button><?php if($selected_branch_id > 0 && $custom_check): ?><button type="button" id="reset-branch-settings" class="btn btn-outline-secondary ml-2">Use Default Settings</button><?php endif; ?></div></form></div></div>
 <div class="col-lg-5"><div class="card"><div class="card-header"><h3 class="card-title">Office Closed Days</h3></div><div class="card-body"><form id="closed-day-form" method="post" class="row"><input type="hidden" name="action" value="add_closed_day"><input type="hidden" name="ajax" value="1"><input type="hidden" name="branch_id" value="<?= $selected_branch_id ?>"><div class="col-5 form-group"><input type="date" required name="closed_date" class="form-control"></div><div class="col-5 form-group"><input type="text" name="closed_title" class="form-control" placeholder="Holiday name"></div><div class="col-2"><button type="submit" class="btn btn-success" title="Save closed day"><i class="fas fa-plus"></i></button></div></form><table class="table table-sm table-bordered"><thead><tr><th>Date</th><th>Reason</th><th></th></tr></thead><tbody id="closed-days-list"><?php while($day=mysqli_fetch_assoc($closed_days)): $past=$day['closed_date'] < $today; ?><tr data-closed-day-id="<?= (int)$day['id'] ?>"><td><?= date('d-m-Y', strtotime($day['closed_date'])) ?></td><td><?= htmlspecialchars($day['title']) ?></td><td><button type="button" class="btn btn-danger btn-xs closed-day-delete" data-closed-day-id="<?= (int)$day['id'] ?>" title="<?= $past ? 'Past closed days cannot be removed' : 'Remove' ?>" <?= $past ? 'disabled' : '' ?>><i class="fas fa-trash"></i></button></td></tr><?php endwhile; ?></tbody></table></div></div></div>
</div>
<script>
const postAttendanceSettings = async data => { const response = await fetch('attendance_settings.php', {method:'POST', body:data}); const result = await response.json(); if(!response.ok || !result.success) throw new Error(result.message || 'Request could not be completed.'); return result; };
document.getElementById('attendance-rules-form').addEventListener('submit', async function(event){ event.preventDefault(); const button=this.querySelector('button[type="submit"]'); button.disabled=true; try{ const result=await postAttendanceSettings(new FormData(this)); document.getElementById('rules-feedback').textContent=result.message; }catch(error){ document.getElementById('rules-feedback').className='text-danger mr-3'; document.getElementById('rules-feedback').textContent=error.message; }finally{ button.disabled=false; } });
document.getElementById('closed-day-form').addEventListener('submit', async function(event){ event.preventDefault(); const form=this, button=form.querySelector('button[type="submit"]'); button.disabled=true; try{ const result=await postAttendanceSettings(new FormData(form)); const day=result.closed_day, parts=day.closed_date.split('-'), dateText=parts[2]+'-'+parts[1]+'-'+parts[0]; document.getElementById('closed-days-list').insertAdjacentHTML('afterbegin','<tr data-closed-day-id="'+day.id+'"><td>'+dateText+'</td><td></td><td><button type="button" class="btn btn-danger btn-xs closed-day-delete" data-closed-day-id="'+day.id+'" title="Remove"><i class="fas fa-trash"></i></button></td></tr>'); document.querySelector('[data-closed-day-id="'+day.id+'"]').children[1].textContent=day.title; form.reset(); }catch(error){ alert(error.message); }finally{ button.disabled=false; } });
document.getElementById('closed-days-list').addEventListener('click', async function(event){ const button=event.target.closest('.closed-day-delete'); if(!button || button.disabled || !confirm('Remove this office closed day?')) return; button.disabled=true; const data=new FormData(); data.append('action','delete_closed_day'); data.append('ajax','1'); data.append('closed_day_id',button.dataset.closedDayId); data.append('branch_id','<?= $selected_branch_id ?>'); try{ await postAttendanceSettings(data); button.closest('tr').remove(); }catch(error){ alert(error.message); button.disabled=false; } });
const resetButton=document.getElementById('reset-branch-settings'); if(resetButton){ resetButton.addEventListener('click', async function(){ if(!confirm('Remove this branch customization and use all-branch default settings?')) return; this.disabled=true; const data=new FormData(); data.append('action','reset_branch_settings'); data.append('ajax','1'); data.append('branch_id','<?= $selected_branch_id ?>'); try{ const result=await postAttendanceSettings(data); window.location.href='attendance_settings.php?branch_id=<?= $selected_branch_id ?>'; }catch(error){ alert(error.message); this.disabled=false; } }); }
</script>
<?php require_once '../includes/footer.php'; ?>
