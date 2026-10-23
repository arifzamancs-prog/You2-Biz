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
if (empty($_SESSION['parking_lost_ticket_csrf'])) $_SESSION['parking_lost_ticket_csrf'] = bin2hex(random_bytes(32));

function parking_lost_redirect($location, $message = '', $type = 'danger')
{
    if ($message !== '') $_SESSION['parking_lost_ticket_flash'] = ['message' => $message, 'type' => $type];
    header('Location: ' . $location);
    exit;
}

function parking_lost_wallet_branches($conn, $company_id, $branch_id)
{
    $result = mysqli_query($conn, "SELECT id FROM branches WHERE user_id=" . (int)$company_id . " AND is_head_office=1");
    $head_office_ids = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $head_office_ids[] = (int)$row['id'];
    }
    if (in_array((int)$branch_id, array_merge([0], $head_office_ids), true)) {
        return implode(',', $head_office_ids ?: [0]);
    }
    return (string)(int)$branch_id;
}

function parking_lost_payment_method_from_wallet($wallet_name)
{
    $wallet_name = strtolower(trim((string)$wallet_name));
    foreach (['bkash', 'nagad', 'card', 'bank'] as $method) {
        if (strpos($wallet_name, $method) !== false) return $method;
    }
    return strpos($wallet_name, 'cash') !== false ? 'cash' : 'wallet';
}

function parking_lost_wallets_for_branch($conn, $company_id, $branch_id)
{
    $branch_ids = parking_lost_wallet_branches($conn, $company_id, $branch_id);
    $result = mysqli_query($conn, "SELECT id, wallet_name FROM wallets WHERE user_id=" . (int)$company_id . " AND COALESCE(branch_id,0) IN ({$branch_ids}) AND status='active' ORDER BY is_system DESC, wallet_name ASC");
    $wallets = [];
    while ($wallet = mysqli_fetch_assoc($result)) {
        $wallet_key = strtolower(trim((string)$wallet['wallet_name']));
        if (isset($wallets[$wallet_key])) continue;
        $wallets[$wallet_key] = $wallet;
    }
    return array_values($wallets);
}

function parking_lost_recent_entries($conn, $company_id)
{
    $entries = [];
    $stmt = mysqli_prepare($conn, "SELECT t.id, t.ticket_no, t.vehicle_number, t.entry_at, v.vehicle_name, g.gate_name FROM parking_tickets t INNER JOIN parking_vehicle_types v ON v.id=t.vehicle_type_id LEFT JOIN parking_gates g ON g.id=t.entry_gate_id WHERE t.company_id=? AND t.ticket_status IN ('active','payment_pending','paid') ORDER BY t.entry_at DESC, t.id DESC LIMIT 10");
    mysqli_stmt_bind_param($stmt, 'i', $company_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $entries[] = [
            'ticket_no' => (string)$row['ticket_no'],
            'vehicle_name' => (string)$row['vehicle_name'],
            'vehicle_number' => (string)($row['vehicle_number'] ?: ''),
            'gate_name' => (string)($row['gate_name'] ?: '—'),
            'entry_time' => date('d M Y, h:i A', strtotime((string)$row['entry_at'])),
        ];
    }
    mysqli_stmt_close($stmt);
    return $entries;
}

