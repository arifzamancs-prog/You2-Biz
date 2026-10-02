<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_image_helper.php';
require_once '../includes/project_package_helper.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);

if (project_package_company_type($conn, $user_id) !== 'Fashion house') {
    header('Location: create_invoice.php');
    exit;
}

if (is_manager_user() && !manager_has_permission('stock_sales')) {
    http_response_code(403);
    exit('Sales access is required.');
}

$branch_id = selected_branch_id($conn, true);
if ($branch_id <= 0) {
    http_response_code(403);
    exit('Current branch is not configured.');
}

$branch_stmt = mysqli_prepare($conn, 'SELECT branch_name,is_head_office FROM branches WHERE id=? AND user_id=? AND status=\'active\' LIMIT 1');
mysqli_stmt_bind_param($branch_stmt, 'ii', $branch_id, $user_id);
mysqli_stmt_execute($branch_stmt);
$current_branch = mysqli_fetch_assoc(mysqli_stmt_get_result($branch_stmt));

if (!$current_branch) {
    http_response_code(403);
    exit('Current branch is not available.');
}

$current_branch_label = (int)($current_branch['is_head_office'] ?? 0) === 1
    ? 'Head Office'
    : (string)($current_branch['branch_name'] ?? 'Current Branch');

$stock_sql = "SELECT p.id,p.product_name,p.sku,p.photo_path,p.sale_price,
                     COALESCE(SUM(sb.remaining_quantity), 0) AS on_hand,
                     COALESCE(SUM(sb.remaining_quantity * sb.unit_cost), 0) AS stock_cost,
                     (SELECT COALESCE(SUM(ii.quantity), 0)
                      FROM invoice_items ii
                      INNER JOIN invoices i ON i.id=ii.invoice_id
                      WHERE i.user_id=p.user_id
                        AND i.branch_id=?
                        AND i.accounting_status='pending'
                        AND ii.product_id=p.id
                        AND ii.quantity>0) AS reserved,
                     (
                        (SELECT COALESCE(SUM(d.damaged_quantity),0) FROM stock_distributions d
                         WHERE d.user_id=p.user_id AND d.product_id=p.id AND d.to_branch_id=? AND d.status='accepted')
                        -
                        (SELECT COALESCE(SUM(dr.quantity),0) FROM stock_damage_returns dr
                         WHERE dr.user_id=p.user_id AND dr.product_id=p.id AND dr.branch_id=? AND dr.status IN ('accepted','dump_pending','dumped'))
                     ) AS damaged
              FROM products p
              INNER JOIN product_categories c ON c.id=p.category_id
              LEFT JOIN stock_batches sb ON sb.product_id=p.id
                  AND sb.user_id=p.user_id
                  AND sb.branch_id=?
              WHERE p.user_id=?
                AND p.status='active'
                AND c.category_type='stock_product'
              GROUP BY p.id,p.product_name,p.sku,p.photo_path,p.sale_price,p.user_id
              HAVING on_hand<>0 OR reserved<>0 OR damaged<>0
              ORDER BY p.product_name ASC";
