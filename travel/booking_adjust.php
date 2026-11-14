<?php
require_once __DIR__ . '/_init.php';

$booking = travel_booking_get($conn, $user_id, (int)($_GET['id'] ?? ($_POST['booking_id'] ?? 0)));
$kind = in_array($_GET['type'] ?? ($_POST['kind'] ?? ''), ['refund', 'void', 'reissue'], true) ? ($_GET['type'] ?? $_POST['kind']) : 'refund';
if (!$booking) {
    header('Location: bookings.php');
    exit;
}
$error = '';
$cash_wallet_id = ensure_default_cash_wallet($conn, $user_id);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    travel_verify_csrf();
    try {
        if ($kind === 'reissue') {
            travel_booking_reissue(
                $conn, $user_id, (int)$booking['id'], (string)($_POST['adjust_date'] ?? ''),
                (float)($_POST['extra_cost'] ?? 0), (int)($_POST['vendor_id'] ?? 0), (float)($_POST['vendor_paid'] ?? 0), (int)($_POST['vendor_wallet'] ?? 0),
                (float)($_POST['extra_price'] ?? 0), (float)($_POST['client_paid'] ?? 0), (int)($_POST['client_wallet'] ?? 0),
                ['travel_date' => (string)($_POST['travel_date'] ?? ''), 'return_date' => (string)($_POST['return_date'] ?? ''), 'pnr' => (string)($_POST['pnr'] ?? ''), 'tickets' => (array)($_POST['ticket'] ?? [])],
                (string)($_POST['note'] ?? ''), (string)($_POST['request_key'] ?? '')
            );
        } else {
            travel_booking_refund(
                $conn, $user_id, (int)$booking['id'], $kind, (string)($_POST['adjust_date'] ?? ''),
                (float)($_POST['vendor_refund'] ?? 0), (float)($_POST['client_refund'] ?? 0), (int)($_POST['vendor_wallet'] ?? 0),
                (float)($_POST['pay_back'] ?? 0), (int)($_POST['client_wallet'] ?? 0),
                $kind === 'void' || ($_POST['scope'] ?? 'full') === 'full',
                (string)($_POST['note'] ?? ''), (string)($_POST['request_key'] ?? '')
            );
        }
        header('Location: booking_view.php?id=' . (int)$booking['id'] . '&adjusted=' . $kind);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($booking['status'] !== 'active') {
    header('Location: booking_view.php?id=' . (int)$booking['id']);
    exit;
}
$labels = travel_adjustment_kinds();
// What we still owe this vendor on the booking (booking bill + reissue bills).
$vendor_bills = (int)$booking['purchase_id'] > 0 ? travel_booking_vendor_bills($conn, $user_id, $booking) : [];
$vendor_bill = $vendor_bills ? ['due_amount' => array_sum(array_map(static fn($bill) => max(0, (float)$bill['due_amount']), $vendor_bills))] : null;
$vendors = travel_vendors($conn, $user_id);
$form = $_POST;
$value = static fn($key, $default = '') => htmlspecialchars((string)($form[$key] ?? $default));
$money = static fn($v) => number_format((float)$v, 2, '.', '');

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<style>.adjust-summary td{padding:.3rem .5rem}.adjust-result{font-weight:700}</style>
<section class="content pt-3"><div class="container-fluid">
    <ul class="nav nav-pills mb-3">
        <?php foreach ($labels as $key => $label) { ?><li class="nav-item"><a class="nav-link<?= $key === $kind ? ' active' : ''; ?>" href="?id=<?= (int)$booking['id']; ?>&type=<?= $key; ?>"><?= $label; ?></a></li><?php } ?>
        <li class="nav-item ml-auto"><a class="nav-link" href="booking_view.php?id=<?= (int)$booking['id']; ?>"><i class="fas fa-arrow-left mr-1"></i>Back to booking</a></li>
    </ul>
    <div class="row">
        <div class="col-lg-4">
            <div class="card card-outline card-secondary">
                <div class="card-header"><h3 class="card-title"><?= htmlspecialchars($booking['booking_no']); ?></h3></div>
                <div class="card-body p-0"><table class="table table-sm mb-0 adjust-summary">
                    <tr><th>Client</th><td><?= htmlspecialchars($booking['client']['customer_name'] ?? ''); ?></td></tr>
                    <tr><th>Service</th><td><?= htmlspecialchars((travel_service_kinds()[$booking['service_kind']] ?? '') . ($booking['route'] !== '' ? ' · ' . $booking['route'] : '')); ?><?= $booking['pnr'] !== '' ? '<div class="small text-muted">PNR ' . htmlspecialchars($booking['pnr']) . '</div>' : ''; ?></td></tr>
                    <tr><th>Vendor</th><td><?= htmlspecialchars($booking['vendor_name'] ?: '—'); ?></td></tr>
                    <tr><th>Purchase</th><td>BDT <?= travel_money($booking['net_cost']); ?><?= $vendor_bill ? '<div class="small text-muted">Still owed to vendor: ' . travel_money($vendor_bill['due_amount']) . '</div>' : ''; ?></td></tr>
                    <tr><th>Selling</th><td>BDT <?= travel_money($booking['net_price']); ?></td></tr>
                    <tr><th>Client paid</th><td>BDT <?= travel_money($booking['paid']); ?><div class="small text-muted">Due: <?= travel_money(max(0, $booking['due'])); ?></div></td></tr>
                </table></div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card card-outline <?= $kind === 'reissue' ? 'card-info' : 'card-danger'; ?>">
                <div class="card-header"><h3 class="card-title"><i class="fas <?= $kind === 'reissue' ? 'fa-sync-alt' : ($kind === 'void' ? 'fa-ban' : 'fa-undo'); ?> mr-2"></i><?= $labels[$kind]; ?></h3></div>
                <div class="card-body">
                    <?php if ($error !== '') { ?><div class="alert alert-danger"><?= htmlspecialchars($error); ?></div><?php } ?>
                    <form method="post" id="adjust-form">
                        <?= travel_csrf_field(); ?>
                        <input type="hidden" name="booking_id" value="<?= (int)$booking['id']; ?>">
                        <input type="hidden" name="kind" value="<?= $kind; ?>">
                        <input type="hidden" name="request_key" value="<?= htmlspecialchars((string)($form['request_key'] ?? bin2hex(random_bytes(24)))); ?>">
                        <div class="form-row">
                            <div class="form-group col-md-4"><label for="adjust_date">Date</label><input id="adjust_date" type="date" name="adjust_date" class="form-control" max="<?= date('Y-m-d'); ?>" value="<?= $value('adjust_date', date('Y-m-d')); ?>" required></div>
                            <?php if ($kind === 'refund') { ?>
                            <div class="form-group col-md-8"><label>Refund</label><div>
                                <div class="custom-control custom-radio custom-control-inline"><input type="radio" id="scope-full" name="scope" value="full" class="custom-control-input"<?= ($form['scope'] ?? 'full') === 'full' ? ' checked' : ''; ?>><label class="custom-control-label" for="scope-full">Full booking (status: Refunded)</label></div>
                                <div class="custom-control custom-radio custom-control-inline"><input type="radio" id="scope-partial" name="scope" value="partial" class="custom-control-input"<?= ($form['scope'] ?? '') === 'partial' ? ' checked' : ''; ?>><label class="custom-control-label" for="scope-partial">Partial (e.g. one passenger)</label></div>
                            </div></div>
                            <?php } ?>
                        </div>

                        <?php if ($kind === 'reissue') { ?>
                            <h6 class="text-info font-weight-bold mt-2">New travel details <small class="text-muted">(leave blank to keep)</small></h6>
                            <div class="form-row">
                                <div class="form-group col-md-4"><label for="travel_date">New Departure</label><input id="travel_date" type="date" name="travel_date" class="form-control" value="<?= $value('travel_date', (string)$booking['travel_date']); ?>"></div>
                                <div class="form-group col-md-4"><label for="return_date">New Return</label><input id="return_date" type="date" name="return_date" class="form-control" value="<?= $value('return_date', (string)$booking['return_date']); ?>"></div>
                                <div class="form-group col-md-4"><label for="pnr">New PNR</label><input id="pnr" name="pnr" class="form-control text-uppercase" maxlength="40" value="<?= $value('pnr', $booking['pnr']); ?>"></div>
                            </div>
                            <?php if ($booking['passengers']) {
                                $passenger_ids = mysqli_query($conn, 'SELECT id, passenger_name, ticket_no FROM travel_booking_passengers WHERE booking_id=' . (int)$booking['id'] . ' ORDER BY id'); ?>
                                <div class="form-row">
                                <?php while ($passenger_ids && ($p = mysqli_fetch_assoc($passenger_ids))) { ?>
                                    <div class="form-group col-md-6"><label>New ticket no · <?= htmlspecialchars($p['passenger_name']); ?></label><input name="ticket[<?= (int)$p['id']; ?>]" class="form-control" maxlength="60" placeholder="<?= htmlspecialchars($p['ticket_no']); ?>"></div>
                                <?php } ?>
                                </div>
                            <?php } ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="text-secondary font-weight-bold">Vendor charge</h6>
                                    <div class="form-group"><label for="vendor_id">Vendor</label><select id="vendor_id" name="vendor_id" class="form-control">
                                        <?php foreach ($vendors as $vendor) { ?><option value="<?= (int)$vendor['id']; ?>"<?= (int)($form['vendor_id'] ?? $booking['vendor_id']) === (int)$vendor['id'] ? ' selected' : ''; ?>><?= htmlspecialchars($vendor['supplier_name']); ?></option><?php } ?>
                                    </select></div>
                                    <div class="form-group"><label for="extra_cost">Fare difference + penalty (vendor)</label><input id="extra_cost" type="number" step="0.01" min="0" name="extra_cost" class="form-control" value="<?= $value('extra_cost', '0'); ?>"></div>
                                    <div class="form-row"><div class="form-group col-6"><label for="vendor_paid">Paid now</label><input id="vendor_paid" type="number" step="0.01" min="0" name="vendor_paid" class="form-control" value="<?= $value('vendor_paid', '0'); ?>"></div>
                                        <div class="form-group col-6"><label for="vendor_wallet">From wallet</label><select id="vendor_wallet" name="vendor_wallet" class="form-control"><?= travel_wallet_options($conn, $user_id, (int)($form['vendor_wallet'] ?? $cash_wallet_id)); ?></select></div></div>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-secondary font-weight-bold">Client charge</h6>
                                    <div class="form-group"><label for="extra_price">Charge to client (incl. service fee)</label><input id="extra_price" type="number" step="0.01" min="0" name="extra_price" class="form-control" value="<?= $value('extra_price', '0'); ?>"></div>
                                    <div class="form-row"><div class="form-group col-6"><label for="client_paid">Received now</label><input id="client_paid" type="number" step="0.01" min="0" name="client_paid" class="form-control" value="<?= $value('client_paid', '0'); ?>"></div>
                                        <div class="form-group col-6"><label for="client_wallet">To wallet</label><select id="client_wallet" name="client_wallet" class="form-control"><?= travel_wallet_options($conn, $user_id, (int)($form['client_wallet'] ?? $cash_wallet_id)); ?></select></div></div>
                                    <div>Profit on reissue: <span id="reissue-profit" class="adjust-result">0.00</span></div>
                                </div>
                            </div>
                        <?php } else { ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="text-secondary font-weight-bold">Vendor</h6>
                                    <div class="form-group"><label for="vendor_refund">Refund from vendor</label><input id="vendor_refund" type="number" step="0.01" min="0" max="<?= $money($booking['net_cost']); ?>" name="vendor_refund" class="form-control" value="<?= $value('vendor_refund', '0'); ?>"<?= (int)$booking['purchase_id'] > 0 ? '' : ' readonly'; ?>>
                                        <small class="form-text text-muted">Vendor keeps: <strong id="vendor-keeps">0.00</strong>. First lowers the vendor due, the rest comes back in cash.</small></div>
                                    <div class="form-group"><label for="vendor_wallet">Vendor cash back to wallet</label><select id="vendor_wallet" name="vendor_wallet" class="form-control"><?= travel_wallet_options($conn, $user_id, (int)($form['vendor_wallet'] ?? $cash_wallet_id)); ?></select>
                                        <small class="form-text text-muted">Cash back: <strong id="vendor-cash">0.00</strong></small></div>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-secondary font-weight-bold">Client</h6>
                                    <div class="form-group"><label for="client_refund">Refund to client</label><input id="client_refund" type="number" step="0.01" min="0" max="<?= $money($booking['net_price']); ?>" name="client_refund" class="form-control" value="<?= $value('client_refund', '0'); ?>">
                                        <small class="form-text text-muted">Client pays (charge): <strong id="client-keeps">0.00</strong></small></div>
                                    <div class="form-row"><div class="form-group col-6"><label for="pay_back">Pay back now</label><input id="pay_back" type="number" step="0.01" min="0" name="pay_back" class="form-control" value="<?= $value('pay_back', '0'); ?>"><small class="form-text text-muted">Up to <strong id="pay-back-max">0.00</strong>; the rest stays as advance.</small></div>
                                        <div class="form-group col-6"><label for="client_wallet">From wallet</label><select id="client_wallet" name="client_wallet" class="form-control"><?= travel_wallet_options($conn, $user_id, (int)($form['client_wallet'] ?? $cash_wallet_id)); ?></select></div></div>
                                </div>
                            </div>
                            <div class="alert alert-light border mb-3">Profit after <?= strtolower($labels[$kind]); ?>: <span id="refund-profit" class="adjust-result">0.00</span> <small class="text-muted">(client pays − vendor keeps)</small></div>
                        <?php } ?>
                        <div class="form-group"><label for="note">Note</label><input id="note" name="note" class="form-control" maxlength="500" placeholder="Reason, airline reference…" value="<?= $value('note'); ?>"></div>
                        <button class="btn <?= $kind === 'reissue' ? 'btn-info' : 'btn-danger'; ?>"><i class="fas fa-check mr-1"></i>Save <?= $labels[$kind]; ?></button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div></section>
<script>
(function () {
    const n = id => { const el = document.getElementById(id); return el ? Number(el.value || 0) : 0; };
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v.toFixed(2); };
    const netCost = <?= $money($booking['net_cost']); ?>, netPrice = <?= $money($booking['net_price']); ?>, paid = <?= $money($booking['paid']); ?>, vendorDue = <?= $money($vendor_bill['due_amount'] ?? 0); ?>;
    function refresh() {
        if (document.getElementById('vendor_refund')) {
            const vendorKeeps = netCost - n('vendor_refund'), clientPays = netPrice - n('client_refund');
            set('vendor-keeps', vendorKeeps);
            set('vendor-cash', Math.max(0, n('vendor_refund') - vendorDue));
            set('client-keeps', clientPays);
            set('pay-back-max', Math.max(0, paid - clientPays));
            const profit = clientPays - vendorKeeps, el = document.getElementById('refund-profit');
            el.textContent = profit.toFixed(2);
            el.className = 'adjust-result ' + (profit < 0 ? 'text-danger' : 'text-success');
        }
        if (document.getElementById('extra_price')) {
            const profit = n('extra_price') - n('extra_cost'), el = document.getElementById('reissue-profit');
            el.textContent = profit.toFixed(2);
            el.className = 'adjust-result ' + (profit < 0 ? 'text-danger' : 'text-success');
        }
    }
    document.querySelectorAll('#adjust-form input').forEach(input => input.addEventListener('input', refresh));
    // A full refund or void starts from the whole amounts; the user enters the charges by lowering them.
    const scopeFull = document.getElementById('scope-full');
    if (document.getElementById('vendor_refund') && (scopeFull ? scopeFull.checked : true) && n('vendor_refund') === 0 && n('client_refund') === 0) {
        <?php if ((int)$booking['purchase_id'] > 0) { ?>document.getElementById('vendor_refund').value = netCost.toFixed(2);<?php } ?>
        document.getElementById('client_refund').value = netPrice.toFixed(2);
    }
    document.getElementById('adjust-form').addEventListener('submit', function (event) {
        if (!confirm(<?= json_encode('Save ' . $labels[$kind] . ' for ' . $booking['booking_no'] . '? This changes the client and vendor accounts.'); ?>)) event.preventDefault();
    });
    refresh();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
