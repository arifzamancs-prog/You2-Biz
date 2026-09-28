<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/printing_helper.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/customer_opening_due_helper.php';
require_once '../includes/booking_invoice_helper.php';
require_once '../includes/project_package_helper.php';

$user_id = $_SESSION['user_id'];
ensure_invoice_posting_columns($conn);
ensure_customer_opening_due_tables($conn);
ensure_booking_invoice_table($conn);
ensure_booking_invoice_type_table($conn, $user_id);

$invoice_types = booking_invoice_types($conn, $user_id);
$project_package_labels = project_package_labels($conn, $user_id);
$is_housing_company = project_package_company_type($conn, $user_id) === 'Housing';
$package_label = $project_package_labels['package'] ?? 'Package';
$package_details_label = $package_label === 'Service' ? 'Service Details' : 'Package Details';
$package_count_label = $package_label === 'Service' ? 'Total Service' : 'Total Package';

$customer_id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;

/* Account/Profile */

$sql = "SELECT
            name,
            address,
            email,
            phone
        FROM users
        WHERE id=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$account = mysqli_fetch_assoc($result);

$account_name =
    $account['name'] ?? 'Account';

/* Customer */

$sql = "SELECT *
        FROM customers
        WHERE id=?
        AND user_id=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $customer_id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$customer = mysqli_fetch_assoc($result);

if(!$customer){
    die("Customer Not Found");
}

/* Ledger */

$ledger = [];

function ledger_invoice_no_from_payment_reference($reference)
{
    $reference = trim((string)$reference);

    if(preg_match('/^Invoice Payment - (INV-[0-9]+)/', $reference, $matches)){
        return $matches[1];
    }

    if(preg_match('/^Outstanding Amount - (INV-[0-9]+)/', $reference, $matches)){
        return $matches[1];
    }

    return '';
}

/* Invoices */

$sql = "SELECT
            invoice_date,
            invoice_no,
            total_amount,
            id
        FROM invoices
        WHERE customer_id=?
        AND user_id=?
        AND accounting_status='posted'";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $customer_id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

while($row=mysqli_fetch_assoc($result)){

    $ledger[] = [

        'trx_date'  => $row['invoice_date'],
        'type'      => 'Invoice',
        'reference' => $row['invoice_no'],
        'invoice_no' => $row['invoice_no'],
        'wallet_name' => '',
        'sort_order' => 1,
        'reference_id' => $row['id'],
        'debit'     => $row['total_amount'],
        'credit'    => 0

    ];

}

/* Payments */

$sql = "SELECT
            payment_date,
            amount,
            note,
            id
        FROM customer_payments
        WHERE customer_id=?
        AND user_id=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $customer_id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

while($row=mysqli_fetch_assoc($result)){

    $payment_invoice_no =
        ledger_invoice_no_from_payment_reference($row['note']);

    $ledger[] = [

        'trx_date'  => $row['payment_date'],
        'type'      => 'Payment',
        'reference' => $row['note'],
        'invoice_no' => $payment_invoice_no,
        'wallet_name' => '',
        'sort_order' => 2,
        'reference_id' => $row['id'],
        'debit'     => 0,
        'credit'    => $row['amount']

    ];

}

/* Previous Due Entries */

$sql = "SELECT
            entry_date,
            due_no,
            amount,
            id
        FROM customer_opening_dues
        WHERE customer_id=?
        AND user_id=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $customer_id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

while($row=mysqli_fetch_assoc($result)){

    $ledger[] = [

        'trx_date'  => $row['entry_date'],
        'type'      => 'Previous Due',
        'reference' => $row['due_no'],
        'invoice_no' => '',
        'wallet_name' => '',
        'sort_order' => 0,
        'reference_id' => $row['id'],
        'debit'     => $row['amount'],
        'credit'    => 0

    ];

}

/* Confirmed Invoice Wallet Transactions */

