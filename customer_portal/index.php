<?php
session_start();

require_once '../includes/db.php';
require_once '../includes/app_config.php';
require_once '../includes/date_helper.php';
require_once '../includes/customer_portal_helper.php';
require_once '../includes/booking_invoice_helper.php';

require_customer_portal_login();
$customer = customer_portal_current_customer($conn);

if(!$customer){
    session_destroy();
    header('Location: ../login.php');
    exit;
}

$message = '';
$message_type = 'success';
$user_id = (int)$customer['user_id'];
$customer_id = (int)$customer['id'];
ensure_customer_access_table($conn);
ensure_booking_invoice_table($conn);
ensure_booking_invoice_type_table($conn, $user_id);

if(isset($_SESSION['customer_portal_flash'])){
    $flash = $_SESSION['customer_portal_flash'];
    unset($_SESSION['customer_portal_flash']);
    $message = (string)($flash['message'] ?? '');
    $message_type = (string)($flash['type'] ?? 'success');
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $address = trim((string)($_POST['address'] ?? ''));
    $new_password = (string)($_POST['new_password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');
    $photo_message = '';
    $photo = $customer['photo'] ?? '';
    $uploaded_photo = customer_portal_upload_photo($_FILES['photo'] ?? null, $photo_message);

    if($uploaded_photo === false){
        $message = $photo_message;
        $message_type = 'danger';
    }elseif($new_password !== '' && strlen($new_password) < 6){
        $message = 'Password must contain at least 6 characters.';
        $message_type = 'danger';
    }elseif($new_password !== '' && $new_password !== $confirm_password){
        $message = 'New password and confirm password do not match.';
        $message_type = 'danger';
    }else{
        if($uploaded_photo !== ''){
            $photo = $uploaded_photo;
        }

        if($new_password !== ''){
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE customers
                 SET address=?, photo=?, portal_password_changed=1
                 WHERE id=? AND user_id=? AND status='active'"
            );
            mysqli_stmt_bind_param($stmt, 'ssii', $address, $photo, $customer_id, $user_id);
        }else{
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE customers
                 SET address=?, photo=?
                 WHERE id=? AND user_id=? AND status='active'"
            );
            mysqli_stmt_bind_param($stmt, 'ssii', $address, $photo, $customer_id, $user_id);
        }

        if(mysqli_stmt_execute($stmt)){
            if($new_password !== ''){
                $access_password_stmt = mysqli_prepare(
                    $conn,
                    "UPDATE customer_access_accounts
                     SET password=?
                     WHERE customer_id=?
                     AND user_id=?"
                );
                if($access_password_stmt){
                    mysqli_stmt_bind_param($access_password_stmt, 'sii', $password_hash, $customer_id, $user_id);
                    mysqli_stmt_execute($access_password_stmt);
                }
            }
            $_SESSION['customer_portal_flash'] = [
                'message' => 'Profile updated successfully.',
                'type' => 'success',
            ];
            header('Location: index.php');
            exit;
        }else{
            $message = 'Profile update failed.';
            $message_type = 'danger';
        }
    }
}

