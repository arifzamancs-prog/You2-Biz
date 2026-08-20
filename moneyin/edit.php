<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/transaction_helper.php';

if(!manager_can_modify()){
    header("Location:index.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$id = (int)($_GET['id'] ?? 0);
$message = '';

$stmt = mysqli_prepare($conn, "SELECT * FROM money_ins WHERE id=? AND user_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, "ii", $id, $user_id);
mysqli_stmt_execute($stmt);
$entry = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if(!$entry){
    die("Money In entry not found.");
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $wallet_id = (int)($_POST['wallet_id'] ?? 0);
    $txn_date = $_POST['txn_date'] ?? date('Y-m-d');
    $amount = (float)($_POST['amount'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    mysqli_begin_transaction($conn);

    try{
        if($amount <= 0){
            throw new Exception("Invalid Amount");
        }

        $is_approved = ($entry['approval_status'] ?? '') === 'approved';

        if($is_approved){
            debit_wallet($conn, (int)$entry['wallet_id'], $user_id, (float)$entry['amount']);
            credit_wallet($conn, $wallet_id, $user_id, $amount);

            $delete_txn = mysqli_prepare(
                $conn,
                "DELETE FROM transactions
                 WHERE user_id=?
                 AND transaction_type='money_in'
                 AND reference_id=?"
            );
            mysqli_stmt_bind_param($delete_txn, "ii", $user_id, $id);
            mysqli_stmt_execute($delete_txn);

            record_wallet_transaction(
                $conn,
                $entry['txn_no'],
                $user_id,
                $wallet_id,
                'money_in',
                $id,
                $amount,
                $note,
                $txn_date
            );
        }

        $reference = '';
        $update = mysqli_prepare(
            $conn,
            "UPDATE money_ins
             SET wallet_id=?,
                 txn_date=?,
                 amount=?,
                 reference=?,
                 note=?
             WHERE id=?
             AND user_id=?"
        );
        mysqli_stmt_bind_param(
            $update,
            "isdssii",
            $wallet_id,
            $txn_date,
            $amount,
            $reference,
            $note,
            $id,
            $user_id
        );
        mysqli_stmt_execute($update);

        mysqli_commit($conn);
        header("Location:index.php");
        exit;
    }catch(Exception $e){
        mysqli_rollback($conn);
        $message = $e->getMessage();
    }
}

$wallets = active_wallets_result($conn, $user_id);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Edit Money In</h3>
    </div>
    <div class="card-body">
        <?php if($message){ ?>
            <div class="alert alert-danger"><?= htmlspecialchars($message); ?></div>
        <?php } ?>

        <form method="post">
            <div class="form-group">
                <label>Date</label>
                <input type="date" name="txn_date" class="form-control" value="<?= htmlspecialchars($entry['txn_date']); ?>" required>
            </div>

            <div class="form-group">
                <label>Wallet</label>
                <select name="wallet_id" class="form-control" required>
                    <?php while($wallet = mysqli_fetch_assoc($wallets)){ ?>
                        <option value="<?= (int)$wallet['id']; ?>" <?= (int)$wallet['id'] === (int)$entry['wallet_id'] ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($wallet['wallet_name']); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>

            <div class="form-group">
                <label>Amount (BDT)</label>
                <input type="number" step="0.01" min="0.01" name="amount" class="form-control" value="<?= htmlspecialchars($entry['amount']); ?>" required>
            </div>

            <div class="form-group">
                <label>Note</label>
                <textarea name="note" class="form-control" rows="4" maxlength="500"><?= htmlspecialchars($entry['note']); ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i>
                Update Money In
            </button>
            <a href="index.php" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
