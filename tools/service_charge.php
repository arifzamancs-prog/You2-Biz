<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/bill_reminder_helper.php';

require_admin_user();
ensure_company_bill_payment_references_table($conn);

$is_all_company_view = is_super_admin_user();
$company_id = (int)($_SESSION['user_id'] ?? 0);

// Super Admin can change a payment status manually from the Status column.
if($is_all_company_view && $_SERVER['REQUEST_METHOD'] === 'POST'){
    $reference_id = (int)($_POST['reference_id'] ?? 0);
    $post_action = (string)($_POST['action'] ?? 'update');

    if($reference_id > 0 && $post_action === 'delete'){
        // Only a finished (approved / rejected) record can be deleted. It is
        // removed for the company Admin too.
        $delete = mysqli_prepare(
            $conn,
            "DELETE FROM company_bill_payment_references
             WHERE id = ? AND status IN ('approved', 'rejected')"
        );
        mysqli_stmt_bind_param($delete, 'i', $reference_id);
        mysqli_stmt_execute($delete);
        $deleted = mysqli_stmt_affected_rows($delete) === 1;
        mysqli_stmt_close($delete);
        $_SESSION['service_charge_flash'] = $deleted ? 'Payment record deleted.' : 'Only approved or rejected records can be deleted.';
    }elseif($reference_id > 0){
        $new_status = (string)($_POST['status'] ?? '');
        $super_admin_id = (int)($_SESSION['login_user_id'] ?? 0);

        // Status can be changed only while the record is still pending.
        if(in_array($new_status, ['approved', 'rejected'], true)){
            $update = mysqli_prepare(
                $conn,
                "UPDATE company_bill_payment_references
                 SET status = ?, verified_by_user_id = ?, verified_at = NOW(),
                     verification_token_hash = NULL, verification_expires_at = NULL
                 WHERE id = ? AND status = 'pending'"
            );
            mysqli_stmt_bind_param($update, 'sii', $new_status, $super_admin_id, $reference_id);
            mysqli_stmt_execute($update);
            $changed = mysqli_stmt_affected_rows($update) === 1;
            mysqli_stmt_close($update);
            $_SESSION['service_charge_flash'] = $changed ? 'Payment status updated to ' . ucfirst($new_status) . '.' : 'This record is no longer pending, so it could not be changed.';
        }
    }

    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

$flash_message = $_SESSION['service_charge_flash'] ?? '';
unset($_SESSION['service_charge_flash']);

if($is_all_company_view){
    $references = mysqli_query(
        $conn,
        "SELECT r.id, r.submitted_at, r.bill_reminder_date, r.reference_no,
                r.service_charge_amount, r.status, u.name AS company_name
         FROM company_bill_payment_references r
         LEFT JOIN users u ON u.id = r.company_id
         ORDER BY r.submitted_at DESC, r.id DESC"
    );
}else{
    $reference_stmt = mysqli_prepare(
        $conn,
        "SELECT r.id, r.submitted_at, r.bill_reminder_date, r.reference_no,
                r.service_charge_amount, r.status
         FROM company_bill_payment_references r
         WHERE r.company_id = ?
         ORDER BY r.submitted_at DESC, r.id DESC"
    );
    mysqli_stmt_bind_param($reference_stmt, 'i', $company_id);
    mysqli_stmt_execute($reference_stmt);
    $references = mysqli_stmt_get_result($reference_stmt);
}

function service_charge_status_badge($status)
{
    $class = $status === 'approved' ? 'success' : ($status === 'rejected' ? 'danger' : 'warning');
    return '<span class="badge badge-' . $class . '">' . htmlspecialchars(ucfirst($status)) . '</span>';
}

function service_charge_status_form($row)
{
    $id = (int)$row['id'];

    // Approved / rejected records are final: they can only be deleted.
    if($row['status'] !== 'pending'){
        return '<form method="post" class="d-inline-block ml-2 mb-0" onsubmit="return confirm(\'Delete this payment record? It will also disappear from the company Admin report.\');">'
            . '<input type="hidden" name="reference_id" value="' . $id . '">'
            . '<input type="hidden" name="action" value="delete">'
            . '<button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash mr-1"></i> Delete</button>'
            . '</form>';
    }

    return '<form method="post" class="form-inline d-inline-flex ml-2 mb-0">'
        . '<input type="hidden" name="reference_id" value="' . $id . '">'
        . '<select name="status" class="form-control form-control-sm mr-2">'
        . '<option value="pending" selected>Pending</option>'
        . '<option value="approved">Approved</option>'
        . '<option value="rejected">Rejected</option>'
        . '</select>'
        . '<button type="submit" class="btn btn-primary btn-sm">Update</button>'
        . '</form>';
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
            <div class="card">
                <div class="card-header"><h3 class="card-title"><i class="fas fa-file-invoice-dollar mr-1"></i> Software Service Charge</h3></div>
                <div class="card-body">
                    <?php if($flash_message !== ''): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($flash_message); ?></div>
                    <?php endif; ?>
                    <div class="table-responsive">
                        <table id="example1" class="table table-bordered table-striped">
                            <thead><tr>
                                <?php if($is_all_company_view): ?><th>Company</th><?php endif; ?>
                                <th style="width:1%; white-space:nowrap;">Payment Date</th><th>Bill Date</th><th>Ref. No.</th><th>Amount</th><th>Status</th>
                            </tr></thead>
                            <tbody>
                            <?php while($references && $row = mysqli_fetch_assoc($references)): ?>
                                <tr>
                                    <?php if($is_all_company_view): ?>
                                        <td><?= htmlspecialchars((string)($row['company_name'] ?? '-')); ?></td>
                                    <?php endif; ?>
                                    <td style="white-space:nowrap;"><?= htmlspecialchars(date('d-m-Y', strtotime($row['submitted_at']))); ?></td>
                                    <td><?= htmlspecialchars(date('d-m-Y', strtotime($row['bill_reminder_date']))); ?></td>
                                    <td><?= htmlspecialchars($row['reference_no']); ?></td>
                                    <td>BDT <?= number_format((float)$row['service_charge_amount'], 2); ?></td>
                                    <td style="white-space:nowrap;">
                                        <?php if($is_all_company_view): ?>
                                            <?= service_charge_status_badge($row['status']); ?>
                                            <?= service_charge_status_form($row); ?>
                                        <?php else: ?>
                                            <?= service_charge_status_badge($row['status']); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
<?php require_once '../includes/footer.php'; ?>
