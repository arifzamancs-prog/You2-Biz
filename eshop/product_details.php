<?php
require_once 'bootstrap.php';
require_once '../includes/product_image_helper.php';
$product_id=(int)($_GET['id'] ?? $_POST['product_id'] ?? 0);
$stmt=mysqli_prepare($conn,"SELECT p.id,p.product_name,p.sku,p.sale_price,p.photo_path,p.sub_category,c.category_name,e.published,e.online_price,e.description,e.regular_price,e.brand,e.key_features,e.specification,e.featured,e.meta_title,e.meta_description FROM products p LEFT JOIN product_categories c ON c.id=p.category_id LEFT JOIN eshop_products e ON e.company_id=p.user_id AND e.product_id=p.id WHERE p.id=? AND p.user_id=?");
mysqli_stmt_bind_param($stmt,'ii',$product_id,$company_id); mysqli_stmt_execute($stmt);
$product=mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if(!$product){ http_response_code(404); exit('Product not found.'); }
$maxImages=8;
$loadImages=function() use ($conn,$company_id,$product_id){
    $s=mysqli_prepare($conn,'SELECT id,file_name FROM eshop_product_images WHERE company_id=? AND product_id=? ORDER BY sort_order,id');
    mysqli_stmt_bind_param($s,'ii',$company_id,$product_id); mysqli_stmt_execute($s);
    return mysqli_fetch_all(mysqli_stmt_get_result($s),MYSQLI_ASSOC);
};
$images=$loadImages();
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    eshop_check_csrf();
    $action=(string)($_POST['action']??'save');
    if($action==='delete_image'){
        $imageId=(int)($_POST['image_id']??0);
        foreach($images as $image){
            if((int)$image['id']!==$imageId) continue;
            $delete=mysqli_prepare($conn,'DELETE FROM eshop_product_images WHERE id=? AND company_id=? AND product_id=?');
            mysqli_stmt_bind_param($delete,'iii',$imageId,$company_id,$product_id); mysqli_stmt_execute($delete);
            product_delete_photo_file($image['file_name']);
            $_SESSION['eshop_saved']='Image removed.';
        }
        header('Location: product_details.php?id='.$product_id); exit;
    }
    $regular=trim((string)($_POST['regular_price']??''));
    $fields=['brand'=>100,'meta_title'=>190,'meta_description'=>300];
    $values=[];
    foreach($fields as $field=>$limit) $values[$field]=trim((string)($_POST[$field]??''));
    $features=trim((string)($_POST['key_features']??'')); $specification=trim((string)($_POST['specification']??''));
    $featured=isset($_POST['featured'])?1:0;
    foreach($fields as $field=>$limit){ if(mb_strlen($values[$field])>$limit) $error='Keep '.str_replace('_',' ',$field).' within '.$limit.' characters.'; }
    if($regular!=='' && !preg_match('/\A\d{1,10}(?:\.\d{1,2})?\z/',$regular)) $error='Enter a valid regular price.';
    elseif(strlen($features)>5000 || strlen($specification)>20000) $error='Key features or specification is too long.';
    $uploads=[];
    if($error==='' && !empty($_FILES['images']['name']) && is_array($_FILES['images']['name'])){
        foreach($_FILES['images']['name'] as $index=>$name){
            if((int)($_FILES['images']['error'][$index]??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) continue;
            $uploads[]=['name'=>$name,'type'=>$_FILES['images']['type'][$index],'tmp_name'=>$_FILES['images']['tmp_name'][$index],'error'=>$_FILES['images']['error'][$index],'size'=>$_FILES['images']['size'][$index]];
        }
        if(count($images)+count($uploads)>$maxImages) $error='A product can have up to '.$maxImages.' gallery images.';
        // Check every image first so a bad file never leaves the details half saved.
        foreach($uploads as $upload){
            $info=(int)$upload['error']===UPLOAD_ERR_OK ? @getimagesize($upload['tmp_name']) : false;
            if(!$info || !in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true)){ $error='Use JPG, PNG or WEBP gallery images.'; break; }
        }
    }
    if($error===''){
        $regularValue=$regular===''?null:$regular;
        // A product edited here before it was ever published gets a hidden shop row.
        $save=mysqli_prepare($conn,"INSERT INTO eshop_products(company_id,product_id,published,online_price,description,regular_price,brand,key_features,specification,featured,meta_title,meta_description) VALUES(?,?,0,NULL,'',?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE regular_price=VALUES(regular_price),brand=VALUES(brand),key_features=VALUES(key_features),specification=VALUES(specification),featured=VALUES(featured),meta_title=VALUES(meta_title),meta_description=VALUES(meta_description)");
        mysqli_stmt_bind_param($save,'iisssssss',$company_id,$product_id,$regularValue,$values['brand'],$features,$specification,$featured,$values['meta_title'],$values['meta_description']);
        mysqli_stmt_execute($save);
        $sort=count($images);
        foreach($uploads as $upload){
            $uploadError='';
            $file=product_save_compressed_photo($upload,$uploadError);
            if($file===''){ $error=$uploadError?:'An image could not be saved.'; break; }
            $sort++;
            $insert=mysqli_prepare($conn,'INSERT INTO eshop_product_images(company_id,product_id,file_name,sort_order) VALUES(?,?,?,?)');
            mysqli_stmt_bind_param($insert,'iisi',$company_id,$product_id,$file,$sort); mysqli_stmt_execute($insert);
        }
        if($error===''){ $_SESSION['eshop_saved']='Product details saved.'; header('Location: product_details.php?id='.$product_id); exit; }
        $images=$loadImages();
    }
    $product=array_merge($product,$values,['regular_price'=>$regular,'key_features'=>$features,'specification'=>$specification,'featured'=>$featured]);
}
$price=$product['online_price']!==null && $product['online_price']!=='' ? (float)$product['online_price'] : (float)$product['sale_price'];
$mainPhoto=eshop_storefront_photo_url($product['photo_path']);
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title">Product Details: <?= eshop_escape($product['product_name']) ?></h3><a class="float-right" href="products.php">Shop Products</a></div><div class="card-body">
<?php if($error){ ?><div class="alert alert-danger" role="alert"><?= eshop_escape($error) ?></div><?php } ?>
<?php if(isset($_SESSION['eshop_saved'])){ ?><div class="alert alert-success" role="status"><?= eshop_escape($_SESSION['eshop_saved']) ?></div><?php unset($_SESSION['eshop_saved']); } ?>
<p class="text-muted mb-3">SKU: <?= eshop_escape($product['sku']) ?> · Category: <?= eshop_escape($product['category_name']??'-') ?><?= trim((string)$product['sub_category'])!==''?' › '.eshop_escape($product['sub_category']):'' ?> · Shop price: <strong>BDT <?= number_format($price,2) ?></strong> · <?= !empty($product['published'])?'<span class="badge badge-success">Published</span>':'<span class="badge badge-secondary">Not published</span>' ?></p>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= eshop_escape($_SESSION['eshop_editor_csrf']) ?>"><input type="hidden" name="product_id" value="<?= $product_id ?>"><input type="hidden" name="action" value="save">
<div class="row">
    <div class="col-md-4 form-group"><label for="regular_price">Regular price (BDT)</label><input id="regular_price" name="regular_price" class="form-control" type="number" min="0" step="0.01" value="<?= eshop_escape($product['regular_price']??'') ?>"><small class="form-text text-muted">Shown struck through with a “Save” badge when higher than the shop price.</small></div>
    <div class="col-md-4 form-group"><label for="brand">Brand</label><input id="brand" name="brand" class="form-control" maxlength="100" value="<?= eshop_escape($product['brand']??'') ?>"><small class="form-text text-muted">Used for the brand filter in the shop.</small></div>
    <div class="col-md-4 form-group"><label class="d-block">Home page</label><div class="custom-control custom-switch mt-2"><input type="checkbox" class="custom-control-input" id="featured" name="featured" value="1" <?= !empty($product['featured'])?'checked':'' ?>><label class="custom-control-label" for="featured">Show in Featured Products</label></div></div>
    <div class="col-md-6 form-group"><label for="key_features">Key features</label><textarea id="key_features" name="key_features" class="form-control" rows="7" maxlength="5000" placeholder="Model: VTS07G&#10;12MP triple lens&#10;4G SIM support"><?= eshop_escape($product['key_features']??'') ?></textarea><small class="form-text text-muted">One feature per line. The first lines also appear on product cards.</small></div>
    <div class="col-md-6 form-group"><label for="specification">Specification</label><textarea id="specification" name="specification" class="form-control" rows="7" maxlength="20000" placeholder="Camera&#10;Resolution: 12MP&#10;Lens: Triple lens&#10;Power&#10;Battery: 16000mAh"><?= eshop_escape($product['specification']??'') ?></textarea><small class="form-text text-muted">Write <code>Label: Value</code> per line. A line without a colon becomes a group heading.</small></div>
    <div class="col-md-6 form-group"><label for="meta_title">SEO title (optional)</label><input id="meta_title" name="meta_title" class="form-control" maxlength="190" value="<?= eshop_escape($product['meta_title']??'') ?>"><small class="form-text text-muted">Defaults to the product name.</small></div>
    <div class="col-md-6 form-group"><label for="meta_description">SEO description (optional)</label><input id="meta_description" name="meta_description" class="form-control" maxlength="300" value="<?= eshop_escape($product['meta_description']??'') ?>"><small class="form-text text-muted">Defaults to the shop description of this product.</small></div>
    <div class="col-12 form-group"><label for="images">Gallery images</label><input id="images" name="images[]" type="file" class="form-control-file" accept="image/jpeg,image/png,image/webp" multiple><small class="form-text text-muted">Up to <?= $maxImages ?> extra images (JPG, PNG, WEBP). The main product photo from Products always comes first.</small></div>
