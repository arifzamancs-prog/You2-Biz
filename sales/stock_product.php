<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_image_helper.php';
require_once '../includes/project_package_helper.php';
require_once '../includes/product_category_helper.php';
require_once '../includes/pending_invoice_stock_helper.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);

if (!in_array(project_package_company_type($conn, $user_id), ['Stock Product', 'Fashion house'], true)) {
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

/*
|--------------------------------------------------------------------------
| Branch stock adjustments (Add Stock)
|--------------------------------------------------------------------------
| A branch admin/manager can add stock directly to the current branch or
| reduce it with a negative quantity. Added stock becomes a FIFO batch at the
| product's purchase price; reductions consume the oldest branch batches.
*/
function ensure_branch_stock_adjustment_tables($conn)
{
    static $done = false;
    if ($done) return;
    $done = true;
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS branch_stock_adjustments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NOT NULL,
        branch_id BIGINT NOT NULL,
        reference_no VARCHAR(40) NOT NULL,
        note VARCHAR(255) NULL,
        created_by_user_id BIGINT NOT NULL DEFAULT 0,
        created_by_name VARCHAR(150) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_bsa_company_branch (user_id, branch_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS branch_stock_adjustment_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        adjustment_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT NOT NULL,
        branch_id BIGINT NOT NULL,
        product_id BIGINT NOT NULL,
        variant_name VARCHAR(100) NOT NULL DEFAULT '',
        quantity DOUBLE NOT NULL,
        unit_cost DOUBLE NOT NULL DEFAULT 0,
        total_cost DOUBLE NOT NULL DEFAULT 0,
        KEY idx_bsai_adjustment (adjustment_id),
        KEY idx_bsai_company_branch (user_id, branch_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
ensure_branch_stock_adjustment_tables($conn);

// The branch's on-hand quantity for one product/variant from FIFO batches.
function branch_stock_on_hand($conn, $user_id, $branch_id, $product_id, $variant_name)
{
    $stmt = mysqli_prepare($conn, 'SELECT COALESCE(SUM(remaining_quantity),0) AS qty FROM stock_batches WHERE user_id=? AND branch_id=? AND product_id=? AND variant_name=? AND remaining_quantity>0');
    mysqli_stmt_bind_param($stmt, 'iiis', $user_id, $branch_id, $product_id, $variant_name);
    mysqli_stmt_execute($stmt);
    $qty = (float)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['qty'] ?? 0);
    mysqli_stmt_close($stmt);
    return $qty;
}

function branch_stock_product($conn, $user_id, $product_id)
{
    $stmt = mysqli_prepare($conn, "SELECT p.id,p.product_name,p.sku,p.purchase_price FROM products p INNER JOIN product_categories c ON c.id=p.category_id AND c.user_id=p.user_id WHERE p.id=? AND p.user_id=? AND p.status='active' AND c.category_type='stock_product'" . restaurant_catalog_scope_sql($conn, 'p', true) . " LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $product_id, $user_id);
    mysqli_stmt_execute($stmt);
    $product = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    return $product;
}

function my_stock_show_photo($conn, $user_id)
{
    return project_package_company_type($conn, (int)$user_id) === 'Fashion house';
}

function my_stock_rows_html($conn, $user_id, $branch_id)
{
    // Only Fashion Retail shows product photos.
    $show_photo = my_stock_show_photo($conn, $user_id);
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

    ob_start();
    if ($stock_result && mysqli_num_rows($stock_result) > 0) { while ($row = mysqli_fetch_assoc($stock_result)) {
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
            <?php if ($show_photo) { ?><td><img src="<?= htmlspecialchars(product_image_url($conn, $row['photo_path'] ?? '')); ?>" alt="" style="width:38px;height:38px;object-fit:cover;border-radius:3px"></td><?php } ?>
            <td><?= htmlspecialchars($row['product_name']); ?><?php if ($variants) { ?><small class="d-block text-muted"><?= implode(' || ', $variants); ?></small><?php } ?></td>
            <td><?= htmlspecialchars($row['sku'] ?? ''); ?></td>
            <td><?= number_format((float)$row['on_hand'], 0); ?></td>
            <td><?= number_format((float)$row['reserved'], 0); ?></td>
            <td class="text-danger"><?= number_format((float)$row['damaged'], 0); ?><?php if ($damages) { ?><small class="d-block text-muted"><?= implode(' || ', $damages); ?></small><?php } ?></td>
            <td><?= number_format($available, 0); ?></td>
            <td><?= number_format((float)$row['stock_cost'], 2); ?></td>
            <td><?= number_format((float)$row['on_hand'] * (float)$row['sale_price'], 2); ?></td>
        </tr>
    <?php } } else { ?><tr><td colspan="<?= $show_photo ? 9 : 8 ?>" class="text-center text-muted py-4">No stock is available in this branch.</td></tr><?php }
    return ob_get_clean();
}

// Only the company administrator may edit or delete Stock Add History.
function branch_stock_can_manage_history()
{
    return is_admin_user() && !is_manager_user();
}

// Loads one adjustment with its items and their FIFO batches (locked when $for_update).
function branch_stock_load_adjustment($conn, $user_id, $branch_id, $adjustment_id, $for_update = false)
{
    $lock = $for_update ? ' FOR UPDATE' : '';
    $stmt = mysqli_prepare($conn, 'SELECT id,reference_no,note FROM branch_stock_adjustments WHERE id=? AND user_id=? AND branch_id=? LIMIT 1' . $lock);
    mysqli_stmt_bind_param($stmt, 'iii', $adjustment_id, $user_id, $branch_id);
    mysqli_stmt_execute($stmt);
    $adjustment = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$adjustment) throw new RuntimeException('Stock entry not found in this branch.');
    $stmt = mysqli_prepare($conn, 'SELECT ai.id,ai.product_id,ai.variant_name,ai.quantity,ai.unit_cost,p.product_name,p.sku FROM branch_stock_adjustment_items ai LEFT JOIN products p ON p.id=ai.product_id AND p.user_id=ai.user_id WHERE ai.adjustment_id=? AND ai.user_id=? ORDER BY ai.id' . $lock);
    mysqli_stmt_bind_param($stmt, 'ii', $adjustment_id, $user_id);
    mysqli_stmt_execute($stmt);
    $items = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    $batch_stmt = mysqli_prepare($conn, "SELECT id,quantity,remaining_quantity FROM stock_batches WHERE user_id=? AND branch_id=? AND source_type='branch_adjustment' AND source_id=? LIMIT 1" . $lock);
    foreach ($items as &$item) {
        $item_id = (int)$item['id'];
        mysqli_stmt_bind_param($batch_stmt, 'iii', $user_id, $branch_id, $item_id);
        mysqli_stmt_execute($batch_stmt);
        $item['batch'] = mysqli_fetch_assoc(mysqli_stmt_get_result($batch_stmt)) ?: null;
        $item['label'] = ($item['product_name'] ?? 'Deleted product') . (trim((string)($item['sku'] ?? '')) !== '' ? ' [' . $item['sku'] . ']' : '') . ($item['variant_name'] !== '' ? ' — ' . $item['variant_name'] : '');
        $item['used'] = $item['batch'] ? max(0, (float)$item['batch']['quantity'] - (float)$item['batch']['remaining_quantity']) : 0;
        if ((float)$item['quantity'] <= 0 || !$item['batch']) {
            throw new RuntimeException('This older stock entry cannot be changed.');
        }
    }
    unset($item);
    mysqli_stmt_close($batch_stmt);
    $adjustment['items'] = $items;
    return $adjustment;
}

function my_stock_history_html($conn, $user_id, $branch_id)
{
    // One row per reference: products on separate lines, variants as "XL-12, XXL-2".
    $stmt = mysqli_prepare($conn, "SELECT a.id AS adjustment_id,a.reference_no,a.note,a.created_by_name,a.created_at,
            ai.product_id,ai.variant_name,ai.quantity,p.product_name,p.sku
        FROM (SELECT id,reference_no,note,created_by_name,created_at FROM branch_stock_adjustments WHERE user_id=? AND branch_id=? ORDER BY id DESC LIMIT 100) a
        INNER JOIN branch_stock_adjustment_items ai ON ai.adjustment_id=a.id AND ai.user_id=? AND ai.branch_id=?
        LEFT JOIN products p ON p.id=ai.product_id AND p.user_id=ai.user_id
        ORDER BY a.id DESC, ai.id ASC");
    mysqli_stmt_bind_param($stmt, 'iiii', $user_id, $branch_id, $user_id, $branch_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $entries = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $id = (int)$row['adjustment_id'];
        if (!isset($entries[$id])) {
            $entries[$id] = ['ref' => $row['reference_no'], 'note' => $row['note'], 'by' => $row['created_by_name'], 'date' => $row['created_at'], 'products' => [], 'total' => 0];
        }
        $pid = (int)$row['product_id'];
        if (!isset($entries[$id]['products'][$pid])) {
            $name = $row['product_name'] ?? 'Deleted product';
            $sku = trim((string)($row['sku'] ?? ''));
            $entries[$id]['products'][$pid] = ['label' => $name . ($sku !== '' ? ' [' . $sku . ']' : ''), 'variants' => [], 'qty' => 0];
        }
        $qty = (float)$row['quantity'];
        if ($row['variant_name'] !== '') {
            $entries[$id]['products'][$pid]['variants'][] = $row['variant_name'] . '-' . number_format($qty, 0);
        }
        $entries[$id]['products'][$pid]['qty'] += $qty;
        $entries[$id]['total'] += $qty;
    }
    mysqli_stmt_close($stmt);
    ob_start();
    $can_manage = branch_stock_can_manage_history();
    if ($entries) { foreach ($entries as $entry_id => $entry) {
        ?>
        <tr<?= array_filter(array_column($entry['products'], 'variants')) ? ' data-has-variant="1"' : ''; ?>>
            <td class="text-nowrap"><?= htmlspecialchars(app_date($entry['date'])); ?></td>
            <td class="text-nowrap"><?= htmlspecialchars($entry['ref']); ?></td>
            <td><?php foreach ($entry['products'] as $product) { ?><div><?= htmlspecialchars($product['label']); ?></div><?php } ?></td>
            <td><?php foreach ($entry['products'] as $product) { ?><div><?= $product['variants'] ? htmlspecialchars(implode(', ', $product['variants'])) : '—'; ?></div><?php } ?></td>
            <td class="font-weight-bold"><?php foreach ($entry['products'] as $product) { ?><div><?= number_format($product['qty'], 0); ?></div><?php } ?></td>
            <td><?= htmlspecialchars($entry['by'] ?? ''); ?></td>
            <td><?= htmlspecialchars($entry['note'] ?? ''); ?></td>
            <?php if ($can_manage) { ?><td class="text-nowrap">
                <button type="button" class="btn btn-warning btn-sm adjust-edit" data-id="<?= (int)$entry_id; ?>" title="Edit"><i class="fas fa-edit"></i></button>
                <button type="button" class="btn btn-danger btn-sm adjust-remove" data-id="<?= (int)$entry_id; ?>" data-ref="<?= htmlspecialchars($entry['ref'], ENT_QUOTES, 'UTF-8'); ?>" title="Delete"><i class="fas fa-trash"></i></button>
            </td><?php } ?>
        </tr>
    <?php } }
    // No placeholder row: DataTables shows its own "empty" message.
    return ob_get_clean();
}

/*
|--------------------------------------------------------------------------
| AJAX actions
|--------------------------------------------------------------------------
*/
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'product_info') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $product = branch_stock_product($conn, $user_id, $product_id);
        if (!$product) {
            echo json_encode(['ok' => false, 'message' => 'Product not found.']);
            exit;
        }
        $variants = product_variant_names($conn, $product_id, $user_id);
        $stock = [];
        foreach ($variants ?: [''] as $variant) {
            $stock[$variant] = branch_stock_on_hand($conn, $user_id, $branch_id, $product_id, $variant);
        }
        echo json_encode(['ok' => true, 'variants' => array_values($variants), 'stock' => $stock]);
        exit;
    }

    if ($action === 'save_adjustment') {
        try {
            $product_ids = $_POST['product_id'] ?? [];
            $variant_names = $_POST['variant_name'] ?? [];
            $quantities = $_POST['qty'] ?? [];
            if (!is_array($product_ids) || !is_array($variant_names) || !is_array($quantities)) {
                throw new RuntimeException('Invalid stock items.');
            }
            $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);

            // Validate every row and merge duplicate product/variant rows.
            $lines = [];
            foreach ($product_ids as $key => $raw_product_id) {
                $product_id = (int)$raw_product_id;
                if ($product_id <= 0) continue;
                $raw_qty = trim((string)($quantities[$key] ?? ''));
                if (!preg_match('/^\d+$/', $raw_qty) || (int)$raw_qty <= 0 || (int)$raw_qty > 1000000) {
                    throw new RuntimeException('Quantity must be a whole number greater than 0.');
                }
                $product = branch_stock_product($conn, $user_id, $product_id);
                if (!$product) throw new RuntimeException('One of the selected products is not available.');
                $variants = product_variant_names($conn, $product_id, $user_id);
                $variant = trim((string)($variant_names[$key] ?? ''));
                if ($variants) {
                    if (!in_array($variant, $variants, true)) {
                        throw new RuntimeException('Select a variant for ' . $product['product_name'] . '.');
                    }
                } else {
                    $variant = '';
                }
                $line_key = $product_id . '|' . $variant;
                if (!isset($lines[$line_key])) {
                    $lines[$line_key] = ['product' => $product, 'variant' => $variant, 'qty' => 0];
                }
                $lines[$line_key]['qty'] += (int)$raw_qty;
            }
            $lines = array_filter($lines, function ($line) { return $line['qty'] !== 0; });
            if (!$lines) throw new RuntimeException('Add at least one product with a quantity.');

            mysqli_begin_transaction($conn);
            $reference_no = 'BSA-' . date('ymdHis') . random_int(10, 99);
            $created_by = (int)($_SESSION['login_user_id'] ?? 0);
            $created_by_name = mb_substr((string)($_SESSION['login_name'] ?? $_SESSION['user_name'] ?? ''), 0, 150);
            $header_stmt = mysqli_prepare($conn, 'INSERT INTO branch_stock_adjustments (user_id,branch_id,reference_no,note,created_by_user_id,created_by_name) VALUES (?,?,?,?,?,?)');
            mysqli_stmt_bind_param($header_stmt, 'iissis', $user_id, $branch_id, $reference_no, $note, $created_by, $created_by_name);
            mysqli_stmt_execute($header_stmt);
            $adjustment_id = (int)mysqli_insert_id($conn);

            $item_stmt = mysqli_prepare($conn, 'INSERT INTO branch_stock_adjustment_items (adjustment_id,user_id,branch_id,product_id,variant_name,quantity) VALUES (?,?,?,?,?,?)');
            $item_cost_stmt = mysqli_prepare($conn, 'UPDATE branch_stock_adjustment_items SET unit_cost=?, total_cost=? WHERE id=?');
            $batch_update_stmt = mysqli_prepare($conn, 'UPDATE stock_batches SET remaining_quantity=remaining_quantity-? WHERE id=? AND remaining_quantity>=?');

            foreach ($lines as $line) {
                $product_id = (int)$line['product']['id'];
                $variant = $line['variant'];
                $qty = (float)$line['qty'];
                $label = $line['product']['product_name'] . ($variant !== '' ? ' (' . $variant . ')' : '');

                mysqli_stmt_bind_param($item_stmt, 'iiiisd', $adjustment_id, $user_id, $branch_id, $product_id, $variant, $qty);
                mysqli_stmt_execute($item_stmt);
                $item_id = (int)mysqli_insert_id($conn);

                if ($qty > 0) {
                    // New branch stock enters FIFO at the product's purchase price.
                    $unit_cost = max(0, (float)$line['product']['purchase_price']);
                    if (!fifo_inventory_create_batch($conn, $user_id, $product_id, $qty, $unit_cost, 'branch_adjustment', $item_id, $reference_no, date('Y-m-d'), $branch_id, $variant)) {
                        throw new RuntimeException('Stock could not be added for ' . $label . '.');
                    }
                    $total_cost = round($qty * $unit_cost, 2);
                } else {
                    // Reduce from the oldest batches, never below pending-invoice reservations.
                    $need = -$qty;
                    $batch_stmt = mysqli_prepare($conn, 'SELECT id,remaining_quantity,unit_cost FROM stock_batches WHERE user_id=? AND product_id=? AND branch_id=? AND variant_name=? AND remaining_quantity>0 ORDER BY COALESCE(batch_date, DATE(created_at)) ASC, id ASC FOR UPDATE');
                    mysqli_stmt_bind_param($batch_stmt, 'iiis', $user_id, $product_id, $branch_id, $variant);
                    mysqli_stmt_execute($batch_stmt);
                    $batches = mysqli_fetch_all(mysqli_stmt_get_result($batch_stmt), MYSQLI_ASSOC);
                    mysqli_stmt_close($batch_stmt);
                    $on_hand = array_sum(array_map(function ($b) { return (float)$b['remaining_quantity']; }, $batches));
                    $reserved = pending_invoice_reserved_quantity($conn, $user_id, $product_id, 0, $variant);
                    $reducible = max(0, $on_hand - $reserved);
                    if ($need > $reducible + 0.0001) {
                        throw new RuntimeException('Only ' . number_format($reducible, 0) . ' of ' . $label . ' can be reduced in this branch' . ($reserved > 0 ? ' (' . number_format($reserved, 0) . ' reserved by pending invoices).' : '.'));
                    }
                    $total_cost = 0;
                    foreach ($batches as $batch) {
                        if ($need <= 0) break;
                        $used = min($need, (float)$batch['remaining_quantity']);
                        $batch_id = (int)$batch['id'];
                        mysqli_stmt_bind_param($batch_update_stmt, 'did', $used, $batch_id, $used);
                        mysqli_stmt_execute($batch_update_stmt);
                        if (mysqli_stmt_affected_rows($batch_update_stmt) !== 1) {
                            throw new RuntimeException('Stock changed while saving. Please try again.');
                        }
                        $total_cost += $used * (float)$batch['unit_cost'];
                        $need -= $used;
                    }
                    $total_cost = -round($total_cost, 2);
                    $unit_cost = $qty != 0 ? round($total_cost / $qty, 4) : 0;
                }
                mysqli_stmt_bind_param($item_cost_stmt, 'ddi', $unit_cost, $total_cost, $item_id);
                mysqli_stmt_execute($item_cost_stmt);
            }
            mysqli_commit($conn);

            echo json_encode([
                'ok' => true,
                'message' => 'Stock saved (' . $reference_no . '). ' . count($lines) . ' product' . (count($lines) === 1 ? '' : 's') . ' updated in ' . $GLOBALS['current_branch_label'] . '.',
                'stock_html' => my_stock_rows_html($conn, $user_id, $branch_id),
                'history_html' => my_stock_history_html($conn, $user_id, $branch_id),
            ]);
        } catch (Throwable $e) {
            try { mysqli_rollback($conn); } catch (Throwable $ignored) {}
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'Stock could not be saved. Please try again.';
            if (!($e instanceof RuntimeException)) error_log('Branch stock adjustment: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'message' => $message]);
        }
        exit;
    }

    if (in_array($action, ['adjustment_detail', 'update_adjustment', 'delete_adjustment'], true)) {
        if (!branch_stock_can_manage_history()) {
            echo json_encode(['ok' => false, 'message' => 'Only the company administrator can edit or delete stock entries.']);
            exit;
        }
        $adjustment_id = (int)($_POST['adjustment_id'] ?? 0);
        try {
            if ($action === 'adjustment_detail') {
                $adjustment = branch_stock_load_adjustment($conn, $user_id, $branch_id, $adjustment_id);
                echo json_encode(['ok' => true, 'reference_no' => $adjustment['reference_no'], 'note' => (string)$adjustment['note'], 'items' => array_map(function ($item) {
                    return ['id' => (int)$item['id'], 'label' => $item['label'], 'quantity' => (float)$item['quantity'], 'used' => (float)$item['used']];
                }, $adjustment['items'])]);
                exit;
            }

            mysqli_begin_transaction($conn);
            $adjustment = branch_stock_load_adjustment($conn, $user_id, $branch_id, $adjustment_id, true);
            $batch_update = mysqli_prepare($conn, 'UPDATE stock_batches SET quantity=?, remaining_quantity=? WHERE id=?');
            $item_update = mysqli_prepare($conn, 'UPDATE branch_stock_adjustment_items SET quantity=?, total_cost=ROUND(?*unit_cost,2) WHERE id=?');

            if ($action === 'delete_adjustment') {
                // Stock already sold from this entry cannot be taken back.
                foreach ($adjustment['items'] as $item) {
                    if ($item['used'] > 0.0001) {
                        throw new RuntimeException(number_format($item['used'], 0) . ' of ' . $item['label'] . ' from this entry has already been sold or moved, so it cannot be deleted.');
                    }
                }
                $delete_batch = mysqli_prepare($conn, 'DELETE FROM stock_batches WHERE id=?');
                foreach ($adjustment['items'] as $item) {
                    $batch_id = (int)$item['batch']['id'];
                    mysqli_stmt_bind_param($delete_batch, 'i', $batch_id);
                    mysqli_stmt_execute($delete_batch);
                }
                $stmt = mysqli_prepare($conn, 'DELETE FROM branch_stock_adjustment_items WHERE adjustment_id=? AND user_id=?');
                mysqli_stmt_bind_param($stmt, 'ii', $adjustment_id, $user_id);
                mysqli_stmt_execute($stmt);
                $stmt = mysqli_prepare($conn, 'DELETE FROM branch_stock_adjustments WHERE id=? AND user_id=? AND branch_id=?');
                mysqli_stmt_bind_param($stmt, 'iii', $adjustment_id, $user_id, $branch_id);
                mysqli_stmt_execute($stmt);
                $message = 'Stock entry ' . $adjustment['reference_no'] . ' deleted. Branch stock has been reduced.';
            } else {
                $quantities = $_POST['item_qty'] ?? [];
                if (!is_array($quantities)) throw new RuntimeException('Invalid quantities.');
                foreach ($adjustment['items'] as $item) {
                    $item_id = (int)$item['id'];
                    $raw_qty = trim((string)($quantities[$item_id] ?? ''));
                    if (!preg_match('/^\d+$/', $raw_qty) || (int)$raw_qty <= 0 || (int)$raw_qty > 1000000) {
                        throw new RuntimeException('Quantity must be a whole number greater than 0.');
                    }
                    $new_qty = (float)(int)$raw_qty;
                    if ($new_qty + 0.0001 < $item['used']) {
                        throw new RuntimeException($item['label'] . ': ' . number_format($item['used'], 0) . ' already sold or moved, so the quantity cannot be less than that.');
                    }
                    if (abs($new_qty - (float)$item['quantity']) < 0.0001) continue;
                    $new_remaining = $new_qty - $item['used'];
                    $batch_id = (int)$item['batch']['id'];
                    mysqli_stmt_bind_param($batch_update, 'ddi', $new_qty, $new_remaining, $batch_id);
                    mysqli_stmt_execute($batch_update);
                    mysqli_stmt_bind_param($item_update, 'ddi', $new_qty, $new_qty, $item_id);
                    mysqli_stmt_execute($item_update);
                }
                $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);
                $stmt = mysqli_prepare($conn, 'UPDATE branch_stock_adjustments SET note=? WHERE id=? AND user_id=?');
                mysqli_stmt_bind_param($stmt, 'sii', $note, $adjustment_id, $user_id);
                mysqli_stmt_execute($stmt);
                $message = 'Stock entry ' . $adjustment['reference_no'] . ' updated. Branch stock has been adjusted.';
            }
            mysqli_commit($conn);
            echo json_encode(['ok' => true, 'message' => $message, 'stock_html' => my_stock_rows_html($conn, $user_id, $branch_id), 'history_html' => my_stock_history_html($conn, $user_id, $branch_id)]);
        } catch (Throwable $e) {
            try { mysqli_rollback($conn); } catch (Throwable $ignored) {}
            if (!($e instanceof RuntimeException)) error_log('Branch stock history: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'The stock entry could not be saved. Please try again.']);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'message' => 'Unknown request.']);
    exit;
}

// Products that can be added to branch stock.
$product_options = mysqli_query($conn, "SELECT p.id,p.product_name,p.sku FROM products p INNER JOIN product_categories c ON c.id=p.category_id AND c.user_id=p.user_id WHERE p.user_id={$user_id} AND p.status='active' AND c.category_type='stock_product'" . restaurant_catalog_scope_sql($conn, 'p', true) . " ORDER BY p.product_name");
$product_options_html = '<option value="">Select Product</option>';
while ($option = mysqli_fetch_assoc($product_options)) {
    $product_options_html .= '<option value="' . (int)$option['id'] . '">' . htmlspecialchars(product_option_label($option['product_name'], $option['sku'] ?? '')) . '</option>';
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<section class="content"><div class="container-fluid">

<div class="card card-outline card-success">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-plus-square mr-2"></i>Add Stock — <?= htmlspecialchars($current_branch_label); ?></h3></div>
    <div class="card-body">
        <p class="text-muted mb-3">Select a product and enter the quantity to add (for each variant if the product has variants). Branch Stock shows the new total as you type.</p>
        <div id="adjust-message" aria-live="polite"></div>
        <style>
            #adjustTable td{vertical-align:middle}
            #adjustTable .adjust-line,#adjustTable .stock-line{display:flex;align-items:center;min-height:38px;margin-bottom:6px}
            #adjustTable .adjust-line:last-child,#adjustTable .stock-line:last-child{margin-bottom:0}
            #adjustTable .adjust-line .variant-label{min-width:70px;font-weight:600;margin-right:8px}
            #adjustTable .adjust-qty{max-width:140px}
            #adjustTable .stock-line{padding:0 .75rem;background:#e9ecef;border:1px solid #ced4da;border-radius:.25rem;max-width:140px}
        </style>
        <form id="adjust-form" autocomplete="off">
            <div class="table-responsive">
                <table class="table table-bordered" id="adjustTable">
                    <thead><tr><th width="40%">Product</th><th style="width:260px">Qty / Variant</th><th style="width:170px">Branch Stock</th><th style="width:70px">Action</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
            <button type="button" id="adjust-add-row" class="btn btn-success"><i class="fas fa-plus"></i> Add Product</button>
            <div class="form-group mt-3 mb-3" style="max-width:600px">
                <label for="adjust-note">Note <small class="text-muted">(optional)</small></label>
                <input type="text" id="adjust-note" name="note" maxlength="255" class="form-control" placeholder="e.g. Opening count, stock correction">
            </div>
            <button type="submit" id="adjust-save" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Save Stock</button>
        </form>
    </div>
</div>

<div class="card card-outline card-primary">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-boxes mr-2"></i>My Stock — <?= htmlspecialchars($current_branch_label); ?></h3><div class="card-tools"><div class="input-group input-group-sm" style="width:260px"><div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div><input type="search" id="my-stock-search" class="form-control" placeholder="Search product or Code" aria-label="Search stock"></div></div></div>
    <div class="card-body">
        <p class="text-muted">Only stock available in the current branch is shown here.</p>
        <div class="table-responsive"><table class="table table-bordered table-striped mb-0" id="my-stock-table">
            <thead><tr><?php if (my_stock_show_photo($conn, $user_id)) { ?><th>Photo</th><?php } ?><th>Product</th><th>Code</th><th>On Hand</th><th>Reserved</th><th>Damaged</th><th>Available</th><th>Stock Cost</th><th>Sale Value</th></tr></thead>
            <tbody id="my-stock-body"><?= my_stock_rows_html($conn, $user_id, $branch_id); ?></tbody>
        </table></div>
        <div id="my-stock-no-results" class="text-center text-muted py-4 d-none">No matching product was found.</div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-history mr-2"></i>Stock Add History — <?= htmlspecialchars($current_branch_label); ?></h3></div>
    <div class="card-body p-0">
        <div class="table-responsive p-3"><table class="table table-bordered table-striped mb-0" id="adjust-history-table">
            <thead><tr><th class="text-nowrap" style="width:105px">Date</th><th class="text-nowrap" style="width:200px">Ref No</th><th style="width:30%">Product</th><th style="width:20%">Variant</th><th style="width:80px">Qty</th><th style="width:140px">By</th><th>Note</th><?php if (branch_stock_can_manage_history()) { ?><th style="width:90px">Action</th><?php } ?></tr></thead>
            <tbody id="adjust-history-body"><?= my_stock_history_html($conn, $user_id, $branch_id); ?></tbody>
        </table></div>
        <small class="text-muted d-block px-3 pb-2">The latest 100 stock entries are loaded.</small>
    </div>
</div>

</div></section>
<template id="adjust-row-template">
    <tr>
        <td><select class="form-control adjust-product"><?= $product_options_html; ?></select></td>
        <td class="adjust-qty-cell"><div class="adjust-lines"><div class="adjust-line"><input type="number" step="1" min="1" class="form-control adjust-qty" data-variant="" placeholder="Qty"></div></div></td>
        <td class="adjust-stock-cell"><div class="stock-lines"><div class="stock-line text-muted">—</div></div></td>
        <td><button type="button" class="btn btn-danger adjust-remove-row" title="Remove row">X</button></td>
    </tr>
</template>
<?php if (branch_stock_can_manage_history()) { ?>
<div class="modal fade" id="adjust-edit-modal" tabindex="-1" role="dialog" aria-labelledby="adjust-edit-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="adjust-edit-title">Edit Stock Entry</h5><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>
        <div class="modal-body">
            <div id="adjust-edit-message"></div>
            <input type="hidden" id="adjust-edit-id" value="">
            <table class="table table-bordered mb-3"><thead><tr><th>Product</th><th style="width:150px">Qty</th></tr></thead><tbody id="adjust-edit-items"></tbody></table>
            <div class="form-group mb-0"><label for="adjust-edit-note">Note <small class="text-muted">(optional)</small></label><input type="text" id="adjust-edit-note" maxlength="255" class="form-control"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="adjust-edit-save"><i class="fas fa-save mr-1"></i>Save Changes</button></div>
    </div></div>
</div>
<?php } ?>
<?php
$adjust_csrf_json = json_encode(stock_csrf_token());
$page_script = <<<HTML
<script>
(function(){
    const csrf = {$adjust_csrf_json};
    const \$ = window.jQuery;
    const tbody = document.querySelector('#adjustTable tbody');
    const template = document.getElementById('adjust-row-template');
    const form = document.getElementById('adjust-form');
    const saveButton = document.getElementById('adjust-save');
    const messageBox = document.getElementById('adjust-message');
    const saveButtonHtml = saveButton.innerHTML;
    let variantSequence = 0;
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const fmt = n => Number(n || 0).toLocaleString(undefined, {maximumFractionDigits: 2});

    function showMessage(ok, text){
        messageBox.innerHTML = '<div class="alert alert-' + (ok ? 'success' : 'danger') + ' alert-dismissible fade show">' + esc(text) + '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>';
    }
    async function post(data){
        data.append('stock_csrf', csrf);
        const response = await fetch(window.location.pathname, {method: 'POST', body: data, credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}});
        const text = await response.text();
        try { return JSON.parse(text); } catch (e) { throw new Error(text && text.length < 200 ? text : 'Unexpected server response.'); }
    }
    // Branch Stock: one box with the product's total (all variants) + entered Qty.
    function updateRowStock(row){
        const stockLine = row.querySelector('.stock-line');
        if(!stockLine || stockLine.dataset.base === undefined) return;
        const base = Number(stockLine.dataset.base || 0);
        let added = 0;
        row.querySelectorAll('.adjust-qty').forEach(function(input){
            input.value = input.value.replace(/[^0-9]/g, '');
            if(input.value !== '' && parseInt(input.value, 10) <= 0) input.value = '';
            if(input.value !== '') added += parseInt(input.value, 10);
        });
        stockLine.textContent = fmt(base + added);
        stockLine.title = added ? fmt(base) + ' + ' + fmt(added) : '';
        stockLine.classList.toggle('text-success', added > 0);
        stockLine.classList.toggle('font-weight-bold', added > 0);
    }
    // Qty / Variant cell: one Qty box per variant, or a single Qty box.
    function renderLines(row, variants, stock){
        const lines = row.querySelector('.adjust-lines');
        const stockLines = row.querySelector('.stock-lines');
        lines.innerHTML = ''; stockLines.innerHTML = '';
        const names = variants.length ? variants : [''];
        names.forEach(function(name){
            const line = document.createElement('div'); line.className = 'adjust-line';
            if(name !== ''){
                const label = document.createElement('span'); label.className = 'variant-label'; label.textContent = name;
                line.appendChild(label);
            }
            const input = document.createElement('input');
            input.type = 'number'; input.step = '1'; input.min = '1'; input.className = 'form-control adjust-qty';
            input.dataset.variant = name; input.placeholder = 'Qty';
            line.appendChild(input);
            lines.appendChild(line);
            input.addEventListener('input', function(){ updateRowStock(row); });
        });
        const total = names.reduce(function(sum, name){ return sum + Number((stock || {})[name] || 0); }, 0);
        const stockLine = document.createElement('div'); stockLine.className = 'stock-line';
        stockLine.dataset.base = String(total);
        stockLine.textContent = fmt(total);
        stockLines.appendChild(stockLine);
        const first = lines.querySelector('.adjust-qty');
        if(first) first.focus();
    }
    function resetLines(row, text){
        row.dataset.hasVariants = '0';
        row.querySelector('.adjust-lines').innerHTML = '<div class="adjust-line"><input type="number" step="1" min="1" class="form-control adjust-qty" data-variant="" placeholder="Qty"></div>';
        row.querySelector('.stock-lines').innerHTML = '<div class="stock-line text-muted">' + esc(text || '—') + '</div>';
    }
    async function loadProduct(row){
        const productId = row.querySelector('.adjust-product').value;
        resetLines(row, productId ? '…' : '—');
        if(!productId) return;
        try {
            const data = new FormData(); data.append('action', 'product_info'); data.append('product_id', productId);
            const result = await post(data);
            if(row.querySelector('.adjust-product').value !== productId) return;
            if(!result.ok){ resetLines(row, '—'); showMessage(false, result.message || 'Product not found.'); return; }
            row.dataset.hasVariants = result.variants.length ? '1' : '0';
            renderLines(row, result.variants, result.stock || {});
        } catch (e) {
            resetLines(row, '—');
            showMessage(false, 'Product stock could not be loaded. Please try again.');
        }
    }
    function addRow(){
        const row = template.content.firstElementChild.cloneNode(true);
        tbody.appendChild(row);
        if(\$ && \$.fn.select2){
            \$(row).find('.adjust-product').select2({theme: 'bootstrap4', width: '100%', placeholder: 'Search product name or code'})
                .on('change', function(){ loadProduct(row); });
        } else {
            row.querySelector('.adjust-product').addEventListener('change', function(){ loadProduct(row); });
        }
        return row;
    }
    function resetRows(){
        if(\$ && \$.fn.select2) \$(tbody).find('.adjust-product').select2('destroy');
        tbody.innerHTML = '';
        addRow();
    }
    document.getElementById('adjust-add-row').addEventListener('click', function(){
        const row = addRow();
        if(\$ && \$.fn.select2) \$(row).find('.adjust-product').select2('open');
    });
    tbody.addEventListener('click', function(e){
        const button = e.target.closest('.adjust-remove-row');
        if(!button) return;
        const row = button.closest('tr');
        if(tbody.rows.length === 1){ resetRows(); return; }
        if(\$ && \$.fn.select2) \$(row).find('.adjust-product').select2('destroy');
        row.remove();
    });
    form.addEventListener('submit', async function(e){
        e.preventDefault();
        if(saveButton.disabled) return;
        const rows = Array.from(tbody.rows).filter(r => r.querySelector('.adjust-product').value);
        if(!rows.length){ showMessage(false, 'Select at least one product.'); return; }
        const data = new FormData();
        let lineCount = 0;
        for(const row of rows){
            const productId = row.querySelector('.adjust-product').value;
            let rowHasQty = false;
            for(const input of row.querySelectorAll('.adjust-qty')){
                const qty = input.value.trim();
                if(qty === '') continue;
                if(!/^\d+$/.test(qty) || parseInt(qty, 10) <= 0){ showMessage(false, 'Quantity must be a whole number greater than 0.'); input.focus(); return; }
                data.append('product_id[]', productId);
                data.append('variant_name[]', input.dataset.variant || '');
                data.append('qty[]', qty);
                rowHasQty = true; lineCount++;
            }
            if(!rowHasQty){
                showMessage(false, row.dataset.hasVariants === '1' ? 'Enter a quantity for at least one variant of every selected product.' : 'Enter a quantity for every selected product.');
                const first = row.querySelector('.adjust-qty'); if(first) first.focus();
                return;
            }
        }
        if(!lineCount){ showMessage(false, 'Enter at least one quantity.'); return; }
        data.append('note', document.getElementById('adjust-note').value);
        saveButton.disabled = true;
        saveButton.innerHTML = '<span class="spinner-border spinner-border-sm mr-1" role="status"></span>Saving…';
        try {
            data.append('action', 'save_adjustment');
            const result = await post(data);
            showMessage(!!result.ok, result.message || 'Stock could not be saved.');
            if(result.ok){
                document.getElementById('my-stock-body').innerHTML = result.stock_html;
                destroyHistoryTable();
                document.getElementById('adjust-history-body').innerHTML = result.history_html;
                initHistoryTable();
                document.getElementById('adjust-note').value = '';
                resetRows();
                document.getElementById('my-stock-search').dispatchEvent(new Event('input'));
            }
        } catch (err) {
            showMessage(false, err.message || 'Stock could not be saved. Please check your connection and try again.');
        } finally {
            saveButton.disabled = false;
            saveButton.innerHTML = saveButtonHtml;
            messageBox.scrollIntoView({behavior: 'smooth', block: 'nearest'});
        }
    });

    // My Stock quick search (rows can be replaced after a save).
    const search = document.getElementById('my-stock-search');
    const noResults = document.getElementById('my-stock-no-results');
    search.addEventListener('input', function(){
        const term = search.value.trim().toLowerCase();
        const rows = Array.from(document.querySelectorAll('#my-stock-body tr[data-stock-search]'));
        let matched = 0;
        rows.forEach(function(row){
            const visible = !term || row.dataset.stockSearch.includes(term);
            row.classList.toggle('d-none', !visible);
            if(visible) matched++;
        });
        noResults.classList.toggle('d-none', !rows.length || matched > 0);
    });

    // Admin: edit / delete a Stock Add History entry.
    function refreshAfterChange(result){
        document.getElementById('my-stock-body').innerHTML = result.stock_html;
        destroyHistoryTable();
        document.getElementById('adjust-history-body').innerHTML = result.history_html;
        initHistoryTable();
        document.getElementById('my-stock-search').dispatchEvent(new Event('input'));
    }
    const historyTable = document.getElementById('adjust-history-table');
    const editModal = document.getElementById('adjust-edit-modal');
    if(historyTable && editModal){
        const editItems = document.getElementById('adjust-edit-items');
        const editMessage = document.getElementById('adjust-edit-message');
        const editSave = document.getElementById('adjust-edit-save');
        const editSaveHtml = editSave.innerHTML;
        const showEditMessage = text => { editMessage.innerHTML = '<div class="alert alert-danger py-2">' + esc(text) + '</div>'; };
        historyTable.addEventListener('click', async function(e){
            const editButton = e.target.closest('.adjust-edit');
            const removeButton = e.target.closest('.adjust-remove');
            if(removeButton){
                if(!window.confirm('Delete stock entry ' + removeButton.dataset.ref + '? The added quantity will be removed from this branch stock.')) return;
                removeButton.disabled = true;
                try {
                    const data = new FormData(); data.append('action', 'delete_adjustment'); data.append('adjustment_id', removeButton.dataset.id);
                    const result = await post(data);
                    showMessage(!!result.ok, result.message || 'The stock entry could not be deleted.');
                    if(result.ok) refreshAfterChange(result); else removeButton.disabled = false;
                } catch (err) { showMessage(false, err.message || 'The stock entry could not be deleted.'); removeButton.disabled = false; }
                messageBox.scrollIntoView({behavior: 'smooth', block: 'nearest'});
                return;
            }
            if(!editButton) return;
            editButton.disabled = true;
            try {
                const data = new FormData(); data.append('action', 'adjustment_detail'); data.append('adjustment_id', editButton.dataset.id);
                const result = await post(data);
                if(!result.ok){ showMessage(false, result.message || 'The stock entry could not be loaded.'); messageBox.scrollIntoView({behavior: 'smooth', block: 'nearest'}); return; }
                document.getElementById('adjust-edit-id').value = editButton.dataset.id;
                document.getElementById('adjust-edit-title').textContent = 'Edit Stock Entry — ' + result.reference_no;
                document.getElementById('adjust-edit-note').value = result.note || '';
                editMessage.innerHTML = '';
                editItems.innerHTML = result.items.map(function(item){
                    const used = Number(item.used || 0);
                    return '<tr><td>' + esc(item.label) + (used > 0 ? '<small class="d-block text-muted">' + fmt(used) + ' already sold or moved — minimum ' + fmt(used) + '</small>' : '') + '</td>'
                        + '<td><input type="number" step="1" min="' + Math.max(1, used) + '" class="form-control adjust-edit-qty" data-item="' + item.id + '" value="' + Number(item.quantity) + '"></td></tr>';
                }).join('');
                \$(editModal).modal('show');
            } catch (err) {
                showMessage(false, err.message || 'The stock entry could not be loaded.');
            } finally {
                editButton.disabled = false;
            }
        });
        editSave.addEventListener('click', async function(){
            const data = new FormData();
            data.append('action', 'update_adjustment');
            data.append('adjustment_id', document.getElementById('adjust-edit-id').value);
            data.append('note', document.getElementById('adjust-edit-note').value);
            for(const input of editItems.querySelectorAll('.adjust-edit-qty')){
                const qty = input.value.trim();
                if(!/^\d+$/.test(qty) || parseInt(qty, 10) <= 0){ showEditMessage('Quantity must be a whole number greater than 0.'); input.focus(); return; }
                data.append('item_qty[' + input.dataset.item + ']', qty);
            }
            editSave.disabled = true;
            editSave.innerHTML = '<span class="spinner-border spinner-border-sm mr-1" role="status"></span>Saving…';
            try {
                const result = await post(data);
                if(!result.ok){ showEditMessage(result.message || 'The stock entry could not be saved.'); return; }
                \$(editModal).modal('hide');
                showMessage(true, result.message);
                refreshAfterChange(result);
                messageBox.scrollIntoView({behavior: 'smooth', block: 'nearest'});
            } catch (err) {
                showEditMessage(err.message || 'The stock entry could not be saved.');
            } finally {
                editSave.disabled = false;
                editSave.innerHTML = editSaveHtml;
            }
        });
    }

    // Stock Add History: 10 entries per page with search.
    function initHistoryTable(){
        if(!\$ || !\$.fn.DataTable) return;
        \$('#adjust-history-table').DataTable({
            pageLength: 10,
            order: [],
            autoWidth: false,
            language: {emptyTable: 'No stock has been added in this branch yet.'}
        });
        // The Variant column only shows when an entry has variants.
        \$('#adjust-history-table').DataTable().column(3).visible(!!document.querySelector('#adjust-history-body tr[data-has-variant]'));
    }
    function destroyHistoryTable(){
        if(\$ && \$.fn.DataTable && \$.fn.DataTable.isDataTable('#adjust-history-table')){
            \$('#adjust-history-table').DataTable().destroy();
        }
    }
    initHistoryTable();

    addRow();
})();
</script>
HTML;
require_once '../includes/footer.php';
?>
