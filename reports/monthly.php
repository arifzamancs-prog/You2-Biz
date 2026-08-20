<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';

$user_id = $_SESSION['user_id'];

$month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$year  = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

/* Money In */

$sql = "SELECT SUM(amount) total_money_in
        FROM money_ins
        WHERE user_id=?
        AND approval_status='approved'
        AND MONTH(txn_date)=?
        AND YEAR(txn_date)=?";

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
$row = mysqli_fetch_assoc($result);

$total_money_in = $row['total_money_in'] ?? 0;


/* Expense */

$sql = "SELECT SUM(amount) total_expense
        FROM expenses
        WHERE user_id=?
        AND approval_status='approved'
        AND MONTH(txn_date)=?
        AND YEAR(txn_date)=?";

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
$row = mysqli_fetch_assoc($result);

$total_expense = $row['total_expense'] ?? 0;

$net_savings = $total_money_in - $total_expense;

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">

            <i class="fas fa-chart-line mr-2"></i>

            Monthly Summary Report

        </h3>

        <div class="card-tools">

            <a
                href="pdf/monthly_summary_pdf.php?month=<?= $month; ?>&year=<?= $year; ?>"
                target="_blank"
                class="btn btn-danger btn-sm">

                <i class="fas fa-file-pdf"></i>

                Export PDF

            </a>

        </div>

    </div>

    <div class="card-body">

        <form method="get">

            <div class="row align-items-end">

                <div class="col-md-4">

                    <label>Month</label>

                    <select name="month" class="form-control">

                        <?php for($m=1;$m<=12;$m++){ ?>

                            <option
                                value="<?= $m; ?>"
                                <?= ($month==$m)?'selected':''; ?>>

                                <?= date('F', mktime(0,0,0,$m,1)); ?>

                            </option>

                        <?php } ?>

                    </select>

                </div>

                <div class="col-md-3">

                    <label>Year</label>

                    <select name="year" class="form-control">

                        <?php
                        for($y=date('Y'); $y>=2020; $y--){
                        ?>

                        <option
                            value="<?= $y; ?>"
                            <?= ($year==$y)?'selected':''; ?>>

                            <?= $y; ?>

                        </option>

                        <?php } ?>

                    </select>

                </div>

                <div class="col-md-2 mt-2 mt-md-0">

                    <button
                        type="submit"
                        class="btn btn-primary btn-block">

                        <i class="fas fa-filter"></i>

                        Filter

                    </button>

                </div>

            </div>

        </form>

    </div>

</div>

<div class="row">

    <div class="col-lg-4">

        <div class="small-box bg-success">

            <div class="inner">

                <h3>
                    BDT <?= number_format($total_money_in,2); ?>
                </h3>

                <p>Total Money In</p>

            </div>

            <div class="icon">
                <i class="fas fa-arrow-circle-down"></i>
            </div>

        </div>

    </div>

    <div class="col-lg-4">

        <div class="small-box bg-danger">

            <div class="inner">

                <h3>
                    BDT <?= number_format($total_expense,2); ?>
                </h3>

                <p>Total Expense</p>

            </div>

            <div class="icon">
                <i class="fas fa-arrow-circle-up"></i>
            </div>

        </div>

    </div>

    <div class="col-lg-4">

        <div class="small-box bg-info">

            <div class="inner">

                <h3>
                    BDT <?= number_format($net_savings,2); ?>
                </h3>

                <p>Net Savings</p>

            </div>

            <div class="icon">
                <i class="fas fa-piggy-bank"></i>
            </div>

        </div>

    </div>

</div>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">

            <i class="fas fa-chart-bar mr-2"></i>

            Money In vs Expense

        </h3>

    </div>

    <div class="card-body">

        <div style="height:350px;">

            <canvas id="monthlyChart"></canvas>

        </div>

    </div>

</div>

<?php

$page_script = '

<script>

const ctx = document.getElementById("monthlyChart");

new Chart(ctx,{

    type:"bar",

    data:{

        labels:["Money In","Expense"],

        datasets:[{

            label:"Amount (BDT)",

            data:['.$total_money_in.','.$total_expense.'],

            backgroundColor:[
                "#28a745",
                "#dc3545"
            ]

        }]

    },

    options:{
        responsive:true,
        maintainAspectRatio:false
    }

});

</script>

';

require_once '../includes/footer.php';

?>
