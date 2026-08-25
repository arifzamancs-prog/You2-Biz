<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/printing_helper.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/super_admin_config.php';
require_once '../includes/customer_due_allocation_helper.php';

$user_id = (int)$_SESSION['user_id'];
ensure_invoice_posting_columns($conn);

$invoice_id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;
$reload_parent = isset($_GET['reload_parent'])
    ? trim((string)$_GET['reload_parent'])
    : '';
$reload_parent_url = '';

if($reload_parent === 'invoice_list'){
    $reload_parent_url = 'invoice_list.php';
}elseif($reload_parent === 'create'){
    $reload_parent_url = 'create_invoice.php';
}

$sql = "SELECT
            i.*,
            c.phone,
            c.address
        FROM invoices i
        LEFT JOIN customers c
            ON c.id = i.customer_id
        WHERE i.id=?
        AND i.user_id=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $invoice_id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$invoice = mysqli_fetch_assoc($result);

if(!$invoice){
    die("Invoice Not Found");
}

if(invoice_is_pending($invoice)){
    $post_redirect = "post_invoice.php?id=" . $invoice_id;

    if($reload_parent !== ''){
        $post_redirect .= "&reload_parent=" . urlencode($reload_parent);
    }

    header("Location: " . $post_redirect);
    exit;
}

$sale_amount = (float)$invoice['total_amount'];
$cash_paid_amount = (float)$invoice['paid_amount'];
$customer_balance_before_invoice = 0.0;
$customer_balance_display_base = 0.0;
$is_existing_customer_invoice = (int)($invoice['customer_id'] ?? 0) > 0;
$source_previous_due_payment_total = 0.0;
$source_invoice_payment_total = 0.0;

if($is_existing_customer_invoice){
    $customer_id = (int)$invoice['customer_id'];
    $customer_balance_before_invoice = customer_signed_balance_total(
        $conn,
        $user_id,
        $customer_id,
        $invoice_id
    );
    $source_previous_due_payment_total = customer_source_invoice_payment_total(
        $conn,
        $user_id,
        $customer_id,
        $invoice_id,
        $invoice['invoice_no']
    );
    $source_invoice_payment_total = customer_source_invoice_all_payment_total(
        $conn,
        $user_id,
        $customer_id,
        $invoice_id,
        $invoice['invoice_no']
    );
    $customer_balance_before_invoice += $source_invoice_payment_total;
    $customer_balance_display_base = $customer_balance_before_invoice;
}

$previous_due_display_amount = $customer_balance_display_base > 0
    ? $customer_balance_display_base
    : 0.0;
$outstanding_available_amount = $customer_balance_display_base < 0
    ? abs($customer_balance_display_base)
    : 0.0;
$has_previous_due_context = $previous_due_display_amount > 0.01;
$has_outstanding_context = $outstanding_available_amount > 0.01;
$applied_outstanding_to_invoice = min(
    $outstanding_available_amount,
    max($sale_amount - $cash_paid_amount, 0)
);
$balance_context_label = $has_outstanding_context
    ? 'Outstanding Amount'
    : 'Previous Due';
$balance_context_amount = $has_outstanding_context
    ? $outstanding_available_amount
    : $previous_due_display_amount;
$sale_with_previous_due = $has_outstanding_context
    ? ($sale_amount - $outstanding_available_amount)
    : ($sale_amount + $previous_due_display_amount);
$sale_with_previous_due_label = $sale_with_previous_due < -0.01
    ? 'Total(Outstanding)'
    : 'Total';
$sale_with_previous_due_display = abs($sale_with_previous_due);
$has_sale_amount = $sale_amount > 0.01;
$total_due_amount = $sale_with_previous_due - $cash_paid_amount;
$is_due_payment_voucher = !$has_sale_amount && $previous_due_display_amount > 0.01;
$calculated_balance_after_paid = $is_existing_customer_invoice
    ? ($customer_balance_display_base + $sale_amount - $cash_paid_amount)
    : ($sale_with_previous_due - $cash_paid_amount);