</div>
<button class="btn btn-primary"><i class="fas fa-save mr-1"></i>Save Details</button> <a class="btn btn-outline-secondary" href="products.php">Back</a>
</form>
<hr>
<h5>Gallery</h5>
<div class="d-flex flex-wrap" style="gap:12px">
    <?php if($mainPhoto!==''){ ?><div class="border rounded p-2 text-center" style="width:140px"><img src="<?= eshop_escape($mainPhoto) ?>" alt="" style="width:120px;height:120px;object-fit:contain"><small class="d-block text-muted mt-1">Main photo</small></div><?php } ?>
    <?php foreach($images as $image){ $url=eshop_storefront_photo_url($image['file_name']); if($url==='') continue; ?>
    <form method="post" class="border rounded p-2 text-center" style="width:140px" onsubmit="return confirm('Remove this image?');"><input type="hidden" name="csrf" value="<?= eshop_escape($_SESSION['eshop_editor_csrf']) ?>"><input type="hidden" name="product_id" value="<?= $product_id ?>"><input type="hidden" name="action" value="delete_image"><input type="hidden" name="image_id" value="<?= (int)$image['id'] ?>"><img src="<?= eshop_escape($url) ?>" alt="" style="width:120px;height:120px;object-fit:contain"><button class="btn btn-link btn-sm text-danger p-0 mt-1">Remove</button></form>
    <?php } ?>
    <?php if($mainPhoto==='' && !$images){ ?><p class="text-muted">No images yet. Add a main photo in Products or upload gallery images above.</p><?php } ?>
</div>
</div></div>
<?php require_once '../includes/footer.php'; ?>
