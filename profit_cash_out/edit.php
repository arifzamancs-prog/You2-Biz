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

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$message = '';

if($id <= 0){
    header('Location: index.php');
    exit;
}

$entry_stmt = mysqli_prepare($conn, "SELECT * FROM profit_cash_outs WHERE id=? AND user_id=? LIMIT 1");
mysqli_stmt_bind_param($entry_stmt, 'ii', $id, $user_id);
mysqli_stmt_execute($entry_stmt);
$entry = mysqli_fetch_assoc(mysqli_stmt_get_result($entry_stmt));

if(!$entry){
    header('Location: index.php');
    exit;
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $wallet_id = (int)($_POST['wallet_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $txn_date = trim((string)($_POST['txn_date'] ?? ''));
    $note = trim((string)($_POST['note'] ?? '')) ?: 'General';

    if($wallet_id <= 0 || $amount <= 0 || $txn_date === ''){
        $message = 'Select a wallet and enter a valid cash out amount.';
    }else{
        mysqli_begin_transaction($conn);

        try{
            $old_wallet_exists = false;
            $new_wallet_exists = false;

            if((int)$entry['wallet_id'] > 0){
                $old_wallet_stmt = mysqli_prepare($conn, "SELECT id FROM wallets WHERE id=? AND user_id=? LIMIT 1");
                $old_wallet_id = (int)$entry['wallet_id'];
                mysqli_stmt_bind_param($old_wallet_stmt, 'ii', $old_wallet_id, $user_id);
                mysqli_stmt_execute($old_wallet_stmt);
                $old_wallet_exists = mysqli_num_rows(mysqli_stmt_get_result($old_wallet_stmt)) > 0;
            }

            $new_wallet_stmt = mysqli_prepare($conn, "SELECT id FROM wallets WHERE id=? AND user_id=? AND status='active' LIMIT 1");
            mysqli_stmt_bind_param($new_wallet_stmt, 'ii', $wallet_id, $user_id);
            mysqli_stmt_execute($new_wallet_stmt);
            $new_wallet_exists = mysqli_num_rows(mysqli_stmt_get_result($new_wallet_stmt)) > 0;

            if(!$new_wallet_exists){
                throw new Exception('Selected wallet is not available.');
            }

            if($old_wallet_exists){
                credit_wallet($conn, (int)$entry['wallet_id'], $user_id, (float)$entry['amount']);
            }

            debit_wallet($conn, $wallet_id, $user_id, $amount);

            $update_stmt = mysqli_prepare($conn, "UPDATE profit_cash_outs SET wallet_id=?, txn_date=?, amount=?, note=? WHERE id=? AND user_id=?");
            mysqli_stmt_bind_param($update_stmt, 'isdsii', $wallet_id, $txn_date, $amount, $note, $id, $user_id);

            if(!mysqli_stmt_execute($update_stmt)){
                throw new Exception(mysqli_stmt_error($update_stmt));
            }

            $delete_txn_stmt = mysqli_prepare(
                $conn,
                "DELETE FROM transactions
                 WHERE user_id=?
                 AND transaction_type='profit_cash_out'
                 AND reference_id=?"
            );
            mysqli_stmt_bind_param($delete_txn_stmt, 'ii', $user_id, $id);
            mysqli_stmt_execute($delete_txn_stmt);

            record_wallet_transaction($conn, $entry['txn_no'], $user_id, $wallet_id, 'profit_cash_out', $id, $amount, $note, $txn_date);

            mysqli_commit($conn);
            header('Location: index.php?updated=1');
            exit;
        }catch(Exception $exception){
            mysqli_rollback($conn);
            $message = $exception->getMessage();
        }
    }

    $entry['wallet_id'] = $wallet_id;
    $entry['txn_date'] = $txn_date;
    $entry['amount'] = $amount;
    $entry['note'] = $note;
}

$wallets = active_wallets_result($conn, $user_id);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-edit mr-2"></i>Edit Profit Cash Out</h3>
    </div>
    <form method="post" class="card-body">
        <input type="hidden" name="id" value="<?= (int)$id; ?>">
        <?php if($message !== ''){ ?>
            <div class="alert alert-danger"><?= htmlspecialchars($message); ?></div>
        <?php } ?>
        <div class="alert alert-info">This remains a profit cash out entry only. Updating it will adjust the wallet balance correctly and will not count as income or expense.</div>
        <div class="row">
            <div class="col-md-4 form-group">
                <label>Wallet</label>
                <select class="form-control" name="wallet_id" required>
                    <option value="">Select Wallet</option>
                    <?php while($wallet = mysqli_fetch_assoc($wallets)){ ?>
                        <option value="<?= (int)$wallet['id']; ?>" <?= (int)$entry['wallet_id'] === (int)$wallet['id'] ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($wallet['wallet_name']); ?> - BDT <?= number_format((float)$wallet['balance'], 2); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-4 form-group">
                <label>Cash Out Amount</label>
                <input type="number" min="0.01" step="0.01" class="form-control" name="amount" value="<?= htmlspecialchars((string)$entry['amount']); ?>" required>
            </div>
            <div class="col-md-4 form-group">
                <label>Date</label>
                <input type="date" class="form-control" name="txn_date" value="<?= htmlspecialchars((string)$entry['txn_date']); ?>" required>
            </div>
        </div>
        <div class="form-group">
            <label>Note</label>
            <textarea class="form-control" name="note" rows="3"><?= htmlspecialchars((string)($entry['note'] ?? '')); ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Update</button>
        <a href="index.php" class="btn btn-secondary">Back</a>
    </form>
</div>
<?php require_once '../includes/footer.php'; ?>
