<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/staff_helper.php';
require_once '../includes/staff_ledger_helper.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/transaction_helper.php';
require_once '../includes/expense_helper.php';
require_once '../includes/staff_attendance_helper.php';

require_admin_user();
$user_id=(int)$_SESSION['user_id'];
ensure_staff_table($conn); ensure_staff_ledger_table($conn); ensure_staff_attendance_tables($conn); ensure_default_cash_wallet($conn,$user_id); ensure_expense_support_tables($conn, $user_id);
$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    $staff_id=(int)($_POST['staff_id'] ?? 0); $wallet_id=(int)($_POST['wallet_id'] ?? 0);
    $entry_type=$_POST['entry_type'] ?? ''; $entry_date_input=trim($_POST['entry_date'] ?? date('d-m-Y'));
    $entry_date_object = DateTime::createFromFormat('d-m-Y', $entry_date_input);
    $entry_date = $entry_date_object ? $entry_date_object->format('Y-m-d') : '';
    $amount=(float)($_POST['amount'] ?? 0); $note=trim($_POST['note'] ?? '');
    if($note === ''){
        $note = 'General';
    }
    if($staff_id<=0 || $wallet_id<=0 || !in_array($entry_type,['bonus','incentive'],true) || $entry_date==='' || $amount<=0){
        $error='Select staff, wallet and payment type, then enter a valid amount.';
    }else{
        mysqli_begin_transaction($conn);
        try{
            $staff_stmt=mysqli_prepare($conn,"SELECT id FROM staff WHERE id=? AND user_id=? AND status='active' LIMIT 1"); mysqli_stmt_bind_param($staff_stmt,'ii',$staff_id,$user_id); mysqli_stmt_execute($staff_stmt);
            if(!mysqli_fetch_assoc(mysqli_stmt_get_result($staff_stmt))){ throw new Exception('Selected staff is not active.'); }
            $wallet_stmt=mysqli_prepare($conn,"SELECT id FROM wallets WHERE id=? AND user_id=? AND status='active' LIMIT 1"); mysqli_stmt_bind_param($wallet_stmt,'ii',$wallet_id,$user_id); mysqli_stmt_execute($wallet_stmt);
            if(!mysqli_fetch_assoc(mysqli_stmt_get_result($wallet_stmt))){ throw new Exception('Selected wallet is not available.'); }
            $txn_no=generate_short_unique_txn_no($conn,'STP','staff_ledger_entries'); $created_by=(int)($_SESSION['login_user_id'] ?? $user_id);
            $insert=mysqli_prepare($conn,"INSERT INTO staff_ledger_entries (txn_no,user_id,staff_id,wallet_id,entry_type,entry_date,amount,note,created_by) VALUES (?,?,?,?,?,?,?,?,?)");
            mysqli_stmt_bind_param($insert,'siiissdsi',$txn_no,$user_id,$staff_id,$wallet_id,$entry_type,$entry_date,$amount,$note,$created_by);
            if(!mysqli_stmt_execute($insert)){ throw new Exception(mysqli_stmt_error($insert)); }
            $ledger_id=(int)mysqli_insert_id($conn);
            debit_wallet($conn,$wallet_id,$user_id,$amount);
            $transaction_note='Staff '.staff_ledger_type_label($entry_type).' payment'.($note!=='' ? ': '.$note : '');
            record_wallet_transaction($conn,$txn_no,$user_id,$wallet_id,'staff_payment',$ledger_id,$amount,$transaction_note,$entry_date);

            $reserved_category_name = reserved_expense_category_name_from_entry_type($entry_type);
            $reserved_category_id = reserved_expense_category_id($conn, $user_id, $reserved_category_name);
            $approved_at = date('Y-m-d H:i:s');
            $expense_note = $note !== '' ? $note : ('Staff ' . staff_ledger_type_label($entry_type) . ' payment');
            $expense_stmt = mysqli_prepare(
                $conn,
                "INSERT INTO expenses
                 (txn_no, user_id, wallet_id, category_id, staff_id, txn_date, amount, note, approval_status, created_by, approved_by, approved_at)
                 VALUES
                 (?, ?, ?, ?, ?, ?, ?, ?, 'approved', ?, ?, ?)"
            );
            mysqli_stmt_bind_param(
                $expense_stmt,
                'siiiisdsiis',
                $txn_no,
                $user_id,
                $wallet_id,
                $reserved_category_id,
                $staff_id,
                $entry_date,
                $amount,
                $expense_note,
                $created_by,
                $created_by,
                $approved_at
            );
            if(!mysqli_stmt_execute($expense_stmt)){ throw new Exception(mysqli_stmt_error($expense_stmt)); }

            mysqli_commit($conn); header('Location: ledger.php?success=1'); exit;
        }catch(Exception $exception){ mysqli_rollback($conn); $error=$exception->getMessage(); }
    }
}

