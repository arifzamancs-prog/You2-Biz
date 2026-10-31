<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/sales_return_helper.php';

$cid = (int)$_SESSION['user_id'];

if(is_agent_user() || !sales_return_enabled($conn, $cid)){
    header('Location: invoice_list.php');
    exit;
}

sales_return_schema($conn);
$branch_scope = selected_branch_id($conn, false);
$find = trim((string)($_GET['invoice_no'] ?? ''));
$find_error = '';

// Find an invoice by number and open its return or exchange form.
if($find !== ''){
    $found = sales_refund_query(
        $conn,
        'SELECT id FROM invoices WHERE user_id=? AND invoice_no=?' . ($branch_scope > 0 ? ' AND branch_id=' . (int)$branch_scope : '') . ' LIMIT 1',
        'is',
        [$cid, $find]
    )->get_result()->fetch_assoc();

    if($found){
        header('Location: ' . (($_GET['mode'] ?? '') === 'exchange' ? 'create_invoice.php?exchange=' : 'sales_return_create.php?invoice_id=') . (int)$found['id']);
        exit;
    }

    $find_error = 'Invoice Not Found';
}

$returns = sales_refund_query(
    $conn,
    'SELECT r.*, i.invoice_no, i.customer_name, c.customer_code, w.wallet_name, cn.invoice_no AS credit_no, xi.invoice_no AS exchange_invoice_no
     FROM sales_refunds r
     LEFT JOIN invoices i ON i.id=r.invoice_id
     LEFT JOIN customers c ON c.id=i.customer_id AND c.user_id=r.user_id
     LEFT JOIN wallets w ON w.id=r.wallet_id
     LEFT JOIN invoices cn ON cn.id=r.credit_invoice_id
     LEFT JOIN invoices xi ON xi.id=r.exchange_invoice_id
     WHERE r.user_id=?' . ($branch_scope > 0 ? ' AND r.branch_id=' . (int)$branch_scope : '') . '
     ORDER BY r.id DESC LIMIT 500',
    'i',
    [$cid]
)->get_result()->fetch_all(MYSQLI_ASSOC);
$show_due_column = (bool)array_filter($returns, fn($row) => (float)$row['due_adjusted'] > 0.009);
$show_advance_column = (bool)array_filter($returns, fn($row) => (float)$row['advance_amount'] > 0.009);
$recent = sales_refund_query(
    $conn,
    "SELECT invoice_no, customer_name, total_amount FROM invoices
     WHERE user_id=? AND accounting_status='posted' AND total_amount>0 AND (created_by_type IS NULL OR created_by_type<>'sales_refund')
     AND invoice_date >= DATE_SUB(CURDATE(), INTERVAL " . SALES_RETURN_DAYS . " DAY)" . ($branch_scope > 0 ? ' AND branch_id=' . (int)$branch_scope : '') . '
     ORDER BY id DESC LIMIT 300',
    'i',
    [$cid]
)->get_result()->fetch_all(MYSQLI_ASSOC);
$message = $_SESSION['sales_return_error'] ?? '';
unset($_SESSION['sales_return_error']);

function sales_return_list_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

<div class="card-header">
    <h3 class="card-title">Sales Return</h3>
</div>

<div class="card-body">

<?php if($message !== ''){ ?>
    <div class="alert alert-danger"><?= sales_return_list_h($message); ?></div>
<?php } ?>
<?php if($find_error !== ''){ ?>
    <div class="alert alert-danger"><?= sales_return_list_h($find_error); ?></div>
<?php } ?>

<form method="get" class="mb-3">
    <label>Invoice No</label>
    <div class="input-group" style="max-width:720px;">
        <input type="text" name="invoice_no" class="form-control" list="returnInvoices" required
               placeholder="Invoice no" value="<?= sales_return_list_h($find); ?>">
        <datalist id="returnInvoices">
            <?php foreach($recent as $row){ ?>
                <option value="<?= sales_return_list_h($row['invoice_no']); ?>"><?= sales_return_list_h($row['customer_name'] . ' - ' . number_format((float)$row['total_amount'], 2)); ?></option>
            <?php } ?>
        </datalist>
        <div class="input-group-append">
            <button type="submit" name="mode" value="return" class="btn btn-primary"><i class="fas fa-undo"></i> Return</button>
            <button type="submit" name="mode" value="exchange" class="btn btn-outline-primary"><i class="fas fa-exchange-alt"></i> Exchange</button>
        </div>
    </div>
    <small class="text-muted">Return is allowed within <?= SALES_RETURN_DAYS; ?> days of the invoice date.</small>
</form>

<div class="table-responsive">
<table id="example1" data-desktop-table="true" class="table table-bordered table-striped">
    <thead>
    <tr>
        <th>Return No</th>
        <th>Date</th>
        <th>Invoice No</th>
        <th>Customer</th>
        <th>Total</th>
        <th>Cash Refund</th>
        <?php if($show_due_column){ ?><th>Due Deducted</th><?php } ?>
        <?php if($show_advance_column){ ?><th>Advance</th><?php } ?>
        <th>Action</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach($returns as $row){ ?>
        <?php $value = (float)$row['return_value'] > 0 ? (float)$row['return_value'] : (float)$row['amount']; ?>
        <tr>
            <td>
                <?= sales_return_list_h($row['credit_no'] ?: ('#' . $row['id'])); ?>
                <?php if(!empty($row['exchange_invoice_id'])){ ?>
                    <br><span class="badge badge-primary">Exchange</span>
                    <a href="view_invoice.php?id=<?= (int)$row['exchange_invoice_id']; ?>"><?= sales_return_list_h($row['exchange_invoice_no']); ?></a>
                <?php } ?>
            </td>
            <td><?= sales_return_list_h(app_date($row['created_at'])); ?></td>
            <td><a href="view_invoice.php?id=<?= (int)$row['invoice_id']; ?>"><?= sales_return_list_h($row['invoice_no']); ?></a></td>
            <td><?= sales_return_list_h($row['customer_name']); ?><?= !empty($row['customer_code']) ? ' (ID: ' . sales_return_list_h($row['customer_code']) . ')' : ''; ?></td>
            <td><?= number_format($value, 2); ?></td>
            <td><?= number_format((float)$row['amount'], 2); ?><?= (float)$row['amount'] > 0 && !empty($row['wallet_name']) ? '<br><small class="text-muted">' . sales_return_list_h($row['wallet_name']) . '</small>' : ''; ?></td>
            <?php if($show_due_column){ ?><td><?= number_format((float)$row['due_adjusted'], 2); ?></td><?php } ?>
            <?php if($show_advance_column){ ?><td><?= number_format((float)$row['advance_amount'], 2); ?></td><?php } ?>
            <td><a href="sales_return_print.php?id=<?= (int)$row['id']; ?>" target="_blank" class="btn btn-success btn-sm">Print</a></td>
        </tr>
    <?php } ?>
    </tbody>
</table>
</div>

</div>

</div>

<?php require_once '../includes/footer.php'; ?>
