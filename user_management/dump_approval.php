<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/project_package_helper.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!is_admin_user() || project_package_company_type($conn, $user_id) !== 'Fashion house') {
    http_response_code(403);
    exit('Fashion House administrator access is required.');
}

ensure_fifo_inventory_tables($conn);
$message = $_SESSION['dump_approval_message'] ?? '';
$message_type = $_SESSION['dump_approval_message_type'] ?? 'success';
unset($_SESSION['dump_approval_message'], $_SESSION['dump_approval_message_type']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals(stock_csrf_token(), (string)($_POST['stock_csrf'] ?? ''))) {
            throw new RuntimeException('Invalid request token.');
        }
        $return_id = (int)($_POST['return_id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');
        if (!in_array($action, ['approve', 'reject'], true) || $return_id <= 0) {
            throw new RuntimeException('Invalid dump approval request.');
        }

        mysqli_begin_transaction($conn);
        fifo_inventory_decide_damage_dump($conn, $user_id, $return_id, $action === 'approve');
        mysqli_commit($conn);
        $_SESSION['dump_approval_message'] = $action === 'approve'
            ? 'Damaged product dump approved.'
            : 'Dump request rejected; item is available for another request.';
        $_SESSION['dump_approval_message_type'] = 'success';
        header('Location: dump_approval.php');
        exit;
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $message = $e->getMessage();
        $message_type = 'danger';
    }
}

$pending_sql = "SELECT dr.id,dr.dump_requested_at,dr.quantity,dr.variant_name,dr.note,p.product_name,p.sku,b.branch_name,b.is_head_office
                FROM stock_damage_returns dr
                INNER JOIN products p ON p.id=dr.product_id AND p.user_id=dr.user_id
                INNER JOIN branches b ON b.id=dr.branch_id AND b.user_id=dr.user_id
                WHERE dr.user_id=? AND dr.status='dump_pending'
                ORDER BY dr.dump_requested_at DESC,dr.id DESC";
$pending_stmt = mysqli_prepare($conn, $pending_sql);
mysqli_stmt_bind_param($pending_stmt, 'i', $user_id);
mysqli_stmt_execute($pending_stmt);
$pending_dumps = mysqli_stmt_get_result($pending_stmt);

$history_sql = "SELECT dr.dumped_at,dr.quantity,dr.variant_name,dr.note,p.product_name,p.sku,b.branch_name,b.is_head_office,
                       CASE WHEN dr.dumped_by=dr.user_id THEN 'Admin' ELSE u.name END AS approved_by_name
                FROM stock_damage_returns dr
                INNER JOIN products p ON p.id=dr.product_id AND p.user_id=dr.user_id
                INNER JOIN branches b ON b.id=dr.branch_id AND b.user_id=dr.user_id
                LEFT JOIN users u ON u.id=dr.dumped_by
                WHERE dr.user_id=? AND dr.status='dumped'
                ORDER BY dr.dumped_at DESC,dr.id DESC LIMIT 500";
$history_stmt = mysqli_prepare($conn, $history_sql);
mysqli_stmt_bind_param($history_stmt, 'i', $user_id);
mysqli_stmt_execute($history_stmt);
$dump_history = mysqli_stmt_get_result($history_stmt);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<section class="content">
    <div class="container-fluid">
        <div class="card card-outline card-danger">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-dumpster mr-2"></i>Dumps Approval</h3></div>
            <div class="card-body">
                <?php if ($message !== '') { ?><div class="alert alert-<?= htmlspecialchars($message_type); ?>"><?= htmlspecialchars($message); ?></div><?php } ?>
                <div class="table-responsive"><table class="table table-bordered table-striped mb-0">
                    <thead><tr><th>Requested</th><th>Branch</th><th>Product</th><th>Variant</th><th>Qty</th><th>Note</th><th>Requested from</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php if ($pending_dumps && mysqli_num_rows($pending_dumps) > 0) { while ($row = mysqli_fetch_assoc($pending_dumps)) { ?>
                            <tr>
                                <td><?= htmlspecialchars(app_datetime($row['dump_requested_at'])); ?></td>
                                <td><?= htmlspecialchars($row['is_head_office'] ? 'Head Office' : $row['branch_name']); ?></td>
                                <td><?= htmlspecialchars($row['product_name']); ?><?= $row['sku'] ? ' <small class="text-muted">' . htmlspecialchars($row['sku']) . '</small>' : ''; ?></td>
                                <td><?= htmlspecialchars($row['variant_name'] ?: '-'); ?></td>
                                <td><?= number_format((float)$row['quantity'], 0); ?></td>
                                <td><?= htmlspecialchars($row['note']); ?></td>
                                <td>Main Warehouse</td>
                                <td>
                                    <form method="post" class="d-inline"><input type="hidden" name="stock_csrf" value="<?= htmlspecialchars(stock_csrf_token()); ?>"><input type="hidden" name="return_id" value="<?= (int)$row['id']; ?>"><button type="submit" name="action" value="approve" class="btn btn-success btn-sm">Accept Dump</button></form>
                                    <form method="post" class="d-inline"><input type="hidden" name="stock_csrf" value="<?= htmlspecialchars(stock_csrf_token()); ?>"><input type="hidden" name="return_id" value="<?= (int)$row['id']; ?>"><button type="submit" name="action" value="reject" class="btn btn-danger btn-sm">Reject</button></form>
                                </td>
                            </tr>
                        <?php } } else { ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">No damaged product dump requests are pending.</td></tr>
                        <?php } ?>
                    </tbody>
                </table></div>
            </div>
        </div>

        <div class="card card-outline card-success">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-history mr-2"></i>Approved History</h3></div>
            <div class="card-body table-responsive"><table class="table table-bordered table-striped mb-0">
                <thead><tr><th>Dumped On</th><th>Branch</th><th>Product</th><th>Variant</th><th>Qty</th><th>Note</th><th>Approved By</th></tr></thead>
                <tbody>
                    <?php if ($dump_history && mysqli_num_rows($dump_history) > 0) { while ($row = mysqli_fetch_assoc($dump_history)) { ?>
                        <tr><td><?= htmlspecialchars(app_datetime($row['dumped_at'])); ?></td><td><?= htmlspecialchars($row['is_head_office'] ? 'Head Office' : $row['branch_name']); ?></td><td><?= htmlspecialchars($row['product_name']); ?><?= $row['sku'] ? ' <small class="text-muted">' . htmlspecialchars($row['sku']) . '</small>' : ''; ?></td><td><?= htmlspecialchars($row['variant_name'] ?: '-'); ?></td><td><?= number_format((float)$row['quantity'], 0); ?></td><td><?= htmlspecialchars($row['note']); ?></td><td><?= htmlspecialchars($row['approved_by_name'] ?? '-'); ?></td></tr>
                    <?php } } else { ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No approved dump requests yet.</td></tr>
                    <?php } ?>
                </tbody>
            </table></div>
        </div>
    </div>
</section>
<?php require_once '../includes/footer.php'; ?>
