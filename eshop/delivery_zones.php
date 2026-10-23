<?php
require_once 'bootstrap.php';
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    eshop_check_csrf();
    $action=(string)($_POST['action']??'');
    $id=(int)($_POST['zone_id']??0);
    if($action==='delete'){
        $s=mysqli_prepare($conn,'DELETE FROM eshop_delivery_zones WHERE id=? AND company_id=?');
        mysqli_stmt_bind_param($s,'ii',$id,$company_id); mysqli_stmt_execute($s);
        $_SESSION['eshop_saved']='Delivery area removed.';
        header('Location: delivery_zones.php'); exit;
    }
    $name=trim((string)($_POST['zone_name']??'')); $charge=trim((string)($_POST['charge']??''));
    $time=trim((string)($_POST['delivery_time']??'')); $sort=(int)($_POST['sort_order']??0); $active=isset($_POST['active'])?1:0;
    if($name==='' || mb_strlen($name)>100) $error='Enter a delivery area name (up to 100 characters).';
    elseif(!preg_match('/\A\d{1,10}(?:\.\d{1,2})?\z/',$charge)) $error='Enter a valid delivery charge (0 for free delivery).';
    elseif(mb_strlen($time)>100) $error='Keep delivery time within 100 characters.';
    else{
        if($action==='update' && $id>0){
            $s=mysqli_prepare($conn,'UPDATE eshop_delivery_zones SET zone_name=?,charge=?,delivery_time=?,sort_order=?,active=? WHERE id=? AND company_id=?');
            mysqli_stmt_bind_param($s,'sssiiii',$name,$charge,$time,$sort,$active,$id,$company_id);
        }else{
            $s=mysqli_prepare($conn,'INSERT INTO eshop_delivery_zones(company_id,zone_name,charge,delivery_time,sort_order,active) VALUES(?,?,?,?,?,?)');
            mysqli_stmt_bind_param($s,'isssii',$company_id,$name,$charge,$time,$sort,$active);
        }
        mysqli_stmt_execute($s);
        $_SESSION['eshop_saved']='Delivery area saved.';
        header('Location: delivery_zones.php'); exit;
    }
}
$s=mysqli_prepare($conn,'SELECT * FROM eshop_delivery_zones WHERE company_id=? ORDER BY sort_order,id');
mysqli_stmt_bind_param($s,'i',$company_id); mysqli_stmt_execute($s);
$zones=mysqli_fetch_all(mysqli_stmt_get_result($s),MYSQLI_ASSOC);
$s=mysqli_prepare($conn,'SELECT charge_type_id FROM eshop_checkout_settings WHERE company_id=?');
mysqli_stmt_bind_param($s,'i',$company_id); mysqli_stmt_execute($s);
$chargeTypeReady=(int)(mysqli_fetch_assoc(mysqli_stmt_get_result($s))['charge_type_id']??0)>0;
$paidZones=array_filter($zones,function($z){ return (int)$z['active']===1 && (float)$z['charge']>0; });
$csrf='<input type="hidden" name="csrf" value="'.eshop_escape($_SESSION['eshop_editor_csrf']).'">';
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-truck mr-1"></i>Delivery Areas</h3><a class="float-right" href="index.php">E-shop</a></div><div class="card-body">
<?php if($error){ ?><div class="alert alert-danger" role="alert"><?= eshop_escape($error) ?></div><?php } ?>
<?php if(isset($_SESSION['eshop_saved'])){ ?><div class="alert alert-success" role="status"><?= eshop_escape($_SESSION['eshop_saved']) ?></div><?php unset($_SESSION['eshop_saved']); } ?>
<p class="text-muted">Customers choose one of these areas at checkout, and its charge is added to the order. Without any active area, the single delivery charge from Shop Settings is used.</p>
<?php if($paidZones && !$chargeTypeReady){ ?><div class="alert alert-warning">Select the delivery invoice charge type in <a href="checkout.php">Checkout Settings</a>, otherwise orders with a delivery charge cannot be placed.</div><?php } ?>
<div class="table-responsive"><table class="table table-bordered table-sm">
<thead><tr><th>Area name</th><th style="width:140px">Charge (BDT)</th><th>Delivery time</th><th style="width:90px">Order</th><th style="width:80px">Active</th><th style="width:170px"></th></tr></thead>
<tbody>
<?php foreach($zones as $zone){ $form='zone-'.(int)$zone['id']; ?>
<tr>
    <td><input form="<?= $form ?>" name="zone_name" class="form-control form-control-sm" maxlength="100" required value="<?= eshop_escape($zone['zone_name']) ?>"></td>
    <td><input form="<?= $form ?>" name="charge" type="number" min="0" step="0.01" class="form-control form-control-sm" required value="<?= eshop_escape($zone['charge']) ?>"></td>
    <td><input form="<?= $form ?>" name="delivery_time" class="form-control form-control-sm" maxlength="100" value="<?= eshop_escape($zone['delivery_time']) ?>" placeholder="1-2 working days"></td>
    <td><input form="<?= $form ?>" name="sort_order" type="number" class="form-control form-control-sm" value="<?= (int)$zone['sort_order'] ?>"></td>
    <td class="text-center"><input form="<?= $form ?>" name="active" type="checkbox" value="1" <?= (int)$zone['active']?'checked':'' ?> aria-label="Active"></td>
    <td>
        <form id="<?= $form ?>" method="post" class="d-inline"><?= $csrf ?><input type="hidden" name="action" value="update"><input type="hidden" name="zone_id" value="<?= (int)$zone['id'] ?>"><button class="btn btn-primary btn-sm">Save</button></form>
        <form method="post" class="d-inline" onsubmit="return confirm('Remove this delivery area?');"><?= $csrf ?><input type="hidden" name="action" value="delete"><input type="hidden" name="zone_id" value="<?= (int)$zone['id'] ?>"><button class="btn btn-outline-danger btn-sm">Delete</button></form>
    </td>
</tr>
<?php } ?>
<tr class="table-light">
    <td><input form="zone-new" name="zone_name" class="form-control form-control-sm" maxlength="100" required placeholder="e.g. Inside Dhaka"></td>
    <td><input form="zone-new" name="charge" type="number" min="0" step="0.01" class="form-control form-control-sm" required placeholder="60"></td>
    <td><input form="zone-new" name="delivery_time" class="form-control form-control-sm" maxlength="100" placeholder="1-2 working days"></td>
    <td><input form="zone-new" name="sort_order" type="number" class="form-control form-control-sm" value="<?= count($zones)+1 ?>"></td>
    <td class="text-center"><input form="zone-new" name="active" type="checkbox" value="1" checked aria-label="Active"></td>
    <td><form id="zone-new" method="post"><?= $csrf ?><input type="hidden" name="action" value="create"><button class="btn btn-success btn-sm"><i class="fas fa-plus mr-1"></i>Add Area</button></form></td>
</tr>
</tbody></table></div>
</div></div>
<?php require_once '../includes/footer.php'; ?>
