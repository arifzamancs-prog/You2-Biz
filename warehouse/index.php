<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_expiry_helper.php';

$company_id = (int)$_SESSION['user_id'];
$central = stock_can_manage_warehouse($conn);
$multi_branch = company_multi_branch_enabled($conn, $company_id);
$warehouse_id = stock_warehouse_id($conn, $company_id);
$scope_id = $central ? 0 : selected_branch_id($conn, true);
if (!$central && $scope_id <= 0) { http_response_code(403); exit('Staff branch is not configured.'); }
$message = $_SESSION['stock_distribution_message'] ?? '';
unset($_SESSION['stock_distribution_message']);
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $id = fifo_inventory_distribute($conn, $company_id, (int)($_POST['to_branch_id'] ?? 0), (int)($_POST['product_id'] ?? 0), (float)($_POST['quantity'] ?? 0), (string)($_POST['request_key'] ?? ''), (string)($_POST['note'] ?? ''), (int)($_POST['from_branch_id'] ?? 0));
        $_SESSION['stock_distribution_message'] = 'Stock distributed successfully. Distribution #' . $id;
        header('Location: index.php'); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$branches = mysqli_query($conn, "SELECT id,branch_name,is_head_office FROM branches WHERE user_id={$company_id} AND status='active' ORDER BY CASE WHEN branch_name='Main Warehouse' THEN 0 WHEN is_head_office=1 THEN 1 ELSE 2 END, branch_name");
