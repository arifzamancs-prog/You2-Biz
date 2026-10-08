<?php
if(PHP_SAPI!=='cli') exit;
require_once __DIR__.'/../includes/eshop_order_helper.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$conn=new mysqli('localhost',getenv('TEST_DB_USER')?:'root',getenv('TEST_DB_PASSWORD')?:'',getenv('TEST_DB_NAME')?:'you2biz');
// Temporary shadows exercise actual checkout SQL without touching business data.
$tables=[
'eshop_orders'=>'id INT AUTO_INCREMENT PRIMARY KEY,company_id INT,invoice_id INT,order_no VARCHAR(50) UNIQUE,request_key CHAR(64) UNIQUE,customer_name VARCHAR(150),phone VARCHAR(30),email VARCHAR(100),address TEXT,total DECIMAL(15,2)',
'eshop_checkout_settings'=>'company_id INT PRIMARY KEY,branch_id INT,charge_type_id INT',
'eshop_settings'=>'company_id INT PRIMARY KEY,enabled INT',
'eshop_profiles'=>'company_id INT PRIMARY KEY,delivery_charge DECIMAL(12,2)',
'branches'=>'id INT PRIMARY KEY,user_id INT,status VARCHAR(20)',
'users'=>'id INT PRIMARY KEY,status VARCHAR(20)',
'invoice_charge_types'=>'id INT PRIMARY KEY,user_id INT,status VARCHAR(20),charge_type VARCHAR(20),charge_value_type VARCHAR(20)',
'products'=>'id INT PRIMARY KEY,user_id INT,product_name VARCHAR(100),sale_price DECIMAL(12,2),status VARCHAR(20),category_id INT DEFAULT 1',
'product_categories'=>'id INT PRIMARY KEY,category_type VARCHAR(30)',
'eshop_products'=>'company_id INT,product_id INT,published INT,online_price DECIMAL(12,2)',
'product_variants'=>'id INT,product_id INT,user_id INT,variant_name VARCHAR(100)',
'stock_batches'=>'user_id INT,product_id INT,branch_id INT,variant_name VARCHAR(100),remaining_quantity DECIMAL(12,2)',
'customers'=>'id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,customer_name VARCHAR(150),phone VARCHAR(30) UNIQUE,email VARCHAR(100),address TEXT,status VARCHAR(20)',
'invoices'=>'id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,branch_id INT,invoice_no VARCHAR(50),customer_id INT,customer_name VARCHAR(150),invoice_date DATE,total_amount DECIMAL(15,2),notes TEXT,paid_amount DECIMAL(15,2),due_amount DECIMAL(15,2),payment_status VARCHAR(20),accounting_status VARCHAR(20),created_by_name VARCHAR(100),created_by_type VARCHAR(20)',
'invoice_items'=>'id INT AUTO_INCREMENT PRIMARY KEY,invoice_id INT,product_id INT,variant_name VARCHAR(100),quantity INT,unit_price DECIMAL(15,2),total_price DECIMAL(15,2)',
'invoice_charges'=>'invoice_id INT,charge_type_id INT,amount DECIMAL(15,2)'
];
foreach($tables as $table=>$columns) $conn->query("CREATE TEMPORARY TABLE $table ($columns) ENGINE=InnoDB");
$conn->query('INSERT INTO eshop_settings VALUES(10,1)');
$conn->query('INSERT INTO eshop_checkout_settings VALUES(10,5,7)');
$conn->query('INSERT INTO eshop_profiles VALUES(10,20)');
$conn->query("INSERT INTO branches VALUES(5,10,'active')");
$conn->query("INSERT INTO users VALUES(10,'active')");
$conn->query("INSERT INTO invoice_charge_types VALUES(7,10,'active','add','fixed')");
$conn->query("INSERT INTO products VALUES(1,10,'Test',100,'active',1),(2,20,'Other company',100,'active',1)");
$conn->query("INSERT INTO product_categories VALUES(1,'stock_product'),(2,'non_stock')");
$conn->query('INSERT INTO eshop_products VALUES(10,1,1,80),(20,2,1,80)');
$conn->query("INSERT INTO stock_batches VALUES(10,1,5,'',5)");
$input=['name'=>'Test customer','phone'=>'01700000000','email'=>'test@example.com','address'=>'Test address'];
function deny_checkout($fn){try{$fn();}catch(RuntimeException $e){return;}throw new Exception('Expected rejection');}
deny_checkout(fn()=>eshop_place_order($conn,10,[2=>1],$input,str_repeat('a',64)));
deny_checkout(fn()=>eshop_place_order($conn,10,[1=>6],$input,str_repeat('b',64)));
$key=str_repeat('c',64);
$no=eshop_place_order($conn,10,[1=>2],$input,$key);
if(eshop_place_order($conn,10,[1=>2],$input,$key)!==$no) throw new Exception('Duplicate changed order');
$invoice=$conn->query('SELECT * FROM invoices')->fetch_assoc();
if($invoice['accounting_status']!=='pending' || (float)$invoice['paid_amount']!==0.0 || (float)$invoice['due_amount']!==180.0 || (int)$invoice['branch_id']!==5) throw new Exception('Invoice totals/status incorrect');
if((float)$conn->query('SELECT remaining_quantity FROM stock_batches')->fetch_row()[0]!==5.0) throw new Exception('Checkout consumed physical stock');
if(pending_invoice_reserved_quantity($conn,10,1)!==2.0) throw new Exception('Missing reservation');
deny_checkout(fn()=>eshop_place_order($conn,10,[1=>4],$input,str_repeat('d',64)));
$conn->query('UPDATE eshop_products SET published=0 WHERE company_id=10');
deny_checkout(fn()=>eshop_place_order($conn,10,[1=>1],$input,str_repeat('e',64)));
$conn->query('UPDATE eshop_products SET published=1 WHERE company_id=10');
$conn->query("INSERT INTO product_variants VALUES(1,1,10,'Red')");
deny_checkout(fn()=>eshop_place_order($conn,10,[1=>1],$input,str_repeat('f',64)));
$conn->query('DELETE FROM product_variants');
$conn->query('UPDATE products SET category_id=2 WHERE id=1');
deny_checkout(fn()=>eshop_place_order($conn,10,[1=>1],$input,str_repeat('2',64)));
$conn->query('UPDATE products SET category_id=999 WHERE id=1');
deny_checkout(fn()=>eshop_place_order($conn,10,[1=>1],$input,str_repeat('3',64)));
$conn->query('UPDATE products SET category_id=1 WHERE id=1');
$conn->query("INSERT INTO customers(user_id,customer_name,phone,status) VALUES(20,'Other','01800000000','active')");
deny_checkout(fn()=>eshop_place_order($conn,10,[1=>1],array_merge($input,['phone'=>'01800000000']),str_repeat('1',64)));
if($conn->query('SELECT COUNT(*) FROM eshop_orders')->fetch_row()[0]!=1 || $conn->query('SELECT COUNT(*) FROM invoices')->fetch_row()[0]!=1) throw new Exception('Failed checkout left records');
$conn->query("INSERT INTO product_variants VALUES(2,1,10,'Red')");
$conn->query("INSERT INTO stock_batches VALUES(10,1,5,'Red',3)");
$variantOrder=eshop_place_order($conn,10,['1|Red'=>2],$input,str_repeat('v',64));
$variantInvoice=(int)$conn->query("SELECT invoice_id FROM eshop_orders WHERE order_no='".$conn->real_escape_string($variantOrder)."'")->fetch_row()[0];
$variantItem=$conn->query("SELECT variant_name,quantity FROM invoice_items WHERE invoice_id=$variantInvoice")->fetch_assoc();
if($variantItem['variant_name']!=='Red' || (int)$variantItem['quantity']!==2) throw new Exception('Variant checkout did not preserve the selected variant');
if(pending_invoice_reserved_quantity($conn,10,1,0,'Red')!==2.0) throw new Exception('Variant reservation is missing');
deny_checkout(fn()=>eshop_place_order($conn,10,['1|Blue'=>1],$input,str_repeat('w',64)));
$conn->close();
echo "PASS: checkout totals, pending invoice, simple and variant reservations, tenant scope, duplicate submission, product restrictions and rollback.\n";
