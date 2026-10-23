<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/parking_helper.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/transaction_helper.php';

require_admin_user();

$company_id = (int)($_SESSION['user_id'] ?? 0);
if (!parking_company_enabled($conn, $company_id)) {
    header('Location: ' . app_path('dashboard.php?error=Parking Management is not enabled for this company.'));
    exit;
}

parking_ensure_company_defaults($conn, $company_id);

function parking_entry_recent_entries($conn, $company_id)
{
    $entries = [];
    $stmt = mysqli_prepare($conn, "SELECT t.ticket_no, t.vehicle_number, t.entry_at, t.ticket_status, v.vehicle_name, g.gate_name FROM parking_tickets t INNER JOIN parking_vehicle_types v ON v.id=t.vehicle_type_id LEFT JOIN parking_gates g ON g.id=t.entry_gate_id WHERE t.company_id=? ORDER BY t.entry_at DESC, t.id DESC LIMIT 10");
    mysqli_stmt_bind_param($stmt, 'i', $company_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $status = (string)($row['ticket_status'] ?? '');
        $entries[] = [
            'ticket_no' => (string)$row['ticket_no'],
            'vehicle_name' => (string)$row['vehicle_name'],
            'vehicle_number' => (string)($row['vehicle_number'] ?: '—'),
            'gate_name' => (string)($row['gate_name'] ?: '—'),
            'entry_time' => date('d M Y, h:i A', strtotime((string)$row['entry_at'])),
            'status' => $status,
            'status_label' => ucwords(str_replace('_', ' ', $status)),
        ];
    }
    mysqli_stmt_close($stmt);

    return $entries;
}

if (($_GET['ajax'] ?? '') === 'recent_entries') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'entries' => parking_entry_recent_entries($conn, $company_id)]);
    exit;
}

if (empty($_SESSION['parking_entry_csrf'])) {
    $_SESSION['parking_entry_csrf'] = bin2hex(random_bytes(32));
}

