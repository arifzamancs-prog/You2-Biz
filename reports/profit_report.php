<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/booking_invoice_helper.php';
require_once '../includes/staff_ledger_helper.php';
require_once '../includes/branch_context_helper.php';
require_once '../includes/report_owner_scope_helper.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id=(int)$_SESSION['user_id']; ensure_invoice_posting_columns($conn); ensure_booking_invoice_table($conn); if($user_id>0){ ensure_booking_invoice_type_table($conn,$user_id); ensure_branch_accounting_columns($conn,$user_id); } ensure_staff_ledger_table($conn);
$invoice_scope=branch_scope_sql($conn,'i'); $sales_scope=branch_scope_sql($conn,'bi'); $expense_scope=branch_scope_sql($conn,'e'); $ledger_scope=branch_scope_sql($conn,'l');
$invoice_owner_scope=report_owner_scope_sql('i',$user_id); $sales_owner_scope=report_owner_scope_sql('bi',$user_id); $expense_owner_scope=report_owner_scope_sql('e',$user_id); $ledger_owner_scope=report_owner_scope_sql('l',$user_id);
// Date range, as in the Sales and Expense reports: today by default.
// The monthly chart shows the year of the To date, the yearly chart the five years up to it.
$valid_date=static function($value){ $d=DateTime::createFromFormat('!Y-m-d',(string)$value); return $d&&$d->format('Y-m-d')===$value?$value:''; };
$from=$valid_date($_GET['from']??'')?:date('Y-m-d'); $to=$valid_date($_GET['to']??'')?:date('Y-m-d');
if($from>$to){ [$from,$to]=[$to,$from]; }
$year=(int)substr($to,0,4);
// Inventory & Sales and Fashion: Gross Profit = sales − purchase price of the goods sold,
// Net Profit = Gross Profit − expenses. Supplier / purchase payments are the stock already
// counted in the purchase price, so they are not taken off again as expenses.
require_once '../includes/project_package_helper.php';
$profit_company_type=$user_id>0?project_package_company_type($conn,$user_id):'';
$profit_with_cost=in_array($profit_company_type,['Stock Product','Fashion house'],true);
$expense_kind_scope=$profit_with_cost?" AND COALESCE(e.source_type,'') NOT IN ('purchase_payment','supplier_payment')":'';
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$link=static fn($f,$t)=>'?'.http_build_query(['from'=>$f,'to'=>$t]);
function report_scalar($conn,$sql,$types,$values){$stmt=mysqli_prepare($conn,$sql); if(!$stmt)return 0.0; mysqli_stmt_bind_param($stmt,$types,...$values); mysqli_stmt_execute($stmt); $row=mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)); return (float)($row['amount']??0);}
$sales_sql="SELECT COALESCE(SUM(amount),0) amount FROM (SELECT i.total_amount amount FROM invoices i WHERE 1=1 {$invoice_owner_scope} {$invoice_scope} AND COALESCE(i.accounting_status,'posted')='posted' AND i.invoice_date BETWEEN ? AND ? UNION ALL SELECT bi.amount FROM booking_invoices bi LEFT JOIN booking_invoice_types bit ON bit.user_id=bi.user_id AND bit.type_key=bi.invoice_type WHERE 1=1 {$sales_owner_scope} {$sales_scope} AND bi.status='confirmed' AND COALESCE(bit.behavior,'income')='income' AND bi.invoice_date BETWEEN ? AND ?) sales";
$total_sales=report_scalar($conn,$sales_sql,'ssss',[$from,$to,$from,$to]);
$expense_sql="SELECT COALESCE(SUM(amount),0) amount FROM (SELECT e.amount FROM expenses e WHERE 1=1 {$expense_owner_scope} {$expense_scope} {$expense_kind_scope} AND e.approval_status='approved' AND e.txn_date BETWEEN ? AND ? UNION ALL SELECT l.amount FROM staff_ledger_entries l WHERE 1=1 {$ledger_owner_scope} {$ledger_scope} AND l.entry_date BETWEEN ? AND ?) x";
$total_expenses=report_scalar($conn,$expense_sql,'ssss',[$from,$to,$from,$to]); $net_profit=$total_sales-$total_expenses;

