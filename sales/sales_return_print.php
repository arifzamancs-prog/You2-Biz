<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/printing_helper.php';
require_once '../includes/sales_return_helper.php';

$cid = (int)$_SESSION['user_id'];
$user_id = $cid;
sales_return_schema($conn);
$refund_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$branch_scope = selected_branch_id($conn, false);

$row = sales_refund_query(
    $conn,
    'SELECT r.*, i.invoice_no, i.invoice_date, i.customer_name, c.customer_code, c.phone, w.wallet_name, cn.invoice_no AS credit_no,
            xi.invoice_no AS exchange_invoice_no, xi.total_amount AS exchange_invoice_total, xi.paid_amount AS exchange_invoice_paid, xi.due_amount AS exchange_invoice_due
     FROM sales_refunds r
     LEFT JOIN invoices i ON i.id=r.invoice_id
     LEFT JOIN customers c ON c.id=i.customer_id AND c.user_id=r.user_id
     LEFT JOIN wallets w ON w.id=r.wallet_id
     LEFT JOIN invoices cn ON cn.id=r.credit_invoice_id
     LEFT JOIN invoices xi ON xi.id=r.exchange_invoice_id
     WHERE r.id=? AND r.user_id=?' . ($branch_scope > 0 ? ' AND r.branch_id=' . (int)$branch_scope : ''),
    'ii',
    [$refund_id, $cid]
)->get_result()->fetch_assoc();

if(!$row){
    die('Sales Return Not Found');
}

$value = (float)$row['return_value'] > 0 ? (float)$row['return_value'] : (float)$row['amount'];

// The slip uses the same fields as the You2 Wallet return slip.
$return = [
    'return_no' => $row['credit_no'] ?: ('#' . $row['id']),
    'created_at' => $row['created_at'],
    'invoice_no' => $row['invoice_no'],
    'invoice_date' => $row['invoice_date'],
    'customer_name' => $row['customer_name'],
    'customer_code' => $row['customer_code'],
    'phone' => $row['phone'],
    'total_amount' => $value,
    'cash_refund' => (float)$row['amount'],
    'wallet_name' => $row['wallet_name'],
    'due_adjusted' => (float)$row['due_adjusted'],
    'advance_amount' => (float)$row['advance_amount'],
    'exchange_invoice_id' => $row['exchange_invoice_id'],
    'exchange_amount' => (float)$row['exchange_amount'],
    'exchange_invoice_no' => $row['exchange_invoice_no'],
    'exchange_invoice_total' => $row['exchange_invoice_total'],
    'exchange_invoice_paid' => $row['exchange_invoice_paid'],
    'exchange_invoice_due' => $row['exchange_invoice_due'],
    'note' => $row['reason'],
    'created_by_name' => '',
];

// Returned lines: from the return itself, or from the credit note for refunds made on the older Refund page.
$items = sales_refund_query(
    $conn,
    'SELECT oi.product_id, oi.variant_name, p.product_name, ri.quantity, ri.unit_price, ri.total_amount, ri.item_condition
     FROM sales_refund_items ri JOIN invoice_items oi ON oi.id=ri.invoice_item_id LEFT JOIN products p ON p.id=oi.product_id
     WHERE ri.refund_id=? ORDER BY ri.id',
    'i',
    [$refund_id]
)->get_result()->fetch_all(MYSQLI_ASSOC);

if((float)$row['return_value'] <= 0){
    $items = sales_refund_query(
        $conn,
        'SELECT ii.variant_name, p.product_name, ABS(ii.quantity) AS quantity, ii.unit_price, ABS(ii.total_price) AS total_amount, \'\' AS item_condition
         FROM invoice_items ii LEFT JOIN products p ON p.id=ii.product_id WHERE ii.invoice_id=? ORDER BY ii.id',
        'i',
        [(int)$row['credit_invoice_id']]
    )->get_result()->fetch_all(MYSQLI_ASSOC);
}

