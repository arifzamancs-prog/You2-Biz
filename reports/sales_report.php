<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/booking_invoice_helper.php';
require_once '../includes/branch_context_helper.php';
require_once '../includes/report_owner_scope_helper.php';

$user_id = (int)$_SESSION['user_id'];
ensure_invoice_posting_columns($conn);
ensure_booking_invoice_table($conn);
if($user_id > 0){ ensure_booking_invoice_type_table($conn, $user_id); ensure_branch_accounting_columns($conn, $user_id); }

$invoice_scope = report_owner_scope_sql('i', $user_id) . branch_scope_sql($conn, 'i');
$booking_scope = report_owner_scope_sql('bi', $user_id) . branch_scope_sql($conn, 'bi');

// Date range: this month so far by default.
$valid_date = static function($value){ $d = DateTime::createFromFormat('!Y-m-d', (string)$value); return $d && $d->format('Y-m-d') === $value ? $value : ''; };
$from = $valid_date($_GET['from'] ?? '') ?: date('Y-m-01');
$to = $valid_date($_GET['to'] ?? '') ?: date('Y-m-d');
if($from > $to){ [$from, $to] = [$to, $from]; }
$report_types = ['summary' => 'Summary', 'product' => 'Product-wise Sales', 'invoice' => 'Invoice Details (with products)', 'customer' => 'Customer-wise Sales'];
$type = array_key_exists($_GET['type'] ?? '', $report_types) ? $_GET['type'] : 'summary';
$search = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);

$rows = function($sql, $types, $params) use ($conn){
    $stmt = mysqli_prepare($conn, $sql);
    if(!$stmt) return [];
    if($types !== '') mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
};
$posted = "COALESCE(i.accounting_status,'posted')='posted' AND i.invoice_date BETWEEN ? AND ?";
$is_return = "(i.total_amount < 0 OR i.created_by_type='sales_refund')";

// Totals for the cards (every report type shows them).
$totals = $rows(
    "SELECT COUNT(CASE WHEN NOT {$is_return} THEN 1 END) AS invoices,
            COALESCE(SUM(CASE WHEN NOT {$is_return} THEN i.total_amount END),0) AS gross,
            COALESCE(SUM(CASE WHEN {$is_return} THEN -i.total_amount END),0) AS returns,
            COALESCE(SUM(CASE WHEN NOT {$is_return} THEN i.paid_amount END),0) AS paid,
            COALESCE(SUM(CASE WHEN NOT {$is_return} AND i.due_amount>0 THEN i.due_amount END),0) AS due
     FROM invoices i WHERE 1=1 {$invoice_scope} AND {$posted}",
    'ss', [$from, $to]
)[0] ?? ['invoices' => 0, 'gross' => 0, 'returns' => 0, 'paid' => 0, 'due' => 0];
$booking = $rows(
    "SELECT COUNT(*) AS invoices, COALESCE(SUM(bi.amount),0) AS amount FROM booking_invoices bi
     LEFT JOIN booking_invoice_types bit ON bit.user_id=bi.user_id AND bit.type_key=bi.invoice_type
     WHERE 1=1 {$booking_scope} AND bi.status='confirmed' AND COALESCE(bit.behavior,'income')='income' AND bi.invoice_date BETWEEN ? AND ?",
    'ss', [$from, $to]
)[0] ?? ['invoices' => 0, 'amount' => 0];
$gross = (float)$totals['gross'] + (float)$booking['amount'];
$returns = (float)$totals['returns'];
$net = $gross - $returns;
$paid = (float)$totals['paid'] + (float)$booking['amount'];
$due = (float)$totals['due'];
$invoice_count = (int)$totals['invoices'] + (int)$booking['invoices'];

$search_sql = '';
$search_types = '';
$search_params = [];
if($search !== '' && $type !== 'summary'){
    $like = '%' . $search . '%';
    $search_sql = ' AND (i.invoice_no LIKE ? OR i.customer_name LIKE ? OR p.product_name LIKE ? OR p.sku LIKE ?)';
    $search_types = 'ssss';
    $search_params = [$like, $like, $like, $like];
}

