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

function parking_report_date($value)
{
    $value = trim((string)$value);
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : date('Y-m-d');
}

function parking_report_query($conn, $sql, $types, array $params)
{
    $stmt = mysqli_prepare($conn, $sql);
    $bindings = [$stmt, $types];
    foreach ($params as $key => $value) {
        $params[$key] = $value;
        $bindings[] = &$params[$key];
    }
    call_user_func_array('mysqli_stmt_bind_param', $bindings);
    mysqli_stmt_execute($stmt);
    return $stmt;
}

$date_from = parking_report_date($_GET['date_from'] ?? date('Y-m-d'));
$date_to = parking_report_date($_GET['date_to'] ?? date('Y-m-d'));
if ($date_from > $date_to) {
    [$date_from, $date_to] = [$date_to, $date_from];
}
$vehicle_type_id = max(0, (int)($_GET['vehicle_type_id'] ?? 0));
$gate_id = max(0, (int)($_GET['gate_id'] ?? 0));

$ticket_filter = 't.company_id=? AND (DATE(t.entry_at) BETWEEN ? AND ? OR DATE(t.exit_at) BETWEEN ? AND ?)';
$ticket_types = 'issss';
$ticket_params = [$company_id, $date_from, $date_to, $date_from, $date_to];
if ($vehicle_type_id > 0) {
    $ticket_filter .= ' AND t.vehicle_type_id=?';
    $ticket_types .= 'i';
    $ticket_params[] = $vehicle_type_id;
}
if ($gate_id > 0) {
    $ticket_filter .= ' AND (t.entry_gate_id=? OR t.exit_gate_id=?)';
    $ticket_types .= 'ii';
    $ticket_params[] = $gate_id;
    $ticket_params[] = $gate_id;
}

$summary_sql = "SELECT
    COALESCE(SUM(DATE(t.entry_at) BETWEEN ? AND ?), 0) AS entries,
    COALESCE(SUM(DATE(t.exit_at) BETWEEN ? AND ?), 0) AS exits,
    COALESCE(SUM(t.ticket_status IN ('active','payment_pending','paid')), 0) AS currently_inside
    FROM parking_tickets t WHERE t.company_id=?";
$summary_types = 'ssssi';
$summary_params = [$date_from, $date_to, $date_from, $date_to, $company_id];
if ($vehicle_type_id > 0) {
    $summary_sql .= ' AND t.vehicle_type_id=?';
    $summary_types .= 'i';
    $summary_params[] = $vehicle_type_id;
}
if ($gate_id > 0) {
    $summary_sql .= ' AND (t.entry_gate_id=? OR t.exit_gate_id=?)';
    $summary_types .= 'ii';
    $summary_params[] = $gate_id;
    $summary_params[] = $gate_id;
}
$summary_stmt = parking_report_query($conn, $summary_sql, $summary_types, $summary_params);
$summary = mysqli_fetch_assoc(mysqli_stmt_get_result($summary_stmt)) ?: [];
mysqli_stmt_close($summary_stmt);

$payment_sql = 'SELECT COALESCE(SUM(p.amount), 0) AS collection FROM parking_payments p WHERE p.company_id=? AND DATE(p.paid_at) BETWEEN ? AND ?';
$payment_types = 'iss';
$payment_params = [$company_id, $date_from, $date_to];
if ($gate_id > 0) {
    $payment_sql .= ' AND EXISTS (SELECT 1 FROM parking_tickets pt WHERE pt.id=p.ticket_id AND (pt.entry_gate_id=? OR pt.exit_gate_id=?))';
    $payment_types .= 'ii';
    $payment_params[] = $gate_id;
    $payment_params[] = $gate_id;
}
if ($vehicle_type_id > 0) {
    $payment_sql .= ' AND EXISTS (SELECT 1 FROM parking_tickets pt WHERE pt.id=p.ticket_id AND pt.vehicle_type_id=?)';
    $payment_types .= 'i';
    $payment_params[] = $vehicle_type_id;
}
$payment_stmt = parking_report_query($conn, $payment_sql, $payment_types, $payment_params);
$collection = mysqli_fetch_assoc(mysqli_stmt_get_result($payment_stmt)) ?: [];
mysqli_stmt_close($payment_stmt);

$history_sql = "SELECT t.*, v.vehicle_name, eg.gate_name AS entry_gate_name, xg.gate_name AS exit_gate_name
    FROM parking_tickets t
    INNER JOIN parking_vehicle_types v ON v.id=t.vehicle_type_id
    LEFT JOIN parking_gates eg ON eg.id=t.entry_gate_id
    LEFT JOIN parking_gates xg ON xg.id=t.exit_gate_id
    WHERE {$ticket_filter}
    ORDER BY COALESCE(t.exit_at, t.entry_at) DESC LIMIT 500";
$history_stmt = parking_report_query($conn, $history_sql, $ticket_types, $ticket_params);
$history = mysqli_stmt_get_result($history_stmt);

