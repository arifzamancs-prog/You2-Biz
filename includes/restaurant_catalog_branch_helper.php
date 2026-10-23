<?php
// Restaurant catalog ownership is separate from the company's shared stock catalog.
function restaurant_catalog_branch_schema($conn, $company_id)
{
    if (($_SESSION['company_type'] ?? '') !== 'Restaurant & Cafe') return;
    static $done = [];
    if (isset($done[$company_id])) return;
    $done[$company_id] = true;
    $head = stock_head_office_id($conn, $company_id);
    foreach (['product_categories', 'products'] as $table) {
        $column = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE 'branch_id'");
        if (mysqli_num_rows($column) === 0) {
            mysqli_query($conn, "ALTER TABLE `$table` ADD branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0, ADD INDEX idx_catalog_branch (user_id, branch_id)");
        }
        // Existing catalog belongs to Head Office; preserve IDs and transaction links.
        mysqli_query($conn, "UPDATE `$table` SET branch_id={$head} WHERE user_id=" . (int)$company_id . " AND branch_id=0");
    }
}

function restaurant_catalog_scope_sql($conn, $alias = '', $for_write = false)
{
    if (($_SESSION['company_type'] ?? '') !== 'Restaurant & Cafe') return '';
    $branch = $for_write
        ? (int)($GLOBALS['stock_wallet_branch_id'] ?? selected_branch_id($conn, true))
        : selected_branch_id($conn, false);
    return $branch > 0 ? ' AND ' . ($alias === '' ? '' : $alias . '.') . 'branch_id=' . (int)$branch : '';
}

function restaurant_catalog_require_record($conn, $table, $id)
{
    if (($_SESSION['company_type'] ?? '') !== 'Restaurant & Cafe') return;
    if (!in_array($table, ['products', 'product_categories', 'suppliers', 'purchases', 'supplier_payments'], true)) throw new InvalidArgumentException('Invalid catalog table.');
    $company = (int)$_SESSION['user_id'];
    $stmt = mysqli_prepare($conn, "SELECT id FROM `$table` WHERE id=? AND user_id=?" . restaurant_catalog_scope_sql($conn, '', true));
    $id = (int)$id;
    mysqli_stmt_bind_param($stmt, 'ii', $id, $company);
    mysqli_stmt_execute($stmt);
    $allowed = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$allowed) { http_response_code(403); exit('This record belongs to another branch.'); }
}

function restaurant_catalog_guard_request($conn, $module, $file)
{
    if (($_SESSION['company_type'] ?? '') !== 'Restaurant & Cafe') return;
    if (in_array($module, ['products', 'product_categories'], true) && in_array($file, ['edit.php', 'delete.php'], true)) {
        restaurant_catalog_require_record($conn, $module, $_GET['id'] ?? $_POST['id'] ?? $_POST['product_id'] ?? 0);
    }
    if ($module === 'purchases' && $file === 'delete_product.php') {
        restaurant_catalog_require_record($conn, 'products', $_GET['id'] ?? 0);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    if (isset($_POST['category_id'])) restaurant_catalog_require_record($conn, 'product_categories', $_POST['category_id']);
    foreach ((array)($_POST['product_id'] ?? []) as $id) {
        if ((int)$id > 0) restaurant_catalog_require_record($conn, 'products', $id);
    }
}

function restaurant_supplier_branch_schema($conn, $company_id)
{
    if (($_SESSION['company_type'] ?? '') !== 'Restaurant & Cafe') return;
    require_once __DIR__ . '/supplier_helper.php';
    ensure_supplier_code_column($conn);
    $column = mysqli_query($conn, "SHOW COLUMNS FROM suppliers LIKE 'branch_id'");
    if (mysqli_num_rows($column) === 0) {
        mysqli_query($conn, 'ALTER TABLE suppliers ADD branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0, ADD INDEX idx_supplier_branch (user_id, branch_id)');
    }
    $head = stock_head_office_id($conn, $company_id);
    mysqli_query($conn, "UPDATE suppliers SET branch_id={$head} WHERE user_id=" . (int)$company_id . " AND branch_id=0");
    $index = mysqli_query($conn, "SHOW INDEX FROM suppliers WHERE Key_name='uniq_supplier_code_per_user'");
    if (mysqli_num_rows($index) > 0) {
        mysqli_query($conn, 'ALTER TABLE suppliers DROP INDEX uniq_supplier_code_per_user, ADD UNIQUE KEY uniq_supplier_code_per_branch (user_id, branch_id, supplier_code)');
    }
}

function restaurant_supplier_guard_request($conn, $module, $file)
{
    if (($_SESSION['company_type'] ?? '') !== 'Restaurant & Cafe' || !in_array($module, ['suppliers', 'purchases'], true)) return;
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($module === 'suppliers') {
        if (in_array($file, ['edit.php','update.php','delete.php','supplier_ledger.php'], true)) restaurant_catalog_require_record($conn, 'suppliers', $id);
        if ($file === 'delete_payment.php') restaurant_catalog_require_record($conn, 'supplier_payments', $id);
        if ($file === 'supplier_payment_entry.php') restaurant_catalog_require_record($conn, 'purchases', $id);
        if ($file === 'supplier_payment_save.php') restaurant_catalog_require_record($conn, 'purchases', $_POST['purchase_id'] ?? 0);
    } elseif (in_array($file, ['edit.php','update.php','delete.php','view.php','print_purchase.php'], true)) {
        restaurant_catalog_require_record($conn, 'purchases', $_POST['purchase_id'] ?? $id);
    }
    if ((int)($_POST['supplier_id'] ?? 0) > 0) restaurant_catalog_require_record($conn, 'suppliers', $_POST['supplier_id']);
    if ((int)($_POST['payment_wallet_id'] ?? 0) > 0) {
        try { stock_require_wallet($conn, (int)$_POST['payment_wallet_id'], (int)$_SESSION['user_id'], stock_customer_branch($conn)); }
        catch (RuntimeException $e) { http_response_code(403); exit($e->getMessage()); }
    }
}
