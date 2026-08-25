<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/expense_helper.php';

$user_id = $_SESSION['user_id'];
ensure_expense_support_tables($conn, $user_id);

$message = '';

if($_SERVER['REQUEST_METHOD']=='POST'){

    $category_name = trim($_POST['category_name']);

    if(expense_category_is_reserved($category_name)){
        $message = "This category name is reserved.";
    } else {

        $sql = "INSERT INTO categories
                (
                    user_id,
                    category_name,
                    status,
                    is_hidden
                )
                VALUES
                (
                    ?,?,
                    'active',
                    0
                )";

        $stmt = mysqli_prepare($conn,$sql);

        mysqli_stmt_bind_param(
            $stmt,
            "is",
            $user_id,
            $category_name
        );

        if(mysqli_stmt_execute($stmt)){

            header("Location:index.php");
            exit;

        }else{

            $message = "Failed To Save Category";

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
            Add Category
        </h3>

    </div>

    <div class="card-body">

        <?php if($message){ ?>

            <div class="alert alert-danger">
                <?= $message; ?>
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

            <button
                type="submit"
                class="btn btn-primary">

                Save Category

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
