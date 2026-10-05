<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_expiry_helper.php';
require_once '../includes/product_image_helper.php';
require_once '../includes/distribution_display_helper.php';
require_once '../includes/project_package_helper.php';

$company_id = (int)$_SESSION['user_id'];
ensure_product_image_column($conn);
$central = stock_can_manage_warehouse($conn);
$multi_branch = company_multi_branch_enabled($conn, $company_id);
$is_fashion_house = project_package_company_type($conn, $company_id) === 'Fashion house';
$pending_damaged_return_count = 0;
if ($central && $multi_branch && $is_fashion_house) {
    $pending_damage_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM stock_damage_returns WHERE user_id={$company_id} AND status='pending'");
    $pending_damaged_return_count = (int)(mysqli_fetch_assoc($pending_damage_result)['total'] ?? 0);
}
$warehouse_id = $multi_branch ? stock_warehouse_id($conn, $company_id) : stock_head_office_id($conn, $company_id);
$pending_warehouse_receipts = 0;
if ($central && $multi_branch) {
    $receipt_count = mysqli_query($conn, "SELECT COUNT(DISTINCT COALESCE(transfer_group, CONCAT('legacy-',id))) AS total FROM stock_distributions WHERE user_id={$company_id} AND to_branch_id={$warehouse_id} AND from_branch_id<>{$warehouse_id} AND status='pending'");
    $pending_warehouse_receipts = (int)(mysqli_fetch_assoc($receipt_count)['total'] ?? 0);
}
$scope_id = $central ? 0 : selected_branch_id($conn, true);
if (!$central && $scope_id <= 0) { http_response_code(403); exit('Staff branch is not configured.'); }
$message = $_SESSION['stock_distribution_message'] ?? '';
unset($_SESSION['stock_distribution_message']);
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'receive_at_warehouse') {
    try {
        if (!$central || !$multi_branch) throw new RuntimeException('Warehouse receipt permission is required.');
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['distribution_ids'] ?? ''))))));
        if (!$ids) throw new RuntimeException('Invalid warehouse receipt request.');
        foreach ($ids as $id) {
            $check = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM stock_distributions WHERE id={$id} AND user_id={$company_id} AND to_branch_id={$warehouse_id} AND status='pending'"));
            if (!$check) throw new RuntimeException('This transfer is no longer pending at Main Warehouse.');
        }
        foreach ($ids as $id) fifo_inventory_receive_distribution($conn, $company_id, $id, 0, $warehouse_id);
        $_SESSION['stock_distribution_message'] = 'Stock received at Main Warehouse successfully.';
        header('Location: index.php'); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') !== 'receive_at_warehouse') {
    $distribution_transaction = false;
    try {
        $base_request_key = (string)($_POST['request_key'] ?? '');
        $product_ids = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $variant_quantities = $_POST['variant_quantity'] ?? [];
        $variant_names = $_POST['variant_name'] ?? [];
        if(!is_array($product_ids) || !preg_match('/^[a-f0-9]{32,64}$/', $base_request_key)) throw new RuntimeException('Invalid distribution request.');
        $distribution_ids = [];
        mysqli_begin_transaction($conn);
        $distribution_transaction = true;
        foreach($product_ids as $line_index => $product_id){
            $product_id = (int)$product_id;
            if($product_id <= 0) continue;
            $line_variants = $variant_quantities[$line_index] ?? [];
            if(is_array($line_variants) && $line_variants){
                foreach($line_variants as $variant_index => $quantity){
                    $variant_name = (string)($variant_names[$line_index][$variant_index] ?? '');
                    $quantity = (float)$quantity;
                    if($quantity <= 0) continue;
                    $request_key = substr(hash('sha256', $base_request_key . '|' . $line_index . '|' . $variant_name), 0, 48);
                    $distribution_ids[] = fifo_inventory_distribute($conn, $company_id, (int)($_POST['to_branch_id'] ?? 0), $product_id, $quantity, $request_key, (string)($_POST['note'] ?? ''), (int)($_POST['from_branch_id'] ?? 0), (string)$variant_name, $base_request_key, false);
                }
            }else{
                $quantity = (float)($quantities[$line_index] ?? 0);
                if($quantity <= 0) continue;
                $request_key = substr(hash('sha256', $base_request_key . '|' . $line_index), 0, 48);
                $distribution_ids[] = fifo_inventory_distribute($conn, $company_id, (int)($_POST['to_branch_id'] ?? 0), $product_id, $quantity, $request_key, (string)($_POST['note'] ?? ''), (int)($_POST['from_branch_id'] ?? 0), '', $base_request_key, false);
            }
        }
        if(empty($distribution_ids)){ throw new RuntimeException('Add a product and enter at least one quantity.'); }
        mysqli_commit($conn);
        $distribution_transaction = false;
        $_SESSION['stock_distribution_message'] = 'Stock distributed successfully in one voucher. Distribution #' . (int)$distribution_ids[0];
        header('Location: index.php'); exit;
    } catch (Throwable $e) {
        if ($distribution_transaction) mysqli_rollback($conn);
        $error = $e->getMessage();
    }
}
$branches = mysqli_query($conn, "SELECT id,branch_name,branch_code,is_head_office FROM branches WHERE user_id={$company_id} AND status='active' ORDER BY CASE WHEN branch_name='Main Warehouse' THEN 0 WHEN is_head_office=1 THEN 1 ELSE 2 END, branch_name");
$stock_location_id = $central ? $warehouse_id : $scope_id;
$scope_sql = " AND b.id={$stock_location_id}";
$stock = mysqli_query($conn, "SELECT p.id AS product_id,p.product_name,p.sku,p.photo_path,p.sale_price,b.id AS branch_id,b.branch_name,b.is_head_office,
    COALESCE(SUM(sb.remaining_quantity),0) AS quantity,
    COALESCE(SUM(sb.remaining_quantity*sb.unit_cost),0) AS cost,
    GREATEST(0,
        (SELECT COALESCE(SUM(d.damaged_quantity),0) FROM stock_distributions d WHERE d.user_id=p.user_id AND d.product_id=p.id AND d.to_branch_id=b.id AND d.status='accepted')
        - (SELECT COALESCE(SUM(dr.quantity),0) FROM stock_damage_returns dr WHERE dr.user_id=p.user_id AND dr.product_id=p.id AND dr.branch_id=b.id AND dr.status IN ('accepted','dump_pending','dumped'))
        + (SELECT COALESCE(SUM(dr.quantity),0) FROM stock_damage_returns dr WHERE dr.user_id=p.user_id AND dr.product_id=p.id AND dr.status IN ('accepted','dump_pending') AND b.id={$warehouse_id})
    ) AS damaged_quantity,
    (SELECT COALESCE(SUM(ii.quantity),0) FROM invoice_items ii INNER JOIN invoices i ON i.id=ii.invoice_id WHERE i.user_id=p.user_id AND i.branch_id=b.id AND ii.product_id=p.id AND ii.quantity>0 AND i.accounting_status='pending') AS reserved
    FROM products p INNER JOIN product_categories c ON c.id=p.category_id
    INNER JOIN branches b ON b.user_id=p.user_id
    LEFT JOIN stock_batches sb ON sb.product_id=p.id AND sb.user_id=p.user_id AND sb.branch_id=b.id
    WHERE p.user_id={$company_id} AND c.category_type='stock_product' {$scope_sql}
    GROUP BY p.id,p.product_name,p.sku,p.photo_path,p.sale_price,p.user_id,b.id,b.branch_name,b.is_head_office
    HAVING quantity<>0 OR reserved<>0
    ORDER BY b.is_head_office DESC,b.branch_name,p.product_name");
$history_scope = $scope_id > 0 ? " AND d.to_branch_id={$scope_id}" : '';
$history = mysqli_query($conn, "SELECT MIN(d.id) AS id,MIN(d.reference_no) AS reference_no,GROUP_CONCAT(d.distribution_ids ORDER BY d.id) AS distribution_ids,MIN(d.created_at) AS created_at,d.user_id,d.from_branch_id,d.to_branch_id,d.status,d.note,
    SUM(d.quantity) AS quantity,SUM(d.total_cost) AS total_cost,SUM(d.damaged_quantity) AS damaged_quantity,
    GROUP_CONCAT(JSON_ARRAY(p.product_name,d.variant_summary) ORDER BY d.id SEPARATOR ',') AS product_summary,
    GROUP_CONCAT(DISTINCT p.sku ORDER BY p.sku SEPARATOR ', ') AS sku_summary,
    fb.branch_code AS from_branch_code,tb.branch_code AS to_branch_code,fb.branch_name AS from_branch_name,fb.is_head_office AS from_head_office,tb.branch_name AS to_branch_name,tb.is_head_office AS to_head_office
    FROM (
        SELECT MIN(id) AS id,MAX(id) AS last_id,MIN(reference_no) AS reference_no,GROUP_CONCAT(id ORDER BY id) AS distribution_ids,
            MIN(created_at) AS created_at,user_id,from_branch_id,to_branch_id,status,note,product_id,
            COALESCE(transfer_group,CONCAT('legacy-',created_at)) AS voucher_key,
            SUM(quantity) AS quantity,SUM(total_cost) AS total_cost,SUM(damaged_quantity) AS damaged_quantity,
            GROUP_CONCAT(CASE WHEN COALESCE(variant_name,'')<>'' THEN CONCAT(variant_name,': ',FORMAT(quantity,0)) ELSE NULL END ORDER BY id SEPARATOR ' || ') AS variant_summary
        FROM stock_distributions
        WHERE user_id={$company_id}
        GROUP BY user_id,from_branch_id,to_branch_id,status,note,product_id,COALESCE(transfer_group,CONCAT('legacy-',created_at))
    ) d
    INNER JOIN products p ON p.id=d.product_id AND p.user_id=d.user_id
    INNER JOIN branches fb ON fb.id=d.from_branch_id AND fb.user_id=d.user_id
    INNER JOIN branches tb ON tb.id=d.to_branch_id AND tb.user_id=d.user_id
    WHERE d.user_id={$company_id} {$history_scope}
    GROUP BY d.voucher_key,d.user_id,d.from_branch_id,d.to_branch_id,d.status,d.note,fb.branch_code,tb.branch_code,fb.branch_name,fb.is_head_office,tb.branch_name,tb.is_head_office
    ORDER BY MAX(d.last_id) DESC LIMIT 500");
$setup_categories = mysqli_query($conn, "SELECT id,category_name,status FROM product_categories WHERE user_id={$company_id} ORDER BY category_name ASC");
$product_deep_search = trim((string)($_GET['product_deep_search'] ?? ''));
$product_load_all = ($_GET['product_load'] ?? '') === 'all';
$product_search_sql = $product_deep_search !== '' ? ' AND (p.product_name LIKE ? OR p.sku LIKE ? OR c.category_name LIKE ? OR p.sub_category LIKE ?)' : '';
$product_limit_sql = ($product_load_all || $product_deep_search !== '') ? '' : ' LIMIT 300';
$setup_product_total = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM products WHERE user_id={$company_id}"))['total'];
$setup_product_stmt = mysqli_prepare($conn, "SELECT p.id,p.product_name,p.sku,p.photo_path,p.purchase_price,p.sale_price,p.status,c.category_name,
    (SELECT COALESCE(SUM(sb.remaining_quantity),0) FROM stock_batches sb WHERE sb.user_id=p.user_id AND sb.product_id=p.id AND sb.branch_id={$warehouse_id}) AS warehouse_stock,
    (SELECT COALESCE(SUM(sb.remaining_quantity),0) FROM stock_batches sb WHERE sb.user_id=p.user_id AND sb.product_id=p.id) AS total_stock,
    (SELECT COALESCE(SUM(sb.remaining_quantity),0) FROM stock_batches sb WHERE sb.user_id=p.user_id AND sb.product_id=p.id AND sb.branch_id<>{$warehouse_id}) AS all_branch_stock,
    (SELECT COALESCE(SUM(ii.quantity),0) FROM invoice_items ii INNER JOIN invoices i ON i.id=ii.invoice_id WHERE i.user_id=p.user_id AND ii.product_id=p.id AND i.accounting_status='posted') AS all_branch_sold,
    GREATEST(0,
        (SELECT COALESCE(SUM(d.damaged_quantity),0) FROM stock_distributions d WHERE d.user_id=p.user_id AND d.product_id=p.id AND d.status='accepted')
        - (SELECT COALESCE(SUM(dr.quantity),0) FROM stock_damage_returns dr WHERE dr.user_id=p.user_id AND dr.product_id=p.id AND dr.status IN ('accepted','dump_pending','dumped'))
    ) AS damaged_quantity
    FROM products p LEFT JOIN product_categories c ON c.id=p.category_id WHERE p.user_id={$company_id}{$product_search_sql} ORDER BY p.id DESC{$product_limit_sql}");
if ($product_deep_search !== '') {
    $product_search_like = '%' . $product_deep_search . '%';
    mysqli_stmt_bind_param($setup_product_stmt, 'ssss', $product_search_like, $product_search_like, $product_search_like, $product_search_like);
}
mysqli_stmt_execute($setup_product_stmt);
$setup_products = mysqli_stmt_get_result($setup_product_stmt);
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<?php if ($central && $multi_branch && $pending_warehouse_receipts > 0) { ?>
<div class="mb-3"><a href="receive_stock.php" class="btn btn-primary"><i class="fas fa-truck-loading mr-1"></i>Product Receive Request <span class="badge badge-light ml-2"><?= $pending_warehouse_receipts; ?></span></a></div>
<?php } ?>
<?php if ($central && $multi_branch && $is_fashion_house && $pending_damaged_return_count > 0) { ?>
<div class="mb-3"><a class="btn btn-outline-danger position-relative" href="damaged_returns.php"><i class="fas fa-exclamation-triangle mr-1"></i>Damaged Product Returns<?php if($pending_damaged_return_count > 0){ ?> <span class="badge badge-danger ml-1"><?= $pending_damaged_return_count ?></span><?php } ?></a></div>
<?php } ?>
<?php if ($central && manager_can_modify()) { ?>
<div class="card card-outline card-primary">
    <div class="card-header"><h3 class="card-title"><i class="fas <?= $is_fashion_house ? 'fa-boxes' : 'fa-plus-circle mr-2 text-primary'; ?> mr-2"></i><?= $is_fashion_house ? 'Product List' : 'Product Setup'; ?></h3></div>
    <div class="card-body">
        <?php if(!$is_fashion_house){ ?>
        <div id="product-setup-message"></div>
        <button type="button" class="btn btn-primary" id="show-product-form"><i class="fas fa-box mr-1"></i>Add Product</button>
        <?php if (is_product_expiry_enabled($conn)) { ?><a href="../products/expired.php" class="btn btn-outline-warning ml-2"><i class="fas fa-clock mr-1"></i>Expired Product</a><?php } ?>
        <form id="product-setup-form" class="border rounded p-3 mt-3 d-none">
            <div class="form-row"><div class="form-group col-md-4"><label>Category</label><select class="form-control setup-category-select" name="category_id" required><option value="">Select Category</option><?php mysqli_data_seek($setup_categories, 0); while ($category = mysqli_fetch_assoc($setup_categories)) { if ($category['status'] === 'active') { ?><option value="<?= (int)$category['id']; ?>"><?= htmlspecialchars($category['category_name']); ?></option><?php } } ?></select></div><div class="form-group col-md-4"><label>Product Name</label><input class="form-control" name="product_name" maxlength="150" required></div><div class="form-group col-md-4"><label>Code</label><input class="form-control" name="sku" maxlength="100"></div></div>
            <div class="form-row"><div class="form-group col-md-3"><label>Purchase Price</label><input class="form-control" type="number" name="purchase_price" min="0" step="0.01" value="0"></div><div class="form-group col-md-3"><label>Sale Price</label><input class="form-control" type="number" name="sale_price" min="0" step="0.01" value="0"></div><div class="form-group col-md-2"><label>Opening Stock</label><input class="form-control" type="number" name="opening_stock" min="0" step="1" value="0"></div><div class="form-group col-md-2"><label>Minimum Stock</label><input class="form-control" type="number" name="minimum_stock" min="0" step="1" value="5"></div><div class="form-group col-md-2"><label>Status</label><select class="form-control" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div></div>
            <?php if (is_product_expiry_enabled($conn)) { ?><div class="form-group"><label>Expiry on <small class="text-muted">(optional)</small></label><input class="form-control" style="max-width:220px" type="date" name="expired_on"></div><?php } ?>
            <button class="btn btn-primary" type="submit"><i class="fas fa-save mr-1"></i>Save Product</button>
        </form>
        <?php } ?>
<form method="get" class="form-row mb-3">
    <div class="col-md-6"><label for="product-deep-search">Deep Search</label><div class="input-group"><input id="product-deep-search" name="product_deep_search" class="form-control" placeholder="Search all products" value="<?= htmlspecialchars($product_deep_search) ?>"><div class="input-group-append"><button class="btn btn-primary">Search</button></div></div></div>
    <div class="col-md-6 d-flex align-items-end"><a href="?product_load=all" class="btn btn-info mr-2">Total Load (<?= $setup_product_total ?>)</a><a href="index.php" class="btn btn-secondary">Reset</a></div>
</form>
<p class="text-muted">Loaded <?= mysqli_num_rows($setup_products) ?> of <?= $setup_product_total ?> products.</p>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <label class="mb-0">Show <select id="setup-product-length" class="custom-select custom-select-sm mx-1" style="width:auto"><option value="50">50</option><option value="100">100</option><option value="300">300</option><option value="-1">All loaded</option></select> entries</label>
    <div class="d-flex align-items-center"><label for="product-list-search" class="mb-0 mr-2">Quick Search</label><input id="product-list-search" class="form-control form-control-sm" style="width:280px;max-width:100%" placeholder="Search loaded products"></div>
</div>
<div class="<?= $is_fashion_house ? '' : 'mt-4'; ?>"><?php if(!$is_fashion_house){ ?><h6 class="font-weight-bold">Product List</h6><?php } ?><div class="table-responsive"><table class="table table-bordered table-sm mb-0"<?php if($is_fashion_house){ ?> style="table-layout:fixed;min-width:1280px"<?php } ?>><?php if($is_fashion_house){ ?><colgroup><col style="width:50px"><col style="width:6%"><col style="width:14%"><col style="width:8%"><col style="width:7%"><col style="width:9%"><col style="width:8%"><col style="width:9%"><col style="width:10%"><col style="width:10%"><col style="width:9%"><col style="width:11%"><col style="width:7%"></colgroup><?php } ?><thead><tr><th>SL</th><th>Photo</th><th>Product</th><th>Category</th><th>Code</th><th>Purchase Price</th><th>Sale Price</th><?php if($is_fashion_house){ ?><th>On Hand</th><th>Warehouse St.</th><th>All Br. Stock</th><th>All Br. Sold</th><th>All Br. Damaged</th><?php } ?><th>Status</th></tr></thead><tbody id="setup-product-list"><?php $setup_sl=0; while ($product = mysqli_fetch_assoc($setup_products)) { $setup_photo = product_image_url($conn, $product['photo_path'] ?? ''); ?><tr><td class="setup-sl"><?= ++$setup_sl ?></td><td><button type="button" class="warehouse-photo-preview" data-photo-src="<?= htmlspecialchars($setup_photo, ENT_QUOTES, 'UTF-8'); ?>" data-photo-alt="<?= htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8'); ?>" title="Click to view larger photo"><img src="<?=htmlspecialchars($setup_photo)?>" style="width:34px;height:34px;object-fit:cover;border-radius:3px" alt="<?= htmlspecialchars($product['product_name']); ?>"></button></td><td><?= htmlspecialchars($product['product_name']); ?></td><td><?= htmlspecialchars($product['category_name'] ?? '-'); ?></td><td><?= htmlspecialchars($product['sku'] ?? ''); ?></td><td><?= number_format((float)$product['purchase_price'], 2); ?></td><td><?= number_format((float)$product['sale_price'], 2); ?></td><?php if($is_fashion_house){ ?><td><?= number_format((float)$product['total_stock'], 0); ?></td><td><?= number_format((float)$product['warehouse_stock'], 0); ?></td><td><?= number_format((float)$product['all_branch_stock'], 0); ?></td><td><?= number_format((float)$product['all_branch_sold'], 0); ?></td><td><?= number_format((float)$product['damaged_quantity'], 0); ?></td><?php } ?><td><span class="badge badge-<?= $product['status'] === 'active' ? 'success' : 'secondary'; ?>"><?= htmlspecialchars(ucfirst($product['status'])); ?></span></td></tr><?php } ?></tbody></table></div></div>
<div class="d-flex justify-content-between align-items-center mt-3"><small id="setup-product-info" role="status"></small><div><button type="button" id="setup-product-prev" class="btn btn-sm btn-outline-secondary">Previous</button> <button type="button" id="setup-product-next" class="btn btn-sm btn-outline-secondary">Next</button></div></div>
    </div>
</div>
<?php } ?>
<div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-warehouse mr-2"></i><?= $central ? ($multi_branch ? 'Main Warehouse' : 'Stock') : 'Branch Stock'; ?></h3><div class="card-tools"><div class="input-group input-group-sm" style="width:260px"><div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div><input id="warehouse-stock-search" class="form-control" placeholder="Search product or Code" aria-label="Search warehouse stock"></div></div></div>
    <div class="card-body">
        <?php if ($message !== '') { ?><div class="alert alert-success"><?= htmlspecialchars($message); ?></div><?php } ?>
        <?php if ($error !== '') { ?><div class="alert alert-danger"><?= htmlspecialchars($error); ?></div><?php } ?>
        <div class="table-responsive"><table class="table table-bordered table-striped" id="warehouse-stock-table">
            <thead><tr><th>Photo</th><th>Product</th><th>Code</th><th>On Hand</th><th>Damaged</th><th>Stock Cost</th><th>Sale Cost</th></tr></thead>
            <tbody><?php while ($row = mysqli_fetch_assoc($stock)) {
                $variant_stmt = mysqli_prepare($conn, "SELECT variant_name,COALESCE(SUM(remaining_quantity),0) AS quantity FROM stock_batches WHERE user_id=? AND product_id=? AND branch_id=? AND variant_name<>'' GROUP BY variant_name ORDER BY variant_name");
                mysqli_stmt_bind_param($variant_stmt, 'iii', $company_id, $row['product_id'], $row['branch_id']);
                mysqli_stmt_execute($variant_stmt); $variant_result = mysqli_stmt_get_result($variant_stmt); $variant_parts = [];
                $variant_quantities = [];
                while($variant = mysqli_fetch_assoc($variant_result)){
                    $variant_quantities[$variant['variant_name']] = $variant['quantity'];
                }
                mysqli_stmt_close($variant_stmt);
                $variant_order = array_unique(array_merge(product_variant_names($conn, (int)$row['product_id'], $company_id), array_keys($variant_quantities)));
                foreach($variant_order as $variant_name){
                    if(array_key_exists($variant_name, $variant_quantities)){
                        $variant_parts[] = htmlspecialchars($variant_name) . ': ' . number_format($variant_quantities[$variant_name], 0);
                    }
                }
                $damage_variant_parts = [];
                if ((int)$row['branch_id'] === $warehouse_id) {
                    $damage_variant_stmt = mysqli_prepare($conn, "SELECT variant_name,SUM(quantity) AS quantity FROM (SELECT variant_name,damaged_quantity AS quantity FROM stock_distributions WHERE user_id=? AND product_id=? AND to_branch_id=? AND status='accepted' AND damaged_quantity>0 UNION ALL SELECT variant_name,quantity FROM stock_damage_returns WHERE user_id=? AND product_id=? AND status IN ('accepted','dump_pending')) damage_variants GROUP BY variant_name ORDER BY variant_name");
                    mysqli_stmt_bind_param($damage_variant_stmt, 'iiiii', $company_id, $row['product_id'], $warehouse_id, $company_id, $row['product_id']);
                } else {
                    $damage_variant_stmt = mysqli_prepare($conn, "SELECT variant_name,SUM(quantity) AS quantity FROM (SELECT variant_name,damaged_quantity AS quantity FROM stock_distributions WHERE user_id=? AND product_id=? AND to_branch_id=? AND status='accepted' AND damaged_quantity>0 UNION ALL SELECT variant_name,-quantity AS quantity FROM stock_damage_returns WHERE user_id=? AND product_id=? AND branch_id=? AND status IN ('accepted','dump_pending','dumped')) damage_variants GROUP BY variant_name HAVING quantity>0 ORDER BY variant_name");
                    mysqli_stmt_bind_param($damage_variant_stmt, 'iiiiii', $company_id, $row['product_id'], $row['branch_id'], $company_id, $row['product_id'], $row['branch_id']);
                }
                mysqli_stmt_execute($damage_variant_stmt); $damage_variant_result = mysqli_stmt_get_result($damage_variant_stmt);
                while($damage_variant = mysqli_fetch_assoc($damage_variant_result)) $damage_variant_parts[] = htmlspecialchars($damage_variant['variant_name'] ?: 'Qty') . ': ' . number_format((float)$damage_variant['quantity'], 0);
                mysqli_stmt_close($damage_variant_stmt);
            ?><tr data-warehouse-stock-search="<?= htmlspecialchars(strtolower($row['product_name'] . ' ' . ($row['sku'] ?? '') . ' ' . implode(' ', $variant_parts)), ENT_QUOTES, 'UTF-8'); ?>">
                <?php $stock_photo = product_image_url($conn, $row['photo_path'] ?? ''); ?><td><button type="button" class="warehouse-photo-preview" data-photo-src="<?= htmlspecialchars($stock_photo, ENT_QUOTES, 'UTF-8'); ?>" data-photo-alt="<?= htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8'); ?>" title="Click to view larger photo"><img src="<?=htmlspecialchars($stock_photo)?>" style="width:38px;height:38px;object-fit:cover;border-radius:3px" alt="<?= htmlspecialchars($row['product_name']); ?>"></button></td><td><?= htmlspecialchars($row['product_name']); ?><?php if($variant_parts){ ?><small class="d-block text-muted"><?= implode(' || ', $variant_parts); ?></small><?php } ?></td><td><?= htmlspecialchars($row['sku'] ?? ''); ?></td>
                <td><?= number_format($row['quantity'],0); ?></td><td class="<?= (float)$row['damaged_quantity'] > 0 ? 'text-danger' : '' ?>"><?= number_format((float)$row['damaged_quantity'],0); ?><?php if($damage_variant_parts){ ?><small class="d-block text-muted"><?= implode(' || ', $damage_variant_parts); ?></small><?php } ?></td><td><?= number_format($row['cost'],2); ?></td><td><?= number_format((float)$row['quantity'] * (float)$row['sale_price'],2); ?></td>
            </tr><?php } ?></tbody>
        </table></div>
        <div id="warehouse-stock-no-results" class="text-center text-muted py-4 d-none">No matching product was found.</div>
    </div>
</div>
<?php if ($central && $multi_branch) { ?>
<div class="card card-outline card-primary">
    <div class="card-header"><h3 class="card-title">Stock Distribution</h3></div>
    <div class="card-body"><form method="post" id="stock-distribution-form">
        <input type="hidden" name="stock_csrf" value="<?= htmlspecialchars(stock_csrf_token()); ?>">
        <input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(24)); ?>">
        <div class="form-row">
            <div class="form-group col-md-3"><label for="distribution-from">From</label><select id="distribution-from" name="from_branch_id" class="form-control" required><?php mysqli_data_seek($branches, 0); while ($branch = mysqli_fetch_assoc($branches)) { ?><option value="<?= (int)$branch['id']; ?>"><?= htmlspecialchars(($branch['is_head_office'] ? 'Head Office' : $branch['branch_name']) . (!empty($branch['branch_code']) ? ' [' . $branch['branch_code'] . ']' : '')); ?></option><?php } ?></select></div>
            <div class="form-group col-md-3"><label for="distribution-branch">To</label><select id="distribution-branch" name="to_branch_id" class="form-control" required><option value="">Select location</option><?php mysqli_data_seek($branches, 0); while ($branch = mysqli_fetch_assoc($branches)) { ?><option value="<?= (int)$branch['id']; ?>"><?= htmlspecialchars(($branch['is_head_office'] ? 'Head Office' : $branch['branch_name']) . (!empty($branch['branch_code']) ? ' [' . $branch['branch_code'] . ']' : '')); ?></option><?php } ?></select></div>
            <div class="form-group col-md-12"><div id="distribution-lines"><div class="form-row distribution-line"><div class="form-group col-md-6"><label>Product / Available</label><select name="product_id[]" class="form-control distribution-product"><option value="">Select product</option></select></div><div class="form-group col-md-6"><label>Quantity</label><div class="distribution-quantity-wrap"><input class="form-control" type="number" min="1" step="1" disabled></div></div></div></div><button class="btn btn-outline-success btn-sm" type="button" id="add-distribution-line"><i class="fas fa-plus mr-1"></i>Add more</button></div>
            <div class="form-group col-md-3"><label for="distribution-note">Note</label><input id="distribution-note" class="form-control" name="note" maxlength="500"></div>
        </div>
        <button class="btn btn-primary" type="submit"><i class="fas fa-truck-loading mr-1"></i> Transfer Stock</button>
    </form></div>
</div>
<?php } ?>
<div class="card"><div class="card-header"><h3 class="card-title">Distribution History</h3><div class="card-tools" style="width:300px"><div class="input-group input-group-sm"><div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div><input id="distribution-history-search" class="form-control" placeholder="Search product, branch, status or note"></div></div></div><div class="card-body">
<div class="mb-3"><label for="distribution-history-count" class="font-weight-normal">Show <select id="distribution-history-count" class="custom-select custom-select-sm d-inline-block mx-1" style="width:auto"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option><option value="500">All (latest 500)</option></select> entries</label></div>
<style>
.distribution-history-table{table-layout:fixed;min-width:1500px;width:100%}
.distribution-history-table th,.distribution-history-table td{vertical-align:middle;overflow-wrap:anywhere}
.distribution-history-table th{white-space:nowrap}
.distribution-history-table td:nth-child(1),.distribution-history-table td:nth-child(2),.distribution-history-table td:nth-child(7),.distribution-history-table td:nth-child(8){white-space:nowrap;overflow-wrap:normal}
</style>
<div class="table-responsive"><table class="table table-bordered table-striped distribution-history-table"><colgroup><col style="width:10%"><col style="width:8%"><col style="width:14%"><col style="width:6%"><col style="width:12%"><col style="width:12%"><col style="width:4%"><col style="width:8%"><col style="width:8%"><col style="width:6%"><col style="width:12%"></colgroup><thead><tr><th>Reference</th><th>Date</th><th>Product</th><th>Code</th><th>From Branch</th><th>To Branch</th><th>Qty</th><th>Cost</th><th>Status</th><th>Action</th><th>Note</th></tr></thead><tbody id="distribution-history-list">
<?php while ($row = mysqli_fetch_assoc($history)) { $can_receive_at_warehouse = $central && $multi_branch && (int)$row['to_branch_id'] === $warehouse_id && ($row['status'] ?? '') === 'pending'; $can_print_distribution = (int)$row['from_branch_id'] === $stock_location_id; ?><tr><td><?= htmlspecialchars(trim((string)($row['reference_no'] ?? '')) ?: ('D-' . date('dmy', strtotime($row['created_at'])) . (int)$row['id'])); ?></td><td><?= htmlspecialchars(app_date($row['created_at'])); ?></td><td><?= distribution_product_blocks($row['product_summary']); ?></td><td><?= htmlspecialchars($row['sku_summary'] ?? ''); ?></td><td><?= htmlspecialchars(($row['from_head_office'] ? 'Head Office' : $row['from_branch_name']) . (!empty($row['from_branch_code']) ? ' [' . $row['from_branch_code'] . ']' : '')); ?></td><td><?= htmlspecialchars(($row['to_head_office'] ? 'Head Office' : $row['to_branch_name']) . (!empty($row['to_branch_code']) ? ' [' . $row['to_branch_code'] . ']' : '')); ?></td><td><?= number_format($row['quantity'],0); ?></td><td><?= number_format($row['total_cost'],2); ?></td><td><span class="badge badge-<?= ($row['status'] ?? 'accepted') === 'accepted' ? 'success' : 'warning'; ?>"><?= htmlspecialchars(ucfirst($row['status'] ?? 'accepted')); ?></span><?php if((float)($row['damaged_quantity'] ?? 0) > 0){ ?><small class="d-block text-danger mt-1">Damaged: <?= number_format((float)$row['damaged_quantity'],0); ?></small><?php } ?></td><td><?php if($can_print_distribution){ ?><a href="print_distribution.php?id=<?= (int)$row['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary mb-1"><i class="fas fa-print mr-1"></i>Print</a><?php } ?><?php if($can_receive_at_warehouse){ ?><form method="post"><input type="hidden" name="stock_csrf" value="<?= htmlspecialchars(stock_csrf_token()); ?>"><input type="hidden" name="action" value="receive_at_warehouse"><input type="hidden" name="distribution_ids" value="<?= htmlspecialchars($row['distribution_ids']); ?>"><button class="btn btn-sm btn-primary" onclick="return confirm('Receive this stock at Main Warehouse?')"><i class="fas fa-check mr-1"></i>Receive</button></form><?php } ?><?php if(!$can_print_distribution && !$can_receive_at_warehouse){ ?><span class="text-muted">-</span><?php } ?></td><td class="text-break"><?= htmlspecialchars($row['note']); ?></td></tr><?php } ?>
</tbody></table></div><div class="d-flex justify-content-between align-items-center"><small class="text-muted" id="distribution-history-info" role="status"></small><div><button type="button" id="distribution-history-prev" class="btn btn-sm btn-outline-secondary">Previous</button> <button type="button" id="distribution-history-next" class="btn btn-sm btn-outline-secondary">Next</button></div></div></div></div>
<style>.warehouse-photo-preview{padding:0;border:0;background:transparent;cursor:zoom-in;line-height:0}.warehouse-photo-preview img{transition:transform .15s ease}.warehouse-photo-preview:hover img{transform:scale(1.08)}</style>
<div class="modal fade" id="warehouse-photo-preview-modal" tabindex="-1" role="dialog" aria-labelledby="warehouse-photo-preview-title" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg" role="document"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="warehouse-photo-preview-title">Product Photo</h5><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div><div class="modal-body text-center p-3"><img id="warehouse-photo-preview-image" src="" alt="" style="max-width:100%;max-height:70vh;object-fit:contain"></div></div></div></div>
<script>
const setupCsrf = <?= json_encode(stock_csrf_token()); ?>;
function bindTableSearch(inputId, bodyId) {
    const input = document.getElementById(inputId);
    const body = document.getElementById(bodyId);
    if (!input || !body) return;
    const length = document.getElementById('setup-product-length');
    const previous = document.getElementById('setup-product-prev');
    const next = document.getElementById('setup-product-next');
    let page = 0;
    function render() {
        const keyword = input.value.trim().toLowerCase();
        const rows = Array.from(body.querySelectorAll('tr'));
        const matches = rows.filter(row => Array.from(row.cells).filter(cell => !cell.classList.contains('setup-sl')).map(cell => cell.textContent).join(' ').toLowerCase().includes(keyword));
        const size = Number(length.value) === -1 ? Math.max(1,matches.length) : Number(length.value);
        page = Math.min(page, Math.max(0,Math.ceil(matches.length/size)-1));
        const start = page*size, end = Math.min(start+size,matches.length);
        rows.forEach(row => {row.style.display='none';});
        matches.slice(start,end).forEach((row,index) => {row.style.display=''; row.querySelector('.setup-sl').textContent=start+index+1;});
        previous.disabled=page===0;
        next.disabled=end>=matches.length;
        document.getElementById('setup-product-info').textContent='Showing '+(matches.length ? start+1 : 0)+' to '+end+' of '+matches.length+' entries';
    }
    input.addEventListener('input', function(){page=0;render();});
    length.addEventListener('change', function(){page=0;render();});
    previous.addEventListener('click', function(){if(page>0) page--;render();});
    next.addEventListener('click', function(){page++;render();});
    render();
}
bindTableSearch('product-list-search', 'setup-product-list');
function bindWarehouseStockSearch() {
    const input = document.getElementById('warehouse-stock-search');
    const rows = Array.from(document.querySelectorAll('#warehouse-stock-table tbody tr[data-warehouse-stock-search]'));
    const noResults = document.getElementById('warehouse-stock-no-results');
    if (!input || !rows.length) return;
    input.addEventListener('input', function () {
        const keyword = this.value.trim().toLowerCase();
        let matched = 0;
        rows.forEach(row => {
            const visible = !keyword || row.dataset.warehouseStockSearch.includes(keyword);
            row.classList.toggle('d-none', !visible);
            if (visible) matched++;
        });
        noResults.classList.toggle('d-none', matched > 0);
    });
}
bindWarehouseStockSearch();
let historyRows = Array.from(document.getElementById('distribution-history-list').rows);
const historySearch = document.getElementById('distribution-history-search');
const historyCount = document.getElementById('distribution-history-count');
let historyPage = 0;
function renderHistoryPage() {
    const query = historySearch.value.trim().toLowerCase();
    const matched = historyRows.filter(row => row.textContent.toLowerCase().includes(query));
    const size = Number(historyCount.value);
    historyPage = Math.min(historyPage, Math.max(0, Math.ceil(matched.length / size) - 1));
    const start = historyPage * size;
    historyRows.forEach(row => row.style.display = 'none');
    matched.slice(start, start + size).forEach(row => row.style.display = '');
    document.getElementById('distribution-history-info').textContent = `Showing ${matched.length ? start + 1 : 0} to ${Math.min(start + size, matched.length)} of ${matched.length} entries (latest 500 distributions)`;
    document.getElementById('distribution-history-prev').disabled = historyPage === 0;
    document.getElementById('distribution-history-next').disabled = start + size >= matched.length;
}
historySearch.addEventListener('input', () => { historyPage = 0; renderHistoryPage(); });
historyCount.addEventListener('change', () => { historyPage = 0; renderHistoryPage(); });
document.getElementById('distribution-history-prev').addEventListener('click', () => { historyPage--; renderHistoryPage(); });
document.getElementById('distribution-history-next').addEventListener('click', () => { historyPage++; renderHistoryPage(); });
renderHistoryPage();
const setupMessage = document.getElementById('product-setup-message');
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
document.getElementById('show-product-form')?.addEventListener('click', () => productSetupForm.classList.toggle('d-none'));
productSetupForm?.addEventListener('submit', async event => {
    event.preventDefault();
    try {
        const result = await submitSetup(productSetupForm, 'add_product');
        const product = result.product;
        document.getElementById('setup-product-list').insertAdjacentHTML('afterbegin', '<tr><td class="setup-sl"></td><td><img src="' + escapeSetupHtml(product.photo_url) + '" style="width:34px;height:34px;object-fit:cover;border-radius:3px" alt=""></td><td>' + escapeSetupHtml(product.name) + '</td><td>' + escapeSetupHtml(product.category) + '</td><td>' + escapeSetupHtml(product.sku) + '</td><td>' + escapeSetupHtml(product.purchase_price) + '</td><td>' + escapeSetupHtml(product.sale_price) + '</td><td><span class="badge badge-' + (product.status === 'active' ? 'success' : 'secondary') + '">' + escapeSetupHtml(product.status[0].toUpperCase() + product.status.slice(1)) + '</span></td></tr>');
        document.getElementById('product-list-search').dispatchEvent(new Event('input'));
        productSetupForm.reset(); productSetupForm.classList.add('d-none'); showSetupMessage(result.message, true);
    } catch (error) { showSetupMessage(error.message, false); }
});

const fromLocation = document.getElementById('distribution-from');
const toLocation = document.getElementById('distribution-branch');
const distributionLines = document.getElementById('distribution-lines');
const distributionLineTemplate = distributionLines?.querySelector('.distribution-line').cloneNode(true);
function initializeDistributionSelects() {
    if (!distributionLines || !window.jQuery || !window.jQuery.fn.select2) return;
    const $from = window.jQuery(fromLocation);
    if (!$from.hasClass('select2-hidden-accessible')) {
        $from.select2({ theme: 'bootstrap4', width: '100%' });
    }
    // Select2 emits jQuery change events, which native listeners do not receive.
    fromLocation.removeEventListener('change', handleDistributionSourceChange);
    $from.off('change.distributionSource').on('change.distributionSource', handleDistributionSourceChange);
    distributionLines.querySelectorAll('.distribution-product').forEach(initializeDistributionProductSelect);
    const $to = window.jQuery(toLocation);
    if (!$to.hasClass('select2-hidden-accessible')) {
        $to.select2({
            theme: 'bootstrap4',
            width: '100%',
            placeholder: 'Select location',
            allowClear: true
        });
    }
}
function initializeDistributionProductSelect(select) {
    if(window.jQuery && window.jQuery.fn.select2){
        const $select=window.jQuery(select);
        if(!$select.hasClass('select2-hidden-accessible')) $select.select2({theme:'bootstrap4',width:'100%',placeholder:'Select product',allowClear:true});
        $select.off('.distributionVariants').on('select2:select.distributionVariants select2:clear.distributionVariants',()=>renderDistributionQuantityInputs(select));
    }
    if(!select.dataset.bound){select.addEventListener('change',()=>renderDistributionQuantityInputs(select));select.dataset.bound='1';}
}

function syncDestination() {
    if (!fromLocation || !toLocation) return;
    [...toLocation.options].forEach(option => {
        option.hidden = option.value !== '' && option.value === fromLocation.value;
        option.disabled = option.hidden;
    });
    if (toLocation.value === fromLocation.value) toLocation.value = '';
    if (window.jQuery && window.jQuery.fn.select2) window.jQuery(toLocation).trigger('change.select2');
}

let sourceProductsRequestController = null;
function loadSourceProducts() {
    if (!distributionLines || !fromLocation) return;
    const addButton = document.getElementById('add-distribution-line');
    addButton.disabled = true;
    if (sourceProductsRequestController) sourceProductsRequestController.abort();
    sourceProductsRequestController = new AbortController();
    const requestedSourceId = fromLocation.value;
    const productSelects=[...distributionLines.querySelectorAll('.distribution-product')];
    productSelects.forEach((select,index)=>{select.innerHTML='<option value="">Select product</option>';select.disabled=true;select.closest('.distribution-line').querySelector('.distribution-quantity-wrap').innerHTML='<input class="form-control" type="number" name="quantity['+index+']" disabled>';if(window.jQuery&&window.jQuery.fn.select2)window.jQuery(select).trigger('change.select2');});
    if (!requestedSourceId) {
        productSelects.forEach(select=>select.disabled=false);
        return;
    }
    fetch('get_branch_products.php?branch_id=' + encodeURIComponent(requestedSourceId), {signal: sourceProductsRequestController.signal, cache: 'no-store'})
        .then(response => response.ok ? response.json() : Promise.reject(new Error('Products could not be loaded.')))
        .then(products => {
            if (fromLocation.value !== requestedSourceId) return;
            productSelects.forEach((select,index) => {
                products.forEach(product => {
                    const option = new Option(product.name + ' — Available: ' + Number(product.available).toLocaleString(), product.id);
                    option.dataset.available=product.available;option.dataset.sourceBranch=requestedSourceId;option.dataset.variants=JSON.stringify(product.variants||[]);select.add(option);
                });
                if(!products.length) select.add(new Option('No stock available in this location',''));
                select.disabled=false;
                if(window.jQuery&&window.jQuery.fn.select2)window.jQuery(select).trigger('change.select2');
            });
            addButton.disabled = !products.length;
        })
        .catch(error => {
            if (error.name === 'AbortError') return;
            productSelects.forEach(select=>{select.innerHTML='<option value="">Products could not be loaded</option>';select.disabled=false;if(window.jQuery&&window.jQuery.fn.select2)window.jQuery(select).trigger('change.select2');});
        });
}

function handleDistributionSourceChange() {
    syncDestination();
    loadSourceProducts();
}
fromLocation?.addEventListener('change', handleDistributionSourceChange);
function renderDistributionQuantityInputs(productSelect) {
    const row=productSelect.closest('.distribution-line');
    const wrap=row.querySelector('.distribution-quantity-wrap');
    const lineIndex=[...distributionLines.querySelectorAll('.distribution-line')].indexOf(row);
    const selectedOption = productSelect.selectedOptions[0];
    if (!selectedOption?.value || selectedOption.dataset.sourceBranch !== fromLocation.value) {
        wrap.innerHTML = '<input class="form-control" type="number" name="quantity['+lineIndex+']" disabled aria-label="Select a source product first">';
        return;
    }
    let variants=[]; try{ variants=JSON.parse(selectedOption?.dataset.variants||'[]'); }catch(error){}
    if(!variants.length){
        const available = Number(selectedOption?.dataset.available || 0);
        wrap.innerHTML='<div class="input-group"><div class="input-group-prepend"><span class="input-group-text">Available: '+available+'</span></div><input class="form-control" type="number" name="quantity['+lineIndex+']" min="1" max="'+available+'" value="" step="1" required></div>';
        return;
    }
    wrap.innerHTML='';
    variants.forEach((variant,variantIndex)=>{
        const group=document.createElement('div'); group.className='input-group mb-1';
        const label=document.createElement('div'); label.className='input-group-prepend'; label.innerHTML='<span class="input-group-text">'+escapeSetupHtml(variant.name)+' (Available: '+Number(variant.available)+')</span>';
        const variantName=document.createElement('input'); variantName.type='hidden'; variantName.name='variant_name['+lineIndex+']['+variantIndex+']'; variantName.value=variant.name;
        const input=document.createElement('input'); input.type='number'; input.className='form-control'; input.name='variant_quantity['+lineIndex+']['+variantIndex+']'; input.min='0'; input.max=variant.available; input.step='1'; input.value='';
        group.append(label,variantName,input); wrap.append(group);
    });
}
function addDistributionLine(){
    const row=distributionLineTemplate.cloneNode(true);
    const select=row.querySelector('.distribution-product');
    select.replaceChildren(...Array.from(distributionLines.querySelector('.distribution-product').options, option => {
        const copy = new Option(option.text, option.value);
        Object.entries(option.dataset).forEach(([key,value])=>{ if(key !== 'select2Id') copy.dataset[key]=value; });
        return copy;
    }));
    select.selectedIndex=0;
    row.querySelector('.distribution-quantity-wrap').innerHTML='<input class="form-control" type="number" disabled aria-label="Select a source product first">';
    distributionLines.appendChild(row);initializeDistributionProductSelect(select);
}
document.getElementById('add-distribution-line')?.addEventListener('click',addDistributionLine);
const stockDistributionForm = document.getElementById('stock-distribution-form');
async function refreshDistributionHistory(responseHtml) {
    let html = responseHtml || '';
    if (!html) {
        const response = await fetch(window.location.href, {headers: {'X-Requested-With': 'XMLHttpRequest'}});
        if (!response.ok) throw new Error('Distribution history could not be refreshed.');
        html = await response.text();
    }
    const refreshedDocument = new DOMParser().parseFromString(html, 'text/html');
    const refreshedBody = refreshedDocument.getElementById('distribution-history-list');
    const currentBody = document.getElementById('distribution-history-list');
    if (!refreshedBody || !currentBody) throw new Error('Distribution history could not be refreshed.');
    currentBody.innerHTML = refreshedBody.innerHTML;
    historyRows = Array.from(currentBody.rows);
    historyPage = 0;
    renderHistoryPage();
}
stockDistributionForm?.addEventListener('ajax-action-success', async function (event) {
    stockDistributionForm.reset();
    const requestKey = stockDistributionForm.querySelector('input[name="request_key"]');
    if (requestKey) {
        const bytes = new Uint8Array(24);
        window.crypto.getRandomValues(bytes);
        requestKey.value = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
    }
    if (window.jQuery && window.jQuery.fn.select2) {
        window.jQuery(fromLocation).trigger('change.select2');
        window.jQuery(document.getElementById('distribution-branch')).val('').trigger('change.select2');
    }
    const firstLine=distributionLines.querySelector('.distribution-line');
    distributionLines.querySelectorAll('.distribution-line').forEach((line,index)=>{if(index>0)line.remove();});
    firstLine.querySelector('.distribution-product').value='';
    syncDestination();
    loadSourceProducts();
    try {
        await refreshDistributionHistory(event.detail?.result?.html || '');
    } catch (error) {
        if (window.showAjaxActionMessage) window.showAjaxActionMessage(error.message, false);
    }
});
syncDestination();
initializeDistributionSelects();
loadSourceProducts();
</script>
<?php
$page_script = <<<'SCRIPT'
<script>
initializeDistributionSelects();
$(document).on('click', '.warehouse-photo-preview', function () {
    $('#warehouse-photo-preview-image').attr({src: this.dataset.photoSrc, alt: this.dataset.photoAlt || 'Product photo'});
    $('#warehouse-photo-preview-title').text(this.dataset.photoAlt || 'Product Photo');
    $('#warehouse-photo-preview-modal').modal('show');
});
</script>
SCRIPT;
$page_script .= '<script src="../assets/js/ajax_page_actions.js?v=' . filemtime(__DIR__ . '/../assets/js/ajax_page_actions.js') . '"></script>';
?>
<?php require_once '../includes/footer.php'; ?>
