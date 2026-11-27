<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/product_expiry_helper.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_category_helper.php';
require_once '../includes/product_image_helper.php';
require_once '../includes/project_package_helper.php';
require_once '../includes/barcode_helper.php';

// Use the company's saved type so the Restaurant & Cafe catalogue is shown
// even if an older staff session has not yet refreshed its session value.
if(restaurant_catalog_enabled($conn, (int)$_SESSION['user_id'])){
    require_once '../restaurant/products_index.php';
    exit;
}

$user_id = $_SESSION['user_id'];
// Inventory & Sales and Fashion retail: Edit stays open; the edit page itself locks
// only the fields that cannot change after transactions.
require_once '../includes/company_settings_helper.php';
$product_edit_always = in_array(normalize_company_type($_SESSION['company_type'] ?? ''), ['Stock Product', 'Fashion house'], true);
$variants_enabled = product_variants_enabled($conn, $user_id);
$product_photos_enabled = product_photos_enabled($conn, $user_id);
ensure_product_management_columns($conn);
ensure_product_image_column($conn);
ensure_fifo_inventory_tables($conn);
ensure_fifo_only_product_categories($conn, $user_id);
ensure_product_subcategory_schema($conn);
$show_expired_on = is_product_expiry_enabled($conn);
$multi_branch = company_multi_branch_enabled($conn, $user_id);
$is_fashion_house = project_package_company_type($conn, $user_id) === 'Fashion house';
// Every product shows its photo at the left of the list; one without a photo shows the
// default (company logo) picture.
$product_photos_enabled = true;
// Product setup is central. With Multi Branch enabled, its stock value means
// Main Warehouse stock; branches sell only after receiving a distribution.
$stock_branch_id = $multi_branch
    ? stock_warehouse_id($conn, $user_id)
    : stock_head_office_id($conn, $user_id);
$stock_filter = $stock_branch_id > 0 ? ' AND sb.branch_id=' . $stock_branch_id : '';
if (empty($_SESSION['product_delete_csrf'])) $_SESSION['product_delete_csrf'] = bin2hex(random_bytes(32));
// The pencil beside Sale Price (AJAX, products/update_sale_price.php) for those who see Edit.
$can_edit_sale_price = manager_can_modify() && stock_can_manage_warehouse($conn);
if (empty($_SESSION['product_price_token'])) $_SESSION['product_price_token'] = bin2hex(random_bytes(32));

$sql = "SELECT
            p.*,
            (SELECT COALESCE(SUM(sb.remaining_quantity),0) FROM stock_batches sb WHERE sb.product_id=p.id AND sb.user_id=p.user_id {$stock_filter}) AS current_stock,
            (SELECT COALESCE(SUM(sb.remaining_quantity),0) FROM stock_batches sb WHERE sb.product_id=p.id AND sb.user_id=p.user_id) AS total_stock,
            c.category_name,
            p.sub_category AS product_sub_category,
            c.category_type
        FROM products p
        LEFT JOIN product_categories c
            ON c.id = p.category_id
        WHERE p.user_id=?
        ORDER BY p.id DESC";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param($stmt, 'i', $user_id);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$product_rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
