<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/booking_invoice_helper.php';
require_admin_user();

$user_id = (int)$_SESSION['user_id'];
ensure_booking_invoice_table($conn);
ensure_booking_invoice_type_table($conn, $user_id);

if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm'){
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    try {
        confirm_booking_invoice($conn, $invoice_id, $user_id);
        header('Location: print.php?id=' . $invoice_id);
    } catch(Throwable $error) {
        header('Location: invoice_list.php?error=' . urlencode($error->getMessage()));
    }
    exit;
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete'){
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    $delete_stmt = mysqli_prepare($conn, "DELETE FROM booking_invoices WHERE id=? AND user_id=? AND status='pending'");
    mysqli_stmt_bind_param($delete_stmt, 'ii', $invoice_id, $user_id);
    mysqli_stmt_execute($delete_stmt);
    header('Location: invoice_list.php?deleted=1');
    exit;
}
$invoice_types = booking_invoice_types($conn, $user_id, false);
$selected_type = trim($_GET['invoice_type'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');

if(!array_key_exists($selected_type, $invoice_types)) $selected_type = '';
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = '';
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) $date_to = '';

$where = "bi.user_id={$user_id}";
if($selected_type !== '') $where .= " AND bi.invoice_type='" . mysqli_real_escape_string($conn, $selected_type) . "'";
if($date_from !== '') $where .= " AND bi.invoice_date >= '" . mysqli_real_escape_string($conn, $date_from) . "'";
if($date_to !== '') $where .= " AND bi.invoice_date <= '" . mysqli_real_escape_string($conn, $date_to) . "'";

$invoice_result = mysqli_query($conn, "SELECT bi.id, bi.invoice_no, bi.invoice_type, bi.invoice_date, bi.amount, bi.status, c.customer_name, p.project_name, pk.package_name FROM booking_invoices bi LEFT JOIN customers c ON c.id = bi.customer_id LEFT JOIN projects p ON p.id = bi.project_id LEFT JOIN packages pk ON pk.id = bi.package_id WHERE {$where} ORDER BY bi.invoice_date DESC, bi.id DESC");
$invoices = [];
$transaction_count = 0;
$transaction_total = 0;
while($invoice_result && $invoice = mysqli_fetch_assoc($invoice_result)){
    $invoices[] = $invoice;
    $transaction_count++;
    $transaction_total += (float)$invoice['amount'];
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<div class="card">
    <div class="card-header"><h3 class="card-title">Invoice List</h3><div class="card-tools"><a href="index.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Create Invoice</a></div></div>
    <div class="card-body">
        <?php if(isset($_GET['deleted'])){ ?><div class="alert alert-success">Invoice deleted successfully.</div><?php } ?>
        <?php if(isset($_GET['confirmed'])){ ?><div class="alert alert-success">Invoice confirmed and wallet balance updated.</div><?php } ?>
        <?php if(isset($_GET['updated'])){ ?><div class="alert alert-success">Invoice updated and wallet balance adjusted.</div><?php } ?>
        <?php if(isset($_GET['error'])){ ?><div class="alert alert-danger"><?= htmlspecialchars($_GET['error']); ?></div><?php } ?>
        <form method="get" class="mb-3"><div class="row">
            <div class="col-md-4 form-group"><label>Invoice Type</label><select name="invoice_type" class="form-control"><option value="">All Types</option><?php foreach($invoice_types as $type_key => $type_name){ ?><option value="<?= htmlspecialchars($type_key); ?>" <?= $selected_type === $type_key ? 'selected' : ''; ?>><?= htmlspecialchars($type_name); ?></option><?php } ?></select></div>
            <div class="col-md-3 form-group"><label>From Date</label><input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from); ?>"></div>
            <div class="col-md-3 form-group"><label>To Date</label><input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to); ?>"></div>
            <div class="col-md-2 form-group d-flex align-items-end"><button class="btn btn-primary mr-2" type="submit">Search</button><a href="invoice_list.php" class="btn btn-secondary">Reset</a></div>
        </div></form>
        <table id="example1" class="table table-bordered table-striped"><thead><tr><th>Invoice No</th><th>Type</th><th>Date</th><th>Customer</th><th>Project</th><th>Package</th><th>Amount</th><th>Status</th><th width="190">Action</th></tr></thead><tbody>
            <?php foreach($invoices as $invoice){ $pending = ($invoice['status'] ?? 'pending') === 'pending'; ?><tr><td><?= htmlspecialchars($invoice['invoice_no']); ?></td><td><?= htmlspecialchars(booking_invoice_type_label($invoice['invoice_type'], $invoice_types)); ?></td><td><?= htmlspecialchars(date('d-m-Y', strtotime($invoice['invoice_date']))); ?></td><td><?= htmlspecialchars($invoice['customer_name'] ?: '-'); ?></td><td><?= htmlspecialchars($invoice['project_name'] ?: '-'); ?></td><td><?= htmlspecialchars($invoice['package_name'] ?: '-'); ?></td><td>BDT <?= number_format((float)$invoice['amount'], 2); ?></td><td><span class="badge badge-<?= $pending ? 'warning' : 'success'; ?>"><?= $pending ? 'Pending' : 'Confirmed'; ?></span></td><td><?php if($pending){ ?><form method="post" class="d-inline invoice-confirm-form" onsubmit="return confirm('Confirm this invoice and update the wallet?');"><input type="hidden" name="action" value="confirm"><input type="hidden" name="invoice_id" value="<?= (int)$invoice['id']; ?>"><button class="btn btn-success btn-sm" title="Confirm" aria-label="Confirm"><i class="fas fa-check"></i></button></form><?php } ?><a href="edit_invoice.php?id=<?= (int)$invoice['id']; ?>" class="btn btn-warning btn-sm" title="Edit Invoice" aria-label="Edit Invoice"><i class="fas fa-edit"></i></a><a href="print.php?id=<?= (int)$invoice['id']; ?>" class="btn btn-info btn-sm" title="Print" target="_blank" rel="noopener"><i class="fas fa-print"></i></a><form method="post" action="delete_invoice.php" class="d-inline" onsubmit="return confirm('Delete this invoice? Its wallet effect will be reversed.');"><input type="hidden" name="invoice_id" value="<?= (int)$invoice['id']; ?>"><button class="btn btn-danger btn-sm" title="Delete Invoice" aria-label="Delete Invoice"><i class="fas fa-trash"></i></button></form></td></tr><?php } ?>
        </tbody></table>
    </div>
</div>
<script>document.querySelectorAll('.invoice-confirm-form').forEach(function(form){form.addEventListener('submit',function(){form.target='_blank';window.setTimeout(function(){window.location.reload();},450);});});</script>
<?php require_once '../includes/footer.php'; ?>
