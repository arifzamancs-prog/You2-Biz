<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/purchase_return_helper.php';

$cid = (int)$_SESSION['user_id'];

if(is_agent_user() || !manager_has_permission('suppliers') || !manager_can_modify()){
    header('Location: index.php');
    exit;
}

ensure_fifo_inventory_tables($conn);
purchase_return_schema($conn);
$purchase_id = (int)($_GET['purchase_id'] ?? 0);
require_branch_record_access($conn, 'purchases', $purchase_id, 'purchase_return.php');
$purchase = purchase_return_fetch_purchase($conn, $cid, $purchase_id);

if(!$purchase){
    $_SESSION['purchase_return_error'] = 'Purchase not found.';
    header('Location: purchase_return.php');
    exit;
}

$block = purchase_return_block_reason($conn, $purchase);
$lines = purchase_return_lines($conn, $purchase);
$GLOBALS['stock_wallet_branch_id'] = (int)$purchase['branch_id'];
$wallets = active_wallets_result($conn, $cid);
$message = $_SESSION['purchase_return_error'] ?? '';
unset($_SESSION['purchase_return_error']);
$purchase_due = round((float)$purchase['due_amount'], 2);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">

<div class="card-header">
    <h3 class="card-title">Return to <?= purchase_return_h(supplier_display_text('Supplier')); ?> — <?= purchase_return_h($purchase['purchase_no']); ?></h3>
    <a class="float-right" href="purchase_return.php">Purchase Returns</a>
</div>

<div class="card-body">

<?php if($message !== ''){ ?><div class="alert alert-danger"><?= purchase_return_h($message); ?></div><?php } ?>

<div class="row mb-3">
    <div class="col-md-4"><strong><?= purchase_return_h(supplier_display_text('Supplier')); ?>:</strong> <?= purchase_return_h($purchase['supplier_name']); ?></div>
    <div class="col-md-3"><strong>Date:</strong> <?= purchase_return_h(app_date($purchase['purchase_date'])); ?></div>
    <div class="col-md-2"><strong>Total:</strong> <?= number_format((float)$purchase['total_amount'], 2); ?></div>
    <div class="col-md-3"><strong>Due now:</strong> <?= number_format($purchase_due, 2); ?></div>
</div>

