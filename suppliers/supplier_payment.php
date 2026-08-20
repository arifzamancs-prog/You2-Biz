<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = $_SESSION['user_id'];

$sql = "SELECT
            p.id,
            p.purchase_no,
            p.purchase_date,
            p.total_amount,
            p.paid_amount,
            p.due_amount,
            p.payment_status,
            s.supplier_name
        FROM purchases p
        LEFT JOIN suppliers s
            ON s.id = p.supplier_id
        WHERE p.user_id=?
        AND p.due_amount > 0
        ORDER BY p.purchase_date DESC, p.id DESC";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$purchases = [];
$total_due = 0;
$total_paid = 0;
$total_purchase = 0;

while($row = mysqli_fetch_assoc($result)){

    $purchases[] = $row;
    $total_due += (float)$row['due_amount'];
    $total_paid += (float)$row['paid_amount'];
    $total_purchase += (float)$row['total_amount'];

}

?>

<?php if(isset($_GET['success'])){ ?>

<div class="alert alert-success alert-dismissible fade show">

    Supplier Due Payment Saved Successfully.

</div>

<?php } ?>

<?php if(isset($_GET['error'])){ ?>

<div class="alert alert-danger alert-dismissible fade show">

    <?= htmlspecialchars($_GET['error']); ?>

</div>

<?php } ?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">
            Supplier Due Payment
        </h3>

    </div>

    <div class="card-body">

        <table
            id="example1"
            class="table table-bordered table-striped">

            <thead>

            <tr>

                <th>Purchase No</th>
                <th>Date</th>
                <th>Supplier</th>
                <th>Total</th>
                <th>Paid</th>
                <th>Due</th>
                <th>Status</th>
                <th width="150">Action</th>

            </tr>

            </thead>

            <tbody>

            <?php foreach($purchases as $row){ ?>

            <tr>

                <td>
                    <?= htmlspecialchars($row['purchase_no']); ?>
                </td>

                <td>
                    <?= htmlspecialchars(app_date($row['purchase_date'])); ?>
                </td>

                <td>
                    <?= htmlspecialchars($row['supplier_name']); ?>
                </td>

                <td>
                    <?= number_format($row['total_amount'],2); ?>
                </td>

                <td>
                    <?= number_format($row['paid_amount'],2); ?>
                </td>

                <td>
                    <span class="text-danger font-weight-bold">
                        <?= number_format($row['due_amount'],2); ?>
                    </span>
                </td>

                <td>

                    <?php if($row['payment_status'] == 'partial'){ ?>

                    <span class="badge badge-warning">
                        Partial
                    </span>

                    <?php }else{ ?>

                    <span class="badge badge-danger">
                        Due
                    </span>

                    <?php } ?>

                </td>

                <td>

                    <a
                        href="supplier_payment_entry.php?id=<?= $row['id']; ?>"
                        class="btn btn-success btn-sm">

                        <i class="fas fa-money-bill"></i>
                        Pay

                    </a>

                    <a
                        href="../purchases/view.php?id=<?= $row['id']; ?>"
                        class="btn btn-info btn-sm">

                        View

                    </a>

                </td>

            </tr>

            <?php } ?>

            </tbody>

            <tfoot>

            <tr>

                <th colspan="3" class="text-right">
                    Total
                </th>
                <th><?= number_format($total_purchase,2); ?></th>
                <th><?= number_format($total_paid,2); ?></th>
                <th><?= number_format($total_due,2); ?></th>
                <th colspan="2"></th>

            </tr>

            </tfoot>

        </table>

    </div>

</div>

<?php
require_once '../includes/footer.php';
?>
