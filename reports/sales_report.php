<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/booking_invoice_helper.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id=(int)$_SESSION['user_id']; ensure_invoice_posting_columns($conn); ensure_booking_invoice_table($conn); ensure_booking_invoice_type_table($conn, $user_id);
$month=isset($_GET['month'])?(int)$_GET['month']:(int)date('m');
$year=isset($_GET['year'])?(int)$_GET['year']:(int)date('Y');
if($month<1||$month>12) $month=(int)date('m'); if($year<2020||$year>2100) $year=(int)date('Y');

$sql="SELECT COALESCE(w.wallet_name,'Unassigned') AS wallet_name,
    COALESCE(SUM(bi.amount),0) AS total_sales,
    COALESCE(SUM(bi.amount),0) AS total_paid,
    COUNT(bi.id) AS invoice_count
    FROM booking_invoices bi
    LEFT JOIN wallets w ON w.id=bi.wallet_id
    LEFT JOIN booking_invoice_types bit ON bit.user_id=bi.user_id AND bit.type_key=bi.invoice_type
    WHERE bi.user_id=? AND bi.status='confirmed' AND COALESCE(bit.behavior,'income')='income'
    AND MONTH(bi.invoice_date)=? AND YEAR(bi.invoice_date)=?
    GROUP BY bi.wallet_id,w.wallet_name ORDER BY total_sales DESC,wallet_name";
$stmt=mysqli_prepare($conn,$sql); mysqli_stmt_bind_param($stmt,'iii',$user_id,$month,$year); mysqli_stmt_execute($stmt); $result=mysqli_stmt_get_result($stmt);
$summary=[]; $labels=[]; $values=[]; $sales_total=0; $paid_total=0; $invoice_total=0;
while($row=mysqli_fetch_assoc($result)){ $summary[]=$row; $labels[]=$row['wallet_name']; $values[]=(float)$row['total_sales']; $sales_total+=(float)$row['total_sales']; $paid_total+=(float)$row['total_paid']; $invoice_total+=(int)$row['invoice_count']; }

$wallet_stmt=mysqli_prepare($conn,"SELECT wallet_name,balance,status FROM wallets WHERE user_id=? ORDER BY status='active' DESC,wallet_name"); mysqli_stmt_bind_param($wallet_stmt,'i',$user_id); mysqli_stmt_execute($wallet_stmt); $wallet_result=mysqli_stmt_get_result($wallet_stmt);
$wallets=[]; $wallet_balance=0; while($wallet=mysqli_fetch_assoc($wallet_result)){ $wallet_balance+=(float)$wallet['balance']; $wallets[]=$wallet; }
?>
<section class="content"><div class="container-fluid">
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-chart-line mr-2"></i>Sales Report</h3></div><div class="card-body"><form method="get"><div class="row align-items-end"><div class="col-md-3"><label>Month</label><select name="month" class="form-control"><?php for($m=1;$m<=12;$m++){?><option value="<?=$m?>" <?=$month===$m?'selected':''?>><?=date('F',mktime(0,0,0,$m,1))?></option><?php }?></select></div><div class="col-md-3"><label>Year</label><select name="year" class="form-control"><?php for($y=(int)date('Y');$y>=2020;$y--){?><option value="<?=$y?>" <?=$year===$y?'selected':''?>><?=$y?></option><?php }?></select></div><div class="col-md-2"><button class="btn btn-primary btn-block"><i class="fas fa-filter"></i> Filter</button></div><div class="col-md-2"><button type="button" onclick="window.print()" class="btn btn-info btn-block"><i class="fas fa-print"></i> Print</button></div><div class="col-md-2"><a href="sales_report.php" class="btn btn-secondary btn-block"><i class="fas fa-redo"></i> This Month</a></div></div></form></div></div>
<div class="report-print-heading d-none">
    <div class="print-brand">YOU2 <span>TECHNOLOGIES</span></div>
    <div class="print-document-title">Sales Report</div>
    <div class="print-report-meta"><span>Report period: <?=date('F Y',mktime(0,0,0,$month,1,$year))?></span><span>Generated: <?=date('d M Y, h:i A')?></span></div>
