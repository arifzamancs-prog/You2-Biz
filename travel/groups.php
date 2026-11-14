<?php
require_once __DIR__ . '/_init.php';

$error = '';
$edit_id = (int)($_GET['edit'] ?? ($_POST['group_id'] ?? 0));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    travel_verify_csrf();
    try {
        $saved_id = travel_group_save($conn, $user_id, $_POST, $edit_id);
        header('Location: group_view.php?id=' . $saved_id . '&saved=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$form = $_POST ?: ($edit_id > 0 ? (travel_group_get($conn, $user_id, $edit_id) ?: []) : ['service_kind' => 'hajj_umrah', 'status' => 'open']);
$value = static fn($key, $default = '') => htmlspecialchars((string)($form[$key] ?? $default));
$vendors = travel_vendors($conn, $user_id);
$packages = [];
$package_result = mysqli_query($conn, "SELECT pk.id, pk.package_name, pr.project_name FROM packages pk INNER JOIN projects pr ON pr.id=pk.project_id AND pr.user_id=pk.user_id WHERE pk.user_id={$user_id} AND pk.status='active' ORDER BY pr.project_name, pk.package_name");
while ($package_result && ($row = mysqli_fetch_assoc($package_result))) {
    $packages[] = $row;
}
$show_closed = !empty($_GET['closed']);
$groups = travel_groups($conn, $user_id, !$show_closed);
// Sale, paid and due of each group's bookings.
$money = [];
$result = mysqli_query($conn, "SELECT b.group_id, SUM(" . travel_effective_price_sql() . ") AS sold, SUM(COALESCE((SELECT SUM(p.amount) FROM travel_client_payments p WHERE p.booking_id=b.id),0)) AS paid
    FROM travel_bookings b WHERE b.user_id={$user_id} AND b.group_id>0 GROUP BY b.group_id");
while ($result && ($row = mysqli_fetch_assoc($result))) {
    $money[(int)$row['group_id']] = $row;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<section class="content pt-3"><div class="container-fluid"><div class="row">
    <div class="col-xl-4">
        <div class="card card-outline card-success">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-kaaba mr-2"></i><?= $edit_id > 0 ? 'Edit Group' : 'New Group'; ?></h3>
                <?php if ($edit_id > 0) { ?><div class="card-tools"><a href="groups.php" class="btn btn-sm btn-outline-secondary">New</a></div><?php } ?></div>
            <div class="card-body">
                <?php if ($error !== '') { ?><div class="alert alert-danger"><?= htmlspecialchars($error); ?></div><?php } ?>
                <form method="post">
                    <?= travel_csrf_field(); ?>
                    <input type="hidden" name="group_id" value="<?= $edit_id; ?>">
                    <div class="form-group"><label for="group_name">Group Name</label><input id="group_name" name="group_name" class="form-control" maxlength="150" placeholder="Umrah February 2027 – 14 Days" value="<?= $value('group_name'); ?>" required></div>
                    <div class="form-row">
                        <div class="form-group col-6"><label for="service_kind">Type</label><select id="service_kind" name="service_kind" class="form-control"><option value="hajj_umrah"<?= ($form['service_kind'] ?? '') === 'hajj_umrah' ? ' selected' : ''; ?>>Hajj & Umrah</option><option value="tour"<?= ($form['service_kind'] ?? '') === 'tour' ? ' selected' : ''; ?>>Tour Package</option></select></div>
                        <div class="form-group col-6"><label for="package_id">Package</label><select id="package_id" name="package_id" class="form-control"><option value="0">—</option><?php foreach ($packages as $package) { ?><option value="<?= (int)$package['id']; ?>"<?= (int)($form['package_id'] ?? 0) === (int)$package['id'] ? ' selected' : ''; ?>><?= htmlspecialchars($package['project_name'] . ' · ' . $package['package_name']); ?></option><?php } ?></select></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-6"><label for="departure_date">Departure</label><input id="departure_date" type="date" name="departure_date" class="form-control" value="<?= $value('departure_date'); ?>"></div>
                        <div class="form-group col-6"><label for="return_date">Return</label><input id="return_date" type="date" name="return_date" class="form-control" value="<?= $value('return_date'); ?>"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-4"><label for="seats">Seats</label><input id="seats" type="number" min="0" name="seats" class="form-control" value="<?= $value('seats', '40'); ?>"><small class="form-text text-muted">0 = no limit</small></div>
                        <div class="form-group col-4"><label for="price_per_person">Price / person</label><input id="price_per_person" type="number" step="0.01" min="0" name="price_per_person" class="form-control" value="<?= $value('price_per_person'); ?>"></div>
                        <div class="form-group col-4"><label for="cost_per_person">Cost / person</label><input id="cost_per_person" type="number" step="0.01" min="0" name="cost_per_person" class="form-control" value="<?= $value('cost_per_person'); ?>"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-6"><label for="vendor_id">Vendor</label><select id="vendor_id" name="vendor_id" class="form-control"><option value="0">—</option><?php foreach ($vendors as $vendor) { ?><option value="<?= (int)$vendor['id']; ?>"<?= (int)($form['vendor_id'] ?? 0) === (int)$vendor['id'] ? ' selected' : ''; ?>><?= htmlspecialchars($vendor['supplier_name']); ?></option><?php } ?></select></div>
                        <div class="form-group col-6"><label for="airline">Airline</label><input id="airline" name="airline" class="form-control" maxlength="100" value="<?= $value('airline'); ?>"></div>
                    </div>
                    <div class="form-group"><label for="hotel_info">Hotels</label><input id="hotel_info" name="hotel_info" class="form-control" maxlength="300" placeholder="Makkah: … (5 nights), Madinah: … (4 nights)" value="<?= $value('hotel_info'); ?>"></div>
                    <div class="form-group"><label for="note">Note</label><input id="note" name="note" class="form-control" maxlength="500" value="<?= $value('note'); ?>"></div>
                    <div class="form-group"><div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input" id="status" name="status" value="closed"<?= ($form['status'] ?? 'open') === 'closed' ? ' checked' : ''; ?>><label class="custom-control-label" for="status">Closed (no new booking)</label></div></div>
                    <button class="btn btn-success"><i class="fas fa-save mr-1"></i>Save Group</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-users mr-2"></i>Hajj, Umrah & Tour Groups</h3>
                <div class="card-tools"><a href="?<?= $show_closed ? '' : 'closed=1'; ?>" class="btn btn-sm btn-outline-secondary"><?= $show_closed ? 'Open groups only' : 'Show closed too'; ?></a></div></div>
            <div class="card-body p-0"><div class="table-responsive"><table class="table table-striped table-sm mb-0">
                <thead><tr><th>Group</th><th>Departure</th><th class="text-center">Seats</th><th class="text-right">Sale</th><th class="text-right">Due</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($groups as $group) { $m = $money[(int)$group['id']] ?? ['sold' => 0, 'paid' => 0]; $due = (float)$m['sold'] - (float)$m['paid']; ?>
                    <tr>
                        <td><a href="group_view.php?id=<?= (int)$group['id']; ?>"><strong><?= htmlspecialchars($group['group_name']); ?></strong></a>
                            <div class="small text-muted"><?= htmlspecialchars($group['group_code'] . ' · ' . ($group['service_kind'] === 'tour' ? 'Tour' : 'Hajj & Umrah')); ?><?= $group['status'] === 'closed' ? ' · <span class="badge badge-secondary">Closed</span>' : ''; ?></div></td>
                        <td class="text-nowrap"><?= $group['departure_date'] ? htmlspecialchars(date('d M Y', strtotime($group['departure_date']))) : '—'; ?></td>
                        <td class="text-center"><?= (int)$group['seats_used']; ?> / <?= (int)$group['seats'] ?: '∞'; ?><?php if ((int)$group['seats'] > 0) { ?><div class="progress progress-xs mt-1"><div class="progress-bar bg-<?= $group['seats_left'] === 0 ? 'danger' : 'success'; ?>" style="width:<?= min(100, round($group['seats_used'] / max(1, (int)$group['seats']) * 100)); ?>%"></div></div><?php } ?></td>
                        <td class="text-right"><?= travel_money($m['sold']); ?></td>
                        <td class="text-right <?= $due > 0 ? 'text-danger' : ''; ?>"><?= travel_money(max(0, $due)); ?></td>
                        <td class="text-nowrap"><a class="btn btn-xs btn-outline-primary" href="group_view.php?id=<?= (int)$group['id']; ?>">Open</a> <a class="btn btn-xs btn-outline-secondary" href="?edit=<?= (int)$group['id']; ?>">Edit</a></td>
                    </tr>
                <?php } ?>
                <?php if (!$groups) { ?><tr><td colspan="6" class="text-center text-muted py-4">No group yet. Create the first one on the left.</td></tr><?php } ?>
                </tbody>
            </table></div></div>
        </div>
    </div>
</div></div></section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
