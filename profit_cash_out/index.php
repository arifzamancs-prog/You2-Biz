<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/transaction_helper.php';
require_once '../includes/profit_cash_out_helper.php';

require_admin_user();
$user_id = (int)$_SESSION['user_id'];
ensure_default_cash_wallet($conn, $user_id);
ensure_profit_cash_out_table($conn);
$error = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $wallet_id = (int)($_POST['wallet_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $txn_date = trim($_POST['txn_date'] ?? date('Y-m-d'));
    $note = trim($_POST['note'] ?? '') ?: 'General';
    $admin_password = (string)($_POST['admin_password'] ?? '');
    $created_by = (int)($_SESSION['login_user_id'] ?? $user_id);

    $password_stmt = mysqli_prepare($conn, "SELECT password FROM users WHERE id=? AND role='admin' LIMIT 1");
    mysqli_stmt_bind_param($password_stmt, 'i', $user_id);
    mysqli_stmt_execute($password_stmt);
    $admin_user = mysqli_fetch_assoc(mysqli_stmt_get_result($password_stmt));

    if(!$admin_user || $admin_password === '' || !password_verify($admin_password, $admin_user['password'])){
        $error = 'Admin password is incorrect.';
    }elseif($wallet_id <= 0 || $amount <= 0 || $txn_date === ''){
        $error = 'Select a wallet and enter a valid cash out amount.';
    }else{
        mysqli_begin_transaction($conn);
        try{
            $wallet_stmt = mysqli_prepare($conn, "SELECT id FROM wallets WHERE id=? AND user_id=? AND status='active' LIMIT 1");
            mysqli_stmt_bind_param($wallet_stmt, 'ii', $wallet_id, $user_id);
            mysqli_stmt_execute($wallet_stmt);
            if(!mysqli_fetch_assoc(mysqli_stmt_get_result($wallet_stmt))){
                throw new Exception('Selected wallet is not available.');
            }

            $txn_no = generate_short_unique_txn_no($conn, 'PCO', 'profit_cash_outs');
            $insert = mysqli_prepare($conn, "INSERT INTO profit_cash_outs (txn_no,user_id,wallet_id,txn_date,amount,note,created_by) VALUES (?,?,?,?,?,?,?)");
            mysqli_stmt_bind_param($insert, 'siisdsi', $txn_no, $user_id, $wallet_id, $txn_date, $amount, $note, $created_by);
            if(!mysqli_stmt_execute($insert)){ throw new Exception(mysqli_stmt_error($insert)); }
            $cash_out_id = (int)mysqli_insert_id($conn);

            debit_wallet($conn, $wallet_id, $user_id, $amount);
            record_wallet_transaction($conn, $txn_no, $user_id, $wallet_id, 'profit_cash_out', $cash_out_id, $amount, $note === '' ? 'Owner profit cash out' : $note, $txn_date);
            mysqli_commit($conn);
            header('Location: index.php?success=1'); exit;
        }catch(Exception $exception){
            mysqli_rollback($conn);
            $error = $exception->getMessage();
        }
    }
}

$wallets = active_wallets_result($conn, $user_id);
$history_stmt = mysqli_prepare($conn, "SELECT p.*,w.wallet_name FROM profit_cash_outs p LEFT JOIN wallets w ON w.id=p.wallet_id AND w.user_id=p.user_id WHERE p.user_id=? ORDER BY p.txn_date DESC,p.id DESC");
mysqli_stmt_bind_param($history_stmt, 'i', $user_id); mysqli_stmt_execute($history_stmt);
$history = mysqli_stmt_get_result($history_stmt);

require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-hand-holding-usd mr-2"></i>Profit Cash Out</h3></div><div class="card-body">
<?php if(isset($_GET['success'])){ ?><div class="alert alert-success">Profit cash out recorded and deducted from the selected wallet.</div><?php } ?>
<?php if($error !== ''){ ?><div class="alert alert-danger"><?=htmlspecialchars($error)?></div><?php } ?>
<div class="alert alert-info">This records the owner's profit withdrawal. It reduces the wallet balance, but it is <strong>not an expense</strong> and does not reduce the Profit Report.</div>
<form method="post"><div class="row"><div class="col-md-4 form-group"><label>Wallet</label><select class="form-control" name="wallet_id" required><option value="">Select Wallet</option><?php while($wallet=mysqli_fetch_assoc($wallets)){ ?><option value="<?=$wallet['id']?>"><?=htmlspecialchars($wallet['wallet_name'])?> — BDT <?=number_format((float)$wallet['balance'],2)?></option><?php } ?></select></div><div class="col-md-3 form-group"><label>Cash Out Amount</label><input type="number" min="0.01" step="0.01" class="form-control" name="amount" required></div><div class="col-md-3 form-group"><label>Date</label><input type="date" class="form-control" name="txn_date" value="<?=date('Y-m-d')?>" required></div></div><div class="row"><div class="col-md-6 form-group"><label>Admin Password</label><input type="password" class="form-control" name="admin_password" autocomplete="current-password" required></div></div><div class="form-group"><label>Note</label><textarea class="form-control" name="note" rows="2" placeholder="Optional note"></textarea></div><button class="btn btn-primary">Cash Out</button></form>
</div></div>
<div class="card"><div class="card-header"><h3 class="card-title">Profit Cash Out History</h3></div><div class="card-body"><table id="example1" class="table table-bordered table-striped"><thead><tr><th>Transaction No.</th><th>Date</th><th>Wallet</th><th>Amount</th><th>Note</th></tr></thead><tbody><?php while($row=mysqli_fetch_assoc($history)){ ?><tr><td><?=htmlspecialchars($row['txn_no'])?></td><td><?=htmlspecialchars(app_date($row['txn_date']))?></td><td><?=htmlspecialchars($row['wallet_name'] ?? '-')?></td><td>BDT <?=number_format((float)$row['amount'],2)?></td><td><?=htmlspecialchars($row['note'] ?? '')?></td></tr><?php } ?></tbody></table></div></div>
<?php require_once '../includes/footer.php'; ?>
