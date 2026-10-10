<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/parking_helper.php';

require_admin_user();
$company_id = (int)($_SESSION['user_id'] ?? 0);
$operator_id = (int)($_SESSION['login_user_id'] ?? 0);
if (!parking_company_enabled($conn, $company_id)) {
    header('Location: ' . app_path('dashboard.php?error=Parking Management is not enabled for this company.'));
    exit;
}
parking_ensure_company_defaults($conn, $company_id);
if (empty($_SESSION['parking_shift_csrf'])) $_SESSION['parking_shift_csrf'] = bin2hex(random_bytes(32));

function parking_shift_redirect($message, $type = 'success')
{
    $_SESSION['parking_shift_flash'] = ['message' => $message, 'type' => $type];
    header('Location: shift.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)($_SESSION['parking_shift_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) parking_shift_redirect('Invalid request. Please try again.', 'danger');
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'open') {
        $gate_id = (int)($_POST['gate_id'] ?? 0);
        $opening_cash = max(0, (float)($_POST['opening_cash'] ?? 0));
        $note = trim((string)($_POST['note'] ?? ''));
        if ($gate_id <= 0) parking_shift_redirect('Select a gate terminal.', 'danger');
        $gate_stmt = mysqli_prepare($conn, "SELECT branch_id FROM parking_gates WHERE id=? AND company_id=? AND status='active' LIMIT 1");
        mysqli_stmt_bind_param($gate_stmt, 'ii', $gate_id, $company_id); mysqli_stmt_execute($gate_stmt); $gate = mysqli_fetch_assoc(mysqli_stmt_get_result($gate_stmt)); mysqli_stmt_close($gate_stmt);
        if (!$gate || (!parking_operator_can_use_gate($conn, $company_id, $gate_id, 'entry') && !parking_operator_can_use_gate($conn, $company_id, $gate_id, 'exit'))) parking_shift_redirect('You are not assigned to this gate terminal.', 'danger');
        $active_stmt = mysqli_prepare($conn, "SELECT id FROM parking_shifts WHERE company_id=? AND operator_id=? AND status='open' LIMIT 1");
        mysqli_stmt_bind_param($active_stmt, 'ii', $company_id, $operator_id); mysqli_stmt_execute($active_stmt); $active = mysqli_fetch_assoc(mysqli_stmt_get_result($active_stmt)); mysqli_stmt_close($active_stmt);
        if ($active) parking_shift_redirect('Close your current active shift before opening another one.', 'danger');
        $branch_id = (int)$gate['branch_id'];
        $stmt = mysqli_prepare($conn, 'INSERT INTO parking_shifts (company_id, branch_id, gate_id, operator_id, opening_cash, note) VALUES (?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'iiiids', $company_id, $branch_id, $gate_id, $operator_id, $opening_cash, $note); mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);
        parking_shift_redirect('Parking shift opened successfully.');
    }
    if ($action === 'close') {
        $shift_id = (int)($_POST['shift_id'] ?? 0);
        $closing_cash = max(0, (float)($_POST['closing_cash'] ?? 0));
        $note = trim((string)($_POST['note'] ?? ''));
        mysqli_begin_transaction($conn);
        try {
            $shift_stmt = mysqli_prepare($conn, "SELECT * FROM parking_shifts WHERE id=? AND company_id=? AND operator_id=? AND status='open' FOR UPDATE");
            mysqli_stmt_bind_param($shift_stmt, 'iii', $shift_id, $company_id, $operator_id); mysqli_stmt_execute($shift_stmt); $shift = mysqli_fetch_assoc(mysqli_stmt_get_result($shift_stmt)); mysqli_stmt_close($shift_stmt);
            if (!$shift) throw new Exception('Active shift not found.');
            $cash_stmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(amount),0) AS total FROM parking_payments WHERE company_id=? AND received_by=? AND payment_method='cash' AND paid_at>=? AND paid_at<=NOW()");
            mysqli_stmt_bind_param($cash_stmt, 'iis', $company_id, $operator_id, $shift['opened_at']); mysqli_stmt_execute($cash_stmt); $cash_collected = (float)(mysqli_fetch_assoc(mysqli_stmt_get_result($cash_stmt))['total'] ?? 0); mysqli_stmt_close($cash_stmt);
            $expected_cash = (float)$shift['opening_cash'] + $cash_collected;
            $difference = $closing_cash - $expected_cash;
            $update_stmt = mysqli_prepare($conn, "UPDATE parking_shifts SET expected_cash=?, closing_cash=?, cash_difference=?, status='closed', closed_at=NOW(), note=? WHERE id=? AND company_id=?");
            mysqli_stmt_bind_param($update_stmt, 'dddsii', $expected_cash, $closing_cash, $difference, $note, $shift_id, $company_id); mysqli_stmt_execute($update_stmt); mysqli_stmt_close($update_stmt);
            mysqli_commit($conn);
        } catch (Throwable $e) { mysqli_rollback($conn); parking_shift_redirect($e->getMessage(), 'danger'); }
        parking_shift_redirect('Parking shift closed and cash reconciled.');
    }
}

