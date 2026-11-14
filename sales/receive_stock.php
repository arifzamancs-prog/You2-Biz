<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_image_helper.php';
require_once '../includes/distribution_display_helper.php';
require_once '../includes/stock_request_helper.php';

$user_id = (int)$_SESSION['user_id'];
$warehouse_receipt_mode = !empty($warehouse_receipt_mode) && stock_can_manage_warehouse($conn);
$branch_id = $warehouse_receipt_mode ? stock_warehouse_id($conn, $user_id) : selected_branch_id($conn, true);
$message = $_SESSION['stock_receive_message'] ?? '';
$message_at = $_SESSION['stock_receive_message_at'] ?? '';
unset($_SESSION['stock_receive_message'], $_SESSION['stock_receive_message_at']);
$error = '';

if (!company_multi_branch_enabled($conn, $user_id)) {
    header('Location: create_invoice.php');
    exit;
}

// Every active location of the company, and how each one is named on this page.
$warehouse_location_id = (int)stock_warehouse_id($conn, $user_id);
$locations = [];
$location_result = mysqli_query($conn, "SELECT id,branch_name,branch_code,is_head_office FROM branches WHERE user_id={$user_id} AND status='active' ORDER BY CASE WHEN id={$warehouse_location_id} THEN 0 WHEN is_head_office=1 THEN 1 ELSE 2 END, branch_name");
while ($location_result && ($location = mysqli_fetch_assoc($location_result))) {
    $locations[(int)$location['id']] = $location;
}
$location_kind = static fn($id) => (int)$id === $warehouse_location_id ? 'Warehouse' : 'Branch';
$location_label = static function ($id) use ($locations) {
    $location = $locations[(int)$id] ?? null;
    if (!$location) return 'Location #' . (int)$id;
    return ($location['is_head_office'] ? 'Head Office' : $location['branch_name']) . (!empty($location['branch_code']) ? ' [' . $location['branch_code'] . ']' : '');
};
$direction_badge = static fn($from, $to) => '<span class="badge badge-light border">' . $location_kind($from) . ' &rarr; ' . $location_kind($to) . '</span>';

// Send stock from this location: Warehouse > Branch, Branch > Warehouse or Branch > Branch.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'send_stock') {
    $send_transaction = false;
    try {
        $base_request_key = (string)($_POST['request_key'] ?? '');
        $to_branch_id = (int)($_POST['to_branch_id'] ?? 0);
        $product_ids = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $variant_quantities = $_POST['variant_quantity'] ?? [];
        $variant_names = $_POST['variant_name'] ?? [];
        if (!is_array($product_ids) || !preg_match('/^[a-f0-9]{32,64}$/', $base_request_key)) throw new RuntimeException('Invalid send request. Reload the page and try again.');
        if (!isset($locations[$to_branch_id]) || $to_branch_id === $branch_id) throw new RuntimeException('Select where the stock goes.');
        $note = (string)($_POST['note'] ?? '');
        $distribution_ids = [];
        mysqli_begin_transaction($conn);
        $send_transaction = true;
        foreach ($product_ids as $line_index => $product_id) {
            $product_id = (int)$product_id;
            if ($product_id <= 0) continue;
            $line_variants = $variant_quantities[$line_index] ?? [];
            if (is_array($line_variants) && $line_variants) {
                foreach ($line_variants as $variant_index => $quantity) {
                    $quantity = (float)$quantity;
                    if ($quantity <= 0) continue;
                    $variant_name = (string)($variant_names[$line_index][$variant_index] ?? '');
                    $request_key = substr(hash('sha256', $base_request_key . '|' . $line_index . '|' . $variant_name), 0, 48);
                    $distribution_ids[] = fifo_inventory_distribute($conn, $user_id, $to_branch_id, $product_id, $quantity, $request_key, $note, $branch_id, $variant_name, $base_request_key, false);
                }
            } else {
                $quantity = (float)($quantities[$line_index] ?? 0);
                if ($quantity <= 0) continue;
                $request_key = substr(hash('sha256', $base_request_key . '|' . $line_index), 0, 48);
                $distribution_ids[] = fifo_inventory_distribute($conn, $user_id, $to_branch_id, $product_id, $quantity, $request_key, $note, $branch_id, '', $base_request_key, false);
            }
        }
        if (!$distribution_ids) throw new RuntimeException('Add a product and enter at least one quantity.');
        mysqli_commit($conn);
        $send_transaction = false;
        $_SESSION['stock_receive_message'] = 'Stock sent to ' . $location_label($to_branch_id) . '. It will be added there once they receive it.';
        header('Location: receive_stock.php');
        exit;
    } catch (Throwable $e) {
        if ($send_transaction) mysqli_rollback($conn);
        $error = $e->getMessage();
    }
}

