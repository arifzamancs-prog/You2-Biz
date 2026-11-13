<?php
// Product requests between locations: a branch (or the warehouse) asks another
// location for stock. The asked location sends it (a normal stock distribution
// the requester then receives) or rejects the request.
require_once __DIR__ . '/fifo_inventory_helper.php';
require_once __DIR__ . '/activity_notification_helper.php';

// Run outside any transaction (CREATE TABLE commits implicitly).
function stock_request_schema($conn)
{
    static $ready = null;
    if ($ready !== null) return $ready;
    $ready = (bool)mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        request_no VARCHAR(40) NOT NULL,
        request_key VARCHAR(64) NOT NULL,
        requester_branch_id INT NOT NULL,
        source_branch_id INT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        note VARCHAR(500) NOT NULL DEFAULT '',
        response_note VARCHAR(500) NOT NULL DEFAULT '',
        transfer_group VARCHAR(64) NOT NULL DEFAULT '',
        requested_by_user_id INT NOT NULL DEFAULT 0,
        requested_by_name VARCHAR(150) NOT NULL DEFAULT '',
        responded_by_user_id INT NOT NULL DEFAULT 0,
        responded_by_name VARCHAR(150) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        responded_at DATETIME NULL,
        UNIQUE KEY stock_request_key (user_id, request_key),
        UNIQUE KEY stock_request_no (user_id, request_no),
        KEY stock_request_source (user_id, source_branch_id, status),
        KEY stock_request_requester (user_id, requester_branch_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")
    && mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_request_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_id INT NOT NULL,
        product_id INT NOT NULL,
        variant_name VARCHAR(100) NOT NULL DEFAULT '',
        quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
        sent_quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
        KEY stock_request_items_request (request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return $ready;
}

// Where a location's Product Send/Receive page lives (the warehouse has its own).
function stock_request_page_url($conn, $company_id, $location_id)
{
    $page = (int)$location_id === (int)stock_warehouse_id($conn, (int)$company_id) ? 'warehouse/receive_stock.php' : 'sales/receive_stock.php';
    return $page . '#product-requests';
}

function stock_request_actor_name()
{
    return mb_substr(trim((string)($_SESSION['login_name'] ?? $_SESSION['user_name'] ?? '')), 0, 150);
}

// $lines: [[product_id, variant_name, quantity], ...]. Returns the new request id.
function stock_request_create($conn, $company_id, $requester_branch_id, $source_branch_id, array $lines, $note, $request_key)
{
    $company_id = (int)$company_id;
    $requester_branch_id = (int)$requester_branch_id;
    $source_branch_id = (int)$source_branch_id;
    if (!preg_match('/^[a-f0-9]{32,64}$/', (string)$request_key)) throw new RuntimeException('Invalid request. Reload the page and try again.');
    if ($requester_branch_id === $source_branch_id) throw new RuntimeException('Select another location to request from.');
    foreach ([$requester_branch_id, $source_branch_id] as $location_id) {
        $location = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM branches WHERE id={$location_id} AND user_id={$company_id} AND status='active'"));
        if (!$location) throw new RuntimeException('Select an active location of this company.');
    }

    $key_sql = mysqli_real_escape_string($conn, (string)$request_key);
    $existing = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM stock_requests WHERE user_id={$company_id} AND request_key='{$key_sql}'"));
    if ($existing) return (int)$existing['id'];

    $clean = [];
    foreach ($lines as [$product_id, $variant_name, $quantity]) {
        $product_id = (int)$product_id;
        $variant_name = trim((string)$variant_name);
        $quantity = (float)$quantity;
        if ($product_id <= 0 || $quantity <= 0) continue;
        if (!is_finite($quantity) || floor($quantity) !== $quantity) throw new RuntimeException('Enter whole quantities.');
        $product = mysqli_fetch_assoc(mysqli_query($conn, "SELECT p.id FROM products p INNER JOIN product_categories c ON c.id=p.category_id WHERE p.id={$product_id} AND p.user_id={$company_id} AND p.status='active' AND c.category_type='stock_product'"));
        if (!$product) throw new RuntimeException('Stock product not found.');
        if (!product_variant_is_valid($conn, $product_id, $company_id, $variant_name)) throw new RuntimeException('Select a valid product variant.');
        $line_key = $product_id . '|' . $variant_name;
        $clean[$line_key] = [$product_id, $variant_name, ($clean[$line_key][2] ?? 0) + $quantity];
    }
    if (!$clean) throw new RuntimeException('Add a product and enter at least one quantity.');

    $note = mb_substr(trim((string)$note), 0, 500);
    $by_id = (int)($_SESSION['login_user_id'] ?? 0);
    $by_name = stock_request_actor_name();
    mysqli_begin_transaction($conn);
    try {
        $base = 'SR-' . date('ymdHis');
        $request_no = $base;
        for ($suffix = 2; mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM stock_requests WHERE user_id={$company_id} AND request_no='" . mysqli_real_escape_string($conn, $request_no) . "'")); $suffix++) {
            $request_no = $base . '-' . $suffix;
        }
        $stmt = mysqli_prepare($conn, 'INSERT INTO stock_requests(user_id,request_no,request_key,requester_branch_id,source_branch_id,note,requested_by_user_id,requested_by_name) VALUES(?,?,?,?,?,?,?,?)');
        mysqli_stmt_bind_param($stmt, 'issiisis', $company_id, $request_no, $request_key, $requester_branch_id, $source_branch_id, $note, $by_id, $by_name);
        if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Request could not be saved.');
        $request_id = (int)mysqli_insert_id($conn);
        $item = mysqli_prepare($conn, 'INSERT INTO stock_request_items(request_id,product_id,variant_name,quantity) VALUES(?,?,?,?)');
        $total = 0;
        foreach ($clean as [$product_id, $variant_name, $quantity]) {
            mysqli_stmt_bind_param($item, 'iisd', $request_id, $product_id, $variant_name, $quantity);
            if (!mysqli_stmt_execute($item)) throw new RuntimeException('Request could not be saved.');
            $total += $quantity;
        }
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }

    activity_notify($conn, $company_id, $source_branch_id, 'stock_request', $request_id,
        'Product request ' . $request_no,
        'From ' . stock_request_location_name($conn, $company_id, $requester_branch_id) . ' · ' . number_format($total) . ' pcs',
        stock_request_page_url($conn, $company_id, $source_branch_id));
    return $request_id;
}

function stock_request_location_name($conn, $company_id, $location_id)
{
    $location = mysqli_fetch_assoc(mysqli_query($conn, "SELECT branch_name,branch_code,is_head_office FROM branches WHERE id=" . (int)$location_id . " AND user_id=" . (int)$company_id));
    if (!$location) return 'Location #' . (int)$location_id;
    if ((int)$location_id === (int)stock_warehouse_id($conn, (int)$company_id)) return 'Main Warehouse';
    return ($location['is_head_office'] ? 'Head Office' : $location['branch_name']) . (!empty($location['branch_code']) ?' [' . $location['branch_code'] . ']' : '');
}

// Pending request of this company, locked for the response.
function stock_request_lock_pending($conn, $company_id, $request_id)
{
    $request = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM stock_requests WHERE id=" . (int)$request_id . " AND user_id=" . (int)$company_id . " FOR UPDATE"));
    if (!$request) throw new RuntimeException('Request not found.');
    if ($request['status'] !== 'pending') throw new RuntimeException('This request was already ' . $request['status'] . '.');
    return $request;
}

// The asked location sends the stock. $send: [item_id => quantity]. Returns the transfer group.
function stock_request_send($conn, $company_id, $request_id, $source_branch_id, array $send, $response_note = '')
{
    $company_id = (int)$company_id;
    $response_note = mb_substr(trim((string)$response_note), 0, 500);
    mysqli_begin_transaction($conn);
    try {
        $request = stock_request_lock_pending($conn, $company_id, $request_id);
        if ((int)$request['source_branch_id'] !== (int)$source_branch_id) throw new RuntimeException('Only the requested location can send this request.');
        $items = mysqli_query($conn, "SELECT * FROM stock_request_items WHERE request_id=" . (int)$request['id'] . " ORDER BY id");
        $group = substr(hash('sha256', 'stock-request|' . $company_id . '|' . $request['id']), 0, 48);
        $note = 'Request ' . $request['request_no'] . ($response_note !== '' ? ' · ' . $response_note : '');
        $sent_total = 0;
        $update = mysqli_prepare($conn, 'UPDATE stock_request_items SET sent_quantity=? WHERE id=?');
        while ($items && ($item = mysqli_fetch_assoc($items))) {
            $quantity = (float)($send[(int)$item['id']] ?? 0);
            if ($quantity <= 0) continue;
            if (!is_finite($quantity) || floor($quantity) !== $quantity || $quantity > (float)$item['quantity']) throw new RuntimeException('Send quantity cannot be more than the requested quantity.');
            $key = substr(hash('sha256', $group . '|' . $item['id']), 0, 48);
            fifo_inventory_distribute($conn, $company_id, (int)$request['requester_branch_id'], (int)$item['product_id'], $quantity, $key, $note, (int)$source_branch_id, (string)$item['variant_name'], $group, false);
            $item_id = (int)$item['id'];
            mysqli_stmt_bind_param($update, 'di', $quantity, $item_id);
            mysqli_stmt_execute($update);
            $sent_total += $quantity;
        }
        if ($sent_total <= 0) throw new RuntimeException('Enter at least one quantity to send.');
        $by_id = (int)($_SESSION['login_user_id'] ?? 0);
        $by_name = stock_request_actor_name();
        $stmt = mysqli_prepare($conn, "UPDATE stock_requests SET status='sent', transfer_group=?, response_note=?, responded_by_user_id=?, responded_by_name=?, responded_at=NOW() WHERE id=?");
        $id = (int)$request['id'];
        mysqli_stmt_bind_param($stmt, 'ssisi', $group, $response_note, $by_id, $by_name, $id);
        mysqli_stmt_execute($stmt);
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
    $first = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM stock_distributions WHERE user_id={$company_id} AND transfer_group='" . mysqli_real_escape_string($conn, $group) . "' ORDER BY id LIMIT 1"));
    $page = (int)$request['requester_branch_id'] === (int)stock_warehouse_id($conn, $company_id) ? 'warehouse/receive_stock.php' : 'sales/receive_stock.php';
    activity_notify($conn, $company_id, (int)$request['requester_branch_id'], 'stock_request', (int)$request['id'],
        'Request ' . $request['request_no'] . ' sent',
        stock_request_location_name($conn, $company_id, $source_branch_id) . ' sent ' . number_format($sent_total) . ' pcs · receive it',
        $page . ($first ? '?distribution_id=' . (int)$first['id'] . '#receive-request-' . (int)$first['id'] : ''));
    return $group;
}

// Reject (by the asked location) or cancel (by the requester).
function stock_request_close($conn, $company_id, $request_id, $location_id, $status, $response_note = '')
{
    $company_id = (int)$company_id;
    $response_note = mb_substr(trim((string)$response_note), 0, 500);
    mysqli_begin_transaction($conn);
    try {
        $request = stock_request_lock_pending($conn, $company_id, $request_id);
        $owner_column = $status === 'rejected' ? 'source_branch_id' : 'requester_branch_id';
        if (!in_array($status, ['rejected', 'cancelled'], true) || (int)$request[$owner_column] !== (int)$location_id) {
            throw new RuntimeException($status === 'rejected' ? 'Only the requested location can reject this request.' : 'Only the requesting location can cancel this request.');
        }
        $by_id = (int)($_SESSION['login_user_id'] ?? 0);
        $by_name = stock_request_actor_name();
        $stmt = mysqli_prepare($conn, 'UPDATE stock_requests SET status=?, response_note=?, responded_by_user_id=?, responded_by_name=?, responded_at=NOW() WHERE id=?');
        $id = (int)$request['id'];
        mysqli_stmt_bind_param($stmt, 'ssisi', $status, $response_note, $by_id, $by_name, $id);
        mysqli_stmt_execute($stmt);
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
    $notify_location = $status === 'rejected' ? (int)$request['requester_branch_id'] : (int)$request['source_branch_id'];
    activity_notify($conn, $company_id, $notify_location, 'stock_request', (int)$request['id'],
        'Request ' . $request['request_no'] . ' ' . $status,
        stock_request_location_name($conn, $company_id, $location_id) . ($response_note !== '' ? ' · ' . $response_note : ''),
        stock_request_page_url($conn, $company_id, $notify_location));
}

// Requests asked to ($side 'incoming') or made by ($side 'outgoing') a location, with their items.
function stock_request_list($conn, $company_id, $location_id, $side, $limit = 100)
{
    $company_id = (int)$company_id;
    $location_id = (int)$location_id;
    $column = $side === 'incoming' ? 'source_branch_id' : 'requester_branch_id';
    $requests = [];
    $result = mysqli_query($conn, "SELECT * FROM stock_requests WHERE user_id={$company_id} AND {$column}={$location_id}
        ORDER BY status='pending' DESC, id DESC LIMIT " . (int)$limit);
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $row['items'] = [];
        $requests[(int)$row['id']] = $row;
    }
    if ($requests) {
        $items = mysqli_query($conn, "SELECT i.*, p.product_name, p.sku, p.photo_path FROM stock_request_items i
            INNER JOIN products p ON p.id=i.product_id AND p.user_id={$company_id}
            WHERE i.request_id IN (" . implode(',', array_keys($requests)) . ") ORDER BY i.id");
        while ($items && ($item = mysqli_fetch_assoc($items))) {
            $requests[(int)$item['request_id']]['items'][] = $item;
        }
    }
    return array_values($requests);
}

function stock_request_pending_count($conn, $company_id, $location_id)
{
    if (!stock_request_schema($conn)) return 0;
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS n FROM stock_requests WHERE user_id=" . (int)$company_id . " AND source_branch_id=" . (int)$location_id . " AND status='pending'"));
    return (int)($row['n'] ?? 0);
}
