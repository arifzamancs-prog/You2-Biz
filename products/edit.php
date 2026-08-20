<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/product_expiry_helper.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_category_helper.php';

$user_id = $_SESSION['user_id'];
ensure_product_management_columns($conn);
ensure_fifo_inventory_tables($conn);
ensure_product_category_type_column($conn);
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
    $is_stock_product = product_category_is_stock($conn, $category_id, $user_id);
    $product_name   = trim($_POST['product_name']);
    $sku            = trim($_POST['sku']);
    $purchase_price = $is_stock_product ? (float)($_POST['purchase_price'] ?? 0) : 0;
    $sale_price     = (float)$_POST['sale_price'];
    $expired_on     = $is_stock_product && $show_expired_on
        ? trim($_POST['expired_on'] ?? '')
        : (string)($product['expired_on'] ?? '');

    if($expired_on === ''){
        $expired_on = null;
    }
    $opening_stock  = $is_stock_product ? (int)($_POST['current_stock'] ?? 0) : 0;
    $minimum_stock =  $is_stock_product ? (int)($_POST['minimum_stock'] ?? 0) : 0;
    $status         = $_POST['status'];

    mysqli_begin_transaction($conn);

    $sql = "UPDATE products
            SET
                category_id=?,
                product_name=?,
                sku=?,
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
        "issddsdddisii",
        $category_id,
        $product_name,
        $sku,
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
        (!$is_stock_product || fifo_inventory_create_batch(
            $conn,
            $user_id,
            $id,
            $opening_stock,
            $purchase_price,
            'product_opening',
            $id,
            'OPEN-' . $id,
            date('Y-m-d')
        ))
    ){
        mysqli_commit($conn);

        header("Location: index.php");
        exit;
    }

    mysqli_rollback($conn);
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

        <form method="post">

            <div class="form-group">

                <label>Category</label>

                <select
                    name="category_id"
                    class="form-control">

                    <?php while($cat = mysqli_fetch_assoc($categories)){ ?>

                        <option
                            value="<?= $cat['id']; ?>"
                            data-category-type="<?= htmlspecialchars($cat['category_type'] ?? 'non_stock'); ?>"
                            <?= $product['category_id']==$cat['id']?'selected':''; ?>>

                            <?= htmlspecialchars($cat['category_name']); ?>

                        </option>

                    <?php } ?>

                </select>

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

                <label>SKU</label>

                <input
                    type="text"
                    name="sku"
                    class="form-control"
                    value="<?= htmlspecialchars($product['sku']); ?>">

            </div>

            <div class="form-group stock-only-field">

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
            <div class="form-group stock-only-field">

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

            <div class="form-group stock-only-field">

                <label>Opening Stock</label>

                <input
                    type="number"
                    step="1"
                    min="0"
                    name="current_stock"
                    class="form-control"
                    value="<?= (int)($product['opening_stock_quantity'] ?? $product['current_stock']); ?>">

            </div>

            <div class="form-group stock-only-field">

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
document.addEventListener('DOMContentLoaded', function () {
    var category = document.querySelector('[name="category_id"]');
    var stockFields = document.querySelectorAll('.stock-only-field');

    function updateStockFields() {
        var option = category.options[category.selectedIndex];
        var isStock = option && option.dataset.categoryType === 'stock_product';
        stockFields.forEach(function (field) {
            field.style.display = isStock ? '' : 'none';
            field.querySelectorAll('input').forEach(function (input) {
                input.disabled = !isStock;
            });
        });
    }

    category.addEventListener('change', updateStockFields);
    updateStockFields();
});
</script>

<?php require_once '../includes/footer.php'; ?>
