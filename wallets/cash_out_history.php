<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/profit_cash_out_helper.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = selected_branch_id($conn, false);

ensure_default_cash_wallet($conn, $user_id);
ensure_profit_cash_out_table($conn);

$sql = "SELECT p.*, w.wallet_name,
               COALESCE(NULLIF(b.branch_name, ''), 'Head Office') AS branch_name
        FROM profit_cash_outs p
        LEFT JOIN wallets w ON w.id=p.wallet_id AND w.user_id=p.user_id
        LEFT JOIN branches b ON b.id=w.branch_id AND b.user_id=w.user_id
        WHERE p.user_id=?";

if ($branch_id > 0) {
    $sql .= " AND w.branch_id=?";
}

$sql .= " ORDER BY p.txn_date DESC, p.id DESC";
$history_stmt = mysqli_prepare($conn, $sql);
if ($branch_id > 0) {
    mysqli_stmt_bind_param($history_stmt, 'ii', $user_id, $branch_id);
} else {
    mysqli_stmt_bind_param($history_stmt, 'i', $user_id);
}
mysqli_stmt_execute($history_stmt);
$history = mysqli_stmt_get_result($history_stmt);
$branch_label = $branch_id > 0 ? current_branch_label($conn) : 'All Branches';

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Wallet Cash Out History <small class="text-muted">— <?= htmlspecialchars($branch_label) ?></small></h3>
    </div>
    <div class="card-body">
        <table id="example1" class="table table-bordered table-striped">
            <thead><tr><th>SL</th><th>Transaction No.</th><th>Date</th><th>Wallet</th><th>Amount</th><th>Note</th></tr></thead>
            <tbody>
            <?php $sl = 1; while ($row = mysqli_fetch_assoc($history)) { ?>
                <tr>
                    <td><?= $sl++ ?></td>
                    <td><?= htmlspecialchars($row['txn_no']) ?></td>
                    <td><?= htmlspecialchars(app_date($row['txn_date'])) ?></td>
                    <td><?= htmlspecialchars($row['wallet_name'] ?: ('Missing Wallet #' . (int)$row['wallet_id'])) ?></td>
                    <td>BDT <?= number_format((float)$row['amount'], 2) ?></td>
                    <td><?= htmlspecialchars($row['note'] ?? '') ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
