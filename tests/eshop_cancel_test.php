<?php
if(PHP_SAPI!=='cli') exit;
require_once __DIR__.'/../includes/eshop_cancel_helper.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$conn=new mysqli('localhost',getenv('TEST_DB_USER')?:'root',getenv('TEST_DB_PASSWORD')?:'',getenv('TEST_DB_NAME')?:'you2biz');
// Only connection-local temporary tables are written; no production records are used.
$definitions=[
 'eshop_orders'=>'id INT PRIMARY KEY,company_id INT,invoice_id INT',
 'invoices'=>'id INT PRIMARY KEY,user_id INT,accounting_status VARCHAR(20),paid_amount DECIMAL(12,2)',
 'eshop_cancellations'=>'order_id INT PRIMARY KEY,actor_id INT,reason VARCHAR(500),invoice_snapshot LONGTEXT',
 'eshop_delivery'=>'order_id INT PRIMARY KEY,status VARCHAR(20),tracking VARCHAR(200)',
 'eshop_delivery_history'=>'id INT AUTO_INCREMENT PRIMARY KEY,order_id INT,actor_id INT,status VARCHAR(20),tracking VARCHAR(200)',
 'customer_payments'=>'id INT,invoice_id INT',
 'transactions'=>'id INT,user_id INT,reference_id INT,transaction_type VARCHAR(30)',
 'invoice_items'=>'id INT,invoice_id INT,product_id INT,quantity INT,total_price DECIMAL(12,2)',
 'products'=>'id INT,user_id INT,product_name VARCHAR(100)',
 'invoice_charges'=>'invoice_id INT,amount DECIMAL(12,2)'
];
foreach($definitions as $table=>$columns) $conn->query("CREATE TEMPORARY TABLE $table ($columns) ENGINE=InnoDB");
$conn->query('INSERT INTO eshop_orders VALUES(1,10,100),(2,10,200),(3,10,300),(4,10,400)');
$conn->query("INSERT INTO invoices VALUES(100,10,'pending',0),(200,10,'posted',0),(300,10,'pending',5),(400,10,'pending',0)");
$conn->query('INSERT INTO customer_payments VALUES(1,400)');
$conn->query("INSERT INTO products VALUES(9,10,'Test item')");
$conn->query('INSERT INTO invoice_items VALUES(1,100,9,2,50)');
$conn->query('INSERT INTO invoice_charges VALUES(100,10)');
function reject_cancel($fn){try{$fn();}catch(RuntimeException $e){return;}throw new Exception('Expected rejection');}
reject_cancel(fn()=>eshop_cancel_pending_order($conn,20,1,'Wrong owner',20));
reject_cancel(fn()=>eshop_cancel_pending_order($conn,10,1,'',10));
reject_cancel(fn()=>eshop_cancel_pending_order($conn,10,2,'Posted',10));
reject_cancel(fn()=>eshop_cancel_pending_order($conn,10,3,'Paid',10));
reject_cancel(fn()=>eshop_cancel_pending_order($conn,10,4,'Payment record',10));
eshop_cancel_pending_order($conn,10,1,'Customer requested cancellation',10);
eshop_cancel_pending_order($conn,10,1,'Repeated click',10);
if($conn->query('SELECT COUNT(*) FROM invoices WHERE id=100')->fetch_row()[0]!=0)throw new Exception('Invoice not removed');
if($conn->query('SELECT COUNT(*) FROM invoice_items WHERE invoice_id=100')->fetch_row()[0]!=0)throw new Exception('Reservation not released');
if($conn->query('SELECT COUNT(*) FROM invoice_charges WHERE invoice_id=100')->fetch_row()[0]!=0)throw new Exception('Charges remain');
if($conn->query('SELECT COUNT(*) FROM invoices')->fetch_row()[0]!=3)throw new Exception('Protected invoices changed');
if($conn->query('SELECT COUNT(*) FROM eshop_delivery_history')->fetch_row()[0]!=1)throw new Exception('Duplicate history');
$snapshot=json_decode($conn->query('SELECT invoice_snapshot FROM eshop_cancellations WHERE order_id=1')->fetch_row()[0],true);
if($snapshot['items'][0]['product_name']!=='Test item' || count($snapshot['charges'])!==1)throw new Exception('Snapshot incomplete');
$conn->close();
echo "PASS: tenant isolation, unpaid/pending guards, payment guard, reservation release, snapshot preservation, duplicate cancellation\n";