$total_balance_label = $calculated_balance_after_paid < -0.01 || ((float)$invoice['due_amount']) < 0
    ? 'Outstanding Amount'
    : 'Total Due';
$total_balance_display = abs($calculated_balance_after_paid);
$invoice_due_label = ((float)$invoice['due_amount']) < 0 ? 'Outstanding Amount' : 'Total Due';
$invoice_due_display = abs((float)$invoice['due_amount']);
if(!$is_existing_customer_invoice && !$has_previous_due_context && $invoice_due_display > 0.01){
    $total_balance_label = $invoice_due_label;
    $total_balance_display = $invoice_due_display;
}

$has_due_context = $balance_context_amount > 0.01;
$show_existing_due_layout = $is_existing_customer_invoice &&
    $has_sale_amount &&
    (
        $has_previous_due_context ||
        $has_due_context ||
        $invoice_due_display > 0.01 ||
        $source_previous_due_payment_total > 0.01
    );
$show_balance_context_line = $has_due_context || $show_existing_due_layout;
$show_due_total_line = (($has_previous_due_context || $has_due_context) && $has_sale_amount) ||
    $show_existing_due_layout;
$show_balance_line = $has_previous_due_context ||
    $has_due_context ||
    $invoice_due_display > 0.01 ||
    $show_existing_due_layout;

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

$profile_result = mysqli_stmt_get_result($stmt);
$profile = mysqli_fetch_assoc($profile_result);

$sql = "SELECT
            ii.*,
            p.product_name
        FROM invoice_items ii
        LEFT JOIN products p
            ON p.id = ii.product_id
        WHERE ii.invoice_id=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $invoice_id
);

mysqli_stmt_execute($stmt);

$items_result = mysqli_stmt_get_result($stmt);
$items = [];

while($row = mysqli_fetch_assoc($items_result)){
    $items[] = $row;
}

$returnable_summary = [];

if((int)($invoice['customer_id'] ?? 0) > 0){
    $returnable_sql = "SELECT
                           ii.product_id,
                           p.product_name,
                           SUM(
                               CASE
                                   WHEN i.id <> ?
                                   AND (
                                       i.invoice_date < ?
                                       OR (i.invoice_date = ? AND i.id < ?)
                                   )
                                   AND ii.quantity > 0
                                   THEN ii.quantity
                                   ELSE 0
                               END
                           ) AS previous_given_qty,
                           SUM(
                               CASE
                                   WHEN i.id <> ?
                                   AND (
                                       i.invoice_date < ?
                                       OR (i.invoice_date = ? AND i.id < ?)
                                   )
                                   AND ii.quantity < 0
                                   THEN ABS(ii.quantity)
                                   ELSE 0
                               END
                           ) AS previous_returned_qty,
                           SUM(
                               CASE
                                   WHEN i.id = ?
                                   AND ii.quantity > 0
                                   THEN ii.quantity
                                   ELSE 0
                               END
                           ) AS current_given_qty,
                           SUM(
                               CASE
                                   WHEN i.id = ?
                                   AND ii.quantity < 0
                                   THEN ABS(ii.quantity)
                                   ELSE 0
                               END
                           ) AS current_returned_qty
                       FROM invoice_items ii
                       INNER JOIN invoices i
                           ON i.id = ii.invoice_id
                       LEFT JOIN products p
                           ON p.id = ii.product_id
                       WHERE i.customer_id=?
                       AND i.user_id=?
                       AND i.accounting_status='posted'
                       AND ii.unit_price = 0
                       AND (
                           i.invoice_date < ?
                           OR (i.invoice_date = ? AND i.id <= ?)
                       )
                       GROUP BY ii.product_id, p.product_name
                       ORDER BY p.product_name ASC";

    $returnable_stmt = mysqli_prepare($conn, $returnable_sql);

    if($returnable_stmt){
        $invoice_date = (string)($invoice['invoice_date'] ?? '');
        $customer_id = (int)$invoice['customer_id'];

        mysqli_stmt_bind_param(
            $returnable_stmt,
            "issiissiiiiissi",
            $invoice_id,
            $invoice_date,
            $invoice_date,
            $invoice_id,
            $invoice_id,
            $invoice_date,
            $invoice_date,
            $invoice_id,
            $invoice_id,
            $invoice_id,
            $customer_id,
            $user_id,
            $invoice_date,
            $invoice_date,
            $invoice_id
        );

        mysqli_stmt_execute($returnable_stmt);
        $returnable_result = mysqli_stmt_get_result($returnable_stmt);

        while($row = mysqli_fetch_assoc($returnable_result)){
            $previous_given_qty = (float)($row['previous_given_qty'] ?? 0);
            $previous_returned_qty = (float)($row['previous_returned_qty'] ?? 0);
            $current_given_qty = (float)($row['current_given_qty'] ?? 0);
            $current_returned_qty = (float)($row['current_returned_qty'] ?? 0);
            $previous_remaining_qty = $previous_given_qty - $previous_returned_qty;
            $current_remaining_qty = $previous_remaining_qty + $current_given_qty - $current_returned_qty;

            if(
                abs($previous_remaining_qty) < 0.00001 &&
                abs($current_given_qty) < 0.00001 &&
                abs($current_returned_qty) < 0.00001 &&
                abs($current_remaining_qty) < 0.00001
            ){
                continue;
            }

            $returnable_summary[] = [
                'product_name' => (string)($row['product_name'] ?? 'Returnable Product'),
                'previous_remaining_qty' => $previous_remaining_qty,
                'current_given_qty' => $current_given_qty,
                'current_returned_qty' => $current_returned_qty,
                'current_remaining_qty' => $current_remaining_qty,
            ];
        }
    }
}

