<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_category_helper.php';
require_once '../includes/staff_ledger_helper.php';
require_once '../includes/printing_helper.php';

$user_id = (int)$_SESSION['user_id'];
ensure_invoice_posting_columns($conn);
ensure_fifo_inventory_tables($conn);
ensure_product_category_type_column($conn);
ensure_staff_ledger_table($conn);
$company_profile = printing_company_profile_data($conn);
$from_date = trim($_GET['from_date'] ?? date('Y-m-01'));
$to_date = trim($_GET['to_date'] ?? date('Y-m-t'));

function profit_report_date_filter($column, $from_date, $to_date, &$types, &$values)
{
    if($from_date !== '' && $to_date !== ''){ $types .= 'ss'; $values[]=$from_date; $values[]=$to_date; return " AND {$column} BETWEEN ? AND ?"; }
    if($from_date !== ''){ $types .= 's'; $values[]=$from_date; return " AND {$column} >= ?"; }
    if($to_date !== ''){ $types .= 's'; $values[]=$to_date; return " AND {$column} <= ?"; }
    return '';
}
function profit_report_result($conn, $sql, $types, $values)
{
    $stmt=mysqli_prepare($conn,$sql); if(!$stmt){ return false; }
    $bind_values = [$stmt, $types];
    foreach($values as $key => $value){
        $bind_values[] = &$values[$key];
    }
    call_user_func_array('mysqli_stmt_bind_param', $bind_values);
    mysqli_stmt_execute($stmt);
    return mysqli_stmt_get_result($stmt);
}

$types='i'; $values=[$user_id];
$filter=profit_report_date_filter('i.invoice_date',$from_date,$to_date,$types,$values);
$sales_sql="SELECT i.id,i.invoice_no,i.invoice_date,i.customer_name,
COALESCE(SUM(CASE WHEN COALESCE(c.category_type,'non_stock')='stock_product' THEN ii.total_price ELSE 0 END),0) stock_sales,
COALESCE(SUM(CASE WHEN COALESCE(c.category_type,'non_stock')='stock_product' THEN ii.cost_amount ELSE 0 END),0) stock_cost,
COALESCE(SUM(CASE WHEN COALESCE(c.category_type,'non_stock')='non_stock' THEN ii.total_price ELSE 0 END),0) non_stock_sales
FROM invoices i LEFT JOIN invoice_items ii ON ii.invoice_id=i.id
LEFT JOIN products p ON p.id=ii.product_id AND p.user_id=i.user_id
LEFT JOIN product_categories c ON c.id=p.category_id AND c.user_id=i.user_id
WHERE i.user_id=? AND i.accounting_status='posted' {$filter}
GROUP BY i.id,i.invoice_no,i.invoice_date,i.customer_name ORDER BY i.invoice_date DESC,i.id DESC";
$result=profit_report_result($conn,$sales_sql,$types,$values);
$invoices=[]; $stock_sales=0.0; $stock_cost=0.0; $non_stock_sales=0.0;
while($result && ($row=mysqli_fetch_assoc($result))){
    $row['gross_profit']=(float)$row['stock_sales']-(float)$row['stock_cost']+(float)$row['non_stock_sales'];
    $invoices[]=$row; $stock_sales+=(float)$row['stock_sales']; $stock_cost+=(float)$row['stock_cost']; $non_stock_sales+=(float)$row['non_stock_sales'];
}

