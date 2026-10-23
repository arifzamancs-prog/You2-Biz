<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/company_settings_helper.php';
require_once '../includes/project_package_helper.php';
require_once '../includes/parking_helper.php';

$company_id = (int)($_SESSION['user_id'] ?? 0);
if ($company_id <= 0 || project_package_company_type($conn, $company_id) !== 'Car Parking') {
    header('Location: ' . app_path('dashboard.php?error=Parking Management is not enabled for this company.'));
    exit;
}

parking_ensure_company_defaults($conn, $company_id);

function parking_dashboard_total($conn, $sql, $company_id)
{
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $company_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: [];
}

$today = parking_dashboard_total(
    $conn,
    "SELECT
        COALESCE(SUM(DATE(entry_at)=CURDATE()), 0) AS entries,
        COALESCE(SUM(DATE(exit_at)=CURDATE()), 0) AS exits,
        COALESCE(SUM(ticket_status IN ('active','payment_pending','paid')), 0) AS active_vehicles
     FROM parking_tickets WHERE company_id=?",
    $company_id
);
$wallet_balance = parking_dashboard_total(
    $conn,
    "SELECT COALESCE(SUM(balance), 0) AS total_balance
     FROM wallets WHERE user_id=? AND status='active'",
    $company_id
);
$traffic_chart = [
    'labels' => [],
    'entries' => array_fill(0, 24, 0),
    'exits' => array_fill(0, 24, 0),
    'inside' => array_fill(0, 24, 0),
];
for ($hour = 0; $hour < 24; $hour++) {
    $traffic_chart['labels'][] = date('g A', strtotime(sprintf('%02d:00', $hour)));
}
$traffic_stmt = mysqli_prepare(
    $conn,
    "SELECT HOUR(entry_at) AS hour, COUNT(*) AS total, 'entry' AS traffic_type
     FROM parking_tickets WHERE company_id=? AND DATE(entry_at)=CURDATE() GROUP BY HOUR(entry_at)
     UNION ALL
     SELECT HOUR(exit_at) AS hour, COUNT(*) AS total, 'exit' AS traffic_type
     FROM parking_tickets WHERE company_id=? AND DATE(exit_at)=CURDATE() GROUP BY HOUR(exit_at)"
);
mysqli_stmt_bind_param($traffic_stmt, 'ii', $company_id, $company_id);
mysqli_stmt_execute($traffic_stmt);
$traffic_result = mysqli_stmt_get_result($traffic_stmt);
while ($traffic = mysqli_fetch_assoc($traffic_result)) {
    $hour = (int)$traffic['hour'];
    $traffic_chart[$traffic['traffic_type'] === 'entry' ? 'entries' : 'exits'][$hour] = (int)$traffic['total'];
}
mysqli_stmt_close($traffic_stmt);
$inside_traffic_stmt = mysqli_prepare(
    $conn,
    "SELECT COUNT(*) AS total FROM parking_tickets
     WHERE company_id=? AND entry_at < ? AND (exit_at IS NULL OR exit_at >= ?)"
);
$now = time();
for ($hour = 0; $hour < 24; $hour++) {
    $hour_end = strtotime(date('Y-m-d') . sprintf(' %02d:00:00', $hour + 1));
    if ($hour_end > $now) continue;
    $point_time = date('Y-m-d H:i:s', $hour_end);
    mysqli_stmt_bind_param($inside_traffic_stmt, 'iss', $company_id, $point_time, $point_time);
    mysqli_stmt_execute($inside_traffic_stmt);
    $traffic_chart['inside'][$hour] = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($inside_traffic_stmt))['total'] ?? 0);
}
mysqli_stmt_close($inside_traffic_stmt);
if (($_GET['ajax'] ?? '') === 'dashboard_stats') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'entries' => (int)($today['entries'] ?? 0),
        'exits' => (int)($today['exits'] ?? 0),
        'inside' => (int)($today['active_vehicles'] ?? 0),
        'wallet_balance' => number_format((float)($wallet_balance['total_balance'] ?? 0), 2),
        'traffic' => $traffic_chart,
    ]);
    exit;
}
$gate_summary = mysqli_prepare(
    $conn,
    "SELECT g.gate_name, g.gate_type,
        COALESCE(SUM(DATE(t.entry_at)=CURDATE() AND t.entry_gate_id=g.id), 0) AS entries,
        COALESCE(SUM(DATE(t.exit_at)=CURDATE() AND t.exit_gate_id=g.id), 0) AS exits
     FROM parking_gates g
     LEFT JOIN parking_tickets t ON t.company_id=g.company_id AND (t.entry_gate_id=g.id OR t.exit_gate_id=g.id)
     WHERE g.company_id=? AND g.status='active'
     GROUP BY g.id, g.gate_name, g.gate_type
     ORDER BY g.gate_name ASC"
);
mysqli_stmt_bind_param($gate_summary, 'i', $company_id);
mysqli_stmt_execute($gate_summary);
$gate_summary_result = mysqli_stmt_get_result($gate_summary);
$recent_live = mysqli_prepare(
    $conn,
    "SELECT t.ticket_no, t.vehicle_number, t.entry_at, v.vehicle_name, g.gate_name
     FROM parking_tickets t
     INNER JOIN parking_vehicle_types v ON v.id=t.vehicle_type_id
     LEFT JOIN parking_gates g ON g.id=t.entry_gate_id
     WHERE t.company_id=? AND t.ticket_status IN ('active','payment_pending','paid')
     ORDER BY t.entry_at ASC LIMIT 8"
);
mysqli_stmt_bind_param($recent_live, 'i', $company_id);
mysqli_stmt_execute($recent_live);
$recent_live_result = mysqli_stmt_get_result($recent_live);
$capacity_stmt = mysqli_prepare(
    $conn,
    "SELECT v.vehicle_name, v.default_capacity, COUNT(t.id) AS occupied
     FROM parking_vehicle_types v
     LEFT JOIN parking_tickets t ON t.vehicle_type_id=v.id AND t.company_id=v.company_id AND t.ticket_status IN ('active','payment_pending','paid')
     WHERE v.company_id=? AND v.status='active' AND v.default_capacity>0
     GROUP BY v.id, v.vehicle_name, v.default_capacity
     ORDER BY v.vehicle_name ASC"
);
mysqli_stmt_bind_param($capacity_stmt, 'i', $company_id);
mysqli_stmt_execute($capacity_stmt);
$capacity_result = mysqli_stmt_get_result($capacity_stmt);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<style>.parking-dashboard-live{display:inline-flex;align-items:center;gap:.38rem;font-size:.85rem;font-weight:600;color:#28a745}.parking-dashboard-live.is-offline{color:#dc3545}.parking-dashboard-live__dot{width:.65rem;height:.65rem;border-radius:50%;background:#28a745;box-shadow:0 0 0 .16rem rgba(40,167,69,.18)}.parking-dashboard-live.is-offline .parking-dashboard-live__dot{background:#dc3545;box-shadow:0 0 0 .16rem rgba(220,53,69,.18)}</style>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="fas fa-parking mr-2"></i>Parking Management</h4>
    <div>
        <span class="parking-dashboard-live mr-2" id="parking-dashboard-status"><span class="parking-dashboard-live__dot"></span><span id="parking-dashboard-status-label">Live</span></span>
        <?php if (is_admin_user() || manager_has_permission('parking_entry')) { ?><a class="btn btn-success mr-1" href="entry.php"><i class="fas fa-car mr-1"></i>New Entry</a><?php } ?>
        <?php if (is_admin_user() || manager_has_permission('parking_exit')) { ?><a class="btn btn-warning mr-1" href="exit.php"><i class="fas fa-sign-out-alt mr-1"></i>Vehicle Exit</a><a class="btn btn-danger mr-1" href="lost_ticket.php"><i class="fas fa-exclamation-triangle mr-1"></i>Lost Ticket</a><a class="btn btn-outline-dark mr-1" href="shift.php"><i class="fas fa-user-clock mr-1"></i>Shift</a><?php } ?>
    </div>
</div>

<div class="row">
    <div class="col-lg-3 col-6"><a class="small-box bg-info" href="reports.php?date_from=<?= date('Y-m-d') ?>&amp;date_to=<?= date('Y-m-d') ?>"><div class="inner"><h3 id="parking-today-entries"><?= (int)($today['entries'] ?? 0) ?></h3><p>Today's Entries</p></div><div class="icon"><i class="fas fa-car"></i></div></a></div>
    <div class="col-lg-3 col-6"><a class="small-box bg-success" href="reports.php?date_from=<?= date('Y-m-d') ?>&amp;date_to=<?= date('Y-m-d') ?>"><div class="inner"><h3 id="parking-today-exits"><?= (int)($today['exits'] ?? 0) ?></h3><p>Today's Exits</p></div><div class="icon"><i class="fas fa-sign-out-alt"></i></div></a></div>
    <div class="col-lg-3 col-6"><a class="small-box bg-warning" href="live.php"><div class="inner"><h3 id="parking-vehicles-inside"><?= (int)($today['active_vehicles'] ?? 0) ?></h3><p>Vehicles Inside</p></div><div class="icon"><i class="fas fa-parking"></i></div></a></div>
    <div class="col-lg-3 col-6"><a class="small-box bg-primary" href="<?= htmlspecialchars(app_path('wallets/index.php')) ?>"><div class="inner"><h3 id="parking-wallet-balance">BDT <?= number_format((float)($wallet_balance['total_balance'] ?? 0), 2) ?></h3><p>Wallet Balance</p></div><div class="icon"><i class="fas fa-wallet"></i></div></a></div>
</div>

<div class="card card-outline card-primary">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-chart-pie mr-2"></i>Entry, Exit &amp; Vehicles Inside</h3><div class="card-tools"><span class="text-muted small">Today</span></div></div>
    <div class="card-body"><div class="row"><div class="col-lg-4"><div style="height: 300px;"><canvas id="parking-activity-chart"></canvas></div></div><div class="col-lg-8"><h6 class="text-center text-muted mb-2">Today’s Traffic by Hour</h6><div style="height: 300px;"><canvas id="parking-traffic-chart"></canvas></div></div></div></div>
</div>

<div class="row"><div class="col-lg-7"><div class="card card-outline card-primary"><div class="card-header"><h3 class="card-title">Currently Parked</h3><div class="card-tools"><a href="live.php" class="btn btn-sm btn-outline-primary">View All</a></div></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>Ticket</th><th>Vehicle</th><th>Number</th><th>Entry Gate</th><th>Entry Time</th></tr></thead><tbody><?php if (mysqli_num_rows($recent_live_result) === 0) { ?><tr><td colspan="5" class="text-center text-muted py-3">No active vehicle found.</td></tr><?php } ?><?php while ($vehicle = mysqli_fetch_assoc($recent_live_result)) { ?><tr><td><?= htmlspecialchars($vehicle['ticket_no']) ?></td><td><?= htmlspecialchars($vehicle['vehicle_name']) ?></td><td><?= htmlspecialchars($vehicle['vehicle_number'] ?: '-') ?></td><td><?= htmlspecialchars($vehicle['gate_name'] ?: '-') ?></td><td><?= htmlspecialchars(date('d M, h:i A', strtotime($vehicle['entry_at']))) ?></td></tr><?php } ?></tbody></table></div></div></div></div>
<div class="col-lg-5"><div class="card card-outline card-secondary"><div class="card-header"><h3 class="card-title">Today's Gate Activity</h3></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>Gate</th><th>Type</th><th>Entry</th><th>Exit</th></tr></thead><tbody><?php if (mysqli_num_rows($gate_summary_result) === 0) { ?><tr><td colspan="4" class="text-center text-muted py-3">No active gate configured.</td></tr><?php } ?><?php while ($gate = mysqli_fetch_assoc($gate_summary_result)) { ?><tr><td><?= htmlspecialchars($gate['gate_name']) ?></td><td><?= htmlspecialchars(ucfirst($gate['gate_type'])) ?></td><td><?= (int)$gate['entries'] ?></td><td><?= (int)$gate['exits'] ?></td></tr><?php } ?></tbody></table></div></div></div></div></div>

<?php if (mysqli_num_rows($capacity_result) > 0) { ?><div class="card card-outline card-info"><div class="card-header"><h3 class="card-title">Vehicle Capacity</h3></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>Vehicle Type</th><th>Inside</th><th>Capacity</th><th>Available</th><th>Occupancy</th></tr></thead><tbody><?php while ($capacity = mysqli_fetch_assoc($capacity_result)) { $available = max(0, (int)$capacity['default_capacity'] - (int)$capacity['occupied']); $percent = min(100, round(((int)$capacity['occupied'] / max(1, (int)$capacity['default_capacity'])) * 100)); ?><tr><td><?= htmlspecialchars($capacity['vehicle_name']) ?></td><td><?= (int)$capacity['occupied'] ?></td><td><?= (int)$capacity['default_capacity'] ?></td><td><?= $available ?></td><td><div class="progress progress-xs"><div class="progress-bar bg-<?= $percent >= 100 ? 'danger' : ($percent >= 80 ? 'warning' : 'success') ?>" style="width:<?= $percent ?>%"></div></div><small><?= $percent ?>%</small></td></tr><?php } ?></tbody></table></div></div></div><?php } ?>

<script>
window.addEventListener('load', function () {
    const canvas = document.getElementById('parking-activity-chart');
    if (!canvas || typeof Chart === 'undefined') return;
    window.parkingActivityChart = new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: ['Today\'s Entry', 'Today\'s Exit', 'Vehicles Inside'],
            datasets: [{
                data: [<?= (int)($today['entries'] ?? 0) ?>, <?= (int)($today['exits'] ?? 0) ?>, <?= (int)($today['active_vehicles'] ?? 0) ?>],
                backgroundColor: ['#17a2b8', '#28a745', '#ffc107'],
                borderColor: '#ffffff',
                borderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '58%',
            plugins: { legend: { display: true, position: 'bottom' } }
        }
    });
    const trafficCanvas = document.getElementById('parking-traffic-chart');
    window.parkingTrafficChart = new Chart(trafficCanvas, {
        type: 'line',
        data: {
            labels: <?= json_encode($traffic_chart['labels']) ?>,
            datasets: [
                { label: 'Entry', data: <?= json_encode($traffic_chart['entries']) ?>, borderColor: '#17a2b8', backgroundColor: 'rgba(23, 162, 184, .12)', borderWidth: 3, fill: true, tension: .3, pointRadius: 3 },
                { label: 'Exit', data: <?= json_encode($traffic_chart['exits']) ?>, borderColor: '#28a745', backgroundColor: 'rgba(40, 167, 69, .08)', borderWidth: 3, fill: true, tension: .3, pointRadius: 3 },
                { label: 'Vehicles Inside', data: <?= json_encode($traffic_chart['inside']) ?>, borderColor: '#ffc107', backgroundColor: 'rgba(255, 193, 7, .08)', borderWidth: 3, fill: false, tension: .3, pointRadius: 3 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
            plugins: { legend: { display: true, position: 'top' } }
        }
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const status = document.getElementById('parking-dashboard-status');
    const statusLabel = document.getElementById('parking-dashboard-status-label');
    function setConnectionStatus(connected) {
        status.classList.toggle('is-offline', !connected);
        statusLabel.textContent = connected ? 'Live' : 'Offline';
    }
    function setText(id, value) { document.getElementById(id).textContent = value; }
    async function refreshDashboardStats() {
        try {
            const response = await fetch('index.php?ajax=dashboard_stats', {credentials: 'same-origin', cache: 'no-store'});
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error('Dashboard update failed');
            setText('parking-today-entries', data.entries);
            setText('parking-today-exits', data.exits);
            setText('parking-vehicles-inside', data.inside);
            setText('parking-wallet-balance', 'BDT ' + data.wallet_balance);
            if (window.parkingActivityChart) {
                window.parkingActivityChart.data.datasets[0].data = [data.entries, data.exits, data.inside];
                window.parkingActivityChart.update();
            }
            if (window.parkingTrafficChart && data.traffic) {
                window.parkingTrafficChart.data.datasets[0].data = data.traffic.entries || [];
                window.parkingTrafficChart.data.datasets[1].data = data.traffic.exits || [];
                window.parkingTrafficChart.data.datasets[2].data = data.traffic.inside || [];
                window.parkingTrafficChart.update();
            }
            setConnectionStatus(true);
        } catch (error) {
            setConnectionStatus(false);
        }
    }
    window.addEventListener('online', refreshDashboardStats);
    window.addEventListener('offline', function () { setConnectionStatus(false); });
    window.addEventListener('focus', refreshDashboardStats);
    window.addEventListener('load', refreshDashboardStats);
    setConnectionStatus(navigator.onLine);
    window.setInterval(refreshDashboardStats, 15000);
});
</script>

<?php mysqli_stmt_close($gate_summary); mysqli_stmt_close($recent_live); mysqli_stmt_close($capacity_stmt); ?>

<?php require_once '../includes/footer.php'; ?>
