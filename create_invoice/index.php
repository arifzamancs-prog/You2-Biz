<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/project_package_helper.php';
require_once '../includes/booking_invoice_helper.php';

require_sales_access();

$user_id = (int)$_SESSION['user_id'];
$created_by_user_id = (int)($_SESSION['login_user_id'] ?? $user_id);

ensure_project_package_tables($conn);
ensure_booking_invoice_table($conn);
ensure_booking_invoice_type_table($conn, $user_id);
$project_package_labels = project_package_labels($conn, $user_id);

$invoice_types = booking_invoice_types($conn, $user_id);
$total_invoice_type_keys = booking_invoice_total_type_keys($conn, $user_id, $invoice_types);
$adjustment_invoice_type_keys = booking_invoice_adjustment_type_keys($conn, $user_id, $invoice_types);
$total_invoice_type_sql = "'" . implode("','", array_map(static function($type_key) use ($conn){
    return mysqli_real_escape_string($conn, $type_key);
}, $total_invoice_type_keys)) . "'";
$payment_type_customers = [];
$customer_projects = [];
$customer_packages = [];
$ptc = mysqli_query($conn, "SELECT DISTINCT customer_id, invoice_type FROM booking_invoices WHERE user_id={$user_id} AND status='confirmed'");
while($ptc && $pr = mysqli_fetch_assoc($ptc)){ $payment_type_customers[$pr['invoice_type']][] = (int)$pr['customer_id']; }
$purchase_map = mysqli_query($conn, "SELECT bi.customer_id, bi.project_id, bi.package_id, bi.invoice_date, pk.package_name, COALESCE(NULLIF(bi.total_price,0),pk.price,bi.amount) AS total_amount, (SELECT COALESCE(SUM(adj.amount),0) FROM booking_invoices adj WHERE adj.user_id=bi.user_id AND adj.customer_id=bi.customer_id AND adj.project_id=bi.project_id AND adj.package_id=bi.package_id AND adj.status='confirmed') AS paid_amount FROM booking_invoices bi LEFT JOIN packages pk ON pk.id=bi.package_id AND pk.user_id=bi.user_id WHERE bi.user_id={$user_id} AND bi.status='confirmed' AND bi.invoice_type IN ({$total_invoice_type_sql}) ORDER BY bi.invoice_date DESC");
while($purchase_map && $pm = mysqli_fetch_assoc($purchase_map)){ $c=(int)$pm['customer_id']; $p=(int)$pm['project_id']; $customer_projects[$c][]=$p; $customer_packages[$c][$p][]=['id'=>(int)$pm['package_id'],'date'=>date('d-m-Y',strtotime($pm['invoice_date'])),'name'=>(string)($pm['package_name'] ?? ''),'total'=>(float)$pm['total_amount'],'paid'=>(float)$pm['paid_amount']]; }
$type = '';
$display_type = isset($_GET['type']) ? normalize_booking_invoice_type($_GET['type'], $invoice_types) : 'booking';

