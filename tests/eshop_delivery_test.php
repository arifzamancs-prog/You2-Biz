<?php
if(PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../includes/eshop_delivery_helper.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli('localhost', getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '', getenv('TEST_DB_NAME') ?: 'you2biz');
// Connection-local temporary tables shadow real tables and disappear on close.
$conn->query('CREATE TEMPORARY TABLE eshop_orders(id BIGINT PRIMARY KEY,company_id BIGINT,invoice_id INT) ENGINE=InnoDB');
$conn->query('CREATE TEMPORARY TABLE invoices(id INT PRIMARY KEY,user_id BIGINT,accounting_status VARCHAR(20)) ENGINE=InnoDB');
$conn->query('CREATE TEMPORARY TABLE eshop_delivery(order_id BIGINT PRIMARY KEY,status VARCHAR(20),tracking VARCHAR(200)) ENGINE=InnoDB');
$conn->query('CREATE TEMPORARY TABLE eshop_delivery_history(id INT AUTO_INCREMENT PRIMARY KEY,order_id BIGINT,actor_id BIGINT,status VARCHAR(20),tracking VARCHAR(200)) ENGINE=InnoDB');
$conn->query("INSERT INTO eshop_orders VALUES(1,10,100),(2,20,200)");
$conn->query("INSERT INTO invoices VALUES(100,10,'pending'),(200,20,'posted')");
function rejected($fn){ try{ $fn(); }catch(RuntimeException $e){ return; } throw new Exception('Expected rejection'); }
rejected(fn()=>eshop_update_delivery($conn,20,1,'processing','',20));
rejected(fn()=>eshop_update_delivery($conn,10,1,'delivered','',10));
eshop_update_delivery($conn,10,1,'processing','',10);
rejected(fn()=>eshop_update_delivery($conn,10,1,'shipped','TRACK',10));
if($conn->query('SELECT COUNT(*) FROM eshop_delivery_history')->fetch_row()[0] != 1) throw new Exception('Failed transition wrote history');
$conn->query("UPDATE invoices SET accounting_status='posted' WHERE id=100");
eshop_update_delivery($conn,10,1,'shipped','TRACK',10);
eshop_update_delivery($conn,10,1,'delivered','TRACK',10);
eshop_update_delivery($conn,10,1,'delivered','TRACK',10);
rejected(fn()=>eshop_update_delivery($conn,10,1,'processing','',10));
if($conn->query('SELECT COUNT(*) FROM eshop_delivery_history')->fetch_row()[0] != 3) throw new Exception('History count incorrect');
if($conn->query("SELECT status FROM eshop_delivery WHERE order_id=1")->fetch_row()[0] !== 'delivered') throw new Exception('Final state incorrect');
$conn->close();
echo "PASS: tenant isolation, transition rules, invoice guard, rollback, history and duplicate updates\n";
