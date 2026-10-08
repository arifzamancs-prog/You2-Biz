<?php
// Read-only release preflight. It never creates, alters, or updates database records.
if(PHP_SAPI!=='cli') exit;
require_once __DIR__.'/../includes/smtp_mailer.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$database=getenv('YOU2BIZ_PREFLIGHT_DB')?:'you2biz';
if(!preg_match('/\A[a-zA-Z0-9_]+\z/',$database)) throw new RuntimeException('Invalid database name.');
$conn=new mysqli('localhost',getenv('TEST_DB_USER')?:'root',getenv('TEST_DB_PASSWORD')?:'',$database);
$conn->set_charset('utf8mb4');
$failures=[]; $warnings=[];
$requiredTables=['users','branches','products','product_categories','product_variants','stock_batches','customers','wallets','invoices','invoice_items','invoice_charge_types','eshop_settings','eshop_profiles','eshop_products','eshop_checkout_settings','eshop_orders','eshop_delivery','eshop_delivery_history','eshop_notifications'];
foreach($requiredTables as $table){ $result=$conn->query("SHOW TABLES LIKE '$table'"); if(!$result->num_rows) $failures[]="Missing table: $table"; }
$requiredColumns=['products'=>['user_id','category_id','status','sale_price'],'product_categories'=>['category_type'],'product_variants'=>['product_id','user_id','variant_name'],'stock_batches'=>['user_id','product_id','branch_id','variant_name','remaining_quantity'],'invoice_items'=>['invoice_id','product_id','variant_name','quantity'],'invoices'=>['user_id','branch_id','accounting_status','payment_status'],'eshop_settings'=>['company_id','slug','enabled'],'eshop_products'=>['company_id','product_id','published'],'eshop_orders'=>['company_id','invoice_id','order_no','request_key'],'eshop_delivery'=>['order_id','status']];
foreach($requiredColumns as $table=>$columns){
    $available=[]; $result=$conn->query("SHOW COLUMNS FROM `$table`"); while($row=$result->fetch_assoc()) $available[]=$row['Field'];
    foreach($columns as $column) if(!in_array($column,$available,true)) $failures[]="Missing column: $table.$column";
}
if(!$conn->query("SHOW TABLES LIKE 'sales_refunds'")->num_rows) $warnings[]='sales_refunds is not present yet; refund history will become available after its Sales module schema is provisioned.';
$uploadDir=__DIR__.'/../uploads/products';
if(!is_dir($uploadDir)) $failures[]='Product upload directory is missing.';
elseif(!is_writable($uploadDir)) $warnings[]='Product upload directory is not writable by the PHP process.';
$smtp=smtp_configuration_status($conn);
if(!$smtp['ready']) $warnings[]='SMTP configuration needs attention: '.implode(', ',$smtp['issues']).'.';
$conn->close();
if($failures){ foreach($failures as $failure) fwrite(STDERR,"FAIL: $failure\n"); }
foreach($warnings as $warning) fwrite(STDOUT,"WARN: $warning\n");
if($failures) exit(1);
echo "PASS: E-shop release preflight completed without modifying database data.\n";
