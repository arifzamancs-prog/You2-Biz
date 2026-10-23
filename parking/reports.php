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

$history_limit = 100;
$history_maximum = 100;
$history_count_sql = "SELECT COUNT(*) AS total FROM parking_tickets t WHERE {$ticket_filter}";
$history_count_stmt = parking_report_query($conn, $history_count_sql, $ticket_types, $ticket_params);
$history_count = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($history_count_stmt))['total'] ?? 0);
mysqli_stmt_close($history_count_stmt);
$history_total = min($history_count, $history_maximum);
$history_pages = max(1, (int)ceil($history_total / $history_limit));
$history_page = max(1, (int)($_GET['page'] ?? 1));
$history_page = min($history_page, $history_pages);
$history_offset = ($history_page - 1) * $history_limit;

$history_sql = "SELECT t.*, v.vehicle_name, eg.gate_name AS entry_gate_name, xg.gate_name AS exit_gate_name
    FROM parking_tickets t
    INNER JOIN parking_vehicle_types v ON v.id=t.vehicle_type_id
    LEFT JOIN parking_gates eg ON eg.id=t.entry_gate_id
    LEFT JOIN parking_gates xg ON xg.id=t.exit_gate_id
    WHERE {$ticket_filter}
    ORDER BY COALESCE(t.exit_at, t.entry_at) DESC LIMIT {$history_limit} OFFSET {$history_offset}";
$history_stmt = parking_report_query($conn, $history_sql, $ticket_types, $ticket_params);
$history_result = mysqli_stmt_get_result($history_stmt);
$history_rows = [];
while ($ticket = mysqli_fetch_assoc($history_result)) {
    $history_rows[] = $ticket;
}
mysqli_stmt_close($history_stmt);

function parking_report_history_html(array $history_rows, $history_total, $history_offset, $history_limit, $history_page, $history_pages, array $history_query)
{
    ob_start(); ?>
    <div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-bordered table-hover mb-0"><thead><tr><th>Ticket</th><th>Vehicle</th><th>Number</th><th>Entry</th><th>Exit</th><th>Gates</th><th>Duration</th><th>Fee</th><th>Status</th></tr></thead><tbody><?php if (!$history_rows) { ?><tr><td colspan="9" class="text-center text-muted py-4">No parking ticket found for this filter.</td></tr><?php } ?><?php foreach ($history_rows as $ticket) { ?><tr><td><?= htmlspecialchars($ticket['ticket_no']) ?></td><td><?= htmlspecialchars($ticket['vehicle_name']) ?></td><td><?= htmlspecialchars($ticket['vehicle_number'] ?: '-') ?></td><td><?= htmlspecialchars(date('d M Y, h:i A', strtotime($ticket['entry_at']))) ?></td><td><?= $ticket['exit_at'] ? htmlspecialchars(date('d M Y, h:i A', strtotime($ticket['exit_at']))) : '-' ?></td><td><?= htmlspecialchars(($ticket['entry_gate_name'] ?: '-') . ' → ' . ($ticket['exit_gate_name'] ?: '-')) ?></td><td><?= $ticket['exit_at'] ? (int)$ticket['duration_minutes'] . ' min' : '-' ?></td><td>BDT <?= number_format((float)$ticket['paid_amount'], 2) ?></td><td><span class="badge badge-<?= $ticket['ticket_status'] === 'exited' ? 'success' : 'warning' ?>"><?= htmlspecialchars($ticket['ticket_status']) ?></span></td></tr><?php } ?></tbody></table></div></div><?php $history_first = $history_total ? $history_offset + 1 : 0; $history_last = min($history_offset + $history_limit, $history_total); ?><div class="card-footer d-flex justify-content-between align-items-center"><span class="text-muted">Showing <?= $history_first ?> to <?= $history_last ?> of <?= $history_total ?> record<?= $history_total === 1 ? '' : 's' ?></span><div><?php if ($history_page > 1) { ?><a class="btn btn-outline-secondary btn-sm" href="reports.php?<?= htmlspecialchars(http_build_query(array_merge($history_query, ['page' => $history_page - 1]))) ?>">Previous</a><?php } ?><span class="btn btn-primary btn-sm disabled mx-1">Page <?= $history_page ?> / <?= $history_pages ?></span><?php if ($history_page < $history_pages) { ?><a class="btn btn-outline-primary btn-sm" href="reports.php?<?= htmlspecialchars(http_build_query(array_merge($history_query, ['page' => $history_page + 1]))) ?>">Next 100</a><?php } ?></div></div>
    <?php return trim(ob_get_clean());
}