$is_exchange = !empty($row['exchange_invoice_id']);
$new_items = $is_exchange
    ? sales_refund_query($conn, 'SELECT ii.*, p.product_name FROM invoice_items ii LEFT JOIN products p ON p.id=ii.product_id WHERE ii.invoice_id=? ORDER BY ii.id', 'i', [(int)$row['exchange_invoice_id']])->get_result()->fetch_all(MYSQLI_ASSOC)
    : [];
// Cash the customer paid on the new invoice (its paid amount includes the credit).
$exchange_cash_paid = $is_exchange ? max(0, round((float)$row['exchange_invoice_paid'] - (float)$row['exchange_amount'], 2)) : 0;

if(!function_exists('product_display_name')){
    function product_display_name($product_name, $variant_name = '')
    {
        $variant_name = trim((string)$variant_name);

        return $variant_name === '' ? (string)$product_name : $product_name . ' (' . $variant_name . ')';
    }
}

if(!function_exists('contact_name_with_code')){
    function contact_name_with_code($name, $code)
    {
        $code = trim((string)$code);

        return $code === '' ? (string)$name : $name . ' (ID: ' . $code . ')';
    }
}

$stmt = mysqli_prepare($conn, "SELECT name, address, email, phone FROM users WHERE id=?");
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

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Sales Return <?= htmlspecialchars($return['return_no']); ?></title>

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
<?php if(!$pos_printing){ echo printing_fit_one_page_html('.page', printing_fit_area_mm($conn)); } ?>
</head>

<body>

<div class="toolbar">
    <a class="btn" href="sales_return.php" style="text-decoration:none;">Back</a>
    <button class="btn" onclick="window.print()">Print</button>
</div>

