<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/fifo_inventory_helper.php';

$company_id = (int)($_SESSION['user_id'] ?? 0);
$distribution_id = max(0, (int)($_GET['id'] ?? 0));
if ($company_id <= 0 || $distribution_id <= 0) {
    http_response_code(404);
    exit('Distribution not found.');
}

$seed = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM stock_distributions WHERE id={$distribution_id} AND user_id={$company_id}"));
if (!$seed) {
    http_response_code(404);
    exit('Distribution not found.');
}

$central = stock_can_manage_warehouse($conn);
$selected_branch = selected_branch_id($conn, true);
if (!$central && (int)$seed['to_branch_id'] !== $selected_branch && (int)$seed['from_branch_id'] !== $selected_branch) {
    http_response_code(403);
    exit('You do not have access to this distribution.');
}

if (trim((string)$seed['transfer_group']) !== '') {
    $group = mysqli_real_escape_string($conn, (string)$seed['transfer_group']);
    $group_where = "d.transfer_group='{$group}'";
} else {
    $group_where = 'd.id=' . (int)$seed['id'];
}

$items = mysqli_query($conn, "SELECT d.id,d.product_id,d.reference_no,d.variant_name,d.quantity,d.damaged_quantity,d.created_at,d.note,d.status,p.product_name,p.sku,fb.branch_name AS from_branch_name,fb.branch_code AS from_branch_code,fb.is_head_office AS from_head_office,tb.branch_name AS to_branch_name,tb.branch_code AS to_branch_code,tb.is_head_office AS to_head_office
    FROM stock_distributions d
    INNER JOIN products p ON p.id=d.product_id AND p.user_id=d.user_id
    INNER JOIN branches fb ON fb.id=d.from_branch_id AND fb.user_id=d.user_id
    INNER JOIN branches tb ON tb.id=d.to_branch_id AND tb.user_id=d.user_id
    WHERE d.user_id={$company_id} AND {$group_where}
    ORDER BY d.id ASC");
$item_rows = [];
while ($items && ($item = mysqli_fetch_assoc($items))) $item_rows[] = $item;
if (!$item_rows) exit('Distribution items not found.');

$distribution = $item_rows[0];
$product_groups = [];
foreach ($item_rows as $item) {
    $product_id = (int)$item['product_id'];
    if (!isset($product_groups[$product_id])) {
        $product_groups[$product_id] = ['product_name' => $item['product_name'], 'sku' => $item['sku'], 'items' => []];
    }
    $product_groups[$product_id]['items'][] = $item;
}

$company = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id={$company_id}")) ?: [];
$company_name = trim((string)($company['company_name'] ?? $company['name'] ?? '')) ?: 'Company';
$reference = trim((string)($distribution['reference_no'] ?? '')) ?: ('D-' . date('dmy', strtotime($distribution['created_at'])) . (int)$distribution['id']);
$from = ($distribution['from_head_office'] ? 'Head Office' : $distribution['from_branch_name']) . (!empty($distribution['from_branch_code']) ? ' [' . $distribution['from_branch_code'] . ']' : '');
$to = ($distribution['to_head_office'] ? 'Head Office' : $distribution['to_branch_name']) . (!empty($distribution['to_branch_code']) ? ' [' . $distribution['to_branch_code'] . ']' : '');
$total_quantity = array_sum(array_map(static fn($row) => (float)$row['quantity'], $item_rows));
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Stock Transfer Invoice <?= htmlspecialchars($reference) ?></title>
<style>
*{box-sizing:border-box} body{font-family:Arial,sans-serif;color:#1f2937;margin:0;background:#f3f4f6}.sheet{width:210mm;min-height:297mm;margin:16px auto;background:#fff;padding:18mm}.toolbar{width:210mm;margin:16px auto;text-align:right}.toolbar button{padding:8px 16px;border:0;border-radius:4px;background:#2563eb;color:#fff;cursor:pointer}.head{display:flex;justify-content:space-between;border-bottom:2px solid #1d4ed8;padding-bottom:14px;margin-bottom:18px}.head h1{font-size:22px;margin:0 0 5px}.head p{margin:3px 0;color:#4b5563}.title{text-align:center;font-size:18px;font-weight:700;margin:18px 0}.meta{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:18px}.box{border:1px solid #d1d5db;border-radius:4px;padding:10px;min-height:72px}.box strong{display:block;margin-bottom:5px}table{width:100%;border-collapse:collapse;margin-top:8px}th,td{border:1px solid #cbd5e1;padding:9px;text-align:left;font-size:13px}th{background:#eff6ff}td.qty{text-align:right}.summary{text-align:right;margin:12px 0;font-weight:700}.note{border:1px solid #d1d5db;padding:10px;min-height:48px;margin-top:18px}.signatures{display:grid;grid-template-columns:repeat(3,1fr);gap:35px;margin-top:80px}.signature{text-align:center;border-top:1px solid #374151;padding-top:7px;font-size:13px}@media print{body{background:#fff}.sheet{margin:0;width:auto;min-height:auto;padding:0}.toolbar{display:none}}
</style></head><body>
<div class="toolbar"><button onclick="window.print()">Print Invoice</button></div>
<main class="sheet"><div class="head"><div><h1><?= htmlspecialchars($company_name) ?></h1><p>Stock Transfer Delivery Invoice</p></div><div><strong>Reference: <?= htmlspecialchars($reference) ?></strong><p>Date: <?= htmlspecialchars(date('d-m-Y', strtotime($distribution['created_at']))) ?></p><p>Status: <?= htmlspecialchars(ucfirst($distribution['status'])) ?></p></div></div>
<div class="title">PRODUCT TRANSFER INVOICE</div>
<div class="meta"><div class="box"><strong>Sent From</strong><?= htmlspecialchars($from) ?></div><div class="box"><strong>Deliver To</strong><?= htmlspecialchars($to) ?></div></div>
<table><thead><tr><th style="width:7%">SL</th><th>Product</th><th>Code</th><th>Variant</th><th style="width:12%">Sent Qty</th><th style="width:14%">Received Qty</th></tr></thead>
<?php $serial = 0; foreach ($product_groups as $product) { $serial++; ?>
<tbody class="product-group">
<?php foreach ($product['items'] as $index => $item) { ?>
<tr>
<?php if ($index === 0) { ?>
    <td rowspan="<?= count($product['items']) ?>"><?= $serial ?></td>
    <td rowspan="<?= count($product['items']) ?>"><?= htmlspecialchars($product['product_name']) ?></td>
    <td rowspan="<?= count($product['items']) ?>"><?= htmlspecialchars($product['sku'] ?? '') ?></td>
<?php } ?>
    <td><?= htmlspecialchars($item['variant_name'] ?: '-') ?></td>
    <td class="qty"><?= number_format((float)$item['quantity'], 0) ?></td>
    <td class="qty">________</td>
</tr>
<?php } ?>
</tbody>
<?php } ?></table>
<style>.product-group{break-inside:avoid;page-break-inside:avoid}.product-group td[rowspan]{vertical-align:middle}</style>
<div class="summary">Total Sent Quantity: <?= number_format($total_quantity, 0) ?></div>
<div class="note"><strong>Note:</strong> <?= htmlspecialchars($distribution['note'] ?: '-') ?></div>
<div class="signatures"><div class="signature">Prepared / Dispatched By</div><div class="signature">Delivered By</div><div class="signature">Receiver Signature &amp; Seal</div></div>
</main></body></html>