$flash = $_SESSION['parking_shift_flash'] ?? null; unset($_SESSION['parking_shift_flash']);
$active_stmt = mysqli_prepare($conn, "SELECT s.*, g.gate_name, g.gate_code FROM parking_shifts s INNER JOIN parking_gates g ON g.id=s.gate_id WHERE s.company_id=? AND s.operator_id=? AND s.status='open' LIMIT 1");
mysqli_stmt_bind_param($active_stmt, 'ii', $company_id, $operator_id); mysqli_stmt_execute($active_stmt); $active_shift = mysqli_fetch_assoc(mysqli_stmt_get_result($active_stmt)); mysqli_stmt_close($active_stmt);
$gate_filter = parking_operator_gate_filter($conn, $company_id, 'both', 'id');
$gates = mysqli_query($conn, "SELECT id, gate_name, gate_code FROM parking_gates WHERE company_id={$company_id} AND status='active'{$gate_filter} ORDER BY gate_name ASC");
$history_stmt = mysqli_prepare($conn, "SELECT s.*, g.gate_name FROM parking_shifts s INNER JOIN parking_gates g ON g.id=s.gate_id WHERE s.company_id=? AND s.operator_id=? ORDER BY s.id DESC LIMIT 20"); mysqli_stmt_bind_param($history_stmt, 'ii', $company_id, $operator_id); mysqli_stmt_execute($history_stmt); $history = mysqli_stmt_get_result($history_stmt);
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<?php if($flash){ ?><div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible"><button type="button" class="close" data-dismiss="alert">&times;</button><?= htmlspecialchars($flash['message']) ?></div><?php } ?>
<div class="d-flex justify-content-between align-items-center mb-3"><h4 class="mb-0"><i class="fas fa-user-clock mr-2"></i>Parking Shift</h4><a href="index.php" class="btn btn-outline-secondary btn-sm">Dashboard</a></div>
<?php if($active_shift){ ?><div class="card card-outline card-success"><div class="card-header"><h3 class="card-title">Active Shift: <?= htmlspecialchars($active_shift['gate_name'].' ('.$active_shift['gate_code'].')') ?></h3></div><div class="card-body"><div class="row"><div class="col-md-4"><strong>Opened</strong><br><?= htmlspecialchars(date('d M Y, h:i A', strtotime($active_shift['opened_at']))) ?></div><div class="col-md-4"><strong>Opening Cash</strong><br>BDT <?= number_format((float)$active_shift['opening_cash'],2) ?></div></div><hr><form method="post" class="row align-items-end"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['parking_shift_csrf']) ?>"><input type="hidden" name="action" value="close"><input type="hidden" name="shift_id" value="<?= (int)$active_shift['id'] ?>"><div class="col-md-4 form-group"><label>Closing Cash</label><input class="form-control" type="number" step="0.01" min="0" name="closing_cash" required></div><div class="col-md-5 form-group"><label>Closing Note</label><input class="form-control" name="note" maxlength="500" placeholder="Optional"></div><div class="col-md-3 form-group"><button class="btn btn-danger btn-block">Close & Reconcile</button></div></form></div></div><?php } else { ?><div class="card card-outline card-primary"><div class="card-header"><h3 class="card-title">Open New Shift</h3></div><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['parking_shift_csrf']) ?>"><input type="hidden" name="action" value="open"><div class="card-body row"><div class="col-md-4 form-group"><label>Gate Terminal</label><select class="form-control" name="gate_id" required><option value="">Select gate</option><?php while($gate=mysqli_fetch_assoc($gates)){ ?><option value="<?= (int)$gate['id'] ?>"><?= htmlspecialchars($gate['gate_name'].' ('.$gate['gate_code'].')') ?></option><?php } ?></select></div><div class="col-md-4 form-group"><label>Opening Cash</label><input class="form-control" type="number" step="0.01" min="0" name="opening_cash" value="0"></div><div class="col-md-4 form-group"><label>Opening Note</label><input class="form-control" name="note" maxlength="500" placeholder="Optional"></div></div><div class="card-footer text-right"><button class="btn btn-primary">Open Shift</button></div></form></div><?php } ?>
<div class="card card-outline card-secondary"><div class="card-header"><h3 class="card-title">Recent Shift History</h3></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>Gate</th><th>Opened</th><th>Closed</th><th>Expected Cash</th><th>Closing Cash</th><th>Difference</th><th>Status</th></tr></thead><tbody><?php if(mysqli_num_rows($history)===0){ ?><tr><td colspan="7" class="text-center text-muted py-3">No shift history found.</td></tr><?php } ?><?php while($shift=mysqli_fetch_assoc($history)){ ?><tr><td><?= htmlspecialchars($shift['gate_name']) ?></td><td><?= htmlspecialchars(date('d M, h:i A',strtotime($shift['opened_at']))) ?></td><td><?= $shift['closed_at']?htmlspecialchars(date('d M, h:i A',strtotime($shift['closed_at']))):'-' ?></td><td>BDT <?= number_format((float)$shift['expected_cash'],2) ?></td><td><?= $shift['closing_cash']!==null?'BDT '.number_format((float)$shift['closing_cash'],2):'-' ?></td><td><?= $shift['cash_difference']!==null?'BDT '.number_format((float)$shift['cash_difference'],2):'-' ?></td><td><span class="badge badge-<?= $shift['status']==='open'?'warning':'success' ?>"><?= htmlspecialchars($shift['status']) ?></span></td></tr><?php } ?></tbody></table></div></div></div>
<?php mysqli_stmt_close($history_stmt); require_once '../includes/footer.php'; ?>
