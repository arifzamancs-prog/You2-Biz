<?php
if(PHP_SAPI!=='cli') exit;
require_once __DIR__.'/../includes/eshop_dashboard_helper.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$conn=new mysqli('localhost',getenv('TEST_DB_USER')?:'root',getenv('TEST_DB_PASSWORD')?:'',getenv('TEST_DB_NAME')?:'you2biz');
$tables=['eshop_orders'=>'id INT,company_id INT,invoice_id INT,order_no VARCHAR(50),customer_name VARCHAR(100),total DECIMAL(12,2),created_at DATETIME','invoices'=>'id INT,user_id INT,total_amount DECIMAL(12,2),accounting_status VARCHAR(20)','eshop_delivery'=>'order_id INT,status VARCHAR(20)','sales_refunds'=>'id INT,user_id INT,invoice_id INT,amount DECIMAL(12,2)'];
foreach($tables as $table=>$columns) $conn->query("CREATE TEMPORARY TABLE $table ($columns)");
$conn->query("INSERT INTO eshop_orders VALUES(1,10,1,'O-1','A',100,'2026-10-01'),(2,10,2,'O-2','B',200,'2026-10-02'),(3,10,3,'O-3','C',300,'2026-10-03'),(4,20,4,'OTHER','X',999,'2026-10-04')");
$conn->query("INSERT INTO invoices VALUES(1,10,100,'pending'),(2,10,200,'posted'),(3,10,300,'posted'),(4,20,999,'posted')"); $conn->query("INSERT INTO eshop_delivery VALUES(1,'new'),(2,'shipped'),(3,'delivered'),(4,'cancelled')"); $conn->query('INSERT INTO sales_refunds VALUES(1,10,3,50),(2,20,4,999)');
function dashboard_assert($condition,$message){if(!$condition) throw new RuntimeException($message);}
$summary=eshop_dashboard_summary($conn,10);
dashboard_assert($summary['orders_total']===3 && $summary['pending_confirmation']===1,'Order/pending counts wrong');
dashboard_assert($summary['delivery_new']===1 && $summary['delivery_shipped']===1 && $summary['delivery_delivered']===1 && $summary['delivery_cancelled']===0,'Delivery counts leaked');
dashboard_assert($summary['net_confirmed_sales']===450.0 && $summary['refunded_total']===50.0,'Net/refund totals wrong');
dashboard_assert(count($summary['recent'])===3 && $summary['recent'][0]['order_no']==='O-3','Recent order scope/order wrong');
echo "PASS: dashboard order, delivery, net sales/refund summaries and company isolation.\n";
