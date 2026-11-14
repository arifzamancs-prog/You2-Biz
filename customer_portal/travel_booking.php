<?php
// A travel client's own booking invoice in the client portal (no purchase price or profit).
session_start();

require_once '../includes/db.php';
require_once '../includes/app_config.php';
require_once '../includes/date_helper.php';
require_once '../includes/customer_portal_helper.php';
require_once '../includes/project_package_helper.php';
require_once '../includes/travel_booking_helper.php';

require_customer_portal_login();
$customer = customer_portal_current_customer($conn);
if (!$customer) {
    session_destroy();
    header('Location: ../login.php');
    exit;
}
$user_id = (int)$customer['user_id'];
$booking = null;
if (project_package_company_type($conn, $user_id) === 'Travel Agency' && travel_booking_schema($conn)) {
    $booking = travel_booking_get($conn, $user_id, (int)($_GET['id'] ?? 0));
}
// Only the client's own booking.
if (!$booking || (int)$booking['client_id'] !== (int)$customer['id']) {
    header('Location: index.php');
    exit;
}
$company = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT name, phone, email, address FROM users WHERE id=' . $user_id));
$schedule = $booking['status'] === 'active' ? travel_installment_schedule($conn, $booking) : [];
$group = (int)$booking['group_id'] > 0 ? travel_group_get($conn, $user_id, (int)$booking['group_id']) : null;
$history = $booking['service_kind'] === 'visa' ? travel_status_history($conn, (int)$booking['id']) : [];
$kinds = travel_service_kinds();
$visa_statuses = travel_visa_statuses();
$is_air = $booking['service_kind'] === 'air_ticket';
$date = static fn($value) => $value ? date('d M Y', strtotime($value)) : '—';
$money = static fn($value) => number_format((float)$value, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($booking['booking_no']); ?> - <?= htmlspecialchars($company['name'] ?? ''); ?></title>
    <link rel="stylesheet" href="../adminlte/plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../adminlte/dist/css/adminlte.min.css">
    <style>
        body{background:#f4f6f9}
        .invoice-box{background:#fff;max-width:880px;margin:24px auto;padding:28px;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.06)}
        .label{color:#6c757d;font-size:.75rem;text-transform:uppercase;letter-spacing:.04em}
        .meta td{padding:2px 10px 2px 0;vertical-align:top}
        @media print{body{background:#fff}.invoice-box{box-shadow:none;margin:0;max-width:none}.no-print{display:none!important}}
    </style>
</head>
<body>
<div class="invoice-box">
    <div class="d-flex justify-content-between mb-3 no-print">
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left mr-1"></i>Back</a>
        <button class="btn btn-sm btn-primary" onclick="window.print()"><i class="fas fa-print mr-1"></i>Print</button>
    </div>
    <div class="d-flex justify-content-between flex-wrap mb-3">
        <div><h4 class="mb-0"><?= htmlspecialchars($company['name'] ?? ''); ?></h4>
            <div class="small text-muted"><?= htmlspecialchars(implode(' · ', array_filter([(string)($company['address'] ?? ''), (string)($company['phone'] ?? ''), (string)($company['email'] ?? '')]))); ?></div></div>
        <div class="text-right"><div class="h5 mb-0">INVOICE</div><strong><?= htmlspecialchars($booking['booking_no']); ?></strong>
            <?php if ($booking['status'] !== 'active') { ?> <span class="badge badge-secondary"><?= htmlspecialchars(ucfirst($booking['status'])); ?></span><?php } ?>
            <div class="small text-muted">Date: <?= $date($booking['booking_date']); ?></div></div>
    </div>
    <div class="row mb-3">
        <div class="col-sm-6"><div class="label">Bill To</div><strong><?= htmlspecialchars($customer['customer_name']); ?></strong><div class="small"><?= htmlspecialchars($customer['phone'] ?? ''); ?></div></div>
        <div class="col-sm-6"><div class="label">Service</div>
            <table class="meta small">
                <tr><td>Type</td><td><strong><?= htmlspecialchars($kinds[$booking['service_kind']] ?? ''); ?></strong><?= $booking['package_name'] ? ' · ' . htmlspecialchars($booking['package_name']) : ''; ?></td></tr>
                <?php if ($is_air) { ?>
                    <tr><td>Airline</td><td><?= htmlspecialchars($booking['airline'] ?: '—'); ?></td></tr>
                    <tr><td>PNR</td><td><strong><?= htmlspecialchars($booking['pnr'] ?: '—'); ?></strong></td></tr>
                    <tr><td>Route</td><td><?= htmlspecialchars($booking['route'] ?: '—'); ?></td></tr>
                    <tr><td>Departure</td><td><?= $date($booking['travel_date']); ?><?= $booking['return_date'] ? ' · Return ' . $date($booking['return_date']) : ''; ?></td></tr>
                <?php } else { ?>
                    <?php if ($booking['service_kind'] === 'visa') { ?><tr><td>Country</td><td><strong><?= htmlspecialchars($booking['country'] ?: '—'); ?></strong><?= $booking['visa_type'] !== '' ? ' · ' . htmlspecialchars($booking['visa_type']) : ''; ?></td></tr><?php } ?>
                    <?php if ($group) { ?><tr><td>Group</td><td><strong><?= htmlspecialchars($group['group_name']); ?></strong><?= $group['hotel_info'] !== '' ? '<div>' . htmlspecialchars($group['hotel_info']) . '</div>' : ''; ?></td></tr><?php } ?>
                    <tr><td>Date</td><td><?= $date($booking['travel_date']); ?></td></tr>
                    <?php if ($booking['details'] !== '') { ?><tr><td>Details</td><td><?= htmlspecialchars($booking['details']); ?></td></tr><?php } ?>
                <?php } ?>
            </table></div>
    </div>
    <table class="table table-bordered table-sm">
        <thead class="thead-light"><tr><th>#</th><th>Passenger</th><th>Passport No</th><?php if ($is_air) { ?><th>Ticket No</th><?php } ?></tr></thead>
        <tbody><?php foreach ($booking['passengers'] as $i => $passenger) { ?><tr><td><?= $i + 1; ?></td><td><?= htmlspecialchars($passenger['passenger_name']); ?></td><td><?= htmlspecialchars($passenger['passport_no']); ?></td><?php if ($is_air) { ?><td><?= htmlspecialchars($passenger['ticket_no']); ?></td><?php } ?></tr><?php } ?></tbody>
    </table>
    <div class="row">
        <div class="col-sm-6">
            <?php if ($history) { ?>
                <div class="label mb-1">Visa Progress</div>
                <ul class="list-unstyled small">
                    <?php foreach ($history as $step) { ?><li><i class="fas fa-check-circle text-<?= travel_visa_status_badge($step['status']); ?> mr-1"></i><?= htmlspecialchars($visa_statuses[$step['status']] ?? $step['status']); ?> · <?= $date($step['status_date']); ?></li><?php } ?>
                </ul>
            <?php } ?>
        </div>
        <div class="col-sm-6"><table class="table table-sm">
            <?php if ((float)$booking['extra_price'] > 0 || (float)$booking['client_credit'] > 0) { ?>
                <tr><th>Fare</th><td class="text-right"><?= $money($booking['price']); ?></td></tr>
                <?php if ((float)$booking['extra_price'] > 0) { ?><tr><th>Reissue charge</th><td class="text-right">+ <?= $money($booking['extra_price']); ?></td></tr><?php } ?>
                <?php if ((float)$booking['client_credit'] > 0) { ?><tr><th>Refund</th><td class="text-right">− <?= $money($booking['client_credit']); ?></td></tr><?php } ?>
            <?php } ?>
            <tr><th>Total</th><td class="text-right">BDT <?= $money($booking['net_price']); ?></td></tr>
            <tr><th>Paid</th><td class="text-right">BDT <?= $money($booking['paid']); ?></td></tr>
            <tr class="<?= $booking['due'] > 0 ? 'text-danger' : 'text-success'; ?>"><th>Due</th><td class="text-right"><strong>BDT <?= $money(max(0, $booking['due'])); ?></strong></td></tr>
        </table></div>
    </div>
    <?php if ($schedule) { ?>
        <div class="label mb-1">Installment Schedule</div>
        <table class="table table-bordered table-sm">
            <thead class="thead-light"><tr><th>#</th><th>Due Date</th><th class="text-right">Amount</th><th class="text-right">Paid</th><th>Status</th></tr></thead>
            <tbody><?php foreach ($schedule as $step) { ?><tr class="<?= $step['state'] === 'overdue' ? 'text-danger' : ''; ?>"><td><?= (int)$step['installment_no']; ?></td><td><?= $date($step['due_date']); ?></td><td class="text-right"><?= $money($step['amount']); ?></td><td class="text-right"><?= $money($step['paid']); ?></td><td><?= ['paid' => 'Paid', 'partial' => 'Part paid', 'overdue' => 'Overdue', 'upcoming' => 'Upcoming'][$step['state']]; ?></td></tr><?php } ?></tbody>
        </table>
    <?php } ?>
</div>
</body>
</html>
