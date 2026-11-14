<?php
// Client portal of a Travel Agency: the client's own bookings, dues, installments, visa status
// and ledger. Included twice by index.php: $travel_part = 'summary' or 'body'.
// Expects $travel (built below on first include), $conn, $user_id, $customer_id.

if (!isset($travel)) {
    $travel = ['bookings' => [], 'upcoming' => []];
    $result = mysqli_query($conn, "SELECT b.id FROM travel_bookings b WHERE b.user_id=" . (int)$user_id . " AND b.client_id=" . (int)$customer_id . " ORDER BY b.booking_date DESC, b.id DESC LIMIT 200");
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $booking = travel_booking_get($conn, $user_id, (int)$row['id']);
        $booking['schedule'] = $booking['status'] === 'active' ? travel_installment_schedule($conn, $booking) : [];
        $travel['bookings'][] = $booking;
        if ($booking['status'] === 'active' && $booking['travel_date'] && $booking['travel_date'] >= date('Y-m-d')) {
            $travel['upcoming'][] = $booking;
        }
    }
    usort($travel['upcoming'], static fn($a, $b) => strcmp($a['travel_date'], $b['travel_date']));
    $travel['balance'] = travel_client_balance($conn, $user_id, $customer_id);
    $travel['total'] = array_sum(array_map(static fn($b) => $b['net_price'], $travel['bookings']));
    $travel['paid'] = (float)mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) FROM travel_client_payments WHERE user_id=" . (int)$user_id . " AND client_id=" . (int)$customer_id))[0];
    $travel['ledger'] = travel_client_ledger($conn, $user_id, $customer_id);
    $travel['active'] = count(array_filter($travel['bookings'], static fn($b) => $b['status'] === 'active'));
}
$portal_kinds = travel_service_kinds();
$portal_visa = travel_visa_statuses();
$portal_date = static fn($value) => $value ? date('d M Y', strtotime($value)) : '—';

if (($travel_part ?? '') === 'summary') { ?>
    <div class="portal-summary travel-summary">
        <style>@media (min-width: 768px){.travel-summary{grid-template-columns:repeat(4,minmax(0,1fr))!important}}</style>
        <div class="metric"><span>Bookings</span><strong><?= (int)$travel['active']; ?></strong></div>
        <div class="metric"><span>Total Amount</span><strong>BDT <?= number_format($travel['total'], 2); ?></strong></div>
        <div class="metric"><span>Total Paid</span><strong>BDT <?= number_format($travel['paid'], 2); ?></strong></div>
        <div class="metric metric-due"><span><?= $travel['balance'] < 0 ? 'Advance' : 'Total Due'; ?></span><strong>BDT <?= number_format(abs($travel['balance']), 2); ?></strong></div>
    </div>
<?php return; } ?>

