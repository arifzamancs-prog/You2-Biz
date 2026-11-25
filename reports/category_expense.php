<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/branch_context_helper.php';
require_once '../includes/report_owner_scope_helper.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id > 0) {
    ensure_branch_accounting_columns($conn, $user_id);
}
$expense_scope = report_owner_scope_sql('e', $user_id) . branch_scope_sql($conn, 'e');

// Date range: today by default (same filters as the Sales Report).
$valid_date = static function ($value) { $d = DateTime::createFromFormat('!Y-m-d', (string)$value); return $d && $d->format('Y-m-d') === $value ? $value : ''; };
$from = $valid_date($_GET['from'] ?? '') ?: date('Y-m-d');
$to = $valid_date($_GET['to'] ?? '') ?: date('Y-m-d');
if ($from > $to) { [$from, $to] = [$to, $from]; }
$report_types = ['summary' => 'Summary', 'category' => 'Category-wise Expense', 'details' => 'Expense Details'];
$type = array_key_exists($_GET['type'] ?? '', $report_types) ? $_GET['type'] : 'summary';

$rows = function ($sql, $types, $params) use ($conn) {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return [];
    if ($types !== '') mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
};
$in_range = "e.txn_date BETWEEN ? AND ?";
$approved = "e.approval_status='approved'";

// Cards: approved expenses of the period, plus what still waits for approval.
$totals = $rows(
    "SELECT COUNT(CASE WHEN {$approved} THEN 1 END) AS entries,
            COALESCE(SUM(CASE WHEN {$approved} THEN e.amount END),0) AS total,
            COUNT(DISTINCT CASE WHEN {$approved} THEN e.category_id END) AS categories,
            COALESCE(SUM(CASE WHEN e.approval_status='pending' THEN e.amount END),0) AS pending,
            COUNT(CASE WHEN e.approval_status='pending' THEN 1 END) AS pending_entries
     FROM expenses e WHERE 1=1 {$expense_scope} AND {$in_range}",
    'ss', [$from, $to]
)[0] ?? ['entries' => 0, 'total' => 0, 'categories' => 0, 'pending' => 0, 'pending_entries' => 0];
$total = (float)$totals['total'];
$days = (int)(new DateTime($from))->diff(new DateTime($to))->days + 1;
$daily_average = $days > 0 ? $total / $days : 0;

$by_category = $rows(
    "SELECT COALESCE(NULLIF(c.category_name,''), 'Uncategorized') AS category_name, COUNT(*) AS entries, SUM(e.amount) AS amount
     FROM expenses e LEFT JOIN categories c ON c.id=e.category_id
     WHERE 1=1 {$expense_scope} AND {$approved} AND {$in_range}
     GROUP BY e.category_id, c.category_name ORDER BY amount DESC",
    'ss', [$from, $to]
);
$top_category = $by_category[0] ?? null;

$data = [];
if ($type === 'summary') {
    $data['daily'] = $rows(
        "SELECT e.txn_date AS day, COUNT(*) AS entries, SUM(e.amount) AS amount
         FROM expenses e WHERE 1=1 {$expense_scope} AND {$approved} AND {$in_range}
         GROUP BY e.txn_date ORDER BY e.txn_date",
        'ss', [$from, $to]
    );
    $data['wallets'] = $rows(
        "SELECT COALESCE(w.wallet_name, 'Unknown wallet') AS wallet_name, COUNT(*) AS entries, SUM(e.amount) AS amount
         FROM expenses e LEFT JOIN wallets w ON w.id=e.wallet_id
         WHERE 1=1 {$expense_scope} AND {$approved} AND {$in_range}
         GROUP BY e.wallet_id, w.wallet_name ORDER BY amount DESC",
        'ss', [$from, $to]
    );
} elseif ($type === 'details') {
    $data['expenses'] = $rows(
        "SELECT e.id, e.txn_no, e.txn_date, e.amount, e.note, e.approval_status,
                COALESCE(NULLIF(c.category_name,''), 'Uncategorized') AS category_name, w.wallet_name
         FROM expenses e
         LEFT JOIN categories c ON c.id=e.category_id
         LEFT JOIN wallets w ON w.id=e.wallet_id
         WHERE 1=1 {$expense_scope} AND {$in_range} AND e.approval_status IN ('approved','pending')
         ORDER BY e.txn_date DESC, e.id DESC LIMIT 1000",
        'ss', [$from, $to]
    );
}