function parking_entry_redirect($message, $type = 'danger')
{
    $_SESSION['parking_entry_flash'] = ['message' => $message, 'type' => $type];
    header('Location: entry.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)($_SESSION['parking_entry_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
        parking_entry_redirect('Invalid request. Please try again.');
    }

    $vehicle_type_id = (int)($_POST['vehicle_type_id'] ?? 0);
    $rate_plan_id = (int)($_POST['rate_plan_id'] ?? 0);
    $gate_id = (int)($_POST['gate_id'] ?? 0);
    $requested_zone_id = (int)($_POST['zone_id'] ?? 0);
    $requested_slot_id = (int)($_POST['slot_id'] ?? 0);
    $vehicle_number = strtoupper(trim((string)($_POST['vehicle_number'] ?? '')));
    $vehicle_number = preg_replace('/\s+/', ' ', $vehicle_number);

    $settings_stmt = mysqli_prepare($conn, 'SELECT * FROM parking_settings WHERE company_id=? LIMIT 1');
    mysqli_stmt_bind_param($settings_stmt, 'i', $company_id);
    mysqli_stmt_execute($settings_stmt);
    $settings = mysqli_fetch_assoc(mysqli_stmt_get_result($settings_stmt)) ?: [];
    mysqli_stmt_close($settings_stmt);

    if ($vehicle_type_id <= 0 || $rate_plan_id <= 0 || $gate_id <= 0) {
        parking_entry_redirect('Vehicle type, rate plan and entry gate are required.');
    }
    if (!empty($settings['vehicle_number_required']) && $vehicle_number === '') {
        parking_entry_redirect('Vehicle number is required by your parking settings.');
    }
    if (strlen($vehicle_number) > 60) {
        parking_entry_redirect('Vehicle number is too long.');
    }

    $rate_stmt = mysqli_prepare($conn, "SELECT r.*, v.vehicle_name, v.default_capacity FROM parking_rate_plans r INNER JOIN parking_vehicle_types v ON v.id=r.vehicle_type_id AND v.company_id=r.company_id WHERE r.id=? AND r.company_id=? AND r.vehicle_type_id=? AND r.status='active' AND v.status='active' LIMIT 1");
    mysqli_stmt_bind_param($rate_stmt, 'iii', $rate_plan_id, $company_id, $vehicle_type_id);
    mysqli_stmt_execute($rate_stmt);
    $rate_plan = mysqli_fetch_assoc(mysqli_stmt_get_result($rate_stmt));
    mysqli_stmt_close($rate_stmt);
    if (!$rate_plan) {
        parking_entry_redirect('Select an active rate plan for the selected vehicle type.');
    }
    $prepaid_mode = ($settings['payment_mode'] ?? '') === 'prepaid_fixed';
    $entry_fee = $prepaid_mode ? max(0, (float)$rate_plan['fixed_fee']) : 0;
    $entry_wallet_id = (int)($_POST['entry_wallet_id'] ?? 0);
    $entry_payment_method = (string)($_POST['entry_payment_method'] ?? 'cash');
    $entry_payment_reference = trim((string)($_POST['entry_payment_reference'] ?? ''));
    if ($prepaid_mode && $rate_plan['rate_mode'] !== 'fixed') {
        parking_entry_redirect('Prepaid Fixed Fee mode requires a fixed-fee rate plan.');
    }
    if ($prepaid_mode && $entry_fee > 0 && ($entry_wallet_id <= 0 || !in_array($entry_payment_method, ['cash','card','bkash','nagad','bank','wallet','other'], true))) {
        parking_entry_redirect('Select a wallet and valid payment method for the prepaid ticket.');
    }

    $gate_stmt = mysqli_prepare($conn, "SELECT id, branch_id, gate_name FROM parking_gates WHERE id=? AND company_id=? AND status='active' AND gate_type IN ('entry','both') LIMIT 1");
    mysqli_stmt_bind_param($gate_stmt, 'ii', $gate_id, $company_id);
    mysqli_stmt_execute($gate_stmt);
    $gate = mysqli_fetch_assoc(mysqli_stmt_get_result($gate_stmt));
    mysqli_stmt_close($gate_stmt);
    if (!$gate) {
        parking_entry_redirect('Select an active entry gate.');
    }
    if (!parking_operator_can_use_gate($conn, $company_id, $gate_id, 'entry')) {
        parking_entry_redirect('You are not assigned to this entry gate.');
    }
    if ($prepaid_mode && $entry_fee > 0) {
        $wallet_stmt = mysqli_prepare($conn, "SELECT id FROM wallets WHERE id=? AND user_id=? AND branch_id=? AND status='active' LIMIT 1");
        $gate_branch_id = (int)$gate['branch_id'];
        mysqli_stmt_bind_param($wallet_stmt, 'iii', $entry_wallet_id, $company_id, $gate_branch_id);
        mysqli_stmt_execute($wallet_stmt);
        $wallet = mysqli_fetch_assoc(mysqli_stmt_get_result($wallet_stmt));
        mysqli_stmt_close($wallet_stmt);
        if (!$wallet) parking_entry_redirect('Select an active wallet from this entry gate branch.');
    }

    $zone_id = 0;
    $slot_id = 0;
    $slot_transaction = false;
    if (!empty($settings['slot_management_enabled'])) {
        if ($requested_zone_id <= 0 || $requested_slot_id <= 0) {
            parking_entry_redirect('Select an available zone and slot.');
        }
        mysqli_begin_transaction($conn);
        $slot_transaction = true;
        $slot_stmt = mysqli_prepare($conn, "SELECT s.id, s.zone_id FROM parking_slots s INNER JOIN parking_zones z ON z.id=s.zone_id WHERE s.id=? AND s.zone_id=? AND s.company_id=? AND s.branch_id=? AND s.status='available' AND z.status='active' AND (s.vehicle_type_id IS NULL OR s.vehicle_type_id=?) AND (z.vehicle_type_id IS NULL OR z.vehicle_type_id=?) FOR UPDATE");
        $gate_branch_id = (int)$gate['branch_id'];
        mysqli_stmt_bind_param($slot_stmt, 'iiiiii', $requested_slot_id, $requested_zone_id, $company_id, $gate_branch_id, $vehicle_type_id, $vehicle_type_id);
        mysqli_stmt_execute($slot_stmt);
        $slot = mysqli_fetch_assoc(mysqli_stmt_get_result($slot_stmt));
        mysqli_stmt_close($slot_stmt);
        if (!$slot) {
            mysqli_rollback($conn);
            parking_entry_redirect('The selected slot is unavailable or does not match this vehicle/gate.');
        }
        $zone_id = (int)$slot['zone_id'];
        $slot_id = (int)$slot['id'];
    } elseif ((int)($rate_plan['default_capacity'] ?? 0) > 0) {
        $capacity_stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM parking_tickets WHERE company_id=? AND vehicle_type_id=? AND ticket_status IN ('active','payment_pending','paid')");
        mysqli_stmt_bind_param($capacity_stmt, 'ii', $company_id, $vehicle_type_id);
        mysqli_stmt_execute($capacity_stmt);
        $currently_inside = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($capacity_stmt))['total'] ?? 0);
        mysqli_stmt_close($capacity_stmt);
        if ($currently_inside >= (int)$rate_plan['default_capacity']) {
            parking_entry_redirect('Parking capacity is full for the selected vehicle type.');
        }
    }
    if ($prepaid_mode && !$slot_transaction) {
        mysqli_begin_transaction($conn);
        $slot_transaction = true;
    }

    $ticket_no = parking_new_ticket_code();
    $barcode_value = $ticket_no;
    $rate_snapshot = json_encode([
        'plan_name' => $rate_plan['plan_name'],
        'rate_mode' => $rate_plan['rate_mode'],
        'fixed_fee' => $rate_plan['fixed_fee'],
        'initial_minutes' => $rate_plan['initial_minutes'],
        'initial_fee' => $rate_plan['initial_fee'],
        'extra_minutes' => $rate_plan['extra_minutes'],
        'extra_fee' => $rate_plan['extra_fee'],
        'daily_max_fee' => $rate_plan['daily_max_fee'],
    ]);
    $operator_id = (int)($_SESSION['login_user_id'] ?? 0);
    $branch_id = (int)$gate['branch_id'];
    $payment_status = ($settings['payment_mode'] ?? 'pay_at_exit') === 'free' ? 'waived' : 'unpaid';

    $inserted_ticket_id = 0;
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $ticket_stmt = mysqli_prepare($conn, "INSERT INTO parking_tickets (company_id, branch_id, ticket_no, barcode_value, vehicle_type_id, vehicle_number, entry_gate_id, zone_id, slot_id, rate_plan_id, rate_snapshot_json, entry_operator_id, entry_at, payment_status, ticket_status) VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?,0), NULLIF(?,0), ?, ?, ?, NOW(), ?, 'active')");
        mysqli_stmt_bind_param($ticket_stmt, 'iissisiiiisis', $company_id, $branch_id, $ticket_no, $barcode_value, $vehicle_type_id, $vehicle_number, $gate_id, $zone_id, $slot_id, $rate_plan_id, $rate_snapshot, $operator_id, $payment_status);
        $saved = mysqli_stmt_execute($ticket_stmt);
        $inserted_ticket_id = $saved ? (int)mysqli_insert_id($conn) : 0;
        mysqli_stmt_close($ticket_stmt);
        if ($inserted_ticket_id > 0) {
            break;
        }
        $ticket_no = parking_new_ticket_code();
        $barcode_value = $ticket_no;
    }
    if ($inserted_ticket_id <= 0) {
        if ($slot_transaction) mysqli_rollback($conn);
        parking_entry_redirect('Ticket could not be created. Please try again.');
    }

    if ($prepaid_mode) {
        if ($entry_fee > 0) {
            $payment_note = 'Prepaid parking entry: ' . $ticket_no;
            $payment_stmt = mysqli_prepare($conn, 'INSERT INTO parking_payments (company_id, branch_id, ticket_id, wallet_id, received_by, payment_method, amount, payment_reference, payment_note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($payment_stmt, 'iiiiisdss', $company_id, $branch_id, $inserted_ticket_id, $entry_wallet_id, $operator_id, $entry_payment_method, $entry_fee, $entry_payment_reference, $payment_note);
            mysqli_stmt_execute($payment_stmt);
            $payment_id = (int)mysqli_insert_id($conn);
            mysqli_stmt_close($payment_stmt);
            credit_wallet($conn, $entry_wallet_id, $company_id, $entry_fee);
            record_wallet_transaction($conn, generate_short_unique_txn_no($conn, 'PARK'), $company_id, $entry_wallet_id, 'money_in', $payment_id, $entry_fee, $payment_note, date('Y-m-d'), $branch_id);
        }
        $paid_status = $entry_fee > 0 ? 'paid' : 'waived';
        $paid_update_stmt = mysqli_prepare($conn, "UPDATE parking_tickets SET paid_amount=?, payment_status=?, ticket_status='paid' WHERE id=? AND company_id=?");
        mysqli_stmt_bind_param($paid_update_stmt, 'dsii', $entry_fee, $paid_status, $inserted_ticket_id, $company_id);
        mysqli_stmt_execute($paid_update_stmt);
        mysqli_stmt_close($paid_update_stmt);
    }

    if ($slot_transaction) {
        $slot_update_stmt = mysqli_prepare($conn, "UPDATE parking_slots SET status='occupied' WHERE id=? AND company_id=? AND status='available'");
        mysqli_stmt_bind_param($slot_update_stmt, 'ii', $slot_id, $company_id);
        mysqli_stmt_execute($slot_update_stmt);
        $slot_updated = mysqli_stmt_affected_rows($slot_update_stmt) > 0;
        mysqli_stmt_close($slot_update_stmt);
        if (!$slot_updated) {
            mysqli_rollback($conn);
            parking_entry_redirect('The selected slot is no longer available. Please try again.');
        }
    }

    $log_stmt = mysqli_prepare($conn, "INSERT INTO parking_gate_logs (company_id, branch_id, gate_id, ticket_id, operator_id, event_type, event_message) VALUES (?, ?, ?, ?, ?, 'entry_created', ?)");
    $log_message = 'Entry ticket created at ' . $gate['gate_name'];
    mysqli_stmt_bind_param($log_stmt, 'iiiiis', $company_id, $branch_id, $gate_id, $inserted_ticket_id, $operator_id, $log_message);
    mysqli_stmt_execute($log_stmt);
    mysqli_stmt_close($log_stmt);
    if ($slot_transaction) mysqli_commit($conn);

    header('Location: print_entry.php?id=' . $inserted_ticket_id);
    exit;
}