// Product requests: ask another location for stock, and answer requests made to this one.
$request_tables_ready = stock_request_schema($conn);
$post_action = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? (string)($_POST['action'] ?? '') : '';
if (in_array($post_action, ['request_stock', 'request_send', 'request_reject', 'request_cancel'], true)) {
    try {
        if (!$request_tables_ready) throw new RuntimeException('Product requests are not available right now.');
        if ($post_action === 'request_stock') {
            $source_branch_id = (int)($_POST['source_branch_id'] ?? 0);
            if (!isset($locations[$source_branch_id]) || $source_branch_id === $branch_id) throw new RuntimeException('Select the location you want product from.');
            $lines = [];
            $product_ids = $_POST['product_id'] ?? [];
            foreach (is_array($product_ids) ? $product_ids : [] as $line_index => $product_id) {
                $line_variants = $_POST['variant_quantity'][$line_index] ?? [];
                if (is_array($line_variants) && $line_variants) {
                    foreach ($line_variants as $variant_index => $quantity) {
                        $lines[] = [(int)$product_id, (string)($_POST['variant_name'][$line_index][$variant_index] ?? ''), (float)$quantity];
                    }
                } else {
                    $lines[] = [(int)$product_id, '', (float)($_POST['quantity'][$line_index] ?? 0)];
                }
            }
            stock_request_create($conn, $user_id, $branch_id, $source_branch_id, $lines, (string)($_POST['note'] ?? ''), (string)($_POST['request_key'] ?? ''));
            $_SESSION['stock_receive_message'] = 'Product request sent to ' . $location_label($source_branch_id) . '. You will be notified when they send it.';
        } elseif ($post_action === 'request_send') {
            $send = $_POST['send_quantity'] ?? [];
            stock_request_send($conn, $user_id, (int)($_POST['request_id'] ?? 0), $branch_id, is_array($send) ? array_map('floatval', $send) : [], (string)($_POST['response_note'] ?? ''));
            $_SESSION['stock_receive_message'] = 'Requested product sent. It will be added there once they receive it.';
        } else {
            $close_status = $post_action === 'request_reject' ? 'rejected' : 'cancelled';
            stock_request_close($conn, $user_id, (int)($_POST['request_id'] ?? 0), $branch_id, $close_status, (string)($_POST['response_note'] ?? ''));
            $_SESSION['stock_receive_message'] = 'Product request ' . $close_status . '.';
        }
        $_SESSION['stock_receive_message_at'] = 'requests';
        header('Location: receive_stock.php#' . ($post_action === 'request_cancel' || $post_action === 'request_stock' ? 'requests-outgoing' : 'product-requests'));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($post_action === '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
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
    $group_key = implode('|', [$row['transfer_group'] ?: ('legacy-' . $row['created_at']), (int)$row['from_branch_id'], (int)$row['to_branch_id'], $row['note']]);
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
    $product_lines = [];
    $codes = [];
    $group['receipt_products'] = [];
    foreach($group['items'] as $item){
        $group['receipt_products'][(int)$item['product_id']][] = $item;
        $product_lines[$item['product_id']]['name'] = $item['product_name'];
        $product_lines[$item['product_id']]['parts'][] = ($item['variant_name'] !== '' ? $item['variant_name'] . ': ' : '') . number_format((float)$item['quantity'], 0);
        if(trim((string)$item['sku']) !== '') $codes[$item['sku']] = true;
    }
    $group['product_summary'] = array_map(static fn($product) => [$product['name'], implode(' || ', $product['parts'])], $product_lines);
    $group['code_summary'] = implode(', ', array_keys($codes));
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
        $row['transfer_group'] ?: ('legacy-' . $row['created_at']),
        (int)$row['to_branch_id'], $row['note']
    ]);
    if (!isset($sent_groups[$group_key])) {
        $sent_groups[$group_key] = ['row' => $row, 'items' => [], 'quantity' => 0];
    }
    $sent_groups[$group_key]['items'][] = $row;
    $sent_groups[$group_key]['quantity'] += (float)$row['quantity'];
}
foreach ($sent_groups as &$group) {
    $product_lines = [];
    $codes = [];
    foreach ($group['items'] as $item) {
        $product_lines[$item['product_id']]['name'] = $item['product_name'];
        $product_lines[$item['product_id']]['parts'][] = ($item['variant_name'] !== '' ? $item['variant_name'] . ': ' : '') . number_format((float)$item['quantity'], 0);
        if (trim((string)$item['sku']) !== '') $codes[$item['sku']] = true;
    }
    $group['product_summary'] = array_map(static fn($product) => [$product['name'], implode(' || ', $product['parts'])], $product_lines);
    $group['code_summary'] = implode(', ', array_keys($codes));
    $group['row'] = $group['items'][0];
}
unset($group);

$incoming_requests = $request_tables_ready ? stock_request_list($conn, $user_id, $branch_id, 'incoming') : [];
$my_requests = $request_tables_ready ? stock_request_list($conn, $user_id, $branch_id, 'outgoing') : [];

