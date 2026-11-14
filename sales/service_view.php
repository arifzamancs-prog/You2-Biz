<?php
// Service Center: one job — mark ready, change delivery date, deliver (bill), cancel.
require_once __DIR__ . '/_sw_init.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$job = sw_service_get($conn, $user_id, $id);
if (!$job || !sw_branch_allows($conn, $job['branch_id'])) {
    header('Location: service_jobs.php');
    exit;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'ready') {
            sw_service_mark_ready($conn, $user_id, $id, $_POST['note'] ?? '');
            sw_flash('success', 'Job marked ready for delivery.');
        } elseif ($action === 'date') {
            sw_service_update_delivery_date($conn, $user_id, $id, $_POST['delivery_date'] ?? '', $_POST['note'] ?? '');
            sw_flash('success', 'Delivery date updated.');
        } elseif ($action === 'cancel') {
            sw_service_cancel($conn, $user_id, $id, !empty($_POST['refund_advance']), $_POST['note'] ?? '');
            sw_flash('success', 'Job cancelled.');
        } elseif ($action === 'deliver') {
            $parts = [];
            foreach ((array)($_POST['part_product'] ?? []) as $i => $product_id) {
                if ((int)$product_id <= 0) {
                    continue;
                }
                $parts[] = [
                    'product_id' => (int)$product_id,
                    'variant_name' => trim((string)($_POST['part_variant'][$i] ?? '')),
                    'qty' => (int)($_POST['part_qty'][$i] ?? 0),
                    'unit_price' => (float)($_POST['part_price'][$i] ?? 0),
                ];
            }
            $invoice_id = sw_service_deliver($conn, $user_id, $id, $parts, $_POST['service_charge'] ?? 0, $_POST['paid_now'] ?? 0, (int)($_POST['wallet_id'] ?? 0), $_POST['note'] ?? '');
            sw_flash('success', 'Delivered. The invoice has been created.');
            header('Location: print_invoice.php?id=' . $invoice_id);
            exit;
        }
        header('Location: service_view.php?id=' . $id);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $job = sw_service_get($conn, $user_id, $id);
    }
}

