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
ensure_product_subcategory_schema($conn);
$show_expired_on = is_product_expiry_enabled($conn);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$sql = "SELECT *
        FROM products
        WHERE id=?
        AND user_id=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$product = mysqli_fetch_assoc($result);

if(!$product){
    die('Product Not Found');
}

$product_fifo_editable = fifo_inventory_product_is_editable_before_sale($conn, $user_id, $id);
$product_has_transactions = product_has_transactions($conn, $id, $user_id);

$sql = "SELECT *
        FROM product_categories
        WHERE user_id=?
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

if($_SERVER['REQUEST_METHOD']=='POST'){
    if($product_has_transactions){
        $_SESSION['error'] = 'This product can no longer be edited because it already has transactions.';
        header("Location: edit.php?id=" . $id);
        exit;
    }

    $category_id    = (int)$_POST['category_id'];
    $sub_category   = trim($_POST['sub_category'] ?? '');
    $sub_category_options = product_category_subcategory_options($conn, $category_id, $user_id);
    $keeping_current_sub_category = $category_id === (int)$product['category_id']
        && $sub_category === trim((string)($product['sub_category'] ?? ''));
    if($sub_category !== '' && !in_array($sub_category, $sub_category_options, true) && !$keeping_current_sub_category){
        $sub_category = '';
    }
    $is_stock_product = product_category_is_stock($conn, $category_id, $user_id);
    if(!$is_stock_product){
        $_SESSION['error'] = 'Please select an active FIFO product category.';
        header("Location: edit.php?id=" . $id);
        exit;
    }
    $product_name   = trim($_POST['product_name']);
    $sku            = trim($_POST['sku']);
    if (product_sku_exists($conn, $user_id, $sku, $id)) {
        $_SESSION['error'] = 'This Code is already in use. Enter a unique Code for this company.';
        header('Location: edit.php?id=' . $id);
        exit;
    }
    $purchase_price = (float)($_POST['purchase_price'] ?? 0);
    $sale_price     = (float)$_POST['sale_price'];
    $expired_on     = $show_expired_on
        ? trim($_POST['expired_on'] ?? '')
        : (string)($product['expired_on'] ?? '');

    if($expired_on === ''){
        $expired_on = null;
    }
    $opening_stock  = (int)($_POST['current_stock'] ?? 0);
    $minimum_stock = (int)($_POST['minimum_stock'] ?? 0);
    $status         = $_POST['status'];

    $photo_error = '';
    $new_photo_path = product_save_compressed_photo($_FILES['product_photo'] ?? [], $photo_error);
    if($photo_error !== ''){
        $_SESSION['error'] = $photo_error;
        header("Location: edit.php?id=" . $id);
        exit;
    }
    $photo_path = $new_photo_path !== '' ? $new_photo_path : (string)($product['photo_path'] ?? '');

    mysqli_begin_transaction($conn);

    $sql = "UPDATE products
            SET
                category_id=?,
                sub_category=?,
                product_name=?,
                sku=?,
                photo_path=?,
                purchase_price=?,
                sale_price=?,
                expired_on=?,
                current_stock=?,
                opening_stock_quantity=?,
                opening_stock_unit_cost=?,
                minimum_stock=?,
                status=?
            WHERE id=?
            AND user_id=?";

    $stmt = mysqli_prepare($conn,$sql);

    mysqli_stmt_bind_param(
        $stmt,
        "issssddsdddisii",
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
        $status,
        $id,
        $user_id
    );

    if(
        mysqli_stmt_execute($stmt) &&
        fifo_inventory_remove_product_opening_batches($conn, $id) &&
        fifo_inventory_create_batch(
            $conn,
            $user_id,
            $id,
            $opening_stock,
            $purchase_price,
            'product_opening',
            $id,
            'OPEN-' . $id,
            date('Y-m-d')
        )
    ){
        mysqli_commit($conn);
        if($new_photo_path !== '' && !empty($product['photo_path']) && $product['photo_path'] !== $new_photo_path) product_delete_photo_file($product['photo_path']);

        header("Location: index.php");
        exit;
    }

    mysqli_rollback($conn);
    if($new_photo_path !== '') product_delete_photo_file($new_photo_path);
    $_SESSION['error'] = 'Product could not be updated.';
    header("Location: edit.php?id=" . $id);
    exit;
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">
            Edit Product
        </h3>

    </div>

    <div class="card-body">

        <?php if(isset($_SESSION['error'])){ ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_SESSION['error']); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php } ?>

        <?php if($product_has_transactions){ ?>
            <div class="alert alert-warning">
                This product is locked because it already has transactions.
            </div>
        <?php } ?>

        <form method="post" enctype="multipart/form-data" id="product-form">

            <div class="form-group"><label>Product Photo <small class="text-muted">(Optional, automatically compressed below 50 KB)</small></label><div class="mb-2"><img src="<?= htmlspecialchars(product_image_url($conn, $product['photo_path'] ?? '')); ?>" alt="Product" style="width:70px;height:70px;object-fit:cover;border:1px solid #ddd;border-radius:4px"></div><input type="file" name="product_photo" id="product_photo" accept="image/jpeg,image/png,image/webp" class="form-control"><small id="product-photo-status" class="form-text text-muted">Leave empty to keep the current photo. Company logo is shown when no product photo exists.</small></div>

            <div class="form-group">

                <label>Category</label>

                <select
                    name="category_id"
                    id="category_id"
                    class="form-control">

                    <?php while($cat = mysqli_fetch_assoc($categories)){ ?>

                        <option
                            value="<?= $cat['id']; ?>"
                            data-sub-categories='<?= htmlspecialchars(json_encode(product_variant_options_from_text($cat['sub_category'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>'
                            <?= $product['category_id']==$cat['id']?'selected':''; ?>>

                            <?= htmlspecialchars($cat['category_name']); ?>

                        </option>

                    <?php } ?>

                </select>

            </div>

            <div class="form-group">
                <label>Sub Category</label>
                <select name="sub_category" id="sub_category" class="form-control" data-current="<?= htmlspecialchars($product['sub_category'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><option value="">Select sub category</option></select>
            </div>

            <div class="form-group">

                <label>Product Name</label>

                <input
                    type="text"
                    name="product_name"
                    class="form-control"
                    value="<?= htmlspecialchars($product['product_name']); ?>"
                    required>

            </div>

            <div class="form-group">

                <label>Code</label>

                <input
                    type="text"
                    name="sku"
                    class="form-control"
                    value="<?= htmlspecialchars($product['sku']); ?>">

            </div>

            <div class="form-group">

                <label>Purchase Price</label>

                <input
                    type="number"
                    step="0.01"
                    min="0"
                    name="purchase_price"
                    class="form-control"
                    value="<?= $product['purchase_price']; ?>">

            </div>

            <div class="form-group">

                <label>Sale Price</label>

                <input
                    type="number"
                    step="0.01"
                    name="sale_price"
                    class="form-control"
                    value="<?= $product['sale_price']; ?>">

            </div>

            <?php if($show_expired_on){ ?>
            <div class="form-group">

                <label>Expiry on</label>

                <input
                    type="date"
                    name="expired_on"
                    class="form-control"
                    value="<?= htmlspecialchars($product['expired_on'] ?? ''); ?>">

            </div>
            <?php }else{ ?>
                <input
                    type="hidden"
                    name="expired_on"
                    value="<?= htmlspecialchars($product['expired_on'] ?? ''); ?>">
            <?php } ?>

            <div class="form-group">

                <label>Opening Stock</label>

                <input
                    type="number"
                    step="1"
                    min="0"
                    name="current_stock"
                    class="form-control"
                    value="<?= (int)($product['opening_stock_quantity'] ?? $product['current_stock']); ?>">

            </div>

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
                value="<?= (int)$product['minimum_stock']; ?>">

            </div>

            <div class="form-group">

                <label>Status</label>

                <select
                    name="status"
                    class="form-control">

                    <option value="active"
                        <?= $product['status']=='active'?'selected':''; ?>>
                        Active
                    </option>

                    <option value="inactive"
                        <?= $product['status']=='inactive'?'selected':''; ?>>
                        Inactive
                    </option>

                </select>

            </div>

            <button
                type="submit"
                class="btn btn-primary"
                <?= $product_has_transactions ? 'disabled' : ''; ?>>

                Update Product

            </button>

        </form>

    </div>

