<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/project_package_helper.php';
require_once '../includes/booking_invoice_helper.php';

require_admin_user();

$user_id = (int)$_SESSION['user_id'];
$type = normalize_booking_invoice_type($_GET['type'] ?? 'booking');

ensure_project_package_tables($conn);
ensure_booking_invoice_table($conn);

$message = '';
$customer_id = (int)($_POST['customer_id'] ?? 0);
$project_id = (int)($_POST['project_id'] ?? 0);
$package_id = (int)($_POST['package_id'] ?? 0);
$wallet_id = (int)($_POST['wallet_id'] ?? 0);
$invoice_date = trim($_POST['invoice_date'] ?? date('m/d/Y'));
$amount = trim($_POST['amount'] ?? '');
$notes = trim($_POST['notes'] ?? '');

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $type = normalize_booking_invoice_type($_POST['invoice_type'] ?? $type);

    $date_object = DateTime::createFromFormat('m/d/Y', $invoice_date);
    $normalized_date = $date_object ? $date_object->format('Y-m-d') : '';
    $numeric_amount = (float)$amount;

    if($customer_id <= 0 || $project_id <= 0 || $package_id <= 0 || $wallet_id <= 0 || $normalized_date === '' || $numeric_amount <= 0){
        $message = 'Customer, Project, Package, Wallet, Date and valid Price are required.';
    } else {
        $invoice_no = generate_booking_invoice_no($conn);

        $insert_stmt = mysqli_prepare(
            $conn,
            "INSERT INTO booking_invoices
             (user_id, invoice_no, customer_id, project_id, package_id, wallet_id, invoice_type, invoice_date, amount, notes)
             VALUES
             (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param(
            $insert_stmt,
            'isiiiissds',
            $user_id,
            $invoice_no,
            $customer_id,
            $project_id,
            $package_id,
            $wallet_id,
            $type,
            $normalized_date,
            $numeric_amount,
            $notes
        );

        if(mysqli_stmt_execute($insert_stmt)){
            $saved_id = (int)mysqli_insert_id($conn);
            header('Location: print.php?id=' . $saved_id);
            exit;
        }

        $message = 'Failed to save invoice.';
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

$recent_invoices = [];
$recent_stmt = mysqli_prepare(
    $conn,
    "SELECT bi.id,
            bi.invoice_no,
            bi.invoice_date,
            bi.amount,
            c.customer_name,
            p.project_name,
            pk.package_name
     FROM booking_invoices bi
     LEFT JOIN customers c ON c.id = bi.customer_id
     LEFT JOIN projects p ON p.id = bi.project_id
     LEFT JOIN packages pk ON pk.id = bi.package_id
     WHERE bi.user_id=?
     AND bi.invoice_type=?
     ORDER BY bi.id DESC
     LIMIT 50"
);
mysqli_stmt_bind_param($recent_stmt, 'is', $user_id, $type);
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
            <?= htmlspecialchars(booking_invoice_page_title($type)); ?>
        </h3>
    </div>

    <div class="card-body">
        <?php if($message){ ?>
            <div class="alert alert-danger"><?= htmlspecialchars($message); ?></div>
        <?php } ?>

        <form method="post">
            <input type="hidden" name="invoice_type" value="<?= htmlspecialchars($type); ?>">

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Customer Profile</label>
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
                        <label>Project</label>
                        <select id="project_id" name="project_id" class="form-control" required>
                            <option value="">Select Project</option>
                            <?php foreach($projects as $project){ ?>
                                <option value="<?= (int)$project['id']; ?>" <?= $project_id === (int)$project['id'] ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($project['project_name']); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="form-group">
                        <label>Package</label>
                        <select id="package_id" name="package_id" class="form-control" required>
                            <option value="">Select Package</option>
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
                        <label>Date</label>
                        <input type="text" name="invoice_date" class="form-control" value="<?= htmlspecialchars($invoice_date); ?>" placeholder="mm/dd/yyyy" required>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="form-group">
                        <label>Price (BDT)</label>
                        <input id="amount" type="number" step="0.01" min="0" name="amount" class="form-control" value="<?= htmlspecialchars($amount); ?>" required>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="form-group">
                        <label>Wallet</label>
                        <select name="wallet_id" class="form-control" required>
                            <option value="">Select Wallet</option>
                            <?php foreach($wallets as $wallet){ ?>
                                <option value="<?= (int)$wallet['id']; ?>" <?= $wallet_id === (int)$wallet['id'] ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($wallet['wallet_name']); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="form-group">
                        <label>Invoice Type</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars(booking_invoice_type_label($type)); ?>" readonly>
                    </div>
                </div>

                <div class="col-md-12">
                    <div class="form-group">
                        <label>Note</label>
                        <textarea name="notes" class="form-control" rows="3"><?= htmlspecialchars($notes); ?></textarea>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i>
                Save & Print Invoice
            </button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><?= htmlspecialchars(booking_invoice_recent_title($type)); ?></h3>
    </div>

    <div class="card-body">
        <table id="example1" class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>Invoice No</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Project</th>
                    <th>Package</th>
                    <th>Amount</th>
                    <th width="90">Print</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($recent_invoices)){ ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted">No data available in table</td>
                    </tr>
                <?php } else { ?>
                    <?php foreach($recent_invoices as $invoice){ ?>
                        <tr>
                            <td><?= htmlspecialchars($invoice['invoice_no']); ?></td>
                            <td><?= htmlspecialchars(date('d-m-Y', strtotime($invoice['invoice_date']))); ?></td>
                            <td><?= htmlspecialchars($invoice['customer_name'] ?: '-'); ?></td>
                            <td><?= htmlspecialchars($invoice['project_name'] ?: '-'); ?></td>
                            <td><?= htmlspecialchars($invoice['package_name'] ?: '-'); ?></td>
                            <td>BDT <?= htmlspecialchars(number_format((float)$invoice['amount'], 2)); ?></td>
                            <td>
                                <a href="print.php?id=<?= (int)$invoice['id']; ?>" class="btn btn-info btn-sm">
                                    <i class="fas fa-print"></i>
                                    Print
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
    const projectSelect = document.getElementById('project_id');
    const packageSelect = document.getElementById('package_id');
    const amountInput = document.getElementById('amount');

    function filterPackages() {
        const selectedProject = projectSelect.value;
        let hasVisibleSelected = false;

        Array.from(packageSelect.options).forEach(function (option, index) {
            if (index === 0) {
                option.hidden = false;
                return;
            }

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
        if (selectedOption && selectedOption.dataset.price && !amountInput.value) {
            amountInput.value = selectedOption.dataset.price;
        }
    }

    projectSelect.addEventListener('change', filterPackages);
    packageSelect.addEventListener('change', syncPackagePrice);

    filterPackages();
});
</script>

<?php
require_once '../includes/footer.php';
?>
