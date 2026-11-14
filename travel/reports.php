<?php
require_once __DIR__ . '/_init.php';

$types = [
    'summary' => 'Service Summary',
    'airline' => 'Airline-wise',
    'vendor' => 'Vendor-wise',
    'client' => 'Client / Agent-wise',
    'upcoming' => 'Upcoming Travel',
    'passport' => 'Passport Expiry',
];
$type = array_key_exists($_GET['type'] ?? '', $types) ? $_GET['type'] : 'summary';
$from = travel_valid_date($_GET['from'] ?? '') ?: date('Y-m-01');
$to = travel_valid_date($_GET['to'] ?? '') ?: date('Y-m-d');
if ($to < $from) [$from, $to] = [$to, $from];
$agents_only = !empty($_GET['agents']);
$days = max(1, min(365, (int)($_GET['days'] ?? ($type === 'passport' ? 180 : 7))));
$kinds = travel_service_kinds();
$today = date('Y-m-d');

/**
 * Sales, cost and profit grouped by $dimension for bookings and their refunds/reissues
 * made between $from and $to (each on its own date, under the booking's airline/vendor/client).
 */
function travel_report_grouped($conn, $user_id, $from, $to, $dimension)
{
    $user_id = (int)$user_id;
    $key = [
        'summary' => 'b.service_kind',
        'airline' => "IF(TRIM(b.airline)='', '(No airline)', UPPER(TRIM(b.airline)))",
        'vendor' => 'b.vendor_id',
        'client' => 'b.client_id',
    ][$dimension];
    $only_air = $dimension === 'airline' ? " AND b.service_kind='air_ticket'" : '';
    $sql = "SELECT group_key, COUNT(DISTINCT IF(is_new=1, booking_id, NULL)) AS bookings, SUM(pax) AS pax,
            SUM(sales) AS sales, SUM(cost) AS cost, SUM(commission) AS commission, SUM(refunds) AS refunds
        FROM (
            SELECT {$key} AS group_key, b.id AS booking_id, 1 AS is_new, b.price AS sales, b.cost AS cost, b.commission AS commission, 0 AS refunds,
                (SELECT COUNT(*) FROM travel_booking_passengers p WHERE p.booking_id=b.id) AS pax
            FROM travel_bookings b WHERE b.user_id={$user_id} AND b.booking_date BETWEEN '{$from}' AND '{$to}'{$only_air}
            UNION ALL
            SELECT {$key}, b.id, 0, a.extra_price - a.client_credit, a.extra_cost - a.cost_credit, a.commission_change, a.client_credit, 0
            FROM travel_booking_adjustments a INNER JOIN travel_bookings b ON b.id=a.booking_id
            WHERE a.user_id={$user_id} AND a.adjust_date BETWEEN '{$from}' AND '{$to}'{$only_air}
        ) x GROUP BY group_key ORDER BY SUM(sales) DESC";
    $rows = [];
    $result = mysqli_query($conn, $sql);
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $row['profit'] = round((float)$row['sales'] - (float)$row['cost'] - (float)$row['commission'], 2);
        $rows[] = $row;
    }
    return $rows;
}

