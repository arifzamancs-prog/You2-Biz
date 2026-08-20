<?php

require_once 'includes/auth.php';
require_once 'includes/db.php';

$user_id = $_SESSION['user_id'];

/* Total Balance */

$sql = "SELECT SUM(balance) total_balance
        FROM wallets
        WHERE user_id=?
        AND status='active'";

$stmt = mysqli_prepare($conn,$sql);
mysqli_stmt_bind_param($stmt,"i",$user_id);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);

$total_balance = $row['total_balance'] ?? 0;


/* Monthly Money In */

$sql = "SELECT SUM(amount) total_money_in
        FROM money_ins
        WHERE user_id=?
        AND MONTH(txn_date)=MONTH(CURDATE())
        AND YEAR(txn_date)=YEAR(CURDATE())";

$stmt = mysqli_prepare($conn,$sql);
mysqli_stmt_bind_param($stmt,"i",$user_id);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);

$total_money_in = $row['total_money_in'] ?? 0;


/* Monthly Expense */

$sql = "SELECT SUM(amount) total_expense
        FROM expenses
        WHERE user_id=?
        AND MONTH(txn_date)=MONTH(CURDATE())
        AND YEAR(txn_date)=YEAR(CURDATE())";

$stmt = mysqli_prepare($conn,$sql);
mysqli_stmt_bind_param($stmt,"i",$user_id);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);

$total_expense = $row['total_expense'] ?? 0;


/* Active Wallets */

$sql = "SELECT COUNT(*) total_wallet
        FROM wallets
        WHERE user_id=?
        AND status='active'";

$stmt = mysqli_prepare($conn,$sql);
mysqli_stmt_bind_param($stmt,"i",$user_id);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);

$total_wallet = $row['total_wallet'] ?? 0;

/* Wallet Overview */

$sql = "SELECT
            wallet_name,
            balance
        FROM wallets
        WHERE user_id=?
        AND status='active'
        ORDER BY balance DESC";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $user_id
);

mysqli_stmt_execute($stmt);

$wallet_overview =
mysqli_stmt_get_result($stmt);

/* Recent Transactions */

$sql = "SELECT
            t.*,
            w.wallet_name
        FROM transactions t
        LEFT JOIN wallets w
            ON w.id = t.wallet_id
        WHERE t.user_id=?
        ORDER BY t.id DESC
        LIMIT 10";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $user_id
);

mysqli_stmt_execute($stmt);

$recent_transactions =
    mysqli_stmt_get_result($stmt);

require_once 'includes/header.php';
require_once 'includes/navbar.php';
require_once 'includes/sidebar.php';

?>

<h1 class="mb-4">
    Welcome <?= htmlspecialchars($_SESSION['user_name']); ?>
</h1>

<div class="row">

    <div class="col-lg-3 col-6">

        <div class="small-box bg-info">

            <div class="inner">

            
                <h3>
                    BDT <?= number_format($total_balance,2); ?>
                </h3>

                <p>Total Balance</p>

            </div>

            <div class="icon">
                <i class="fas fa-wallet"></i>
            </div>

        </div>

    </div>
    

    <div class="col-lg-3 col-6">

        <div class="small-box bg-success">

            <div class="inner">

                <h3>
                    BDT <?= number_format($total_money_in,2); ?>
                </h3>

                <p>This Month Money In</p>

            </div>

            <div class="icon">
                <i class="fas fa-arrow-down"></i>
            </div>

        </div>

    </div>

    <div class="col-lg-3 col-6">

        <div class="small-box bg-danger">

            <div class="inner">

                <h3>
                    BDT <?= number_format($total_expense,2); ?>
                </h3>

                <p>This Month Expense</p>

            </div>

            <div class="icon">
                <i class="fas fa-arrow-up"></i>
            </div>

        </div>

    </div>

    <div class="col-lg-3 col-6">

        <div class="small-box bg-warning">

            <div class="inner">

                <h3><?= $total_wallet; ?></h3>

                <p>Active Wallets</p>

            </div>

            <div class="icon">
                <i class="fas fa-credit-card"></i>
            </div>

        </div>

    </div>