$vehicle_types = mysqli_query($conn, "SELECT id, vehicle_name FROM parking_vehicle_types WHERE company_id={$company_id} ORDER BY vehicle_name ASC");
$gates = mysqli_query($conn, "SELECT id, gate_name, gate_code FROM parking_gates WHERE company_id={$company_id} ORDER BY gate_name ASC");

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3"><h4 class="mb-0"><i class="fas fa-chart-bar mr-2"></i>Parking Reports</h4><div><a href="activity.php" class="btn btn-outline-dark btn-sm mr-1"><i class="fas fa-history mr-1"></i>Activity Log</a><a href="index.php" class="btn btn-outline-secondary btn-sm">Dashboard</a></div></div>

<div class="card card-outline card-info"><div class="card-body"><form method="get" class="row align-items-end"><div class="form-group col-md-2"><label>From</label><input class="form-control" type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"></div><div class="form-group col-md-2"><label>To</label><input class="form-control" type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"></div><div class="form-group col-md-3"><label>Vehicle Type</label><select class="form-control" name="vehicle_type_id"><option value="0">All vehicle types</option><?php while ($vehicle = mysqli_fetch_assoc($vehicle_types)) { ?><option value="<?= (int)$vehicle['id'] ?>" <?= (int)$vehicle['id'] === $vehicle_type_id ? 'selected' : '' ?>><?= htmlspecialchars($vehicle['vehicle_name']) ?></option><?php } ?></select></div><div class="form-group col-md-3"><label>Gate</label><select class="form-control" name="gate_id"><option value="0">All gates</option><?php while ($gate = mysqli_fetch_assoc($gates)) { ?><option value="<?= (int)$gate['id'] ?>" <?= (int)$gate['id'] === $gate_id ? 'selected' : '' ?>><?= htmlspecialchars($gate['gate_name'] . ' (' . $gate['gate_code'] . ')') ?></option><?php } ?></select></div><div class="form-group col-md-2"><button class="btn btn-info btn-block"><i class="fas fa-filter mr-1"></i>Filter</button></div></form></div></div>

<div class="row"><div class="col-lg-3 col-6"><div class="small-box bg-info"><div class="inner"><h3><?= (int)($summary['entries'] ?? 0) ?></h3><p>Entries</p></div><div class="icon"><i class="fas fa-car"></i></div></div></div><div class="col-lg-3 col-6"><div class="small-box bg-success"><div class="inner"><h3><?= (int)($summary['exits'] ?? 0) ?></h3><p>Exits</p></div><div class="icon"><i class="fas fa-sign-out-alt"></i></div></div></div><div class="col-lg-3 col-6"><div class="small-box bg-warning"><div class="inner"><h3><?= (int)($summary['currently_inside'] ?? 0) ?></h3><p>Currently Inside</p></div><div class="icon"><i class="fas fa-parking"></i></div></div></div><div class="col-lg-3 col-6"><div class="small-box bg-primary"><div class="inner"><h3>BDT <?= number_format((float)($collection['collection'] ?? 0), 2) ?></h3><p>Collection</p></div><div class="icon"><i class="fas fa-wallet"></i></div></div></div></div>

<div class="card card-outline card-primary"><div class="card-header"><h3 class="card-title">Ticket History <small class="text-muted">(maximum 500 records)</small></h3><div class="card-tools"><a class="btn btn-success btn-sm" href="export_report.php?<?= htmlspecialchars(http_build_query(['date_from'=>$date_from,'date_to'=>$date_to,'vehicle_type_id'=>$vehicle_type_id,'gate_id'=>$gate_id])) ?>"><i class="fas fa-file-csv mr-1"></i>Export CSV</a></div></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-bordered table-hover mb-0"><thead><tr><th>Ticket</th><th>Vehicle</th><th>Number</th><th>Entry</th><th>Exit</th><th>Gates</th><th>Duration</th><th>Fee</th><th>Status</th></tr></thead><tbody><?php if (mysqli_num_rows($history) === 0) { ?><tr><td colspan="9" class="text-center text-muted py-4">No parking ticket found for this filter.</td></tr><?php } ?><?php while ($ticket = mysqli_fetch_assoc($history)) { ?><tr><td><?= htmlspecialchars($ticket['ticket_no']) ?></td><td><?= htmlspecialchars($ticket['vehicle_name']) ?></td><td><?= htmlspecialchars($ticket['vehicle_number'] ?: '-') ?></td><td><?= htmlspecialchars(date('d M Y, h:i A', strtotime($ticket['entry_at']))) ?></td><td><?= $ticket['exit_at'] ? htmlspecialchars(date('d M Y, h:i A', strtotime($ticket['exit_at']))) : '-' ?></td><td><?= htmlspecialchars(($ticket['entry_gate_name'] ?: '-') . ' → ' . ($ticket['exit_gate_name'] ?: '-')) ?></td><td><?= $ticket['exit_at'] ? (int)$ticket['duration_minutes'] . ' min' : '-' ?></td><td>BDT <?= number_format((float)$ticket['paid_amount'], 2) ?></td><td><span class="badge badge-<?= $ticket['ticket_status'] === 'exited' ? 'success' : 'warning' ?>"><?= htmlspecialchars($ticket['ticket_status']) ?></span></td></tr><?php } ?></tbody></table></div></div></div>

<?php mysqli_stmt_close($history_stmt); require_once '../includes/footer.php'; ?>
