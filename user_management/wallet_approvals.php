<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/transaction_helper.php';
require_once '../includes/wallet_helper.php';

require_admin_user();

$user_id = (int)$_SESSION['user_id'];
$admin_id = (int)($_SESSION['login_user_id'] ?? $_SESSION['user_id']);
$message = '';
$message_type = '';

function redirect_approvals($message = '', $type = 'success')
{
    $location = $_POST['return_to'] ?? 'wallet_approvals.php';

    if(
        !is_string($location) ||
        trim($location) === '' ||
        preg_match('/^https?:\/\//i', $location)
    ){
        $location = 'wallet_approvals.php';
    }

    if ($message !== '') {
        $_SESSION['wallet_approvals_flash_message'] = $message;
        $_SESSION['wallet_approvals_flash_type'] = $type;
    }

    header("Location: " . $location);
    exit;
}

$message = $_SESSION['wallet_approvals_flash_message'] ?? '';
$message_type = $_SESSION['wallet_approvals_flash_type'] ?? '';
unset($_SESSION['wallet_approvals_flash_message'], $_SESSION['wallet_approvals_flash_type']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $source = $_POST['source'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if (!in_array($source, ['expense', 'transfer'], true) ||
        !in_array($action, ['approve', 'reject'], true) ||
        $id <= 0) {

        redirect_approvals('Invalid approval request.', 'danger');
    }

    mysqli_begin_transaction($conn);

    try {

        if ($source === 'expense') {

            $sql = "SELECT *
                    FROM expenses
                    WHERE id=?
                    AND user_id=?
                    AND approval_status='pending'
                    LIMIT 1";

            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "ii", $id, $user_id);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

            if (!$row) {
                throw new Exception('Pending expense entry not found.');
            }

            if ($action === 'approve') {

                debit_wallet($conn, $row['wallet_id'], $user_id, $row['amount']);

                record_wallet_transaction(
                    $conn,
                    $row['txn_no'],
                    $user_id,
                    $row['wallet_id'],
                    'expense',
                    $row['id'],
                    $row['amount'],
                    $row['note'],
                    $row['txn_date']
                );
            }

            $status = $action === 'approve' ? 'approved' : 'rejected';

            $update_sql = "UPDATE expenses
                           SET approval_status=?,
                               approved_by=?,
                               approved_at=NOW()
                           WHERE id=?
                           AND user_id=?";

            $update_stmt = mysqli_prepare($conn, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "siii", $status, $admin_id, $id, $user_id);
            mysqli_stmt_execute($update_stmt);

        } else {

            $sql = "SELECT *
                    FROM transfers
                    WHERE id=?
                    AND user_id=?
                    AND approval_status='pending'
                    LIMIT 1";

            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "ii", $id, $user_id);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

            if (!$row) {
                throw new Exception('Pending transfer entry not found.');
            }

            if ($action === 'approve') {

                debit_wallet($conn, $row['from_wallet_id'], $user_id, $row['amount']);
                credit_wallet($conn, $row['to_wallet_id'], $user_id, $row['amount']);

                record_wallet_transaction(
                    $conn,
                    $row['txn_no'],
                    $user_id,
                    $row['from_wallet_id'],
                    'transfer',
                    $row['id'],
                    $row['amount'],
                    'Transfer: ' . $row['note'],
                    $row['txn_date']
                );
            }

            $status = $action === 'approve' ? 'approved' : 'rejected';

            $update_sql = "UPDATE transfers
                           SET approval_status=?,
                               approved_by=?,
                               approved_at=NOW()
                           WHERE id=?
                           AND user_id=?";

            $update_stmt = mysqli_prepare($conn, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "siii", $status, $admin_id, $id, $user_id);
            mysqli_stmt_execute($update_stmt);
        }

        mysqli_commit($conn);
        redirect_approvals('Entry ' . ($action === 'approve' ? 'approved' : 'rejected') . ' successfully.');

    } catch (Exception $e) {

        mysqli_rollback($conn);
        redirect_approvals($e->getMessage(), 'danger');
    }
}

