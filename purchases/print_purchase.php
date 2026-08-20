<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/printing_helper.php';

$user_id = (int)$_SESSION['user_id'];
$purchase_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$sql = "SELECT
            p.*,
            s.supplier_name,
            s.phone
        FROM purchases p
        LEFT JOIN suppliers s
            ON s.id = p.supplier_id
        WHERE p.id=?
        AND p.user_id=?";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "ii", $purchase_id, $user_id);
mysqli_stmt_execute($stmt);
$purchase = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if(!$purchase){
    die("Purchase Not Found");
}

$sql = "SELECT
            pi.*,
            p.product_name
        FROM purchase_items pi
        LEFT JOIN products p
            ON p.id = pi.product_id
        WHERE pi.purchase_id=?";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $purchase_id);
mysqli_stmt_execute($stmt);
$items_result = mysqli_stmt_get_result($stmt);
$items = [];

while($row = mysqli_fetch_assoc($items_result)){
    $items[] = $row;
}

$sql = "SELECT
            name,
            address,
            email,
            phone
        FROM users
        WHERE id=?";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$profile = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

$account_name = $profile['name'] ?? 'Account';
$account_address = $profile['address'] ?? '';
$account_phone = $profile['phone'] ?? '';
$pos_printing = is_pos_printing($conn);
$custom_printing = is_custom_printing($conn);
$custom_size = current_printing_custom_size($conn);
$custom_top_margin = current_printing_custom_top_margin($conn);
$general_top_margin = current_printing_general_top_margin($conn);
$use_general_top_margin = should_use_general_top_margin($conn);
$print_page_size = $custom_printing
    ? $custom_size['width'] . 'in ' . $custom_size['height'] . 'in'
    : ($pos_printing ? '80mm auto' : 'A4');
$print_page_width = $custom_printing
    ? $custom_size['width'] . 'in'
    : ($pos_printing ? '80mm' : '210mm');
$print_page_margin = $custom_printing
    ? $custom_top_margin . 'in 12mm 12mm 12mm'
    : ($pos_printing ? '4mm' : ($use_general_top_margin ? $general_top_margin . 'in 12mm 12mm 12mm' : '12mm'));
$general_printing = current_printing_option($conn) === 'general';
$general_page_padding_top = $use_general_top_margin ? ($general_top_margin . 'in') : '22mm';
$screen_page_padding = $pos_printing
    ? '8px'
    : ($custom_printing
        ? ($custom_top_margin . 'in 18mm 22mm')
        : ($general_printing ? ($general_page_padding_top . ' 18mm 22mm') : '22mm 18mm'));
$company_seal_url = current_company_seal_url($conn);
$company_logo_url = current_print_company_logo_url($conn);
$show_general_company_logo = $general_printing && should_print_company_logo($conn) && $company_logo_url !== '';
$show_general_company_seal = $general_printing && should_print_company_seal($conn) && $company_seal_url !== '';

function purchase_print_datetime_display($purchase)
{
    $purchase_no = (string)($purchase['purchase_no'] ?? '');

    if(preg_match('/PUR-(\d{14})/', $purchase_no, $matches)){
        $format = str_starts_with($matches[1], '20') ? 'YmdHis' : 'ymdHis';
        $value = $format === 'YmdHis' ? $matches[1] : substr($matches[1], 0, 12);
        $time = DateTime::createFromFormat($format, $value);

        if($time instanceof DateTime){
            return $time->format('d-m-Y h:i A');
        }
    }

    if(!empty($purchase['created_at'] ?? '')){
        return app_datetime($purchase['created_at']);
    }

    return app_date($purchase['purchase_date'] ?? '');
}

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Purchase <?= htmlspecialchars($purchase['purchase_no']); ?></title>

<style>
@page{
    size:<?= htmlspecialchars($print_page_size); ?>;
    margin:<?= htmlspecialchars($print_page_margin); ?>;
}

*{
    box-sizing:border-box;
}

body{
    background:#eef1f5;
    color:#111827;
    font-family:Arial, Helvetica, sans-serif;
    font-size:<?= $pos_printing ? '11px' : '13px'; ?>;
    line-height:1.4;
    margin:0;
}

.page{
    background:#fff;
    box-shadow:0 8px 28px rgba(15,23,42,.16);
    margin:18px auto;
    padding:<?= htmlspecialchars($screen_page_padding); ?>;
    width:<?= htmlspecialchars($print_page_width); ?>;
}

.toolbar{
    margin:18px auto 0;
    text-align:right;
    width:<?= htmlspecialchars($print_page_width); ?>;
}

.btn{
    background:#111827;
    border:0;
    border-radius:4px;
    color:#fff;
    cursor:pointer;
    padding:7px 12px;
}

.company-head{
    align-items:center;
    display:flex;
    gap:12px;
    justify-content:<?= $pos_printing ? 'center' : 'flex-start'; ?>;
    margin-bottom:6px;
}

.company-logo-print{
    flex:0 0 auto;
    max-height:54px;
    max-width:72px;
    object-fit:contain;
}

.text-center{
    text-align:center;
}

.text-right{
    text-align:right;
}

.muted{
    color:#5f6b7a;
}

h1{
    font-size:<?= $pos_printing ? '15px' : '26px'; ?>;
    margin:0 0 5px;
}

