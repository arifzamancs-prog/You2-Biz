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

$message = '';
$message_type = '';

/*
|----------------------------------
| Load Categories
|----------------------------------
*/

$sql = "SELECT *
        FROM product_categories
        WHERE user_id=?
        AND status='active'
        ORDER BY
            CASE WHEN category_name='General (Non Stock)' THEN 0 ELSE 1 END,
            category_name ASC";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $user_id
);

mysqli_stmt_execute($stmt);

$categories =
mysqli_stmt_get_result($stmt);

/*
|----------------------------------
| Save Product
|----------------------------------
*/

if($_SERVER['REQUEST_METHOD']=='POST'){

    $limit_sql = "SELECT
                    u.max_products,
                    COUNT(p.id) AS product_count
                  FROM users u
                  LEFT JOIN products p
                    ON p.user_id = u.id
                  WHERE u.id=?
                  GROUP BY u.id, u.max_products";

    $limit_stmt = mysqli_prepare($conn, $limit_sql);

    mysqli_stmt_bind_param(
        $limit_stmt,
        "i",
        $user_id
    );

    mysqli_stmt_execute($limit_stmt);
    $limit = mysqli_fetch_assoc(mysqli_stmt_get_result($limit_stmt));

    if($limit && (int)$limit['product_count'] >= (int)$limit['max_products']){

        $message = "Product limit reached for your subscription. " . subscription_support_message();
        $message_type = "danger";

    }else{

    $category_id =
    (int)$_POST['category_id'];

    $is_stock_product = product_category_is_stock($conn, $category_id, $user_id);

    $product_name =
    trim($_POST['product_name']);

    $sku =
    trim($_POST['sku']);

    $purchase_price = $is_stock_product ? (float)($_POST['purchase_price'] ?? 0) : 0;

    $sale_price =
    (float)$_POST['sale_price'];

    $expired_on = $is_stock_product && $show_expired_on
        ? trim($_POST['expired_on'] ?? '')
        : '';

    if($expired_on === ''){
        $expired_on = null;
    }

    $opening_stock = $is_stock_product ? (int)($_POST['current_stock'] ?? 0) : 0;
    $minimum_stock = $is_stock_product ? (int)($_POST['minimum_stock'] ?? 0) : 0;

    $status =
    $_POST['status'];

    mysqli_begin_transaction($conn);

    $sql = "INSERT INTO products
            (
                user_id,
                category_id,
                product_name,
                sku,
                purchase_price,
                sale_price,
                expired_on,
                current_stock,
                opening_stock_quantity,
                opening_stock_unit_cost,
                minimum_stock,
                status
            )
            VALUES
            (
                ?,?,?,?,?,?,?,?,?,?,?,?
            )";

    $stmt = mysqli_prepare(
        $conn,
        $sql
    );

    mysqli_stmt_bind_param(
        $stmt,
        "iissddsdddis",
        $user_id,
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
        $status
        
    );

    if(mysqli_stmt_execute($stmt)){
        $product_id = (int)mysqli_insert_id($conn);

        if($is_stock_product && !fifo_inventory_create_batch(
            $conn,
            $user_id,
            $product_id,
            $opening_stock,
            $purchase_price,
            'product_opening',
            $product_id,
            'OPEN-' . $product_id,
            date('Y-m-d')
        )){
            mysqli_rollback($conn);
            $message = "Product opening stock batch could not be saved.";
            $message_type = "danger";
        }else{
            mysqli_commit($conn);

            header(
                "Location: index.php"
            );

            exit;
        }

    }else{
        mysqli_rollback($conn);

        $message =
        "Failed to save product";

        $message_type =
        "danger";
    }

    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">

            <i class="fas fa-plus-circle mr-2"></i>

            Add Product

        </h3>

    </div>

    <div class="card-body">

        <?php if($message){ ?>

            <div class="alert alert-<?= $message_type; ?>">

                <?= htmlspecialchars($message); ?>

            </div>

        <?php } ?>

        <form method="post">

            <div class="row">

                <div class="col-md-6">

                    <div class="form-group">

                        <label>
                            Category
                        </label>

                        <select
                            name="category_id"
                            class="form-control"
                            required>

                            <?php $default_category_selected = false; ?>

                            <?php while($cat = mysqli_fetch_assoc($categories)){ ?>

                                <?php
                                $is_default_non_stock =
                                    !$default_category_selected &&
                                    ($cat['category_name'] ?? '') === 'General (Non Stock)';

                                if($is_default_non_stock){
                                    $default_category_selected = true;
                                }
                                ?>

                                <option
                                    value="<?= $cat['id']; ?>"
                                    data-category-type="<?= htmlspecialchars($cat['category_type'] ?? 'non_stock'); ?>"
                                    <?= $is_default_non_stock ? 'selected' : ''; ?>>

                                    <?= htmlspecialchars($cat['category_name']); ?>

                                </option>

                            <?php } ?>

                        </select>

                    </div>

                </div>

                <div class="col-md-6">

                    <div class="form-group">

                        <label>
                            Product Name
                        </label>

                        <input
                            type="text"
                            name="product_name"
                            class="form-control"
                            required>

                    </div>

                </div>

            </div>

            <div class="row">

                <div class="col-md-4">

                    <div class="form-group">

                        <label>
                            SKU
                        </label>

                        <input
                            type="text"
                            name="sku"
                            class="form-control">

                    </div>

                </div>

                <div class="col-md-4 stock-only-field">

                    <div class="form-group">

                        <label>
                            Purchase Price
                        </label>

                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            name="purchase_price"
                            class="form-control"
                            value="0">

                    </div>

                </div>

                <div class="col-md-4">

                    <div class="form-group">

                        <label>
                            Sale Price
                        </label>

                        <input
                            type="number"
                            step="0.01"
                            name="sale_price"
                            class="form-control"
                            value="0">

                    </div>

                </div>

                <?php if($show_expired_on){ ?>
                <div class="col-md-4 stock-only-field">

                    <div class="form-group">

                        <label>
                            Expiry on
                        </label>

                        <input
                            type="date"
                            name="expired_on"
                            class="form-control">

                    </div>

                </div>
                <?php } ?>

            </div>

            <div class="row">

                <div class="col-md-4 stock-only-field">

                    <div class="form-group">

                        <label>
                            Opening Stock
                        </label>

                        <input
                            type="number"
                            step="1"
                            min="0"
                            name="current_stock"
                            class="form-control"
                            value="0">

                    </div>

                </div>

                <div class="col-md-4 stock-only-field">

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
                        value="5">

                </div>

            </div>

                <div class="col-md-4">

                    <div class="form-group">

                        <label>
                            Status
                        </label>

                        <select
                            name="status"
                            class="form-control">

                            <option value="active">
                                Active
                            </option>

                            <option value="inactive">
                                Inactive
                            </option>

                        </select>

                    </div>

                </div>

            </div>

            <button
                type="submit"
                class="btn btn-primary">

                <i class="fas fa-save"></i>

                Save Product

            </button>

            <a
                href="index.php"
                class="btn btn-secondary">

                Back

            </a>

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

<?php
require_once '../includes/footer.php';
?>
