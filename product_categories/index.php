<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/product_category_helper.php';

$user_id = $_SESSION['user_id'];

// Also backfill the defaults for companies created before this feature existed.
ensure_default_product_categories($conn, $user_id);

$sql = "SELECT *
        FROM product_categories
        WHERE user_id=?
        ORDER BY id ASC";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">

            <i class="fas fa-tags mr-2"></i>

            Product Categories

        </h3>

        <?php if(manager_can_modify()){ ?>

        <div class="card-tools">

            <a
                href="create.php"
                class="btn btn-primary btn-sm">

                <i class="fas fa-plus"></i>

                Add Category

            </a>

        </div>

        <?php } ?>

    </div>

    <div class="card-body">

        <table
            id="example1"
            class="table table-bordered table-striped">

            <thead>

            <tr>

                <th>Category Name</th>
                <th>Category Type</th>
                <th>Status</th>
                <?php if(manager_can_modify()){ ?>
                    <th width="150">Action</th>
                <?php } ?>

            </tr>

            </thead>

            <tbody>

            <?php while($row = mysqli_fetch_assoc($result)){ ?>

            <tr>

                <td>
                    <?= htmlspecialchars($row['category_name']); ?>
                    <?php if(product_category_is_default($row['category_name'])){ ?>
                        <span class="badge badge-secondary ml-1">Default</span>
                    <?php } ?>
                </td>

                <td>
                    <?= htmlspecialchars(product_category_type_label($row['category_type'] ?? 'non_stock')); ?>
                </td>

                <td>

                    <?php if($row['status']=='active'){ ?>

                        <span class="badge badge-success">

                            Active

                        </span>

                    <?php } else { ?>

                        <span class="badge badge-danger">

                            Inactive

                        </span>

                    <?php } ?>

                </td>

                <?php if(manager_can_modify()){ ?>
                <td>

                    <?php if(product_category_is_default($row['category_name'])){ ?>

                        <span class="text-muted small">Locked</span>

                    <?php } elseif(product_category_has_usage($conn, $row['id'], $user_id)){ ?>

                        <span class="text-muted small">In use</span>

                    <?php } else { ?>

                    <a
                        href="edit.php?id=<?= $row['id']; ?>"
                        class="btn btn-warning btn-sm">

                        <i class="fas fa-edit"></i>

                    </a>

                    <a
                        href="delete.php?id=<?= $row['id']; ?>"
                        class="btn btn-danger btn-sm"
                        onclick="return confirm('Delete this category?')">

                        <i class="fas fa-trash"></i>

                    </a>

                    <?php } ?>

                </td>
                <?php } ?>

            </tr>

            <?php } ?>

            </tbody>

        </table>

    </div>

</div>

<?php
require_once '../includes/footer.php';
?>
