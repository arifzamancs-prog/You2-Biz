<?php
// Creates schema-only staging plus synthetic records. No live rows are copied.
if (PHP_SAPI !== 'cli') exit;
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli('localhost', 'root', '', 'you2biz');
$database = 'you2biz_staging_'.bin2hex(random_bytes(8));
$conn->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
try {
    $tables = $conn->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'");
    while ($row = $tables->fetch_row()) {
        $table = str_replace('`', '``', $row[0]);
        $conn->query("CREATE TABLE `$database`.`$table` LIKE `you2biz`.`$table`");
    }
    $conn->select_db($database);
    $password = bin2hex(random_bytes(12));
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $s = $conn->prepare("INSERT INTO users(id,name,company_type,email,phone,password,role,status,subscription_status,subscription_expires_at,email_verified,fifo_enabled) VALUES(1,'STAGING Test Shop','Stock Product','admin@example.test','01700000001',?,'admin','active','active','2099-12-31',1,1)");
    $s->bind_param('s',$hash); $s->execute();
    $conn->query("INSERT INTO branches(id,user_id,branch_name,is_head_office,status) VALUES(1,1,'Test Head Office',1,'active'),(2,1,'Main Warehouse',0,'active')");
    $conn->query("INSERT INTO wallets(id,user_id,branch_id,wallet_name,balance,status) VALUES(1,1,1,'Test Cash',0,'active')");
    $conn->query("INSERT INTO product_categories(id,user_id,category_name,category_type,status) VALUES(1,1,'Test Physical','stock_product','active')");
    $conn->query("INSERT INTO products(id,user_id,category_id,product_name,sku,purchase_price,sale_price,current_stock,status) VALUES(1,1,1,'Test Product','STAGING-1',40,100,10,'active')");
    require_once __DIR__.'/../includes/fifo_inventory_helper.php';
    ensure_fifo_inventory_tables($conn);
    fifo_inventory_create_batch($conn,1,1,10,40,'opening',1,'STAGING-STOCK','2026-01-01',1);
    $conn->query('INSERT INTO stock_inventory_migrations(user_id,completed_at) VALUES(1,NOW()) ON DUPLICATE KEY UPDATE completed_at=NOW()');
    require_once __DIR__.'/../includes/eshop_helper.php';
    require_once __DIR__.'/../includes/eshop_order_helper.php';
    ensure_eshop_table($conn); eshop_order_schema($conn);
    $conn->query("INSERT INTO eshop_settings(company_id,slug,enabled) VALUES(1,'staging-shop',1)");
    $conn->query("INSERT INTO eshop_profiles(company_id,shop_name,phone,email,address,description,delivery_charge,return_policy) VALUES(1,'STAGING Test Shop','01700000001','shop@example.test','Test address','Synthetic data only',0,'Test returns')");
    $conn->query("INSERT INTO eshop_products(company_id,product_id,published,online_price,description) VALUES(1,1,1,100,'Synthetic physical product')");
    $conn->query('INSERT INTO eshop_checkout_settings(company_id,branch_id,charge_type_id) VALUES(1,1,0)');
    echo json_encode(['database'=>$database,'email'=>'admin@example.test','password'=>$password], JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $e) {
    // This exact generated database contains only synthetic setup records.
    $conn->query("DROP DATABASE `$database`");
    throw $e;
} finally { $conn->close(); }
