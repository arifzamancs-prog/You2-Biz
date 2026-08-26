<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/staff_helper.php';
require_once '../includes/staff_ledger_helper.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/expense_helper.php';

require_admin_user();
$user_id = (int)$_SESSION['user_id'];
ensure_staff_table($conn); ensure_staff_ledger_table($conn); ensure_expense_support_tables($conn, $user_id);
$id = (int)($_GET['id'] ?? 0); $error = '';
$return_to_profile = ($_GET['return_to'] ?? '') === 'profile';

$entry_stmt = mysqli_prepare($conn, "SELECT * FROM staff_ledger_entries WHERE id=? AND user_id=? LIMIT 1");
mysqli_stmt_bind_param($entry_stmt, 'ii', $id, $user_id); mysqli_stmt_execute($entry_stmt);
$entry = mysqli_fetch_assoc(mysqli_stmt_get_result($entry_stmt));
if(!$entry){ die('Ledger entry not found.'); }

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $staff_id = (int)($_POST['staff_id'] ?? 0); $wallet_id = (int)($_POST['wallet_id'] ?? 0);
    $entry_type = $_POST['entry_type'] ?? ''; $date = trim($_POST['entry_date'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0); $note = trim($_POST['note'] ?? '');
    if($note === '') $note = 'General';
    if($staff_id <= 0 || $wallet_id <= 0 || !in_array($entry_type, ['salary','bonus','incentive'], true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $amount <= 0){
        $error = 'Enter valid staff, wallet, type, date and amount.';
    }else{
        mysqli_begin_transaction($conn);
        try {
            $lock_stmt = mysqli_prepare($conn, "SELECT * FROM staff_ledger_entries WHERE id=? AND user_id=? FOR UPDATE");
            mysqli_stmt_bind_param($lock_stmt, 'ii', $id, $user_id); mysqli_stmt_execute($lock_stmt);
            $old = mysqli_fetch_assoc(mysqli_stmt_get_result($lock_stmt));
            if(!$old) throw new Exception('Ledger entry not found.');
            $wallet_check = mysqli_prepare($conn, "SELECT id FROM wallets WHERE id=? AND user_id=? AND status='active' LIMIT 1");
            mysqli_stmt_bind_param($wallet_check, 'ii', $wallet_id, $user_id); mysqli_stmt_execute($wallet_check);
            if(!mysqli_fetch_assoc(mysqli_stmt_get_result($wallet_check))) throw new Exception('Selected wallet is not available.');
            credit_wallet($conn, (int)$old['wallet_id'], $user_id, (float)$old['amount']);
            debit_wallet($conn, $wallet_id, $user_id, $amount);
            $update = mysqli_prepare($conn, "UPDATE staff_ledger_entries SET staff_id=?, wallet_id=?, entry_type=?, entry_date=?, amount=?, note=? WHERE id=? AND user_id=?");
            mysqli_stmt_bind_param($update, 'iissdsii', $staff_id, $wallet_id, $entry_type, $date, $amount, $note, $id, $user_id); mysqli_stmt_execute($update);
            $note_text = 'Staff ' . staff_ledger_type_label($entry_type) . ' payment: ' . $note;
            $transaction = mysqli_prepare($conn, "UPDATE transactions SET wallet_id=?, amount=?, note=?, txn_date=? WHERE txn_no=? AND user_id=? AND transaction_type='staff_payment'");
            mysqli_stmt_bind_param($transaction, 'idsssi', $wallet_id, $amount, $note_text, $date, $old['txn_no'], $user_id); mysqli_stmt_execute($transaction);
            $category = reserved_expense_category_id($conn, $user_id, reserved_expense_category_name_from_entry_type($entry_type));
            $expense = mysqli_prepare($conn, "UPDATE expenses SET wallet_id=?, category_id=?, staff_id=?, txn_date=?, amount=?, note=? WHERE txn_no=? AND user_id=?");
            mysqli_stmt_bind_param($expense, 'iiisdssi', $wallet_id, $category, $staff_id, $date, $amount, $note, $old['txn_no'], $user_id); mysqli_stmt_execute($expense);
            mysqli_commit($conn); header('Location: ' . ($return_to_profile ? 'profile.php?id=' . $staff_id . '&updated=1' : 'ledger.php?updated=1')); exit;
        } catch(Throwable $exception) { mysqli_rollback($conn); $error = $exception->getMessage(); }
    }
    $entry = array_merge($entry, ['staff_id'=>$staff_id,'wallet_id'=>$wallet_id,'entry_type'=>$entry_type,'entry_date'=>$date,'amount'=>$amount,'note'=>$note]);
}

$staffs = mysqli_query($conn, "SELECT id, staff_code, name FROM staff WHERE user_id={$user_id} ORDER BY name");
$wallets = active_wallets_result($conn, $user_id);
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title">Edit Staff Ledger Entry</h3></div><div class="card-body">
<?php if($error){ ?><div class="alert alert-danger"><?=htmlspecialchars($error)?></div><?php } ?>
<form method="post"><div class="row"><div class="col-md-4 form-group"><label>Staff</label><select name="staff_id" class="form-control" required><?php while($staff=mysqli_fetch_assoc($staffs)){ ?><option value="<?= (int)$staff['id'] ?>" <?= (int)$entry['staff_id']===(int)$staff['id']?'selected':'' ?>><?=htmlspecialchars($staff['name'])?> (<?=htmlspecialchars($staff['staff_code'])?>)</option><?php } ?></select></div><div class="col-md-3 form-group"><label>Payment Type</label><select name="entry_type" class="form-control"><option value="salary" <?=$entry['entry_type']==='salary'?'selected':''?>>Salary</option><option value="bonus" <?=$entry['entry_type']==='bonus'?'selected':''?>>Bonus</option><option value="incentive" <?=$entry['entry_type']==='incentive'?'selected':''?>>Incentive</option></select></div><div class="col-md-3 form-group"><label>Wallet</label><select name="wallet_id" class="form-control" required><?php while($wallet=mysqli_fetch_assoc($wallets)){ ?><option value="<?= (int)$wallet['id'] ?>" <?= (int)$entry['wallet_id']===(int)$wallet['id']?'selected':''?>><?=htmlspecialchars($wallet['wallet_name'])?></option><?php } ?></select></div><div class="col-md-2 form-group"><label>Amount</label><input type="number" name="amount" min="0.01" step="0.01" class="form-control" value="<?=htmlspecialchars($entry['amount'])?>" required></div></div><div class="row"><div class="col-md-3 form-group"><label>Date</label><input type="date" name="entry_date" class="form-control" value="<?=htmlspecialchars($entry['entry_date'])?>" required></div></div><div class="form-group"><label>Note</label><textarea name="note" class="form-control" rows="3"><?=htmlspecialchars($entry['note'] ?? '')?></textarea></div><button class="btn btn-primary"><i class="fas fa-save"></i> Update Entry</button> <a href="<?= $return_to_profile ? 'profile.php?id=' . (int)$entry['staff_id'] : 'ledger.php'; ?>" class="btn btn-secondary">Back</a></form>
</div></div>
<?php require_once '../includes/footer.php'; ?>
