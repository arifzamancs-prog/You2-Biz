<?php
if(PHP_SAPI!=='cli') exit;
require_once __DIR__.'/../includes/eshop_notification_helper.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$conn=new mysqli('localhost',getenv('TEST_DB_USER')?:'root',getenv('TEST_DB_PASSWORD')?:'',getenv('TEST_DB_NAME')?:'you2biz');
$tables=[
'eshop_orders'=>'id INT,company_id INT,invoice_id INT,order_no VARCHAR(50),customer_name VARCHAR(150),email VARCHAR(100),total DECIMAL(12,2)',
'users'=>'id INT,name VARCHAR(100)',
'eshop_delivery'=>'order_id INT,status VARCHAR(20),tracking VARCHAR(200)',
'invoices'=>'id INT,user_id INT',
'eshop_notifications'=>"id INT AUTO_INCREMENT PRIMARY KEY,company_id INT,order_id INT,actor_id INT,request_key CHAR(64) UNIQUE,recipient VARCHAR(100),delivery_status VARCHAR(20),result VARCHAR(20) DEFAULT 'sending'"
];
foreach($tables as $table=>$columns) $conn->query("CREATE TEMPORARY TABLE $table ($columns) ENGINE=InnoDB");
$conn->query("INSERT INTO users VALUES(10,'Company & Co')");
$conn->query("INSERT INTO invoices VALUES(100,10)");
$conn->query("INSERT INTO eshop_orders VALUES(1,10,100,'ESH-1','<Customer>','test@example.com',50),(2,10,100,'ESH-2','Invalid','',20)");
$conn->query("INSERT INTO eshop_delivery VALUES(1,'shipped','<TRACK>')");
$calls=0;
$sender=function($to,$name,$subject,$body,$attachments,$options)use(&$calls){
    $calls++;
    if($options['from_name']!=='Company & Co' || !str_contains($body,'&lt;TRACK&gt;') || !str_contains($body,'&lt;Customer&gt;')) throw new Exception('Invalid email content');
    return [true,''];
};
function reject_notification($fn){try{$fn();}catch(RuntimeException $e){return;}throw new Exception('Expected rejection');}
reject_notification(fn()=>eshop_notify_order($conn,20,1,20,str_repeat('a',64),$sender));
reject_notification(fn()=>eshop_notify_order($conn,10,2,10,str_repeat('b',64),$sender));
eshop_notify_order($conn,10,1,10,str_repeat('c',64),$sender);
reject_notification(fn()=>eshop_notify_order($conn,10,1,10,str_repeat('c',64),$sender));
reject_notification(fn()=>eshop_notify_order($conn,10,1,10,str_repeat('d',64),fn()=>[false,'Unavailable']));
if($calls!==1) throw new Exception('Unexpected email attempt');
$results=$conn->query('SELECT result FROM eshop_notifications ORDER BY id')->fetch_all(MYSQLI_NUM);
if($results!==[['invalid_email'],['accepted'],['failed']]) throw new Exception('Incorrect log');
$auto_calls=0;
$auto_sender=function()use(&$auto_calls){ $auto_calls++; return [true,'']; };
if(!eshop_auto_notify_reference($conn,10,'ESH-1',$auto_sender)) throw new Exception('Automatic email failed');
eshop_auto_notify_reference($conn,10,'ESH-1',$auto_sender);
if($auto_calls!==1) throw new Exception('Repeated status caused duplicate email');
$conn->query("UPDATE eshop_delivery SET status='delivered' WHERE order_id=1");
if(!eshop_auto_notify($conn,10,1,10,$auto_sender) || $auto_calls!==2) throw new Exception('New status did not notify');
$conn->query("UPDATE eshop_delivery SET status='cancelled' WHERE order_id=1");
$conn->query('DELETE FROM invoices WHERE id=100');
if(!eshop_auto_notify($conn,10,1,10,$auto_sender) || $auto_calls!==3) throw new Exception('Cancellation did not notify');
$conn->query("UPDATE eshop_delivery SET status='processing' WHERE order_id=1");
if(eshop_auto_notify($conn,10,1,10,$auto_sender)) throw new Exception('Missing invoice should be rejected');
$conn->query('INSERT INTO invoices VALUES(100,10)');
if(eshop_auto_notify($conn,10,1,10,fn()=>throw new Exception('SMTP unavailable'))) throw new Exception('Transport failure should return false');
if($conn->query("SELECT status FROM eshop_delivery WHERE order_id=1")->fetch_row()[0]!=='processing') throw new Exception('Email failure changed order state');
$conn->close();
echo "PASS: tenant scope, invalid email, sender name, escaped content, duplicate guard and result history. No email sent.\n";
