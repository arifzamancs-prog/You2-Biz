<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/booking_invoice_helper.php';

ensure_booking_invoice_table($conn);

$user_id = (int)$_SESSION['user_id'];
$id = (int)($_GET['id'] ?? 0);

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
        body { font-family: Arial, sans-serif; margin: 24px; color: #1f2937; }
        .invoice-box { max-width: 900px; margin: 0 auto; border: 1px solid #dbe2ea; padding: 24px; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; }
        .title { font-size: 28px; font-weight: 700; margin: 0 0 6px; }
        .meta, .details td { font-size: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #dbe2ea; padding: 10px 12px; text-align: left; }
        th { background: #f8fafc; }
        .summary { margin-top: 20px; width: 320px; margin-left: auto; }
        .summary td { font-weight: 600; }
        .note { margin-top: 20px; padding: 12px; background: #f8fafc; border: 1px solid #dbe2ea; }
        @media print { .no-print { display:none; } body { margin: 0; } .invoice-box { border: none; } }
    </style>
</head>
<body>
    <div class="invoice-box">
        <div class="header">
            <div>
                <p class="title"><?= htmlspecialchars(booking_invoice_page_title($invoice['invoice_type'])); ?></p>
                <div class="meta">Invoice No: <strong><?= htmlspecialchars($invoice['invoice_no']); ?></strong></div>
                <div class="meta">Date: <strong><?= htmlspecialchars(date('d-m-Y', strtotime($invoice['invoice_date']))); ?></strong></div>
            </div>
            <div>
                <button class="no-print" onclick="window.print()">Print</button>
            </div>
        </div>

        <table class="details">
            <tr>
                <th width="30%">Customer</th>
                <td><?= htmlspecialchars($invoice['customer_name'] ?: '-'); ?></td>
            </tr>
            <tr>
                <th>Phone</th>
                <td><?= htmlspecialchars($invoice['phone'] ?: '-'); ?></td>
            </tr>
            <tr>
                <th>Email</th>
                <td><?= htmlspecialchars($invoice['email'] ?: '-'); ?></td>
            </tr>
            <tr>
                <th>Address</th>
                <td><?= htmlspecialchars($invoice['address'] ?: '-'); ?></td>
            </tr>
            <tr>
                <th>Project</th>
                <td><?= htmlspecialchars($invoice['project_name'] ?: '-'); ?></td>
            </tr>
            <tr>
                <th>Package</th>
                <td><?= htmlspecialchars($invoice['package_name'] ?: '-'); ?></td>
            </tr>
            <tr>
                <th>Wallet</th>
                <td><?= htmlspecialchars($invoice['wallet_name'] ?: '-'); ?></td>
            </tr>
            <tr>
                <th>Invoice Type</th>
                <td><?= htmlspecialchars(booking_invoice_type_label($invoice['invoice_type'])); ?></td>
            </tr>
        </table>

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
    </div>
</body>
</html>
