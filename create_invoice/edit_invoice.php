<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/booking_invoice_helper.php';
require_once '../includes/project_package_helper.php';
require_once '../includes/branch_context_helper.php';

require_sales_access();
$user_id = (int)$_SESSION['user_id'];
ensure_branch_accounting_columns($conn, $user_id);
$branch_scope = branch_scope_sql($conn, 'booking_invoices');
ensure_booking_invoice_table($conn);
ensure_booking_invoice_type_table($conn, $user_id);
$project_package_labels = project_package_labels($conn, $user_id);

$invoice_id = (int)($_GET['id'] ?? $_POST['invoice_id'] ?? 0);
if($invoice_id <= 0){
    header('Location: invoice_list.php');
    exit;
}

$invoice_stmt = mysqli_prepare($conn, "SELECT * FROM booking_invoices WHERE id=? AND user_id=? {$branch_scope} LIMIT 1");
mysqli_stmt_bind_param($invoice_stmt, 'ii', $invoice_id, $user_id);
mysqli_stmt_execute($invoice_stmt);
$invoice = mysqli_fetch_assoc(mysqli_stmt_get_result($invoice_stmt));
if(!$invoice){
    die('Invoice not found.');
}

$invoice_types = booking_invoice_types($conn, $user_id, false);
$total_invoice_type_keys = booking_invoice_total_type_keys($conn, $user_id, $invoice_types);
$is_housing_company = project_package_company_type($conn, $user_id) === 'Housing';
$cash_wallet_id = ensure_default_cash_wallet($conn, $user_id);
$message = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $project_id = (int)($_POST['project_id'] ?? 0);
    $package_id = (int)($_POST['package_id'] ?? 0);
    $wallet_id = (int)($_POST['wallet_id'] ?? $cash_wallet_id);
    $invoice_type = trim((string)($_POST['invoice_type'] ?? ''));
    $invoice_date = trim((string)($_POST['invoice_date'] ?? ''));
    $normalized_invoice_date = booking_invoice_normalize_date($invoice_date);
    $amount = (float)($_POST['amount'] ?? 0);
    $total_price = trim((string)($_POST['total_price'] ?? ''));
    $charge_inputs = $_POST['charge_value'] ?? [];
    $preserved_charge_rows = [];
    $preserved_stmt = mysqli_prepare($conn, "SELECT bic.charge_type_id, bic.charge_name, bic.charge_type, bic.charge_value_type, bic.input_value, bic.charge_amount FROM booking_invoice_charges bic LEFT JOIN invoice_charge_types ict ON ict.id=bic.charge_type_id AND ict.user_id=? AND ict.status='active' AND ict.show_on_invoice=1 WHERE bic.booking_invoice_id=? AND ict.id IS NULL");
    mysqli_stmt_bind_param($preserved_stmt, 'ii', $user_id, $invoice_id); mysqli_stmt_execute($preserved_stmt);
    $preserved_result = mysqli_stmt_get_result($preserved_stmt);
    while($saved_charge = mysqli_fetch_assoc($preserved_result)){
        $preserved_charge_rows[] = ['charge'=>['id'=>(int)$saved_charge['charge_type_id'], 'charge_name'=>$saved_charge['charge_name'], 'charge_type'=>$saved_charge['charge_type'], 'charge_value_type'=>$saved_charge['charge_value_type']], 'input_value'=>(float)$saved_charge['input_value'], 'amount'=>(float)$saved_charge['charge_amount']];
    }
    $charge_calculation = booking_invoice_charge_total($conn, $user_id, $amount, $charge_inputs, $preserved_charge_rows); $final_amount = $charge_calculation['total'];
    $invoice_type_establishes_total = booking_invoice_establishes_total($conn, $user_id, $invoice_type);
    $numeric_total_price = $invoice_type_establishes_total ? (float)$total_price : 0;
    $notes = trim((string)($_POST['notes'] ?? ''));
    $property_values = [
        'block_name' => trim((string)($_POST['block_name'] ?? $_POST['block_name_select'] ?? '')),
        'road_no' => trim((string)($_POST['road_no'] ?? $_POST['road_no_select'] ?? '')),
        'plot_no' => trim((string)($_POST['plot_no'] ?? $_POST['plot_no_select'] ?? '')),
        'file_no' => trim((string)($_POST['file_no'] ?? $_POST['file_no_select'] ?? '')),
    ];
    $adjustment_by_file = $is_housing_company && in_array($invoice_type, ['installment', 'cancel_return'], true);
    if($adjustment_by_file && $property_values['file_no'] !== ''){
        $source_stmt = mysqli_prepare($conn, "SELECT project_id, package_id, block_name, road_no, plot_no FROM booking_invoices WHERE user_id=? AND branch_id=? AND file_no=? AND customer_id=? AND status='confirmed' AND invoice_type IN ('booking', 'full_payment') AND id<>? ORDER BY id DESC LIMIT 1");
        $branch_id = (int)($invoice['branch_id'] ?? 0);
        mysqli_stmt_bind_param($source_stmt, 'iisii', $user_id, $branch_id, $property_values['file_no'], $customer_id, $invoice_id);
        mysqli_stmt_execute($source_stmt);
        $source = mysqli_fetch_assoc(mysqli_stmt_get_result($source_stmt));
        if($source){
            $project_id = (int)$source['project_id'];
            $package_id = (int)$source['package_id'];
            foreach(['block_name', 'road_no', 'plot_no'] as $field) $property_values[$field] = trim((string)($source[$field] ?? ''));
        } else {
            $message = 'Select a File No. created by a Booking or Full Payment invoice.';
        }
    }

    if($message === '' && $is_housing_company && in_array($invoice_type, ['booking', 'full_payment'], true) && $property_values['file_no'] !== ''){
        $duplicate_file_stmt = mysqli_prepare($conn, "SELECT id FROM booking_invoices WHERE user_id=? AND file_no=? AND id<>? LIMIT 1");
        mysqli_stmt_bind_param($duplicate_file_stmt, 'isi', $user_id, $property_values['file_no'], $invoice_id);
        mysqli_stmt_execute($duplicate_file_stmt);
        if(mysqli_fetch_assoc(mysqli_stmt_get_result($duplicate_file_stmt))) $message = 'This File No. is already used. Select a non-used File No.';
    }

    if($message === '' && $is_housing_company && in_array($invoice_type, ['booking', 'full_payment'], true) && $project_id > 0 && $package_id > 0){
        $duplicate_plot_stmt = mysqli_prepare($conn, "SELECT id, file_no FROM booking_invoices WHERE user_id=? AND project_id=? AND package_id=? AND block_name=? AND road_no=? AND plot_no=? AND invoice_type IN ('booking', 'full_payment') AND id<>? LIMIT 1");
        mysqli_stmt_bind_param($duplicate_plot_stmt, 'iiisssi', $user_id, $project_id, $package_id, $property_values['block_name'], $property_values['road_no'], $property_values['plot_no'], $invoice_id);
        mysqli_stmt_execute($duplicate_plot_stmt);
        $duplicate_plot = mysqli_fetch_assoc(mysqli_stmt_get_result($duplicate_plot_stmt));
        if($duplicate_plot) $message = 'This Project, Package and Plot Details are already assigned to File No. ' . trim((string)($duplicate_plot['file_no'] ?? '-')) . '.';
    }

    if($message !== ''){
        // Keep the File No. validation message.
    } elseif($customer_id <= 0 || $project_id <= 0 || $package_id <= 0 || $wallet_id <= 0 || $amount <= 0 || $final_amount <= 0 || ($invoice_type_establishes_total && $numeric_total_price <= 0) || !isset($invoice_types[$invoice_type]) || $normalized_invoice_date === ''){
        $message = 'Please complete all invoice fields correctly.';
    } elseif($is_housing_company && (!$property_values['block_name'] || !$property_values['road_no'] || !$property_values['plot_no'] || !$property_values['file_no'])){
        $message = 'Block, Road No., Plot No. and File No. are required for Housing invoices.';
    }else{
        mysqli_begin_transaction($conn);
        try{
            $lock_stmt = mysqli_prepare($conn, "SELECT * FROM booking_invoices WHERE id=? AND user_id=? {$branch_scope} FOR UPDATE");
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
                'UPDATE booking_invoices SET customer_id=?, project_id=?, package_id=?, block_name=?, road_no=?, plot_no=?, file_no=?, wallet_id=?, invoice_type=?, invoice_date=?, amount=?, total_price=?, notes=?, status=\'pending\', wallet_effect_applied=0, confirmed_at=NULL WHERE id=? AND user_id=?'
            );
            mysqli_stmt_bind_param($update_stmt, 'iiissssissddsii', $customer_id, $project_id, $package_id, $property_values['block_name'], $property_values['road_no'], $property_values['plot_no'], $property_values['file_no'], $wallet_id, $invoice_type, $normalized_invoice_date, $final_amount, $numeric_total_price, $notes, $invoice_id, $user_id);
            if(!mysqli_stmt_execute($update_stmt)){
                throw new Exception(mysqli_stmt_error($update_stmt));
            }
            if($is_housing_company) booking_invoice_store_property_options($conn, $user_id, $property_values);
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
                $updated_invoice['invoice_date'] = $normalized_invoice_date;
                $updated_invoice['amount'] = $final_amount;
                $updated_invoice['total_price'] = $numeric_total_price;
                $updated_invoice['notes'] = $notes;
                foreach($property_values as $field => $value) $updated_invoice[$field] = $value;
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

    $invoice = array_merge($invoice, compact('customer_id', 'project_id', 'package_id', 'wallet_id', 'invoice_type', 'invoice_date', 'amount', 'total_price', 'notes', 'property_values'));
    foreach($property_values as $field => $value) $invoice[$field] = $value;
}

