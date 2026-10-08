<?php
require_once 'bootstrap.php';
require_once '../includes/product_category_helper.php';
require_once '../includes/eshop_order_helper.php';
require_once '../includes/eshop_catalog_helper.php';
eshop_order_schema($conn);
$error = '';
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    eshop_check_csrf();
    $product_id = (int)($_POST['product_id'] ?? 0);
    $price = trim((string)($_POST['online_price'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $published = isset($_POST['published']) ? 1 : 0;
    $check = mysqli_prepare($conn, 'SELECT id,status FROM products WHERE id=? AND user_id=?');
    mysqli_stmt_bind_param($check,'ii',$product_id,$company_id); mysqli_stmt_execute($check);
    $product = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
    if(!$product){ $error = 'Product not found.'; }
    elseif($published && $product['status'] !== 'active'){ $error = 'Activate this product in Products before publishing it.'; }
    elseif($published && !product_uses_stock($conn,$product_id,$company_id)){ $error = 'Only physical stock products can be published in the E-shop.'; }
    elseif($price !== '' && !preg_match('/\A\d{1,10}(?:\.\d{1,2})?\z/', $price)){ $error = 'Enter a valid non-negative online price.'; }
    elseif(strlen($description) > 10000){ $error = 'Description is too long.'; }
    else{
        $price = $price === '' ? null : $price;
        $save = mysqli_prepare($conn, 'INSERT INTO eshop_products (company_id,product_id,published,online_price,description) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE published=VALUES(published),online_price=VALUES(online_price),description=VALUES(description)');
        mysqli_stmt_bind_param($save,'iiiss',$company_id,$product_id,$published,$price,$description); mysqli_stmt_execute($save);
        $_SESSION['eshop_saved'] = 'Shop product settings saved.';
        header('Location: products.php'); exit;
    }
}
$q = mb_substr(trim((string)($_GET['q'] ?? '')),0,100);
$page = max(1,(int)($_GET['page'] ?? 1)); $offset = ($page-1)*24;
$like = '%'.$q.'%';
$checkoutBranch=eshop_central_branch_id($conn,$company_id);
$summaryStmt=mysqli_prepare($conn,"SELECT COUNT(*) AS total_products,COALESCE(SUM(CASE WHEN e.published=1 THEN 1 ELSE 0 END),0) AS published_products,COALESCE(SUM(CASE WHEN p.status='active' AND c.category_type='stock_product' THEN 1 ELSE 0 END),0) AS publishable_products FROM products p LEFT JOIN product_categories c ON c.id=p.category_id LEFT JOIN eshop_products e ON e.company_id=p.user_id AND e.product_id=p.id WHERE p.user_id=?"); mysqli_stmt_bind_param($summaryStmt,'i',$company_id); mysqli_stmt_execute($summaryStmt); $productSummary=mysqli_fetch_assoc(mysqli_stmt_get_result($summaryStmt));
$stmt = mysqli_prepare($conn, "SELECT p.id,p.product_name,p.sku,p.sale_price,p.photo_path,p.status,c.category_type,e.published,e.online_price,e.description,(SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id=p.id AND pv.user_id=p.user_id) AS variant_count FROM products p LEFT JOIN product_categories c ON c.id=p.category_id LEFT JOIN eshop_products e ON e.company_id=p.user_id AND e.product_id=p.id WHERE p.user_id=? AND (p.product_name LIKE ? OR p.sku LIKE ?) ORDER BY p.id DESC LIMIT 25 OFFSET ?");
mysqli_stmt_bind_param($stmt,'issi',$company_id,$like,$like,$offset); mysqli_stmt_execute($stmt);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC); $more = count($rows)>24; $rows = array_slice($rows,0,24);
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title">Shop Products</h3><a class="float-right" href="index.php">E-shop</a></div><div class="card-body">
<?php if($error){ ?><div class="alert alert-danger" role="alert"><?= eshop_escape($error) ?></div><?php } ?>
<?php if(isset($_SESSION['eshop_saved'])){ ?><div class="alert alert-success" role="status"><?= eshop_escape($_SESSION['eshop_saved']) ?></div><?php unset($_SESSION['eshop_saved']); } ?>
<p class="text-muted">Select products for your storefront. Leave online price empty to use the product sale price. Published, active products appear in your shop.</p>
<div class="row mb-3"><div class="col-4"><div class="small-box bg-light border"><div class="inner"><h4><?= (int)$productSummary['published_products'] ?></h4><p>Published</p></div></div></div><div class="col-4"><div class="small-box bg-light border"><div class="inner"><h4><?= (int)$productSummary['publishable_products'] ?></h4><p>Physical & active</p></div></div></div><div class="col-4"><div class="small-box bg-light border"><div class="inner"><h4><?= (int)$productSummary['total_products'] ?></h4><p>All products</p></div></div></div></div>
<form method="get" class="input-group mb-3"><input name="q" class="form-control" placeholder="Search product name or SKU" aria-label="Search products" value="<?= eshop_escape($q) ?>"><div class="input-group-append"><button class="btn btn-primary">Search</button><?php if($q!==''){ ?><a class="btn btn-outline-secondary" href="products.php">Clear</a><?php } ?></div></form>
<?php if(!$rows){ ?><p>No products found. Add physical products in the Products module first.</p><?php } ?>
<div class="row"><?php foreach($rows as $item){ ?>
<div class="col-12 col-md-6 col-xl-4"><form method="post" class="card h-100"><div class="card-body">
<?php $isPhysical=$item['category_type']==='stock_product'; $photoFile=basename((string)($item['photo_path']??'')); $hasPhoto=$photoFile!=='' && is_file(__DIR__.'/../uploads/products/'.$photoFile); $variantNames=(int)$item['variant_count']>0?product_variant_names($conn,(int)$item['id'],$company_id):[]; $onlineVariants=$isPhysical && $checkoutBranch>0?eshop_catalog_variant_options($conn,$company_id,(int)$item['id']):[]; $onlineStock=array_sum(array_column($onlineVariants,'available')); $stockBreakdown=[]; foreach($onlineVariants as $onlineVariant){ if($onlineVariant['name']!=='') $stockBreakdown[]=$onlineVariant['name'].': '.(int)$onlineVariant['available']; } ?><?php if($hasPhoto){ ?><img class="img-fluid rounded border mb-2" style="height:150px;width:100%;object-fit:contain;background:#f8f9fa" src="<?= eshop_escape(app_path('uploads/products/'.rawurlencode($photoFile))) ?>" alt="<?= eshop_escape($item['product_name']) ?>"><?php }else{ ?><div class="border rounded bg-light text-muted d-flex align-items-center justify-content-center mb-2" style="height:90px">No product photo</div><?php } ?><h5><?= eshop_escape($item['product_name']) ?></h5><p class="small text-muted">SKU: <?= eshop_escape($item['sku']) ?> · <?= eshop_escape($item['status']) ?><br>Sale price: BDT <?= number_format((float)$item['sale_price'],2) ?><br><?= $isPhysical?'Physical stock product':'Not a physical stock product' ?><?php if($variantNames){ ?><br>Variants: <?= eshop_escape(implode(', ',$variantNames)) ?><?php } ?><?php if($isPhysical && $checkoutBranch>0){ ?><br><span class="<?= $onlineStock>0?'text-success':'text-danger' ?>">Central online stock: <?= (int)$onlineStock ?></span><?php if($stockBreakdown){ ?><br><span>Stock by variant: <?= eshop_escape(implode(' · ',$stockBreakdown)) ?><?php } ?><?php }elseif($isPhysical){ ?><br><span class="text-warning">Central stock is not available yet.</span><?php } ?></p>
<input type="hidden" name="csrf" value="<?= eshop_escape($_SESSION['eshop_editor_csrf']) ?>"><input type="hidden" name="product_id" value="<?= (int)$item['id'] ?>">
<div class="form-group"><label for="price-<?= (int)$item['id'] ?>">Online price (optional)</label><input id="price-<?= (int)$item['id'] ?>" name="online_price" class="form-control" type="number" min="0" max="9999999999.99" step="0.01" value="<?= eshop_escape($item['online_price'] ?? '') ?>"></div>
<div class="form-group"><label for="desc-<?= (int)$item['id'] ?>">Product description</label><textarea id="desc-<?= (int)$item['id'] ?>" name="description" class="form-control" rows="3" maxlength="10000" aria-describedby="desc-help-<?= (int)$item['id'] ?>"><?= eshop_escape($item['description'] ?? '') ?></textarea><small id="desc-help-<?= (int)$item['id'] ?>" class="form-text text-muted">This description appears on this product's E-shop listing and details page.</small></div>
<label class="d-block"><input type="checkbox" name="published" value="1" <?= !empty($item['published'])?'checked':'' ?> <?= (!$isPhysical || $item['status']!=='active')?'disabled':'' ?>> Publish in E-shop</label><?php if(!$isPhysical){ ?><p class="small text-warning">Only physical stock products can be published.</p><?php }elseif($item['status']!=='active'){ ?><p class="small text-warning">Activate this product before publishing.</p><?php } ?>
<button class="btn btn-primary btn-sm">Save Product</button>
</div></form></div><?php } ?></div>
<nav class="mt-3" aria-label="Product pages"><?php if($page>1){ ?><a class="btn btn-outline-secondary" href="?<?= eshop_escape(http_build_query(['q'=>$q,'page'=>$page-1])) ?>">Previous</a><?php } ?> <?php if($more){ ?><a class="btn btn-outline-primary" href="?<?= eshop_escape(http_build_query(['q'=>$q,'page'=>$page+1])) ?>">Next</a><?php } ?></nav>
</div></div><?php require_once '../includes/footer.php'; ?>
