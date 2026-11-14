<?php
// Service Center: list of service jobs.
require_once __DIR__ . '/_sw_init.php';

$statuses = sw_service_statuses();
$status = (string)($_GET['status'] ?? 'open');
$q = trim((string)($_GET['q'] ?? ''));
$from = sw_parse_date($_GET['from'] ?? '');
$to = sw_parse_date($_GET['to'] ?? '');

$where = 'j.user_id=' . $user_id . branch_scope_sql($conn, 'j');
if ($status === 'open') {
    $where .= " AND j.status IN ('received','ready')";
} elseif (isset($statuses[$status])) {
    $where .= " AND j.status='" . mysqli_real_escape_string($conn, $status) . "'";
}
if ($q !== '') {
    $like = "'%" . mysqli_real_escape_string($conn, $q) . "%'";
    $where .= " AND (j.job_no LIKE {$like} OR j.customer_name LIKE {$like} OR j.customer_phone LIKE {$like} OR j.item_name LIKE {$like} OR j.brand_model LIKE {$like} OR j.serial_no LIKE {$like})";
}
if ($from) {
    $where .= " AND DATE(j.received_at) >= '{$from}'";
}
if ($to) {
    $where .= " AND DATE(j.received_at) <= '{$to}'";
}
$rows = mysqli_query($conn, "SELECT j.*, i.invoice_no, i.total_amount AS invoice_total FROM service_jobs j LEFT JOIN invoices i ON i.id=j.invoice_id AND i.user_id=j.user_id WHERE {$where} ORDER BY j.status IN ('received','ready') DESC, j.delivery_date ASC, j.id DESC LIMIT 500");
$counts = [];
$count_result = mysqli_query($conn, 'SELECT status, COUNT(*) n FROM service_jobs j WHERE j.user_id=' . $user_id . branch_scope_sql($conn, 'j') . ' GROUP BY status');
while ($count_result && ($c = mysqli_fetch_assoc($count_result))) {
    $counts[$c['status']] = (int)$c['n'];
}
$counts['open'] = ($counts['received'] ?? 0) + ($counts['ready'] ?? 0);
$today = date('Y-m-d');

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<section class="content pt-3"><div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <h4 class="mb-2 mb-sm-0"><i class="fas fa-tools mr-2 text-primary"></i>Service Jobs</h4>
        <a href="service_receive.php" class="btn btn-primary btn-sm"><i class="fas fa-plus mr-1"></i>Receive for Service</a>
    </div>
    <?= sw_flash_html(); ?>
    <ul class="nav nav-pills mb-3">
        <?php foreach (['open' => 'Open'] + $statuses as $key => $label) { ?>
            <li class="nav-item"><a class="nav-link <?= $status === $key ? 'active' : ''; ?>" href="?status=<?= sw_h($key); ?>"><?= sw_h($label); ?> <span class="badge badge-light"><?= (int)($counts[$key] ?? 0); ?></span></a></li>
        <?php } ?>
        <li class="nav-item"><a class="nav-link <?= $status === 'all' ? 'active' : ''; ?>" href="?status=all">All</a></li>
    </ul>
    <div class="card">
        <div class="card-header">
            <form class="form-row align-items-end" method="get">
                <input type="hidden" name="status" value="<?= sw_h($status); ?>">
                <div class="col-md-4 mb-2"><input name="q" class="form-control form-control-sm" value="<?= sw_h($q); ?>" placeholder="Job no, customer, phone, product, serial"></div>
                <div class="col-md-2 mb-2"><input type="date" name="from" class="form-control form-control-sm" value="<?= sw_h($from); ?>" title="Received from"></div>
                <div class="col-md-2 mb-2"><input type="date" name="to" class="form-control form-control-sm" value="<?= sw_h($to); ?>" title="Received to"></div>
                <div class="col-md-2 mb-2"><button class="btn btn-sm btn-outline-primary btn-block"><i class="fas fa-search mr-1"></i>Search</button></div>
            </form>
        </div>
        <div class="card-body table-responsive p-0">
            <table class="table table-hover table-sm mb-0">
                <thead class="thead-light"><tr><th>Job</th><th>Customer</th><th>Product &amp; Problem</th><th>Delivery</th><th class="text-right">Charge</th><th class="text-right">Advance</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php $shown = 0; while ($rows && ($row = mysqli_fetch_assoc($rows))) { $shown++;
                    $open = in_array($row['status'], ['received', 'ready'], true);
                    $late = $open && $row['delivery_date'] && $row['delivery_date'] < $today; ?>
                    <tr>
                        <td class="text-nowrap"><a href="service_view.php?id=<?= (int)$row['id']; ?>"><strong><?= sw_h($row['job_no']); ?></strong></a><div class="small text-muted"><?= sw_datetime($row['received_at']); ?></div></td>
                        <td><?= sw_h($row['customer_name']); ?><div class="small text-muted"><?= sw_h($row['customer_phone']); ?><?= (int)$row['customer_id'] === 0 ? ' · Walk-in' : ''; ?></div></td>
                        <td><?= sw_h(trim($row['item_name'] . ' ' . $row['brand_model'])); ?><div class="small text-muted text-truncate" style="max-width:260px"><?= sw_h($row['problem']); ?></div></td>
                        <td class="text-nowrap <?= $late ? 'text-danger font-weight-bold' : ''; ?>"><?= sw_date($row['delivery_date']); ?><?= $late ? '<div class="small">Overdue</div>' : ''; ?></td>
                        <td class="text-right"><?= $row['status'] === 'delivered' ? sw_amount($row['invoice_total']) : '<span class="text-muted">est. ' . sw_amount($row['estimated_charge']) . '</span>'; ?></td>
                        <td class="text-right"><?= (float)$row['advance_amount'] > 0 ? sw_amount($row['advance_amount']) : '—'; ?></td>
                        <td><span class="badge badge-<?= sw_status_badge($row['status']); ?>"><?= sw_h($statuses[$row['status']] ?? $row['status']); ?></span><?= $row['invoice_no'] ? '<div class="small">' . sw_h($row['invoice_no']) . '</div>' : ''; ?></td>
                        <td class="text-nowrap text-right">
                            <a href="service_view.php?id=<?= (int)$row['id']; ?>" class="btn btn-xs btn-outline-primary" title="Open"><i class="fas fa-eye"></i></a>
                            <a href="service_print.php?id=<?= (int)$row['id']; ?>" class="btn btn-xs btn-outline-secondary" title="Receipt" target="_blank"><i class="fas fa-print"></i></a>
                        </td>
                    </tr>
                <?php } ?>
                <?php if (!$shown) { ?><tr><td colspan="8" class="text-center text-muted py-4">No service job found.</td></tr><?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div></section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