$history_query = ['date_from'=>$date_from,'date_to'=>$date_to,'vehicle_type_id'=>$vehicle_type_id,'gate_id'=>$gate_id];
$history_html = parking_report_history_html($history_rows, $history_total, $history_offset, $history_limit, $history_page, $history_pages, $history_query);

if (($_GET['ajax'] ?? '') === 'ticket_history') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['html' => $history_html], JSON_UNESCAPED_UNICODE);
    exit;
}

$vehicle_types = mysqli_query($conn, "SELECT id, vehicle_name FROM parking_vehicle_types WHERE company_id={$company_id} ORDER BY vehicle_name ASC");
$gates = mysqli_query($conn, "SELECT id, gate_name, gate_code FROM parking_gates WHERE company_id={$company_id} ORDER BY gate_name ASC");

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<style>.parking-report-status{font-size:.9rem}.parking-report-dot{display:inline-block;width:12px;height:12px;border-radius:50%;background:#28a745;box-shadow:0 0 0 3px rgba(40,167,69,.15);vertical-align:-1px}.parking-report-status.offline .parking-report-dot{background:#dc3545;box-shadow:0 0 0 3px rgba(220,53,69,.15)}</style>
<div class="d-flex justify-content-between align-items-center mb-3"><h4 class="mb-0"><i class="fas fa-chart-bar mr-2"></i>Parking Reports</h4><div><span id="parking-report-status" class="parking-report-status text-success mr-2"><span class="parking-report-dot mr-1"></span>Live</span><a href="activity.php" class="btn btn-outline-dark btn-sm mr-1"><i class="fas fa-history mr-1"></i>Activity Log</a><a href="index.php" class="btn btn-outline-secondary btn-sm">Dashboard</a></div></div>

<div class="card card-outline card-info"><div class="card-body"><form id="parking-report-filter" method="get" class="row align-items-end"><div class="form-group col-md-2"><label>From</label><input class="form-control" type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"></div><div class="form-group col-md-2"><label>To</label><input class="form-control" type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"></div><div class="form-group col-md-3"><label>Vehicle Type</label><select class="form-control" name="vehicle_type_id"><option value="0">All vehicle types</option><?php while ($vehicle = mysqli_fetch_assoc($vehicle_types)) { ?><option value="<?= (int)$vehicle['id'] ?>" <?= (int)$vehicle['id'] === $vehicle_type_id ? 'selected' : '' ?>><?= htmlspecialchars($vehicle['vehicle_name']) ?></option><?php } ?></select></div><div class="form-group col-md-3"><label>Gate</label><select class="form-control" name="gate_id"><option value="0">All gates</option><?php while ($gate = mysqli_fetch_assoc($gates)) { ?><option value="<?= (int)$gate['id'] ?>" <?= (int)$gate['id'] === $gate_id ? 'selected' : '' ?>><?= htmlspecialchars($gate['gate_name'] . ' (' . $gate['gate_code'] . ')') ?></option><?php } ?></select></div><div class="form-group col-md-2"><button class="btn btn-info btn-block"><i class="fas fa-filter mr-1"></i>Filter</button></div></form></div></div>

<div class="card card-outline card-primary"><div class="card-header"><h3 class="card-title">Ticket History <small class="text-muted">(maximum 100 records)</small></h3><div class="card-tools"><a class="btn btn-success btn-sm" href="export_report.php?<?= htmlspecialchars(http_build_query($history_query)) ?>"><i class="fas fa-file-csv mr-1"></i>Export CSV</a></div></div><div id="parking-report-history"><?= $history_html ?></div></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('parking-report-filter'), history = document.getElementById('parking-report-history'), status = document.getElementById('parking-report-status');
    function setStatus(online) { status.classList.toggle('offline', !online); status.classList.toggle('text-success', online); status.classList.toggle('text-danger', !online); status.innerHTML = '<span class="parking-report-dot mr-1"></span>' + (online ? 'Live' : 'Offline'); }
    async function loadHistory() { try { const query = new URLSearchParams(new FormData(form)); query.set('ajax', 'ticket_history'); const response = await fetch('reports.php?' + query.toString(), {headers:{'X-Requested-With':'XMLHttpRequest'}}); if (!response.ok) throw new Error(); const data = await response.json(); history.innerHTML = data.html; setStatus(true); } catch (error) { setStatus(false); } }
    form.addEventListener('submit', function (event) { event.preventDefault(); loadHistory(); });
    window.addEventListener('online', loadHistory); window.addEventListener('offline', () => setStatus(false));
    setInterval(() => { if (navigator.onLine) loadHistory(); else setStatus(false); }, 15000);
});
</script>

<?php require_once '../includes/footer.php'; ?>
