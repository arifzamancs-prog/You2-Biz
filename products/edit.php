<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/product_expiry_helper.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_category_helper.php';
require_once '../includes/product_image_helper.php';

$user_id = $_SESSION['user_id'];
$variants_enabled = product_variants_enabled($conn, $user_id);
$product_photos_enabled = product_photos_enabled($conn, $user_id);
ensure_product_management_columns($conn);
ensure_product_image_column($conn);
ensure_fifo_inventory_tables($conn);
ensure_fifo_only_product_categories($conn, $user_id);
ensure_product_subcategory_schema($conn);
ensure_product_variant_schema($conn);
$show_expired_on = is_product_expiry_enabled($conn);
require_once '../includes/service_warranty_helper.php';
require_once '../includes/eshop_variant_image_helper.php';
$variant_photos_enabled = eshop_variant_images_enabled($conn, (int)$user_id);
$show_warranty = product_warranty_enabled();
if ($show_warranty) product_warranty_schema($conn);
require_once '../includes/product_serial_helper.php';
$show_serial = product_serial_schema($conn);

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

$product_fifo_editable = fifo_inventory_product_opening_is_editable($conn, $user_id, $id);
// Opening serials are typed again only while none has been sold or sent; otherwise the
// serials are listed: sold ones fixed, in-stock ones correctable.
$serial_reentry = $product_fifo_editable;
if($show_serial && $serial_reentry){
    $moved_serials = mysqli_query($conn, "SELECT COUNT(*) FROM product_serials WHERE user_id=" . (int)$user_id . " AND product_id=" . (int)$id . " AND status<>'in_stock'");
    $serial_reentry = $moved_serials && (int)(mysqli_fetch_row($moved_serials)[0] ?? 0) === 0;
}
// The company admin may still correct opening stock that was only sent to a
// branch and fully brought back (nothing sold, purchased or damaged).
$opening_round_trip_location = (!$product_fifo_editable && is_admin_user() && !is_manager_user())
    ? fifo_inventory_opening_round_trip_location($conn, $user_id, $id)
    : 0;
$opening_stock_editable = $product_fifo_editable || $opening_round_trip_location > 0;
$product_has_transactions = product_has_transactions($conn, $id, $user_id);
$product_variant_names = product_variant_names($conn, $id, $user_id);
$product_variant_opening = [];
$variant_opening_stmt = mysqli_prepare($conn, "SELECT variant_name, COALESCE(SUM(" . ($opening_round_trip_location ? 'quantity' : 'remaining_quantity') . "),0) AS quantity FROM stock_batches WHERE user_id=? AND product_id=? AND source_type='product_opening' AND source_id=? GROUP BY variant_name");
if($variant_opening_stmt){
    mysqli_stmt_bind_param($variant_opening_stmt, 'iii', $user_id, $id, $id);
    mysqli_stmt_execute($variant_opening_stmt);
    $variant_opening_result = mysqli_stmt_get_result($variant_opening_stmt);
    while($variant_opening_row = mysqli_fetch_assoc($variant_opening_result)) $product_variant_opening[$variant_opening_row['variant_name']] = (int)$variant_opening_row['quantity'];
    mysqli_stmt_close($variant_opening_stmt);
}