$scope_sql = $scope_id > 0 ? " AND b.id={$scope_id}" : '';
$stock = mysqli_query($conn, "SELECT p.product_name,p.sku,b.id AS branch_id,b.branch_name,b.is_head_office,
    COALESCE(SUM(sb.remaining_quantity),0) AS quantity,
    COALESCE(SUM(sb.remaining_quantity*sb.unit_cost),0) AS cost,
    (SELECT COALESCE(SUM(ii.quantity),0) FROM invoice_items ii INNER JOIN invoices i ON i.id=ii.invoice_id WHERE i.user_id=p.user_id AND i.branch_id=b.id AND ii.product_id=p.id AND ii.quantity>0 AND i.accounting_status='pending') AS reserved
    FROM products p INNER JOIN product_categories c ON c.id=p.category_id
    INNER JOIN branches b ON b.user_id=p.user_id
    LEFT JOIN stock_batches sb ON sb.product_id=p.id AND sb.user_id=p.user_id AND sb.branch_id=b.id
    WHERE p.user_id={$company_id} AND c.category_type='stock_product' {$scope_sql}
    GROUP BY p.id,p.product_name,p.sku,p.user_id,b.id,b.branch_name,b.is_head_office
    HAVING quantity<>0 OR reserved<>0 OR b.is_head_office=1
    ORDER BY b.is_head_office DESC,b.branch_name,p.product_name");
$history_scope = $scope_id > 0 ? " AND d.to_branch_id={$scope_id}" : '';
$history = mysqli_query($conn, "SELECT d.*,p.product_name,p.sku,fb.branch_name AS from_branch_name,fb.is_head_office AS from_head_office,tb.branch_name AS to_branch_name,tb.is_head_office AS to_head_office FROM stock_distributions d INNER JOIN products p ON p.id=d.product_id AND p.user_id=d.user_id INNER JOIN branches fb ON fb.id=d.from_branch_id AND fb.user_id=d.user_id INNER JOIN branches tb ON tb.id=d.to_branch_id AND tb.user_id=d.user_id WHERE d.user_id={$company_id} {$history_scope} ORDER BY d.id DESC LIMIT 500");
$setup_categories = mysqli_query($conn, "SELECT id,category_name,status FROM product_categories WHERE user_id={$company_id} ORDER BY category_name ASC");
$setup_products = mysqli_query($conn, "SELECT p.id,p.product_name,p.sku,p.purchase_price,p.sale_price,p.status,c.category_name FROM products p LEFT JOIN product_categories c ON c.id=p.category_id WHERE p.user_id={$company_id} ORDER BY p.id DESC");
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<?php if ($central && manager_can_modify()) { ?>
<div class="card card-outline card-primary">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-plus-circle mr-2 text-primary"></i>Product Setup</h3></div>
    <div class="card-body">
        <div id="product-setup-message"></div>
        <button type="button" class="btn btn-outline-primary mr-2" id="show-category-form"><i class="fas fa-tags mr-1"></i>Add Category</button>
        <button type="button" class="btn btn-primary" id="show-product-form"><i class="fas fa-box mr-1"></i>Add Product</button>
        <?php if (is_product_expiry_enabled($conn)) { ?><a href="../products/expired.php" class="btn btn-outline-warning ml-2"><i class="fas fa-clock mr-1"></i>Expired Product</a><?php } ?>
        <form id="category-setup-form" class="border rounded p-3 mt-3 d-none">
            <div class="form-row align-items-end"><div class="form-group col-md-6 mb-md-0"><label>Category Name</label><input class="form-control" name="category_name" maxlength="150" required></div><div class="form-group col-md-3 mb-md-0"><label>Status</label><select class="form-control" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div><div class="col-md-3"><button class="btn btn-primary" type="submit"><i class="fas fa-save mr-1"></i>Save Category</button></div></div>
        </form>
        <form id="product-setup-form" class="border rounded p-3 mt-3 d-none">
            <div class="form-row"><div class="form-group col-md-4"><label>Category</label><select class="form-control setup-category-select" name="category_id" required><option value="">Select Category</option><?php mysqli_data_seek($setup_categories, 0); while ($category = mysqli_fetch_assoc($setup_categories)) { if ($category['status'] === 'active') { ?><option value="<?= (int)$category['id']; ?>"><?= htmlspecialchars($category['category_name']); ?></option><?php } } ?></select></div><div class="form-group col-md-4"><label>Product Name</label><input class="form-control" name="product_name" maxlength="150" required></div><div class="form-group col-md-4"><label>SKU</label><input class="form-control" name="sku" maxlength="100"></div></div>
            <div class="form-row"><div class="form-group col-md-3"><label>Purchase Price</label><input class="form-control" type="number" name="purchase_price" min="0" step="0.01" value="0"></div><div class="form-group col-md-3"><label>Sale Price</label><input class="form-control" type="number" name="sale_price" min="0" step="0.01" value="0"></div><div class="form-group col-md-2"><label>Opening Stock</label><input class="form-control" type="number" name="opening_stock" min="0" step="1" value="0"></div><div class="form-group col-md-2"><label>Minimum Stock</label><input class="form-control" type="number" name="minimum_stock" min="0" step="1" value="5"></div><div class="form-group col-md-2"><label>Status</label><select class="form-control" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div></div>
            <?php if (is_product_expiry_enabled($conn)) { ?><div class="form-group"><label>Expiry on <small class="text-muted">(optional)</small></label><input class="form-control" style="max-width:220px" type="date" name="expired_on"></div><?php } ?>
            <button class="btn btn-primary" type="submit"><i class="fas fa-save mr-1"></i>Save Product</button>
        </form>
        <div class="row mt-4"><div class="col-lg-5"><h6 class="font-weight-bold">Category List</h6><div class="table-responsive"><table class="table table-bordered table-sm mb-0"><thead><tr><th>Category</th><th>Status</th></tr></thead><tbody id="setup-category-list"><?php mysqli_data_seek($setup_categories, 0); while ($category = mysqli_fetch_assoc($setup_categories)) { ?><tr><td><?= htmlspecialchars($category['category_name']); ?></td><td><span class="badge badge-<?= $category['status'] === 'active' ? 'success' : 'secondary'; ?>"><?= htmlspecialchars(ucfirst($category['status'])); ?></span></td></tr><?php } ?></tbody></table></div></div><div class="col-lg-7 mt-3 mt-lg-0"><h6 class="font-weight-bold">Product List</h6><div class="table-responsive"><table class="table table-bordered table-sm mb-0"><thead><tr><th>Product</th><th>Category</th><th>SKU</th><th>Purchase</th><th>Sale</th><th>Status</th></tr></thead><tbody id="setup-product-list"><?php while ($product = mysqli_fetch_assoc($setup_products)) { ?><tr><td><?= htmlspecialchars($product['product_name']); ?></td><td><?= htmlspecialchars($product['category_name'] ?? '-'); ?></td><td><?= htmlspecialchars($product['sku'] ?? ''); ?></td><td><?= number_format((float)$product['purchase_price'], 2); ?></td><td><?= number_format((float)$product['sale_price'], 2); ?></td><td><span class="badge badge-<?= $product['status'] === 'active' ? 'success' : 'secondary'; ?>"><?= htmlspecialchars(ucfirst($product['status'])); ?></span></td></tr><?php } ?></tbody></table></div></div></div>
    </div>
</div>
<?php } ?>
<div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-warehouse mr-2"></i><?= $central ? 'Main Warehouse' : 'Branch Stock'; ?></h3></div>
    <div class="card-body">
        <?php if ($message !== '') { ?><div class="alert alert-success"><?= htmlspecialchars($message); ?></div><?php } ?>
        <?php if ($error !== '') { ?><div class="alert alert-danger"><?= htmlspecialchars($error); ?></div><?php } ?>
        <div class="table-responsive"><table class="table table-bordered table-striped">
            <thead><tr><th>Product</th><th>SKU</th><th>Location</th><th>On Hand</th><th>Reserved</th><th>Stock Cost</th></tr></thead>
            <tbody><?php while ($row = mysqli_fetch_assoc($stock)) { ?><tr>
                <td><?= htmlspecialchars($row['product_name']); ?></td><td><?= htmlspecialchars($row['sku'] ?? ''); ?></td>
                <td><?= htmlspecialchars((int)$row['branch_id'] === $warehouse_id ? 'Main Warehouse' : $row['branch_name']); ?></td>
                <td><?= number_format($row['quantity'],0); ?></td><td><?= number_format($row['reserved'],0); ?></td><td><?= number_format($row['cost'],2); ?></td>
            </tr><?php } ?></tbody>
        </table></div>
    </div>
</div>
<?php if ($central && $multi_branch) { ?>
<div class="card card-outline card-primary">
    <div class="card-header"><h3 class="card-title">Stock Distribution</h3></div>
    <div class="card-body"><form method="post">
        <input type="hidden" name="stock_csrf" value="<?= htmlspecialchars(stock_csrf_token()); ?>">
        <input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(24)); ?>">
        <div class="form-row">
            <div class="form-group col-md-3"><label for="distribution-from">From</label><select id="distribution-from" name="from_branch_id" class="form-control" required><?php mysqli_data_seek($branches, 0); while ($branch = mysqli_fetch_assoc($branches)) { ?><option value="<?= (int)$branch['id']; ?>"><?= htmlspecialchars($branch['is_head_office'] ? 'Head Office' : $branch['branch_name']); ?></option><?php } ?></select></div>
            <div class="form-group col-md-3"><label for="distribution-product">Product / Available</label><select id="distribution-product" name="product_id" class="form-control" required><option value="">Select product</option></select></div>
            <div class="form-group col-md-2"><label for="distribution-branch">To</label><select id="distribution-branch" name="to_branch_id" class="form-control" required><option value="">Select location</option><?php mysqli_data_seek($branches, 0); while ($branch = mysqli_fetch_assoc($branches)) { ?><option value="<?= (int)$branch['id']; ?>"><?= htmlspecialchars($branch['is_head_office'] ? 'Head Office' : $branch['branch_name']); ?></option><?php } ?></select></div>
            <div class="form-group col-md-2"><label for="distribution-quantity">Quantity</label><input id="distribution-quantity" class="form-control" type="number" name="quantity" min="1" step="1" required></div>
            <div class="form-group col-md-3"><label for="distribution-note">Note</label><input id="distribution-note" class="form-control" name="note" maxlength="500"></div>
        </div>
        <button class="btn btn-primary" type="submit"><i class="fas fa-truck-loading mr-1"></i> Transfer Stock</button>
    </form></div>
</div>
<?php } ?>
<div class="card"><div class="card-header"><h3 class="card-title">Distribution History</h3></div><div class="card-body">
<div class="table-responsive"><table class="table table-bordered table-striped"><thead><tr><th style="width:13%">Reference</th><th style="width:10%">Date</th><th style="width:8%">Product</th><th style="width:8%">SKU</th><th style="width:13%">From Branch</th><th style="width:13%">To Branch</th><th style="width:8%">Quantity</th><th style="width:10%">Cost</th><th style="width:17%">Note</th></tr></thead><tbody>
<?php while ($row = mysqli_fetch_assoc($history)) { ?><tr><td><?= 'D-' . date('dmy', strtotime($row['created_at'])) . (int)$row['id']; ?></td><td><?= htmlspecialchars(app_date($row['created_at'])); ?></td><td><?= htmlspecialchars($row['product_name']); ?></td><td><?= htmlspecialchars($row['sku'] ?? ''); ?></td><td><?= htmlspecialchars($row['from_head_office'] ? 'Head Office' : $row['from_branch_name']); ?></td><td><?= htmlspecialchars($row['to_head_office'] ? 'Head Office' : $row['to_branch_name']); ?></td><td><?= number_format($row['quantity'],0); ?></td><td><?= number_format($row['total_cost'],2); ?></td><td><?= htmlspecialchars($row['note']); ?></td></tr><?php } ?>
</tbody></table></div><small class="text-muted">Latest 500 distributions.</small></div></div>
<script>
const setupCsrf = <?= json_encode(stock_csrf_token()); ?>;
const setupMessage = document.getElementById('product-setup-message');
const categorySetupForm = document.getElementById('category-setup-form');
const productSetupForm = document.getElementById('product-setup-form');
const escapeSetupHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[character]));

