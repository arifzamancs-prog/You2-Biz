<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/fifo_inventory_helper.php';
$user_id = (int)$_SESSION['user_id'];
$from = (string)($_GET['from'] ?? date('Y-m-01'));
$to = (string)($_GET['to'] ?? date('Y-m-d'));
foreach ([$from,$to] as $date) {
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) { http_response_code(400); exit('Invalid date.'); }
}
if ($from > $to) { http_response_code(400); exit('From date must precede To date.'); }
$scope = branch_scope_sql($conn, 'i');
$stmt = mysqli_prepare($conn, "SELECT i.id,i.invoice_no,i.invoice_date,i.total_amount,i.paid_amount,i.due_amount,b.branch_name,COALESCE(SUM(ii.cost_amount),0) AS fifo_cost FROM invoices i LEFT JOIN invoice_items ii ON ii.invoice_id=i.id LEFT JOIN branches b ON b.id=i.branch_id AND b.user_id=i.user_id WHERE i.user_id=? AND i.accounting_status='posted' AND i.invoice_date BETWEEN ? AND ? {$scope} GROUP BY i.id,i.invoice_no,i.invoice_date,i.total_amount,i.paid_amount,i.due_amount,b.branch_name ORDER BY i.invoice_date DESC,i.id DESC");
mysqli_stmt_bind_param($stmt,'iss',$user_id,$from,$to); mysqli_stmt_execute($stmt); $rows=mysqli_stmt_get_result($stmt);
$totals=['sales'=>0,'cost'=>0,'paid'=>0,'due'=>0];
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title">Stock Sales &amp; FIFO Gross Profit — <?= htmlspecialchars(current_branch_label($conn)); ?></h3><button class="btn btn-primary btn-sm float-right d-print-none" onclick="window.print()">Print</button></div><div class="card-body">
<form method="get" class="form-inline mb-3 d-print-none"><label class="mr-2">From <input class="form-control ml-2" type="date" name="from" value="<?= htmlspecialchars($from); ?>" required></label><label class="mr-2">To <input class="form-control ml-2" type="date" name="to" value="<?= htmlspecialchars($to); ?>" required></label><button class="btn btn-primary">View</button></form>
<div class="table-responsive"><table class="table table-bordered table-striped"><thead><tr><th>Invoice</th><th>Date</th><th>Branch</th><th>Sales</th><th>FIFO Cost</th><th>Gross Profit</th><th>Paid</th><th>Due</th></tr></thead><tbody>
<?php while($row=mysqli_fetch_assoc($rows)){ $totals['sales']+=(float)$row['total_amount']; $totals['cost']+=(float)$row['fifo_cost']; $totals['paid']+=(float)$row['paid_amount']; $totals['due']+=(float)$row['due_amount']; ?>
<tr><td><a href="../sales/print_invoice.php?id=<?= (int)$row['id']; ?>"><?= htmlspecialchars($row['invoice_no']); ?></a></td><td><?= htmlspecialchars(app_date($row['invoice_date'])); ?></td><td><?= htmlspecialchars($row['branch_name'] ?? 'Head Office'); ?></td><td><?= number_format($row['total_amount'],2); ?></td><td><?= number_format($row['fifo_cost'],2); ?></td><td><?= number_format($row['total_amount']-$row['fifo_cost'],2); ?></td><td><?= number_format($row['paid_amount'],2); ?></td><td><?= number_format($row['due_amount'],2); ?></td></tr>
<?php } ?></tbody><tfoot><tr><th colspan="3">Total</th><th><?= number_format($totals['sales'],2); ?></th><th><?= number_format($totals['cost'],2); ?></th><th><?= number_format($totals['sales']-$totals['cost'],2); ?></th><th><?= number_format($totals['paid'],2); ?></th><th><?= number_format($totals['due'],2); ?></th></tr></tfoot></table></div>
</div></div>
<?php require_once '../includes/footer.php'; ?>
