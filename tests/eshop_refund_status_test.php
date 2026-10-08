<?php
if(PHP_SAPI!=='cli') exit;
require_once __DIR__.'/../includes/eshop_refund_status_helper.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$conn=new mysqli('localhost',getenv('TEST_DB_USER')?:'root',getenv('TEST_DB_PASSWORD')?:'');
$database='you2biz_eshop_test_'.bin2hex(random_bytes(8));
$conn->query("CREATE DATABASE `$database`");
function refund_status_assert($ok,$message) { if(!$ok) throw new RuntimeException($message); }
try {
    $conn->select_db($database);
    $conn->query('CREATE TABLE eshop_orders(id INT,company_id INT,invoice_id INT)');
    $conn->query('CREATE TABLE invoices(id INT,user_id INT,total_amount DECIMAL(15,2))');
    $conn->query('INSERT INTO eshop_orders VALUES(1,10,2),(3,20,4)');
    $conn->query('INSERT INTO invoices VALUES(2,10,200),(4,20,100)');
    refund_status_assert(eshop_order_refund_status($conn,10,1)['status']==='none','Missing schema fallback');
    $conn->query('CREATE TABLE sales_refunds(id INT, user_id INT,invoice_id INT,credit_invoice_id INT,amount DECIMAL(15,2),reason TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
    $conn->query("INSERT INTO sales_refunds(id,user_id,invoice_id,credit_invoice_id,amount,reason) VALUES(1,20,2,5,999,'Other company')");
    refund_status_assert(eshop_order_refund_status($conn,10,1)['amount']===0.0,'Cross-company refund leaked');
    $conn->query("INSERT INTO sales_refunds(id,user_id,invoice_id,credit_invoice_id,amount,reason) VALUES(2,10,2,6,50,'Partial')");
    $summary=eshop_order_refund_status($conn,10,1,true);
    refund_status_assert($summary['status']==='partial' && $summary['amount']===50.0 && count($summary['history'])===1,'Partial refund summary');
    $conn->query("INSERT INTO sales_refunds(id,user_id,invoice_id,credit_invoice_id,amount,reason) VALUES(3,10,2,7,150,'Remaining')");
    $summary=eshop_order_refund_status($conn,10,1,true);
    refund_status_assert($summary['status']==='full' && $summary['count']===2 && (int)$summary['history'][0]['id']===3,'Full refund summary/history ordering');
    try { eshop_order_refund_status($conn,20,1); throw new LogicException('Wrong company was allowed'); } catch(RuntimeException $e) {}
    $conn->query('DELETE FROM invoices WHERE id=2');
    refund_status_assert(eshop_order_refund_status($conn,10,1)['status']==='recorded','Missing invoice must not imply full refund');
    echo "PASS: missing schema, no/partial/full refunds, tenant isolation, history order and missing invoice.\n";
} finally {
    $conn->query("DROP DATABASE `$database`"); $conn->close();
}
