<?php
require_once __DIR__ . '/_init.php';

$from = travel_valid_date($_GET['from'] ?? '') ?: date('Y-m-01');
$to = travel_valid_date($_GET['to'] ?? '') ?: date('Y-m-d');
$kind = array_key_exists($_GET['kind'] ?? '', travel_service_kinds()) ? $_GET['kind'] : '';
$client_id = (int)($_GET['client_id'] ?? 0);
$vendor_id = (int)($_GET['vendor_id'] ?? 0);
$due_only = !empty($_GET['due']);
$search = trim((string)($_GET['q'] ?? ''));

$where = ["b.user_id={$user_id}", "b.booking_date BETWEEN '{$from}' AND '{$to}'"];
if ($kind !== '') $where[] = "b.service_kind='{$kind}'";
if ($client_id > 0) $where[] = "b.client_id={$client_id}";
if ($vendor_id > 0) $where[] = "b.vendor_id={$vendor_id}";
if ($search !== '') {
    $like = "'%" . mysqli_real_escape_string($conn, $search) . "%'";
    $where[] = "(b.booking_no LIKE {$like} OR b.pnr LIKE {$like} OR b.route LIKE {$like} OR b.airline LIKE {$like} OR c.customer_name LIKE {$like} OR c.phone LIKE {$like}
        OR EXISTS (SELECT 1 FROM travel_booking_passengers tp WHERE tp.booking_id=b.id AND (tp.passenger_name LIKE {$like} OR tp.passport_no LIKE {$like} OR tp.ticket_no LIKE {$like})))";
}
$sql = "SELECT b.*, " . travel_effective_price_sql() . " AS net_price, " . travel_effective_cost_sql() . " AS net_cost, c.customer_name, c.customer_code, c.phone, s.supplier_name,
        COALESCE((SELECT SUM(p.amount) FROM travel_client_payments p WHERE p.booking_id=b.id), 0) AS paid,
        (SELECT GROUP_CONCAT(tp.passenger_name ORDER BY tp.id SEPARATOR ', ') FROM travel_booking_passengers tp WHERE tp.booking_id=b.id) AS passengers
    FROM travel_bookings b
    LEFT JOIN customers c ON c.id=b.client_id AND c.user_id=b.user_id
    LEFT JOIN suppliers s ON s.id=b.vendor_id AND s.user_id=b.user_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY b.booking_date DESC, b.id DESC LIMIT 1000";