</div>


<div class="card">

    <div class="card-header">

        <h3 class="card-title">
            Money In vs Expense
        </h3>

    </div>

<div class="card-body">

    <div style="height:350px;">

        <canvas id="moneyChart"></canvas>

    </div>

</div>

</div>


<div class="card">

    <div class="card-header">

        <div class="row mb-3">

    <div class="col-md-3">

        <a href="moneyin/create.php"
           class="btn btn-success btn-block">

            <i class="fas fa-plus"></i>

            Add Money In

        </a>

    </div>

    <div class="col-md-3">

        <a href="expenses/create.php"
           class="btn btn-danger btn-block">

            <i class="fas fa-minus"></i>

            Add Expense

        </a>

    </div>

    <div class="col-md-3">

        <a href="transfers/create.php"
           class="btn btn-info btn-block">

            <i class="fas fa-random"></i>

            Transfer

        </a>

    </div>

    <div class="col-md-3">

        <a href="wallets/create.php"
           class="btn btn-primary btn-block">

            <i class="fas fa-wallet"></i>

            Add Wallet

        </a>

    </div>

</div>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">
            Wallet Overview
        </h3>

    </div>

    <div class="card-body p-0">

        <table class="table table-striped">

            <thead>

            <tr>

                <th>Wallet</th>
                <th>Balance</th>

            </tr>

            </thead>

            <tbody>

            <?php while($wallet = mysqli_fetch_assoc($wallet_overview)){ ?>

            <tr>

                <td>
                    <?= htmlspecialchars($wallet['wallet_name']); ?>
                </td>

                <td>
                    BDT <?= number_format($wallet['balance'],2); ?>
                </td>

            </tr>

            <?php } ?>

            </tbody>

        </table>

    </div>

</div>

        <div class="card-tools">

            <a href="transactions/index.php"
               class="btn btn-primary btn-sm">

                View All

            </a>

        </div>

    </div>

    <div class="card-body p-0">

        <table class="table table-striped">

            <thead>

            <tr>

                <th>Date</th>
                <th>Type</th>
                <th>Wallet</th>
                <th>Amount</th>

            </tr>

            </thead>

            <tbody>

            <?php while($row = mysqli_fetch_assoc($recent_transactions)){ ?>

            <tr>

                <td>
                    <?= htmlspecialchars(app_date($row['txn_date'])); ?>
                </td>

                <td>

                    <?php if($row['transaction_type']=='money_in'){ ?>

                        <span class="badge badge-success">
                            Money In
                        </span>

                    <?php }elseif($row['transaction_type']=='expense'){ ?>

                        <span class="badge badge-danger">
                            Expense
                        </span>

                    <?php }else{ ?>

                        <span class="badge badge-warning">
                            Transfer
                        </span>

                    <?php } ?>

                </td>

                <td>
                    <?= htmlspecialchars($row['wallet_name']); ?>
                </td>

                <td>

                    <?php if($row['transaction_type']=='money_in'){ ?>

                        <span class="text-success">
                            BDT <?= number_format($row['amount'],2); ?>
                        </span>

                    <?php }else{ ?>

                        <span class="text-danger">
                            BDT <?= number_format($row['amount'],2); ?>
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

const ctx = document.getElementById("moneyChart");

new Chart(ctx, {

    type: "bar",

    data: {

        labels: ["Money In", "Expense"],

        datasets: [{

            label: "Amount (BDT)",

            data: ['.$total_money_in.', '.$total_expense.'],

            backgroundColor: [
                "#28a745",
                "#dc3545"
            ]

        }]

    },

    options: {

        responsive: true,
        maintainAspectRatio: false

    }

});

</script>

';

require_once "includes/footer.php";

?>
