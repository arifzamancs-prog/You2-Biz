<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/sales_return_helper.php';

$cid = (int)$_SESSION['user_id'];

if(is_agent_user() || !sales_return_enabled($conn, $cid)){
    header('Location: invoice_list.php');
    exit;
}

sales_return_schema($conn);
$invoice_id = (int)($_GET['invoice_id'] ?? 0);
require_branch_record_access($conn, 'invoices', $invoice_id, 'sales_return.php');
try{
    $branch = stock_invoice_branch($conn, $cid, $invoice_id);
}catch(RuntimeException $e){
    $_SESSION['sales_return_error'] = $e->getMessage();
    header('Location: sales_return.php');
    exit;
}
$GLOBALS['stock_wallet_branch_id'] = $branch;

$invoice = sales_return_fetch_invoice($conn, $cid, $invoice_id);
$block_reason = sales_return_block_reason($invoice);
$lines = $invoice && $block_reason === '' ? sales_return_lines($conn, $invoice) : [];

if($block_reason === '' && array_sum(array_column($lines, 'returnable_qty')) <= 0){
    $block_reason = 'Everything on this invoice has already been returned.';
}

$customer_id = (int)($invoice['customer_id'] ?? 0);
$customer_due = sales_return_customer_due($conn, $cid, $branch, $customer_id);
$wallet_rows = sales_refund_query($conn, "SELECT id, wallet_name, balance FROM wallets WHERE user_id=? AND branch_id=? AND status='active' ORDER BY is_system DESC, wallet_name", 'ii', [$cid, $branch])->get_result()->fetch_all(MYSQLI_ASSOC);
$default_wallet_id = (int)($invoice['receive_wallet_id'] ?? 0);
$error = $_SESSION['sales_return_error'] ?? '';
unset($_SESSION['sales_return_error']);

function sales_return_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

<div class="card-header d-flex align-items-center">
    <h3 class="card-title mb-0">Sales Return</h3>
    <div class="ml-auto">
        <a href="sales_return.php" class="btn btn-secondary btn-sm">Back</a>
    </div>
</div>

<div class="card-body">

<?php if($error !== ''){ ?>
    <div class="alert alert-danger"><?= sales_return_h($error); ?></div>
<?php } ?>

<?php if(!$invoice){ ?>

    <div class="alert alert-danger">Invoice Not Found</div>

<?php }else{ ?>

<div class="row">
    <div class="col-md-6">
        <table class="table table-bordered table-sm">
            <tr><th style="width:40%">Invoice No</th><td><?= sales_return_h($invoice['invoice_no']); ?></td></tr>
            <tr><th>Date</th><td><?= sales_return_h(app_date($invoice['invoice_date'])); ?></td></tr>
            <tr><th>Customer</th><td><?= sales_return_h($invoice['customer_name']); ?><?= !empty($invoice['customer_code']) ? ' (ID: ' . sales_return_h($invoice['customer_code']) . ')' : ''; ?></td></tr>
        </table>
    </div>
    <div class="col-md-6">
        <table class="table table-bordered table-sm">
            <tr><th style="width:40%">Total</th><td><?= number_format((float)$invoice['total_amount'], 2); ?></td></tr>
            <tr><th>Paid</th><td><?= number_format((float)$invoice['paid_amount'], 2); ?></td></tr>
            <?php if($customer_due > 0.009){ ?>
            <tr><th>Customer Due</th><td><?= number_format($customer_due, 2); ?></td></tr>
            <?php } ?>
        </table>
    </div>
</div>

<?php if($block_reason !== ''){ ?>

    <div class="alert alert-warning"><?= sales_return_h($block_reason); ?></div>

<?php }else{ ?>

<form method="post" action="sales_return_save.php" id="salesReturnForm">

<input type="hidden" name="invoice_id" value="<?= (int)$invoice['id']; ?>">

<div class="table-responsive">
<table class="table table-bordered">
    <thead>
    <tr>
        <th>Product</th>
        <th class="text-right">Sold</th>
        <th class="text-right">Returned</th>
        <th class="text-right">Refund / Unit</th>
        <th style="width:120px">Return Qty</th>
        <th style="width:170px">Condition</th>
        <th class="text-right" style="width:130px">Total</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach($lines as $line){ ?>
        <tr class="return-line" data-price="<?= sales_return_h(number_format($line['refund_unit_price'], 2, '.', '')); ?>">
            <td><?= sales_return_h(sales_return_line_label($line)); ?></td>
            <td class="text-right"><?= (int)$line['sold_qty']; ?></td>
            <td class="text-right"><?= (int)$line['returned_qty']; ?></td>
            <td class="text-right"><?= number_format($line['refund_unit_price'], 2); ?></td>
            <td>
                <input type="number" name="return_qty[<?= (int)$line['id']; ?>]" class="form-control return-qty"
                       min="0" max="<?= (int)$line['returnable_qty']; ?>" step="1" value="0"
                       <?= (int)$line['returnable_qty'] <= 0 ? 'readonly' : ''; ?>>
                <small class="text-muted">Max <?= (int)$line['returnable_qty']; ?></small>
            </td>
            <td>
                <select name="item_condition[<?= (int)$line['id']; ?>]" class="form-control">
                    <option value="restock">Back to stock</option>
                    <option value="damaged">Damaged</option>
                </select>
            </td>
            <td class="text-right line-total">0.00</td>
        </tr>
    <?php } ?>
    </tbody>
    <tfoot>
    <tr>
        <th colspan="6" class="text-right">Return Total</th>
        <th class="text-right" id="returnTotal">0.00</th>
    </tr>
    </tfoot>
</table>
</div>

<h5 class="mt-3">Settle the return</h5>

<div class="row">
    <div class="col-md-4">
        <div class="form-group">
            <label>Cash Refund</label>
            <input type="number" name="cash_refund" id="cashRefund" class="form-control settle" min="0" step="0.01" value="0">
            <select name="refund_wallet_id" id="refundWallet" class="form-control mt-2">
                <?php foreach($wallet_rows as $wallet){ ?>
                    <option value="<?= (int)$wallet['id']; ?>" <?= (int)$wallet['id'] === $default_wallet_id ? 'selected' : ''; ?>>
                        <?= sales_return_h($wallet['wallet_name']); ?> (<?= number_format((float)$wallet['balance'], 2); ?>)
                    </option>
                <?php } ?>
            </select>
            <small class="text-muted">Paid back from this wallet.</small>
        </div>
    </div>
    <?php if($customer_id > 0){ ?>
    <?php if($customer_due > 0.009){ ?>
    <div class="col-md-4">
        <div class="form-group">
            <label>Deduct from Due</label>
            <input type="number" name="due_adjusted" id="dueAdjusted" class="form-control settle" min="0" max="<?= sales_return_h(number_format($customer_due, 2, '.', '')); ?>" step="0.01" value="0">
            <small class="text-muted">Customer due: <?= number_format($customer_due, 2); ?></small>
        </div>
    </div>
    <?php } ?>
    <div class="col-md-4">
        <div class="form-group">
            <label>Keep as Advance</label>
            <input type="number" name="advance_amount" id="advanceAmount" class="form-control settle" min="0" step="0.01" value="0">
            <small class="text-muted">Used against the customer's next purchase.</small>
        </div>
    </div>
    <?php } ?>
</div>

<div class="alert alert-secondary" id="settleStatus">Enter the quantities to return.</div>

<div class="form-group">
    <label>Note / Reason</label>
    <textarea name="note" class="form-control" rows="2" maxlength="1000"></textarea>
</div>

<button type="submit" class="btn btn-primary" id="saveReturn">
    <i class="fas fa-undo"></i> Save Return &amp; Print
</button>

</form>

<?php } ?>

<?php } ?>

