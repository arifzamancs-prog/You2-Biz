<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/printing_helper.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/customer_opening_due_helper.php';

$user_id = $_SESSION['user_id'];
ensure_invoice_posting_columns($conn);
ensure_customer_opening_due_tables($conn);

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
        'sort_order' => 0,
        'reference_id' => $row['id'],
        'debit'     => $row['amount'],
        'credit'    => 0

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

$total_sales = 0;
$total_paid  = 0;

foreach($ledger as $entry){

    $total_sales += $entry['debit'];
    $total_paid  += $entry['credit'];

}

$current_due = $total_sales - $total_paid;
$current_due_label = $current_due < 0 ? 'Outstanding Amount' : 'Current Due';
$current_due_display = $current_due < 0 ? abs($current_due) : $current_due;

$running_ledger = [];
$balance = 0;

foreach($ledger as $row){

    $balance += (float)$row['debit'];
    $balance -= (float)$row['credit'];

    $row['running_balance'] = $balance;
    $running_ledger[] = $row;
}

$ledger = $running_ledger;

/* Returnable Products */

$returnable_sql = "SELECT
                       i.invoice_date,
                       p.product_name,
                       SUM(CASE WHEN ii.quantity > 0 THEN ii.quantity ELSE 0 END) AS given_qty,
                       SUM(CASE WHEN ii.quantity < 0 THEN ABS(ii.quantity) ELSE 0 END) AS returned_qty
                   FROM invoice_items ii
                   INNER JOIN invoices i
                       ON i.id = ii.invoice_id
                   LEFT JOIN products p
                       ON p.id = ii.product_id
                   WHERE i.customer_id=?
                   AND i.user_id=?
                   AND i.accounting_status='posted'
                   AND ii.unit_price = 0
                   GROUP BY i.invoice_date, ii.product_id, p.product_name
                   HAVING given_qty > 0 OR returned_qty > 0
                   ORDER BY i.invoice_date ASC, p.product_name ASC";

$returnable_stmt = mysqli_prepare($conn, $returnable_sql);

mysqli_stmt_bind_param(
    $returnable_stmt,
    "ii",
    $customer_id,
    $user_id
);

mysqli_stmt_execute($returnable_stmt);

$returnable_products =
    mysqli_stmt_get_result($returnable_stmt);

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
            <td>Total Sales</td>
            <td class="text-right"><?= number_format($total_sales,2); ?></td>
        </tr>
        <tr>
            <td>Total Paid</td>
            <td class="text-right"><?= number_format($total_paid,2); ?></td>
        </tr>
        <tr class="grand">
            <td><?= htmlspecialchars($current_due_label); ?></td>
            <td class="text-right"><?= number_format($current_due_display,2); ?></td>
        </tr>
    </table>

    <table>
        <thead>
        <tr>
            <th>Date</th>
            <th>Type</th>
            <th class="text-right">Debit</th>
            <th class="text-right">Credit</th>
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
                <td><?= htmlspecialchars($row['type']); ?><br><span class="muted"><?= htmlspecialchars($row['reference']); ?></span></td>
                <td class="text-right"><?= number_format($row['debit'],2); ?></td>
                <td class="text-right"><?= number_format($row['credit'],2); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>

    <h3>Returnable Products</h3>

    <table>
        <thead>
        <tr>
            <th>Date</th>
            <th>Product</th>
            <th class="text-right">Given</th>
            <th class="text-right">Return</th>
            <th class="text-right">Left</th>
        </tr>
        </thead>
        <tbody>
        <?php if(mysqli_num_rows($returnable_products) === 0){ ?>
            <tr>
                <td colspan="5" class="text-center muted">No returnable products found.</td>
            </tr>
        <?php } ?>
        <?php while($product = mysqli_fetch_assoc($returnable_products)){ ?>
            <?php
            $given_qty = (float)$product['given_qty'];
            $returned_qty = (float)$product['returned_qty'];
            $remaining_qty = $given_qty - $returned_qty;
            ?>
            <tr>
                <td><?= htmlspecialchars(app_datetime($product['invoice_date'])); ?></td>
                <td><?= htmlspecialchars($product['product_name'] ?? 'Product'); ?></td>
                <td class="text-right"><?= number_format($given_qty,0); ?></td>
                <td class="text-right"><?= number_format($returned_qty,0); ?></td>
                <td class="text-right"><?= number_format($remaining_qty,0); ?></td>
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
    background:#f1f5f9;
    color:#172033;
    font-family:Arial, sans-serif;
    font-size:12px;
    line-height:1.4;
    margin:0;
}

.ledger-page{
    background:#fff;
    box-shadow:0 3px 14px rgba(15,23,42,.12);
    margin:18px auto;
    max-width:210mm;
    padding:12mm;
}