$sql = "SELECT
            ic.amount,
            ict.charge_name,
            ict.charge_type
        FROM invoice_charges ic
        LEFT JOIN invoice_charge_types ict
            ON ict.id = ic.charge_type_id
        WHERE ic.invoice_id=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $invoice_id
);

mysqli_stmt_execute($stmt);

$charges_result = mysqli_stmt_get_result($stmt);
$charges = [];

while($row = mysqli_fetch_assoc($charges_result)){
    $charges[] = $row;
}

if(is_super_admin_user() && empty($profile)){
    $account_name = defined('SUPER_ADMIN_NAME') ? SUPER_ADMIN_NAME : 'Account';
    $account_address = defined('SUPER_ADMIN_PROFILE_ADDRESS') ? SUPER_ADMIN_PROFILE_ADDRESS : '';
    $account_phone = defined('SUPER_ADMIN_PROFILE_PHONE') ? SUPER_ADMIN_PROFILE_PHONE : '';
    $account_email = function_exists('super_admin_notify_email') ? super_admin_notify_email() : '';
}else{
    $account_name = $profile['name'] ?? 'Account';
    $account_address = $profile['address'] ?? '';
    $account_phone = $profile['phone'] ?? '';
    $account_email = $profile['email'] ?? '';
}

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

$invoice_created_by_text = '';
$creator_type = '';
$hide_invoice_notes = !should_print_invoice_notes($conn);

if(!empty($invoice['created_by_name'] ?? '')){
    $creator_type = trim((string)($invoice['created_by_type'] ?? ''));
    $creator_name = trim((string)$invoice['created_by_name']);

    if(should_print_invoice_created_by($conn) && in_array($creator_type, ['Agent', 'Manager'], true)){
        $invoice_created_by_text = 'Invoice created by ' . $creator_name;
    }

    if($creator_type === 'Agent'){
        $hide_invoice_notes = true;
    }
}

function invoice_product_display($product_name, $quantity, $unit_price)
{
    $quantity_value = (float)$quantity;

    if((float)$unit_price == 0.0){
        return $product_name . ($quantity_value < 0 ? ' (Return)' : ' (Given)');
    }

    return $product_name;
}

function invoice_qty_display($quantity, $unit_price)
{
    $quantity_value = (float)$quantity;

    if((float)$unit_price == 0.0){
        $quantity_value = abs($quantity_value);
    }

    return rtrim(rtrim(number_format($quantity_value, 2, '.', ''), '0'), '.');
}

