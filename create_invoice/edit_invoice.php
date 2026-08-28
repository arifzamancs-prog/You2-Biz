<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/booking_invoice_helper.php';

require_sales_access();
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
$cash_wallet_id = ensure_default_cash_wallet($conn, $user_id);
$message = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $project_id = (int)($_POST['project_id'] ?? 0);
    $package_id = (int)($_POST['package_id'] ?? 0);
    $wallet_id = (int)($_POST['wallet_id'] ?? $cash_wallet_id);
    $invoice_type = trim((string)($_POST['invoice_type'] ?? ''));
    $invoice_date = trim((string)($_POST['invoice_date'] ?? ''));
    $amount = (float)($_POST['amount'] ?? 0); $charge_inputs = $_POST['charge_value'] ?? [];
    $preserved_charge_rows = [];
    $preserved_stmt = mysqli_prepare($conn, "SELECT bic.charge_type_id, bic.charge_name, bic.charge_type, bic.charge_value_type, bic.input_value, bic.charge_amount FROM booking_invoice_charges bic LEFT JOIN invoice_charge_types ict ON ict.id=bic.charge_type_id AND ict.user_id=? AND ict.status='active' AND ict.show_on_invoice=1 WHERE bic.booking_invoice_id=? AND ict.id IS NULL");
    mysqli_stmt_bind_param($preserved_stmt, 'ii', $user_id, $invoice_id); mysqli_stmt_execute($preserved_stmt);
    $preserved_result = mysqli_stmt_get_result($preserved_stmt);
    while($saved_charge = mysqli_fetch_assoc($preserved_result)){
        $preserved_charge_rows[] = ['charge'=>['id'=>(int)$saved_charge['charge_type_id'], 'charge_name'=>$saved_charge['charge_name'], 'charge_type'=>$saved_charge['charge_type'], 'charge_value_type'=>$saved_charge['charge_value_type']], 'input_value'=>(float)$saved_charge['input_value'], 'amount'=>(float)$saved_charge['charge_amount']];
    }
    $charge_calculation = booking_invoice_charge_total($conn, $user_id, $amount, $charge_inputs, $preserved_charge_rows); $final_amount = $charge_calculation['total'];
    $notes = trim((string)($_POST['notes'] ?? ''));

    if($customer_id <= 0 || $project_id <= 0 || $package_id <= 0 || $wallet_id <= 0 || $amount <= 0 || $final_amount <= 0 || !isset($invoice_types[$invoice_type]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoice_date)){
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
            mysqli_stmt_bind_param($update_stmt, 'iiiissdsii', $customer_id, $project_id, $package_id, $wallet_id, $invoice_type, $invoice_date, $final_amount, $notes, $invoice_id, $user_id);
            if(!mysqli_stmt_execute($update_stmt)){
                throw new Exception(mysqli_stmt_error($update_stmt));
            }
            $delete_charges = mysqli_prepare($conn, 'DELETE FROM booking_invoice_charges WHERE booking_invoice_id=?'); mysqli_stmt_bind_param($delete_charges, 'i', $invoice_id); mysqli_stmt_execute($delete_charges);
            $charge_insert = mysqli_prepare($conn, "INSERT INTO booking_invoice_charges (booking_invoice_id,charge_type_id,charge_name,charge_type,charge_value_type,input_value,charge_amount) VALUES (?,?,?,?,?,?,?)");
            foreach($charge_calculation['rows'] as $charge_row){ $c=$charge_row['charge']; mysqli_stmt_bind_param($charge_insert,'iisssdd',$invoice_id,$c['id'],$c['charge_name'],$c['charge_type'],$c['charge_value_type'],$charge_row['input_value'],$charge_row['amount']); mysqli_stmt_execute($charge_insert); }

            if($was_confirmed){
                $updated_invoice = $locked_invoice;
                $updated_invoice['customer_id'] = $customer_id;
                $updated_invoice['project_id'] = $project_id;
                $updated_invoice['package_id'] = $package_id;
                $updated_invoice['wallet_id'] = $wallet_id;
                $updated_invoice['invoice_type'] = $invoice_type;
                $updated_invoice['invoice_date'] = $invoice_date;
                $updated_invoice['amount'] = $final_amount;
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
$invoice_charges = booking_invoice_active_charges($conn, $user_id);
$saved_charge_values=[]; $saved_charge_result=mysqli_query($conn, "SELECT charge_type_id,input_value FROM booking_invoice_charges WHERE booking_invoice_id=".(int)$invoice_id); while($saved_charge_result && $saved=mysqli_fetch_assoc($saved_charge_result)) $saved_charge_values[$saved['charge_type_id']]=$saved['input_value'];
$inactive_invoice_charges = [];
$inactive_charge_stmt = mysqli_prepare($conn, "SELECT bic.charge_type_id, bic.charge_name, bic.charge_type, bic.charge_value_type, bic.input_value FROM booking_invoice_charges bic LEFT JOIN invoice_charge_types ict ON ict.id=bic.charge_type_id AND ict.user_id=? AND ict.status='active' AND ict.show_on_invoice=1 WHERE bic.booking_invoice_id=? AND ict.id IS NULL");
mysqli_stmt_bind_param($inactive_charge_stmt, 'ii', $user_id, $invoice_id); mysqli_stmt_execute($inactive_charge_stmt);
$inactive_charge_result = mysqli_stmt_get_result($inactive_charge_stmt); while($inactive_charge_result && $inactive_charge = mysqli_fetch_assoc($inactive_charge_result)) $inactive_invoice_charges[] = $inactive_charge;

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
            <div class="col-md-3 form-group"><label>Date</label><input type="date" name="invoice_date" class="form-control" value="<?= htmlspecialchars($invoice['invoice_date']); ?>" required></div>
            <div class="col-md-3 form-group"><label>Invoice Type</label><select name="invoice_type" class="form-control" required><?php foreach($invoice_types as $type_key => $type_name){ ?><option value="<?= htmlspecialchars($type_key); ?>" <?= $invoice['invoice_type'] === $type_key ? 'selected' : ''; ?>><?= htmlspecialchars($type_name); ?></option><?php } ?></select></div>
            <div class="col-md-3 form-group"><label>Customer Name</label><select name="customer_id" class="form-control" required><?php while($customer = mysqli_fetch_assoc($customers)){ ?><option value="<?= (int)$customer['id']; ?>" <?= (int)$invoice['customer_id'] === (int)$customer['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($customer['customer_name'] . (!empty($customer['customer_code']) ? ' (' . $customer['customer_code'] . ')' : '')); ?></option><?php } ?></select></div>
            <div class="col-md-3 form-group"><label>Project</label><select name="project_id" class="form-control" required><?php while($project = mysqli_fetch_assoc($projects)){ ?><option value="<?= (int)$project['id']; ?>" <?= (int)$invoice['project_id'] === (int)$project['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($project['project_name']); ?></option><?php } ?></select></div>
            <div class="col-md-3 form-group"><label>Package</label><select name="package_id" class="form-control" required><?php while($package = mysqli_fetch_assoc($packages)){ ?><option value="<?= (int)$package['id']; ?>" <?= (int)$invoice['package_id'] === (int)$package['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($package['package_name']); ?></option><?php } ?></select></div>
            <div class="col-md-4 form-group"><label>Amount (BDT)</label><input type="number" min="0.01" step="0.01" name="amount" class="form-control" value="<?= htmlspecialchars($invoice['amount']); ?>" required></div>
            <div class="col-md-4 form-group"><label>Wallet</label><select name="wallet_id" class="form-control" required><?php while($wallet = mysqli_fetch_assoc($wallets)){ ?><option value="<?= (int)$wallet['id']; ?>" <?= (int)$invoice['wallet_id'] === (int)$wallet['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($wallet['wallet_name']); ?></option><?php } ?></select></div>
            <?php while($charge=mysqli_fetch_assoc($invoice_charges)){ ?><div class="col-md-4 form-group"><label><?=htmlspecialchars($charge['charge_name'])?> (<?= $charge['charge_type']==='less'?'Less':'Add' ?><?= $charge['charge_value_type']==='percent'?', %':'' ?>)</label><input type="number" min="0" step="0.01" class="form-control" name="charge_value[<?= (int)$charge['id'] ?>]" value="<?=htmlspecialchars($saved_charge_values[$charge['id']] ?? '')?>"></div><?php } ?>
            <?php foreach($inactive_invoice_charges as $inactive_charge){ ?><div class="col-md-4 form-group"><label><?= htmlspecialchars($inactive_charge['charge_name']) ?> <span class="badge badge-secondary">Inactive</span></label><input type="number" class="form-control" value="<?= htmlspecialchars($inactive_charge['input_value']) ?>" readonly><small class="text-muted">Historical charge — retained on update.</small></div><?php } ?>
            <div class="col-md-12 form-group"><label>Note</label><textarea name="notes" rows="3" class="form-control"><?= htmlspecialchars($invoice['notes'] ?? ''); ?></textarea></div>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Update Invoice</button>
        <a href="invoice_list.php" class="btn btn-secondary">Back</a>
    </form>
</div>
<?php require_once '../includes/footer.php'; ?>
