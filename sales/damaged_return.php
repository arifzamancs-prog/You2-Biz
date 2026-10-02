<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_image_helper.php';
require_once '../includes/project_package_helper.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (project_package_company_type($conn, $user_id) !== 'Fashion house' || !company_multi_branch_enabled($conn, $user_id)) { header('Location: stock_product.php'); exit; }
if (is_manager_user() && !manager_has_permission('stock_sales')) { http_response_code(403); exit('Sales access is required.'); }
$branch_id = selected_branch_id($conn, true);
if ($branch_id <= 0) { http_response_code(403); exit('Current branch is not configured.'); }
$message = $_SESSION['damage_return_message'] ?? ''; unset($_SESSION['damage_return_message']); $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $id = fifo_inventory_request_damage_return($conn, $user_id, $branch_id, (int)($_POST['product_id'] ?? 0), (string)($_POST['variant_name'] ?? ''), (float)($_POST['quantity'] ?? 0), (string)($_POST['request_key'] ?? ''), (string)($_POST['note'] ?? ''));
        $_SESSION['damage_return_message'] = "Damaged-return request #{$id} sent to warehouse."; header('Location: damaged_return.php'); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$damage_options = [];
$damage_rows = mysqli_query($conn, "SELECT d.product_id,d.variant_name,p.product_name,p.sku,
    COALESCE(SUM(d.damaged_quantity),0) - COALESCE((SELECT SUM(dr.quantity) FROM stock_damage_returns dr WHERE dr.user_id=d.user_id AND dr.branch_id=d.to_branch_id AND dr.product_id=d.product_id AND dr.variant_name=d.variant_name),0) AS available_quantity
    FROM stock_distributions d INNER JOIN products p ON p.id=d.product_id
    WHERE d.user_id={$user_id} AND d.to_branch_id={$branch_id} AND d.status='accepted' AND d.damaged_quantity>0
    GROUP BY d.product_id,d.variant_name,p.product_name,p.sku,d.user_id,d.to_branch_id
    HAVING available_quantity>0 ORDER BY p.product_name,d.variant_name");
while($damage = mysqli_fetch_assoc($damage_rows)) {
    $product_id = (int)$damage['product_id'];
    if (!isset($damage_options[$product_id])) $damage_options[$product_id] = ['name'=>$damage['product_name'], 'sku'=>$damage['sku'], 'variants'=>[]];
    $damage_options[$product_id]['variants'][] = ['name'=>$damage['variant_name'], 'quantity'=>(float)$damage['available_quantity']];
}
$returns = mysqli_query($conn, "SELECT dr.*,p.product_name,p.sku,b.branch_name FROM stock_damage_returns dr INNER JOIN products p ON p.id=dr.product_id INNER JOIN branches b ON b.id=dr.branch_id WHERE dr.user_id={$user_id} AND dr.branch_id={$branch_id} ORDER BY dr.id DESC LIMIT 200");
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<section class="content"><div class="container-fluid">
<div class="card card-outline card-danger"><div class="card-header"><h3 class="card-title"><i class="fas fa-exchange-alt mr-2"></i>Return Damaged Product</h3></div><div class="card-body">
<?php if($message){ ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php } ?><?php if($error){ ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php } ?>
<p class="text-muted">A submitted damaged item is locked from sales. Branch stock is deducted only when warehouse accepts it.</p>
<form method="post"><input type="hidden" name="stock_csrf" value="<?= htmlspecialchars(stock_csrf_token()) ?>"><input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(24)) ?>"><div class="form-row">
<div class="form-group col-md-4"><label>Product</label><select name="product_id" id="damage-product" class="form-control" required><option value="">Select damaged product</option><?php foreach($damage_options as $id=>$p){ ?><option value="<?= $id ?>"><?= htmlspecialchars($p['name']) ?><?= $p['sku'] ? ' — '.htmlspecialchars($p['sku']) : '' ?></option><?php } ?></select></div>
<div class="form-group col-md-3"><label>Variant</label><select name="variant_name" id="damage-variant" class="form-control"><option value="">Select product first</option></select></div>
<div class="form-group col-md-2"><label>Quantity</label><input type="number" name="quantity" id="damage-quantity" class="form-control" min="1" step="1" required></div><div class="form-group col-md-3"><label>Note</label><input name="note" class="form-control" maxlength="500"></div></div><button class="btn btn-danger"><i class="fas fa-truck mr-1"></i>Send Return Request</button></form>
</div></div>
<div class="card"><div class="card-header"><h3 class="card-title">My Damaged Returns</h3></div><div class="card-body table-responsive"><table class="table table-bordered table-striped"><thead><tr><th>Date</th><th>Product</th><th>Variant</th><th>Qty</th><th>Status</th><th>Note</th></tr></thead><tbody><?php while($r=mysqli_fetch_assoc($returns)){ ?><tr><td><?= htmlspecialchars(app_datetime($r['created_at'])) ?></td><td><?= htmlspecialchars($r['product_name']) ?></td><td><?= htmlspecialchars($r['variant_name'] ?: '-') ?></td><td><?= number_format((float)$r['quantity'],0) ?></td><td><span class="badge badge-<?= $r['status']==='pending'?'warning':($r['status']==='accepted'?'info':'secondary') ?>"><?= htmlspecialchars(ucfirst($r['status'])) ?></span></td><td><?= htmlspecialchars($r['note']) ?></td></tr><?php } ?></tbody></table></div></div>
</div></section>
<script>
const product=document.getElementById('damage-product'), variant=document.getElementById('damage-variant'), quantity=document.getElementById('damage-quantity'), damagedOptions=<?= json_encode($damage_options, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
product.addEventListener('change', function(){ const item=damagedOptions[this.value]; variant.innerHTML=''; quantity.value=''; quantity.removeAttribute('max'); if(!item){ variant.innerHTML='<option value="">Select damaged product first</option>'; return; } item.variants.forEach(v=>{ const option=document.createElement('option'); option.value=v.name; option.textContent=(v.name || 'Qty')+' (Damaged available: '+v.quantity+')'; option.dataset.available=v.quantity; variant.appendChild(option); }); variant.dispatchEvent(new Event('change')); });
variant.addEventListener('change', function(){ const available=this.selectedOptions[0]?.dataset.available || ''; quantity.max=available; });
</script>
<?php require_once '../includes/footer.php'; ?>
