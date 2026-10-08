<?php
if (PHP_SAPI!=='cli') exit;
$database=$argv[1]??'';
if (!preg_match('/\Ayou2biz_staging_[a-f0-9]{16}\z/',$database)) exit("Use only a synthetic staging database.\n");
require_once __DIR__.'/../includes/sales_refund_helper.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$conn=new mysqli('localhost','root','',$database);
sales_refund_schema($conn);
function verify_refund($ok,$message) { if (!$ok) throw new RuntimeException($message); }
function reject_refund($fn) { try { $fn(); } catch (RuntimeException $e) { return; } throw new RuntimeException('Expected rejection'); }
verify_refund((int)$conn->query('SELECT COUNT(*) FROM sales_refunds')->fetch_row()[0]===0,'Use staging after the checkout/payment test, before refunds.');
verify_refund($conn->query('SELECT name FROM users WHERE id=1')->fetch_row()[0]==='STAGING Test Shop','Not the synthetic company');
$original=$conn->query('SELECT * FROM invoices WHERE id=1')->fetch_assoc();
verify_refund((float)$original['paid_amount']===200.0,'Run the staging payment test first');
$item=(int)$conn->query('SELECT id FROM invoice_items WHERE invoice_id=1 LIMIT 1')->fetch_row()[0];
$line=[$item=>['quantity'=>1,'restock'=>1]];
$key=str_repeat('a',64);
reject_refund(fn()=>sales_issue_refund($conn,2,1,1,1,1,'100','Wrong company',$line,$key));
reject_refund(fn()=>sales_issue_refund($conn,1,2,1,1,1,'100','Wrong branch',$line,$key));
reject_refund(fn()=>sales_issue_refund($conn,1,1,1,1,999,'100','Wrong wallet',$line,$key));
reject_refund(fn()=>sales_issue_refund($conn,1,1,1,1,1,'201','Too much',$line,$key));
reject_refund(fn()=>sales_issue_refund($conn,1,1,1,1,1,'NaN','Invalid',$line,$key));
$id=sales_issue_refund($conn,1,1,1,1,1,'100','Partial refund, restock one',$line,$key);
verify_refund(sales_issue_refund($conn,1,1,1,1,1,'100','Retry',$line,$key)===$id,'Duplicate request was not idempotent');
verify_refund((float)$conn->query('SELECT balance FROM wallets WHERE id=1')->fetch_row()[0]===100.0,'Wrong wallet balance');
verify_refund((float)$conn->query('SELECT SUM(remaining_quantity) FROM stock_batches WHERE product_id=1')->fetch_row()[0]===9.0,'Wrong restock quantity');
reject_refund(fn()=>sales_issue_refund($conn,1,1,1,1,1,'150','Over-refund',[],str_repeat('b',64)));
reject_refund(fn()=>sales_issue_refund($conn,1,1,1,1,1,'100','Over-return',[$item=>['quantity'=>2,'restock'=>1]],str_repeat('c',64)));
reject_refund(fn()=>sales_refund_protect_invoice($conn,1,1));
sales_issue_refund($conn,1,1,1,1,1,'100','Remainder, damaged item not restocked',[$item=>['quantity'=>1,'restock'=>0]],str_repeat('d',64));
verify_refund((float)$conn->query('SELECT balance FROM wallets WHERE id=1')->fetch_row()[0]===0.0,'Full refund did not empty test wallet');
verify_refund((float)$conn->query('SELECT SUM(remaining_quantity) FROM stock_batches WHERE product_id=1')->fetch_row()[0]===9.0,'No-restock refund changed inventory');
verify_refund((float)$conn->query('SELECT SUM(total_amount) FROM invoices')->fetch_row()[0]===0.0,'Credit notes did not reverse sales total');
verify_refund((float)$conn->query('SELECT SUM(amount) FROM customer_payments')->fetch_row()[0]===0.0,'Customer receipt ledger mismatch');
verify_refund((float)$conn->query('SELECT SUM(total_price) FROM invoice_items')->fetch_row()[0]===0.0,'Item sales report does not reconcile');
verify_refund((float)$conn->query('SELECT SUM(cost_amount) FROM invoice_items')->fetch_row()[0]===40.0,'Cost reversal must only include restocked items');
verify_refund($conn->query('SELECT * FROM invoices WHERE id=1')->fetch_assoc()===$original,'Original invoice was modified');
verify_refund((int)$conn->query('SELECT COUNT(*) FROM sales_refunds')->fetch_row()[0]===2,'Rejections left refund records');
echo "PASS: partial/full refund, duplicate request, tenant/branch/wallet limits, payment cap, return quantity cap, restock choice, credit notes, signed customer ledger and original preservation.\n";
