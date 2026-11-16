<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/printing_helper.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_category_helper.php';
require_once '../includes/supplier_helper.php';

$user_id = (int)$_SESSION['user_id'];
$purchase_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
ensure_fifo_inventory_tables($conn);
ensure_product_subcategory_schema($conn);
ensure_supplier_code_column($conn);

$sql = "SELECT
            p.*,
            s.supplier_name,
            s.supplier_code,
            s.phone,
            s.address
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

$GLOBALS['printing_branch_context'] = (int)($purchase['branch_id'] ?? 0);

$sql = "SELECT
            pi.*,
            p.product_name,
            p.sku,
            p.sub_category,
            c.category_name
        FROM purchase_items pi
        LEFT JOIN products p
            ON p.id = pi.product_id
        LEFT JOIN product_categories c
            ON c.id = p.category_id
        WHERE pi.purchase_id=?";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $purchase_id);
mysqli_stmt_execute($stmt);
$items_result = mysqli_stmt_get_result($stmt);
$items = [];

while($row = mysqli_fetch_assoc($items_result)){
    $key = trim((string)($row['sku'] ?? '')) !== ''
        ? 'code:' . $row['sku']
        : 'product:' . (int)$row['product_id'];
    if(!isset($items[$key])){
        $row['variant_quantities'] = [];
        $items[$key] = $row;
    }else{
        $items[$key]['quantity'] += (float)$row['quantity'];
        $items[$key]['total_cost'] += (float)$row['total_cost'];
    }
    $variant = trim((string)($row['variant_name'] ?? ''));
    if($variant !== ''){
        $items[$key]['variant_quantities'][$variant] = ($items[$key]['variant_quantities'][$variant] ?? 0) + (float)$row['quantity'];
    }
}
foreach($items as &$item){
    $item['unit_cost'] = (float)$item['quantity'] > 0
        ? (float)$item['total_cost'] / (float)$item['quantity']
        : 0;
    $item['variant_summary'] = implode(' || ', array_map(
        static function($name, $qty){ return $name . ': ' . number_format($qty, 0); },
        array_keys($item['variant_quantities']),
        $item['variant_quantities']
    ));
}
unset($item);

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

// Company details follow the sales invoice, including the My Profile email / website switches.
$account_name = $profile['name'] ?? 'Account';
$account_address = $profile['address'] ?? '';
$account_phone = $profile['phone'] ?? '';
$account_email = $profile['email'] ?? '';
$account_website = company_profile_website($conn, $user_id);
$contact_visibility = company_invoice_contact_visibility($conn, $user_id);
if(!$contact_visibility['email']) $account_email = '';
if(!$contact_visibility['website']) $account_website = '';

$total_amount = (float)$purchase['total_amount'];
$paid_amount = (float)$purchase['paid_amount'];
$due_amount = (float)$purchase['due_amount'];
$purchase_status = $due_amount <= 0.009 ? 'paid' : ($paid_amount > 0.009 ? 'partial' : 'due');
$status_class = [
    'paid' => 'status-paid',
    'partial' => 'status-partial',
    'due' => 'status-due'
];
$status_label = [
    'paid' => 'Paid',
    'partial' => 'Partial',
    'due' => 'Due'
];

$custom_printing = is_custom_printing($conn);
$custom_size = current_printing_custom_size($conn);
$custom_top_margin = current_printing_custom_top_margin($conn);
$general_top_margin = current_printing_general_top_margin($conn);
$use_general_top_margin = should_use_general_top_margin($conn);
$custom_page_size = $custom_size['width'] . 'in ' . $custom_size['height'] . 'in';
$custom_page_width = $custom_size['width'] . 'in';
$custom_page_min_height = $custom_size['height'] . 'in';
$custom_page_margin = $custom_top_margin . 'in 12mm 12mm 12mm';
$general_page_margin = $general_top_margin . 'in 12mm 12mm 12mm';
$general_printing = current_printing_option($conn) === 'general';
$general_page_padding_top = $use_general_top_margin ? ($general_top_margin . 'in') : '22mm';
$screen_page_padding = $custom_printing
    ? ($custom_top_margin . 'in 18mm 22mm')
    : ($general_printing ? ($general_page_padding_top . ' 18mm 22mm') : '22mm 18mm');
