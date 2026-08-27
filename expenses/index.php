<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/expense_helper.php';

$user_id = $_SESSION['user_id'];
ensure_expense_support_tables($conn, $user_id);

$sql = "SELECT
            e.*,
            w.wallet_name,
            c.category_name,
            s.name AS staff_name,
            s.staff_code
        FROM expenses e
        LEFT JOIN wallets w
        ON w.id = e.wallet_id
        AND w.user_id = e.user_id
        LEFT JOIN categories c
        ON c.id = e.category_id
        AND c.user_id = e.user_id
        LEFT JOIN staff s
        ON s.id = e.staff_id
        AND s.user_id = e.user_id
        WHERE e.user_id=?
        ORDER BY e.id DESC";

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
            Expense List
        </h3>

        <div class="card-tools">

            <a href="create.php"
               class="btn btn-primary btn-sm">

                <i class="fas fa-plus"></i>
                Add Expense

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
                <th>Category</th>
                <th>Receiver</th>
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
                    <?= htmlspecialchars($row['wallet_name'] ?: ('Missing Wallet #' . (int)$row['wallet_id'])); ?>
                </td>

                <td>
                    <?= htmlspecialchars($row['category_name'] ?: ('Missing Category #' . (int)$row['category_id'])); ?>
                </td>

                <td>
                    <?=
                        !empty($row['staff_name'])
                            ? htmlspecialchars($row['staff_name']) . (!empty($row['staff_code']) ? ' (' . htmlspecialchars($row['staff_code']) . ')' : '')
                            : (in_array(($row['source_type'] ?? ''), ['purchase_payment', 'supplier_payment'], true) ? 'N/A' : '-');
                    ?>
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
                    <?php $is_supplier_payment_expense = in_array(($row['source_type'] ?? ''), ['purchase_payment', 'supplier_payment'], true); ?>
                    <?php if($is_supplier_payment_expense){ ?>
                        <button type="button" class="btn btn-secondary btn-sm" disabled title="Managed from the supplier payment flow.">
                            <i class="fas fa-lock"></i>
                        </button>
                    <?php }else{ ?>
                        <a href="edit.php?id=<?= (int)$row['id']; ?>" class="btn btn-warning btn-sm" title="Edit Expense" aria-label="Edit Expense">
                            <i class="fas fa-edit"></i>
                        </a>
                        <a href="delete.php?id=<?= (int)$row['id']; ?>"
                           class="btn btn-danger btn-sm" title="Delete Expense" aria-label="Delete Expense"
                           onclick="return confirm('Delete this expense entry?')">
                            <i class="fas fa-trash"></i>
                        </a>
                    <?php } ?>
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