$statuses = sw_service_statuses();
$history = sw_history($conn, 'service', $id);
$open = in_array($job['status'], ['received', 'ready'], true);
[$products, $variants] = $open ? sw_product_options($conn, $user_id) : [[], []];
$late = $open && $job['delivery_date'] && $job['delivery_date'] < date('Y-m-d');

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<section class="content pt-3"><div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <h4 class="mb-2 mb-sm-0"><i class="fas fa-tools mr-2 text-primary"></i><?= sw_h($job['job_no']); ?>
            <span class="badge badge-<?= sw_status_badge($job['status']); ?> align-middle"><?= sw_h($statuses[$job['status']] ?? $job['status']); ?></span></h4>
        <div>
            <a href="service_jobs.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-list mr-1"></i>Service Jobs</a>
            <a href="service_print.php?id=<?= (int)$job['id']; ?>" target="_blank" class="btn btn-outline-primary btn-sm"><i class="fas fa-print mr-1"></i>Receipt</a>
            <?php if ((int)$job['invoice_id'] > 0) { ?><a href="print_invoice.php?id=<?= (int)$job['invoice_id']; ?>" target="_blank" class="btn btn-success btn-sm"><i class="fas fa-file-invoice mr-1"></i>Invoice <?= sw_h($job['invoice_no']); ?></a><?php } ?>
        </div>
    </div>
    <?= sw_flash_html(); ?>
    <?php if ($error !== '') { ?><div class="alert alert-danger"><?= sw_h($error); ?></div><?php } ?>
    <div class="row">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-body">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><th class="text-muted" style="width:38%">Customer</th><td><strong><?= sw_h($job['customer_name']); ?></strong><?= (int)$job['customer_id'] === 0 ? ' <span class="badge badge-light">Walk-in</span>' : ''; ?><div class="small"><?= sw_h($job['customer_phone']); ?></div></td></tr>
                        <tr><th class="text-muted">Product</th><td><?= sw_h($job['item_name']); ?><?= $job['brand_model'] !== '' ? ' · ' . sw_h($job['brand_model']) : ''; ?><?= $job['serial_no'] !== '' ? '<div class="small">Serial/IMEI: ' . sw_h($job['serial_no']) . '</div>' : ''; ?></td></tr>
                        <tr><th class="text-muted">Problem</th><td><?= nl2br(sw_h($job['problem'])); ?></td></tr>
                        <?php if ($job['accessories'] !== '') { ?><tr><th class="text-muted">Received with</th><td><?= sw_h($job['accessories']); ?></td></tr><?php } ?>
                        <?php if (trim((string)$job['note']) !== '') { ?><tr><th class="text-muted">Note</th><td><?= nl2br(sw_h($job['note'])); ?></td></tr><?php } ?>
                        <tr><th class="text-muted">Received</th><td><?= sw_datetime($job['received_at']); ?> <span class="small text-muted">by <?= sw_h($job['created_by_name']); ?></span></td></tr>
                        <tr><th class="text-muted">Delivery date</th><td class="<?= $late ? 'text-danger font-weight-bold' : ''; ?>"><?= sw_date($job['delivery_date']); ?><?= $late ? ' (overdue)' : ''; ?></td></tr>
                        <?php if (!empty($job['staff_name'])) { ?><tr><th class="text-muted">Technician</th><td><?= sw_h($job['staff_name']); ?></td></tr><?php } ?>
                        <tr><th class="text-muted">Estimated charge</th><td>BDT <?= sw_amount($job['estimated_charge']); ?></td></tr>
                        <tr><th class="text-muted">Advance</th><td>BDT <?= sw_amount($job['advance_amount']); ?><?= !empty($job['advance_wallet_name']) && (float)$job['advance_amount'] > 0 ? ' <span class="small text-muted">(' . sw_h($job['advance_wallet_name']) . ')</span>' : ''; ?></td></tr>
                        <?php if ($job['status'] === 'delivered') { ?>
                            <tr><th class="text-muted">Service charge</th><td>BDT <?= sw_amount($job['service_charge']); ?></td></tr>
                            <tr><th class="text-muted">Parts</th><td>BDT <?= sw_amount($job['parts_total']); ?></td></tr>
                            <tr><th class="text-muted">Delivered</th><td><?= sw_datetime($job['delivered_at']); ?></td></tr>
                        <?php } ?>
                        <?php if ((float)$job['refund_amount'] > 0) { ?><tr><th class="text-muted">Advance refunded</th><td>BDT <?= sw_amount($job['refund_amount']); ?></td></tr><?php } ?>
                    </table>
                </div>
            </div>
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
            <div class="card card-outline card-success">
                <div class="card-header"><h3 class="card-title"><i class="fas fa-check-circle mr-1"></i>Deliver &amp; Bill</h3></div>
                <form method="post" id="deliver-form">
                    <?= sw_csrf_field(); ?><input type="hidden" name="id" value="<?= (int)$job['id']; ?>"><input type="hidden" name="action" value="deliver">
                    <div class="card-body">
                        <label class="mb-1">Parts used from stock <span class="text-muted small">(optional)</span></label>
                        <div class="table-responsive"><table class="table table-sm mb-2" id="parts">
                            <thead><tr><th>Product</th><th style="width:16%">Variant</th><th style="width:12%">Qty</th><th style="width:17%">Price</th><th style="width:14%" class="text-right">Total</th><th></th></tr></thead>
                            <tbody></tbody>
                        </table></div>
                        <button type="button" class="btn btn-outline-primary btn-xs mb-3" id="add-part"><i class="fas fa-plus mr-1"></i>Add part</button>
                        <div class="form-row">
                            <div class="form-group col-md-4"><label>Service charge</label><input type="number" step="0.01" min="0" name="service_charge" id="service_charge" class="form-control" value="<?= sw_h(number_format((float)$job['estimated_charge'], 2, '.', '')); ?>"></div>
                            <div class="form-group col-md-4"><label>Paid now</label><input type="number" step="0.01" min="0" name="paid_now" id="paid_now" class="form-control" value="0"></div>
                            <div class="form-group col-md-4"><label>Wallet</label><select name="wallet_id" class="form-control"><?= sw_wallet_options($conn, $user_id, (int)$job['advance_wallet_id']); ?></select></div>
                        </div>
                        <table class="table table-sm mb-2" style="max-width:360px;margin-left:auto">
                            <tr><th>Parts</th><td class="text-right" id="sum-parts">0.00</td></tr>
                            <tr><th>Service charge</th><td class="text-right" id="sum-charge">0.00</td></tr>
                            <tr><th>Total</th><td class="text-right font-weight-bold" id="sum-total">0.00</td></tr>
                            <tr><th>Advance</th><td class="text-right">− <?= sw_amount($job['advance_amount']); ?></td></tr>
                            <tr><th id="sum-pay-label">Payable</th><td class="text-right font-weight-bold" id="sum-pay">0.00</td></tr>
                            <tr><th>Due after payment</th><td class="text-right" id="sum-due">0.00</td></tr>
                        </table>
                        <?php if ((int)$job['customer_id'] === 0) { ?><p class="small text-muted mb-2">Walk-in customer: the full payable amount must be paid on delivery.</p><?php } ?>
                        <div class="form-group mb-0"><label>Note</label><input name="note" class="form-control" maxlength="255" placeholder="Work done, warranty on repair…"></div>
                    </div>
                    <div class="card-footer text-right"><button type="submit" class="btn btn-success"><i class="fas fa-file-invoice mr-1"></i>Deliver &amp; Print Invoice</button></div>
                </form>
            </div>
            <div class="row">
                <?php if ($job['status'] === 'received') { ?>
                <div class="col-md-6"><div class="card card-outline card-info"><div class="card-body">
                    <form method="post"><?= sw_csrf_field(); ?><input type="hidden" name="id" value="<?= (int)$job['id']; ?>"><input type="hidden" name="action" value="ready">
                        <label>Repair finished</label><input name="note" class="form-control form-control-sm mb-2" placeholder="Note (optional)" maxlength="255">
                        <button class="btn btn-info btn-sm btn-block"><i class="fas fa-check mr-1"></i>Mark Ready</button></form>
                </div></div></div>
                <?php } ?>
                <div class="col-md-6"><div class="card card-outline card-secondary"><div class="card-body">
                    <form method="post"><?= sw_csrf_field(); ?><input type="hidden" name="id" value="<?= (int)$job['id']; ?>"><input type="hidden" name="action" value="date">
                        <label>Change delivery date</label><input type="date" name="delivery_date" class="form-control form-control-sm mb-2" value="<?= sw_h($job['delivery_date']); ?>" required>
                        <input name="note" class="form-control form-control-sm mb-2" placeholder="Reason (optional)" maxlength="255">
                        <button class="btn btn-outline-secondary btn-sm btn-block"><i class="fas fa-calendar-alt mr-1"></i>Update Date</button></form>
                </div></div></div>
                <div class="col-md-6"><div class="card card-outline card-danger"><div class="card-body">
                    <form method="post" onsubmit="return confirm('Cancel this service job?');"><?= sw_csrf_field(); ?><input type="hidden" name="id" value="<?= (int)$job['id']; ?>"><input type="hidden" name="action" value="cancel">
                        <label>Cancel job</label><input name="note" class="form-control form-control-sm mb-2" placeholder="Reason" maxlength="255" required>
                        <?php if ((float)$job['advance_amount'] > 0) { ?><div class="custom-control custom-checkbox mb-2"><input type="checkbox" class="custom-control-input" id="refund_advance" name="refund_advance" value="1" checked><label class="custom-control-label" for="refund_advance">Refund advance BDT <?= sw_amount($job['advance_amount']); ?></label></div><?php } ?>
                        <button class="btn btn-outline-danger btn-sm btn-block"><i class="fas fa-times mr-1"></i>Cancel Job</button></form>
                </div></div></div>
            </div>
            <?php } else { ?>
                <div class="alert alert-light border">This job is <?= sw_h(strtolower($statuses[$job['status']] ?? $job['status'])); ?>.<?php if ((int)$job['invoice_id'] > 0) { ?> Payments and dues continue on invoice <a href="view_invoice.php?id=<?= (int)$job['invoice_id']; ?>"><?= sw_h($job['invoice_no']); ?></a>.<?php } ?></div>
            <?php } ?>
        </div>
    </div>
