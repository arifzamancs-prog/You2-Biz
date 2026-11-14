<?php
require_once __DIR__ . '/_init.php';

$error = '';
$client_id = (int)($_POST['client_id'] ?? ($_GET['client_id'] ?? 0));
$booking_id = (int)($_POST['booking_id'] ?? ($_GET['booking_id'] ?? 0));
$cash_wallet_id = ensure_default_cash_wallet($conn, $user_id);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    travel_verify_csrf();
    try {
        // A double submit of the same form is ignored.
        $form_key = (string)($_POST['form_key'] ?? '');
        if ($form_key !== '' && isset($_SESSION['travel_payment_keys'][$form_key])) {
            header('Location: client_ledger.php?client_id=' . $client_id);
            exit;
        }
        $payment_no = travel_receive_payment($conn, $user_id, $branch_id, $client_id, (float)($_POST['amount'] ?? 0), (int)($_POST['wallet_id'] ?? 0), (string)($_POST['payment_date'] ?? ''), (string)($_POST['note'] ?? ''), $booking_id);
        $_SESSION['travel_payment_keys'][$form_key] = $payment_no;
        if ($booking_id > 0) {
            header('Location: booking_view.php?id=' . $booking_id . '&paid=1');
        } else {
            header('Location: client_ledger.php?client_id=' . $client_id . '&paid=' . urlencode($payment_no));
        }
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$clients = travel_clients($conn, $user_id);
$client = $client_id > 0 ? travel_client($conn, $user_id, $client_id) : null;
$balance = $client ? travel_client_balance($conn, $user_id, $client_id) : 0;
$due_rows = [];
if ($client) {
    $dues = travel_booking_dues($conn, $user_id, $client_id);
    if ($dues) {
        $result = mysqli_query($conn, "SELECT b.id, b.booking_no, b.booking_date, b.service_kind, b.route, b.pnr, " . travel_effective_price_sql() . " AS price FROM travel_bookings b WHERE b.id IN (" . implode(',', array_keys($dues)) . ") ORDER BY b.booking_date, b.id");
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $row['due'] = $dues[(int)$row['id']];
            $due_rows[] = $row;
        }
    }
}
$suggested = $booking_id > 0 && $client ? (travel_booking_dues($conn, $user_id, $client_id)[$booking_id] ?? 0) : max(0, $balance);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<section class="content pt-3"><div class="container-fluid"><div class="row">
    <div class="col-lg-5">
        <div class="card card-outline card-success">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-hand-holding-usd mr-2"></i>Receive Payment</h3></div>
            <div class="card-body">
                <?php if ($error !== '') { ?><div class="alert alert-danger"><?= htmlspecialchars($error); ?></div><?php } ?>
                <form method="get" id="client-pick" class="mb-3">
                    <label for="client_pick">Client</label>
                    <select id="client_pick" name="client_id" class="form-control" onchange="this.form.submit()">
                        <option value="">Select client</option>
                        <?php foreach ($clients as $row) { ?><option value="<?= (int)$row['id']; ?>"<?= (int)$row['id'] === $client_id ? ' selected' : ''; ?>><?= htmlspecialchars(travel_client_label($row) . ($row['is_agent'] ? ' — Agent (B2B)' : '')); ?></option><?php } ?>
                    </select>
                </form>
                <?php if ($client) { ?>
                    <div class="alert <?= $balance > 0 ? 'alert-warning' : 'alert-success'; ?> py-2">
                        <?= $balance > 0 ? 'Total due' : ($balance < 0 ? 'Advance with us' : 'No due'); ?>: <strong>BDT <?= travel_money(abs($balance)); ?></strong>
                    </div>
                    <form method="post" id="receive-form">
                        <?= travel_csrf_field(); ?>
                        <input type="hidden" name="form_key" value="<?= bin2hex(random_bytes(16)); ?>">
                        <input type="hidden" name="client_id" value="<?= (int)$client_id; ?>">
                        <div class="form-group"><label for="booking_id">Apply to</label>
                            <select id="booking_id" name="booking_id" class="form-control">
                                <option value="0">Oldest due bookings first</option>
                                <?php foreach ($due_rows as $row) { ?><option value="<?= (int)$row['id']; ?>" data-due="<?= $row['due']; ?>"<?= (int)$row['id'] === $booking_id ? ' selected' : ''; ?>><?= htmlspecialchars($row['booking_no'] . ' · due ' . travel_money($row['due'])); ?></option><?php } ?>
                            </select>
                            <small class="form-text text-muted">More than the due is kept as the client's advance.</small></div>
                        <div class="form-row">
                            <div class="form-group col-6"><label for="amount">Amount</label><input id="amount" type="number" step="0.01" min="0.01" name="amount" class="form-control" value="<?= $suggested > 0 ? htmlspecialchars(number_format($suggested, 2, '.', '')) : ''; ?>" required></div>
                            <div class="form-group col-6"><label for="payment_date">Date</label><input id="payment_date" type="date" name="payment_date" class="form-control" max="<?= date('Y-m-d'); ?>" value="<?= date('Y-m-d'); ?>" required></div>
                        </div>
                        <div class="form-group"><label for="wallet_id">To Wallet</label><select id="wallet_id" name="wallet_id" class="form-control" required><?= travel_wallet_options($conn, $user_id, $cash_wallet_id); ?></select></div>
                        <div class="form-group"><label for="note">Note</label><input id="note" name="note" class="form-control" maxlength="500" placeholder="Optional (bKash TrxID, cheque no…)"></div>
                        <button class="btn btn-success"><i class="fas fa-check mr-1"></i>Receive</button>
                    </form>
                <?php } ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <?php if ($client) { ?>
        <div class="card">
            <div class="card-header"><h3 class="card-title">Due bookings of <?= htmlspecialchars($client['customer_name']); ?></h3>
                <div class="card-tools"><a href="client_ledger.php?client_id=<?= (int)$client_id; ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-book mr-1"></i>Client Ledger</a></div></div>
            <div class="card-body p-0"><table class="table table-sm table-striped mb-0">
                <thead><tr><th>Booking</th><th>Date</th><th>Service</th><th class="text-right">Total</th><th class="text-right">Due</th></tr></thead>
                <tbody>
                <?php foreach ($due_rows as $row) { ?>
                    <tr><td><a href="booking_view.php?id=<?= (int)$row['id']; ?>"><?= htmlspecialchars($row['booking_no']); ?></a></td><td><?= htmlspecialchars(date('d-m-Y', strtotime($row['booking_date']))); ?></td>
                        <td><?= htmlspecialchars((travel_service_kinds()[$row['service_kind']] ?? '') . ($row['route'] !== '' ? ' · ' . $row['route'] : '')); ?></td>
                        <td class="text-right"><?= travel_money($row['price']); ?></td><td class="text-right text-danger"><?= travel_money($row['due']); ?></td></tr>
                <?php } ?>
                <?php if (!$due_rows) { ?><tr><td colspan="5" class="text-center text-muted py-3">No due booking.</td></tr><?php } ?>
                </tbody>
            </table></div>
        </div>
        <?php } ?>
    </div>
</div></div></section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery && jQuery.fn.select2) jQuery('#client_pick').select2({theme: 'bootstrap4', width: '100%'}).on('select2:select', function () { this.form.submit(); });
    const booking = document.getElementById('booking_id');
    if (booking) booking.addEventListener('change', function () {
        const option = booking.selectedOptions[0];
        if (option && option.dataset.due) document.getElementById('amount').value = Number(option.dataset.due).toFixed(2);
    });
    const form = document.getElementById('receive-form');
    if (form) form.addEventListener('submit', function (event) {
        if (!confirm('Receive BDT ' + Number(document.getElementById('amount').value || 0).toFixed(2) + ' from this client?')) event.preventDefault();
    });
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
