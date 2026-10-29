<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/contact_code_helper.php';
require_once '../includes/customer_helper.php';

$user_id = $_SESSION['user_id'];
ensure_contact_code_columns($conn);
contact_code_fill_missing($conn, 'customer', $user_id);

$sql = "SELECT *
        FROM customers
        WHERE user_id=?
        ORDER BY id DESC";

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

                <th>ID</th>
                <th>Customer Name</th>
                <th>Phone</th>
                <th>Address</th>
                <th>Email</th>
                <th>Status</th>
                <th width="220">Action</th>

            </tr>

            </thead>

            <tbody>

            <?php while($row = mysqli_fetch_assoc($result)){ ?>

            <tr>

                <td>
                    <?= htmlspecialchars($row['customer_code'] ?? ''); ?>
                </td>

                <td>
                    <?= htmlspecialchars($row['customer_name']); ?>
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

                        <i class="fas fa-book"></i> Ledger

                    </a>

                    <?php if(manager_can_modify()){ ?>

                    <a
                        href="edit.php?id=<?= $row['id']; ?>"
                        class="btn btn-warning btn-sm"
                        title="Edit">

                        <i class="fas fa-edit"></i> Edit

                    </a>

                    <?php if(!customer_has_transactions($conn, $row['id'], $user_id)){ ?>
                    <a
                        href="delete.php?id=<?= $row['id']; ?>"
                        class="btn btn-danger btn-sm"
                        onclick="return confirm('Delete this customer?')"
                        title="Delete">

                        <i class="fas fa-trash"></i> Delete

                    </a>
                    <?php }else{ ?>
                    <!-- Customers with invoices, payments or dues keep their ledger, so they cannot be deleted. -->
                    <button type="button" class="btn btn-secondary btn-sm" disabled title="This customer has transactions and cannot be deleted">
                        <i class="fas fa-trash"></i> Delete
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