</div>

</div>

<script>
(function(){
    var form = document.getElementById("salesReturnForm");
    if(!form){ return; }
    var hasCustomer = <?= $customer_id > 0 ? 'true' : 'false'; ?>;
    var customerDue = <?= json_encode(round($customer_due, 2)); ?>;
    var cash = document.getElementById("cashRefund");
    var due = document.getElementById("dueAdjusted");
    var advance = document.getElementById("advanceAmount");
    var status = document.getElementById("settleStatus");
    var touched = false;
    function num(el){ return el ? (parseFloat(el.value) || 0) : 0; }
    function money(v){ return (Math.round(v * 100) / 100).toFixed(2); }
    function total(){
        var sum = 0;
        form.querySelectorAll(".return-line").forEach(function(row){
            var input = row.querySelector(".return-qty");
            var max = parseInt(input.getAttribute("max"), 10) || 0;
            var qty = Math.max(0, Math.min(max, parseInt(input.value, 10) || 0));
            var line = qty * parseFloat(row.getAttribute("data-price"));
            row.querySelector(".line-total").textContent = money(line);
            sum += line;
        });
        return Math.round(sum * 100) / 100;
    }
    function check(){
        var t = total();
        document.getElementById("returnTotal").textContent = money(t);
        var settled = Math.round((num(cash) + num(due) + num(advance)) * 100) / 100;
        var dueOk = !due || num(due) <= customerDue + 0.001;
        var advanceOk = !advance || num(advance) <= 0 || num(due) >= customerDue - 0.001;
        var ok = t > 0 && Math.abs(settled - t) < 0.01 && dueOk && advanceOk;
        status.className = "alert " + (ok ? "alert-success" : (t > 0 ? "alert-warning" : "alert-secondary"));
        status.textContent = t <= 0 ? "Enter the quantities to return." :
            (!dueOk ? "The due to deduct is more than the customer owes." :
            (!advanceOk ? "Deduct the customer's full due before keeping an advance." :
            (ok ? "Return " + money(t) + " is fully settled." : "Cash + Due + Advance must equal " + money(t) + " (now " + money(settled) + ").")));
        return ok;
    }
    function autoSplit(){
        if(touched){ return; }
        var t = total();
        var d = hasCustomer && due ? Math.min(t, customerDue) : 0;
        if(due){ due.value = money(d); }
        if(advance){ advance.value = "0.00"; }
        cash.value = money(t - d);
    }
    form.querySelectorAll(".return-qty").forEach(function(input){
        input.addEventListener("input", function(){ autoSplit(); check(); });
    });
    form.querySelectorAll(".settle").forEach(function(input){
        input.addEventListener("input", function(){ touched = true; check(); });
    });
    form.addEventListener("submit", function(e){
        if(!check()){ e.preventDefault(); alert(status.textContent); return; }
        document.getElementById("saveReturn").disabled = true;
    });
    check();
})();
</script>

<?php require_once '../includes/footer.php'; ?>
