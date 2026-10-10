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

if (empty($_SESSION['parking_setup_csrf'])) {
    $_SESSION['parking_setup_csrf'] = bin2hex(random_bytes(32));
}

function parking_setup_redirect($message, $type = 'success')
{
    if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $type === 'success', 'message' => $message, 'type' => $type]);
        exit;
    }
    $_SESSION['parking_setup_flash'] = ['message' => $message, 'type' => $type];
    header('Location: setup.php');
    exit;
}

function parking_setup_valid_csrf()
{
    return hash_equals((string)($_SESSION['parking_setup_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!parking_setup_valid_csrf()) {
        parking_setup_redirect('Invalid request. Please try again.', 'danger');
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_settings') {
        $payment_mode = (string)($_POST['payment_mode'] ?? 'pay_at_exit');
        $barcode_format = (string)($_POST['barcode_format'] ?? 'barcode');
        $grace_minutes = max(0, min(1440, (int)($_POST['grace_minutes'] ?? 0)));
        $lost_ticket_fee = max(0, (float)($_POST['lost_ticket_fee'] ?? 0));

        if (!in_array($payment_mode, ['pay_at_exit', 'prepaid_fixed', 'hybrid', 'free'], true)
            || !in_array($barcode_format, ['barcode', 'qr', 'both'], true)) {
            parking_setup_redirect('Please select valid parking settings.', 'danger');
        }

        $slot_enabled = isset($_POST['slot_management_enabled']) ? 1 : 0;
        $entry_print = isset($_POST['entry_print_enabled']) ? 1 : 0;
        $exit_print = isset($_POST['exit_print_enabled']) ? 1 : 0;
        $vehicle_number_required = isset($_POST['vehicle_number_required']) ? 1 : 0;
        $stmt = mysqli_prepare(
            $conn,
            'UPDATE parking_settings SET slot_management_enabled=?, payment_mode=?, entry_print_enabled=?, exit_print_enabled=?, barcode_format=?, vehicle_number_required=?, grace_minutes=?, lost_ticket_fee=? WHERE company_id=?'
        );
        mysqli_stmt_bind_param($stmt, 'isiisiidi', $slot_enabled, $payment_mode, $entry_print, $exit_print, $barcode_format, $vehicle_number_required, $grace_minutes, $lost_ticket_fee, $company_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        parking_setup_redirect('Parking settings updated successfully.');
    }

    if ($action === 'add_vehicle_type') {
        $vehicle_name = trim((string)($_POST['vehicle_name'] ?? ''));
        $vehicle_code = strtolower(trim((string)($_POST['vehicle_code'] ?? '')));
        $vehicle_code = preg_replace('/[^a-z0-9_-]+/', '-', $vehicle_code);
        $vehicle_code = trim($vehicle_code, '-');
        $capacity = max(0, (int)($_POST['default_capacity'] ?? 0));

        if ($vehicle_name === '' || $vehicle_code === '' || strlen($vehicle_name) > 100 || strlen($vehicle_code) > 40) {
            parking_setup_redirect('Vehicle name and a valid short code are required.', 'danger');
        }

        $stmt = mysqli_prepare($conn, "INSERT INTO parking_vehicle_types (company_id, vehicle_code, vehicle_name, default_capacity) VALUES (?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'issi', $company_id, $vehicle_code, $vehicle_name, $capacity);
        try {
            $vehicle_saved = mysqli_stmt_execute($stmt);
        } catch (mysqli_sql_exception $exception) {
            mysqli_stmt_close($stmt);
            parking_setup_redirect('This vehicle code already exists. Please use a different code.', 'danger');
        }
        if (!$vehicle_saved) {
            mysqli_stmt_close($stmt);
            parking_setup_redirect('This vehicle code already exists.', 'danger');
        }
        mysqli_stmt_close($stmt);
        parking_setup_redirect('Vehicle type added successfully.');
    }

    if ($action === 'toggle_vehicle_type') {
        $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if ($vehicle_id <= 0 || !in_array($status, ['active', 'inactive'], true)) {
            parking_setup_redirect('Invalid vehicle type request.', 'danger');
        }
        $stmt = mysqli_prepare($conn, 'UPDATE parking_vehicle_types SET status=? WHERE id=? AND company_id=?');
        mysqli_stmt_bind_param($stmt, 'sii', $status, $vehicle_id, $company_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        parking_setup_redirect('Vehicle type status updated.');
    }

    if ($action === 'delete_vehicle_type') {
        $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
        if ($vehicle_id <= 0) {
            parking_setup_redirect('Invalid vehicle type request.', 'danger');
        }

        $usage_tables = [
            'parking_rate_plans' => 'vehicle_type_id',
            'parking_zones' => 'vehicle_type_id',
            'parking_slots' => 'vehicle_type_id',
            'parking_tickets' => 'vehicle_type_id',
        ];
        foreach ($usage_tables as $table_name => $column_name) {
            $usage_stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM {$table_name} WHERE company_id=? AND {$column_name}=?");
            mysqli_stmt_bind_param($usage_stmt, 'ii', $company_id, $vehicle_id);
            mysqli_stmt_execute($usage_stmt);
            $usage = mysqli_fetch_assoc(mysqli_stmt_get_result($usage_stmt));
            mysqli_stmt_close($usage_stmt);
            if ((int)($usage['total'] ?? 0) > 0) {
                parking_setup_redirect('This vehicle type is already in use and cannot be deleted. Deactivate it instead.', 'danger');
            }
        }

        $stmt = mysqli_prepare($conn, 'DELETE FROM parking_vehicle_types WHERE id=? AND company_id=?');
        mysqli_stmt_bind_param($stmt, 'ii', $vehicle_id, $company_id);
        mysqli_stmt_execute($stmt);
        $deleted = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        parking_setup_redirect($deleted > 0 ? 'Vehicle type deleted successfully.' : 'Vehicle type was not found.', $deleted > 0 ? 'success' : 'danger');
    }

    if ($action === 'add_rate_plan') {
        $vehicle_type_id = (int)($_POST['vehicle_type_id'] ?? 0);
        $plan_name = trim((string)($_POST['plan_name'] ?? ''));
        $rate_mode = (string)($_POST['rate_mode'] ?? 'duration');
        $fixed_fee = max(0, (float)($_POST['fixed_fee'] ?? 0));
        $initial_minutes = max(0, (int)($_POST['initial_minutes'] ?? 0));
        $initial_fee = max(0, (float)($_POST['initial_fee'] ?? 0));
        $extra_minutes = max(1, (int)($_POST['extra_minutes'] ?? 60));
        $extra_fee = max(0, (float)($_POST['extra_fee'] ?? 0));
        $daily_max_fee = max(0, (float)($_POST['daily_max_fee'] ?? 0));

        if ($vehicle_type_id <= 0 || $plan_name === '' || strlen($plan_name) > 120 || !in_array($rate_mode, ['fixed', 'duration', 'free'], true)) {
            parking_setup_redirect('Please provide valid rate plan details.', 'danger');
        }
        $vehicle_stmt = mysqli_prepare($conn, "SELECT id FROM parking_vehicle_types WHERE id=? AND company_id=? AND status='active' LIMIT 1");
        mysqli_stmt_bind_param($vehicle_stmt, 'ii', $vehicle_type_id, $company_id);
        mysqli_stmt_execute($vehicle_stmt);
        $vehicle = mysqli_fetch_assoc(mysqli_stmt_get_result($vehicle_stmt));
        mysqli_stmt_close($vehicle_stmt);
        if (!$vehicle) {
            parking_setup_redirect('Select an active vehicle type.', 'danger');
        }

        $stmt = mysqli_prepare($conn, 'INSERT INTO parking_rate_plans (company_id, vehicle_type_id, plan_name, rate_mode, fixed_fee, initial_minutes, initial_fee, extra_minutes, extra_fee, daily_max_fee) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'iissdidddd', $company_id, $vehicle_type_id, $plan_name, $rate_mode, $fixed_fee, $initial_minutes, $initial_fee, $extra_minutes, $extra_fee, $daily_max_fee);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        parking_setup_redirect('Rate plan added successfully.');
    }

    if ($action === 'toggle_rate_plan') {
        $rate_id = (int)($_POST['rate_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if ($rate_id <= 0 || !in_array($status, ['active', 'inactive'], true)) {
            parking_setup_redirect('Invalid rate plan request.', 'danger');
        }
        $stmt = mysqli_prepare($conn, 'UPDATE parking_rate_plans SET status=? WHERE id=? AND company_id=?');
        mysqli_stmt_bind_param($stmt, 'sii', $status, $rate_id, $company_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        parking_setup_redirect('Rate plan status updated.');
    }

    if ($action === 'add_gate') {
        $gate_name = trim((string)($_POST['gate_name'] ?? ''));
        $gate_code = strtoupper(trim((string)($_POST['gate_code'] ?? '')));
        $gate_code = preg_replace('/[^A-Z0-9_-]+/', '-', $gate_code);
        $gate_code = trim($gate_code, '-');
        $gate_type = (string)($_POST['gate_type'] ?? 'both');
        $terminal_name = trim((string)($_POST['terminal_name'] ?? ''));
        $branch_id = $parking_multi_branch_enabled ? max(0, (int)($_POST['branch_id'] ?? 0)) : 0;

        if ($gate_name === '' || $gate_code === '' || strlen($gate_name) > 120 || strlen($gate_code) > 50 || !in_array($gate_type, ['entry', 'exit', 'both'], true)) {
            parking_setup_redirect('Gate name, code and type are required.', 'danger');
        }
        $stmt = mysqli_prepare($conn, 'INSERT INTO parking_gates (company_id, branch_id, gate_name, gate_code, gate_type, terminal_name) VALUES (?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'iissss', $company_id, $branch_id, $gate_name, $gate_code, $gate_type, $terminal_name);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            parking_setup_redirect('This gate code already exists for the selected branch.', 'danger');
        }
        mysqli_stmt_close($stmt);
        parking_setup_redirect('Parking gate added successfully.');
    }

    if ($action === 'toggle_gate') {
        $gate_id = (int)($_POST['gate_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if ($gate_id <= 0 || !in_array($status, ['active', 'inactive'], true)) {
            parking_setup_redirect('Invalid gate request.', 'danger');
        }
        $stmt = mysqli_prepare($conn, 'UPDATE parking_gates SET status=? WHERE id=? AND company_id=?');
        mysqli_stmt_bind_param($stmt, 'sii', $status, $gate_id, $company_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        parking_setup_redirect('Gate status updated.');
    }

    if ($action === 'save_gate_assignment') {
        $assigned_user_id = (int)($_POST['assigned_user_id'] ?? 0);
        $assigned_gate_id = (int)($_POST['assigned_gate_id'] ?? 0);
        $access_type = (string)($_POST['access_type'] ?? 'both');
        if ($assigned_user_id <= 0 || $assigned_gate_id <= 0 || !in_array($access_type, ['entry', 'exit', 'both'], true)) {
            parking_setup_redirect('Select a staff account, gate and access type.', 'danger');
        }
        $staff_stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE id=? AND owner_id=? AND role='manager' AND status='active' LIMIT 1");
        mysqli_stmt_bind_param($staff_stmt, 'ii', $assigned_user_id, $company_id);
        mysqli_stmt_execute($staff_stmt);
        $staff = mysqli_fetch_assoc(mysqli_stmt_get_result($staff_stmt));
        mysqli_stmt_close($staff_stmt);
        $gate_stmt = mysqli_prepare($conn, "SELECT id FROM parking_gates WHERE id=? AND company_id=? AND status='active' LIMIT 1");
        mysqli_stmt_bind_param($gate_stmt, 'ii', $assigned_gate_id, $company_id);
        mysqli_stmt_execute($gate_stmt);
        $assigned_gate = mysqli_fetch_assoc(mysqli_stmt_get_result($gate_stmt));
        mysqli_stmt_close($gate_stmt);
        if (!$staff || !$assigned_gate) parking_setup_redirect('Select an active company staff account and gate.', 'danger');
        $stmt = mysqli_prepare($conn, "INSERT INTO parking_gate_staff_assignments (company_id, gate_id, user_id, access_type, status) VALUES (?, ?, ?, ?, 'active') ON DUPLICATE KEY UPDATE access_type=VALUES(access_type), status='active'");
        mysqli_stmt_bind_param($stmt, 'iiis', $company_id, $assigned_gate_id, $assigned_user_id, $access_type);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        parking_setup_redirect('Gate terminal access saved.');
    }

    if ($action === 'toggle_gate_assignment') {
        $assignment_id = (int)($_POST['assignment_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if ($assignment_id <= 0 || !in_array($status, ['active', 'inactive'], true)) parking_setup_redirect('Invalid terminal assignment request.', 'danger');
        $stmt = mysqli_prepare($conn, 'UPDATE parking_gate_staff_assignments SET status=? WHERE id=? AND company_id=?');
        mysqli_stmt_bind_param($stmt, 'sii', $status, $assignment_id, $company_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        parking_setup_redirect('Gate terminal access updated.');
    }

    parking_setup_redirect('Unknown parking setup request.', 'danger');
}

$flash = $_SESSION['parking_setup_flash'] ?? null;
unset($_SESSION['parking_setup_flash']);

$settings_stmt = mysqli_prepare($conn, 'SELECT * FROM parking_settings WHERE company_id=? LIMIT 1');
mysqli_stmt_bind_param($settings_stmt, 'i', $company_id);
mysqli_stmt_execute($settings_stmt);
$settings = mysqli_fetch_assoc(mysqli_stmt_get_result($settings_stmt)) ?: [];
mysqli_stmt_close($settings_stmt);

$vehicle_types = mysqli_query($conn, "SELECT * FROM parking_vehicle_types WHERE company_id={$company_id} ORDER BY vehicle_name ASC");
$active_vehicle_types = mysqli_query($conn, "SELECT id, vehicle_name FROM parking_vehicle_types WHERE company_id={$company_id} AND status='active' ORDER BY vehicle_name ASC");
$rate_plans = mysqli_query($conn, "SELECT r.*, v.vehicle_name FROM parking_rate_plans r INNER JOIN parking_vehicle_types v ON v.id=r.vehicle_type_id AND v.company_id=r.company_id WHERE r.company_id={$company_id} ORDER BY r.status='active' DESC, r.id DESC");
$gates = mysqli_query($conn, "SELECT g.*, COALESCE(NULLIF(b.branch_name, ''), 'Head Office') AS branch_name FROM parking_gates g LEFT JOIN branches b ON b.id=g.branch_id AND b.user_id=g.company_id WHERE g.company_id={$company_id} ORDER BY g.status='active' DESC, g.gate_name ASC");
$branches = mysqli_query($conn, "SELECT id, branch_name FROM branches WHERE user_id={$company_id} AND status='active' AND COALESCE(is_head_office, 0)=0 ORDER BY branch_name ASC");
$parking_staff = mysqli_query($conn, "SELECT id, name, username FROM users WHERE owner_id={$company_id} AND role='manager' AND status='active' ORDER BY name ASC, username ASC");
$assignment_gates = mysqli_query($conn, "SELECT id, gate_name, gate_code, gate_type FROM parking_gates WHERE company_id={$company_id} AND status='active' ORDER BY gate_name ASC");
$gate_assignments = mysqli_query($conn, "SELECT a.*, g.gate_name, g.gate_code, u.name AS staff_name, u.username FROM parking_gate_staff_assignments a INNER JOIN parking_gates g ON g.id=a.gate_id INNER JOIN users u ON u.id=a.user_id WHERE a.company_id={$company_id} ORDER BY a.status='active' DESC, g.gate_name ASC, u.name ASC");

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div id="parking-setup-content">
<?php if ($flash) { ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type'] ?? 'success') ?> alert-dismissible">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        <?= htmlspecialchars($flash['message'] ?? '') ?>
    </div>
<?php } ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="fas fa-cog mr-2"></i>Parking Setup</h4>
    <div><a href="slots.php" class="btn btn-outline-info btn-sm mr-1"><i class="fas fa-th mr-1"></i>Zones & Slots</a><a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left mr-1"></i>Parking Management</a></div>
</div>

<div class="card card-outline card-primary">
    <div class="card-header"><h3 class="card-title">Operation Settings</h3></div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['parking_setup_csrf']) ?>">
        <input type="hidden" name="action" value="save_settings">
        <div class="card-body row">
            <div class="form-group col-md-4"><label>Payment Mode</label><select class="form-control" name="payment_mode"><option value="pay_at_exit" <?= ($settings['payment_mode'] ?? '') === 'pay_at_exit' ? 'selected' : '' ?>>Pay at Exit</option><option value="prepaid_fixed" <?= ($settings['payment_mode'] ?? '') === 'prepaid_fixed' ? 'selected' : '' ?>>Prepaid Fixed Fee</option><option value="hybrid" <?= ($settings['payment_mode'] ?? '') === 'hybrid' ? 'selected' : '' ?>>Hybrid</option><option value="free" <?= ($settings['payment_mode'] ?? '') === 'free' ? 'selected' : '' ?>>Free Parking</option></select></div>
            <div class="form-group col-md-4"><label>Ticket Code</label><select class="form-control" name="barcode_format"><option value="barcode" <?= ($settings['barcode_format'] ?? '') === 'barcode' ? 'selected' : '' ?>>Barcode</option><option value="qr" <?= ($settings['barcode_format'] ?? '') === 'qr' ? 'selected' : '' ?>>QR Code</option><option value="both" <?= ($settings['barcode_format'] ?? '') === 'both' ? 'selected' : '' ?>>Barcode + QR</option></select></div>
            <div class="form-group col-md-4"><label>Grace Minutes</label><input class="form-control" type="number" min="0" max="1440" name="grace_minutes" value="<?= (int)($settings['grace_minutes'] ?? 0) ?>"></div>
            <div class="form-group col-md-4"><label>Lost Ticket Fee</label><input class="form-control" type="number" min="0" step="0.01" name="lost_ticket_fee" value="<?= htmlspecialchars((string)($settings['lost_ticket_fee'] ?? '0.00')) ?>"></div>
            <div class="col-md-3 custom-control custom-switch"><input class="custom-control-input" type="checkbox" id="slot_management_enabled" name="slot_management_enabled" <?= !empty($settings['slot_management_enabled']) ? 'checked' : '' ?>><label class="custom-control-label" for="slot_management_enabled">Enable slot management</label></div>
            <div class="col-md-3 custom-control custom-switch"><input class="custom-control-input" type="checkbox" id="entry_print_enabled" name="entry_print_enabled" <?= !isset($settings['entry_print_enabled']) || !empty($settings['entry_print_enabled']) ? 'checked' : '' ?>><label class="custom-control-label" for="entry_print_enabled">Print entry ticket</label></div>
            <div class="col-md-3 custom-control custom-switch"><input class="custom-control-input" type="checkbox" id="exit_print_enabled" name="exit_print_enabled" <?= !empty($settings['exit_print_enabled']) ? 'checked' : '' ?>><label class="custom-control-label" for="exit_print_enabled">Print exit receipt</label></div>
            <div class="col-md-3 custom-control custom-switch"><input class="custom-control-input" type="checkbox" id="vehicle_number_required" name="vehicle_number_required" <?= !empty($settings['vehicle_number_required']) ? 'checked' : '' ?>><label class="custom-control-label" for="vehicle_number_required">Vehicle number required</label></div>
        </div>
        <div class="card-footer text-right"><button class="btn btn-primary"><i class="fas fa-save mr-1"></i>Save Settings</button></div>
    </form>
</div>

<div class="row">
    <div class="col-lg-6">
        <div class="card card-outline card-info"><div class="card-header"><h3 class="card-title">Vehicle Types</h3></div><div class="card-body">
            <form method="post" class="row mb-4"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['parking_setup_csrf']) ?>"><input type="hidden" name="action" value="add_vehicle_type"><div class="col-md-5 form-group"><label>Name</label><input class="form-control" name="vehicle_name" placeholder="e.g. CNG" required></div><div class="col-md-4 form-group"><label>Code</label><input class="form-control" name="vehicle_code" placeholder="e.g. cng" required></div><div class="col-md-3 form-group"><label>Capacity</label><input class="form-control" type="number" min="0" name="default_capacity" value="0"></div><div class="col-12 text-right"><button class="btn btn-info btn-sm">Add Vehicle Type</button></div></form>
            <div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>Vehicle</th><th>Code</th><th>Capacity</th><th>Status</th><th>Action</th></tr></thead><tbody><?php while ($vehicle = mysqli_fetch_assoc($vehicle_types)) { ?><tr><td><?= htmlspecialchars($vehicle['vehicle_name']) ?></td><td><?= htmlspecialchars($vehicle['vehicle_code']) ?></td><td><?= (int)$vehicle['default_capacity'] ?></td><td><span class="badge badge-<?= $vehicle['status'] === 'active' ? 'success' : 'secondary' ?>"><?= htmlspecialchars($vehicle['status']) ?></span></td><td class="text-nowrap"><form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['parking_setup_csrf']) ?>"><input type="hidden" name="action" value="toggle_vehicle_type"><input type="hidden" name="vehicle_id" value="<?= (int)$vehicle['id'] ?>"><input type="hidden" name="status" value="<?= $vehicle['status'] === 'active' ? 'inactive' : 'active' ?>"><button class="btn btn-xs btn-outline-secondary"><?= $vehicle['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form> <form method="post" class="d-inline" onsubmit="return confirm('Delete this vehicle type?');"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['parking_setup_csrf']) ?>"><input type="hidden" name="action" value="delete_vehicle_type"><input type="hidden" name="vehicle_id" value="<?= (int)$vehicle['id'] ?>"><button class="btn btn-xs btn-outline-danger">Delete</button></form></td></tr><?php } ?></tbody></table></div>
        </div></div>
    </div>
    <div class="col-lg-6">
        <div class="card card-outline card-warning"><div class="card-header"><h3 class="card-title">Rate Plans</h3></div><div class="card-body">
            <form method="post" class="row mb-4"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['parking_setup_csrf']) ?>"><input type="hidden" name="action" value="add_rate_plan"><div class="col-md-6 form-group"><label>Vehicle Type</label><select class="form-control" name="vehicle_type_id" required><option value="">Select</option><?php while ($type = mysqli_fetch_assoc($active_vehicle_types)) { ?><option value="<?= (int)$type['id'] ?>"><?= htmlspecialchars($type['vehicle_name']) ?></option><?php } ?></select></div><div class="col-md-6 form-group"><label>Plan Name</label><input class="form-control" name="plan_name" placeholder="e.g. Standard Car Rate" required></div><div class="col-md-4 form-group"><label>Rate Mode</label><select class="form-control" name="rate_mode"><option value="duration">Duration</option><option value="fixed">Fixed</option><option value="free">Free</option></select></div><div class="col-md-4 form-group"><label>Fixed Fee</label><input class="form-control" type="number" min="0" step="0.01" name="fixed_fee" value="0"></div><div class="col-md-4 form-group"><label>Initial Minutes / Fee</label><div class="input-group"><input class="form-control" type="number" min="0" name="initial_minutes" value="0"><input class="form-control" type="number" min="0" step="0.01" name="initial_fee" value="0"></div></div><div class="col-md-6 form-group"><label>Extra Minutes / Fee</label><div class="input-group"><input class="form-control" type="number" min="1" name="extra_minutes" value="60"><input class="form-control" type="number" min="0" step="0.01" name="extra_fee" value="0"></div></div><div class="col-md-6 form-group"><label>Daily Maximum Fee</label><input class="form-control" type="number" min="0" step="0.01" name="daily_max_fee" value="0"></div><div class="col-12 text-right"><button class="btn btn-warning btn-sm">Add Rate Plan</button></div></form>
            <div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>Plan</th><th>Vehicle</th><th>Mode</th><th>Rate</th><th>Status</th><th>Action</th></tr></thead><tbody><?php while ($rate = mysqli_fetch_assoc($rate_plans)) { ?><tr><td><?= htmlspecialchars($rate['plan_name']) ?></td><td><?= htmlspecialchars($rate['vehicle_name']) ?></td><td><?= htmlspecialchars($rate['rate_mode']) ?></td><td><?= number_format((float)($rate['rate_mode'] === 'fixed' ? $rate['fixed_fee'] : $rate['initial_fee']), 2) ?></td><td><span class="badge badge-<?= $rate['status'] === 'active' ? 'success' : 'secondary' ?>"><?= htmlspecialchars($rate['status']) ?></span></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['parking_setup_csrf']) ?>"><input type="hidden" name="action" value="toggle_rate_plan"><input type="hidden" name="rate_id" value="<?= (int)$rate['id'] ?>"><input type="hidden" name="status" value="<?= $rate['status'] === 'active' ? 'inactive' : 'active' ?>"><button class="btn btn-xs btn-outline-secondary"><?= $rate['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form></td></tr><?php } ?></tbody></table></div>
        </div></div>
    </div>
</div>

<div class="card card-outline card-success"><div class="card-header"><h3 class="card-title">Entry / Exit Gates</h3></div><div class="card-body">
    <form method="post" class="row mb-4"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['parking_setup_csrf']) ?>"><input type="hidden" name="action" value="add_gate"><div class="<?= $parking_multi_branch_enabled ? 'col-md-3' : 'col-md-4' ?> form-group"><label>Gate Name</label><input class="form-control" name="gate_name" placeholder="Main Entry" required></div><div class="<?= $parking_multi_branch_enabled ? 'col-md-2' : 'col-md-3' ?> form-group"><label>Gate Code</label><input class="form-control" name="gate_code" placeholder="ENTRY-1" required></div><div class="col-md-2 form-group"><label>Type</label><select class="form-control" name="gate_type"><option value="entry">Entry</option><option value="exit">Exit</option><option value="both">Both</option></select></div><?php if ($parking_multi_branch_enabled) { ?><div class="col-md-3 form-group"><label>Branch</label><select class="form-control" name="branch_id"><option value="0">Head Office</option><?php while ($branch = mysqli_fetch_assoc($branches)) { ?><option value="<?= (int)$branch['id'] ?>"><?= htmlspecialchars($branch['branch_name']) ?></option><?php } ?></select></div><?php } ?><div class="<?= $parking_multi_branch_enabled ? 'col-md-2' : 'col-md-3' ?> form-group"><label>Terminal Name</label><input class="form-control" name="terminal_name" placeholder="Counter PC 1"></div><div class="col-12 text-right"><button class="btn btn-success btn-sm">Add Gate</button></div></form>
    <div class="table-responsive"><table class="table table-bordered table-sm mb-0"><thead><tr><th>Gate</th><th>Code</th><th>Type</th><?php if ($parking_multi_branch_enabled) { ?><th>Branch</th><?php } ?><th>Terminal</th><th>Status</th><th>Action</th></tr></thead><tbody><?php while ($gate = mysqli_fetch_assoc($gates)) { ?><tr><td><?= htmlspecialchars($gate['gate_name']) ?></td><td><?= htmlspecialchars($gate['gate_code']) ?></td><td><?= htmlspecialchars(ucfirst($gate['gate_type'])) ?></td><?php if ($parking_multi_branch_enabled) { ?><td><?= htmlspecialchars($gate['branch_name']) ?></td><?php } ?><td><?= htmlspecialchars($gate['terminal_name'] ?: '-') ?></td><td><span class="badge badge-<?= $gate['status'] === 'active' ? 'success' : 'secondary' ?>"><?= htmlspecialchars($gate['status']) ?></span></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['parking_setup_csrf']) ?>"><input type="hidden" name="action" value="toggle_gate"><input type="hidden" name="gate_id" value="<?= (int)$gate['id'] ?>"><input type="hidden" name="status" value="<?= $gate['status'] === 'active' ? 'inactive' : 'active' ?>"><button class="btn btn-xs btn-outline-secondary"><?= $gate['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form></td></tr><?php } ?></tbody></table></div>
</div></div>

<div class="card card-outline card-dark"><div class="card-header"><h3 class="card-title">Gate Terminal Staff Access</h3></div><div class="card-body"><p class="text-muted">Assign a staff login to a specific gate. The staff must also have the matching Parking Entry or Parking Exit permission.</p><form method="post" class="row mb-4"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['parking_setup_csrf']) ?>"><input type="hidden" name="action" value="save_gate_assignment"><div class="col-md-4 form-group"><label>Staff Login</label><select class="form-control" name="assigned_user_id" required><option value="">Select staff</option><?php while($staff=mysqli_fetch_assoc($parking_staff)){ ?><option value="<?= (int)$staff['id'] ?>"><?= htmlspecialchars(($staff['name'] ?: $staff['username']) . ' (' . $staff['username'] . ')') ?></option><?php } ?></select></div><div class="col-md-4 form-group"><label>Gate Terminal</label><select class="form-control" name="assigned_gate_id" required><option value="">Select gate</option><?php while($gate=mysqli_fetch_assoc($assignment_gates)){ ?><option value="<?= (int)$gate['id'] ?>"><?= htmlspecialchars($gate['gate_name'].' ('.$gate['gate_code'].' · '.ucfirst($gate['gate_type']).')') ?></option><?php } ?></select></div><div class="col-md-2 form-group"><label>Access</label><select class="form-control" name="access_type"><option value="entry">Entry Only</option><option value="exit">Exit Only</option><option value="both">Both</option></select></div><div class="col-md-2 form-group d-flex align-items-end"><button class="btn btn-dark btn-block">Save Assignment</button></div></form><div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>Staff</th><th>Gate</th><th>Terminal Access</th><th>Status</th><th></th></tr></thead><tbody><?php if(mysqli_num_rows($gate_assignments)===0){ ?><tr><td colspan="5" class="text-center text-muted py-3">No gate terminal assignment yet.</td></tr><?php } ?><?php while($assignment=mysqli_fetch_assoc($gate_assignments)){ ?><tr><td><?= htmlspecialchars(($assignment['staff_name'] ?: $assignment['username']) . ' (' . $assignment['username'] . ')') ?></td><td><?= htmlspecialchars($assignment['gate_name'].' ('.$assignment['gate_code'].')') ?></td><td><?= htmlspecialchars(ucfirst($assignment['access_type'])) ?></td><td><span class="badge badge-<?= $assignment['status']==='active'?'success':'secondary' ?>"><?= htmlspecialchars($assignment['status']) ?></span></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['parking_setup_csrf']) ?>"><input type="hidden" name="action" value="toggle_gate_assignment"><input type="hidden" name="assignment_id" value="<?= (int)$assignment['id'] ?>"><input type="hidden" name="status" value="<?= $assignment['status']==='active'?'inactive':'active' ?>"><button class="btn btn-xs btn-outline-secondary"><?= $assignment['status']==='active'?'Deactivate':'Activate' ?></button></form></td></tr><?php } ?></tbody></table></div></div></div>

</div>
<script>
(function () {
    function showParkingSetupMessage(message, type) {
        var content = document.getElementById('parking-setup-content');
        if (!content) return;
        var alert = document.createElement('div');
        alert.className = 'alert alert-' + (type || 'success') + ' alert-dismissible';
        alert.innerHTML = '<button type="button" class="close" data-dismiss="alert">&times;</button>';
        alert.appendChild(document.createTextNode(message));
        content.insertBefore(alert, content.firstChild);
        alert.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    async function refreshParkingSetupContent() {
        var response = await fetch(window.location.href, { credentials: 'same-origin' });
        var html = await response.text();
        var documentCopy = new DOMParser().parseFromString(html, 'text/html');
        var freshContent = documentCopy.getElementById('parking-setup-content');
        var currentContent = document.getElementById('parking-setup-content');
        if (!freshContent || !currentContent) throw new Error('Unable to refresh parking setup.');
        currentContent.innerHTML = freshContent.innerHTML;
    }

    document.addEventListener('submit', async function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.closest('#parking-setup-content') || form.method.toLowerCase() !== 'post') return;
        if (event.defaultPrevented) return;
        event.preventDefault();
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }
        var button = form.querySelector('button[type="submit"], button:not([type])');
        if (button) button.disabled = true;
        try {
            // The hidden input named "action" shadows the form.action property.
            var response = await fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(form)
            });
            if (!response.ok || !(response.headers.get('content-type') || '').includes('application/json')) {
                throw new Error('Unable to save. Please refresh the page and try again.');
            }
            var result = await response.json();
            if (!result.ok) throw new Error(result.message || 'Unable to save changes.');
            await refreshParkingSetupContent();
            showParkingSetupMessage(result.message, result.type);
        } catch (error) {
            showParkingSetupMessage(error.message || 'Unable to save changes. Please try again.', 'danger');
        } finally {
            if (button) button.disabled = false;
        }
    });
}());
</script>

<?php require_once '../includes/footer.php'; ?>