$breakdown_sql="SELECT category_name,SUM(amount) amount FROM (SELECT CONVERT(CONCAT('Expense: ',COALESCE(c.category_name,'General')) USING utf8mb4) COLLATE utf8mb4_unicode_ci category_name,e.amount FROM expenses e LEFT JOIN categories c ON c.id=e.category_id WHERE 1=1 {$expense_owner_scope} {$expense_scope} {$expense_kind_scope} AND e.approval_status='approved' AND e.txn_date BETWEEN ? AND ? UNION ALL SELECT CONVERT(CONCAT('Staff ',UPPER(LEFT(l.entry_type,1)),SUBSTRING(l.entry_type,2)) USING utf8mb4) COLLATE utf8mb4_unicode_ci category_name,l.amount FROM staff_ledger_entries l WHERE 1=1 {$ledger_owner_scope} {$ledger_scope} AND l.entry_date BETWEEN ? AND ?) x GROUP BY category_name ORDER BY amount DESC,category_name";
$stmt=mysqli_prepare($conn,$breakdown_sql); mysqli_stmt_bind_param($stmt,'ssss',$from,$to,$from,$to); mysqli_stmt_execute($stmt); $result=mysqli_stmt_get_result($stmt); $breakdown=[]; while($row=mysqli_fetch_assoc($result))$breakdown[]=$row;

$month_labels=[];$month_sales=[];$month_expenses=[];for($m=1;$m<=12;$m++){ $month_labels[]=date('M',mktime(0,0,0,$m,1));$month_sales[$m]=0.0;$month_expenses[$m]=0.0; }
$stmt=mysqli_prepare($conn,"SELECT m,SUM(amount) amount FROM (SELECT MONTH(i.invoice_date) m,i.total_amount amount FROM invoices i WHERE 1=1 {$invoice_owner_scope} {$invoice_scope} AND COALESCE(i.accounting_status,'posted')='posted' AND YEAR(i.invoice_date)=? UNION ALL SELECT MONTH(bi.invoice_date),bi.amount FROM booking_invoices bi LEFT JOIN booking_invoice_types bit ON bit.user_id=bi.user_id AND bit.type_key=bi.invoice_type WHERE 1=1 {$sales_owner_scope} {$sales_scope} AND bi.status='confirmed' AND COALESCE(bit.behavior,'income')='income' AND YEAR(bi.invoice_date)=?) sales GROUP BY m"); mysqli_stmt_bind_param($stmt,'ii',$year,$year);mysqli_stmt_execute($stmt);$result=mysqli_stmt_get_result($stmt);while($row=mysqli_fetch_assoc($result))$month_sales[(int)$row['m']]=(float)$row['amount'];
$stmt=mysqli_prepare($conn,"SELECT m,SUM(amount) amount FROM (SELECT MONTH(e.txn_date) m,e.amount FROM expenses e WHERE 1=1 {$expense_owner_scope} {$expense_scope} {$expense_kind_scope} AND e.approval_status='approved' AND YEAR(e.txn_date)=? UNION ALL SELECT MONTH(l.entry_date) m,l.amount FROM staff_ledger_entries l WHERE 1=1 {$ledger_owner_scope} {$ledger_scope} AND YEAR(l.entry_date)=?) x GROUP BY m");mysqli_stmt_bind_param($stmt,'ii',$year,$year);mysqli_stmt_execute($stmt);$result=mysqli_stmt_get_result($stmt);while($row=mysqli_fetch_assoc($result))$month_expenses[(int)$row['m']]=(float)$row['amount'];