<main class="page">
    <div class="<?= $pos_printing ? 'text-center' : ''; ?>">
        <div class="company-head">
            <?php if(!$pos_printing && $show_general_company_logo){ ?>
                <img src="<?= htmlspecialchars($company_logo_url); ?>" alt="Company Logo" class="company-logo-print">
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

    <div class="title"><?= $is_exchange ? 'Exchange' : 'Sales Return'; ?></div>

    <div class="line">
        <span>Return No</span>
        <strong><?= htmlspecialchars($return['return_no']); ?></strong>
    </div>
    <div class="line">
        <span>Date</span>
        <span><?= htmlspecialchars(app_datetime($return['created_at'])); ?></span>
    </div>
    <div class="line">
        <span>Invoice No</span>
        <span><?= htmlspecialchars((string)$return['invoice_no']); ?> (<?= htmlspecialchars(app_date($return['invoice_date'])); ?>)</span>
    </div>
    <div class="line">
        <span>Customer</span>
        <span><?= htmlspecialchars(contact_name_with_code($return['customer_name'], $return['customer_code'] ?? '')); ?></span>
    </div>
    <?php if(!empty($return['phone'])){ ?>
        <div class="line">
            <span>Phone</span>
            <span><?= htmlspecialchars($return['phone']); ?></span>
        </div>
    <?php } ?>

    <table>
        <thead>
        <tr>
            <th><?= $is_exchange ? 'Returned Products' : 'Product'; ?></th>
            <th class="text-right">Qty</th>
            <th class="text-right">Price</th>
            <th class="text-right">Total</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach($items as $item){ ?>
            <tr>
                <td>
                    <?= htmlspecialchars(product_display_name($item['product_name'] ?? 'Product', $item['variant_name'] ?? '')); ?>
                    <?php if($item['item_condition'] === 'damaged'){ ?><br><span class="muted">(Damaged)</span><?php } ?>
                </td>
                <td class="text-right"><?= (int)$item['quantity']; ?></td>
                <td class="text-right"><?= number_format((float)$item['unit_price'], 2); ?></td>
                <td class="text-right"><?= number_format((float)$item['total_amount'], 2); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>

    <?php if($is_exchange){ ?>

    <div class="line" style="margin-top:12px;">
        <span>New Invoice</span>
        <strong><?= htmlspecialchars((string)$return['exchange_invoice_no']); ?></strong>
    </div>

    <table>
        <thead>
        <tr>
            <th>New Products</th>
            <th class="text-right">Qty</th>
            <th class="text-right">Price</th>
            <th class="text-right">Total</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach($new_items as $item){ ?>
            <tr>
                <td><?= htmlspecialchars(product_display_name($item['product_name'] ?? 'Product', $item['variant_name'] ?? '')); ?></td>
                <td class="text-right"><?= (int)$item['quantity']; ?></td>
                <td class="text-right"><?= number_format((float)$item['unit_price'], 2); ?></td>
                <td class="text-right"><?= number_format((float)$item['total_price'], 2); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>

    <table class="summary">
        <tr>
            <td>New Invoice Total</td>
            <td class="text-right">BDT <?= number_format((float)$return['exchange_invoice_total'], 2); ?></td>
        </tr>
        <tr>
            <td>Exchange Credit (returned)</td>
            <td class="text-right">BDT -<?= number_format((float)$return['total_amount'], 2); ?></td>
        </tr>
        <?php if($exchange_cash_paid > 0){ ?>
        <tr>
            <td>Customer Paid</td>
            <td class="text-right">BDT <?= number_format($exchange_cash_paid, 2); ?></td>
        </tr>
        <?php } ?>
        <?php if((float)$return['cash_refund'] > 0){ ?>
        <tr>
            <td>Cash Refund<?= !empty($return['wallet_name']) ? ' (' . htmlspecialchars($return['wallet_name']) . ')' : ''; ?></td>
            <td class="text-right">BDT <?= number_format((float)$return['cash_refund'], 2); ?></td>
        </tr>
        <?php } ?>
        <?php if((float)$return['exchange_invoice_due'] > 0.009){ ?>
        <tr class="grand">
            <td>Due</td>
            <td class="text-right">BDT <?= number_format((float)$return['exchange_invoice_due'], 2); ?></td>
        </tr>
        <?php } ?>
    </table>

    <?php }else{ ?>

    <table class="summary">
        <?php if((float)$return['cash_refund'] > 0){ ?>
        <tr>
            <td>Cash Refund<?= !empty($return['wallet_name']) ? ' (' . htmlspecialchars($return['wallet_name']) . ')' : ''; ?></td>
            <td class="text-right">BDT <?= number_format((float)$return['cash_refund'], 2); ?></td>
        </tr>
        <?php } ?>
        <?php if((float)$return['due_adjusted'] > 0){ ?>
        <tr>
            <td>Deducted from Due</td>
            <td class="text-right">BDT <?= number_format((float)$return['due_adjusted'], 2); ?></td>
        </tr>
        <?php } ?>
        <?php if((float)$return['advance_amount'] > 0){ ?>
        <tr>
            <td>Kept as Advance</td>
            <td class="text-right">BDT <?= number_format((float)$return['advance_amount'], 2); ?></td>
        </tr>
        <?php } ?>
        <tr class="grand">
            <td>Return Total</td>
            <td class="text-right">BDT <?= number_format((float)$return['total_amount'], 2); ?></td>
        </tr>
    </table>

    <?php } ?>

    <?php if(!empty($return['note'])){ ?>
        <p><strong>Note:</strong> <?= nl2br(htmlspecialchars($return['note'])); ?></p>
    <?php } ?>

    <?php if(!empty($return['created_by_name'])){ ?>
        <p class="muted">Created by: <?= htmlspecialchars($return['created_by_name']); ?></p>
    <?php } ?>

    <?php if(!$pos_printing && $show_general_company_seal){ ?>
        <div class="seal-wrap">
            <div class="seal-card">
                <img src="<?= htmlspecialchars($company_seal_url); ?>" alt="Company Seal">
            </div>
        </div>
    <?php } ?>
<?= printing_powered_by_html(); ?>
</main>

</body>
</html>