<?php if ($travel['upcoming']) { ?>
<div class="card portal-card">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-plane-departure mr-1"></i> Upcoming Trips</h3></div>
    <div class="card-body table-responsive p-0"><table class="table table-bordered mb-0">
        <thead><tr><th>Date</th><th>Trip</th><th>Passengers</th><th class="text-right">Due</th></tr></thead>
        <tbody>
        <?php foreach ($travel['upcoming'] as $booking) { ?>
            <tr>
                <td class="text-nowrap"><strong><?= $portal_date($booking['travel_date']); ?></strong></td>
                <td><?= htmlspecialchars($portal_kinds[$booking['service_kind']] ?? ''); ?><div class="text-muted small"><?= htmlspecialchars(trim($booking['airline'] . ' ' . $booking['route'] . ($booking['pnr'] !== '' ? ' · PNR ' . $booking['pnr'] : '') . ($booking['country'] !== '' ? ' ' . $booking['country'] : ''))); ?></div></td>
                <td class="small"><?= htmlspecialchars(implode(', ', array_column($booking['passengers'], 'passenger_name'))); ?></td>
                <td class="text-right <?= $booking['due'] > 0 ? 'ledger-debit font-weight-bold' : 'ledger-credit'; ?>"><?= $booking['due'] > 0 ? number_format($booking['due'], 2) : 'Paid'; ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table></div>
</div>
<?php } ?>

<div class="card portal-card">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-suitcase-rolling mr-1"></i> My Bookings</h3></div>
    <div class="card-body table-responsive p-0"><table class="table table-bordered table-striped mb-0 ledger-table">
        <thead><tr><th>Booking</th><th>Service</th><th>Travel</th><th class="text-right">Total</th><th class="text-right">Paid</th><th class="text-right">Due</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($travel['bookings'] as $booking) {
            $next = null;
            foreach ($booking['schedule'] as $step) { if ($step['due'] > 0) { $next = $step; break; } } ?>
            <tr>
                <td class="text-nowrap"><a href="travel_booking.php?id=<?= (int)$booking['id']; ?>"><?= htmlspecialchars($booking['booking_no']); ?></a><div class="text-muted small"><?= $portal_date($booking['booking_date']); ?></div></td>
                <td><?= htmlspecialchars($portal_kinds[$booking['service_kind']] ?? ''); ?><div class="text-muted small"><?= htmlspecialchars(trim($booking['route'] . ($booking['country'] !== '' ? ' ' . $booking['country'] : '') . ' · ' . count($booking['passengers']) . ' pax', ' ·')); ?></div></td>
                <td class="text-nowrap"><?= $portal_date($booking['travel_date']); ?></td>
                <td class="text-right"><?= number_format($booking['net_price'], 2); ?></td>
                <td class="text-right ledger-credit"><?= number_format((float)$booking['paid'], 2); ?></td>
                <td class="text-right font-weight-bold <?= $booking['due'] > 0 ? 'ledger-debit' : ''; ?>"><?= number_format(max(0, $booking['due']), 2); ?>
                    <?php if ($next) { ?><div class="small <?= $next['state'] === 'overdue' ? 'ledger-debit' : 'text-muted'; ?>">Next: <?= number_format($next['due'], 2); ?> by <?= $portal_date($next['due_date']); ?></div><?php } ?></td>
                <td>
                    <?php if ($booking['status'] !== 'active') { ?><span class="badge badge-secondary"><?= htmlspecialchars(ucfirst($booking['status'])); ?></span>
                    <?php } elseif ($booking['service_kind'] === 'visa') { $visa = $booking['process_status'] ?: 'received'; ?><span class="badge badge-<?= travel_visa_status_badge($visa); ?>"><?= htmlspecialchars($portal_visa[$visa] ?? $visa); ?></span>
                    <?php } else { ?><span class="badge badge-success">Confirmed</span><?php } ?>
                </td>
            </tr>
        <?php } ?>
        <?php if (!$travel['bookings']) { ?><tr><td colspan="7" class="text-center text-muted">No booking yet.</td></tr><?php } ?>
        </tbody>
    </table></div>
</div>

<div class="card portal-card">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-book mr-1"></i> Ledger</h3></div>
    <div class="card-body table-responsive p-0"><table class="table table-bordered mb-0 ledger-table">
        <thead><tr><th>Date</th><th>Type</th><th>Reference</th><th class="text-right">Amount</th><th class="text-right">Paid</th><th class="text-right">Balance</th></tr></thead>
        <tbody>
        <?php foreach ($travel['ledger']['rows'] as $row) { ?>
            <tr>
                <td class="text-nowrap"><?= htmlspecialchars(app_date($row['date'])); ?></td>
                <td><span class="ledger-type"><?= htmlspecialchars($row['type']); ?></span></td>
                <td><?= htmlspecialchars($row['reference']); ?></td>
                <td class="text-right"><?= $row['debit'] ? number_format($row['debit'], 2) : ''; ?></td>
                <td class="text-right ledger-credit"><?= $row['credit'] ? number_format($row['credit'], 2) : ''; ?></td>
                <td class="text-right font-weight-bold"><?= $row['balance'] < 0 ? '(' . number_format(-$row['balance'], 2) . ')' : number_format($row['balance'], 2); ?></td>
            </tr>
        <?php } ?>
        <?php if (!$travel['ledger']['rows']) { ?><tr><td colspan="6" class="text-center text-muted">No entry yet.</td></tr><?php } ?>
        </tbody>
    </table></div>
</div>