</div></section>
<?php if ($open) { ?>
<template id="part-row">
    <tr>
        <td><select name="part_product[]" class="form-control form-control-sm part-product"><option value="">Select product</option>
            <?php foreach ($products as $p) { ?><option value="<?= (int)$p['id']; ?>" data-price="<?= sw_h($p['sale_price']); ?>"><?= sw_h($p['product_name'] . ($p['sku'] ? ' [' . $p['sku'] . ']' : '')); ?></option><?php } ?>
        </select></td>
        <td><select name="part_variant[]" class="form-control form-control-sm part-variant"><option value="">—</option></select></td>
        <td><input type="number" name="part_qty[]" class="form-control form-control-sm part-qty" min="1" step="1" value="1"></td>
        <td><input type="number" name="part_price[]" class="form-control form-control-sm part-price" min="0" step="0.01" value="0"></td>
        <td class="text-right part-total align-middle">0.00</td>
        <td class="align-middle"><button type="button" class="btn btn-xs btn-outline-danger part-remove">&times;</button></td>
    </tr>
</template>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const variants = <?= json_encode($variants, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE); ?>;
    const advance = <?= json_encode((float)$job['advance_amount']); ?>;
    const walkin = <?= (int)$job['customer_id'] === 0 ? 'true' : 'false'; ?>;
    const body = document.querySelector('#parts tbody');
    const charge = document.getElementById('service_charge');
    const paid = document.getElementById('paid_now');
    const money = function (v) { return (Math.round((Number(v) || 0) * 100) / 100).toFixed(2); };
    let paidTouched = false;
    const recalc = function () {
        let parts = 0;
        body.querySelectorAll('tr').forEach(function (row) {
            const line = (parseInt(row.querySelector('.part-qty').value, 10) || 0) * (Number(row.querySelector('.part-price').value) || 0);
            row.querySelector('.part-total').textContent = money(line);
            parts += line;
        });
        const total = parts + (Number(charge.value) || 0);
        const payable = total - advance;
        document.getElementById('sum-parts').textContent = money(parts);
        document.getElementById('sum-charge').textContent = money(charge.value);
        document.getElementById('sum-total').textContent = money(total);
        document.getElementById('sum-pay-label').textContent = payable < 0 ? 'Advance to refund' : 'Payable';
        document.getElementById('sum-pay').textContent = money(Math.abs(payable));
        if (!paidTouched) paid.value = money(Math.max(0, payable));
        paid.readOnly = payable <= 0;
        if (payable <= 0) paid.value = '0.00';
        document.getElementById('sum-due').textContent = money(Math.max(0, payable - (Number(paid.value) || 0)));
    };
    const addRow = function () {
        const row = document.getElementById('part-row').content.firstElementChild.cloneNode(true);
        body.appendChild(row);
        const product = row.querySelector('.part-product');
        if (window.jQuery && jQuery.fn.select2) jQuery(product).select2({theme: 'bootstrap4', width: '100%'}).on('select2:select', function () { product.dispatchEvent(new Event('change')); });
        product.addEventListener('change', function () {
            const option = product.selectedOptions[0];
            row.querySelector('.part-price').value = option && option.dataset.price ? money(option.dataset.price) : '0.00';
            const select = row.querySelector('.part-variant');
            const list = variants[product.value] || [];
            select.innerHTML = list.length ? '' : '<option value="">—</option>';
            list.forEach(function (name) { const o = document.createElement('option'); o.value = name; o.textContent = name; select.appendChild(o); });
            recalc();
        });
        row.querySelector('.part-remove').addEventListener('click', function () { row.remove(); recalc(); });
        row.querySelectorAll('input').forEach(function (input) { input.addEventListener('input', recalc); });
    };
    document.getElementById('add-part').addEventListener('click', addRow);
    charge.addEventListener('input', recalc);
    paid.addEventListener('input', function () { paidTouched = true; recalc(); });
    recalc();
    document.getElementById('deliver-form').addEventListener('submit', function (event) {
        const due = Number(document.getElementById('sum-due').textContent) || 0;
        if (walkin && due > 0.009) { event.preventDefault(); alert('A walk-in customer must pay the full payable amount.'); return; }
        if (!confirm('Deliver the product and create the invoice (BDT ' + document.getElementById('sum-total').textContent + ')?')) event.preventDefault();
    });
});
</script>
<?php } ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
