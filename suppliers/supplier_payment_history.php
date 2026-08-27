<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = $_SESSION['user_id'];

$sql = "SELECT
            sp.id,
            sp.supplier_id,
            sp.purchase_id,
            sp.wallet_id,
            sp.amount,
            sp.payment_date,
            sp.note,
            s.supplier_name,
            p.purchase_no,
            w.wallet_name
        FROM supplier_payments sp
        LEFT JOIN suppliers s
            ON s.id = sp.supplier_id
            AND s.user_id = sp.user_id
        LEFT JOIN purchases p
            ON p.id = sp.purchase_id
            AND p.user_id = sp.user_id
        LEFT JOIN wallets w
            ON w.id = sp.wallet_id
            AND w.user_id = sp.user_id
        WHERE sp.user_id=?
        ORDER BY sp.payment_date DESC, sp.id DESC";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$payments = [];
$total_paid = 0;

while($row = mysqli_fetch_assoc($result)){

    $payments[] = $row;
    $total_paid += (float)$row['amount'];

}

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">
            Supplier Due Payment History
        </h3>

    </div>

    <div class="card-body">

        <table
            id="example1"
            class="table table-bordered table-striped">

            <thead>

            <tr>

                <th>Date</th>
                <th>Supplier</th>
                <th>Purchase No</th>
                <th>Wallet</th>
                <th>Amount</th>
                <th>Note</th>

            </tr>

            </thead>

            <tbody>

            <?php foreach($payments as $row){ ?>

            <tr>

                <td>
                    <?= htmlspecialchars(app_date($row['payment_date'])); ?>
                </td>

                <td>
                    <?= htmlspecialchars($row['supplier_name'] ?: ('Missing Supplier #' . (int)$row['supplier_id'])); ?>
                </td>

                <td>
                    <?= htmlspecialchars($row['purchase_no'] ?: ('Missing Purchase #' . (int)$row['purchase_id'])); ?>
                </td>

                <td>
                    <?= htmlspecialchars($row['wallet_name'] ?: ('Missing Wallet #' . (int)$row['wallet_id'])); ?>
                </td>

                <td>
                    <span class="text-dark font-weight-bold">
                        <?= number_format($row['amount'],2); ?>
                    </span>
                </td>

                <td>
                    <?= htmlspecialchars($row['note']); ?>
                </td>

            </tr>

            <?php } ?>

            </tbody>

            <tfoot>

            <tr>

                <th colspan="4" class="text-right">
                    Total
                </th>
                <th><?= number_format($total_paid,2); ?></th>
                <th></th>

            </tr>

            </tfoot>

        </table>

    </div>

</div>

<?php
require_once '../includes/footer.php';
?>
