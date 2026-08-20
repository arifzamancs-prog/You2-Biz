<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';

$user_id = $_SESSION['user_id'];

ensure_default_cash_wallet($conn, $user_id);

$sql = "SELECT
            wallet_name,
            description,
            balance,
            status
        FROM wallets
        WHERE user_id=?
        ORDER BY wallet_name ASC";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$chart_labels = [];
$chart_values = [];

$total_balance = 0;

while($row = mysqli_fetch_assoc($result)){

    $chart_labels[] = $row['wallet_name'];
    $chart_values[] = $row['balance'];

    $total_balance += $row['balance'];
}

mysqli_data_seek($result,0);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="row">

    <div class="col-lg-12">

        <div class="small-box bg-info">

            <div class="inner">

                <h3>
                    BDT <?= number_format($total_balance,2); ?>
                </h3>

                <p>
                    Total Wallet Balance
                </p>

            </div>

            <div class="icon">
                <i class="fas fa-wallet"></i>
            </div>

        </div>

    </div>

</div>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">
            Wallet Balance Chart
        </h3>

    </div>

    <div class="card-body">

        <div style="height:400px;">

            <canvas id="walletChart"></canvas>

        </div>

    </div>

</div>

<div class="card">

<div class="card-header">

    <h3 class="card-title">

        <i class="fas fa-wallet mr-2"></i>

        Wallet Summary

    </h3>

    <div class="card-tools">

        <a
            href="pdf/wallet_summary_pdf.php"
            target="_blank"
            class="btn btn-danger btn-sm">

            <i class="fas fa-file-pdf"></i>

            Export PDF

        </a>

    </div>

</div>

    <div class="card-body">

        <table class="table table-bordered table-striped">

            <thead>

            <tr>

                <th>Wallet</th>
                <th>Description</th>
                <th>Balance</th>
                <th>Status</th>

            </tr>

            </thead>

            <tbody>

            <?php while($row = mysqli_fetch_assoc($result)){ ?>

            <tr>

                <td>
                    <?= htmlspecialchars($row['wallet_name']); ?>
                </td>

                <td>
                    <?= htmlspecialchars($row['description']); ?>
                </td>

                <td>
                    BDT <?= number_format($row['balance'],2); ?>
                </td>

                <td>

                    <?php if($row['status']=='active'){ ?>

                        <span class="badge badge-success">
                            Active
                        </span>

                    <?php }else{ ?>

                        <span class="badge badge-danger">
                            Inactive
                        </span>

                    <?php } ?>

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

const ctx = document.getElementById("walletChart");

new Chart(ctx,{

    type:"bar",

    data:{

        labels: '.json_encode($chart_labels).',

        datasets:[{

            label:"Balance",

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