function showSetupMessage(message, ok) {
    if (setupMessage) setupMessage.innerHTML = '<div class="alert alert-' + (ok ? 'success' : 'danger') + ' py-2 mt-3 mb-0">' + escapeSetupHtml(message) + '</div>';
}
async function submitSetup(form, action) {
    const formData = new FormData(form);
    formData.append('action', action);
    formData.append('csrf', setupCsrf);
    const response = await fetch('product_setup_ajax.php', { method: 'POST', body: formData });
    const result = await response.json();
    if (!result.ok) throw new Error(result.message || 'Could not save.');
    return result;
}
document.getElementById('show-category-form')?.addEventListener('click', () => categorySetupForm.classList.toggle('d-none'));
document.getElementById('show-product-form')?.addEventListener('click', () => productSetupForm.classList.toggle('d-none'));
categorySetupForm?.addEventListener('submit', async event => {
    event.preventDefault();
    try {
        const result = await submitSetup(categorySetupForm, 'add_category');
        const category = result.category;
        document.getElementById('setup-category-list').insertAdjacentHTML('afterbegin', '<tr><td>' + escapeSetupHtml(category.name) + '</td><td><span class="badge badge-' + (category.status === 'active' ? 'success' : 'secondary') + '">' + escapeSetupHtml(category.status[0].toUpperCase() + category.status.slice(1)) + '</span></td></tr>');
        if (category.status === 'active') document.querySelectorAll('.setup-category-select').forEach(select => select.add(new Option(category.name, category.id)));
        categorySetupForm.reset(); categorySetupForm.classList.add('d-none'); showSetupMessage(result.message, true);
    } catch (error) { showSetupMessage(error.message, false); }
});
productSetupForm?.addEventListener('submit', async event => {
    event.preventDefault();
    try {
        const result = await submitSetup(productSetupForm, 'add_product');
        const product = result.product;
        document.getElementById('setup-product-list').insertAdjacentHTML('afterbegin', '<tr><td>' + escapeSetupHtml(product.name) + '</td><td>' + escapeSetupHtml(product.category) + '</td><td>' + escapeSetupHtml(product.sku) + '</td><td>' + escapeSetupHtml(product.purchase_price) + '</td><td>' + escapeSetupHtml(product.sale_price) + '</td><td><span class="badge badge-' + (product.status === 'active' ? 'success' : 'secondary') + '">' + escapeSetupHtml(product.status[0].toUpperCase() + product.status.slice(1)) + '</span></td></tr>');
        productSetupForm.reset(); productSetupForm.classList.add('d-none'); showSetupMessage(result.message, true);
    } catch (error) { showSetupMessage(error.message, false); }
});

