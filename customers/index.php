<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/customer_helper.php';

$user_id = $_SESSION['user_id'];

$ref_staff_column = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'ref_staff_id'");
if($ref_staff_column && mysqli_num_rows($ref_staff_column) === 0){
    mysqli_query($conn, "ALTER TABLE customers ADD COLUMN ref_staff_id BIGINT UNSIGNED NULL AFTER customer_name");
    mysqli_query($conn, "ALTER TABLE customers ADD INDEX idx_customers_ref_staff (ref_staff_id)");
}

$sql = "SELECT c.*, s.name AS ref_staff_name
        FROM customers c
        LEFT JOIN staff s ON s.id=c.ref_staff_id AND s.user_id=c.user_id
        WHERE c.user_id=?
        ORDER BY c.id DESC";

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

            <i class="fas fa-users mr-2"></i>

            Customer List

        </h3>

        <div class="card-tools">

            <a
                href="create.php"
                class="btn btn-primary btn-sm">

                <i class="fas fa-plus"></i>

                Add Customer

            </a>

        </div>

    </div>

    <div class="card-body">

        <table
            id="example1"
            class="table table-bordered table-striped">

            <thead>

            <tr>

                <th>Customer Name</th>
                <th>Ref</th>
                <th>Phone</th>
                <th>Address</th>
                <th>Email</th>
                <th>Status</th>
                <th width="220">Action</th>

            </tr>

            </thead>

            <tbody>

            <?php while($row = mysqli_fetch_assoc($result)){ $used = customer_has_transactions($conn, $row['id'], $user_id); ?>

            <tr>

                <td>
                    <?= htmlspecialchars($row['customer_name']); ?>
                </td>

                <td>
                    <?= htmlspecialchars($row['ref_staff_name'] ?: '-'); ?>
                </td>

                <td>
                    <?= htmlspecialchars($row['phone']); ?>
                </td>

                <td>
                    <?= htmlspecialchars($row['address'] ?: '-'); ?>
                </td>

                <td>
                    <?= htmlspecialchars($row['email']); ?>
                </td>

                <td>

                    <?php if($row['status']=='active'){ ?>

                        <span class="badge badge-success">

                            Active

                        </span>

                    <?php } else { ?>

                        <span class="badge badge-danger">

                            Inactive

                        </span>

                    <?php } ?>

                </td>

                <td>

                    <a
                        href="customer_ledger.php?id=<?= $row['id']; ?>"
                        class="btn btn-info btn-sm"
                        title="Ledger">

                        <i class="fas fa-book"></i>

                    </a>

                    <?php if(manager_can_modify()){ ?>

                    <a
                        href="edit.php?id=<?= $row['id']; ?>"
                        class="btn btn-warning btn-sm"
                        title="Edit">

                        <i class="fas fa-edit"></i>

                    </a>

                    <?php if(!$used){ ?>
                        <a
                            href="delete.php?id=<?= $row['id']; ?>"
                            class="btn btn-danger btn-sm"
                            onclick="return confirm('Delete this customer?')"
                            title="Delete">

                            <i class="fas fa-trash"></i>

                        </a>
                    <?php }else{ ?>
                        <button
                            type="button"
                            class="btn btn-secondary btn-sm"
                            disabled
                            title="This customer has transactions and cannot be deleted">

                            <i class="fas fa-trash"></i>

                        </button>
                    <?php } ?>

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
