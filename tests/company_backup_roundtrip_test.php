<?php
// Runs the actual import POST handler against a disposable schema, never live rows.
if(PHP_SAPI !== 'cli') exit;
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../includes/company_backup_helper.php';
function is_super_admin_user(){ return false; }
define('SUPER_ADMIN_PROFILE_AVATAR','test.png');
function branding_company_default_avatar_filename($conn){ return 'test.png'; }
function branding_avatar_upload_dir_path(){ return sys_get_temp_dir(); }
function check_backup_test($ok, $message){ if(!$ok) throw new RuntimeException($message); }
$conn = new mysqli('localhost','root','','you2biz');
$test_db = 'you2biz_staging_' . bin2hex(random_bytes(8));
$conn->query("CREATE DATABASE `$test_db`");
$backup_file = tempnam(sys_get_temp_dir(), 'you2backup');
$fashion = in_array('--fashion', $argv, true);
$housing = in_array('--housing', $argv, true);
$service = in_array('--service', $argv, true);
$service_multi = $service && in_array('--multi-branch', $argv, true);
$restaurant = in_array('--restaurant', $argv, true);
$restaurant_multi = $restaurant && in_array('--multi-branch', $argv, true);
$parking = in_array('--parking', $argv, true);
try{
    $tables = $conn->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetch_all();
    foreach($tables as $table) $conn->query("CREATE TABLE `$test_db`.`{$table[0]}` LIKE `you2biz`.`{$table[0]}`");
    $conn->select_db($test_db);
    $hash = $conn->real_escape_string(password_hash('backup-test', PASSWORD_DEFAULT));
    $conn->query("INSERT INTO users(id,name,email,phone,password,role,company_type) VALUES(1,'Backup Test','backup@example.test','00000000001','$hash','admin','Stock Product')");
    $queries = [
        "INSERT INTO branches(id,user_id,branch_name,is_head_office,status) VALUES(10,1,'Head Office',1,'active'),(20,1,'Warehouse',0,'active')",
        "INSERT INTO wallets(id,user_id,branch_id,wallet_name,balance) VALUES(10,1,10,'Cash',500),(20,1,10,'Bank',250)",
        "INSERT INTO categories(id,user_id,category_name) VALUES(40,1,'Expense')",
        "INSERT INTO product_categories(id,user_id,category_name) VALUES(50,1,'Products')",
        "INSERT INTO products(id,user_id,category_id,product_name,sku,purchase_price,sale_price,current_stock) VALUES(60,1,50,'Test','TEST',40,100,8)",
        "INSERT INTO customers(id,user_id,customer_name) VALUES(70,1,'Test Customer')",
        "INSERT INTO suppliers(id,user_id,supplier_name) VALUES(80,1,'Test Vendor')",
        "INSERT INTO purchases(id,user_id,branch_id,purchase_no,supplier_id,payment_wallet_id,purchase_date,total_amount) VALUES(90,1,20,'PUR-TEST',80,20,CURDATE(),400)",
        "INSERT INTO purchase_items(id,purchase_id,product_id,quantity,unit_cost,total_cost) VALUES(100,90,60,10,40,400)",
        "INSERT INTO stock_batches(id,user_id,branch_id,product_id,source_type,source_id,quantity,remaining_quantity,unit_cost) VALUES(110,1,20,60,'purchase',90,10,8,40)",
        "INSERT INTO invoices(id,user_id,branch_id,invoice_no,customer_id,receive_wallet_id,invoice_date,total_amount,paid_amount) VALUES(120,1,10,'INV-TEST',70,10,CURDATE(),200,150)",
        "INSERT INTO invoice_items(id,invoice_id,product_id,quantity,unit_price,total_price,cost_amount) VALUES(130,120,60,2,100,200,80)",
        "INSERT INTO expenses(id,user_id,branch_id,wallet_id,category_id,txn_no,txn_date,amount) VALUES(140,1,10,10,40,'EXP-TEST',CURDATE(),25)",
        "INSERT INTO money_ins(id,user_id,branch_id,wallet_id,txn_no,txn_date,amount) VALUES(150,1,10,20,'IN-TEST',CURDATE(),500)",
        "INSERT INTO transfers(id,user_id,branch_id,from_wallet_id,to_wallet_id,txn_no,txn_date,amount) VALUES(160,1,10,10,20,'TR-TEST',CURDATE(),50)",
        "INSERT INTO transactions(user_id,branch_id,wallet_id,txn_no,transaction_type,reference_id,amount,txn_date) VALUES(1,10,10,'TX1','sales_invoice',120,150,CURDATE()),(1,10,10,'TX2','expense',140,25,CURDATE()),(1,10,20,'TX3','money_in',150,500,CURDATE()),(1,10,10,'TX4','transfer_out',160,50,CURDATE())",
        "INSERT INTO stock_distributions(id,user_id,from_branch_id,to_branch_id,product_id,quantity,reference_no,request_key,created_by) VALUES(170,1,20,10,60,2,'DIST-TEST','dist-test',1)",
        "INSERT INTO stock_distribution_items(distribution_id,source_batch_id,destination_batch_id,quantity,unit_cost) VALUES(170,110,110,2,40)",
    ];
    foreach($queries as $sql) $conn->query($sql);
    if($fashion){
        $conn->query("UPDATE users SET company_type='Fashion house',multi_branch_enabled=1 WHERE id=1");
        $conn->query("INSERT INTO product_variants(user_id,product_id,variant_name) VALUES(1,60,'XL'),(1,60,'XXL')");
        foreach(['purchase_items','invoice_items','stock_batches','stock_distributions'] as $variant_table){
            $conn->query("UPDATE `$variant_table` SET variant_name='XL'");
        }
        $conn->query("INSERT INTO stock_batches(id,user_id,branch_id,product_id,variant_name,source_type,source_id,quantity,remaining_quantity,unit_cost) VALUES(180,1,10,60,'XL','distribution',170,2,0,40),(190,1,10,60,'XL','sales_return',130,1,1,40),(200,1,20,60,'XXL','product_opening',60,5,5,45)");
        $conn->query('UPDATE stock_distribution_items SET destination_batch_id=180');
        $conn->query('INSERT INTO invoice_item_allocations(invoice_item_id,stock_batch_id,quantity,unit_cost,total_cost) VALUES(130,180,2,40,80)');
        $conn->query("INSERT INTO stock_damage_returns(id,user_id,branch_id,product_id,variant_name,quantity,request_key,created_by,status) VALUES(210,1,10,60,'XL',1,'test-damage',1,'pending')");
        $conn->query('INSERT INTO stock_damage_return_items(damage_return_id,source_batch_id,quantity) VALUES(210,190,1)');
    }
    if($housing || $service){
        $conn->query("UPDATE users SET company_type='Housing' WHERE id=1");
        $conn->query("INSERT INTO projects(id,user_id,project_name,project_code) VALUES(220,1,'Housing Project','HP')");
        $conn->query("INSERT INTO packages(id,user_id,project_id,package_name,price) VALUES(230,1,220,'Plot Package',5000)");
        $conn->query("INSERT INTO booking_invoices(id,user_id,branch_id,invoice_no,customer_id,project_id,package_id,wallet_id,invoice_date,amount,status,wallet_effect_applied) VALUES(240,1,10,'BOOK-TEST',70,220,230,10,CURDATE(),1000,'confirmed',1)");
        $conn->query("INSERT INTO invoice_charge_types(id,user_id,charge_name) VALUES(250,1,'Booking Fee')");
        $conn->query("INSERT INTO booking_invoice_charges(booking_invoice_id,charge_type_id,charge_name,charge_amount) VALUES(240,250,'Booking Fee',50)");
        $conn->query("INSERT INTO transactions(user_id,branch_id,wallet_id,txn_no,transaction_type,reference_id,amount,txn_date) VALUES(1,10,10,'BOOK-TX','invoice_income',240,1000,CURDATE())");
        $conn->query("INSERT INTO land_entries(user_id,project_id,entry_type,land_size,unit) VALUES(1,220,'Land Purchase',10,'Katha')");
        $conn->query("INSERT INTO land_plots(user_id,project_id,block_name,plot_size,total_plots,sold_plots) VALUES(1,220,'A',5,2,1)");
    }
    if($service){
        $conn->query("UPDATE users SET company_type='Service type',multi_branch_enabled=".($service_multi ? 1 : 0)." WHERE id=1");
        $conn->query("UPDATE projects SET project_name='Service Category' WHERE id=220");
        $conn->query("UPDATE packages SET package_name='Consultation Service' WHERE id=230");
        $conn->query("INSERT INTO booking_invoice_types(user_id,type_key,type_name,behavior) VALUES(1,'consultation','Consultation','income'),(1,'refund','Refund','expense')");
        $conn->query("UPDATE booking_invoices SET invoice_type='consultation' WHERE id=240");
        $service_branch = $service_multi ? 20 : 10;
        $conn->query("INSERT INTO booking_invoices(id,user_id,branch_id,invoice_no,customer_id,project_id,package_id,wallet_id,invoice_type,invoice_date,amount,status,wallet_effect_applied) VALUES(260,1,$service_branch,'SERVICE-REFUND',70,220,230,20,'refund',CURDATE(),100,'confirmed',1)");
        $conn->query("INSERT INTO transactions(user_id,branch_id,wallet_id,txn_no,transaction_type,reference_id,amount,txn_date) VALUES(1,$service_branch,20,'SERVICE-TX','invoice_expense',260,100,CURDATE())");
        if($service_multi) $conn->query('UPDATE wallets SET branch_id=20 WHERE id=20');
    }
    if($restaurant){
        $restaurant_branch = $restaurant_multi ? 20 : 10;
        $conn->query("UPDATE users SET company_type='Restaurant & Cafe',multi_branch_enabled=".($restaurant_multi ? 1 : 0)." WHERE id=1");
        $conn->query("INSERT INTO restaurant_tables(id,user_id,table_name,capacity,status) VALUES(320,1,'Table A',4,'active')");
        $conn->query("UPDATE invoices SET branch_id=$restaurant_branch,receive_wallet_id=".($restaurant_multi ? 20 : 10).",restaurant_table_id=320 WHERE id=120");
        if($restaurant_multi){
            $conn->query('UPDATE wallets SET branch_id=20 WHERE id=20');
            $conn->query('UPDATE purchases SET branch_id=20,payment_wallet_id=20 WHERE id=90');
            $conn->query('UPDATE stock_batches SET branch_id=20 WHERE id=110');
            $conn->query("UPDATE transactions SET branch_id=20,wallet_id=20 WHERE txn_no='TX1'");
        }
    }
    if($parking){
        $conn->query("UPDATE users SET company_type='Car Parking' WHERE id=1");
        $conn->query("INSERT INTO parking_settings(company_id,slot_management_enabled,grace_minutes) VALUES(1,1,10)");
        $conn->query("INSERT INTO parking_vehicle_types(id,company_id,vehicle_code,vehicle_name,default_capacity,status) VALUES(400,1,'CAR','Car',4,'active')");
        $conn->query("INSERT INTO parking_rate_plans(id,company_id,vehicle_type_id,plan_name,rate_mode,fixed_fee,status) VALUES(410,1,400,'Hourly','fixed',30,'active')");
        $conn->query("INSERT INTO parking_gates(id,company_id,branch_id,gate_name,gate_code,gate_type,status) VALUES(420,1,10,'Main Gate','MAIN','both','active')");
        $conn->query("INSERT INTO parking_gate_staff_assignments(id,company_id,gate_id,user_id,access_type,status) VALUES(425,1,420,1,'both','active')");
        $conn->query("INSERT INTO parking_zones(id,company_id,branch_id,zone_name,zone_code,vehicle_type_id,capacity,status) VALUES(430,1,10,'Zone A','A',400,10,'active')");
        $conn->query("INSERT INTO parking_slots(id,company_id,branch_id,zone_id,slot_code,vehicle_type_id,status) VALUES(440,1,10,430,'A-01',400,'occupied')");
        $conn->query("INSERT INTO parking_tickets(id,company_id,branch_id,ticket_no,barcode_value,vehicle_type_id,vehicle_number,entry_gate_id,exit_gate_id,zone_id,slot_id,rate_plan_id,entry_operator_id,exit_operator_id,entry_at,exit_at,calculated_fee,paid_amount,payment_status,ticket_status) VALUES(450,1,10,'PARK-TEST','BARCODE-TEST',400,'DHAKA-123',420,420,430,440,410,1,1,NOW(),NOW(),30,30,'paid','exited')");
        $conn->query("INSERT INTO parking_payments(id,company_id,branch_id,ticket_id,wallet_id,received_by,payment_method,amount,payment_reference,payment_note) VALUES(460,1,10,450,10,1,'cash',30,'PARK-TEST','Parking payment')");
        $conn->query("INSERT INTO parking_gate_logs(id,company_id,branch_id,gate_id,ticket_id,operator_id,event_type,event_message) VALUES(470,1,10,420,450,1,'exit_confirmed','Vehicle exited')");
        $conn->query("INSERT INTO parking_shifts(id,company_id,branch_id,gate_id,operator_id,opening_cash,expected_cash,status,note) VALUES(480,1,10,420,1,0,30,'open','Test shift')");
        $conn->query("INSERT INTO transactions(user_id,branch_id,wallet_id,txn_no,transaction_type,reference_id,amount,note,txn_date) VALUES(1,10,10,'PARK-TX','money_in',460,30,'Parking payment: PARK-TEST',CURDATE())");
    }
    [$ok,$error,$backup] = company_backup_collect($conn,1);
    check_backup_test($ok,$error);
    if($housing) check_backup_test(count($backup['data']['booking_invoice_charges'] ?? [])===1,'Housing booking charges missing from export');
    check_backup_test(count($backup['data']['stock_distribution_items'])===1,'Missing distribution details');
    file_put_contents($backup_file,json_encode($backup));
    $delete_source=file_get_contents(__DIR__.'/../tools/delete_data.php');
    $delete_start=strpos($delete_source,'function delete_data_valid_table_name');
    $delete_end=strpos($delete_source,"if(\$_SERVER['REQUEST_METHOD']=='POST')");
    eval(substr($delete_source,$delete_start,$delete_end-$delete_start));
    [$deleted,$delete_error]=delete_company_business_data($conn,1);
    check_backup_test($deleted,$delete_error);
    check_backup_test((int)$conn->query('SELECT COUNT(*) FROM invoices')->fetch_row()[0]===0,'Delete did not clear invoices');
    // Simulate the automatic Cash wallet/head-office bootstrap between delete and import.
    $conn->query("INSERT INTO branches(user_id,branch_name,is_head_office,status) VALUES(1,'Head Office',1,'active')");
    $bootstrap_branch=$conn->insert_id;
    $conn->query("INSERT INTO wallets(user_id,branch_id,wallet_name,balance) VALUES(1,$bootstrap_branch,'Cash',0)");
    // Execute the real POST controller with only authentication/HTML includes
    // removed; the database and verified synthetic account are supplied here.
    $source = file_get_contents(__DIR__.'/../tools/import.php');
    $source = preg_replace('/^require_once .*;\s*$/m','',$source);
    $source = substr($source,5);
    $_SESSION=['user_id'=>1,'selected_branch_id'=>10];
    $_SERVER['REQUEST_METHOD']='POST';
    $_POST=['password'=>'backup-test'];
    $_FILES=['backup_file'=>['error'=>0,'tmp_name'=>$backup_file]];
    ob_start(); eval($source); ob_end_clean();
    check_backup_test($message_type==='success','Import failed: '.$message);
    foreach($backup['data'] as $table=>$rows){
        if(in_array($table,['users_manager_agents','pricing_plan_requests','support_tickets'],true)) continue;
        $count=(int)$conn->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0];
        check_backup_test($count===count($rows),"Row count mismatch: $table");
    }
    $head=(int)$conn->query('SELECT id FROM branches WHERE is_head_office=1')->fetch_row()[0];
    check_backup_test($head!==10,'Test must force ID changes');
    check_backup_test(!isset($_SESSION['selected_branch_id']),'Stale session branch');
    $checks = [
        'dashboard/sales total'=>["SELECT SUM(total_amount) FROM invoices WHERE branch_id=$head",$restaurant_multi ? 0 : 200],
        'expense report'=>["SELECT SUM(amount) FROM expenses WHERE branch_id=$head",25],
        'profit inputs'=>["SELECT SUM(i.total_price-i.cost_amount) FROM invoice_items i JOIN invoices p ON p.id=i.invoice_id WHERE p.branch_id=$head",$restaurant_multi ? 0 : 120],
        'wallet balances'=>["SELECT SUM(balance) FROM wallets WHERE branch_id=$head",($service_multi || $restaurant_multi) ? 500 : 750],
        'wallet activity'=>["SELECT COUNT(*) FROM transactions t JOIN wallets w ON w.id=t.wallet_id WHERE t.branch_id=$head",$service ? ($service_multi ? 5 : 6) : ($restaurant_multi ? 3 : (($housing || $parking) ? 5 : 4))],
        'product category'=>['SELECT COUNT(*) FROM products p JOIN product_categories c ON c.id=p.category_id',1],
        'purchase wallet'=>['SELECT COUNT(*) FROM purchases p JOIN wallets w ON w.id=p.payment_wallet_id',1],
        'invoice wallet'=>['SELECT COUNT(*) FROM invoices p JOIN wallets w ON w.id=p.receive_wallet_id',1],
        'transfer wallets'=>['SELECT COUNT(*) FROM transfers t JOIN wallets a ON a.id=t.from_wallet_id JOIN wallets b ON b.id=t.to_wallet_id',1],
        'forward expense reference'=>["SELECT COUNT(*) FROM transactions t JOIN expenses e ON e.id=t.reference_id WHERE t.transaction_type='expense'",1],
        'distribution details'=>['SELECT COUNT(*) FROM stock_distribution_items i JOIN stock_distributions d ON d.id=i.distribution_id JOIN stock_batches b ON b.id=i.source_batch_id',1],
    ];
    foreach($checks as $label=>[$sql,$expected]){
        check_backup_test((float)$conn->query($sql)->fetch_row()[0]===$expected*1.0,$label);
        echo "PASS: $label\n";
    }
    if($housing || $service){
        $housing_checks = [
            'booking income'=>["SELECT SUM(i.amount) FROM booking_invoices i JOIN projects p ON p.id=i.project_id JOIN packages k ON k.id=i.package_id JOIN wallets w ON w.id=i.wallet_id JOIN customers c ON c.id=i.customer_id WHERE i.branch_id=$head AND i.status='confirmed' AND i.invoice_type<>'refund'",1000],
            'booking charges'=>['SELECT SUM(c.charge_amount) FROM booking_invoice_charges c JOIN booking_invoices i ON i.id=c.booking_invoice_id JOIN invoice_charge_types t ON t.id=c.charge_type_id',50],
            'booking transaction'=>["SELECT COUNT(*) FROM transactions t JOIN booking_invoices i ON i.id=t.reference_id WHERE t.transaction_type='invoice_income'",1],
            'land ledger'=>['SELECT SUM(l.land_size) FROM land_entries l JOIN projects p ON p.id=l.project_id',10],
            'plot ledger'=>['SELECT SUM(l.sold_plots) FROM land_plots l JOIN projects p ON p.id=l.project_id',1],
        ];
        foreach($housing_checks as $label=>[$sql,$expected]){
            check_backup_test((float)$conn->query($sql)->fetch_row()[0]===$expected*1.0,$label);
            echo 'PASS: '.($service ? 'Service Business' : 'Housing')." $label\n";
        }
    }
    if($service){
        $other=(int)$conn->query('SELECT id FROM branches WHERE is_head_office=0')->fetch_row()[0];
        $refund_branch=$service_multi ? $other : $head;
        $service_checks=[
            'company mode'=>["SELECT multi_branch_enabled FROM users WHERE id=1",$service_multi ? 1 : 0],
            'invoice type income'=>["SELECT SUM(i.amount) FROM booking_invoices i JOIN booking_invoice_types t ON t.user_id=i.user_id AND t.type_key=i.invoice_type WHERE t.behavior='income' AND i.branch_id=$head",1000],
            'branch refund total'=>["SELECT SUM(i.amount) FROM booking_invoices i JOIN booking_invoice_types t ON t.user_id=i.user_id AND t.type_key=i.invoice_type WHERE t.behavior='expense' AND i.branch_id=$refund_branch",100],
            'refund wallet reference'=>["SELECT COUNT(*) FROM transactions t JOIN booking_invoices i ON i.id=t.reference_id JOIN wallets w ON w.id=t.wallet_id WHERE t.transaction_type='invoice_expense' AND t.branch_id=i.branch_id AND w.branch_id=i.branch_id",1],
            'all branch net income'=>["SELECT SUM(IF(t.behavior='income',i.amount,-i.amount)) FROM booking_invoices i JOIN booking_invoice_types t ON t.user_id=i.user_id AND t.type_key=i.invoice_type",900],
        ];
        foreach($service_checks as $label=>[$sql,$expected]){
            check_backup_test((float)$conn->query($sql)->fetch_row()[0]===$expected*1.0,$label);
            echo "PASS: Service Business $label\n";
        }
    }
    if($restaurant){
        $restaurant_invoice_branch=$restaurant_multi ? (int)$conn->query('SELECT id FROM branches WHERE is_head_office=0')->fetch_row()[0] : $head;
        $restaurant_checks=[
            'company mode'=>["SELECT multi_branch_enabled FROM users WHERE id=1",$restaurant_multi ? 1 : 0],
            'table catalogue'=>['SELECT COUNT(*) FROM restaurant_tables WHERE table_name=\'Table A\' AND capacity=4',1],
            'invoice table reference'=>["SELECT COUNT(*) FROM invoices i JOIN restaurant_tables t ON t.id=i.restaurant_table_id WHERE i.branch_id=$restaurant_invoice_branch AND t.user_id=1",1],
            'restaurant branch invoice total'=>["SELECT SUM(total_amount) FROM invoices WHERE branch_id=$restaurant_invoice_branch",200],
        ];
        foreach($restaurant_checks as $label=>[$sql,$expected]){
            check_backup_test((float)$conn->query($sql)->fetch_row()[0]===$expected*1.0,$label);
            echo "PASS: Restaurant & Cafe $label\n";
        }
    }
    if($parking){
        $parking_checks=[
            'settings'=>['SELECT slot_management_enabled FROM parking_settings WHERE company_id=1',1],
            'ticket references'=>['SELECT COUNT(*) FROM parking_tickets t JOIN parking_vehicle_types v ON v.id=t.vehicle_type_id JOIN parking_gates a ON a.id=t.entry_gate_id JOIN parking_zones z ON z.id=t.zone_id JOIN parking_slots s ON s.id=t.slot_id JOIN parking_rate_plans r ON r.id=t.rate_plan_id',1],
            'payment wallet and ticket'=>['SELECT COUNT(*) FROM parking_payments p JOIN parking_tickets t ON t.id=p.ticket_id JOIN wallets w ON w.id=p.wallet_id',1],
            'gate activity and shift'=>['SELECT COUNT(*) FROM parking_gate_logs l JOIN parking_gates g ON g.id=l.gate_id JOIN parking_tickets t ON t.id=l.ticket_id',1],
            'gate assignment'=>['SELECT COUNT(*) FROM parking_gate_staff_assignments a JOIN parking_gates g ON g.id=a.gate_id WHERE a.user_id=1',1],
            'wallet transaction reference'=>["SELECT COUNT(*) FROM transactions x JOIN parking_payments p ON p.id=x.reference_id WHERE x.txn_no='PARK-TX'",1],
        ];
        foreach($parking_checks as $label=>[$sql,$expected]){
            check_backup_test((float)$conn->query($sql)->fetch_row()[0]===$expected*1.0,$label);
            echo "PASS: Car Parking $label\n";
        }
    }
    if($fashion){
        $fashion_checks = [
            'variant catalogue'=>["SELECT COUNT(*) FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.variant_name IN ('XL','XXL')",2],
            'invoice FIFO cost'=>['SELECT SUM(a.total_cost) FROM invoice_item_allocations a JOIN invoice_items i ON i.id=a.invoice_item_id JOIN stock_batches b ON b.id=a.stock_batch_id',80],
            'distribution batch reference'=>["SELECT COUNT(*) FROM stock_batches b JOIN stock_distributions d ON d.id=b.source_id WHERE b.source_type='distribution'",1],
            'sales return batch reference'=>["SELECT COUNT(*) FROM stock_batches b JOIN invoice_items i ON i.id=b.source_id WHERE b.source_type='sales_return'",1],
            'damage return detail'=>['SELECT COUNT(*) FROM stock_damage_return_items i JOIN stock_damage_returns d ON d.id=i.damage_return_id JOIN stock_batches b ON b.id=i.source_batch_id',1],
            'XL remaining stock'=>["SELECT SUM(remaining_quantity) FROM stock_batches WHERE variant_name='XL'",9],
            'XXL remaining stock'=>["SELECT SUM(remaining_quantity) FROM stock_batches WHERE variant_name='XXL'",5],
        ];
        foreach($fashion_checks as $label=>[$sql,$expected]){
            check_backup_test((float)$conn->query($sql)->fetch_row()[0]===$expected*1.0,$label);
            echo "PASS: Fashion Retail $label\n";
        }
    }
    echo "PASS: all exported table counts preserved; branch IDs changed and remapped\n";
    $controller=substr($source,strpos($source,"if(\$_SERVER['REQUEST_METHOD']=='POST')"));
    $bad=$backup;
    $bad['data']['invoices'][0]['not_a_real_column']=1;
    file_put_contents($backup_file,json_encode($bad));
    ob_start(); eval($controller); ob_end_clean();
    check_backup_test($message_type==='danger','Malformed row must fail');
    check_backup_test((float)$conn->query('SELECT SUM(total_amount) FROM invoices')->fetch_row()[0]===200.0,'Failure must roll back deleted data');
    unset($bad['data']['wallets']);
    file_put_contents($backup_file,json_encode($bad));
    ob_start(); eval($controller); ob_end_clean();
    check_backup_test($message_type==='danger' && (str_contains($message,'Incomplete backup') || str_contains($message,'row count mismatch')),'Incomplete backup accepted');
    check_backup_test((float)$conn->query('SELECT SUM(balance) FROM wallets')->fetch_row()[0]===750.0,'Incomplete backup deleted wallets');
    echo "PASS: malformed and incomplete imports preserve existing data\n";
}finally{
    $conn->select_db('you2biz');
    if(preg_match('/^you2biz_staging_[a-f0-9]{16}$/',$test_db)) $conn->query("DROP DATABASE `$test_db`");
    unlink($backup_file);
}