function invoice_plain_qty_display($quantity)
{
    return rtrim(rtrim(number_format((float)$quantity, 2, '.', ''), '0'), '.');
}

function invoice_print_datetime_display($invoice)
{
    $invoice_no = (string)($invoice['invoice_no'] ?? '');

    if(preg_match('/INV-(\d{14})/', $invoice_no, $matches)){
        $format = str_starts_with($matches[1], '20') ? 'YmdHis' : 'ymdHis';
        $value = $format === 'YmdHis' ? $matches[1] : substr($matches[1], 0, 12);
        $time = DateTime::createFromFormat($format, $value);

        if($time instanceof DateTime){
            return $time->format('d-m-Y h:i A');
        }
    }

    if(!empty($invoice['created_at'] ?? '')){
        return app_datetime($invoice['created_at']);
    }

    return app_date($invoice['invoice_date'] ?? '');
}

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
$paid_seal_url = current_paid_seal_url($conn);
$company_logo_url = current_print_company_logo_url($conn);
$show_company_profile = should_print_company_profile($conn);
$show_general_company_logo = $general_printing && should_print_company_logo($conn) && $company_logo_url !== '';
$show_general_company_seal = $general_printing && should_print_company_seal($conn) && $company_seal_url !== '';
$show_general_paid_seal = $general_printing && should_print_paid_seal($conn) && $paid_seal_url !== '' && (($invoice['payment_status'] ?? '') === 'paid' || $total_due_amount <= 0.009);

