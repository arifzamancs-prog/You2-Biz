<?php
// Restaurant & Cafe product list, adapted from You2-cafe. This page deliberately
// shares the You2-Biz products, invoices, wallets, security and tenant tables.
require_once '../includes/product_expiry_helper.php';
require_once '../includes/product_category_helper.php';

$user_id = (int)$_SESSION['user_id'];
ensure_product_management_columns($conn);
ensure_product_image_column($conn);
ensure_restaurant_product_categories($conn, $user_id);
$show_expired_on = is_product_expiry_enabled($conn);
if (empty($_SESSION['product_delete_csrf'])) $_SESSION['product_delete_csrf'] = bin2hex(random_bytes(32));
$stmt = mysqli_prepare($conn, "SELECT p.*, COALESCE(c.category_name,'Unassigned') category_name,
    COALESCE(c.category_type,'non_stock') category_type
    FROM products p LEFT JOIN product_categories c ON c.id=p.category_id AND c.user_id=p.user_id
    WHERE p.user_id=? ORDER BY CASE WHEN c.category_type='stock_product' THEN 0 ELSE 1 END, p.id DESC");
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$products = mysqli_stmt_get_result($stmt);
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<div class="card">
  <div class="card-header"><h3 class="card-title"><i class="fas fa-box mr-2"></i>Products</h3>
  <?php if(manager_can_modify()){ ?><div class="card-tools"><a href="../products/create.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Add Product</a></div><?php } ?></div>
  <div class="card-body">
    <?php if(isset($_SESSION['error'])){ ?><div class="alert alert-danger"><?=htmlspecialchars($_SESSION['error']); unset($_SESSION['error']);?></div><?php } ?>
    <div id="restaurant-product-message" role="status"></div>
    <style>
      #restaurant-product-list .restaurant-product-photo{width:76px;min-width:76px;text-align:center}
      #restaurant-product-list .restaurant-product-code{width:105px;min-width:105px}
      #restaurant-product-list .restaurant-product-category{width:180px;min-width:150px}
      #restaurant-product-list .restaurant-product-photo img{width:46px;height:46px;object-fit:cover;border-radius:4px}
    </style>
    <table id="restaurant-product-list" data-csrf="<?=htmlspecialchars($_SESSION['product_delete_csrf'])?>" data-stock-csrf="<?=htmlspecialchars(stock_csrf_token())?>" class="table table-bordered table-striped"><thead><tr>
      <th class="restaurant-product-photo">Photo</th><th>Product</th><th class="restaurant-product-code">Code</th><th class="restaurant-product-category">Category</th><th>Purchase</th><th>Sale</th>
      <?php if($show_expired_on){ ?><th>Expiry on</th><?php } ?><th>Stock</th><th>Status</th>
      <?php if(manager_can_modify()){ ?><th width="150">Action</th><?php } ?>
    </tr></thead><tbody>
    <?php $last_type=null; $columns=8+($show_expired_on?1:0)+(manager_can_modify()?1:0); while($row=mysqli_fetch_assoc($products)){
      $type=$row['category_type']==='stock_product'?'stock_product':'non_stock';
      if($type!==$last_type){ $last_type=$type; ?><tr class="table-primary"><td colspan="<?=$columns?>"><strong><?=$type==='stock_product'?'Stock Products':'Non Stock Products'?></strong></td></tr><?php }
      $used=product_has_transactions($conn,(int)$row['id'],$user_id); ?>
      <tr><td class="restaurant-product-photo"><img src="<?= htmlspecialchars(product_image_url($conn, $row['photo_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8') ?>"></td>
      <td><?=htmlspecialchars($row['product_name'])?></td>
      <td class="restaurant-product-code"><?=htmlspecialchars(trim((string)$row['sku'])!==''?$row['sku']:'-')?></td>
      <td class="restaurant-product-category"><?=htmlspecialchars($row['category_name'])?></td>
      <td><?=$type==='stock_product'?'BDT '.number_format((float)$row['purchase_price'],2):'N/A'?></td>
      <td>BDT <?=number_format((float)$row['sale_price'],2)?></td>
      <?php if($show_expired_on){ ?><td><?=$type==='stock_product'?htmlspecialchars(app_date($row['expired_on']??'')):'N/A'?></td><?php } ?>
      <td><?=$type==='stock_product'?number_format((float)$row['current_stock'],0):'N/A'?></td>
      <td><span class="badge badge-<?=$row['status']==='active'?'success':'danger'?>"><?=htmlspecialchars(ucfirst($row['status']))?></span></td>
      <?php if(manager_can_modify()){ ?><td>
        <?php if(!$used){ ?><a href="../products/edit.php?id=<?=(int)$row['id']?>" class="btn btn-warning btn-sm" title="Edit Product"><i class="fas fa-edit"></i></a>
        <button type="button" data-product-id="<?=(int)$row['id']?>" class="btn btn-danger btn-sm restaurant-product-delete" title="Delete Product"><i class="fas fa-trash"></i></button>
        <?php }else{ ?><button class="btn btn-warning btn-sm" disabled title="This product has transactions."><i class="fas fa-edit"></i></button><button class="btn btn-danger btn-sm" disabled title="This product has transactions."><i class="fas fa-trash"></i></button><?php } ?>
      </td><?php } ?></tr>
    <?php } ?></tbody></table>
  </div>
</div>
<script>
(function () {
  const list = document.getElementById('restaurant-product-list');
  const message = document.getElementById('restaurant-product-message');
  if (!list || !message) return;

  function showMessage(type, text) {
    message.className = 'alert alert-' + type;
    message.textContent = text;
  }

  document.addEventListener('click', async function (event) {
    const button = event.target.closest('.restaurant-product-delete');
    if (!button) return;

    if (!window.confirm('Delete this product?')) return;
    button.disabled = true;

    try {
      const response = await fetch('../products/delete.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'Accept': 'application/json'
        },
        body: new URLSearchParams({
          id: button.dataset.productId,
          csrf: list.getAttribute('data-csrf'),
          stock_csrf: list.getAttribute('data-stock-csrf')
        })
      });
      const responseText = await response.text();
      let data;
      try {
        data = JSON.parse(responseText);
      } catch (_) {
        throw new Error('Unexpected server response. Reload the page and try again.');
      }
      if (!response.ok || !data.success) {
        throw new Error(data.message || 'Product could not be deleted.');
      }

      const row = button.closest('tr');
      const groupRow = row.previousElementSibling && row.previousElementSibling.classList.contains('table-primary')
        ? row.previousElementSibling
        : null;
      row.remove();
      if (groupRow && (!groupRow.nextElementSibling || groupRow.nextElementSibling.classList.contains('table-primary'))) {
        groupRow.remove();
      }
      showMessage('success', data.message || 'Product deleted.');
    } catch (error) {
      showMessage('danger', error.message || 'Product could not be deleted.');
      button.disabled = false;
    }
  });
})();
</script>
<?php require_once '../includes/footer.php'; ?>