<?php if($block !== ''){ ?>
    <div class="alert alert-warning"><?= purchase_return_h($block); ?></div>
    <a href="purchase_return.php" class="btn btn-secondary">Back</a>
<?php }else{ ?>

<form method="post" action="purchase_return_save.php" id="purchase-return-form">
<input type="hidden" name="purchase_id" value="<?= (int)$purchase['id']; ?>">

<div class="table-responsive">
<table class="table table-bordered">
    <thead class="thead-light">
    <tr>
        <th>Product</th>
        <th class="text-right">Bought</th>
        <th class="text-right">Already returned</th>
        <th class="text-right">Sold / used</th>
        <th class="text-right">Unit cost</th>
        <th style="width:150px">Return qty</th>
        <th class="text-right">Value</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach($lines as $line){ ?>
        <tr data-return-line data-cost="<?= purchase_return_h((float)$line['unit_cost']); ?>">
            <td><?= purchase_return_h(purchase_return_line_label($line)); ?><?= !empty($line['sku']) ? '<br><small class="text-muted">Code: ' . purchase_return_h($line['sku']) . '</small>' : ''; ?></td>
            <td class="text-right"><?= (int)$line['quantity']; ?></td>
            <td class="text-right"><?= (int)$line['returned_qty']; ?></td>
            <td class="text-right"><?= (int)$line['sold_or_used_qty']; ?></td>
            <td class="text-right"><?= number_format((float)$line['unit_cost'], 2); ?></td>
            <td>
                <?php if((int)$line['returnable_qty'] > 0){ ?>
                <input type="number" class="form-control form-control-sm" name="return_qty[<?= (int)$line['id']; ?>]" min="0" max="<?= (int)$line['returnable_qty']; ?>" step="1" value="0" data-return-qty>
                <small class="text-muted">Max <?= (int)$line['returnable_qty']; ?></small>
                <?php }else{ ?><span class="text-muted">—</span><?php } ?>
            </td>
            <td class="text-right" data-return-value>0.00</td>
        </tr>
    <?php } ?>
    </tbody>
    <tfoot>
    <tr><th colspan="6" class="text-right">Return total</th><th class="text-right" id="return-total">0.00</th></tr>
    </tfoot>
</table>
</div>

<div class="row">
    <div class="col-md-4 form-group">
        <label for="due_adjusted">Deduct from purchase due</label>
        <input type="number" class="form-control" id="due_adjusted" name="due_adjusted" min="0" max="<?= purchase_return_h($purchase_due); ?>" step="0.01" value="0.00">
        <small class="text-muted">Up to <?= number_format($purchase_due, 2); ?> (what you still owe on this purchase).</small>
    </div>
    <div class="col-md-4 form-group">
        <label for="cash_refund">Cash refund from <?= purchase_return_h(supplier_display_text('supplier')); ?></label>
        <input type="number" class="form-control" id="cash_refund" name="cash_refund" min="0" step="0.01" value="0.00" readonly>
        <small class="text-muted">The rest of the return total, received back in cash.</small>
    </div>
    <div class="col-md-4 form-group" id="refund-wallet-group">
        <label for="refund_wallet_id">Receive refund into wallet</label>
        <select class="form-control" id="refund_wallet_id" name="refund_wallet_id">
            <?php while($wallet = mysqli_fetch_assoc($wallets)){ ?>
                <option value="<?= (int)$wallet['id']; ?>" <?= (int)$wallet['is_system'] === 1 ? 'selected' : ''; ?>><?= purchase_return_h($wallet['wallet_name']); ?></option>
            <?php } ?>
        </select>
    </div>
    <div class="col-12 form-group">
        <label for="note">Reason / note</label>
        <input type="text" class="form-control" id="note" name="note" maxlength="500" placeholder="e.g. Damaged, wrong size, quality issue">
    </div>
</div>

<button type="submit" class="btn btn-primary" id="save-return" disabled><i class="fas fa-undo"></i> Save Return</button>
<a href="purchase_return.php" class="btn btn-secondary">Back</a>
</form>

<script>
(function(){
    var form = document.getElementById('purchase-return-form');
    var due = document.getElementById('due_adjusted'), cash = document.getElementById('cash_refund');
    var maxDue = <?= json_encode($purchase_due); ?>, total = 0, dueTouched = false;
    var fmt = function(n){ return (Math.round(n * 100) / 100).toFixed(2); };
    function recalc(){
        total = 0;
        form.querySelectorAll('[data-return-line]').forEach(function(row){
            var input = row.querySelector('[data-return-qty]');
            var qty = input ? Math.max(0, Math.min(parseInt(input.max || '0', 10), parseInt(input.value, 10) || 0)) : 0;
            var value = qty * parseFloat(row.dataset.cost || '0');
            row.querySelector('[data-return-value]').textContent = fmt(value);
            total += value;
        });
        total = Math.round(total * 100) / 100;
        document.getElementById('return-total').textContent = fmt(total);
        // Default: lower the due first, take the rest as a cash refund.
        var dueValue = dueTouched ? Math.min(parseFloat(due.value) || 0, total, maxDue) : Math.min(total, maxDue);
        due.max = fmt(Math.min(total, maxDue));
        due.value = fmt(dueValue);
        cash.value = fmt(Math.max(0, total - dueValue));
        document.getElementById('refund-wallet-group').style.display = parseFloat(cash.value) > 0 ? '' : 'none';
        document.getElementById('save-return').disabled = total <= 0;
    }
    form.querySelectorAll('[data-return-qty]').forEach(function(input){ input.addEventListener('input', recalc); });
    due.addEventListener('input', function(){ dueTouched = true; recalc(); });
    form.addEventListener('submit', function(event){
        if(!confirm('Return these products to the supplier? Stock will be reduced now.')) event.preventDefault();
    });
    recalc();
})();
</script>

<?php } ?>

</div>

</div>

<?php require_once '../includes/footer.php'; ?>
