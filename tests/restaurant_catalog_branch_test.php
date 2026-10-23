<?php
// Isolated database regression checks; never writes to application data.
if (PHP_SAPI !== 'cli') exit;
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../includes/product_category_helper.php';
function stock_head_office_id($conn, $company_id) { return 10; }
function selected_branch_id($conn, $write = false) { return (int)($_SESSION['selected_branch_id'] ?: ($write ? 10 : 0)); }
function check_catalog($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$_SESSION = ['user_id'=>1, 'company_type'=>'Restaurant & Cafe', 'selected_branch_id'=>20];
$conn = new mysqli('localhost', 'root', '', 'you2biz');
if (($argv[1] ?? '') === '--guard') {
    $db = $argv[2] ?? '';
    if (!preg_match('/^you2biz_staging_[a-f0-9]{16}$/', $db)) exit(2);
    $conn->select_db($db);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['product_id'=>[(int)$argv[3]]];
    restaurant_catalog_guard_request($conn, 'sales', 'save_invoice.php');
    echo 'allowed';
    exit;
}
if (($argv[1] ?? '') === '--supplier-guard') {
    $db = $argv[2] ?? '';
    if (!preg_match('/^you2biz_staging_[a-f0-9]{16}$/', $db)) exit(2);
    $conn->select_db($db);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['id'=>(int)$argv[5], 'purchase_id'=>(int)$argv[5]];
    restaurant_supplier_guard_request($conn, $argv[3], $argv[4]);
    echo 'allowed';
    exit;
}
$db = 'you2biz_staging_' . bin2hex(random_bytes(8));
$conn->query("CREATE DATABASE `$db`");
try {
    $conn->select_db($db);
    $conn->query('CREATE TABLE product_categories (id INT PRIMARY KEY, user_id INT, category_name VARCHAR(100))');
    $conn->query('CREATE TABLE products (id INT PRIMARY KEY, user_id INT, sku VARCHAR(100))');
    $conn->query("INSERT INTO product_categories VALUES (1,1,'Breakfast'),(9,2,'Other tenant')");
    $conn->query("INSERT INTO products VALUES (1,1,'HO-1'),(9,2,'OTHER')");
    restaurant_catalog_branch_schema($conn, 1);
    check_catalog((int)$conn->query('SELECT branch_id FROM products WHERE id=1')->fetch_row()[0] === 10, 'Legacy product migration');
    check_catalog((int)$conn->query('SELECT branch_id FROM products WHERE id=9')->fetch_row()[0] === 0, 'Other tenant must remain unchanged');
    $conn->query("INSERT INTO product_categories VALUES (2,1,'Breakfast',20),(3,1,'Dinner',30)");
    $conn->query("INSERT INTO products VALUES (2,1,'BR-1',20),(3,1,'BR-1',30)");
    foreach ([20=>2,30=>3,10=>1] as $branch=>$expected) {
        $_SESSION['selected_branch_id'] = $branch;
        $rows = $conn->query('SELECT id FROM products WHERE user_id=1' . restaurant_catalog_scope_sql($conn))->fetch_all();
        check_catalog(count($rows) === 1 && (int)$rows[0][0] === $expected, 'Branch catalog isolation');
    }
    $_SESSION['selected_branch_id'] = 20;
    check_catalog(product_category_name_exists($conn,1,'Breakfast'), 'Duplicate category in own branch');
    check_catalog(!product_category_name_exists($conn,1,'Dinner'), 'Other branch name remains available');
    check_catalog(product_sku_exists($conn,1,'BR-1'), 'Duplicate SKU in own branch');
    check_catalog(!product_sku_exists($conn,1,'HO-1'), 'Other branch SKU remains available');
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --guard ' . escapeshellarg($db);
    check_catalog(trim(shell_exec($command . ' 2')) === 'allowed', 'Own branch invoice item allowed');
    check_catalog(str_contains(shell_exec($command . ' 3'), 'belongs to another branch'), 'Forged invoice item rejected');
    check_catalog(str_contains(shell_exec($command . ' 9'), 'belongs to another branch'), 'Other tenant item rejected');
    $conn->query('CREATE TABLE suppliers (id INT PRIMARY KEY, user_id INT, supplier_name VARCHAR(100))');
    $conn->query("INSERT INTO suppliers VALUES (1,1,'Legacy'),(9,2,'Other tenant')");
    restaurant_supplier_branch_schema($conn, 1);
    check_catalog((int)$conn->query('SELECT branch_id FROM suppliers WHERE id=1')->fetch_row()[0] === 10, 'Legacy supplier migration');
    $conn->query("INSERT INTO suppliers(id,user_id,supplier_name,supplier_code,branch_id) VALUES (2,1,'Local','S-1',20),(3,1,'Remote','S-1',30)");
    foreach (['purchases', 'supplier_payments'] as $table) {
        $conn->query("CREATE TABLE `$table` (id INT PRIMARY KEY, user_id INT, branch_id INT)");
        $conn->query("INSERT INTO `$table` VALUES (2,1,20),(3,1,30),(9,2,20)");
    }
    foreach (['suppliers','purchases','supplier_payments'] as $table) {
        $rows = $conn->query("SELECT id FROM `$table` WHERE user_id=1" . restaurant_catalog_scope_sql($conn))->fetch_all();
        check_catalog(count($rows) === 1 && (int)$rows[0][0] === 2, 'Supplier/purchase/payment list isolation');
    }
    $supplier_command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --supplier-guard ' . escapeshellarg($db);
    foreach ([['suppliers','update.php'],['suppliers','supplier_payment_save.php'],['purchases','update.php'],['suppliers','delete_payment.php']] as [$module,$file]) {
        $guard = $supplier_command . ' ' . $module . ' ' . $file;
        check_catalog(trim(shell_exec($guard . ' 2')) === 'allowed', 'Own supplier/purchase/payment allowed');
        check_catalog(str_contains(shell_exec($guard . ' 3'), 'belongs to another branch'), 'Other branch supplier/purchase/payment blocked');
        check_catalog(str_contains(shell_exec($guard . ' 9'), 'belongs to another branch'), 'Other tenant supplier/purchase/payment blocked');
    }
    $_SESSION['selected_branch_id'] = 0;
    check_catalog(restaurant_catalog_scope_sql($conn) === '', 'All Branches read scope');
    check_catalog(restaurant_catalog_scope_sql($conn, 'p', true) === ' AND p.branch_id=10', 'All Branches writes default to Head Office');
    $_SESSION['company_type'] = 'Stock Product';
    check_catalog(restaurant_catalog_scope_sql($conn, 'p', true) === '', 'Other company types unchanged');
    echo "PASS: catalog/supplier migration, branch visibility, local codes, forged invoice/purchase/payment protection, and other company types.\n";
} finally {
    $conn->select_db('you2biz');
    $conn->query("DROP DATABASE `$db`");
}
