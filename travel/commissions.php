<?php
require_once __DIR__ . '/_init.php';

$agent_id = (int)($_POST['agent_id'] ?? ($_GET['agent_id'] ?? 0));
$error = '';
$message = $_SESSION['travel_commission_message'] ?? '';
unset($_SESSION['travel_commission_message']);
$cash_wallet_id = ensure_default_cash_wallet($conn, $user_id);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    travel_verify_csrf();
    try {
        $payment_no = travel_commission_pay($conn, $user_id, $branch_id, $agent_id, (float)($_POST['amount'] ?? 0), (string)($_POST['method'] ?? 'cash'), (int)($_POST['wallet_id'] ?? 0), (string)($_POST['payment_date'] ?? ''), (string)($_POST['note'] ?? ''), (string)($_POST['request_key'] ?? ''));
        $_SESSION['travel_commission_message'] = 'Commission ' . $payment_no . ' saved.';
        header('Location: commissions.php?agent_id=' . $agent_id);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$agents = travel_commission_agents($conn, $user_id);
$agent = $agent_id > 0 ? travel_client($conn, $user_id, $agent_id) : null;
$ledger = $agent ? travel_commission_ledger($conn, $user_id, $agent_id) : [];
$payable = $agent ? travel_commission_payable($conn, $user_id, $agent_id) : 0;
$client_due = $agent ? travel_client_balance($conn, $user_id, $agent_id) : 0;
$date = static fn($value) => $value ? date('d-m-Y', strtotime($value)) : '—';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<style>@media print{body *{visibility:hidden}.commission-print,.commission-print *{visibility:visible}.commission-print{position:absolute;left:0;top:0;width:100%}.no-print{display:none!important}}</style>
<section class="content pt-3"><div class="container-fluid">
    <?php if ($message !== '') { ?><div class="alert alert-success no-print"><?= htmlspecialchars($message); ?></div><?php } ?>
    <div class="row">
        <div class="col-lg-5 no-print">
            <div class="card card-outline card-warning">
                <div class="card-header"><h3 class="card-title"><i class="fas fa-handshake mr-2"></i>Agent Commission</h3></div>
                <div class="card-body p-0"><table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Agent</th><th class="text-center">Bookings</th><th class="text-right">Earned</th><th class="text-right">Paid</th><th class="text-right">Payable</th></tr></thead>
                    <tbody>
                    <?php foreach ($agents as $row) { ?>
                        <tr class="<?= $row['agent_id'] === $agent_id ? 'table-warning' : ''; ?>" style="cursor:pointer" onclick="location.href='?agent_id=<?= (int)$row['agent_id']; ?>'">
                            <td><?= htmlspecialchars($row['client']['customer_name'] ?? '(Deleted)'); ?><?= !empty($row['client']['is_agent']) ? ' <span class="badge badge-warning">Agent</span>' : ''; ?></td>
                            <td class="text-center"><?= (int)$row['bookings']; ?></td>
                            <td class="text-right"><?= travel_money($row['earned']); ?></td>
                            <td class="text-right"><?= travel_money($row['paid']); ?></td>
                            <td class="text-right <?= $row['payable'] > 0 ? 'text-danger font-weight-bold' : ''; ?>"><?= travel_money($row['payable']); ?></td>
                        </tr>
                    <?php } ?>
                    <?php if (!$agents) { ?><tr><td colspan="5" class="text-center text-muted py-4">No commission yet. Choose a Commission Agent when you save a booking.</td></tr><?php } else { ?>
                        <tr class="font-weight-bold"><td colspan="4" class="text-right">Total payable</td><td class="text-right"><?= travel_money(array_sum(array_map(static fn($row) => max(0, $row['payable']), $agents))); ?></td></tr><?php } ?>
                    </tbody>
                </table></div>
            </div>

            <?php if ($agent) { ?>
            <div class="card card-outline card-success">
                <div class="card-header"><h3 class="card-title">Pay Commission · <?= htmlspecialchars($agent['customer_name']); ?></h3></div>
                <div class="card-body">
                    <?php if ($error !== '') { ?><div class="alert alert-danger"><?= htmlspecialchars($error); ?></div><?php } ?>
                    <div class="mb-2">Payable: <strong class="<?= $payable > 0 ? 'text-danger' : ''; ?>">BDT <?= travel_money($payable); ?></strong>
                        <?php if ($client_due > 0) { ?><span class="text-muted"> · Agent owes us BDT <?= travel_money($client_due); ?></span><?php } ?></div>
                    <?php if ($payable > 0) { ?>
                    <form method="post" id="commission-form">
                        <?= travel_csrf_field(); ?>
                        <input type="hidden" name="agent_id" value="<?= (int)$agent_id; ?>">
                        <input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(24)); ?>">
                        <div class="form-group"><label>How</label><div>
                            <div class="custom-control custom-radio custom-control-inline"><input type="radio" id="method-cash" name="method" value="cash" class="custom-control-input" checked><label class="custom-control-label" for="method-cash">Pay from wallet</label></div>
                            <div class="custom-control custom-radio custom-control-inline"><input type="radio" id="method-adjust" name="method" value="adjust" class="custom-control-input"<?= $client_due > 0 ? '' : ' disabled'; ?>><label class="custom-control-label" for="method-adjust">Adjust with agent's due<?= $client_due > 0 ? '' : ' (no due)'; ?></label></div>
                        </div></div>
                        <div class="form-row">
                            <div class="form-group col-6"><label for="amount">Amount</label><input id="amount" type="number" step="0.01" min="0.01" max="<?= number_format($payable, 2, '.', ''); ?>" name="amount" class="form-control" value="<?= number_format($payable, 2, '.', ''); ?>" required></div>
                            <div class="form-group col-6"><label for="payment_date">Date</label><input id="payment_date" type="date" name="payment_date" class="form-control" max="<?= date('Y-m-d'); ?>" value="<?= date('Y-m-d'); ?>" required></div>
                        </div>
                        <div class="form-group" id="wallet-group"><label for="wallet_id">From Wallet</label><select id="wallet_id" name="wallet_id" class="form-control"><?= travel_wallet_options($conn, $user_id, $cash_wallet_id); ?></select></div>
                        <div class="form-group"><label for="note">Note</label><input id="note" name="note" class="form-control" maxlength="500" placeholder="Optional"></div>
                        <button class="btn btn-success"><i class="fas fa-check mr-1"></i>Save</button>
                    </form>
                    <?php } ?>
                </div>
            </div>
            <?php } ?>
        </div>

        <div class="col-lg-7">
            <?php if ($agent) { ?>
            <div class="card commission-print"><div class="card-body">
                <div class="d-flex justify-content-between mb-3">
                    <div><h5 class="mb-0">Commission Ledger</h5><div><strong><?= htmlspecialchars($agent['customer_name']); ?></strong> <span class="text-muted small"><?= htmlspecialchars($agent['phone']); ?></span></div></div>
                    <button type="button" class="btn btn-sm btn-outline-primary no-print align-self-start" onclick="window.print()"><i class="fas fa-print mr-1"></i>Print</button>
                </div>
                <table class="table table-bordered table-sm">
                    <thead class="thead-light"><tr><th>Date</th><th>Type</th><th>Reference</th><th>Details</th><th class="text-right">Earned</th><th class="text-right">Paid</th><th class="text-right">Payable</th></tr></thead>
                    <tbody>
                    <?php foreach ($ledger as $row) { ?>
                        <tr>
                            <td class="text-nowrap"><?= $date($row['date']); ?></td>
                            <td><?= htmlspecialchars($row['type']); ?></td>
                            <td class="text-nowrap"><?php if ($row['booking_id'] > 0) { ?><a href="booking_view.php?id=<?= (int)$row['booking_id']; ?>"><?= htmlspecialchars($row['reference']); ?></a><?php } else { echo htmlspecialchars($row['reference']); } ?></td>
                            <td class="small"><?= htmlspecialchars($row['detail']); ?></td>
                            <td class="text-right <?= $row['earned'] < 0 ? 'text-danger' : ''; ?>"><?= $row['earned'] != 0 ? travel_money($row['earned']) : ''; ?></td>
                            <td class="text-right"><?= $row['paid'] > 0 ? travel_money($row['paid']) : ''; ?></td>
                            <td class="text-right"><?= travel_money($row['balance']); ?></td>
                        </tr>
                    <?php } ?>
                    <?php if (!$ledger) { ?><tr><td colspan="7" class="text-center text-muted">No commission entry.</td></tr><?php } ?>
                    </tbody>
                </table>
                <div class="text-right h5 <?= $payable > 0 ? 'text-danger' : 'text-success'; ?>">Payable: BDT <?= travel_money($payable); ?></div>
            </div></div>
            <?php } else { ?>
                <div class="card"><div class="card-body text-muted">Select an agent on the left to see the commission ledger and pay commission.</div></div>
            <?php } ?>
        </div>
    </div>
</div></section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('commission-form');
    if (!form) return;
    const toggle = () => { document.getElementById('wallet-group').hidden = document.getElementById('method-adjust').checked; };
    form.querySelectorAll('input[name="method"]').forEach(radio => radio.addEventListener('change', toggle));
    form.addEventListener('submit', function (event) {
        const how = document.getElementById('method-adjust').checked ? 'adjust it with the agent\'s due' : 'pay it from the wallet';
        if (!confirm('Commission BDT ' + Number(document.getElementById('amount').value || 0).toFixed(2) + ': ' + how + '?')) event.preventDefault();
    });
    toggle();
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
