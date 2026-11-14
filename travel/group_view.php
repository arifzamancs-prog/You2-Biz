<?php
require_once __DIR__ . '/_init.php';

$group = travel_group_get($conn, $user_id, (int)($_GET['id'] ?? 0));
if (!$group) {
    header('Location: groups.php');
    exit;
}
$company = mysqli_fetch_assoc(mysqli_query($conn, "SELECT name, phone FROM users WHERE id={$user_id}"));
$bookings = [];
$result = mysqli_query($conn, "SELECT b.id FROM travel_bookings b WHERE b.user_id={$user_id} AND b.group_id=" . (int)$group['id'] . " ORDER BY b.booking_date, b.id");
while ($result && ($row = mysqli_fetch_assoc($result))) {
    $booking = travel_booking_get($conn, $user_id, (int)$row['id']);
    $booking['schedule'] = $booking['status'] === 'active' ? travel_installment_schedule($conn, $booking) : [];
    $bookings[] = $booking;
}
$totals = ['sold' => 0, 'paid' => 0, 'due' => 0, 'cost' => 0];
$overdue = 0;
foreach ($bookings as $booking) {
    $totals['sold'] += $booking['net_price'];
    $totals['cost'] += $booking['net_cost'];
    $totals['paid'] += (float)$booking['paid'];
    $totals['due'] += max(0, $booking['due']);
    foreach ($booking['schedule'] as $step) {
        if ($step['state'] === 'overdue') $overdue += $step['due'];
    }
}
$date = static fn($value) => $value ? date('d M Y', strtotime($value)) : '—';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<style>@media print{body *{visibility:hidden}.manifest-print,.manifest-print *{visibility:visible}.manifest-print{position:absolute;left:0;top:0;width:100%}.no-print{display:none!important}}</style>
<section class="content pt-3"><div class="container-fluid">
    <?php if (!empty($_GET['saved'])) { ?><div class="alert alert-success no-print">Group saved.</div><?php } ?>
    <div class="d-flex flex-wrap mb-3 no-print" style="gap:.5rem">
        <?php if ($group['status'] === 'open' && ((int)$group['seats'] === 0 || $group['seats_left'] > 0)) { ?><a class="btn btn-success btn-sm" href="booking_create.php?kind=<?= htmlspecialchars($group['service_kind']); ?>&group_id=<?= (int)$group['id']; ?>"><i class="fas fa-user-plus mr-1"></i>Add Booking</a><?php } ?>
        <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="fas fa-print mr-1"></i>Print Manifest</button>
        <a class="btn btn-outline-secondary btn-sm" href="groups.php?edit=<?= (int)$group['id']; ?>"><i class="fas fa-edit mr-1"></i>Edit Group</a>
        <a class="btn btn-outline-secondary btn-sm" href="groups.php"><i class="fas fa-list mr-1"></i>All Groups</a>
    </div>
    <div class="row no-print">
        <?php foreach ([['Seats', (int)$group['seats_used'] . ' / ' . ((int)$group['seats'] ?: '∞'), 'info', 'fa-chair'], ['Sale', 'BDT ' . travel_money($totals['sold']), 'primary', 'fa-file-invoice-dollar'], ['Collected', 'BDT ' . travel_money($totals['paid']), 'success', 'fa-hand-holding-usd'], ['Due (overdue)', 'BDT ' . travel_money($totals['due']) . ($overdue > 0 ? ' (' . travel_money($overdue) . ')' : ''), 'danger', 'fa-exclamation-circle']] as [$label, $value, $color, $icon]) { ?>
            <div class="col-md-3 col-sm-6"><div class="info-box"><span class="info-box-icon bg-<?= $color; ?>"><i class="fas <?= $icon; ?>"></i></span><div class="info-box-content"><span class="info-box-text"><?= $label; ?></span><span class="info-box-number"><?= $value; ?></span></div></div></div>
        <?php } ?>
    </div>

    <div class="card manifest-print"><div class="card-body">
        <div class="d-flex justify-content-between flex-wrap mb-2">
            <div><h4 class="mb-0"><?= htmlspecialchars($group['group_name']); ?></h4>
                <div class="text-muted small"><?= htmlspecialchars($group['group_code']); ?><?= $group['package_name'] ? ' · ' . htmlspecialchars($group['package_name']) : ''; ?> · <?= htmlspecialchars($company['name'] ?? ''); ?></div></div>
            <div class="text-right small">
                <div>Departure: <strong><?= $date($group['departure_date']); ?></strong> · Return: <strong><?= $date($group['return_date']); ?></strong></div>
                <?php if ($group['airline'] !== '') { ?><div>Airline: <?= htmlspecialchars($group['airline']); ?></div><?php } ?>
                <?php if ($group['hotel_info'] !== '') { ?><div>Hotels: <?= htmlspecialchars($group['hotel_info']); ?></div><?php } ?>
            </div>
        </div>
        <h6 class="font-weight-bold mt-3">Passenger Manifest</h6>
        <table class="table table-bordered table-sm">
            <thead class="thead-light"><tr><th style="width:40px">#</th><th>Passenger</th><th>Passport No</th><th>Client</th><th>Phone</th><th>Booking</th></tr></thead>
            <tbody>
            <?php $n = 0; foreach ($bookings as $booking) { if ($booking['status'] !== 'active') continue; foreach ($booking['passengers'] as $passenger) { $n++; ?>
                <tr><td><?= $n; ?></td><td><?= htmlspecialchars($passenger['passenger_name']); ?></td><td><?= htmlspecialchars($passenger['passport_no']); ?></td><td><?= htmlspecialchars($booking['client']['customer_name'] ?? ''); ?></td><td><?= htmlspecialchars($booking['client']['phone'] ?? ''); ?></td><td><?= htmlspecialchars($booking['booking_no']); ?></td></tr>
            <?php } } ?>
            <?php if ($n === 0) { ?><tr><td colspan="6" class="text-center text-muted">No passenger yet.</td></tr><?php } ?>
            </tbody>
        </table>
    </div></div>

    <div class="card no-print">
        <div class="card-header"><h3 class="card-title">Collection</h3></div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table table-striped table-sm mb-0">
            <thead><tr><th>Booking</th><th>Client</th><th class="text-center">Pax</th><th class="text-right">Total</th><th class="text-right">Paid</th><th class="text-right">Due</th><th>Next installment</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($bookings as $booking) {
                $next = null;
                foreach ($booking['schedule'] as $step) { if ($step['due'] > 0) { $next = $step; break; } } ?>
                <tr>
                    <td><a href="booking_view.php?id=<?= (int)$booking['id']; ?>"><?= htmlspecialchars($booking['booking_no']); ?></a><?= $booking['status'] !== 'active' ? ' <span class="badge badge-secondary">' . htmlspecialchars(ucfirst($booking['status'])) . '</span>' : ''; ?></td>
                    <td><?= htmlspecialchars($booking['client']['customer_name'] ?? ''); ?><?= !empty($booking['client']['is_agent']) ? ' <span class="badge badge-warning">Agent</span>' : ''; ?></td>
                    <td class="text-center"><?= count($booking['passengers']); ?></td>
                    <td class="text-right"><?= travel_money($booking['net_price']); ?></td>
                    <td class="text-right"><?= travel_money($booking['paid']); ?></td>
                    <td class="text-right <?= $booking['due'] > 0 ? 'text-danger' : ''; ?>"><?= travel_money(max(0, $booking['due'])); ?></td>
                    <td><?php if ($next) { ?><span class="<?= $next['state'] === 'overdue' ? 'text-danger font-weight-bold' : ''; ?>">#<?= (int)$next['installment_no']; ?> · <?= $date($next['due_date']); ?> · <?= travel_money($next['due']); ?></span><?= $next['state'] === 'overdue' ? ' <span class="badge badge-danger">Overdue</span>' : ''; ?><?php } elseif ($booking['schedule']) { ?><span class="text-success">All paid</span><?php } else { ?><span class="text-muted">—</span><?php } ?></td>
                    <td><?php if ($booking['due'] > 0 && $booking['status'] === 'active') { ?><a class="btn btn-xs btn-outline-success" href="receive_payment.php?client_id=<?= (int)$booking['client_id']; ?>&booking_id=<?= (int)$booking['id']; ?>">Receive</a><?php } ?></td>
                </tr>
            <?php } ?>
            <?php if (!$bookings) { ?><tr><td colspan="8" class="text-center text-muted py-3">No booking in this group yet.</td></tr><?php } ?>
            </tbody>
        </table></div></div>
    </div>
</div></section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
