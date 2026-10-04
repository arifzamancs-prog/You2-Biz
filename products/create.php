<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/product_expiry_helper.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_category_helper.php';
require_once '../includes/product_image_helper.php';

$user_id = $_SESSION['user_id'];
ensure_product_management_columns($conn);
ensure_product_image_column($conn);
ensure_fifo_inventory_tables($conn);
ensure_fifo_only_product_categories($conn, $user_id);
ensure_product_variant_schema($conn);
ensure_product_subcategory_schema($conn);
$show_expired_on = is_product_expiry_enabled($conn);

$message = '';
$message_type = '';

/*
|----------------------------------
| Load Categories
|----------------------------------
*/

$sql = "SELECT *
        FROM product_categories
        WHERE user_id=?
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

    if(!$is_stock_product){
        $message = 'Please select an active FIFO product category.';
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

    $status =
    $_POST['status'];

    $photo_error = '';
    $photo_path = product_save_compressed_photo($_FILES['product_photo'] ?? [], $photo_error);
    if($photo_error !== ''){
        $message = $photo_error;
        $message_type = 'danger';
    }else{

    mysqli_begin_transaction($conn);

    $sql = "INSERT INTO products
            (
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
                ?,?,?,?,?,?,?,?,?,?,?,?,?,?
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
                $stock_saved = fifo_inventory_create_batch($conn, $user_id, $product_id, $variant_qty, $purchase_price, 'product_opening', $product_id, 'OPEN-' . $product_id, date('Y-m-d'), null, $variant) && $stock_saved;
            }
        }else{
            $stock_saved = fifo_inventory_create_batch(
                $conn, $user_id, $product_id, $opening_stock, $purchase_price,
                'product_opening', $product_id, 'OPEN-' . $product_id, date('Y-m-d')
            );
        }

        if(!$stock_saved){
            mysqli_rollback($conn);
            product_delete_photo_file($photo_path);
            $message = "Product opening stock batch could not be saved.";
            $message_type = "danger";
        }else{
            mysqli_commit($conn);

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

                                <option value="<?= $cat['id']; ?>" data-sub-categories='<?= htmlspecialchars(json_encode(product_variant_options_from_text($cat['sub_category'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>' data-variants='<?= htmlspecialchars(json_encode(product_variant_options_from_text($cat['variant_options'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>'>

                                    <?= htmlspecialchars($cat['category_name']); ?>

                                </option>

                            <?php } ?>

                        </select>

                    </div>

                </div>

                <div class="col-md-6">
                    <div class="form-group">
                        <label>Sub Category</label>
                        <select name="sub_category" id="sub_category" class="form-control"><option value="">Select sub category</option></select>
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
                            required>

                    </div>

                </div>

            </div>

            <div class="row"><div class="col-md-4"><div class="form-group"><label>Product Photo <small class="text-muted">(Optional, automatically compressed below 50 KB)</small></label><input type="file" name="product_photo" id="product_photo" accept="image/jpeg,image/png,image/webp" class="form-control"><small id="product-photo-status" class="form-text text-muted"></small></div></div></div>

            <div class="row">

                <div class="col-md-4">

                    <div class="form-group">

                        <label>
                            Code
                        </label>

                        <input
                            type="text"
                            name="sku"
                            class="form-control">

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
                            value="0">

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
                            value="0">

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
                            class="form-control">

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
                            value="0">

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
                        value="5">

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

                            <option value="active">
                                Active
                            </option>

                            <option value="inactive">
                                Inactive
                            </option>

                        </select>

                    </div>

                </div>

            </div>

            <button
                type="submit"
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

    </div>

</div>

<script>
(function(){
const category=document.getElementById('category_id'), subCategory=document.getElementById('sub_category'), opening=document.querySelector('[name="current_stock"]'), wrap=document.getElementById('variant-opening-wrap'), fields=document.getElementById('variant-opening-fields');
function syncVariants(){
  const option=category.options[category.selectedIndex]; let variants=[];
  let subCategories=[]; try{subCategories=JSON.parse(option.dataset.subCategories||'[]')}catch(e){}
  subCategory.innerHTML='<option value="">'+(subCategories.length?'Select sub category':'No sub category')+'</option>';
  subCategories.forEach(name=>{const item=document.createElement('option');item.value=name;item.textContent=name;subCategory.appendChild(item);});
  subCategory.disabled=subCategories.length===0;
  try{variants=JSON.parse(option.dataset.variants||'[]')}catch(e){}
  fields.innerHTML=''; wrap.classList.toggle('d-none',variants.length===0); opening.disabled=variants.length>0;
  if(!variants.length){ return; }
  const updateTotal=()=>{let total=0; fields.querySelectorAll('input').forEach(input=>{total+=Math.max(0,parseInt(input.value,10)||0);}); opening.value=total;};
  variants.forEach(variant=>{const div=document.createElement('div'); div.className='col-md-4'; const label=document.createElement('label'); label.textContent=variant; const input=document.createElement('input'); input.type='number'; input.min='0'; input.step='1'; input.value='0'; input.className='form-control'; input.name='variant_opening_qty['+variant+']'; input.addEventListener('input',updateTotal); div.append(label,input); fields.append(div);});
  updateTotal();
}
category.addEventListener('change',syncVariants); syncVariants();
})();
(function(){const input=document.getElementById('product_photo'),form=document.getElementById('product-form'),status=document.getElementById('product-photo-status');if(!input||!window.DataTransfer)return;let busy=false;input.addEventListener('change',function(){const file=input.files[0];if(!file)return;busy=true;status.className='form-text text-muted';status.textContent='Compressing photo…';const reader=new FileReader();reader.onload=e=>{const image=new Image();image.onload=()=>{let max=1200;const attempt=()=>{const scale=Math.min(1,max/Math.max(image.width,image.height)),canvas=document.createElement('canvas');canvas.width=Math.max(1,Math.round(image.width*scale));canvas.height=Math.max(1,Math.round(image.height*scale));canvas.getContext('2d').drawImage(image,0,0,canvas.width,canvas.height);let quality=.82;const save=()=>canvas.toBlob(blob=>{if(blob&&blob.size<=51200){const data=new DataTransfer();data.items.add(new File([blob],'product-photo.jpg',{type:'image/jpeg'}));input.files=data.files;busy=false;status.className='form-text text-success';status.textContent='Photo ready: '+Math.ceil(blob.size/1024)+' KB.';return;}if(quality>.1){quality-=.12;save();return;}if(max>96){max=Math.max(96,Math.round(max*.72));attempt();return;}busy=false;input.value='';status.className='form-text text-danger';status.textContent='Photo could not be compressed below 50 KB.';},'image/jpeg',quality);save();};attempt();};image.onerror=()=>{busy=false;status.textContent='Invalid photo selected.';};image.src=e.target.result;};reader.readAsDataURL(file);});form.addEventListener('submit',e=>{if(busy){e.preventDefault();status.className='form-text text-warning';status.textContent='Please wait for photo compression.';}});})();
</script>

<?php
require_once '../includes/footer.php';
?>