if(is_pos_printing($conn)){
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>POS Invoice <?= htmlspecialchars($invoice['invoice_no']); ?></title>

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

.grand td{
    border-top:1px dashed #111;
    font-size:13px;
    font-weight:bold;
    padding-top:5px;
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
<?php if($reload_parent_url !== ''){ ?>
<script>
window.addEventListener('load', function () {
    if (window.opener && !window.opener.closed) {
        window.opener.location = <?= json_encode($reload_parent_url); ?>;
    }
});
</script>
<?php } ?>
</head>

<body>

<div class="toolbar">
    <button class="btn" onclick="window.print()">Print Invoice</button>
</div>

<main class="receipt">
    <?php if($show_company_profile){ ?>
    <div class="text-center">
        <h1><?= htmlspecialchars($account_name); ?></h1>
        <?php if(!empty($account_address) && $account_address != 'None'){ ?>
            <div class="muted"><?= htmlspecialchars($account_address); ?></div>
        <?php } ?>
        <?php if(!empty($account_phone)){ ?>
            <div class="muted">Phone: <?= htmlspecialchars($account_phone); ?></div>
        <?php } ?>
    </div>
    <?php } ?>

    <div class="title">Sales Invoice</div>

    <div class="line">
        <span>Invoice</span>
        <strong><?= htmlspecialchars($invoice['invoice_no']); ?></strong>
    </div>
    <div class="line">
        <span>Date</span>
        <span><?= htmlspecialchars(invoice_print_datetime_display($invoice)); ?></span>
    </div>
    <div class="line">
        <span>Customer</span>
        <span><?= htmlspecialchars($invoice['customer_name']); ?></span>
    </div>
    <?php if(!empty($invoice['phone'])){ ?>
        <div class="line">
            <span>Phone</span>
            <span><?= htmlspecialchars($invoice['phone']); ?></span>
        </div>
    <?php } ?>

    <table>
        <thead>
        <tr>
            <th>Item</th>
            <th class="text-right">Qty</th>
            <th class="text-right">Rate</th>
            <th class="text-right">Total</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach($items as $item){ ?>
            <tr>
                <td><?= htmlspecialchars(invoice_product_display($item['product_name'], $item['quantity'], $item['unit_price'])); ?></td>
                <td class="text-right"><?= htmlspecialchars(invoice_qty_display($item['quantity'], $item['unit_price'])); ?></td>
                <td class="text-right"><?= number_format($item['unit_price'],2); ?></td>
                <td class="text-right"><?= number_format($item['total_price'],2); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>

    <?php if(!empty($charges)){ ?>
        <table class="summary">
            <?php foreach($charges as $charge){ ?>
                <tr>
                    <td><?= htmlspecialchars($charge['charge_name']); ?></td>
                    <td class="text-right"><?= number_format($charge['amount'],2); ?></td>
                </tr>
            <?php } ?>
        </table>
    <?php } ?>

    <table class="summary">
        <tr>
            <td>Net Amount</td>
            <td class="text-right">BDT <?= number_format($sale_amount,2); ?></td>
        </tr>
        <?php if($show_balance_context_line){ ?>
        <tr>
            <td><?= htmlspecialchars($balance_context_label); ?></td>
            <td class="text-right">BDT <?= number_format($balance_context_amount,2); ?></td>
        </tr>
        <?php if($show_due_total_line){ ?>
        <tr>
            <td><?= htmlspecialchars($sale_with_previous_due_label); ?></td>
            <td class="text-right">BDT <?= number_format($sale_with_previous_due_display,2); ?></td>
        </tr>
        <?php } ?>
        <?php } ?>
        <tr>
            <td>Paid</td>
            <td class="text-right">BDT <?= number_format($cash_paid_amount,2); ?></td>
        </tr>
        <?php if($show_balance_line){ ?>
        <tr class="grand">
            <td><?= htmlspecialchars($total_balance_label); ?></td>
            <td class="text-right">BDT <?= number_format($total_balance_display,2); ?></td>
        </tr>
        <?php } ?>
    </table>

    <?php if(!$hide_invoice_notes && !empty($invoice['notes'])){ ?>
        <p><strong>Notes:</strong> <?= nl2br(htmlspecialchars($invoice['notes'])); ?></p>
    <?php } ?>

    <?php if($invoice_created_by_text !== ''){ ?>
        <p><strong><?= htmlspecialchars($invoice_created_by_text); ?></strong></p>
    <?php } ?>

    <div class="footer">
        Thank you for your cooperation.
    </div>
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
<title>Invoice <?= htmlspecialchars($invoice['invoice_no']); ?></title>

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

table{
    border-collapse:collapse;
    width:100%;
}

.items{
    margin-top:22px;
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

.stamp-card.paid img{
    opacity:.9;
}

.signatures{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:64px;
    margin-top:70px;
}

.signature-line{
    border-top:1px solid #111827;
    padding-top:8px;
    text-align:center;
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
<?php if($reload_parent_url !== ''){ ?>
<script>
window.addEventListener('load', function () {
    if (window.opener && !window.opener.closed) {
        window.opener.location = <?= json_encode($reload_parent_url); ?>;
    }
});
</script>
<?php } ?>
</head>

<body>

<div class="toolbar">
    <button class="btn" onclick="window.print()">Print Invoice</button>
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
        </div>
        <?php } ?>

        <div class="invoice-title">
            <h2>Sales Invoice</h2>
            <div class="muted">Invoice No</div>
            <strong><?= htmlspecialchars($invoice['invoice_no']); ?></strong>
            <br>
            <span class="status <?= $status_class[$invoice['payment_status']] ?? 'status-due'; ?>">
                <?= $status_label[$invoice['payment_status']] ?? 'Due'; ?>
            </span>
        </div>

    </section>

    <section class="info-grid">

        <div class="panel">
            <div class="panel-title">Bill To</div>

            <div class="line">
                <span class="label">Customer</span>
                <span class="value"><?= htmlspecialchars($invoice['customer_name']); ?></span>
            </div>

            <?php if(!empty($invoice['phone'])){ ?>
            <div class="line">
                <span class="label">Phone</span>
                <span class="value"><?= htmlspecialchars($invoice['phone']); ?></span>
            </div>
            <?php } ?>

            <?php if(!empty($invoice['address'])){ ?>
            <div class="line">
                <span class="label">Address</span>
                <span class="value"><?= htmlspecialchars($invoice['address']); ?></span>
            </div>
            <?php } ?>
        </div>

    </section>

    <table class="items">
        <thead>
        <tr>
            <th style="width:7%;">SL</th>
            <th>Product</th>
            <th class="text-right" style="width:15%;">Qty</th>
            <th class="text-right" style="width:18%;">Unit Price</th>
            <th class="text-right" style="width:18%;">Total</th>
        </tr>
        </thead>
        <tbody>
        <?php if(empty($items)){ ?>
        <tr>
            <td colspan="5" class="text-center muted">No invoice items found.</td>
        </tr>
        <?php } ?>

        <?php foreach($items as $index => $item){ ?>
        <tr>
            <td><?= $index + 1; ?></td>
            <td><?= htmlspecialchars(invoice_product_display($item['product_name'], $item['quantity'], $item['unit_price'])); ?></td>
            <td class="text-right"><?= htmlspecialchars(invoice_qty_display($item['quantity'], $item['unit_price'])); ?></td>
            <td class="text-right">BDT <?= number_format($item['unit_price'],2); ?></td>
            <td class="text-right">BDT <?= number_format($item['total_price'],2); ?></td>
        </tr>
        <?php } ?>
        </tbody>
    </table>

    <?php if(!empty($charges)){ ?>
    <table class="items">
        <thead>
        <tr>
            <th>Charge</th>
            <th style="width:20%;">Type</th>
            <th class="text-right" style="width:22%;">Amount</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach($charges as $charge){ ?>
        <tr>
            <td><?= htmlspecialchars($charge['charge_name']); ?></td>
            <td><?= htmlspecialchars(ucfirst($charge['charge_type'])); ?></td>
            <td class="text-right">BDT <?= number_format($charge['amount'],2); ?></td>
        </tr>
        <?php } ?>
        </tbody>
    </table>
    <?php } ?>

    <section class="summary">
        <table>
            <tr>
                <td>Net Amount</td>
                <td class="text-right">BDT <?= number_format($sale_amount,2); ?></td>
            </tr>
            <?php if($show_balance_context_line){ ?>
            <tr>
                <td><?= htmlspecialchars($balance_context_label); ?></td>
                <td class="text-right">BDT <?= number_format($balance_context_amount,2); ?></td>
            </tr>
            <?php if($show_due_total_line){ ?>
            <tr>
                <td><?= htmlspecialchars($sale_with_previous_due_label); ?></td>
                <td class="text-right">BDT <?= number_format($sale_with_previous_due_display,2); ?></td>
            </tr>
            <?php } ?>
            <?php } ?>
            <tr>
                <td>Paid</td>
                <td class="text-right">BDT <?= number_format($cash_paid_amount,2); ?></td>
            </tr>
            <?php if($show_balance_line){ ?>
            <tr class="grand">
                <td><?= htmlspecialchars($total_balance_label); ?></td>
                <td class="text-right">BDT <?= number_format($total_balance_display,2); ?></td>
            </tr>
            <?php } ?>
        </table>
    </section>

    <?php if(!$hide_invoice_notes && !empty($invoice['notes'])){ ?>
    <section class="notes">
        <strong>Notes:</strong>
        <?= nl2br(htmlspecialchars($invoice['notes'])); ?>
    </section>
    <?php } ?>

    <?php if($invoice_created_by_text !== ''){ ?>
    <section class="notes">
        <strong><?= htmlspecialchars($invoice_created_by_text); ?></strong>
    </section>
    <?php } ?>

    <?php if($show_general_company_seal || $show_general_paid_seal){ ?>
    <section class="stamp-grid">
        <?php if($show_general_company_seal){ ?>
        <div class="stamp-card">
            <img src="<?= htmlspecialchars($company_seal_url); ?>" alt="Company Seal">
        </div>
        <?php } ?>

        <?php if($show_general_paid_seal){ ?>
        <div class="stamp-card paid">
            <img src="<?= htmlspecialchars($paid_seal_url); ?>" alt="Paid Seal">
        </div>
        <?php } ?>
    </section>
    <?php } ?>

    <section class="signatures">
        <div class="signature-line">Customer Signature</div>
        <div class="signature-line">Authorized Signature</div>
    </section>

    <div class="footer-note">
        Thank you for your cooperation.
    </div>

</main>

</body>
</html>