</div>

<script>
(function(){const category=document.getElementById('category_id'), subCategory=document.getElementById('sub_category');function syncSubCategory(){let choices=[];try{choices=JSON.parse(category.options[category.selectedIndex]?.dataset.subCategories||'[]')}catch(e){}const current=subCategory.dataset.current||'';if(current&&!choices.includes(current)){choices.unshift(current)}subCategory.innerHTML='<option value="">'+(choices.length?'Select sub category':'No sub category')+'</option>';choices.forEach(name=>{const option=document.createElement('option');option.value=name;option.textContent=name;option.selected=name===current;subCategory.appendChild(option);});subCategory.disabled=choices.length===0;subCategory.dataset.current='';}category.addEventListener('change',syncSubCategory);syncSubCategory();})();
(function(){const input=document.getElementById('product_photo'),form=document.getElementById('product-form'),status=document.getElementById('product-photo-status');if(!input||!window.DataTransfer)return;let busy=false;input.addEventListener('change',function(){const file=input.files[0];if(!file)return;busy=true;status.className='form-text text-muted';status.textContent='Compressing photo…';const reader=new FileReader();reader.onload=e=>{const image=new Image();image.onload=()=>{let max=1200;const attempt=()=>{const scale=Math.min(1,max/Math.max(image.width,image.height)),canvas=document.createElement('canvas');canvas.width=Math.max(1,Math.round(image.width*scale));canvas.height=Math.max(1,Math.round(image.height*scale));canvas.getContext('2d').drawImage(image,0,0,canvas.width,canvas.height);let quality=.82;const save=()=>canvas.toBlob(blob=>{if(blob&&blob.size<=51200){const data=new DataTransfer();data.items.add(new File([blob],'product-photo.jpg',{type:'image/jpeg'}));input.files=data.files;busy=false;status.className='form-text text-success';status.textContent='Photo ready: '+Math.ceil(blob.size/1024)+' KB.';return;}if(quality>.1){quality-=.12;save();return;}if(max>96){max=Math.max(96,Math.round(max*.72));attempt();return;}busy=false;input.value='';status.className='form-text text-danger';status.textContent='Photo could not be compressed below 50 KB.';},'image/jpeg',quality);save();};attempt();};image.onerror=()=>{busy=false;status.textContent='Invalid photo selected.';};image.src=e.target.result;};reader.readAsDataURL(file);});form.addEventListener('submit',e=>{if(busy){e.preventDefault();status.className='form-text text-warning';status.textContent='Please wait for photo compression.';}});})();
</script>

<?php require_once '../includes/footer.php'; ?>