$company = $rows('SELECT name, address, phone FROM users WHERE id=?', 'i', [$user_id])[0] ?? [];
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$money = static fn($v) => number_format((float)$v, 2);
$period = app_date($from) . ' – ' . app_date($to);
$link = static fn($f, $t) => '?' . http_build_query(['type' => $GLOBALS['type'], 'from' => $f, 'to' => $t]);
$show_chart = $type !== 'details' && $by_category;

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<section class="content"><div class="container-fluid">

<div class="card report-filter"><div class="card-header"><h3 class="card-title"><i class="fas fa-chart-pie mr-2"></i>Expense Report</h3></div><div class="card-body">
<form method="get"><div class="row align-items-end">
    <div class="col-md-4 form-group"><label for="er-type">Report type</label><select id="er-type" name="type" class="form-control"><?php foreach ($report_types as $key => $label) { ?><option value="<?= $key ?>" <?= $type === $key ? 'selected' : '' ?>><?= $h($label) ?></option><?php } ?></select></div>
    <div class="col-md-3 form-group"><label for="er-from">From</label><input id="er-from" type="date" name="from" class="form-control" value="<?= $h($from) ?>" max="<?= date('Y-m-d') ?>"></div>
    <div class="col-md-3 form-group"><label for="er-to">To</label><input id="er-to" type="date" name="to" class="form-control" value="<?= $h($to) ?>" max="<?= date('Y-m-d') ?>"></div>
    <div class="col-md-2 form-group"><button class="btn btn-primary btn-block"><i class="fas fa-search mr-1"></i>Search</button></div>
</div></form>
<div class="d-flex flex-wrap" style="gap:6px">
<?php
// Quick ranges; the one matching the dates on screen is highlighted.
foreach ([
    'Today' => [date('Y-m-d'), date('Y-m-d')],
    'Yesterday' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
    'Last 7 days' => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
    'This Month' => [date('Y-m-01'), date('Y-m-d')],
    'Last Month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    'This Year' => [date('Y-01-01'), date('Y-m-d')],
] as $quick_label => [$quick_from, $quick_to]) { $quick_active = $quick_from === $from && $quick_to === $to; ?>
    <a class="btn btn-sm <?= $quick_active ? 'btn-secondary active' : 'btn-outline-secondary' ?>" href="<?= $h($link($quick_from, $quick_to)) ?>"><?= $h($quick_label) ?></a>
<?php } ?>
    <button type="button" onclick="window.print()" class="btn btn-sm btn-info ml-auto"><i class="fas fa-print mr-1"></i>Print</button>
</div>
</div></div>

<div class="report-print-heading d-none">
    <div class="print-company"><?= $h($company['name'] ?? '') ?></div>
    <?php if (!empty($company['address']) || !empty($company['phone'])) { ?><div class="print-company-meta"><?= $h(trim(($company['address'] ?? '') . (!empty($company['phone']) ? ' · ' . $company['phone'] : ''), ' ·')) ?></div><?php } ?>
    <div class="print-title">Expense Report — <?= $h($report_types[$type]) ?></div>
    <div class="print-meta">Period: <?= $h($period) ?><?= branch_scope_sql($conn, 'e') !== '' ? ' · Branch: ' . $h(current_branch_label($conn)) : '' ?> · Printed: <?= date('d M Y, h:i A') ?></div>
</div>

<div class="row sr-cards">
    <div class="col-6 col-lg"><div class="small-box bg-danger"><div class="inner"><h4>BDT <?= $money($total) ?></h4><p>Total Expense · <?= (int)$totals['entries'] ?> entries</p></div></div></div>
    <div class="col-6 col-lg"><div class="small-box bg-primary"><div class="inner"><h4><?= (int)$totals['categories'] ?></h4><p>Categories</p></div></div></div>
    <div class="col-6 col-lg"><div class="small-box bg-info"><div class="inner"><h4>BDT <?= $money($daily_average) ?></h4><p>Daily Average · <?= $days ?> days</p></div></div></div>
    <div class="col-6 col-lg"><div class="small-box bg-success"><div class="inner"><h4><?= $top_category ? 'BDT ' . $money($top_category['amount']) : '—' ?></h4><p>Top: <?= $h($top_category['category_name'] ?? 'No expense') ?></p></div></div></div>
    <div class="col-6 col-lg"><div class="small-box bg-warning"><div class="inner"><h4>BDT <?= $money($totals['pending']) ?></h4><p>Pending Approval · <?= (int)$totals['pending_entries'] ?></p></div></div></div>
