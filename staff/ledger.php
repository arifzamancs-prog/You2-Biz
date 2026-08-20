<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/staff_helper.php';
require_once '../includes/staff_ledger_helper.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/transaction_helper.php';

require_admin_user();
$user_id=(int)$_SESSION['user_id'];
ensure_staff_table($conn); ensure_staff_ledger_table($conn); ensure_default_cash_wallet($conn,$user_id);
$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    $staff_id=(int)($_POST['staff_id'] ?? 0); $wallet_id=(int)($_POST['wallet_id'] ?? 0);
    $entry_type=$_POST['entry_type'] ?? ''; $entry_date=trim($_POST['entry_date'] ?? date('Y-m-d'));
    $amount=(float)($_POST['amount'] ?? 0); $note=trim($_POST['note'] ?? '');
    if($staff_id<=0 || $wallet_id<=0 || !in_array($entry_type,['salary','bonus','incentive'],true) || $entry_date==='' || $amount<=0){
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
            mysqli_commit($conn); header('Location: ledger.php?success=1'); exit;
        }catch(Exception $exception){ mysqli_rollback($conn); $error=$exception->getMessage(); }
    }
}

$staffs=mysqli_query($conn,"SELECT id,staff_code,name FROM staff WHERE user_id={$user_id} AND status='active' ORDER BY name ASC");
$wallets=active_wallets_result($conn,$user_id);
$ledger_stmt=mysqli_prepare($conn,"SELECT l.*,s.staff_code,s.name,w.wallet_name FROM staff_ledger_entries l INNER JOIN staff s ON s.id=l.staff_id AND s.user_id=l.user_id LEFT JOIN wallets w ON w.id=l.wallet_id AND w.user_id=l.user_id WHERE l.user_id=? ORDER BY l.entry_date DESC,l.id DESC"); mysqli_stmt_bind_param($ledger_stmt,'i',$user_id); mysqli_stmt_execute($ledger_stmt); $ledger=mysqli_stmt_get_result($ledger_stmt);
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-book mr-2"></i>Staff Salary, Bonus & Incentive Ledger</h3></div><div class="card-body">
<?php if(isset($_GET['success'])){ ?><div class="alert alert-success">Staff payment recorded and deducted from the selected wallet.</div><?php } ?><?php if($error!==''){ ?><div class="alert alert-danger"><?=htmlspecialchars($error)?></div><?php } ?>
<form method="post"><div class="row"><div class="col-md-3 form-group"><label>Staff</label><select name="staff_id" class="form-control" required><option value="">Select Staff</option><?php while($staff=mysqli_fetch_assoc($staffs)){ ?><option value="<?=$staff['id']?>"><?=htmlspecialchars($staff['name'])?> (<?=htmlspecialchars($staff['staff_code'])?>)</option><?php } ?></select></div><div class="col-md-2 form-group"><label>Payment Type</label><select name="entry_type" class="form-control" required><option value="salary">Salary</option><option value="bonus">Bonus</option><option value="incentive">Incentive</option></select></div><div class="col-md-3 form-group"><label>Wallet</label><select name="wallet_id" class="form-control" required><option value="">Select Wallet</option><?php while($wallet=mysqli_fetch_assoc($wallets)){ ?><option value="<?=$wallet['id']?>"><?=htmlspecialchars($wallet['wallet_name'])?> — BDT <?=number_format((float)$wallet['balance'],2)?></option><?php } ?></select></div><div class="col-md-2 form-group"><label>Amount</label><input type="number" name="amount" min="0.01" step="0.01" class="form-control" required></div><div class="col-md-2 form-group"><label>Date</label><input type="date" name="entry_date" class="form-control" value="<?=date('Y-m-d')?>" required></div></div><div class="form-group"><label>Note</label><textarea name="note" rows="2" class="form-control" placeholder="Optional note"></textarea></div><button class="btn btn-primary"><i class="fas fa-save"></i> Save Ledger Entry</button></form>
</div></div>
<div class="card"><div class="card-header"><h3 class="card-title">Ledger History</h3></div><div class="card-body"><table id="example1" class="table table-bordered table-striped"><thead><tr><th>Transaction No.</th><th>Date</th><th>Staff</th><th>Type</th><th>Wallet</th><th>Amount</th><th>Note</th></tr></thead><tbody><?php while($row=mysqli_fetch_assoc($ledger)){ ?><tr><td><?=htmlspecialchars($row['txn_no'])?></td><td><?=htmlspecialchars(app_date($row['entry_date']))?></td><td><?=htmlspecialchars($row['name'])?> <small class="text-muted">(<?=htmlspecialchars($row['staff_code'])?>)</small></td><td><span class="badge badge-info"><?=htmlspecialchars(staff_ledger_type_label($row['entry_type']))?></span></td><td><?=htmlspecialchars($row['wallet_name'] ?? '-')?></td><td>BDT <?=number_format((float)$row['amount'],2)?></td><td><?=htmlspecialchars($row['note'] ?? '')?></td></tr><?php } ?></tbody></table></div></div>
<?php require_once '../includes/footer.php'; ?>
