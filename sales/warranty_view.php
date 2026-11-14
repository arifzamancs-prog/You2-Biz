<?php
// Warranty: one claim — send to supplier, back from supplier, ready, deliver, cancel.
require_once __DIR__ . '/_sw_init.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$claim = sw_warranty_get($conn, $user_id, $id);
if (!$claim || !sw_branch_allows($conn, $claim['branch_id'])) {
    header('Location: warranty_claims.php');
    exit;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? '');
        sw_warranty_action($conn, $user_id, $id, $action, $_POST);
        $messages = ['send' => 'Sent to the supplier.', 'back' => 'Received back from the supplier.', 'ready' => 'Marked ready for delivery.', 'deliver' => 'Delivered to the customer.', 'cancel' => 'Claim cancelled.', 'expected' => 'Expected date updated.'];
        sw_flash('success', $messages[$action] ?? 'Saved.');
        header('Location: warranty_view.php?id=' . $id);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $claim = sw_warranty_get($conn, $user_id, $id);
    }
}

$statuses = sw_warranty_statuses();
$history = sw_history($conn, 'warranty', $id);
$status = $claim['status'];
$open = !in_array($status, ['delivered', 'cancelled'], true);
$suppliers = $open ? mysqli_query($conn, "SELECT id, supplier_name FROM suppliers WHERE user_id={$user_id} AND status='active' ORDER BY supplier_name") : null;
[$products, $variants] = $open ? sw_product_options($conn, $user_id) : [[], []];
$period = sw_warranty_period($claim['sale_date'], (int)$claim['warranty_months']);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
$hidden = static fn($action) => sw_csrf_field() . '<input type="hidden" name="id" value="' . (int)$claim['id'] . '"><input type="hidden" name="action" value="' . $action . '">';
?>
<section class="content pt-3"><div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <h4 class="mb-2 mb-sm-0"><i class="fas fa-shield-alt mr-2 text-primary"></i><?= sw_h($claim['claim_no']); ?>
            <span class="badge badge-<?= sw_status_badge($status); ?> align-middle"><?= sw_h($statuses[$status] ?? $status); ?></span></h4>
        <div>
            <a href="warranty_claims.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-list mr-1"></i>Warranty Claims</a>
            <a href="warranty_print.php?id=<?= (int)$claim['id']; ?>" target="_blank" class="btn btn-outline-primary btn-sm"><i class="fas fa-print mr-1"></i>Receipt</a>
        </div>
    </div>
    <?= sw_flash_html(); ?>
    <?php if ($error !== '') { ?><div class="alert alert-danger"><?= sw_h($error); ?></div><?php } ?>
    <div class="row">
        <div class="col-lg-5">
            <div class="card"><div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><th class="text-muted" style="width:38%">Customer</th><td><strong><?= sw_h($claim['customer_name']); ?></strong><div class="small"><?= sw_h($claim['customer_phone']); ?></div></td></tr>
                    <tr><th class="text-muted">Product</th><td><?= sw_h($claim['product_name']); ?><?= $claim['variant_name'] !== '' ? ' (' . sw_h($claim['variant_name']) . ')' : ''; ?><?= $claim['serial_no'] !== '' ? '<div class="small">Serial/IMEI: ' . sw_h($claim['serial_no']) . '</div>' : ''; ?></td></tr>
                    <tr><th class="text-muted">Sold on</th><td><a href="view_invoice.php?id=<?= (int)$claim['invoice_id']; ?>"><?= sw_h($claim['invoice_no']); ?></a> · <?= sw_date($claim['sale_date']); ?></td></tr>
                    <tr><th class="text-muted">Warranty</th><td><?php if ((int)$claim['warranty_months'] > 0) { ?><?= (int)$claim['warranty_months']; ?> months, until <?= sw_date($claim['warranty_until']); ?><?php } else { ?>Not set<?php } ?>
                        <div><?= (int)$claim['in_warranty'] ? '<span class="badge badge-success">In warranty when received</span>' : '<span class="badge badge-danger">Out of warranty when received</span>'; ?></div></td></tr>
                    <tr><th class="text-muted">Problem</th><td><?= nl2br(sw_h($claim['problem'])); ?></td></tr>
                    <?php if ($claim['accessories'] !== '') { ?><tr><th class="text-muted">Received with</th><td><?= sw_h($claim['accessories']); ?></td></tr><?php } ?>
                    <?php if (trim((string)$claim['note']) !== '') { ?><tr><th class="text-muted">Note</th><td><?= sw_h($claim['note']); ?></td></tr><?php } ?>
                    <tr><th class="text-muted">Received</th><td><?= sw_datetime($claim['received_at']); ?> <span class="small text-muted">by <?= sw_h($claim['created_by_name']); ?></span></td></tr>
                    <tr><th class="text-muted">Expected delivery</th><td><?= sw_date($claim['expected_date']); ?></td></tr>
                    <?php if ((int)$claim['supplier_id'] > 0) { ?><tr><th class="text-muted">Supplier</th><td><?= sw_h($claim['supplier_name']); ?><div class="small">Sent <?= sw_date($claim['sent_date']); ?><?= $claim['back_date'] ? ' · Back ' . sw_date($claim['back_date']) : ''; ?><?= $claim['supplier_result'] !== '' ? ' · ' . sw_h(sw_supplier_results()[$claim['supplier_result']] ?? $claim['supplier_result']) : ''; ?></div></td></tr><?php } ?>
                    <?php if ($status === 'delivered') { ?><tr><th class="text-muted">Delivered</th><td><?= sw_datetime($claim['delivered_at']); ?><div class="small"><?= sw_h(sw_warranty_delivery_types()[$claim['delivery_type']] ?? $claim['delivery_type']); ?><?= (int)$claim['replace_invoice_id'] > 0 ? ' · ' . sw_h($claim['replace_product_name']) . ($claim['replace_variant'] !== '' ? ' (' . sw_h($claim['replace_variant']) . ')' : '') . ' · <a href="view_invoice.php?id=' . (int)$claim['replace_invoice_id'] . '">' . sw_h($claim['replace_invoice_no']) . '</a>' : ''; ?></div></td></tr><?php } ?>
                </table>
            </div></div>
            <div class="card">
                <div class="card-header"><h3 class="card-title"><i class="fas fa-history mr-1"></i>History</h3></div>
                <div class="card-body p-0"><ul class="list-group list-group-flush">
                    <?php foreach ($history as $step) { ?>
                        <li class="list-group-item py-2"><span class="badge badge-<?= sw_status_badge($step['status']); ?>"><?= sw_h($statuses[$step['status']] ?? $step['status']); ?></span>
                            <span class="small text-muted ml-1"><?= sw_datetime($step['created_at']); ?> · <?= sw_h($step['created_by_name']); ?></span>
                            <?php if (trim((string)$step['note']) !== '') { ?><div class="small"><?= sw_h($step['note']); ?></div><?php } ?></li>
                    <?php } ?>
                </ul></div>
            </div>
        </div>
        <div class="col-lg-7">
            <?php if ($open) { ?>
                <?php if ($status === 'received') { ?>
                <div class="card card-outline card-warning"><div class="card-header"><h3 class="card-title"><i class="fas fa-truck mr-1"></i>Send to Supplier</h3></div>
                    <form method="post"><?= $hidden('send'); ?><div class="card-body"><div class="form-row">
                        <div class="form-group col-md-5"><label>Supplier</label><select name="supplier_id" class="form-control" required><option value="">Select supplier</option>
                            <?php while ($suppliers && ($s = mysqli_fetch_assoc($suppliers))) { ?><option value="<?= (int)$s['id']; ?>"><?= sw_h($s['supplier_name']); ?></option><?php } ?></select></div>
                        <div class="form-group col-md-3"><label>Date</label><input type="date" name="date" class="form-control" value="<?= date('Y-m-d'); ?>"></div>
                        <div class="form-group col-md-4"><label>Note</label><input name="note" class="form-control" maxlength="255" placeholder="Courier, reference…"></div>
                    </div><button class="btn btn-warning btn-sm"><i class="fas fa-paper-plane mr-1"></i>Mark Sent</button></div></form>
                </div>
                <?php } ?>
                <?php if ($status === 'sent_supplier') { ?>
                <div class="card card-outline card-primary"><div class="card-header"><h3 class="card-title"><i class="fas fa-undo mr-1"></i>Back from Supplier</h3></div>
                    <form method="post"><?= $hidden('back'); ?><div class="card-body"><div class="form-row">
                        <div class="form-group col-md-4"><label>Supplier result</label><select name="supplier_result" class="form-control" required><option value="">Select</option>
                            <?php foreach (sw_supplier_results() as $key => $label) { ?><option value="<?= sw_h($key); ?>"><?= sw_h($label); ?></option><?php } ?></select></div>
                        <div class="form-group col-md-3"><label>Date</label><input type="date" name="date" class="form-control" value="<?= date('Y-m-d'); ?>"></div>
                        <div class="form-group col-md-5"><label>Note</label><input name="note" class="form-control" maxlength="255"></div>
                    </div><button class="btn btn-primary btn-sm"><i class="fas fa-check mr-1"></i>Mark Received Back</button></div></form>
                </div>
                <?php } ?>
                <?php if ($status !== 'sent_supplier') { ?>
                <div class="card card-outline card-success"><div class="card-header"><h3 class="card-title"><i class="fas fa-hand-holding mr-1"></i>Deliver to Customer</h3></div>
                    <form method="post" id="deliver-form"><?= $hidden('deliver'); ?><div class="card-body">
                        <div class="form-group"><label>Delivery</label>
                            <?php $default_type = $claim['supplier_result'] === 'replaced' ? 'replaced_supplier' : ($claim['supplier_result'] === 'rejected' ? 'returned' : 'repaired');
                            foreach (sw_warranty_delivery_types() as $key => $label) { ?>
                                <div class="custom-control custom-radio"><input type="radio" class="custom-control-input" id="dt_<?= sw_h($key); ?>" name="delivery_type" value="<?= sw_h($key); ?>" <?= $key === $default_type ? 'checked' : ''; ?>><label class="custom-control-label" for="dt_<?= sw_h($key); ?>"><?= sw_h($label); ?></label></div>
                            <?php } ?>
                        </div>
                        <div class="form-row sw-replace" style="display:none">
                            <div class="form-group col-md-8"><label>Replacement product (from stock)</label><select name="replace_product_id" id="replace_product" class="form-control">
                                <?php foreach ($products as $p) { ?><option value="<?= (int)$p['id']; ?>" <?= (int)$p['id'] === (int)$claim['product_id'] ? 'selected' : ''; ?>><?= sw_h($p['product_name'] . ($p['sku'] ? ' [' . $p['sku'] . ']' : '')); ?></option><?php } ?></select></div>
                            <div class="form-group col-md-4"><label>Variant</label><select name="replace_variant" id="replace_variant" class="form-control"></select></div>
                            <div class="col-12"><p class="small text-muted">One unit leaves stock on a zero-value invoice, so its cost is recorded.</p></div>
                        </div>
                        <div class="form-group"><label>Note</label><input name="note" class="form-control" maxlength="255"></div>
                        <button class="btn btn-success"><i class="fas fa-check-double mr-1"></i>Deliver</button>
                    </div></form>
                </div>
                <?php } ?>
                <div class="row">
                    <?php if (in_array($status, ['received', 'back_supplier'], true)) { ?>
                    <div class="col-md-6"><div class="card card-outline card-info"><div class="card-body">
                        <form method="post"><?= $hidden('ready'); ?><label>Ready for delivery</label><input name="note" class="form-control form-control-sm mb-2" placeholder="Note (optional)" maxlength="255">
                            <button class="btn btn-info btn-sm btn-block"><i class="fas fa-check mr-1"></i>Mark Ready</button></form>
                    </div></div></div>
                    <?php } ?>
                    <div class="col-md-6"><div class="card card-outline card-secondary"><div class="card-body">
                        <form method="post"><?= $hidden('expected'); ?><label>Expected delivery date</label><input type="date" name="date" class="form-control form-control-sm mb-2" value="<?= sw_h($claim['expected_date']); ?>" required>
                            <button class="btn btn-outline-secondary btn-sm btn-block"><i class="fas fa-calendar-alt mr-1"></i>Update Date</button></form>
                    </div></div></div>
                    <div class="col-md-6"><div class="card card-outline card-danger"><div class="card-body">
                        <form method="post" onsubmit="return confirm('Cancel this warranty claim?');"><?= $hidden('cancel'); ?><label>Cancel claim</label><input name="note" class="form-control form-control-sm mb-2" placeholder="Reason" maxlength="255" required>
                            <button class="btn btn-outline-danger btn-sm btn-block"><i class="fas fa-times mr-1"></i>Cancel Claim</button></form>
                    </div></div></div>
                </div>
            <?php } else { ?>
                <div class="alert alert-light border">This claim is <?= sw_h(strtolower($statuses[$status] ?? $status)); ?>.</div>
            <?php } ?>
        </div>
    </div>
