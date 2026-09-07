<?php
session_start();

require_once '../includes/db.php';
require_once '../includes/date_helper.php';
require_once '../includes/customer_portal_helper.php';

require_customer_portal_login();
$customer = customer_portal_current_customer($conn);

if(!$customer){
    die('Customer Not Found');
}

$ledger = customer_portal_ledger_rows($conn, (int)$customer['user_id'], (int)$customer['id']);
$total_debit = 0;
$total_credit = 0;
foreach($ledger as $row){
    $total_debit += (float)$row['debit'];
    $total_credit += (float)$row['credit'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Ledger</title>
    <link rel="stylesheet" href="../adminlte/dist/css/adminlte.min.css">
    <style>
        body{background:#fff;color:#111827;font-size:13px;padding:24px;}
        .header{border-bottom:2px solid #111827;margin-bottom:18px;padding-bottom:12px;}
        table{width:100%;}
        @media print{.no-print{display:none;}}
    </style>
</head>
<body>
    <button class="btn btn-primary btn-sm no-print mb-3" onclick="window.print()">Print</button>
    <div class="header">
        <h3 class="mb-1">Customer Ledger</h3>
        <strong><?= htmlspecialchars($customer['customer_name']); ?></strong><br>
        CID: <?= htmlspecialchars($customer['customer_code'] ?: '-'); ?> |
        Phone: <?= htmlspecialchars($customer['phone'] ?: '-'); ?> |
        Email: <?= htmlspecialchars($customer['email'] ?: '-'); ?>
    </div>
    <table class="table table-bordered table-sm">
        <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Reference</th>
                <th class="text-right">Debit</th>
                <th class="text-right">Credit</th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($ledger)){ ?>
                <tr><td colspan="5" class="text-center">No ledger entries found.</td></tr>
            <?php } ?>
            <?php foreach($ledger as $row){ ?>
                <tr>
                    <td><?= htmlspecialchars(app_date($row['date'])); ?></td>
                    <td><?= htmlspecialchars($row['type']); ?></td>
                    <td><?= htmlspecialchars($row['reference'] ?: '-'); ?></td>
                    <td class="text-right"><?= number_format((float)$row['debit'], 2); ?></td>
                    <td class="text-right"><?= number_format((float)$row['credit'], 2); ?></td>
                </tr>
            <?php } ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="3" class="text-right">Total</th>
                <th class="text-right"><?= number_format($total_debit, 2); ?></th>
                <th class="text-right"><?= number_format($total_credit, 2); ?></th>
            </tr>
            <tr>
                <th colspan="3" class="text-right">Current Due</th>
                <th colspan="2" class="text-right"><?= number_format($total_debit - $total_credit, 2); ?></th>
            </tr>
        </tfoot>
    </table>
</body>
</html>
