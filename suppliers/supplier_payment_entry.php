<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = $_SESSION['user_id'];

$purchase_id = (int)($_GET['id'] ?? 0);

$sql = "SELECT

            p.*,
            s.supplier_name

        FROM purchases p

        LEFT JOIN suppliers s
        ON s.id = p.supplier_id

        WHERE p.id=?
        AND p.user_id=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $purchase_id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$purchase = mysqli_fetch_assoc($result);

if(!$purchase){

    die("Purchase Not Found");

}

/* Wallets */

$wallets = active_wallets_result($conn, $user_id);

?>

<section class="content-header">

<div class="container-fluid">

<h1>

Supplier Due Payment

</h1>

</div>

</section>

<section class="content">

<div class="container-fluid">

<div class="card">

<div class="card-body">

<?php if(isset($_SESSION['supplier_payment_error'])){ ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle mr-1"></i>
        <?= htmlspecialchars($_SESSION['supplier_payment_error']); ?>
        <button type="button" class="close" data-dismiss="alert">
            <span>&times;</span>
        </button>
    </div>
    <?php unset($_SESSION['supplier_payment_error']); ?>
<?php } ?>

<form
method="post"
action="supplier_payment_save.php">

<input
type="hidden"
name="purchase_id"
value="<?= $purchase['id']; ?>">

<div class="form-group">

<label>

Purchase No

</label>

<input
type="text"
class="form-control"
value="<?= htmlspecialchars($purchase['purchase_no']); ?>"
readonly>

</div>

<div class="form-group">

<label>

Supplier

</label>

<input
type="text"
class="form-control"
value="<?= htmlspecialchars($purchase['supplier_name']); ?>"
readonly>

</div>

<div class="form-group">

<label>

Current Due

</label>

<input
type="text"
class="form-control"
value="<?= number_format($purchase['due_amount'],2); ?>"
readonly>

</div>

<div class="form-group">

<label>

Pay Amount

</label>

<input
type="number"
step="0.01"
name="amount"
max="<?= $purchase['due_amount']; ?>"
value="<?= number_format((float)$purchase['due_amount'], 2, '.', ''); ?>"
class="form-control"
required>

</div>

<div class="form-group">

<label>

Pay From Wallet

</label>

<select
id="payment_wallet_id"
name="payment_wallet_id"
class="form-control"
required>

<?php
while($wallet=mysqli_fetch_assoc($wallets)){
?>

<option
value="<?= $wallet['id']; ?>"
data-balance="<?= number_format((float)$wallet['balance'], 2, '.', ''); ?>"
<?= $wallet['is_system']==1 ? 'selected' : ''; ?>>

<?= htmlspecialchars($wallet['wallet_name']); ?>

</option>

<?php } ?>

</select>

</div>

<div
class="form-group"
id="payment_wallet_balance_group"
style="display:none;">

<div
id="payment_wallet_balance"
class="small font-weight-bold text-muted">
BDT 0.00
</div>

</div>

<div class="form-group">

<label>

Notes

</label>

<textarea
name="notes"
class="form-control"
rows="3"></textarea>

</div>

<button
type="submit"
class="btn btn-success">

Save Payment

</button>

<a
href="supplier_payment.php"
class="btn btn-secondary">

Back

</a>

</form>

</div>

</div>

</div>

</section>

<?php
$page_script = '
<script>
$(function(){
    function updatePaymentWalletBalance(){
        var selected = $("#payment_wallet_id option:selected");
        var walletId = selected.val();
        var balance = parseFloat(selected.data("balance")) || 0;

        if(walletId){
            $("#payment_wallet_balance")
                .text("Present Balance: BDT " + balance.toFixed(2));
            $("#payment_wallet_balance_group").show();
        }else{
            $("#payment_wallet_balance")
                .text("BDT 0.00");
            $("#payment_wallet_balance_group").hide();
        }
    }

    $("#payment_wallet_id").on("change", updatePaymentWalletBalance);
    updatePaymentWalletBalance();
});
</script>
';
require_once '../includes/footer.php';
?>
