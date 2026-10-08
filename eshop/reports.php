<?php
require_once 'bootstrap.php';
require_once '../includes/eshop_order_helper.php';
require_once '../includes/eshop_delivery_helper.php';
eshop_order_schema($conn); eshop_delivery_schema($conn);

$validDate=static function($value){
    $value=trim((string)$value);
    if(!preg_match('/\A\d{4}-\d{2}-\d{2}\z/',$value)) return null;
    $date=DateTime::createFromFormat('!Y-m-d',$value); $errors=DateTime::getLastErrors();
    return $date && (!$errors || (!$errors['warning_count'] && !$errors['error_count'])) && $date->format('Y-m-d')===$value ? $value : null;
};
$toDate=$validDate($_GET['to']??'')??date('Y-m-d');
$fromDate=$validDate($_GET['from']??'')??date('Y-m-d',strtotime($toDate.' -29 days'));
if($fromDate>$toDate){ $swap=$fromDate; $fromDate=$toDate; $toDate=$swap; }

$hasRefunds=false; try { $hasRefunds=mysqli_query($conn,'SELECT 1 FROM sales_refunds LIMIT 1')!==false; } catch(Throwable $e) {}
$refundJoin=$hasRefunds ? 'LEFT JOIN (SELECT invoice_id,COALESCE(SUM(amount),0) amount FROM sales_refunds WHERE user_id=? GROUP BY invoice_id) r ON r.invoice_id=i.id' : 'LEFT JOIN (SELECT 0 invoice_id,0 amount) r ON 1=0';
$summarySql="SELECT COUNT(*) orders_total,
    COALESCE(SUM(COALESCE(i.accounting_status,'')='pending'),0) pending_orders,
    COALESCE(SUM(COALESCE(i.accounting_status,'')='posted'),0) confirmed_orders,
    COALESCE(SUM(CASE WHEN i.accounting_status='posted' THEN i.total_amount ELSE 0 END),0) confirmed_gross,
    COALESCE(SUM(COALESCE(r.amount,0)),0) refunded_total,
    COALESCE(SUM(CASE WHEN i.accounting_status='posted' THEN i.total_amount-COALESCE(r.amount,0) ELSE 0 END),0) net_confirmed_sales
    FROM eshop_orders o LEFT JOIN invoices i ON i.id=o.invoice_id AND i.user_id=o.company_id $refundJoin
    WHERE o.company_id=? AND DATE(o.created_at) BETWEEN ? AND ?";
$summaryStmt=mysqli_prepare($conn,$summarySql); if($hasRefunds) mysqli_stmt_bind_param($summaryStmt,'iiss',$company_id,$company_id,$fromDate,$toDate); else mysqli_stmt_bind_param($summaryStmt,'iss',$company_id,$fromDate,$toDate); mysqli_stmt_execute($summaryStmt); $summary=mysqli_fetch_assoc(mysqli_stmt_get_result($summaryStmt))?:[];
$dailySql="SELECT DATE(o.created_at) report_date,COUNT(*) orders_total,
    COALESCE(SUM(COALESCE(i.accounting_status,'')='pending'),0) pending_orders,
    COALESCE(SUM(COALESCE(i.accounting_status,'')='posted'),0) confirmed_orders,
    COALESCE(SUM(COALESCE(d.status,'new')='delivered'),0) delivered_orders,
    COALESCE(SUM(CASE WHEN i.accounting_status='posted' THEN i.total_amount ELSE 0 END),0) confirmed_gross,
    COALESCE(SUM(COALESCE(r.amount,0)),0) refunded_total,
    COALESCE(SUM(CASE WHEN i.accounting_status='posted' THEN i.total_amount-COALESCE(r.amount,0) ELSE 0 END),0) net_confirmed_sales
    FROM eshop_orders o LEFT JOIN invoices i ON i.id=o.invoice_id AND i.user_id=o.company_id LEFT JOIN eshop_delivery d ON d.order_id=o.id $refundJoin
    WHERE o.company_id=? AND DATE(o.created_at) BETWEEN ? AND ? GROUP BY DATE(o.created_at) ORDER BY report_date DESC";
$dailyStmt=mysqli_prepare($conn,$dailySql); if($hasRefunds) mysqli_stmt_bind_param($dailyStmt,'iiss',$company_id,$company_id,$fromDate,$toDate); else mysqli_stmt_bind_param($dailyStmt,'iss',$company_id,$fromDate,$toDate); mysqli_stmt_execute($dailyStmt); $daily=mysqli_fetch_all(mysqli_stmt_get_result($dailyStmt),MYSQLI_ASSOC);
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card card-outline card-primary"><div class="card-header"><h3 class="card-title"><i class="fas fa-chart-line mr-2"></i>E-shop Reports</h3><a class="float-right" href="index.php">E-shop</a></div><div class="card-body">
<p class="text-muted">Order activity for the selected date range. Refund and net sales totals include refunds recorded against orders placed in this period.</p>
<form method="get" class="row mb-3"><div class="col-12 col-md-4 mb-2"><label for="from">From date</label><input id="from" class="form-control" type="date" name="from" value="<?= eshop_escape($fromDate) ?>"></div><div class="col-12 col-md-4 mb-2"><label for="to">To date</label><input id="to" class="form-control" type="date" name="to" value="<?= eshop_escape($toDate) ?>"></div><div class="col-12 col-md-4 d-flex align-items-end mb-2"><button class="btn btn-primary btn-block">View report</button></div></form>
<div class="row"><div class="col-6 col-md mb-3"><div class="small-box bg-info"><div class="inner"><h3><?= (int)($summary['orders_total']??0) ?></h3><p>Orders placed</p></div></div></div><div class="col-6 col-md mb-3"><div class="small-box bg-warning"><div class="inner"><h3><?= (int)($summary['pending_orders']??0) ?></h3><p>Pending</p></div></div></div><div class="col-6 col-md mb-3"><div class="small-box bg-success"><div class="inner"><h3>BDT <?= number_format((float)($summary['confirmed_gross']??0),0) ?></h3><p>Gross sales</p></div></div></div><div class="col-6 col-md mb-3"><div class="small-box bg-danger"><div class="inner"><h3>BDT <?= number_format((float)($summary['refunded_total']??0),0) ?></h3><p>Refunded</p></div></div></div><div class="col-12 col-md mb-3"><div class="small-box bg-secondary"><div class="inner"><h3>BDT <?= number_format((float)($summary['net_confirmed_sales']??0),0) ?></h3><p>Net sales</p></div></div></div></div>
<div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>Date</th><th>Orders</th><th>Pending</th><th>Confirmed</th><th>Delivered</th><th>Gross</th><th>Refunded</th><th>Net sales</th></tr></thead><tbody><?php if(!$daily){ ?><tr><td colspan="8" class="text-muted">No E-shop orders in this date range.</td></tr><?php } ?><?php foreach($daily as $row){ ?><tr><td><?= eshop_escape($row['report_date']) ?></td><td><?= (int)$row['orders_total'] ?></td><td><?= (int)$row['pending_orders'] ?></td><td><?= (int)$row['confirmed_orders'] ?></td><td><?= (int)$row['delivered_orders'] ?></td><td>BDT <?= number_format((float)$row['confirmed_gross'],2) ?></td><td>BDT <?= number_format((float)$row['refunded_total'],2) ?></td><td>BDT <?= number_format((float)$row['net_confirmed_sales'],2) ?></td></tr><?php } ?></tbody></table></div>
</div></div>
<?php require_once '../includes/footer.php'; ?>