$staffs=mysqli_query($conn,"SELECT id,staff_code,name FROM staff WHERE user_id={$user_id} AND status='active' ORDER BY name ASC");
$wallets=active_wallets_result($conn,$user_id);
$ledger_stmt=mysqli_prepare($conn,"SELECT l.*,s.staff_code,s.name,w.wallet_name FROM staff_ledger_entries l INNER JOIN staff s ON s.id=l.staff_id AND s.user_id=l.user_id LEFT JOIN wallets w ON w.id=l.wallet_id AND w.user_id=l.user_id WHERE l.user_id=? ORDER BY l.entry_date DESC,l.id DESC"); mysqli_stmt_bind_param($ledger_stmt,'i',$user_id); mysqli_stmt_execute($ledger_stmt); $ledger=mysqli_stmt_get_result($ledger_stmt);

// Yearly attendance is kept alongside the payment ledger so payroll decisions
// can be reviewed without navigating away from the selected staff member.
$attendance_staff_id=(int)($_GET['attendance_staff_id'] ?? 0);
$attendance_year=(int)($_GET['attendance_year'] ?? date('Y'));
if($attendance_year < 2000 || $attendance_year > ((int)date('Y') + 1)){ $attendance_year=(int)date('Y'); }
$attendance_start=$attendance_year.'-01-01'; $attendance_end=$attendance_year.'-12-31';
$attendance_staffs=mysqli_query($conn,"SELECT id,staff_code,name FROM staff WHERE user_id={$user_id} ORDER BY name ASC");
$years_result=mysqli_query($conn,"SELECT DISTINCT YEAR(attendance_date) AS attendance_year FROM staff_attendance_logs WHERE user_id={$user_id} UNION SELECT ".(int)date('Y')." ORDER BY attendance_year DESC");
$staff_filter=$attendance_staff_id>0 ? ' AND s.id='.(int)$attendance_staff_id : '';
$attendance_summary=mysqli_query($conn,"SELECT s.id,s.name,s.staff_code,s.designation,
    COALESCE(SUM(a.attendance_status='present'),0) AS present_count,
    COALESCE(SUM(a.attendance_status='late'),0) AS late_count,
    COALESCE(SUM(a.attendance_status='absent'),0) AS absent_count,
    COALESCE(SUM(a.attendance_status='casual_leave'),0) AS casual_leave_count,
    COALESCE(SUM(a.attendance_status='medical_leave'),0) AS medical_leave_count,
    COALESCE(SUM(a.attendance_status='closed_day'),0) AS closed_day_count
    FROM staff s LEFT JOIN staff_attendance_logs a ON a.staff_id=s.id AND a.user_id=s.user_id AND a.attendance_date BETWEEN '".mysqli_real_escape_string($conn,$attendance_start)."' AND '".mysqli_real_escape_string($conn,$attendance_end)."'
    WHERE s.user_id={$user_id}{$staff_filter} GROUP BY s.id,s.name,s.staff_code,s.designation ORDER BY s.name ASC");
$attendance_log=mysqli_query($conn,"SELECT a.*,s.name,s.staff_code,s.designation FROM staff_attendance_logs a INNER JOIN staff s ON s.id=a.staff_id AND s.user_id=a.user_id WHERE a.user_id={$user_id} AND a.attendance_date BETWEEN '".mysqli_real_escape_string($conn,$attendance_start)."' AND '".mysqli_real_escape_string($conn,$attendance_end)."'".($attendance_staff_id>0 ? ' AND a.staff_id='.(int)$attendance_staff_id : '')." ORDER BY a.attendance_date DESC,a.login_at DESC");
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<link rel="stylesheet" href="../adminlte/plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css">
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-book mr-2"></i>Staff Salary, Bonus & Incentive Ledger</h3></div><div class="card-body">
<?php if(isset($_GET['success'])){ ?><div class="alert alert-success">Staff payment recorded and deducted from the selected wallet.</div><?php } ?><?php if(isset($_GET['updated'])){ ?><div class="alert alert-success">Ledger entry updated and wallet balance adjusted.</div><?php } ?><?php if(isset($_GET['deleted'])){ ?><div class="alert alert-success">Ledger entry deleted and wallet balance restored.</div><?php } ?><?php if(isset($_GET['error'])){ ?><div class="alert alert-danger"><?=htmlspecialchars($_GET['error'])?></div><?php } ?><?php if($error!==''){ ?><div class="alert alert-danger"><?=htmlspecialchars($error)?></div><?php } ?>
<form method="post"><div class="row"><div class="col-md-3 form-group"><label>Staff</label><select name="staff_id" class="form-control" required><option value="">Select Staff</option><?php while($staff=mysqli_fetch_assoc($staffs)){ ?><option value="<?=$staff['id']?>"><?=htmlspecialchars($staff['name'])?> (<?=htmlspecialchars($staff['staff_code'])?>)</option><?php } ?></select></div><div class="col-md-2 form-group"><label>Payment Type</label><select name="entry_type" class="form-control" required><option value="bonus">Bonus</option><option value="incentive">Incentive</option></select></div><div class="col-md-3 form-group"><label>Wallet</label><select name="wallet_id" class="form-control" required><option value="">Select Wallet</option><?php while($wallet=mysqli_fetch_assoc($wallets)){ ?><option value="<?=$wallet['id']?>"><?=htmlspecialchars($wallet['wallet_name'])?> — BDT <?=number_format((float)$wallet['balance'],2)?></option><?php } ?></select></div><div class="col-md-2 form-group"><label>Amount</label><input type="number" name="amount" min="0.01" step="0.01" class="form-control" required></div><div class="col-md-2 form-group"><label>Date</label><div class="input-group date" id="entry_date_picker" data-target-input="nearest"><input type="text" name="entry_date" class="form-control datetimepicker-input" data-target="#entry_date_picker" value="<?=htmlspecialchars($_POST['entry_date'] ?? date('d-m-Y'))?>" required><div class="input-group-append" data-target="#entry_date_picker" data-toggle="datetimepicker"><div class="input-group-text"><i class="fa fa-calendar"></i></div></div></div></div></div><div class="form-group"><label>Note</label><textarea name="note" rows="2" class="form-control" placeholder="Optional note"></textarea></div><button class="btn btn-primary"><i class="fas fa-save"></i> Save Ledger Entry</button></form>
</div></div>
<div class="card"><div class="card-header"><h3 class="card-title">Ledger History</h3></div><div class="card-body"><table id="example1" class="table table-bordered table-striped"><thead><tr><th>Transaction No.</th><th>Date</th><th>Staff</th><th>Type</th><th>Wallet</th><th>Amount</th><th>Note</th><th width="100">Action</th></tr></thead><tbody><?php while($row=mysqli_fetch_assoc($ledger)){ ?><tr><td><?=htmlspecialchars($row['txn_no'])?></td><td><?=htmlspecialchars(app_date($row['entry_date']))?></td><td><?=htmlspecialchars($row['name'])?> <small class="text-muted">(<?=htmlspecialchars($row['staff_code'])?>)</small></td><td><span class="badge badge-info"><?=htmlspecialchars(staff_ledger_type_label($row['entry_type']))?></span></td><td><?=htmlspecialchars($row['wallet_name'] ?? '-')?></td><td>BDT <?=number_format((float)$row['amount'],2)?></td><td><?=htmlspecialchars($row['note'] ?? '')?></td><td><a href="ledger_edit.php?id=<?=(int)$row['id']?>" class="btn btn-warning btn-sm" title="Edit"><i class="fas fa-edit"></i></a><a href="ledger_delete.php?id=<?=(int)$row['id']?>" class="btn btn-danger btn-sm" title="Delete" onclick="return confirm('Delete this ledger entry? The wallet balance will be restored.');"><i class="fas fa-trash"></i></a></td></tr><?php } ?></tbody></table></div></div>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-calendar-check mr-2"></i>Yearly Attendance Summary</h3></div><div class="card-body">
<form method="get" class="row align-items-end mb-3"><div class="col-md-4 form-group mb-md-0"><label>Staff</label><select name="attendance_staff_id" class="form-control"><option value="0">All Staff</option><?php while($attendance_staff=mysqli_fetch_assoc($attendance_staffs)){ ?><option value="<?=$attendance_staff['id']?>" <?=$attendance_staff_id===(int)$attendance_staff['id']?'selected':''?>><?=htmlspecialchars($attendance_staff['name'])?> (<?=htmlspecialchars($attendance_staff['staff_code'])?>)</option><?php } ?></select></div><div class="col-md-3 form-group mb-md-0"><label>Year</label><select name="attendance_year" class="form-control"><?php while($year_row=mysqli_fetch_assoc($years_result)){ $year_value=(int)$year_row['attendance_year']; ?><option value="<?=$year_value?>" <?=$attendance_year===$year_value?'selected':''?>><?=$year_value?></option><?php } ?></select></div><div class="col-md-2"><button class="btn btn-primary"><i class="fas fa-filter"></i> View Attendance</button></div></form>
<div class="table-responsive"><table class="table table-bordered table-striped"><thead><tr><th>Staff</th><th>Designation</th><th class="text-center">Present</th><th class="text-center">Late</th><th class="text-center">Absent</th><th class="text-center">Casual Leave</th><th class="text-center">Medical Leave</th><th class="text-center">Office Closed Day</th></tr></thead><tbody><?php while($summary=mysqli_fetch_assoc($attendance_summary)){ ?><tr><td><?=htmlspecialchars($summary['name'])?> <small class="text-muted">(<?=htmlspecialchars($summary['staff_code'])?>)</small></td><td><?=htmlspecialchars($summary['designation'] ?: '-')?></td><td class="text-center"><span class="badge badge-success"><?=(int)$summary['present_count']?></span></td><td class="text-center"><span class="badge badge-warning"><?=(int)$summary['late_count']?></span></td><td class="text-center"><span class="badge badge-danger"><?=(int)$summary['absent_count']?></span></td><td class="text-center"><span class="badge badge-info"><?=(int)$summary['casual_leave_count']?></span></td><td class="text-center"><span class="badge badge-primary"><?=(int)$summary['medical_leave_count']?></span></td><td class="text-center"><span class="badge badge-secondary"><?=(int)$summary['closed_day_count']?></span></td></tr><?php } ?></tbody></table></div>
</div></div>
<div class="card"><div class="card-header"><h3 class="card-title">Attendance History — <?=$attendance_year?></h3></div><div class="card-body table-responsive"><table id="attendance-history" class="table table-bordered table-striped"><thead><tr><th>Date</th><th>Staff</th><th>Login Time</th><th>IP Address</th><th>Status</th></tr></thead><tbody><?php while($attendance_row=mysqli_fetch_assoc($attendance_log)){ $attendance_status=$attendance_row['attendance_status']; $badge=$attendance_status==='present'?'success':($attendance_status==='late'?'warning':($attendance_status==='absent'?'danger':($attendance_status==='casual_leave'?'info':($attendance_status==='medical_leave'?'primary':'secondary')))); ?><tr><td><?=date('d-m-Y',strtotime($attendance_row['attendance_date']))?></td><td><?=htmlspecialchars($attendance_row['name'])?> <small class="text-muted">(<?=htmlspecialchars($attendance_row['staff_code'])?>)</small></td><td><?=$attendance_row['login_at']?date('h:i A',strtotime($attendance_row['login_at'])):'—'?></td><td><?=htmlspecialchars($attendance_row['login_ip'] ?: '—')?></td><td><span class="badge badge-<?=$badge?>"><?=htmlspecialchars(ucwords(str_replace('_',' ',$attendance_status)))?></span></td></tr><?php } ?></tbody></table></div></div>
<?php
$page_script = '<script src="../adminlte/plugins/moment/moment.min.js"></script><script src="../adminlte/plugins/tempusdominus-bootstrap-4/js/tempusdominus-bootstrap-4.min.js"></script><script>$(function(){ $("#entry_date_picker").datetimepicker({format:"DD-MM-YYYY", icons:{time:"far fa-clock", date:"far fa-calendar", up:"fas fa-arrow-up", down:"fas fa-arrow-down", previous:"fas fa-chevron-left", next:"fas fa-chevron-right", today:"far fa-calendar-check", clear:"far fa-trash-alt", close:"far fa-times-circle"}}); $("#attendance-history").DataTable({"responsive":true,"autoWidth":false,"order":[[0,"desc"]}); });</script>';
require_once '../includes/footer.php';
?>
