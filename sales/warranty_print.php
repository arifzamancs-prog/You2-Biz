<?php
// Warranty receipt: customer copy and office copy on one page.
require_once __DIR__ . '/_sw_init.php';

$claim = sw_warranty_get($conn, $user_id, (int)($_GET['id'] ?? 0));
if (!$claim || !sw_branch_allows($conn, $claim['branch_id'])) {
    header('Location: warranty_claims.php');
    exit;
}
$company = sw_company_info($conn, $user_id, (int)$claim['branch_id']);
$statuses = sw_warranty_statuses();
$copy = static function ($title) use ($claim, $company, $statuses) {
    ob_start(); ?>
    <div class="receipt">
        <div class="head">
            <div><div class="company"><?= sw_h($company['name'] ?? ''); ?></div>
                <div class="muted"><?= sw_h(implode(' · ', array_filter([$company['branch'] ?? '', $company['address'] ?? '', $company['phone'] ?? '']))); ?></div></div>
            <div class="right"><div class="title">WARRANTY RECEIPT</div><div class="copy"><?= sw_h($title); ?></div></div>
        </div>
        <table class="meta">
            <tr><th>Claim No</th><td><strong><?= sw_h($claim['claim_no']); ?></strong></td><th>Received</th><td><?= sw_datetime($claim['received_at']); ?></td></tr>
            <tr><th>Customer</th><td><?= sw_h($claim['customer_name']); ?></td><th>Expected</th><td><strong><?= sw_date($claim['expected_date']); ?></strong></td></tr>
            <tr><th>Phone</th><td><?= sw_h($claim['customer_phone'] ?: '—'); ?></td><th>Status</th><td><?= sw_h($statuses[$claim['status']] ?? $claim['status']); ?></td></tr>
        </table>
        <table class="items">
            <tr><th style="width:24%">Product</th><td><strong><?= sw_h($claim['product_name']); ?></strong><?= $claim['variant_name'] !== '' ? ' (' . sw_h($claim['variant_name']) . ')' : ''; ?><?= $claim['serial_no'] !== '' ? '<br><span class="muted">Serial/IMEI: ' . sw_h($claim['serial_no']) . '</span>' : ''; ?></td></tr>
            <tr><th>Sold on</th><td><?= sw_h($claim['invoice_no']); ?> · <?= sw_date($claim['sale_date']); ?>
                <?php if ((int)$claim['warranty_months'] > 0) { ?> · Warranty <?= (int)$claim['warranty_months']; ?> months, until <?= sw_date($claim['warranty_until']); ?><?php } ?>
                · <strong><?= (int)$claim['in_warranty'] ? 'In warranty' : 'Out of warranty'; ?></strong></td></tr>
            <tr><th>Problem</th><td><?= nl2br(sw_h($claim['problem'])); ?></td></tr>
            <?php if ($claim['accessories'] !== '') { ?><tr><th>Received with</th><td><?= sw_h($claim['accessories']); ?></td></tr><?php } ?>
            <?php if (trim((string)$claim['note']) !== '') { ?><tr><th>Note</th><td><?= sw_h($claim['note']); ?></td></tr><?php } ?>
        </table>
        <p class="terms">The product is received for warranty service as per the terms of the manufacturer/supplier. Physical damage, burn or liquid damage is not covered. Please bring this receipt when collecting the product.</p>
        <div class="sign"><span>Customer signature</span><span>Received by: <?= sw_h($claim['created_by_name']); ?></span></div>
    </div>
    <?php return ob_get_clean();
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= sw_h($claim['claim_no']); ?> - Warranty Receipt</title>
    <link rel="stylesheet" href="../adminlte/plugins/fontawesome-free/css/all.min.css">
    <style>
        body{font-family:Arial,Helvetica,sans-serif;color:#222;background:#f1f3f6;margin:0}
        .toolbar{max-width:800px;margin:16px auto 0;display:flex;justify-content:space-between;gap:8px}
        .toolbar a,.toolbar button{font:inherit;font-size:14px;padding:7px 14px;border-radius:6px;border:1px solid #ced4da;background:#fff;color:#333;text-decoration:none;cursor:pointer}
        .toolbar button{background:#1f6feb;border-color:#1f6feb;color:#fff}
        .sheet{max-width:800px;margin:12px auto 24px;background:#fff;padding:18px 24px;box-shadow:0 2px 10px rgba(0,0,0,.08)}
        .receipt{padding:6px 0 10px}
        .cut{border-top:1px dashed #999;margin:14px 0;position:relative}
        .cut span{position:absolute;top:-9px;left:50%;transform:translateX(-50%);background:#fff;padding:0 8px;font-size:11px;color:#888}
        .head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #222;padding-bottom:6px;margin-bottom:8px}
        .company{font-size:18px;font-weight:700}.muted{color:#666;font-size:12px}
        .right{text-align:right}.title{font-weight:700;font-size:15px;letter-spacing:.04em}.copy{font-size:11px;color:#666;text-transform:uppercase}
        table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:8px}
        .meta th{text-align:left;color:#555;font-weight:600;width:15%;padding:3px 4px}
        .meta td{padding:3px 4px}
        .items th,.items td{border:1px solid #ccc;padding:5px 7px;text-align:left;vertical-align:top}
        .items th{background:#f5f5f5;font-weight:600}
        .terms{font-size:11px;color:#555;margin:6px 0 18px}
        .sign{display:flex;justify-content:space-between;font-size:12px}
        .sign span:first-child{border-top:1px solid #555;padding-top:3px;min-width:160px}
        @media print{body{background:#fff}.toolbar{display:none}.sheet{box-shadow:none;margin:0;max-width:none;padding:0 6mm}}
    </style>
</head>
<body>
<div class="toolbar">
    <a href="warranty_view.php?id=<?= (int)$claim['id']; ?>"><i class="fas fa-arrow-left"></i> Claim details</a>
    <button type="button" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
</div>
<div class="sheet">
    <?= $copy('Customer copy'); ?>
    <div class="cut"><span>✂ cut here</span></div>
    <?= $copy('Office copy'); ?>
</div>
<?php if (!empty($_GET['auto'])) { ?><script>window.addEventListener('load', function () { window.print(); });</script><?php } ?>
</body>
</html>
