<?php
// CLI-only disposable database. No production rows are read or changed.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$_SESSION = ['user_id'=>1,'login_user_id'=>1,'user_role'=>'admin','fifo_enabled'=>1];
function is_manager_user(){ return $_SESSION['user_role']==='manager'; }
function is_admin_user(){ return $_SESSION['user_role']==='admin'; }
function is_super_admin_user(){ return false; }
function current_manager_staff_id($conn=null){ return (int)($_SESSION['staff_id']??0); }
function manager_has_permission($key){ return in_array($key,$_SESSION['access_permissions']??[],true); }
require_once __DIR__ . '/../includes/fifo_inventory_helper.php';
require_once __DIR__ . '/../includes/pending_invoice_stock_helper.php';
require_once __DIR__ . '/../includes/transaction_helper.php';
require_once __DIR__ . '/../includes/wallet_helper.php';
require_once __DIR__ . '/../includes/customer_due_allocation_helper.php';
require_once __DIR__ . '/../includes/expense_helper.php';

function expect_stock($condition,$label){ if(!$condition) throw new RuntimeException($label); echo "PASS {$label}\n"; }
function rejected_stock($fn,$label){ try { $fn(); } catch(RuntimeException $e) { echo "PASS {$label}\n"; return; } throw new RuntimeException($label); }
$conn = new mysqli('localhost','root','');
$db = 'you2biz_stock_test_' . bin2hex(random_bytes(6));
$created = false;
try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4"); $created=true; $conn->select_db($db);
    foreach(['users','staff','branches','wallets','transactions','expenses','categories','customers','money_ins','transfers','customer_payments','staff_ledger_entries'] as $table) {
        $conn->query("CREATE TABLE `{$table}` LIKE you2biz.`{$table}`");
    }
    ensure_fifo_inventory_tables($conn);
    ensure_fifo_inventory_tables($conn);
    expect_stock($conn->query("SHOW TABLES LIKE 'stock_distributions'")->num_rows===1,'fresh schema auto-created and repeat-safe');
    $conn->query("INSERT INTO users(id,name,company_type,fifo_enabled,email,phone,password,role,multi_branch_enabled) VALUES(1,'Test HQ','Stock Product',1,'hq@example.test','100','test','admin',1),(2,'Other Company','Stock Product',1,'other@example.test','200','test','admin',1)");
    $conn->query("INSERT INTO branches(id,user_id,branch_name,is_head_office) VALUES(1,1,'Head Office',1),(2,1,'Branch A',0),(3,1,'Branch B',0),(4,2,'Foreign Branch',0)");
    $conn->query("INSERT INTO staff(id,user_id,name,branch_id) VALUES(1,1,'Staff A',2),(2,1,'HQ Staff',1)");
    $conn->query("INSERT INTO product_categories(id,user_id,category_name,category_type) VALUES(1,1,'Stock Product','stock_product')");
    $conn->query("INSERT INTO products(id,user_id,category_id,product_name,current_stock,purchase_price) VALUES(1,1,1,'Test Product',20,150)");
    ensure_branch_accounting_columns($conn,1);
    fifo_inventory_create_batch($conn,1,1,10,100,'purchase',1,'PUR-A','2026-01-01',1);
    fifo_inventory_create_batch($conn,1,1,10,150,'purchase',2,'PUR-B','2026-02-01',1);
    fifo_inventory_migrate_company($conn,1); fifo_inventory_migrate_company($conn,1);
    expect_stock((float)$conn->query('SELECT SUM(remaining_quantity) FROM stock_batches')->fetch_row()[0]===20.0,'migration does not duplicate existing batches');
    $request=str_repeat('a',48);
    $distribution=fifo_inventory_distribute($conn,1,2,1,12,$request);
    expect_stock(fifo_inventory_get_available_stock($conn,1,1,1)===8.0 && fifo_inventory_get_available_stock($conn,1,1,2)===12.0,'distribution separates warehouse and branch stock');
    expect_stock((float)$conn->query("SELECT total_cost FROM stock_distributions WHERE id={$distribution}")->fetch_row()[0]===1300.0,'distribution preserves FIFO batch cost');
    expect_stock(fifo_inventory_distribute($conn,1,2,1,12,$request)===$distribution && fifo_inventory_get_available_stock($conn,1,1,2)===12.0,'double submit is idempotent');
    expect_stock((float)$conn->query('SELECT SUM(balance) FROM wallets')->fetch_row()[0]===0.0,'distribution does not move wallet money');
    rejected_stock(fn()=>fifo_inventory_distribute($conn,1,2,1,9,str_repeat('b',48)),'warehouse oversell rejected');
    rejected_stock(fn()=>fifo_inventory_distribute($conn,1,4,1,1,str_repeat('c',48)),'foreign company destination rejected');
    $conn->query("INSERT INTO invoices(id,user_id,branch_id,invoice_no,invoice_date,accounting_status) VALUES(1,1,2,'STOCK-TEST','2026-03-01','posted'),(2,1,1,'PENDING-TEST','2026-03-01','pending')");
    $conn->query('INSERT INTO invoice_items(id,invoice_id,product_id,quantity,unit_price,total_price) VALUES(1,1,1,11,200,2200),(2,2,1,7,200,1400)');
    rejected_stock(fn()=>fifo_inventory_distribute($conn,1,2,1,2,str_repeat('d',48)),'pending warehouse reservations block distribution');
    $conn->begin_transaction(); $sale=fifo_inventory_allocate_sale($conn,1,1,1,11); $conn->commit();
    expect_stock($sale['success'] && $sale['cost_amount']===1150.0,'branch sale consumes oldest distributed batches at original cost');
    expect_stock(fifo_inventory_get_available_stock($conn,1,1,1)===8.0 && fifo_inventory_get_available_stock($conn,1,1,2)===1.0,'branch sale cannot consume warehouse stock');
    $conn->begin_transaction(); $bad=fifo_inventory_allocate_sale($conn,1,1,1,2); $conn->rollback();
    expect_stock(!$bad['success'] && fifo_inventory_get_available_stock($conn,1,1,2)===1.0,'branch oversell leaves stock unchanged');
    $conn->begin_transaction(); fifo_inventory_restore_invoice_item($conn,1); $conn->commit();
    expect_stock(fifo_inventory_get_available_stock($conn,1,1,2)===12.0,'invoice reversal restores original branch batches');
    $_SESSION['user_role']='manager'; $_SESSION['staff_id']=1; $_SESSION['access_permissions']=['warehouse','stock_sales'];
    expect_stock(selected_branch_id($conn,true)===2,'branch staff context is fixed to assigned branch');
    expect_stock(product_stock_snapshot_for_invoice($conn,1,1)['current_stock']===12.0,'sales picker uses branch quantity instead of company quantity');
    rejected_stock(fn()=>fifo_inventory_distribute($conn,1,3,1,1,str_repeat('e',48)),'branch staff cannot distribute central stock');
    $hq_wallet=(int)$conn->query('SELECT id FROM wallets WHERE user_id=1 AND branch_id=1 LIMIT 1')->fetch_row()[0];
    rejected_stock(fn()=>stock_require_wallet($conn,$hq_wallet,1,2),'cross-branch wallet rejected');
    $_SESSION['user_role']='admin'; unset($_SESSION['selected_branch_id']);
    expect_stock(!fifo_inventory_purchase_is_editable($conn,1),'distributed purchase cannot be edited or deleted');
    $_SERVER['REQUEST_URI']='/You2-Biz/warehouse/index.php'; $_SERVER['REQUEST_METHOD']='GET';
    stock_module_bootstrap($conn);
    ensure_expense_support_tables($conn,1);
    expect_stock($_SESSION['fifo_enabled']===1, 'module bootstrap provisions company flag and dependent schemas');
    expect_stock(stock_line_totals([1,2],[2,3],[100.25,10.5]) === [200.5,31.5], 'server computes stock line totals');
    rejected_stock(fn()=>stock_line_totals([1],[1.5],[100]), 'fractional stock quantity rejected');
    rejected_stock(fn()=>stock_line_totals([1],[-1],[100],false), 'negative purchase quantity rejected');
    rejected_stock(fn()=>stock_line_totals([1],[1],[INF]), 'non-finite stock price rejected');
    $conn->query("INSERT INTO customers(id,user_id,customer_name,phone,address) VALUES(1,1,'Test Customer','123','Test Address')");
    $conn->query("UPDATE invoices SET customer_id=1,total_amount=2200,paid_amount=200,due_amount=2000 WHERE id=1");
    $conn->query("INSERT INTO invoices(id,user_id,branch_id,customer_id,invoice_no,invoice_date,total_amount,paid_amount,due_amount) VALUES(3,1,3,1,'OTHER-BRANCH','2026-03-01',3000,100,2900)");
    $conn->query("INSERT INTO customer_payments(user_id,branch_id,customer_id,invoice_id,amount,payment_date,note) VALUES(1,2,1,1,200,'2026-03-01','test'),(1,3,1,3,100,'2026-03-01','test')");
    $_SESSION['selected_branch_id']=2;
    expect_stock(customer_signed_balance_total($conn,1,1)===2000.0, 'customer balance excludes other branch sales and payments');
    expect_stock(customer_due_report_total($conn,1)===2000.0, 'customer due report is branch scoped');
    expect_stock(latest_existing_customer_invoice_id($conn,1,1)===1, 'latest invoice edit rule is branch scoped');
    $conn->begin_transaction();
    expect_stock(allocate_customer_previous_due_payment($conn,1,1,99,'NEW-SOURCE',500)===500.0, 'previous due payment allocates in current branch');
    expect_stock((float)$conn->query('SELECT due_amount FROM invoices WHERE id=3')->fetch_row()[0]===2900.0, 'due allocation leaves other branch invoice unchanged');
    expect_stock((int)$conn->query("SELECT branch_id FROM customer_payments WHERE note LIKE 'Invoice Payment - NEW-SOURCE%' LIMIT 1")->fetch_row()[0]===2, 'allocated payment saves branch ownership');
    rollback_customer_previous_due_payment_allocation($conn,1,1,'NEW-SOURCE');
    expect_stock(customer_signed_balance_total($conn,1,1)===2000.0, 'reversing due allocation restores branch ledger');
    $conn->rollback();
    $branch_wallet=(int)$conn->query('SELECT id FROM wallets WHERE user_id=1 AND branch_id=2 LIMIT 1')->fetch_row()[0];
    $conn->begin_transaction();
    credit_wallet($conn,$branch_wallet,1,250);
    record_wallet_transaction($conn,'STOCK-RECEIPT',1,$branch_wallet,'receive_payment',1,250,'Stock due collection','2026-03-01');
    expect_stock((float)$conn->query("SELECT balance FROM wallets WHERE id={$branch_wallet}")->fetch_row()[0]===250.0, 'stock payment credits branch wallet');
    expect_stock((int)$conn->query("SELECT branch_id FROM transactions WHERE txn_no='STOCK-RECEIPT'")->fetch_row()[0]===2, 'wallet transaction and invoice branch agree');
    $conn->rollback();
    expect_stock((float)$conn->query("SELECT balance FROM wallets WHERE id={$branch_wallet}")->fetch_row()[0]===0.0, 'failed transaction rollback restores wallet');
    rejected_stock(fn()=>credit_wallet($conn,$hq_wallet,1,10), 'wallet helper rejects cross-branch credits');
    $_SESSION['selected_branch_id']=1;
    $conn->query("UPDATE wallets SET balance=1000 WHERE id={$hq_wallet}");
    $conn->begin_transaction();
    debit_wallet($conn,$hq_wallet,1,100);
    $expense=record_supplier_payment_expense($conn,1,$hq_wallet,100,'2026-03-01','Stock In','purchase_payment',9);
    expect_stock((float)$conn->query("SELECT balance FROM wallets WHERE id={$hq_wallet}")->fetch_row()[0]===900.0, 'supplier expense mirror does not double-debit wallet');
    expect_stock((int)$conn->query("SELECT branch_id FROM expenses WHERE id={$expense}")->fetch_row()[0]===1, 'supplier expense belongs to warehouse wallet branch');
    $conn->rollback();
    $conn->query('UPDATE users SET multi_branch_enabled=0 WHERE id=1');
    rejected_stock(fn()=>fifo_inventory_distribute($conn,1,2,1,1,str_repeat('f',48)),'distribution disabled when Multi Branch is inactive');
    echo "ALL STOCK INTEGRATION CHECKS PASSED\n";
} finally {
    if ($created && preg_match('/^you2biz_stock_test_[a-f0-9]{12}$/',$db)) $conn->query("DROP DATABASE `{$db}`");
}