$expense_types='i'; $expense_values=[$user_id];
$expense_filter=profit_report_date_filter('e.txn_date',$from_date,$to_date,$expense_types,$expense_values);
$ledger_types='i'; $ledger_values=[$user_id];
$ledger_filter=profit_report_date_filter('l.entry_date',$from_date,$to_date,$ledger_types,$ledger_values);
$expense_sql="SELECT category_name,COALESCE(SUM(total_amount),0) total_amount FROM (
    SELECT CONVERT(CONCAT('Expense: ',COALESCE(c.category_name,'Uncategorized')) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS category_name,e.amount total_amount
    FROM expenses e LEFT JOIN categories c ON c.id=e.category_id
    WHERE e.user_id=? AND e.approval_status='approved' {$expense_filter}
    UNION ALL
    SELECT CONVERT(CONCAT('Staff ',UPPER(LEFT(l.entry_type,1)),SUBSTRING(l.entry_type,2)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS category_name,l.amount total_amount
    FROM staff_ledger_entries l WHERE l.user_id=? {$ledger_filter}
) expense_rows GROUP BY category_name ORDER BY total_amount DESC,category_name ASC";
$expense_result=profit_report_result($conn,$expense_sql,$expense_types.$ledger_types,array_merge($expense_values,$ledger_values));
$expenses=[]; $total_expenses=0.0;
while($expense_result && ($expense=mysqli_fetch_assoc($expense_result))){ $expenses[]=$expense; $total_expenses+=(float)$expense['total_amount']; }
$total_sales=$stock_sales+$non_stock_sales; $stock_gross_profit=$stock_sales-$stock_cost; $gross_profit=$stock_gross_profit+$non_stock_sales; $net_profit=$gross_profit-$total_expenses;

require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<section class="content"><div class="container-fluid">
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-coins mr-2"></i>Monthly Profit Report</h3></div><div class="card-body"><form method="get"><div class="row align-items-end"><div class="col-md-3"><label>From Date</label><input type="date" name="from_date" class="form-control" value="<?=htmlspecialchars($from_date)?>"></div><div class="col-md-3"><label>To Date</label><input type="date" name="to_date" class="form-control" value="<?=htmlspecialchars($to_date)?>"></div><div class="col-md-2"><button class="btn btn-primary btn-block">Search</button></div><div class="col-md-2"><button type="button" class="btn btn-info btn-block" onclick="window.print()">Print</button></div><div class="col-md-2"><a href="profit_report.php" class="btn btn-secondary btn-block">This Month</a></div></div></form></div></div>
<div class="report-print-header d-none text-center mb-3"><h2><?=htmlspecialchars($company_profile['name'])?></h2><div><?=htmlspecialchars($company_profile['address'])?></div><strong>Monthly Profit Report</strong><br><small><?=htmlspecialchars(app_date($from_date))?> to <?=htmlspecialchars(app_date($to_date))?></small></div>
<div class="row"><div class="col-md-3"><div class="small-box bg-info"><div class="inner"><h3>BDT <?=number_format($total_sales,2)?></h3><p>Total Sales</p></div><div class="icon"><i class="fas fa-chart-line"></i></div></div></div><div class="col-md-3"><div class="small-box bg-warning"><div class="inner"><h3>BDT <?=number_format($stock_cost,2)?></h3><p>Stock Product Cost</p></div><div class="icon"><i class="fas fa-boxes"></i></div></div></div><div class="col-md-3"><div class="small-box bg-success"><div class="inner"><h3>BDT <?=number_format($gross_profit,2)?></h3><p>Gross Profit</p></div><div class="icon"><i class="fas fa-plus-circle"></i></div></div></div><div class="col-md-3"><div class="small-box bg-<?=$net_profit>=0?'primary':'danger'?>"><div class="inner"><h3>BDT <?=number_format($net_profit,2)?></h3><p>Net Profit after Expenses</p></div><div class="icon"><i class="fas fa-wallet"></i></div></div></div></div>
<div class="row"><div class="col-md-7"><div class="card"><div class="card-header"><h3 class="card-title">Profit Calculation</h3></div><div class="card-body p-0"><table class="table table-bordered mb-0"><tbody><tr><th>Stock Product Sales</th><td class="text-right">BDT <?=number_format($stock_sales,2)?></td></tr><tr><th>Less: Actual FIFO Stock Cost</th><td class="text-right text-danger">− BDT <?=number_format($stock_cost,2)?></td></tr><tr><th>Stock Product Gross Profit</th><td class="text-right">BDT <?=number_format($stock_gross_profit,2)?></td></tr><tr><th>Non-stock Product Profit <small class="text-muted">(100% of sale)</small></th><td class="text-right">BDT <?=number_format($non_stock_sales,2)?></td></tr><tr class="table-success"><th>Gross Profit</th><th class="text-right">BDT <?=number_format($gross_profit,2)?></th></tr><tr><th>Less: Approved Expenses</th><td class="text-right text-danger">− BDT <?=number_format($total_expenses,2)?></td></tr><tr class="<?=$net_profit>=0?'table-primary':'table-danger'?>"><th>Net Profit</th><th class="text-right">BDT <?=number_format($net_profit,2)?></th></tr></tbody></table></div></div></div><div class="col-md-5"><div class="card"><div class="card-header"><h3 class="card-title">Expense Breakdown</h3></div><div class="card-body p-0"><table class="table table-bordered mb-0"><thead><tr><th>Category</th><th class="text-right">Amount</th></tr></thead><tbody><?php if(!$expenses){ ?><tr><td colspan="2" class="text-center text-muted">No approved expenses in this period.</td></tr><?php } foreach($expenses as $expense){ ?><tr><td><?=htmlspecialchars($expense['category_name'])?></td><td class="text-right">BDT <?=number_format((float)$expense['total_amount'],2)?></td></tr><?php } ?></tbody><tfoot><tr><th>Total Expenses</th><th class="text-right">BDT <?=number_format($total_expenses,2)?></th></tr></tfoot></table></div></div></div></div>
<div class="card"><div class="card-header"><h3 class="card-title">Invoice-wise Gross Profit</h3></div><div class="card-body"><table id="example1" class="table table-bordered table-striped"><thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th>Stock Sales</th><th>FIFO Cost</th><th>Non-stock Profit</th><th>Gross Profit</th></tr></thead><tbody><?php if(!$invoices){ ?><tr><td colspan="7" class="text-center text-muted">No posted sales found for this period.</td></tr><?php } foreach($invoices as $row){ ?><tr><td><?=htmlspecialchars($row['invoice_no'])?></td><td><?=htmlspecialchars(app_date($row['invoice_date']))?></td><td><?=htmlspecialchars($row['customer_name'])?></td><td>BDT <?=number_format((float)$row['stock_sales'],2)?></td><td>BDT <?=number_format((float)$row['stock_cost'],2)?></td><td>BDT <?=number_format((float)$row['non_stock_sales'],2)?></td><td class="font-weight-bold">BDT <?=number_format((float)$row['gross_profit'],2)?></td></tr><?php } ?></tbody><tfoot><tr><th colspan="3" class="text-right">Total</th><th>BDT <?=number_format($stock_sales,2)?></th><th>BDT <?=number_format($stock_cost,2)?></th><th>BDT <?=number_format($non_stock_sales,2)?></th><th>BDT <?=number_format($gross_profit,2)?></th></tr></tfoot></table></div></div>
</div></section>
<?php $page_script='<style>@media print{.main-header,.main-sidebar,.main-footer,.card form,.dataTables_length,.dataTables_filter,.dataTables_info,.dataTables_paginate{display:none!important}.content-wrapper,.content,.container-fluid{margin:0!important;padding:0!important;width:100%!important}.card{box-shadow:none!important;border:0!important}.report-print-header{display:block!important}table{font-size:10px!important}}</style>'; require_once '../includes/footer.php'; ?>
