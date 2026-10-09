<?php
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-plus-circle mr-2"></i>Add Product</h3>
    </div>

    <div class="card-body">
        <?php if($message){ ?>
            <div class="alert alert-<?= htmlspecialchars($message_type) ?>"><?= htmlspecialchars($message) ?></div>
        <?php } ?>

        <form method="post" enctype="multipart/form-data" id="restaurant-product-form">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="category_id">Category</label>
                        <select name="category_id" id="category_id" class="form-control" required>
                            <?php while($cat = mysqli_fetch_assoc($categories)){ ?>
                                <option value="<?= (int)$cat['id'] ?>" data-product-type="<?= htmlspecialchars($cat['category_type'], ENT_QUOTES, 'UTF-8') ?>" <?= (string)$cat['id'] === (string)($form_data['category_id'] ?? '') ? 'selected' : '' ?>><?= htmlspecialchars($cat['category_name']) ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="product_name">Product Name</label>
                        <input type="text" name="product_name" id="product_name" class="form-control" value="<?= htmlspecialchars((string)($form_data['product_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="product_photo">Product Photo</label>
                        <input type="file" name="product_photo" id="product_photo" accept="image/jpeg,image/png,image/webp" class="form-control-file">
                        <small class="form-text text-muted">Max 2 MB. JPG, PNG, or WEBP images are resized automatically.</small>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label for="sku">Code</label>
                        <input type="text" name="sku" id="sku" class="form-control" value="<?= htmlspecialchars((string)($form_data['sku'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
                <div class="col-md-4 restaurant-stock-field">
                    <div class="form-group">
                        <label for="purchase_price">Purchase Price</label>
                        <input type="number" step="0.01" min="0" name="purchase_price" id="purchase_price" class="form-control" value="<?= htmlspecialchars((string)($form_data['purchase_price'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label for="sale_price">Sale Price</label>
                        <input type="number" step="0.01" min="0" name="sale_price" id="sale_price" class="form-control" value="<?= htmlspecialchars((string)($form_data['sale_price'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-4 restaurant-stock-field">
                    <div class="form-group">
                        <label for="current_stock">Opening Stock</label>
                        <input type="number" step="1" min="0" name="current_stock" id="current_stock" class="form-control" value="<?= htmlspecialchars((string)($form_data['current_stock'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
                <div class="col-md-4 restaurant-stock-field">
                    <div class="form-group">
                        <label for="minimum_stock">Minimum Stock</label>
                        <input type="number" step="1" min="0" name="minimum_stock" id="minimum_stock" class="form-control" value="<?= htmlspecialchars((string)($form_data['minimum_stock'] ?? '5'), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select name="status" id="status" class="form-control">
                            <option value="active" <?= ($form_data['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= ($form_data['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Product</button>
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
