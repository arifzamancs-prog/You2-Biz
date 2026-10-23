<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/product_expiry_helper.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_category_helper.php';
require_once '../includes/product_image_helper.php';

$user_id = $_SESSION['user_id'];
$catalog_branch_id = ($_SESSION['company_type'] ?? '') === 'Restaurant & Cafe' ? selected_branch_id($conn, true) : 0;
$catalog_branch_column = $catalog_branch_id > 0 ? 'branch_id,' : '';
$catalog_branch_value = $catalog_branch_id > 0 ? $catalog_branch_id . ',' : '';
$variants_enabled = product_variants_enabled($conn, $user_id);
$product_photos_enabled = product_photos_enabled($conn, $user_id);
ensure_product_management_columns($conn);
ensure_product_image_column($conn);
ensure_fifo_inventory_tables($conn);
ensure_fifo_only_product_categories($conn, $user_id);
ensure_product_variant_schema($conn);
ensure_product_subcategory_schema($conn);
$show_expired_on = is_product_expiry_enabled($conn);

$message = '';
$message_type = '';
$form_data = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
// The Add Product form saves through AJAX so several products can be added without leaving the page.
$is_ajax_save = $_SERVER['REQUEST_METHOD'] === 'POST' && strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

/*
|----------------------------------
| Load Categories
|----------------------------------
*/

$sql = "SELECT *
        FROM product_categories
        WHERE user_id=?" . restaurant_catalog_scope_sql($conn, '', true) . "
        AND status='active'
        ORDER BY category_name ASC";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $user_id
);

mysqli_stmt_execute($stmt);

$categories =
mysqli_stmt_get_result($stmt);

/*
|----------------------------------
| Save Product
|----------------------------------
*/

