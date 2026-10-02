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
foreach ($pending_groups as &$group) {
    $variant_order = array_flip(product_variant_names($conn, (int)$group['row']['product_id'], $user_id));
    usort($group['items'], static function ($left, $right) use ($variant_order) {
        return ($variant_order[$left['variant_name']] ?? PHP_INT_MAX) <=> ($variant_order[$right['variant_name']] ?? PHP_INT_MAX);
    });
    $group['row'] = $group['items'][0];
}
unset($group);

$sent_pending = mysqli_query($conn, "SELECT d.*,p.product_name,p.sku,p.photo_path,tb.branch_name AS to_branch_name,tb.branch_code AS to_branch_code,tb.is_head_office AS to_head_office
    FROM stock_distributions d
    INNER JOIN products p ON p.id=d.product_id AND p.user_id=d.user_id
    INNER JOIN branches tb ON tb.id=d.to_branch_id AND tb.user_id=d.user_id
    WHERE d.user_id={$user_id} AND d.from_branch_id={$branch_id} AND d.status='pending'
    ORDER BY d.created_at ASC,d.id ASC");
$sent_groups = [];
while ($sent_pending && ($row = mysqli_fetch_assoc($sent_pending))) {
    $group_key = json_encode([
        $row['transfer_group'] ?? ('legacy-' . $row['created_at']),
        (int)$row['product_id'], (int)$row['to_branch_id'], $row['note']
    ]);
    if (!isset($sent_groups[$group_key])) {
        $sent_groups[$group_key] = ['row' => $row, 'items' => [], 'quantity' => 0];
    }
    $sent_groups[$group_key]['items'][] = $row;
    $sent_groups[$group_key]['quantity'] += (float)$row['quantity'];
}
foreach ($sent_groups as &$group) {
    $variant_order = array_flip(product_variant_names($conn, (int)$group['row']['product_id'], $user_id));
    usort($group['items'], static function ($left, $right) use ($variant_order) {
        return ($variant_order[$left['variant_name']] ?? PHP_INT_MAX) <=> ($variant_order[$right['variant_name']] ?? PHP_INT_MAX);
    });
}
unset($group);

$received_history = mysqli_query($conn, "SELECT MIN(d.id) AS id,MIN(d.created_at) AS created_at,MIN(d.reference_no) AS reference_no,MAX(d.received_at) AS received_at,SUM(d.total_cost) AS total_cost,d.user_id,d.from_branch_id,d.to_branch_id,d.product_id,d.note,
    SUM(d.quantity) AS sent_quantity,SUM(d.quantity-d.damaged_quantity) AS received_quantity,SUM(d.damaged_quantity) AS damaged_quantity,
    GROUP_CONCAT(CASE WHEN d.variant_name<>'' THEN CONCAT(d.variant_name, ': ', FORMAT(d.quantity-d.damaged_quantity,0)) ELSE NULL END ORDER BY d.id SEPARATOR ' || ') AS variant_summary,
    GROUP_CONCAT(CASE WHEN d.variant_name<>'' AND d.damaged_quantity>0 THEN CONCAT(d.variant_name, ': ', FORMAT(d.damaged_quantity,0)) ELSE NULL END ORDER BY d.id SEPARATOR ' || ') AS damaged_variant_summary,
    p.product_name,p.sku,fb.branch_name AS from_branch_name,fb.is_head_office AS from_head_office,fb.branch_code AS from_branch_code,tb.branch_name AS to_branch_name,tb.is_head_office AS to_head_office,tb.branch_code AS to_branch_code
    FROM stock_distributions d
    INNER JOIN products p ON p.id=d.product_id AND p.user_id=d.user_id
    INNER JOIN branches fb ON fb.id=d.from_branch_id AND fb.user_id=d.user_id
    INNER JOIN branches tb ON tb.id=d.to_branch_id AND tb.user_id=d.user_id
    WHERE d.user_id={$user_id} AND d.to_branch_id={$branch_id} AND d.status='accepted'
    GROUP BY COALESCE(NULLIF(d.transfer_group,''), CONCAT('legacy-',d.created_at)),d.user_id,d.from_branch_id,d.to_branch_id,d.product_id,d.note,p.product_name,p.sku,fb.branch_name,fb.is_head_office,fb.branch_code,tb.branch_name,tb.is_head_office,tb.branch_code
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

<section class="content"><div class="container-fluid"><div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-truck mr-2"></i>Product Sent Request</h3></div>
    <div class="card-body"><div class="table-responsive"><table class="table table-bordered table-striped mb-0">
        <thead><tr><th>Photo</th><th>Reference</th><th>Date</th><th>To</th><th>Product</th><th>Code</th><th>Sent Qty</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($sent_groups as $group) { $row = $group['row']; ?>
            <tr>
                <td><img src="<?= htmlspecialchars(product_image_url($conn, $row['photo_path'] ?? '')); ?>" alt="" style="width:38px;height:38px;object-fit:cover;border-radius:3px"></td>
                <td><?= 'D-' . date('dmy', strtotime($row['created_at'])) . (int)$row['id']; ?></td>
                <td><?= htmlspecialchars(app_date($row['created_at'])); ?></td>
                <td><?= htmlspecialchars(($row['to_head_office'] ? 'Head Office' : $row['to_branch_name']) . (!empty($row['to_branch_code']) ? ' [' . $row['to_branch_code'] . ']' : '')); ?></td>
                <td><?= htmlspecialchars($row['product_name']); ?><?php
                    $variant_parts = [];
                    foreach ($group['items'] as $item) {
                        if ($item['variant_name'] !== '') $variant_parts[] = htmlspecialchars($item['variant_name']) . ': ' . number_format((float)$item['quantity'], 0);
                    }
                    if ($variant_parts) { ?><small class="d-block text-muted"><?= implode(' || ', $variant_parts); ?></small><?php } ?></td>
                <td><?= htmlspecialchars($row['sku'] ?? ''); ?></td>
                <td><?= number_format($group['quantity'], 0); ?></td>
                <td><span class="badge badge-warning">Pending</span><small class="d-block text-muted">Awaiting receipt</small></td>
                <td><a href="../warehouse/print_distribution.php?id=<?= (int)$row['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-print mr-1"></i>Print</a></td>
            </tr>
        <?php } ?>
        <?php if (!$sent_groups) { ?><tr><td colspan="9" class="text-center text-muted py-4">No product sent request is pending from this location.</td></tr><?php } ?>
        </tbody>
    </table></div></div>
</div></div></section>

<section class="content"><div class="container-fluid"><div class="card">
    <div class="card-header"><h3 class="card-title">Distribution History</h3><div class="card-tools" style="width:300px"><div class="input-group input-group-sm"><div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div><input id="receive-history-search" class="form-control" aria-label="Search distribution history" placeholder="Search product, branch, status or note"></div></div></div>
    <div class="card-body">
    <div class="mb-3"><label for="receive-history-count" class="font-weight-normal">Show <select id="receive-history-count" class="custom-select custom-select-sm d-inline-block mx-1" style="width:auto"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option><option value="500">All (latest 500)</option></select> entries</label></div>
    <style>
    .receive-history-table{table-layout:fixed;min-width:1500px;width:100%}
    .receive-history-table th,.receive-history-table td{vertical-align:middle;overflow-wrap:anywhere}
    .receive-history-table th{white-space:nowrap}
    .receive-history-table td:nth-child(1),.receive-history-table td:nth-child(2),.receive-history-table td:nth-child(7),.receive-history-table td:nth-child(8),.receive-history-table td:nth-child(9){white-space:nowrap;overflow-wrap:normal}
    </style>
    <div class="table-responsive"><table class="table table-bordered table-striped receive-history-table">
        <colgroup><col style="width:10%"><col style="width:8%"><col style="width:14%"><col style="width:6%"><col style="width:12%"><col style="width:12%"><col style="width:5%"><col style="width:8%"><col style="width:7%"><col style="width:9%"><col style="width:9%"></colgroup>
        <thead><tr><th>Reference</th><th>Received</th><th>Product</th><th>Code</th><th>From Branch</th><th>To Branch</th><th>Qty</th><th>Received Qty</th><th>Cost</th><th>Status</th><th>Note</th></tr></thead>
        <tbody id="receive-history-list"><?php if($received_history){ while($row = mysqli_fetch_assoc($received_history)){ ?>
            <tr data-receive-history-row>
                <td><?= 'D-' . date('dmy', strtotime($row['created_at'])) . (int)$row['id']; ?></td>
                <td><?= htmlspecialchars(app_date($row['received_at'])); ?></td>
                <td><?= htmlspecialchars($row['product_name']); ?><?php if(!empty($row['variant_summary'])){ ?><small class="d-block text-muted"><?= htmlspecialchars($row['variant_summary']); ?></small><?php } ?></td>
                <td><?= htmlspecialchars($row['sku'] ?? ''); ?></td>
                <td><?= htmlspecialchars(($row['from_head_office'] ? 'Head Office' : $row['from_branch_name']) . (!empty($row['from_branch_code']) ? ' [' . $row['from_branch_code'] . ']' : '')); ?></td>
                <td><?= htmlspecialchars(($row['to_head_office'] ? 'Head Office' : $row['to_branch_name']) . (!empty($row['to_branch_code']) ? ' [' . $row['to_branch_code'] . ']' : '')); ?></td>
                <td><?= number_format((float)$row['sent_quantity'],0); ?></td>
                <td><?= number_format((float)$row['received_quantity'],0); ?></td>
                <td><?= number_format((float)$row['total_cost'],2); ?></td>
                <td><span class="badge badge-success">Accepted</span><?php if((float)$row['damaged_quantity'] > 0){ ?><small class="d-block text-danger mt-1">Damaged: <?= number_format((float)$row['damaged_quantity'],0); ?></small><?php if(!empty($row['damaged_variant_summary'])){ ?><small class="d-block text-muted"><?= htmlspecialchars($row['damaged_variant_summary']); ?></small><?php } } ?></td>
                <td class="text-break"><?= htmlspecialchars($row['note']); ?></td>
            </tr>
        <?php } } ?><tr id="receive-history-empty" hidden><td colspan="11" class="text-center text-muted py-4">No matching received stock records.</td></tr></tbody>
    </table></div><div class="d-flex justify-content-end align-items-center"><div><button type="button" id="receive-history-prev" class="btn btn-sm btn-outline-secondary">Previous</button> <button type="button" id="receive-history-next" class="btn btn-sm btn-outline-secondary">Next</button></div></div></div>
</div></div></section>
<script>
(() => {
    const rows = Array.from(document.querySelectorAll('[data-receive-history-row]'));
    const search = document.getElementById('receive-history-search');
    const count = document.getElementById('receive-history-count');
    const previous = document.getElementById('receive-history-prev');
    const next = document.getElementById('receive-history-next');
    let page = 0;
    function renderHistory() {
        const keyword = search.value.trim().toLowerCase();
        const filtered = rows.filter(row => row.textContent.toLowerCase().includes(keyword));
        const size = Number(count.value);
        page = Math.max(0, Math.min(page, Math.ceil(filtered.length / size) - 1));
        rows.forEach(row => { row.hidden = true; });
        filtered.slice(page * size, (page + 1) * size).forEach(row => { row.hidden = false; });
        document.getElementById('receive-history-empty').hidden = filtered.length > 0;
        previous.disabled = page === 0;
        next.disabled = (page + 1) * size >= filtered.length;
    }
    search.addEventListener('input', () => { page = 0; renderHistory(); });
    count.addEventListener('change', () => { page = 0; renderHistory(); });
    previous.addEventListener('click', () => { page--; renderHistory(); });
    next.addEventListener('click', () => { page++; renderHistory(); });
    renderHistory();
})();
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