$received_history = mysqli_query($conn, "SELECT MIN(d.id) AS id,MIN(d.created_at) AS created_at,MIN(d.reference_no) AS reference_no,MAX(d.received_at) AS received_at,SUM(d.total_cost) AS total_cost,d.user_id,d.from_branch_id,d.to_branch_id,d.note,
    SUM(d.quantity) AS sent_quantity,SUM(d.quantity-d.damaged_quantity) AS received_quantity,SUM(d.damaged_quantity) AS damaged_quantity,
    GROUP_CONCAT(JSON_ARRAY(p.product_name,d.variant_summary) ORDER BY d.id SEPARATOR ',') AS product_summary,
    GROUP_CONCAT(DISTINCT p.sku ORDER BY p.sku SEPARATOR ', ') AS sku_summary,
    GROUP_CONCAT(CASE WHEN d.damaged_quantity>0 THEN CONCAT(p.product_name,' · ',d.damaged_variant_summary) ELSE NULL END ORDER BY d.id SEPARATOR ' || ') AS damaged_variant_summary,
    fb.branch_name AS from_branch_name,fb.is_head_office AS from_head_office,fb.branch_code AS from_branch_code,tb.branch_name AS to_branch_name,tb.is_head_office AS to_head_office,tb.branch_code AS to_branch_code
    FROM (
        SELECT MIN(id) AS id,MAX(id) AS last_id,MIN(created_at) AS created_at,MIN(reference_no) AS reference_no,MAX(received_at) AS received_at,
            user_id,from_branch_id,to_branch_id,product_id,status,note,
            COALESCE(NULLIF(transfer_group,''),CONCAT('legacy-',created_at)) AS voucher_key,
            SUM(total_cost) AS total_cost,SUM(quantity) AS quantity,SUM(damaged_quantity) AS damaged_quantity,
            GROUP_CONCAT(CONCAT(CASE WHEN COALESCE(variant_name,'')<>'' THEN CONCAT(variant_name,': ') ELSE '' END,FORMAT(quantity-damaged_quantity,0)) ORDER BY id SEPARATOR ' || ') AS variant_summary,
            GROUP_CONCAT(CASE WHEN damaged_quantity>0 THEN CONCAT(CASE WHEN COALESCE(variant_name,'')<>'' THEN CONCAT(variant_name,': ') ELSE '' END,FORMAT(damaged_quantity,0)) ELSE NULL END ORDER BY id SEPARATOR ' || ') AS damaged_variant_summary
        FROM stock_distributions
        WHERE user_id={$user_id} AND to_branch_id={$branch_id} AND status='accepted'
        GROUP BY user_id,from_branch_id,to_branch_id,product_id,status,note,COALESCE(NULLIF(transfer_group,''),CONCAT('legacy-',created_at))
    ) d
    INNER JOIN products p ON p.id=d.product_id AND p.user_id=d.user_id
    INNER JOIN branches fb ON fb.id=d.from_branch_id AND fb.user_id=d.user_id
    INNER JOIN branches tb ON tb.id=d.to_branch_id AND tb.user_id=d.user_id
    WHERE d.user_id={$user_id} AND d.to_branch_id={$branch_id} AND d.status='accepted'
    GROUP BY d.voucher_key,d.user_id,d.from_branch_id,d.to_branch_id,d.note,fb.branch_name,fb.is_head_office,fb.branch_code,tb.branch_name,tb.is_head_office,tb.branch_code
    ORDER BY MAX(d.received_at) DESC,MAX(d.last_id) DESC LIMIT 500");

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<section class="content"><div class="container-fluid"><div class="card card-outline card-success">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-shipping-fast mr-2"></i>Send Product</h3></div>
    <div class="card-body">
        <?php if ($message !== '' && $message_at !== 'requests') { ?><div class="alert alert-success"><?= htmlspecialchars($message); ?></div><?php } ?>
        <?php if ($error !== '') { ?><div class="alert alert-danger"><?= htmlspecialchars($error); ?></div><?php } ?>
        <p class="text-muted mb-3">Send stock from <strong><?= htmlspecialchars($location_label($branch_id)); ?></strong> (<?= $location_kind($branch_id); ?>) to the warehouse or another branch. It leaves this location now and is added there when they receive it.</p>
        <form method="post" id="send-stock-form">
            <input type="hidden" name="stock_csrf" value="<?= htmlspecialchars(stock_csrf_token()); ?>">
            <input type="hidden" name="action" value="send_stock">
            <input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(24)); ?>">
            <div class="form-row">
                <div class="form-group col-md-4"><label>From</label><input class="form-control" value="<?= htmlspecialchars($location_label($branch_id) . ' (' . $location_kind($branch_id) . ')'); ?>" readonly></div>
                <div class="form-group col-md-4"><label for="send-to">To</label>
                    <select id="send-to" name="to_branch_id" class="form-control" required>
                        <option value="">Select location</option>
                        <?php foreach (['Warehouse', 'Branch'] as $kind) { $group = array_filter($locations, fn($location) => $location_kind($location['id']) === $kind && (int)$location['id'] !== $branch_id); if (!$group) continue; ?>
                            <optgroup label="<?= $kind === 'Warehouse' ? 'Main Warehouse' : 'Branches'; ?>">
                                <?php foreach ($group as $location) { ?><option value="<?= (int)$location['id']; ?>" data-direction="<?= $location_kind($branch_id) . ' → ' . $kind; ?>"><?= htmlspecialchars($location_label($location['id'])); ?></option><?php } ?>
                            </optgroup>
                        <?php } ?>
                    </select>
                </div>
                <div class="form-group col-md-4 d-flex align-items-end"><span id="send-direction" class="badge badge-info p-2" hidden></span></div>
            </div>
            <div id="send-lines">
                <div class="form-row send-line">
                    <div class="form-group col-md-6"><label>Product / Available</label><select name="product_id[]" class="form-control send-product"><option value="">Loading products…</option></select></div>
                    <div class="form-group col-md-6"><label>Quantity</label><div class="send-quantity-wrap"><input class="form-control" type="number" disabled></div></div>
                </div>
            </div>
            <button class="btn btn-outline-success btn-sm mb-3" type="button" id="send-add-line"><i class="fas fa-plus mr-1"></i>Add more</button>
            <div class="form-row"><div class="form-group col-md-6"><label for="send-note">Note</label><input id="send-note" class="form-control" name="note" maxlength="500" placeholder="Optional"></div></div>
            <button class="btn btn-success" type="submit"><i class="fas fa-paper-plane mr-1"></i>Send Product</button>
        </form>
    </div>
</div></div></section>