$wallet_transaction_sql = "SELECT
        COALESCE(t.id, bi.id) AS ledger_reference_id,
        t.id AS transaction_id,
        t.txn_no,
        COALESCE(t.txn_date, bi.invoice_date) AS ledger_date,
        t.transaction_type,
        COALESCE(t.amount, bi.amount) AS ledger_amount,
        t.note,
        bi.invoice_no,
        bi.invoice_type,
        bi.notes AS invoice_note,
        bi.invoice_date,
        bi.file_no,
        bi.block_name,
        bi.road_no,
        bi.plot_no,
        COALESCE(NULLIF(bi.total_price, 0), pk.price, bi.amount) AS package_price,
        p.project_name,
        pk.package_name,
        w.wallet_name
    FROM transactions t
    RIGHT JOIN booking_invoices bi
        ON bi.id=t.reference_id
        AND bi.user_id=t.user_id
        AND t.transaction_type IN ('invoice_income', 'invoice_expense')
    LEFT JOIN projects p
        ON p.id=bi.project_id
        AND p.user_id=bi.user_id
    LEFT JOIN packages pk
        ON pk.id=bi.package_id
        AND pk.user_id=bi.user_id
    LEFT JOIN wallets w
        ON w.id=t.wallet_id
        AND w.user_id=t.user_id
    WHERE bi.customer_id=?
    AND bi.user_id=?
    AND bi.status='confirmed'
    ORDER BY COALESCE(t.txn_date, bi.invoice_date), COALESCE(t.id, bi.id)";

$wallet_transaction_stmt = mysqli_prepare($conn, $wallet_transaction_sql);
mysqli_stmt_bind_param($wallet_transaction_stmt, 'ii', $customer_id, $user_id);
mysqli_stmt_execute($wallet_transaction_stmt);
$wallet_transactions = mysqli_stmt_get_result($wallet_transaction_stmt);

while($wallet_transactions && $row = mysqli_fetch_assoc($wallet_transactions)){
    $invoice_type_key = normalize_booking_invoice_type($row['invoice_type'] ?? '', $invoice_types);
    $transaction_type = trim((string)($row['transaction_type'] ?? ''));
    $is_refund = $transaction_type !== ''
        ? $transaction_type === 'invoice_expense'
        : booking_invoice_behavior($conn, $user_id, $invoice_type_key) === 'expense';

    $ledger[] = [
        'trx_date' => $row['ledger_date'],
        'type' => $is_refund ? 'Wallet Refund' : 'Wallet Received',
        'reference' => trim((string)$row['invoice_no']),
        'invoice_no' => trim((string)$row['invoice_no']),
        'wallet_name' => (string)($row['wallet_name'] ?? ''),
        'payment_type' => booking_invoice_type_label($row['invoice_type'] ?? '', $invoice_types),
        'invoice_type_key' => $invoice_type_key,
        'note' => trim((string)($row['invoice_note'] ?? '')),
        'project_details' => trim((string)($row['project_name'] ?? '') . (($row['project_name'] ?? '') !== '' && ($row['package_name'] ?? '') !== '' ? ' - ' : '') . (string)($row['package_name'] ?? '')),
        'booking_date' => $row['invoice_date'],
        'file_no' => trim((string)($row['file_no'] ?? '')),
        'plot_details' => trim(implode(', ', array_filter([
            trim((string)($row['block_name'] ?? '')) !== '' ? 'Block: ' . trim((string)$row['block_name']) : '',
            trim((string)($row['road_no'] ?? '')) !== '' ? 'Road No: ' . trim((string)$row['road_no']) : '',
            trim((string)($row['plot_no'] ?? '')) !== '' ? 'Plot No: ' . trim((string)$row['plot_no']) : '',
        ]))),
        'total_amount' => (!$is_refund && booking_invoice_establishes_total($conn, $user_id, $invoice_type_key)) ? (float)$row['package_price'] : 0,
        'sort_order' => $is_refund ? 3 : 2,
        'reference_id' => (int)$row['ledger_reference_id'],
        'debit' => $is_refund ? (float)$row['ledger_amount'] : 0,
        'credit' => $is_refund ? 0 : (float)$row['ledger_amount'],
    ];
}

/* Merge Invoice + Same Invoice Payment */

$merged_ledger = [];

