<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/product_category_helper.php';

$user_id = $_SESSION['user_id'];
ensure_product_category_type_column($conn);

$id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;

$sql = "SELECT *
        FROM product_categories
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

$category = mysqli_fetch_assoc($result);

if(!$category){

    die('Category Not Found');

}

if(product_category_is_default($category['category_name'])){
    die('Default categories cannot be edited.');
}

if(product_category_has_usage($conn, $id, $user_id)){
    die('This category cannot be edited because it has products or transactions.');
}

$message = '';
$message_type = '';

if($_SERVER['REQUEST_METHOD']=='POST'){

    $category_name =
    trim($_POST['category_name']);

    $category_type = $_POST['category_type'] ?? 'non_stock';

    if(!in_array($category_type, ['non_stock', 'stock_product'], true)){
        $category_type = 'non_stock';
    }

    $status =
    $_POST['status'];

    $sql = "UPDATE product_categories
            SET
                category_name=?,
                category_type=?,
                status=?
            WHERE id=?
            AND user_id=?";

    $stmt = mysqli_prepare(
        $conn,
        $sql
    );

    mysqli_stmt_bind_param(
        $stmt,
        "sssii",
        $category_name,
        $category_type,
        $status,
        $id,
        $user_id
    );

    if(mysqli_stmt_execute($stmt)){

        header(
            "Location: index.php"
        );

        exit;

    }else{

        $message =
        "Update Failed";

        $message_type =
        "danger";
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">

            <i class="fas fa-edit mr-2"></i>

            Edit Category

        </h3>

    </div>

    <div class="card-body">

        <?php if($message){ ?>

            <div class="alert alert-<?= $message_type; ?>">

                <?= htmlspecialchars($message); ?>

            </div>

        <?php } ?>

        <form method="post">

            <div class="form-group">

                <label>
                    Category Name
                </label>

                <input
                    type="text"
                    name="category_name"
                    class="form-control"
                    value="<?= htmlspecialchars($category['category_name']); ?>"
                    required>

            </div>

            <div class="form-group">

                <label>Category Type</label>

                <select name="category_type" class="form-control" required>
                    <option value="non_stock" <?= ($category['category_type'] ?? 'non_stock') === 'non_stock' ? 'selected' : ''; ?>>Non Stock/Service</option>
                    <option value="stock_product" <?= ($category['category_type'] ?? '') === 'stock_product' ? 'selected' : ''; ?>>Stock</option>
                </select>

            </div>

            <div class="form-group">

                <label>
                    Status
                </label>

                <select
                    name="status"
                    class="form-control">

                    <option
                        value="active"
                        <?= $category['status']=='active'?'selected':''; ?>>

                        Active

                    </option>

                    <option
                        value="inactive"
                        <?= $category['status']=='inactive'?'selected':''; ?>>

                        Inactive

                    </option>

                </select>

            </div>

            <button
                type="submit"
                class="btn btn-primary">

                <i class="fas fa-save"></i>

                Update Category

            </button>

            <a
                href="index.php"
                class="btn btn-secondary">

                Back

            </a>

        </form>

    </div>

</div>

<?php
require_once '../includes/footer.php';
?>
