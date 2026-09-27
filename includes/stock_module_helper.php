<?php

require_once __DIR__ . '/branch_context_helper.php';

function stock_head_office_id($conn, $company_id)
{
    $stmt = mysqli_prepare($conn, 'SELECT id FROM branches WHERE user_id=? AND is_head_office=1 LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $company_id);
    mysqli_stmt_execute($stmt);
    $id = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('Head Office is not configured.');
    return $id;
}

function stock_warehouse_id($conn, $company_id)
{
    $company_id = (int)$company_id;
    ensure_head_office_branch($conn, $company_id);
    $stmt = mysqli_prepare($conn, "SELECT id FROM branches WHERE user_id=? AND is_head_office=0 AND branch_name='Main Warehouse' LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $company_id);
    mysqli_stmt_execute($stmt);
    $id = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['id'] ?? 0);
    if ($id <= 0) {
        $insert = mysqli_prepare($conn, "INSERT INTO branches (user_id,branch_name,status) VALUES (?,'Main Warehouse','active')");
        mysqli_stmt_bind_param($insert, 'i', $company_id);
        if (!mysqli_stmt_execute($insert)) throw new RuntimeException('Main Warehouse could not be configured.');
        $id = (int)mysqli_insert_id($conn);
    }
    return $id;
}

function stock_migrate_head_office_inventory_to_warehouse($conn, $company_id)
{
    static $done = [];
    $company_id = (int)$company_id;
    if (isset($done[$company_id])) return;
    $done[$company_id] = true;
    $head_id = stock_head_office_id($conn, $company_id);
    $warehouse_id = stock_warehouse_id($conn, $company_id);
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS stock_warehouse_migrations (user_id BIGINT UNSIGNED PRIMARY KEY, completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $marker = mysqli_fetch_assoc(mysqli_query($conn, "SELECT user_id FROM stock_warehouse_migrations WHERE user_id={$company_id}"));
    if ($marker || $head_id === $warehouse_id) return;
    mysqli_begin_transaction($conn);
    try {
        mysqli_query($conn, "UPDATE stock_batches SET branch_id={$warehouse_id} WHERE user_id={$company_id} AND branch_id={$head_id}");
        mysqli_query($conn, "INSERT INTO stock_warehouse_migrations (user_id) VALUES ({$company_id})");
        mysqli_commit($conn);
    } catch (Throwable $e) { mysqli_rollback($conn); throw $e; }
}

function stock_can_manage_warehouse($conn)
{
    return is_admin_user() || (is_manager_user() && manager_is_head_office($conn, (int)$_SESSION['user_id']));
}

function stock_require_wallet($conn, $wallet_id, $company_id, $branch_id)
{
    $stmt = mysqli_prepare($conn, "SELECT id FROM wallets WHERE id=? AND user_id=? AND branch_id=? AND status='active' LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'iii', $wallet_id, $company_id, $branch_id);
    mysqli_stmt_execute($stmt);
    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        throw new RuntimeException('Select an active wallet belonging to this transaction\'s branch.');
    }
}

function stock_invoice_branch($conn, $company_id, $invoice_id)
{
    $stmt = mysqli_prepare($conn, 'SELECT branch_id FROM invoices WHERE id=? AND user_id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $invoice_id, $company_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$row || (int)$row['branch_id'] <= 0) throw new RuntimeException('Invoice branch was not found.');
    $branch_id = (int)$row['branch_id'];
    $scope = selected_branch_id($conn, false);
    if ($scope > 0 && $scope !== $branch_id) throw new RuntimeException('This invoice belongs to another branch.');
    return $branch_id;
}

function stock_csrf_token()
{
    if (empty($_SESSION['stock_csrf'])) $_SESSION['stock_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['stock_csrf'];
}

// Monetary customer balances are always evaluated in one stock location.
// All Branches remains available on the consolidated sales report; collections
// use the selected branch (Head Office when the selector is All Branches).
function stock_customer_branch($conn)
{
    return (int)($GLOBALS['stock_wallet_branch_id'] ?? selected_branch_id($conn, true));
}

function stock_customer_scope($conn, $alias = '')
{
    if (empty($GLOBALS['stock_request_active'])) return '';
    return ' AND ' . ($alias === '' ? '' : $alias . '.') . 'branch_id=' . stock_customer_branch($conn);
}

function stock_line_totals($product_ids, $quantities, $prices, $allow_returns = true)
{
    if (!is_array($product_ids) || !is_array($quantities) || !is_array($prices)) throw new RuntimeException('Invalid stock items.');
    $totals = [];
    foreach ($product_ids as $key => $product_id) {
        if (empty($product_id)) continue;
        $quantity = (float)($quantities[$key] ?? 0);
        $price = (float)($prices[$key] ?? 0);
        if (!is_finite($quantity) || $quantity == 0 || floor($quantity) != $quantity || abs($quantity) > 2147483647 || (!$allow_returns && $quantity < 0) || !is_finite($price) || $price < 0) {
            throw new RuntimeException('Enter a valid whole quantity and a non-negative price.');
        }
        $totals[$key] = round($quantity * $price, 2);
    }
    if (!$totals) throw new RuntimeException('Add at least one stock item.');
    return $totals;
}

function stock_verify_csrf()
{
    if (!hash_equals(stock_csrf_token(), (string)($_POST['stock_csrf'] ?? ''))) {
        http_response_code(403);
        exit('Invalid request. Reload the form and try again.');
    }
}

function ensure_stock_module_feature_column($conn)
{
    $col = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'fifo_enabled'");
    if ($col && mysqli_num_rows($col) === 0) {
        mysqli_query($conn, 'ALTER TABLE users ADD COLUMN fifo_enabled TINYINT(1) NOT NULL DEFAULT 0');
    }
}

// Invoked after database connection and refreshed staff permissions, before any
// output or transaction. Stock Product company type, plus its enabled flag,
// controls sidebar visibility and direct URLs on every request.
function stock_module_bootstrap($conn)
{
    require_once __DIR__ . '/company_settings_helper.php';
    ensure_company_setting_columns($conn);
    ensure_stock_module_feature_column($conn);
    $company_id = (int)($_SESSION['user_id'] ?? 0);
    if ($company_id <= 0) return;
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT company_type, fifo_enabled FROM users WHERE id={$company_id}"));
    $_SESSION['company_type'] = normalize_company_type($row['company_type'] ?? 'Housing');
    $_SESSION['fifo_enabled'] = (int)($row['fifo_enabled'] ?? 0);
    $_SESSION['stock_product_enabled'] = $_SESSION['company_type'] === 'Stock Product'
        && $_SESSION['fifo_enabled'] === 1;

    // Dashboards and existing financial reports can read stock tables before
    // the first visit to a Stock page on a newly deployed installation.
    if ($_SESSION['stock_product_enabled']) {
        require_once __DIR__ . '/fifo_inventory_helper.php';
        ensure_fifo_inventory_tables($conn);
        stock_migrate_head_office_inventory_to_warehouse($conn, $company_id);
    }

    $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (!preg_match('#/(sales|products|product_categories|purchases|suppliers|warehouse|inventory)/#', $path, $match)) return;
    // Housing uses the ordinary supplier/purchase pages without enabling FIFO.
    // Check staff permission here before bypassing the stock-only bootstrap.
    if ($_SESSION['company_type'] === 'Housing' && in_array($match[1], ['suppliers', 'purchases'], true)) {
        if (is_manager_user() && !manager_has_permission('suppliers')) {
            http_response_code(403);
            exit('Permission denied.');
        }
        return;
    }
    if (!$_SESSION['stock_product_enabled']) {
        header('Location: ' . app_path('dashboard.php?error=Stock module is inactive'));
        exit;
    }
    require_once __DIR__ . '/fifo_inventory_helper.php';
    ensure_fifo_inventory_tables($conn);
    ensure_branch_accounting_columns($conn, $company_id);
    require_once __DIR__ . '/customer_opening_due_helper.php';
    ensure_customer_opening_due_tables($conn);
    $opening_branch = mysqli_query($conn, "SHOW COLUMNS FROM customer_opening_dues LIKE 'branch_id'");
    if (mysqli_num_rows($opening_branch) === 0) {
        mysqli_query($conn, 'ALTER TABLE customer_opening_dues ADD COLUMN branch_id BIGINT NOT NULL DEFAULT 0');
    }
    $head_office_id = stock_head_office_id($conn, $company_id);
    mysqli_query($conn, "UPDATE customer_opening_dues SET branch_id={$head_office_id} WHERE user_id={$company_id} AND branch_id=0");
    mysqli_query($conn, "UPDATE customer_payments p INNER JOIN invoices i ON i.id=p.invoice_id AND i.user_id=p.user_id SET p.branch_id=i.branch_id WHERE p.user_id={$company_id} AND p.branch_id<>i.branch_id AND i.branch_id>0");
    require_once __DIR__ . '/product_category_helper.php';
    ensure_default_product_categories($conn, $company_id);
    fifo_inventory_migrate_company($conn, $company_id);

    $module = $match[1];
    $file = basename($path);
    $GLOBALS['stock_request_active'] = true;
    if (is_manager_user() && company_multi_branch_enabled($conn, $company_id) && manager_branch_id($conn, $company_id) <= 0) {
        http_response_code(403); exit('Assign a branch before using stock modules.');
    }
    $permissions = ['sales'=>'stock_sales','products'=>'products','product_categories'=>'products','purchases'=>'suppliers','suppliers'=>'suppliers','warehouse'=>'warehouse','inventory'=>'warehouse'];
    $required_permission = $module === 'warehouse' && $file === 'sales_report.php' ? 'stock_sales' : $permissions[$module];
    if (is_manager_user() && !manager_has_permission($required_permission)) {
        http_response_code(403); exit('Permission denied.');
    }
    // Supplier purchasing and opening balances feed the central warehouse.
    $central = in_array($module, ['purchases', 'suppliers', 'product_categories'], true)
        || ($module === 'products' && !in_array($file, ['index.php', 'expired.php'], true));
    if ($central && !stock_can_manage_warehouse($conn)) {
        header('Location: ' . app_path('dashboard.php?error=Central stock management requires Head Office access'));
        exit;
    }
    if ($module === 'inventory') {
        header('Location: ' . app_path('warehouse/index.php'));
        exit;
    }
    if (in_array($module, ['purchases', 'suppliers'], true)) {
        // Do not let a navbar filter send central purchase payments to a branch.
        $GLOBALS['stock_wallet_branch_id'] = stock_warehouse_id($conn, $company_id);
    }
    if ($module === 'sales' && in_array($file, ['edit_invoice.php', 'update_invoice.php', 'post_invoice.php', 'delete_invoice.php', 'payment_entry.php', 'payment_save.php', 'view_invoice.php', 'print_invoice.php'], true)) {
        $id = (int)($_POST['invoice_id'] ?? $_GET['id'] ?? 0);
        if ($id > 0) {
            try { $GLOBALS['stock_wallet_branch_id'] = stock_invoice_branch($conn, $company_id, $id); }
            catch (RuntimeException $e) { http_response_code(403); exit(htmlspecialchars($e->getMessage())); }
        }
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $file !== 'get_product.php') stock_verify_csrf();
}