</div></section>
<?php if ($open && $status !== 'sent_supplier') { ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const variants = <?= json_encode($variants, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE); ?>;
    const current = <?= json_encode((string)$claim['variant_name'], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE); ?>;
    const form = document.getElementById('deliver-form');
    const product = document.getElementById('replace_product');
    const variant = document.getElementById('replace_variant');
    const fill = function () {
        const list = variants[product.value] || [];
        variant.innerHTML = list.length ? '' : '<option value="">—</option>';
        list.forEach(function (name) { const o = document.createElement('option'); o.value = name; o.textContent = name; o.selected = name === current; variant.appendChild(o); });
    };
    const sync = function () {
        const type = form.querySelector('input[name="delivery_type"]:checked');
        form.querySelector('.sw-replace').style.display = type && type.value === 'replaced_stock' ? '' : 'none';
    };
    product.addEventListener('change', fill);
    if (window.jQuery && jQuery.fn.select2) jQuery(product).select2({theme: 'bootstrap4', width: '100%'}).on('select2:select', fill);
    form.querySelectorAll('input[name="delivery_type"]').forEach(function (radio) { radio.addEventListener('change', sync); });
    fill(); sync();
    form.addEventListener('submit', function (event) {
        const type = form.querySelector('input[name="delivery_type"]:checked');
        if (type && type.value === 'replaced_stock' && !confirm('Give one unit from stock as the replacement?')) event.preventDefault();
    });
});
</script>
<?php } ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