$customers = mysqli_query($conn, "SELECT id, customer_name, customer_code FROM customers WHERE user_id={$user_id} AND status='active' ORDER BY customer_name");
$projects = mysqli_query($conn, "SELECT id, project_name FROM projects WHERE user_id={$user_id} AND status='active' ORDER BY project_name");
$packages = mysqli_query($conn, "SELECT id, project_id, package_name, price FROM packages WHERE user_id={$user_id} AND status='active' ORDER BY package_name");
$wallets = mysqli_query($conn, "SELECT id, wallet_name FROM wallets WHERE user_id={$user_id} AND status='active' ORDER BY is_system DESC, wallet_name");
$invoice_charges = booking_invoice_active_charges($conn, $user_id);
$property_options = $is_housing_company ? booking_invoice_property_options($conn, $user_id) : [];
$file_property_map = [];
if($is_housing_company){
    $source_properties = mysqli_query($conn, "SELECT bi.customer_id, bi.project_id, bi.package_id, bi.block_name, bi.road_no, bi.plot_no, bi.file_no, COALESCE(NULLIF(bi.total_price, 0), 0) AS total_amount, COALESCE((SELECT SUM(CASE WHEN related.invoice_type='cancel_return' THEN -related.amount ELSE related.amount END) FROM booking_invoices related WHERE related.user_id=bi.user_id AND related.file_no=bi.file_no AND related.status='confirmed'), 0) AS paid_amount FROM booking_invoices bi WHERE bi.user_id={$user_id} AND bi.invoice_type IN ('booking', 'full_payment') AND bi.status='confirmed' AND bi.file_no IS NOT NULL AND TRIM(bi.file_no)<>'' ORDER BY bi.id DESC");
    while($source_properties && $source_row = mysqli_fetch_assoc($source_properties)){
        $file_key = trim((string)$source_row['file_no']);
        if(!isset($file_property_map[$file_key])) $file_property_map[$file_key] = ['customer_id'=>(string)(int)$source_row['customer_id'], 'project_id'=>(string)(int)$source_row['project_id'], 'package_id'=>(string)(int)$source_row['package_id'], 'block_name'=>(string)$source_row['block_name'], 'road_no'=>(string)$source_row['road_no'], 'plot_no'=>(string)$source_row['plot_no'], 'total_amount'=>(float)$source_row['total_amount'], 'paid_amount'=>(float)$source_row['paid_amount']];
    }
    foreach($file_property_map as $file_key => $_source) if(!in_array($file_key, $property_options['file_no'], true)) $property_options['file_no'][] = $file_key;
}
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
    <form method="post" class="card-body" id="edit-invoice-form">
        <input type="hidden" name="invoice_id" value="<?= $invoice_id; ?>">
        <?php if($message !== ''){ ?><div class="alert alert-danger"><?= htmlspecialchars($message); ?></div><?php } ?>
        <div class="row">
            <div class="col-md-3 form-group"><label>Date</label><div class="input-group"><input type="text" id="invoice-date-display" name="invoice_date" class="form-control" value="<?= htmlspecialchars(booking_invoice_display_date($invoice['invoice_date'])); ?>" placeholder="DD-MM-YYYY" pattern="\d{2}-\d{2}-\d{4}" required><div class="input-group-append"><input type="date" id="invoice-date-picker" class="form-control" value="<?= htmlspecialchars(booking_invoice_normalize_date($invoice['invoice_date'])); ?>" aria-label="Choose payment date" style="max-width:52px; padding:4px;"></div></div></div>
            <div class="col-md-3 form-group"><label>Customer Name</label><select id="customer_id" name="customer_id" class="form-control" required><?php while($customer = mysqli_fetch_assoc($customers)){ ?><option value="<?= (int)$customer['id']; ?>" <?= (int)$invoice['customer_id'] === (int)$customer['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($customer['customer_name'] . (!empty($customer['customer_code']) ? ' (' . $customer['customer_code'] . ')' : '')); ?></option><?php } ?></select></div>
            <div class="col-md-3 form-group"><label>Payment Type</label><select id="invoice_type" name="invoice_type" class="form-control" required><?php foreach($invoice_types as $type_key => $type_name){ ?><option value="<?= htmlspecialchars($type_key); ?>" <?= $invoice['invoice_type'] === $type_key ? 'selected' : ''; ?>><?= htmlspecialchars($type_name); ?></option><?php } ?></select></div>
            <?php if($is_housing_company){ $property_field='file_no'; $property_label='File No.'; ?><div class="col-md-3 form-group"><label><?= $property_label; ?></label><select id="file_no_select" name="file_no_select" class="form-control housing-property-select" data-property-field="file_no" required><option value="">Select File No.</option><?php foreach($property_options['file_no'] as $property_option){ ?><option value="<?= htmlspecialchars($property_option); ?>" <?= (string)($invoice['file_no'] ?? '') === (string)$property_option ? 'selected' : ''; ?>><?= htmlspecialchars($property_option); ?></option><?php } ?></select><input type="hidden" id="file_no" name="file_no" value="<?= htmlspecialchars((string)($invoice['file_no'] ?? '')); ?>"></div><?php } ?>
            <div class="col-md-3 form-group"><label><?= htmlspecialchars($project_package_labels['project']); ?></label><select id="project_id" name="project_id" class="form-control" required><?php while($project = mysqli_fetch_assoc($projects)){ ?><option value="<?= (int)$project['id']; ?>" <?= (int)$invoice['project_id'] === (int)$project['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($project['project_name']); ?></option><?php } ?></select></div>
            <div class="col-md-3 form-group"><label><?= htmlspecialchars($project_package_labels['package']); ?></label><select id="package_id" name="package_id" class="form-control" required><?php while($package = mysqli_fetch_assoc($packages)){ ?><option value="<?= (int)$package['id']; ?>" data-project-id="<?= (int)$package['project_id']; ?>" data-price="<?= htmlspecialchars(number_format((float)$package['price'], 2, '.', '')); ?>" <?= (int)$invoice['package_id'] === (int)$package['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($package['package_name']); ?></option><?php } ?></select></div>
            <?php foreach(['block_name' => 'Block', 'road_no' => 'Road No.', 'plot_no' => 'Plot No.'] as $property_field => $property_label){ ?><div class="col-md-<?= $property_field === 'block_name' ? '3' : '4'; ?> form-group<?= !$is_housing_company ? ' d-none' : ''; ?>"><label><?= htmlspecialchars($property_label); ?></label><select id="<?= htmlspecialchars($property_field); ?>_select" name="<?= htmlspecialchars($property_field); ?>_select" class="form-control housing-property-select" data-property-field="<?= htmlspecialchars($property_field); ?>" <?= $is_housing_company ? 'required' : ''; ?>><option value="">Select <?= htmlspecialchars($property_label); ?></option><?php foreach(($property_options[$property_field] ?? []) as $property_option){ ?><option value="<?= htmlspecialchars($property_option); ?>" <?= (string)($invoice[$property_field] ?? '') === (string)$property_option ? 'selected' : ''; ?>><?= htmlspecialchars($property_option); ?></option><?php } ?></select><input type="hidden" id="<?= htmlspecialchars($property_field); ?>" name="<?= htmlspecialchars($property_field); ?>" value="<?= htmlspecialchars((string)($invoice[$property_field] ?? '')); ?>"></div><?php } ?>
            <div class="col-md-4 form-group" id="total-price-group" style="display:none;"><label>Total Price (BDT)</label><input id="total_price" type="number" min="0.01" step="0.01" name="total_price" class="form-control" value="<?= htmlspecialchars(($invoice['total_price'] ?? 0) > 0 ? $invoice['total_price'] : $invoice['amount']); ?>"></div>
            <div class="col-md-4 form-group"><label>Pay Amount (BDT)</label><input id="amount" type="number" min="0.01" step="0.01" name="amount" class="form-control" value="<?= htmlspecialchars($invoice['amount']); ?>" required><small id="file-balance-info" class="form-text text-muted"></small></div>
            <div class="col-md-4 form-group"><label>Wallet</label><select name="wallet_id" class="form-control" required><?php while($wallet = mysqli_fetch_assoc($wallets)){ ?><option value="<?= (int)$wallet['id']; ?>" <?= (int)$invoice['wallet_id'] === (int)$wallet['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($wallet['wallet_name']); ?></option><?php } ?></select></div>
            <?php while($charge=mysqli_fetch_assoc($invoice_charges)){ ?><div class="col-md-4 form-group"><label><?=htmlspecialchars($charge['charge_name'])?> (<?= $charge['charge_type']==='less'?'Less':'Add' ?><?= $charge['charge_value_type']==='percent'?', %':'' ?>)</label><input type="number" min="0" step="0.01" class="form-control" name="charge_value[<?= (int)$charge['id'] ?>]" value="<?=htmlspecialchars($saved_charge_values[$charge['id']] ?? '')?>"></div><?php } ?>
            <?php foreach($inactive_invoice_charges as $inactive_charge){ ?><div class="col-md-4 form-group"><label><?= htmlspecialchars($inactive_charge['charge_name']) ?> <span class="badge badge-secondary">Inactive</span></label><input type="number" class="form-control" value="<?= htmlspecialchars($inactive_charge['input_value']) ?>" readonly><small class="text-muted">Historical charge — retained on update.</small></div><?php } ?>
            <div class="col-md-12 form-group"><label>Note</label><textarea name="notes" rows="3" class="form-control"><?= htmlspecialchars($invoice['notes'] ?? ''); ?></textarea></div>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Update Invoice</button>
        <a href="invoice_list.php" class="btn btn-secondary">Back</a>
    </form>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const totalInvoiceTypes = <?= json_encode(array_values($total_invoice_type_keys)); ?>;
    const projectSelect = document.getElementById('project_id');
    const packageSelect = document.getElementById('package_id');
    const customerSelect = document.getElementById('customer_id');
    const invoiceTypeSelect = document.getElementById('invoice_type');
    const totalPriceGroup = document.getElementById('total-price-group');
    const totalPriceInput = document.getElementById('total_price');
    const invoiceDateDisplay = document.getElementById('invoice-date-display');
    const invoiceDatePicker = document.getElementById('invoice-date-picker');
    const filePropertyMap = <?= json_encode($file_property_map); ?>;
    const plotAssignments = Object.values(filePropertyMap);
    const housingPropertySelects = Array.from(document.querySelectorAll('.housing-property-select'));

    function setHousingPropertyValue(field, value) {
        const select = document.getElementById(field + '_select');
        const hidden = document.getElementById(field);
        if (!select || !hidden) return;
        value = String(value || '').trim();
        if (value && !Array.from(select.options).some(function(option){ return option.value === value; })) select.add(new Option(value, value, false, false));
        select.value = value;
        hidden.value = value;
    }

    function syncHousingProperties() {
        if (!housingPropertySelects.length) return;
        const adjustment = ['installment', 'cancel_return'].includes(invoiceTypeSelect.value);
        const selectedFile = String((document.getElementById('file_no_select') || {}).value || '').trim();
        const source = adjustment && selectedFile ? filePropertyMap[selectedFile] : null;
        const lockFields = !!source;
        if (source) {
            projectSelect.value = source.project_id || '';
            filterPackages();
            packageSelect.value = source.package_id || '';
            ['block_name', 'road_no', 'plot_no'].forEach(function(field){ setHousingPropertyValue(field, source[field] || ''); });
        }
        const balanceInfo = document.getElementById('file-balance-info');
        if (balanceInfo) {
            if (source) {
                const paid = Number(source.paid_amount || 0);
                const due = Math.max(0, Number(source.total_amount || 0) - paid);
                balanceInfo.textContent = 'Total Paid: BDT ' + paid.toFixed(2) + ' | Due: BDT ' + due.toFixed(2);
            } else balanceInfo.textContent = '';
        }
        [projectSelect, packageSelect].forEach(function(select){
            select.disabled = lockFields;
            select.classList.toggle('bg-light', lockFields);
            select.setAttribute('aria-readonly', lockFields ? 'true' : 'false');
        });
        housingPropertySelects.forEach(function(select){
            const isFile = select.dataset.propertyField === 'file_no';
            if (isFile) {
                Array.from(select.options).forEach(function(option, index){
                    if (index === 0) return;
                    const optionSource = filePropertyMap[String(option.value)];
                    option.hidden = adjustment && (!optionSource || String(optionSource.customer_id || '') !== String(customerSelect.value || ''));
                });
                if (select.value && select.options[select.selectedIndex] && select.options[select.selectedIndex].hidden) setHousingPropertyValue('file_no', '');
            }
            if (select.dataset.propertyField === 'plot_no') {
                const selectedBlock = String((document.getElementById('block_name_select') || {}).value || '').trim();
                const selectedRoad = String((document.getElementById('road_no_select') || {}).value || '').trim();
                const restrictUsedPlots = ['booking', 'full_payment', 'installment', 'cancel_return'].includes(invoiceTypeSelect.value);
                Array.from(select.options).forEach(function(option, index){
                    if (index === 0) return;
                    const isUsedPlot = plotAssignments.some(function(item){
                        return String(item.project_id) === String(projectSelect.value) && String(item.package_id) === String(packageSelect.value) && String(item.block_name || '') === selectedBlock && String(item.road_no || '') === selectedRoad && String(item.plot_no || '') === String(option.value);
                    });
                    option.hidden = restrictUsedPlots && isUsedPlot && !(source && String(source.plot_no || '') === String(option.value));
                });
            }
            select.disabled = !isFile && lockFields;
            select.classList.toggle('bg-light', !isFile && lockFields);
            select.setAttribute('aria-readonly', !isFile && lockFields ? 'true' : 'false');
            const hidden = document.getElementById(select.dataset.propertyField);
            if (hidden) hidden.value = select.value || '';
        });
    }

    function invoiceDateToDisplay(value) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return '';
        const parts = value.split('-');
        return parts[2] + '-' + parts[1] + '-' + parts[0];
    }
    function invoiceDateToPicker(value) {
        const match = /^(\d{2})-(\d{2})-(\d{4})$/.exec((value || '').trim());
        return match ? match[3] + '-' + match[2] + '-' + match[1] : '';
    }
    invoiceDatePicker.addEventListener('change', function () {
        invoiceDateDisplay.value = invoiceDateToDisplay(this.value);
    });
    invoiceDateDisplay.addEventListener('change', function () {
        const value = invoiceDateToPicker(this.value);
        if (value) invoiceDatePicker.value = value;
    });

    function filterPackages() {
        const selectedProject = projectSelect.value;
        let hasVisibleSelected = false;

        Array.from(packageSelect.options).forEach(function (option) {
            const matches = !selectedProject || option.dataset.projectId === selectedProject;
            option.hidden = !matches;

            if (option.selected && matches) {
                hasVisibleSelected = true;
            }
        });

        if (!hasVisibleSelected) {
            packageSelect.value = '';
        }
    }

    function syncPackagePrice() {
        const selectedOption = packageSelect.options[packageSelect.selectedIndex];
        if (selectedOption && selectedOption.dataset.price) {
            if (!totalPriceInput.value || totalInvoiceTypes.includes(invoiceTypeSelect.value)) {
                totalPriceInput.value = selectedOption.dataset.price;
            }
        }
    }

    function toggleTotalPrice() {
        const needsTotalPrice = totalInvoiceTypes.includes(invoiceTypeSelect.value);
        totalPriceGroup.style.display = needsTotalPrice ? '' : 'none';
        totalPriceInput.required = needsTotalPrice;

        if (needsTotalPrice && !totalPriceInput.value) {
            const selectedOption = packageSelect.options[packageSelect.selectedIndex];
            if (selectedOption && selectedOption.dataset.price) {
                totalPriceInput.value = selectedOption.dataset.price;
            }
        }
    }

    projectSelect.addEventListener('change', filterPackages);
    packageSelect.addEventListener('change', syncPackagePrice);
    invoiceTypeSelect.addEventListener('change', toggleTotalPrice);
    invoiceTypeSelect.addEventListener('change', syncHousingProperties);
    customerSelect.addEventListener('change', syncHousingProperties);
    housingPropertySelects.forEach(function(select){
        select.addEventListener('change', function(){
            const hidden = document.getElementById(this.dataset.propertyField);
            if (hidden) hidden.value = this.value || '';
            syncHousingProperties();
        });
    });
    filterPackages();
    toggleTotalPrice();
    syncHousingProperties();
});
</script>
<?php require_once '../includes/footer.php'; ?>