// Each product's own barcode (the one its labels carry); missing ones are created here.
$product_barcodes = product_barcodes_for_items(
    $conn,
    $user_id,
    array_map(fn($product) => ['product' => $product, 'variant' => '', 'position' => 0], $product_rows)
);

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
        <div id="product-message" role="status"></div>

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
            #product-list .product-name-column{min-width:240px}
            #product-list .product-barcode-column{min-width:150px;white-space:nowrap}
            #product-list .purchase-price-column{width:1%;white-space:nowrap}
            #product-list .sale-price-column{min-width:100px!important;width:100px!important;white-space:nowrap}
            /* Branch Stock and Total Stock: only as wide as their numbers need. */
            #product-list .all-branch-stock-column,#product-list .branch-stock-column{width:1%!important;min-width:85px!important;white-space:normal}
            #product-list .product-action-column{width:76px!important;min-width:76px!important;white-space:nowrap}
            #product-list.inventory-sales-product-list{table-layout:auto}
            #product-list .product-code-column{min-width:90px;white-space:nowrap}
            #product-list.inventory-sales-product-list .inventory-product-column{min-width:200px}
            #product-list.inventory-sales-product-list .purchase-price-column{width:125px;min-width:125px;word-break:normal;overflow-wrap:normal}
            .product-photo-preview{padding:0;border:0;background:transparent;cursor:zoom-in;line-height:0}
            .product-photo-preview img{transition:transform .15s ease}
            .product-photo-preview:hover img{transform:scale(1.08)}
        </style>
        <table
            id="product-list" data-csrf="<?= htmlspecialchars($_SESSION['product_delete_csrf']) ?>" data-stock-csrf="<?= htmlspecialchars(stock_csrf_token()) ?>"
            class="table table-bordered table-striped<?= !$product_photos_enabled ? ' inventory-sales-product-list' : '' ?>">

            <thead>

            <tr>

                <?php if($product_photos_enabled){ ?><th class="photo-column">Photo</th><?php } ?>
                <th class="product-name-column<?= !$product_photos_enabled ? ' inventory-product-column' : '' ?>">Product</th>
                <th class="product-code-column">Code</th>
                <th class="product-barcode-column">Barcode</th>
                <th class="purchase-price-column">Purchase Price</th>
                <th class="sale-price-column">Sale Price</th>
                <?php if($multi_branch){ ?>
                    <th class="branch-stock-column">Branch Stock</th>
                <?php } ?>
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

            <?php foreach($product_rows as $row){ ?>

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
            $variant_quantities = [];
            while($variant_stock = mysqli_fetch_assoc($variant_stocks)){
                $variant_quantities[$variant_stock['variant_name']] = $variant_stock['quantity'];
            }
            mysqli_stmt_close($variant_stock_stmt);
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
                <?php if($product_photos_enabled){ ?><td><button type="button" class="product-photo-preview" data-photo-src="<?= htmlspecialchars(product_image_url($conn, $row['photo_path'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-photo-alt="<?= htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8'); ?>" title="Click to view larger photo"><img src="<?= htmlspecialchars(product_image_url($conn, $row['photo_path'] ?? '')); ?>" alt="<?= htmlspecialchars($row['product_name']); ?>" style="width:46px;height:46px;object-fit:cover;border-radius:4px"></button></td><?php } ?>

                <td class="product-name-column<?= !$product_photos_enabled ? ' inventory-product-column' : '' ?>">
                    <?= htmlspecialchars($row['product_name']); ?>
                </td>

                <td class="product-code-column"><?= trim((string)$row['sku']) !== '' ? htmlspecialchars($row['sku']) : '-'; ?></td>

                <?php $product_barcode = $product_barcodes[(int)$row['id']][''] ?? ''; ?>
                <td class="product-barcode-column">
                    <?php if($product_barcode !== ''){ ?>
                        <span class="product-barcode-img" role="button" tabindex="0" data-barcode="<?= htmlspecialchars($product_barcode, ENT_QUOTES, 'UTF-8'); ?>" data-product-id="<?= (int)$row['id']; ?>" data-product-name="<?= htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8'); ?>" title="<?= htmlspecialchars($product_barcode, ENT_QUOTES, 'UTF-8'); ?> (click to print)" aria-label="Print barcode of <?= htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8'); ?>"><span class="d-none"><?= htmlspecialchars($product_barcode); ?></span></span>
                    <?php }else{ ?>
                        -
                    <?php } ?>
                </td>

                <td class="purchase-price-column">
                    BDT <?= number_format($row['purchase_price'],2); ?>
                </td>

                <td class="sale-price-column">
                    <span class="ppe-value">BDT <?= number_format($row['sale_price'],2); ?></span>
                    <?php if($can_edit_sale_price){ ?><button type="button" class="btn btn-link btn-sm p-0 ml-1 product-price-edit" data-id="<?= (int)$row['id']; ?>" data-price="<?= htmlspecialchars(number_format((float)$row['sale_price'], 2, '.', '')); ?>" title="Change sale price" aria-label="Change sale price of <?= htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-pen"></i></button><?php } ?>
                </td>

                <?php if($multi_branch){ ?>
                    <?php // Branch Stock: every branch except the Main Warehouse (Total Stock − Warehouse Stock). ?>
                    <td class="branch-stock-column">
                        <?php if($row['category_type'] === 'non_stock'){ ?>
                            Not tracked
                        <?php }else{ ?>
                            <?= number_format(max(0, (float)$row['total_stock'] - (float)$row['current_stock']), 0); ?>
                            <?php
                            $branch_variant_parts = [];
                            $branch_variant_order = $variants_enabled
                                ? array_unique(array_merge(product_variant_names($conn, (int)$row['id'], $user_id), array_keys($total_variant_quantities)))
                                : [];
                            foreach($branch_variant_order as $variant_name){
                                $branch_quantity = (float)($total_variant_quantities[$variant_name] ?? 0) - (float)($variant_quantities[$variant_name] ?? 0);
                                if($branch_quantity > 0){
                                    $branch_variant_parts[] = htmlspecialchars($variant_name) . ': ' . number_format($branch_quantity, 0);
                                }
                            }
                            ?>
                            <?php if($branch_variant_parts){ ?><small class="d-block text-muted"><?= implode(' || ', $branch_variant_parts); ?></small><?php } ?>
                        <?php } ?>
                    </td>
                <?php } ?>

                <?php if($show_expired_on){ ?>
                    <td><?= htmlspecialchars(app_date($row['expired_on'] ?? '')); ?></td>
                <?php } ?>

                <td>
                    <?= $row['category_type'] === 'non_stock' ? 'Not tracked' : number_format($row['current_stock'], 0); ?>
                    <?php
                    $variant_order = $variants_enabled
                        ? array_unique(array_merge(product_variant_names($conn, (int)$row['id'], $user_id), array_keys($variant_quantities)))
                        : [];
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
                        <?= $row['category_type'] === 'non_stock' ? 'Not tracked' : number_format($row['total_stock'], 0); ?>
                        <?php
                        $total_variant_order = $variants_enabled
                            ? array_unique(array_merge(
                                product_variant_names($conn, (int)$row['id'], $user_id),
                                array_keys($total_variant_quantities)
                            ))
                            : [];
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

                    <?php if(!$product_has_transactions || $product_edit_always){ ?>
                        <a
                            href="edit.php?id=<?= $row['id']; ?>"
                            class="btn btn-warning btn-sm"
                            title="<?= $product_has_transactions ? 'Edit Product (category, purchase price and opening stock are locked)' : 'Edit Product' ?>">

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
                        <button type="button"
                            data-product-id="<?= (int)$row['id']; ?>"
                            class="btn btn-danger btn-sm product-delete"
                            title="Delete Product">

                            <i class="fas fa-trash"></i>

                        </button>
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
$product_list_non_sortable_columns = $product_photos_enabled ? '[0]' : '[]';
// Clicking a barcode picture opens the barcode print popup.
$barcode_popup_config = [
    'url' => app_path('products/barcode_print.php'),
    'labelsUrl' => is_admin_user() && in_array(project_package_company_type($conn, (int)$user_id), ['Stock Product', 'Fashion house'], true)
        ? app_path('user_management/barcode_labels.php')
        : '',
];
$page_script = '<script>window.PRODUCT_PRICE_EDIT = ' . json_encode(['url' => app_path('products/update_sale_price.php'), 'token' => $_SESSION['product_price_token']], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>
<script src="' . htmlspecialchars(app_path('assets/js/product_price_edit.js') . '?v=' . @filemtime(dirname(__DIR__) . '/assets/js/product_price_edit.js')) . '"></script>
<script src="' . htmlspecialchars(app_path('adminlte/plugins/jsbarcode/JsBarcode.code128.min.js')) . '"></script>
<script>window.BARCODE_PRINT = ' . json_encode($barcode_popup_config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>
<script src="' . htmlspecialchars(app_path('assets/js/barcode_print_popup.js') . '?v=' . @filemtime(dirname(__DIR__) . '/assets/js/barcode_print_popup.js')) . '"></script>
' . str_replace('__NON_SORTABLE_COLUMNS__', $product_list_non_sortable_columns, <<<'SCRIPT'
<script>
$(function () {
    const table = $('#product-list').DataTable({
        responsive: false, autoWidth: false, order: [], pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        dom: '<"row align-items-center mb-3"<"col-md-6"l><"col-md-6"f>>rtip',
        columnDefs: [{targets: __NON_SORTABLE_COLUMNS__, orderable: false, searchable: false}]
    });
    $(document).on('click', '.product-delete', async function(){
        if (!confirm('Delete this product?')) return;
        const button=this; button.disabled=true;
        try {
            const response=await fetch('delete.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'}, body:new URLSearchParams({id:button.dataset.productId,csrf:$('#product-list').attr('data-csrf'),stock_csrf:$('#product-list').attr('data-stock-csrf')})});
            const responseText=await response.text();
            let data;
            try { data=JSON.parse(responseText); }
            catch (_) { throw new Error(response.status===403 ? 'Request could not be verified. Reload this page and try again.' : 'Unexpected server response. Reload this page and try again.'); }
            if (!response.ok || !data.success) throw new Error(data.message || 'Delete failed.');
            table.row($(button).closest('tr')).remove().draw(false);
            $('#product-message').attr('class','alert alert-success').text('Product deleted.');
        } catch(error) { $('#product-message').attr('class','alert alert-danger').text(error.message); }
        finally {button.disabled=false;}
    });
    $(document).on('click', '.product-photo-preview', function () {
        $('#product-photo-preview-image').attr({src: this.dataset.photoSrc, alt: this.dataset.photoAlt || 'Product photo'});
        $('#product-photo-preview-title').text(this.dataset.photoAlt || 'Product Photo');
        $('#product-photo-preview-modal').modal('show');
    });
});
</script>
SCRIPT
);
require_once '../includes/footer.php';
?>
