<?php
// Restaurant & Cafe keeps menu and stock categories in separate sections.
require_once '../includes/product_category_helper.php';

$user_id = (int)$_SESSION['user_id'];
ensure_restaurant_product_categories($conn, $user_id);
$stmt = mysqli_prepare($conn, 'SELECT * FROM product_categories WHERE user_id=?' . restaurant_catalog_scope_sql($conn) . ' ORDER BY id ASC');
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$categories = mysqli_stmt_get_result($stmt);

$menu_categories = [];
$stock_categories = [];
while ($category = mysqli_fetch_assoc($categories)) {
    if (($category['category_type'] ?? 'non_stock') === 'stock_product') {
        $stock_categories[] = $category;
    } else {
        $menu_categories[] = $category;
    }
}

function restaurant_category_row($row, $user_id)
{
    ?>
    <tr>
        <td><?= htmlspecialchars($row['category_name']); ?></td>
        <td><span class="badge badge-<?= $row['status'] === 'active' ? 'success' : 'danger'; ?>"><?= htmlspecialchars(ucfirst($row['status'])); ?></span></td>
        <?php if (manager_can_modify()) { ?>
        <td width="150">
            <a href="../product_categories/edit.php?id=<?= (int)$row['id']; ?>" class="btn btn-warning btn-sm" title="Edit category"><i class="fas fa-edit"></i></a>
            <?php if (product_category_has_usage($GLOBALS['conn'], (int)$row['id'], $user_id)) { ?>
                <span class="text-muted small ml-1">In use</span>
            <?php } else { ?>
                <a href="../product_categories/delete.php?id=<?= (int)$row['id']; ?>" class="btn btn-danger btn-sm" title="Delete category" onclick="return confirm('Delete this category?')"><i class="fas fa-trash"></i></a>
            <?php } ?>
        </td>
        <?php } ?>
    </tr>
    <?php
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-tags mr-2"></i>Non Stock Product Categories</h3>
        <?php if (manager_can_modify()) { ?><div class="card-tools"><a href="../product_categories/create.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Add Category</a></div><?php } ?>
    </div>
    <div class="card-body">
        <table id="example1" class="table table-bordered table-striped">
            <thead><tr><th>Category Name</th><th style="width:95px">Status</th><?php if (manager_can_modify()) { ?><th width="150">Action</th><?php } ?></tr></thead>
            <tbody>
                <?php if (!$menu_categories) { ?><tr><td colspan="<?= manager_can_modify() ? 3 : 2; ?>" class="text-center text-muted">No non-stock product categories found.</td></tr><?php } ?>
                <?php foreach ($menu_categories as $category) { restaurant_category_row($category, $user_id); } ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-boxes mr-2"></i>Stock Product Categories</h3></div>
    <div class="card-body">
        <table id="example2" class="table table-bordered table-striped">
            <thead><tr><th>Category Name</th><th style="width:95px">Status</th><?php if (manager_can_modify()) { ?><th width="150">Action</th><?php } ?></tr></thead>
            <tbody>
                <?php if (!$stock_categories) { ?><tr><td colspan="<?= manager_can_modify() ? 3 : 2; ?>" class="text-center text-muted">No stock product categories found.</td></tr><?php } ?>
                <?php foreach ($stock_categories as $category) { restaurant_category_row($category, $user_id); } ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
