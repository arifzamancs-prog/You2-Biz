<?php
// Schema-only scratch database. No production rows are copied or changed.
if (PHP_SAPI !== 'cli') exit;
ob_start();
require __DIR__ . '/create_local_staging.php';
$fixture = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
$conn = new mysqli('localhost', 'root', '', $fixture['database']);
require_once __DIR__ . '/../includes/product_category_helper.php';
require_once __DIR__ . '/../includes/restaurant_module_helper.php';
require_once __DIR__ . '/../includes/restaurant_table_helper.php';
function cafe_check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
$conn->query("UPDATE users SET company_type='Restaurant & Cafe',fifo_enabled=0 WHERE id=1");
$_SESSION = ['user_id'=>1,'company_type'=>'Restaurant & Cafe','fifo_enabled'=>0];
ensure_fifo_only_product_categories($conn, 1);
ensure_fifo_only_product_categories($conn, 1);
$conn->query("INSERT INTO product_categories(user_id,category_name,category_type,status) VALUES(1,'Prepared Food','non_stock','active')");
$foodCategory = (int)$conn->insert_id;
cafe_check((int)$conn->query('SELECT COUNT(*) n FROM product_categories WHERE user_id=1')->fetch_assoc()['n'] === 4, 'Cafe defaults are missing or duplicated');
cafe_check(product_category_allows_creation($conn, $foodCategory, 1), 'Cafe cannot create non-stock');
cafe_check(!product_category_allows_creation($conn, 999999, 1), 'Invalid category accepted');
cafe_check(fifo_inventory_is_enabled(), 'Cafe FIFO must be enabled for packaged goods');
cafe_check(table_system_enabled($conn, 1), 'Cafe tables disabled');
$conn->query("INSERT INTO products(id,user_id,category_id,product_name,sku,purchase_price,sale_price,current_stock,status) VALUES(2,1,$foodCategory,'Biriyani','CAFE-FOOD',60,150,0,'active')");
cafe_check(!product_uses_stock($conn, 2, 1), 'Prepared food consumes stock');
cafe_check(product_uses_stock($conn, 1, 1), 'Bottled product not tracked');
cafe_check(!product_category_allows_creation($conn, $foodCategory, 2), 'Cross-company category accepted');
$conn->query("UPDATE users SET company_type='Stock Product',fifo_enabled=1 WHERE id=1");
$_SESSION['company_type'] = 'Stock Product';
cafe_check(!product_category_allows_creation($conn, $foodCategory, 1), 'Non-Cafe accepts non-stock');
cafe_check(!table_system_enabled($conn, 1), 'Non-Cafe exposes tables');
$original = [['href'=>'unchanged','label'=>'Original']];
cafe_check(restaurant_module_menu('Sales', $original) === $original, 'Other company menu modified');
$conn->query("UPDATE users SET company_type='Restaurant & Cafe',fifo_enabled=1 WHERE id=1");
$_SESSION['company_type'] = 'Restaurant & Cafe';
ensure_fifo_only_product_categories($conn, 1);
cafe_check(!product_uses_stock($conn, 2, 1), 'Cafe bootstrap reclassified food');
echo "PASS: Cafe category isolation, defaults, stock/non-stock, tables, tenant ownership and non-Cafe navigation.\n";
echo json_encode($fixture) . "\n";
