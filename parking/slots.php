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
$parking_multi_branch_enabled = company_multi_branch_enabled($conn, $company_id);
if (empty($_SESSION['parking_slot_csrf'])) $_SESSION['parking_slot_csrf'] = bin2hex(random_bytes(32));

function parking_slot_redirect($message, $type = 'success')
{
    $_SESSION['parking_slot_flash'] = ['message' => $message, 'type' => $type];
    header('Location: slots.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)($_SESSION['parking_slot_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) parking_slot_redirect('Invalid request. Please try again.', 'danger');
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_zone') {
        $name = trim((string)($_POST['zone_name'] ?? ''));
        $code = strtoupper(trim((string)($_POST['zone_code'] ?? '')));
        $code = trim(preg_replace('/[^A-Z0-9_-]+/', '-', $code), '-');
        $branch_id = $parking_multi_branch_enabled ? max(0, (int)($_POST['branch_id'] ?? 0)) : 0;
        $vehicle_type_id = max(0, (int)($_POST['vehicle_type_id'] ?? 0));
        $capacity = max(0, (int)($_POST['capacity'] ?? 0));
        if ($name === '' || $code === '') parking_slot_redirect('Zone name and code are required.', 'danger');
        $vehicle_value = $vehicle_type_id > 0 ? $vehicle_type_id : null;
        $stmt = mysqli_prepare($conn, 'INSERT INTO parking_zones (company_id, branch_id, zone_name, zone_code, vehicle_type_id, capacity) VALUES (?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'iissii', $company_id, $branch_id, $name, $code, $vehicle_value, $capacity);
        if (!mysqli_stmt_execute($stmt)) { mysqli_stmt_close($stmt); parking_slot_redirect('This zone code already exists in the selected branch.', 'danger'); }
        mysqli_stmt_close($stmt);
        parking_slot_redirect('Parking zone added.');
    }

    if ($action === 'add_slot') {
        $zone_id = (int)($_POST['zone_id'] ?? 0);
        $code = strtoupper(trim((string)($_POST['slot_code'] ?? '')));
        $code = trim(preg_replace('/[^A-Z0-9_-]+/', '-', $code), '-');
        $vehicle_type_id = max(0, (int)($_POST['vehicle_type_id'] ?? 0));
        if ($zone_id <= 0 || $code === '') parking_slot_redirect('Select a zone and enter a slot code.', 'danger');
        $zone_stmt = mysqli_prepare($conn, "SELECT branch_id, capacity FROM parking_zones WHERE id=? AND company_id=? AND status='active' LIMIT 1");
        mysqli_stmt_bind_param($zone_stmt, 'ii', $zone_id, $company_id);
        mysqli_stmt_execute($zone_stmt);
        $zone = mysqli_fetch_assoc(mysqli_stmt_get_result($zone_stmt));
        mysqli_stmt_close($zone_stmt);
        if (!$zone) parking_slot_redirect('Select an active zone.', 'danger');
        $branch_id = (int)$zone['branch_id'];
        if ((int)$zone['capacity'] > 0) {
            $count_stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS total FROM parking_slots WHERE company_id=? AND zone_id=? AND status<>\'inactive\'');
            mysqli_stmt_bind_param($count_stmt, 'ii', $company_id, $zone_id);
            mysqli_stmt_execute($count_stmt);
            $slot_count = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($count_stmt))['total'] ?? 0);
            mysqli_stmt_close($count_stmt);
            if ($slot_count >= (int)$zone['capacity']) parking_slot_redirect('This zone has reached its configured slot capacity.', 'danger');
        }
        $vehicle_value = $vehicle_type_id > 0 ? $vehicle_type_id : null;
        $stmt = mysqli_prepare($conn, 'INSERT INTO parking_slots (company_id, branch_id, zone_id, slot_code, vehicle_type_id) VALUES (?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'iiisi', $company_id, $branch_id, $zone_id, $code, $vehicle_value);
        if (!mysqli_stmt_execute($stmt)) { mysqli_stmt_close($stmt); parking_slot_redirect('This slot code already exists in the selected branch.', 'danger'); }
        mysqli_stmt_close($stmt);
        parking_slot_redirect('Parking slot added.');
    }

    if ($action === 'toggle_zone') {
        $id = (int)($_POST['zone_id'] ?? 0); $status = (string)($_POST['status'] ?? '');
        if ($id <= 0 || !in_array($status, ['active','inactive'], true)) parking_slot_redirect('Invalid zone request.', 'danger');
        $stmt = mysqli_prepare($conn, 'UPDATE parking_zones SET status=? WHERE id=? AND company_id=?'); mysqli_stmt_bind_param($stmt, 'sii', $status, $id, $company_id); mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);
        parking_slot_redirect('Zone status updated.');
    }
    if ($action === 'toggle_slot') {
        $id = (int)($_POST['slot_id'] ?? 0); $status = (string)($_POST['status'] ?? '');
        if ($id <= 0 || !in_array($status, ['available','inactive','maintenance'], true)) parking_slot_redirect('Invalid slot request.', 'danger');
        $stmt = mysqli_prepare($conn, "UPDATE parking_slots SET status=? WHERE id=? AND company_id=? AND status<>'occupied'"); mysqli_stmt_bind_param($stmt, 'sii', $status, $id, $company_id); mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);
        parking_slot_redirect('Slot status updated.');
    }
}

