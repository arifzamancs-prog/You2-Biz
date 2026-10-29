<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/printing_helper.php';
require_once '../includes/supplier_helper.php';
require_once '../includes/supplier_ledger_helper.php';

$user_id = $_SESSION['user_id'];

if(supplier_uses_auto_code($conn, $user_id)){
    ensure_supplier_code_column($conn);
    supplier_code_fill_missing($conn, (int)$user_id);
}

$supplier_id = isset($_GET['id'])
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

/* Supplier + Ledger */

$data = supplier_ledger_data($conn, $user_id, $supplier_id);

if(!$data){
    die(supplier_display_text("Supplier Not Found"));
}

$supplier = $data['supplier'];
$ledger = $data['ledger'];
$total_purchase = $data['total_purchase'];
$total_paid = $data['total_paid'];
$current_due_label = $data['current_due'] < 0 ? 'Outstanding Amount' : 'Current Due';
$current_due_display = $data['current_due_display'];
$supplier_display_name = supplier_name_with_code($supplier['supplier_name'], $supplier['supplier_code'] ?? '');

if(is_pos_printing($conn)){
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title><?= supplier_display_text("Supplier Ledger"); ?></title>

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

    <h3><?= supplier_display_text("Supplier Ledger"); ?></h3>

    <div class="line">
        <span><?= supplier_display_text("Supplier"); ?></span>
        <strong><?= htmlspecialchars($supplier_display_name); ?></strong>
    </div>
    <?php if(!empty($supplier['phone'])){ ?>
    <div class="line">
        <span>Phone</span>
        <span><?= htmlspecialchars($supplier['phone']); ?></span>
    </div>
    <?php } ?>

    <table class="summary">
        <tr>
            <td>Total Purchase</td>
            <td class="text-right"><?= number_format($total_purchase,2); ?></td>
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
            <th class="text-right">Purchase</th>
            <th class="text-right">Paid</th>
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
                <td><?= htmlspecialchars(app_date($row['trx_date'])); ?></td>
                <td><?= htmlspecialchars($row['type']); ?><br><span class="muted"><?= htmlspecialchars($row['reference']); ?></span></td>
                <td class="text-right"><?= number_format($row['debit'],2); ?></td>
                <td class="text-right"><?= number_format($row['credit'],2); ?></td>
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
<?= supplier_display_text("Supplier Ledger"); ?>
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
        <?= supplier_display_text("Supplier Ledger Statement"); ?>
    </h3>

</div>

<main class="ledger-page">

<hr>


<table>

<tr>

<th width="20%">
<?= supplier_display_text("Supplier"); ?>
</th>

<td>
<?= htmlspecialchars($supplier_display_name); ?>
</td>

<th width="15%">
Phone
</th>

<td>
<?= htmlspecialchars($supplier['phone'] ?? ''); ?>
</td>

</tr>

</table>

<br>

<table>

<tr>

<th>Total Purchase</th>
<th>Total Paid</th>
<th><?= htmlspecialchars($current_due_label); ?></th>

</tr>

<tr>

<td class="text-right">
<?= number_format($total_purchase,2); ?>
</td>

<td class="text-right">
<?= number_format($total_paid,2); ?>
</td>

<td class="text-right">
<?= number_format($current_due_display,2); ?>
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
<th>Purchase</th>
<th>Paid</th>
<th>Balance</th>

</tr>

</thead>

<tbody>

<?php if(empty($ledger)){ ?>

<tr>

<td colspan="6" class="text-center muted">
No ledger entries found.
</td>

</tr>

<?php } ?>

<?php foreach($ledger as $row){ ?>

<tr>

<td><?= htmlspecialchars(app_date($row['trx_date'])); ?></td>
<td><?= htmlspecialchars($row['type']); ?></td>
<td><?= htmlspecialchars($row['reference']); ?></td>
<td class="text-right"><?= number_format($row['debit'],2); ?></td>
<td class="text-right"><?= number_format($row['credit'],2); ?></td>
<td class="text-right"><?= ledger_balance_display($row['running_balance']); ?></td>

</tr>

<?php } ?>

</tbody>

</table>

</main>

</body>
</html>