.text-center > h2{
    color:#102a43;
    font-size:23px;
    letter-spacing:.2px;
}

.text-center > h3{
    border-bottom:2px solid #1d4ed8;
    color:#1e3a5f;
    font-size:14px;
    letter-spacing:.08em;
    margin:14px auto 0;
    padding-bottom:7px;
    text-transform:uppercase;
    width:min(100% - 24mm, 186mm);
}

table{
    border-collapse:collapse;
    margin:0;
    width:100%;
}

table th,
table td{
    border:1px solid #cbd5e1;
    padding:7px 8px;
}

table th{
    background:#eaf1fb;
    color:#1e3a5f;
    font-weight:700;
}

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
    border-radius:5px;
    color:#fff;
    cursor:pointer;
    font-size:13px;
    padding:8px 16px;
}

@media print{

    body{ background:#fff; }

    .no-print{
        display:none;
    }

    .ledger-page{
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

<div class="text-center">

    <h2>
        <?php echo htmlspecialchars($account_name); ?>
    </h2>

    <?php if(!empty($account['address']) && $account['address'] != 'None'){ ?>

    <div class="muted">
        <?php echo htmlspecialchars($account['address']); ?>
    </div>

    <?php } ?>

    <div class="muted">

        <?php if(!empty($account['phone'])){ ?>
            Phone: <?php echo htmlspecialchars($account['phone']); ?>
        <?php } ?>

        <?php if(!empty($account['email'])){ ?>
            <?= !empty($account['phone']) ? ' | ' : ''; ?>
            Email: <?php echo htmlspecialchars($account['email']); ?>
        <?php } ?>

    </div>

    <h3>
        Customer Ledger Statement
    </h3>

</div>

<main class="ledger-page">

<hr>

<table>

<tr>

<th width="20%">
Customer
</th>

<td>

<?php
echo htmlspecialchars(
    $customer['customer_name']
);
?>

</td>

<th width="15%">
Phone
</th>

<td>

<?php
echo htmlspecialchars(
    $customer['phone']
);
?>

</td>

</tr>

</table>

<br>

<table>

<tr>

<th>Total Sales</th>
<th>Total Paid</th>
<th>Current Due</th>

</tr>

<tr>

<td class="text-right">
<?php echo number_format($total_sales,2); ?>
</td>

<td class="text-right">
<?php echo number_format($total_paid,2); ?>
</td>

<td class="text-right">
<?php echo number_format($current_due_display,2); ?>
</td>

</tr>

</table>

<br>

<table>

<thead>

<tr>

<th>Date</th>
<th>Type</th>
<th>Reference</th>
<th>Debit</th>
<th>Credit</th>
<th>Balance</th>

</tr>

</thead>

<tbody>

<?php

$balance = 0;

if(empty($ledger)){
?>

<tr>

<td colspan="6" class="text-right">
No ledger entries found.
</td>

</tr>

<?php
}

foreach($ledger as $row){

    $balance += $row['debit'];
    $balance -= $row['credit'];

?>

<tr>

<td>
<?php echo app_datetime($row['trx_date']); ?>
</td>

<td>
<?php echo htmlspecialchars($row['type']); ?>
</td>

<td>
<?php echo htmlspecialchars($row['reference']); ?>
</td>

<td class="text-right">
<?php echo number_format($row['debit'],2); ?>
</td>

<td class="text-right">
<?php echo number_format($row['credit'],2); ?>
</td>

<td class="text-right">
<?php echo number_format($balance,2); ?>
</td>

</tr>

<?php } ?>

</tbody>

</table>

<h3>
Returnable Products
</h3>

<table>

<thead>

<tr>

<th>Date</th>
<th>Product</th>
<th>Given</th>
<th>Returned</th>
<th>Remaining</th>

</tr>

</thead>

<tbody>

<?php if(mysqli_num_rows($returnable_products) === 0){ ?>

<tr>

<td colspan="5" class="text-center muted">
No returnable products found.
</td>

</tr>

<?php } ?>

<?php while($product = mysqli_fetch_assoc($returnable_products)){ ?>

<?php
$given_qty = (float)$product['given_qty'];
$returned_qty = (float)$product['returned_qty'];
$remaining_qty = $given_qty - $returned_qty;
?>

<tr>

<td><?= htmlspecialchars(app_datetime($product['invoice_date'])); ?></td>
<td><?= htmlspecialchars($product['product_name'] ?? 'Product'); ?></td>
<td class="text-right"><?= number_format($given_qty,0); ?></td>
<td class="text-right"><?= number_format($returned_qty,0); ?></td>
<td class="text-right"><?= number_format($remaining_qty,0); ?></td>

</tr>

<?php } ?>

</tbody>

</table>

</main>

</body>
</html>