<section class="content"><div class="container-fluid"><div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-truck mr-2"></i>Product Sent</h3></div>
    <div class="card-body"><div class="table-responsive"><table class="table table-bordered table-striped mb-0">
        <thead><tr><th>Photo</th><th>Reference</th><th>Date</th><th>To</th><th>Product</th><th>Code</th><th>Sent Qty</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($sent_groups as $group) { $row = $group['row']; ?>
            <tr>
                <td><img src="<?= htmlspecialchars(product_image_url($conn, $row['photo_path'] ?? '')); ?>" alt="" style="width:38px;height:38px;object-fit:cover;border-radius:3px"></td>
                <td><?= htmlspecialchars(trim((string)($row['reference_no'] ?? '')) ?: ('D-' . date('dmy', strtotime($row['created_at'])) . (int)$row['id'])); ?></td>
                <td><?= htmlspecialchars(app_date($row['created_at'])); ?></td>
                <td><?= htmlspecialchars(($row['to_head_office'] ? 'Head Office' : $row['to_branch_name']) . (!empty($row['to_branch_code']) ? ' [' . $row['to_branch_code'] . ']' : '')); ?><div class="mt-1"><?= $direction_badge($row['from_branch_id'], $row['to_branch_id']); ?></div></td>
                <td><?= distribution_product_blocks($group['product_summary']); ?></td>
                <td><?= htmlspecialchars($group['code_summary']); ?></td>
                <td><?= number_format($group['quantity'], 0); ?></td>
                <td><span class="badge badge-warning">Pending</span><small class="d-block text-muted">Awaiting receipt</small></td>
                <td><a href="../warehouse/print_distribution.php?id=<?= (int)$row['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-print mr-1"></i>Print</a></td>
            </tr>
        <?php } ?>
        <?php if (!$sent_groups) { ?><tr><td colspan="9" class="text-center text-muted py-4">No product sent request is pending from this location.</td></tr><?php } ?>
        </tbody>
    </table></div></div>
</div></div></section>

<section class="content" id="product-request"><div class="container-fluid"><div class="card card-outline card-info">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-hand-holding-medical mr-2"></i>Request Product</h3></div>
    <div class="card-body">
        <p class="text-muted mb-3">Ask another branch or the warehouse to send product to <strong><?= htmlspecialchars($location_label($branch_id)); ?></strong>. They see it in their request list and send it, or reject it.</p>
        <form method="post" id="request-stock-form">
            <input type="hidden" name="stock_csrf" value="<?= htmlspecialchars(stock_csrf_token()); ?>">
            <input type="hidden" name="action" value="request_stock">
            <input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(24)); ?>">
            <div class="form-row">
                <div class="form-group col-md-4"><label for="request-from">Request From</label>
                    <select id="request-from" name="source_branch_id" class="form-control" required>
                        <option value="">Select location</option>
                        <?php foreach (['Warehouse', 'Branch'] as $kind) { $group = array_filter($locations, fn($location) => $location_kind($location['id']) === $kind && (int)$location['id'] !== $branch_id); if (!$group) continue; ?>
                            <optgroup label="<?= $kind === 'Warehouse' ? 'Main Warehouse' : 'Branches'; ?>">
                                <?php foreach ($group as $location) { ?><option value="<?= (int)$location['id']; ?>" data-direction="<?= $kind . ' → ' . $location_kind($branch_id); ?>"><?= htmlspecialchars($location_label($location['id'])); ?></option><?php } ?>
                            </optgroup>
                        <?php } ?>
                    </select>
                </div>
                <div class="form-group col-md-4"><label>To</label><input class="form-control" value="<?= htmlspecialchars($location_label($branch_id) . ' (' . $location_kind($branch_id) . ')'); ?>" readonly></div>
                <div class="form-group col-md-4 d-flex align-items-end"><span id="request-direction" class="badge badge-info p-2" hidden></span></div>
            </div>
            <div id="request-lines">
                <div class="form-row request-line">
                    <div class="form-group col-md-6"><label>Product / Available there</label><select name="product_id[]" class="form-control request-product"><option value="">Select a location first</option></select></div>
                    <div class="form-group col-md-6"><label>Quantity</label><div class="request-quantity-wrap"><input class="form-control" type="number" disabled></div></div>
                </div>
            </div>
            <button class="btn btn-outline-info btn-sm mb-3" type="button" id="request-add-line"><i class="fas fa-plus mr-1"></i>Add more</button>
            <div class="form-row"><div class="form-group col-md-6"><label for="request-note">Note</label><input id="request-note" class="form-control" name="note" maxlength="500" placeholder="Optional"></div></div>
            <button class="btn btn-info" type="submit"><i class="fas fa-hand-paper mr-1"></i>Send Request</button>
        </form>
    </div>
</div></div></section>

