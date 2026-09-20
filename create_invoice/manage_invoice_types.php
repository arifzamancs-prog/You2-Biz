<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/booking_invoice_helper.php';
require_admin_user();

$user_id = (int)$_SESSION['user_id'];
ensure_booking_invoice_table($conn);
ensure_booking_invoice_type_table($conn, $user_id);
$system_type_keys = booking_system_invoice_type_keys();
$message = '';
$error = '';
$message = $_SESSION['payment_type_flash_message'] ?? '';
$error = $_SESSION['payment_type_flash_error'] ?? '';
unset($_SESSION['payment_type_flash_message'], $_SESSION['payment_type_flash_error']);

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $action = $_POST['action'] ?? '';
    if($action === 'rename'){
        $type_id = (int)($_POST['type_id'] ?? 0);
        $type_name = trim((string)($_POST['type_name'] ?? ''));
        if($type_id <= 0 || $type_name === ''){
            $error = 'Enter a payment type name.';
        }else{
            $rename_stmt = mysqli_prepare($conn, "UPDATE booking_invoice_types SET type_name=? WHERE id=? AND user_id=? LIMIT 1");
            mysqli_stmt_bind_param($rename_stmt, 'sii', $type_name, $type_id, $user_id);
            $message = mysqli_stmt_execute($rename_stmt) ? 'Payment type renamed successfully.' : 'Unable to rename this payment type.';
            if($message[0] === 'U') $error = $message;
        }
    }
    if($action === 'add'){
        $type_name = trim($_POST['type_name'] ?? '');
        $type_key = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $type_name), '_'));
        $behavior = $_POST['behavior'] ?? '';
        if($type_name === '' || $type_key === '' || !in_array($behavior, ['income', 'expense'], true)){
            $error = 'Enter a payment type name and select its behavior.';
        }elseif(in_array($type_key, $system_type_keys, true) || $type_key === 'profit_return' || $type_key === 'full_payment'){
            $error = 'This payment type name is reserved by the system.';
        }else{
            $stmt = mysqli_prepare($conn, "INSERT INTO booking_invoice_types (user_id, type_key, type_name, behavior, status) VALUES (?, ?, ?, ?, 'active') ON DUPLICATE KEY UPDATE type_name=VALUES(type_name), behavior=VALUES(behavior), status='active'");
            mysqli_stmt_bind_param($stmt, 'isss', $user_id, $type_key, $type_name, $behavior);
            if(mysqli_stmt_execute($stmt)) $message = 'Payment type added or activated.';
            else $error = 'Unable to save this payment type.';
        }
    }
    if($action === 'delete'){
        $type_id = (int)($_POST['type_id'] ?? 0);
        $type_stmt = mysqli_prepare($conn, "SELECT type_key FROM booking_invoice_types WHERE id=? AND user_id=? LIMIT 1");
        mysqli_stmt_bind_param($type_stmt, 'ii', $type_id, $user_id);
        mysqli_stmt_execute($type_stmt);
        $type_row = mysqli_fetch_assoc(mysqli_stmt_get_result($type_stmt));
        if(!$type_row){
            $error = 'Payment type not found.';
        }elseif(in_array($type_row['type_key'], $system_type_keys, true) || strtolower(trim((string)($type_row['type_name'] ?? ''))) === 'full payment'){
            $error = 'System payment types are fixed and cannot be deleted.';
        }else{
            $count_stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM booking_invoices WHERE user_id=? AND invoice_type=?");
            mysqli_stmt_bind_param($count_stmt, 'is', $user_id, $type_row['type_key']);
            mysqli_stmt_execute($count_stmt);
            $usage = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($count_stmt))['total'] ?? 0);
            if($usage > 0){
                $error = 'Payment types with transactions cannot be deleted.';
            }else{
                $delete_stmt = mysqli_prepare($conn, "UPDATE booking_invoice_types SET status='inactive' WHERE id=? AND user_id=?");
                mysqli_stmt_bind_param($delete_stmt, 'ii', $type_id, $user_id);
                mysqli_stmt_execute($delete_stmt);
                $message = 'Payment type deleted from new entries.';
            }
        }
    }
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if($message !== '') $_SESSION['payment_type_flash_message'] = $message;
    if($error !== '') $_SESSION['payment_type_flash_error'] = $error;
    header('Location: manage_invoice_types.php');
    exit;
}