foreach($ledger as $entry){

    $merge_key = '';

    if(
        $entry['invoice_no'] !== '' &&
        in_array($entry['type'], ['Invoice', 'Payment'], true)
    ){
        $merge_key =
            $entry['trx_date'] . '|' .
            $entry['invoice_no'];
    }

    if($merge_key === ''){
        $merged_ledger[] = $entry;
        continue;
    }

    $existing_index = null;

    foreach($merged_ledger as $index => $merged_entry){
        if(($merged_entry['merge_key'] ?? '') === $merge_key){
            $existing_index = $index;
            break;
        }
    }

    if($existing_index === null){
        $entry['merge_key'] = $merge_key;

        if($entry['type'] === 'Payment'){
            $entry['type'] = 'Invoice';
            $entry['reference'] = $entry['invoice_no'] !== ''
                ? $entry['invoice_no']
                : $entry['reference'];
            $entry['sort_order'] = 1;
        }

        $merged_ledger[] = $entry;
        continue;
    }

    $merged_ledger[$existing_index]['debit'] +=
        (float)$entry['debit'];

    $merged_ledger[$existing_index]['credit'] +=
        (float)$entry['credit'];

    if(
        $merged_ledger[$existing_index]['invoice_no'] === '' &&
        $entry['invoice_no'] !== ''
    ){
        $merged_ledger[$existing_index]['invoice_no'] =
            $entry['invoice_no'];
    }

    if(
        $merged_ledger[$existing_index]['reference'] === '' ||
        str_starts_with(
            (string)$merged_ledger[$existing_index]['reference'],
            'Invoice Payment - '
        )
    ){
        $merged_ledger[$existing_index]['reference'] =
            $entry['invoice_no'] !== ''
                ? $entry['invoice_no']
                : $entry['reference'];
    }

    $merged_ledger[$existing_index]['type'] = 'Invoice';
    $merged_ledger[$existing_index]['sort_order'] = 1;
}

$ledger = $merged_ledger;

/* Sort */

usort($ledger,function($a,$b){

    $date_compare =
        strtotime($a['trx_date'])
        - strtotime($b['trx_date']);

    if($date_compare != 0){
        return $date_compare;
    }

    if($a['sort_order'] != $b['sort_order']){
        return $a['sort_order'] - $b['sort_order'];
    }

    return $a['reference_id'] - $b['reference_id'];

});

/* Group housing transactions by File No. so each purchased plot prints as
   one complete statement, with its booking, installments and returns. */
$ledger_groups = [];

foreach($ledger as $row){
    $project_details = trim((string)($row['project_details'] ?? ''));
    $file_no = trim((string)($row['file_no'] ?? ''));
    $total_amount = (float)($row['total_amount'] ?? 0);

    if($project_details === '' && $total_amount <= 0){
        continue;
    }

    $group_key = ($is_housing_company && $file_no !== '')
        ? 'file-' . md5($file_no)
        : 'invoice-' . md5(trim((string)($row['invoice_no'] ?? '')) . '|' . $project_details);

    if(!isset($ledger_groups[$group_key])){
        $ledger_groups[$group_key] = [
            'file_no' => $file_no,
            'project_details' => $project_details !== '' ? $project_details : '-',
            'plot_details' => trim((string)($row['plot_details'] ?? '')),
            'booking_date' => trim((string)($row['booking_date'] ?? '')),
            'total_amount' => 0,
            'total_paid' => 0,
            'latest_time' => strtotime((string)$row['trx_date']) ?: 0,
            'rows' => [],
        ];
    }

    $ledger_groups[$group_key]['total_amount'] += $total_amount;
    $ledger_groups[$group_key]['total_paid'] += (float)$row['credit'] - (float)$row['debit'];
    $ledger_groups[$group_key]['latest_time'] = max($ledger_groups[$group_key]['latest_time'], strtotime((string)$row['trx_date']) ?: 0);

    if($ledger_groups[$group_key]['plot_details'] === '' && trim((string)($row['plot_details'] ?? '')) !== ''){
        $ledger_groups[$group_key]['plot_details'] = trim((string)$row['plot_details']);
    }
    if($ledger_groups[$group_key]['booking_date'] === '' && trim((string)($row['booking_date'] ?? '')) !== ''){
        $ledger_groups[$group_key]['booking_date'] = trim((string)$row['booking_date']);
    }
    $ledger_groups[$group_key]['rows'][] = $row;
}

