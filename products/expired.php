<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/product_expiry_helper.php';

$user_id = (int)$_SESSION['user_id'];

ensure_product_management_columns($conn);

$sql = "SELECT
            p.*,
            c.category_name
        FROM products p
        LEFT JOIN product_categories c
            ON c.id = p.category_id
        WHERE p.user_id=?
        AND p.expired_on IS NOT NULL
        AND p.expired_on <> '0000-00-00'
        AND p.expired_on < CURDATE()
        ORDER BY p.expired_on ASC, p.id DESC";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">
            <i class="fas fa-calendar-times mr-2"></i>
            Expired Product
        </h3>

    </div>

    <div class="card-body">

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
                <th>Expired on</th>
                <th>Stock</th>
                <th>Status</th>
            </tr>
            </thead>

            <tbody>

            <?php while($row = mysqli_fetch_assoc($result)){ ?>

            <tr>
                <td><?= htmlspecialchars($row['product_name']); ?></td>
                <td><?= htmlspecialchars($row['category_name']); ?></td>
                <td><?= htmlspecialchars($row['sku']); ?></td>
                <td>BDT <?= number_format((float)$row['purchase_price'], 2); ?></td>
                <td>BDT <?= number_format((float)$row['sale_price'], 2); ?></td>
                <td><?= htmlspecialchars(app_date($row['expired_on'] ?? '')); ?></td>
                <td><?= number_format((float)$row['current_stock'], 0); ?></td>
                <td>
                    <?php if($row['status'] == 'active'){ ?>
                        <span class="badge badge-success">Active</span>
                    <?php }else{ ?>
                        <span class="badge badge-danger">Inactive</span>
                    <?php } ?>
                </td>
            </tr>

            <?php } ?>

            </tbody>

        </table>

    </div>

</div>

<?php
require_once '../includes/footer.php';
?>