$data = [];
if($type === 'summary'){
    $data['daily'] = $rows(
        "SELECT d.day, SUM(d.invoices) invoices, SUM(d.sales) sales, SUM(d.returns) returns, SUM(d.paid) paid FROM (
            SELECT i.invoice_date AS day, SUM(CASE WHEN NOT {$is_return} THEN 1 ELSE 0 END) invoices,
                   SUM(CASE WHEN NOT {$is_return} THEN i.total_amount ELSE 0 END) sales,
                   SUM(CASE WHEN {$is_return} THEN -i.total_amount ELSE 0 END) returns,
                   SUM(CASE WHEN NOT {$is_return} THEN i.paid_amount ELSE 0 END) paid
            FROM invoices i WHERE 1=1 {$invoice_scope} AND {$posted} GROUP BY i.invoice_date
            UNION ALL
            SELECT bi.invoice_date, COUNT(*), SUM(bi.amount), 0, SUM(bi.amount) FROM booking_invoices bi
            LEFT JOIN booking_invoice_types bit ON bit.user_id=bi.user_id AND bit.type_key=bi.invoice_type
            WHERE 1=1 {$booking_scope} AND bi.status='confirmed' AND COALESCE(bit.behavior,'income')='income' AND bi.invoice_date BETWEEN ? AND ?
            GROUP BY bi.invoice_date
         ) d GROUP BY d.day ORDER BY d.day",
        'ssss', [$from, $to, $from, $to]
    );
    $data['wallets'] = $rows(
        "SELECT COALESCE(w.wallet_name,'Unassigned') wallet_name, COUNT(*) invoices, SUM(i.total_amount) sales, SUM(i.paid_amount) paid
         FROM invoices i LEFT JOIN wallets w ON w.id=i.receive_wallet_id
         WHERE 1=1 {$invoice_scope} AND {$posted} AND NOT {$is_return}
         GROUP BY i.receive_wallet_id, w.wallet_name ORDER BY sales DESC",
        'ss', [$from, $to]
    );
}elseif($type === 'product'){
    $data['products'] = $rows(
        "SELECT p.product_name, p.sku, COALESCE(ii.variant_name,'') variant_name,
                COUNT(DISTINCT CASE WHEN ii.quantity>0 THEN i.id END) invoices,
                SUM(CASE WHEN ii.quantity>0 THEN ii.quantity ELSE 0 END) sold_qty,
                SUM(CASE WHEN ii.quantity<0 THEN -ii.quantity ELSE 0 END) returned_qty,
                SUM(ii.total_price) net_amount
         FROM invoice_items ii JOIN invoices i ON i.id=ii.invoice_id LEFT JOIN products p ON p.id=ii.product_id
         WHERE 1=1 {$invoice_scope} AND {$posted} {$search_sql}
         GROUP BY ii.product_id, p.product_name, p.sku, COALESCE(ii.variant_name,'')
         ORDER BY p.product_name, variant_name",
        'ss' . $search_types, array_merge([$from, $to], $search_params)
    );
}elseif($type === 'invoice'){
    $invoice_rows = $rows(
        "SELECT i.id, i.invoice_no, i.invoice_date, i.customer_name, i.total_amount, i.paid_amount, i.due_amount, i.created_by_name, i.created_by_type,
                ii.variant_name, ii.quantity, ii.unit_price, ii.total_price, p.product_name, p.sku
         FROM invoices i LEFT JOIN invoice_items ii ON ii.invoice_id=i.id LEFT JOIN products p ON p.id=ii.product_id
         WHERE i.id IN (SELECT id FROM (
             SELECT DISTINCT i.id FROM invoices i LEFT JOIN invoice_items ii ON ii.invoice_id=i.id LEFT JOIN products p ON p.id=ii.product_id
             WHERE 1=1 {$invoice_scope} AND {$posted} {$search_sql} ORDER BY i.id DESC LIMIT 500) limited)
         ORDER BY i.invoice_date, i.id, ii.id",
        'ss' . $search_types, array_merge([$from, $to], $search_params)
    );
    foreach($invoice_rows as $row){
        $id = (int)$row['id'];
        if(!isset($data['invoices'][$id])) $data['invoices'][$id] = ['head' => $row, 'items' => []];
        if($row['product_name'] !== null || $row['quantity'] !== null) $data['invoices'][$id]['items'][] = $row;
    }
    $data['invoices'] = $data['invoices'] ?? [];
}else{
    $data['customers'] = $rows(
        "SELECT COALESCE(NULLIF(TRIM(i.customer_name),''),'Walk-in') customer_name, c.phone,
                COUNT(CASE WHEN NOT {$is_return} THEN 1 END) invoices,
                SUM(CASE WHEN NOT {$is_return} THEN i.total_amount ELSE 0 END) sales,
                SUM(CASE WHEN {$is_return} THEN -i.total_amount ELSE 0 END) returns,
                SUM(CASE WHEN NOT {$is_return} THEN i.paid_amount ELSE 0 END) paid,
                SUM(CASE WHEN NOT {$is_return} AND i.due_amount>0 THEN i.due_amount ELSE 0 END) due
         FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id AND c.user_id=i.user_id
         WHERE 1=1 {$invoice_scope} AND {$posted}" . ($search !== '' ? ' AND (i.customer_name LIKE ? OR c.phone LIKE ?)' : '') . "
         GROUP BY i.customer_id, customer_name, c.phone ORDER BY sales DESC",
        'ss' . ($search !== '' ? 'ss' : ''), array_merge([$from, $to], $search !== '' ? ['%' . $search . '%', '%' . $search . '%'] : [])
    );
}

