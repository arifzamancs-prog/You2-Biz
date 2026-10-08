<?php
// CLI-only integration test. Every write targets a fresh disposable database.
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../includes/eshop_order_helper.php';
require_once __DIR__.'/../includes/invoice_posting_helper.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli('localhost', getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '');
$testDatabase = 'you2biz_eshop_test_'.bin2hex(random_bytes(8));
$created = false;
function stock_test_assert($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
try {
    $conn->query("CREATE DATABASE `$testDatabase`");
    $created = true;
    $conn->select_db($testDatabase);
    // Run real migrations only in the isolated database, before transactions.
    ensure_fifo_inventory_tables($conn);
    ensure_invoice_posting_columns($conn);
    eshop_order_schema($conn);
    $conn->query('CREATE TABLE users(id INT PRIMARY KEY,status VARCHAR(20))');
    $conn->query('CREATE TABLE branches(id INT PRIMARY KEY,user_id INT,status VARCHAR(20))');
    $conn->query('CREATE TABLE eshop_settings(company_id INT PRIMARY KEY,enabled INT)');
    $conn->query('CREATE TABLE eshop_profiles(company_id INT PRIMARY KEY,delivery_charge DECIMAL(12,2))');
    $conn->query('CREATE TABLE eshop_products(company_id INT,product_id INT,published INT,online_price DECIMAL(12,2))');
    $conn->query('CREATE TABLE customers(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,customer_name VARCHAR(150),phone VARCHAR(30),email VARCHAR(100),address TEXT,status VARCHAR(20))');
    $conn->query("INSERT INTO users VALUES(10,'active')");
    $conn->query("INSERT INTO branches VALUES(5,10,'active'),(6,10,'active')");
    $conn->query('INSERT INTO eshop_settings VALUES(10,1)');
    $conn->query('INSERT INTO eshop_profiles VALUES(10,0)');
    $conn->query('INSERT INTO eshop_checkout_settings VALUES(10,5,0)');
    $conn->query("INSERT INTO product_categories(id,user_id,category_name,category_type) VALUES(1,10,'Physical','stock_product')");
    $conn->query("INSERT INTO products(id,user_id,category_id,product_name,sale_price,current_stock) VALUES(1,10,1,'Test product',100,25)");
    $conn->query('INSERT INTO eshop_products VALUES(10,1,1,80)');
    fifo_inventory_create_batch($conn,10,1,5,40,'opening',1,'TEST-5','2026-01-01',5);
    fifo_inventory_create_batch($conn,10,1,20,60,'opening',1,'TEST-6','2026-01-01',6);
    $input = ['name'=>'Test','phone'=>'01700000000','email'=>'','address'=>'Test address'];
    eshop_place_order($conn,10,[1=>2],$input,str_repeat('a',64));
    eshop_place_order($conn,10,[1=>1],$input,str_repeat('b',64));
    $invoice = $conn->query('SELECT * FROM invoices ORDER BY id LIMIT 1')->fetch_assoc();
    $id = (int)$invoice['id'];
    $itemId = (int)$conn->query("SELECT id FROM invoice_items WHERE invoice_id=$id")->fetch_row()[0];
    stock_test_assert(invoice_is_pending($invoice), 'Checkout must produce a pending Sales invoice');
    $conn->begin_transaction();
    $snapshot = product_stock_snapshot_for_invoice($conn,10,1,$id);
    stock_test_assert((float)$snapshot['available_stock'] === 4.0, 'Exclude own reservation but preserve other pending orders');
    $allocation = fifo_inventory_allocate_sale($conn,10,$itemId,1,2);
    stock_test_assert($allocation['success'] && (float)$allocation['cost_amount'] === 80.0, 'Wrong FIFO cost');
    $conn->rollback();
    stock_test_assert(fifo_inventory_get_available_stock($conn,10,1,5) === 5.0, 'Failed posting must restore stock');
    stock_test_assert((int)$conn->query('SELECT COUNT(*) FROM invoice_item_allocations')->fetch_row()[0] === 0, 'Rollback left allocations');
    // Exercise the same stock helpers used by Sales; not the HTTP/payment handler.
    $conn->begin_transaction();
    $allocation = fifo_inventory_allocate_sale($conn,10,$itemId,1,2);
    stock_test_assert($allocation['success'], 'Allocation failed');
    $conn->query("UPDATE invoices SET accounting_status='posted' WHERE id=$id");
    $conn->commit();
    stock_test_assert(pending_invoice_reserved_quantity($conn,10,1) === 1.0, 'Posted invoice still reserves stock');
    stock_test_assert(fifo_inventory_get_available_stock($conn,10,1,5) === 3.0, 'Wrong sale stock');
    stock_test_assert(fifo_inventory_get_available_stock($conn,10,1,6) === 20.0, 'Another branch was affected');
    stock_test_assert(!invoice_is_pending($conn->query("SELECT * FROM invoices WHERE id=$id")->fetch_assoc()), 'Already-posted guard failed');
    echo "PASS: real checkout to Sales stock helpers, own-reservation exclusion, FIFO cost, rollback, reservation release and branch isolation. HTTP/payment flow not exercised.\n";
} finally {
    $conn->rollback();
    // Never accept an environment-supplied cleanup target.
    if ($created && preg_match('/\Ayou2biz_eshop_test_[a-f0-9]{16}\z/', $testDatabase)) {
        $conn->query("DROP DATABASE `$testDatabase`");
    }
    $conn->close();
}
