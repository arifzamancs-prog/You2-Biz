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

function parking_live_vehicles($conn, $company_id, $search = '')
{
    $search = trim((string)$search);
    $search_like = '%' . $search . '%';
    $sql = "SELECT t.ticket_no, t.vehicle_number, t.entry_at, v.vehicle_name, g.gate_name, r.plan_name
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
    $result = mysqli_stmt_get_result($stmt);
    $vehicles = [];
    while ($vehicle = mysqli_fetch_assoc($result)) {
        $minutes = max(0, (int)ceil((time() - strtotime($vehicle['entry_at'])) / 60));
        $vehicles[] = [
            'ticket_no' => (string)$vehicle['ticket_no'],
            'vehicle_name' => (string)$vehicle['vehicle_name'],
            'vehicle_number' => (string)($vehicle['vehicle_number'] ?: '—'),
            'gate_name' => (string)($vehicle['gate_name'] ?: '—'),
            'entry_time' => date('d M Y, h:i A', strtotime($vehicle['entry_at'])),
            'duration' => $minutes . ' min',
            'plan_name' => (string)($vehicle['plan_name'] ?: '—'),
        ];
    }
    mysqli_stmt_close($stmt);
    return $vehicles;
}

$search = trim((string)($_GET['q'] ?? ''));
$vehicles = parking_live_vehicles($conn, $company_id, $search);

if (($_GET['ajax'] ?? '') === 'live_vehicles') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['vehicles' => $vehicles], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<style>.parking-live-status{font-size:.9rem}.parking-live-dot{display:inline-block;width:12px;height:12px;border-radius:50%;background:#28a745;box-shadow:0 0 0 3px rgba(40,167,69,.15);vertical-align:-1px}.parking-live-status.offline .parking-live-dot{background:#dc3545;box-shadow:0 0 0 3px rgba(220,53,69,.15)}</style>
<div class="card card-outline card-primary"><div class="card-header"><h3 class="card-title"><i class="fas fa-parking mr-2"></i>Live Parking</h3><div class="card-tools"><span id="parking-live-status" class="parking-live-status text-success mr-2"><span class="parking-live-dot mr-1"></span>Live</span><a href="index.php" class="btn btn-outline-secondary btn-sm">Dashboard</a></div></div><div class="card-body"><form id="live-search-form" method="get" class="mb-3"><div class="input-group"><input id="live-search" class="form-control" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search ticket number, barcode or vehicle number"><div class="input-group-append"><button class="btn btn-primary"><i class="fas fa-search"></i> Search</button></div></div></form><div class="table-responsive"><table class="table table-bordered table-hover"><thead><tr><th>Ticket</th><th>Vehicle</th><th>Number</th><th>Entry Gate</th><th>Entry Time</th><th>Duration</th><th>Rate Plan</th></tr></thead><tbody id="live-vehicles-body"><?php if (!$vehicles) { ?><tr><td colspan="7" class="text-center text-muted py-4">No currently parked vehicle found.</td></tr><?php } ?><?php foreach ($vehicles as $vehicle) { ?><tr><td><?= htmlspecialchars($vehicle['ticket_no']) ?></td><td><?= htmlspecialchars($vehicle['vehicle_name']) ?></td><td><?= htmlspecialchars($vehicle['vehicle_number']) ?></td><td><?= htmlspecialchars($vehicle['gate_name']) ?></td><td><?= htmlspecialchars($vehicle['entry_time']) ?></td><td><?= htmlspecialchars($vehicle['duration']) ?></td><td><?= htmlspecialchars($vehicle['plan_name']) ?></td></tr><?php } ?></tbody></table></div></div></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('live-search-form'), input = document.getElementById('live-search'), body = document.getElementById('live-vehicles-body'), status = document.getElementById('parking-live-status');
    const escapeHtml = value => String(value).replace(/[&<>'\"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','\"':'&quot;'}[char]));
    function setStatus(online) { status.classList.toggle('offline', !online); status.classList.toggle('text-success', online); status.classList.toggle('text-danger', !online); status.innerHTML = '<span class="parking-live-dot mr-1"></span>' + (online ? 'Live' : 'Offline'); }
    async function loadVehicles() { try { const response = await fetch('live.php?ajax=live_vehicles&q=' + encodeURIComponent(input.value), {headers:{'X-Requested-With':'XMLHttpRequest'}}); if (!response.ok) throw new Error(); const data = await response.json(); body.innerHTML = data.vehicles.length ? data.vehicles.map(vehicle => '<tr><td>'+escapeHtml(vehicle.ticket_no)+'</td><td>'+escapeHtml(vehicle.vehicle_name)+'</td><td>'+escapeHtml(vehicle.vehicle_number)+'</td><td>'+escapeHtml(vehicle.gate_name)+'</td><td>'+escapeHtml(vehicle.entry_time)+'</td><td>'+escapeHtml(vehicle.duration)+'</td><td>'+escapeHtml(vehicle.plan_name)+'</td></tr>').join('') : '<tr><td colspan="7" class="text-center text-muted py-4">No currently parked vehicle found.</td></tr>'; setStatus(true); } catch (error) { setStatus(false); } }
    form.addEventListener('submit', function (event) { event.preventDefault(); loadVehicles(); });
    window.addEventListener('online', loadVehicles); window.addEventListener('offline', () => setStatus(false));
    setInterval(() => { if (navigator.onLine) loadVehicles(); else setStatus(false); }, 15000);
});
</script>

<?php require_once '../includes/footer.php'; ?>
