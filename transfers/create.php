<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/transaction_helper.php';
require_once '../includes/wallet_helper.php';

$message = '';

$user_id = $_SESSION['user_id'];

ensure_default_cash_wallet($conn, $user_id);

function transfer_generate_unique_txn_no($conn)
{
    for($attempt = 0; $attempt < 10; $attempt++){
        $txn_no = 'TRF-' . date('ymdHis') . random_int(10, 99);

        $stmt = mysqli_prepare(
            $conn,
            "SELECT
                (SELECT COUNT(*) FROM transfers WHERE txn_no=?) AS transfer_count,
                (SELECT COUNT(*) FROM transactions WHERE txn_no=?) AS transaction_count"
        );

        if(!$stmt){
            return $txn_no;
        }

        mysqli_stmt_bind_param($stmt, "ss", $txn_no, $txn_no);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;

        if(
            (int)($row['transfer_count'] ?? 0) === 0 &&
            (int)($row['transaction_count'] ?? 0) === 0
        ){
            return $txn_no;
        }
    }

    return 'TRF-' . date('ymdHis') . random_int(100, 999);
}

if($_SERVER['REQUEST_METHOD'] == 'POST'){

    $from_wallet_id = (int)$_POST['from_wallet_id'];
    $to_wallet_id   = (int)$_POST['to_wallet_id'];
    $txn_date       = date('Y-m-d');
    $amount         = (float)$_POST['amount'];
    $note           = trim($_POST['note'] ?? '') ?: 'General';
    $created_by = $_SESSION['login_user_id'] ?? $_SESSION['user_id'];
    $needs_approval = is_manager_user() || ((int)$created_by !== (int)$user_id);
    $approval_status = $needs_approval ? 'pending' : 'approved';
    $approved_by = $needs_approval ? null : $created_by;
    $approved_at = $needs_approval ? null : date('Y-m-d H:i:s');

    if($from_wallet_id == $to_wallet_id){

        $message = "Source and Destination wallet cannot be same";

    }else{

        $sql = "SELECT balance
                FROM wallets
                WHERE id=?
                AND user_id=?";

        $stmt = mysqli_prepare($conn,$sql);

        mysqli_stmt_bind_param(
            $stmt,
            "ii",
            $from_wallet_id,
            $user_id
        );

        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);

        $wallet = mysqli_fetch_assoc($result);

        $current_balance = (float)$wallet['balance'];

        if($approval_status === 'approved' && $amount > $current_balance){

            $message = "Insufficient Balance";

        }else{

            $txn_no = transfer_generate_unique_txn_no($conn);

            mysqli_begin_transaction($conn);

            try{

                // Transfer Save

                $sql = "INSERT INTO transfers
                        (
                            txn_no,
                            user_id,
                            from_wallet_id,
                            to_wallet_id,
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
                    $from_wallet_id,
                    $to_wallet_id,
                    $txn_date,
                    $amount,
                    $note,
                    $approval_status,
                    $created_by,
                    $approved_by,
                    $approved_at
                );

                mysqli_stmt_execute($stmt);

                $transfer_id = mysqli_insert_id($conn);

                if($approval_status === 'approved'){

                // Minus From Wallet

                debit_wallet(
                    $conn,
                    $from_wallet_id,
                    $user_id,
                    $amount
                );

                // Add To Wallet

                credit_wallet(
                    $conn,
                    $to_wallet_id,
                    $user_id,
                    $amount
                );

                record_wallet_transaction(
                    $conn,
                    $txn_no,
                    $user_id,
                    $from_wallet_id,
                    'transfer',
                    $transfer_id,
                    $amount,
                    'Transfer: ' . $note,
                    $txn_date
                );

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

$wallets = active_wallets_result($conn, $user_id);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

    <div class="card-header">
        <h3 class="card-title">
            Wallet Transfer
        </h3>
    </div>

    <div class="card-body">

        <?php if($message){ ?>

            <div class="alert alert-danger">
                <?= $message; ?>
            </div>

        <?php } ?>

        <form method="post">

            <div class="form-group">

                <label>Transfer From</label>

                <select
                    id="from_wallet_id"
                    name="from_wallet_id"
                    class="form-control"
                    required>

                    <option value="">
                        Select Wallet
                    </option>

                    <?php
                    mysqli_data_seek($wallets,0);

                    while($wallet = mysqli_fetch_assoc($wallets)){
                    ?>

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
                id="from_wallet_balance_group"
                style="display:none;">

                <label>Present Balance</label>

                <div
                    id="from_wallet_balance"
                    class="small font-weight-bold text-muted">
                    BDT 0.00
                </div>

            </div>

            <div class="form-group">

                <label>Transfer To</label>

                <select
                    name="to_wallet_id"
                    class="form-control"
                    required>

                    <option value="">
                        Select Wallet
                    </option>

                    <?php
                    mysqli_data_seek($wallets,0);

                    while($wallet = mysqli_fetch_assoc($wallets)){
                    ?>

                    <option value="<?= $wallet['id']; ?>">
                        <?= htmlspecialchars($wallet['wallet_name']); ?>
                    </option>

                    <?php } ?>

                </select>

            </div>
            <div class="form-group">

                <label>Amount</label>

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
                    rows="3"></textarea>

            </div>

            <button
                type="submit"
                class="btn btn-primary">

                <i class="fas fa-random"></i>
                Transfer

            </button>

        </form>

    </div>

</div>

<?php
$page_script = '
<script>
$(function(){
    function updateFromWalletBalance(){
        var selected = $("#from_wallet_id option:selected");
        var walletId = selected.val();
        var balance = parseFloat(selected.data("balance")) || 0;

        if(walletId){
            $("#from_wallet_balance")
                .text("Present Balance: BDT " + balance.toFixed(2));
            $("#from_wallet_balance_group").show();
        }else{
            $("#from_wallet_balance")
                .text("BDT 0.00");
            $("#from_wallet_balance_group").hide();
        }
    }

    $("#from_wallet_id").on("change", updateFromWalletBalance);
    updateFromWalletBalance();
});
</script>
';
require_once '../includes/footer.php';
?>
