<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/booking_invoice_helper.php';
require_once '../includes/printing_helper.php';

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
            w.wallet_name,
            creator.name AS created_by_name
     FROM booking_invoices bi
     LEFT JOIN customers c ON c.id = bi.customer_id AND c.user_id = bi.user_id
     LEFT JOIN projects p ON p.id = bi.project_id AND p.user_id = bi.user_id
     LEFT JOIN packages pk ON pk.id = bi.package_id AND pk.user_id = bi.user_id
     LEFT JOIN wallets w ON w.id = bi.wallet_id AND w.user_id = bi.user_id
     LEFT JOIN users creator ON creator.id = bi.created_by_user_id
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
$charge_stmt=mysqli_prepare($conn,'SELECT charge_name,charge_type,charge_amount FROM booking_invoice_charges WHERE booking_invoice_id=? ORDER BY id'); mysqli_stmt_bind_param($charge_stmt,'i',$id); mysqli_stmt_execute($charge_stmt); $charge_result=mysqli_stmt_get_result($charge_stmt); $booking_charges=[]; $base_amount=(float)$invoice['amount']; while($charge=mysqli_fetch_assoc($charge_result)){ $booking_charges[]=$charge; $base_amount += $charge['charge_type']==='less' ? (float)$charge['charge_amount'] : -(float)$charge['charge_amount']; }

$printing_option = current_printing_option($conn);
$custom_size = current_printing_custom_size($conn);
$custom_top_margin = current_printing_custom_top_margin($conn);
$print_top_margin = $printing_option === 'custom'
    ? $custom_top_margin
    : (should_use_general_top_margin($conn) ? current_printing_general_top_margin($conn) : 0);