$expense_sql = "SELECT
                    e.id,
                    e.txn_no,
                    e.txn_date,
                    e.amount,
                    e.note,
                    e.created_at,
                    w.wallet_name,
                    c.category_name,
                    u.name AS created_by_name
                FROM expenses e
                LEFT JOIN wallets w
                    ON w.id=e.wallet_id
                LEFT JOIN categories c
                    ON c.id=e.category_id
                LEFT JOIN users u
                    ON u.id=e.created_by
                WHERE e.user_id=?
                AND e.approval_status='pending'
                ORDER BY e.created_at DESC";

$transfer_sql = "SELECT
                    t.id,
                    t.txn_no,
                    t.txn_date,
                    t.amount,
                    t.note,
                    t.created_at,
                    fw.wallet_name AS from_wallet,
                    tw.wallet_name AS to_wallet,
                    u.name AS created_by_name
                 FROM transfers t
                 LEFT JOIN wallets fw
                    ON fw.id=t.from_wallet_id
                 LEFT JOIN wallets tw
                    ON tw.id=t.to_wallet_id
                 LEFT JOIN users u
                    ON u.id=t.created_by
                 WHERE t.user_id=?
                 AND t.approval_status='pending'
                 ORDER BY t.created_at DESC";

$expense_stmt = mysqli_prepare($conn, $expense_sql);
mysqli_stmt_bind_param($expense_stmt, "i", $user_id);
mysqli_stmt_execute($expense_stmt);
$expenses = mysqli_stmt_get_result($expense_stmt);

$transfer_stmt = mysqli_prepare($conn, $transfer_sql);
mysqli_stmt_bind_param($transfer_stmt, "i", $user_id);
mysqli_stmt_execute($transfer_stmt);
$transfers = mysqli_stmt_get_result($transfer_stmt);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<?php if($message){ ?>
    <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
        <?= htmlspecialchars($message); ?>
    </div>
<?php } ?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Pending Wallet Approvals</h3>
    </div>
    <div class="card-body">
        <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" data-toggle="tab" href="#expenses">Expenses</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-toggle="tab" href="#transfers">Transfers</a>
            </li>
        </ul>

        <div class="tab-content pt-3">
            <div class="tab-pane fade show active" id="expenses">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Wallet</th>
                            <th>Category</th>
                            <th>Amount</th>
                            <th>By</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = mysqli_fetch_assoc($expenses)){ ?>
                            <tr>
                                <td><?= htmlspecialchars(app_date($row['txn_date'])); ?></td>
                                <td><?= htmlspecialchars($row['wallet_name']); ?></td>
                                <td><?= htmlspecialchars($row['category_name']); ?></td>
                                <td><?= number_format($row['amount'],2); ?></td>
                                <td><?= htmlspecialchars($row['created_by_name'] ?? '-'); ?></td>
                                <td><?php approval_buttons('expense', $row['id']); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <div class="tab-pane fade" id="transfers">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Amount</th>
                            <th>By</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = mysqli_fetch_assoc($transfers)){ ?>
                            <tr>
                                <td><?= htmlspecialchars(app_date($row['txn_date'])); ?></td>
                                <td><?= htmlspecialchars($row['from_wallet']); ?></td>
                                <td><?= htmlspecialchars($row['to_wallet']); ?></td>
                                <td><?= number_format($row['amount'],2); ?></td>
                                <td><?= htmlspecialchars($row['created_by_name'] ?? '-'); ?></td>
                                <td><?php approval_buttons('transfer', $row['id']); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
function approval_buttons($source, $id)
{
?>
    <form method="post" class="d-inline">
        <input type="hidden" name="source" value="<?= htmlspecialchars($source); ?>">
        <input type="hidden" name="id" value="<?= (int)$id; ?>">
        <input type="hidden" name="action" value="approve">
        <button type="submit" class="btn btn-success btn-sm">
            Approve
        </button>
    </form>
    <form method="post" class="d-inline">
        <input type="hidden" name="source" value="<?= htmlspecialchars($source); ?>">
        <input type="hidden" name="id" value="<?= (int)$id; ?>">
        <input type="hidden" name="action" value="reject">
        <button type="submit" class="btn btn-danger btn-sm">
            Reject
        </button>
    </form>
<?php
}

require_once '../includes/footer.php';
?>