$message = '';
$customer_id = (int)($_POST['customer_id'] ?? 0);
$project_id = (int)($_POST['project_id'] ?? 0);
$package_id = (int)($_POST['package_id'] ?? 0);
$cash_wallet_id = ensure_default_cash_wallet($conn, $user_id);
$wallet_id = (int)($_POST['wallet_id'] ?? $cash_wallet_id);
$invoice_date = trim($_POST['invoice_date'] ?? date('d-m-Y'));
$amount = trim($_POST['amount'] ?? '');
$total_price = trim($_POST['total_price'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$charge_inputs = $_POST['charge_value'] ?? [];

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $save_action = $_POST['save_action'] ?? 'save';
    $type = trim((string)($_POST['invoice_type'] ?? ''));
    if($type !== ''){
        $type = normalize_booking_invoice_type($type, $invoice_types);
    }
    $display_type = $type !== '' ? $type : 'booking';

    $normalized_date = booking_invoice_normalize_date($invoice_date);
    $numeric_amount = (float)$amount;
    $type_establishes_total = booking_invoice_establishes_total($conn, $user_id, $type);
    $numeric_total_price = $type_establishes_total ? (float)$total_price : 0;
    $charge_calculation = booking_invoice_charge_total($conn, $user_id, $numeric_amount, $charge_inputs);
    $final_amount = $charge_calculation['total'];

    if($customer_id <= 0 || $project_id <= 0 || $package_id <= 0 || $wallet_id <= 0 || $type === '' || $normalized_date === '' || $numeric_amount <= 0 || $final_amount <= 0 || ($type_establishes_total && $numeric_total_price <= 0)){
        $message = 'Customer, ' . $project_package_labels['project'] . ', ' . $project_package_labels['package'] . ', Payment Type, Date and valid Amount are required.';
    } else {
        $invoice_no = generate_booking_invoice_no($conn);

        $insert_stmt = mysqli_prepare(
            $conn,
            "INSERT INTO booking_invoices
             (user_id, invoice_no, customer_id, project_id, package_id, wallet_id, invoice_type, invoice_date, amount, total_price, notes, created_by_user_id)
             VALUES
             (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param(
            $insert_stmt,
            'isiiiissddsi',
            $user_id,
            $invoice_no,
            $customer_id,
            $project_id,
            $package_id,
            $wallet_id,
            $type,
            $normalized_date,
            $final_amount,
            $numeric_total_price,
            $notes,
            $created_by_user_id
        );

        if(mysqli_stmt_execute($insert_stmt)){
            $saved_id = (int)mysqli_insert_id($conn);
            $charge_insert = mysqli_prepare($conn, "INSERT INTO booking_invoice_charges (booking_invoice_id,charge_type_id,charge_name,charge_type,charge_value_type,input_value,charge_amount) VALUES (?,?,?,?,?,?,?)");
            foreach($charge_calculation['rows'] as $charge_row){ $c=$charge_row['charge']; mysqli_stmt_bind_param($charge_insert,'iisssdd',$saved_id,$c['id'],$c['charge_name'],$c['charge_type'],$c['charge_value_type'],$charge_row['input_value'],$charge_row['amount']); mysqli_stmt_execute($charge_insert); }
            if($save_action === 'save_print'){
                try {
                    confirm_booking_invoice($conn, $saved_id, $user_id);
                    header('Location: print.php?id=' . $saved_id);
                    exit;
                } catch(Throwable $error) {
                    $message = 'Invoice saved as Pending. It could not be confirmed: ' . $error->getMessage();
                }
            }else{
                header('Location: index.php?type=' . urlencode($type) . '&saved=pending');
                exit;
            }
        }else{
            $message = 'Failed to save invoice.';
        }
    }
}

$customers = [];
$customer_query = mysqli_query(
    $conn,
    "SELECT id, customer_name
     FROM customers
     WHERE user_id={$user_id}
     AND status='active'
     ORDER BY customer_name"
);
while($customer_query && $row = mysqli_fetch_assoc($customer_query)){
    $customers[] = $row;
}

$projects = [];
$project_query = mysqli_query(
    $conn,
    "SELECT id, project_name
     FROM projects
     WHERE user_id={$user_id}
     AND status='active'
     ORDER BY project_name"
);
while($project_query && $row = mysqli_fetch_assoc($project_query)){
    $projects[] = $row;
}

$packages = [];
$package_query = mysqli_query(
    $conn,
    "SELECT id, project_id, package_name, price
     FROM packages
     WHERE user_id={$user_id}
     AND status='active'
     ORDER BY package_name"
);
while($package_query && $row = mysqli_fetch_assoc($package_query)){
    $packages[] = $row;
}

$wallets = [];
$wallet_result = active_wallets_result($conn, $user_id);
while($wallet_result && $row = mysqli_fetch_assoc($wallet_result)){
    $wallets[] = $row;
}
$invoice_charges = booking_invoice_active_charges($conn, $user_id);

$recent_invoices = [];
$recent_stmt = mysqli_prepare(
    $conn,
    "SELECT bi.id,
            bi.invoice_no,
            bi.invoice_date,
            bi.amount,
            bi.status,
            c.customer_name,
            p.project_name,
            pk.package_name
     FROM booking_invoices bi
     LEFT JOIN customers c ON c.id = bi.customer_id AND c.user_id = bi.user_id
     LEFT JOIN projects p ON p.id = bi.project_id AND p.user_id = bi.user_id
     LEFT JOIN packages pk ON pk.id = bi.package_id AND pk.user_id = bi.user_id
     WHERE bi.user_id=?
     AND bi.invoice_type=?
     ORDER BY bi.id DESC
     LIMIT 50"
);
mysqli_stmt_bind_param($recent_stmt, 'is', $user_id, $display_type);
mysqli_stmt_execute($recent_stmt);
$recent_result = mysqli_stmt_get_result($recent_stmt);
while($recent_result && $row = mysqli_fetch_assoc($recent_result)){
    $recent_invoices[] = $row;
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-file-alt mr-2"></i>
            <?= htmlspecialchars(booking_invoice_page_title($display_type, $invoice_types)); ?>
        </h3>
    </div>

    <div class="card-body">
        <?php if($message){ ?>
            <div class="alert alert-danger"><?= htmlspecialchars($message); ?></div>
        <?php } ?>
        <?php if(isset($_GET['saved'])){ ?>
            <div class="alert alert-success">
                <?= ($_GET['saved'] ?? '') === 'confirmed'
                    ? 'Invoice saved and confirmed. Wallet balance has been updated and the print page opened in a new tab.'
                    : 'Invoice saved as Pending. Wallet balance will not change until it is confirmed.'; ?>
            </div>
            <script>if(window.history.replaceState){ const url=new URL(window.location.href); url.searchParams.delete('saved'); window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : '')); }</script>
        <?php } ?>

        <form method="post" id="create-invoice-form">
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Payment Date</label>
                        <div class="input-group">
                            <input type="text" id="invoice-date-display" name="invoice_date" class="form-control" value="<?= htmlspecialchars($invoice_date); ?>" placeholder="DD-MM-YYYY" pattern="\d{2}-\d{2}-\d{4}" required>
                            <div class="input-group-append">
                                <input type="date" id="invoice-date-picker" class="form-control" value="<?= htmlspecialchars(booking_invoice_normalize_date($invoice_date)); ?>" aria-label="Choose invoice date" style="max-width:52px; padding:4px;">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="form-group">
                        <label>Customer Name</label>
                        <select name="customer_id" class="form-control" required>
                            <option value="">Select Customer</option>
                            <?php foreach($customers as $customer){ ?>
                                <option value="<?= (int)$customer['id']; ?>" <?= $customer_id === (int)$customer['id'] ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($customer['customer_name']); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="form-group">
                        <label><?= htmlspecialchars($project_package_labels['project']); ?></label>
                        <select id="project_id" name="project_id" class="form-control" required>
                            <option value=""><?= htmlspecialchars($project_package_labels['project_select']); ?></option>
                            <?php foreach($projects as $project){ ?>
                                <option value="<?= (int)$project['id']; ?>" data-purchased-customers="<?= htmlspecialchars(implode(',', array_keys(array_filter($customer_projects, function($ps) use ($project){ return in_array((int)$project['id'], $ps, true); })))); ?>" <?= $project_id === (int)$project['id'] ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($project['project_name']); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="form-group">
                        <label><?= htmlspecialchars($project_package_labels['package']); ?></label>
                        <select id="package_id" name="package_id" class="form-control" required>
                            <option value="">Select <?= htmlspecialchars($project_package_labels['package']); ?></option>
                            <?php foreach($packages as $package){ ?>
                                <option
                                    value="<?= (int)$package['id']; ?>"
                                    data-project-id="<?= (int)$package['project_id']; ?>"
                                    data-price="<?= htmlspecialchars(number_format((float)$package['price'], 2, '.', '')); ?>"
                                    <?= $package_id === (int)$package['id'] ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($package['package_name']); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="form-group">
                        <label>Payment Type</label>
                        <select id="invoice_type" name="invoice_type" class="form-control" required <?= $customer_id > 0 ? '' : 'disabled'; ?> >
                            <option value="">Select Payment Type</option>
                            <?php foreach($invoice_types as $type_key => $type_name){ ?>
                                <option value="<?= htmlspecialchars($type_key); ?>" data-customer-ids="<?= htmlspecialchars(implode(',', $payment_type_customers[$type_key] ?? [])); ?>" <?= $type === $type_key ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($type_name); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

                <div class="col-md-4" id="total-price-group" style="display:none;">
                    <div class="form-group">
                        <label>Total Price (BDT)</label>
                        <input id="total_price" type="number" step="0.01" min="0" name="total_price" class="form-control" value="<?= htmlspecialchars($total_price); ?>">
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="form-group">
                        <label>Pay Amount (BDT)</label>
                        <input id="amount" type="number" step="0.01" min="0" name="amount" class="form-control" value="<?= htmlspecialchars($amount); ?>" required>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="form-group">
                        <label>Wallet</label>
                        <select id="wallet_id" name="wallet_id" class="form-control" required>
                            <?php foreach($wallets as $wallet){ ?>
                                <option value="<?= (int)$wallet['id']; ?>" data-balance="<?= htmlspecialchars(number_format((float)($wallet['balance'] ?? 0), 2, '.', '')); ?>" <?= $wallet_id === (int)$wallet['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($wallet['wallet_name']); ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

                <?php while($charge = mysqli_fetch_assoc($invoice_charges)){ ?><div class="col-md-4"><div class="form-group"><label><?= htmlspecialchars($charge['charge_name']) ?> (<?= $charge['charge_type']==='less'?'Less':'Add' ?><?= $charge['charge_value_type']==='percent'?', %':'' ?>)</label><input type="number" min="0" step="0.01" class="form-control invoice-charge-input" name="charge_value[<?= (int)$charge['id'] ?>]" value="<?= htmlspecialchars($charge_inputs[$charge['id']] ?? '') ?>"></div></div><?php } ?>

                <div class="col-md-12">
                    <div class="form-group">
                        <label>Note</label>
                        <textarea name="notes" class="form-control" rows="3"><?= htmlspecialchars($notes); ?></textarea>
                    </div>
                </div>
            </div>

            <button type="submit" name="save_action" value="save" class="btn btn-secondary">
                <i class="fas fa-save"></i>
                Save Invoice
            </button>
            <button type="submit" name="save_action" value="save_print" class="btn btn-primary">
                <i class="fas fa-save"></i>
                Save & Print Invoice
            </button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
            <h3 class="card-title"><?= htmlspecialchars(booking_invoice_recent_title($display_type, $invoice_types)); ?></h3>
    </div>

    <div class="card-body">
        <table id="example1" class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>Invoice No</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th><?= htmlspecialchars($project_package_labels['project']); ?></th>
                    <th><?= htmlspecialchars($project_package_labels['package']); ?></th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th width="90">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($recent_invoices)){ ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted">No data available in table</td>
                    </tr>
                <?php } else { ?>
                    <?php foreach($recent_invoices as $invoice){ ?>
                        <tr>
                            <td><?= htmlspecialchars($invoice['invoice_no']); ?></td>
                            <td><?= htmlspecialchars(date('d-m-Y', strtotime($invoice['invoice_date']))); ?></td>
                            <td><?= htmlspecialchars($invoice['customer_name'] ?: ('Missing Customer #' . (int)$invoice['customer_id'])); ?></td>
                            <td><?= htmlspecialchars($invoice['project_name'] ?: ('Missing ' . $project_package_labels['project'] . ' #' . (int)$invoice['project_id'])); ?></td>
                            <td><?= htmlspecialchars($invoice['package_name'] ?: ('Missing ' . $project_package_labels['package'] . ' #' . (int)$invoice['package_id'])); ?></td>
                            <td>BDT <?= htmlspecialchars(number_format((float)$invoice['amount'], 2)); ?></td>
                            <td><span class="badge badge-<?= ($invoice['status'] ?? 'pending') === 'confirmed' ? 'success' : 'warning'; ?>"><?= htmlspecialchars(ucfirst($invoice['status'] ?? 'pending')); ?></span></td>
                            <td>
                                <a href="print.php?id=<?= (int)$invoice['id']; ?>" class="btn btn-info btn-sm" target="_blank" rel="noopener" title="Print Invoice" aria-label="Print Invoice">
                                    <i class="fas fa-print"></i>
                                </a>
                            </td>
                        </tr>
                    <?php } ?>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const customerProjects = <?= json_encode($customer_projects); ?>;
    const customerPackages = <?= json_encode($customer_packages); ?>;
    const totalInvoiceTypes = <?= json_encode(array_values($total_invoice_type_keys)); ?>;
    const adjustmentInvoiceTypes = <?= json_encode(array_values($adjustment_invoice_type_keys)); ?>;
    const projectSelect = document.getElementById('project_id');
    const packageSelect = document.getElementById('package_id');
    const invoiceTypeSelect = document.getElementById('invoice_type');
    const totalPriceGroup = document.getElementById('total-price-group');
    const totalPriceInput = document.getElementById('total_price');
    const walletSelect = document.getElementById('wallet_id');
    const invoiceDateDisplay = document.getElementById('invoice-date-display');
    const invoiceDatePicker = document.getElementById('invoice-date-picker');

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

    function updateWalletBalance(){
        let el = document.getElementById('selected-wallet-balance');
        if(!el){ el=document.createElement('small'); el.id='selected-wallet-balance'; el.className='form-text text-muted'; walletSelect.parentNode.appendChild(el); }
        const show = invoiceTypeSelect.value === 'cancel_return' && projectSelect.value && packageSelect.value;
        const opt = walletSelect.options[walletSelect.selectedIndex];
        el.textContent = show && opt ? 'Selected wallet balance: ' + Number(opt.dataset.balance || 0).toFixed(2) : '';
    }

    function filterPackages() {
        const selectedProject = projectSelect.value;
        const customerId = document.querySelector('[name="customer_id"]').value;
        const adjustmentMode = adjustmentInvoiceTypes.includes(invoiceTypeSelect.value);
        const purchased = (customerPackages[customerId] && customerPackages[customerId][selectedProject]) || [];
        let hasVisibleSelected = false;

        // For adjustments, show every ledger purchase (including repeated
        // purchases of the same package on different dates) as its own option.
        Array.from(packageSelect.querySelectorAll('.purchased-package-option')).forEach(function(option){ option.remove(); });
        if (adjustmentMode && selectedProject) {
            purchased.forEach(function(item){
                const base = Array.from(packageSelect.options).find(function(option){ return Number(option.value) === Number(item.id) && !option.classList.contains('purchased-package-option'); });
                if (!base) return;
                const option = base.cloneNode(true);
                option.className = 'purchased-package-option';
                option.textContent = (item.name || base.textContent.trim()) + ' (' + item.date + ')';
                option.dataset.total = item.total;
                option.dataset.paid = item.paid;
                option.dataset.due = Math.max(0, item.total - item.paid);
                option.hidden = false;
                packageSelect.appendChild(option);
            });
        }

        Array.from(packageSelect.options).forEach(function (option, index) {
            if (index === 0) {
                option.hidden = false;
                return;
            }

            const purchasedMatch = purchased.some(function(item){ return Number(item.id) === Number(option.value); });
            const matches = !!selectedProject && option.dataset.projectId === selectedProject && (!adjustmentMode || (purchasedMatch && option.classList.contains('purchased-package-option')));
            option.hidden = !matches;
            if (matches && adjustmentMode) {
                const item = purchased.find(function(entry){ return Number(entry.id) === Number(option.value); });
                if (item) option.textContent = item.name + ' (' + item.date + ')';
            }

            if (option.selected && matches) {
                hasVisibleSelected = true;
            }
        });

        if (!hasVisibleSelected) {
            packageSelect.value = '';
        }

        let balance = document.getElementById('selected-package-balance');
        if (!balance) {
            balance = document.createElement('small');
            balance.id = 'selected-package-balance';
            balance.className = 'form-text text-muted';
            packageSelect.parentNode.appendChild(balance);
        }
        const selected = packageSelect.options[packageSelect.selectedIndex];
        balance.textContent = adjustmentMode && selected && selected.dataset.total
            ? 'Total Amount: ' + Number(selected.dataset.total).toFixed(2) + ' | Total Paid: ' + Number(selected.dataset.paid).toFixed(2) + ' | Total Due: ' + Number(selected.dataset.due).toFixed(2)
            : '';
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
    packageSelect.addEventListener('change', function(){
        syncPackagePrice();
        const selected = packageSelect.options[packageSelect.selectedIndex];
        const balance = document.getElementById('selected-package-balance');
        if (balance) balance.textContent = adjustmentInvoiceTypes.includes(invoiceTypeSelect.value) && selected && selected.dataset.total
            ? 'Total Amount: ' + Number(selected.dataset.total).toFixed(2) + ' | Total Paid: ' + Number(selected.dataset.paid).toFixed(2) + ' | Total Due: ' + Number(selected.dataset.due).toFixed(2) : '';
    });
    invoiceTypeSelect.addEventListener('change', toggleTotalPrice);
    walletSelect.addEventListener('change', updateWalletBalance);
    invoiceTypeSelect.addEventListener('change', function(){
        const restrictProjects=adjustmentInvoiceTypes.includes(this.value), c=document.querySelector('[name="customer_id"]').value;
        const balance = document.getElementById('selected-package-balance');
        if (balance && !restrictProjects) balance.textContent = '';
        projectSelect.value = '';
        packageSelect.value = '';
        Array.from(projectSelect.options).forEach(function(o,i){ if(i) o.hidden=restrictProjects && !!c && !((customerProjects[c]||[]).includes(Number(o.value))); });
        Array.from(packageSelect.options).forEach(function(o,i){ if(i) o.hidden = i > 0; });
    });
    projectSelect.addEventListener('change', function(){
        const c=document.querySelector('[name="customer_id"]').value;
        if(adjustmentInvoiceTypes.includes(invoiceTypeSelect.value)) filterPackages();
        updateWalletBalance();
    });
    packageSelect.addEventListener('change', updateWalletBalance);
    document.querySelector('[name="customer_id"]').addEventListener('change', function(){
        invoiceTypeSelect.disabled = !this.value;
        projectSelect.value = '';
        packageSelect.value = '';
        Array.from(projectSelect.options).forEach(function(o,i){ if(i) o.hidden = false; });
        Array.from(packageSelect.options).forEach(function(o,i){ if(i) o.hidden = true; });
        const balance = document.getElementById('selected-package-balance');
        if (balance) balance.textContent = '';
        const restrictProjects = adjustmentInvoiceTypes.includes(invoiceTypeSelect.value);
        Array.from(projectSelect.options).forEach(function(o,i){ if(i) o.hidden=restrictProjects && !!this.value && !((customerProjects[this.value]||[]).includes(Number(o.value))); }, this);
        if(!this.value){ invoiceTypeSelect.value = ''; toggleTotalPrice(); }
        updateWalletBalance();
    });

    document.getElementById('create-invoice-form').addEventListener('submit', function (event) {
        if (event.submitter && event.submitter.value === 'save_print') {
            this.target = '_blank';
            const invoiceType = document.querySelector('[name="invoice_type"]').value || 'booking';
            window.setTimeout(function () {
                window.location.href = window.location.pathname + '?type=' + encodeURIComponent(invoiceType) + '&saved=confirmed';
            }, 450);
        } else {
            this.target = '';
        }
    });

    filterPackages();
    toggleTotalPrice();
    updateWalletBalance();
});
</script>
<style>
#create-invoice-form > .row > div { order: 6; }
#create-invoice-form > .row > div:nth-child(1) { order: 1; }
#create-invoice-form > .row > div:nth-child(2) { order: 2; }
#create-invoice-form > .row > div:nth-child(5) { order: 3; }
#create-invoice-form > .row > div:nth-child(3) { order: 4; }
#create-invoice-form > .row > div:nth-child(4) { order: 5; }
</style>

<?php
require_once '../includes/footer.php';
?>
