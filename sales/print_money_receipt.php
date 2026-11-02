<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/printing_helper.php';
require_once '../includes/customer_due_allocation_helper.php';

$user_id = (int)$_SESSION['user_id'];
ensure_customer_opening_due_tables($conn);
$receipt_no = trim((string)($_GET['no'] ?? ''));

// The wallet entry is the receipt itself: amount, wallet, collecting branch and time.
$stmt = mysqli_prepare(
    $conn,
    "SELECT t.*, w.wallet_name, COALESCE(NULLIF(b.branch_name, ''), 'Head Office') AS branch_name
     FROM transactions t
     LEFT JOIN wallets w ON w.id=t.wallet_id
     LEFT JOIN branches b ON b.id=t.branch_id
     WHERE t.user_id=? AND t.txn_no=? AND t.transaction_type='receive_payment'
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, "is", $user_id, $receipt_no);
mysqli_stmt_execute($stmt);
$receipt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if($receipt_no === '' || !$receipt){
    die('Money Receipt Not Found');
}

// Due entries this payment cleared (they may belong to different branches).
$stmt = mysqli_prepare(
    $conn,
    "SELECT cp.customer_id, cp.amount, i.invoice_no, d.due_no,
            COALESCE(NULLIF(b.branch_name, ''), 'Head Office') AS branch_name
     FROM customer_payments cp
     LEFT JOIN invoices i ON i.id=cp.invoice_id
     LEFT JOIN customer_opening_dues d ON d.id=cp.opening_due_id
     LEFT JOIN branches b ON b.id=cp.branch_id
     WHERE cp.user_id=? AND cp.receipt_no=?
     ORDER BY cp.id"
);
mysqli_stmt_bind_param($stmt, "is", $user_id, $receipt_no);
mysqli_stmt_execute($stmt);
$lines = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
foreach($lines as &$line){
    $line['reference_no'] = (string)($line['invoice_no'] ?? $line['due_no'] ?? '');
}
unset($line);

$customer = null;
$customer_id = (int)($lines[0]['customer_id'] ?? 0);

if($customer_id > 0){
    $stmt = mysqli_prepare($conn, "SELECT customer_name, customer_code, phone FROM customers WHERE id=? AND user_id=?");
    mysqli_stmt_bind_param($stmt, "ii", $customer_id, $user_id);
    mysqli_stmt_execute($stmt);
    $customer = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
}else{
    // Instant (unregistered) customer: the wallet entry points at the invoice.
    $stmt = mysqli_prepare(
        $conn,
        "SELECT i.invoice_no, i.customer_name, COALESCE(NULLIF(b.branch_name, ''), 'Head Office') AS branch_name
         FROM invoices i LEFT JOIN branches b ON b.id=i.branch_id
         WHERE i.id=? AND i.user_id=?"
    );
    $reference_id = (int)$receipt['reference_id'];
    mysqli_stmt_bind_param($stmt, "ii", $reference_id, $user_id);
    mysqli_stmt_execute($stmt);
    $invoice = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if($invoice){
        $customer = ['customer_name' => $invoice['customer_name'], 'customer_code' => '', 'phone' => ''];
        $lines = [[
            'amount' => (float)$receipt['amount'],
            'reference_no' => $invoice['invoice_no'],
            'branch_name' => $invoice['branch_name'],
        ]];
    }
}

// Due still open after this receipt, across all branches.
$remaining_due = null;
if($customer_id > 0){
    $stmt = mysqli_prepare(
        $conn,
        "SELECT GREATEST(
            COALESCE((SELECT SUM(total_amount) FROM invoices WHERE user_id=? AND customer_id=? AND accounting_status='posted'),0)
            + COALESCE((SELECT SUM(amount) FROM customer_opening_dues WHERE user_id=? AND customer_id=?),0)
            - COALESCE((SELECT SUM(amount) FROM customer_payments WHERE user_id=? AND customer_id=?),0),
            0
        ) AS due"
    );
    mysqli_stmt_bind_param($stmt, "iiiiii", $user_id, $customer_id, $user_id, $customer_id, $user_id, $customer_id);
    mysqli_stmt_execute($stmt);
    $remaining_due = (float)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['due'] ?? 0);
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
$company_logo_url = current_print_company_logo_url($conn);
$show_general_company_logo = $general_printing && should_print_company_logo($conn) && $company_logo_url !== '';

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Money Receipt <?= htmlspecialchars($receipt_no); ?></title>

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

.signatures{
    display:flex;
    justify-content:space-between;
    margin-top:60px;
}

.signatures span{
    border-top:1px solid #111;
    min-width:160px;
    padding-top:4px;
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
        padding:0;
        width:auto;
    }
}
</style>
<?php if(!$pos_printing){ echo printing_fit_one_page_html('.page', printing_fit_area_mm($conn)); } ?>
</head>

<body>

<div class="toolbar">
    <a class="btn" href="receive_payment.php<?= isset($_GET['back']) ? '?success=1&receipt=' . urlencode($receipt_no) : ''; ?>" style="text-decoration:none;">Back</a>
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

    <div class="title">Money Receipt</div>

    <div class="line">
        <span>Receipt No</span>
        <strong><?= htmlspecialchars($receipt_no); ?></strong>
    </div>
    <div class="line">
        <span>Date</span>
        <span><?= htmlspecialchars(app_datetime($receipt['created_at'])); ?></span>
    </div>
    <?php if($customer){ ?>
        <div class="line">
            <span>Customer</span>
            <span><?= htmlspecialchars(contact_name_with_code($customer['customer_name'], $customer['customer_code'] ?? '')); ?></span>
        </div>
        <?php if(!empty($customer['phone'])){ ?>
            <div class="line">
                <span>Phone</span>
                <span><?= htmlspecialchars($customer['phone']); ?></span>
            </div>
        <?php } ?>
    <?php } ?>
    <div class="line">
        <span>Received At</span>
        <span><?= htmlspecialchars($receipt['branch_name']); ?></span>
    </div>
    <div class="line">
        <span>Received In</span>
        <span><?= htmlspecialchars((string)$receipt['wallet_name']); ?></span>
    </div>

    <table>
        <thead>
        <tr>
            <th>Due Reference</th>
            <th>Branch</th>
            <th class="text-right">Paid</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach($lines as $line){ ?>
            <tr>
                <td><?= htmlspecialchars($line['reference_no']); ?></td>
                <td><?= htmlspecialchars($line['branch_name']); ?></td>
                <td class="text-right"><?= number_format((float)$line['amount'], 2); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>

    <table class="summary">
        <tr class="grand">
            <td>Total Received</td>
            <td class="text-right">BDT <?= number_format((float)$receipt['amount'], 2); ?></td>
        </tr>
        <?php if($remaining_due !== null){ ?>
            <tr>
                <td>Remaining Due</td>
                <td class="text-right">BDT <?= number_format($remaining_due, 2); ?></td>
            </tr>
        <?php } ?>
    </table>

    <?php if(!$pos_printing){ ?>
        <div class="signatures">
            <span>Customer Signature</span>
            <span>Authorized Signature</span>
        </div>
    <?php }else{ ?>
        <p class="text-center muted" style="margin-top:12px;">Thank you</p>
    <?php } ?>
</main>

<script>
window.addEventListener("load", function(){ window.print(); });
</script>

</body>
</html>
