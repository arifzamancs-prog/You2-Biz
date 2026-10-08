<?php
require_once 'bootstrap.php';
require_once '../includes/eshop_order_helper.php';
require_once '../includes/eshop_delivery_helper.php';
require_once '../includes/eshop_cancel_helper.php';
require_once '../includes/eshop_notification_helper.php';
require_once '../includes/eshop_return_helper.php';
require_once '../includes/eshop_refund_status_helper.php';
eshop_return_schema($conn);
eshop_order_schema($conn); eshop_delivery_schema($conn);
eshop_cancel_schema($conn);
eshop_notification_schema($conn);
if(empty($_SESSION['eshop_notify_key'])) $_SESSION['eshop_notify_key']=bin2hex(random_bytes(32));
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    eshop_check_csrf();
    try{
        if(($_POST['action']??'')==='request_return'){
            eshop_request_return($conn,$company_id,(int)($_POST['order_id']??0),(int)($_SESSION['login_user_id']??$company_id),(string)($_POST['reason']??''));
            $_SESSION['eshop_order_flash']='Return request recorded for review. No stock or refund adjustment has been made.';
            header('Location: orders.php?id='.(int)$_POST['order_id']); exit;
        }elseif(($_POST['action']??'')==='notify'){
            $key=(string)($_POST['notification_key']??'');
            if(!hash_equals($_SESSION['eshop_notify_key'],$key)) throw new RuntimeException('Refresh the order before sending another email.');
            $_SESSION['eshop_notify_key']=bin2hex(random_bytes(32));
            $_SESSION['eshop_order_flash']=eshop_notify_order($conn,$company_id,(int)($_POST['order_id']??0),(int)($_SESSION['login_user_id']??$company_id),$key);
            header('Location: orders.php?id='.(int)$_POST['order_id']); exit;
        }elseif(($_POST['action']??'')==='cancel'){
            eshop_cancel_pending_order($conn,$company_id,(int)($_POST['order_id']??0),(string)($_POST['reason']??''),(int)($_SESSION['login_user_id']??$company_id));
        }else{
            eshop_update_delivery($conn,$company_id,(int)($_POST['order_id']??0),(string)($_POST['status']??''),trim((string)($_POST['tracking']??'')),(int)($_SESSION['login_user_id']??$company_id));
        }
        $_SESSION['eshop_order_flash']='Order updated.';
        eshop_auto_notify($conn,$company_id,(int)($_POST['order_id']??0),(int)($_SESSION['login_user_id']??$company_id));
        header('Location: orders.php?id='.(int)$_POST['order_id']); exit;
    }catch(RuntimeException $e){ $error=$e instanceof mysqli_sql_exception?'Unable to update this order.':$e->getMessage(); }
}
$filter=(string)($_GET['status']??'');
if(!in_array($filter,['new','processing','shipped','delivered','cancelled'],true)) $filter='';
$invoiceFilter=(string)($_GET['invoice']??'');
if(!in_array($invoiceFilter,['pending','posted'],true)) $invoiceFilter='';
$q=mb_substr(trim((string)($_GET['q']??'')),0,100);
$fromDate=trim((string)($_GET['from']??'')); $toDate=trim((string)($_GET['to']??''));
$validOrderDate=static function($value){
    if($value==='' || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/',$value)) return '';
    $date=DateTime::createFromFormat('!Y-m-d',$value); $errors=DateTime::getLastErrors();
    return $date && (!$errors || (!$errors['warning_count'] && !$errors['error_count'])) && $date->format('Y-m-d')===$value ? $value : '';
};
$fromDate=$validOrderDate($fromDate); $toDate=$validOrderDate($toDate);
$page=max(1,min(100000,(int)($_GET['page']??1))); $offset=($page-1)*20; $like='%'.$q.'%';
$id=(int)($_GET['id']??0);
$listQuery=http_build_query(['q'=>$q,'status'=>$filter,'invoice'=>$invoiceFilter,'from'=>$fromDate,'to'=>$toDate,'page'=>$page]);
$sql="SELECT o.*,COALESCE(d.status,'new') AS delivery_status,COALESCE(d.tracking,'') AS tracking,i.id AS linked_invoice,i.accounting_status,i.payment_status,i.due_amount,i.total_amount FROM eshop_orders o LEFT JOIN eshop_delivery d ON d.order_id=o.id LEFT JOIN invoices i ON i.id=o.invoice_id AND i.user_id=o.company_id WHERE o.company_id=?";
if($id>0){ $s=mysqli_prepare($conn,$sql.' AND o.id=?'); mysqli_stmt_bind_param($s,'ii',$company_id,$id); }
else{
    $s=mysqli_prepare($conn,$sql." AND (?='' OR COALESCE(d.status,'new')=?) AND (?='' OR COALESCE(i.accounting_status,'')=?) AND (?='' OR DATE(o.created_at)>=?) AND (?='' OR DATE(o.created_at)<=?) AND (o.order_no LIKE ? OR o.customer_name LIKE ? OR o.phone LIKE ?) ORDER BY o.id DESC LIMIT 21 OFFSET ?");
    mysqli_stmt_bind_param($s,'isssssssssssi',$company_id,$filter,$filter,$invoiceFilter,$invoiceFilter,$fromDate,$fromDate,$toDate,$toDate,$like,$like,$like,$offset);
}
mysqli_stmt_execute($s); $rows=mysqli_fetch_all(mysqli_stmt_get_result($s),MYSQLI_ASSOC); $more=count($rows)>20; $rows=array_slice($rows,0,20);
if($id>0 && !$rows){ http_response_code(404); exit('Order not found.'); }
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title">E-shop Orders</h3><?php if($id>0){ ?><a class="float-right" href="orders.php?<?= eshop_escape($listQuery) ?>">← Back to results</a><?php }else{ ?><a class="float-right" href="index.php">E-shop</a><?php } ?></div><div class="card-body">
<?php if($error){ ?><div class="alert alert-danger" role="alert"><?= eshop_escape($error) ?></div><?php } ?>
<?php if(isset($_SESSION['eshop_order_flash'])){ ?><div class="alert alert-success" role="status"><?= eshop_escape($_SESSION['eshop_order_flash']) ?></div><?php unset($_SESSION['eshop_order_flash']); } ?>
<form method="get" class="row mb-3"><div class="col-12 col-lg-4 mb-2"><input class="form-control" name="q" value="<?= eshop_escape($q) ?>" placeholder="Order reference, customer or phone" aria-label="Search orders"></div><div class="col-6 col-lg-2 mb-2"><select name="status" class="form-control" aria-label="Delivery status"><option value="">All delivery statuses</option><?php foreach(['new','processing','shipped','delivered','cancelled'] as $status){ ?><option <?= $filter===$status?'selected':'' ?> value="<?= $status ?>"><?= ucfirst($status) ?></option><?php } ?></select></div><div class="col-6 col-lg-2 mb-2"><select name="invoice" class="form-control" aria-label="Invoice status"><option value="">All invoice statuses</option><option value="pending" <?= $invoiceFilter==='pending'?'selected':'' ?>>Pending confirmation</option><option value="posted" <?= $invoiceFilter==='posted'?'selected':'' ?>>Confirmed</option></select></div><div class="col-6 col-lg-1 mb-2"><input class="form-control" type="date" name="from" value="<?= eshop_escape($fromDate) ?>" aria-label="From date" title="From date"></div><div class="col-6 col-lg-1 mb-2"><input class="form-control" type="date" name="to" value="<?= eshop_escape($toDate) ?>" aria-label="To date" title="To date"></div><div class="col-12 col-lg-2 mb-2"><button class="btn btn-primary btn-block">Search</button></div></form>
<?php if(!$rows){ ?><p>No orders found.<?php if($q!=='' || $filter!=='' || $invoiceFilter!=='' || $fromDate!=='' || $toDate!==''){ ?> <a href="orders.php">Clear filters</a><?php } ?></p><?php } ?>
<?php foreach($rows as $order){ $refund=eshop_order_refund_status($conn,$company_id,(int)$order['id'],$id>0); $deliveryBadge=['new'=>'secondary','processing'=>'info','shipped'=>'primary','delivered'=>'success','cancelled'=>'dark'][$order['delivery_status']]??'secondary'; $invoiceBadge=['pending'=>'warning','posted'=>'success'][$order['accounting_status']??'']??'secondary'; $detailQuery=http_build_query(['id'=>(int)$order['id'],'q'=>$q,'status'=>$filter,'invoice'=>$invoiceFilter,'from'=>$fromDate,'to'=>$toDate,'page'=>$page]); $phoneDial=preg_replace('/[^0-9+]/','',(string)$order['phone']); $hasCustomerEmail=(bool)filter_var($order['email']??'',FILTER_VALIDATE_EMAIL); ?>
<section class="card card-outline card-primary"><div class="card-body">
<div class="row"><div class="col-12 col-md-6"><h5><a href="?<?= eshop_escape($detailQuery) ?>"><?= eshop_escape($order['order_no']) ?></a></h5><p><?= eshop_escape($order['created_at']) ?></p><strong><?= eshop_escape($order['customer_name']) ?></strong><p class="text-break"><?= eshop_escape($order['phone']) ?><br><?= eshop_escape($order['email']) ?><br><?= nl2br(eshop_escape($order['address'])) ?></p><?php if($id>0 && $phoneDial!==''){ ?><a class="btn btn-sm btn-outline-success mb-2" href="tel:<?= eshop_escape($phoneDial) ?>"><i class="fas fa-phone mr-1"></i>Call customer</a><?php } ?><?php if($id>0 && $hasCustomerEmail){ ?> <a class="btn btn-sm btn-outline-primary mb-2" href="mailto:<?= eshop_escape($order['email']) ?>"><i class="fas fa-envelope mr-1"></i>Email customer</a><?php } ?></div>
<div class="col-12 col-md-6"><p>Order total: <strong>BDT <?= number_format((float)$order['total'],2) ?></strong><br>Delivery: <span class="badge badge-<?= $deliveryBadge ?>"><?= eshop_escape(ucfirst($order['delivery_status'])) ?></span><br>Invoice: <span class="badge badge-<?= $invoiceBadge ?>"><?= eshop_escape(ucfirst($order['accounting_status']??'Unavailable')) ?></span><br>Payment: <?= eshop_escape($order['payment_status']??'Unavailable') ?></p>
<?php if($order['linked_invoice']){ ?><a class="btn btn-outline-primary btn-sm" href="../sales/view_invoice.php?id=<?= (int)$order['invoice_id'] ?>">View Sales Invoice</a> <a class="btn btn-outline-secondary btn-sm" href="../sales/invoice_list.php">Manage in Sales</a><?php }elseif($order['delivery_status']==='cancelled'){ ?><p class="text-muted">Pending invoice cancelled; stock reservation released.</p><?php }else{ ?><p class="text-danger">Linked invoice is unavailable. Review this order before fulfillment.</p><?php } ?></div></div>
<?php if($order['delivery_status']==='cancelled'){
    $cs=mysqli_prepare($conn,'SELECT reason,created_at,invoice_snapshot FROM eshop_cancellations WHERE order_id=?'); mysqli_stmt_bind_param($cs,'i',$order['id']); mysqli_stmt_execute($cs); $cancellation=mysqli_fetch_assoc(mysqli_stmt_get_result($cs));
    if($cancellation){ ?><div class="alert alert-secondary">Cancelled: <?= eshop_escape($cancellation['created_at']) ?><br>Reason: <?= eshop_escape($cancellation['reason']) ?></div>
    <?php if($id>0){ $snapshot=json_decode($cancellation['invoice_snapshot'],true); ?><h6>Original invoice items</h6><ul><?php foreach($snapshot['items']??[] as $saved){ ?><li><?= eshop_escape($saved['product_name']??'Product') ?> × <?= (int)$saved['quantity'] ?> — BDT <?= number_format((float)$saved['total_price'],2) ?></li><?php } ?></ul><?php } }
} ?>
<div class="alert <?= $refund['status']==='none'?'alert-light':'alert-info' ?> mt-3" role="status">
<strong>Refund: <?= eshop_escape($refund['label']) ?></strong>
<?php if($refund['count']>0){ ?><br>Total refunded: BDT <?= number_format($refund['amount'],2) ?> · <?= $refund['count'] ?> record(s)<?php } ?>
<div class="small">Refund status is separate from the original payment and delivery status. A return request alone does not mean money was refunded.</div>
<?php if($order['linked_invoice'] && $order['accounting_status']==='posted' && (float)$order['total_amount']>0){ ?><a class="btn btn-sm btn-outline-dark mt-2" href="../sales/refund.php?id=<?= (int)$order['invoice_id'] ?>">Refund / History in Sales</a><span class="small d-block mt-1">Select the original invoice's branch in Sales if prompted.</span><?php } ?>
</div>
<?php if($id>0 && $refund['history']){ ?><details class="mb-3"><summary>Sales refund history</summary><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Refund</th><th>Amount</th><th>Reason</th><th>Date</th></tr></thead><tbody><?php foreach($refund['history'] as $entry){ ?><tr><td>#<?= (int)$entry['id'] ?> <a href="../sales/view_invoice.php?id=<?= (int)$entry['credit_invoice_id'] ?>">Credit note</a></td><td>BDT <?= number_format((float)$entry['amount'],2) ?></td><td class="text-break"><?= eshop_escape($entry['reason']) ?></td><td><?= eshop_escape($entry['created_at']) ?></td></tr><?php } ?></tbody></table></div><?php if($refund['count']>50){ ?><p class="small">Showing the latest 50 refunds. Open Sales for the complete history.</p><?php } ?></details><?php } ?>
<?php if($order['accounting_status']==='pending' && in_array($order['delivery_status'],['new','processing'],true)){ ?>
<details class="mt-3 mb-3"><summary class="text-danger">Cancel pending order</summary><form method="post" onsubmit="return confirm('Cancel this order and remove its unpaid pending invoice? Its stock reservation will be released.');">
<input type="hidden" name="csrf" value="<?= eshop_escape($_SESSION['eshop_editor_csrf']) ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
<label class="mt-2" for="reason-<?= $order['id'] ?>">Cancellation reason</label><textarea id="reason-<?= $order['id'] ?>" name="reason" class="form-control mb-2" maxlength="500" required></textarea><button class="btn btn-outline-danger btn-sm">Cancel Order</button></form></details>
<?php } ?>
<?php if($id>0 && $order['linked_invoice']){
    $items=mysqli_prepare($conn,'SELECT ii.variant_name,ii.quantity,ii.unit_price,ii.total_price,p.product_name FROM invoice_items ii JOIN invoices i ON i.id=ii.invoice_id LEFT JOIN products p ON p.id=ii.product_id AND p.user_id=i.user_id WHERE i.id=? AND i.user_id=?'); mysqli_stmt_bind_param($items,'ii',$order['invoice_id'],$company_id); mysqli_stmt_execute($items); $items=mysqli_stmt_get_result($items);
?><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Product</th><th>Variant</th><th>Qty</th><th>Unit price</th><th>Total</th></tr></thead><tbody><?php while($item=mysqli_fetch_assoc($items)){ ?><tr><td><?= eshop_escape($item['product_name']??'Unavailable product') ?></td><td><?= eshop_escape($item['variant_name']?:'—') ?></td><td><?= (int)$item['quantity'] ?></td><td><?= number_format((float)$item['unit_price'],2) ?></td><td><?= number_format((float)$item['total_price'],2) ?></td></tr><?php } ?></tbody></table></div><?php } ?>
<?php if($order['linked_invoice']){ ?><form method="post" class="row mt-3"><input type="hidden" name="csrf" value="<?= eshop_escape($_SESSION['eshop_editor_csrf']) ?>"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><div class="col-12 col-md-4 form-group"><label for="status-<?= $order['id'] ?>">Delivery status</label><select id="status-<?= $order['id'] ?>" name="status" class="form-control"><?php foreach(array_merge([$order['delivery_status']],eshop_delivery_steps($order['delivery_status'])) as $status){ ?><option value="<?= $status ?>"><?= ucfirst($status) ?></option><?php } ?></select></div><div class="col-12 col-md-6 form-group"><label for="tracking-<?= $order['id'] ?>">Courier / tracking reference</label><input id="tracking-<?= $order['id'] ?>" class="form-control" name="tracking" maxlength="200" value="<?= eshop_escape($order['tracking']) ?>"></div><div class="col-12 col-md-2 form-group d-flex align-items-end"><button class="btn btn-primary">Update</button></div></form><?php } ?>
<?php if($id>0){ $history=mysqli_prepare($conn,'SELECT status,tracking,created_at FROM eshop_delivery_history WHERE order_id=? ORDER BY id DESC'); mysqli_stmt_bind_param($history,'i',$id); mysqli_stmt_execute($history); $history=mysqli_stmt_get_result($history); ?><h6>Status history</h6><ul><?php while($event=mysqli_fetch_assoc($history)){ ?><li><?= eshop_escape($event['created_at'].' — '.ucfirst($event['status']).' — '.$event['tracking']) ?></li><?php } ?></ul><?php } ?>
<?php if($id>0){ ?>
<?php
$rs=mysqli_prepare($conn,'SELECT reason,created_at FROM eshop_return_requests WHERE order_id=? AND company_id=?'); mysqli_stmt_bind_param($rs,'ii',$id,$company_id); mysqli_stmt_execute($rs); $return_request=mysqli_fetch_assoc(mysqli_stmt_get_result($rs));
if($return_request){ ?><div class="alert alert-warning"><strong>Return requested — review in Sales</strong><br><?= eshop_escape($return_request['created_at']) ?><br><?= nl2br(eshop_escape($return_request['reason'])) ?><p class="mb-2">The company administrator processes returns and refunds in Sales. This request does not adjust stock or payment and is not proof of a completed refund. Select the original invoice's branch before proceeding.</p><a class="btn btn-dark btn-sm" href="../sales/invoice_list.php">Open Sales Invoice List</a><?php if($order['linked_invoice']){ ?> <a class="btn btn-outline-dark btn-sm" href="../sales/view_invoice.php?id=<?= (int)$order['invoice_id'] ?>">View Original Invoice</a><?php } ?></div>
<?php }elseif($order['accounting_status']==='posted' && in_array($order['delivery_status'],['shipped','delivered'],true)){ ?>
<details class="mb-3"><summary>Request return review</summary><form method="post"><input type="hidden" name="csrf" value="<?= eshop_escape($_SESSION['eshop_editor_csrf']) ?>"><input type="hidden" name="action" value="request_return"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><label for="return-reason" class="mt-2">Return reason and items/quantities to review</label><textarea id="return-reason" name="reason" class="form-control mb-2" required maxlength="1000"></textarea><p class="text-muted">This records a review request. It does not return stock or refund payment.</p><button class="btn btn-outline-warning">Submit Return Request</button></form></details>
<?php } ?>
<hr><h6>Customer notification</h6><p class="small text-muted">Send the current order status and tracking reference to <?= eshop_escape($hasCustomerEmail?$order['email']:'the customer') ?>.</p>
<?php if($hasCustomerEmail){ ?><form method="post" class="mb-3" onsubmit="this.querySelector('button').disabled=true;">
<input type="hidden" name="csrf" value="<?= eshop_escape($_SESSION['eshop_editor_csrf']) ?>"><input type="hidden" name="notification_key" value="<?= eshop_escape($_SESSION['eshop_notify_key']) ?>"><input type="hidden" name="action" value="notify"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><button class="btn btn-outline-primary"><i class="fas fa-envelope mr-1"></i>Email Order Update</button>
</form><?php }else{ ?><div class="alert alert-warning small">A valid customer email is required before an order update can be sent.</div><?php } ?>
<?php $ns=mysqli_prepare($conn,'SELECT recipient,delivery_status,result,created_at FROM eshop_notifications WHERE company_id=? AND order_id=? ORDER BY id DESC LIMIT 20'); mysqli_stmt_bind_param($ns,'ii',$company_id,$id); mysqli_stmt_execute($ns); $notifications=mysqli_stmt_get_result($ns); ?>
<ul class="small text-break"><?php while($notice=mysqli_fetch_assoc($notifications)){ ?><li><?= eshop_escape($notice['created_at'].' — '.$notice['recipient'].' — '.ucfirst($notice['delivery_status']).' — '.($notice['result']==='accepted'?'Accepted by mail server':($notice['result']==='failed'?'Failed':($notice['result']==='invalid_email'?'Not sent: invalid or missing email':'Sending / result unverified')))) ?></li><?php } ?></ul>
<?php } ?>
</div></section><?php } ?>
<?php if(!$id){ ?><nav><?php if($page>1){ ?><a class="btn btn-outline-secondary" href="?<?= eshop_escape(http_build_query(['q'=>$q,'status'=>$filter,'invoice'=>$invoiceFilter,'from'=>$fromDate,'to'=>$toDate,'page'=>$page-1])) ?>">Previous</a><?php } ?> <?php if($more){ ?><a class="btn btn-outline-primary" href="?<?= eshop_escape(http_build_query(['q'=>$q,'status'=>$filter,'invoice'=>$invoiceFilter,'from'=>$fromDate,'to'=>$toDate,'page'=>$page+1])) ?>">Next</a><?php } ?></nav><?php } ?>
</div></div><?php require_once '../includes/footer.php'; ?>
