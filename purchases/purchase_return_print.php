<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/printing_helper.php';
require_once '../includes/purchase_return_helper.php';

$cid = (int)$_SESSION['user_id'];
purchase_return_schema($conn);
$return_id = (int)($_GET['id'] ?? 0);

$rows = purchase_return_rows(
    $conn,
    'SELECT r.*, p.purchase_no, p.purchase_date, s.supplier_name, s.phone AS supplier_phone, s.address AS supplier_address, w.wallet_name
     FROM purchase_returns r
     LEFT JOIN purchases p ON p.id=r.purchase_id
     LEFT JOIN suppliers s ON s.id=r.supplier_id AND s.user_id=r.user_id
     LEFT JOIN wallets w ON w.id=r.wallet_id
     WHERE r.id=? AND r.user_id=?',
    'ii',
    [$return_id, $cid]
);
$return = $rows[0] ?? null;
if(!$return){
    die('Purchase return not found.');
}
$items = purchase_return_rows(
    $conn,
    'SELECT ri.*, pr.product_name, pr.sku FROM purchase_return_items ri LEFT JOIN products pr ON pr.id=ri.product_id WHERE ri.return_id=? ORDER BY ri.id',
    'i',
    [$return_id]
);
$profile = purchase_return_rows($conn, 'SELECT name, address, email, phone FROM users WHERE id=?', 'i', [$cid])[0] ?? [];
$company_logo_url = current_print_company_logo_url($conn);
$show_logo = current_printing_option($conn) === 'general' && should_print_company_logo($conn) && $company_logo_url !== '';
$supplier_word = supplier_display_text('Supplier');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Purchase Return <?= purchase_return_h($return['return_no']); ?></title>
<style>
@page{size:A4;margin:12mm}
*{box-sizing:border-box}
body{margin:0;background:#eef1f5;font:13px/1.5 Arial,Helvetica,sans-serif;color:#111827}
.page{max-width:210mm;margin:16px auto;background:#fff;padding:20mm 16mm;box-shadow:0 2px 10px rgba(0,0,0,.08)}
.head{display:flex;justify-content:space-between;gap:20px;border-bottom:2px solid #111827;padding-bottom:14px;margin-bottom:18px}
.head h1{margin:0 0 6px;font-size:22px}
.head .muted{color:#4b5563;font-size:12px}
.title{text-align:right}
.title h2{margin:0;font-size:20px;letter-spacing:.5px}
.title strong{display:block;margin-top:6px}
.logo{max-height:60px;max-width:180px;margin-bottom:6px}
.boxes{display:flex;gap:16px;margin-bottom:18px}
.box{flex:1;border:1px solid #e5e7eb;border-radius:6px;padding:12px 14px}
.box b{display:block;font-size:11px;letter-spacing:.6px;color:#6b7280;margin-bottom:6px}
table{width:100%;border-collapse:collapse}
th{background:#111827;color:#fff;text-align:left;padding:8px;font-size:12px}
td{padding:8px;border-bottom:1px solid #e5e7eb}
.r{text-align:right}
.summary{width:300px;margin:16px 0 0 auto}
.summary td{border:1px solid #e5e7eb}
.summary .grand td{background:#111827;color:#fff;font-weight:700}
.note{margin-top:16px;font-size:12px;color:#374151}
.sign{display:flex;justify-content:space-between;margin-top:60px}
.sign div{width:40%;border-top:1px solid #111827;text-align:center;padding-top:6px;font-size:12px}
.actions{max-width:210mm;margin:12px auto;text-align:right}
.actions button,.actions a{padding:8px 16px;border:0;border-radius:4px;background:#2563eb;color:#fff;cursor:pointer;text-decoration:none;font-size:13px;margin-left:6px}
.actions a{background:#6b7280}
@media print{body{background:#fff}.page{margin:0;box-shadow:none;padding:0}.actions{display:none}}
</style>
</head>
<body>
<div class="actions"><a href="purchase_return.php">Back</a><button onclick="window.print()">Print</button></div>
<div class="page">
    <div class="head">
        <div>
            <?php if($show_logo){ ?><img class="logo" src="<?= purchase_return_h($company_logo_url); ?>" alt=""><?php } ?>
            <h1><?= purchase_return_h($profile['name'] ?? ''); ?></h1>
            <div class="muted"><?= nl2br(purchase_return_h($profile['address'] ?? '')); ?><br>Phone: <?= purchase_return_h($profile['phone'] ?? ''); ?><?= !empty($profile['email']) ? ' | Email: ' . purchase_return_h($profile['email']) : ''; ?></div>
        </div>
        <div class="title">
            <h2>PURCHASE RETURN</h2>
            <span class="muted">Return No</span>
            <strong><?= purchase_return_h($return['return_no']); ?></strong>
            <span class="muted">Date: <?= purchase_return_h(app_date($return['return_date'])); ?></span>
        </div>
    </div>

    <div class="boxes">
        <div class="box"><b>RETURNED TO (<?= purchase_return_h(strtoupper($supplier_word)); ?>)</b><?= purchase_return_h($return['supplier_name']); ?><?= !empty($return['supplier_phone']) ? '<br>' . purchase_return_h($return['supplier_phone']) : ''; ?><?= !empty($return['supplier_address']) ? '<br>' . nl2br(purchase_return_h($return['supplier_address'])) : ''; ?></div>
        <div class="box"><b>AGAINST PURCHASE</b><?= purchase_return_h($return['purchase_no']); ?><br><span class="muted">Purchased: <?= purchase_return_h(app_date($return['purchase_date'])); ?></span></div>
    </div>

    <table>
        <thead><tr><th>SL</th><th>Product</th><th class="r">Qty</th><th class="r">Unit Cost</th><th class="r">Total</th></tr></thead>
        <tbody>
        <?php foreach($items as $index => $item){ ?>
            <tr>
                <td><?= $index + 1; ?></td>
                <td><?= purchase_return_h(purchase_return_line_label($item)); ?><?= !empty($item['sku']) ? ' <span class="muted">[' . purchase_return_h($item['sku']) . ']</span>' : ''; ?></td>
                <td class="r"><?= (int)$item['quantity']; ?></td>
                <td class="r">BDT <?= number_format((float)$item['unit_cost'], 2); ?></td>
                <td class="r">BDT <?= number_format((float)$item['total_amount'], 2); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>

    <table class="summary">
        <tr class="grand"><td>Return Total</td><td class="r">BDT <?= number_format((float)$return['total_amount'], 2); ?></td></tr>
        <?php if((float)$return['due_adjusted'] > 0){ ?><tr><td>Deducted from due</td><td class="r">BDT <?= number_format((float)$return['due_adjusted'], 2); ?></td></tr><?php } ?>
        <?php if((float)$return['cash_refund'] > 0){ ?><tr><td>Cash refund received<?= !empty($return['wallet_name']) ? '<br><small>' . purchase_return_h($return['wallet_name']) . '</small>' : ''; ?></td><td class="r">BDT <?= number_format((float)$return['cash_refund'], 2); ?></td></tr><?php } ?>
    </table>

    <?php if(trim((string)$return['note']) !== ''){ ?><p class="note"><strong>Reason:</strong> <?= nl2br(purchase_return_h($return['note'])); ?></p><?php } ?>
    <?php if(trim((string)$return['actor_name']) !== ''){ ?><p class="note">Returned by: <?= purchase_return_h($return['actor_name']); ?></p><?php } ?>

    <div class="sign"><div><?= purchase_return_h($supplier_word); ?> Signature</div><div>Authorized Signature</div></div>
</div>
</body>
</html>
