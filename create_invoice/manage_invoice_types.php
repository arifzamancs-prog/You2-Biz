<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/booking_invoice_helper.php';
require_admin_user();

$user_id = (int)$_SESSION['user_id'];
ensure_booking_invoice_table($conn);
ensure_booking_invoice_type_table($conn, $user_id);
$message = '';
$error = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $action = $_POST['action'] ?? '';
    if($action === 'add'){
        $type_name = trim($_POST['type_name'] ?? '');
        $type_key = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $type_name), '_'));
        $behavior = $_POST['behavior'] ?? '';
        if($type_name === '' || $type_key === '' || !in_array($behavior, ['income', 'expense'], true)){
            $error = 'Enter an invoice type name and select its behavior.';
        }else{
            $stmt = mysqli_prepare($conn, "INSERT INTO booking_invoice_types (user_id, type_key, type_name, behavior, status) VALUES (?, ?, ?, ?, 'active') ON DUPLICATE KEY UPDATE type_name=VALUES(type_name), behavior=VALUES(behavior), status='active'");
            mysqli_stmt_bind_param($stmt, 'isss', $user_id, $type_key, $type_name, $behavior);
            if(mysqli_stmt_execute($stmt)) $message = 'Invoice type added or activated.';
            else $error = 'Unable to save this invoice type.';
        }
    }
    if($action === 'delete'){
        $type_id = (int)($_POST['type_id'] ?? 0);
        $type_stmt = mysqli_prepare($conn, "SELECT type_key FROM booking_invoice_types WHERE id=? AND user_id=? LIMIT 1");
        mysqli_stmt_bind_param($type_stmt, 'ii', $type_id, $user_id);
        mysqli_stmt_execute($type_stmt);
        $type_row = mysqli_fetch_assoc(mysqli_stmt_get_result($type_stmt));
        if(!$type_row){
            $error = 'Invoice type not found.';
        }else{
            $count_stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM booking_invoices WHERE user_id=? AND invoice_type=?");
            mysqli_stmt_bind_param($count_stmt, 'is', $user_id, $type_row['type_key']);
            mysqli_stmt_execute($count_stmt);
            $usage = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($count_stmt))['total'] ?? 0);
            if($usage > 0){
                $error = 'Invoice types with transactions cannot be deleted.';
            }else{
                $delete_stmt = mysqli_prepare($conn, "UPDATE booking_invoice_types SET status='inactive' WHERE id=? AND user_id=?");
                mysqli_stmt_bind_param($delete_stmt, 'ii', $type_id, $user_id);
                mysqli_stmt_execute($delete_stmt);
                $message = 'Invoice type deleted from new entries.';
            }
        }
    }
}

$types_result = mysqli_query($conn, "SELECT t.*, COUNT(bi.id) AS transaction_count FROM booking_invoice_types t LEFT JOIN booking_invoices bi ON bi.user_id=t.user_id AND bi.invoice_type=t.type_key WHERE t.user_id={$user_id} GROUP BY t.id ORDER BY t.status='active' DESC, t.type_name");
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title">Manage Invoice Type</h3></div><div class="card-body">
<?php if($message){ ?><div class="alert alert-success"><?= htmlspecialchars($message); ?></div><?php } ?>
<?php if($error){ ?><div class="alert alert-danger"><?= htmlspecialchars($error); ?></div><?php } ?>
<form method="post" class="mb-4"><input type="hidden" name="action" value="add"><div class="row"><div class="col-md-5 form-group"><label>New Invoice Type</label><input type="text" name="type_name" class="form-control" maxlength="100" required></div><div class="col-md-3 form-group"><label>Behavior</label><select name="behavior" class="form-control" required><option value="">Select Behavior</option><option value="income">Income</option><option value="expense">Expense</option></select></div><div class="col-md-2 form-group d-flex align-items-end"><button class="btn btn-primary">Add Type</button></div></div></form>
<table id="example1" class="table table-bordered table-striped"><thead><tr><th>Invoice Type</th><th>Behavior</th><th>Status</th><th>Transactions</th><th width="100">Action</th></tr></thead><tbody><?php while($type = mysqli_fetch_assoc($types_result)){ ?><tr><td><?= htmlspecialchars($type['type_name']); ?></td><td><span class="badge badge-<?= $type['behavior'] === 'income' ? 'success' : 'warning'; ?>"><?= htmlspecialchars(ucfirst($type['behavior'])); ?></span></td><td><span class="badge badge-<?= $type['status'] === 'active' ? 'success' : 'secondary'; ?>"><?= htmlspecialchars(ucfirst($type['status'])); ?></span></td><td><?= (int)$type['transaction_count']; ?></td><td><?php if($type['status'] === 'active' && (int)$type['transaction_count'] > 0){ ?><button class="btn btn-secondary btn-sm" disabled title="This type has transactions"><i class="fas fa-trash"></i></button><?php }elseif($type['status'] === 'active'){ ?><form method="post" onsubmit="return confirm('Remove this invoice type?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="type_id" value="<?= (int)$type['id']; ?>"><button class="btn btn-danger btn-sm" title="Delete"><i class="fas fa-trash"></i></button></form><?php } ?></td></tr><?php } ?></tbody></table>
</div></div>
<?php require_once '../includes/footer.php'; ?>