</div>
<div class="row"><div class="col-md-4"><div class="small-box bg-primary"><div class="inner"><h3>BDT <?=number_format($sales_total,2)?></h3><p>Total Sales</p></div><div class="icon"><i class="fas fa-chart-line"></i></div></div></div><div class="col-md-4"><div class="small-box bg-success"><div class="inner"><h3>BDT <?=number_format($paid_total,2)?></h3><p>Invoice Payment</p></div><div class="icon"><i class="fas fa-hand-holding-usd"></i></div></div></div><div class="col-md-4"><div class="small-box bg-info"><div class="inner"><h3>BDT <?=number_format($wallet_balance,2)?></h3><p>Current Wallet Balance</p></div><div class="icon"><i class="fas fa-wallet"></i></div></div></div></div>
<div class="card"><div class="card-header"><h3 class="card-title">Sales Distribution by Payment by</h3></div><div class="card-body"><div style="height:400px"><canvas id="salesChart"></canvas></div></div></div>
<div class="card"><div class="card-header"><h3 class="card-title">Payment by Sales Summary</h3></div><div class="card-body table-responsive"><table class="table table-bordered table-striped"><thead><tr><th>Payment by</th><th>Invoices</th><th>Total Sales</th><th>Paid</th></tr></thead><tbody><?php foreach($summary as $row){?><tr><td><?=htmlspecialchars($row['wallet_name'])?></td><td><?=$row['invoice_count']?></td><td>BDT <?=number_format($row['total_sales'],2)?></td><td class="text-success">BDT <?=number_format($row['total_paid'],2)?></td></tr><?php } if(empty($summary)){?><tr><td colspan="4" class="text-center text-muted">No confirmed sales found for this month.</td></tr><?php }?></tbody><tfoot><tr><th>Total</th><th><?=$invoice_total?></th><th>BDT <?=number_format($sales_total,2)?></th><th>BDT <?=number_format($paid_total,2)?></th></tr></tfoot></table></div></div>
<div class="card"><div class="card-header"><h3 class="card-title">Current Wallet Balance</h3></div><div class="card-body table-responsive"><table class="table table-bordered table-striped"><thead><tr><th>Payment by</th><th>Current Balance</th><th>Status</th></tr></thead><tbody><?php foreach($wallets as $wallet){?><tr><td><?=htmlspecialchars($wallet['wallet_name'])?></td><td class="font-weight-bold">BDT <?=number_format($wallet['balance'],2)?></td><td><span class="badge badge-<?=$wallet['status']==='active'?'success':'secondary'?>"><?=ucfirst(htmlspecialchars($wallet['status']))?></span></td></tr><?php }?></tbody><tfoot><tr><th>Total Wallet Balance</th><th colspan="2">BDT <?=number_format($wallet_balance,2)?></th></tr></tfoot></table></div></div>
</div></section>
<?php $page_script='<script>new Chart(document.getElementById("salesChart"),{type:"pie",data:{labels:'.json_encode($labels).',datasets:[{data:'.json_encode($values).',backgroundColor:["#1677e8","#28a745","#ffc107","#dc3545","#6f42c1","#20c997"]}]},options:{responsive:true,maintainAspectRatio:false}});</script><style>@page{size:A4 landscape;margin:10mm}@media print{html,body{background:#fff!important;color:#14213d!important;font-size:11px!important}.main-header,.main-sidebar,.main-footer,.card:first-child,.btn,.dataTables_filter,.dataTables_length,.dataTables_paginate,.dataTables_info{display:none!important}.content-wrapper,.content,.container-fluid{margin:0!important;padding:0!important;width:100%!important;min-height:0!important}.report-print-heading{display:block!important;border-bottom:2px solid #1677e8!important;padding:0 0 10px!important;margin:0 0 12px!important;text-align:center!important}.report-print-heading h2{font-size:20px!important;letter-spacing:1px!important;margin:0 0 4px!important;color:#0b2e59!important}.report-print-heading strong{font-size:11px!important;color:#5d6b82!important}.row{display:flex!important;flex-wrap:wrap!important;margin:0 -4px!important}.row>.col-md-4{flex:0 0 33.333%!important;max-width:33.333%!important;padding:0 4px!important}.card{box-shadow:none!important;border:1px solid #d9e2ef!important;border-radius:0!important;margin-bottom:10px!important;break-inside:avoid!important}.card-header{background:#f3f7fc!important;border-bottom:1px solid #d9e2ef!important;padding:7px 10px!important;font-weight:700!important}.card-body{padding:9px!important}.small-box{background:#fff!important;border:1px solid #d9e2ef!important;box-shadow:none!important;min-height:auto!important;margin-bottom:8px!important;color:#14213d!important}.small-box h3,.small-box p{color:#14213d!important}.small-box h3{font-size:18px!important;margin:0!important}.small-box p{font-size:10px!important;margin:3px 0 0!important}.small-box .icon{display:none!important}canvas{max-height:230px!important}table{width:100%!important;font-size:10px!important;border-collapse:collapse!important}th{background:#eaf2fb!important;color:#12375d!important}th,td{padding:5px!important;border:1px solid #d9e2ef!important}.badge{border:1px solid #8794a5!important;color:#14213d!important;background:#fff!important}}</style>'; require_once '../includes/footer.php'; ?>
