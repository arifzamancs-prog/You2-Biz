<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/expense_helper.php';

$user_id = $_SESSION['user_id'];
ensure_expense_support_tables($conn, $user_id);
$id = (int)$_GET['id'];

$sql = "SELECT *
        FROM categories
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
    die("Category Not Found");
}

if(expense_category_is_reserved($category['category_name']) || (int)($category['is_hidden'] ?? 0) === 1){
    die("Reserved category cannot be edited.");
}

if($_SERVER['REQUEST_METHOD']=='POST'){

    $category_name = trim($_POST['category_name']);

    if(expense_category_is_reserved($category_name)){
        die("Reserved category name cannot be used.");
    }

    $sql = "UPDATE categories
            SET category_name=?
            WHERE id=?
            AND user_id=?";

    $stmt = mysqli_prepare($conn,$sql);

    mysqli_stmt_bind_param(
        $stmt,
        "sii",
        $category_name,
        $id,
        $user_id
    );

    mysqli_stmt_execute($stmt);

    header("Location:index.php");
    exit;
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">

    <div class="card-header">
        <h3 class="card-title">Edit Category</h3>
    </div>

    <div class="card-body">

        <form method="post">

            <div class="form-group">

                <label>Category Name</label>

                <input
                    type="text"
                    name="category_name"
                    class="form-control"
                    required
                    value="<?= htmlspecialchars($category['category_name']); ?>">

            </div>

            <button
                type="submit"
                class="btn btn-primary">

                Update Category

            </button>

            <a href="index.php"
               class="btn btn-secondary">

                Back

            </a>

        </form>

    </div>

</div>

<?php
require_once '../includes/footer.php';
?>
