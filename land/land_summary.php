<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/project_package_helper.php';

$uid = (int)$_SESSION['user_id'];
if (project_package_company_type($conn, $uid) !== 'Housing' || (is_manager_user() && !manager_has_permission('land_ledger'))) die('Access denied');

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS land_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED NOT NULL,
    entry_type ENUM('Land Purchase','Land Sale','Unused Land') NOT NULL,
    land_size DECIMAL(12,2) NOT NULL,
    unit VARCHAR(20) NOT NULL,
    party VARCHAR(255) NULL,
    entry_date DATE NULL,
    note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(user_id), INDEX(project_id)
)");
function format_land_area($decimal) {
    $decimal = (float)$decimal;
    return number_format($decimal, 2) . ' Decimal | ' . number_format($decimal / 1.65, 2) . ' Katha | ' . number_format($decimal / 33, 2) . ' Bigha';
}
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS land_plots (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED NOT NULL,
    block_name VARCHAR(10) NOT NULL,
    plot_size DECIMAL(10,2) NOT NULL,
    total_plots INT UNSIGNED NOT NULL DEFAULT 0,
    sold_plots INT UNSIGNED NOT NULL DEFAULT 0,
    note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(user_id), INDEX(project_id)
)");

$summary = mysqli_query($conn, "SELECT p.project_name,
    SUM(CASE WHEN le.entry_type='Land Purchase' THEN le.land_size ELSE 0 END) AS purchased,
    SUM(CASE WHEN le.entry_type='Land Sale' THEN le.land_size ELSE 0 END) AS sold,
    SUM(CASE WHEN le.entry_type='Unused Land' THEN le.land_size ELSE 0 END) AS unused,
    COUNT(le.id) AS entries
    FROM projects p LEFT JOIN land_entries le ON le.project_id=p.id AND le.user_id={$uid}
    WHERE p.user_id={$uid} GROUP BY p.id, p.project_name ORDER BY p.project_name");
$plots = mysqli_query($conn, "SELECT p.project_name, lp.block_name, lp.plot_size,
    SUM(lp.total_plots) AS total_plots, SUM(lp.sold_plots) AS sold_plots,
    SUM(lp.total_plots-lp.sold_plots) AS available_plots, COUNT(lp.id) AS entries
    FROM land_plots lp JOIN projects p ON p.id=lp.project_id WHERE lp.user_id={$uid}
    GROUP BY p.id,p.project_name,lp.block_name,lp.plot_size ORDER BY p.project_name,lp.block_name,lp.plot_size");
$landTotals = mysqli_fetch_assoc(mysqli_query($conn, "SELECT
    COALESCE(SUM(CASE WHEN entry_type='Land Purchase' THEN land_size ELSE 0 END),0) AS purchased,
    COALESCE(SUM(CASE WHEN entry_type='Land Sale' THEN land_size ELSE 0 END),0) AS sold,
    COALESCE(SUM(CASE WHEN entry_type='Unused Land' THEN land_size ELSE 0 END),0) AS unused,
    COUNT(*) AS entries FROM land_entries WHERE user_id={$uid}"));
$plotTotals = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_plots),0) AS total_plots,
    COALESCE(SUM(sold_plots),0) AS sold_plots,
    COALESCE(SUM(total_plots-sold_plots),0) AS available_plots,
    COUNT(*) AS entries FROM land_plots WHERE user_id={$uid}"));
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<style>.print-report-header{display:none}@media print{@page{size:A4 portrait;margin:12mm}.main-sidebar,.main-header,.main-footer,.print-report-btn,.card-header{display:none!important}.content-wrapper{margin-left:0!important;min-height:0!important}.content,.container-fluid{padding:0!important;margin:0!important}.card,.card-body{border:0!important;box-shadow:none!important;padding:0!important}.print-report-header{display:block!important;text-align:center;border-bottom:2px solid #1f4e79;padding:0 0 10px;margin:0 0 16px}.print-report-header h1{font-size:20pt;margin:0 0 3px;color:#1f4e79;font-weight:700}.print-report-header .report-title{font-size:15pt;font-weight:700;margin:0}.print-report-header .report-meta{font-size:9pt;color:#555;margin-top:5px}.card-body h5{font-size:12pt;color:#1f4e79;border-left:4px solid #1f4e79;padding-left:7px;margin:16px 0 7px}.table-responsive{overflow:visible!important}.table{width:100%!important;border-collapse:collapse!important;font-size:9pt;margin-bottom:14px!important}.table th{background:#1f4e79!important;color:#fff!important;border:1px solid #1f4e79!important;padding:6px!important;font-weight:700}.table td{border:1px solid #b9c7d4!important;padding:6px!important;color:#222!important}.table-striped tbody tr:nth-of-type(odd){background:#eef4f8!important}.card-body hr{border-top:1px solid #b9c7d4!important;margin:16px 0!important}}</style>
<div class="card"><div class="card-header"><h3 class="d-inline">Land Summary</h3><button type="button" class="btn btn-primary float-right print-report-btn" onclick="window.print()"><i class="fas fa-print"></i> Print Report</button></div><div class="card-body"><div class="print-report-header"><h1><?=htmlspecialchars($_SESSION['user_name'] ?? 'Company')?></h1><p class="report-title">Land Summary Report</p><div class="report-meta">Generated on <?=date('d-m-Y h:i A')?></div></div>
<h5>Project-wise Land Summary</h5><div class="table-responsive"><table class="table table-bordered table-striped"><thead><tr><th>Project</th><th>Land Purchase</th><th>Land Sale</th><th>Unused Land</th><th>Total Entries</th></tr></thead><tbody><?php if(mysqli_num_rows($summary)){ while($row=mysqli_fetch_assoc($summary)){ ?><tr><td><?=htmlspecialchars($row['project_name'])?></td><td><?=number_format((float)$row['purchased'],2)?> Decimal</td><td><?=number_format((float)$row['sold'],2)?> Decimal</td><td><?=number_format((float)$row['unused'],2)?> Decimal</td><td><?=$row['entries']?></td></tr><?php }} else { ?><tr><td colspan="5" class="text-center">No project found.</td></tr><?php } ?></tbody><tfoot><tr class="font-weight-bold"><td>Total</td><td><?=format_land_area($landTotals['purchased'])?></td><td><?=format_land_area($landTotals['sold'])?></td><td><?=format_land_area($landTotals['unused'])?></td><td><?=$landTotals['entries']?></td></tr></tfoot></table></div>
<hr><h5>Block-wise Plot Summary</h5><div class="table-responsive"><table class="table table-bordered table-striped"><thead><tr><th>Project</th><th>Block</th><th>Plot Size</th><th>Total Plots</th><th>Sold Plots</th><th>Available Plots</th><th>Total Entries</th></tr></thead><tbody><?php if(mysqli_num_rows($plots)){ while($plot=mysqli_fetch_assoc($plots)){ ?><tr><td><?=htmlspecialchars($plot['project_name'])?></td><td><?=htmlspecialchars($plot['block_name'])?></td><td><?=number_format((float)$plot['plot_size'],2)?> Katha</td><td><?=$plot['total_plots']?></td><td><?=$plot['sold_plots']?></td><td><?=$plot['available_plots']?></td><td><?=$plot['entries']?></td></tr><?php }} else { ?><tr><td colspan="7" class="text-center">No plot entry found.</td></tr><?php } ?></tbody><tfoot><tr class="font-weight-bold"><td colspan="3">Total</td><td><?=$plotTotals['total_plots']?></td><td><?=$plotTotals['sold_plots']?></td><td><?=$plotTotals['available_plots']?></td><td><?=$plotTotals['entries']?></td></tr></tfoot></table></div>
</div></div><?php require_once '../includes/footer.php'; ?>
