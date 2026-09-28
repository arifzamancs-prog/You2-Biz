<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/project_package_helper.php';
require_once '../includes/booking_invoice_helper.php';
require_once '../includes/customer_portal_helper.php';

require_sales_access();

$user_id = (int)$_SESSION['user_id'];
$created_by_user_id = (int)($_SESSION['login_user_id'] ?? $user_id);
$branch_id = selected_branch_id($conn, true);
$branch_scope = branch_scope_sql($conn, 'bi');

ensure_project_package_tables($conn);
ensure_booking_invoice_table($conn);
ensure_booking_invoice_type_table($conn, $user_id);
ensure_invoice_request_table($conn);
$project_package_labels = project_package_labels($conn, $user_id);
$is_housing_company = project_package_company_type($conn, $user_id) === 'Housing';

if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject_invoice_request'){
    $reject_request_id = (int)($_POST['invoice_request_id'] ?? 0);
    $reject_stmt = mysqli_prepare($conn, "UPDATE invoice_requests SET status='rejected' WHERE id=? AND user_id=? AND status='pending'");
    mysqli_stmt_bind_param($reject_stmt, 'ii', $reject_request_id, $user_id);
    mysqli_stmt_execute($reject_stmt);
    header('Location: index.php?request_rejected=1');
    exit;
}

$invoice_types = booking_invoice_types($conn, $user_id);
$total_invoice_type_keys = booking_invoice_total_type_keys($conn, $user_id, $invoice_types);
$adjustment_invoice_type_keys = booking_invoice_adjustment_type_keys($conn, $user_id, $invoice_types);
$total_invoice_type_sql = "'" . implode("','", array_map(static function($type_key) use ($conn){
    return mysqli_real_escape_string($conn, $type_key);
}, $total_invoice_type_keys)) . "'";
$payment_type_customers = [];
$customer_projects = [];
$customer_packages = [];
$ptc = mysqli_query($conn, "SELECT DISTINCT customer_id, invoice_type FROM booking_invoices bi WHERE user_id={$user_id} AND status='confirmed'{$branch_scope}");
while($ptc && $pr = mysqli_fetch_assoc($ptc)){ $payment_type_customers[$pr['invoice_type']][] = (int)$pr['customer_id']; }
$purchase_map = mysqli_query($conn, "SELECT bi.customer_id, bi.project_id, bi.package_id, bi.invoice_date, pk.package_name, COALESCE(NULLIF(bi.total_price,0),pk.price,bi.amount) AS total_amount, (SELECT COALESCE(SUM(adj.amount),0) FROM booking_invoices adj WHERE adj.user_id=bi.user_id AND adj.branch_id=bi.branch_id AND adj.customer_id=bi.customer_id AND adj.project_id=bi.project_id AND adj.package_id=bi.package_id AND adj.status='confirmed') AS paid_amount FROM booking_invoices bi LEFT JOIN packages pk ON pk.id=bi.package_id AND pk.user_id=bi.user_id WHERE bi.user_id={$user_id} AND bi.status='confirmed'{$branch_scope} AND bi.invoice_type IN ({$total_invoice_type_sql}) ORDER BY bi.invoice_date DESC");
while($purchase_map && $pm = mysqli_fetch_assoc($purchase_map)){ $c=(int)$pm['customer_id']; $p=(int)$pm['project_id']; $customer_projects[$c][]=$p; $customer_packages[$c][$p][]=['id'=>(int)$pm['package_id'],'date'=>date('d-m-Y',strtotime($pm['invoice_date'])),'name'=>(string)($pm['package_name'] ?? ''),'total'=>(float)$pm['total_amount'],'paid'=>(float)$pm['paid_amount']]; }
$type = '';
$display_type = isset($_GET['type']) ? normalize_booking_invoice_type($_GET['type'], $invoice_types) : 'booking';

