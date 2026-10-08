<?php
require_once 'bootstrap.php';
$error = '';
$stmt = mysqli_prepare($conn, 'SELECT * FROM eshop_profiles WHERE company_id=?');
mysqli_stmt_bind_param($stmt, 'i', $company_id); mysqli_stmt_execute($stmt);
$profile = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [
    'shop_name'=>$_SESSION['user_name'] ?? '', 'logo_path'=>'', 'phone'=>'', 'email'=>'', 'address'=>'',
    'description'=>'', 'delivery_charge'=>'0.00', 'return_policy'=>''
];
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    eshop_check_csrf();
    foreach(array_keys($profile) as $key){ if($key !== 'company_id' && $key !== 'logo_path') $profile[$key] = trim((string)($_POST[$key] ?? '')); }
    $oldLogo=basename((string)($profile['logo_path']??''));
    if(($_FILES['logo']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
        $upload=$_FILES['logo']; $image=($upload['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK ? @getimagesize($upload['tmp_name']) : false;
        $types=[IMAGETYPE_JPEG=>'jpg',IMAGETYPE_PNG=>'png',IMAGETYPE_WEBP=>'webp'];
        if(!$image || !isset($types[$image[2]]) || (int)$upload['size']>2*1024*1024) $error='Upload a JPG, PNG or WEBP logo up to 2MB.';
        else { $dir=__DIR__.'/../uploads/eshop_logos'; if(!is_dir($dir) && !mkdir($dir,0775,true)) $error='Logo storage could not be created.'; else { $file='shop-'.$company_id.'-'.date('YmdHis').'-'.bin2hex(random_bytes(4)).'.'.$types[$image[2]]; if(!move_uploaded_file($upload['tmp_name'],$dir.'/'.$file)) $error='Logo upload failed.'; else $profile['logo_path']=$file; } }
    }
    if($profile['shop_name'] === '' || mb_strlen($profile['shop_name']) > 100 || strlen($profile['phone']) > 40 || strlen($profile['email']) > 150){
        $error = 'Enter a shop name (up to 100 characters) and valid contact details.';
    }elseif($profile['email'] !== '' && !filter_var($profile['email'], FILTER_VALIDATE_EMAIL)){
        $error = 'Enter a valid email address.';
    }elseif(!preg_match('/\A\d{1,10}(?:\.\d{1,2})?\z/', $profile['delivery_charge'])){
        $error = 'Enter a non-negative delivery charge with up to two decimal places.';
    }elseif(max(strlen($profile['address']), strlen($profile['description']), strlen($profile['return_policy'])) > 10000){
        $error = 'Keep each text field within 10,000 bytes.';
    }else{
        $save = mysqli_prepare($conn, 'INSERT INTO eshop_profiles (company_id,shop_name,logo_path,phone,email,address,description,delivery_charge,return_policy) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE shop_name=VALUES(shop_name),logo_path=VALUES(logo_path),phone=VALUES(phone),email=VALUES(email),address=VALUES(address),description=VALUES(description),delivery_charge=VALUES(delivery_charge),return_policy=VALUES(return_policy)');
        mysqli_stmt_bind_param($save,'issssssss',$company_id,$profile['shop_name'],$profile['logo_path'],$profile['phone'],$profile['email'],$profile['address'],$profile['description'],$profile['delivery_charge'],$profile['return_policy']);
        mysqli_stmt_execute($save);
        if(($profile['logo_path']??'')!==$oldLogo && $oldLogo!=='' && is_file(__DIR__.'/../uploads/eshop_logos/'.$oldLogo)) @unlink(__DIR__.'/../uploads/eshop_logos/'.$oldLogo);
        $_SESSION['eshop_saved'] = 'Shop settings saved.';
        header('Location: settings.php'); exit;
    }
}
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title">Shop Settings</h3><a class="float-right" href="index.php">E-shop</a></div><div class="card-body">
<?php if($error){ ?><div class="alert alert-danger" role="alert"><?= eshop_escape($error) ?></div><?php } ?>
<?php if(isset($_SESSION['eshop_saved'])){ ?><div class="alert alert-success" role="status"><?= eshop_escape($_SESSION['eshop_saved']) ?></div><?php unset($_SESSION['eshop_saved']); } ?>
<p class="text-break text-muted">Reserved URL: <?= eshop_escape(eshop_reserved_url($shop['slug'])) ?></p>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= eshop_escape($_SESSION['eshop_editor_csrf']) ?>"><div class="row">
<div class="col-12 form-group"><label for="logo">Shop logo</label><input id="logo" name="logo" class="form-control-file" type="file" accept="image/jpeg,image/png,image/webp"><small class="form-text text-muted">Optional. JPG, PNG or WEBP, maximum 2MB. A square or wide logo works best.</small><?php $logoFile=basename((string)($profile['logo_path']??'')); if($logoFile!=='' && is_file(__DIR__.'/../uploads/eshop_logos/'.$logoFile)){ ?><img class="mt-2 border rounded p-1" style="max-width:180px;max-height:90px;object-fit:contain" src="<?= eshop_escape(app_path('uploads/eshop_logos/'.rawurlencode($logoFile))) ?>" alt="Current shop logo"><?php } ?></div>
<?php foreach(['shop_name'=>'Shop name','phone'=>'Contact phone','email'=>'Contact email','delivery_charge'=>'Delivery charge (BDT)'] as $key=>$label){ ?>
<div class="col-12 col-md-6 form-group"><label for="<?= $key ?>"><?= $label ?></label><input id="<?= $key ?>" name="<?= $key ?>" class="form-control" type="<?= $key==='email'?'email':($key==='delivery_charge'?'number':'text') ?>" <?= $key==='delivery_charge'?'min="0" max="9999999999.99" step="0.01" required':($key==='shop_name'?'maxlength="100" required':'') ?> value="<?= eshop_escape($profile[$key]) ?>"></div>
<?php } ?>
<?php foreach(['address'=>'Shop address','description'=>'About your shop','return_policy'=>'Return policy'] as $key=>$label){ ?>
<div class="col-12 form-group"><label for="<?= $key ?>"><?= $label ?></label><textarea class="form-control" id="<?= $key ?>" name="<?= $key ?>" rows="3" maxlength="10000"><?= eshop_escape($profile[$key]) ?></textarea></div>
<?php } ?></div><button class="btn btn-primary">Save Settings</button></form></div></div>
<?php require_once '../includes/footer.php'; ?>
