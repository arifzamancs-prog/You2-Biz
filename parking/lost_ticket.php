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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_lost_exit') {
    if (!hash_equals((string)($_SESSION['parking_lost_ticket_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
        parking_lost_redirect('lost_ticket.php', 'Invalid request. Please try again.');
    }
    $ticket_id = (int)($_POST['ticket_id'] ?? 0);
    $exit_gate_id = (int)($_POST['exit_gate_id'] ?? 0);
    $wallet_id = (int)($_POST['wallet_id'] ?? 0);
    $payment_method = (string)($_POST['payment_method'] ?? 'cash');
    $reference = trim((string)($_POST['payment_reference'] ?? ''));
    $note = trim((string)($_POST['payment_note'] ?? ''));
    if ($ticket_id <= 0 || $exit_gate_id <= 0 || !in_array($payment_method, ['cash', 'card', 'bkash', 'nagad', 'bank', 'wallet', 'other'], true)) {
        parking_lost_redirect('lost_ticket.php', 'Ticket, exit gate and payment method are required.');
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
            $wallet_stmt = mysqli_prepare($conn, "SELECT id FROM wallets WHERE id=? AND user_id=? AND branch_id=? AND status='active' LIMIT 1");
            mysqli_stmt_bind_param($wallet_stmt, 'iii', $wallet_id, $company_id, $branch_id);
            mysqli_stmt_execute($wallet_stmt);
            $wallet = mysqli_fetch_assoc(mysqli_stmt_get_result($wallet_stmt));
            mysqli_stmt_close($wallet_stmt);
            if (!$wallet) throw new Exception('Select an active wallet from this ticket branch.');
            $payment_stmt = mysqli_prepare($conn, 'INSERT INTO parking_payments (company_id, branch_id, ticket_id, wallet_id, received_by, payment_method, amount, payment_reference, payment_note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $lost_note = trim('Lost ticket. ' . $note);
            mysqli_stmt_bind_param($payment_stmt, 'iiiiisdss', $company_id, $branch_id, $ticket_id, $wallet_id, $operator_id, $payment_method, $fee, $reference, $lost_note);
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
$wallets = mysqli_query($conn, "SELECT id, wallet_name FROM wallets WHERE user_id={$company_id} AND status='active' ORDER BY is_system DESC, wallet_name ASC");

require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<?php if ($flash) { ?><div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible"><button type="button" class="close" data-dismiss="alert">&times;</button><?= htmlspecialchars($flash['message']) ?></div><?php } ?>
<div class="card card-outline card-warning"><div class="card-header"><h3 class="card-title"><i class="fas fa-exclamation-triangle mr-2"></i>Lost Ticket Exit</h3><div class="card-tools"><a href="exit.php" class="btn btn-outline-secondary btn-sm">Normal Exit</a></div></div><div class="card-body"><p class="text-muted">Find the active vehicle by its registered vehicle number, then confirm the fixed lost-ticket fee.</p><form method="get"><div class="input-group"><input class="form-control form-control-lg" name="vehicle" value="<?= htmlspecialchars($vehicle_lookup) ?>" placeholder="Enter vehicle number" required autofocus><div class="input-group-append"><button class="btn btn-warning">Find Vehicle</button></div></div></form></div></div>
<?php if ($matches !== null) { ?><div class="card card-outline card-danger"><div class="card-header"><h3 class="card-title">Matching Active Tickets</h3></div><div class="card-body"><p>Configured lost-ticket fee: <strong>BDT <?= number_format((float)($settings['lost_ticket_fee'] ?? 0), 2) ?></strong></p><?php if (mysqli_num_rows($matches) === 0) { ?><p class="text-muted mb-0">No active ticket found for this vehicle number.</p><?php } ?><?php while ($ticket = mysqli_fetch_assoc($matches)) { ?><form method="post" class="border rounded p-3 mb-3"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['parking_lost_ticket_csrf']) ?>"><input type="hidden" name="action" value="confirm_lost_exit"><input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>"><input type="hidden" name="vehicle_lookup" value="<?= htmlspecialchars($vehicle_lookup) ?>"><div class="row"><div class="col-md-5"><strong><?= htmlspecialchars($ticket['ticket_no']) ?></strong><br><?= htmlspecialchars($ticket['vehicle_name']) ?> — <?= htmlspecialchars($ticket['vehicle_number']) ?><br><small class="text-muted">Entry: <?= htmlspecialchars(date('d M Y, h:i A', strtotime($ticket['entry_at']))) ?> · <?= htmlspecialchars($ticket['gate_name'] ?: '-') ?></small></div><div class="col-md-3 form-group"><label>Exit Gate</label><select class="form-control" name="exit_gate_id" required><option value="">Select</option><?php mysqli_data_seek($exit_gates, 0); while ($gate = mysqli_fetch_assoc($exit_gates)) { ?><option value="<?= (int)$gate['id'] ?>"><?= htmlspecialchars($gate['gate_name'] . ' (' . $gate['gate_code'] . ')') ?></option><?php } ?></select></div><div class="col-md-2 form-group"><label>Wallet</label><select class="form-control" name="wallet_id"><option value="">Select</option><?php mysqli_data_seek($wallets, 0); while ($wallet = mysqli_fetch_assoc($wallets)) { ?><option value="<?= (int)$wallet['id'] ?>"><?= htmlspecialchars($wallet['wallet_name']) ?></option><?php } ?></select></div><div class="col-md-2 form-group"><label>Method</label><select class="form-control" name="payment_method"><option value="cash">Cash</option><option value="card">Card</option><option value="bkash">bKash</option><option value="nagad">Nagad</option><option value="bank">Bank</option><option value="other">Other</option></select></div><div class="col-md-6 form-group mb-0"><label>Reference</label><input class="form-control" name="payment_reference" maxlength="150"></div><div class="col-md-6 form-group mb-0"><label>Note</label><input class="form-control" name="payment_note" maxlength="500" placeholder="Optional verification note"></div></div><div class="text-right mt-3"><button class="btn btn-danger" onclick="return confirm('Confirm lost-ticket exit?')">Confirm Lost Ticket Exit</button></div></form><?php } ?></div></div><?php } ?>
<?php if ($matches instanceof mysqli_result) mysqli_free_result($matches); require_once '../includes/footer.php'; ?>