$company_seal_url = current_company_seal_url($conn);
$company_logo_url = current_print_company_logo_url($conn);
$show_company_profile = should_print_company_profile($conn);
$show_general_company_logo = $general_printing && should_print_company_logo($conn) && $company_logo_url !== '';
// POS receipt: the logo sits at the very top when logo printing is active.
$show_pos_company_logo = should_print_company_logo($conn) && $company_logo_url !== '';
$show_general_company_seal = $general_printing && should_print_company_seal($conn) && $company_seal_url !== '';
$show_purchase_barcode = should_print_invoice_barcode($conn);

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

$supplier_text = supplier_name_with_code($purchase['supplier_name'], $purchase['supplier_code'] ?? '');

if(is_pos_printing($conn)){
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>POS Purchase <?= htmlspecialchars($purchase['purchase_no']); ?></title>

<style>
@page{
    size:80mm auto;
    margin:4mm;
}

*{
    box-sizing:border-box;
}

body{
    background:#f5f5f5;
    color:#111;
    font-family:Arial, Helvetica, sans-serif;
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

.toolbar{
    margin:12px auto 0;
    text-align:right;
    width:80mm;
}

.btn{
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

h1{
    font-size:15px;
    margin:0 0 3px;
}

.title{
    border-bottom:1px dashed #111;
    border-top:1px dashed #111;
    font-weight:bold;
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

/* Item lines: the name wraps, numbers stay on one line with a gap between columns. */
.pos-items td.item{word-break:break-word;overflow-wrap:anywhere}
.pos-items th.num,.pos-items td.num{text-align:center;white-space:nowrap;padding:4px 0 4px 8px}

.product-variants{display:block;margin-top:2px;font-size:85%;line-height:1.4}

.grand td{
    border-top:1px dashed #111;
    font-size:13px;
    font-weight:bold;
    padding-top:5px;
}

.invoice-barcode-wrap{
    margin:10px 0 0;
}

.invoice-barcode-wrap svg{
    margin:0 auto;
}

.signatures{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
    margin-top:28px;
}

.signature-line{
    border-top:1px solid #111;
    padding-top:4px;
    text-align:center;
}

.footer{
    border-top:1px dashed #111;
    margin-top:10px;
    padding-top:8px;
    text-align:center;
}

@media print{
    body{
        background:#fff;
    }

    .toolbar{
        display:none;
    }

    .receipt{
        margin:0;
        padding:0;
        width:auto;
    }
}
</style>
<script>
function preparePosPrintPage() {
    const receipt = document.querySelector('.receipt');
    if (!receipt) return;

    // CSS pixels are 96 DPI in Chrome. Include the printed page margins and
    // use a small safety buffer so the footer is never clipped.
    const heightMm = Math.max(65, Math.ceil(receipt.scrollHeight * 25.4 / 96) + 12);
    let pageStyle = document.getElementById('pos-print-page-size');
    if (!pageStyle) {
        pageStyle = document.createElement('style');
        pageStyle.id = 'pos-print-page-size';
        document.head.appendChild(pageStyle);
    }
    pageStyle.textContent = '@page { size: 80mm ' + heightMm + 'mm; margin: 4mm; }';
}

window.addEventListener('load', preparePosPrintPage);
</script>
</head>

<body>

<div class="toolbar">
    <button class="btn" onclick="preparePosPrintPage(); window.print()">Print Purchase</button>
</div>

<main class="receipt">
    <?php if($show_pos_company_logo){ ?>
    <div class="text-center" style="margin-bottom:6px">
        <img src="<?= htmlspecialchars($company_logo_url); ?>" alt="Company Logo" style="max-width:60%;max-height:70px;object-fit:contain">
    </div>
    <?php } ?>
    <?php if($show_company_profile){ ?>
    <div class="text-center">
        <h1><?= htmlspecialchars($account_name); ?></h1>
        <?php if(!empty($account_address) && $account_address != 'None'){ ?>
            <div class="muted"><?= htmlspecialchars($account_address); ?></div>
        <?php } ?>
        <?php $pos_contact = array_filter([
            !empty($account_phone) && $account_phone !== 'None' ? 'Phone: ' . $account_phone : '',
            !empty($account_email) && $account_email !== 'None' ? 'Email: ' . $account_email : '',
            // No email: the website joins the phone line instead of its own line.
            (empty($account_email) || $account_email === 'None') && $account_website !== '' ? 'Website: ' . $account_website : '',
        ]); ?>
        <?php if($pos_contact){ ?>
            <div class="muted"><?= htmlspecialchars(implode(', ', $pos_contact)); ?></div>
        <?php } ?>
        <?php if($account_website !== '' && !empty($account_email) && $account_email !== 'None'){ ?>
            <div class="muted">Website: <?= htmlspecialchars($account_website); ?></div>
        <?php } ?>
        <?php if(($pos_vat_text = print_vat_text($conn)) !== ''){ ?>
            <div style="display:inline-block;margin-top:3px;padding:1px 6px;border:1px solid #000;border-radius:3px;font-size:11px;color:#000"><strong>VAT Reg. No:</strong> <?= htmlspecialchars(substr($pos_vat_text, strlen('VAT Reg. No: '))); ?></div>
        <?php } ?>
    </div>
    <?php } ?>

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
        <span>Status</span>
        <strong><?= htmlspecialchars($status_label[$purchase_status]); ?></strong>
    </div>
    <div class="line">
        <span><?= supplier_display_text('Supplier'); ?></span>
        <span><?= htmlspecialchars($supplier_text); ?></span>
    </div>
    <?php if(!empty($purchase['phone'])){ ?>
        <div class="line">
            <span>Phone</span>
            <span><?= htmlspecialchars($purchase['phone']); ?></span>
        </div>
    <?php } ?>

    <table class="pos-items">
        <thead>
        <tr>
            <th>Product</th>
            <th class="num">Qty</th>
            <th class="num">Price</th>
            <th class="num">Total</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach($items as $item){ ?>
            <tr>
                <td class="item"><?= htmlspecialchars($item['product_name']); ?><?php if(!empty($item['variant_summary'])){ ?><small class="muted product-variants"><?= htmlspecialchars($item['variant_summary']); ?></small><?php } ?></td>
                <td class="num"><?= number_format($item['quantity'],0); ?></td>
                <td class="num"><?= number_format($item['unit_cost'],2); ?></td>
                <td class="num"><?= number_format($item['total_cost'],2); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>

    <table class="summary">
        <tr>
            <td>Total Purchase</td>
            <td class="text-right">BDT <?= number_format($total_amount,2); ?></td>
        </tr>
        <tr>
            <td>Paid</td>
            <td class="text-right">BDT <?= number_format($paid_amount,2); ?></td>
        </tr>
        <tr class="grand">
            <td>Due</td>
            <td class="text-right">BDT <?= number_format($due_amount,2); ?></td>
        </tr>
    </table>

    <?php if(!empty($purchase['notes'])){ ?>
        <p><strong>Notes:</strong> <?= nl2br(htmlspecialchars($purchase['notes'])); ?></p>
    <?php } ?>

    <section class="signatures">
        <div class="signature-line">Supplier Signature</div>
        <div class="signature-line">Authorized Signature</div>
    </section>

    <?php if($show_purchase_barcode){ ?>
    <div class="invoice-barcode-wrap">
        <?= printing_invoice_barcode_html($purchase['purchase_no'], 0.9, 36); ?>
    </div>
    <?php } ?>

    <div class="footer">
        <?= htmlspecialchars(print_thanks_text($conn)); ?>
    </div>
<?= printing_powered_by_html(); ?>
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
<title>Purchase <?= htmlspecialchars($purchase['purchase_no']); ?></title>

<style>
@page{
    size:<?= $custom_printing ? htmlspecialchars($custom_page_size) : 'A4'; ?>;
    margin:<?= $custom_printing ? htmlspecialchars($custom_page_margin) : ($use_general_top_margin ? htmlspecialchars($general_page_margin) : '12mm'); ?>;
}

*{
    box-sizing:border-box;
}

body{
    margin:0;
    background:#eef1f5;
    color:#1f2933;
    font-family:Arial, Helvetica, sans-serif;
    font-size:13px;
    line-height:1.45;
}

.page{
    width:<?= $custom_printing ? htmlspecialchars($custom_page_width) : '210mm'; ?>;
    min-height:<?= $custom_printing ? htmlspecialchars($custom_page_min_height) : '297mm'; ?>;
    margin:18px auto;
    background:#fff;
    padding:<?= htmlspecialchars($screen_page_padding); ?>;
    box-shadow:0 8px 28px rgba(15,23,42,.16);
}

.toolbar{
    width:<?= $custom_printing ? htmlspecialchars($custom_page_width) : '210mm'; ?>;
    margin:18px auto 0;
    text-align:right;
}

.btn{
    border:0;
    border-radius:4px;
    background:#2563eb;
    color:#fff;
    cursor:pointer;
    font-size:13px;
    padding:8px 14px;
}

.company-head{
    align-items:center;
    display:flex;
    gap:12px;
    margin-bottom:6px;
}

.company-logo-print{
    flex:0 0 auto;
    max-height:54px;
    max-width:72px;
    object-fit:contain;
}

.topbar{
    border-bottom:2px solid #111827;
    display:flex;
    justify-content:space-between;
    gap:24px;
    padding-bottom:18px;
}

.company h1{
    color:#111827;
    font-size:26px;
    letter-spacing:.3px;
    margin:0 0 6px;
}

.muted{
    color:#5f6b7a;
}

.invoice-title{
    text-align:right;
}

.invoice-barcode-wrap{
    margin-top:6px;
}

.invoice-barcode-wrap svg{
    margin-left:auto;
}

.invoice-meta{
    text-align:right;
}

.invoice-title h2{
    color:#111827;
    font-size:24px;
    margin:0 0 8px;
    text-transform:uppercase;
}

.status{
    border-radius:999px;
    display:inline-block;
    font-size:12px;
    font-weight:bold;
    padding:4px 10px;
}

.status-paid{
    background:#dcfce7;
    color:#166534;
}

.status-partial{
    background:#fef3c7;
    color:#92400e;
}

.status-due{
    background:#fee2e2;
    color:#991b1b;
}

.info-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:18px;
    margin-top:22px;
}

.panel{
    border:1px solid #d7dde5;
    border-radius:6px;
    padding:14px;
}

.panel-title{
    color:#111827;
    font-size:12px;
    font-weight:bold;
    letter-spacing:.5px;
    margin-bottom:10px;
    text-transform:uppercase;
}

.line{
    display:flex;
    justify-content:space-between;
    gap:14px;
    padding:3px 0;
}

.label{
    color:#5f6b7a;
}

.value{
    color:#111827;
    font-weight:bold;
    text-align:right;
}

/* Party box lines read "Customer : name": labels in one column, colons lined up. */
.panel .line{
    justify-content:flex-start;
}

.panel .label{
    display:flex;
    flex:0 0 90px;
    justify-content:space-between;
}

.panel .label::after{
    content:":";
    padding-left:4px;
}

.panel .value{
    text-align:left;
}

table{
    border-collapse:collapse;
    width:100%;
}

.items{
    margin-top:22px;
}

/* Amount columns stay on one line ("BDT 2,767,500.00"). */
.items td.text-right{
    white-space:nowrap;
}

th{
    background:#111827;
    color:#fff;
    font-size:12px;
    letter-spacing:.3px;
    padding:9px 8px;
    text-align:left;
    text-transform:uppercase;
}

td{
    border-bottom:1px solid #e5e7eb;
    padding:9px 8px;
    vertical-align:top;
}

.text-right{
    text-align:right;
}

.text-center{
    text-align:center;
}

.purchase-items{table-layout:fixed}
.purchase-items td{overflow-wrap:anywhere}
.purchase-items .text-right{white-space:nowrap;overflow-wrap:normal}
.product-variants{display:block;margin-top:4px;font-size:85%;line-height:1.5}

.summary{
    display:flex;
    justify-content:flex-end;
    margin-top:18px;
}

.summary table{
    width:42%;
}

.summary td{
    border:1px solid #d7dde5;
}

.summary .grand td{
    background:#111827;
    color:#fff;
    font-size:15px;
    font-weight:bold;
}

.notes{
    border-left:4px solid #2563eb;
    color:#374151;
    margin-top:18px;
    padding:8px 12px;
}

.stamp-grid{
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:24px;
    margin-top:26px;
}

.stamp-card{
    min-height:130px;
    text-align:center;
}

.stamp-card img{
    max-height:110px;
    max-width:100%;
    object-fit:contain;
}

.signatures{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:64px;
    margin-top:40px;
}

.signature-line{
    border-top:1px solid #111827;
    padding-top:8px;
    text-align:center;
}

/* Signatures, thank-you note and powered-by always stay on the same page. */
.print-end{
    break-inside:avoid;
    page-break-inside:avoid;
}

.footer-note{
    color:#6b7280;
    font-size:12px;
    margin-top:28px;
    text-align:center;
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
        min-height:auto;
        padding:0;
        width:auto;
    }
}
</style>
<?= printing_fit_one_page_html('.page', printing_fit_area_mm($conn)); ?>
</head>

<body>

<div class="toolbar">
    <button class="btn" onclick="window.print()">Print Purchase</button>
</div>

<main class="page">

    <section class="topbar">
        <?php if($show_company_profile){ ?>
        <div class="company">
            <div class="company-head">
                <?php if($show_general_company_logo){ ?>
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

            <div class="muted">
                <?php if(!empty($account_phone)){ ?>
                    Phone: <?= htmlspecialchars($account_phone); ?>
                <?php } ?>
                <?php if(!empty($account_email)){ ?>
                    <?= !empty($account_phone) ? ' | ' : ''; ?>
                    Email: <?= htmlspecialchars($account_email); ?>
                <?php } ?>
            </div>
            <?php if($account_website !== ''){ ?>
                <div class="muted">Website: <?= htmlspecialchars($account_website); ?></div>
            <?php } ?>
            <?php if(($account_vat_text = print_vat_text($conn)) !== ''){ ?>
                <div style="display:inline-block;margin-top:6px;padding:2px 10px;border:1px solid #1f2933;border-radius:4px;font-size:12px;color:#1f2933"><strong>VAT Reg. No:</strong> <?= htmlspecialchars(substr($account_vat_text, strlen('VAT Reg. No: '))); ?></div>
            <?php } ?>
        </div>
        <?php } ?>

        <div class="invoice-title">
            <h2>Purchase</h2>
            <div class="muted">Purchase No</div>
            <strong><?= htmlspecialchars($purchase['purchase_no']); ?></strong>
            <?php if($show_purchase_barcode){ ?>
            <div class="invoice-barcode-wrap">
                <?= printing_invoice_barcode_html($purchase['purchase_no'], 1, 40); ?>
            </div>
            <?php } ?>
        </div>

    </section>

    <section class="info-grid">

        <div class="panel">
            <div class="panel-title"><?= supplier_display_text('Supplier'); ?></div>

            <div class="line">
                <span class="label"><?= supplier_display_text('Supplier'); ?></span>
                <span class="value"><?= supplier_name_with_code_html($purchase['supplier_name'], $purchase['supplier_code'] ?? ''); ?></span>
            </div>

            <div class="line">
                <span class="label">Phone</span>
                <span class="value"><?= htmlspecialchars((string)($purchase['phone'] ?? '')); ?></span>
            </div>

            <div class="line">
                <span class="label">Address</span>
                <span class="value"><?= htmlspecialchars((string)($purchase['address'] ?? '')); ?></span>
            </div>
        </div>

        <div class="invoice-meta">
            <div><span class="muted">Date :</span> <strong><?= htmlspecialchars(app_date($purchase['purchase_date'] ?? '')); ?></strong></div>
            <br>
            <span class="status <?= $status_class[$purchase_status]; ?>">
                <?= $status_label[$purchase_status]; ?>
            </span>
        </div>

    </section>

    <table class="items purchase-items">
        <colgroup><col style="width:6%"><col style="width:36%"><col style="width:18%"><col style="width:9%"><col style="width:14%"><col style="width:17%"></colgroup>
        <thead>
        <tr>
            <th>SL</th>
            <th>Product</th>
            <th>Category</th>
            <th class="text-right">Qty</th>
            <th class="text-right">Cost</th>
            <th class="text-right">Total</th>
        </tr>
        </thead>
        <tbody>
        <?php if(empty($items)){ ?>
        <tr>
            <td colspan="6" class="text-center muted">No purchase items found.</td>
        </tr>
        <?php } ?>

        <?php $line_no = 0; foreach($items as $item){ $line_no++; ?>
        <tr>
            <td><?= $line_no; ?></td>
            <td><?= htmlspecialchars($item['product_name']); ?><?php if(!empty($item['variant_summary'])){ ?><small class="muted product-variants"><?= htmlspecialchars($item['variant_summary']); ?></small><?php } ?></td>
            <td><?= htmlspecialchars($item['category_name'] ?: '—'); ?><?php if(!empty($item['sub_category'])){ ?><small class="muted"><br><?= htmlspecialchars($item['sub_category']); ?></small><?php } ?></td>
            <td class="text-right"><?= number_format($item['quantity'],0); ?></td>
            <td class="text-right">BDT <?= number_format($item['unit_cost'],2); ?></td>
            <td class="text-right">BDT <?= number_format($item['total_cost'],2); ?></td>
        </tr>
        <?php } ?>
        </tbody>
    </table>

    <section class="summary">
        <table>
            <tr>
                <td>Total Purchase</td>
                <td class="text-right">BDT <?= number_format($total_amount,2); ?></td>
            </tr>
            <tr>
                <td>Paid</td>
                <td class="text-right">BDT <?= number_format($paid_amount,2); ?></td>
            </tr>
            <tr class="grand">
                <td>Due</td>
                <td class="text-right">BDT <?= number_format($due_amount,2); ?></td>
            </tr>
        </table>
    </section>

    <?php if(!empty($purchase['notes'])){ ?>
    <section class="notes">
        <strong>Notes:</strong>
        <?= nl2br(htmlspecialchars($purchase['notes'])); ?>
    </section>
    <?php } ?>

    <?php if($show_general_company_seal){ ?>
    <section class="stamp-grid">
        <div class="stamp-card">
            <img src="<?= htmlspecialchars($company_seal_url); ?>" alt="Company Seal">
        </div>
    </section>
    <?php } ?>

    <div class="print-end">
    <section class="signatures">
        <div class="signature-line">Supplier Signature</div>
        <div class="signature-line">Authorized Signature</div>
    </section>

    <div class="footer-note">
        <?= htmlspecialchars(print_thanks_text($conn)); ?>
    </div>

<?= printing_powered_by_html(); ?>
    </div>
</main>

</body>
</html>