$start_year=$year-4;$year_labels=[];$year_sales=[];$year_expenses=[];for($y=$start_year;$y<=$year;$y++){ $year_labels[]=(string)$y;$year_sales[$y]=0.0;$year_expenses[$y]=0.0; }
$stmt=mysqli_prepare($conn,"SELECT y,SUM(amount) amount FROM (SELECT YEAR(i.invoice_date) y,i.total_amount amount FROM invoices i WHERE 1=1 {$invoice_owner_scope} {$invoice_scope} AND COALESCE(i.accounting_status,'posted')='posted' AND YEAR(i.invoice_date) BETWEEN ? AND ? UNION ALL SELECT YEAR(bi.invoice_date),bi.amount FROM booking_invoices bi LEFT JOIN booking_invoice_types bit ON bit.user_id=bi.user_id AND bit.type_key=bi.invoice_type WHERE 1=1 {$sales_owner_scope} {$sales_scope} AND bi.status='confirmed' AND COALESCE(bit.behavior,'income')='income' AND YEAR(bi.invoice_date) BETWEEN ? AND ?) sales GROUP BY y");mysqli_stmt_bind_param($stmt,'iiii',$start_year,$year,$start_year,$year);mysqli_stmt_execute($stmt);$result=mysqli_stmt_get_result($stmt);while($row=mysqli_fetch_assoc($result))$year_sales[(int)$row['y']]=(float)$row['amount'];
$stmt=mysqli_prepare($conn,"SELECT y,SUM(amount) amount FROM (SELECT YEAR(e.txn_date) y,e.amount FROM expenses e WHERE 1=1 {$expense_owner_scope} {$expense_scope} {$expense_kind_scope} AND e.approval_status='approved' AND YEAR(e.txn_date) BETWEEN ? AND ? UNION ALL SELECT YEAR(l.entry_date) y,l.amount FROM staff_ledger_entries l WHERE 1=1 {$ledger_owner_scope} {$ledger_scope} AND YEAR(l.entry_date) BETWEEN ? AND ?) x GROUP BY y");mysqli_stmt_bind_param($stmt,'iiii',$start_year,$year,$start_year,$year);mysqli_stmt_execute($stmt);$result=mysqli_stmt_get_result($stmt);while($row=mysqli_fetch_assoc($result))$year_expenses[(int)$row['y']]=(float)$row['amount'];
$cost_of_sales=0.0; $gross_profit=$total_sales;
if($profit_with_cost){
    $cost_line="CASE WHEN ii.cost_amount<>0 THEN ii.cost_amount ELSE ii.quantity*COALESCE(p.purchase_price,0) END";
    $cost_from="FROM invoice_items ii INNER JOIN invoices i ON i.id=ii.invoice_id LEFT JOIN products p ON p.id=ii.product_id WHERE 1=1 {$invoice_owner_scope} {$invoice_scope} AND COALESCE(i.accounting_status,'posted')='posted'";
    $cost_of_sales=report_scalar($conn,"SELECT COALESCE(SUM({$cost_line}),0) amount {$cost_from} AND i.invoice_date BETWEEN ? AND ?",'ss',[$from,$to]);
    $gross_profit=$total_sales-$cost_of_sales;
    $net_profit=$gross_profit-$total_expenses;
    // Charts: the expense bar holds the purchase price of the goods sold as well, so
    // sales minus expense is the net profit of each month / year.
    $stmt=mysqli_prepare($conn,"SELECT MONTH(i.invoice_date) m,SUM({$cost_line}) amount {$cost_from} AND YEAR(i.invoice_date)=? GROUP BY m"); mysqli_stmt_bind_param($stmt,'i',$year); mysqli_stmt_execute($stmt); $result=mysqli_stmt_get_result($stmt); while($row=mysqli_fetch_assoc($result))$month_expenses[(int)$row['m']]+=(float)$row['amount'];
    $stmt=mysqli_prepare($conn,"SELECT YEAR(i.invoice_date) y,SUM({$cost_line}) amount {$cost_from} AND YEAR(i.invoice_date) BETWEEN ? AND ? GROUP BY y"); mysqli_stmt_bind_param($stmt,'ii',$start_year,$year); mysqli_stmt_execute($stmt); $result=mysqli_stmt_get_result($stmt); while($row=mysqli_fetch_assoc($result)) if(isset($year_expenses[(int)$row['y']])) $year_expenses[(int)$row['y']]+=(float)$row['amount'];
}
// Travel Agency: profit = travel sales − travel purchase cost − other expenses.
// Sales and cost count on the booking date, refunds and reissues on their own dates.
// Vendor payments are not counted again as expenses (the purchase cost already is).
if($profit_company_type==='Travel Agency'){
    require_once '../includes/travel_booking_helper.php';
    if(travel_booking_schema($conn)){
        $month_start=$from; $month_end=$to;
        $vendor_paid_sql="SELECT %s AS p, COALESCE(SUM(amount),0) AS amount FROM expenses WHERE user_id=".(int)$user_id." AND approval_status='approved' AND source_type IN ('purchase_payment','supplier_payment') AND txn_date BETWEEN '%s' AND '%s' GROUP BY p";
        $vendor_paid=mysqli_fetch_assoc(mysqli_query($conn,sprintf($vendor_paid_sql,"'1'",$month_start,$month_end)));
        $travel_cost=travel_sales_total($conn,$user_id,$month_start,$month_end,'cost');
        $travel_commission=travel_sales_total($conn,$user_id,$month_start,$month_end,'commission');
        $total_sales+=travel_sales_total($conn,$user_id,$month_start,$month_end);
        $total_expenses+=$travel_cost+$travel_commission-(float)($vendor_paid['amount']??0);
        $net_profit=$total_sales-$total_expenses;
        // Expense breakdown: one "Travel purchase cost" line instead of the vendor payments.
        $breakdown=array_values(array_filter($breakdown,static fn($row)=>!preg_match('/(Supplier|Vendor) Payment$/i',(string)$row['category_name'])));
        if($travel_commission!=0) array_unshift($breakdown,['category_name'=>'Agent commission','amount'=>$travel_commission]);
        if($travel_cost!=0) array_unshift($breakdown,['category_name'=>'Travel purchase cost','amount'=>$travel_cost]);
        foreach(travel_sales_by_period($conn,$user_id,$year.'-01-01',$year.'-12-31','month') as $period=>$amount){ $month_sales[(int)substr($period,5,2)]+=$amount; }
        foreach(['cost','commission'] as $part) foreach(travel_sales_by_period($conn,$user_id,$year.'-01-01',$year.'-12-31','month',$part) as $period=>$amount){ $month_expenses[(int)substr($period,5,2)]+=$amount; }
        $rows=mysqli_query($conn,sprintf($vendor_paid_sql,'MONTH(txn_date)',$year.'-01-01',$year.'-12-31')); while($rows&&($row=mysqli_fetch_assoc($rows))) $month_expenses[(int)$row['p']]-=(float)$row['amount'];
        foreach(travel_sales_by_period($conn,$user_id,$start_year.'-01-01',$year.'-12-31','year') as $period=>$amount){ if(isset($year_sales[(int)$period])) $year_sales[(int)$period]+=$amount; }
        foreach(['cost','commission'] as $part) foreach(travel_sales_by_period($conn,$user_id,$start_year.'-01-01',$year.'-12-31','year',$part) as $period=>$amount){ if(isset($year_expenses[(int)$period])) $year_expenses[(int)$period]+=$amount; }
        $rows=mysqli_query($conn,sprintf($vendor_paid_sql,'YEAR(txn_date)',$start_year.'-01-01',$year.'-12-31')); while($rows&&($row=mysqli_fetch_assoc($rows))) if(isset($year_expenses[(int)$row['p']])) $year_expenses[(int)$row['p']]-=(float)$row['amount'];
    }
}
?>
<section class="content"><div class="container-fluid">
<div class="card report-filter"><div class="card-header"><h3 class="card-title"><i class="fas fa-coins mr-2"></i>Profit Report</h3></div><div class="card-body">
<form method="get"><div class="row align-items-end">
    <div class="col-md-4 form-group"><label for="pr-from">From</label><input id="pr-from" type="date" name="from" class="form-control" value="<?=$h($from)?>" max="<?=date('Y-m-d')?>"></div>
    <div class="col-md-4 form-group"><label for="pr-to">To</label><input id="pr-to" type="date" name="to" class="form-control" value="<?=$h($to)?>" max="<?=date('Y-m-d')?>"></div>
    <div class="col-md-4 form-group"><button class="btn btn-primary btn-block"><i class="fas fa-search mr-1"></i>Search</button></div>
