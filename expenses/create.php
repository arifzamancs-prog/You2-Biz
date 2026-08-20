<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/transaction_helper.php';

$message = '';

$user_id = $_SESSION['user_id'];

ensure_default_cash_wallet($conn, $user_id);

if($_SERVER['REQUEST_METHOD'] == 'POST'){

    $wallet_id   = (int)$_POST['wallet_id'];
    $category_id = (int)$_POST['category_id'];
    $txn_date    = date('Y-m-d');
    $amount      = (float)$_POST['amount'];
    $note        = trim($_POST['note']);
    $created_by = $_SESSION['login_user_id'] ?? $_SESSION['user_id'];
    $needs_approval = is_manager_user() || ((int)$created_by !== (int)$user_id);
    $approval_status = $needs_approval ? 'pending' : 'approved';
    $approved_by = $needs_approval ? null : $created_by;
    $approved_at = $needs_approval ? null : date('Y-m-d H:i:s');

    // Wallet Balance Check

    $sql = "SELECT balance
            FROM wallets
            WHERE id=?
            AND user_id=?";

    $stmt = mysqli_prepare($conn,$sql);

    mysqli_stmt_bind_param(
        $stmt,
        "ii",
        $wallet_id,
        $user_id
    );

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    $wallet = mysqli_fetch_assoc($result);

    if(!$wallet){

        $message = "Wallet Not Found";

    }else{

        $current_balance = (float)$wallet['balance'];

        if($approval_status === 'approved' && $amount > $current_balance){

            $message = "Insufficient Balance";

        }else{

            $txn_no = generate_short_unique_txn_no($conn, 'TXN', 'expenses');

            mysqli_begin_transaction($conn);

            try{

                // Expense Insert

                $sql = "INSERT INTO expenses
                (
                    txn_no,
                    user_id,
                    wallet_id,
                    category_id,
                    txn_date,
                    amount,
                    note,
                    approval_status,
                    created_by,
                    approved_by,
                    approved_at
                )
                VALUES
                (
                    ?,?,?,?,?,?,?,?,?,?,?
                )";

                $stmt = mysqli_prepare($conn,$sql);

                mysqli_stmt_bind_param(
                    $stmt,
                    "siiisdssiis",
                    $txn_no,
                    $user_id,
                    $wallet_id,
                    $category_id,
                    $txn_date,
                    $amount,
                    $note,
                    $approval_status,
                    $created_by,
                    $approved_by,
                    $approved_at
                );

                mysqli_stmt_execute($stmt);

                $expense_id = mysqli_insert_id($conn);

                if($approval_status === 'approved'){

                // Wallet Minus

                debit_wallet(
                    $conn,
                    $wallet_id,
                    $user_id,
                    $amount
                );

                // Transaction History

                $sql = "INSERT INTO transactions
                (
                    txn_no,
                    user_id,
                    wallet_id,
                    transaction_type,
                    reference_id,
                    amount,
                    note,
                    txn_date
                )
                VALUES
                (
                    ?,?,?,?,?,?,?,?
                )";

                $stmt = mysqli_prepare($conn,$sql);

                $type = 'expense';

                mysqli_stmt_bind_param(
                    $stmt,
                    "siisidss",
                    $txn_no,
                    $user_id,
                    $wallet_id,
                    $type,
                    $expense_id,
                    $amount,
                    $note,
                    $txn_date
                );

                mysqli_stmt_execute($stmt);

                }

                mysqli_commit($conn);

                header("Location:index.php");
                exit;

            }catch(Exception $e){

                mysqli_rollback($conn);

                $message = $e->getMessage();
            }
        }
    }
}

// Wallet List

$wallets = active_wallets_result($conn, $user_id);

// Category List

$categories = mysqli_query(
    $conn,
    "SELECT id,category_name
     FROM categories
     WHERE user_id = $user_id
     AND status='active'
     ORDER BY category_name ASC"
);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">
            Expense Entry
        </h3>

    </div>

    <div class="card-body">

        <?php if($message){ ?>

            <div class="alert alert-danger">

                <?= htmlspecialchars($message); ?>

            </div>

        <?php } ?>

        <form method="post">

            <div class="form-group">

                <label>Wallet</label>

                <select
                    id="wallet_id"
                    name="wallet_id"
                    class="form-control"
                    required>

                    <option value="">
                        Select Wallet
                    </option>

                    <?php while($wallet = mysqli_fetch_assoc($wallets)){ ?>

                        <option
                            value="<?= $wallet['id']; ?>"
                            data-balance="<?= number_format((float)$wallet['balance'], 2, '.', ''); ?>">

                            <?= htmlspecialchars($wallet['wallet_name']); ?>

                        </option>

                    <?php } ?>

                </select>

            </div>

            <div
                class="form-group"
                id="wallet_balance_group"
                style="display:none;">

                <div
                    id="wallet_balance"
                    class="small font-weight-bold text-muted">
                    BDT 0.00
                </div>

            </div>

            <div class="form-group">

                <label>Category</label>

                <select
                    name="category_id"
                    class="form-control"
                    required>

                    <option value="">
                        Select Category
                    </option>

                    <?php while($category = mysqli_fetch_assoc($categories)){ ?>

                        <option value="<?= $category['id']; ?>">

                            <?= htmlspecialchars($category['category_name']); ?>

                        </option>

                    <?php } ?>

                </select>

            </div>

            <div class="form-group">

                <label>Amount (BDT)</label>

                <input
                    type="number"
                    step="0.01"
                    min="0.01"
                    name="amount"
                    class="form-control"
                    required>

            </div>

            <div class="form-group">

                <label>Note</label>

                <textarea
                    name="note"
                    class="form-control"
                    rows="4"
                    maxlength="500"></textarea>

            </div>

            <button
                type="submit"
                class="btn btn-danger">

                <i class="fas fa-save"></i>
                Save Expense

            </button>

            <a href="index.php"
               class="btn btn-secondary">

                Back

            </a>

        </form>

    </div>

</div>

<?php
$page_script = '
<script>
$(function(){
    function updateWalletBalance(){
        var selected = $("#wallet_id option:selected");
        var walletId = selected.val();
        var balance = parseFloat(selected.data("balance")) || 0;

        if(walletId){
            $("#wallet_balance")
                .text("Present Balance: BDT " + balance.toFixed(2));
            $("#wallet_balance_group").show();
        }else{
            $("#wallet_balance")
                .text("BDT 0.00");
            $("#wallet_balance_group").hide();
        }
    }

    $("#wallet_id").on("change", updateWalletBalance);
    updateWalletBalance();
});
</script>
';
require_once '../includes/footer.php';
?>
