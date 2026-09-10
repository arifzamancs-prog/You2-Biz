<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/staff_helper.php';
require_once '../includes/staff_ledger_helper.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/transaction_helper.php';
require_once '../includes/expense_helper.php';
require_once '../includes/staff_attendance_helper.php';

require_staff_manage_access();
$user_id=(int)$_SESSION['user_id'];
// Keep the ledger list available even if an older live database still needs
// expense-table upgrades. Those upgrades are only required when saving a row.
ensure_staff_table($conn); ensure_staff_ledger_table($conn); ensure_default_staff_ledger_payment_types($conn,$user_id); ensure_default_cash_wallet($conn,$user_id);
$error='';
$message='';

if(isset($_SESSION['staff_ledger_flash_error'])){
    $error = (string)$_SESSION['staff_ledger_flash_error'];
    unset($_SESSION['staff_ledger_flash_error']);
}
if(isset($_SESSION['staff_ledger_flash_message'])){
    $message = (string)$_SESSION['staff_ledger_flash_message'];
    unset($_SESSION['staff_ledger_flash_message']);
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $form_action = $_POST['form_action'] ?? 'create_ledger';

    if($form_action === 'create_payment_type' || $form_action === 'update_payment_type'){
        $type_id = (int)($_POST['type_id'] ?? 0);
        $type_name = trim((string)($_POST['type_name'] ?? ''));
        $type_key = staff_ledger_payment_type_key($type_name);

        if($type_name === '' || $type_key === ''){
            $_SESSION['staff_ledger_flash_error'] = 'Payment type name is required.';
            header('Location: ledger.php');
            exit;
        }
        if($type_key === 'salary'){
            $_SESSION['staff_ledger_flash_error'] = 'Salary is a reserved payment type.';
            header('Location: ledger.php');
            exit;
        }

        if($form_action === 'update_payment_type' && $type_id > 0){
            $old_stmt = mysqli_prepare($conn, "SELECT type_key FROM staff_ledger_payment_types WHERE id=? AND user_id=? LIMIT 1");
            mysqli_stmt_bind_param($old_stmt, 'ii', $type_id, $user_id);
            mysqli_stmt_execute($old_stmt);
            $old_type = mysqli_fetch_assoc(mysqli_stmt_get_result($old_stmt));
            if(!$old_type){
                $_SESSION['staff_ledger_flash_error'] = 'Payment type not found.';
                header('Location: ledger.php');
                exit;
            }

            $update_stmt = mysqli_prepare($conn, "UPDATE staff_ledger_payment_types SET type_key=?, type_name=? WHERE id=? AND user_id=?");
            mysqli_stmt_bind_param($update_stmt, 'ssii', $type_key, $type_name, $type_id, $user_id);
            try{
                $updated = mysqli_stmt_execute($update_stmt);
            }catch(Throwable $exception){
                $updated = false;
            }
            if($updated){
                if((string)$old_type['type_key'] !== $type_key){
                    $entry_update = mysqli_prepare($conn, "UPDATE staff_ledger_entries SET entry_type=? WHERE user_id=? AND entry_type=?");
                    mysqli_stmt_bind_param($entry_update, 'sis', $type_key, $user_id, $old_type['type_key']);
                    mysqli_stmt_execute($entry_update);
                }
                $_SESSION['staff_ledger_flash_message'] = 'Payment type updated successfully.';
            }else{
                $_SESSION['staff_ledger_flash_error'] = 'Payment type could not be updated. This name may already exist.';
            }
            header('Location: ledger.php');
            exit;
        }

        $insert_stmt = mysqli_prepare($conn, "INSERT INTO staff_ledger_payment_types (user_id, type_key, type_name) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($insert_stmt, 'iss', $user_id, $type_key, $type_name);
        try{
            $created = mysqli_stmt_execute($insert_stmt);
        }catch(Throwable $exception){
            $created = false;
        }
        if($created){
            $_SESSION['staff_ledger_flash_message'] = 'Payment type created successfully.';
        }else{
            $_SESSION['staff_ledger_flash_error'] = 'Payment type could not be created. This name may already exist.';
        }
        header('Location: ledger.php');
        exit;
    }

    if($form_action === 'delete_payment_type'){
        $type_id = (int)($_POST['type_id'] ?? 0);
        $type_stmt = mysqli_prepare($conn, "SELECT type_key FROM staff_ledger_payment_types WHERE id=? AND user_id=? LIMIT 1");
        mysqli_stmt_bind_param($type_stmt, 'ii', $type_id, $user_id);
        mysqli_stmt_execute($type_stmt);
        $type = mysqli_fetch_assoc(mysqli_stmt_get_result($type_stmt));
        if(!$type){
            $_SESSION['staff_ledger_flash_error'] = 'Payment type not found.';
        }elseif(staff_ledger_payment_type_used($conn, $user_id, $type['type_key'])){
            $_SESSION['staff_ledger_flash_error'] = 'This payment type has ledger history and cannot be deleted.';
        }else{
            $delete_stmt = mysqli_prepare($conn, "DELETE FROM staff_ledger_payment_types WHERE id=? AND user_id=?");
            mysqli_stmt_bind_param($delete_stmt, 'ii', $type_id, $user_id);
            $_SESSION['staff_ledger_flash_message'] = mysqli_stmt_execute($delete_stmt)
                ? 'Payment type deleted successfully.'
                : 'Payment type could not be deleted.';
        }
        header('Location: ledger.php');
        exit;
    }

    $staff_id=(int)($_POST['staff_id'] ?? 0); $wallet_id=(int)($_POST['wallet_id'] ?? 0);
    $entry_type=staff_ledger_payment_type_key($_POST['entry_type'] ?? ''); $entry_date_input=trim($_POST['entry_date'] ?? date('d-m-Y'));
    $entry_date_object = DateTime::createFromFormat('d-m-Y', $entry_date_input);
    $entry_date = $entry_date_object ? $entry_date_object->format('Y-m-d') : '';
    $amount=(float)($_POST['amount'] ?? 0); $note=trim($_POST['note'] ?? '');
    if($note === ''){
        $note = 'General';
    }
    if($staff_id<=0 || $wallet_id<=0 || !staff_ledger_payment_type_exists($conn,$user_id,$entry_type) || $entry_date==='' || $amount<=0){
        $_SESSION['staff_ledger_flash_error'] = 'Select staff, wallet and payment type, then enter a valid amount.';
        header('Location: ledger.php');
        exit;
    }else{
        mysqli_begin_transaction($conn);
        try{
            ensure_expense_support_tables($conn, $user_id);
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
            record_wallet_transaction($conn,$txn_no,$user_id,$wallet_id,'expense',$ledger_id,$amount,$transaction_note,$entry_date);

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
        }catch(Exception $exception){
            mysqli_rollback($conn);
            $_SESSION['staff_ledger_flash_error'] = $exception->getMessage();
            header('Location: ledger.php');
            exit;
        }
    }
}

$staffs=mysqli_query($conn,"SELECT id,staff_code,name FROM staff WHERE user_id={$user_id} AND status='active' ORDER BY name ASC");
$wallets=active_wallets_result($conn,$user_id);
$payment_types=staff_ledger_payment_types($conn,$user_id);
$payment_type_rows=[];
$all_payment_types=staff_ledger_payment_types($conn,$user_id,false);
while($type_row=mysqli_fetch_assoc($all_payment_types)){ $payment_type_rows[]=$type_row; }
$ledger_stmt=mysqli_prepare($conn,"SELECT l.*,s.staff_code,s.name,w.wallet_name FROM staff_ledger_entries l INNER JOIN staff s ON s.id=l.staff_id AND s.user_id=l.user_id LEFT JOIN wallets w ON w.id=l.wallet_id AND w.user_id=l.user_id WHERE l.user_id=? ORDER BY l.entry_date DESC,l.id DESC"); mysqli_stmt_bind_param($ledger_stmt,'i',$user_id); mysqli_stmt_execute($ledger_stmt); $ledger=mysqli_stmt_get_result($ledger_stmt);

require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<link rel="stylesheet" href="../adminlte/plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css">
<style>
.payment-type-tools .input-group{max-width:820px}
.payment-type-table td{vertical-align:middle}
.payment-type-edit-row{display:none}
.payment-type-row.is-editing .payment-type-view-row{display:none}
.payment-type-row.is-editing .payment-type-edit-row{display:flex}
.payment-type-name{font-weight:600}
.payment-type-key{font-size:12px;color:#6c757d}
</style>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-book mr-2"></i>Staff Salary, Bonus & Incentive Ledger</h3></div><div class="card-body">
<?php if(isset($_GET['success'])){ ?><div class="alert alert-success">Staff payment recorded and deducted from the selected wallet.</div><?php } ?><?php if(isset($_GET['updated'])){ ?><div class="alert alert-success">Ledger entry updated and wallet balance adjusted.</div><?php } ?><?php if(isset($_GET['deleted'])){ ?><div class="alert alert-success">Ledger entry deleted and wallet balance restored.</div><?php } ?><?php if($message!==''){ ?><div class="alert alert-success"><?=htmlspecialchars($message)?></div><?php } ?><?php if(isset($_GET['error'])){ ?><div class="alert alert-danger"><?=htmlspecialchars($_GET['error'])?></div><?php } ?><?php if($error!==''){ ?><div class="alert alert-danger"><?=htmlspecialchars($error)?></div><?php } ?>
<form method="post"><input type="hidden" name="form_action" value="create_ledger"><div class="row"><div class="col-md-3 form-group"><label>Staff</label><select name="staff_id" class="form-control" required><option value="">Select Staff</option><?php while($staff=mysqli_fetch_assoc($staffs)){ ?><option value="<?=$staff['id']?>"><?=htmlspecialchars($staff['name'])?> (<?=htmlspecialchars($staff['staff_code'])?>)</option><?php } ?></select></div><div class="col-md-2 form-group"><label>Payment Type</label><select name="entry_type" class="form-control" required><option value="">Select Type</option><?php while($type=mysqli_fetch_assoc($payment_types)){ ?><option value="<?=htmlspecialchars($type['type_key'])?>"><?=htmlspecialchars($type['type_name'])?></option><?php } ?></select></div><div class="col-md-3 form-group"><label>Wallet</label><select name="wallet_id" class="form-control" required><option value="">Select Wallet</option><?php while($wallet=mysqli_fetch_assoc($wallets)){ ?><option value="<?=$wallet['id']?>"><?=htmlspecialchars($wallet['wallet_name'])?> — BDT <?=number_format((float)$wallet['balance'],2)?></option><?php } ?></select></div><div class="col-md-2 form-group"><label>Amount</label><input type="number" name="amount" min="0.01" step="0.01" class="form-control" required></div><div class="col-md-2 form-group"><label>Date</label><div class="input-group date" id="entry_date_picker" data-target-input="nearest"><input type="text" name="entry_date" class="form-control datetimepicker-input" data-target="#entry_date_picker" value="<?=htmlspecialchars($_POST['entry_date'] ?? date('d-m-Y'))?>" required><div class="input-group-append" data-target="#entry_date_picker" data-toggle="datetimepicker"><div class="input-group-text"><i class="fa fa-calendar"></i></div></div></div></div></div><div class="form-group"><label>Note</label><textarea name="note" rows="2" class="form-control" placeholder="Optional note"></textarea></div><button class="btn btn-primary"><i class="fas fa-save"></i> Save Ledger Entry</button></form>
</div></div>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-list mr-2"></i>Payment Type Options</h3></div><div class="card-body payment-type-tools"><form method="post" class="mb-3"><input type="hidden" name="form_action" value="create_payment_type"><label>New Payment Type</label><div class="input-group"><input type="text" name="type_name" class="form-control" placeholder="Example: Festival Bonus" required><div class="input-group-append"><button class="btn btn-primary"><i class="fas fa-plus"></i> Create Type</button></div></div></form><div class="table-responsive"><table class="table table-bordered table-hover table-sm mb-0 payment-type-table"><thead><tr><th>Payment Type</th><th width="180">Action</th></tr></thead><tbody><?php foreach($payment_type_rows as $type){ $type_used=staff_ledger_payment_type_used($conn,$user_id,$type['type_key']); $update_form_id='payment_type_update_'.(int)$type['id']; ?><tr class="payment-type-row"><td><div class="payment-type-view-row"><div class="payment-type-name"><?=htmlspecialchars($type['type_name'])?></div><div class="payment-type-key"><?=htmlspecialchars($type['type_key'])?></div></div><form id="<?=$update_form_id?>" method="post" class="payment-type-edit-row mb-0"><input type="hidden" name="form_action" value="update_payment_type"><input type="hidden" name="type_id" value="<?= (int)$type['id'] ?>"><input type="text" name="type_name" class="form-control form-control-sm" value="<?=htmlspecialchars($type['type_name'])?>" required></form></td><td><div class="payment-type-view-row"><button type="button" class="btn btn-info btn-sm payment-type-edit-btn" title="Edit"><i class="fas fa-edit"></i></button> <form method="post" class="d-inline" onsubmit="return confirm('Delete this payment type?');"><input type="hidden" name="form_action" value="delete_payment_type"><input type="hidden" name="type_id" value="<?= (int)$type['id'] ?>"><button class="btn btn-danger btn-sm" <?= $type_used ? 'disabled title="This type has ledger history"' : 'title="Delete"' ?>><i class="fas fa-trash"></i></button></form></div><div class="payment-type-edit-row"><button form="<?=$update_form_id?>" class="btn btn-success btn-sm" title="Save"><i class="fas fa-save"></i></button> <button type="button" class="btn btn-secondary btn-sm payment-type-cancel-btn" title="Cancel"><i class="fas fa-times"></i></button></div></td></tr><?php } ?></tbody></table></div></div></div>
<div class="card"><div class="card-header"><h3 class="card-title">Ledger History</h3></div><div class="card-body"><table id="example1" class="table table-bordered table-striped"><thead><tr><th>Transaction No.</th><th>Date</th><th>Staff</th><th>Type</th><th>Wallet</th><th>Amount</th><th>Note</th><th width="100">Action</th></tr></thead><tbody><?php while($row=mysqli_fetch_assoc($ledger)){ ?><tr><td><?=htmlspecialchars($row['txn_no'])?></td><td><?=htmlspecialchars(app_date($row['entry_date']))?></td><td><?=htmlspecialchars($row['name'])?> <small class="text-muted">(<?=htmlspecialchars($row['staff_code'])?>)</small></td><td><span class="badge badge-info"><?=htmlspecialchars(staff_ledger_type_label($row['entry_type']))?></span></td><td><?=htmlspecialchars($row['wallet_name'] ?? '-')?></td><td>BDT <?=number_format((float)$row['amount'],2)?></td><td><?=htmlspecialchars($row['note'] ?? '')?></td><td><a href="ledger_edit.php?id=<?=(int)$row['id']?>" class="btn btn-warning btn-sm" title="Edit"><i class="fas fa-edit"></i></a><a href="ledger_delete.php?id=<?=(int)$row['id']?>" class="btn btn-danger btn-sm" title="Delete" onclick="return confirm('Delete this ledger entry? The wallet balance will be restored.');"><i class="fas fa-trash"></i></a></td></tr><?php } ?></tbody></table></div></div>
<?php
$page_script = '<script src="../adminlte/plugins/moment/moment.min.js"></script><script src="../adminlte/plugins/tempusdominus-bootstrap-4/js/tempusdominus-bootstrap-4.min.js"></script><script>$(function(){ $("#entry_date_picker").datetimepicker({format:"DD-MM-YYYY", icons:{time:"far fa-clock", date:"far fa-calendar", up:"fas fa-arrow-up", down:"fas fa-arrow-down", previous:"fas fa-chevron-left", next:"fas fa-chevron-right", today:"far fa-calendar-check", clear:"far fa-trash-alt", close:"far fa-times-circle"}}); $(".payment-type-edit-btn").on("click",function(){ var row=$(this).closest(".payment-type-row"); $(".payment-type-row").not(row).removeClass("is-editing"); row.addClass("is-editing"); row.find("input[name=type_name]").trigger("focus").select(); }); $(".payment-type-cancel-btn").on("click",function(){ $(this).closest(".payment-type-row").removeClass("is-editing"); }); });</script>';
require_once '../includes/footer.php';
?>