</div></form>
<div class="d-flex flex-wrap" style="gap:6px">
<?php
// Quick ranges; the one matching the dates on screen is highlighted.
foreach ([
    'Today' => [date('Y-m-d'), date('Y-m-d')],
    'Yesterday' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
    'Last 7 days' => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
    'This Month' => [date('Y-m-01'), date('Y-m-d')],
    'Last Month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    'This Year' => [date('Y-01-01'), date('Y-m-d')],
] as $quick_label => [$quick_from, $quick_to]) { $quick_active = $quick_from === $from && $quick_to === $to; ?>
    <a class="btn btn-sm <?= $quick_active ? 'btn-secondary active' : 'btn-outline-secondary' ?>" href="<?= $h($link($quick_from, $quick_to)) ?>"><?= $h($quick_label) ?></a>
<?php } ?>
    <button type="button" onclick="window.print()" class="btn btn-sm btn-info ml-auto"><i class="fas fa-print mr-1"></i>Print</button>
</div>
</div></div>
<div class="report-print-heading d-none">
    <div class="print-brand">YOU2 <span>TECHNOLOGIES</span></div>
    <div class="print-document-title">Profit Report</div>
    <div class="print-report-meta"><span>Report period: <?=$h(app_date($from).' – '.app_date($to))?></span><span>Generated: <?=date('d M Y, h:i A')?></span></div>