.title{
    border-bottom:<?= $pos_printing ? '1px dashed #111' : '2px solid #111827'; ?>;
    border-top:<?= $pos_printing ? '1px dashed #111' : '0'; ?>;
    font-size:<?= $pos_printing ? '12px' : '24px'; ?>;
    font-weight:bold;
    margin:<?= $pos_printing ? '8px 0' : '18px 0'; ?>;
    padding:<?= $pos_printing ? '5px 0' : '0 0 14px'; ?>;
    text-align:<?= $pos_printing ? 'center' : 'left'; ?>;
    text-transform:uppercase;
}

.line{
    display:flex;
    justify-content:space-between;
    gap:10px;
    padding:2px 0;
}

table{
    border-collapse:collapse;
    margin-top:<?= $pos_printing ? '8px' : '18px'; ?>;
    width:100%;
}

th,
td{
    border-bottom:<?= $pos_printing ? '1px dashed #bbb' : '1px solid #e5e7eb'; ?>;
    padding:<?= $pos_printing ? '4px 0' : '9px 8px'; ?>;
    vertical-align:top;
}

th{
    background:<?= $pos_printing ? 'transparent' : '#111827'; ?>;
    color:<?= $pos_printing ? '#111' : '#fff'; ?>;
    font-size:<?= $pos_printing ? '10px' : '12px'; ?>;
    text-align:left;
}

.summary td{
    border:0;
    padding:2px 0;
}

.grand td{
    border-top:1px dashed #111;
    font-size:<?= $pos_printing ? '13px' : '15px'; ?>;
    font-weight:bold;
    padding-top:5px;
}

.seal-wrap{
    margin-top:26px;
    text-align:right;
}

.seal-card{
    display:inline-block;
    text-align:center;
}

.seal-card img{
    max-height:110px;
    max-width:100%;
    object-fit:contain;
}

@media print{
    body{
        background:#fff;
    }

    .toolbar{
        display:none;
    }

    .page{
        box-shadow:none;
        margin:0;
        padding:0;
        width:auto;
    }
}
</style>
</head>

<body>

<div class="toolbar">
    <button class="btn" onclick="window.print()">Print</button>
</div>

<main class="page">
    <div class="<?= $pos_printing ? 'text-center' : ''; ?>">
        <div class="company-head">
            <?php if(!$pos_printing && $show_general_company_logo){ ?>
                <img
                    src="<?= htmlspecialchars($company_logo_url); ?>"
                    alt="Company Logo"
                    class="company-logo-print">
            <?php } ?>

            <h1><?= htmlspecialchars($account_name); ?></h1>
        </div>
        <?php if(!empty($account_address) && $account_address != 'None'){ ?>
            <div class="muted"><?= htmlspecialchars($account_address); ?></div>
        <?php } ?>
        <?php if(!empty($account_phone)){ ?>
            <div class="muted">Phone: <?= htmlspecialchars($account_phone); ?></div>
        <?php } ?>
    </div>

    <div class="title">Purchase</div>

    <div class="line">
        <span>Purchase No</span>
        <strong><?= htmlspecialchars($purchase['purchase_no']); ?></strong>
    </div>
    <div class="line">
        <span>Date</span>
        <span><?= htmlspecialchars(purchase_print_datetime_display($purchase)); ?></span>
    </div>
    <div class="line">
        <span>Supplier</span>
        <span><?= htmlspecialchars($purchase['supplier_name']); ?></span>
    </div>
    <?php if(!empty($purchase['phone'])){ ?>
        <div class="line">
            <span>Phone</span>
            <span><?= htmlspecialchars($purchase['phone']); ?></span>
        </div>
    <?php } ?>

    <table>
        <thead>
        <tr>
            <th>Product</th>
            <th class="text-right">Qty</th>
            <th class="text-right">Cost</th>
            <th class="text-right">Total</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach($items as $item){ ?>
            <tr>
                <td><?= htmlspecialchars($item['product_name']); ?></td>
                <td class="text-right"><?= number_format($item['quantity'],0); ?></td>
                <td class="text-right"><?= number_format($item['unit_cost'],2); ?></td>
                <td class="text-right"><?= number_format($item['total_cost'],2); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>

    <table class="summary">
        <tr>
            <td>Total Purchase</td>
            <td class="text-right">BDT <?= number_format($purchase['total_amount'],2); ?></td>
        </tr>
        <tr>
            <td>Paid</td>
            <td class="text-right">BDT <?= number_format($purchase['paid_amount'],2); ?></td>
        </tr>
        <tr class="grand">
            <td>Due</td>
            <td class="text-right">BDT <?= number_format($purchase['due_amount'],2); ?></td>
        </tr>
    </table>

    <?php if(!empty($purchase['notes'])){ ?>
        <p><strong>Notes:</strong> <?= nl2br(htmlspecialchars($purchase['notes'])); ?></p>
    <?php } ?>

    <?php if(!$pos_printing && $show_general_company_seal){ ?>
        <div class="seal-wrap">
            <div class="seal-card">
                <img src="<?= htmlspecialchars($company_seal_url); ?>" alt="Company Seal">
            </div>
        </div>
    <?php } ?>
</main>

</body>
</html>