$flash = $_SESSION['parking_slot_flash'] ?? null; unset($_SESSION['parking_slot_flash']);
$settings_stmt = mysqli_prepare($conn, 'SELECT slot_management_enabled FROM parking_settings WHERE company_id=? LIMIT 1'); mysqli_stmt_bind_param($settings_stmt, 'i', $company_id); mysqli_stmt_execute($settings_stmt); $settings = mysqli_fetch_assoc(mysqli_stmt_get_result($settings_stmt)) ?: []; mysqli_stmt_close($settings_stmt);
$branches = mysqli_query($conn, "SELECT id, branch_name FROM branches WHERE user_id={$company_id} AND status='active' AND COALESCE(is_head_office, 0)=0 ORDER BY branch_name ASC");
$vehicle_types = mysqli_query($conn, "SELECT id, vehicle_name FROM parking_vehicle_types WHERE company_id={$company_id} AND status='active' ORDER BY vehicle_name ASC");
$zones = mysqli_query($conn, "SELECT z.*, COALESCE(NULLIF(b.branch_name, ''), 'Head Office') AS branch_name, v.vehicle_name FROM parking_zones z LEFT JOIN branches b ON b.id=z.branch_id AND b.user_id=z.company_id LEFT JOIN parking_vehicle_types v ON v.id=z.vehicle_type_id WHERE z.company_id={$company_id} ORDER BY z.status='active' DESC, z.zone_name ASC");
$zone_options = mysqli_query($conn, "SELECT id, zone_name, zone_code FROM parking_zones WHERE company_id={$company_id} AND status='active' ORDER BY zone_name ASC");
$slots = mysqli_query($conn, "SELECT s.*, z.zone_name, z.zone_code, v.vehicle_name FROM parking_slots s INNER JOIN parking_zones z ON z.id=s.zone_id LEFT JOIN parking_vehicle_types v ON v.id=s.vehicle_type_id WHERE s.company_id={$company_id} ORDER BY s.status='occupied' DESC, z.zone_name ASC, s.slot_code ASC");
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<?php if ($flash) { ?><div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible"><button type="button" class="close" data-dismiss="alert">&times;</button><?= htmlspecialchars($flash['message']) ?></div><?php } ?>
<?php if (empty($settings['slot_management_enabled'])) { ?><div class="alert alert-info">Slot Management is currently <strong>OFF</strong>. You can prepare zones and slots now; turn it on from <a href="setup.php">Parking Setup</a> when ready.</div><?php } ?>
<div class="d-flex justify-content-between align-items-center mb-3"><h4 class="mb-0"><i class="fas fa-th mr-2"></i>Parking Zones & Slots</h4><a href="setup.php" class="btn btn-outline-secondary btn-sm">Parking Setup</a></div>
<div class="row"><div class="col-lg-6"><div class="card card-outline card-info"><div class="card-header"><h3 class="card-title">Add Zone</h3></div><div class="card-body"><form method="post" class="row"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['parking_slot_csrf']) ?>"><input type="hidden" name="action" value="add_zone"><div class="col-md-6 form-group"><label>Zone Name</label><input class="form-control" name="zone_name" placeholder="Basement A" required></div><div class="col-md-6 form-group"><label>Zone Code</label><input class="form-control" name="zone_code" placeholder="B1-A" required></div><?php if ($parking_multi_branch_enabled) { ?><div class="col-md-4 form-group"><label>Branch</label><select class="form-control" name="branch_id"><option value="0">Head Office</option><?php while ($branch=mysqli_fetch_assoc($branches)) { ?><option value="<?= (int)$branch['id'] ?>"><?= htmlspecialchars($branch['branch_name']) ?></option><?php } ?></select></div><?php } ?><div class="col-md-<?= $parking_multi_branch_enabled ? 4 : 6 ?> form-group"><label>Vehicle Type</label><select class="form-control" name="vehicle_type_id"><option value="0">All</option><?php mysqli_data_seek($vehicle_types,0); while ($type=mysqli_fetch_assoc($vehicle_types)) { ?><option value="<?= (int)$type['id'] ?>"><?= htmlspecialchars($type['vehicle_name']) ?></option><?php } ?></select></div><div class="col-md-<?= $parking_multi_branch_enabled ? 4 : 6 ?> form-group"><label>Capacity</label><input class="form-control" type="number" min="0" name="capacity" value="0"></div><div class="col-12 text-right"><button class="btn btn-info">Add Zone</button></div></form></div></div></div><div class="col-lg-6"><div class="card card-outline card-success"><div class="card-header"><h3 class="card-title">Add Slot</h3></div><div class="card-body"><form method="post" class="row"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['parking_slot_csrf']) ?>"><input type="hidden" name="action" value="add_slot"><div class="col-md-6 form-group"><label>Zone</label><select class="form-control" name="zone_id" required><option value="">Select zone</option><?php while ($zone=mysqli_fetch_assoc($zone_options)) { ?><option value="<?= (int)$zone['id'] ?>"><?= htmlspecialchars($zone['zone_name'].' ('.$zone['zone_code'].')') ?></option><?php } ?></select></div><div class="col-md-6 form-group"><label>Slot Code</label><input class="form-control" name="slot_code" placeholder="A-001" required></div><div class="col-md-6 form-group"><label>Vehicle Type</label><select class="form-control" name="vehicle_type_id"><option value="0">Follow zone / all</option><?php mysqli_data_seek($vehicle_types,0); while ($type=mysqli_fetch_assoc($vehicle_types)) { ?><option value="<?= (int)$type['id'] ?>"><?= htmlspecialchars($type['vehicle_name']) ?></option><?php } ?></select></div><div class="col-md-6 d-flex align-items-end form-group"><button class="btn btn-success btn-block">Add Slot</button></div></form></div></div></div></div>
<div class="card card-outline card-primary"><div class="card-header"><h3 class="card-title">Zones</h3></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>Zone</th><th>Code</th><?php if ($parking_multi_branch_enabled) { ?><th>Branch</th><?php } ?><th>Vehicle</th><th>Capacity</th><th>Status</th><th>Action</th></tr></thead><tbody><?php while($zone=mysqli_fetch_assoc($zones)){ ?><tr><td><?= htmlspecialchars($zone['zone_name']) ?></td><td><?= htmlspecialchars($zone['zone_code']) ?></td><?php if ($parking_multi_branch_enabled) { ?><td><?= htmlspecialchars($zone['branch_name']) ?></td><?php } ?><td><?= htmlspecialchars($zone['vehicle_name'] ?: 'All') ?></td><td><?= (int)$zone['capacity'] ?></td><td><span class="badge badge-<?= $zone['status']==='active'?'success':'secondary' ?>"><?= htmlspecialchars($zone['status']) ?></span></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['parking_slot_csrf']) ?>"><input type="hidden" name="action" value="toggle_zone"><input type="hidden" name="zone_id" value="<?= (int)$zone['id'] ?>"><input type="hidden" name="status" value="<?= $zone['status']==='active'?'inactive':'active' ?>"><button class="btn btn-xs btn-outline-secondary"><?= $zone['status']==='active'?'Deactivate':'Activate' ?></button></form></td></tr><?php } ?></tbody></table></div></div></div>
<div class="card card-outline card-secondary"><div class="card-header"><h3 class="card-title">Slots</h3></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>Zone</th><th>Slot</th><th>Vehicle</th><th>Status</th><th>Action</th></tr></thead><tbody><?php while($slot=mysqli_fetch_assoc($slots)){ ?><tr><td><?= htmlspecialchars($slot['zone_name'].' ('.$slot['zone_code'].')') ?></td><td><?= htmlspecialchars($slot['slot_code']) ?></td><td><?= htmlspecialchars($slot['vehicle_name'] ?: 'All') ?></td><td><span class="badge badge-<?= $slot['status']==='available'?'success':($slot['status']==='occupied'?'warning':'secondary') ?>"><?= htmlspecialchars($slot['status']) ?></span></td><td><?php if($slot['status']!=='occupied'){ ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['parking_slot_csrf']) ?>"><input type="hidden" name="action" value="toggle_slot"><input type="hidden" name="slot_id" value="<?= (int)$slot['id'] ?>"><input type="hidden" name="status" value="<?= $slot['status']==='available'?'inactive':'available' ?>"><button class="btn btn-xs btn-outline-secondary"><?= $slot['status']==='available'?'Deactivate':'Activate' ?></button></form><?php } ?></td></tr><?php } ?></tbody></table></div></div></div>
<?php require_once '../includes/footer.php'; ?>
