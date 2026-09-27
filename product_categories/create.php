<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/product_category_helper.php';

$user_id = $_SESSION['user_id'];
ensure_fifo_only_product_categories($conn, $user_id);

$message = '';
$message_type = '';

if($_SERVER['REQUEST_METHOD']=='POST'){

    $category_name = trim($_POST['category_name']);
    $category_type = 'stock_product';
    $status = $_POST['status'];

    $sql = "INSERT INTO product_categories
            (
                user_id,
                category_name,
                category_type,
                status
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?
            )";

    $stmt = mysqli_prepare($conn,$sql);

    mysqli_stmt_bind_param(
        $stmt,
        "isss",
        $user_id,
        $category_name,
        $category_type,
        $status
    );

    if(mysqli_stmt_execute($stmt)){

        header(
            "Location: index.php"
        );

        exit;

    }else{

        $message = "Failed to save category";
        $message_type = "danger";

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

            Add Product Category

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
                    required>

            </div>

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

            <button
                type="submit"
                class="btn btn-primary">

                <i class="fas fa-save"></i>

                Save Category

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