if($_SERVER['REQUEST_METHOD']=='POST'){

    $limit_sql = "SELECT
                    u.max_products,
                    COUNT(p.id) AS product_count
                  FROM users u
                  LEFT JOIN products p
                    ON p.user_id = u.id
                  WHERE u.id=?
                  GROUP BY u.id, u.max_products";

    $limit_stmt = mysqli_prepare($conn, $limit_sql);

    mysqli_stmt_bind_param(
        $limit_stmt,
        "i",
        $user_id
    );

    mysqli_stmt_execute($limit_stmt);
    $limit = mysqli_fetch_assoc(mysqli_stmt_get_result($limit_stmt));

    if (product_sku_exists($conn, $user_id, $_POST['sku'] ?? '')) {
        $message = 'This Code is already in use. Enter a unique Code for this company.';
        $message_type = 'danger';
    } elseif($limit && (int)$limit['product_count'] >= (int)$limit['max_products']){

        $message = "Product limit reached for your subscription. " . subscription_support_message();
        $message_type = "danger";

    }else{

    $category_id =
    (int)$_POST['category_id'];
    $sub_category = trim($_POST['sub_category'] ?? '');
    $sub_category_options = product_category_subcategory_options($conn, $category_id, $user_id);
    if($sub_category !== '' && !in_array($sub_category, $sub_category_options, true)){
        $sub_category = '';
    }

    $is_stock_product = product_category_is_stock($conn, $category_id, $user_id);

    if(!product_category_allows_creation($conn, $category_id, $user_id)){
        $message = 'Please select an active product category.';
        $message_type = 'danger';
    }else{

    $product_name =
    trim($_POST['product_name']);

    $sku =
    trim($_POST['sku']);

    $purchase_price = (float)($_POST['purchase_price'] ?? 0);

    $sale_price =
    (float)$_POST['sale_price'];

    $expired_on = $show_expired_on
        ? trim($_POST['expired_on'] ?? '')
        : '';

    if($expired_on === ''){
        $expired_on = null;
    }

    $category_variants = product_category_variant_options($conn, $category_id, $user_id);
    $variant_quantities = $_POST['variant_opening_qty'] ?? [];
    $variant_opening = [];
    foreach($category_variants as $variant){
        $qty = (int)($variant_quantities[$variant] ?? 0);
        if($qty < 0){ $qty = 0; }
        $variant_opening[$variant] = $qty;
    }
    $opening_stock = empty($category_variants)
        ? (int)($_POST['current_stock'] ?? 0)
        : array_sum($variant_opening);
    $minimum_stock = (int)($_POST['minimum_stock'] ?? 0);
    if (!$is_stock_product) {
        $opening_stock = $minimum_stock = 0;
        $variant_opening = array_fill_keys($category_variants, 0);
    }

    $status =
    $_POST['status'];

    $photo_error = '';
    $photo_path = $product_photos_enabled
        ? product_save_compressed_photo($_FILES['product_photo'] ?? [], $photo_error)
        : '';
    if($photo_error !== ''){
        $message = $photo_error;
        $message_type = 'danger';
    }else{

    mysqli_begin_transaction($conn);

    $sql = "INSERT INTO products
            (
                {$catalog_branch_column}
                user_id,
                category_id,
                sub_category,
                product_name,
                sku,
                photo_path,
                purchase_price,
                sale_price,
                expired_on,
                current_stock,
                opening_stock_quantity,
                opening_stock_unit_cost,
                minimum_stock,
                status
            )
            VALUES
            (
                {$catalog_branch_value}?,?,?,?,?,?,?,?,?,?,?,?,?,?
            )";

    $stmt = mysqli_prepare(
        $conn,
        $sql
    );

    mysqli_stmt_bind_param(
        $stmt,
        "iissssddsdddis",
        $user_id,
        $category_id,
        $sub_category,
        $product_name,
        $sku,
        $photo_path,
        $purchase_price,
        $sale_price,
        $expired_on,
        $opening_stock,
        $opening_stock,
        $purchase_price,
        $minimum_stock,
        $status
        
    );

    if(mysqli_stmt_execute($stmt)){
        $product_id = (int)mysqli_insert_id($conn);
        $stock_saved = true;
        if(!empty($category_variants)){
            $variant_stmt = mysqli_prepare($conn, 'INSERT INTO product_variants(user_id,product_id,variant_name) VALUES (?,?,?)');
            foreach($variant_opening as $variant => $variant_qty){
                mysqli_stmt_bind_param($variant_stmt, 'iis', $user_id, $product_id, $variant);
                $stock_saved = mysqli_stmt_execute($variant_stmt) && $stock_saved;
                $stock_saved = fifo_inventory_create_batch($conn, $user_id, $product_id, $variant_qty, $purchase_price, 'product_opening', $product_id, 'OPEN-' . $product_id, date('Y-m-d'), $catalog_branch_id > 0 ? $catalog_branch_id : null, $variant) && $stock_saved;
            }
        }else{
            $stock_saved = fifo_inventory_create_batch(
                $conn, $user_id, $product_id, $opening_stock, $purchase_price,
                'product_opening', $product_id, 'OPEN-' . $product_id, date('Y-m-d'), $catalog_branch_id > 0 ? $catalog_branch_id : null
            );
        }

        if(!$stock_saved){
            mysqli_rollback($conn);
            product_delete_photo_file($photo_path);
            $message = "Product opening stock batch could not be saved.";
            $message_type = "danger";
        }else{
            mysqli_commit($conn);

            if($is_ajax_save){
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'message' => 'Product "' . $product_name . '" saved successfully.',
                    'product' => ['id' => $product_id, 'name' => $product_name, 'sku' => $sku, 'stock' => $opening_stock],
                ]);
                exit;
            }

            header(
                "Location: index.php"
            );

            exit;
        }

    }else{
        mysqli_rollback($conn);
        product_delete_photo_file($photo_path);

        $message =
        "Failed to save product";

        $message_type =
        "danger";
    }
    }
    }

    }
}

if($is_ajax_save){
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => $message !== '' ? $message : 'Product could not be saved. Please try again.']);
    exit;
}

