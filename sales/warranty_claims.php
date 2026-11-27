<?php
// Warranty: list of warranty claims.
require_once __DIR__ . '/_sw_init.php';

$statuses = sw_warranty_statuses();
$status = (string)($_GET['status'] ?? 'open');
$from = sw_parse_date($_GET['from'] ?? '');
$to = sw_parse_date($_GET['to'] ?? '');

$where = 'w.user_id=' . $user_id . branch_scope_sql($conn, 'w');
if ($status === 'open') {
    $where .= " AND w.status NOT IN ('delivered','cancelled')";
} elseif (isset($statuses[$status])) {
    $where .= " AND w.status='" . mysqli_real_escape_string($conn, $status) . "'";
}
if ($from) {
    $where .= " AND DATE(w.received_at) >= '{$from}'";
}
if ($to) {
    $where .= " AND DATE(w.received_at) <= '{$to}'";
}
$rows = mysqli_query($conn, "SELECT w.*, s.supplier_name FROM warranty_claims w LEFT JOIN suppliers s ON s.id=w.supplier_id AND s.user_id=w.user_id WHERE {$where} ORDER BY w.status IN ('delivered','cancelled') ASC, w.received_at DESC, w.id DESC LIMIT 500");
$counts = [];
$count_result = mysqli_query($conn, 'SELECT status, COUNT(*) n FROM warranty_claims w WHERE w.user_id=' . $user_id . branch_scope_sql($conn, 'w') . ' GROUP BY status');
while ($count_result && ($c = mysqli_fetch_assoc($count_result))) {
    $counts[$c['status']] = (int)$c['n'];
}
$counts['open'] = array_sum(array_intersect_key($counts, array_flip(['received', 'sent_supplier', 'back_supplier', 'ready'])));
$today = date('Y-m-d');

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<section class="content pt-3"><div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <h4 class="mb-2 mb-sm-0"><i class="fas fa-shield-alt mr-2 text-primary"></i>Warranty Claims</h4>
        <a href="warranty_receive.php" class="btn btn-primary btn-sm"><i class="fas fa-plus mr-1"></i>Warranty Receive</a>
    </div>
    <?= sw_flash_html(); ?>
    <ul class="nav nav-pills mb-3 flex-wrap">
        <?php foreach (['open' => 'Open'] + $statuses as $key => $label) { ?>
            <li class="nav-item"><a class="nav-link <?= $status === $key ? 'active' : ''; ?>" href="?status=<?= sw_h($key); ?>"><?= sw_h($label); ?> <span class="badge badge-light"><?= (int)($counts[$key] ?? 0); ?></span></a></li>
        <?php } ?>
        <li class="nav-item"><a class="nav-link <?= $status === 'all' ? 'active' : ''; ?>" href="?status=all">All</a></li>
    </ul>
    <div class="card">
        <div class="card-header">
            <form class="form-row align-items-end" method="get">
                <input type="hidden" name="status" value="<?= sw_h($status); ?>">
                <div class="col-md-3 mb-2"><label class="small mb-1" for="warranty-from">Received From</label><input type="date" id="warranty-from" name="from" class="form-control form-control-sm" value="<?= sw_h($from); ?>"></div>
                <div class="col-md-3 mb-2"><label class="small mb-1" for="warranty-to">Received To</label><input type="date" id="warranty-to" name="to" class="form-control form-control-sm" value="<?= sw_h($to); ?>"></div>
                <div class="col-md-2 mb-2"><button class="btn btn-sm btn-outline-primary btn-block"><i class="fas fa-search mr-1"></i>Search</button></div>
            </form>
        </div>
        <div class="card-body">
            <table class="table table-hover table-sm mb-0" id="warranty-claims-table">
                <thead class="thead-light"><tr><th>Claim</th><th>Customer</th><th>Phone</th><th>Product</th><th>Serial</th><th>Invoice</th><th>Warranty</th><th>Expected</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php while ($rows && ($row = mysqli_fetch_assoc($rows))) {
                    $open = !in_array($row['status'], ['delivered', 'cancelled'], true);
                    $late = $open && $row['expected_date'] && $row['expected_date'] < $today; ?>
                    <tr>
                        <td class="text-nowrap"><a href="warranty_view.php?id=<?= (int)$row['id']; ?>"><strong><?= sw_h($row['claim_no']); ?></strong></a><div class="small text-muted"><?= sw_datetime($row['received_at']); ?></div></td>
                        <td><?= sw_h($row['customer_name']); ?></td>
                        <td class="text-nowrap"><?= trim((string)$row['customer_phone']) !== '' ? sw_h($row['customer_phone']) : '—'; ?></td>
                        <td><?= sw_h($row['product_name']); ?><?= $row['variant_name'] !== '' ? ' <span class="text-muted">(' . sw_h($row['variant_name']) . ')</span>' : ''; ?><div class="small text-muted text-truncate" style="max-width:240px"><?= sw_h($row['problem']); ?></div></td>
                        <td class="text-nowrap"><?= trim((string)$row['serial_no']) !== '' ? sw_h($row['serial_no']) : '—'; ?></td>
                        <td class="text-nowrap"><?= sw_h($row['invoice_no']); ?><div class="small text-muted"><?= sw_date($row['sale_date']); ?></div></td>
                        <td><?= (int)$row['in_warranty'] ? '<span class="badge badge-success">In warranty</span>' : '<span class="badge badge-danger">Out of warranty</span>'; ?></td>
                        <td class="text-nowrap <?= $late ? 'text-danger font-weight-bold' : ''; ?>"><?= sw_date($row['expected_date']); ?></td>
                        <td><span class="badge badge-<?= sw_status_badge($row['status']); ?>"><?= sw_h($statuses[$row['status']] ?? $row['status']); ?></span><?= $row['status'] === 'sent_supplier' && $row['supplier_name'] ? '<div class="small">' . sw_h($row['supplier_name']) . '</div>' : ''; ?></td>
                        <td class="text-nowrap text-right">
                            <a href="warranty_view.php?id=<?= (int)$row['id']; ?>" class="btn btn-xs btn-outline-primary" title="Open"><i class="fas fa-eye"></i></a>
                            <a href="warranty_print.php?id=<?= (int)$row['id']; ?>" class="btn btn-xs btn-outline-secondary" title="Receipt" target="_blank"><i class="fas fa-print"></i></a>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div></section>
<?php
// Show entries + Search (searches every column of the loaded claims, phone and serial included).
$page_script = <<<'SCRIPT'
<script>
$(function () {
    $('#warranty-claims-table').DataTable({
        order: [], pageLength: 25, autoWidth: false, responsive: false,
        lengthMenu: [10, 25, 50, 100],
        columnDefs: [{targets: -1, orderable: false, searchable: false}],
        language: {emptyTable: 'No warranty claim found.', zeroRecords: 'No matching warranty claim.'}
    });
});
</script>
SCRIPT;
require_once __DIR__ . '/../includes/footer.php'; ?>
