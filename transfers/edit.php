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

$stmt = mysqli_prepare($conn, "SELECT * FROM transfers WHERE id=? AND user_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, "ii", $id, $user_id);
mysqli_stmt_execute($stmt);
$entry = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if(!$entry){
    die("Transfer entry not found.");
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $from_wallet_id = (int)($_POST['from_wallet_id'] ?? 0);
    $to_wallet_id = (int)($_POST['to_wallet_id'] ?? 0);
    $txn_date = $_POST['txn_date'] ?? date('Y-m-d');
    $amount = (float)($_POST['amount'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    mysqli_begin_transaction($conn);

    try{
        if($amount <= 0){
            throw new Exception("Invalid Amount");
        }

        if($from_wallet_id === $to_wallet_id){
            throw new Exception("Source and Destination wallet cannot be same");
        }

        $is_approved = ($entry['approval_status'] ?? '') === 'approved';

        if($is_approved){
            credit_wallet($conn, (int)$entry['from_wallet_id'], $user_id, (float)$entry['amount']);
            debit_wallet($conn, (int)$entry['to_wallet_id'], $user_id, (float)$entry['amount']);
            debit_wallet($conn, $from_wallet_id, $user_id, $amount);
            credit_wallet($conn, $to_wallet_id, $user_id, $amount);

            $delete_txn = mysqli_prepare(
                $conn,
                "DELETE FROM transactions
                 WHERE user_id=?
                 AND transaction_type='transfer'
                 AND reference_id=?"
            );
            mysqli_stmt_bind_param($delete_txn, "ii", $user_id, $id);
            mysqli_stmt_execute($delete_txn);

            record_wallet_transaction(
                $conn,
                $entry['txn_no'],
                $user_id,
                $from_wallet_id,
                'transfer',
                $id,
                $amount,
                'Transfer: ' . $note,
                $txn_date
            );
        }

        $update = mysqli_prepare(
            $conn,
            "UPDATE transfers
             SET from_wallet_id=?,
                 to_wallet_id=?,
                 txn_date=?,
                 amount=?,
                 note=?
             WHERE id=?
             AND user_id=?"
        );
        mysqli_stmt_bind_param(
            $update,
            "iisdsii",
            $from_wallet_id,
            $to_wallet_id,
            $txn_date,
            $amount,
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
        <h3 class="card-title">Edit Transfer</h3>
    </div>
    <div class="card-body">
        <?php if($message){ ?>
            <div class="alert alert-danger"><?= htmlspecialchars($message); ?></div>
        <?php } ?>

        <form method="post">
            <div class="form-group">
                <label>Transfer From</label>
                <select name="from_wallet_id" class="form-control" required>
                    <?php mysqli_data_seek($wallets, 0); ?>
                    <?php while($wallet = mysqli_fetch_assoc($wallets)){ ?>
                        <option value="<?= (int)$wallet['id']; ?>" <?= (int)$wallet['id'] === (int)$entry['from_wallet_id'] ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($wallet['wallet_name']); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>

            <div class="form-group">
                <label>Transfer To</label>
                <select name="to_wallet_id" class="form-control" required>
                    <?php mysqli_data_seek($wallets, 0); ?>
                    <?php while($wallet = mysqli_fetch_assoc($wallets)){ ?>
                        <option value="<?= (int)$wallet['id']; ?>" <?= (int)$wallet['id'] === (int)$entry['to_wallet_id'] ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($wallet['wallet_name']); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>

            <div class="form-group">
                <label>Date</label>
                <input type="date" name="txn_date" class="form-control" value="<?= htmlspecialchars($entry['txn_date']); ?>" required>
            </div>

            <div class="form-group">
                <label>Amount</label>
                <input type="number" step="0.01" min="0.01" name="amount" class="form-control" value="<?= htmlspecialchars($entry['amount']); ?>" required>
            </div>

            <div class="form-group">
                <label>Note</label>
                <textarea name="note" class="form-control" rows="3"><?= htmlspecialchars($entry['note']); ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i>
                Update Transfer
            </button>
            <a href="index.php" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
