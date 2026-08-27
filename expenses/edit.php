<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/transaction_helper.php';
require_once '../includes/staff_helper.php';
require_once '../includes/expense_helper.php';

if(!manager_can_modify()){
    header("Location:index.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$id = (int)($_GET['id'] ?? 0);
$message = '';
ensure_staff_table($conn);
ensure_expense_support_tables($conn, $user_id);

$stmt = mysqli_prepare($conn, "SELECT * FROM expenses WHERE id=? AND user_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, "ii", $id, $user_id);
mysqli_stmt_execute($stmt);
$entry = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if(!$entry){
    die("Expense entry not found.");
}

if(in_array(($entry['source_type'] ?? ''), ['purchase_payment', 'supplier_payment'], true)){
    $_SESSION['error'] = 'Supplier payment expenses must be changed from the purchase or supplier payment flow.';
    header("Location:index.php");
    exit;
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $wallet_id = (int)($_POST['wallet_id'] ?? 0);
    $category_id = (int)($_POST['category_id'] ?? 0);
    $staff_id = (int)($_POST['staff_id'] ?? 0);
    $txn_date = $_POST['txn_date'] ?? date('Y-m-d');
    $amount = (float)($_POST['amount'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    mysqli_begin_transaction($conn);

    try{
        if($amount <= 0){
            throw new Exception("Invalid Amount");
        }

        $is_approved = ($entry['approval_status'] ?? '') === 'approved';
        $old_wallet_exists = false;
        $new_wallet_exists = false;

        if((int)($entry['wallet_id'] ?? 0) > 0){
            $wallet_stmt = mysqli_prepare($conn, "SELECT id FROM wallets WHERE id=? AND user_id=? LIMIT 1");
            $old_wallet_id = (int)$entry['wallet_id'];
            mysqli_stmt_bind_param($wallet_stmt, "ii", $old_wallet_id, $user_id);
            mysqli_stmt_execute($wallet_stmt);
            $old_wallet_exists = mysqli_num_rows(mysqli_stmt_get_result($wallet_stmt)) > 0;
        }

        if($wallet_id > 0){
            $wallet_stmt = mysqli_prepare($conn, "SELECT id FROM wallets WHERE id=? AND user_id=? LIMIT 1");
            mysqli_stmt_bind_param($wallet_stmt, "ii", $wallet_id, $user_id);
            mysqli_stmt_execute($wallet_stmt);
            $new_wallet_exists = mysqli_num_rows(mysqli_stmt_get_result($wallet_stmt)) > 0;
        }

        if($is_approved){
            if($old_wallet_exists){
                credit_wallet($conn, (int)$entry['wallet_id'], $user_id, (float)$entry['amount']);
            }

            if(!$new_wallet_exists){
                throw new Exception("Selected wallet not found");
            }

            debit_wallet($conn, $wallet_id, $user_id, $amount);

            $delete_txn = mysqli_prepare(
                $conn,
                "DELETE FROM transactions
                 WHERE user_id=?
                 AND transaction_type='expense'
                 AND reference_id=?"
            );
            mysqli_stmt_bind_param($delete_txn, "ii", $user_id, $id);
            mysqli_stmt_execute($delete_txn);

            record_wallet_transaction(
                $conn,
                $entry['txn_no'],
                $user_id,
                $wallet_id,
                'expense',
                $id,
                $amount,
                $note,
                $txn_date
            );
        }

        $update = mysqli_prepare(
            $conn,
            "UPDATE expenses
             SET wallet_id=?,
                 category_id=?,
                 staff_id=?,
                 txn_date=?,
                 amount=?,
                 note=?
             WHERE id=?
             AND user_id=?"
        );
        mysqli_stmt_bind_param(
            $update,
            "iiisdsii",
            $wallet_id,
            $category_id,
            $staff_id,
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
$categories = mysqli_query(
    $conn,
    "SELECT id, category_name
     FROM categories
     WHERE user_id={$user_id}
     AND status='active'
     AND COALESCE(is_hidden, 0)=0
     ORDER BY category_name ASC"
);

$staffs = mysqli_query(
    $conn,
    "SELECT id, name, staff_code
     FROM staff
     WHERE user_id={$user_id}
     AND status='active'
     ORDER BY name ASC"
);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Edit Expense</h3>
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
                <label>Category</label>
                <select name="category_id" class="form-control" required>
                    <?php while($category = mysqli_fetch_assoc($categories)){ ?>
                        <option value="<?= (int)$category['id']; ?>" <?= (int)$category['id'] === (int)$entry['category_id'] ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($category['category_name']); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>

            <div class="form-group">
                <label>Amount (BDT)</label>
                <input type="number" step="0.01" min="0.01" name="amount" class="form-control" value="<?= htmlspecialchars($entry['amount']); ?>" required>
            </div>

            <div class="form-group">
                <label>Receiver</label>
                <select name="staff_id" class="form-control">
                    <option value="">Select Staff</option>
                    <?php while($staff = mysqli_fetch_assoc($staffs)){ ?>
                        <option value="<?= (int)$staff['id']; ?>" <?= (int)$staff['id'] === (int)($entry['staff_id'] ?? 0) ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($staff['name']); ?><?= !empty($staff['staff_code']) ? ' (' . htmlspecialchars($staff['staff_code']) . ')' : ''; ?>
                        </option>
                    <?php } ?>
                </select>
            </div>

            <div class="form-group">
                <label>Note</label>
                <textarea name="note" class="form-control" rows="4"><?= htmlspecialchars($entry['note']); ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i>
                Update Expense
            </button>
            <a href="index.php" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
