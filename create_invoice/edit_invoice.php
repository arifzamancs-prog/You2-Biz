<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/booking_invoice_helper.php';

require_admin_user();
$user_id = (int)$_SESSION['user_id'];
ensure_booking_invoice_table($conn);
ensure_booking_invoice_type_table($conn, $user_id);

$invoice_id = (int)($_GET['id'] ?? $_POST['invoice_id'] ?? 0);
if($invoice_id <= 0){
    header('Location: invoice_list.php');
    exit;
}

$invoice_stmt = mysqli_prepare($conn, 'SELECT * FROM booking_invoices WHERE id=? AND user_id=? LIMIT 1');
mysqli_stmt_bind_param($invoice_stmt, 'ii', $invoice_id, $user_id);
mysqli_stmt_execute($invoice_stmt);
$invoice = mysqli_fetch_assoc(mysqli_stmt_get_result($invoice_stmt));
if(!$invoice){
    die('Invoice not found.');
}

$invoice_types = booking_invoice_types($conn, $user_id, false);
$message = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $project_id = (int)($_POST['project_id'] ?? 0);
    $package_id = (int)($_POST['package_id'] ?? 0);
    $wallet_id = (int)($_POST['wallet_id'] ?? 0);
    $invoice_type = trim((string)($_POST['invoice_type'] ?? ''));
    $invoice_date = trim((string)($_POST['invoice_date'] ?? ''));
    $amount = (float)($_POST['amount'] ?? 0);
    $notes = trim((string)($_POST['notes'] ?? ''));

    if($customer_id <= 0 || $project_id <= 0 || $package_id <= 0 || $wallet_id <= 0 || $amount <= 0 || !isset($invoice_types[$invoice_type]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoice_date)){
        $message = 'Please complete all invoice fields correctly.';
    }else{
        mysqli_begin_transaction($conn);
        try{
            $lock_stmt = mysqli_prepare($conn, 'SELECT * FROM booking_invoices WHERE id=? AND user_id=? FOR UPDATE');
            mysqli_stmt_bind_param($lock_stmt, 'ii', $invoice_id, $user_id);
            mysqli_stmt_execute($lock_stmt);
            $locked_invoice = mysqli_fetch_assoc(mysqli_stmt_get_result($lock_stmt));
            if(!$locked_invoice){
                throw new Exception('Invoice not found.');
            }

            $was_confirmed = ($locked_invoice['status'] ?? 'pending') === 'confirmed' || !empty($locked_invoice['wallet_effect_applied']);
            if($was_confirmed){
                booking_invoice_reverse_wallet_effect($conn, $locked_invoice, $user_id);
            }

            $update_stmt = mysqli_prepare(
                $conn,
                'UPDATE booking_invoices SET customer_id=?, project_id=?, package_id=?, wallet_id=?, invoice_type=?, invoice_date=?, amount=?, notes=?, status=\'pending\', wallet_effect_applied=0, confirmed_at=NULL WHERE id=? AND user_id=?'
            );
            mysqli_stmt_bind_param($update_stmt, 'iiiissdsii', $customer_id, $project_id, $package_id, $wallet_id, $invoice_type, $invoice_date, $amount, $notes, $invoice_id, $user_id);
            if(!mysqli_stmt_execute($update_stmt)){
                throw new Exception(mysqli_stmt_error($update_stmt));
            }

            if($was_confirmed){
                $updated_invoice = $locked_invoice;
                $updated_invoice['customer_id'] = $customer_id;
                $updated_invoice['project_id'] = $project_id;
                $updated_invoice['package_id'] = $package_id;
                $updated_invoice['wallet_id'] = $wallet_id;
                $updated_invoice['invoice_type'] = $invoice_type;
                $updated_invoice['invoice_date'] = $invoice_date;
                $updated_invoice['amount'] = $amount;
                $updated_invoice['notes'] = $notes;
                booking_invoice_apply_wallet_effect($conn, $updated_invoice, $user_id);
            }

            mysqli_commit($conn);
            header('Location: invoice_list.php?updated=1');
            exit;
        }catch(Throwable $error){
            mysqli_rollback($conn);
            $message = $error->getMessage();
        }
    }

    $invoice = array_merge($invoice, compact('customer_id', 'project_id', 'package_id', 'wallet_id', 'invoice_type', 'invoice_date', 'amount', 'notes'));
}