$rows = [];
$columns = [];
if (in_array($type, ['summary', 'airline', 'vendor', 'client'], true)) {
    $rows = travel_report_grouped($conn, $user_id, $from, $to, $type);
    if ($type === 'summary') {
        foreach ($rows as &$row) $row['label'] = $kinds[$row['group_key']] ?? $row['group_key'];
        unset($row);
    } elseif ($type === 'airline') {
        foreach ($rows as &$row) $row['label'] = $row['group_key'];
        unset($row);
    } elseif ($type === 'vendor') {
        foreach ($rows as &$row) {
            $vendor = (int)$row['group_key'] > 0 ? mysqli_fetch_assoc(mysqli_query($conn, 'SELECT supplier_name FROM suppliers WHERE id=' . (int)$row['group_key'] . " AND user_id={$user_id}")) : null;
            $row['label'] = $vendor['supplier_name'] ?? '(No vendor)';
            // What we still owe this vendor today, on all its bills.
            $row['current_due'] = (int)$row['group_key'] > 0 ? (float)mysqli_fetch_row(mysqli_query($conn, 'SELECT COALESCE(SUM(due_amount),0) FROM purchases WHERE supplier_id=' . (int)$row['group_key'] . " AND user_id={$user_id}"))[0] : 0;
        }
        unset($row);
    } else {
        $kept = [];
        foreach ($rows as $row) {
            $client = travel_client($conn, $user_id, (int)$row['group_key']);
            if ($agents_only && empty($client['is_agent'])) continue;
            $row['label'] = $client['customer_name'] ?? '(Deleted client)';
            $row['is_agent'] = !empty($client['is_agent']);
            $row['phone'] = $client['phone'] ?? '';
            $row['current_due'] = travel_client_balance($conn, $user_id, (int)$row['group_key']);
            $kept[] = $row;
        }
        $rows = $kept;
    }
} elseif ($type === 'upcoming') {
    $until = date('Y-m-d', strtotime("+{$days} days"));
    $result = mysqli_query($conn, "SELECT b.*, c.customer_name, c.phone,
            " . travel_effective_price_sql() . " - COALESCE((SELECT SUM(p.amount) FROM travel_client_payments p WHERE p.booking_id=b.id), 0) AS due,
            (SELECT GROUP_CONCAT(tp.passenger_name ORDER BY tp.id SEPARATOR ', ') FROM travel_booking_passengers tp WHERE tp.booking_id=b.id) AS passengers
        FROM travel_bookings b LEFT JOIN customers c ON c.id=b.client_id AND c.user_id=b.user_id
        WHERE b.user_id={$user_id} AND b.status='active' AND b.travel_date BETWEEN '{$today}' AND '{$until}'
        ORDER BY b.travel_date, b.id");
    while ($result && ($row = mysqli_fetch_assoc($result))) $rows[] = $row;
} else {
    // Client passports from the client form (Passport Expiry is stored as d/m/Y).
    $limit = date('Y-m-d', strtotime("+{$days} days"));
    $result = mysqli_query($conn, "SELECT id, customer_code, customer_name, phone, extra_data FROM customers WHERE user_id={$user_id} AND extra_data LIKE '%passport_expiry%'");
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $extra = json_decode((string)$row['extra_data'], true) ?: [];
        $date = DateTime::createFromFormat('!d/m/Y', trim((string)($extra['passport_expiry'] ?? ''))) ?: DateTime::createFromFormat('!Y-m-d', trim((string)($extra['passport_expiry'] ?? '')));
        if (!$date || $date->format('Y-m-d') > $limit) continue;
        $row['expiry'] = $date->format('Y-m-d');
        $row['passport_no'] = (string)($extra['passport_no'] ?? '');
        $row['days_left'] = (int)floor((strtotime($row['expiry']) - strtotime($today)) / 86400);
        $rows[] = $row;
    }
    usort($rows, static fn($a, $b) => strcmp($a['expiry'], $b['expiry']));
}
$total = static fn($key) => array_sum(array_map(static fn($row) => (float)($row[$key] ?? 0), $rows));
$company = mysqli_fetch_assoc(mysqli_query($conn, "SELECT name FROM users WHERE id={$user_id}"));
$period_based = in_array($type, ['summary', 'airline', 'vendor', 'client'], true);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<style>@media print{body *{visibility:hidden}.report-print,.report-print *{visibility:visible}.report-print{position:absolute;left:0;top:0;width:100%}.no-print{display:none!important}}</style>
<section class="content pt-3"><div class="container-fluid">
    <div class="card card-outline card-info no-print"><div class="card-body pb-1">
        <ul class="nav nav-pills mb-3">
            <?php foreach ($types as $key => $label) { ?><li class="nav-item"><a class="nav-link py-1<?= $key === $type ? ' active' : ''; ?>" href="?<?= htmlspecialchars(http_build_query(['type' => $key, 'from' => $from, 'to' => $to])); ?>"><?= htmlspecialchars($label); ?></a></li><?php } ?>
        </ul>
        <form method="get" class="form-row align-items-end">
            <input type="hidden" name="type" value="<?= $type; ?>">
            <?php if ($period_based) { ?>
                <div class="form-group col-md-2"><label>From</label><input type="date" name="from" class="form-control form-control-sm" value="<?= $from; ?>"></div>
                <div class="form-group col-md-2"><label>To</label><input type="date" name="to" class="form-control form-control-sm" value="<?= $to; ?>"></div>
                <div class="form-group col-md-4 d-flex" style="gap:.3rem">
                    <?php foreach (['This month' => [date('Y-m-01'), date('Y-m-d')], 'Last month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))], 'This year' => [date('Y-01-01'), date('Y-m-d')]] as $label => [$a, $b]) { ?>
                        <a class="btn btn-sm btn-outline-secondary" href="?<?= htmlspecialchars(http_build_query(['type' => $type, 'from' => $a, 'to' => $b, 'agents' => $agents_only ? 1 : null])); ?>"><?= $label; ?></a>
                    <?php } ?>
                </div>
                <?php if ($type === 'client') { ?><div class="form-group col-md-2"><div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="agents" name="agents" value="1"<?= $agents_only ? ' checked' : ''; ?>><label class="custom-control-label" for="agents">B2B agents only</label></div></div><?php } ?>
            <?php } else { ?>
                <div class="form-group col-md-3"><label><?= $type === 'upcoming' ? 'Travelling in the next (days)' : 'Expiring within (days)'; ?></label><input type="number" min="1" max="365" name="days" class="form-control form-control-sm" value="<?= $days; ?>"></div>
            <?php } ?>
            <div class="form-group col-md-2"><button class="btn btn-sm btn-primary"><i class="fas fa-filter mr-1"></i>Show</button> <button type="button" class="btn btn-sm btn-outline-primary" onclick="window.print()"><i class="fas fa-print mr-1"></i>Print</button></div>
        </form>
    </div></div>

    <div class="card report-print"><div class="card-body">
        <div class="d-flex justify-content-between flex-wrap mb-3">
            <div><h4 class="mb-0"><?= htmlspecialchars($types[$type]); ?> Report</h4><div class="text-muted small"><?= htmlspecialchars($company['name'] ?? ''); ?></div></div>
            <div class="text-right small text-muted"><?= $period_based ? htmlspecialchars(date('d M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to))) : ($type === 'upcoming' ? 'Next ' . $days . ' days' : 'Expiring within ' . $days . ' days'); ?><br>Generated <?= date('d M Y, h:i A'); ?></div>
        </div>

        <?php if ($period_based) { ?>
        <table class="table table-bordered table-sm">
            <thead class="thead-light"><tr>
                <th><?= ['summary' => 'Service', 'airline' => 'Airline', 'vendor' => 'Vendor', 'client' => 'Client'][$type]; ?></th>
                <th class="text-center">Bookings</th><th class="text-center">Pax</th>
                <th class="text-right">Sales</th><th class="text-right">Purchase</th><th class="text-right">Commission</th><th class="text-right">Profit</th><th class="text-right">Margin</th>
                <?php if ($type === 'vendor') { ?><th class="text-right">Vendor Due (now)</th><?php } ?>
                <?php if ($type === 'client') { ?><th class="text-right">Client Due (now)</th><?php } ?>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $row) { $margin = (float)$row['sales'] > 0 ? $row['profit'] / (float)$row['sales'] * 100 : 0; ?>
                <tr>
                    <td><?= htmlspecialchars($row['label']); ?><?= !empty($row['is_agent']) ? ' <span class="badge badge-warning">Agent</span>' : ''; ?><?= !empty($row['phone']) ? '<div class="small text-muted">' . htmlspecialchars($row['phone']) . '</div>' : ''; ?></td>
                    <td class="text-center"><?= (int)$row['bookings']; ?></td><td class="text-center"><?= (int)$row['pax']; ?></td>
                    <td class="text-right"><?= travel_money($row['sales']); ?><?= (float)$row['refunds'] > 0 ? '<div class="small text-muted">incl. refund −' . travel_money($row['refunds']) . '</div>' : ''; ?></td>
                    <td class="text-right"><?= travel_money($row['cost']); ?></td>
                    <td class="text-right"><?= travel_money($row['commission']); ?></td>
                    <td class="text-right <?= $row['profit'] < 0 ? 'text-danger' : 'text-success'; ?>"><?= travel_money($row['profit']); ?></td>
                    <td class="text-right"><?= number_format($margin, 1); ?>%</td>
                    <?php if ($type === 'vendor' || $type === 'client') { $due = (float)$row['current_due']; ?><td class="text-right <?= $due > 0 ? 'text-danger' : ''; ?>"><?= $due < 0 ? '(' . travel_money(-$due) . ' adv.)' : travel_money($due); ?></td><?php } ?>
                </tr>
            <?php } ?>
            <?php if (!$rows) { ?><tr><td colspan="10" class="text-center text-muted py-4">No booking in this period.</td></tr><?php } ?>
            </tbody>
            <?php if ($rows) { $profit = $total('sales') - $total('cost') - $total('commission'); ?>
            <tfoot class="font-weight-bold"><tr><td>Total</td><td class="text-center"><?= (int)$total('bookings'); ?></td><td class="text-center"><?= (int)$total('pax'); ?></td>
                <td class="text-right"><?= travel_money($total('sales')); ?></td><td class="text-right"><?= travel_money($total('cost')); ?></td><td class="text-right"><?= travel_money($total('commission')); ?></td>
                <td class="text-right"><?= travel_money($profit); ?></td><td class="text-right"><?= $total('sales') > 0 ? number_format($profit / $total('sales') * 100, 1) : '0.0'; ?>%</td>
                <?php if ($type === 'vendor' || $type === 'client') { ?><td class="text-right"><?= travel_money(array_sum(array_map(static fn($row) => max(0, (float)$row['current_due']), $rows))); ?></td><?php } ?></tr></tfoot>
            <?php } ?>
        </table>
        <p class="small text-muted mb-0">Bookings count on their booking date; refunds and reissues count on the date they were made, under the same <?= $type === 'summary' ? 'service' : $type; ?>.</p>

        <?php } elseif ($type === 'upcoming') { ?>
        <table class="table table-bordered table-sm">
            <thead class="thead-light"><tr><th>Travel Date</th><th>Booking</th><th>Client</th><th>Passengers</th><th>Service</th><th class="text-right">Due</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row) { $due = round((float)$row['due'], 2); ?>
                <tr class="<?= $due > 0 ? 'table-warning' : ''; ?>">
                    <td class="text-nowrap"><strong><?= htmlspecialchars(date('d M Y', strtotime($row['travel_date']))); ?></strong><div class="small text-muted"><?= $row['travel_date'] === $today ? 'Today' : 'in ' . (int)floor((strtotime($row['travel_date']) - strtotime($today)) / 86400) . ' day(s)'; ?></div></td>
                    <td><a href="booking_view.php?id=<?= (int)$row['id']; ?>"><?= htmlspecialchars($row['booking_no']); ?></a></td>
                    <td><?= htmlspecialchars($row['customer_name'] ?? ''); ?><div class="small text-muted"><?= htmlspecialchars($row['phone'] ?? ''); ?></div></td>
                    <td class="small"><?= htmlspecialchars((string)$row['passengers']); ?></td>
                    <td><?= htmlspecialchars($kinds[$row['service_kind']] ?? ''); ?><div class="small text-muted"><?= htmlspecialchars(trim($row['airline'] . ' ' . $row['route'] . ($row['pnr'] !== '' ? ' · PNR ' . $row['pnr'] : '') . ($row['country'] !== '' ? ' ' . $row['country'] : ''))); ?></div></td>
                    <td class="text-right <?= $due > 0 ? 'text-danger font-weight-bold' : 'text-success'; ?>"><?= $due > 0 ? travel_money($due) : 'Paid'; ?></td>
                </tr>
            <?php } ?>
            <?php if (!$rows) { ?><tr><td colspan="6" class="text-center text-muted py-4">No travel in the next <?= $days; ?> days.</td></tr><?php } ?>
            </tbody>
        </table>
        <p class="small text-muted mb-0">Rows in yellow still have a due — collect it before the client travels.</p>

        <?php } else { ?>
        <table class="table table-bordered table-sm">
            <thead class="thead-light"><tr><th>Client</th><th>Phone</th><th>Passport No</th><th>Expiry</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row) { ?>
                <tr class="<?= $row['days_left'] < 0 ? 'table-danger' : ($row['days_left'] <= 90 ? 'table-warning' : ''); ?>">
                    <td><?= htmlspecialchars($row['customer_name']); ?><?= $row['customer_code'] ? ' <small class="text-muted">[' . htmlspecialchars($row['customer_code']) . ']</small>' : ''; ?></td>
                    <td><?= htmlspecialchars($row['phone']); ?></td>
                    <td><?= htmlspecialchars($row['passport_no']); ?></td>
                    <td class="text-nowrap"><?= htmlspecialchars(date('d M Y', strtotime($row['expiry']))); ?></td>
                    <td><?= $row['days_left'] < 0 ? '<span class="badge badge-danger">Expired</span>' : '<span class="badge badge-' . ($row['days_left'] <= 90 ? 'warning' : 'info') . '">' . (int)$row['days_left'] . ' days left</span>'; ?></td>
                </tr>
            <?php } ?>
            <?php if (!$rows) { ?><tr><td colspan="5" class="text-center text-muted py-4">No client passport expires within <?= $days; ?> days.</td></tr><?php } ?>
            </tbody>
        </table>
        <p class="small text-muted mb-0">From the Passport Expiry field of the client form. Most countries need 6 months of validity for a visa.</p>
        <?php } ?>
    </div></div>
</div></section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
