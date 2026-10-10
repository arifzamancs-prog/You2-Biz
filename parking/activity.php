<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/parking_helper.php';

require_admin_user();
$company_id = (int)($_SESSION['user_id'] ?? 0);
if (!parking_company_enabled($conn, $company_id)) {
    header('Location: ' . app_path('dashboard.php?error=Parking Management is not enabled for this company.'));
    exit;
}
parking_ensure_company_defaults($conn, $company_id);
$date = trim((string)($_GET['date'] ?? date('Y-m-d')));
$parsed = DateTime::createFromFormat('Y-m-d', $date);
if (!$parsed || $parsed->format('Y-m-d') !== $date) $date = date('Y-m-d');
$event_type = trim((string)($_GET['event_type'] ?? ''));
$allowed_events = ['entry_created', 'barcode_scanned', 'exit_confirmed', 'print', 'barrier_opened', 'override'];
if ($event_type !== '' && !in_array($event_type, $allowed_events, true)) $event_type = '';
$sql = "SELECT l.*, g.gate_name, t.ticket_no, t.vehicle_number, u.name AS operator_name, u.username AS operator_username
        FROM parking_gate_logs l
        LEFT JOIN parking_gates g ON g.id=l.gate_id
        LEFT JOIN parking_tickets t ON t.id=l.ticket_id
        LEFT JOIN users u ON u.id=l.operator_id
        WHERE l.company_id=? AND DATE(l.created_at)=?";
$types = 'is'; $params = [$company_id, $date];
if ($event_type !== '') { $sql .= ' AND l.event_type=?'; $types .= 's'; $params[] = $event_type; }
$sql .= ' ORDER BY l.id DESC LIMIT 500';
$stmt = mysqli_prepare($conn, $sql);
if ($event_type === '') mysqli_stmt_bind_param($stmt, 'is', $company_id, $date); else mysqli_stmt_bind_param($stmt, 'iss', $company_id, $date, $event_type);
mysqli_stmt_execute($stmt); $logs = mysqli_stmt_get_result($stmt);
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3"><h4 class="mb-0"><i class="fas fa-history mr-2"></i>Parking Activity Log</h4><a href="reports.php" class="btn btn-outline-secondary btn-sm">Reports</a></div>
<div class="card card-outline card-dark"><div class="card-body"><form method="get" class="row align-items-end"><div class="col-md-4 form-group"><label>Date</label><input class="form-control" type="date" name="date" value="<?= htmlspecialchars($date) ?>"></div><div class="col-md-4 form-group"><label>Event</label><select class="form-control" name="event_type"><option value="">All events</option><?php foreach($allowed_events as $event){ ?><option value="<?= htmlspecialchars($event) ?>" <?= $event===$event_type?'selected':'' ?>><?= htmlspecialchars(ucwords(str_replace('_',' ',$event))) ?></option><?php } ?></select></div><div class="col-md-4 form-group"><button class="btn btn-dark btn-block"><i class="fas fa-filter mr-1"></i>Filter</button></div></form></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-bordered table-hover mb-0"><thead><tr><th>Time</th><th>Event</th><th>Ticket / Vehicle</th><th>Gate</th><th>Operator</th><th>Details</th></tr></thead><tbody><?php if(mysqli_num_rows($logs)===0){ ?><tr><td colspan="6" class="text-center text-muted py-4">No activity found for this filter.</td></tr><?php } ?><?php while($log=mysqli_fetch_assoc($logs)){ ?><tr><td><?= htmlspecialchars(date('d M Y, h:i:s A',strtotime($log['created_at']))) ?></td><td><span class="badge badge-<?= $log['event_type']==='override'?'danger':($log['event_type']==='exit_confirmed'?'success':'info') ?>"><?= htmlspecialchars(ucwords(str_replace('_',' ',$log['event_type']))) ?></span></td><td><?= htmlspecialchars($log['ticket_no'] ?: '-') ?><?= $log['vehicle_number'] ? '<br><small>'.htmlspecialchars($log['vehicle_number']).'</small>' : '' ?></td><td><?= htmlspecialchars($log['gate_name'] ?: '-') ?></td><td><?= htmlspecialchars($log['operator_name'] ?: $log['operator_username'] ?: '-') ?></td><td><?= htmlspecialchars($log['event_message']) ?></td></tr><?php } ?></tbody></table></div></div></div>
<?php mysqli_stmt_close($stmt); require_once '../includes/footer.php'; ?>
