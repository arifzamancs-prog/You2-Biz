param(
    [Parameter(Mandatory=$true)][ValidatePattern('^you2biz_staging_[a-f0-9]{16}$')][string]$Database,
    [Parameter(Mandatory=$true)][string]$TestPassword,
    [int]$Port=8098
)
$ErrorActionPreference='Stop'
$base="http://127.0.0.1:$Port"
function Check($ok,$message){if(!$ok){throw $message}}
function Query([string]$sql){ & C:/xampp/mysql/bin/mysql.exe -u root -N -B $Database -e $sql }
function Token($html){
    $m=[regex]::Match($html,'const token = "([a-f0-9]+)";')
    Check $m.Success 'Missing CSRF token'
    $m.Groups[1].Value
}
$login=Invoke-WebRequest "$base/login.php" -SessionVariable admin -UseBasicParsing
$login=Invoke-WebRequest "$base/login.php" -WebSession $admin -Method Post -Body @{login='admin@example.test';password=$TestPassword} -UseBasicParsing
foreach($path in @('products/index.php','products/create.php','suppliers/index.php','purchases/create.php','sales/create_invoice.php','table_management/index.php')){
    $page=Invoke-WebRequest "$base/$path" -WebSession $admin -UseBasicParsing
    Check (!$page.Content.Contains('Fatal error')) "$path failed"
    Check ($page.Content.Contains('Logout')) "$path not authenticated"
}
$create=Invoke-WebRequest "$base/products/create.php" -WebSession $admin -UseBasicParsing
$token=Token $create.Content
$category=Query "SELECT id FROM product_categories WHERE user_id=1 AND category_type='non_stock' LIMIT 1"
$product=Invoke-WebRequest "$base/products/create.php" -WebSession $admin -Method Post -Body @{stock_csrf=$token;category_id=$category;product_name='Prepared Coffee';sku='CAFE-HTTP';purchase_price=20;sale_price=50;current_stock=999;minimum_stock=999;status='active'} -UseBasicParsing
Check ((Query "SELECT current_stock FROM products WHERE sku='CAFE-HTTP'") -eq '0') 'Non-stock opening quantity was not ignored'
Check ((Query "SELECT COUNT(*) FROM stock_batches sb JOIN products p ON p.id=sb.product_id WHERE p.sku='CAFE-HTTP'") -eq '0') 'Non-stock batch created'
$create=Invoke-WebRequest "$base/sales/create_invoice.php" -WebSession $admin -UseBasicParsing
$token=Token $create.Content
Check ($create.Content.Contains('name="restaurant_table_id"')) 'Cafe table selector missing'
$body=@{stock_csrf=$token;action='save';ajax='1';customer_id=0;grand_total=500;paid_amount=500;due_amount=0;payment_status='paid';receive_wallet_id=1;notes='Cafe integration test';'product_id[0]'=1;'product_id[1]'=2;'qty[0]'=2;'qty[1]'=2;'price[0]'=100;'price[1]'=150}
$saved=Invoke-WebRequest "$base/sales/save_invoice.php" -WebSession $admin -Method Post -Body $body -UseBasicParsing
Check ((Query 'SELECT COUNT(*) FROM invoices') -eq '1') "Mixed invoice not saved: $($saved.Content)"
$id=Query 'SELECT id FROM invoices LIMIT 1'
Check ((Query "SELECT accounting_status FROM invoices WHERE id=$id") -eq 'pending') 'Not pending'
Check ([double](Query 'SELECT SUM(remaining_quantity) FROM stock_batches WHERE product_id=1') -eq 10) 'Pending invoice consumed stock'
$posted=Invoke-WebRequest "$base/sales/post_invoice.php?id=$id" -WebSession $admin -UseBasicParsing
Check ((Query "SELECT accounting_status FROM invoices WHERE id=$id") -eq 'posted') "Posting failed: $($posted.Content)"
Check ([double](Query 'SELECT SUM(remaining_quantity) FROM stock_batches WHERE product_id=1') -eq 8) 'Packaged goods did not deduct exactly 2'
Check ([double](Query 'SELECT current_stock FROM products WHERE id=2') -eq 0) 'Food stock was deducted'
Check ((Query 'SELECT COUNT(*) FROM stock_transactions WHERE product_id=2') -eq '0') 'Food created stock transaction'
Check ([double](Query 'SELECT balance FROM wallets WHERE id=1') -eq 500) 'Shared wallet did not receive payment'
$again=Invoke-WebRequest "$base/sales/post_invoice.php?id=$id" -WebSession $admin -UseBasicParsing
Check ([double](Query 'SELECT SUM(remaining_quantity) FROM stock_batches WHERE product_id=1') -eq 8) 'Repeated posting deducted twice'
Check ([double](Query 'SELECT balance FROM wallets WHERE id=1') -eq 500) 'Repeated posting credited wallet twice'
Write-Output 'PASS: authenticated Cafe screens, non-stock creation, mixed pending invoice, posting, stock deduction, wallet payment and duplicate-post guard. Synthetic data only.'
