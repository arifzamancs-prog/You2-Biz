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

while($row = mysqli_fetch_assoc($result)){

    $chart_labels[] = $row['category_name'];
    $chart_values[] = $row['total_amount'];
}

mysqli_data_seek($result,0);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

<div class="card-header">

    <h3 class="card-title">

        <i class="fas fa-chart-pie mr-2"></i>

        Expense By Category Report

    </h3>

    <div class="card-tools">

        <a
            href="pdf/category_expense_pdf.php?month=<?= $month; ?>&year=<?= $year; ?>"
            target="_blank"
            class="btn btn-danger btn-sm">

            <i class="fas fa-file-pdf"></i>

            Export PDF

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

';

require_once '../includes/footer.php';

?>
