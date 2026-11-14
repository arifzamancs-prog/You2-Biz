<?php
// Service receipt: customer copy and office copy on one page.
require_once __DIR__ . '/_sw_init.php';

$job = sw_service_get($conn, $user_id, (int)($_GET['id'] ?? 0));
if (!$job || !sw_branch_allows($conn, $job['branch_id'])) {
    header('Location: service_jobs.php');
    exit;
}
$company = sw_company_info($conn, $user_id, (int)$job['branch_id']);
$statuses = sw_service_statuses();
$copy = static function ($title) use ($job, $company, $statuses) {
    ob_start(); ?>
    <div class="receipt">
        <div class="head">
            <div><div class="company"><?= sw_h($company['name'] ?? ''); ?></div>
                <div class="muted"><?= sw_h(implode(' · ', array_filter([$company['branch'] ?? '', $company['address'] ?? '', $company['phone'] ?? '']))); ?></div></div>
            <div class="right"><div class="title">SERVICE RECEIPT</div><div class="copy"><?= sw_h($title); ?></div></div>
        </div>
        <table class="meta">
            <tr><th>Job No</th><td><strong><?= sw_h($job['job_no']); ?></strong></td><th>Received</th><td><?= sw_datetime($job['received_at']); ?></td></tr>
            <tr><th>Customer</th><td><?= sw_h($job['customer_name']); ?><?= (int)$job['customer_id'] === 0 ? ' <span class="muted">(Walk-in)</span>' : ''; ?></td><th>Delivery date</th><td><strong><?= sw_date($job['delivery_date']); ?></strong></td></tr>
            <tr><th>Phone</th><td><?= sw_h($job['customer_phone'] ?: '—'); ?></td><th>Status</th><td><?= sw_h($statuses[$job['status']] ?? $job['status']); ?></td></tr>
        </table>
        <table class="items">
            <tr><th style="width:24%">Product</th><td><strong><?= sw_h($job['item_name']); ?></strong><?= $job['brand_model'] !== '' ? ' · ' . sw_h($job['brand_model']) : ''; ?><?= $job['serial_no'] !== '' ? '<br><span class="muted">Serial/IMEI: ' . sw_h($job['serial_no']) . '</span>' : ''; ?></td></tr>
            <tr><th>Problem</th><td><?= nl2br(sw_h($job['problem'])); ?></td></tr>
            <?php if ($job['accessories'] !== '') { ?><tr><th>Received with</th><td><?= sw_h($job['accessories']); ?></td></tr><?php } ?>
            <?php if (trim((string)$job['note']) !== '') { ?><tr><th>Note</th><td><?= nl2br(sw_h($job['note'])); ?></td></tr><?php } ?>
            <?php if (!empty($job['staff_name'])) { ?><tr><th>Technician</th><td><?= sw_h($job['staff_name']); ?></td></tr><?php } ?>
        </table>
        <table class="money">
            <tr><th>Estimated charge</th><td>BDT <?= sw_amount($job['estimated_charge']); ?></td>
                <th>Advance paid</th><td>BDT <?= sw_amount($job['advance_amount']); ?></td></tr>
        </table>
        <p class="terms">The final charge (service charge and parts used) is billed on delivery; the advance is deducted from it. Please bring this receipt when collecting the product.</p>
        <div class="sign"><span>Customer signature</span><span>Received by: <?= sw_h($job['created_by_name']); ?></span></div>
    </div>
    <?php return ob_get_clean();
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= sw_h($job['job_no']); ?> - Service Receipt</title>
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
        .meta th,.money th{text-align:left;color:#555;font-weight:600;width:15%;padding:3px 4px}
        .meta td,.money td{padding:3px 4px}
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
    <a href="service_view.php?id=<?= (int)$job['id']; ?>"><i class="fas fa-arrow-left"></i> Job details</a>
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
