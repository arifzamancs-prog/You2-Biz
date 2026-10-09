<?php
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-edit mr-2"></i>Edit Product</h3></div>
    <div class="card-body">
        <?php if(isset($_SESSION['error'])){ ?>
            <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php } ?>

        <form method="post" enctype="multipart/form-data" id="restaurant-product-edit-form">
            <input type="hidden" name="expired_on" value="<?= htmlspecialchars((string)($product['expired_on'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="category_id">Category</label>
                        <select name="category_id" id="category_id" class="form-control" required>
                            <?php while($cat = mysqli_fetch_assoc($categories)){ ?>
                                <option value="<?= (int)$cat['id'] ?>" data-product-type="<?= htmlspecialchars($cat['category_type'], ENT_QUOTES, 'UTF-8') ?>" <?= (int)$product['category_id'] === (int)$cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['category_name']) ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="product_name">Product Name</label>
                        <input type="text" name="product_name" id="product_name" class="form-control" value="<?= htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="product_photo">Product Photo</label>
                        <input type="file" name="product_photo" id="product_photo" accept="image/jpeg,image/png,image/webp" class="form-control-file">
                        <small class="form-text text-muted">Optional. Leave empty to keep the current photo. Max 2 MB.</small>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-4"><div class="form-group"><label for="sku">Code</label><input type="text" name="sku" id="sku" class="form-control" value="<?= htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8') ?>"></div></div>
                <div class="col-md-4 restaurant-stock-field"><div class="form-group"><label for="purchase_price">Purchase Price</label><input type="number" step="0.01" min="0" name="purchase_price" id="purchase_price" class="form-control" value="<?= htmlspecialchars((string)$product['purchase_price'], ENT_QUOTES, 'UTF-8') ?>" <?= !$product_fifo_editable ? 'readonly' : '' ?>></div></div>
                <div class="col-md-4"><div class="form-group"><label for="sale_price">Sale Price</label><input type="number" step="0.01" min="0" name="sale_price" id="sale_price" class="form-control" value="<?= htmlspecialchars((string)$product['sale_price'], ENT_QUOTES, 'UTF-8') ?>"></div></div>
            </div>

            <div class="row">
                <div class="col-md-4 restaurant-stock-field"><div class="form-group"><label for="current_stock">Opening Stock</label><input type="number" step="1" min="0" name="current_stock" id="current_stock" class="form-control" value="<?= (int)($product['opening_stock_quantity'] ?? $product['current_stock']) ?>" <?= !$product_fifo_editable ? 'readonly' : '' ?>></div></div>
                <div class="col-md-4 restaurant-stock-field"><div class="form-group"><label for="minimum_stock">Minimum Stock</label><input type="number" step="1" min="0" name="minimum_stock" id="minimum_stock" class="form-control" value="<?= (int)$product['minimum_stock'] ?>"></div></div>
                <div class="col-md-4"><div class="form-group"><label for="status">Status</label><select name="status" id="status" class="form-control"><option value="active" <?= $product['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $product['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div></div>
            </div>

            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Product</button>
            <a href="index.php" class="btn btn-secondary">Back</a>
        </form>
    </div>
</div>

<script>
(function () {
    const category = document.getElementById('category_id');
    const stockFields = document.querySelectorAll('.restaurant-stock-field');
    const purchase = document.getElementById('purchase_price');
    const opening = document.getElementById('current_stock');
    const minimum = document.getElementById('minimum_stock');

    function syncProductType() {
        const option = category.options[category.selectedIndex];
        const isStock = option && option.dataset.productType === 'stock_product';
        stockFields.forEach(field => field.classList.toggle('d-none', !isStock));
        if (!isStock) {
            purchase.value = 0;
            opening.value = 0;
            minimum.value = 0;
        }
    }

    category.addEventListener('change', syncProductType);
    syncProductType();
})();
</script>

<?php require_once '../includes/footer.php'; ?>
