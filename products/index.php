<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/product_expiry_helper.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_category_helper.php';

$user_id = $_SESSION['user_id'];
ensure_product_management_columns($conn);
ensure_fifo_inventory_tables($conn);
ensure_fifo_only_product_categories($conn, $user_id);
$show_expired_on = is_product_expiry_enabled($conn);
$stock_branch_id = selected_branch_id($conn, false);
$stock_filter = $stock_branch_id > 0 ? ' AND sb.branch_id=' . $stock_branch_id : '';

$sql = "SELECT
            p.*,
            (SELECT COALESCE(SUM(sb.remaining_quantity),0) FROM stock_batches sb WHERE sb.product_id=p.id AND sb.user_id=p.user_id {$stock_filter}) AS current_stock,
            c.category_name,
            c.category_type
        FROM products p
        LEFT JOIN product_categories c
            ON c.id = p.category_id
        WHERE p.user_id=?
        ORDER BY p.id DESC";

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

            <i class="fas fa-box mr-2"></i>

            Products

        </h3>

        <?php if(manager_can_modify() && stock_can_manage_warehouse($conn)){ ?>

        <div class="card-tools">

            <a
                href="create.php"
                class="btn btn-primary btn-sm">

                <i class="fas fa-plus"></i>

                Add Product

            </a>

        </div>

        <?php } ?>

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

        <table
            id="example1"
            class="table table-bordered table-striped">

            <thead>

            <tr>

                <th>Product</th>
                <th>Category</th>
                <th>SKU</th>
                <th>Purchase</th>
                <th>Sale</th>
                <?php if($show_expired_on){ ?>
                    <th>Expiry on</th>
                <?php } ?>
                <th>Stock</th>
                <th>Status</th>
                <?php if(manager_can_modify() && stock_can_manage_warehouse($conn)){ ?>
                    <th width="150">Action</th>
                <?php } ?>

            </tr>

            </thead>

            <tbody>

            <?php while($row = mysqli_fetch_assoc($result)){ ?>

            <?php
            $product_has_transactions = product_has_transactions($conn, (int)$row['id'], $user_id);
            ?>

            <tr>

                <td>
                    <?= htmlspecialchars($row['product_name']); ?>
                </td>

                <td>
                    <?= htmlspecialchars($row['category_name']); ?>
                </td>

                <td>
                    <?= htmlspecialchars($row['sku']); ?>
                </td>

                <td>
                    BDT <?= number_format($row['purchase_price'],2); ?>
                </td>

                <td>
                    BDT <?= number_format($row['sale_price'],2); ?>
                </td>

                <?php if($show_expired_on){ ?>
                    <td><?= htmlspecialchars(app_date($row['expired_on'] ?? '')); ?></td>
                <?php } ?>

                <td>
                    <?= number_format($row['current_stock'], 0); ?>
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

                <?php if(manager_can_modify() && stock_can_manage_warehouse($conn)){ ?>
                <td>

                    <?php if(!$product_has_transactions){ ?>
                        <a
                            href="edit.php?id=<?= $row['id']; ?>"
                            class="btn btn-warning btn-sm"
                            title="Edit Product">

                            <i class="fas fa-edit"></i>

                        </a>

                    <?php }else{ ?>
                        <button
                            type="button"
                            class="btn btn-warning btn-sm"
                            disabled
                            title="This product is locked because it already has transactions.">

                            <i class="fas fa-edit"></i>

                        </button>
                    <?php } ?>

                    <?php if(!$product_has_transactions){ ?>
                        <a
                            href="delete.php?id=<?= $row['id']; ?>"
                            class="btn btn-danger btn-sm"
                            title="Delete Product"
                            onclick="return confirm('Delete this product?')">

                            <i class="fas fa-trash"></i>

                        </a>
                    <?php }else{ ?>
                        <button
                            type="button"
                            class="btn btn-danger btn-sm"
                            disabled
                            title="This product is locked because it already has transactions.">

                            <i class="fas fa-trash"></i>

                        </button>
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
