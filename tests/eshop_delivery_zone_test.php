<?php
if(PHP_SAPI!=='cli') exit;
require_once __DIR__.'/../includes/eshop_order_helper.php';
require_once __DIR__.'/../includes/eshop_storefront_helper.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$conn=new mysqli('localhost',getenv('TEST_DB_USER')?:'root',getenv('TEST_DB_PASSWORD')?:'',getenv('TEST_DB_NAME')?:'you2biz');
// Temporary shadows exercise the real checkout SQL without touching business data.
$tables=[
'eshop_orders'=>'id INT AUTO_INCREMENT PRIMARY KEY,company_id INT,invoice_id INT,order_no VARCHAR(50) UNIQUE,request_key CHAR(64) UNIQUE,customer_name VARCHAR(150),phone VARCHAR(30),email VARCHAR(100),address TEXT,total DECIMAL(15,2)',
'eshop_checkout_settings'=>'company_id INT PRIMARY KEY,branch_id INT,charge_type_id INT',
'eshop_settings'=>'company_id INT PRIMARY KEY,enabled INT',
'eshop_profiles'=>'company_id INT PRIMARY KEY,delivery_charge DECIMAL(12,2)',
'eshop_delivery_zones'=>'id INT PRIMARY KEY,company_id INT,zone_name VARCHAR(100),charge DECIMAL(12,2),active INT',
'branches'=>'id INT PRIMARY KEY,user_id INT,status VARCHAR(20)',
'users'=>'id INT PRIMARY KEY,status VARCHAR(20)',
'invoice_charge_types'=>'id INT PRIMARY KEY,user_id INT,status VARCHAR(20),charge_type VARCHAR(20),charge_value_type VARCHAR(20)',
'products'=>'id INT PRIMARY KEY,user_id INT,product_name VARCHAR(100),sale_price DECIMAL(12,2),status VARCHAR(20),category_id INT DEFAULT 1',
'product_categories'=>'id INT PRIMARY KEY,category_type VARCHAR(30)',
'eshop_products'=>'company_id INT,product_id INT,published INT,online_price DECIMAL(12,2)',
'product_variants'=>'id INT,product_id INT,user_id INT,variant_name VARCHAR(100)',
'stock_batches'=>'user_id INT,product_id INT,branch_id INT,variant_name VARCHAR(100),remaining_quantity DECIMAL(12,2)',
'customers'=>'id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,customer_name VARCHAR(150),phone VARCHAR(30),email VARCHAR(100),address TEXT,status VARCHAR(20)',
'invoices'=>'id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,branch_id INT,invoice_no VARCHAR(50),customer_id INT,customer_name VARCHAR(150),invoice_date DATE,total_amount DECIMAL(15,2),notes TEXT,paid_amount DECIMAL(15,2),due_amount DECIMAL(15,2),payment_status VARCHAR(20),accounting_status VARCHAR(20),created_by_name VARCHAR(100),created_by_type VARCHAR(20)',
'invoice_items'=>'id INT AUTO_INCREMENT PRIMARY KEY,invoice_id INT,product_id INT,variant_name VARCHAR(100),quantity INT,unit_price DECIMAL(15,2),total_price DECIMAL(15,2)',
'invoice_charges'=>'invoice_id INT,charge_type_id INT,amount DECIMAL(15,2)'
];
foreach($tables as $table=>$columns) $conn->query("CREATE TEMPORARY TABLE $table ($columns) ENGINE=InnoDB");
$conn->query('INSERT INTO eshop_settings VALUES(10,1)');
$conn->query('INSERT INTO eshop_checkout_settings VALUES(10,5,7)');
$conn->query('INSERT INTO eshop_profiles VALUES(10,20)');
$conn->query("INSERT INTO eshop_delivery_zones VALUES(1,10,'Inside Dhaka',60,1),(2,10,'Outside Dhaka',120,1),(3,10,'Closed area',10,0),(4,20,'Other company',1,1),(5,10,'Pickup',0,1)");
$conn->query("INSERT INTO branches VALUES(5,10,'active')");
$conn->query("INSERT INTO users VALUES(10,'active')");
$conn->query("INSERT INTO invoice_charge_types VALUES(7,10,'active','add','fixed')");
$conn->query("INSERT INTO products VALUES(1,10,'Camera',100,'active',1)");
$conn->query("INSERT INTO product_categories VALUES(1,'stock_product')");
$conn->query('INSERT INTO eshop_products VALUES(10,1,1,80)');
$conn->query("INSERT INTO stock_batches VALUES(10,1,5,'',50)");
$input=['name'=>'Zone customer','phone'=>'01700000000','email'=>'','address'=>'Test address'];
function deny_zone($fn,$message){ try{ $fn(); }catch(RuntimeException $e){ return; } throw new Exception($message); }
$total=function($orderNo) use ($conn){ $s=$conn->prepare('SELECT i.total_amount,i.notes,(SELECT COALESCE(SUM(amount),0) FROM invoice_charges c WHERE c.invoice_id=i.id) charges FROM eshop_orders o JOIN invoices i ON i.id=o.invoice_id WHERE o.order_no=?'); $s->bind_param('s',$orderNo); $s->execute(); return $s->get_result()->fetch_assoc(); };

// Without a zone key the flat Shop Settings charge still applies (older storefront links).
$flat=$total(eshop_place_order($conn,10,[1=>1],$input,str_repeat('a',64)));
if((float)$flat['total_amount']!==100.0 || (float)$flat['charges']!==20.0) throw new Exception('Flat delivery charge changed');

$outside=$total(eshop_place_order($conn,10,[1=>2],$input+['delivery_zone_id'=>2],str_repeat('b',64)));
if((float)$outside['total_amount']!==280.0 || (float)$outside['charges']!==120.0) throw new Exception('Zone charge was not applied');
if(strpos($outside['notes'],'Delivery area: Outside Dhaka')===false) throw new Exception('Delivery area missing from invoice notes');

$free=$total(eshop_place_order($conn,10,[1=>1],$input+['delivery_zone_id'=>5],str_repeat('c',64)));
if((float)$free['total_amount']!==80.0 || (float)$free['charges']!==0.0) throw new Exception('Free delivery area added a charge');

$before=(int)$conn->query('SELECT COUNT(*) FROM invoices')->fetch_row()[0];
deny_zone(fn()=>eshop_place_order($conn,10,[1=>1],$input+['delivery_zone_id'=>0],str_repeat('d',64)),'Missing area was accepted');
deny_zone(fn()=>eshop_place_order($conn,10,[1=>1],$input+['delivery_zone_id'=>3],str_repeat('e',64)),'Inactive area was accepted');
deny_zone(fn()=>eshop_place_order($conn,10,[1=>1],$input+['delivery_zone_id'=>4],str_repeat('f',64)),'Another company area was accepted');
if((int)$conn->query('SELECT COUNT(*) FROM invoices')->fetch_row()[0]!==$before) throw new Exception('Rejected area left an invoice');

if(eshop_storefront_spec_rows("Camera\nResolution: 12MP\n")!==[['label'=>'Camera','value'=>null],['label'=>'Resolution','value'=>'12MP']]) throw new Exception('Specification parsing changed');
if(eshop_storefront_lines("• One\n - Two\n\n*Three")!==['One','Two','Three']) throw new Exception('Feature list parsing changed');
$conn->close();
echo "PASS: delivery area charges, free area, flat-charge fallback, inactive/other-company/missing area rejection, invoice notes and storefront text parsing.\n";
