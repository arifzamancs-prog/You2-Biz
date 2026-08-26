<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/booking_invoice_helper.php';

ensure_booking_invoice_table($conn);

$user_id = (int)$_SESSION['user_id'];
$id = (int)($_GET['id'] ?? 0);

ensure_booking_invoice_type_table($conn, $user_id);
$invoice_types = booking_invoice_types($conn, $user_id, false);

$stmt = mysqli_prepare(
    $conn,
    "SELECT bi.*,
            c.customer_name,
            c.phone,
            c.email,
            c.address,
            p.project_name,
            pk.package_name,
            w.wallet_name
     FROM booking_invoices bi
     LEFT JOIN customers c ON c.id = bi.customer_id
     LEFT JOIN projects p ON p.id = bi.project_id
     LEFT JOIN packages pk ON pk.id = bi.package_id
     LEFT JOIN wallets w ON w.id = bi.wallet_id
     WHERE bi.id=?
     AND bi.user_id=?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'ii', $id, $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$invoice = $result ? mysqli_fetch_assoc($result) : null;

if(!$invoice){
    header('Location: index.php?type=booking');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($invoice['invoice_no']); ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 36px 18px; background: #eef2f7; color: #172033; font: 14px/1.5 Arial, sans-serif; }
        .invoice-box { max-width: 900px; min-height: 680px; margin: 0 auto; background: #fff; box-shadow: 0 8px 28px rgba(20, 35, 60, .13); }
        .top-bar { height: 8px; background: #1479e8; }
        .invoice-content { padding: 42px 46px; }
        .header { display: flex; justify-content: space-between; gap: 20px; align-items: flex-start; padding-bottom: 26px; border-bottom: 2px solid #e9eef5; }
        .brand { color: #1479e8; font-size: 23px; font-weight: 800; letter-spacing: .3px; }
        .brand small { display: block; margin-top: 3px; color: #718096; font-size: 12px; font-weight: 500; }
        .title { color: #172033; font-size: 29px; font-weight: 800; margin: 0 0 6px; text-align: right; }
        .meta { color: #52627a; font-size: 13px; text-align: right; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .badge-confirmed { background: #ddf6e6; color: #177a37; } .badge-pending { background: #fff2d5; color: #9a6200; }
        .customer-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; margin: 30px 0; }
        .label { color: #7b8799; font-size: 11px; font-weight: 700; letter-spacing: .7px; text-transform: uppercase; }
        .customer-name { margin-top: 5px; font-size: 18px; font-weight: 700; }
        .contact { color: #52627a; margin-top: 3px; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: 11px 14px; background: #1479e8; color: #fff; font-size: 12px; letter-spacing: .3px; text-align: left; text-transform: uppercase; }
        td { padding: 13px 14px; border-bottom: 1px solid #e5eaf1; }
        .summary { margin-top: 24px; width: 330px; margin-left: auto; }
        .summary th { background: #f2f6fb; color: #172033; text-align: left; } .summary td { color: #1479e8; font-size: 18px; font-weight: 800; text-align: right; }
        .note { margin-top: 28px; padding: 15px 18px; border-left: 4px solid #1479e8; background: #f4f8fd; color: #45546a; }
        .footer { margin-top: 48px; padding-top: 16px; border-top: 1px solid #e5eaf1; color: #7b8799; font-size: 12px; text-align: center; }
        .print-button { border: 0; border-radius: 4px; padding: 10px 16px; background: #1479e8; color: #fff; cursor: pointer; font-weight: 700; }
        @media print { body { padding: 0; background: #fff; } .invoice-box { box-shadow: none; max-width: none; } .no-print { display:none; } }
    </style>
</head>
<body>
    <div class="invoice-box"><div class="top-bar"></div><div class="invoice-content">
        <div class="header">
            <div>
                <div class="brand">You2 Technologies<small>Professional Business Solutions</small></div>
            </div>
            <div>
                <p class="title"><?= htmlspecialchars(booking_invoice_page_title($invoice['invoice_type'], $invoice_types)); ?></p>
                <div class="meta">Invoice No: <strong><?= htmlspecialchars($invoice['invoice_no']); ?></strong></div>
                <div class="meta">Date: <strong><?= htmlspecialchars(date('d-m-Y', strtotime($invoice['invoice_date']))); ?></strong></div>
                <div class="meta"><span class="badge badge-<?= ($invoice['status'] ?? 'pending') === 'confirmed' ? 'confirmed' : 'pending'; ?>"><?= htmlspecialchars($invoice['status'] ?? 'pending'); ?></span></div>
                <p><button class="no-print print-button" onclick="window.print()">Print Invoice</button></p>
            </div>
        </div>

        <div class="customer-grid"><div><div class="label">Bill To</div><div class="customer-name"><?= htmlspecialchars($invoice['customer_name'] ?: '-'); ?></div><div class="contact"><?= htmlspecialchars($invoice['phone'] ?: '-'); ?><br><?= htmlspecialchars($invoice['email'] ?: '-'); ?><br><?= nl2br(htmlspecialchars($invoice['address'] ?: '-')); ?></div></div><div><div class="label">Invoice Details</div><div class="contact"><strong>Project:</strong> <?= htmlspecialchars($invoice['project_name'] ?: '-'); ?><br><strong>Package:</strong> <?= htmlspecialchars($invoice['package_name'] ?: '-'); ?><br><strong>Payment by:</strong> <?= htmlspecialchars($invoice['wallet_name'] ?: '-'); ?><br><strong>Type:</strong> <?= htmlspecialchars(booking_invoice_type_label($invoice['invoice_type'], $invoice_types)); ?></div></div></div>
        <table><thead><tr><th>Description</th><th style="width: 180px; text-align:right;">Amount</th></tr></thead><tbody><tr><td><?= htmlspecialchars($invoice['package_name'] ?: booking_invoice_type_label($invoice['invoice_type'], $invoice_types)); ?></td><td style="text-align:right;">BDT <?= htmlspecialchars(number_format((float)$invoice['amount'], 2)); ?></td></tr></tbody></table>

        <table class="summary">
            <tr>
                <th>Total Amount</th>
                <td>BDT <?= htmlspecialchars(number_format((float)$invoice['amount'], 2)); ?></td>
            </tr>
        </table>

        <?php if(!empty($invoice['notes'])){ ?>
            <div class="note">
                <strong>Note:</strong><br>
                <?= nl2br(htmlspecialchars($invoice['notes'])); ?>
            </div>
        <?php } ?>
        <div class="footer">This is a system-generated invoice. Thank you for your business.</div>
    </div></div>
</body>
</html>
