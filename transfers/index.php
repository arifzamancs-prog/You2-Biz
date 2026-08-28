<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';

$user_id = $_SESSION['user_id'];

$sql = "SELECT
            t.*,
            fw.wallet_name AS from_wallet,
            tw.wallet_name AS to_wallet
        FROM transfers t
        LEFT JOIN wallets fw
            ON fw.id = t.from_wallet_id
            AND fw.user_id = t.user_id
        LEFT JOIN wallets tw
            ON tw.id = t.to_wallet_id
            AND tw.user_id = t.user_id
        WHERE t.user_id=?
        ORDER BY t.id DESC";

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
            Transfer History
        </h3>

        <?php if(is_admin_user()){ ?><div class="card-tools">

            <a href="create.php"
               class="btn btn-primary btn-sm">

                <i class="fas fa-plus"></i>
                New Transfer

            </a>

        </div><?php } ?>

    </div>

    <div class="card-body">

        <table
            id="example1"
            class="table table-bordered table-hover">

            <thead>

                <tr>
                    <th>Date</th>
                    <th>Txn No</th>
                    <th>From Wallet</th>
                    <th>To Wallet</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Note</th>
                    <?php if(manager_can_modify()){ ?>
                        <th>Action</th>
                    <?php } ?>
                </tr>

            </thead>

            <tbody>

            <?php while($row = mysqli_fetch_assoc($result)){ ?>

                <tr>

                    <td>
                        <?= htmlspecialchars(app_date($row['txn_date'])); ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($row['txn_no']); ?>
                    </td>

                    <td>

                        <span class="badge badge-danger">
                            <?= htmlspecialchars($row['from_wallet'] ?: ('Missing Wallet #' . (int)$row['from_wallet_id'])); ?>
                        </span>

                    </td>

                    <td>

                        <span class="badge badge-success">
                            <?= htmlspecialchars($row['to_wallet'] ?: ('Missing Wallet #' . (int)$row['to_wallet_id'])); ?>
                        </span>

                    </td>

                    <td>

                        <strong>
                            BDT <?= number_format($row['amount'],2); ?>
                        </strong>

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
                            <a href="edit.php?id=<?= (int)$row['id']; ?>" class="btn btn-warning btn-sm" title="Edit Transfer" aria-label="Edit Transfer">
                                <i class="fas fa-edit"></i>
                            </a>
                            <a href="delete.php?id=<?= (int)$row['id']; ?>"
                               class="btn btn-danger btn-sm" title="Delete Transfer" aria-label="Delete Transfer"
                               onclick="return confirm('Delete this transfer?')">
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