$flash = $_SESSION['parking_entry_flash'] ?? null;
unset($_SESSION['parking_entry_flash']);
$entry_settings_stmt = mysqli_prepare($conn, 'SELECT slot_management_enabled FROM parking_settings WHERE company_id=? LIMIT 1');
mysqli_stmt_bind_param($entry_settings_stmt, 'i', $company_id);
mysqli_stmt_execute($entry_settings_stmt);
$entry_settings = mysqli_fetch_assoc(mysqli_stmt_get_result($entry_settings_stmt)) ?: [];
mysqli_stmt_close($entry_settings_stmt);
$slot_management_enabled = !empty($entry_settings['slot_management_enabled']);
$prepaid_entry_enabled = ($entry_settings['payment_mode'] ?? '') === 'prepaid_fixed';
$vehicles = mysqli_query($conn, "SELECT id, vehicle_name FROM parking_vehicle_types WHERE company_id={$company_id} AND status='active' ORDER BY vehicle_name ASC");
$rates = mysqli_query($conn, "SELECT r.id, r.vehicle_type_id, r.plan_name, r.rate_mode, r.fixed_fee, r.initial_fee, v.vehicle_name FROM parking_rate_plans r INNER JOIN parking_vehicle_types v ON v.id=r.vehicle_type_id WHERE r.company_id={$company_id} AND r.status='active' AND v.status='active' ORDER BY v.vehicle_name ASC, r.plan_name ASC");
$entry_gate_filter = parking_operator_gate_filter($conn, $company_id, 'entry', 'id');
$gates = mysqli_query($conn, "SELECT id, gate_name, gate_code FROM parking_gates WHERE company_id={$company_id} AND status='active' AND gate_type IN ('entry','both'){$entry_gate_filter} ORDER BY gate_name ASC");
$entry_wallets = $prepaid_entry_enabled ? mysqli_query($conn, "SELECT id, wallet_name FROM wallets WHERE user_id={$company_id} AND status='active' ORDER BY is_system DESC, wallet_name ASC") : null;
$zones = $slot_management_enabled ? mysqli_query($conn, "SELECT id, zone_name, zone_code FROM parking_zones WHERE company_id={$company_id} AND status='active' ORDER BY zone_name ASC") : null;
$slots = $slot_management_enabled ? mysqli_query($conn, "SELECT s.id, s.zone_id, s.slot_code, s.vehicle_type_id FROM parking_slots s INNER JOIN parking_zones z ON z.id=s.zone_id WHERE s.company_id={$company_id} AND s.status='available' AND z.status='active' ORDER BY s.slot_code ASC") : null;
$recent_entries = parking_entry_recent_entries($conn, $company_id);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<?php if ($flash) { ?><div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible"><button type="button" class="close" data-dismiss="alert">&times;</button><?= htmlspecialchars($flash['message']) ?></div><?php } ?>

<div class="card card-outline card-success">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-car mr-2"></i>New Parking Entry</h3></div>
    <form method="post" autocomplete="off" target="_blank">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['parking_entry_csrf']) ?>">
        <div class="card-body row">
            <div class="form-group col-md-4"><label>Vehicle Type</label><select class="form-control" id="vehicle_type_id" name="vehicle_type_id" required><option value="">Select vehicle</option><?php while ($vehicle = mysqli_fetch_assoc($vehicles)) { ?><option value="<?= (int)$vehicle['id'] ?>"><?= htmlspecialchars($vehicle['vehicle_name']) ?></option><?php } ?></select></div>
            <div class="form-group col-md-4"><label>Rate Plan</label><select class="form-control" id="rate_plan_id" name="rate_plan_id" required><option value="">Select rate plan</option><?php while ($rate = mysqli_fetch_assoc($rates)) { ?><option value="<?= (int)$rate['id'] ?>" data-vehicle="<?= (int)$rate['vehicle_type_id'] ?>"><?= htmlspecialchars($rate['vehicle_name'] . ' — ' . $rate['plan_name'] . ' (' . ucfirst($rate['rate_mode']) . ')') ?></option><?php } ?></select><small class="text-muted">Only plans for the selected vehicle are available.</small></div>
            <div class="form-group col-md-4"><label>Entry Gate</label><select class="form-control" name="gate_id" required><option value="">Select entry gate</option><?php while ($gate = mysqli_fetch_assoc($gates)) { ?><option value="<?= (int)$gate['id'] ?>"><?= htmlspecialchars($gate['gate_name'] . ' (' . $gate['gate_code'] . ')') ?></option><?php } ?></select></div>
            <div class="form-group col-md-6"><label>Vehicle Number <span class="text-muted">(optional unless required in settings)</span></label><input class="form-control form-control-lg" name="vehicle_number" maxlength="60" placeholder="e.g. DHAKA METRO GA-12-3456" autofocus></div>
            <?php if ($prepaid_entry_enabled) { ?><div class="col-12"><div class="callout callout-info"><strong>Prepaid Fixed Fee</strong> — select a fixed-fee rate plan and receive payment before issuing this ticket.</div></div><div class="form-group col-md-4"><label>Receive Wallet</label><select class="form-control" name="entry_wallet_id"><option value="">Select wallet</option><?php while($wallet=mysqli_fetch_assoc($entry_wallets)){ ?><option value="<?= (int)$wallet['id'] ?>"><?= htmlspecialchars($wallet['wallet_name']) ?></option><?php } ?></select></div><div class="form-group col-md-4"><label>Payment Method</label><select class="form-control" name="entry_payment_method"><option value="cash">Cash</option><option value="card">Card</option><option value="bkash">bKash</option><option value="nagad">Nagad</option><option value="bank">Bank</option><option value="wallet">Wallet</option><option value="other">Other</option></select></div><div class="form-group col-md-4"><label>Reference</label><input class="form-control" name="entry_payment_reference" maxlength="150" placeholder="Optional"></div><?php } ?>
            <?php if ($slot_management_enabled) { ?><div class="form-group col-md-3"><label>Zone</label><select class="form-control" id="zone_id" name="zone_id" required><option value="">Select zone</option><?php while ($zone = mysqli_fetch_assoc($zones)) { ?><option value="<?= (int)$zone['id'] ?>"><?= htmlspecialchars($zone['zone_name'] . ' (' . $zone['zone_code'] . ')') ?></option><?php } ?></select></div><div class="form-group col-md-3"><label>Available Slot</label><select class="form-control" id="slot_id" name="slot_id" required><option value="">Select slot</option><?php while ($slot = mysqli_fetch_assoc($slots)) { ?><option value="<?= (int)$slot['id'] ?>" data-zone="<?= (int)$slot['zone_id'] ?>" data-vehicle="<?= (int)($slot['vehicle_type_id'] ?? 0) ?>"><?= htmlspecialchars($slot['slot_code']) ?></option><?php } ?></select></div><?php } ?>
        </div>
        <div class="card-footer text-right"><button class="btn btn-success btn-lg"><i class="fas fa-ticket-alt mr-1"></i>Print Entry Ticket</button></div>
    </form>