$company_profile = printing_company_profile_data($conn);
$show_company_profile = should_print_company_profile($conn);
$company_logo_url = should_print_company_logo($conn) ? current_print_company_logo_url($conn) : '';
$has_print_logo = $company_logo_url !== '';
$company_seal_url = should_print_company_seal($conn) ? current_company_seal_url($conn) : '';
$paid_seal_url = should_print_paid_seal($conn) && ($invoice['status'] ?? 'pending') === 'confirmed' ? current_paid_seal_url($conn) : '';
$show_notes = should_print_invoice_notes($conn);
$show_created_by = should_print_invoice_created_by($conn);
$created_by_name = trim((string)($invoice['created_by_name'] ?? '')) ?: trim((string)($company_profile['name'] ?? 'System'));
$page_size = $printing_option === 'pos' ? '80mm auto' : ($printing_option === 'custom' ? number_format((float)$custom_size['width'], 2, '.', '') . 'in ' . number_format((float)$custom_size['height'], 2, '.', '') . 'in' : 'A4');
$invoice_width = $printing_option === 'pos' ? '80mm' : ($printing_option === 'custom' ? number_format((float)$custom_size['width'], 2, '.', '') . 'in' : '900px');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($invoice['invoice_no']); ?></title>
    <style>
        * { box-sizing: border-box; }
        @page { size: <?= $page_size ?>; margin: 0; }
        body { margin: 0; padding: 36px 18px; background: #eef2f7; color: #172033; font: 14px/1.5 Arial, sans-serif; }
        .invoice-box { width: <?= $invoice_width ?>; max-width: calc(100% - 36px); min-height: 680px; margin: 0 auto; background: #fff; box-shadow: 0 8px 28px rgba(20, 35, 60, .13); }
        .top-bar { height: 8px; background: #1479e8; }
        .invoice-content { padding: 42px 46px; padding-top: calc(42px + <?= number_format((float)$print_top_margin, 2, '.', '') ?>in); }
        .header { display: flex; justify-content: space-between; gap: 20px; align-items: flex-start; padding-bottom: 26px; border-bottom: 2px solid #e9eef5; }
        .company-head { display:block; max-width:55%; } .brand-row { display:flex; align-items:center; gap:14px; } .company-logo { display:block; flex:0 0 auto; width:48px; height:48px; margin:0; object-fit:contain; }
        .brand { color: #1479e8; font-size: 27px; font-weight: 800; letter-spacing: .2px; }
        .company-contact { margin-top:4px; color:#52627a; font-size:15px; font-weight:500; } .company-contact .address-phone { white-space:normal; } .phone-separator { display:none; } .company-phone { display:block; }
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
        .total-row { position:relative; min-height:74px; margin-top:24px; } .summary { width:330px; margin:0 0 0 auto; } .paid-total-seal { position:absolute; left:50%; top:50%; transform:translate(-50%, -50%); } .paid-total-seal img { display:block; max-width:115px; max-height:76px; object-fit:contain; }
        .summary th { background: #f2f6fb; color: #172033; text-align: left; } .summary td { color: #1479e8; font-size: 18px; font-weight: 800; text-align: right; }
        .note { margin-top: 28px; padding: 15px 18px; border-left: 4px solid #1479e8; background: #f4f8fd; color: #45546a; }
        .approval-row { position:relative; display:block; margin-top:44px; min-height:96px; } .seal-block { position:absolute; text-align:center; min-width:130px; } .seal-block img { display:block; margin:0 auto; max-width:115px; max-height:76px; object-fit:contain; } .company-seal-block { right:0; bottom:0; }
        .created-by { margin-top:18px; color:#65748a; font-size:12px; }
        .footer { margin-top: 48px; padding-top: 16px; border-top: 1px solid #e5eaf1; color: #7b8799; font-size: 12px; text-align: center; }
        .print-button { border: 0; border-radius: 4px; padding: 10px 16px; background: #1479e8; color: #fff; cursor: pointer; font-weight: 700; }
        <?php if($printing_option === 'pos'){ ?>
        body { font-size:11px; } .invoice-content { padding:18px 14px; padding-top:calc(18px + <?= number_format((float)$print_top_margin, 2, '.', '') ?>in); } .header { gap:8px; padding-bottom:14px; } .company-head { max-width:52%; } .brand-row { gap:7px; } .company-logo { width:34px; height:34px; } .brand { font-size:14px; } .company-contact { margin-top:6px; font-size:8px; } .title { font-size:17px; } .meta { font-size:9px; } .customer-grid { grid-template-columns:1fr; gap:12px; margin:16px 0; } th,td { padding:7px 6px; font-size:9px; } .total-row { min-height:56px; margin-top:14px; } .summary { width:100%; } .paid-total-seal img { max-width:78px; max-height:48px; } .approval-row { margin-top:22px; min-height:72px; } .seal-block { min-width:90px; } .seal-block img { max-width:78px; max-height:48px; } .footer { margin-top:20px; font-size:9px; }
        <?php } ?>
        @media print { body { padding: 0; background: #fff; } .invoice-box { box-shadow: none; max-width: none; } .no-print { display:none; } }
    </style>
</head>
<body>
    <div class="invoice-box"><div class="top-bar"></div><div class="invoice-content">
        <div class="header">
            <div class="company-head<?= $has_print_logo ? ' has-logo' : ''; ?>">
                <div class="brand-row">
                    <?php if($company_logo_url !== ''){ ?><img class="company-logo" src="<?= htmlspecialchars($company_logo_url); ?>" alt="Company logo"><?php } ?>
                    <?php if($show_company_profile){ ?><div class="brand"><?= htmlspecialchars($company_profile['name'] ?: 'Company'); ?></div><?php } ?>
                </div>
                <?php if($show_company_profile){ ?><div class="company-contact"><div class="address-phone"><?= !empty($company_profile['address']) && $company_profile['address'] !== 'None' ? htmlspecialchars($company_profile['address']) : ''; ?><?= !empty($company_profile['address']) && $company_profile['address'] !== 'None' && !empty($company_profile['phone']) && $company_profile['phone'] !== 'None' ? '<span class="phone-separator"> · </span>' : ''; ?><?= !empty($company_profile['phone']) && $company_profile['phone'] !== 'None' ? '<span class="company-phone">Phone: ' . htmlspecialchars($company_profile['phone']) . '</span>' : ''; ?></div><?= !empty($company_profile['email']) && $company_profile['email'] !== 'None' ? '<div>Email: ' . htmlspecialchars($company_profile['email']) . '</div>' : ''; ?></div><?php } ?>
            </div>
            <div>
                <p class="title"><?= htmlspecialchars(booking_invoice_page_title($invoice['invoice_type'], $invoice_types)); ?></p>
                <div class="meta">Invoice No: <strong><?= htmlspecialchars($invoice['invoice_no']); ?></strong></div>
                <div class="meta">Date: <strong><?= htmlspecialchars(date('d-m-Y', strtotime($invoice['invoice_date']))); ?></strong></div>
                <div class="meta"><span class="badge badge-<?= ($invoice['status'] ?? 'pending') === 'confirmed' ? 'confirmed' : 'pending'; ?>"><?= htmlspecialchars($invoice['status'] ?? 'pending'); ?></span></div>
                <p><button class="no-print print-button" onclick="window.print()">Print Invoice</button></p>
            </div>
        </div>

        <div class="customer-grid"><div><div class="label">Bill To</div><div class="customer-name"><?= htmlspecialchars($invoice['customer_name'] ?: ('Missing Customer #' . (int)$invoice['customer_id'])); ?></div><div class="contact"><strong>Phone:</strong> <?= htmlspecialchars($invoice['phone'] ?: '-'); ?><br><strong>Address:</strong> <?= nl2br(htmlspecialchars($invoice['address'] ?: '-')); ?></div></div><div><div class="label">Invoice Details</div><div class="contact"><strong>Project:</strong> <?= htmlspecialchars($invoice['project_name'] ?: ('Missing Project #' . (int)$invoice['project_id'])); ?><br><strong>Package:</strong> <?= htmlspecialchars($invoice['package_name'] ?: ('Missing Package #' . (int)$invoice['package_id'])); ?><br><strong>Payment by:</strong> <?= htmlspecialchars($invoice['wallet_name'] ?: ('Missing Wallet #' . (int)$invoice['wallet_id'])); ?></div></div></div>
        <table><thead><tr><th>Description</th><th style="width: 180px; text-align:right;">Amount</th></tr></thead><tbody><tr><td><?= htmlspecialchars($invoice['package_name'] ?: ('Missing Package #' . (int)$invoice['package_id'])); ?></td><td style="text-align:right;">BDT <?=number_format($base_amount,2)?></td></tr><?php foreach($booking_charges as $charge){ ?><tr><td><?=htmlspecialchars($charge['charge_name'])?> (<?= $charge['charge_type']==='less'?'Less':'Add' ?>)</td><td style="text-align:right;"><?= $charge['charge_type']==='less'?'- ':'+ ' ?>BDT <?=number_format((float)$charge['charge_amount'],2)?></td></tr><?php } ?></tbody></table>

        <div class="total-row">
            <?php if($paid_seal_url !== ''){ ?><div class="paid-total-seal"><img src="<?= htmlspecialchars($paid_seal_url); ?>" alt="Paid seal"></div><?php } ?>
            <table class="summary">
                <tr>
                    <th>Total Amount</th>
                    <td>BDT <?= htmlspecialchars(number_format((float)$invoice['amount'], 2)); ?></td>
                </tr>
            </table>
        </div>

        <?php if($show_notes && !empty($invoice['notes'])){ ?>
            <div class="note">
                <strong>Note:</strong><br>
                <?= nl2br(htmlspecialchars($invoice['notes'])); ?>
            </div>
        <?php } ?>
        <?php if($company_seal_url !== ''){ ?><div class="approval-row">
            <?php if($company_seal_url !== ''){ ?><div class="seal-block company-seal-block"><img src="<?= htmlspecialchars($company_seal_url); ?>" alt="Company seal"></div><?php } ?>
        </div><?php } ?>
        <?php if($show_created_by){ ?><div class="created-by">Created by: <strong><?= htmlspecialchars($created_by_name); ?></strong></div><?php } ?>
        <div class="footer">This is a system-generated invoice. Thank you for your business.</div>
    </div></div>
</body>
</html>
