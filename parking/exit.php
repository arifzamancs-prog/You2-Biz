<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/parking_helper.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/transaction_helper.php';

require_admin_user();
// Older parking records use 0 for Head Office; wallets use its actual branch ID.
function parking_exit_wallet_branches($conn, $company_id, $branch_id)
{
    $company_id = (int)$company_id;
    $branch_id = (int)$branch_id;
    $result = mysqli_query($conn, "SELECT id FROM branches WHERE user_id={$company_id} AND is_head_office=1");
    $head_office_ids = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $head_office_ids[] = (int)$row['id'];
    }
    // Old parking tickets may store Head Office as 0. Prefer the real Head
    // Office branch wallet when it exists, instead of showing duplicate Cash
    // wallets from both the legacy and current branch records.
    if (in_array($branch_id, array_merge([0], $head_office_ids), true)) {
        return implode(',', $head_office_ids ?: [0]);
    }
    return (string)$branch_id;
}

function parking_exit_payment_method_from_wallet($wallet_name)
{
    $wallet_name = strtolower(trim((string)$wallet_name));
    foreach (['bkash', 'nagad', 'card', 'bank'] as $method) {
        if (strpos($wallet_name, $method) !== false) return $method;
    }
    return strpos($wallet_name, 'cash') !== false ? 'cash' : 'wallet';
}
$company_id = (int)($_SESSION['user_id'] ?? 0);
if (!parking_company_enabled($conn, $company_id)) {
    header('Location: ' . app_path('dashboard.php?error=Parking Management is not enabled for this company.'));
    exit;
}
parking_ensure_company_defaults($conn, $company_id);
if (empty($_SESSION['parking_exit_csrf'])) {
    $_SESSION['parking_exit_csrf'] = bin2hex(random_bytes(32));
}

function parking_exit_redirect($location, $message = '', $type = 'danger')
{
    if ($message !== '') {
        $_SESSION['parking_exit_flash'] = ['message' => $message, 'type' => $type];
    }
    header('Location: ' . $location);
    exit;
}

function parking_exit_recent_exits($conn, $company_id)
{
    $stmt = mysqli_prepare($conn, "SELECT t.id, t.ticket_no, t.vehicle_number, t.exit_at, t.duration_minutes, t.paid_amount, t.ticket_status, v.vehicle_name, g.gate_name FROM parking_tickets t INNER JOIN parking_vehicle_types v ON v.id=t.vehicle_type_id LEFT JOIN parking_gates g ON g.id=t.exit_gate_id WHERE t.company_id=? AND t.ticket_status IN ('exited','lost') ORDER BY t.exit_at DESC, t.id DESC LIMIT 10");
    mysqli_stmt_bind_param($stmt, 'i', $company_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $exits = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $exits[] = [
            'ticket_no' => (string)$row['ticket_no'],
            'lost' => $row['ticket_status'] === 'lost',
            'vehicle_name' => (string)$row['vehicle_name'],
            'vehicle_number' => (string)($row['vehicle_number'] ?: '—'),
            'gate_name' => (string)($row['gate_name'] ?: '—'),
            'exit_time' => $row['exit_at'] ? date('d M Y, h:i A', strtotime((string)$row['exit_at'])) : '—',
            'duration' => (int)$row['duration_minutes'] . ' min',
            'paid' => 'BDT ' . number_format((float)$row['paid_amount'], 2),
        ];
    }
    mysqli_stmt_close($stmt);
    return $exits;
}

if (($_GET['ajax'] ?? '') === 'recent_exits') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'exits' => parking_exit_recent_exits($conn, $company_id)]);
    exit;
}

