<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/product_expiry_helper.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_category_helper.php';
require_once '../includes/product_image_helper.php';
require_once '../includes/project_package_helper.php';

$user_id = $_SESSION['user_id'];
ensure_product_management_columns($conn);
ensure_product_image_column($conn);
ensure_fifo_inventory_tables($conn);
ensure_fifo_only_product_categories($conn, $user_id);
ensure_product_subcategory_schema($conn);
$show_expired_on = is_product_expiry_enabled($conn);
$multi_branch = company_multi_branch_enabled($conn, $user_id);
$is_fashion_house = project_package_company_type($conn, $user_id) === 'Fashion house';
// Product setup is central. With Multi Branch enabled, its stock value means
// Main Warehouse stock; branches sell only after receiving a distribution.
$stock_branch_id = $multi_branch
    ? stock_warehouse_id($conn, $user_id)
    : stock_head_office_id($conn, $user_id);
$stock_filter = $stock_branch_id > 0 ? ' AND sb.branch_id=' . $stock_branch_id : '';

$sql = "SELECT
            p.*,
            (SELECT COALESCE(SUM(sb.remaining_quantity),0) FROM stock_batches sb WHERE sb.product_id=p.id AND sb.user_id=p.user_id {$stock_filter}) AS current_stock,
            (SELECT COALESCE(SUM(sb.remaining_quantity),0) FROM stock_batches sb WHERE sb.product_id=p.id AND sb.user_id=p.user_id) AS total_stock,
            c.category_name,
            c.sub_category AS category_sub_category,
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

        <style>
            #example1 .all-branch-stock-column{min-width:190px!important;width:190px!important}
            #example1 .product-action-column{width:88px!important;min-width:88px!important;white-space:nowrap}
            .product-photo-preview{padding:0;border:0;background:transparent;cursor:zoom-in;line-height:0}
            .product-photo-preview img{transition:transform .15s ease}
            .product-photo-preview:hover img{transform:scale(1.08)}
        </style>
        <table
            id="example1"
            class="table table-bordered table-striped">

            <thead>

            <tr>

                <th>Photo</th>
                <th>Product</th>
                <th>Category</th>
                <th>Code</th>
                <th>Purchase Price</th>
                <th>Sale Price</th>
                <?php if($show_expired_on){ ?>
                    <th>Expiry on</th>
                <?php } ?>
                <th><?= $multi_branch ? 'Warehouse Stock' : 'Stock'; ?></th>
                <?php if($multi_branch){ ?>
                    <th class="all-branch-stock-column"><?= $is_fashion_house ? 'All Branch Stock' : 'Total Stock'; ?></th>
                <?php } ?>
                <th>Status</th>
                <?php if(manager_can_modify() && stock_can_manage_warehouse($conn)){ ?>
                    <th class="product-action-column">Action</th>
                <?php } ?>

            </tr>

            </thead>

            <tbody>

            <?php while($row = mysqli_fetch_assoc($result)){ ?>

            <?php
            $product_has_transactions = product_has_transactions($conn, (int)$row['id'], $user_id);
            $variant_stock_stmt = mysqli_prepare(
                $conn,
                'SELECT variant_name, COALESCE(SUM(remaining_quantity),0) AS quantity
                 FROM stock_batches
                 WHERE product_id=? AND user_id=? AND branch_id=? AND variant_name<>\'\'
                 GROUP BY variant_name ORDER BY variant_name'
            );
            mysqli_stmt_bind_param($variant_stock_stmt, 'iii', $row['id'], $user_id, $stock_branch_id);
            mysqli_stmt_execute($variant_stock_stmt);
            $variant_stocks = mysqli_stmt_get_result($variant_stock_stmt);
            $total_variant_quantities = [];

            if($multi_branch){
                $total_variant_stock_stmt = mysqli_prepare(
                    $conn,
                    'SELECT variant_name, COALESCE(SUM(remaining_quantity),0) AS quantity
                     FROM stock_batches
                     WHERE product_id=? AND user_id=? AND variant_name<>\'\'
                     GROUP BY variant_name'
                );
                mysqli_stmt_bind_param($total_variant_stock_stmt, 'ii', $row['id'], $user_id);
                mysqli_stmt_execute($total_variant_stock_stmt);
                $total_variant_stocks = mysqli_stmt_get_result($total_variant_stock_stmt);

                while($total_variant_stock = mysqli_fetch_assoc($total_variant_stocks)){
                    $total_variant_quantities[$total_variant_stock['variant_name']] = $total_variant_stock['quantity'];
                }

                mysqli_stmt_close($total_variant_stock_stmt);
            }
            ?>

            <tr>

                <td><button type="button" class="product-photo-preview" data-photo-src="<?= htmlspecialchars(product_image_url($conn, $row['photo_path'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-photo-alt="<?= htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8'); ?>" title="Click to view larger photo"><img src="<?= htmlspecialchars(product_image_url($conn, $row['photo_path'] ?? '')); ?>" alt="<?= htmlspecialchars($row['product_name']); ?>" style="width:46px;height:46px;object-fit:cover;border-radius:4px"></button></td>

                <td>
                    <?= htmlspecialchars($row['product_name']); ?>
                </td>

                <td>
                    <?= htmlspecialchars($row['category_name']); ?>
                    <?php if(!empty($row['category_sub_category'])){ ?><div class="small text-muted">[<?= htmlspecialchars($row['category_sub_category']); ?>]</div><?php } ?>
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
                    <?php
                    $variant_quantities = [];
                    while($variant_stock = mysqli_fetch_assoc($variant_stocks)){
                        $variant_quantities[$variant_stock['variant_name']] = $variant_stock['quantity'];
                    }
                    mysqli_stmt_close($variant_stock_stmt);
                    $variant_order = array_unique(array_merge(product_variant_names($conn, (int)$row['id'], $user_id), array_keys($variant_quantities)));
                    $variant_parts = [];
                    foreach($variant_order as $variant_name){
                        if(array_key_exists($variant_name, $variant_quantities)){
                            $variant_parts[] = htmlspecialchars($variant_name) . ': ' . number_format($variant_quantities[$variant_name], 0);
                        }
                    }
                    ?>
                    <?php if($variant_parts){ ?><small class="d-block text-muted"><?= implode(' || ', $variant_parts); ?></small><?php } ?>
                </td>

                <?php if($multi_branch){ ?>
                    <td class="all-branch-stock-column">
                        <?= number_format($row['total_stock'], 0); ?>
                        <?php
                        $total_variant_order = array_unique(array_merge(
                            product_variant_names($conn, (int)$row['id'], $user_id),
                            array_keys($total_variant_quantities)
                        ));
                        $total_variant_parts = [];
                        foreach($total_variant_order as $variant_name){
                            if(array_key_exists($variant_name, $total_variant_quantities)){
                                $total_variant_parts[] = htmlspecialchars($variant_name) . ': '
                                    . number_format($total_variant_quantities[$variant_name], 0);
                            }
                        }
                        ?>
                        <?php if($total_variant_parts){ ?><small class="d-block text-muted"><?= implode(' || ', $total_variant_parts); ?></small><?php } ?>
                    </td>
                <?php } ?>

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
                <td class="product-action-column">

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

<div class="modal fade" id="product-photo-preview-modal" tabindex="-1" role="dialog" aria-labelledby="product-photo-preview-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="product-photo-preview-title">Product Photo</h5><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>
            <div class="modal-body text-center p-3"><img id="product-photo-preview-image" src="" alt="" style="max-width:100%;max-height:70vh;object-fit:contain"></div>
        </div>
    </div>
</div>

<?php
$page_script = <<<'SCRIPT'
<script>
$(function () {
    $(document).on('click', '.product-photo-preview', function () {
        $('#product-photo-preview-image').attr({src: this.dataset.photoSrc, alt: this.dataset.photoAlt || 'Product photo'});
        $('#product-photo-preview-title').text(this.dataset.photoAlt || 'Product Photo');
        $('#product-photo-preview-modal').modal('show');
    });
});
</script>
SCRIPT;
require_once '../includes/footer.php';
?>
