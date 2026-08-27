<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';

$user_id = $_SESSION['user_id'];

$sql = "SELECT
            m.*,
            w.wallet_name
        FROM money_ins m
        LEFT JOIN wallets w
        ON w.id = m.wallet_id
        AND w.user_id = m.user_id
        WHERE m.user_id=?
        ORDER BY m.id DESC";

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
            Money In List
        </h3>

        <div class="card-tools">

            <a href="create.php"
               class="btn btn-primary btn-sm">

                <i class="fas fa-plus"></i>
                Add Money In

            </a>

        </div>

    </div>

    <div class="card-body">

        <table
            id="example1"
            class="table table-bordered table-striped">

            <thead>

            <tr>
                <th>Date</th>
                <th>Wallet</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Note</th>
                <?php if(manager_can_modify()){ ?>
                    <th>Action</th>
                <?php } ?>
            </tr>

            </thead>

            <tbody>

            <?php while($row=mysqli_fetch_assoc($result)){ ?>

            <tr>

                <td>
                    <?= htmlspecialchars(app_date($row['txn_date'])); ?>
                </td>

                <td>
                    <?= htmlspecialchars($row['wallet_name'] ?: ('Missing Wallet #' . (int)$row['wallet_id'])); ?>
                </td>

                <td>
                    BDT <?= number_format($row['amount'],2); ?>
                </td>

                <td>
                    <?php if(($row['approval_status'] ?? 'pending') === 'pending' || empty($row['approval_status'])){ ?>
                        <span class="badge badge-warning">Pending</span>
                    <?php }elseif(($row['approval_status'] ?? 'approved') === 'rejected'){ ?>
                        <span class="badge badge-danger">Rejected</span>
                    <?php }else{ ?>
                        <span class="badge badge-success">Approved</span>
                    <?php } ?>
                </td>

                <td>
                    <?= htmlspecialchars($row['note']); ?>
                </td>

                    <?php if(manager_can_modify()){ ?>
                <td>
                        <a href="edit.php?id=<?= (int)$row['id']; ?>" class="btn btn-warning btn-sm" title="Edit Money In" aria-label="Edit Money In">
                            <i class="fas fa-edit"></i>
                        </a>
                        <a href="delete.php?id=<?= (int)$row['id']; ?>"
                           class="btn btn-danger btn-sm" title="Delete Money In" aria-label="Delete Money In"
                           onclick="return confirm('Delete this money in entry?')">
                            <i class="fas fa-trash"></i>
                        </a>
                </td>
                    <?php } ?>

            </tr>

            <?php } ?>

            </tbody>

        </table>

    </div>

</div>

<?php
require_once '../includes/footer.php';
?>
