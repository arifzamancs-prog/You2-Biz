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

$sql = "SELECT
            p.*,
            c.category_name,
            c.category_type
        FROM products p
        LEFT JOIN product_categories c
            ON c.id = p.category_id
        WHERE p.user_id=?
        ORDER BY CASE WHEN c.category_type='stock_product' THEN 0 ELSE 1 END, p.id DESC";

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

        <?php if(manager_can_modify()){ ?>

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
                <?php if(manager_can_modify()){ ?>
                    <th width="150">Action</th>
                <?php } ?>

            </tr>

            </thead>

            <tbody>

            <?php $last_product_type = null; $product_column_count = 7 + ($show_expired_on ? 1 : 0) + (manager_can_modify() ? 1 : 0); while($row = mysqli_fetch_assoc($result)){ ?>

            <?php
            $product_has_transactions = product_has_transactions($conn, (int)$row['id'], $user_id);
            $current_product_type = ($row['category_type'] ?? 'non_stock') === 'stock_product' ? 'stock_product' : 'non_stock';
            ?>

            <?php if($current_product_type !== $last_product_type){ $last_product_type = $current_product_type; ?>
            <tr class="table-primary"><td colspan="<?=$product_column_count?>"><strong><?= $current_product_type === 'stock_product' ? 'Stock Products' : 'Non Stock/Service Products' ?></strong></td></tr>
            <?php } ?>

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
                    <?php if(($row['category_type'] ?? 'non_stock') === 'stock_product'){ ?>
                        BDT <?= number_format($row['purchase_price'],2); ?>
                    <?php }else{ ?>
                        N/A
                    <?php } ?>
                </td>

                <td>
                    BDT <?= number_format($row['sale_price'],2); ?>
                </td>

                <?php if($show_expired_on){ ?>
                    <td>
                        <?php if(($row['category_type'] ?? 'non_stock') === 'stock_product'){ ?>
                            <?= htmlspecialchars(app_date($row['expired_on'] ?? '')); ?>
                        <?php }else{ ?>
                            N/A
                        <?php } ?>
                    </td>
                <?php } ?>

                <td>
                    <?= ($row['category_type'] ?? 'non_stock') === 'stock_product'
                        ? number_format($row['current_stock'], 0)
                        : 'N/A'; ?>
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
