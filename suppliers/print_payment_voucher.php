<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';

$user_id = (int)$_SESSION['user_id'];
$payment_id = (int)($_GET['id'] ?? 0);

$payment_stmt = mysqli_prepare(
    $conn,
    "SELECT sp.*, s.supplier_name, s.phone AS supplier_phone, p.purchase_no, w.wallet_name
     FROM supplier_payments sp
     LEFT JOIN suppliers s ON s.id=sp.supplier_id
     LEFT JOIN purchases p ON p.id=sp.purchase_id
     LEFT JOIN wallets w ON w.id=sp.wallet_id
     WHERE sp.id=? AND sp.user_id=? LIMIT 1"
);
mysqli_stmt_bind_param($payment_stmt, 'ii', $payment_id, $user_id);
mysqli_stmt_execute($payment_stmt);
$payment = mysqli_fetch_assoc(mysqli_stmt_get_result($payment_stmt));
if(!$payment){ die('Payment voucher not found.'); }

$company_stmt = mysqli_prepare($conn, 'SELECT name, address, phone, email FROM users WHERE id=? LIMIT 1');
mysqli_stmt_bind_param($company_stmt, 'i', $user_id);
mysqli_stmt_execute($company_stmt);
$company = mysqli_fetch_assoc(mysqli_stmt_get_result($company_stmt)) ?: [];
$voucher_no = 'SPV-' . str_pad((string)$payment_id, 6, '0', STR_PAD_LEFT);
?>
<!doctype html><html><head><meta charset="utf-8"><title>Payment Voucher <?= htmlspecialchars($voucher_no); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eef2f7;color:#172033;font-family:Arial,Helvetica,sans-serif}.toolbar{width:820px;margin:20px auto 0;text-align:right}.toolbar button{background:#1769e0;color:#fff;border:0;border-radius:5px;padding:9px 16px;font-weight:bold;cursor:pointer}.voucher{width:820px;margin:14px auto 30px;background:#fff;padding:46px 52px;box-shadow:0 10px 30px rgba(15,23,42,.12)}.head{display:flex;justify-content:space-between;border-bottom:3px solid #1769e0;padding-bottom:20px}.company{font-size:26px;font-weight:800;color:#102e58}.muted{color:#667085;font-size:13px;line-height:1.55}.title{text-align:right;font-size:22px;font-weight:800;letter-spacing:1px;color:#1769e0}.grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:28px}.box{border:1px solid #d9e1ec;border-radius:7px;padding:17px}.label{font-size:11px;text-transform:uppercase;letter-spacing:.7px;color:#667085;font-weight:bold;margin-bottom:7px}.value{font-size:16px;font-weight:700}.details{width:100%;border-collapse:collapse;margin-top:26px}.details th{background:#102e58;color:#fff;text-align:left;padding:12px}.details td{padding:14px;border-bottom:1px solid #dfe6ef}.right{text-align:right}.amount{font-size:22px;font-weight:800;color:#d24444}.note{margin-top:22px;border-left:4px solid #1769e0;background:#f6f9fd;padding:13px;white-space:pre-line}.footer{display:flex;justify-content:space-between;margin-top:48px;color:#667085;font-size:12px}.sign{width:180px;border-top:1px solid #9aa7b8;padding-top:7px;text-align:center}@media print{body{background:#fff}.toolbar{display:none}.voucher{box-shadow:none;margin:0;width:100%;padding:0}}
</style></head><body>
<div class="toolbar"><button onclick="window.print()">Print Voucher</button></div>
<main class="voucher"><header class="head"><div><div class="company"><?= htmlspecialchars($company['name'] ?? 'Company'); ?></div><div class="muted"><?= nl2br(htmlspecialchars($company['address'] ?? '')); ?><br><?= htmlspecialchars($company['phone'] ?? ''); ?><?= !empty($company['phone']) && !empty($company['email']) ? ' · ' : ''; ?><?= htmlspecialchars($company['email'] ?? ''); ?></div></div><div><div class="title">PAYMENT VOUCHER</div><div class="muted" style="text-align:right;margin-top:8px">Voucher No: <?= htmlspecialchars($voucher_no); ?><br>Date: <?= htmlspecialchars(app_date($payment['payment_date'])); ?></div></div></header>
<section class="grid"><div class="box"><div class="label">Paid To</div><div class="value"><?= htmlspecialchars($payment['supplier_name'] ?: 'Supplier'); ?></div><div class="muted"><?= htmlspecialchars($payment['supplier_phone'] ?: ''); ?></div></div><div class="box"><div class="label">Payment From Wallet</div><div class="value"><?= htmlspecialchars($payment['wallet_name'] ?: '—'); ?></div><div class="label" style="margin-top:15px">Purchase Reference</div><div class="value"><?= htmlspecialchars($payment['purchase_no'] ?: '—'); ?></div></div></section>
<table class="details"><thead><tr><th>Description</th><th class="right">Amount</th></tr></thead><tbody><tr><td>Supplier due payment for purchase <?= htmlspecialchars($payment['purchase_no'] ?: ''); ?></td><td class="right amount">BDT <?= number_format((float)$payment['amount'], 2); ?></td></tr></tbody></table>
<?php if(trim((string)$payment['note']) !== ''){ ?><div class="note"><strong>Note</strong><br><?= htmlspecialchars($payment['note']); ?></div><?php } ?><footer class="footer"><div>Generated on <?= date('d-m-Y h:i A'); ?></div><div class="sign">Authorized Signature</div></footer></main>
</body></html>
