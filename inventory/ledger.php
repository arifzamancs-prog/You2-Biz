<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';

$user_id = $_SESSION['user_id'];

$sql = "SELECT
            st.*,
            p.product_name
        FROM stock_transactions st
        LEFT JOIN products p
            ON p.id = st.product_id
        WHERE st.user_id=?
        ORDER BY st.id DESC";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">

            <i class="fas fa-history mr-2"></i>

            Stock Ledger

        </h3>

    </div>

    <div class="card-body">

        <table
            id="ledgerTable"
            class="table table-bordered table-striped">

            <thead>

            <tr>

                <th>Date</th>
                <th>Product</th>
                <th>Type</th>
                <th>Quantity</th>
                <th>Note</th>

            </tr>

            </thead>

            <tbody>

            <?php while($row = mysqli_fetch_assoc($result)){ ?>

            <tr>

                <td>
                    <?= htmlspecialchars(app_date($row['txn_date'])); ?>
                </td>

                <td>
                    <?= htmlspecialchars($row['product_name']); ?>
                </td>

                <td>

                    <?php if($row['transaction_type']=='stock_in'){ ?>

                        <span class="badge badge-success">

                            Stock In

                        </span>

                    <?php } else { ?>

                        <span class="badge badge-danger">

                            Stock Out

                        </span>

                    <?php } ?>

                </td>

                <td>

                    <?= number_format($row['quantity'],0); ?>

                </td>

                <td>

                    <?= htmlspecialchars($row['note']); ?>

                </td>

            </tr>

            <?php } ?>

            </tbody>

        </table>

    </div>

</div>

<script>

$(function(){

    $('#ledgerTable').DataTable({

        order:[[0,'desc']]

    });

});

</script>

<?php
require_once '../includes/footer.php';
?>
