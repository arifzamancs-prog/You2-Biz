<?php
require_once 'bootstrap.php';
require_once '../includes/eshop_order_helper.php';
require_once '../includes/eshop_customer_auth_helper.php';
require_once '../includes/eshop_delivery_helper.php';
require_once '../includes/eshop_dashboard_helper.php';
require_once '../includes/smtp_mailer.php';
if(is_super_admin_user() || !is_admin_user()){
    http_response_code(403);
    exit('E-shop access is restricted to the company administrator.');
}
$shop = eshop_company_settings($conn, (int)$_SESSION['user_id']);
if(!$shop || !(int)$shop['enabled']){
    http_response_code(403);
    exit('E-shop is not enabled for this company.');
}
$company_id=(int)$_SESSION['user_id'];
eshop_order_schema($conn); eshop_delivery_schema($conn);
$customerVerification=eshop_checkout_verification_method($conn,$company_id);
$summary=eshop_dashboard_summary($conn,$company_id);
$smtpStatus=smtp_configuration_status($conn);
$profileStmt=mysqli_prepare($conn,'SELECT shop_name,phone,address FROM eshop_profiles WHERE company_id=?'); mysqli_stmt_bind_param($profileStmt,'i',$company_id); mysqli_stmt_execute($profileStmt); $profile=mysqli_fetch_assoc(mysqli_stmt_get_result($profileStmt))?:[];
$profileReady=trim((string)($profile['shop_name']??''))!=='' && trim((string)($profile['phone']??''))!=='' && trim((string)($profile['address']??''))!=='';
$checkoutStmt=mysqli_prepare($conn,'SELECT COUNT(*) amount FROM eshop_checkout_settings WHERE company_id=? AND branch_id>0'); mysqli_stmt_bind_param($checkoutStmt,'i',$company_id); mysqli_stmt_execute($checkoutStmt); $checkoutReady=(int)(mysqli_fetch_assoc(mysqli_stmt_get_result($checkoutStmt))['amount']??0)>0;
$publishedStmt=mysqli_prepare($conn,"SELECT COUNT(*) amount FROM eshop_products ep JOIN products p ON p.id=ep.product_id AND p.user_id=ep.company_id JOIN product_categories c ON c.id=p.category_id AND c.category_type='stock_product' WHERE ep.company_id=? AND ep.published=1 AND p.status='active'"); mysqli_stmt_bind_param($publishedStmt,'i',$company_id); mysqli_stmt_execute($publishedStmt); $publishedCount=(int)(mysqli_fetch_assoc(mysqli_stmt_get_result($publishedStmt))['amount']??0);
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<div class="card card-outline card-primary">
<div class="card-header"><h3 class="card-title"><i class="fas fa-store mr-2"></i>E-shop</h3></div>
<div class="card-body"><span class="badge badge-success mb-3">E-shop access active</span>
<h5>Your shop URL</h5><p class="text-break"><a target="_blank" rel="noopener" href="<?= htmlspecialchars(eshop_reserved_url($shop['slug'])) ?>"><?= htmlspecialchars(eshop_reserved_url($shop['slug'])) ?></a></p>
<p class="text-muted">Manage your storefront, products and customer orders. Configure Checkout Settings to accept cash-on-delivery orders.</p>
<div class="card card-outline <?= $profileReady && $checkoutReady && $publishedCount>0 && $smtpStatus['ready']?'card-success':'card-warning' ?>"><div class="card-header"><h3 class="card-title">Store readiness</h3></div><div class="card-body py-2"><div class="row"><div class="col-12 col-md-4 mb-2 mb-md-0"><a class="text-reset" href="settings.php"><i class="fas <?= $profileReady?'fa-check-circle text-success':'fa-exclamation-circle text-warning' ?> mr-1"></i>Shop profile <?= $profileReady?'ready':'needs details' ?></a></div><div class="col-12 col-md-4 mb-2 mb-md-0"><a class="text-reset" href="checkout.php"><i class="fas <?= $checkoutReady?'fa-check-circle text-success':'fa-exclamation-circle text-warning' ?> mr-1"></i>Checkout branch <?= $checkoutReady?'ready':'not configured' ?></a></div><div class="col-12 col-md-4"><a class="text-reset" href="products.php"><i class="fas <?= $publishedCount>0?'fa-check-circle text-success':'fa-exclamation-circle text-warning' ?> mr-1"></i><?= $publishedCount ?> published product<?= $publishedCount===1?'':'s' ?></a></div></div><hr class="my-2"><div class="small"><i class="fas <?= $smtpStatus['ready']?'fa-check-circle text-success':'fa-exclamation-circle text-warning' ?> mr-1"></i>Email setup <?= $smtpStatus['ready']?'looks complete. Delivery is recorded in each order history.':'needs attention: '.eshop_escape(implode(', ',$smtpStatus['issues'])).'. Contact the system administrator.' ?></div></div></div>
<div class="row"><div class="col-6 col-md-3 mb-3"><div class="small-box bg-warning"><div class="inner"><h3><?= $summary['pending_confirmation'] ?></h3><p>Pending confirmation</p></div></div></div><div class="col-6 col-md-3 mb-3"><div class="small-box bg-info"><div class="inner"><h3><?= $summary['delivery_processing']+$summary['delivery_new'] ?></h3><p>To fulfil</p></div></div></div><div class="col-6 col-md-3 mb-3"><div class="small-box bg-success"><div class="inner"><h3><?= $summary['delivery_shipped']+$summary['delivery_delivered'] ?></h3><p>Shipped / delivered</p></div></div></div><div class="col-6 col-md-3 mb-3"><div class="small-box bg-secondary"><div class="inner"><h3>BDT <?= number_format($summary['net_confirmed_sales'],0) ?></h3><p>Net confirmed sales</p></div></div></div></div>
<div class="alert alert-light border small">Orders: <strong><?= $summary['orders_total'] ?></strong> · Cancelled: <strong><?= $summary['delivery_cancelled'] ?></strong> · Refunded: <strong>BDT <?= number_format($summary['refunded_total'],2) ?></strong>. Sales figures above are net of recorded refunds.</div>
<div class="card card-outline card-light"><div class="card-header"><h3 class="card-title">Recent orders</h3><a class="float-right" href="orders.php">View all</a></div><div class="card-body p-0 table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Order</th><th>Customer</th><th>Delivery</th><th>Invoice</th><th>Total</th></tr></thead><tbody><?php if(!$summary['recent']){ ?><tr><td colspan="5" class="text-muted p-3">No orders yet.</td></tr><?php } ?><?php foreach($summary['recent'] as $order){ ?><tr><td><a href="orders.php?id=<?= (int)$order['id'] ?>"><?= eshop_escape($order['order_no']) ?></a></td><td><?= eshop_escape($order['customer_name']) ?></td><td><?= eshop_escape(ucfirst($order['delivery_status'])) ?></td><td><?= eshop_escape($order['accounting_status']??'Unavailable') ?></td><td>BDT <?= number_format((float)$order['total'],2) ?><?php if((float)$order['refunded_amount']>0){ ?><br><small class="text-danger">Refunded: <?= number_format((float)$order['refunded_amount'],2) ?></small><?php } ?></td></tr><?php } ?></tbody></table></div></div>
<div class="row mb-3"><div class="col-12 col-md-6 mb-2"><a class="btn btn-primary btn-block" href="settings.php"><i class="fas fa-cog mr-2"></i>Shop Settings</a></div><div class="col-12 col-md-6 mb-2"><a class="btn btn-outline-primary btn-block" href="products.php"><i class="fas fa-box mr-2"></i>Shop Products</a></div></div>
<div class="row mb-3"><div class="col-12 col-md-4 mb-2"><a class="btn btn-warning btn-block" href="orders.php?status=new">Review <?= $summary['pending_confirmation'] ?> Pending Order<?= $summary['pending_confirmation']===1?'':'s' ?></a></div><div class="col-12 col-md-4 mb-2"><a class="btn btn-info btn-block" href="orders.php?status=processing">Fulfil <?= $summary['delivery_processing']+$summary['delivery_new'] ?> Order<?= $summary['delivery_processing']+$summary['delivery_new']===1?'':'s' ?></a></div><div class="col-12 col-md-4 mb-2"><a class="btn btn-outline-success btn-block" href="orders.php?status=shipped">View Shipped Orders</a></div></div>
<p><a class="btn btn-primary mr-2 mb-2" href="orders.php">All Orders</a><a class="btn btn-outline-primary mr-2 mb-2" href="checkout.php">Checkout Settings</a><a class="btn btn-outline-secondary mb-2" href="reports.php"><i class="fas fa-chart-line mr-1"></i>Reports</a></p><div class="alert alert-light border py-2"><i class="fas fa-user-shield mr-1"></i>Customer checkout verification: <strong><?= $customerVerification==='email'?'Email OTP':'SMS OTP' ?></strong>. Change this in <a href="checkout.php">Checkout Settings</a>.</div>
<p class="mb-0">Contact Super Admin to change your shop URL or access.</p></div></div>
<?php require_once '../includes/footer.php'; ?>