</div>

<?php if ($type === 'summary') { ?>
<div class="row">
    <div class="col-lg-7">
        <div class="card"><div class="card-header"><h3 class="card-title">Day-wise Expense</h3></div><div class="card-body table-responsive p-0">
        <table class="table table-bordered table-striped table-sm mb-0"><thead><tr><th>Date</th><th class="text-right">Entries</th><th class="text-right">Amount</th></tr></thead><tbody>
        <?php $sum = ['entries' => 0, 'amount' => 0]; foreach ($data['daily'] as $row) { $sum['entries'] += (int)$row['entries']; $sum['amount'] += (float)$row['amount']; ?>
        <tr><td><?= $h(app_date($row['day'])) ?></td><td class="text-right"><?= (int)$row['entries'] ?></td><td class="text-right"><?= $money($row['amount']) ?></td></tr>
        <?php } if (!$data['daily']) { ?><tr><td colspan="3" class="text-center text-muted py-3">No approved expenses in this period.</td></tr><?php } ?>
        </tbody><tfoot><tr><th>Total</th><th class="text-right"><?= (int)$sum['entries'] ?></th><th class="text-right"><?= $money($sum['amount']) ?></th></tr></tfoot></table>
        </div></div>

        <div class="card"><div class="card-header"><h3 class="card-title">Expense by Payment Wallet</h3></div><div class="card-body table-responsive p-0">
        <table class="table table-bordered table-striped table-sm mb-0"><thead><tr><th>Paid from</th><th class="text-right">Entries</th><th class="text-right">Amount</th></tr></thead><tbody>
        <?php foreach ($data['wallets'] as $row) { ?><tr><td><?= $h($row['wallet_name']) ?></td><td class="text-right"><?= (int)$row['entries'] ?></td><td class="text-right"><?= $money($row['amount']) ?></td></tr><?php } if (!$data['wallets']) { ?><tr><td colspan="3" class="text-center text-muted py-3">No data.</td></tr><?php } ?>
        </tbody></table>
        </div></div>
    </div>
    <div class="col-lg-5">
        <div class="card"><div class="card-header"><h3 class="card-title">Expense Distribution</h3></div><div class="card-body">
            <?php if ($show_chart) { ?><div style="height:320px"><canvas id="expenseChart"></canvas></div><?php } else { ?><p class="text-center text-muted mb-0">No approved expenses in this period.</p><?php } ?>
        </div></div>
    </div>
</div>

<?php } elseif ($type === 'category') { ?>
<div class="row">
    <div class="col-lg-7">
        <div class="card"><div class="card-header"><h3 class="card-title">Category-wise Expense</h3></div><div class="card-body table-responsive p-0">
        <table class="table table-bordered table-striped table-sm mb-0"><thead><tr><th>#</th><th>Category</th><th class="text-right">Entries</th><th class="text-right">Amount</th><th class="text-right">Share</th></tr></thead><tbody>
        <?php foreach ($by_category as $n => $row) { ?>
        <tr><td><?= $n + 1 ?></td><td><?= $h($row['category_name']) ?></td><td class="text-right"><?= (int)$row['entries'] ?></td><td class="text-right"><?= $money($row['amount']) ?></td><td class="text-right"><?= $total > 0 ? number_format((float)$row['amount'] * 100 / $total, 1) . '%' : '—' ?></td></tr>
        <?php } if (!$by_category) { ?><tr><td colspan="5" class="text-center text-muted py-3">No approved expenses in this period.</td></tr><?php } ?>
        </tbody><tfoot><tr><th colspan="2">Total</th><th class="text-right"><?= (int)$totals['entries'] ?></th><th class="text-right"><?= $money($total) ?></th><th class="text-right"><?= $total > 0 ? '100%' : '—' ?></th></tr></tfoot></table>
        </div></div>
    </div>
    <div class="col-lg-5">
        <div class="card"><div class="card-header"><h3 class="card-title">Expense Distribution</h3></div><div class="card-body">
            <?php if ($show_chart) { ?><div style="height:320px"><canvas id="expenseChart"></canvas></div><?php } else { ?><p class="text-center text-muted mb-0">No approved expenses in this period.</p><?php } ?>
        </div></div>
    </div>
</div>

<?php } else { ?>
<div class="card"><div class="card-header"><h3 class="card-title">Expense Details</h3><?php if (count($data['expenses']) >= 1000) { ?><span class="float-right small text-muted">Showing the latest 1000 entries — narrow the dates to see all.</span><?php } ?></div><div class="card-body table-responsive p-0">
<table class="table table-bordered table-striped table-sm mb-0"><thead><tr><th>Date</th><th>Expense No</th><th>Category</th><th>Paid from</th><th>Note</th><th class="text-right">Amount</th></tr></thead><tbody>
<?php $sum = 0; foreach ($data['expenses'] as $row) { $pending = $row['approval_status'] !== 'approved'; if (!$pending) $sum += (float)$row['amount']; ?>
<tr class="<?= $pending ? 'table-warning' : '' ?>"><td><?= $h(app_date($row['txn_date'])) ?></td><td><?= $h($row['txn_no'] ?: ('#' . $row['id'])) ?><?= $pending ? ' <span class="badge badge-warning">Pending</span>' : '' ?></td><td><?= $h($row['category_name']) ?></td><td><?= $h($row['wallet_name'] ?: '—') ?></td><td><?= $h($row['note'] ?: '—') ?></td><td class="text-right"><?= $money($row['amount']) ?></td></tr>
<?php } if (!$data['expenses']) { ?><tr><td colspan="6" class="text-center text-muted py-3">No expenses in this period.</td></tr><?php } ?>
</tbody><tfoot><tr><th colspan="5">Total (approved)</th><th class="text-right"><?= $money($sum) ?></th></tr></tfoot></table>
</div></div>
<?php } ?>