$rows = [];
$totals = ['cost' => 0, 'price' => 0, 'paid' => 0, 'due' => 0];
$result = mysqli_query($conn, $sql);
while ($result && ($row = mysqli_fetch_assoc($result))) {
    // Refunds and reissues change what the booking is worth.
    $row['price'] = (float)$row['net_price'];
    $row['cost'] = (float)$row['net_cost'];
    $row['commission_now'] = (float)$row['commission'] - (float)$row['commission_credit'];
    $row['due'] = round($row['price'] - (float)$row['paid'], 2);
    if ($due_only && $row['due'] <= 0) continue;
    $rows[] = $row;
    foreach (['cost', 'price', 'paid'] as $key) $totals[$key] += (float)$row[$key];
    $totals['commission'] = ($totals['commission'] ?? 0) + $row['commission_now'];
    $totals['due'] += max(0, $row['due']);
}
$clients = travel_clients($conn, $user_id);
$vendors = travel_vendors($conn, $user_id);
$kinds = travel_service_kinds();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<section class="content pt-3"><div class="container-fluid">
    <div class="row">
        <?php foreach ([['Sales', $totals['price'], 'info', 'fa-file-invoice-dollar'], ['Purchase', $totals['cost'], 'secondary', 'fa-truck'], ['Profit', $totals['price'] - $totals['cost'] - ($totals['commission'] ?? 0), 'success', 'fa-chart-line'], ['Client Due', $totals['due'], 'danger', 'fa-exclamation-circle']] as [$label, $amount, $color, $icon]) { ?>
            <div class="col-md-3 col-sm-6"><div class="info-box"><span class="info-box-icon bg-<?= $color; ?>"><i class="fas <?= $icon; ?>"></i></span>
                <div class="info-box-content"><span class="info-box-text"><?= $label; ?></span><span class="info-box-number">BDT <?= travel_money($amount); ?></span></div></div></div>
        <?php } ?>
    </div>
    <div class="card card-outline card-info">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-list mr-2"></i>Booking List</h3>
            <div class="card-tools"><a href="booking_create.php" class="btn btn-sm btn-info"><i class="fas fa-plus mr-1"></i>New Booking</a></div></div>
        <div class="card-body">
            <form method="get" class="form-row align-items-end mb-3">
                <div class="form-group col-md-2"><label>From</label><input type="date" name="from" class="form-control form-control-sm" value="<?= $from; ?>"></div>
                <div class="form-group col-md-2"><label>To</label><input type="date" name="to" class="form-control form-control-sm" value="<?= $to; ?>"></div>
                <div class="form-group col-md-2"><label>Type</label><select name="kind" class="form-control form-control-sm"><option value="">All</option><?php foreach ($kinds as $key => $label) { ?><option value="<?= $key; ?>"<?= $key === $kind ? ' selected' : ''; ?>><?= htmlspecialchars($label); ?></option><?php } ?></select></div>
                <div class="form-group col-md-2"><label>Client</label><select name="client_id" class="form-control form-control-sm"><option value="">All</option><?php foreach ($clients as $client) { ?><option value="<?= (int)$client['id']; ?>"<?= (int)$client['id'] === $client_id ? ' selected' : ''; ?>><?= htmlspecialchars($client['customer_name']); ?></option><?php } ?></select></div>
                <div class="form-group col-md-2"><label>Vendor</label><select name="vendor_id" class="form-control form-control-sm"><option value="">All</option><?php foreach ($vendors as $vendor) { ?><option value="<?= (int)$vendor['id']; ?>"<?= (int)$vendor['id'] === $vendor_id ? ' selected' : ''; ?>><?= htmlspecialchars($vendor['supplier_name']); ?></option><?php } ?></select></div>
                <div class="form-group col-md-2"><label>Search</label><input name="q" class="form-control form-control-sm" placeholder="PNR, passenger, ticket…" value="<?= htmlspecialchars($search); ?>"></div>
                <div class="form-group col-md-12 mb-0 d-flex align-items-center" style="gap:.75rem">
                    <div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="due-only" name="due" value="1"<?= $due_only ? ' checked' : ''; ?>><label class="custom-control-label" for="due-only">Due only</label></div>
                    <button class="btn btn-sm btn-primary"><i class="fas fa-filter mr-1"></i>Filter</button>
                    <a href="bookings.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
            <div class="table-responsive"><table class="table table-bordered table-striped table-sm">
                <thead><tr><th>Booking</th><th>Date</th><th>Client</th><th>Service</th><th>Passengers</th><th>Vendor</th><th class="text-right">Purchase</th><th class="text-right">Selling</th><th class="text-right">Profit</th><th class="text-right">Paid</th><th class="text-right">Due</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row) { $profit = (float)$row['price'] - (float)$row['cost'] - $row['commission_now']; ?>
                    <tr>
                        <td class="text-nowrap"><a href="booking_view.php?id=<?= (int)$row['id']; ?>"><?= htmlspecialchars($row['booking_no']); ?></a><?php if ($row['status'] !== 'active') { ?><div><span class="badge badge-<?= $row['status'] === 'void' ? 'secondary' : 'danger'; ?>"><?= htmlspecialchars(ucfirst($row['status'])); ?></span></div><?php } elseif ((float)$row['extra_price'] > 0 || (float)$row['client_credit'] > 0) { ?><div><span class="badge badge-info"><?= (float)$row['client_credit'] > 0 ? 'Part refund' : 'Reissued'; ?></span></div><?php } ?></td>
                        <td class="text-nowrap"><?= htmlspecialchars(date('d-m-Y', strtotime($row['booking_date']))); ?></td>
                        <td><?= htmlspecialchars($row['customer_name'] ?? '—'); ?><div class="small text-muted"><?= htmlspecialchars($row['phone'] ?? ''); ?></div></td>
                        <td><?= htmlspecialchars($kinds[$row['service_kind']] ?? ''); ?>
                            <?php if ($row['service_kind'] === 'air_ticket') { ?><div class="small text-muted"><?= htmlspecialchars(trim($row['route'] . ($row['pnr'] !== '' ? ' · ' . $row['pnr'] : ''))); ?><?= $row['travel_date'] ? ' · ' . htmlspecialchars(date('d M', strtotime($row['travel_date']))) : ''; ?></div><?php } elseif ($row['details'] !== '') { ?><div class="small text-muted"><?= htmlspecialchars(mb_strimwidth($row['details'], 0, 60, '…')); ?></div><?php } ?></td>
                        <td class="small"><?= htmlspecialchars((string)$row['passengers']); ?></td>
                        <td class="small"><?= htmlspecialchars($row['supplier_name'] ?? '—'); ?></td>
                        <td class="text-right"><?= travel_money($row['cost']); ?></td>
                        <td class="text-right"><?= travel_money($row['price']); ?></td>
                        <td class="text-right <?= $profit < 0 ? 'text-danger' : 'text-success'; ?>"><?= travel_money($profit); ?></td>
                        <td class="text-right"><?= travel_money($row['paid']); ?></td>
                        <td class="text-right <?= $row['due'] > 0 ? 'text-danger font-weight-bold' : ''; ?>"><?= travel_money(max(0, $row['due'])); ?></td>
                        <td class="text-nowrap">
                            <a class="btn btn-xs btn-outline-primary" href="booking_view.php?id=<?= (int)$row['id']; ?>" title="View / Print"><i class="fas fa-print"></i></a>
                            <?php if ($row['due'] > 0 && $row['status'] === 'active') { ?><a class="btn btn-xs btn-outline-success" href="receive_payment.php?client_id=<?= (int)$row['client_id']; ?>&booking_id=<?= (int)$row['id']; ?>" title="Receive Payment"><i class="fas fa-hand-holding-usd"></i></a><?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                <?php if (!$rows) { ?><tr><td colspan="12" class="text-center text-muted py-4">No booking found for this filter.</td></tr><?php } ?>
                </tbody>
                <?php if ($rows) { ?><tfoot><tr class="font-weight-bold"><td colspan="6" class="text-right">Total</td><td class="text-right"><?= travel_money($totals['cost']); ?></td><td class="text-right"><?= travel_money($totals['price']); ?></td><td class="text-right"><?= travel_money($totals['price'] - $totals['cost'] - ($totals['commission'] ?? 0)); ?></td><td class="text-right"><?= travel_money($totals['paid']); ?></td><td class="text-right"><?= travel_money($totals['due']); ?></td><td></td></tr></tfoot><?php } ?>
            </table></div>
        </div>
    </div>
</div></section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