const fromLocation = document.getElementById('distribution-from');
const toLocation = document.getElementById('distribution-branch');
const productSelect = document.getElementById('distribution-product');
const quantityInput = document.getElementById('distribution-quantity');
if (window.jQuery && $.fn.select2) {
    $('#distribution-product').select2({theme: 'bootstrap4', width: '100%', placeholder: 'Search product name or SKU', allowClear: true});
}

function syncDestination() {
    [...toLocation.options].forEach(option => {
        option.hidden = option.value !== '' && option.value === fromLocation.value;
        option.disabled = option.hidden;
    });
    if (toLocation.value === fromLocation.value) toLocation.value = '';
}

function loadSourceProducts() {
    productSelect.innerHTML = '<option value="">Select product</option>';
    quantityInput.value = '';
    quantityInput.max = '';
    if (!fromLocation.value) return;
    fetch('get_branch_products.php?branch_id=' + encodeURIComponent(fromLocation.value))
        .then(response => response.ok ? response.json() : [])
        .then(products => {
            products.forEach(product => {
                const option = new Option(product.name + ' — ' + Number(product.available).toLocaleString(), product.id);
                option.dataset.available = product.available;
                productSelect.add(option);
            });
            if (window.jQuery && $.fn.select2) $(productSelect).trigger('change.select2');
        });
}

fromLocation?.addEventListener('change', () => { syncDestination(); loadSourceProducts(); });
productSelect?.addEventListener('change', function () { quantityInput.max = this.selectedOptions[0]?.dataset.available || ''; });
syncDestination();
loadSourceProducts();
</script>
<?php require_once '../includes/footer.php'; ?>