$invoice_types = booking_invoice_types($conn, $user_id, false);
$ledger_rows = customer_portal_admin_style_ledger_rows($conn, $user_id, $customer_id, $invoice_types);
$ledger_groups = customer_portal_group_ledger_rows($ledger_rows);
$total_amount = 0;
$total_paid = 0;
$purchased_package_count = 0;
foreach($ledger_groups as $group){
    $total_amount += (float)$group['total_amount'];
    $total_paid += (float)$group['total_paid'];
    if((float)$group['total_amount'] > 0){
        $purchased_package_count++;
    }
}
$total_due = max(0, $total_amount - $total_paid);
$show_grand_summary = $purchased_package_count > 1;
$show_edit_profile = ($_SERVER['REQUEST_METHOD'] === 'POST') || ($message !== '' && $message_type === 'danger');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Customer Dashboard - You2 Biz</title>
    <link rel="stylesheet" href="../adminlte/plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../adminlte/dist/css/adminlte.min.css">
    <style>
        body{
            background:
                radial-gradient(circle at top left, rgba(20,184,166,.18), transparent 34%),
                linear-gradient(135deg, #f8fbff 0%, #eef5fb 100%);
            color:#0f172a;
        }
        .portal-shell{max-width:1220px;margin:0 auto;padding:26px;}
        .portal-navbar{
            align-items:center;
            background:rgba(255,255,255,.92);
            border:1px solid #dbe4ee;
            border-radius:18px;
            box-shadow:0 10px 28px rgba(15,23,42,.08);
            display:flex;
            justify-content:space-between;
            margin-bottom:16px;
            padding:12px 16px;
        }
        .portal-brand{
            color:#0f172a;
            font-size:18px;
            font-weight:800;
        }
        .portal-menu{align-items:center;display:flex;gap:8px;}
        .portal-menu-link{
            border-radius:999px;
            font-weight:800;
            padding:9px 14px;
        }
        .portal-hero{
            align-items:center;
            background:linear-gradient(135deg,#0f766e 0%,#0284c7 100%);
            border-radius:24px;
            box-shadow:0 18px 42px rgba(15,118,110,.18);
            color:#fff;
            display:flex;
            justify-content:space-between;
            margin-bottom:18px;
            overflow:hidden;
            padding:24px;
            position:relative;
        }
        .portal-hero:after{
            background:rgba(255,255,255,.13);
            border-radius:999px;
            content:"";
            height:220px;
            position:absolute;
            right:-80px;
            top:-100px;
            width:220px;
        }
        .portal-profile{align-items:center;display:flex;gap:18px;position:relative;z-index:1;}
        .portal-photo{
            align-items:center;
            background:rgba(255,255,255,.18);
            border:4px solid rgba(255,255,255,.68);
            border-radius:18px;
            box-shadow:0 12px 28px rgba(15,23,42,.22);
            display:inline-flex;
            font-size:34px;
            font-weight:800;
            height:104px;
            justify-content:center;
            object-fit:cover;
            width:104px;
        }
        .portal-hero h1{font-size:30px;font-weight:800;margin:0 0 6px;}
        .portal-muted{color:rgba(255,255,255,.84);font-size:14px;}
        .portal-summary{
            display:grid;
            gap:14px;
            grid-template-columns:repeat(<?php echo $show_grand_summary ? '4' : '3'; ?>,minmax(0,1fr));
            margin-bottom:18px;
        }
        .metric{
            background:#fff;
            border:1px solid #dbe4ee;
            border-radius:18px;
            box-shadow:0 10px 28px rgba(15,23,42,.07);
            padding:18px;
        }
        .metric span{
            color:#64748b;
            display:block;
            font-size:12px;
            font-weight:800;
            letter-spacing:.04em;
            text-transform:uppercase;
        }
        .metric strong{display:block;font-size:26px;font-weight:800;margin-top:8px;}
        .metric-due strong{color:#dc2626;}
        .portal-card{
            border:1px solid #dbe4ee;
            border-radius:18px;
            box-shadow:0 12px 32px rgba(15,23,42,.08);
            overflow:hidden;
        }
        .portal-card .card-header{
            background:linear-gradient(135deg,#ffffff 0%,#f1f8ff 100%);
            border-bottom:1px solid #dbe4ee;
            padding:17px 20px;
        }
        .portal-card .card-title{font-size:18px;font-weight:800;}
        .portal-form .form-control{
            border-radius:10px;
            min-height:42px;
        }
        .portal-form textarea.form-control{min-height:96px;}
        .portal-save{
            border:0;
            border-radius:12px;
            font-weight:800;
            padding:11px 16px;
        }
        .ledger-table th{
            background:#f8fafc;
            color:#334155;
            font-size:13px;
            letter-spacing:.02em;
            text-transform:uppercase;
            white-space:nowrap;
        }
        .ledger-type{
            background:#e0f2fe;
            border-radius:999px;
            color:#0369a1;
            display:inline-block;
            font-size:12px;
            font-weight:800;
            padding:5px 10px;
            text-transform:capitalize;
        }
        .ledger-debit{color:#dc2626;font-weight:800;}
        .ledger-credit{color:#047857;font-weight:800;}
        .ledger-block{margin-bottom:16px;}
        .ledger-block-info{background:#f8fafc;border-bottom:1px solid #dbe4ee;padding:15px 18px;}
        .ledger-block-info table{margin-bottom:0;}
        .ledger-block-info th{width:170px;}
        .portal-alert{border:0;border-radius:14px;box-shadow:0 8px 20px rgba(15,23,42,.08);}
        .portal-edit-panel.d-none{display:none!important;}
        @media(max-width:767.98px){
            .portal-shell{padding:12px;}
            .portal-navbar{align-items:flex-start;flex-direction:column;gap:10px;}
            .portal-menu{width:100%;}
            .portal-menu .btn{flex:1;}
            .portal-hero{align-items:flex-start;flex-direction:column;gap:16px;padding:18px;}
            .portal-profile{align-items:flex-start;}
            .portal-photo{border-radius:14px;height:82px;width:82px;}
            .portal-hero h1{font-size:24px;}
            .portal-summary{grid-template-columns:1fr;}
            .table-responsive{font-size:13px;}
        }
    </style>
</head>
<body>
<div class="portal-shell">
    <div class="portal-navbar">
        <div class="portal-brand">
            <i class="fas fa-user-circle mr-1"></i>
            Customer Dashboard
        </div>
        <div class="portal-menu">
            <a href="#edit-profile" class="btn btn-info portal-menu-link" id="edit-profile-toggle"><i class="fas fa-user-edit"></i> Edit Profile</a>
            <a href="logout.php" class="btn btn-danger portal-menu-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <div class="portal-hero">
        <div class="portal-profile">
            <?php $portal_photo_url = customer_photo_url($customer['photo'] ?? ''); ?>
            <?php if($portal_photo_url !== ''){ ?>
                <img src="<?= htmlspecialchars($portal_photo_url); ?>" class="portal-photo" alt="Customer Photo">
            <?php } else { ?>
                <span class="portal-photo">
                    <?= htmlspecialchars(strtoupper(substr(trim((string)$customer['customer_name']), 0, 1)) ?: 'C'); ?>
                </span>
            <?php } ?>
            <div>
                <h1><?= htmlspecialchars($customer['customer_name']); ?></h1>
                <div class="portal-muted">
                    CID: <?= htmlspecialchars($customer['customer_code'] ?: '-'); ?> |
                    <?= htmlspecialchars($customer['phone'] ?: '-'); ?>
                </div>
                <?php if(!empty($customer['address'])){ ?>
                    <div class="portal-muted mt-1"><?= htmlspecialchars($customer['address']); ?></div>
                <?php } ?>
            </div>
        </div>
    </div>

    <?php if((int)($customer['portal_password_changed'] ?? 0) === 0){ ?>
        <div class="alert alert-warning portal-alert">Default password is active. Please change your password.</div>
    <?php } ?>

    <?php if($message !== ''){ ?>
        <div class="alert alert-<?= htmlspecialchars($message_type); ?> portal-alert"><?= htmlspecialchars($message); ?></div>
    <?php } ?>

    <div class="portal-summary">
        <?php if($show_grand_summary){ ?>
            <div class="metric"><span>Total Package</span><strong><?= (int)$purchased_package_count; ?></strong></div>
            <div class="metric"><span>Grand Total Amount</span><strong>BDT <?= number_format($total_amount, 2); ?></strong></div>
            <div class="metric"><span>Grand Total Paid</span><strong>BDT <?= number_format($total_paid, 2); ?></strong></div>
            <div class="metric metric-due"><span>Grand Total Due</span><strong>BDT <?= number_format($total_due, 2); ?></strong></div>
        <?php } else { ?>
            <div class="metric"><span>Total Amount</span><strong>BDT <?= number_format($total_amount, 2); ?></strong></div>
            <div class="metric"><span>Total Paid</span><strong>BDT <?= number_format($total_paid, 2); ?></strong></div>
            <div class="metric metric-due"><span>Total Due</span><strong>BDT <?= number_format($total_due, 2); ?></strong></div>
        <?php } ?>
    </div>

    <div class="row">
        <div class="col-lg-4 portal-edit-panel <?= $show_edit_profile ? '' : 'd-none'; ?>">
            <div class="card portal-card" id="edit-profile">
                <div class="card-header"><h3 class="card-title">Edit Profile</h3></div>
                <div class="card-body">
                    <form method="post" action="index.php" enctype="multipart/form-data" class="portal-form">
                        <div class="form-group">
                            <label>Photo</label>
                            <input type="file" name="photo" class="form-control" accept="image/jpeg,image/png,image/webp">
                            <small class="text-muted">Max 2MB. Photo will be converted to 250x250 px.</small>
                        </div>
                        <div class="form-group">
                            <label>Address</label>
                            <textarea name="address" class="form-control" rows="3"><?= htmlspecialchars($customer['address'] ?? ''); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label>New Password</label>
                            <input type="password" name="new_password" class="form-control" autocomplete="new-password">
                        </div>
                        <div class="form-group">
                            <label>Confirm Password</label>
                            <input type="password" name="confirm_password" class="form-control" autocomplete="new-password">
                        </div>
                        <button type="submit" class="btn btn-primary btn-block portal-save"><i class="fas fa-save"></i> Update Profile</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="<?= $show_edit_profile ? 'col-lg-8' : 'col-lg-12'; ?>" id="ledger-column">
            <?php if(empty($ledger_groups)){ ?>
                <div class="card portal-card">
                    <div class="card-header"><h3 class="card-title">Ledger</h3></div>
                    <div class="card-body text-center text-muted">No ledger entries found.</div>
                </div>
            <?php } ?>

            <?php foreach($ledger_groups as $group){ ?>
                <div class="card portal-card ledger-block">
                    <div class="ledger-block-info">
                        <table class="table table-bordered">
                            <tr><th>Project Details</th><td><?= htmlspecialchars($group['project_details']); ?></td></tr>
                            <tr><th>Booking Date</th><td><?= htmlspecialchars($group['booking_date']); ?></td></tr>
                            <tr><th>Total Amount</th><td><?= number_format((float)$group['total_amount'], 2); ?></td></tr>
                            <tr><th>Total Paid</th><td><?= number_format((float)$group['total_paid'], 2); ?></td></tr>
                            <tr><th>Total Due</th><td><?= number_format((float)$group['total_due'], 2); ?></td></tr>
                        </table>
                    </div>
                    <div class="card-body table-responsive p-0">
                        <table class="table table-bordered table-striped mb-0 ledger-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Payment Type</th>
                                    <th>Note</th>
                                    <th>Payment By</th>
                                    <th class="text-right">Paid</th>
                                    <th class="text-right">Due</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($group['rows'] as $row){
                                    $row_amount = (float)$row['credit'] - (float)$row['debit'];
                                ?>
                                    <tr>
                                        <td><?= htmlspecialchars(app_date($row['trx_date'])); ?></td>
                                        <td><span class="ledger-type"><?= htmlspecialchars(($row['payment_type'] ?? '') !== '' ? $row['payment_type'] : '-'); ?></span></td>
                                        <td><?= htmlspecialchars(trim((string)($row['note'] ?? '')) !== '' ? $row['note'] : '-'); ?></td>
                                        <td><?= htmlspecialchars(($row['wallet_name'] ?? '') !== '' ? $row['wallet_name'] : '-'); ?></td>
                                        <td class="text-right <?= $row_amount < 0 ? 'ledger-debit' : 'ledger-credit'; ?>">
                                            <?= $row_amount < 0 ? '-' . number_format(abs($row_amount), 2) : number_format($row_amount, 2); ?>
                                        </td>
                                        <td class="text-right font-weight-bold"><?= number_format((float)($row['due'] ?? 0), 2); ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="4" class="text-right">Total</th>
                                    <th class="text-right"><?= number_format((float)$group['total_paid'], 2); ?></th>
                                    <th class="text-right"><?= number_format((float)$group['total_due'], 2); ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            <?php } ?>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function(){
    var toggle = document.getElementById('edit-profile-toggle');
    var panel = document.querySelector('.portal-edit-panel');
    var ledgerColumn = document.getElementById('ledger-column');
    var editCard = document.getElementById('edit-profile');

    if(!toggle || !panel || !ledgerColumn || !editCard){
        return;
    }

    toggle.addEventListener('click', function(event){
        event.preventDefault();
        panel.classList.remove('d-none');
        ledgerColumn.classList.remove('col-lg-12');
        ledgerColumn.classList.add('col-lg-8');
        editCard.scrollIntoView({behavior: 'smooth', block: 'start'});
    });
});
</script>
</body>
</html>