$company = $rows('SELECT name, address, phone FROM users WHERE id=?', 'i', [$user_id])[0] ?? [];
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$money = static fn($v) => number_format((float)$v, 2);
$period = app_date($from) . ' – ' . app_date($to);
$link = static fn($f, $t) => '?' . http_build_query(['type' => $GLOBALS['type'], 'from' => $f, 'to' => $t]);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<section class="content"><div class="container-fluid">

<div class="card report-filter"><div class="card-header"><h3 class="card-title"><i class="fas fa-chart-line mr-2"></i>Sales Report</h3></div><div class="card-body">
<form method="get"><div class="row align-items-end">
    <div class="col-md-3 form-group"><label for="sr-type">Report type</label><select id="sr-type" name="type" class="form-control"><?php foreach($report_types as $key => $label){ ?><option value="<?= $key ?>" <?= $type === $key ? 'selected' : '' ?>><?= $h($label) ?></option><?php } ?></select></div>
    <div class="col-md-2 form-group"><label for="sr-from">From</label><input id="sr-from" type="date" name="from" class="form-control" value="<?= $h($from) ?>" max="<?= date('Y-m-d') ?>"></div>
    <div class="col-md-2 form-group"><label for="sr-to">To</label><input id="sr-to" type="date" name="to" class="form-control" value="<?= $h($to) ?>" max="<?= date('Y-m-d') ?>"></div>
    <div class="col-md-3 form-group"><label for="sr-q">Search <small class="text-muted">(invoice, customer, product, code)</small></label><input id="sr-q" name="q" class="form-control" value="<?= $h($search) ?>" placeholder="Optional"></div>
    <div class="col-md-2 form-group"><button class="btn btn-primary btn-block"><i class="fas fa-filter mr-1"></i>Show</button></div>
</div></form>
<div class="d-flex flex-wrap" style="gap:6px">
    <a class="btn btn-sm btn-outline-secondary" href="<?= $h($link(date('Y-m-d'), date('Y-m-d'))) ?>">Today</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= $h($link(date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day')))) ?>">Yesterday</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= $h($link(date('Y-m-d', strtotime('-6 days')), date('Y-m-d'))) ?>">Last 7 days</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= $h($link(date('Y-m-01'), date('Y-m-d'))) ?>">This Month</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= $h($link(date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month')))) ?>">Last Month</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= $h($link(date('Y-01-01'), date('Y-m-d'))) ?>">This Year</a>
    <button type="button" onclick="window.print()" class="btn btn-sm btn-info ml-auto"><i class="fas fa-print mr-1"></i>Print</button>
</div>
</div></div>