$stock_stmt = mysqli_prepare($conn, $stock_sql);
mysqli_stmt_bind_param($stock_stmt, 'iiiii', $branch_id, $branch_id, $branch_id, $branch_id, $user_id);
mysqli_stmt_execute($stock_stmt);
$stock_result = mysqli_stmt_get_result($stock_stmt);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<section class="content"><div class="container-fluid"><div class="card card-outline card-primary">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-boxes mr-2"></i>My Stock — <?= htmlspecialchars($current_branch_label); ?></h3><div class="card-tools"><div class="input-group input-group-sm" style="width:260px"><div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div><input type="search" id="my-stock-search" class="form-control" placeholder="Search product or Code" aria-label="Search stock"></div></div></div>
    <div class="card-body">
        <p class="text-muted">Only stock available in the current branch is shown here.</p>
        <div class="table-responsive"><table class="table table-bordered table-striped mb-0" id="my-stock-table">
            <thead><tr><th>Photo</th><th>Product</th><th>Code</th><th>On Hand</th><th>Reserved</th><th>Damaged</th><th>Available</th><th>Stock Cost</th><th>Sale Value</th></tr></thead>
            <tbody>
            <?php if ($stock_result && mysqli_num_rows($stock_result) > 0) { while ($row = mysqli_fetch_assoc($stock_result)) {
                $variant_stmt = mysqli_prepare($conn, "SELECT variant_name,COALESCE(SUM(remaining_quantity),0) AS quantity FROM stock_batches WHERE user_id=? AND product_id=? AND branch_id=? AND variant_name<>'' GROUP BY variant_name ORDER BY variant_name");
                mysqli_stmt_bind_param($variant_stmt, 'iii', $user_id, $row['id'], $branch_id);
                mysqli_stmt_execute($variant_stmt);
                $variant_result = mysqli_stmt_get_result($variant_stmt);
                $variants = [];
                while ($variant = mysqli_fetch_assoc($variant_result)) {
                    $variants[] = htmlspecialchars($variant['variant_name']) . ': ' . number_format((float)$variant['quantity'], 0);
                }
                mysqli_stmt_close($variant_stmt);
                $damage_stmt = mysqli_prepare($conn, "SELECT variant_name,SUM(quantity) AS quantity FROM (
                    SELECT variant_name,damaged_quantity AS quantity FROM stock_distributions WHERE user_id=? AND product_id=? AND to_branch_id=? AND status='accepted' AND damaged_quantity>0
                    UNION ALL
                    SELECT variant_name,-quantity AS quantity FROM stock_damage_returns WHERE user_id=? AND product_id=? AND branch_id=? AND status IN ('accepted','dump_pending','dumped')
                ) damaged_by_variant GROUP BY variant_name ORDER BY variant_name");
                mysqli_stmt_bind_param($damage_stmt, 'iiiiii', $user_id, $row['id'], $branch_id, $user_id, $row['id'], $branch_id);
                mysqli_stmt_execute($damage_stmt); $damage_result = mysqli_stmt_get_result($damage_stmt); $damages = [];
                while ($damage = mysqli_fetch_assoc($damage_result)) if ((float)$damage['quantity'] > 0) $damages[] = htmlspecialchars($damage['variant_name'] ?: 'Qty') . ': ' . number_format((float)$damage['quantity'], 0);
                mysqli_stmt_close($damage_stmt);
                // Damaged transfer items never entered branch inventory, so
                // they are displayed separately and do not reduce on-hand stock.
                $available = max(0, (float)$row['on_hand'] - (float)$row['reserved']);
            ?>
                <tr data-stock-search="<?= htmlspecialchars(strtolower($row['product_name'] . ' ' . ($row['sku'] ?? '') . ' ' . implode(' ', $variants)), ENT_QUOTES, 'UTF-8'); ?>">
                    <td><img src="<?= htmlspecialchars(product_image_url($conn, $row['photo_path'] ?? '')); ?>" alt="" style="width:38px;height:38px;object-fit:cover;border-radius:3px"></td>
                    <td><?= htmlspecialchars($row['product_name']); ?><?php if ($variants) { ?><small class="d-block text-muted"><?= implode(' || ', $variants); ?></small><?php } ?></td>
                    <td><?= htmlspecialchars($row['sku'] ?? ''); ?></td>
                    <td><?= number_format((float)$row['on_hand'], 0); ?></td>
                    <td><?= number_format((float)$row['reserved'], 0); ?></td>
                    <td class="text-danger"><?= number_format((float)$row['damaged'], 0); ?><?php if ($damages) { ?><small class="d-block text-muted"><?= implode(' || ', $damages); ?></small><?php } ?></td>
                    <td><?= number_format($available, 0); ?></td>
                    <td><?= number_format((float)$row['stock_cost'], 2); ?></td>
                    <td><?= number_format((float)$row['on_hand'] * (float)$row['sale_price'], 2); ?></td>
                </tr>
            <?php } } else { ?><tr><td colspan="9" class="text-center text-muted py-4">No stock is available in this branch.</td></tr><?php } ?>
            </tbody>
        </table></div>
        <div id="my-stock-no-results" class="text-center text-muted py-4 d-none">No matching product was found.</div>
    </div>
</div></div></section>
<script>
document.addEventListener('DOMContentLoaded', function(){
    const search = document.getElementById('my-stock-search');
    const rows = Array.from(document.querySelectorAll('#my-stock-table tbody tr[data-stock-search]'));
    const noResults = document.getElementById('my-stock-no-results');
    if(!search || !rows.length) return;
    search.addEventListener('input', function(){
        const term = search.value.trim().toLowerCase();
        let matched = 0;
        rows.forEach(function(row){
            const visible = !term || row.dataset.stockSearch.includes(term);
            row.classList.toggle('d-none', !visible);
            if(visible) matched++;
        });
        noResults.classList.toggle('d-none', matched > 0);
    });
});
</script>
<?php require_once '../includes/footer.php'; ?>