$types_result = mysqli_query($conn, "SELECT t.*, COUNT(bi.id) AS transaction_count FROM booking_invoice_types t LEFT JOIN booking_invoices bi ON bi.user_id=t.user_id AND bi.invoice_type=t.type_key WHERE t.user_id={$user_id} AND t.type_key<>'profit_return' GROUP BY t.id ORDER BY FIELD(t.type_key, 'booking', 'cancel_return', 'installment', 'full_payment') DESC, t.status='active' DESC, t.type_name");
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title">Manage Payment Type</h3></div><div class="card-body">
<?php if($message){ ?><div class="alert alert-success"><?= htmlspecialchars($message); ?></div><?php } ?>
<?php if($error){ ?><div class="alert alert-danger"><?= htmlspecialchars($error); ?></div><?php } ?>



<table id="example1" class="table table-bordered table-striped"><thead><tr><th>Payment Type</th><th>Behavior</th><th>Status</th><th>Transactions</th><th width="140">Action</th></tr></thead><tbody><?php while($type = mysqli_fetch_assoc($types_result)){ $is_system_type = in_array($type['type_key'], $system_type_keys, true); ?><tr><td><?= htmlspecialchars($type['type_name']); ?></td><td><span class="badge badge-<?= $type['behavior'] === 'income' ? 'success' : 'warning'; ?>"><?= htmlspecialchars(ucfirst($type['behavior'])); ?></span></td><td><span class="badge badge-<?= $type['status'] === 'active' ? 'success' : 'secondary'; ?>"><?= htmlspecialchars(ucfirst($type['status'])); ?></span></td><td><?= (int)$type['transaction_count']; ?></td><td><?php if($is_system_type){ ?><span class="text-muted"><i class="fas fa-lock"></i> Fixed</span><?php }else{ ?><button type="button" class="btn btn-warning btn-sm payment-type-edit" data-id="<?= (int)$type['id']; ?>" data-name="<?= htmlspecialchars($type['type_name'], ENT_QUOTES); ?>" title="Edit"><i class="fas fa-edit"></i></button> <?php if($type['status'] === 'active' && (int)$type['transaction_count'] > 0){ ?><button class="btn btn-secondary btn-sm" disabled title="This type has transactions"><i class="fas fa-trash"></i></button><?php }elseif($type['status'] === 'active'){ ?><form class="d-inline" method="post" onsubmit="return confirm('Remove this payment type?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="type_id" value="<?= (int)$type['id']; ?>"><button class="btn btn-danger btn-sm" title="Delete"><i class="fas fa-trash"></i></button></form><?php } ?><?php } ?></td></tr><?php } ?></tbody></table>
<div class="text-center mt-3"><button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#add-payment-type-modal" title="Add Payment Type"><i class="fas fa-plus"></i></button></div>
</div></div>
<div class="modal fade" id="add-payment-type-modal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content"><input type="hidden" name="action" value="add"><div class="modal-header"><h5 class="modal-title">Add Payment Type</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><div class="modal-body"><div class="form-group"><label>Payment Type</label><input type="text" name="type_name" class="form-control" maxlength="100" required></div><div class="form-group mb-0"><label>Behavior</label><select name="behavior" class="form-control" required><option value="">Select Behavior</option><option value="income">Income</option><option value="expense">Expense</option></select></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="fas fa-plus"></i> Add Type</button></div></form></div></div>
<script>
document.addEventListener('click', function(event){
  const button = event.target.closest('.payment-type-edit');
  if(!button) return;
  const name = prompt('Payment type name:', button.dataset.name || '');
  if(name === null || !name.trim()) return;
  const form = document.createElement('form');
  form.method = 'post';
  form.innerHTML = '<input type="hidden" name="action" value="rename"><input type="hidden" name="type_id" value="' + button.dataset.id + '"><input type="hidden" name="type_name">';
  form.elements.type_name.value = name.trim();
  document.body.appendChild(form);
  form.submit();
});
</script>
<?php require_once '../includes/footer.php'; ?>

