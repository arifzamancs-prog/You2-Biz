<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';

$user_id = $_SESSION['user_id'];

$month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$year  = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

$sql = "SELECT
            c.category_name,
            SUM(e.amount) total_amount
        FROM expenses e
        INNER JOIN categories c
            ON c.id = e.category_id
        WHERE e.user_id=?
        AND e.approval_status='approved'
        AND MONTH(e.txn_date)=?
        AND YEAR(e.txn_date)=?
        GROUP BY e.category_id
        ORDER BY total_amount DESC";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "iii",
    $user_id,
    $month,
    $year
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$chart_labels = [];
$chart_values = [];
$total_expense_amount = 0;

while($row = mysqli_fetch_assoc($result)){

    $chart_labels[] = $row['category_name'];
    $chart_values[] = $row['total_amount'];
    $total_expense_amount += (float)$row['total_amount'];
}

mysqli_data_seek($result,0);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="report-print-heading d-none">
    <div class="print-brand">YOU2 <span>TECHNOLOGIES</span></div>
    <div class="print-document-title">Expense Report</div>
    <div class="print-report-meta"><span>Report period: <?= date('F Y', mktime(0, 0, 0, $month, 1)); ?></span><span>Generated: <?= date('d M Y, h:i A'); ?></span></div>
</div>

<div class="card">

<div class="card-header">

    <h3 class="card-title">

        <i class="fas fa-chart-pie mr-2"></i>

        Expense Report

    </h3>

    <div class="card-tools">

        <a
            href="#"
            onclick="window.print(); return false;"
            class="btn btn-primary btn-sm">

            <i class="fas fa-print"></i>

            Print

        </a>

    </div>

</div>

    <div class="card-body">

        <form method="get">

            <div class="row">

                <div class="col-md-3">

                    <label>Month</label>

                    <select name="month" class="form-control">

                        <?php for($m=1;$m<=12;$m++){ ?>

                        <option
                            value="<?= $m; ?>"
                            <?= ($month==$m)?'selected':''; ?>>

                            <?= date('F',mktime(0,0,0,$m,1)); ?>

                        </option>

                        <?php } ?>

                    </select>

                </div>

                <div class="col-md-3">

                    <label>Year</label>

                    <select name="year" class="form-control">

                        <?php for($y=date('Y');$y>=2020;$y--){ ?>

                        <option
                            value="<?= $y; ?>"
                            <?= ($year==$y)?'selected':''; ?>>

                            <?= $y; ?>

                        </option>

                        <?php } ?>

                    </select>

                </div>

                <div class="col-md-2">

                    <label>&nbsp;</label>

                    <button
                        type="submit"
                        class="btn btn-primary btn-block">

                        Filter

                    </button>

                </div>

            </div>

        </form>

    </div>

</div>

<div class="row">
    <div class="col-md-4">
        <div class="small-box bg-danger">
            <div class="inner">
                <h3>BDT <?= number_format($total_expense_amount, 2); ?></h3>
                <p>Total Amount</p>
            </div>
            <div class="icon"><i class="fas fa-receipt"></i></div>
        </div>
    </div>
</div>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">
            Expense Distribution
        </h3>

    </div>

    <div class="card-body">

        <div style="height:400px;">

            <canvas id="categoryChart"></canvas>

        </div>

    </div>

</div>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">
            Category Expense Summary
        </h3>

    </div>

    <div class="card-body">

        <table class="table table-bordered table-striped">

            <thead>

            <tr>

                <th>Category</th>
                <th>Total Expense</th>

            </tr>

            </thead>

            <tbody>

            <?php

            mysqli_data_seek($result,0);

            while($row = mysqli_fetch_assoc($result)){
            ?>

            <tr>

                <td>
                    <?= htmlspecialchars($row['category_name']); ?>
                </td>

                <td>
                    BDT <?= number_format($row['total_amount'],2); ?>
                </td>

            </tr>

            <?php } ?>

            </tbody>

            <tfoot>
                <tr>
                    <th>Total Amount</th>
                    <th>BDT <?= number_format($total_expense_amount, 2); ?></th>
                </tr>
            </tfoot>

        </table>

    </div>

</div>

<?php

$page_script = '

<script>

const ctx = document.getElementById("categoryChart");

new Chart(ctx,{

    type:"pie",

    data:{

        labels: '.json_encode($chart_labels).',

        datasets:[{

            data: '.json_encode($chart_values).'

        }]

    },

    options:{
        responsive:true,
        maintainAspectRatio:false
    }

});

</script>

<style>
@page{size:A4 landscape;margin:10mm}
@media print{
    html,body{background:#fff!important;color:#14213d!important;font-size:11px!important}
    .main-header,.main-sidebar,.main-footer,.card:first-child,.btn,.dataTables_filter,.dataTables_length,.dataTables_paginate,.dataTables_info{display:none!important}
    .content-wrapper,.content,.container-fluid{margin:0!important;padding:0!important;width:100%!important;min-height:0!important}
    .report-print-heading{display:block!important;border-bottom:2px solid #1677e8!important;padding:0 0 10px!important;margin:0 0 12px!important;text-align:center!important}
    .report-print-heading h2{font-size:20px!important;letter-spacing:1px!important;margin:0 0 3px!important;color:#0b2e59!important}
    .report-print-heading .print-subtitle{font-size:13px!important;font-weight:700!important;letter-spacing:1.5px!important;color:#1677e8!important;margin-bottom:3px!important}
    .report-print-heading strong{font-size:11px!important;color:#5d6b82!important}
    .row{display:flex!important;flex-wrap:wrap!important;margin:0 -4px!important}
    .row>.col-md-4,.row>.col-md-6{padding:0 4px!important}
    .row>.col-md-4{flex:0 0 33.333%!important;max-width:33.333%!important}
    .row>.col-md-6{flex:0 0 50%!important;max-width:50%!important}
    .card{box-shadow:none!important;border:1px solid #d9e2ef!important;border-radius:0!important;margin-bottom:10px!important;break-inside:avoid!important}
    .card-header{background:#f3f7fc!important;border-bottom:1px solid #d9e2ef!important;padding:7px 10px!important;font-weight:700!important}
    .card-body{padding:9px!important}
    .small-box{background:#fff!important;border:1px solid #d9e2ef!important;box-shadow:none!important;min-height:auto!important;color:#14213d!important}
    .small-box h3,.small-box p{color:#14213d!important}.small-box .icon{display:none!important}
    canvas{max-height:220px!important}
    table{width:100%!important;font-size:10px!important;border-collapse:collapse!important}
    th{background:#eaf2fb!important;color:#12375d!important}
    th,td{padding:5px!important;border:1px solid #d9e2ef!important}
}
</style>

';

require_once '../includes/footer.php';

?>
