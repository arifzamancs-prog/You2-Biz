<?php
require_once 'bootstrap.php';
require_once '../includes/eshop_order_helper.php';
eshop_order_schema($conn);
$message='';
$profileStmt=mysqli_prepare($conn,'SELECT delivery_charge FROM eshop_profiles WHERE company_id=?'); mysqli_stmt_bind_param($profileStmt,'i',$company_id); mysqli_stmt_execute($profileStmt);
$deliveryCharge=(float)(mysqli_fetch_assoc(mysqli_stmt_get_result($profileStmt))['delivery_charge']??0);
try{ $centralBranch=eshop_central_branch_id($conn,$company_id); }catch(RuntimeException $e){ $centralBranch=0; $message='Central Head Office could not be prepared.'; }
if($_SERVER['REQUEST_METHOD']==='POST'){
    eshop_check_csrf(); $branch=$centralBranch; $charge=(int)($_POST['charge_type_id']??0); $verification=(string)($_POST['customer_verification_method']??'sms');
    if(!in_array($verification,['sms','email'],true)) $verification='sms';
    if($branch>0){
        $valid=true;
        if($charge){ $s=mysqli_prepare($conn,"SELECT id FROM invoice_charge_types WHERE id=? AND user_id=? AND charge_type='add' AND charge_value_type='fixed' AND status='active'"); mysqli_stmt_bind_param($s,'ii',$charge,$company_id); mysqli_stmt_execute($s); $valid=(bool)mysqli_fetch_assoc(mysqli_stmt_get_result($s)); }
        if($valid && $deliveryCharge>0 && !$charge){ $message='Select the invoice charge type for the configured delivery charge.'; }
        elseif($valid){ $s=mysqli_prepare($conn,'INSERT INTO eshop_checkout_settings(company_id,branch_id,charge_type_id,customer_verification_method) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE branch_id=VALUES(branch_id),charge_type_id=VALUES(charge_type_id),customer_verification_method=VALUES(customer_verification_method)'); mysqli_stmt_bind_param($s,'iiis',$company_id,$branch,$charge,$verification); mysqli_stmt_execute($s); $message='Checkout settings saved.'; }
        else $message='Select a valid delivery charge type.';
    }else $message='Central Head Office is not available.';
}
$s=mysqli_prepare($conn,'SELECT * FROM eshop_checkout_settings WHERE company_id=?'); mysqli_stmt_bind_param($s,'i',$company_id); mysqli_stmt_execute($s); $config=mysqli_fetch_assoc(mysqli_stmt_get_result($s))?:[];
$charges=mysqli_query($conn,"SELECT id,charge_name FROM invoice_charge_types WHERE user_id=$company_id AND status='active' AND charge_type='add' AND charge_value_type='fixed'");
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header">Checkout Settings</div><div class="card-body">
<?php if($message){ ?><div class="alert <?= $message==='Checkout settings saved.'?'alert-success':'alert-danger' ?>" role="status"><?= eshop_escape($message) ?></div><?php } ?>
<p>E-shop orders are centrally controlled. Orders create Pending Sales Invoices and reserve central Head Office stock. They are not routed through the Multi Branch feature. Payment is cash on delivery.</p>
<div class="alert <?= $deliveryCharge>0?'alert-info':'alert-light' ?>">Storefront delivery charge: <strong>BDT <?= number_format($deliveryCharge,2) ?></strong><?php if($deliveryCharge>0){ ?>. Select its matching fixed add-charge below so Sales invoices match the checkout total.<?php } ?></div>
<form method="post"><input type="hidden" name="csrf" value="<?= eshop_escape($_SESSION['eshop_editor_csrf']) ?>">
<div class="alert alert-light border"><strong>Order source:</strong> Central Head Office stock. This is fixed for E-shop and does not depend on Multi Branch.</div>
<label for="charge">Delivery invoice charge type</label><select id="charge" name="charge_type_id" class="form-control mb-3" <?= $deliveryCharge>0?'required':'' ?>><option value="0">No delivery charge</option><?php while($c=mysqli_fetch_assoc($charges)){ ?><option value="<?= $c['id'] ?>" <?= ($config['charge_type_id']??0)==$c['id']?'selected':'' ?>><?= eshop_escape($c['charge_name']) ?></option><?php } ?></select><p class="text-muted">If delivery charge in Shop Settings is greater than zero, select its matching invoice charge type here.</p>
<label for="verification">Customer verification</label><select id="verification" name="customer_verification_method" class="form-control mb-2"><option value="sms" <?= ($config['customer_verification_method']??'sms')==='sms'?'selected':'' ?>>SMS OTP</option><option value="email" <?= ($config['customer_verification_method']??'sms')==='email'?'selected':'' ?>>Email OTP</option></select><p class="text-muted">Customers verify this contact before placing an order and are then automatically signed in to this shop. SMS uses the system Bulk SMS gateway; email uses the system SMTP settings.</p><button class="btn btn-primary">Save</button> <a href="index.php">Back</a></form></div></div>
<?php require_once '../includes/footer.php'; ?>
