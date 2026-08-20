<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/invoice_reference_helper.php';
require_once '../includes/staff_helper.php';
require_once '../includes/restaurant_table_helper.php';
$user_id = (int)$_SESSION['user_id'];
ensure_invoice_posting_columns($conn); ensure_invoice_reference_columns($conn); ensure_staff_table($conn); ensure_restaurant_tables_table($conn);
$table_system_is_enabled = table_system_enabled($conn, $user_id);
if (!$table_system_is_enabled) { header('Location: ../dashboard.php'); exit; }
$from_date = trim($_GET['from_date'] ?? '');
$to_date = trim($_GET['to_date'] ?? '');

function staff_table_report_result($conn, $sql, $user_id, $from_date, $to_date) {
    $date_filter = '';
    if($from_date !== '' && $to_date !== ''){ $date_filter = ' AND i.invoice_date BETWEEN ? AND ?'; }
    elseif($from_date !== ''){ $date_filter = ' AND i.invoice_date >= ?'; }
    elseif($to_date !== ''){ $date_filter = ' AND i.invoice_date <= ?'; }
    $sql = str_replace('/*DATE_FILTER*/', $date_filter, $sql);
    $stmt=mysqli_prepare($conn,$sql);
    if($from_date !== '' && $to_date !== ''){ mysqli_stmt_bind_param($stmt,'iss',$user_id,$from_date,$to_date); }
    elseif($from_date !== '' || $to_date !== ''){ $date=$from_date !== '' ? $from_date : $to_date; mysqli_stmt_bind_param($stmt,'is',$user_id,$date); }
    else{ mysqli_stmt_bind_param($stmt,'i',$user_id); }
    mysqli_stmt_execute($stmt); return mysqli_stmt_get_result($stmt);
}

$base = " WHERE i.user_id=? AND i.accounting_status='posted'";
$system_filter = $table_system_is_enabled ? '' : ' AND 1=0';
$staff_sales = staff_table_report_result($conn, "SELECT s.staff_code, s.name, COUNT(i.id) AS invoice_count, COALESCE(SUM(i.total_amount),0) AS total_sale FROM invoices i INNER JOIN staff s ON s.id=i.staff_id AND s.user_id=i.user_id INNER JOIN restaurant_tables rt ON rt.id=i.restaurant_table_id AND rt.user_id=i.user_id AND rt.status='active'" . $base . $system_filter . " /*DATE_FILTER*/ GROUP BY s.id, s.staff_code, s.name ORDER BY total_sale DESC", $user_id, $from_date, $to_date);
$table_sales = staff_table_report_result($conn, "SELECT rt.table_name, s.staff_code, s.name AS staff_name, COUNT(i.id) AS invoice_count, COALESCE(SUM(i.total_amount),0) AS total_sale FROM invoices i INNER JOIN restaurant_tables rt ON rt.id=i.restaurant_table_id AND rt.user_id=i.user_id AND rt.status='active' LEFT JOIN staff s ON s.id=i.staff_id AND s.user_id=i.user_id" . $base . $system_filter . " /*DATE_FILTER*/ GROUP BY rt.id, rt.table_name, s.staff_code, s.name ORDER BY total_sale DESC, rt.table_name", $user_id, $from_date, $to_date);
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<section class="content"><div class="container-fluid"><div class="card"><div class="card-body"><form method="get"><div class="row"><div class="col-md-3"><label>From Date</label><input type="date" name="from_date" class="form-control" value="<?=htmlspecialchars($from_date)?>"></div><div class="col-md-3"><label>To Date</label><input type="date" name="to_date" class="form-control" value="<?=htmlspecialchars($to_date)?>"></div><div class="col-md-2"><label>&nbsp;</label><button class="btn btn-primary btn-block">Search</button></div><div class="col-md-2"><label>&nbsp;</label><button type="button" class="btn btn-info btn-block" onclick="window.print()">Print</button></div><div class="col-md-2"><label>&nbsp;</label><a href="staff_table_sales_report.php" class="btn btn-secondary btn-block">Reset</a></div></div></form></div></div>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-user-tie mr-2"></i>Staff Total Sale Report</h3></div><div class="card-body"><table id="example1" class="table table-bordered table-striped"><thead><tr><th>Staff ID</th><th>Staff Name</th><th>Invoices</th><th>Total Sale</th></tr></thead><tbody><?php $staff_total=0; while($row=mysqli_fetch_assoc($staff_sales)){ $staff_total+=(float)$row['total_sale']; ?><tr><td><?=htmlspecialchars($row['staff_code'])?></td><td><?=htmlspecialchars($row['name'])?></td><td><?=$row['invoice_count']?></td><td>BDT <?=number_format((float)$row['total_sale'],2)?></td></tr><?php } ?></tbody><tfoot><tr><th colspan="3" class="text-right">Total</th><th>BDT <?=number_format($staff_total,2)?></th></tr></tfoot></table></div></div>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-chair mr-2"></i>Table-wise Sale Report</h3></div><div class="card-body"><table id="example2" class="table table-bordered table-striped"><thead><tr><th>Table</th><th>Staff</th><th>Invoices</th><th>Total Sale</th></tr></thead><tbody><?php $table_total=0; while($row=mysqli_fetch_assoc($table_sales)){ $table_total+=(float)$row['total_sale']; ?><tr><td><?=htmlspecialchars($row['table_name'])?></td><td><?=htmlspecialchars($row['staff_name'] ?: '-')?><?= $row['staff_code'] ? ' (' . htmlspecialchars($row['staff_code']) . ')' : '' ?></td><td><?=$row['invoice_count']?></td><td>BDT <?=number_format((float)$row['total_sale'],2)?></td></tr><?php } ?></tbody><tfoot><tr><th colspan="3" class="text-right">Total</th><th>BDT <?=number_format($table_total,2)?></th></tr></tfoot></table></div></div></div></section>
<?php require_once '../includes/footer.php'; ?>