</div></section>
<?php
$chart_script = $show_chart ? '<script>
$(function(){
    var canvas = document.getElementById("expenseChart");
    if (!canvas || !window.Chart) return;
    new Chart(canvas, {
        type: "doughnut",
        data: { labels: ' . json_encode(array_column($by_category, 'category_name'), JSON_HEX_TAG | JSON_HEX_APOS) . ', datasets: [{ data: ' . json_encode(array_map('floatval', array_column($by_category, 'amount'))) . ', backgroundColor: ["#dc3545","#007bff","#28a745","#ffc107","#17a2b8","#6f42c1","#fd7e14","#20c997","#e83e8c","#6c757d"] }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: "bottom" } } }
    });
});
</script>' : '';

$page_script = $chart_script . '<style>
.sr-cards .small-box h4{font-size:1.35rem;font-weight:700;margin:0}.sr-cards .small-box p{margin:4px 0 0}
@page{size:A4 portrait;margin:10mm}
@media print{
 html,body{background:#fff!important;color:#111!important;font-size:10.5px!important}
 .main-header,.main-sidebar,.main-footer,.report-filter,.btn{display:none!important}
 .content-wrapper,.content,.container-fluid{margin:0!important;padding:0!important;width:100%!important;min-height:0!important}
 .report-print-heading{display:block!important;text-align:center;border-bottom:2px solid #111;padding-bottom:8px;margin-bottom:10px}
 .print-company{font-size:18px;font-weight:700}.print-company-meta{font-size:10px;color:#444}
 .print-title{font-size:14px;font-weight:700;margin-top:6px}.print-meta{font-size:10px;color:#444;margin-top:2px}
 .sr-cards{display:flex!important;flex-wrap:nowrap!important;margin:0 -3px 8px!important}.sr-cards>div{flex:1 1 0!important;max-width:none!important;padding:0 3px!important}
 .small-box{background:#fff!important;color:#111!important;border:1px solid #999!important;box-shadow:none!important;margin:0!important}.small-box h4,.small-box p{color:#111!important}.small-box h4{font-size:13px!important}.small-box p{font-size:9px!important}
 .row{display:flex!important;flex-wrap:wrap!important}.col-lg-7{flex:0 0 58.33%!important;max-width:58.33%!important}.col-lg-5{flex:0 0 41.67%!important;max-width:41.67%!important}
 .card{box-shadow:none!important;border:1px solid #999!important;border-radius:0!important;margin-bottom:8px!important}
 .card-header{background:#eee!important;padding:5px 8px!important}.card-title{font-size:12px!important;font-weight:700}
 canvas{max-height:220px!important}
 table{font-size:9.5px!important;width:100%!important}th,td{padding:3px 5px!important;border:1px solid #999!important}th{background:#f2f2f2!important}
 tr{break-inside:avoid}.table-warning td{background:#fff6da!important}
}</style>';
require_once '../includes/footer.php';
?>