$sql = "SELECT *
        FROM product_categories
        WHERE user_id=?" . restaurant_catalog_scope_sql($conn, '', true) . "
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
    $category_id    = (int)$_POST['category_id'];
    if($opening_round_trip_location && $category_id !== (int)$product['category_id']){
        $opening_round_trip_location = 0;
        $opening_stock_editable = false;
    }
    $sub_category   = trim($_POST['sub_category'] ?? '');
    $sub_category_options = product_category_subcategory_options($conn, $category_id, $user_id);
    $keeping_current_sub_category = $category_id === (int)$product['category_id']
        && $sub_category === trim((string)($product['sub_category'] ?? ''));
    if($sub_category !== '' && !in_array($sub_category, $sub_category_options, true) && !$keeping_current_sub_category){
        $sub_category = '';
    }
    // Variant stock and history belong to the current category, so it is fixed after any transaction.
    if($product_has_transactions && $category_id !== (int)$product['category_id']){
        $_SESSION['error'] = 'Category cannot be changed because this product already has transactions. Other information can still be updated.';
        header('Location: edit.php?id=' . $id);
        exit;
    }
    $is_stock_product = product_category_is_stock($conn, $category_id, $user_id);
    // Keeping the product's own category is allowed even if that category was made inactive later.
    if($category_id !== (int)$product['category_id'] && !product_category_allows_creation($conn, $category_id, $user_id)){
        $_SESSION['error'] = 'Please select an active product category.';
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
    $purchase_price = $product_fifo_editable ? (float)($_POST['purchase_price'] ?? 0) : (float)$product['purchase_price'];
    $sale_price     = (float)$_POST['sale_price'];
    $expired_on     = $show_expired_on
        ? trim($_POST['expired_on'] ?? '')
        : (string)($product['expired_on'] ?? '');

    if($expired_on === ''){
        $expired_on = null;
    }
    $category_variants = product_category_variant_options($conn, $category_id, $user_id);
    $variant_quantities = $_POST['variant_opening_qty'] ?? [];
    $variant_opening = [];
    if($opening_stock_editable){
        foreach($category_variants as $variant){ $variant_opening[$variant] = max(0, (int)($variant_quantities[$variant] ?? 0)); }
    }
    $opening_stock  = $opening_stock_editable
        ? (empty($category_variants) ? (int)($_POST['current_stock'] ?? 0) : array_sum($variant_opening))
        : (int)($product['opening_stock_quantity'] ?? $product['current_stock']);
    $minimum_stock = (int)($_POST['minimum_stock'] ?? 0);
    if ($is_stock_product !== product_uses_stock($conn, $id, $user_id)
        && (product_has_transactions($conn, $id, $user_id) || !$product_fifo_editable)) {
        $_SESSION['error'] = 'Cannot change stock type after inventory or sales activity. Create a new product instead.';
        header('Location: edit.php?id=' . $id);
        exit;
    }
    if (!$is_stock_product) {
        $opening_stock = $minimum_stock = 0;
        $variant_opening = array_fill_keys($category_variants, 0);
    }
    $status         = $_POST['status'];

    $photo_error = '';
    $new_photo_path = $product_photos_enabled
        ? product_save_compressed_photo($_FILES['product_photo'] ?? [], $photo_error)
        : '';
    if($photo_error !== ''){
        $_SESSION['error'] = $photo_error;
        header("Location: edit.php?id=" . $id);
        exit;
    }
    $photo_path = $new_photo_path !== '' ? $new_photo_path : (string)($product['photo_path'] ?? '');

    // Product Serial: with editable opening stock the serials are entered again, one per piece.
    $serial_on = $show_serial && $is_stock_product && !empty($_POST['serial_enabled']);
    $opening_serials = [];
    if($serial_on && $serial_reentry){
        try{
            $opening_serials = product_serial_parse_groups($_POST['opening_serials'] ?? []);
            product_serial_check_counts($opening_serials, empty($category_variants) ? ['' => $opening_stock] : $variant_opening, 'Opening stock');
        }catch(RuntimeException $e){
            if($new_photo_path !== '') product_delete_photo_file($new_photo_path);
            $_SESSION['error'] = $e->getMessage();
            header('Location: edit.php?id=' . $id);
            exit;
        }
    }
    $serial_error = '';

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

    $stock_saved = mysqli_stmt_execute($stmt);
    // After FIFO activity the opening stock is fixed; the stock counter follows the batches.
    if($stock_saved && !$opening_stock_editable && $is_stock_product) product_sync_current_stock($conn, $user_id, $id);
    // Warranty belongs to the Product Serial option; off means no warranty.
    if($stock_saved) product_warranty_save($conn, $user_id, $id, $serial_on ? ($_POST['warranty_months'] ?? 0) : 0);
    if($stock_saved && $show_serial){
        try{
            product_serial_set_enabled($conn, $user_id, $id, $serial_on);
            if($serial_reentry){
                product_serial_remove_source($conn, $user_id, 'opening', $id, $id);
                foreach($opening_serials as $serial_variant => $serial_list){
                    product_serial_add($conn, $user_id, $id, $serial_variant, $serial_list, stock_purchase_location_id($conn, (int)$user_id), 'opening', $id);
                }
            }elseif($serial_on && isset($_POST['serial_fix']) && is_array($_POST['serial_fix'])){
                // Stock already moved: in-stock serials can be corrected, sold ones stay fixed.
                product_serial_correct($conn, $user_id, $id, $_POST['serial_fix']);
            }
        }catch(RuntimeException $e){
            $serial_error = $e->getMessage();
            $stock_saved = false;
        }
    }
    if($stock_saved && $product_fifo_editable){
        $stock_saved = fifo_inventory_remove_product_opening_batches($conn, $id);
        if($stock_saved){
            $delete_variants = mysqli_prepare($conn, 'DELETE FROM product_variants WHERE user_id=? AND product_id=?');
            mysqli_stmt_bind_param($delete_variants, 'ii', $user_id, $id);
            $stock_saved = mysqli_stmt_execute($delete_variants);
            mysqli_stmt_close($delete_variants);
        }
        if($stock_saved && !empty($category_variants)){
            $variant_stmt = mysqli_prepare($conn, 'INSERT INTO product_variants(user_id,product_id,variant_name) VALUES (?,?,?)');
            foreach($variant_opening as $variant => $variant_qty){
                mysqli_stmt_bind_param($variant_stmt, 'iis', $user_id, $id, $variant);
                $stock_saved = mysqli_stmt_execute($variant_stmt) && $stock_saved;
                $stock_saved = fifo_inventory_create_batch($conn, $user_id, $id, $variant_qty, $purchase_price, 'product_opening', $id, 'OPEN-' . $id, date('Y-m-d'), ($_SESSION['company_type'] ?? '') === 'Restaurant & Cafe' ? selected_branch_id($conn, true) : null, $variant) && $stock_saved;
            }
            mysqli_stmt_close($variant_stmt);
        }elseif($stock_saved){
            $stock_saved = fifo_inventory_create_batch($conn, $user_id, $id, $opening_stock, $purchase_price, 'product_opening', $id, 'OPEN-' . $id, date('Y-m-d'), ($_SESSION['company_type'] ?? '') === 'Restaurant & Cafe' ? selected_branch_id($conn, true) : null);
        }
    }elseif($stock_saved && $opening_round_trip_location && $is_stock_product){
        $stock_saved = fifo_inventory_reset_round_trip_opening($conn, $user_id, $id, $opening_round_trip_location, empty($category_variants) ? ['' => $opening_stock] : $variant_opening, $purchase_price);
        if($stock_saved && !empty($category_variants)){
            $variant_stmt = mysqli_prepare($conn, 'INSERT INTO product_variants(user_id,product_id,variant_name) VALUES (?,?,?)');
            foreach(array_diff($category_variants, $product_variant_names) as $variant){
                mysqli_stmt_bind_param($variant_stmt, 'iis', $user_id, $id, $variant);
                $stock_saved = mysqli_stmt_execute($variant_stmt) && $stock_saved;
            }
            mysqli_stmt_close($variant_stmt);
        }
    }

    if($stock_saved){
        mysqli_commit($conn);
        // E-shop variant photos (only when the e-shop is active).
        if($variant_photos_enabled){
            $variant_photo_error = eshop_variant_images_save($conn, (int)$user_id, (int)$id, eshop_variant_files_slice('variant_photo'), array_map('strval', (array)($_POST['variant_photo_remove'] ?? [])));
            if($variant_photo_error !== '') $_SESSION['error'] = 'Product saved, but a variant photo was not: ' . $variant_photo_error;
        }
        if($new_photo_path !== '' && !empty($product['photo_path']) && $product['photo_path'] !== $new_photo_path) product_delete_photo_file($product['photo_path']);

        header("Location: index.php");
        exit;
    }

    mysqli_rollback($conn);
    if($new_photo_path !== '') product_delete_photo_file($new_photo_path);
    $_SESSION['error'] = $serial_error !== '' ? $serial_error : 'Product could not be updated.';
    header("Location: edit.php?id=" . $id);
    exit;
}