if (($_GET['ajax'] ?? '') === 'recent_entries') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'entries' => parking_lost_recent_entries($conn, $company_id)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_lost_exit') {
    if (!hash_equals((string)($_SESSION['parking_lost_ticket_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
        parking_lost_redirect('lost_ticket.php', 'Invalid request. Please try again.');
    }
    $ticket_id = (int)($_POST['ticket_id'] ?? 0);
    $exit_gate_id = (int)($_POST['exit_gate_id'] ?? 0);
    $wallet_id = (int)($_POST['wallet_id'] ?? 0);
    $note = trim((string)($_POST['payment_note'] ?? ''));
    if ($ticket_id <= 0 || $exit_gate_id <= 0) {
        parking_lost_redirect('lost_ticket.php', 'Ticket and exit gate are required.');
    }

    mysqli_begin_transaction($conn);
    try {
        $ticket_stmt = mysqli_prepare($conn, "SELECT * FROM parking_tickets WHERE id=? AND company_id=? AND ticket_status IN ('active','payment_pending','paid') FOR UPDATE");
        mysqli_stmt_bind_param($ticket_stmt, 'ii', $ticket_id, $company_id);
        mysqli_stmt_execute($ticket_stmt);
        $ticket = mysqli_fetch_assoc(mysqli_stmt_get_result($ticket_stmt));
        mysqli_stmt_close($ticket_stmt);
        if (!$ticket) throw new Exception('This ticket is not available for lost-ticket exit.');

        $gate_stmt = mysqli_prepare($conn, "SELECT id, branch_id, gate_name FROM parking_gates WHERE id=? AND company_id=? AND status='active' AND gate_type IN ('exit','both') LIMIT 1");
        mysqli_stmt_bind_param($gate_stmt, 'ii', $exit_gate_id, $company_id);
        mysqli_stmt_execute($gate_stmt);
        $gate = mysqli_fetch_assoc(mysqli_stmt_get_result($gate_stmt));
        mysqli_stmt_close($gate_stmt);
        if (!$gate || (int)$gate['branch_id'] !== (int)$ticket['branch_id']) throw new Exception('Select an active exit gate from the ticket branch.');
        if (!parking_operator_can_use_gate($conn, $company_id, $exit_gate_id, 'exit')) throw new Exception('You are not assigned to this exit gate.');

        $settings_stmt = mysqli_prepare($conn, 'SELECT lost_ticket_fee, exit_print_enabled FROM parking_settings WHERE company_id=? LIMIT 1');
        mysqli_stmt_bind_param($settings_stmt, 'i', $company_id);
        mysqli_stmt_execute($settings_stmt);
        $settings = mysqli_fetch_assoc(mysqli_stmt_get_result($settings_stmt)) ?: [];
        mysqli_stmt_close($settings_stmt);
        $fee = max(0, (float)($settings['lost_ticket_fee'] ?? 0));
        $operator_id = (int)($_SESSION['login_user_id'] ?? 0);
        $branch_id = (int)$ticket['branch_id'];
        if ($fee > 0) {
            $wallet_branches = parking_lost_wallet_branches($conn, $company_id, $branch_id);
            $wallet_stmt = mysqli_prepare($conn, "SELECT id, wallet_name FROM wallets WHERE id=? AND user_id=? AND COALESCE(branch_id,0) IN ({$wallet_branches}) AND status='active' LIMIT 1");
            mysqli_stmt_bind_param($wallet_stmt, 'ii', $wallet_id, $company_id);
            mysqli_stmt_execute($wallet_stmt);
            $wallet = mysqli_fetch_assoc(mysqli_stmt_get_result($wallet_stmt));
            mysqli_stmt_close($wallet_stmt);
            if (!$wallet) throw new Exception('Select an active wallet from this ticket branch.');
            $payment_method = parking_lost_payment_method_from_wallet($wallet['wallet_name'] ?? '');
            $payment_stmt = mysqli_prepare($conn, 'INSERT INTO parking_payments (company_id, branch_id, ticket_id, wallet_id, received_by, payment_method, amount, payment_reference, payment_note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $lost_note = trim('Lost ticket. ' . $note);
            $payment_reference = '';
            mysqli_stmt_bind_param($payment_stmt, 'iiiiisdss', $company_id, $branch_id, $ticket_id, $wallet_id, $operator_id, $payment_method, $fee, $payment_reference, $lost_note);
            mysqli_stmt_execute($payment_stmt);
            $payment_id = (int)mysqli_insert_id($conn);
            mysqli_stmt_close($payment_stmt);
            credit_wallet($conn, $wallet_id, $company_id, $fee);
            record_wallet_transaction($conn, generate_short_unique_txn_no($conn, 'PARK'), $company_id, $wallet_id, 'money_in', $payment_id, $fee, 'Lost parking ticket: ' . $ticket['ticket_no'], date('Y-m-d'), $branch_id);
        }

        $duration = max(0, (int)ceil((time() - strtotime($ticket['entry_at'])) / 60));
        $ticket_note = trim($ticket['notes'] . ' Lost ticket exit. ' . $note);
        $payment_status = $fee > 0 ? 'paid' : 'waived';
        $update_stmt = mysqli_prepare($conn, "UPDATE parking_tickets SET exit_gate_id=?, exit_operator_id=?, exit_at=NOW(), duration_minutes=?, calculated_fee=?, paid_amount=?, payment_status=?, ticket_status='lost', notes=? WHERE id=? AND company_id=?");
        mysqli_stmt_bind_param($update_stmt, 'iiiddssii', $exit_gate_id, $operator_id, $duration, $fee, $fee, $payment_status, $ticket_note, $ticket_id, $company_id);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
        if ((int)($ticket['slot_id'] ?? 0) > 0) {
            $slot_release_stmt = mysqli_prepare($conn, "UPDATE parking_slots SET status='available' WHERE id=? AND company_id=? AND status='occupied'");
            $ticket_slot_id = (int)$ticket['slot_id'];
            mysqli_stmt_bind_param($slot_release_stmt, 'ii', $ticket_slot_id, $company_id);
            mysqli_stmt_execute($slot_release_stmt);
            mysqli_stmt_close($slot_release_stmt);
        }
        $log_stmt = mysqli_prepare($conn, "INSERT INTO parking_gate_logs (company_id, branch_id, gate_id, ticket_id, operator_id, event_type, event_message) VALUES (?, ?, ?, ?, ?, 'override', ?)");
        $log_message = 'Lost ticket exit confirmed at ' . $gate['gate_name'];
        mysqli_stmt_bind_param($log_stmt, 'iiiiis', $company_id, $branch_id, $exit_gate_id, $ticket_id, $operator_id, $log_message);
        mysqli_stmt_execute($log_stmt);
        mysqli_stmt_close($log_stmt);
        mysqli_commit($conn);
    } catch (Throwable $exception) {
        mysqli_rollback($conn);
        parking_lost_redirect('lost_ticket.php?vehicle=' . urlencode((string)($_POST['vehicle_lookup'] ?? '')), $exception->getMessage());
    }
    parking_lost_redirect(!empty($settings['exit_print_enabled']) ? 'print_exit.php?id=' . $ticket_id : 'lost_ticket.php', 'Lost-ticket exit confirmed.', 'success');
}

$flash = $_SESSION['parking_lost_ticket_flash'] ?? null;
unset($_SESSION['parking_lost_ticket_flash']);
$vehicle_lookup = strtoupper(trim((string)($_GET['vehicle'] ?? '')));
$matches = null;
if ($vehicle_lookup !== '') {
    $stmt = mysqli_prepare($conn, "SELECT t.*, v.vehicle_name, g.gate_name FROM parking_tickets t INNER JOIN parking_vehicle_types v ON v.id=t.vehicle_type_id LEFT JOIN parking_gates g ON g.id=t.entry_gate_id WHERE t.company_id=? AND t.vehicle_number=? AND t.ticket_status IN ('active','payment_pending','paid') ORDER BY t.entry_at DESC");
    mysqli_stmt_bind_param($stmt, 'is', $company_id, $vehicle_lookup);
    mysqli_stmt_execute($stmt);
    $matches = mysqli_stmt_get_result($stmt);
}
$settings_stmt = mysqli_prepare($conn, 'SELECT lost_ticket_fee FROM parking_settings WHERE company_id=? LIMIT 1');
mysqli_stmt_bind_param($settings_stmt, 'i', $company_id);
mysqli_stmt_execute($settings_stmt);
$settings = mysqli_fetch_assoc(mysqli_stmt_get_result($settings_stmt)) ?: [];
mysqli_stmt_close($settings_stmt);
$exit_gate_filter = parking_operator_gate_filter($conn, $company_id, 'exit', 'id');
$exit_gates = mysqli_query($conn, "SELECT id, gate_name, gate_code FROM parking_gates WHERE company_id={$company_id} AND status='active' AND gate_type IN ('exit','both'){$exit_gate_filter} ORDER BY gate_name ASC");
$recent_entries = parking_lost_recent_entries($conn, $company_id);

require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<?php if ($flash) { ?><div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible"><button type="button" class="close" data-dismiss="alert">&times;</button><?= htmlspecialchars($flash['message']) ?></div><?php } ?>
<div class="card card-outline card-warning"><div class="card-header"><h3 class="card-title"><i class="fas fa-exclamation-triangle mr-2"></i>Lost Ticket Exit</h3><div class="card-tools"><a href="exit.php" class="btn btn-outline-secondary btn-sm">Normal Exit</a></div></div><div class="card-body"><p class="text-muted">Find the active vehicle by its registered vehicle number, then confirm the fixed lost-ticket fee.</p><form method="get"><div class="input-group"><input class="form-control form-control-lg" name="vehicle" value="<?= htmlspecialchars($vehicle_lookup) ?>" placeholder="Enter vehicle number" required autofocus><div class="input-group-append"><button class="btn btn-warning">Find Vehicle</button></div></div></form></div></div>
<div class="card card-outline card-info">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-history mr-2"></i>Last 10 Active Entries</h3><div class="card-tools"><button type="button" class="btn btn-sm btn-outline-primary" id="refresh-recent-entries"><i class="fas fa-sync-alt mr-1"></i>Refresh</button></div></div>
    <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Ticket</th><th>Vehicle</th><th>Vehicle Number</th><th>Entry Gate</th><th>Entry Time</th><th></th></tr></thead><tbody id="recent-entry-rows">
        <?php if (!$recent_entries) { ?><tr><td colspan="6" class="text-center text-muted py-4">No active parking entry found.</td></tr><?php } else { foreach ($recent_entries as $recent_entry) { ?><tr><td><?= htmlspecialchars($recent_entry['ticket_no']) ?></td><td><?= htmlspecialchars($recent_entry['vehicle_name']) ?></td><td><?= htmlspecialchars($recent_entry['vehicle_number'] ?: '—') ?></td><td><?= htmlspecialchars($recent_entry['gate_name']) ?></td><td><?= htmlspecialchars($recent_entry['entry_time']) ?></td><td class="text-right"><?php if ($recent_entry['vehicle_number'] !== '') { ?><a class="btn btn-sm btn-danger" href="lost_ticket.php?vehicle=<?= urlencode($recent_entry['vehicle_number']) ?>">Select</a><?php } ?></td></tr><?php } } ?>
    </tbody></table></div></div>
</div>
<?php if ($matches !== null) { ?><div class="card card-outline card-danger"><div class="card-header"><h3 class="card-title">Matching Active Tickets</h3></div><div class="card-body"><p>Configured lost-ticket fee: <strong>BDT <?= number_format((float)($settings['lost_ticket_fee'] ?? 0), 2) ?></strong></p><?php if (mysqli_num_rows($matches) === 0) { ?><p class="text-muted mb-0">No active ticket found for this vehicle number.</p><?php } ?><?php while ($ticket = mysqli_fetch_assoc($matches)) { $ticket_wallets = parking_lost_wallets_for_branch($conn, $company_id, (int)$ticket['branch_id']); ?><form method="post" class="border rounded p-3 mb-3"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['parking_lost_ticket_csrf']) ?>"><input type="hidden" name="action" value="confirm_lost_exit"><input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>"><input type="hidden" name="vehicle_lookup" value="<?= htmlspecialchars($vehicle_lookup) ?>"><div class="row"><div class="col-md-5"><strong><?= htmlspecialchars($ticket['ticket_no']) ?></strong><br><?= htmlspecialchars($ticket['vehicle_name']) ?> — <?= htmlspecialchars($ticket['vehicle_number']) ?><br><small class="text-muted">Entry: <?= htmlspecialchars(date('d M Y, h:i A', strtotime($ticket['entry_at']))) ?> · <?= htmlspecialchars($ticket['gate_name'] ?: '-') ?></small></div><div class="col-md-3 form-group"><label>Exit Gate</label><select class="form-control" name="exit_gate_id" required><option value="">Select</option><?php mysqli_data_seek($exit_gates, 0); while ($gate = mysqli_fetch_assoc($exit_gates)) { ?><option value="<?= (int)$gate['id'] ?>"><?= htmlspecialchars($gate['gate_name'] . ' (' . $gate['gate_code'] . ')') ?></option><?php } ?></select></div><div class="col-md-4 form-group"><label>Wallet</label><select class="form-control" name="wallet_id"><option value="">Select</option><?php foreach ($ticket_wallets as $wallet) { ?><option value="<?= (int)$wallet['id'] ?>"><?= htmlspecialchars($wallet['wallet_name']) ?></option><?php } ?></select></div><div class="col-md-12 form-group mb-0"><label>Note</label><input class="form-control" name="payment_note" maxlength="500" placeholder="Optional verification note"></div></div><div class="text-right mt-3"><button class="btn btn-danger" onclick="return confirm('Confirm lost-ticket exit?')">Confirm Lost Ticket Exit</button></div></form><?php } ?></div></div><?php } ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const lostTicketPreferenceKey = 'parking_lost_ticket_preferences_<?= (int)$company_id ?>';
    const exitGates = document.querySelectorAll('select[name="exit_gate_id"]');
    const wallets = document.querySelectorAll('select[name="wallet_id"]');
    function applySavedValue(select, value) {
        if (!value) return;
        Array.from(select.options).some(function (option) {
            if (option.value !== value) return false;
            select.value = value;
            return true;
        });
    }
    function saveLostTicketPreferences() {
        try {
            localStorage.setItem(lostTicketPreferenceKey, JSON.stringify({
                exit_gate: exitGates[0] ? exitGates[0].value : '',
                wallet: wallets[0] ? wallets[0].value : ''
            }));
        } catch (error) {
            // The lost-ticket form still works when browser storage is unavailable.
        }
    }
    try {
        const saved = JSON.parse(localStorage.getItem(lostTicketPreferenceKey) || '{}');
        exitGates.forEach(function (select) { applySavedValue(select, saved.exit_gate); });
        wallets.forEach(function (select) { applySavedValue(select, saved.wallet); });
    } catch (error) {}
    exitGates.forEach(function (select) { select.addEventListener('change', saveLostTicketPreferences); });
    wallets.forEach(function (select) { select.addEventListener('change', saveLostTicketPreferences); });

    const rows = document.getElementById('recent-entry-rows');
    const refresh = document.getElementById('refresh-recent-entries');
    function cell(row, value) { const item = document.createElement('td'); item.textContent = value || '—'; row.appendChild(item); }
    function render(entries) {
        rows.replaceChildren();
        if (!entries.length) { const row = document.createElement('tr'); const item = document.createElement('td'); item.colSpan = 6; item.className = 'text-center text-muted py-4'; item.textContent = 'No active parking entry found.'; row.appendChild(item); rows.appendChild(row); return; }
        entries.forEach(function (entry) { const row = document.createElement('tr'); cell(row, entry.ticket_no); cell(row, entry.vehicle_name); cell(row, entry.vehicle_number); cell(row, entry.gate_name); cell(row, entry.entry_time); const action = document.createElement('td'); action.className = 'text-right'; if (entry.vehicle_number) { const link = document.createElement('a'); link.className = 'btn btn-sm btn-danger'; link.href = 'lost_ticket.php?vehicle=' + encodeURIComponent(entry.vehicle_number); link.textContent = 'Select'; action.appendChild(link); } row.appendChild(action); rows.appendChild(row); });
    }
    async function loadRecentEntries() { try { const response = await fetch('lost_ticket.php?ajax=recent_entries', {credentials: 'same-origin', cache: 'no-store'}); const data = await response.json(); if (response.ok && data.ok) render(data.entries || []); } catch (error) {} }
    refresh.addEventListener('click', loadRecentEntries);
    window.addEventListener('focus', loadRecentEntries);
    window.setInterval(loadRecentEntries, 15000);
});
</script>
<?php if ($matches instanceof mysqli_result) mysqli_free_result($matches); require_once '../includes/footer.php'; ?>
