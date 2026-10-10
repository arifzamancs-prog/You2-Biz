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
$collection = parking_dashboard_total(
    $conn,
    "SELECT COALESCE(SUM(amount), 0) AS collection
     FROM parking_payments WHERE company_id=? AND DATE(paid_at)=CURDATE()",
    $company_id
);
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

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="fas fa-parking mr-2"></i>Parking Management</h4>
    <div>
        <?php if (is_admin_user() || manager_has_permission('parking_entry')) { ?><a class="btn btn-success btn-sm mr-1" href="entry.php"><i class="fas fa-car mr-1"></i>New Entry</a><?php } ?>
        <?php if (is_admin_user() || manager_has_permission('parking_exit')) { ?><a class="btn btn-warning btn-sm mr-1" href="exit.php"><i class="fas fa-sign-out-alt mr-1"></i>Vehicle Exit</a><a class="btn btn-outline-warning btn-sm mr-1" href="lost_ticket.php"><i class="fas fa-exclamation-triangle mr-1"></i>Lost Ticket</a><a class="btn btn-outline-dark btn-sm mr-1" href="shift.php"><i class="fas fa-user-clock mr-1"></i>Shift</a><?php } ?>
        <?php if (is_admin_user() || manager_has_permission('parking_reports')) { ?><a class="btn btn-info btn-sm mr-1" href="reports.php"><i class="fas fa-chart-bar mr-1"></i>Reports</a><?php } ?>
        <?php if (is_admin_user() || manager_has_permission('parking_settings')) { ?><a class="btn btn-primary btn-sm" href="setup.php"><i class="fas fa-cog mr-1"></i>Setup</a><?php } ?>
    </div>
</div>

<div class="row">
    <div class="col-lg-3 col-6"><div class="small-box bg-info"><div class="inner"><h3><?= (int)($today['entries'] ?? 0) ?></h3><p>Today's Entries</p></div><div class="icon"><i class="fas fa-car"></i></div></div></div>
    <div class="col-lg-3 col-6"><div class="small-box bg-success"><div class="inner"><h3><?= (int)($today['exits'] ?? 0) ?></h3><p>Today's Exits</p></div><div class="icon"><i class="fas fa-sign-out-alt"></i></div></div></div>
    <div class="col-lg-3 col-6"><div class="small-box bg-warning"><div class="inner"><h3><?= (int)($today['active_vehicles'] ?? 0) ?></h3><p>Vehicles Inside</p></div><div class="icon"><i class="fas fa-parking"></i></div><a href="live.php" class="small-box-footer">View live list <i class="fas fa-arrow-circle-right"></i></a></div></div>
    <div class="col-lg-3 col-6"><div class="small-box bg-primary"><div class="inner"><h3>BDT <?= number_format((float)($collection['collection'] ?? 0), 2) ?></h3><p>Today's Collection</p></div><div class="icon"><i class="fas fa-wallet"></i></div></div></div>
</div>

<div class="row"><div class="col-lg-7"><div class="card card-outline card-primary"><div class="card-header"><h3 class="card-title">Currently Parked</h3><div class="card-tools"><a href="live.php" class="btn btn-sm btn-outline-primary">View All</a></div></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>Ticket</th><th>Vehicle</th><th>Number</th><th>Entry Gate</th><th>Entry Time</th></tr></thead><tbody><?php if (mysqli_num_rows($recent_live_result) === 0) { ?><tr><td colspan="5" class="text-center text-muted py-3">No active vehicle found.</td></tr><?php } ?><?php while ($vehicle = mysqli_fetch_assoc($recent_live_result)) { ?><tr><td><?= htmlspecialchars($vehicle['ticket_no']) ?></td><td><?= htmlspecialchars($vehicle['vehicle_name']) ?></td><td><?= htmlspecialchars($vehicle['vehicle_number'] ?: '-') ?></td><td><?= htmlspecialchars($vehicle['gate_name'] ?: '-') ?></td><td><?= htmlspecialchars(date('d M, h:i A', strtotime($vehicle['entry_at']))) ?></td></tr><?php } ?></tbody></table></div></div></div></div>
<div class="col-lg-5"><div class="card card-outline card-secondary"><div class="card-header"><h3 class="card-title">Today's Gate Activity</h3></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>Gate</th><th>Type</th><th>Entry</th><th>Exit</th></tr></thead><tbody><?php if (mysqli_num_rows($gate_summary_result) === 0) { ?><tr><td colspan="4" class="text-center text-muted py-3">No active gate configured.</td></tr><?php } ?><?php while ($gate = mysqli_fetch_assoc($gate_summary_result)) { ?><tr><td><?= htmlspecialchars($gate['gate_name']) ?></td><td><?= htmlspecialchars(ucfirst($gate['gate_type'])) ?></td><td><?= (int)$gate['entries'] ?></td><td><?= (int)$gate['exits'] ?></td></tr><?php } ?></tbody></table></div></div></div></div></div>

<?php if (mysqli_num_rows($capacity_result) > 0) { ?><div class="card card-outline card-info"><div class="card-header"><h3 class="card-title">Vehicle Capacity</h3></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>Vehicle Type</th><th>Inside</th><th>Capacity</th><th>Available</th><th>Occupancy</th></tr></thead><tbody><?php while ($capacity = mysqli_fetch_assoc($capacity_result)) { $available = max(0, (int)$capacity['default_capacity'] - (int)$capacity['occupied']); $percent = min(100, round(((int)$capacity['occupied'] / max(1, (int)$capacity['default_capacity'])) * 100)); ?><tr><td><?= htmlspecialchars($capacity['vehicle_name']) ?></td><td><?= (int)$capacity['occupied'] ?></td><td><?= (int)$capacity['default_capacity'] ?></td><td><?= $available ?></td><td><div class="progress progress-xs"><div class="progress-bar bg-<?= $percent >= 100 ? 'danger' : ($percent >= 80 ? 'warning' : 'success') ?>" style="width:<?= $percent ?>%"></div></div><small><?= $percent ?>%</small></td></tr><?php } ?></tbody></table></div></div></div><?php } ?>

<?php mysqli_stmt_close($gate_summary); mysqli_stmt_close($recent_live); mysqli_stmt_close($capacity_stmt); ?>

<?php require_once '../includes/footer.php'; ?>