<div class="report-print-heading d-none">
    <div class="print-company"><?= $h($company['name'] ?? '') ?></div>
    <?php if(!empty($company['address']) || !empty($company['phone'])){ ?><div class="print-company-meta"><?= $h(trim(($company['address'] ?? '') . (!empty($company['phone']) ? ' · ' . $company['phone'] : ''), ' ·')) ?></div><?php } ?>
    <div class="print-title">Sales Report — <?= $h($report_types[$type]) ?></div>
    <div class="print-meta">Period: <?= $h($period) ?><?= branch_scope_sql($conn, 'i') !== '' ? ' · Branch: ' . $h(current_branch_label($conn)) : '' ?><?= $search !== '' ? ' · Search: ' . $h($search) : '' ?> · Printed: <?= date('d M Y, h:i A') ?></div>
</div>

<div class="row sr-cards">
    <div class="col-6 col-lg"><div class="small-box bg-primary"><div class="inner"><h4>BDT <?= $money($gross) ?></h4><p>Gross Sales · <?= $invoice_count ?> invoices</p></div></div></div>
    <div class="col-6 col-lg"><div class="small-box bg-danger"><div class="inner"><h4>BDT <?= $money($returns) ?></h4><p>Returns</p></div></div></div>
    <div class="col-6 col-lg"><div class="small-box bg-info"><div class="inner"><h4>BDT <?= $money($net) ?></h4><p>Net Sales</p></div></div></div>
    <div class="col-6 col-lg"><div class="small-box bg-success"><div class="inner"><h4>BDT <?= $money($paid) ?></h4><p>Received</p></div></div></div>
    <div class="col-6 col-lg"><div class="small-box bg-warning"><div class="inner"><h4>BDT <?= $money($due) ?></h4><p>Due</p></div></div></div>
</div>

