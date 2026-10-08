<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/sales_refund_helper.php';
if (($_SESSION['user_role']??'')!=='admin') { http_response_code(403); exit('Only the company Admin can refund sales.'); }
$cid=(int)$_SESSION['user_id'];
$id=(int)($_GET['id']??0);
require_branch_record_access($conn,'invoices',$id,'invoice_list.php');
$branch=stock_invoice_branch($conn,$cid,$id);
sales_refund_schema($conn);
$invoice=sales_refund_query($conn,'SELECT * FROM invoices WHERE id=? AND user_id=?','ii',[$id,$cid])->get_result()->fetch_assoc();
if (!$invoice || $invoice['accounting_status']!=='posted' || (float)$invoice['total_amount']<=0) { http_response_code(400); exit('Refund requires a confirmed sale.'); }
if (empty($_SESSION['refund_keys'][$id])) $_SESSION['refund_keys'][$id]=bin2hex(random_bytes(32));
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        stock_verify_csrf();
        if (!hash_equals($_SESSION['refund_keys'][$id],(string)($_POST['request_key']??''))) throw new RuntimeException('Reload this refund form before submitting.');
        $refundId=sales_issue_refund($conn,$cid,$branch,$id,(int)($_SESSION['login_user_id']??$cid),(int)($_POST['wallet_id']??0),(string)($_POST['amount']??''),(string)($_POST['reason']??''),$_POST['items']??[],(string)$_POST['request_key']);
        $_SESSION['refund_keys'][$id]=bin2hex(random_bytes(32));
        $_SESSION['refund_success']='Refund #'.$refundId.' recorded successfully.';
        header('Location: refund.php?id='.$id); exit;
    } catch (mysqli_sql_exception $e) { error_log($e->getMessage()); $error='Refund could not be saved. No refund was committed. Please try again.'; }
    catch (RuntimeException $e) { $error=$e->getMessage(); }
}
$history=sales_refund_query($conn,'SELECT r.*,w.wallet_name FROM sales_refunds r LEFT JOIN wallets w ON w.id=r.wallet_id WHERE r.user_id=? AND r.invoice_id=? ORDER BY r.id DESC','ii',[$cid,$id])->get_result()->fetch_all(MYSQLI_ASSOC);
$paid=(float)sales_refund_query($conn,'SELECT COALESCE(SUM(amount),0) total FROM customer_payments WHERE user_id=? AND invoice_id=? AND branch_id=?','iii',[$cid,$id,$branch])->get_result()->fetch_assoc()['total'];
$remaining=max(0,round(min($paid,(float)$invoice['total_amount'])-array_sum(array_column($history,'amount')),2));
$items=sales_refund_query($conn,'SELECT ii.*,p.product_name,COALESCE((SELECT SUM(ri.quantity) FROM sales_refund_items ri WHERE ri.invoice_item_id=ii.id),0) returned FROM invoice_items ii LEFT JOIN products p ON p.id=ii.product_id WHERE ii.invoice_id=? AND ii.quantity>0','i',[$id])->get_result()->fetch_all(MYSQLI_ASSOC);
$wallets=sales_refund_query($conn,"SELECT id,wallet_name,balance FROM wallets WHERE user_id=? AND branch_id=? AND status='active'",'ii',[$cid,$branch])->get_result()->fetch_all(MYSQLI_ASSOC);
function refund_h($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title">Sales Refund — <?= refund_h($invoice['invoice_no']) ?></h3><a class="float-right" href="view_invoice.php?id=<?= $id ?>">Original invoice</a></div><div class="card-body">
<?php if ($error!=='') { ?><div class="alert alert-danger" role="alert"><?= refund_h($error) ?></div><?php } ?>
<?php if (isset($_SESSION['refund_success'])) { ?><div class="alert alert-success" role="status"><?= refund_h($_SESSION['refund_success']) ?></div><?php unset($_SESSION['refund_success']); } ?>
<p>Remaining refundable payment: <strong><?= number_format($remaining,2) ?></strong>. The original invoice stays unchanged; each refund creates a linked credit note and reverses the customer receipt.</p>
<p>Enter the full remaining amount or a smaller amount for a partial refund. Quantity and restocking are separate: leave quantity at zero for a money-only refund. Customer credit not recorded as a payment on this invoice is excluded.</p>
<?php if ($remaining>0) { ?>
<form method="post" onsubmit="return confirm('Record this refund and deduct the selected wallet?');">
<input type="hidden" name="stock_csrf" value="<?= refund_h(stock_csrf_token()) ?>"><input type="hidden" name="request_key" value="<?= refund_h($_SESSION['refund_keys'][$id]) ?>">
<div class="row"><div class="col-md-6 form-group"><label for="refund-amount">Refund amount</label><input class="form-control" id="refund-amount" name="amount" type="number" min="0.01" max="<?= refund_h($remaining) ?>" step="0.01" required></div>
<div class="col-md-6 form-group"><label for="refund-wallet">Refund wallet</label><select class="form-control" id="refund-wallet" name="wallet_id" required><option value="">Select wallet</option><?php foreach ($wallets as $w) { ?><option value="<?= (int)$w['id'] ?>"><?= refund_h($w['wallet_name']) ?> (<?= number_format((float)$w['balance'],2) ?>)</option><?php } ?></select></div></div>
<div class="table-responsive"><table class="table table-bordered"><thead><tr><th>Product</th><th>Unreturned quantity</th><th>Return now</th><th>Add to stock</th></tr></thead><tbody>
<?php foreach ($items as $item) { $itemId=(int)$item['id']; $available=max(0,(int)$item['quantity']-(int)$item['returned']); ?><tr><td><?= refund_h($item['product_name']) ?> <?= refund_h($item['variant_name']??'') ?></td><td><?= $available ?></td><td><input aria-label="Return quantity for <?= refund_h($item['product_name']) ?>" class="form-control" style="min-width:90px" type="number" name="items[<?= $itemId ?>][quantity]" min="0" max="<?= $available ?>" step="1" value="0"></td><td><label><input type="checkbox" name="items[<?= $itemId ?>][restock]" value="1"> Restock</label></td></tr><?php } ?>
</tbody></table></div><div class="form-group"><label for="refund-reason">Reason</label><textarea class="form-control" id="refund-reason" name="reason" maxlength="1000" required></textarea></div><button class="btn btn-danger" type="submit">Record refund</button></form>
<?php } ?>
<h4 class="mt-4">Refund history</h4><div class="table-responsive"><table class="table table-bordered"><thead><tr><th>Refund</th><th>Amount</th><th>Wallet</th><th>Reason</th><th>Date</th></tr></thead><tbody><?php foreach ($history as $r) { ?><tr><td>#<?= (int)$r['id'] ?> <a href="view_invoice.php?id=<?= (int)$r['credit_invoice_id'] ?>">Credit note</a></td><td><?= number_format((float)$r['amount'],2) ?></td><td><?= refund_h($r['wallet_name']) ?></td><td><?= refund_h($r['reason']) ?></td><td><?= refund_h($r['created_at']) ?></td></tr><?php } ?></tbody></table></div>
</div></div>
<?php require_once '../includes/footer.php'; ?>