// Restaurant & Cafe has a concise product form: menu items do not need
// inventory fields, while stock items reveal them only when selected.
if (restaurant_catalog_enabled($conn, $user_id)) {
    require_once '../restaurant/products_create.php';
    exit;
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">

            <i class="fas fa-plus-circle mr-2"></i>

            Add Product

        </h3>

    </div>

    <div class="card-body">

        <div id="product-save-message" aria-live="polite"></div>

        <?php if($message){ ?>

            <div class="alert alert-<?= $message_type; ?>">

                <?= htmlspecialchars($message); ?>

            </div>

        <?php } ?>

        <form method="post" enctype="multipart/form-data" id="product-form">

            <div class="row">

                <div class="col-md-6">

                    <div class="form-group">

                        <label>
                            Category
                        </label>

                        <select
                            name="category_id"
                            id="category_id"
                            class="form-control"
                            required>

                            <?php while($cat = mysqli_fetch_assoc($categories)){ ?>

                                <option value="<?= $cat['id']; ?>" data-product-type="<?= htmlspecialchars($cat['category_type'], ENT_QUOTES) ?>" <?= (string)$cat['id'] === (string)($form_data['category_id'] ?? '') ? 'selected' : '' ?> data-sub-categories='<?= htmlspecialchars(json_encode(product_variant_options_from_text($cat['sub_category'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>' data-variants='<?= htmlspecialchars(json_encode($variants_enabled ? product_variant_options_from_text($cat['variant_options'] ?? '') : []), ENT_QUOTES, 'UTF-8'); ?>'>

                                    <?= htmlspecialchars($cat['category_name']); ?>

                                </option>

                            <?php } ?>

                        </select>

                    </div>

                </div>

                <div class="col-md-6">
                    <div class="form-group">
                        <label>Sub Category</label>
                        <select name="sub_category" id="sub_category" class="form-control" data-selected="<?= htmlspecialchars((string)($form_data['sub_category'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><option value="">Select sub category</option></select>
                    </div>
                </div>

                <div class="col-md-6">

                    <div class="form-group">

                        <label>
                            Product Name
                        </label>

                        <input
                            type="text"
                            name="product_name"
                            class="form-control"
                            value="<?= htmlspecialchars((string)($form_data['product_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            required>

                    </div>

                </div>

            </div>

            <?php if($product_photos_enabled){ ?><div class="row"><div class="col-md-4"><div class="form-group"><label>Product Photo <small class="text-muted">(Optional)</small></label><input type="file" name="product_photo" id="product_photo" accept="image/jpeg,image/png,image/webp" class="form-control"><small id="product-photo-status" class="form-text text-muted"></small></div></div></div><?php } ?>

            <div class="row">

                <div class="col-md-4">

                    <div class="form-group">

                        <label>
                            Code
                        </label>

                        <input
                            type="text"
                            name="sku"
                            class="form-control"
                            value="<?= htmlspecialchars((string)($form_data['sku'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

                    </div>

                </div>

                <div class="col-md-8 d-none" id="variant-opening-wrap">
                    <label>Variant Opening Stock</label>
                    <div class="row" id="variant-opening-fields"></div>
                </div>

                <div class="col-md-4">

                    <div class="form-group">

                        <label>
                            Purchase Price
                        </label>

                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            name="purchase_price"
                            class="form-control"
                            value="<?= htmlspecialchars((string)($form_data['purchase_price'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>">

                    </div>

                </div>

                <div class="col-md-4">

                    <div class="form-group">

                        <label>
                            Sale Price
                        </label>

                        <input
                            type="number"
                            step="0.01"
                            name="sale_price"
                            class="form-control"
                            value="<?= htmlspecialchars((string)($form_data['sale_price'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>">

                    </div>

                </div>

                <?php if($show_expired_on){ ?>
                <div class="col-md-4">

                    <div class="form-group">

                        <label>
                            Expiry on
                        </label>

                        <input
                            type="date"
                            name="expired_on"
                            class="form-control"
                            value="<?= htmlspecialchars((string)($form_data['expired_on'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

                    </div>

                </div>
                <?php } ?>

            </div>

            <div class="row">

                <div class="col-md-4">

                    <div class="form-group">

                        <label>
                            Opening Stock
                        </label>

                        <input
                            type="number"
                            step="1"
                            min="0"
                            name="current_stock"
                            class="form-control"
                            value="<?= htmlspecialchars((string)($form_data['current_stock'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>">

                    </div>

                </div>

                <div class="col-md-4">

                <div class="form-group">

                    <label>
                        Minimum Stock
                    </label>

                    <input
                        type="number"
                        step="1"
                        min="0"
                        name="minimum_stock"
                        class="form-control"
                        value="<?= htmlspecialchars((string)($form_data['minimum_stock'] ?? '5'), ENT_QUOTES, 'UTF-8') ?>">

                </div>

            </div>

                <div class="col-md-4">

                    <div class="form-group">

                        <label>
                            Status
                        </label>

                        <select
                            name="status"
                            class="form-control">

                            <option value="active" <?= ($form_data['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>
                                Active
                            </option>

                            <option value="inactive" <?= ($form_data['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>
                                Inactive
                            </option>

                        </select>

                    </div>

                </div>

            </div>

            <button
                type="submit"
                id="save-product-btn"
                class="btn btn-primary">

                <i class="fas fa-save"></i>

                Save Product

            </button>

            <a
                href="index.php"
                class="btn btn-secondary">

                Back

            </a>

        </form>

        <div id="recent-products-wrap" class="mt-4 d-none">
            <h6 class="font-weight-bold mb-2"><i class="fas fa-check-circle text-success mr-1"></i>Added on this page</h6>
            <ul class="list-group" id="recent-products-list"></ul>
        </div>

    </div>

</div>

<script>
(function(){
const category=document.getElementById('category_id'), subCategory=document.getElementById('sub_category'), opening=document.querySelector('[name="current_stock"]'), wrap=document.getElementById('variant-opening-wrap'), fields=document.getElementById('variant-opening-fields');
const savedVariantQuantities=<?= json_encode($form_data['variant_opening_qty'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
function syncVariants(){
  const option=category.options[category.selectedIndex]; let variants=[];
  let subCategories=[]; try{subCategories=JSON.parse(option.dataset.subCategories||'[]')}catch(e){}
  subCategory.innerHTML='<option value="">'+(subCategories.length?'Select sub category':'No sub category')+'</option>';
  subCategories.forEach(name=>{const item=document.createElement('option');item.value=name;item.textContent=name;if(name===subCategory.dataset.selected)item.selected=true;subCategory.appendChild(item);});
  subCategory.disabled=subCategories.length===0;
  subCategory.closest('.col-md-6').classList.toggle('d-none',subCategories.length===0);
  try{variants=JSON.parse(option.dataset.variants||'[]')}catch(e){}
  fields.innerHTML=''; wrap.classList.toggle('d-none',variants.length===0); opening.disabled=variants.length>0;
  const nonStock=option?.dataset.productType==='non_stock';
  [opening, document.querySelector('[name="minimum_stock"]')].forEach(input=>{if(input){input.closest('.form-group').classList.toggle('d-none',nonStock);if(nonStock)input.value=0;}});
  if(nonStock){wrap.classList.add('d-none');opening.disabled=true;return;}
  if(!variants.length){ return; }
  const updateTotal=()=>{let total=0; fields.querySelectorAll('input').forEach(input=>{total+=Math.max(0,parseInt(input.value,10)||0);}); opening.value=total;};
  variants.forEach(variant=>{const div=document.createElement('div'); div.className='col-md-4'; const label=document.createElement('label'); label.textContent=variant; const input=document.createElement('input'); input.type='number'; input.min='0'; input.step='1'; input.value=savedVariantQuantities[variant] ?? '0'; input.className='form-control'; input.name='variant_opening_qty['+variant+']'; input.addEventListener('input',updateTotal); div.append(label,input); fields.append(div);});
  updateTotal();
}
category.addEventListener('change',syncVariants); syncVariants();
})();
(function(){const input=document.getElementById('product_photo'),status=document.getElementById('product-photo-status');if(!input||!status)return;input.addEventListener('change',function(){status.textContent='';});})();
// Save products with AJAX and keep the form open for the next product.
(function(){
  const form=document.getElementById('product-form'), button=document.getElementById('save-product-btn'), box=document.getElementById('product-save-message');
  const recentWrap=document.getElementById('recent-products-wrap'), recentList=document.getElementById('recent-products-list');
  if(!form||!button||!window.fetch||!window.FormData)return;
  const buttonHtml=button.innerHTML;
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const show=(ok,text)=>{box.innerHTML='<div class="alert alert-'+(ok?'success':'danger')+' alert-dismissible fade show">'+esc(text)+'<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>';};
  const resetForNext=()=>{
    ['product_name','sku'].forEach(n=>{const f=form.querySelector('[name="'+n+'"]');if(f)f.value='';});
    ['purchase_price','sale_price','current_stock'].forEach(n=>{const f=form.querySelector('[name="'+n+'"]');if(f)f.value='0';});
    const min=form.querySelector('[name="minimum_stock"]'); if(min)min.value='5';
    const exp=form.querySelector('[name="expired_on"]'); if(exp)exp.value='';
    const status=form.querySelector('[name="status"]'); if(status)status.value='active';
    const photo=document.getElementById('product_photo'); if(photo)photo.value='';
    form.querySelectorAll('#variant-opening-fields input').forEach(f=>{f.value='0';f.dispatchEvent(new Event('input'));});
    const name=form.querySelector('[name="product_name"]'); if(name)name.focus();
  };
  form.addEventListener('submit',async function(e){
    e.preventDefault();
    if(button.disabled)return;
    document.querySelectorAll('.card-body > .alert').forEach(a=>a.remove());
    button.disabled=true; button.innerHTML='<span class="spinner-border spinner-border-sm mr-1" role="status"></span>Saving…';
    try{
      const response=await fetch(window.location.href,{method:'POST',body:new FormData(form),headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'});
      const result=await response.json();
      show(!!result.ok,result.message||'Product could not be saved. Please try again.');
      if(result.ok){
        const p=result.product||{};
        recentWrap.classList.remove('d-none');
        recentList.insertAdjacentHTML('afterbegin','<li class="list-group-item d-flex justify-content-between align-items-center py-2"><span>'+esc(p.name)+(p.sku?' <small class="text-muted">('+esc(p.sku)+')</small>':'')+'</span><span class="badge badge-light">Stock: '+esc(p.stock)+'</span></li>');
        resetForNext();
      }
    }catch(err){
      show(false,'Product could not be saved. Please check your connection and try again.');
    }finally{
      button.disabled=false; button.innerHTML=buttonHtml;
      box.scrollIntoView({behavior:'smooth',block:'nearest'});
    }
  });
})();
</script>

<?php
require_once '../includes/footer.php';
?>