</div>
<?php if($profit_with_cost){ ?>
<div class="row profit-cards">
    <div class="col-6 col-lg"><div class="small-box bg-primary"><div class="inner"><h3>BDT <?=number_format($total_sales,2)?></h3><p>Total Sales</p></div><div class="icon"><i class="fas fa-chart-line"></i></div></div></div>
    <div class="col-6 col-lg"><div class="small-box bg-secondary"><div class="inner"><h3>BDT <?=number_format($cost_of_sales,2)?></h3><p>Purchase Price of Goods Sold</p></div><div class="icon"><i class="fas fa-boxes"></i></div></div></div>
    <div class="col-6 col-lg"><div class="small-box bg-<?=$gross_profit>=0?'info':'warning'?>"><div class="inner"><h3>BDT <?=number_format($gross_profit,2)?></h3><p>Gross Profit</p></div><div class="icon"><i class="fas fa-balance-scale"></i></div></div></div>
    <div class="col-6 col-lg"><div class="small-box bg-danger"><div class="inner"><h3>BDT <?=number_format($total_expenses,2)?></h3><p>Total Expenses</p></div><div class="icon"><i class="fas fa-receipt"></i></div></div></div>
    <div class="col-12 col-lg"><div class="small-box bg-<?=$net_profit>=0?'success':'warning'?>"><div class="inner"><h3>BDT <?=number_format($net_profit,2)?></h3><p>Net Profit</p></div><div class="icon"><i class="fas fa-wallet"></i></div></div></div>
</div>
<?php }else{ ?>
<div class="row"><div class="col-md-4"><div class="small-box bg-primary"><div class="inner"><h3>BDT <?=number_format($total_sales,2)?></h3><p>Total Sales</p></div><div class="icon"><i class="fas fa-chart-line"></i></div></div></div><div class="col-md-4"><div class="small-box bg-danger"><div class="inner"><h3>BDT <?=number_format($total_expenses,2)?></h3><p>Total Expenses</p></div><div class="icon"><i class="fas fa-receipt"></i></div></div></div><div class="col-md-4"><div class="small-box bg-<?=$net_profit>=0?'success':'warning'?>"><div class="inner"><h3>BDT <?=number_format($net_profit,2)?></h3><p>Net Profit</p></div><div class="icon"><i class="fas fa-wallet"></i></div></div></div></div>
<?php } ?>
<div class="row"><div class="col-md-6"><div class="card"><div class="card-header"><h3 class="card-title">Monthly Sales vs Expense — <?=$year?></h3></div><div class="card-body"><div style="height:330px"><canvas id="monthlyChart"></canvas></div></div></div></div><div class="col-md-6"><div class="card"><div class="card-header"><h3 class="card-title">Yearly Sales vs Expense</h3></div><div class="card-body"><div style="height:330px"><canvas id="yearlyChart"></canvas></div></div></div></div></div>
<div class="row"><div class="col-md-6"><div class="card"><div class="card-header"><h3 class="card-title">Profit Calculation</h3></div><div class="card-body p-0"><table class="table table-bordered mb-0"><tbody><tr><th>Total Sales</th><td class="text-right">BDT <?=number_format($total_sales,2)?></td></tr><?php if($profit_with_cost){ ?><tr><th>Less: Purchase Price of Goods Sold</th><td class="text-right text-danger">BDT <?=number_format($cost_of_sales,2)?></td></tr><tr class="<?=$gross_profit>=0?'table-info':'table-warning'?>"><th>Gross Profit</th><th class="text-right">BDT <?=number_format($gross_profit,2)?></th></tr><?php } ?><tr><th>Less: Total Expenses</th><td class="text-right text-danger">BDT <?=number_format(abs($total_expenses),2)?></td></tr><tr class="<?=$net_profit>=0?'table-success':'table-danger'?>"><th>Net Profit</th><th class="text-right">BDT <?=number_format($profit_with_cost?$net_profit:abs($net_profit),2)?></th></tr></tbody></table><?php if($profit_with_cost){ ?><div class="small text-muted p-2">Purchase price is the stock cost (FIFO) of each item sold; items without a recorded cost use the product's purchase price. Supplier / purchase payments are not counted as expenses here, as that stock is already in the purchase price.</div><?php } ?></div></div></div><div class="col-md-6"><div class="card"><div class="card-header"><h3 class="card-title">Expense Breakdown</h3></div><div class="card-body p-0"><table class="table table-bordered mb-0"><thead><tr><th>Category</th><th class="text-right">Amount</th></tr></thead><tbody><?php foreach($breakdown as $item){?><tr><td><?=htmlspecialchars($item['category_name'])?></td><td class="text-right">BDT <?=number_format($item['amount'],2)?></td></tr><?php }if(empty($breakdown)){?><tr><td colspan="2" class="text-center text-muted">No expenses in this period.</td></tr><?php }?></tbody><tfoot><tr><th>Total Expenses</th><th class="text-right">BDT <?=number_format($total_expenses,2)?></th></tr></tfoot></table></div></div></div></div>
</div></section>
<?php $page_script='<script>var chartOptions={responsive:true,maintainAspectRatio:false,scales:{yAxes:[{ticks:{beginAtZero:true}}]}};new Chart(document.getElementById("monthlyChart"),{type:"bar",data:{labels:'.json_encode($month_labels).',datasets:[{label:"Sales",data:'.json_encode(array_values($month_sales)).',backgroundColor:"#1677e8"},{label:"Expense",data:'.json_encode(array_values($month_expenses)).',backgroundColor:"#dc3545"}]},options:chartOptions});new Chart(document.getElementById("yearlyChart"),{type:"bar",data:{labels:'.json_encode($year_labels).',datasets:[{label:"Sales",data:'.json_encode(array_values($year_sales)).',backgroundColor:"#28a745"},{label:"Expense",data:'.json_encode(array_values($year_expenses)).',backgroundColor:"#ffc107"}]},options:chartOptions});</script><style>@page{size:A4 landscape;margin:10mm}@media print{html,body{background:#fff!important;color:#14213d!important;font-size:10px!important}.main-header,.main-sidebar,.main-footer,.report-filter,.btn{display:none!important}.content-wrapper,.content,.container-fluid{margin:0!important;padding:0!important;width:100%!important;min-height:0!important}.report-print-heading{display:block!important;border-bottom:2px solid #1677e8!important;padding:0 0 10px!important;margin:0 0 12px!important;text-align:center!important}.report-print-heading h2{font-size:20px!important;letter-spacing:1px!important;margin:0 0 4px!important;color:#0b2e59!important}.report-print-heading strong{font-size:11px!important;color:#5d6b82!important}.row{display:flex!important;flex-wrap:wrap!important;margin:0 -4px!important}.row>.col-md-4{flex:0 0 33.333%!important;max-width:33.333%!important;padding:0 4px!important}.profit-cards{flex-wrap:nowrap!important}.profit-cards>div{flex:1 1 0!important;max-width:none!important;padding:0 4px!important}.row>.col-md-6{flex:0 0 50%!important;max-width:50%!important;padding:0 4px!important}.card{box-shadow:none!important;border:1px solid #d9e2ef!important;border-radius:0!important;margin-bottom:8px!important;break-inside:avoid!important}.card-header{background:#f3f7fc!important;border-bottom:1px solid #d9e2ef!important;padding:7px 10px!important;font-weight:700!important}.card-body{padding:8px!important}.small-box{background:#fff!important;border:1px solid #d9e2ef!important;box-shadow:none!important;min-height:auto!important;margin-bottom:8px!important;color:#14213d!important}.small-box h3,.small-box p{color:#14213d!important}.small-box h3{font-size:17px!important;margin:0!important}.small-box p{font-size:10px!important;margin:3px 0 0!important}.small-box .icon{display:none!important}canvas{max-height:150px!important}table{width:100%!important;font-size:9px!important;border-collapse:collapse!important}th{background:#eaf2fb!important;color:#12375d!important}th,td{padding:5px!important;border:1px solid #d9e2ef!important}.bg-success,.bg-danger,.bg-warning{background:#fff!important;color:#14213d!important}}</style>';require_once '../includes/footer.php';?>
