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

$search = trim((string)($_GET['q'] ?? ''));
$search_like = '%' . $search . '%';
$sql = "SELECT t.*, v.vehicle_name, g.gate_name, r.plan_name
        FROM parking_tickets t
        INNER JOIN parking_vehicle_types v ON v.id=t.vehicle_type_id
        LEFT JOIN parking_gates g ON g.id=t.entry_gate_id
        LEFT JOIN parking_rate_plans r ON r.id=t.rate_plan_id
        WHERE t.company_id=? AND t.ticket_status IN ('active','payment_pending','paid')";
if ($search !== '') {
    $sql .= ' AND (t.ticket_no LIKE ? OR t.barcode_value LIKE ? OR t.vehicle_number LIKE ?)';
}
$sql .= ' ORDER BY t.entry_at ASC';
$stmt = mysqli_prepare($conn, $sql);
if ($search !== '') {
    mysqli_stmt_bind_param($stmt, 'isss', $company_id, $search_like, $search_like, $search_like);
} else {
    mysqli_stmt_bind_param($stmt, 'i', $company_id);
}
mysqli_stmt_execute($stmt);
$vehicles = mysqli_stmt_get_result($stmt);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card card-outline card-primary"><div class="card-header"><h3 class="card-title"><i class="fas fa-parking mr-2"></i>Live Parking</h3><div class="card-tools"><a href="index.php" class="btn btn-outline-secondary btn-sm">Dashboard</a></div></div><div class="card-body"><form method="get" class="mb-3"><div class="input-group"><input class="form-control" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search ticket number, barcode or vehicle number"><div class="input-group-append"><button class="btn btn-primary"><i class="fas fa-search"></i> Search</button></div></div></form><div class="table-responsive"><table class="table table-bordered table-hover"><thead><tr><th>Ticket</th><th>Vehicle</th><th>Number</th><th>Entry Gate</th><th>Entry Time</th><th>Duration</th><th>Rate Plan</th></tr></thead><tbody><?php if (mysqli_num_rows($vehicles) === 0) { ?><tr><td colspan="7" class="text-center text-muted py-4">No currently parked vehicle found.</td></tr><?php } ?><?php while ($vehicle = mysqli_fetch_assoc($vehicles)) { $minutes = max(0, (int)ceil((time() - strtotime($vehicle['entry_at'])) / 60)); ?><tr><td><?= htmlspecialchars($vehicle['ticket_no']) ?></td><td><?= htmlspecialchars($vehicle['vehicle_name']) ?></td><td><?= htmlspecialchars($vehicle['vehicle_number'] ?: '-') ?></td><td><?= htmlspecialchars($vehicle['gate_name'] ?: '-') ?></td><td><?= htmlspecialchars(date('d M Y, h:i A', strtotime($vehicle['entry_at']))) ?></td><td><?= $minutes ?> min</td><td><?= htmlspecialchars($vehicle['plan_name'] ?: '-') ?></td></tr><?php } ?></tbody></table></div></div></div>

<?php mysqli_stmt_close($stmt); require_once '../includes/footer.php'; ?>
