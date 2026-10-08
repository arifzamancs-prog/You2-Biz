<?php
if(PHP_SAPI!=='cli') exit;
require_once __DIR__.'/../includes/eshop_return_helper.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$conn=new mysqli('localhost',getenv('TEST_DB_USER')?:'root',getenv('TEST_DB_PASSWORD')?:'',getenv('TEST_DB_NAME')?:'you2biz');
foreach([
'eshop_orders'=>'id INT,company_id INT,invoice_id INT',
'invoices'=>'id INT,user_id INT,accounting_status VARCHAR(20)',
'eshop_delivery'=>'order_id INT,status VARCHAR(20)',
'eshop_return_requests'=>'order_id INT PRIMARY KEY,company_id INT,actor_id INT,reason VARCHAR(1000)'
] as $table=>$columns) $conn->query("CREATE TEMPORARY TABLE $table ($columns) ENGINE=InnoDB");
$conn->query('INSERT INTO eshop_orders VALUES(1,10,100)');
$conn->query("INSERT INTO invoices VALUES(100,10,'pending')");
$conn->query("INSERT INTO eshop_delivery VALUES(1,'delivered')");
function deny_return($fn){try{$fn();}catch(RuntimeException $e){return;}throw new Exception('Expected rejection');}
deny_return(fn()=>eshop_request_return($conn,20,1,20,'Wrong company'));
deny_return(fn()=>eshop_request_return($conn,10,1,10,'Pending invoice'));
$conn->query("UPDATE invoices SET accounting_status='posted'");
$conn->query("UPDATE eshop_delivery SET status='processing'");
deny_return(fn()=>eshop_request_return($conn,10,1,10,'Not shipped'));
$conn->query("UPDATE eshop_delivery SET status='delivered'");
deny_return(fn()=>eshop_request_return($conn,10,1,10,''));
eshop_request_return($conn,10,1,10,'Wrong size');
eshop_request_return($conn,10,1,10,'Repeated submission');
$rows=$conn->query('SELECT reason FROM eshop_return_requests')->fetch_all(MYSQLI_NUM);
if($rows!==[['Wrong size']]) throw new Exception('Duplicate request or overwritten reason');
if($conn->query('SELECT accounting_status FROM invoices')->fetch_row()[0]!=='posted') throw new Exception('Invoice modified');
$conn->close();
echo "PASS: tenant isolation, invoice and shipping guards, validation, duplicate request and unchanged invoice\n";