<?php if($type === 'summary'){ ?>
<div class="card"><div class="card-header"><h3 class="card-title">Day-wise Sales</h3></div><div class="card-body table-responsive p-0">
<table class="table table-bordered table-striped table-sm mb-0"><thead><tr><th>Date</th><th class="text-right">Invoices</th><th class="text-right">Sales</th><th class="text-right">Returns</th><th class="text-right">Net</th><th class="text-right">Received</th></tr></thead><tbody>
<?php $sum = ['invoices' => 0, 'sales' => 0, 'returns' => 0, 'paid' => 0]; foreach($data['daily'] as $row){ foreach($sum as $k => $v) $sum[$k] += (float)$row[$k]; ?>
<tr><td><?= $h(app_date($row['day'])) ?></td><td class="text-right"><?= (int)$row['invoices'] ?></td><td class="text-right"><?= $money($row['sales']) ?></td><td class="text-right text-danger"><?= (float)$row['returns'] > 0 ? $money($row['returns']) : '—' ?></td><td class="text-right font-weight-bold"><?= $money($row['sales'] - $row['returns']) ?></td><td class="text-right"><?= $money($row['paid']) ?></td></tr>
<?php } if(!$data['daily']){ ?><tr><td colspan="6" class="text-center text-muted py-3">No confirmed sales in this period.</td></tr><?php } ?>
</tbody><tfoot><tr><th>Total</th><th class="text-right"><?= (int)$sum['invoices'] ?></th><th class="text-right"><?= $money($sum['sales']) ?></th><th class="text-right"><?= $money($sum['returns']) ?></th><th class="text-right"><?= $money($sum['sales'] - $sum['returns']) ?></th><th class="text-right"><?= $money($sum['paid']) ?></th></tr></tfoot></table>
</div></div>
<div class="card"><div class="card-header"><h3 class="card-title">Sales by Payment Wallet</h3></div><div class="card-body table-responsive p-0">
<table class="table table-bordered table-striped table-sm mb-0"><thead><tr><th>Payment by</th><th class="text-right">Invoices</th><th class="text-right">Sales</th><th class="text-right">Received</th></tr></thead><tbody>
<?php foreach($data['wallets'] as $row){ ?><tr><td><?= $h($row['wallet_name']) ?></td><td class="text-right"><?= (int)$row['invoices'] ?></td><td class="text-right"><?= $money($row['sales']) ?></td><td class="text-right"><?= $money($row['paid']) ?></td></tr><?php } if(!$data['wallets']){ ?><tr><td colspan="4" class="text-center text-muted py-3">No data.</td></tr><?php } ?>
</tbody></table>
</div></div>

<?php }elseif($type === 'product'){ ?>
<div class="card"><div class="card-header"><h3 class="card-title">Product-wise Sales</h3></div><div class="card-body table-responsive p-0">
<table class="table table-bordered table-striped table-sm mb-0"><thead><tr><th>#</th><th>Product</th><th>Code</th><th>Variant</th><th class="text-right">Invoices</th><th class="text-right">Sold Qty</th><th class="text-right">Returned</th><th class="text-right">Net Qty</th><th class="text-right">Net Amount</th></tr></thead><tbody>
<?php $sum = ['sold_qty' => 0, 'returned_qty' => 0, 'net_amount' => 0]; foreach($data['products'] as $n => $row){ foreach($sum as $k => $v) $sum[$k] += (float)$row[$k]; ?>
<tr><td><?= $n + 1 ?></td><td><?= $h($row['product_name'] ?? 'Deleted product') ?></td><td><?= $h($row['sku']) ?></td><td><?= $h($row['variant_name'] ?: '—') ?></td><td class="text-right"><?= (int)$row['invoices'] ?></td><td class="text-right"><?= number_format((float)$row['sold_qty']) ?></td><td class="text-right text-danger"><?= (float)$row['returned_qty'] > 0 ? number_format((float)$row['returned_qty']) : '—' ?></td><td class="text-right font-weight-bold"><?= number_format((float)$row['sold_qty'] - (float)$row['returned_qty']) ?></td><td class="text-right"><?= $money($row['net_amount']) ?></td></tr>
<?php } if(!$data['products']){ ?><tr><td colspan="9" class="text-center text-muted py-3">No products sold in this period.</td></tr><?php } ?>
</tbody><tfoot><tr><th colspan="5">Total</th><th class="text-right"><?= number_format($sum['sold_qty']) ?></th><th class="text-right"><?= number_format($sum['returned_qty']) ?></th><th class="text-right"><?= number_format($sum['sold_qty'] - $sum['returned_qty']) ?></th><th class="text-right"><?= $money($sum['net_amount']) ?></th></tr></tfoot></table>
</div><div class="card-footer small text-muted">Amounts are product line totals; invoice charges (delivery, VAT) and invoice discounts are not split by product.</div></div>

<?php }elseif($type === 'invoice'){ ?>
<div class="card"><div class="card-header"><h3 class="card-title">Invoice Details</h3><?php if(count($data['invoices']) >= 500){ ?><span class="float-right small text-muted">Showing the latest 500 invoices — narrow the dates to see all.</span><?php } ?></div><div class="card-body table-responsive p-0">
<table class="table table-bordered table-sm mb-0 sr-invoice-table"><thead><tr><th>Date</th><th>Invoice / Customer</th><th>Product</th><th>Variant</th><th class="text-right">Qty</th><th class="text-right">Unit Price</th><th class="text-right">Line Total</th><th class="text-right">Invoice Total</th><th class="text-right">Paid</th><th class="text-right">Due</th></tr></thead><tbody>
<?php $sum = ['total' => 0, 'paid' => 0, 'due' => 0]; foreach($data['invoices'] as $invoice){ $head = $invoice['head']; $items = $invoice['items'] ?: [null]; $span = count($items); $return = (float)$head['total_amount'] < 0 || $head['created_by_type'] === 'sales_refund'; if(!$return){ $sum['total'] += (float)$head['total_amount']; $sum['paid'] += (float)$head['paid_amount']; $sum['due'] += max(0, (float)$head['due_amount']); } foreach($items as $index => $item){ ?>
<tr class="<?= $index === 0 ? 'sr-invoice-start' : '' ?><?= $return ? ' table-danger' : '' ?>">
    <?php if($index === 0){ ?><td rowspan="<?= $span ?>"><?= $h(app_date($head['invoice_date'])) ?></td><td rowspan="<?= $span ?>"><a href="../sales/view_invoice.php?id=<?= (int)$head['id'] ?>" class="font-weight-bold"><?= $h($head['invoice_no']) ?></a><?= $return ? ' <span class="badge badge-danger">Return</span>' : '' ?><div class="small"><?= $h($head['customer_name'] ?: 'Walk-in') ?></div><?php if(trim((string)$head['created_by_name']) !== ''){ ?><div class="small text-muted">By <?= $h($head['created_by_name']) ?></div><?php } ?></td><?php } ?>
    <td><?= $item ? $h($item['product_name'] ?? 'Deleted product') . ($item['sku'] ? ' <small class="text-muted">[' . $h($item['sku']) . ']</small>' : '') : '<span class="text-muted">—</span>' ?></td>
    <td><?= $item ? $h($item['variant_name'] ?: '—') : '' ?></td>
    <td class="text-right"><?= $item ? number_format((float)$item['quantity']) : '' ?></td>
    <td class="text-right"><?= $item ? $money($item['unit_price']) : '' ?></td>
    <td class="text-right"><?= $item ? $money($item['total_price']) : '' ?></td>
    <?php if($index === 0){ ?><td rowspan="<?= $span ?>" class="text-right font-weight-bold"><?= $money($head['total_amount']) ?></td><td rowspan="<?= $span ?>" class="text-right"><?= $money($head['paid_amount']) ?></td><td rowspan="<?= $span ?>" class="text-right"><?= (float)$head['due_amount'] > 0 ? $money($head['due_amount']) : '—' ?></td><?php } ?>
</tr>
<?php } } if(!$data['invoices']){ ?><tr><td colspan="10" class="text-center text-muted py-3">No invoices in this period.</td></tr><?php } ?>
</tbody><tfoot><tr><th colspan="7">Total (sales, returns excluded)</th><th class="text-right"><?= $money($sum['total']) ?></th><th class="text-right"><?= $money($sum['paid']) ?></th><th class="text-right"><?= $money($sum['due']) ?></th></tr></tfoot></table>
</div></div>

<?php }else{ ?>
<div class="card"><div class="card-header"><h3 class="card-title">Customer-wise Sales</h3></div><div class="card-body table-responsive p-0">
<table class="table table-bordered table-striped table-sm mb-0"><thead><tr><th>#</th><th>Customer</th><th>Phone</th><th class="text-right">Invoices</th><th class="text-right">Sales</th><th class="text-right">Returns</th><th class="text-right">Received</th><th class="text-right">Due</th></tr></thead><tbody>
<?php $sum = ['invoices' => 0, 'sales' => 0, 'returns' => 0, 'paid' => 0, 'due' => 0]; foreach($data['customers'] as $n => $row){ foreach($sum as $k => $v) $sum[$k] += (float)$row[$k]; ?>
<tr><td><?= $n + 1 ?></td><td><?= $h($row['customer_name']) ?></td><td><?= $h($row['phone']) ?></td><td class="text-right"><?= (int)$row['invoices'] ?></td><td class="text-right"><?= $money($row['sales']) ?></td><td class="text-right text-danger"><?= (float)$row['returns'] > 0 ? $money($row['returns']) : '—' ?></td><td class="text-right"><?= $money($row['paid']) ?></td><td class="text-right"><?= (float)$row['due'] > 0 ? $money($row['due']) : '—' ?></td></tr>
<?php } if(!$data['customers']){ ?><tr><td colspan="8" class="text-center text-muted py-3">No sales in this period.</td></tr><?php } ?>
</tbody><tfoot><tr><th colspan="3">Total</th><th class="text-right"><?= (int)$sum['invoices'] ?></th><th class="text-right"><?= $money($sum['sales']) ?></th><th class="text-right"><?= $money($sum['returns']) ?></th><th class="text-right"><?= $money($sum['paid']) ?></th><th class="text-right"><?= $money($sum['due']) ?></th></tr></tfoot></table>
</div></div>
<?php } ?>

</div></section>
<?php
$page_script = '<style>
.sr-cards .small-box h4{font-size:1.35rem;font-weight:700;margin:0}.sr-cards .small-box p{margin:4px 0 0}
.sr-invoice-table tr.sr-invoice-start td{border-top:2px solid #adb5bd}
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
 .card{box-shadow:none!important;border:1px solid #999!important;border-radius:0!important;margin-bottom:8px!important}
 .card-header{background:#eee!important;padding:5px 8px!important}.card-title{font-size:12px!important;font-weight:700}
 table{font-size:9.5px!important;width:100%!important}th,td{padding:3px 5px!important;border:1px solid #999!important}th{background:#f2f2f2!important}
 tr{break-inside:avoid}a{color:#111!important;text-decoration:none!important}.table-danger td{background:#fde8e8!important}
}</style>';
require_once '../includes/footer.php';
?>
