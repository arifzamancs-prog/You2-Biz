<?php
require_once 'bootstrap.php';
$error='';
$bannerDir=__DIR__.'/../uploads/eshop_banners';
$maxBanners=8;
$s=mysqli_prepare($conn,'SELECT * FROM eshop_banners WHERE company_id=? ORDER BY sort_order,id');
mysqli_stmt_bind_param($s,'i',$company_id); mysqli_stmt_execute($s);
$banners=mysqli_fetch_all(mysqli_stmt_get_result($s),MYSQLI_ASSOC);
$findBanner=function($id) use ($banners){ foreach($banners as $banner){ if((int)$banner['id']===(int)$id) return $banner; } return null; };
// Only site paths and http(s) addresses are accepted as banner links.
$validLink=function($link){ return $link==='' || (strlen($link)<=500 && preg_match('#\A(https?://[^\s<>"]+|/[^\s<>"]*)\z#i',$link)); };
if($_SERVER['REQUEST_METHOD']==='POST'){
    eshop_check_csrf();
    $action=(string)($_POST['action']??'');
    $banner=$findBanner($_POST['banner_id']??0);
    if($action==='delete' && $banner){
        $d=mysqli_prepare($conn,'DELETE FROM eshop_banners WHERE id=? AND company_id=?');
        $bannerId=(int)$banner['id']; mysqli_stmt_bind_param($d,'ii',$bannerId,$company_id); mysqli_stmt_execute($d);
        $file=basename((string)$banner['image']); if($file!=='' && is_file($bannerDir.'/'.$file)) @unlink($bannerDir.'/'.$file);
        $_SESSION['eshop_saved']='Banner removed.';
        header('Location: banners.php'); exit;
    }
    $title=trim((string)($_POST['title']??'')); $subtitle=trim((string)($_POST['subtitle']??''));
    $link=trim((string)($_POST['link_url']??'')); $sort=(int)($_POST['sort_order']??0); $active=isset($_POST['active'])?1:0;
    if(mb_strlen($title)>150 || mb_strlen($subtitle)>255) $error='Keep the title within 150 and subtitle within 255 characters.';
    elseif(!$validLink($link)) $error='Banner link must start with https://, http:// or /.';
    elseif($action==='update' && $banner){
        $u=mysqli_prepare($conn,'UPDATE eshop_banners SET title=?,subtitle=?,link_url=?,sort_order=?,active=? WHERE id=? AND company_id=?');
        $bannerId=(int)$banner['id']; mysqli_stmt_bind_param($u,'sssiiii',$title,$subtitle,$link,$sort,$active,$bannerId,$company_id); mysqli_stmt_execute($u);
        $_SESSION['eshop_saved']='Banner saved.';
        header('Location: banners.php'); exit;
    }elseif($action==='create'){
        $upload=$_FILES['image']??null;
        $image=$upload && (int)$upload['error']===UPLOAD_ERR_OK ? @getimagesize($upload['tmp_name']) : false;
        $types=[IMAGETYPE_JPEG=>'jpg',IMAGETYPE_PNG=>'png',IMAGETYPE_WEBP=>'webp'];
        if(count($banners)>=$maxBanners) $error='You can add up to '.$maxBanners.' banners.';
        elseif(!$image || !isset($types[$image[2]])) $error='Upload a JPG, PNG or WEBP banner image.';
        elseif((int)$upload['size']>3*1024*1024) $error='Banner image must be 3MB or smaller.';
        elseif(!is_dir($bannerDir) && !mkdir($bannerDir,0775,true)) $error='Banner storage could not be created.';
        else{
            $file='banner-'.$company_id.'-'.date('YmdHis').'-'.bin2hex(random_bytes(4)).'.'.$types[$image[2]];
            if(!move_uploaded_file($upload['tmp_name'],$bannerDir.'/'.$file)) $error='Banner upload failed.';
            else{
                $i=mysqli_prepare($conn,'INSERT INTO eshop_banners(company_id,image,title,subtitle,link_url,sort_order,active) VALUES(?,?,?,?,?,?,?)');
                mysqli_stmt_bind_param($i,'issssii',$company_id,$file,$title,$subtitle,$link,$sort,$active); mysqli_stmt_execute($i);
                $_SESSION['eshop_saved']='Banner added.';
                header('Location: banners.php'); exit;
            }
        }
    }
}
$csrf='<input type="hidden" name="csrf" value="'.eshop_escape($_SESSION['eshop_editor_csrf']).'">';
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-images mr-1"></i>Home Page Banners</h3><a class="float-right" href="index.php">E-shop</a></div><div class="card-body">
<?php if($error){ ?><div class="alert alert-danger" role="alert"><?= eshop_escape($error) ?></div><?php } ?>
<?php if(isset($_SESSION['eshop_saved'])){ ?><div class="alert alert-success" role="status"><?= eshop_escape($_SESSION['eshop_saved']) ?></div><?php unset($_SESSION['eshop_saved']); } ?>
<p class="text-muted">Active banners rotate at the top of your shop's home page. Recommended size: 1400 × 580 pixels. Without banners, the shop shows a welcome section.</p>
<div class="row">
<?php foreach($banners as $banner){ $file=basename((string)$banner['image']); $url=$file!=='' && is_file($bannerDir.'/'.$file)?app_path('uploads/eshop_banners/'.rawurlencode($file)):''; ?>
<div class="col-12 col-lg-6 mb-3"><div class="card h-100"><div class="card-body">
    <?php if($url!==''){ ?><img src="<?= eshop_escape($url) ?>" alt="" class="img-fluid rounded border mb-3" style="width:100%;aspect-ratio:2.4/1;object-fit:cover"><?php }else{ ?><div class="alert alert-warning">Image file is missing.</div><?php } ?>
    <form method="post"><?= $csrf ?><input type="hidden" name="action" value="update"><input type="hidden" name="banner_id" value="<?= (int)$banner['id'] ?>">
        <div class="form-row">
            <div class="col-md-6 form-group"><label class="small">Title</label><input name="title" class="form-control form-control-sm" maxlength="150" value="<?= eshop_escape($banner['title']) ?>"></div>
            <div class="col-md-6 form-group"><label class="small">Subtitle</label><input name="subtitle" class="form-control form-control-sm" maxlength="255" value="<?= eshop_escape($banner['subtitle']) ?>"></div>
            <div class="col-md-8 form-group"><label class="small">Link (optional)</label><input name="link_url" class="form-control form-control-sm" maxlength="500" value="<?= eshop_escape($banner['link_url']) ?>" placeholder="https://... or /shop/your-shop/offers"></div>
            <div class="col-md-4 form-group"><label class="small">Order</label><input name="sort_order" type="number" class="form-control form-control-sm" value="<?= (int)$banner['sort_order'] ?>"></div>
        </div>
        <div class="custom-control custom-switch mb-2"><input type="checkbox" class="custom-control-input" id="active-<?= (int)$banner['id'] ?>" name="active" value="1" <?= (int)$banner['active']?'checked':'' ?>><label class="custom-control-label" for="active-<?= (int)$banner['id'] ?>">Active</label></div>
        <button class="btn btn-primary btn-sm">Save</button>
    </form>
    <form method="post" class="mt-2" onsubmit="return confirm('Remove this banner?');"><?= $csrf ?><input type="hidden" name="action" value="delete"><input type="hidden" name="banner_id" value="<?= (int)$banner['id'] ?>"><button class="btn btn-outline-danger btn-sm">Delete</button></form>
</div></div></div>
<?php } ?>
</div>
<?php if(count($banners)<$maxBanners){ ?>
<div class="card card-outline card-success"><div class="card-header"><h3 class="card-title">Add Banner</h3></div><div class="card-body">
<form method="post" enctype="multipart/form-data"><?= $csrf ?><input type="hidden" name="action" value="create">
    <div class="form-row">
        <div class="col-12 form-group"><label for="image">Banner image *</label><input id="image" name="image" type="file" class="form-control-file" accept="image/jpeg,image/png,image/webp" required><small class="form-text text-muted">JPG, PNG or WEBP, maximum 3MB.</small></div>
        <div class="col-md-6 form-group"><label for="title">Title</label><input id="title" name="title" class="form-control" maxlength="150"></div>
        <div class="col-md-6 form-group"><label for="subtitle">Subtitle</label><input id="subtitle" name="subtitle" class="form-control" maxlength="255"></div>
        <div class="col-md-8 form-group"><label for="link_url">Link (optional)</label><input id="link_url" name="link_url" class="form-control" maxlength="500" placeholder="https://... or /shop/your-shop/offers"></div>
        <div class="col-md-4 form-group"><label for="sort_order">Order</label><input id="sort_order" name="sort_order" type="number" class="form-control" value="<?= count($banners)+1 ?>"></div>
    </div>
    <div class="custom-control custom-switch mb-3"><input type="checkbox" class="custom-control-input" id="active-new" name="active" value="1" checked><label class="custom-control-label" for="active-new">Active</label></div>
    <button class="btn btn-success"><i class="fas fa-upload mr-1"></i>Upload Banner</button>
</form>
</div></div>
<?php } ?>
</div></div>
<?php require_once '../includes/footer.php'; ?>