foreach($ledger_groups as &$group){
    $running_paid = 0;
    foreach($group['rows'] as &$row){
        $running_paid += (float)$row['credit'] - (float)$row['debit'];
        $row['due'] = max(0, (float)$group['total_amount'] - $running_paid);
    }
    unset($row);
    $group['total_due'] = max(0, (float)$group['total_amount'] - (float)$group['total_paid']);
}
unset($group);

uasort($ledger_groups, function($a, $b){
    return (int)$b['latest_time'] <=> (int)$a['latest_time'];
});

$total_paid = 0;
$total_amount = 0;
foreach($ledger_groups as $group){
    $total_paid += (float)$group['total_paid'];
    $total_amount += (float)$group['total_amount'];
}
$total_due = max(0, $total_amount - $total_paid);
$purchased_package_count = count($ledger_groups);

$custom_printing = is_custom_printing($conn);
$custom_size = current_printing_custom_size($conn);
$custom_top_margin = current_printing_custom_top_margin($conn);
$custom_page_size = $custom_size['width'] . 'in ' . $custom_size['height'] . 'in';
$custom_page_width = $custom_size['width'] . 'in';
$custom_page_margin = $custom_top_margin . 'in 12mm 12mm 12mm';

if(is_pos_printing($conn)){
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Customer Ledger</title>

<style>
@page{
    size:80mm auto;
    margin:4mm;
}

body{
    background:#f5f5f5;
    color:#111;
    font-family:Arial, sans-serif;
    font-size:11px;
    line-height:1.35;
    margin:0;
}

.receipt{
    background:#fff;
    margin:12px auto;
    padding:8px;
    width:80mm;
}

.no-print{
    margin:12px auto 0;
    text-align:right;
    width:80mm;
}

button{
    background:#111827;
    border:0;
    border-radius:4px;
    color:#fff;
    cursor:pointer;
    padding:7px 12px;
}

.text-center{
    text-align:center;
}

.text-right{
    text-align:right;
}

.muted{
    color:#555;
}

h2{
    font-size:15px;
    margin:0 0 3px;
}

h3{
    border-bottom:1px dashed #111;
    border-top:1px dashed #111;
    font-size:12px;
    margin:8px 0;
    padding:5px 0;
    text-align:center;
    text-transform:uppercase;
}

.line{
    display:flex;
    justify-content:space-between;
    gap:8px;
}

table{
    border-collapse:collapse;
    margin-top:8px;
    width:100%;
}

th,
td{
    border-bottom:1px dashed #bbb;
    padding:4px 0;
    vertical-align:top;
}

th{
    font-size:10px;
    text-align:left;
}

.summary td{
    border:0;
    padding:2px 0;
}

.grand td{
    border-top:1px dashed #111;
    font-weight:bold;
    padding-top:5px;
}

@media print{
    body{
        background:#fff;
    }

    .no-print{
        display:none;
    }

    .receipt{
        margin:0;
        padding:0;
        width:auto;
    }
}
</style>
</head>

<body>

<div class="no-print">
    <button onclick="window.print()">Print</button>
</div>

<main class="receipt">
    <div class="text-center">
        <h2><?= htmlspecialchars($account_name); ?></h2>
        <?php if(!empty($account['address']) && $account['address'] != 'None'){ ?>
            <div class="muted"><?= htmlspecialchars($account['address']); ?></div>
        <?php } ?>
        <?php if(!empty($account['phone'])){ ?>
            <div class="muted">Phone: <?= htmlspecialchars($account['phone']); ?></div>
        <?php } ?>
    </div>

    <h3>Customer Ledger</h3>

    <div class="line">
        <span>Customer</span>
        <strong><?= htmlspecialchars($customer['customer_name']); ?></strong>
    </div>
    <div class="line">
        <span>Phone</span>
        <span><?= htmlspecialchars($customer['phone']); ?></span>
    </div>

    <table class="summary">
        <tr>
            <td>Total Paid</td>
            <td class="text-right"><?= number_format($total_paid,2); ?></td>
        </tr>
    </table>

    <table>
        <thead>
        <tr>
            <th>Date</th>
            <th>Invoice No.</th>
            <th>Type</th>
            <th class="text-right">Amount</th>
        </tr>
        </thead>
        <tbody>
        <?php if(empty($ledger)){ ?>
            <tr>
                <td colspan="4" class="text-center muted">No ledger entries found.</td>
            </tr>
        <?php } ?>
        <?php foreach($ledger as $row){ ?>
            <tr>
                <td><?= htmlspecialchars(app_datetime($row['trx_date'])); ?></td>
                <td><?= htmlspecialchars($row['invoice_no'] !== '' ? $row['invoice_no'] : $row['reference']); ?></td>
                <td><?= htmlspecialchars(($row['wallet_name'] ?? '') !== '' ? $row['wallet_name'] : '-'); ?></td>
                <td class="text-right"><?= number_format((float)$row['debit'] > 0 ? $row['debit'] : $row['credit'],2); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>

</main>

</body>
</html>
<?php
    exit;
}

?>

<!DOCTYPE html>
<html>
<head>

<meta charset="utf-8">

<title>
Customer Ledger
</title>

<style>

@page{
    size:A4 portrait;
    margin:12mm;
}

*{ box-sizing:border-box; }

body{
    background:#eef2f7;
    color:#172033;
    font-family:"Segoe UI", Arial, sans-serif;
    font-size:12px;
    line-height:1.4;
    margin:0;
}

.ledger-page{
    background:#fff;
    border-top:5px solid #1463c3;
    box-shadow:0 8px 28px rgba(15,23,42,.12);
    margin:24px auto;
    max-width:210mm;
    min-height:270mm;
    padding:14mm;
}

.document-header{
    align-items:flex-start;
    border-bottom:1px solid #dbe5f1;
    display:flex;
    justify-content:space-between;
    padding-bottom:16px;
}

.company-name{
    color:#102a43;
    font-size:24px;
    font-weight:700;
    letter-spacing:.1px;
    margin:0 0 4px;
}

.company-meta{
    color:#64748b;
    font-size:11px;
    line-height:1.7;
}

.document-title{
    color:#1463c3;
    font-size:12px;
    font-weight:700;
    letter-spacing:.12em;
    margin:5px 0 0;
    text-align:right;
    text-transform:uppercase;
}

.document-date{
    color:#64748b;
    font-size:10px;
    margin-top:5px;
    text-align:right;
}

.customer-summary{
    display:grid;
    gap:12px;
    grid-template-columns:3fr 2fr;
    margin:18px 0;
}

.customer-card,
.total-card{
    border:1px solid #dbe5f1;
    border-radius:7px;
    overflow:hidden;
}

.customer-card{
    display:grid;
    grid-template-columns:1fr 1fr;
}

.customer-item{
    padding:11px 13px;
}

.customer-item + .customer-item{
    border-left:1px solid #dbe5f1;
}

.customer-item:nth-child(3),
.customer-item:nth-child(4){
    border-top:1px solid #dbe5f1;
}

.meta-label{
    color:#64748b;
    display:block;
    font-size:10px;
    font-weight:700;
    letter-spacing:.06em;
    margin-bottom:3px;
    text-transform:uppercase;
}

.meta-value{
    color:#102a43;
    font-size:13px;
    font-weight:600;
}

.total-card{
    background:#f0f7ff;
    border-color:#bdd7f5;
    padding:11px 13px;
    text-align:right;
}

.total-card .meta-value{
    color:#1463c3;
    font-size:20px;
}

.totals-grid{
    display:grid;
    gap:8px;
    grid-template-columns:repeat(2, 1fr);
}

.totals-grid .total-card{ min-height:64px; }

.file-statement{
    border:1px solid #dbe5f1;
    border-radius:7px;
    margin-top:18px;
    overflow:hidden;
}

.file-heading{
    align-items:center;
    background:#f0f7ff;
    border-bottom:1px solid #bdd7f5;
    color:#102a43;
    display:flex;
    font-size:14px;
    font-weight:700;
    justify-content:space-between;
    padding:10px 13px;
}

.file-details{
    display:grid;
    grid-template-columns:repeat(2, 1fr);
}

.file-detail{
    border-bottom:1px solid #e2e8f0;
    padding:8px 13px;
}

.file-detail:nth-child(odd){ border-right:1px solid #e2e8f0; }
.file-detail .meta-value{ font-size:12px; }

.file-statement .ledger-table th,
.file-statement .ledger-table td{ padding:7px 9px; }

.file-total-row td{
    background:#f8fafc;
    border-top:1px solid #dbe5f1;
    font-weight:700;
}

.table-title{
    color:#102a43;
    font-size:13px;
    font-weight:700;
    margin:24px 0 8px;
}

table{
    border-collapse:collapse;
    margin:0;
    width:100%;
}

table th,
table td{
    border-bottom:1px solid #e2e8f0;
    padding:9px 10px;
}

table th{
    background:#102a43;
    border:0;
    color:#fff;
    font-size:10px;
    font-weight:700;
    letter-spacing:.04em;
    text-transform:uppercase;
}

.ledger-table tbody tr:nth-child(even){ background:#f8fafc; }
.ledger-table td:last-child{ color:#102a43; font-weight:700; }

h3{
    color:#1e3a5f;
    font-size:14px;
    margin:20px 0 8px;
}

.text-right{
    text-align:right;
}

.text-center{
    text-align:center;
}

.muted{
    color:#64748b;
}

.no-print{
    margin:14px auto 0;
    max-width:210mm;
    text-align:right;
}

.no-print button{
    background:#1d4ed8;
    border:0;
    border-radius:6px;
    color:#fff;
    cursor:pointer;
    font-size:13px;
    padding:8px 16px;
}

.document-footer{
    border-top:1px solid #dbe5f1;
    color:#94a3b8;
    font-size:10px;
    margin-top:26px;
    padding-top:9px;
    text-align:center;
}

@media print{

    body{ background:#fff; }

    .no-print{
        display:none;
    }

    .ledger-page{
        border-top:0;
        box-shadow:none;
        margin:0;
        max-width:none;
        padding:0;
    }

    thead{ display:table-header-group; }
    tr{ break-inside:avoid; page-break-inside:avoid; }

}

</style>

</head>

<body>

<div class="no-print">

    <button onclick="window.print()">

        Print

    </button>

</div>

<main class="ledger-page">

<header class="document-header">
    <div>
        <h1 class="company-name"><?php echo htmlspecialchars($account_name); ?></h1>
        <?php if(!empty($account['address']) && $account['address'] != 'None'){ ?>
            <div class="company-meta"><?php echo htmlspecialchars($account['address']); ?></div>
        <?php } ?>
        <div class="company-meta">
            <?php if(!empty($account['phone'])){ ?>Phone: <?php echo htmlspecialchars($account['phone']); ?><?php } ?>
            <?php if(!empty($account['email'])){ ?><?= !empty($account['phone']) ? ' &nbsp;|&nbsp; ' : ''; ?>Email: <?php echo htmlspecialchars($account['email']); ?><?php } ?>
        </div>
    </div>
    <div>
        <div class="document-title">Customer Ledger Statement</div>
        <div class="document-date">Generated: <?php echo date('d-m-Y'); ?></div>
    </div>
</header>

<section class="customer-summary">
    <div class="customer-card">
        <div class="customer-item">
            <span class="meta-label">Customer ID</span>
            <span class="meta-value"><?php echo htmlspecialchars((string)($customer['customer_code'] ?? $customer['id'])); ?></span>
        </div>
        <div class="customer-item">
            <span class="meta-label">Customer Name</span>
            <span class="meta-value"><?php echo htmlspecialchars($customer['customer_name']); ?></span>
        </div>
        <div class="customer-item">
            <span class="meta-label">Phone</span>
            <span class="meta-value"><?php echo htmlspecialchars($customer['phone']); ?></span>
        </div>
        <div class="customer-item">
            <span class="meta-label">Address</span>
            <span class="meta-value"><?php echo htmlspecialchars((string)($customer['address'] ?? '-')); ?></span>
        </div>
    </div>
    <div class="totals-grid">
        <div class="total-card">
            <span class="meta-label"><?php echo htmlspecialchars($package_count_label); ?></span>
            <span class="meta-value"><?php echo number_format($purchased_package_count); ?></span>
        </div>
        <div class="total-card">
            <span class="meta-label">Total Amount</span>
            <span class="meta-value">BDT <?php echo number_format($total_amount,2); ?></span>
        </div>
        <div class="total-card">
            <span class="meta-label">Total Paid</span>
            <span class="meta-value">BDT <?php echo number_format($total_paid,2); ?></span>
        </div>
        <div class="total-card">
            <span class="meta-label">Total Due</span>
            <span class="meta-value">BDT <?php echo number_format($total_due,2); ?></span>
        </div>
    </div>
</section>

<div class="table-title">File-wise Ledger Details</div>

<?php if(empty($ledger_groups)){ ?>
    <p class="muted">No package or file ledger entries found.</p>
<?php } ?>

<?php foreach($ledger_groups as $group){ ?>
<section class="file-statement">
    <div class="file-heading">
        <span>File No: <?php echo htmlspecialchars($group['file_no'] !== '' ? $group['file_no'] : '-'); ?></span>
        <span>Due: BDT <?php echo number_format((float)$group['total_due'], 2); ?></span>
    </div>
    <div class="file-details">
        <div class="file-detail"><span class="meta-label"><?php echo htmlspecialchars($package_details_label); ?></span><span class="meta-value"><?php echo htmlspecialchars($group['project_details']); ?></span></div>
        <div class="file-detail"><span class="meta-label">Plot Details</span><span class="meta-value"><?php echo htmlspecialchars($group['plot_details'] !== '' ? $group['plot_details'] : '-'); ?></span></div>
        <div class="file-detail"><span class="meta-label">Booking Date</span><span class="meta-value"><?php echo $group['booking_date'] !== '' ? htmlspecialchars(app_date($group['booking_date'])) : '-'; ?></span></div>
        <div class="file-detail"><span class="meta-label">File Summary</span><span class="meta-value">Amount: BDT <?php echo number_format((float)$group['total_amount'],2); ?> &nbsp;|&nbsp; Paid: BDT <?php echo number_format((float)$group['total_paid'],2); ?></span></div>
    </div>
    <table class="ledger-table">
        <thead><tr><th>Date</th><th>Invoice No.</th><th>Payment Type</th><th>Note</th><th>Payment By</th><th class="text-right">Paid</th><th class="text-right">Due</th></tr></thead>
        <tbody>
        <?php foreach($group['rows'] as $row){ $paid = (float)$row['credit'] - (float)$row['debit']; ?>
            <tr>
                <td><?php echo htmlspecialchars(app_date($row['trx_date'])); ?></td>
                <td><?php echo htmlspecialchars((string)($row['invoice_no'] ?? '-')); ?></td>
                <td><?php echo htmlspecialchars((string)($row['payment_type'] ?? $row['type'] ?? '-')); ?></td>
                <td><?php echo htmlspecialchars(trim((string)($row['note'] ?? '')) !== '' ? (string)$row['note'] : '-'); ?></td>
                <td><?php echo htmlspecialchars(trim((string)($row['wallet_name'] ?? '')) !== '' ? (string)$row['wallet_name'] : '-'); ?></td>
                <td class="text-right"><?php echo number_format($paid, 2); ?></td>
                <td class="text-right"><?php echo number_format((float)$row['due'], 2); ?></td>
            </tr>
        <?php } ?>
        <tr class="file-total-row"><td colspan="5" class="text-right">File Total</td><td class="text-right"><?php echo number_format((float)$group['total_paid'],2); ?></td><td class="text-right"><?php echo number_format((float)$group['total_due'],2); ?></td></tr>
        </tbody>
    </table>
</section>
<?php } ?>

<div class="document-footer">This is a system-generated customer ledger statement.</div>

</main>

</body>
</html>
