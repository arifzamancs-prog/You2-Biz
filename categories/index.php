<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/expense_helper.php';

$user_id = $_SESSION['user_id'];
ensure_expense_support_tables($conn, $user_id);

$sql = "SELECT *
        FROM categories
        WHERE user_id=?
        AND COALESCE(is_hidden, 0)=0
        ORDER BY id DESC";

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
            Expence Category List
        </h3>

        <?php if(manager_can_modify()){ ?>

        <div class="card-tools">

            <a href="create.php"
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
                <th>Category</th>
                <th>Status</th>
                <?php if(manager_can_modify()){ ?>
                    <th width="220">Action</th>
                <?php } ?>
            </tr>

            </thead>

            <tbody>

            <?php while($row = mysqli_fetch_assoc($result)){ ?>

            <tr>

                <td>
                    <?= htmlspecialchars($row['category_name']); ?>
                </td>

                <td>

                    <?php if($row['status']=='active'){ ?>

                        <span class="badge badge-success">
                            Active
                        </span>

                    <?php }else{ ?>

                        <span class="badge badge-danger">
                            Inactive
                        </span>

                    <?php } ?>

                </td>

                <?php if(manager_can_modify()){ ?>
                <td>

                    <a href="edit.php?id=<?= $row['id']; ?>"
                       class="btn btn-info btn-sm">

                        <i class="fas fa-edit"></i>
                        Edit

                    </a>

                    <?php if($row['status']=='active'){ ?>

                        <a href="inactive.php?id=<?= $row['id']; ?>"
                           class="btn btn-danger btn-sm"
                           onclick="return confirm('Inactive this category?')">

                            <i class="fas fa-ban"></i>
                            Inactive

                        </a>

                    <?php }else{ ?>

                        <a href="active.php?id=<?= $row['id']; ?>"
                           class="btn btn-success btn-sm"
                           onclick="return confirm('Activate this category?')">

                            <i class="fas fa-check"></i>
                            Active

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
