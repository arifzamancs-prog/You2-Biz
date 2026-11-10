<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/purchase_return_helper.php';

$cid = (int)$_SESSION['user_id'];

if(is_agent_user() || !manager_has_permission('suppliers')){
    header('Location: index.php');
    exit;
}

ensure_fifo_inventory_tables($conn);
purchase_return_schema($conn);
$branch_scope = selected_branch_id($conn, false);
$find = trim((string)($_GET['purchase_no'] ?? ''));
$find_error = '';

// Find a purchase by number and open its return form.
if($find !== ''){
    $sql = 'SELECT id FROM purchases WHERE user_id=? AND purchase_no=?';
    $types = 'is';
    $params = [$cid, $find];
    if($branch_scope > 0){
        $sql .= ' AND branch_id=?';
        $types .= 'i';
        $params[] = (int)$branch_scope;
    }
    $found = purchase_return_rows($conn, $sql . ' LIMIT 1', $types, $params);
    if($found){
        header('Location: purchase_return_create.php?purchase_id=' . (int)$found[0]['id']);
        exit;
    }
    $find_error = 'Purchase not found.';
}

$returns = purchase_return_list($conn, $cid, $branch_scope);
$recent_sql = 'SELECT p.purchase_no, p.total_amount, s.supplier_name FROM purchases p LEFT JOIN suppliers s ON s.id=p.supplier_id WHERE p.user_id=?' . ($branch_scope > 0 ? ' AND p.branch_id=' . (int)$branch_scope : '') . ' ORDER BY p.id DESC LIMIT 300';
$recent = purchase_return_rows($conn, $recent_sql, 'i', [$cid]);
$message = $_SESSION['purchase_return_error'] ?? '';
unset($_SESSION['purchase_return_error']);
$saved = $_SESSION['purchase_return_saved'] ?? '';
unset($_SESSION['purchase_return_saved']);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">

<div class="card-header">
    <h3 class="card-title">Purchase Return</h3>
    <a class="float-right" href="index.php">Purchases</a>
</div>

<div class="card-body">

<?php if($saved !== ''){ ?><div class="alert alert-success"><?= purchase_return_h($saved); ?></div><?php } ?>
<?php if($message !== ''){ ?><div class="alert alert-danger"><?= purchase_return_h($message); ?></div><?php } ?>
<?php if($find_error !== ''){ ?><div class="alert alert-danger"><?= purchase_return_h($find_error); ?></div><?php } ?>

<form method="get" class="mb-3">
    <label for="purchase_no">Purchase No</label>
    <div class="input-group" style="max-width:720px;">
        <input type="text" id="purchase_no" name="purchase_no" class="form-control" list="returnPurchases" required
               placeholder="Purchase no" value="<?= purchase_return_h($find); ?>">
        <datalist id="returnPurchases">
            <?php foreach($recent as $row){ ?>
                <option value="<?= purchase_return_h($row['purchase_no']); ?>"><?= purchase_return_h(($row['supplier_name'] ?? '') . ' - ' . number_format((float)$row['total_amount'], 2)); ?></option>
            <?php } ?>
        </datalist>
        <div class="input-group-append">
            <button type="submit" class="btn btn-primary"><i class="fas fa-undo"></i> Return to <?= purchase_return_h(supplier_display_text('Supplier')); ?></button>
        </div>
    </div>
    <small class="text-muted">Only products from that purchase which are still in stock (not sold or used) can be returned.</small>
</form>

<div class="table-responsive">
<table id="example1" data-desktop-table="true" class="table table-bordered table-striped">
    <thead>
    <tr>
        <th>Return No</th>
        <th>Date</th>
        <th>Purchase No</th>
        <th><?= purchase_return_h(supplier_display_text('Supplier')); ?></th>
        <th>Total</th>
        <th>Due Deducted</th>
        <th>Cash Refund</th>
        <th>Action</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach($returns as $row){ ?>
        <tr>
            <td><?= purchase_return_h($row['return_no']); ?></td>
            <td><?= purchase_return_h(app_date($row['return_date'])); ?></td>
            <td><a href="view.php?id=<?= (int)$row['purchase_id']; ?>"><?= purchase_return_h($row['purchase_no']); ?></a></td>
            <td><?= purchase_return_h($row['supplier_name']); ?></td>
            <td><?= number_format((float)$row['total_amount'], 2); ?></td>
            <td><?= number_format((float)$row['due_adjusted'], 2); ?></td>
            <td><?= number_format((float)$row['cash_refund'], 2); ?><?= (float)$row['cash_refund'] > 0 && !empty($row['wallet_name']) ? '<br><small class="text-muted">' . purchase_return_h($row['wallet_name']) . '</small>' : ''; ?></td>
            <td><a href="purchase_return_print.php?id=<?= (int)$row['id']; ?>" target="_blank" class="btn btn-success btn-sm">Print</a></td>
        </tr>
    <?php } ?>
    </tbody>
</table>
</div>

</div>

</div>

<?php require_once '../includes/footer.php'; ?>