</div>

<div class="card card-outline card-info">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-history mr-2"></i>Last 10 Entries</h3>
        <div class="card-tools"><button type="button" class="btn btn-sm btn-outline-primary" id="refresh-recent-entries"><i class="fas fa-sync-alt mr-1"></i>Refresh</button></div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr><th>Ticket</th><th>Vehicle</th><th>Vehicle Number</th><th>Entry Gate</th><th>Entry Time</th><th>Status</th></tr></thead>
                <tbody id="recent-entry-rows">
                    <?php if (!$recent_entries) { ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No parking entry found.</td></tr>
                    <?php } else { foreach ($recent_entries as $recent_entry) { ?>
                        <tr>
                            <td><?= htmlspecialchars($recent_entry['ticket_no']) ?></td>
                            <td><?= htmlspecialchars($recent_entry['vehicle_name']) ?></td>
                            <td><?= htmlspecialchars($recent_entry['vehicle_number']) ?></td>
                            <td><?= htmlspecialchars($recent_entry['gate_name']) ?></td>
                            <td><?= htmlspecialchars($recent_entry['entry_time']) ?></td>
                            <td><span class="badge badge-<?= in_array($recent_entry['status'], ['paid', 'exited'], true) ? 'success' : 'info' ?>"><?= htmlspecialchars($recent_entry['status_label']) ?></span></td>
                        </tr>
                    <?php } } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const vehicle = document.getElementById('vehicle_type_id');
    const rate = document.getElementById('rate_plan_id');
    const gate = document.querySelector('select[name="gate_id"]');
    const preferenceKey = 'parking_entry_preferences_<?= (int)$company_id ?>';
    function saveEntryPreferences() {
        try {
            localStorage.setItem(preferenceKey, JSON.stringify({
                vehicle: vehicle.value,
                rate: rate.value,
                gate: gate ? gate.value : ''
            }));
        } catch (error) {
            // The entry form still works when browser storage is unavailable.
        }
    }
    function filterRates() {
        const selected = vehicle.value;
        Array.from(rate.options).forEach(function (option, index) {
            if (index === 0) return;
            option.hidden = selected !== '' && option.dataset.vehicle !== selected;
            option.disabled = option.hidden;
        });
        if (rate.selectedOptions[0] && rate.selectedOptions[0].disabled) rate.value = '';
    }
    vehicle.addEventListener('change', filterRates);
    try {
        const saved = JSON.parse(localStorage.getItem(preferenceKey) || '{}');
        if (saved.vehicle && vehicle.querySelector('option[value="' + CSS.escape(saved.vehicle) + '"]')) vehicle.value = saved.vehicle;
        filterRates();
        const savedRate = rate.querySelector('option[value="' + CSS.escape(saved.rate || '') + '"]');
        if (savedRate && !savedRate.disabled) rate.value = saved.rate;
        if (gate && saved.gate && gate.querySelector('option[value="' + CSS.escape(saved.gate) + '"]')) gate.value = saved.gate;
    } catch (error) {
        filterRates();
    }
    vehicle.addEventListener('change', saveEntryPreferences);
    rate.addEventListener('change', saveEntryPreferences);
    if (gate) gate.addEventListener('change', saveEntryPreferences);
    const zone = document.getElementById('zone_id');
    const slot = document.getElementById('slot_id');
    function filterSlots() {
        if (!zone || !slot) return;
        Array.from(slot.options).forEach(function (option, index) {
            if (index === 0) return;
            const zoneMatches = zone.value !== '' && option.dataset.zone === zone.value;
            const vehicleMatches = option.dataset.vehicle === '0' || vehicle.value === '' || option.dataset.vehicle === vehicle.value;
            option.hidden = !zoneMatches || !vehicleMatches;
            option.disabled = option.hidden;
        });
        if (slot.selectedOptions[0] && slot.selectedOptions[0].disabled) slot.value = '';
    }
    if (zone) zone.addEventListener('change', filterSlots);
    vehicle.addEventListener('change', filterSlots);
    filterSlots();

    const recentRows = document.getElementById('recent-entry-rows');
    const refreshRecentEntries = document.getElementById('refresh-recent-entries');
    function statusClass(status) {
        return ['paid', 'exited'].includes(status) ? 'badge-success' : 'badge-info';
    }
    function addCell(row, value) {
        const cell = document.createElement('td');
        cell.textContent = value || '—';
        row.appendChild(cell);
        return cell;
    }
    function renderRecentEntries(entries) {
        recentRows.replaceChildren();
        if (!entries.length) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 6;
            cell.className = 'text-center text-muted py-4';
            cell.textContent = 'No parking entry found.';
            row.appendChild(cell);
            recentRows.appendChild(row);
            return;
        }
        entries.forEach(function (entry) {
            const row = document.createElement('tr');
            addCell(row, entry.ticket_no);
            addCell(row, entry.vehicle_name);
            addCell(row, entry.vehicle_number);
            addCell(row, entry.gate_name);
            addCell(row, entry.entry_time);
            const statusCell = document.createElement('td');
            const badge = document.createElement('span');
            badge.className = 'badge ' + statusClass(entry.status);
            badge.textContent = entry.status_label;
            statusCell.appendChild(badge);
            row.appendChild(statusCell);
            recentRows.appendChild(row);
        });
    }
    async function loadRecentEntries() {
        try {
            const response = await fetch('entry.php?ajax=recent_entries', {credentials: 'same-origin', cache: 'no-store'});
            const data = await response.json();
            if (response.ok && data.ok) renderRecentEntries(data.entries || []);
        } catch (error) {
            // Keep the server-rendered list visible if a refresh fails.
        }
    }
    if (refreshRecentEntries) refreshRecentEntries.addEventListener('click', loadRecentEntries);
    window.addEventListener('focus', loadRecentEntries);
    window.setInterval(loadRecentEntries, 15000);
});
</script>

<?php require_once '../includes/footer.php'; ?>
