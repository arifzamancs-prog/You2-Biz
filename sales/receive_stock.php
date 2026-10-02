<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_image_helper.php';

$user_id = (int)$_SESSION['user_id'];
$warehouse_receipt_mode = !empty($warehouse_receipt_mode) && stock_can_manage_warehouse($conn);
$branch_id = $warehouse_receipt_mode ? stock_warehouse_id($conn, $user_id) : selected_branch_id($conn, true);
$message = $_SESSION['stock_receive_message'] ?? '';
unset($_SESSION['stock_receive_message']);
$error = '';

if (!company_multi_branch_enabled($conn, $user_id)) {
    header('Location: create_invoice.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $receipts = $_POST['distribution_received'] ?? [];
        if(!is_array($receipts) || empty($receipts)){ throw new RuntimeException('Reload this page and select a stock receipt.'); }
        $damages = [];
        $total_sent_quantity = 0.0;
        $total_received_quantity = 0.0;
        foreach($receipts as $distribution_id => $received_quantity){
            $distribution_id = (int)$distribution_id;
            if(!is_scalar($received_quantity) || !is_numeric($received_quantity)) throw new RuntimeException('Invalid received quantity.');
            $received_quantity = (float)$received_quantity;
            $distribution = mysqli_fetch_assoc(mysqli_query($conn, "SELECT quantity,variant_name FROM stock_distributions WHERE id={$distribution_id} AND user_id={$user_id} AND to_branch_id={$branch_id} AND status='pending'"));
            $sent_quantity = (float)($distribution['quantity'] ?? 0);
            $variant_label = trim((string)($distribution['variant_name'] ?? ''));
            if(!$distribution || !is_finite($received_quantity) || $received_quantity < 0 || floor($received_quantity) !== $received_quantity || $received_quantity > $sent_quantity){
                throw new RuntimeException(($variant_label !== '' ? $variant_label . ': ' : '') . 'Received quantity cannot exceed sent quantity.');
            }
            $total_sent_quantity += $sent_quantity;
            $total_received_quantity += $received_quantity;
            $damages[$distribution_id] = $sent_quantity - $received_quantity;
        }
        if($total_received_quantity > $total_sent_quantity){
            throw new RuntimeException('Total received quantity cannot exceed total sent quantity.');
        }
        foreach($damages as $distribution_id => $damaged_quantity){
            fifo_inventory_receive_distribution($conn, $user_id, $distribution_id, $damaged_quantity, $branch_id);
        }
        $_SESSION['stock_receive_message'] = 'Product stock received successfully.';
        header('Location: receive_stock.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$pending = mysqli_query($conn, "SELECT d.*,p.product_name,p.sku,p.photo_path,fb.branch_name AS from_branch_name,fb.is_head_office AS from_head_office
    FROM stock_distributions d
    INNER JOIN products p ON p.id=d.product_id AND p.user_id=d.user_id
    INNER JOIN branches fb ON fb.id=d.from_branch_id AND fb.user_id=d.user_id
    WHERE d.user_id={$user_id} AND d.to_branch_id={$branch_id} AND d.status='pending'
    ORDER BY d.created_at ASC,d.id ASC");

$pending_groups = [];
$selected_distribution_id = max(0, (int)($_GET['distribution_id'] ?? 0));
$selected_group_key = '';
while($pending && ($row = mysqli_fetch_assoc($pending))){
    $group_key = implode('|', [(int)$row['product_id'], (int)$row['from_branch_id'], (int)$row['to_branch_id'], $row['note'], date('Y-m-d', strtotime($row['created_at']))]);
    if(!isset($pending_groups[$group_key])){
        $pending_groups[$group_key] = ['row' => $row, 'items' => [], 'quantity' => 0];
    }
    $pending_groups[$group_key]['items'][] = $row;
    $pending_groups[$group_key]['quantity'] += (float)$row['quantity'];
    if((int)$row['id'] === $selected_distribution_id){
        $selected_group_key = $group_key;
    }
}

$received_history = mysqli_query($conn, "SELECT MIN(d.id) AS id,MIN(d.reference_no) AS reference_no,MAX(d.received_at) AS received_at,d.user_id,d.from_branch_id,d.to_branch_id,d.product_id,d.note,
    SUM(d.quantity) AS sent_quantity,SUM(d.quantity-d.damaged_quantity) AS received_quantity,SUM(d.damaged_quantity) AS damaged_quantity,
    GROUP_CONCAT(CASE WHEN d.variant_name<>'' THEN CONCAT(d.variant_name, ': ', FORMAT(d.quantity-d.damaged_quantity,0)) ELSE NULL END ORDER BY d.id SEPARATOR ' || ') AS variant_summary,
    GROUP_CONCAT(CASE WHEN d.variant_name<>'' AND d.damaged_quantity>0 THEN CONCAT(d.variant_name, ': ', FORMAT(d.damaged_quantity,0)) ELSE NULL END ORDER BY d.id SEPARATOR ' || ') AS damaged_variant_summary,
    p.product_name,p.sku,fb.branch_name AS from_branch_name,fb.is_head_office AS from_head_office
    FROM stock_distributions d
    INNER JOIN products p ON p.id=d.product_id AND p.user_id=d.user_id
    INNER JOIN branches fb ON fb.id=d.from_branch_id AND fb.user_id=d.user_id
    WHERE d.user_id={$user_id} AND d.to_branch_id={$branch_id} AND d.status='accepted'
    GROUP BY COALESCE(NULLIF(d.transfer_group,''), CONCAT('legacy-',d.id)),d.user_id,d.from_branch_id,d.to_branch_id,d.product_id,d.note,p.product_name,p.sku,fb.branch_name,fb.is_head_office
    ORDER BY MAX(d.received_at) DESC,MAX(d.id) DESC LIMIT 500");

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<section class="content"><div class="container-fluid"><div class="card card-outline card-primary">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-truck-loading mr-2"></i>Product Receive Request</h3></div>
    <div class="card-body">
        <?php if ($message !== '') { ?><div class="alert alert-success"><?= htmlspecialchars($message); ?></div><?php } ?>
        <?php if ($error !== '') { ?><div class="alert alert-danger"><?= htmlspecialchars($error); ?></div><?php } ?>
        <?php if ($warehouse_receipt_mode) { ?><a href="index.php" class="btn btn-outline-secondary mb-3">Back to Main Warehouse</a><?php } ?>
        <p class="text-muted">Receive stock sent to <strong><?= htmlspecialchars($warehouse_receipt_mode ? 'Main Warehouse' : current_branch_label($conn)); ?></strong>.</p>
        <div class="table-responsive"><table class="table table-bordered table-striped mb-0">
    <thead><tr><th>Photo</th><th>Reference</th><th>From</th><th>Product</th><th>Code</th><th>Sent Qty</th><th>Good Received Qty</th><th>Action</th></tr></thead>
            <tbody>
            <?php if (!empty($pending_groups)) { foreach ($pending_groups as $group_key => $group) { $row = $group['row']; $is_selected_request = $group_key === $selected_group_key; ?>
                <tr id="receive-request-<?= (int)$row['id']; ?>" class="<?= $is_selected_request ? 'table-primary' : ''; ?>"><td><img src="<?= htmlspecialchars(product_image_url($conn, $row['photo_path'] ?? '')); ?>" alt="" style="width:38px;height:38px;object-fit:cover;border-radius:3px"></td>
                    <td><?= htmlspecialchars($row['reference_no']); ?></td><td><?= htmlspecialchars($row['from_head_office'] ? 'Head Office' : $row['from_branch_name']); ?></td>
                    <td><?= htmlspecialchars($row['product_name']); ?><?php $variant_parts=[]; foreach($group['items'] as $item){ if($item['variant_name'] !== ''){ $variant_parts[] = htmlspecialchars($item['variant_name']) . ': ' . number_format((float)$item['quantity'],0); } } ?><?php if($variant_parts){ ?><small class="d-block text-muted"><?= implode(' || ', $variant_parts); ?></small><?php } ?></td><td><?= htmlspecialchars($row['sku'] ?? ''); ?></td><td><?= number_format($group['quantity'], 0); ?></td>
                    <td><?php $form_id = 'receipt-' . (int)$row['id']; ?><form id="<?= $form_id; ?>" method="post"><input type="hidden" name="stock_csrf" value="<?= htmlspecialchars(stock_csrf_token()); ?>"><?php foreach($group['items'] as $item){ ?><div class="input-group input-group-sm mb-1" style="min-width:200px"><div class="input-group-prepend"><span class="input-group-text"><?= htmlspecialchars($item['variant_name'] ?: 'Qty'); ?></span></div><input type="number" class="form-control receipt-quantity" name="distribution_received[<?= (int)$item['id']; ?>]" min="0" max="<?= (int)$item['quantity']; ?>" step="1" value="<?= (int)$item['quantity']; ?>" required><small class="w-100 text-muted receipt-damage">Damaged: 0</small></div><?php } ?></form></td>
                    <td><button form="<?= $form_id; ?>" class="btn btn-success btn-sm" type="submit"<?= $is_selected_request ? ' autofocus' : ''; ?>><i class="fas fa-check mr-1"></i>Receive</button></td></tr>
            <?php } } else { ?><tr><td colspan="8" class="text-center text-muted py-4">No product receive request is pending for this branch.</td></tr><?php } ?>
            </tbody>
        </table></div>
    </div>
</div></div></section>

<section class="content"><div class="container-fluid"><div class="card card-outline card-success">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-history mr-2"></i>Receive History</h3></div>
    <div class="card-body"><div class="table-responsive"><table class="table table-bordered table-striped mb-0" style="table-layout:fixed;min-width:1100px">
        <colgroup><col style="width:11%"><col style="width:14%"><col style="width:14%"><col style="width:22%"><col style="width:7%"><col style="width:10%"><col style="width:10%"><col style="width:12%"></colgroup>
        <thead><tr><th>Reference</th><th>Received</th><th>From</th><th>Product</th><th>Code</th><th>Received Qty</th><th>Damaged</th><th>Note</th></tr></thead>
        <tbody><?php if($received_history && mysqli_num_rows($received_history) > 0){ while($row = mysqli_fetch_assoc($received_history)){ ?>
            <tr><td><?= htmlspecialchars($row['reference_no']); ?></td><td><?= htmlspecialchars(app_datetime($row['received_at'])); ?></td><td><?= htmlspecialchars($row['from_head_office'] ? 'Head Office' : $row['from_branch_name']); ?></td><td><?= htmlspecialchars($row['product_name']); ?><?php if(!empty($row['variant_summary'])){ ?><small class="d-block text-muted"><?= htmlspecialchars($row['variant_summary']); ?></small><?php } ?></td><td><?= htmlspecialchars($row['sku'] ?? ''); ?></td><td><?= number_format((float)$row['received_quantity'],0); ?></td><td><?= number_format((float)$row['damaged_quantity'],0); ?><?php if(!empty($row['damaged_variant_summary'])){ ?><small class="d-block text-muted"><?= htmlspecialchars($row['damaged_variant_summary']); ?></small><?php } ?></td><td><?= htmlspecialchars($row['note']); ?></td></tr>
        <?php } }else{ ?><tr><td colspan="8" class="text-center text-muted py-4">No stock has been received by this branch yet.</td></tr><?php } ?></tbody>
    </table></div><small class="text-muted d-block mt-2">Latest 500 received stock records for this branch.</small></div>
</div></div></section>
<script>
document.querySelectorAll('.receipt-quantity').forEach(function(input){
    input.addEventListener('input', function(){
        const maximum = Number(input.max);
        let received = Number(input.value);
        if(Number.isFinite(received) && received > maximum){
            input.value = maximum;
            received = maximum;
        }
        if(Number.isFinite(received) && received < 0){
            input.value = 0;
            received = 0;
        }
        input.parentElement.querySelector('.receipt-damage').textContent = 'Damaged: ' + Math.max(0, maximum - (Number.isFinite(received) ? received : 0));
    });
});
document.querySelectorAll('form[id^="receipt-"]').forEach(function(form){
    form.addEventListener('submit', function(event){
        const inputs = Array.from(form.querySelectorAll('.receipt-quantity'));
        const totalSent = inputs.reduce(function(total, input){ return total + Number(input.max || 0); }, 0);
        const totalReceived = inputs.reduce(function(total, input){ return total + Number(input.value || 0); }, 0);
        const invalidItem = inputs.find(function(input){
            const value = Number(input.value);
            return !Number.isInteger(value) || value < 0 || value > Number(input.max);
        });

        if(invalidItem || totalReceived > totalSent){
            event.preventDefault();
            alert('Received quantity cannot exceed sent quantity, individually or in total.');
            if(invalidItem) invalidItem.focus();
        }
    });
});
const selectedRequest = document.querySelector('tr.table-primary[id^="receive-request-"]');
if (selectedRequest) {
    selectedRequest.scrollIntoView({ behavior: 'smooth', block: 'center' });
    const receiveButton = selectedRequest.querySelector('button[type="submit"]');
    if (receiveButton) receiveButton.focus();
}
</script>
<?php require_once '../includes/footer.php'; ?>
