<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/transaction_helper.php';

$message = '';

$user_id = $_SESSION['user_id'];

if($_SERVER['REQUEST_METHOD'] == 'POST'){

    $wallet_id = (int)$_POST['wallet_id'];
    $txn_date  = date('Y-m-d');
    $amount    = (float)$_POST['amount'];
    $reference = '';
    $note      = trim($_POST['note']);
    $created_by = $_SESSION['login_user_id'] ?? $_SESSION['user_id'];
    $approval_status = 'approved';
    $approved_by = (int)$created_by;
    $approved_at = date('Y-m-d H:i:s');

    $txn_no = generate_short_unique_txn_no($conn, 'TXN', 'money_ins');

    mysqli_begin_transaction($conn);

    try{

        // Money In Insert

        $sql = "INSERT INTO money_ins
        (
            txn_no,
            user_id,
            wallet_id,
            txn_date,
            amount,
            reference,
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
            "siisdsssiis",
            $txn_no,
            $user_id,
            $wallet_id,
            $txn_date,
            $amount,
            $reference,
            $note,
            $approval_status,
            $created_by,
            $approved_by,
            $approved_at
        );

        mysqli_stmt_execute($stmt);

        $money_in_id = mysqli_insert_id($conn);

        if($approval_status === 'approved'){

        // Wallet Balance Update

        $sql = "UPDATE wallets
                SET balance = balance + ?
                WHERE id=?
                AND user_id=?";

        $stmt = mysqli_prepare($conn,$sql);

        mysqli_stmt_bind_param(
            $stmt,
            "dii",
            $amount,
            $wallet_id,
            $user_id
        );

        mysqli_stmt_execute($stmt);

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

        $type = 'money_in';

        mysqli_stmt_bind_param(
            $stmt,
            "siisidss",
            $txn_no,
            $user_id,
            $wallet_id,
            $type,
            $money_in_id,
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

/* Active Wallet List */

$wallets = active_wallets_result($conn, $user_id);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">
            Money In Entry
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
                    name="wallet_id"
                    class="form-control"
                    required>

                    <option value="">
                        Select Wallet
                    </option>

                    <?php while($wallet = mysqli_fetch_assoc($wallets)){ ?>

                        <option value="<?= $wallet['id']; ?>">

                            <?= htmlspecialchars($wallet['wallet_name']); ?>

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
                class="btn btn-primary">

                <i class="fas fa-save"></i>
                Save Money In

            </button>

            <a href="index.php"
               class="btn btn-secondary">

                Back

            </a>

        </form>

    </div>

</div>

<?php
require_once '../includes/footer.php';
?>