<?php
$request_status_badge = ['pending' => 'warning', 'sent' => 'success', 'rejected' => 'danger', 'cancelled' => 'secondary'];
$request_items_html = static function ($request, $with_sent) {
    // One block per product; its variants on one line.
    $products = [];
    foreach ($request['items'] as $item) {
        $product_id = (int)$item['product_id'];
        $products[$product_id]['head'] = '<strong>' . htmlspecialchars($item['product_name']) . '</strong>'
            . (trim((string)$item['sku']) !== '' ? ' <small class="text-muted">[' . htmlspecialchars($item['sku']) . ']</small>' : '');
        $products[$product_id]['parts'][] = ($item['variant_name'] !== '' ? htmlspecialchars($item['variant_name']) . ': ' : 'Qty: ') . number_format((float)$item['quantity'], 0)
            . ($with_sent && $request['status'] === 'sent' ? ' (sent ' . number_format((float)$item['sent_quantity'], 0) . ')' : '');
    }
    $html = '';
    foreach ($products as $product) {
        $html .= '<div class="mb-1">' . $product['head'] . '<br><small>' . implode(' · ', $product['parts']) . '</small></div>';
    }
    return $html;
};
$request_total = static fn($request) => array_sum(array_map(static fn($item) => (float)$item['quantity'], $request['items']));
$incoming_pending = count(array_filter($incoming_requests, static fn($request) => $request['status'] === 'pending'));
$my_pending = count(array_filter($my_requests, static fn($request) => $request['status'] === 'pending'));
?>
<section class="content" id="product-requests"><div class="container-fluid"><div class="card card-outline card-info">
    <div class="card-header p-0 border-bottom-0">
        <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#requests-incoming" role="tab"><i class="fas fa-inbox mr-1"></i>Requests to <?= htmlspecialchars($location_label($branch_id)); ?> <?php if ($incoming_pending) { ?><span class="badge badge-warning ml-1"><?= $incoming_pending; ?></span><?php } ?></a></li>
            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#requests-outgoing" role="tab"><i class="fas fa-paper-plane mr-1"></i>My Requests <?php if ($my_pending) { ?><span class="badge badge-secondary ml-1"><?= $my_pending; ?></span><?php } ?></a></li>
        </ul>
    </div>
    <div class="card-body">
        <?php if ($message !== '' && $message_at === 'requests') { ?><div class="alert alert-success"><?= htmlspecialchars($message); ?></div><?php } ?>
        <div class="tab-content">
        <div class="tab-pane fade show active" id="requests-incoming" role="tabpanel">
            <div class="table-responsive"><table class="table table-bordered table-striped mb-0">
                <thead><tr><th>Request</th><th>Date</th><th>Requested By</th><th>Product</th><th>Qty</th><th>Note</th><th>Status</th><th style="min-width:260px">Action</th></tr></thead>
                <tbody>
                <?php foreach ($incoming_requests as $request) { $form_id = 'request-send-' . (int)$request['id']; ?>
                    <tr<?= $request['status'] === 'pending' ? ' class="table-warning"' : ''; ?>>
                        <td class="text-nowrap"><?= htmlspecialchars($request['request_no']); ?></td>
                        <td class="text-nowrap"><?= htmlspecialchars(app_date($request['created_at'])); ?><small class="d-block text-muted"><?= htmlspecialchars(date('h:i A', strtotime($request['created_at']))); ?></small></td>
                        <td><?= htmlspecialchars($location_label($request['requester_branch_id'])); ?><div class="mt-1"><?= $direction_badge($request['source_branch_id'], $request['requester_branch_id']); ?></div><?php if ($request['requested_by_name'] !== '') { ?><small class="d-block text-muted"><?= htmlspecialchars($request['requested_by_name']); ?></small><?php } ?></td>
                        <td><?= $request_items_html($request, true); ?></td>
                        <td><?= number_format($request_total($request), 0); ?></td>
                        <td class="text-break"><?= htmlspecialchars($request['note']); ?></td>
                        <td><span class="badge badge-<?= $request_status_badge[$request['status']] ?? 'light'; ?>"><?= htmlspecialchars(ucfirst($request['status'])); ?></span><?php if ($request['response_note'] !== '') { ?><small class="d-block text-muted"><?= htmlspecialchars($request['response_note']); ?></small><?php } ?></td>
                        <td>
                            <?php if ($request['status'] === 'pending') { ?>
                                <form id="<?= $form_id; ?>" method="post" class="request-send-form">
                                    <input type="hidden" name="stock_csrf" value="<?= htmlspecialchars(stock_csrf_token()); ?>">
                                    <input type="hidden" name="request_id" value="<?= (int)$request['id']; ?>">
                                    <?php foreach ($request['items'] as $item) { ?>
                                        <div class="input-group input-group-sm mb-1">
                                            <div class="input-group-prepend"><span class="input-group-text text-truncate" style="max-width:150px" title="<?= htmlspecialchars($item['product_name']); ?>"><?= htmlspecialchars($item['variant_name'] !== '' ? $item['variant_name'] : $item['product_name']); ?></span></div>
                                            <input type="number" class="form-control" name="send_quantity[<?= (int)$item['id']; ?>]" min="0" max="<?= (int)$item['quantity']; ?>" step="1" value="<?= (int)$item['quantity']; ?>">
                                            <small class="w-100 text-muted request-available" data-product="<?= (int)$item['product_id']; ?>" data-variant="<?= htmlspecialchars($item['variant_name']); ?>"></small>
                                        </div>
                                    <?php } ?>
                                    <input type="text" class="form-control form-control-sm mb-1" name="response_note" maxlength="500" placeholder="Note (optional, reason if rejected)">
                                    <button class="btn btn-success btn-sm" type="submit" name="action" value="request_send"><i class="fas fa-paper-plane mr-1"></i>Send</button>
                                    <button class="btn btn-outline-danger btn-sm" type="submit" name="action" value="request_reject" formnovalidate><i class="fas fa-times mr-1"></i>Reject</button>
                                </form>
                            <?php } else { ?>
                                <small class="text-muted"><?= htmlspecialchars(ucfirst($request['status'])); ?><?= $request['responded_at'] ? ' ' . htmlspecialchars(app_date($request['responded_at'])) : ''; ?><?= $request['responded_by_name'] !== '' ? ' · ' . htmlspecialchars($request['responded_by_name']) : ''; ?></small>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                <?php if (!$incoming_requests) { ?><tr><td colspan="8" class="text-center text-muted py-4">No branch has requested product from here.</td></tr><?php } ?>
                </tbody>
            </table></div>
        </div>
        <div class="tab-pane fade" id="requests-outgoing" role="tabpanel">
            <div class="table-responsive"><table class="table table-bordered table-striped mb-0">
                <thead><tr><th>Request</th><th>Date</th><th>Requested From</th><th>Product</th><th>Qty</th><th>Note</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($my_requests as $request) { ?>
                    <tr>
                        <td class="text-nowrap"><?= htmlspecialchars($request['request_no']); ?></td>
                        <td class="text-nowrap"><?= htmlspecialchars(app_date($request['created_at'])); ?><small class="d-block text-muted"><?= htmlspecialchars(date('h:i A', strtotime($request['created_at']))); ?></small></td>
                        <td><?= htmlspecialchars($location_label($request['source_branch_id'])); ?><div class="mt-1"><?= $direction_badge($request['source_branch_id'], $request['requester_branch_id']); ?></div></td>
                        <td><?= $request_items_html($request, true); ?></td>
                        <td><?= number_format($request_total($request), 0); ?></td>
                        <td class="text-break"><?= htmlspecialchars($request['note']); ?></td>
                        <td><span class="badge badge-<?= $request_status_badge[$request['status']] ?? 'light'; ?>"><?= htmlspecialchars(ucfirst($request['status'])); ?></span>
                            <?php if ($request['status'] === 'sent') { ?><small class="d-block text-muted">Receive it below</small><?php } ?>
                            <?php if ($request['response_note'] !== '') { ?><small class="d-block text-muted"><?= htmlspecialchars($request['response_note']); ?></small><?php } ?></td>
                        <td>
                            <?php if ($request['status'] === 'pending') { ?>
                                <form method="post" onsubmit="return confirm('Cancel this product request?');">
                                    <input type="hidden" name="stock_csrf" value="<?= htmlspecialchars(stock_csrf_token()); ?>">
                                    <input type="hidden" name="action" value="request_cancel">
                                    <input type="hidden" name="request_id" value="<?= (int)$request['id']; ?>">
                                    <button class="btn btn-outline-secondary btn-sm" type="submit"><i class="fas fa-ban mr-1"></i>Cancel</button>
                                </form>
                            <?php } else { ?>
                                <small class="text-muted"><?= $request['responded_at'] ? htmlspecialchars(app_date($request['responded_at'])) : ''; ?><?= $request['responded_by_name'] !== '' ? ' · ' . htmlspecialchars($request['responded_by_name']) : ''; ?></small>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                <?php if (!$my_requests) { ?><tr><td colspan="8" class="text-center text-muted py-4">You have not requested product from another location yet.</td></tr><?php } ?>
                </tbody>
            </table></div>
        </div>
    </div></div>
</div></div></section>

<section class="content" id="product-receive"><div class="container-fluid"><div class="card card-outline card-primary">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-truck-loading mr-2"></i>Product Receive</h3></div>
    <div class="card-body">
        <?php if ($warehouse_receipt_mode) { ?><a href="index.php" class="btn btn-outline-secondary mb-3">Back to Main Warehouse</a><?php } ?>
        <div class="table-responsive"><table class="table table-bordered table-striped mb-0">
    <thead><tr><th>Photo</th><th>Reference</th><th>From</th><th>Product</th><th>Code</th><th>Sent Qty</th><th>Good Received Qty</th><th>Action</th></tr></thead>
            <tbody>
            <?php if (!empty($pending_groups)) { foreach ($pending_groups as $group_key => $group) { $row = $group['row']; $is_selected_request = $group_key === $selected_group_key; ?>
                <tr id="receive-request-<?= (int)$row['id']; ?>" class="<?= $is_selected_request ? 'table-primary' : ''; ?>"><td><img src="<?= htmlspecialchars(product_image_url($conn, $row['photo_path'] ?? '')); ?>" alt="" style="width:38px;height:38px;object-fit:cover;border-radius:3px"></td>
                    <td><?= htmlspecialchars(trim((string)($row['reference_no'] ?? '')) ?: ('D-' . date('dmy', strtotime($row['created_at'])) . (int)$row['id'])); ?></td><td><?= htmlspecialchars($row['from_head_office'] ? 'Head Office' : $row['from_branch_name']); ?><div class="mt-1"><?= $direction_badge($row['from_branch_id'], $row['to_branch_id']); ?></div></td>
                    <td><?= distribution_product_blocks($group['product_summary']); ?></td><td><?= htmlspecialchars($group['code_summary']); ?></td><td><?= number_format($group['quantity'], 0); ?></td>
                    <td>
                        <?php $form_id = 'receipt-' . (int)$row['id']; ?>
                        <form id="<?= $form_id; ?>" method="post">
                            <input type="hidden" name="stock_csrf" value="<?= htmlspecialchars(stock_csrf_token()); ?>">
                            <?php foreach ($group['receipt_products'] as $product_items) { $product = $product_items[0]; ?>
                                <fieldset class="border rounded p-2 mb-2 bg-white" style="min-width:220px">
                                    <legend class="w-auto px-1 mb-2 h6 font-weight-bold">
                                        <?= htmlspecialchars($product['product_name']); ?><?php if(trim((string)$product['sku']) !== ''){ ?> [<?= htmlspecialchars($product['sku']); ?>]<?php } ?>
                                    </legend>
                                    <?php foreach ($product_items as $item) { $input_id = 'received-qty-' . (int)$item['id']; ?>
                                        <div class="input-group input-group-sm mb-1">
                                            <div class="input-group-prepend"><label for="<?= $input_id; ?>" class="input-group-text mb-0"><?= htmlspecialchars($item['variant_name'] ?: 'Qty'); ?></label></div>
                                            <input id="<?= $input_id; ?>" type="number" class="form-control receipt-quantity" name="distribution_received[<?= (int)$item['id']; ?>]" min="0" max="<?= (int)$item['quantity']; ?>" step="1" value="<?= (int)$item['quantity']; ?>" required>
                                            <small class="w-100 text-muted receipt-damage">Damaged: 0</small>
                                        </div>
                                    <?php } ?>
                                </fieldset>
                            <?php } ?>
                        </form>
                    </td>
                    <td><button form="<?= $form_id; ?>" class="btn btn-success btn-sm" type="submit"<?= $is_selected_request ? ' autofocus' : ''; ?>><i class="fas fa-check mr-1"></i>Receive</button></td></tr>
            <?php } } else { ?><tr><td colspan="8" class="text-center text-muted py-4">No product receive request is pending for this branch.</td></tr><?php } ?>
            </tbody>
        </table></div>
    </div>
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
                <td><?= htmlspecialchars(trim((string)($row['reference_no'] ?? '')) ?: ('D-' . date('dmy', strtotime($row['created_at'])) . (int)$row['id'])); ?></td>
                <td><?= htmlspecialchars(app_date($row['received_at'])); ?></td>
                <td><?= distribution_product_blocks($row['product_summary']); ?></td>
                <td><?= htmlspecialchars($row['sku_summary'] ?? ''); ?></td>
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
// Send Product: products and variants available at this location.
(function () {
    const form = document.getElementById('send-stock-form');
    if (!form) return;
    const lines = document.getElementById('send-lines');
    const template = lines.querySelector('.send-line').cloneNode(true);
    const to = document.getElementById('send-to');
    const direction = document.getElementById('send-direction');
    const escapeHtml = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    let products = [];
    to.addEventListener('change', function () {
        const option = to.selectedOptions[0];
        direction.hidden = !option || !option.value;
        direction.textContent = option && option.value ? option.dataset.direction : '';
    });
    function fillSelect(select) {
        select.innerHTML = '';
        select.add(new Option(products.length ? 'Select product' : 'No stock available here', ''));
        products.forEach(product => {
            const option = new Option(product.name + ' — Available: ' + Number(product.available).toLocaleString(), product.id);
            option.dataset.available = product.available;
            option.dataset.variants = JSON.stringify(product.variants || []);
            select.add(option);
        });
        select.addEventListener('change', () => renderQuantity(select));
    }
    function renderQuantity(select) {
        const row = select.closest('.send-line');
        const wrap = row.querySelector('.send-quantity-wrap');
        const index = [...lines.querySelectorAll('.send-line')].indexOf(row);
        const option = select.selectedOptions[0];
        if (!option || !option.value) { wrap.innerHTML = '<input class="form-control" type="number" disabled>'; return; }
        let variants = [];
        try { variants = JSON.parse(option.dataset.variants || '[]'); } catch (e) {}
        if (!variants.length) {
            const available = Number(option.dataset.available || 0);
            wrap.innerHTML = '<div class="input-group"><div class="input-group-prepend"><span class="input-group-text">Available: ' + available + '</span></div><input class="form-control" type="number" name="quantity[' + index + ']" min="1" max="' + available + '" step="1" required></div>';
            return;
        }
        wrap.innerHTML = variants.map((variant, v) =>
            '<div class="input-group mb-1"><div class="input-group-prepend"><span class="input-group-text">' + escapeHtml(variant.name) + ' (Available: ' + Number(variant.available) + ')</span></div>'
            + '<input type="hidden" name="variant_name[' + index + '][' + v + ']" value="' + escapeHtml(variant.name) + '">'
            + '<input class="form-control" type="number" name="variant_quantity[' + index + '][' + v + ']" min="0" max="' + Number(variant.available) + '" step="1"></div>'
        ).join('');
    }
    document.getElementById('send-add-line').addEventListener('click', function () {
        const row = template.cloneNode(true);
        lines.appendChild(row);
        fillSelect(row.querySelector('.send-product'));
    });
    fetch('../sales/send_stock_products.php<?= $warehouse_receipt_mode ? '?warehouse=1' : ''; ?>', {cache: 'no-store', credentials: 'same-origin'})
        .then(response => response.ok ? response.json() : [])
        .then(list => { products = Array.isArray(list) ? list : []; lines.querySelectorAll('.send-product').forEach(fillSelect); })
        .catch(() => { lines.querySelectorAll('.send-product').forEach(select => { select.innerHTML = '<option value="">Products could not be loaded</option>'; }); });
    form.addEventListener('submit', function (event) {
        const filled = [...form.querySelectorAll('input[name^="quantity"], input[name^="variant_quantity"]')].some(input => Number(input.value) > 0);
        if (!filled) { event.preventDefault(); alert('Add a product and enter at least one quantity.'); return; }
        if (!confirm('Send this stock to ' + (to.selectedOptions[0] ? to.selectedOptions[0].text : '') + '?')) event.preventDefault();
    });
})();
// Request Product: products and variants available at the location being asked.
(function () {
    const form = document.getElementById('request-stock-form');
    if (!form) return;
    const lines = document.getElementById('request-lines');
    const template = lines.querySelector('.request-line').cloneNode(true);
    const from = document.getElementById('request-from');
    const direction = document.getElementById('request-direction');
    const escapeHtml = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    let products = [];
    function fillSelect(select) {
        select.innerHTML = '';
        if (!from.value) { select.add(new Option('Select a location first', '')); return; }
        select.add(new Option(products.length ? 'Select product' : 'No stock available there', ''));
        products.forEach(product => {
            const option = new Option(product.name + ' — Available: ' + Number(product.available).toLocaleString(), product.id);
            option.dataset.available = product.available;
            option.dataset.variants = JSON.stringify(product.variants || []);
            select.add(option);
        });
    }
    function renderQuantity(select) {
        const row = select.closest('.request-line');
        const wrap = row.querySelector('.request-quantity-wrap');
        const index = [...lines.querySelectorAll('.request-line')].indexOf(row);
        const option = select.selectedOptions[0];
        if (!option || !option.value) { wrap.innerHTML = '<input class="form-control" type="number" disabled>'; return; }
        let variants = [];
        try { variants = JSON.parse(option.dataset.variants || '[]'); } catch (e) {}
        if (!variants.length) {
            const available = Number(option.dataset.available || 0);
            wrap.innerHTML = '<div class="input-group"><div class="input-group-prepend"><span class="input-group-text">Available: ' + available + '</span></div><input class="form-control" type="number" name="quantity[' + index + ']" min="1" max="' + available + '" step="1" required></div>';
            return;
        }
        wrap.innerHTML = variants.map((variant, v) =>
            '<div class="input-group mb-1"><div class="input-group-prepend"><span class="input-group-text">' + escapeHtml(variant.name) + ' (Available: ' + Number(variant.available) + ')</span></div>'
            + '<input type="hidden" name="variant_name[' + index + '][' + v + ']" value="' + escapeHtml(variant.name) + '">'
            + '<input class="form-control" type="number" name="variant_quantity[' + index + '][' + v + ']" min="0" max="' + Number(variant.available) + '" step="1"></div>'
        ).join('');
    }
    lines.addEventListener('change', event => { if (event.target.classList.contains('request-product')) renderQuantity(event.target); });
    function resetLines() {
        lines.querySelectorAll('.request-line').forEach((row, i) => { if (i > 0) row.remove(); });
        const select = lines.querySelector('.request-product');
        fillSelect(select);
        renderQuantity(select);
    }
    from.addEventListener('change', function () {
        const option = from.selectedOptions[0];
        direction.hidden = !option || !option.value;
        direction.textContent = option && option.value ? option.dataset.direction : '';
        products = [];
        if (!from.value) { resetLines(); return; }
        lines.querySelectorAll('.request-product').forEach(select => { select.innerHTML = '<option value="">Loading products…</option>'; });
        fetch('../sales/request_stock_products.php?source=' + encodeURIComponent(from.value) + '<?= $warehouse_receipt_mode ? '&warehouse=1' : ''; ?>', {cache: 'no-store', credentials: 'same-origin'})
            .then(response => response.ok ? response.json() : [])
            .then(list => { products = Array.isArray(list) ? list : []; resetLines(); })
            .catch(() => { lines.querySelectorAll('.request-product').forEach(select => { select.innerHTML = '<option value="">Products could not be loaded</option>'; }); });
    });
    document.getElementById('request-add-line').addEventListener('click', function () {
        const row = template.cloneNode(true);
        lines.appendChild(row);
        fillSelect(row.querySelector('.request-product'));
    });
    form.addEventListener('submit', function (event) {
        const filled = [...form.querySelectorAll('input[name^="quantity"], input[name^="variant_quantity"]')].some(input => Number(input.value) > 0);
        if (!filled) { event.preventDefault(); alert('Add a product and enter at least one quantity.'); return; }
        if (!confirm('Request this product from ' + (from.selectedOptions[0] ? from.selectedOptions[0].text : '') + '?')) event.preventDefault();
    });
})();
// Requests to this location: show what is available here, confirm Send / Reject.
(function () {
    const hints = document.querySelectorAll('.request-available');
    if (hints.length) {
        fetch('../sales/send_stock_products.php<?= $warehouse_receipt_mode ? '?warehouse=1' : ''; ?>', {cache: 'no-store', credentials: 'same-origin'})
            .then(response => response.ok ? response.json() : [])
            .then(list => {
                const stock = {};
                (Array.isArray(list) ? list : []).forEach(product => {
                    stock[product.id + '|'] = Number(product.available);
                    (product.variants || []).forEach(variant => { stock[product.id + '|' + variant.name] = Number(variant.available); });
                });
                hints.forEach(hint => {
                    const available = stock[hint.dataset.product + '|' + hint.dataset.variant] || 0;
                    hint.textContent = 'Available here: ' + available;
                    hint.classList.toggle('text-danger', available <= 0);
                });
            })
            .catch(() => {});
    }
    document.querySelectorAll('.request-send-form').forEach(form => {
        form.addEventListener('submit', event => {
            const action = event.submitter ? event.submitter.value : 'request_send';
            if (action === 'request_send' && ![...form.querySelectorAll('input[name^="send_quantity"]')].some(input => Number(input.value) > 0)) {
                event.preventDefault();
                alert('Enter at least one quantity to send.');
                return;
            }
            if (!confirm(action === 'request_reject' ? 'Reject this product request?' : 'Send this product now?')) event.preventDefault();
        });
    });
    // After a cancel the page opens on My Requests.
    document.addEventListener('DOMContentLoaded', () => {
        if (location.hash !== '#requests-outgoing' || !window.jQuery) return;
        jQuery('a[href="#requests-outgoing"]').tab('show');
        document.getElementById('product-requests').scrollIntoView();
    });
})();
const selectedRequest = document.querySelector('tr.table-primary[id^="receive-request-"]');
if (selectedRequest) {
    selectedRequest.scrollIntoView({ behavior: 'smooth', block: 'center' });
    const receiveButton = selectedRequest.querySelector('button[type="submit"]');
    if (receiveButton) receiveButton.focus();
}
</script>
<?php require_once '../includes/footer.php'; ?>
