<?php
if(PHP_SAPI!=='cli') exit;
// This test mirrors the category query used in the public storefront.
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$conn=new mysqli('localhost',getenv('TEST_DB_USER')?:'root',getenv('TEST_DB_PASSWORD')?:'',getenv('TEST_DB_NAME')?:'you2biz');
$tables=['eshop_products'=>'company_id INT,product_id INT,published INT,online_price DECIMAL(12,2)','products'=>'id INT,user_id INT,category_id INT,product_name VARCHAR(100),sale_price DECIMAL(12,2),status VARCHAR(20)','product_categories'=>'id INT,category_name VARCHAR(100),category_type VARCHAR(30),status VARCHAR(20)'];
foreach($tables as $table=>$columns) $conn->query("CREATE TEMPORARY TABLE $table ($columns)");
$conn->query("INSERT INTO product_categories VALUES(1,'Fashion','stock_product','active'),(2,'Hidden','stock_product','inactive'),(3,'Service','non_stock','active')");
$conn->query("INSERT INTO products VALUES(1,10,1,'Shirt',70,'active'),(2,10,2,'Old',30,'active'),(3,10,3,'Service',30,'active'),(4,20,1,'Other company',10,'active'),(5,10,1,'Cap',50,'active')");
$conn->query('INSERT INTO eshop_products VALUES(10,1,1,70),(10,2,1,30),(10,3,1,30),(20,4,1,10),(10,5,1,40)');
$cid=10; $s=$conn->prepare("SELECT DISTINCT c.id,c.category_name FROM eshop_products e JOIN products p ON p.id=e.product_id AND p.user_id=e.company_id JOIN product_categories c ON c.id=p.category_id AND c.category_type='stock_product' AND c.status='active' WHERE e.company_id=? AND e.published=1 AND p.status='active' ORDER BY c.category_name"); $s->bind_param('i',$cid); $s->execute(); $categories=$s->get_result()->fetch_all(MYSQLI_ASSOC);
if(count($categories)!==1 || $categories[0]['category_name']!=='Fashion') throw new RuntimeException('Category list leaked inactive/non-stock/other-company data');
$category=1; $like='%'; $offset=0; $s=$conn->prepare("SELECT p.product_name FROM eshop_products e JOIN products p ON p.id=e.product_id AND p.user_id=e.company_id JOIN product_categories c ON c.id=p.category_id AND c.category_type='stock_product' WHERE e.company_id=? AND e.published=1 AND p.status='active' AND p.product_name LIKE ? AND (?=0 OR c.id=?) ORDER BY p.id DESC LIMIT 25 OFFSET ?"); $s->bind_param('isiii',$cid,$like,$category,$category,$offset); $s->execute(); $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);
if(count($rows)!==2 || $rows[0]['product_name']!=='Cap' || $rows[1]['product_name']!=='Shirt') throw new RuntimeException('Category filter is not tenant-scoped');
$s=$conn->prepare("SELECT p.product_name FROM eshop_products e JOIN products p ON p.id=e.product_id AND p.user_id=e.company_id JOIN product_categories c ON c.id=p.category_id AND c.category_type='stock_product' WHERE e.company_id=? AND e.published=1 AND p.status='active' AND p.product_name LIKE ? AND (?=0 OR c.id=?) ORDER BY COALESCE(e.online_price,p.sale_price) ASC, p.id DESC LIMIT 25 OFFSET ?"); $s->bind_param('isiii',$cid,$like,$category,$category,$offset); $s->execute(); $sorted=$s->get_result()->fetch_all(MYSQLI_ASSOC);
if($sorted[0]['product_name']!=='Cap' || $sorted[1]['product_name']!=='Shirt') throw new RuntimeException('Price sorting is incorrect');
echo "PASS: storefront category list/filter and safe price sorting exclude inactive, non-stock and other-company products.\n";
