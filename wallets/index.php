<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';

$user_id = $_SESSION['user_id'];

ensure_default_cash_wallet($conn, $user_id);

$result = mysqli_query(
    $conn,
    "SELECT *
     FROM wallets
     WHERE user_id = $user_id
     ORDER BY id DESC"
);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">
            Wallet List
        </h3>

        <?php if(is_admin_user()){ ?>

        <div class="card-tools">

            <a href="create.php"
               class="btn btn-primary btn-sm">

                <i class="fas fa-plus"></i>
                Add Wallet

            </a>

        </div>

        <?php } ?>

    </div>

    <div class="card-body">

        <table
            id="example1"
            class="table table-bordered table-striped">

            <thead>

            <tr>
                <th>Wallet Name</th>
                <th>Description</th>
                <th>Balance</th>
                <th>Status</th>
                <th width="220">Action</th>
            </tr>

            </thead>

            <tbody>

            <?php while($row = mysqli_fetch_assoc($result)){ ?>

            <tr>

                <td>
                    <?= htmlspecialchars($row['wallet_name']); ?>
                </td>

                <td>

                    <?php
                    if(!empty($row['description'])){
                        echo htmlspecialchars($row['description']);
                    }else{
                        echo '-';
                    }
                    ?>

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

                <td>

<?php if($row['is_system']==1){ ?>

    <span class="badge badge-primary">

        <i class="fas fa-lock"></i>
        System Wallet

    </span>

<?php }elseif(manager_can_modify()){ ?>

    <a href="edit.php?id=<?= $row['id']; ?>"
       class="btn btn-info btn-sm" title="Edit Wallet" aria-label="Edit Wallet">

        <i class="fas fa-edit"></i>
    </a>

    <?php if($row['status']=='active'){ ?>

        <a href="inactive.php?id=<?= $row['id']; ?>"
           class="btn btn-danger btn-sm" title="Inactive Wallet" aria-label="Inactive Wallet"
           onclick="return confirm('Inactive this wallet?')">

            <i class="fas fa-ban"></i>
        </a>

    <?php }else{ ?>

        <a href="active.php?id=<?= $row['id']; ?>"
           class="btn btn-success btn-sm" title="Activate Wallet" aria-label="Activate Wallet"
           onclick="return confirm('Activate this wallet?')">

            <i class="fas fa-check"></i>
        </a>

    <?php } ?>

<?php }else{ ?>

    <span class="text-muted">View only</span>

<?php } ?>

</td>

            </tr>

            <?php } ?>

            </tbody>

        </table>

    </div>

</div>

<?php
require_once '../includes/footer.php';
?>
