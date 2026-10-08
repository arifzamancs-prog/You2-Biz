<?php
if(PHP_SAPI!=='cli') exit;
require_once __DIR__.'/../includes/eshop_catalog_helper.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$conn=new mysqli('localhost',getenv('TEST_DB_USER')?:'root',getenv('TEST_DB_PASSWORD')?:'',getenv('TEST_DB_NAME')?:'you2biz');
$tables=[
    'eshop_checkout_settings'=>'company_id INT PRIMARY KEY,branch_id INT',
    'stock_batches'=>'user_id INT,product_id INT,branch_id INT,variant_name VARCHAR(100),remaining_quantity DECIMAL(12,2)',
    'invoices'=>'id INT PRIMARY KEY,user_id INT,branch_id INT,accounting_status VARCHAR(20)',
    'invoice_items'=>'invoice_id INT,product_id INT,variant_name VARCHAR(100),quantity INT',
    'products'=>'id INT,user_id INT,category_id INT',
    'product_categories'=>'id INT,category_type VARCHAR(30)',
    'product_variants'=>'id INT,product_id INT,user_id INT,variant_name VARCHAR(100)'
];
foreach($tables as $name=>$definition) $conn->query("CREATE TEMPORARY TABLE $name ($definition)");
$conn->query('INSERT INTO eshop_checkout_settings VALUES(10,5),(20,6)');
$conn->query("INSERT INTO stock_batches VALUES(10,1,5,'',7),(10,1,6,'',100),(20,1,6,'',50)");
$conn->query("INSERT INTO invoices VALUES(1,10,5,'pending'),(2,10,6,'pending'),(3,10,5,'posted')");
$conn->query("INSERT INTO invoice_items VALUES(1,1,'',2),(2,1,'',30),(3,1,'',50)");
$conn->query("INSERT INTO products VALUES(1,10,1),(2,10,2)"); $conn->query("INSERT INTO product_categories VALUES(1,'stock_product'),(2,'non_stock')");
function catalog_assert($condition,$message){if(!$condition) throw new RuntimeException($message);}
catalog_assert(eshop_catalog_available_quantity($conn,10,1)===5,'Must use checkout branch and pending reservations only');
catalog_assert(eshop_catalog_available_quantity($conn,20,1)===50,'Company stock isolation');
catalog_assert(eshop_catalog_is_physical_product($conn,10,1),'Physical product rejected');
catalog_assert(!eshop_catalog_is_physical_product($conn,10,2),'Non-stock product allowed');
$simpleOptions=eshop_catalog_variant_options($conn,10,1);
catalog_assert(count($simpleOptions)===1 && $simpleOptions[0]['name']==='' && $simpleOptions[0]['available']===5,'Simple product availability is incorrect');
$conn->query("INSERT INTO product_variants VALUES(1,1,10,'Red')");
$conn->query("INSERT INTO stock_batches VALUES(10,1,5,'Red',4)");
$variantOptions=eshop_catalog_variant_options($conn,10,1);
catalog_assert(count($variantOptions)===1 && $variantOptions[0]['name']==='Red' && $variantOptions[0]['available']===4,'Variant availability is incorrect');
echo "PASS: storefront availability uses checkout branch, pending reservations, tenant isolation, physical-only products and variant options.\n";