$message = '';
$invoice_request_id = (int)($_POST['invoice_request_id'] ?? $_GET['invoice_request_id'] ?? 0);
$requested_customer_id = 0;
if($invoice_request_id > 0){
    $request_customer_stmt = mysqli_prepare($conn, "SELECT customer_id FROM invoice_requests WHERE id=? AND user_id=? AND status='pending' LIMIT 1");
    mysqli_stmt_bind_param($request_customer_stmt, 'ii', $invoice_request_id, $user_id);
    mysqli_stmt_execute($request_customer_stmt);
    $request_customer = mysqli_fetch_assoc(mysqli_stmt_get_result($request_customer_stmt));
    $requested_customer_id = (int)($request_customer['customer_id'] ?? 0);
    if($requested_customer_id <= 0) $invoice_request_id = 0;
}
$customer_id = (int)($_POST['customer_id'] ?? $requested_customer_id);
$project_id = (int)($_POST['project_id'] ?? 0);
$package_id = (int)($_POST['package_id'] ?? 0);
$cash_wallet_id = ensure_default_cash_wallet($conn, $user_id);
$wallet_id = (int)($_POST['wallet_id'] ?? $cash_wallet_id);
$invoice_date = trim($_POST['invoice_date'] ?? date('d-m-Y'));
$amount = trim($_POST['amount'] ?? '');
$total_price = trim($_POST['total_price'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$charge_inputs = $_POST['charge_value'] ?? [];
$property_values = [
    'block_name' => trim($_POST['block_name'] ?? $_POST['block_name_select'] ?? ''),
    'road_no' => trim($_POST['road_no'] ?? $_POST['road_no_select'] ?? ''),
    'plot_no' => trim($_POST['plot_no'] ?? $_POST['plot_no_select'] ?? ''),
    'file_no' => trim($_POST['file_no'] ?? $_POST['file_no_select'] ?? ''),
];

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
    $requires_existing_file_number = $is_housing_company && in_array($type, ['installment', 'cancel_return'], true);
    $file_number_is_new = in_array($type, ['booking', 'full_payment'], true);

    // An adjustment always follows the package originally assigned to its File No.
    // Resolve it on the server too, so submitted values cannot point at another package.
    if($requires_existing_file_number && $property_values['file_no'] !== ''){
        $file_source_stmt = mysqli_prepare(
            $conn,
            "SELECT project_id, package_id, block_name, road_no, plot_no
             FROM booking_invoices
             WHERE user_id=? AND branch_id=? AND file_no=? AND customer_id=? AND status='confirmed'
               AND invoice_type IN ('booking', 'full_payment')
             ORDER BY id DESC LIMIT 1"
        );
        mysqli_stmt_bind_param($file_source_stmt, 'iisi', $user_id, $branch_id, $property_values['file_no'], $customer_id);
        mysqli_stmt_execute($file_source_stmt);
        $file_source = mysqli_fetch_assoc(mysqli_stmt_get_result($file_source_stmt));
        if($file_source){
            $project_id = (int)$file_source['project_id'];
            $package_id = (int)$file_source['package_id'];
            foreach(['block_name', 'road_no', 'plot_no'] as $field){
                $property_values[$field] = trim((string)($file_source[$field] ?? ''));
            }
        } else {
            $message = 'Select a File No. created by a Booking or Full Payment invoice.';
        }
    }

    if($is_housing_company && $property_values['file_no'] !== ''){
        $file_check_stmt = mysqli_prepare($conn, "SELECT id FROM booking_invoices WHERE user_id=? AND file_no=? LIMIT 1");
        mysqli_stmt_bind_param($file_check_stmt, 'is', $user_id, $property_values['file_no']);
        mysqli_stmt_execute($file_check_stmt);
        $file_number_exists = mysqli_fetch_assoc(mysqli_stmt_get_result($file_check_stmt));

        if(($file_number_is_new && $file_number_exists) || ($requires_existing_file_number && !$file_number_exists)){
            $message = $file_number_is_new
                ? 'This File No. is already used. Select a non-used File No.'
                : 'Select an existing File No. for Installment or Cancel/Return.';
        }
    }

    // A plot allocation is unique too: a different File No. must not create a
    // second Booking/Full Payment for the same project, package and plot.
    if($message === '' && $is_housing_company && $file_number_is_new && $project_id > 0 && $package_id > 0){
        $plot_duplicate_stmt = mysqli_prepare(
            $conn,
            "SELECT id, file_no FROM booking_invoices
             WHERE user_id=? AND project_id=? AND package_id=?
               AND block_name=? AND road_no=? AND plot_no=?
               AND invoice_type IN ('booking', 'full_payment')
             LIMIT 1"
        );
        mysqli_stmt_bind_param($plot_duplicate_stmt, 'iiisss', $user_id, $project_id, $package_id, $property_values['block_name'], $property_values['road_no'], $property_values['plot_no']);
        mysqli_stmt_execute($plot_duplicate_stmt);
        $plot_duplicate = mysqli_fetch_assoc(mysqli_stmt_get_result($plot_duplicate_stmt));
        if($plot_duplicate){
            $message = 'This Project, Package and Plot Details are already assigned to File No. ' . trim((string)($plot_duplicate['file_no'] ?? '-')) . '.';
        }
    }

    if($message !== ''){
        // Keep the more specific File No. validation message.
    } elseif($customer_id <= 0 || $project_id <= 0 || $package_id <= 0 || $wallet_id <= 0 || $type === '' || $normalized_date === '' || $numeric_amount <= 0 || $final_amount <= 0 || ($type_establishes_total && $numeric_total_price <= 0)){
        $message = 'Customer, ' . $project_package_labels['project'] . ', ' . $project_package_labels['package'] . ', Payment Type, Date and valid Amount are required.';
    } elseif($is_housing_company && (!$property_values['block_name'] || !$property_values['road_no'] || !$property_values['plot_no'] || !$property_values['file_no'])){
        $message = 'Block, Road No., Plot No. and File No. are required for Housing invoices.';
    } else {
        $invoice_no = generate_booking_invoice_no($conn);

        $insert_stmt = mysqli_prepare(
            $conn,
            "INSERT INTO booking_invoices
             (user_id, branch_id, invoice_no, customer_id, project_id, package_id, block_name, road_no, plot_no, file_no, wallet_id, invoice_type, invoice_date, amount, total_price, notes, created_by_user_id)
             VALUES
             (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param(
            $insert_stmt,
            'iisiiissssissddsi',
            $user_id,
            $branch_id,
            $invoice_no,
            $customer_id,
            $project_id,
            $package_id,
            $property_values['block_name'],
            $property_values['road_no'],
            $property_values['plot_no'],
            $property_values['file_no'],
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
            if($is_housing_company){
                booking_invoice_store_property_options($conn, $user_id, $property_values);
            }
            if($invoice_request_id > 0){
                $complete_request_stmt = mysqli_prepare($conn, "UPDATE invoice_requests SET status='completed', completed_invoice_id=?, completed_at=NOW() WHERE id=? AND user_id=? AND customer_id=? AND status='pending'");
                mysqli_stmt_bind_param($complete_request_stmt, 'iiii', $saved_id, $invoice_request_id, $user_id, $customer_id);
                mysqli_stmt_execute($complete_request_stmt);
            }
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
    "SELECT id, customer_name, customer_code, phone
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
$property_options = $is_housing_company ? booking_invoice_property_options($conn, $user_id) : [];
$used_file_numbers = [];
if($is_housing_company){
    $used_file_stmt = mysqli_prepare($conn, "SELECT DISTINCT file_no FROM booking_invoices WHERE user_id=? AND invoice_type IN ('booking', 'full_payment') AND file_no IS NOT NULL AND TRIM(file_no)<>'' ORDER BY file_no");
    mysqli_stmt_bind_param($used_file_stmt, 'i', $user_id);
    mysqli_stmt_execute($used_file_stmt);
    $used_file_result = mysqli_stmt_get_result($used_file_stmt);
    while($used_file_result && $used_file_row = mysqli_fetch_assoc($used_file_result)){
        $used_file_numbers[] = (string)$used_file_row['file_no'];
    }
    $property_options['file_no'] = array_values(array_unique(array_merge($property_options['file_no'], $used_file_numbers)));
    sort($property_options['file_no'], SORT_NATURAL | SORT_FLAG_CASE);
}
$file_property_map = [];
if($is_housing_company){
    $booking_property_query = mysqli_query(
        $conn,
        "SELECT bi.customer_id, bi.project_id, bi.package_id, bi.block_name, bi.road_no, bi.plot_no, bi.file_no,
                COALESCE(NULLIF(bi.total_price, 0), 0) AS total_amount,
                COALESCE((SELECT SUM(CASE WHEN related.invoice_type='cancel_return' THEN -related.amount ELSE related.amount END)
                          FROM booking_invoices related
                          WHERE related.user_id=bi.user_id AND related.file_no=bi.file_no AND related.status='confirmed'), 0) AS paid_amount
         FROM booking_invoices bi
         WHERE bi.user_id={$user_id} AND bi.branch_id={$branch_id}
           AND bi.invoice_type IN ('booking', 'full_payment') AND bi.status='confirmed'
           AND bi.file_no IS NOT NULL AND TRIM(bi.file_no)<>''
         ORDER BY bi.id DESC"
    );
    while($booking_property_query && $property_row = mysqli_fetch_assoc($booking_property_query)){
        $file_key = trim((string)$property_row['file_no']);
        if(!isset($file_property_map[$file_key])){
            $file_property_map[$file_key] = [
                'file_no' => $file_key,
                'customer_id' => (string)(int)$property_row['customer_id'],
                'project_id' => (string)(int)$property_row['project_id'],
                'package_id' => (string)(int)$property_row['package_id'],
                'block_name' => (string)($property_row['block_name'] ?? ''),
                'road_no' => (string)($property_row['road_no'] ?? ''),
                'plot_no' => (string)($property_row['plot_no'] ?? ''),
                'total_amount' => (float)($property_row['total_amount'] ?? 0),
                'paid_amount' => (float)($property_row['paid_amount'] ?? 0),
            ];
        }
    }
}

$recent_invoices = [];
$pending_invoice_requests = [];
$pending_request_stmt = mysqli_prepare($conn, "SELECT ir.id, ir.note, ir.photo, ir.created_at, c.customer_name, c.customer_code FROM invoice_requests ir INNER JOIN customers c ON c.id=ir.customer_id AND c.user_id=ir.user_id WHERE ir.user_id=? AND ir.status='pending' ORDER BY ir.created_at ASC");
mysqli_stmt_bind_param($pending_request_stmt, 'i', $user_id);
mysqli_stmt_execute($pending_request_stmt);
$pending_request_result = mysqli_stmt_get_result($pending_request_stmt);
while($pending_request_result && $request_row = mysqli_fetch_assoc($pending_request_result)) $pending_invoice_requests[] = $request_row;

$recent_stmt = mysqli_prepare(
    $conn,
    "SELECT bi.id,
            bi.invoice_no,
            bi.invoice_date,
            bi.amount,
            bi.status,
            bi.customer_id,
            bi.project_id,
            bi.package_id,
            bi.block_name,
            bi.road_no,
            bi.plot_no,
            bi.file_no,
            c.customer_name,
            c.customer_code,
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

<?php if(!empty($pending_invoice_requests)){ ?>
<div class="card card-outline card-warning invoice-request-card">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-file-invoice mr-2"></i>Pending Invoice Requests</h3></div>
    <div class="card-body table-responsive p-0"><table class="table table-bordered table-striped mb-0 invoice-request-table"><thead><tr><th>Photo</th><th>Customer Name &amp; ID</th><th>Note</th><th>Submitted</th><th>Status</th><th>Action</th></tr></thead><tbody>
        <?php foreach($pending_invoice_requests as $request){ $request_photo_url = invoice_request_photo_url($request['photo']); ?>
            <tr><td><?php if($request_photo_url !== ''){ ?><button type="button" class="btn p-0 invoice-request-photo" data-toggle="modal" data-target="#invoice-request-photo-modal" data-photo="<?= htmlspecialchars($request_photo_url); ?>" data-customer="<?= htmlspecialchars($request['customer_name']); ?>"><img src="<?= htmlspecialchars($request_photo_url); ?>" alt="Invoice request photo"></button><?php }else{ ?><span class="text-muted">-</span><?php } ?></td><td><strong><?= htmlspecialchars($request['customer_name']); ?></strong><div class="text-muted small">ID: <?= htmlspecialchars($request['customer_code'] ?: '-'); ?></div></td><td class="request-note"><?= htmlspecialchars($request['note'] ?: '-'); ?></td><td><?= htmlspecialchars(date('d-m-Y', strtotime($request['created_at']))); ?></td><td><span class="badge badge-warning">Pending</span></td><td><a href="index.php?invoice_request_id=<?= (int)$request['id']; ?>" class="btn btn-warning btn-sm mr-1"><i class="fas fa-file-invoice"></i> Create Invoice</a><form method="post" class="d-inline" onsubmit="return confirm('Reject this invoice request?');"><input type="hidden" name="action" value="reject_invoice_request"><input type="hidden" name="invoice_request_id" value="<?= (int)$request['id']; ?>"><button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-times"></i> Reject</button></form></td></tr>
        <?php } ?>
    </tbody></table></div>
</div>
<div class="modal fade" id="invoice-request-photo-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Invoice Request Photo</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div><div class="modal-body text-center"><img id="invoice-request-photo-preview" class="img-fluid" alt="Invoice request photo"></div></div></div></div>
<?php } ?>

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
            <?php if($invoice_request_id > 0){ ?><input type="hidden" name="invoice_request_id" value="<?= (int)$invoice_request_id; ?>"><?php } ?>
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Date</label>
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
                        <select id="customer_id" name="customer_id" class="form-control customer-select" required>
                            <option value="">Select Customer</option>
                            <?php foreach($customers as $customer){ ?>
                                <option value="<?= (int)$customer['id']; ?>" <?= $customer_id === (int)$customer['id'] ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($customer['customer_name'] . ' [Mob: ' . ($customer['phone'] ?: '-') . '] [ID: ' . ($customer['customer_code'] ?: '-') . ']'); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="form-group">
                        <label>Payment Type</label>
                        <select id="invoice_type" name="invoice_type" class="form-control" required>
                            <option value="">Select Payment Type</option>
                            <?php foreach($invoice_types as $type_key => $type_name){ ?>
                                <option value="<?= htmlspecialchars($type_key); ?>" data-customer-ids="<?= htmlspecialchars(implode(',', $payment_type_customers[$type_key] ?? [])); ?>" <?= $type === $type_key ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($type_name); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

                <?php if($is_housing_company){ ?>
                    <div class="col-md-3 housing-property-group">
                        <div class="form-group">
                            <label>File No.</label>
                            <div class="input-group">
                                <select id="file_no_select" name="file_no_select" class="form-control housing-property-select" data-property-field="file_no" required>
                                    <option value="">Select File No.</option>
                                    <?php foreach($property_options['file_no'] as $property_option){ ?>
                                        <option value="<?= htmlspecialchars($property_option); ?>" <?= $property_values['file_no'] === $property_option ? 'selected' : ''; ?>><?= htmlspecialchars($property_option); ?></option>
                                    <?php } ?>
                                    <?php if($property_values['file_no'] !== '' && !in_array($property_values['file_no'], $property_options['file_no'], true)){ ?>
                                        <option value="<?= htmlspecialchars($property_values['file_no']); ?>" selected><?= htmlspecialchars($property_values['file_no']); ?></option>
                                    <?php } ?>
                                </select>
                                <div class="input-group-append"><button type="button" class="btn btn-outline-primary add-property-option" data-property-field="file_no" data-property-label="File No." title="Add new File No."><i class="fas fa-plus"></i></button></div>
                            </div>
                            <input type="hidden" id="file_no" name="file_no" value="<?= htmlspecialchars($property_values['file_no']); ?>">
                        </div>
                    </div>
                <?php } ?>

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

                <?php if($is_housing_company){ ?>
                    <?php foreach(['block_name' => 'Block', 'road_no' => 'Road No.', 'plot_no' => 'Plot No.'] as $property_field => $property_label){ ?>
                        <div class="col-md-4 housing-property-group">
                            <div class="form-group">
                                <label><?= htmlspecialchars($property_label); ?></label>
                                <div class="input-group">
                                    <select id="<?= htmlspecialchars($property_field); ?>_select" name="<?= htmlspecialchars($property_field); ?>_select" class="form-control housing-property-select" data-property-field="<?= htmlspecialchars($property_field); ?>" required>
                                        <option value="">Select <?= htmlspecialchars($property_label); ?></option>
                                        <?php foreach($property_options[$property_field] as $property_option){ ?>
                                            <option value="<?= htmlspecialchars($property_option); ?>" <?= $property_values[$property_field] === $property_option ? 'selected' : ''; ?>><?= htmlspecialchars($property_option); ?></option>
                                        <?php } ?>
                                        <?php if($property_values[$property_field] !== '' && !in_array($property_values[$property_field], $property_options[$property_field], true)){ ?>
                                            <option value="<?= htmlspecialchars($property_values[$property_field]); ?>" selected><?= htmlspecialchars($property_values[$property_field]); ?></option>
                                        <?php } ?>
                                    </select>
                                    <div class="input-group-append">
                                        <button type="button" class="btn btn-outline-primary add-property-option" data-property-field="<?= htmlspecialchars($property_field); ?>" data-property-label="<?= htmlspecialchars($property_label); ?>" title="Add new <?= htmlspecialchars($property_label); ?>"><i class="fas fa-plus"></i></button>
                                    </div>
                                </div>
                                <input type="hidden" id="<?= htmlspecialchars($property_field); ?>" name="<?= htmlspecialchars($property_field); ?>" value="<?= htmlspecialchars($property_values[$property_field]); ?>">
                            </div>
                        </div>
                    <?php } ?>
                <?php } ?>

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
                        <small id="file-balance-info" class="form-text text-muted"></small>
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
                    <th width="130">Action</th>
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
                            <td><?= htmlspecialchars($invoice['customer_name'] ? $invoice['customer_name'] . ' [ID: ' . ($invoice['customer_code'] ?: '-') . ']' : ('Missing Customer #' . (int)$invoice['customer_id'])); ?></td>
                            <td><?= htmlspecialchars($invoice['project_name'] ?: ('Missing ' . $project_package_labels['project'] . ' #' . (int)$invoice['project_id'])); ?></td>
                            <td><?= htmlspecialchars($invoice['package_name'] ?: ('Missing ' . $project_package_labels['package'] . ' #' . (int)$invoice['package_id'])); ?><?php if($is_housing_company){ ?><div class="small text-muted">File: <?= htmlspecialchars($invoice['file_no'] ?: '-'); ?> | Block: <?= htmlspecialchars($invoice['block_name'] ?: '-'); ?> | Road: <?= htmlspecialchars($invoice['road_no'] ?: '-'); ?> | Plot: <?= htmlspecialchars($invoice['plot_no'] ?: '-'); ?></div><?php } ?></td>
                            <td>BDT <?= htmlspecialchars(number_format((float)$invoice['amount'], 2)); ?></td>
                            <td><span class="badge badge-<?= ($invoice['status'] ?? 'pending') === 'confirmed' ? 'success' : 'warning'; ?>"><?= htmlspecialchars(ucfirst($invoice['status'] ?? 'pending')); ?></span></td>
                            <td>
                                <a href="../customers/customer_ledger.php?id=<?= (int)$invoice['customer_id']; ?>" class="btn btn-primary btn-sm" title="Customer Ledger" aria-label="Customer Ledger">
                                    <i class="fas fa-book"></i>
                                </a>
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
    const customerSelect = document.getElementById('customer_id');
    const filePropertyMap = <?= json_encode($file_property_map); ?>;
    const plotAssignments = Object.values(filePropertyMap);
    const usedFileNumbers = <?= json_encode($used_file_numbers); ?>.map(function(value){ return String(value); });
    const housingPropertySelects = Array.from(document.querySelectorAll('.housing-property-select'));

    function setHousingPropertyValue(field, value) {
        const select = document.getElementById(field + '_select');
        const hidden = document.getElementById(field);
        if (!select || !hidden) return;
        value = String(value || '').trim();
        if (value && !Array.from(select.options).some(function(option){ return option.value === value; })) {
            select.add(new Option(value, value, false, false));
        }
        select.value = value;
        hidden.value = value;
    }

    function syncHousingProperties() {
        if (!housingPropertySelects.length) return;
        const locked = ['installment', 'cancel_return'].includes(invoiceTypeSelect.value);
        const fileSelect = document.getElementById('file_no_select');
        const selectedFile = String(fileSelect ? fileSelect.value : '').trim();
        const fileSource = locked && selectedFile ? filePropertyMap[selectedFile] : null;
        const lockFields = locked && !!fileSource;

        if (fileSource) {
            projectSelect.value = fileSource.project_id || '';
            filterPackages();
            packageSelect.value = fileSource.package_id || '';
            ['block_name', 'road_no', 'plot_no'].forEach(function(field){
                setHousingPropertyValue(field, fileSource[field] || '');
            });
        }

        const balanceInfo = document.getElementById('file-balance-info');
        if (balanceInfo) {
            if (fileSource) {
                const paid = Number(fileSource.paid_amount || 0);
                const due = Math.max(0, Number(fileSource.total_amount || 0) - paid);
                balanceInfo.textContent = 'Total Paid: BDT ' + paid.toFixed(2) + ' | Due: BDT ' + due.toFixed(2);
            } else {
                balanceInfo.textContent = '';
            }
        }

        [projectSelect, packageSelect].forEach(function(select){
            select.classList.toggle('bg-light', lockFields);
            select.disabled = lockFields;
            select.tabIndex = lockFields ? -1 : 0;
            select.setAttribute('aria-readonly', lockFields ? 'true' : 'false');
        });

        housingPropertySelects.forEach(function(select){
            const field = select.dataset.propertyField;
            const addButton = document.querySelector('.add-property-option[data-property-field="' + field + '"]');
            if (field === 'file_no') {
                const allowExistingFiles = locked;
                const selectedCustomerId = String(customerSelect.value || '');
                Array.from(select.options).forEach(function(option, index){
                    if (index === 0) return;
                    const fileSourceOption = filePropertyMap[String(option.value)];
                    option.hidden = allowExistingFiles
                        ? !fileSourceOption || String(fileSourceOption.customer_id || '') !== selectedCustomerId
                        : usedFileNumbers.includes(String(option.value));
                });
                if (select.value && select.options[select.selectedIndex] && select.options[select.selectedIndex].hidden) {
                    setHousingPropertyValue(field, '');
                }
                select.disabled = false;
                if (addButton) addButton.disabled = locked;
            } else {
                if (field === 'plot_no') {
                    const selectedBlock = String((document.getElementById('block_name_select') || {}).value || '').trim();
                    const selectedRoad = String((document.getElementById('road_no_select') || {}).value || '').trim();
                    const restrictUsedPlots = ['booking', 'full_payment', 'installment', 'cancel_return'].includes(invoiceTypeSelect.value);
                    Array.from(select.options).forEach(function(option, index){
                        if (index === 0) return;
                        const isUsedPlot = plotAssignments.some(function(item){
                            return String(item.project_id) === String(projectSelect.value) &&
                                String(item.package_id) === String(packageSelect.value) &&
                                String(item.block_name || '') === selectedBlock &&
                                String(item.road_no || '') === selectedRoad &&
                                String(item.plot_no || '') === String(option.value);
                        });
                        option.hidden = restrictUsedPlots && isUsedPlot && !(fileSource && String(fileSource.plot_no || '') === String(option.value));
                    });
                    if (!fileSource && select.value && select.options[select.selectedIndex] && select.options[select.selectedIndex].hidden) {
                        setHousingPropertyValue(field, '');
                    }
                }
                select.disabled = lockFields;
                select.classList.toggle('bg-light', lockFields);
                select.tabIndex = lockFields ? -1 : 0;
                select.setAttribute('aria-readonly', lockFields ? 'true' : 'false');
                if (addButton) addButton.disabled = lockFields;
            }
            const hidden = document.getElementById(field);
            if (hidden) hidden.value = select.value || '';
        });
    }

    housingPropertySelects.forEach(function(select){
        select.addEventListener('change', function(){
            const hidden = document.getElementById(this.dataset.propertyField);
            if (hidden) hidden.value = this.value || '';
            syncHousingProperties();
        });
    });
    document.querySelectorAll('.add-property-option').forEach(function(button){
        button.addEventListener('click', function(){
            const value = window.prompt('Enter new ' + (this.dataset.propertyLabel || 'value') + ':');
            if (value === null || !value.trim()) return;
            if (this.dataset.propertyField === 'plot_no' && ['booking', 'full_payment'].includes(invoiceTypeSelect.value)) {
                const block = String((document.getElementById('block_name_select') || {}).value || '').trim();
                const road = String((document.getElementById('road_no_select') || {}).value || '').trim();
                const duplicatePlot = plotAssignments.find(function(item){
                    return String(item.project_id) === String(projectSelect.value) &&
                        String(item.package_id) === String(packageSelect.value) &&
                        String(item.block_name || '') === block &&
                        String(item.road_no || '') === road &&
                        String(item.plot_no || '').trim().toLowerCase() === value.trim().toLowerCase();
                });
                if (duplicatePlot) {
                    const assignedFile = String(duplicatePlot.file_no || '-');
                    window.alert('This Plot Details are already assigned to File No. ' + assignedFile + '.');
                    return;
                }
            }
            if (this.dataset.propertyField === 'file_no') {
                const normalized = value.trim().toLowerCase();
                const exists = usedFileNumbers.some(function(fileNumber){
                    return String(fileNumber).trim().toLowerCase() === normalized;
                });
                if (exists) {
                    window.alert('This File No. already exists. File No. must be unique.');
                    return;
                }
            }
            setHousingPropertyValue(this.dataset.propertyField, value);
        });
    });

    $('#invoice-request-photo-modal').on('show.bs.modal', function(event){
        const trigger = $(event.relatedTarget);
        $('#invoice-request-photo-preview').attr('src', trigger.data('photo') || '');
        $(this).find('.modal-title').text((trigger.data('customer') || 'Customer') + ' — Invoice Request Photo');
    });

    // Select2 provides a searchable customer field while retaining the native
    // select value used by the existing invoice form logic.
    if (window.jQuery && $.fn.select2) {
        $('#customer_id').select2({
            theme: 'bootstrap4',
            width: '100%',
            placeholder: 'Type to search customer',
            allowClear: true
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
        syncHousingProperties();
        const selected = packageSelect.options[packageSelect.selectedIndex];
        const balance = document.getElementById('selected-package-balance');
        if (balance) balance.textContent = adjustmentInvoiceTypes.includes(invoiceTypeSelect.value) && selected && selected.dataset.total
            ? 'Total Amount: ' + Number(selected.dataset.total).toFixed(2) + ' | Total Paid: ' + Number(selected.dataset.paid).toFixed(2) + ' | Total Due: ' + Number(selected.dataset.due).toFixed(2) : '';
    });
    invoiceTypeSelect.addEventListener('change', toggleTotalPrice);
    invoiceTypeSelect.addEventListener('change', syncHousingProperties);
    walletSelect.addEventListener('change', updateWalletBalance);
    invoiceTypeSelect.addEventListener('change', function(){
        const restrictProjects=adjustmentInvoiceTypes.includes(this.value), c=document.querySelector('[name="customer_id"]').value;
        const balance = document.getElementById('selected-package-balance');
        if (balance && !restrictProjects) balance.textContent = '';
        projectSelect.value = '';
        packageSelect.value = '';
        Array.from(projectSelect.options).forEach(function(o,i){ if(i) o.hidden=restrictProjects && !!c && !((customerProjects[c]||[]).includes(Number(o.value))); });
        Array.from(packageSelect.options).forEach(function(o,i){ if(i) o.hidden = i > 0; });
        syncHousingProperties();
    });
    projectSelect.addEventListener('change', function(){
        const c=document.querySelector('[name="customer_id"]').value;
        if(adjustmentInvoiceTypes.includes(invoiceTypeSelect.value)) filterPackages();
        updateWalletBalance();
        syncHousingProperties();
    });
    packageSelect.addEventListener('change', updateWalletBalance);
    customerSelect.addEventListener('change', function(){
        invoiceTypeSelect.disabled = false;
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
        syncHousingProperties();
    });

    // Select2 usually emits a native change event. Handle its own events as
    // well so Payment Type always unlocks after a searchable selection.
    if (window.jQuery && $.fn.select2) {
        $('#customer_id').on('select2:select select2:clear', function(){
            this.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    // Covers browser form-state restoration after a refresh or Back action.
    window.setTimeout(function(){
        invoiceTypeSelect.disabled = false;
    }, 0);

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
    syncHousingProperties();
});
</script>
<style>
.invoice-request-photo { background:transparent; border:0; }
.invoice-request-photo img { border:1px solid #e5e7eb; border-radius:8px; height:72px; object-fit:cover; width:72px; }
.invoice-request-table th { white-space:nowrap; }
.request-note { min-width:180px; white-space:pre-wrap; }
</style>

<?php
require_once '../includes/footer.php';
?>
