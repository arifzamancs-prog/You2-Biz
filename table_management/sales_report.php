<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/restaurant_table_helper.php';
require_once '../includes/invoice_reference_helper.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/staff_helper.php';

$user_id = (int)$_SESSION['user_id'];
ensure_staff_table($conn);
ensure_restaurant_tables_table($conn);
ensure_invoice_reference_columns($conn);
ensure_invoice_posting_columns($conn);

if (!table_system_enabled($conn, $user_id)) {
    header('Location: ../dashboard.php');
    exit;
}

$today = date('Y-m-d');
$date_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_from'] ?? '')) ? $_GET['date_from'] : date('Y-m-01');
$date_to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_to'] ?? '')) ? $_GET['date_to'] : $today;
if ($date_to < $date_from) {
    $date_to = $date_from;
}
$staff_id = max(0, (int)($_GET['staff_id'] ?? 0));

$staff_stmt = mysqli_prepare($conn, "SELECT id, name, staff_code FROM staff WHERE user_id=? ORDER BY name ASC");
mysqli_stmt_bind_param($staff_stmt, 'i', $user_id);
mysqli_stmt_execute($staff_stmt);
$staff_members = mysqli_stmt_get_result($staff_stmt);

$where = "i.user_id=? AND i.invoice_date BETWEEN ? AND ? AND (i.accounting_status IS NULL OR i.accounting_status<>'void')";
if ($staff_id > 0) $where .= ' AND i.staff_id=' . $staff_id;
$sql = "SELECT i.invoice_no, i.invoice_date, i.total_amount,
               s.name AS staff_name, s.staff_code, rt.table_name
        FROM invoices i
        LEFT JOIN staff s ON s.id=i.staff_id AND s.user_id=i.user_id
        LEFT JOIN restaurant_tables rt ON rt.id=i.restaurant_table_id AND rt.user_id=i.user_id
        WHERE {$where}
        ORDER BY i.invoice_date DESC, i.id DESC";
$report_stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($report_stmt, 'iss', $user_id, $date_from, $date_to);
mysqli_stmt_execute($report_stmt);
$report_rows = mysqli_stmt_get_result($report_stmt);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<div class="card table-sales-report">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Staff Sales Report</h3>
        <div class="card-tools no-print"><button type="button" class="btn btn-secondary btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print Report</button></div>
    </div>
    <div class="card-body">
        <form method="get" class="no-print mb-4">
            <div class="form-row align-items-end">
                <div class="col-md-3 mb-2"><label>From Date</label><input type="date" name="date_from" class="form-control" value="<?=htmlspecialchars($date_from, ENT_QUOTES, 'UTF-8')?>"></div>
                <div class="col-md-3 mb-2"><label>To Date</label><input type="date" name="date_to" class="form-control" value="<?=htmlspecialchars($date_to, ENT_QUOTES, 'UTF-8')?>"></div>
                <div class="col-md-4 mb-2"><label>Staff</label><select name="staff_id" class="form-control"><option value="0">All Staff</option><?php while($staff=mysqli_fetch_assoc($staff_members)){ ?><option value="<?= (int)$staff['id'] ?>" <?= $staff_id === (int)$staff['id'] ? 'selected' : '' ?>><?=htmlspecialchars($staff['name'])?><?= $staff['staff_code'] ? ' (' . htmlspecialchars($staff['staff_code']) . ')' : '' ?></option><?php } ?></select></div>
                <div class="col-md-2 mb-2"><button class="btn btn-primary btn-block"><i class="fas fa-filter"></i> Show Report</button></div>
            </div>
        </form>
        <div class="print-title d-none"><h3>Staff Sales Report</h3><p><?=htmlspecialchars($date_from)?> to <?=htmlspecialchars($date_to)?></p></div>
        <table id="example1" class="table table-bordered table-striped" data-desktop-table>
            <thead><tr><th>SL</th><th>Date</th><th>Invoice</th><th>Table</th><th>Staff</th><th>Sales Amount</th></tr></thead>
            <tbody><?php $total = 0; $sl = 1; while($row=mysqli_fetch_assoc($report_rows)){ $amount=(float)$row['total_amount']; $total += $amount; ?><tr><td><?= $sl++ ?></td><td><?=htmlspecialchars($row['invoice_date'])?></td><td><?=htmlspecialchars($row['invoice_no'])?></td><td><?=htmlspecialchars($row['table_name'] ?: 'Not assigned')?></td><td><?=htmlspecialchars($row['staff_name'] ?: 'Not assigned')?><?= $row['staff_code'] ? ' (' . htmlspecialchars($row['staff_code']) . ')' : '' ?></td><td class="text-right">BDT <?=number_format($amount, 2)?></td></tr><?php } ?></tbody>
            <tfoot><tr><th colspan="5" class="text-right">Total Sales</th><th class="text-right">BDT <?=number_format($total, 2)?></th></tr></tfoot>
        </table>
    </div>
</div>
<style>@media print{.main-header,.main-sidebar,.main-footer,.no-print,.dataTables_filter,.dataTables_length,.dataTables_paginate,.dataTables_info{display:none!important}.content-wrapper{margin-left:0!important}.print-title{display:block!important}.table-sales-report{border:0!important;box-shadow:none!important}}</style>
<?php require_once '../includes/footer.php'; ?>
