<?php
require_once __DIR__ . '/_init.php';

$booking = travel_booking_get($conn, $user_id, (int)($_GET['id'] ?? 0));
if (!$booking) {
    header('Location: bookings.php');
    exit;
}
$company = mysqli_fetch_assoc(mysqli_query($conn, "SELECT name, phone, email, address, avatar FROM users WHERE id={$user_id}"));
$client = $booking['client'];
$kind_label = travel_service_kinds()[$booking['service_kind']] ?? 'Booking';
$is_air = $booking['service_kind'] === 'air_ticket';
$profit = $booking['net_price'] - $booking['net_cost'] - $booking['net_commission'];
$status_badge = ['active' => 'success', 'refunded' => 'danger', 'void' => 'secondary'][$booking['status']] ?? 'light';
$adjust_labels = travel_adjustment_kinds();
$schedule = $booking['status'] === 'active' ? travel_installment_schedule($conn, $booking) : [];
$group = (int)$booking['group_id'] > 0 ? travel_group_get($conn, $user_id, (int)$booking['group_id']) : null;
$payments = mysqli_query($conn, "SELECT payment_no, payment_date, amount FROM travel_client_payments WHERE booking_id=" . (int)$booking['id'] . " ORDER BY id");
$trip_labels = ['one_way' => 'One Way', 'round_trip' => 'Round Trip', 'multi_city' => 'Multi City'];
$date = static fn($value) => $value ? date('d M Y', strtotime($value)) : '—';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<style>
.travel-invoice{background:#fff;max-width:900px;margin:0 auto;padding:28px;border:1px solid #dee2e6;border-radius:6px}
.travel-invoice h2{font-size:1.4rem;margin:0}
.travel-invoice .meta td{padding:2px 8px 2px 0;vertical-align:top}
.travel-invoice .label{color:#6c757d;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em}
.travel-invoice table.items th{background:#f4f6f9}
@media print{
    body *{visibility:hidden}
    .travel-invoice,.travel-invoice *{visibility:visible}
    .travel-invoice{position:absolute;left:0;top:0;border:0;max-width:none;width:100%;padding:0}
    .no-print{display:none!important}
}
</style>
<section class="content pt-3"><div class="container-fluid">
    <?php if (!empty($_GET['created'])) { ?><div class="alert alert-success no-print">Booking <?= htmlspecialchars($booking['booking_no']); ?> saved.</div><?php } ?>
    <?php if (!empty($_GET['paid'])) { ?><div class="alert alert-success no-print">Payment received.</div><?php } ?>
    <?php if (isset($adjust_labels[$_GET['adjusted'] ?? ''])) { ?><div class="alert alert-success no-print"><?= $adjust_labels[$_GET['adjusted']]; ?> saved. Client and vendor accounts are updated.</div><?php } ?>
    <div class="d-flex flex-wrap mb-3 no-print" style="gap:.5rem">
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><i class="fas fa-print mr-1"></i>Print Invoice</button>
        <?php if ($booking['due'] > 0 && $booking['status'] === 'active') { ?><a class="btn btn-success btn-sm" href="receive_payment.php?client_id=<?= (int)$booking['client_id']; ?>&booking_id=<?= (int)$booking['id']; ?>"><i class="fas fa-hand-holding-usd mr-1"></i>Receive Payment</a><?php } ?>
        <a class="btn btn-outline-secondary btn-sm" href="client_ledger.php?client_id=<?= (int)$booking['client_id']; ?>"><i class="fas fa-book mr-1"></i>Client Ledger</a>
        <?php if ($booking['status'] === 'active') { ?>
            <a class="btn btn-outline-info btn-sm" href="booking_adjust.php?id=<?= (int)$booking['id']; ?>&type=reissue"><i class="fas fa-sync-alt mr-1"></i>Reissue</a>
            <a class="btn btn-outline-danger btn-sm" href="booking_adjust.php?id=<?= (int)$booking['id']; ?>&type=refund"><i class="fas fa-undo mr-1"></i>Refund</a>
            <a class="btn btn-outline-secondary btn-sm" href="booking_adjust.php?id=<?= (int)$booking['id']; ?>&type=void"><i class="fas fa-ban mr-1"></i>Void</a>
        <?php } ?>
        <a class="btn btn-outline-info btn-sm" href="booking_create.php"><i class="fas fa-plus mr-1"></i>New Booking</a>
        <a class="btn btn-outline-secondary btn-sm" href="bookings.php"><i class="fas fa-list mr-1"></i>Booking List</a>
    </div>

    <div class="row">
        <div class="col-xl-9">
            <div class="travel-invoice">
                <div class="d-flex justify-content-between flex-wrap mb-3">
                    <div>
                        <h2><?= htmlspecialchars($company['name'] ?? ''); ?></h2>
                        <div class="text-muted small"><?= htmlspecialchars(trim(implode(' · ', array_filter([(string)($company['address'] ?? ''), (string)($company['phone'] ?? ''), (string)($company['email'] ?? '')])))); ?></div>
                    </div>
                    <div class="text-right">
                        <div class="h4 mb-0">INVOICE</div>
                        <div><strong><?= htmlspecialchars($booking['booking_no']); ?></strong><?php if ($booking['status'] !== 'active') { ?> <span class="badge badge-<?= $status_badge; ?>"><?= htmlspecialchars(ucfirst($booking['status'])); ?></span><?php } ?></div>
                        <div class="small text-muted">Date: <?= $date($booking['booking_date']); ?></div>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-sm-6">
                        <div class="label">Bill To</div>
                        <div><strong><?= htmlspecialchars($client['customer_name'] ?? ''); ?></strong><?= !empty($client['is_agent']) ? ' <span class="badge badge-warning">Agent</span>' : ''; ?></div>
                        <div class="small"><?= htmlspecialchars(trim(($client['customer_code'] ? 'ID: ' . $client['customer_code'] . ' · ' : '') . ($client['phone'] ?? ''))); ?></div>
                        <?php if (!empty($client['address'])) { ?><div class="small"><?= htmlspecialchars($client['address']); ?></div><?php } ?>
                    </div>
                    <div class="col-sm-6">
                        <div class="label">Service</div>
                        <table class="meta small">
                            <tr><td>Type</td><td><strong><?= htmlspecialchars($kind_label); ?></strong><?= $booking['package_name'] ? ' · ' . htmlspecialchars($booking['package_name']) : ''; ?></td></tr>
                            <?php if ($is_air) { ?>
                                <tr><td>Airline</td><td><?= htmlspecialchars($booking['airline'] ?: '—'); ?></td></tr>
                                <tr><td>PNR</td><td><strong><?= htmlspecialchars($booking['pnr'] ?: '—'); ?></strong></td></tr>
                                <tr><td>Route</td><td><?= htmlspecialchars($booking['route'] ?: '—'); ?> <?= isset($trip_labels[$booking['trip_type']]) ? '(' . $trip_labels[$booking['trip_type']] . ')' : ''; ?></td></tr>
                                <tr><td>Departure</td><td><?= $date($booking['travel_date']); ?><?= $booking['return_date'] ? ' · Return ' . $date($booking['return_date']) : ''; ?></td></tr>
                            <?php } else { ?>
                                <?php if ($booking['service_kind'] === 'visa') { ?>
                                    <tr><td>Country</td><td><strong><?= htmlspecialchars($booking['country'] ?: '—'); ?></strong><?= $booking['visa_type'] !== '' ? ' · ' . htmlspecialchars($booking['visa_type']) . ' visa' : ''; ?></td></tr>
                                    <?php if ($booking['application_no'] !== '') { ?><tr><td>Ref No</td><td><?= htmlspecialchars($booking['application_no']); ?></td></tr><?php } ?>
                                    <?php if ($booking['expected_date']) { ?><tr><td>Expected</td><td><?= $date($booking['expected_date']); ?></td></tr><?php } ?>
                                <?php } ?>
                                <?php if ($group) { ?><tr><td>Group</td><td><strong><?= htmlspecialchars($group['group_name']); ?></strong><?= $group['hotel_info'] !== '' ? '<div>' . htmlspecialchars($group['hotel_info']) . '</div>' : ''; ?></td></tr><?php } ?>
                                <tr><td>Date</td><td><?= $date($booking['travel_date']); ?><?= $group && $group['return_date'] ? ' · Return ' . $date($group['return_date']) : ''; ?></td></tr>
                                <?php if ($booking['details'] !== '') { ?><tr><td>Details</td><td><?= htmlspecialchars($booking['details']); ?></td></tr><?php } ?>
                            <?php } ?>
                        </table>
                    </div>
                </div>
                <table class="table table-bordered table-sm items">
                    <thead><tr><th style="width:40px">#</th><th>Passenger</th><th>Passport No</th><?php if ($is_air) { ?><th>Ticket No</th><?php } ?></tr></thead>
                    <tbody>
                    <?php foreach ($booking['passengers'] as $index => $passenger) { ?>
                        <tr><td><?= $index + 1; ?></td><td><?= htmlspecialchars($passenger['passenger_name']); ?></td><td><?= htmlspecialchars($passenger['passport_no']); ?></td><?php if ($is_air) { ?><td><?= htmlspecialchars($passenger['ticket_no']); ?></td><?php } ?></tr>
                    <?php } ?>
                    <?php if (!$booking['passengers']) { ?><tr><td colspan="<?= $is_air ? 4 : 3; ?>" class="text-muted text-center">—</td></tr><?php } ?>
                    </tbody>
                </table>
                <div class="row">
                    <div class="col-sm-6 small text-muted"><?= $booking['note'] !== '' ? 'Note: ' . htmlspecialchars($booking['note']) : ''; ?></div>
                    <div class="col-sm-6">
                        <table class="table table-sm mb-0">
                            <?php if ((float)$booking['extra_price'] > 0 || (float)$booking['client_credit'] > 0) { ?>
                                <tr><th>Fare</th><td class="text-right">BDT <?= travel_money($booking['price']); ?></td></tr>
                                <?php if ((float)$booking['extra_price'] > 0) { ?><tr><th>Reissue charge</th><td class="text-right">+ <?= travel_money($booking['extra_price']); ?></td></tr><?php } ?>
                                <?php if ((float)$booking['client_credit'] > 0) { ?><tr><th>Refund</th><td class="text-right">− <?= travel_money($booking['client_credit']); ?></td></tr><?php } ?>
                            <?php } ?>
                            <tr><th>Total</th><td class="text-right">BDT <?= travel_money($booking['net_price']); ?></td></tr>
                            <tr><th>Paid</th><td class="text-right">BDT <?= travel_money($booking['paid']); ?></td></tr>
                            <tr class="<?= $booking['due'] > 0 ? 'text-danger' : 'text-success'; ?>"><th>Due</th><td class="text-right"><strong>BDT <?= travel_money(max(0, $booking['due'])); ?></strong></td></tr>
                        </table>
                    </div>
                </div>
                <?php if ($schedule) { ?>
                <h6 class="font-weight-bold mt-3 mb-2">Installment Schedule</h6>
                <table class="table table-bordered table-sm">
                    <thead class="thead-light"><tr><th style="width:40px">#</th><th>Due Date</th><th class="text-right">Amount</th><th class="text-right">Paid</th><th class="text-right">Due</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($schedule as $step) { ?>
                        <tr class="<?= $step['state'] === 'overdue' ? 'text-danger' : ''; ?>"><td><?= (int)$step['installment_no']; ?></td><td><?= $date($step['due_date']); ?></td><td class="text-right"><?= travel_money($step['amount']); ?></td><td class="text-right"><?= travel_money($step['paid']); ?></td><td class="text-right"><?= travel_money($step['due']); ?></td>
                            <td><?= ['paid' => '<span class="badge badge-success">Paid</span>', 'partial' => '<span class="badge badge-info">Part paid</span>', 'overdue' => '<span class="badge badge-danger">Overdue</span>', 'upcoming' => '<span class="badge badge-light border">Upcoming</span>'][$step['state']]; ?></td></tr>
                    <?php } ?>
                    </tbody>
                </table>
                <?php } ?>
                <div class="small text-muted mt-4">Prepared by <?= htmlspecialchars($booking['created_by_name'] ?: '—'); ?> · Thank you for travelling with us.</div>
            </div>
        </div>
        <div class="col-xl-3 no-print mt-3 mt-xl-0">
            <div class="card card-outline card-secondary">
                <div class="card-header"><h3 class="card-title">Internal (not printed)</h3></div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tr><th>Vendor</th><td><?= htmlspecialchars($booking['vendor_name'] ?: '—'); ?></td></tr>
                        <tr><th>Status</th><td><span class="badge badge-<?= $status_badge; ?>"><?= htmlspecialchars(ucfirst($booking['status'])); ?></span></td></tr>
                        <tr><th>Purchase</th><td>BDT <?= travel_money($booking['net_cost']); ?></td></tr>
                        <tr><th>Selling</th><td>BDT <?= travel_money($booking['net_price']); ?></td></tr>
                        <?php if ((float)$booking['commission'] > 0) { ?><tr><th>Commission</th><td>BDT <?= travel_money($booking['net_commission']); ?><div class="small text-muted"><a href="commissions.php?agent_id=<?= (int)$booking['commission_agent_id']; ?>"><?= htmlspecialchars($booking['commission_agent']['customer_name'] ?? 'Agent'); ?></a><?= (float)$booking['commission_credit'] > 0 ? ' · cancelled ' . travel_money($booking['commission_credit']) : ''; ?></div></td></tr><?php } ?>
                        <tr><th>Profit</th><td class="<?= $profit < 0 ? 'text-danger' : 'text-success'; ?> font-weight-bold">BDT <?= travel_money($profit); ?></td></tr>
                        <?php if ((int)$booking['vendor_id'] > 0) { ?><tr><td colspan="2"><a href="<?= htmlspecialchars(app_path('suppliers/supplier_ledger.php?id=' . (int)$booking['vendor_id'])); ?>"><i class="fas fa-book mr-1"></i>Vendor ledger</a></td></tr><?php } ?>
                    </table>
                </div>
            </div>
            <?php if ($booking['service_kind'] === 'visa') { $visa_statuses = travel_visa_statuses(); $visa_status = $booking['process_status'] ?: 'received'; ?>
            <div class="card card-outline card-info">
                <div class="card-header"><h3 class="card-title">Visa Progress</h3><div class="card-tools"><span class="badge badge-<?= travel_visa_status_badge($visa_status); ?>"><?= htmlspecialchars($visa_statuses[$visa_status] ?? $visa_status); ?></span></div></div>
                <div class="card-body p-2">
                    <ul class="list-unstyled small mb-2">
                        <?php foreach (travel_status_history($conn, (int)$booking['id']) as $step) { ?>
                            <li class="mb-1"><i class="fas fa-circle text-<?= travel_visa_status_badge($step['status']); ?> mr-1" style="font-size:.55rem"></i><strong><?= htmlspecialchars($visa_statuses[$step['status']] ?? $step['status']); ?></strong> · <?= $date($step['status_date']); ?><?= $step['sms_status'] === 'sent' ? ' <i class="fas fa-sms text-success" title="SMS sent"></i>' : ($step['sms_status'] === 'failed' ? ' <i class="fas fa-sms text-danger" title="SMS failed"></i>' : ''); ?><?php if ($step['note'] !== '') { ?><div class="text-muted pl-3"><?= htmlspecialchars($step['note']); ?></div><?php } ?></li>
                        <?php } ?>
                    </ul>
                    <?php if ($booking['status'] === 'active') { ?>
                    <form method="post" action="visa_tracker.php" onsubmit="return confirm('Change the visa status?');">
                        <?= travel_csrf_field(); ?>
                        <input type="hidden" name="booking_id" value="<?= (int)$booking['id']; ?>"><input type="hidden" name="return_to" value="view">
                        <div class="form-row">
                            <div class="col-7 mb-1"><select name="status" class="form-control form-control-sm"><?php foreach ($visa_statuses as $key => $label) { if ($key === $visa_status) continue; ?><option value="<?= $key; ?>"<?= $key === travel_visa_next_status($visa_status) ? ' selected' : ''; ?>><?= htmlspecialchars($label); ?></option><?php } ?></select></div>
                            <div class="col-5 mb-1"><input type="date" name="status_date" class="form-control form-control-sm" max="<?= date('Y-m-d'); ?>" value="<?= date('Y-m-d'); ?>"></div>
                            <div class="col-12 mb-1"><input name="note" class="form-control form-control-sm" maxlength="200" placeholder="Note (optional, goes in SMS)"></div>
                            <div class="col-6 pt-1"><div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="view-sms" name="send_sms" value="1" checked><label class="custom-control-label small" for="view-sms">SMS client</label></div></div>
                            <div class="col-6"><button class="btn btn-sm btn-info btn-block">Update</button></div>
                        </div>
                    </form>
                    <?php } ?>
                </div>
            </div>
            <?php } ?>
            <?php if ($booking['adjustments']) { ?>
            <div class="card">
                <div class="card-header"><h3 class="card-title">Refund / Reissue History</h3></div>
                <div class="card-body p-0"><table class="table table-sm mb-0">
                    <?php foreach ($booking['adjustments'] as $adjustment) { ?>
                        <tr><td><strong><?= htmlspecialchars($adjust_labels[$adjustment['kind']] ?? ''); ?></strong> <?= htmlspecialchars($adjustment['adjust_no']); ?><div class="small text-muted"><?= $date($adjustment['adjust_date']); ?> · <?= htmlspecialchars($adjustment['details']); ?></div>
                            <div class="small"><?php if ($adjustment['kind'] === 'reissue') { ?>Client +<?= travel_money($adjustment['extra_price']); ?> · Vendor +<?= travel_money($adjustment['extra_cost']); ?><?php } else { ?>Client refund <?= travel_money($adjustment['client_credit']); ?><?= (float)$adjustment['client_cash'] > 0 ? ' (paid back ' . travel_money($adjustment['client_cash']) . ')' : ''; ?> · Vendor refund <?= travel_money($adjustment['cost_credit']); ?><?php } ?></div>
                            <?php if ($adjustment['note'] !== '') { ?><div class="small text-muted"><?= htmlspecialchars($adjustment['note']); ?></div><?php } ?></td></tr>
                    <?php } ?>
                </table></div>
            </div>
            <?php } ?>
            <div class="card">
                <div class="card-header"><h3 class="card-title">Payments</h3></div>
                <div class="card-body p-0"><table class="table table-sm mb-0">
                    <?php $any = false; while ($payments && ($payment = mysqli_fetch_assoc($payments))) { $any = true; ?>
                        <tr><td><?= htmlspecialchars($payment['payment_no']); ?><div class="small text-muted"><?= $date($payment['payment_date']); ?></div></td><td class="text-right">BDT <?= travel_money($payment['amount']); ?></td></tr>
                    <?php } if (!$any) { ?><tr><td class="text-muted">No payment yet.</td></tr><?php } ?>
                </table></div>
            </div>
        </div>
    </div>
</div></section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
