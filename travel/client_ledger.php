<?php
require_once __DIR__ . '/_init.php';

$client_id = (int)($_GET['client_id'] ?? 0);
$from = travel_valid_date($_GET['from'] ?? '');
$to = travel_valid_date($_GET['to'] ?? '');
$clients = travel_clients($conn, $user_id);
$client = $client_id > 0 ? travel_client($conn, $user_id, $client_id) : null;
$ledger = $client ? travel_client_ledger($conn, $user_id, $client_id, $from, $to) : null;
$company = mysqli_fetch_assoc(mysqli_query($conn, "SELECT name, phone, address FROM users WHERE id={$user_id}"));

// All clients with a balance, for the overview when no client is chosen.
$overview = [];
if (!$client) {
    $result = mysqli_query($conn, "SELECT c.id, c.customer_name, c.customer_code, c.phone,
            COALESCE((SELECT SUM(" . travel_effective_price_sql() . ") FROM travel_bookings b WHERE b.user_id=c.user_id AND b.client_id=c.id), 0) AS sold,
            COALESCE((SELECT SUM(p.amount) FROM travel_client_payments p WHERE p.user_id=c.user_id AND p.client_id=c.id), 0) AS paid
        FROM customers c WHERE c.user_id={$user_id} HAVING sold > 0 OR paid > 0 ORDER BY (sold - paid) DESC");
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $overview[] = $row;
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<style>@media print{body *{visibility:hidden}.ledger-print,.ledger-print *{visibility:visible}.ledger-print{position:absolute;left:0;top:0;width:100%}.no-print{display:none!important}}</style>
<section class="content pt-3"><div class="container-fluid">
    <?php if (!empty($_GET['paid'])) { ?><div class="alert alert-success no-print">Payment <?= htmlspecialchars((string)$_GET['paid']); ?> received.</div><?php } ?>
    <div class="card card-outline card-secondary no-print">
        <div class="card-body pb-1">
            <form method="get" class="form-row align-items-end">
                <div class="form-group col-md-4"><label for="client_id">Client</label>
                    <select id="client_id" name="client_id" class="form-control"><option value="">All clients (balances)</option>
                        <?php foreach ($clients as $row) { ?><option value="<?= (int)$row['id']; ?>"<?= (int)$row['id'] === $client_id ? ' selected' : ''; ?>><?= htmlspecialchars(travel_client_label($row) . ($row['is_agent'] ? ' — Agent (B2B)' : '')); ?></option><?php } ?>
                    </select></div>
                <div class="form-group col-md-2"><label>From</label><input type="date" name="from" class="form-control" value="<?= $from; ?>"></div>
                <div class="form-group col-md-2"><label>To</label><input type="date" name="to" class="form-control" value="<?= $to; ?>"></div>
                <div class="form-group col-md-4">
                    <button class="btn btn-primary"><i class="fas fa-search mr-1"></i>Show</button>
                    <?php if ($client) { ?><button type="button" class="btn btn-outline-primary" onclick="window.print()"><i class="fas fa-print mr-1"></i>Print</button>
                    <a class="btn btn-success" href="receive_payment.php?client_id=<?= (int)$client_id; ?>"><i class="fas fa-hand-holding-usd mr-1"></i>Receive</a><?php } ?>
                </div>
            </form>
        </div>
    </div>

    <?php if ($client) { ?>
    <div class="card ledger-print"><div class="card-body">
        <div class="d-flex justify-content-between flex-wrap mb-3">
            <div><h4 class="mb-0"><?= htmlspecialchars($company['name'] ?? ''); ?></h4><div class="small text-muted"><?= htmlspecialchars(trim(($company['address'] ?? '') . ' ' . ($company['phone'] ?? ''))); ?></div></div>
            <div class="text-right"><h5 class="mb-0">Client Ledger</h5>
                <div><strong><?= htmlspecialchars($client['customer_name']); ?></strong><?= $client['is_agent'] ? ' <span class="badge badge-warning">Agent</span>' : ''; ?></div>
                <div class="small text-muted"><?= htmlspecialchars(trim(($client['customer_code'] ? 'ID ' . $client['customer_code'] . ' · ' : '') . $client['phone'])); ?></div>
                <div class="small text-muted"><?= $from || $to ? htmlspecialchars(($from ?: 'Start') . ' to ' . ($to ?: 'Today')) : 'All dates'; ?></div></div>
        </div>
        <table class="table table-bordered table-sm">
            <thead class="thead-light"><tr><th>Date</th><th>Type</th><th>Reference</th><th>Details</th><th class="text-right">Debit (Sale)</th><th class="text-right">Credit (Paid)</th><th class="text-right">Balance</th></tr></thead>
            <tbody>
                <?php if ($from) { ?><tr class="font-italic"><td colspan="6">Opening balance</td><td class="text-right"><?= travel_money($ledger['opening']); ?></td></tr><?php } ?>
                <?php $debit = 0; $credit = 0; foreach ($ledger['rows'] as $row) { $debit += $row['debit']; $credit += $row['credit']; ?>
                    <tr>
                        <td class="text-nowrap"><?= htmlspecialchars(date('d-m-Y', strtotime($row['date']))); ?></td>
                        <td><?= htmlspecialchars($row['type']); ?></td>
                        <td class="text-nowrap"><?php if ($row['sort'] === 1) { ?><a href="booking_view.php?id=<?= (int)$row['id']; ?>"><?= htmlspecialchars($row['reference']); ?></a><?php } else { echo htmlspecialchars($row['reference']); } ?></td>
                        <td class="small"><?= htmlspecialchars($row['detail']); ?></td>
                        <td class="text-right"><?= $row['debit'] ? travel_money($row['debit']) : ''; ?></td>
                        <td class="text-right"><?= $row['credit'] ? travel_money($row['credit']) : ''; ?></td>
                        <td class="text-right"><?= travel_money($row['balance']); ?></td>
                    </tr>
                <?php } ?>
                <?php if (!$ledger['rows']) { ?><tr><td colspan="7" class="text-center text-muted">No entry in this period.</td></tr><?php } ?>
            </tbody>
            <tfoot class="font-weight-bold"><tr><td colspan="4" class="text-right">Total</td><td class="text-right"><?= travel_money($debit); ?></td><td class="text-right"><?= travel_money($credit); ?></td><td class="text-right"><?= travel_money($ledger['rows'] ? end($ledger['rows'])['balance'] : $ledger['opening']); ?></td></tr></tfoot>
        </table>
        <div class="text-right h5 <?= $ledger['closing'] > 0 ? 'text-danger' : 'text-success'; ?>"><?= $ledger['closing'] >= 0 ? 'Current due' : 'Advance'; ?>: BDT <?= travel_money(abs($ledger['closing'])); ?></div>
    </div></div>
    <?php } else { ?>
    <div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-users mr-2"></i>Client Balances</h3></div>
        <div class="card-body p-0"><table class="table table-striped table-sm mb-0">
            <thead><tr><th>Client</th><th>Phone</th><th class="text-right">Total Sale</th><th class="text-right">Paid</th><th class="text-right">Due / (Advance)</th><th></th></tr></thead>
            <tbody>
            <?php $total_due = 0; foreach ($overview as $row) { $due = (float)$row['sold'] - (float)$row['paid']; $total_due += $due; ?>
                <tr><td><?= htmlspecialchars($row['customer_name']); ?></td><td><?= htmlspecialchars($row['phone']); ?></td>
                    <td class="text-right"><?= travel_money($row['sold']); ?></td><td class="text-right"><?= travel_money($row['paid']); ?></td>
                    <td class="text-right <?= $due > 0 ? 'text-danger' : 'text-success'; ?>"><?= $due < 0 ? '(' . travel_money(-$due) . ')' : travel_money($due); ?></td>
                    <td class="text-nowrap"><a class="btn btn-xs btn-outline-secondary" href="?client_id=<?= (int)$row['id']; ?>">Ledger</a> <?php if ($due > 0) { ?><a class="btn btn-xs btn-outline-success" href="receive_payment.php?client_id=<?= (int)$row['id']; ?>">Receive</a><?php } ?></td></tr>
            <?php } ?>
            <?php if (!$overview) { ?><tr><td colspan="6" class="text-center text-muted py-4">No booking yet.</td></tr><?php } else { ?>
                <tr class="font-weight-bold"><td colspan="4" class="text-right">Total due</td><td class="text-right"><?= travel_money($total_due); ?></td><td></td></tr><?php } ?>
            </tbody>
        </table></div></div>
    <?php } ?>
</div></section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery && jQuery.fn.select2) jQuery('#client_id').select2({theme: 'bootstrap4', width: '100%'});
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
