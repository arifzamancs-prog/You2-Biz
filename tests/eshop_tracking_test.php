<?php
if(PHP_SAPI!=='cli') exit;
require_once __DIR__.'/../includes/eshop_tracking_helper.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$conn=new mysqli('localhost',getenv('TEST_DB_USER')?:'root',getenv('TEST_DB_PASSWORD')?:'',getenv('TEST_DB_NAME')?:'you2biz');
$tables=['eshop_orders'=>'id INT,company_id INT,invoice_id INT,order_no VARCHAR(50),phone VARCHAR(30),customer_name VARCHAR(100),total DECIMAL(12,2),created_at DATETIME','eshop_delivery'=>'order_id INT,status VARCHAR(20),tracking VARCHAR(100)','invoices'=>'id INT,user_id INT,total_amount DECIMAL(12,2),accounting_status VARCHAR(20),payment_status VARCHAR(20),due_amount DECIMAL(12,2)','invoice_items'=>'id INT,invoice_id INT,product_id INT,variant_name VARCHAR(100),quantity INT,unit_price DECIMAL(12,2),total_price DECIMAL(12,2)','products'=>'id INT,user_id INT,product_name VARCHAR(100)','eshop_delivery_history'=>'id INT,order_id INT,status VARCHAR(20),tracking VARCHAR(100),created_at DATETIME'];
foreach($tables as $table=>$columns) $conn->query("CREATE TEMPORARY TABLE $table ($columns)");
$conn->query("INSERT INTO eshop_orders VALUES(1,10,5,'ESH-TEST','01700000000','Customer',300,'2026-10-07 10:00:00'),(2,20,6,'ESH-TEST','01700000000','Other',500,'2026-10-07 10:00:00')");
$conn->query("INSERT INTO invoices VALUES(5,10,300,'posted','due',300),(6,20,500,'posted','paid',0)"); $conn->query("INSERT INTO products VALUES(1,10,'Shirt'),(2,20,'Other product')"); $conn->query("INSERT INTO invoice_items VALUES(1,5,1,'Red / Large',2,150,300),(2,6,2,'Other',1,500,500)"); $conn->query("INSERT INTO eshop_delivery VALUES(1,'shipped','TRACK-1')"); $conn->query("INSERT INTO eshop_delivery_history VALUES(1,1,'processing','','2026-10-07 10:01:00'),(2,1,'shipped','TRACK-1','2026-10-07 10:02:00')");
function tracking_assert($condition,$message){if(!$condition) throw new RuntimeException($message);}
$tracked=eshop_public_order_tracking($conn,10,'ESH-TEST','01700000000'); tracking_assert($tracked['delivery_status']==='shipped' && count($tracked['history'])===2,'Valid tracked order missing details'); tracking_assert(count($tracked['items'])===1 && $tracked['items'][0]['variant_name']==='Red / Large','Matched order did not expose its own variant item');
tracking_assert(eshop_public_order_tracking($conn,10,'ESH-TEST','01700000001')===null,'Wrong phone exposed order');
tracking_assert(eshop_public_order_tracking($conn,20,'ESH-TEST','01700000000')['customer_name']==='Other','Company scope failed');
tracking_assert(eshop_public_order_tracking($conn,10,str_repeat('X',51),'01700000000')===null,'Invalid reference accepted');
echo "PASS: public tracking requires company-scoped order reference plus phone, exposes matched variant items and delivery only after match, and keeps refund fallback safe.\n";