$customers = mysqli_query($conn, "SELECT id, customer_name, customer_code FROM customers WHERE user_id={$user_id} AND status='active' ORDER BY customer_name");
$projects = mysqli_query($conn, "SELECT id, project_name FROM projects WHERE user_id={$user_id} AND status='active' ORDER BY project_name");
$packages = mysqli_query($conn, "SELECT id, package_name FROM packages WHERE user_id={$user_id} AND status='active' ORDER BY package_name");
$wallets = mysqli_query($conn, "SELECT id, wallet_name FROM wallets WHERE user_id={$user_id} AND status='active' ORDER BY is_system DESC, wallet_name");

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-edit mr-2"></i>Edit Invoice</h3></div>
    <form method="post" class="card-body">
        <input type="hidden" name="invoice_id" value="<?= $invoice_id; ?>">
        <?php if($message !== ''){ ?><div class="alert alert-danger"><?= htmlspecialchars($message); ?></div><?php } ?>
        <div class="row">
            <div class="col-md-6 form-group"><label>Customer</label><select name="customer_id" class="form-control" required><?php while($customer = mysqli_fetch_assoc($customers)){ ?><option value="<?= (int)$customer['id']; ?>" <?= (int)$invoice['customer_id'] === (int)$customer['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($customer['customer_name'] . (!empty($customer['customer_code']) ? ' (' . $customer['customer_code'] . ')' : '')); ?></option><?php } ?></select></div>
            <div class="col-md-3 form-group"><label>Project</label><select name="project_id" class="form-control" required><?php while($project = mysqli_fetch_assoc($projects)){ ?><option value="<?= (int)$project['id']; ?>" <?= (int)$invoice['project_id'] === (int)$project['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($project['project_name']); ?></option><?php } ?></select></div>
            <div class="col-md-3 form-group"><label>Package</label><select name="package_id" class="form-control" required><?php while($package = mysqli_fetch_assoc($packages)){ ?><option value="<?= (int)$package['id']; ?>" <?= (int)$invoice['package_id'] === (int)$package['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($package['package_name']); ?></option><?php } ?></select></div>
            <div class="col-md-4 form-group"><label>Date</label><input type="date" name="invoice_date" class="form-control" value="<?= htmlspecialchars($invoice['invoice_date']); ?>" required></div>
            <div class="col-md-4 form-group"><label>Amount (BDT)</label><input type="number" min="0.01" step="0.01" name="amount" class="form-control" value="<?= htmlspecialchars($invoice['amount']); ?>" required></div>
            <div class="col-md-4 form-group"><label>Wallet</label><select name="wallet_id" class="form-control" required><?php while($wallet = mysqli_fetch_assoc($wallets)){ ?><option value="<?= (int)$wallet['id']; ?>" <?= (int)$invoice['wallet_id'] === (int)$wallet['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($wallet['wallet_name']); ?></option><?php } ?></select></div>
            <div class="col-md-4 form-group"><label>Invoice Type</label><select name="invoice_type" class="form-control" required><?php foreach($invoice_types as $type_key => $type_name){ ?><option value="<?= htmlspecialchars($type_key); ?>" <?= $invoice['invoice_type'] === $type_key ? 'selected' : ''; ?>><?= htmlspecialchars($type_name); ?></option><?php } ?></select></div>
            <div class="col-md-12 form-group"><label>Note</label><textarea name="notes" rows="3" class="form-control"><?= htmlspecialchars($invoice['notes'] ?? ''); ?></textarea></div>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Update Invoice</button>
        <a href="invoice_list.php" class="btn btn-secondary">Back</a>
    </form>
</div>
<?php require_once '../includes/footer.php'; ?>
