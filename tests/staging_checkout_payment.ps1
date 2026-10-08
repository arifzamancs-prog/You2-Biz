param(
    [Parameter(Mandatory=$true)][ValidatePattern('^you2biz_staging_[a-f0-9]{16}$')][string]$Database,
    [Parameter(Mandatory=$true)][string]$TestPassword,
    [ValidatePattern('^http://127\.0\.0\.1:\d+$')][string]$BaseUrl='http://127.0.0.1:8097',
    [switch]$Refund
)
$ErrorActionPreference='Stop'
$base=$BaseUrl
function Query([string]$Sql) {
    $result = & C:\xampp\mysql\bin\mysql.exe -u root --batch --skip-column-names $Database -e $Sql
    if ($LASTEXITCODE -ne 0) { throw 'Staging verification query failed' }
    return ($result -join "`n").Trim()
}
function Check($Condition,[string]$Message) { if (!$Condition) { throw $Message } }
function Hidden([string]$Html,[string]$Name) {
    $match=[regex]::Match($Html, 'name="'+$Name+'"\s+value="([^"]+)"')
    if (!$match.Success) { throw "Missing form field: $Name" }
    return $match.Groups[1].Value
}
Check ((Query 'SELECT COUNT(*) FROM invoices') -eq '0') 'Use a fresh synthetic staging database.'
$shop=Invoke-WebRequest "$base/shop/index.php?shop=staging-shop" -SessionVariable shopper
Check ($shop.Content.Contains('STAGING Test Shop')) 'Staging storefront not identified.'
$csrf=Hidden $shop.Content 'csrf'
$cart=Invoke-WebRequest "$base/shop/index.php?shop=staging-shop" -WebSession $shopper -Method Post -Body @{csrf=$csrf;action='add';product_id=1;quantity=2}
$key=Hidden $cart.Content 'order_key'
$body=@{csrf=$csrf;action='checkout';order_key=$key;name='HTTP Test Customer';phone='01700000002';email='';address='Synthetic staging address'}
$order=Invoke-WebRequest "$base/shop/index.php?shop=staging-shop" -WebSession $shopper -Method Post -Body $body
Check ((Query "SELECT CONCAT(accounting_status,':',paid_amount,':',due_amount) FROM invoices") -eq 'pending:0.00:200.00') 'Checkout invoice mismatch.'
# Replaying the old key must not create another invoice.
$replay=Invoke-WebRequest "$base/shop/index.php?shop=staging-shop" -WebSession $shopper -Method Post -Body $body
Check ((Query 'SELECT COUNT(*) FROM invoices') -eq '1') 'Duplicate checkout created another invoice.'
$login=Invoke-WebRequest "$base/login.php" -SessionVariable admin
$logged=Invoke-WebRequest "$base/login.php" -WebSession $admin -Method Post -Body @{login='admin@example.test';password=$TestPassword}
$id=Query 'SELECT id FROM invoices LIMIT 1'
$view=Invoke-WebRequest "$base/sales/view_invoice.php?id=$id" -WebSession $admin
Check ($view.Content.Contains('Pay &amp; Print') -or $view.Content.Contains('Pay & Print')) 'Admin invoice screen not accessible.'
$confirmed=Invoke-WebRequest "$base/sales/post_invoice.php?id=$id" -WebSession $admin
Check ((Query "SELECT CONCAT(accounting_status,':',due_amount) FROM invoices WHERE id=$id") -eq 'posted:200.00') 'Sales confirmation failed.'
$stockAfter=Query 'SELECT SUM(remaining_quantity) FROM stock_batches WHERE product_id=1'
Check ([double]$stockAfter -eq 8) 'Confirmation did not consume exactly two stock units.'
$again=Invoke-WebRequest "$base/sales/post_invoice.php?id=$id" -WebSession $admin
Check ((Query 'SELECT SUM(remaining_quantity) FROM stock_batches WHERE product_id=1') -eq $stockAfter) 'Repeat confirmation consumed stock twice.'
$paymentForm=Invoke-WebRequest "$base/sales/payment_entry.php?id=$id" -WebSession $admin
# The shared footer injects this token into POST forms in the browser.
$tokenMatch=[regex]::Match($paymentForm.Content, 'const token = "([a-f0-9]+)";')
Check ($tokenMatch.Success) 'Missing payment CSRF token.'
$payment=Invoke-WebRequest "$base/sales/payment_save.php" -WebSession $admin -Method Post -Body @{stock_csrf=$tokenMatch.Groups[1].Value;payment_mode='invoice';invoice_id=$id;amount=200;receive_wallet_id=1}
Check ((Query "SELECT CONCAT(payment_status,':',due_amount) FROM invoices WHERE id=$id") -eq 'paid:0.00') 'Payment did not settle invoice.'
Check ((Query 'SELECT balance FROM wallets WHERE id=1') -eq '200.00') 'Payment wallet mismatch.'
Check ((Query 'SELECT SUM(amount) FROM customer_payments') -eq '200.00') 'Payment ledger mismatch.'
Write-Output 'PASS: HTTP checkout, duplicate checkout, authenticated Sales screen, confirmation, repeat-confirmation stock guard, payment ledger and wallet. Synthetic database only.'
if ($Refund) {
    $refundForm=Invoke-WebRequest "$base/sales/refund.php?id=$id" -WebSession $admin
    $refundCsrf=Hidden $refundForm.Content 'stock_csrf'
    $refundKey=Hidden $refundForm.Content 'request_key'
    $itemId=Query "SELECT id FROM invoice_items WHERE invoice_id=$id LIMIT 1"
    $refundBody=@{stock_csrf=$refundCsrf;request_key=$refundKey;wallet_id=1;amount=100;reason='HTTP partial refund';"items[$itemId][quantity]"=1;"items[$itemId][restock]"=1}
    $refundResult=Invoke-WebRequest "$base/sales/refund.php?id=$id" -WebSession $admin -Method Post -Body $refundBody
    Check ($refundResult.Content.Contains('recorded successfully')) 'Refund form did not report success.'
    Check ((Query 'SELECT balance FROM wallets WHERE id=1') -eq '100.00') 'HTTP partial refund wallet mismatch.'
    $replayedRefund=Invoke-WebRequest "$base/sales/refund.php?id=$id" -WebSession $admin -Method Post -Body $refundBody
    Check ((Query 'SELECT COUNT(*) FROM sales_refunds') -eq '1') 'Refund form replay created a duplicate.'
    $refundBody.request_key=Hidden $refundResult.Content 'request_key'
    $refundBody.reason='HTTP final refund without restocking'
    $refundBody.Remove("items[$itemId][restock]")
    $finalRefund=Invoke-WebRequest "$base/sales/refund.php?id=$id" -WebSession $admin -Method Post -Body $refundBody
    Check ((Query 'SELECT balance FROM wallets WHERE id=1') -eq '0.00') 'HTTP final refund wallet mismatch.'
    Check ([double](Query 'SELECT SUM(remaining_quantity) FROM stock_batches WHERE product_id=1') -eq 9) 'Refund restock choice mismatch.'
    Check ([double](Query 'SELECT SUM(amount) FROM customer_payments') -eq 0) 'Refund customer ledger mismatch.'
    Check ([double](Query 'SELECT SUM(paid_amount) FROM invoices') -eq 0) 'Refund invoice paid total mismatch.'
    Check ([double](Query 'SELECT SUM(total_price) FROM invoice_items') -eq 0) 'Refund product report mismatch.'
    Check ((Query 'SELECT COUNT(*) FROM transactions t JOIN customer_payments p ON p.id=t.reference_id WHERE t.amount<0 AND p.amount=t.amount') -eq '2') 'Refund transaction reference mismatch.'
    $deleteAttempt=Invoke-WebRequest "$base/sales/delete_invoice.php?id=$id" -WebSession $admin
    Check ((Query "SELECT COUNT(*) FROM invoices WHERE id=$id") -eq '1') 'Refund-linked invoice was deleted.'
    Write-Output 'PASS: authenticated refund form, partial/full refund, replay guard, optional restock, signed ledger, product reports and original-invoice deletion protection.'
}