function parking_exit_load_ticket($conn, $company_id, $ticket_id)
{
    $stmt = mysqli_prepare($conn, "SELECT t.*, v.vehicle_name, eg.gate_name AS entry_gate_name, r.plan_name FROM parking_tickets t INNER JOIN parking_vehicle_types v ON v.id=t.vehicle_type_id LEFT JOIN parking_gates eg ON eg.id=t.entry_gate_id LEFT JOIN parking_rate_plans r ON r.id=t.rate_plan_id WHERE t.id=? AND t.company_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $ticket_id, $company_id);
    mysqli_stmt_execute($stmt);
    $ticket = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $ticket ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)($_SESSION['parking_exit_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
        parking_exit_redirect('exit.php', 'Invalid request. Please try again.');
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'scan') {
        $barcode = strtoupper(trim((string)($_POST['barcode_value'] ?? '')));
        if ($barcode === '') {
            parking_exit_redirect('exit.php', 'Scan or enter a barcode first.');
        }
        $stmt = mysqli_prepare($conn, 'SELECT id FROM parking_tickets WHERE company_id=? AND barcode_value=? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'is', $company_id, $barcode);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$row) {
            parking_exit_redirect('exit.php', 'No parking ticket was found for this barcode.');
        }
        parking_exit_redirect('exit.php?ticket=' . (int)$row['id']);
    }

    if ($action === 'confirm_exit') {
        $ticket_id = (int)($_POST['ticket_id'] ?? 0);
        $exit_gate_id = (int)($_POST['exit_gate_id'] ?? 0);
        $wallet_id = (int)($_POST['wallet_id'] ?? 0);
        $payment_note = trim((string)($_POST['payment_note'] ?? ''));
        if ($ticket_id <= 0 || $exit_gate_id <= 0) {
            parking_exit_redirect('exit.php', 'Select an exit gate.');
        }

        mysqli_begin_transaction($conn);
        try {
            $ticket_stmt = mysqli_prepare($conn, 'SELECT * FROM parking_tickets WHERE id=? AND company_id=? FOR UPDATE');
            mysqli_stmt_bind_param($ticket_stmt, 'ii', $ticket_id, $company_id);
            mysqli_stmt_execute($ticket_stmt);
            $ticket = mysqli_fetch_assoc(mysqli_stmt_get_result($ticket_stmt));
            mysqli_stmt_close($ticket_stmt);
            if (!$ticket || !in_array($ticket['ticket_status'], ['active', 'payment_pending', 'paid'], true)) {
                throw new Exception('This ticket is no longer available for exit.');
            }

            $gate_stmt = mysqli_prepare($conn, "SELECT id, branch_id, gate_name FROM parking_gates WHERE id=? AND company_id=? AND status='active' AND gate_type IN ('exit','both') LIMIT 1");
            mysqli_stmt_bind_param($gate_stmt, 'ii', $exit_gate_id, $company_id);
            mysqli_stmt_execute($gate_stmt);
            $exit_gate = mysqli_fetch_assoc(mysqli_stmt_get_result($gate_stmt));
            mysqli_stmt_close($gate_stmt);
            if (!$exit_gate) {
                throw new Exception('Select an active exit gate.');
            }
            if (!parking_operator_can_use_gate($conn, $company_id, $exit_gate_id, 'exit')) {
                throw new Exception('You are not assigned to this exit gate.');
            }
            if ((int)$exit_gate['branch_id'] !== (int)$ticket['branch_id']) {
                throw new Exception('The selected exit gate belongs to a different branch.');
            }

            $settings_stmt = mysqli_prepare($conn, 'SELECT payment_mode, grace_minutes, exit_print_enabled FROM parking_settings WHERE company_id=? LIMIT 1');
            mysqli_stmt_bind_param($settings_stmt, 'i', $company_id);
            mysqli_stmt_execute($settings_stmt);
            $settings = mysqli_fetch_assoc(mysqli_stmt_get_result($settings_stmt)) ?: [];
            mysqli_stmt_close($settings_stmt);
            $calculation = parking_calculate_ticket_fee($ticket, (int)($settings['grace_minutes'] ?? 0));
            $prepaid_ticket = ($settings['payment_mode'] ?? '') === 'prepaid_fixed' && $ticket['payment_status'] === 'paid';
            $fee = $prepaid_ticket ? 0.00 : (float)$calculation['fee'];
            $is_free = $prepaid_ticket || ($settings['payment_mode'] ?? '') === 'free' || $fee <= 0;
            $operator_id = (int)($_SESSION['login_user_id'] ?? 0);

            if (!$is_free) {
                if ($wallet_id <= 0) {
                    throw new Exception('Select the wallet where this payment was received.');
                }
                $ticket_branch_id = (int)$ticket['branch_id'];
                $wallet_branches = parking_exit_wallet_branches($conn, $company_id, $ticket_branch_id);
                $wallet_stmt = mysqli_prepare($conn, "SELECT id, wallet_name FROM wallets WHERE id=? AND user_id=? AND COALESCE(branch_id,0) IN ({$wallet_branches}) AND status='active' LIMIT 1");
                mysqli_stmt_bind_param($wallet_stmt, 'ii', $wallet_id, $company_id);
                mysqli_stmt_execute($wallet_stmt);
                $wallet = mysqli_fetch_assoc(mysqli_stmt_get_result($wallet_stmt));
                mysqli_stmt_close($wallet_stmt);
                if (!$wallet) {
                    throw new Exception('Select an active wallet from this ticket branch.');
                }
                $payment_method = parking_exit_payment_method_from_wallet($wallet['wallet_name'] ?? '');
                $payment_reference = '';

                $payment_stmt = mysqli_prepare($conn, 'INSERT INTO parking_payments (company_id, branch_id, ticket_id, wallet_id, received_by, payment_method, amount, payment_reference, payment_note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $ticket_branch_id = (int)$ticket['branch_id'];
                mysqli_stmt_bind_param($payment_stmt, 'iiiiisdss', $company_id, $ticket_branch_id, $ticket_id, $wallet_id, $operator_id, $payment_method, $fee, $payment_reference, $payment_note);
                mysqli_stmt_execute($payment_stmt);
                $payment_id = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($payment_stmt);

                credit_wallet($conn, $wallet_id, $company_id, $fee);
$txn_no = generate_short_unique_txn_no($conn, 'PARK');
                record_wallet_transaction($conn, $txn_no, $company_id, $wallet_id, 'money_in', $payment_id, $fee, 'Parking payment: ' . $ticket['ticket_no'], date('Y-m-d'), $ticket_branch_id);
            } else {
                $wallet_id = 0;
            }

            $paid_amount = $prepaid_ticket ? (float)$ticket['paid_amount'] : ($is_free ? 0 : $fee);
            $payment_status = $prepaid_ticket ? 'paid' : ($is_free ? 'waived' : 'paid');
            $update_stmt = mysqli_prepare($conn, "UPDATE parking_tickets SET exit_gate_id=?, exit_operator_id=?, exit_at=NOW(), duration_minutes=?, calculated_fee=?, paid_amount=?, payment_status=?, ticket_status='exited' WHERE id=? AND company_id=?");
            mysqli_stmt_bind_param($update_stmt, 'iiiddsii', $exit_gate_id, $operator_id, $calculation['duration_minutes'], $fee, $paid_amount, $payment_status, $ticket_id, $company_id);
            mysqli_stmt_execute($update_stmt);
            mysqli_stmt_close($update_stmt);

            if ((int)($ticket['slot_id'] ?? 0) > 0) {
                $slot_release_stmt = mysqli_prepare($conn, "UPDATE parking_slots SET status='available' WHERE id=? AND company_id=? AND status='occupied'");
                $ticket_slot_id = (int)$ticket['slot_id'];
                mysqli_stmt_bind_param($slot_release_stmt, 'ii', $ticket_slot_id, $company_id);
                mysqli_stmt_execute($slot_release_stmt);
                mysqli_stmt_close($slot_release_stmt);
            }

            $log_stmt = mysqli_prepare($conn, "INSERT INTO parking_gate_logs (company_id, branch_id, gate_id, ticket_id, operator_id, event_type, event_message) VALUES (?, ?, ?, ?, ?, 'exit_confirmed', ?)");
            $log_message = 'Exit confirmed at ' . $exit_gate['gate_name'];
            $ticket_branch_id = (int)$ticket['branch_id'];
            mysqli_stmt_bind_param($log_stmt, 'iiiiis', $company_id, $ticket_branch_id, $exit_gate_id, $ticket_id, $operator_id, $log_message);
            mysqli_stmt_execute($log_stmt);
            mysqli_stmt_close($log_stmt);
            mysqli_commit($conn);
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            parking_exit_redirect('exit.php?ticket=' . $ticket_id, $exception->getMessage());
        }

        $next = !empty($settings['exit_print_enabled']) ? 'print_exit.php?id=' . $ticket_id : 'exit.php';
        parking_exit_redirect($next, 'Exit confirmed successfully.', 'success');
    }
}

$flash = $_SESSION['parking_exit_flash'] ?? null;
unset($_SESSION['parking_exit_flash']);
$ticket = null;
$calculation = null;
if (isset($_GET['ticket'])) {
    $ticket = parking_exit_load_ticket($conn, $company_id, (int)$_GET['ticket']);
    if (!$ticket) {
        $flash = ['message' => 'Ticket not found.', 'type' => 'danger'];
    } elseif ($ticket['ticket_status'] === 'exited') {
        $flash = ['message' => 'This ticket has already exited.', 'type' => 'warning'];
        $ticket = null;
    } elseif (!in_array($ticket['ticket_status'], ['active', 'payment_pending', 'paid'], true)) {
        $flash = ['message' => 'This ticket cannot be exited in its current status.', 'type' => 'danger'];
        $ticket = null;
    } else {
        $settings_stmt = mysqli_prepare($conn, 'SELECT * FROM parking_settings WHERE company_id=? LIMIT 1');
        mysqli_stmt_bind_param($settings_stmt, 'i', $company_id);
        mysqli_stmt_execute($settings_stmt);
        $settings = mysqli_fetch_assoc(mysqli_stmt_get_result($settings_stmt)) ?: [];
        mysqli_stmt_close($settings_stmt);
        $calculation = parking_calculate_ticket_fee($ticket, (int)($settings['grace_minutes'] ?? 0));
        if (($settings['payment_mode'] ?? '') === 'prepaid_fixed' && $ticket['payment_status'] === 'paid') {
            $calculation['fee'] = 0.00;
        }
    }
}
$exit_gate_filter = parking_operator_gate_filter($conn, $company_id, 'exit', 'id');
$exit_gates = mysqli_query($conn, "SELECT id, gate_name, gate_code FROM parking_gates WHERE company_id={$company_id} AND status='active' AND gate_type IN ('exit','both'){$exit_gate_filter} ORDER BY gate_name ASC");
$wallet_branches = parking_exit_wallet_branches($conn, $company_id, (int)($ticket['branch_id'] ?? 0));
$wallets = mysqli_query($conn, "SELECT id, wallet_name, branch_id FROM wallets WHERE user_id={$company_id} AND COALESCE(branch_id,0) IN ({$wallet_branches}) AND status='active' ORDER BY is_system DESC, wallet_name ASC");
$recent_exits = parking_exit_recent_exits($conn, $company_id);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<?php if ($flash) { ?><div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible"><button type="button" class="close" data-dismiss="alert">&times;</button><?= htmlspecialchars($flash['message']) ?></div><?php } ?>
<div class="card card-outline card-warning"><div class="card-header"><h3 class="card-title"><i class="fas fa-sign-out-alt mr-2"></i>Vehicle Exit</h3></div><div class="card-body"><form method="post" autocomplete="off" id="scan-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['parking_exit_csrf']) ?>"><input type="hidden" name="action" value="scan"><div class="row align-items-end"><div class="col-md-8 form-group mb-md-0"><label>Scan Barcode</label><input class="form-control form-control-lg" name="barcode_value" id="scan-barcode" placeholder="Scan ticket barcode here" autofocus required></div><div class="col-md-4"><button class="btn btn-warning btn-lg btn-block"><i class="fas fa-search mr-1"></i>Find Ticket</button></div></div></form></div></div>

<?php if ($ticket && $calculation) { ?>
<div class="card card-outline card-success"><div class="card-header"><h3 class="card-title">Ticket: <?= htmlspecialchars($ticket['ticket_no']) ?></h3></div><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['parking_exit_csrf']) ?>"><input type="hidden" name="action" value="confirm_exit"><input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>"><div class="card-body row"><div class="col-md-6"><table class="table table-sm table-bordered"><tr><th>Vehicle</th><td><?= htmlspecialchars($ticket['vehicle_name']) ?></td></tr><tr><th>Vehicle Number</th><td><?= htmlspecialchars($ticket['vehicle_number'] ?: 'N/A') ?></td></tr><tr><th>Entry</th><td><?= htmlspecialchars(date('d M Y, h:i A', strtotime($ticket['entry_at']))) ?></td></tr><tr><th>Duration</th><td><?= (int)$calculation['duration_minutes'] ?> minutes</td></tr><tr><th>Rate Plan</th><td><?= htmlspecialchars($ticket['plan_name'] ?: '-') ?></td></tr></table></div><div class="col-md-6"><div class="callout callout-success"><h4>Payable: BDT <?= number_format((float)$calculation['fee'], 2) ?></h4><p class="mb-0">Fee is calculated from the saved entry-time rate.</p></div><div class="form-group"><label>Exit Gate</label><select class="form-control" name="exit_gate_id" required><option value="">Select exit gate</option><?php while ($gate = mysqli_fetch_assoc($exit_gates)) { ?><option value="<?= (int)$gate['id'] ?>"><?= htmlspecialchars($gate['gate_name'] . ' (' . $gate['gate_code'] . ')') ?></option><?php } ?></select></div><div class="form-group"><label>Receive Wallet <?= (float)$calculation['fee'] <= 0 ? '(not required for free exit)' : '' ?></label><select class="form-control" name="wallet_id"><option value="">Select wallet</option><?php while ($wallet = mysqli_fetch_assoc($wallets)) { ?><option value="<?= (int)$wallet['id'] ?>" data-branch="<?= (int)$wallet['branch_id'] ?>"><?= htmlspecialchars($wallet['wallet_name']) ?></option><?php } ?></select></div><div class="form-group"><label>Note</label><input class="form-control" name="payment_note" maxlength="500" placeholder="Optional"></div></div></div><div class="card-footer text-right"><button class="btn btn-success btn-lg"><i class="fas fa-check-circle mr-1"></i>Confirm Exit</button></div></form></div>
<?php } ?>

<div class="card card-outline card-info">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-history mr-2"></i>Last 10 Activities</h3><div class="card-tools"><button type="button" class="btn btn-sm btn-outline-primary" id="refresh-recent-exits"><i class="fas fa-sync-alt mr-1"></i>Refresh</button></div></div>
    <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Ticket</th><th>Vehicle</th><th>Vehicle Number</th><th>Exit Gate</th><th>Exit Time</th><th>Duration</th><th class="text-right">Paid</th></tr></thead><tbody id="recent-exit-rows">
        <?php if (!$recent_exits) { ?><tr><td colspan="7" class="text-center text-muted py-4">No vehicle exit found yet.</td></tr><?php } ?>
        <?php foreach ($recent_exits as $recent_exit) { ?><tr><td><?= htmlspecialchars($recent_exit['ticket_no']) ?><?php if ($recent_exit['lost']) { ?> <span class="badge badge-danger">Lost</span><?php } ?></td><td><?= htmlspecialchars($recent_exit['vehicle_name']) ?></td><td><?= htmlspecialchars($recent_exit['vehicle_number']) ?></td><td><?= htmlspecialchars($recent_exit['gate_name']) ?></td><td><?= htmlspecialchars($recent_exit['exit_time']) ?></td><td><?= htmlspecialchars($recent_exit['duration']) ?></td><td class="text-right"><?= htmlspecialchars($recent_exit['paid']) ?></td></tr><?php } ?>
    </tbody></table></div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const exitGate = document.querySelector('select[name="exit_gate_id"]');
    const receiveWallet = document.querySelector('select[name="wallet_id"]');
    const exitPreferenceKey = 'parking_exit_preferences_<?= (int)$company_id ?>';
    function applySavedValue(select, value) {
        if (!select || !value) return;
        Array.from(select.options).some(function (option) {
            if (option.value !== value) return false;
            select.value = value;
            return true;
        });
    }
    function saveExitPreferences() {
        try {
            localStorage.setItem(exitPreferenceKey, JSON.stringify({
                exit_gate: exitGate ? exitGate.value : '',
                wallet: receiveWallet ? receiveWallet.value : ''
            }));
        } catch (error) {
            // Payment and exit still work if browser storage is unavailable.
        }
    }
    try {
        const saved = JSON.parse(localStorage.getItem(exitPreferenceKey) || '{}');
        applySavedValue(exitGate, saved.exit_gate);
        applySavedValue(receiveWallet, saved.wallet);
    } catch (error) {}
    if (exitGate) exitGate.addEventListener('change', saveExitPreferences);
    if (receiveWallet) receiveWallet.addEventListener('change', saveExitPreferences);

    const exitRows = document.getElementById('recent-exit-rows');
    const refreshExits = document.getElementById('refresh-recent-exits');
    function exitCell(row, value, className) { const item = document.createElement('td'); item.textContent = value || '—'; if (className) item.className = className; row.appendChild(item); return item; }
    function renderExits(exits) {
        exitRows.replaceChildren();
        if (!exits.length) { const row = document.createElement('tr'); const item = exitCell(row, 'No vehicle exit found yet.', 'text-center text-muted py-4'); item.colSpan = 7; exitRows.appendChild(row); return; }
        exits.forEach(function (exit) {
            const row = document.createElement('tr');
            const ticket = exitCell(row, exit.ticket_no);
            if (exit.lost) { const badge = document.createElement('span'); badge.className = 'badge badge-danger'; badge.textContent = 'Lost'; ticket.append(' ', badge); }
            exitCell(row, exit.vehicle_name); exitCell(row, exit.vehicle_number); exitCell(row, exit.gate_name); exitCell(row, exit.exit_time); exitCell(row, exit.duration); exitCell(row, exit.paid, 'text-right');
            exitRows.appendChild(row);
        });
    }
    async function loadRecentExits() { try { const response = await fetch('exit.php?ajax=recent_exits', {credentials: 'same-origin', cache: 'no-store'}); const data = await response.json(); if (response.ok && data.ok) renderExits(data.exits || []); } catch (error) {} }
    refreshExits.addEventListener('click', loadRecentExits);
    window.addEventListener('focus', loadRecentExits);
    window.setInterval(loadRecentExits, 15000);

    // Barcode readers type the whole code in a fast burst. Catch it wherever the focus is
    // and find the ticket as soon as the burst ends, even when the reader sends no Enter.
    const scanForm = document.getElementById('scan-form');
    const scanInput = document.getElementById('scan-barcode');
    const SCAN_KEY_GAP_MS = 50;
    const SCAN_IDLE_MS = 150;
    const SCAN_MIN_LENGTH = 4;
    let lastKeyAt = 0;
    let fastKeys = 0;
    let idleTimer = null;
    let submitting = false;

    function isOtherField(element) {
        if (!element || element === scanInput) return false;
        return element.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(element.tagName);
    }
    function submitScan() {
        if (submitting || scanInput.value.trim() === '') return;
        submitting = true;
        scanForm.submit();
    }
    document.addEventListener('keydown', function (event) {
        if (event.ctrlKey || event.altKey || event.metaKey || isOtherField(document.activeElement)) return;
        const now = Date.now();
        if (event.key === 'Enter') {
            if (document.activeElement === scanInput || fastKeys >= SCAN_MIN_LENGTH - 1) {
                event.preventDefault();
                submitScan();
            }
            return;
        }
        if (event.key.length !== 1) return;
        fastKeys = now - lastKeyAt <= SCAN_KEY_GAP_MS ? fastKeys + 1 : 0;
        lastKeyAt = now;
        if (document.activeElement !== scanInput) {
            // Start a fresh code in the scan box when typing begins outside it.
            event.preventDefault();
            if (fastKeys === 0) scanInput.value = '';
            scanInput.value += event.key;
            scanInput.focus();
        }
        clearTimeout(idleTimer);
        idleTimer = setTimeout(function () {
            if (fastKeys >= SCAN_MIN_LENGTH - 1) submitScan();
            fastKeys = 0;
        }, SCAN_IDLE_MS);
    });
    scanForm.addEventListener('submit', function () { submitting = true; });
    if (!document.querySelector('select[name="exit_gate_id"]')) scanInput.focus();
});
</script>

<?php require_once '../includes/footer.php'; ?>