if (restaurant_catalog_enabled($conn, $user_id)) {
    require_once '../restaurant/products_edit.php';
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
                This product already has transactions, so <strong>Category</strong><?= !$product_fifo_editable ? ', <strong>Purchase Price</strong>' : '' ?><?= !$opening_stock_editable ? ' and <strong>Opening Stock</strong>' : '' ?> cannot be changed. Name, code, sub category, sale price, minimum stock, status and photo can still be updated.
            </div>
        <?php }elseif($opening_round_trip_location){ ?>
            <div class="alert alert-info">
                This product was only transferred and all of its stock is back, with no sale or purchase. As admin you can still correct the opening stock. Purchase price stays read-only.
            </div>
        <?php }elseif(!$product_fifo_editable){ ?>
            <div class="alert alert-warning">
                FIFO opening stock and purchase price are read-only because this product already has FIFO activity. Other product information can still be updated.
            </div>
        <?php } ?>

        <form method="post" enctype="multipart/form-data" id="product-form">

            <div class="row">

                <div class="col-md-6">
                    <div class="form-group">
                        <label>Category<?= $product_has_transactions ? ' <small class="text-muted">(Locked: product has transactions)</small>' : '' ?></label>
                        <?php if($product_has_transactions){ ?><input type="hidden" name="category_id" value="<?= (int)$product['category_id']; ?>"><?php } ?>
                        <select
                            <?= $product_has_transactions ? 'disabled' : 'name="category_id"' ?>
                            id="category_id"
                            class="form-control">
                            <?php while($cat = mysqli_fetch_assoc($categories)){ ?>
                                <option
                                    value="<?= $cat['id']; ?>"
                                    data-product-type="<?= htmlspecialchars($cat['category_type'], ENT_QUOTES) ?>"
                                    data-sub-categories='<?= htmlspecialchars(json_encode(product_variant_options_from_text($cat['sub_category'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>'
                                    data-variants='<?= htmlspecialchars(json_encode($variants_enabled ? product_variant_options_from_text($cat['variant_options'] ?? '') : []), ENT_QUOTES, 'UTF-8'); ?>'
                                    <?= $product['category_id']==$cat['id']?'selected':''; ?>>
                                    <?= htmlspecialchars($cat['category_name']); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="form-group">
                        <label>Sub Category</label>
                        <select name="sub_category" id="sub_category" class="form-control" data-current="<?= htmlspecialchars($product['sub_category'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><option value="">Select sub category</option></select>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="form-group">
                        <label>Product Name</label>
                        <input type="text" name="product_name" class="form-control" value="<?= htmlspecialchars($product['product_name']); ?>" required>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="active" <?= $product['status']=='active'?'selected':''; ?>>Active</option>
                            <option value="inactive" <?= $product['status']=='inactive'?'selected':''; ?>>Inactive</option>
                        </select>
                    </div>
                </div>

            </div>

            <div class="form-group d-none" id="variant-opening-wrap">
                <label>Variant Opening Stock<?= !$opening_stock_editable ? ' <small class="text-muted">(Read-only due to FIFO activity)</small>' : '' ?></label>
                <div class="row" id="variant-opening-fields"></div>
            </div>

            <?php if($product_photos_enabled){ ?><div class="row"><div class="col-md-4"><div class="form-group"><label>Product Photo <small class="text-muted">(Optional)</small></label><div class="mb-2"><img id="product-photo-preview" src="<?= htmlspecialchars(product_image_url($conn, $product['photo_path'] ?? '')); ?>" alt="Product" style="width:70px;height:70px;object-fit:cover;border:1px solid #ddd;border-radius:4px"></div><input type="file" name="product_photo" id="product_photo" accept="image/jpeg,image/png,image/webp" class="form-control"><small id="product-photo-status" class="form-text text-muted"></small></div></div></div><?php } ?>

            <div class="row">

                <div class="col-md-4">
                    <div class="form-group">
                        <label>Code</label>
                        <input type="text" name="sku" class="form-control" value="<?= htmlspecialchars($product['sku']); ?>">
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="form-group">
                        <label>Purchase Price</label>
                        <input type="number" step="0.01" min="0" name="purchase_price" class="form-control" value="<?= $product['purchase_price']; ?>" <?= !$product_fifo_editable ? 'readonly' : '' ?>>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="form-group">
                        <label>Sale Price</label>
                        <input type="number" step="0.01" name="sale_price" class="form-control" value="<?= $product['sale_price']; ?>">
                    </div>
                </div>

                <?php if($show_expired_on){ ?>
                <div class="col-md-4">
                    <div class="form-group">
                        <label>Expiry on</label>
                        <input type="date" name="expired_on" class="form-control" value="<?= htmlspecialchars($product['expired_on'] ?? ''); ?>">
                    </div>
                </div>
                <?php }else{ ?>
                <input type="hidden" name="expired_on" value="<?= htmlspecialchars($product['expired_on'] ?? ''); ?>">
                <?php } ?>

            </div>

            <?php if($show_serial){
                $serial_existing = [];
                $serial_in_stock = 0; $serial_sold = 0;
                $serial_list_rows = [];
                $serial_rows = mysqli_query($conn, 'SELECT id, variant_name, serial_no, status, source_type, source_id FROM product_serials WHERE user_id=' . (int)$user_id . ' AND product_id=' . (int)$id . ' ORDER BY id');
                while($serial_rows && ($serial_row = mysqli_fetch_assoc($serial_rows))){
                    $serial_list_rows[] = $serial_row;
                    if($serial_row['source_type'] === 'opening') $serial_existing[$serial_row['variant_name'] === '' ? '__none__' : $serial_row['variant_name']][] = $serial_row['serial_no'];
                    if($serial_row['status'] === 'sold') $serial_sold++; else $serial_in_stock++;
                }
            }
            ?>

            <div class="row">

                <div class="col-md-4">
                    <div class="form-group">
                        <label>Opening Stock</label>
                        <input type="number" step="1" min="0" name="current_stock" class="form-control" value="<?= (int)($product['opening_stock_quantity'] ?? $product['current_stock']); ?>" <?= !$opening_stock_editable ? 'readonly' : '' ?>>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="form-group">
                        <label>Minimum Stock</label>
                        <input type="number" step="1" min="0" name="minimum_stock" class="form-control" value="<?= (int)$product['minimum_stock']; ?>">
                    </div>
                </div>

                <?php if($show_serial){ ?>
            <div class="col-md-4" id="serial-switch-col"><div class="form-group">
                <label class="d-block">Product Serial / Warranty</label>
                <div class="custom-control custom-switch mt-2">
                    <input type="checkbox" class="custom-control-input" id="serial_enabled" name="serial_enabled" value="1" <?= !empty($product['serial_enabled']) ? 'checked' : '' ?>>
                    <label class="custom-control-label" for="serial_enabled"><span class="sr-only">Product Serial / Warranty</span></label>
                </div>
                <small class="text-muted">Serials in stock: <strong><?= $serial_in_stock ?></strong> · sold: <strong><?= $serial_sold ?></strong></small>
            </div>
            </div>
                <?php } ?>

            </div>

            <?php if($show_serial){ ?>
            <!-- Shown only while Product Serial is on: warranty on the left, the serials on its right. -->
            <div class="row <?= empty($product['serial_enabled']) ? 'd-none' : '' ?>" id="opening-serial-wrap">
                <div class="col-md-4">
                    <div class="form-group">
                        <label>Warranty (months)</label>
                        <input type="number" step="1" min="0" max="600" name="warranty_months" class="form-control" value="<?= (int)($product['warranty_months'] ?? 0); ?>">
                        <small class="text-muted">0 = no warranty. Used by Warranty Receive.</small>
                    </div>
                </div>
                <?php if($serial_reentry){ ?>
                <div class="col-md-8">
                    <div class="form-group">
                        <label>Opening Stock Serials <small class="text-muted">(type or scan, then Enter — one per piece)</small></label>
                        <div id="opening-serial-boxes"></div>
                    </div>
                </div>
                <?php }else{ ?>
                <div class="col-md-8">
                    <div class="form-group">
                        <label>Serial / IMEI <small class="text-muted">(sold serials are fixed; in-stock serials can be corrected)</small></label>
                        <?php if(!$serial_list_rows){ ?>
                            <div class="text-muted small">No serials recorded for this product.</div>
                        <?php } ?>
                        <div class="row">
                        <?php foreach($serial_list_rows as $serial_row){ $serial_fixed = $serial_row['status'] !== 'in_stock'; ?>
                            <div class="col-sm-6 mb-2">
                                <div class="input-group input-group-sm">
                                    <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-<?= $serial_fixed ? 'lock text-muted' : 'barcode' ?>"></i></span></div>
                                    <input type="text" class="form-control" maxlength="100" value="<?= htmlspecialchars($serial_row['serial_no'], ENT_QUOTES, 'UTF-8'); ?>"
                                        <?= $serial_fixed ? 'readonly' : 'name="serial_fix[' . (int)$serial_row['id'] . ']" required' ?>>
                                    <div class="input-group-append"><span class="input-group-text <?= $serial_row['status'] === 'sold' ? 'text-danger' : ($serial_fixed ? 'text-warning' : 'text-success') ?>"><?= $serial_row['status'] === 'sold' ? 'Sold' : ($serial_row['status'] === 'damaged' ? 'Damaged' : ($serial_fixed ? 'In transit' : 'In stock')) ?></span></div>
                                </div>
                            </div>
                        <?php } ?>
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>
            <?php if(!$serial_reentry){ ?><script>document.getElementById('serial_enabled').addEventListener('change',function(){document.getElementById('opening-serial-wrap').classList.toggle('d-none',!this.checked);});document.addEventListener('keydown',function(e){if(e.key!=='Enter'||!e.target.name||e.target.name.indexOf('serial_fix[')!==0)return;e.preventDefault();const all=[...document.querySelectorAll('input[name^="serial_fix["]')];const next=all[all.indexOf(e.target)+1];if(next){next.focus();next.select();}});</script><?php } ?>
            <?php } ?>


            <button
                type="submit"
                class="btn btn-primary">

                Update Product

            </button>

        </form>

    </div>

</div>

<script>
(function(){
const category=document.getElementById('category_id'), subCategory=document.getElementById('sub_category'), opening=document.querySelector('[name="current_stock"]'), wrap=document.getElementById('variant-opening-wrap'), fields=document.getElementById('variant-opening-fields');
const fifoEditable=<?= json_encode($opening_stock_editable) ?>;
const savedVariants=<?= json_encode($product_variant_opening, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
function syncCategoryFields(){
  let subCategories=[], variants=[];
  const selected=category.options[category.selectedIndex];
  try{subCategories=JSON.parse(selected?.dataset.subCategories||'[]')}catch(e){}
  try{variants=JSON.parse(selected?.dataset.variants||'[]')}catch(e){}
  const hasSubCategories=subCategories.length>0;
  const current=subCategory.dataset.current||'';
  if(current&&!subCategories.includes(current)) subCategories.unshift(current);
  subCategory.innerHTML='<option value="">'+(subCategories.length?'Select sub category':'No sub category')+'</option>';
  subCategories.forEach(name=>{const option=document.createElement('option');option.value=name;option.textContent=name;option.selected=name===current;subCategory.appendChild(option);});
  subCategory.disabled=subCategories.length===0;
  subCategory.closest('.form-group').classList.toggle('d-none',!hasSubCategories);
  subCategory.dataset.current='';
  fields.innerHTML='';
  wrap.classList.toggle('d-none',variants.length===0);
  opening.disabled=fifoEditable&&variants.length>0;
  opening.readOnly=!fifoEditable;
  const nonStock=selected?.dataset.productType==='non_stock';
  [opening,document.querySelector('[name="minimum_stock"]')].forEach(input=>{if(input){input.closest('.form-group').classList.toggle('d-none',nonStock);if(nonStock)input.value=0;}});
  if(nonStock){wrap.classList.add('d-none');opening.disabled=true;return;}
  if(!variants.length) return;
  const updateTotal=()=>{let total=0;fields.querySelectorAll('input').forEach(input=>{total+=Math.max(0,parseInt(input.value,10)||0);});opening.value=total;};
  variants.forEach(variant=>{const column=document.createElement('div');column.className='col-md-4';const label=document.createElement('label');label.textContent=variant;const input=document.createElement('input');input.type='number';input.min='0';input.step='1';input.className='form-control';input.name='variant_opening_qty['+variant+']';input.value=savedVariants[variant]??'0';input.readOnly=!fifoEditable;input.addEventListener('input',updateTotal);column.append(label,input);fields.append(column);});
  updateTotal();
}
category.addEventListener('change',syncCategoryFields);syncCategoryFields();
})();
(function(){const input=document.getElementById('product_photo'),status=document.getElementById('product-photo-status'),preview=document.getElementById('product-photo-preview');if(!input)return;const original=preview?(preview.getAttribute('src')||''):'';let objectUrl='';input.addEventListener('change',function(){if(status)status.textContent='';if(objectUrl){URL.revokeObjectURL(objectUrl);objectUrl='';}if(!preview)return;const file=input.files&&input.files[0];if(file&&/^image\//.test(file.type)){objectUrl=URL.createObjectURL(file);preview.src=objectUrl;preview.classList.remove('d-none');}else if(original){preview.src=original;preview.classList.remove('d-none');}else{preview.removeAttribute('src');preview.classList.add('d-none');}});})();
</script>

<?php if($variant_photos_enabled) echo eshop_variant_photo_script('#variant-opening-fields input[type=number]', ['^variant_opening_qty', 'variant_photo'], eshop_variant_image_urls($conn, (int)$user_id, (int)$id)); ?>
<?php if($show_serial && $serial_reentry){ echo product_serial_taken_script($conn, (int)$user_id, ["opening", (int)$id]) . product_serial_box_script(); ?>
<script>
(function(){
  const sw=document.getElementById('serial_enabled'); if(!sw) return;
  const wrap=document.getElementById('opening-serial-wrap'), boxes=document.getElementById('opening-serial-boxes');
  const category=document.getElementById('category_id'), opening=document.querySelector('[name="current_stock"]'), fields=document.getElementById('variant-opening-fields');
  const existing=<?= json_encode((object)$serial_existing, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  function targets(){
    const inputs=[...fields.querySelectorAll('input[type=number]')];
    if(inputs.length) return inputs.map(i=>{const key=i.name.replace(/^variant_opening_qty\[(.*)\]$/,'$1');return {key:key,label:key,qty:Math.max(0,parseInt(i.value,10)||0),variant:true};});
    return [{key:'__none__',label:'',qty:Math.max(0,parseInt(opening.value,10)||0),variant:false}];
  }
  function sync(){
    const option=category.options[category.selectedIndex];
    const nonStock=option&&option.dataset.productType==='non_stock';
    const on=sw.checked&&!nonStock;
    wrap.classList.toggle('d-none',!on);
    if(!on){ boxes.innerHTML=''; return; }
    const list=targets(), keep={}; list.forEach(x=>keep[x.key]=1);
    [...boxes.children].forEach(c=>{ if(!keep[c.dataset.key]) c.remove(); });
    list.forEach(x=>{
      let col=[...boxes.children].find(c=>c.dataset.key===x.key);
      if(!col){
        col=document.createElement('div'); col.className='mb-2'; col.dataset.key=x.key;
        if(x.label){ const l=document.createElement('label'); l.className='small mb-1'; l.textContent=x.label; col.append(l); }
        const b=document.createElement('div'); b.className='ps-box'; b.dataset.name='opening_serials['+x.key+']'; b.dataset.value=(existing[x.key]||[]).join('\n'); col.append(b);
        boxes.append(col); window.psBoxInit(col);
      }
      col.classList.toggle('d-none', x.variant && x.qty===0 && !col.querySelector('.ps-tag'));
      window.psBoxSetNeed(col.querySelector('.ps-box'), x.qty);
    });
  }
  sw.addEventListener('change',sync);
  category.addEventListener('change',sync);
  document.getElementById('product-form').addEventListener('input',function(e){ if(e.target.type==='number') sync(); });
  document.addEventListener('DOMContentLoaded',sync);
})();
</script>
<?php } ?>
<?php require_once '../includes/footer.php'; ?>
