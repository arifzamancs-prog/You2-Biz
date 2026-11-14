<?php
// Service Center and Warranty Management for Inventory & Sales companies.
//
// Service: a walk-in or registered customer brings a product for repair. The job is
// received with a receipt (promised delivery date, estimated charge, optional advance).
// On delivery the final service charge and any spare parts used from stock become a
// normal posted sales invoice, so stock (FIFO), profit, customer due and wallets all
// follow the existing sales rules.
//
// Warranty: a product sold on an invoice comes back under warranty. The claim is
// received (receive time recorded), optionally sent to the supplier and received back,
// then delivered. A replacement from own stock is posted as a zero-value invoice so the
// stock and its cost are recorded.
//
// Accounting:
//   - Advance at receive: wallet + ("service_advance" transaction, income).
//   - Delivery: invoice total = parts + service charge. Paid = advance used + cash now.
//     Cash now goes to the wallet as "sales_invoice". Any unused advance is refunded
//     ("service_refund"). Registered customers may keep a due on the invoice.
//   - Cancel: the advance can be refunded ("service_refund").

require_once __DIR__ . '/schema_cache_helper.php';

function sw_module_active($conn = null)
{
    return ($_SESSION['company_type'] ?? '') === 'Stock Product' && function_exists('sales_module_enabled') && sales_module_enabled();
}

// Page guard for the service and warranty pages (under sales/, so the stock bootstrap,
// stock_sales permission and CSRF check already ran).
function sw_require_module($conn)
{
    if (!sw_module_active($conn) || is_agent_user()) {
        header('Location: ' . app_path('dashboard.php?error=' . rawurlencode('Service and Warranty are available for Inventory & Sales companies.')));
        exit;
    }
    sw_schema($conn);
}

function sw_schema($conn)
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    $ready = true;
    if (!schema_once('service_warranty_schema_v1')) {
        return true;
    }
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS service_jobs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        job_no VARCHAR(40) NOT NULL,
        customer_id INT NOT NULL DEFAULT 0,
        customer_name VARCHAR(150) NOT NULL DEFAULT '',
        customer_phone VARCHAR(30) NOT NULL DEFAULT '',
        item_name VARCHAR(150) NOT NULL DEFAULT '',
        brand_model VARCHAR(150) NOT NULL DEFAULT '',
        serial_no VARCHAR(100) NOT NULL DEFAULT '',
        problem TEXT NULL,
        accessories VARCHAR(255) NOT NULL DEFAULT '',
        received_at DATETIME NOT NULL,
        delivery_date DATE NULL,
        estimated_charge DECIMAL(15,2) NOT NULL DEFAULT 0,
        advance_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        advance_wallet_id INT NOT NULL DEFAULT 0,
        staff_id INT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'received',
        service_charge DECIMAL(15,2) NOT NULL DEFAULT 0,
        parts_total DECIMAL(15,2) NOT NULL DEFAULT 0,
        invoice_id INT NOT NULL DEFAULT 0,
        refund_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        ready_at DATETIME NULL,
        delivered_at DATETIME NULL,
        note TEXT NULL,
        created_by_user_id INT NOT NULL DEFAULT 0,
        created_by_name VARCHAR(150) NOT NULL DEFAULT '',
        request_key VARCHAR(64) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_service_job_no (user_id, job_no),
        UNIQUE KEY uniq_service_request (request_key),
        KEY idx_service_status (user_id, branch_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS warranty_claims (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        claim_no VARCHAR(40) NOT NULL,
        invoice_id INT NOT NULL,
        invoice_no VARCHAR(50) NOT NULL DEFAULT '',
        invoice_item_id INT NOT NULL,
        product_id INT NOT NULL,
        variant_name VARCHAR(100) NOT NULL DEFAULT '',
        product_name VARCHAR(150) NOT NULL DEFAULT '',
        customer_id INT NOT NULL DEFAULT 0,
        customer_name VARCHAR(150) NOT NULL DEFAULT '',
        customer_phone VARCHAR(30) NOT NULL DEFAULT '',
        sale_date DATE NULL,
        warranty_months INT NOT NULL DEFAULT 0,
        warranty_until DATE NULL,
        in_warranty TINYINT(1) NOT NULL DEFAULT 0,
        serial_no VARCHAR(100) NOT NULL DEFAULT '',
        problem TEXT NULL,
        accessories VARCHAR(255) NOT NULL DEFAULT '',
        received_at DATETIME NOT NULL,
        expected_date DATE NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'received',
        supplier_id INT NOT NULL DEFAULT 0,
        sent_date DATE NULL,
        back_date DATE NULL,
        supplier_result VARCHAR(20) NOT NULL DEFAULT '',
        delivery_type VARCHAR(20) NOT NULL DEFAULT '',
        replace_product_id INT NOT NULL DEFAULT 0,
        replace_variant VARCHAR(100) NOT NULL DEFAULT '',
        replace_invoice_id INT NOT NULL DEFAULT 0,
        delivered_at DATETIME NULL,
        note TEXT NULL,
        created_by_user_id INT NOT NULL DEFAULT 0,
        created_by_name VARCHAR(150) NOT NULL DEFAULT '',
        request_key VARCHAR(64) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_warranty_claim_no (user_id, claim_no),
        UNIQUE KEY uniq_warranty_request (request_key),
        KEY idx_warranty_status (user_id, branch_id, status),
        KEY idx_warranty_item (invoice_item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // One history table for both modules: kind = service | warranty.
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS service_warranty_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        kind VARCHAR(10) NOT NULL,
        record_id INT NOT NULL,
        status VARCHAR(20) NOT NULL,
        note TEXT NULL,
        created_by_name VARCHAR(150) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_sw_log (kind, record_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    product_warranty_schema($conn);
    invoice_item_warranty_schema($conn);
    return true;
}

function product_warranty_schema($conn)
{
    if (!schema_once('product_warranty_months')) {
        return;
    }
    $col = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'warranty_months'");
    if ($col && mysqli_num_rows($col) === 0) {
        mysqli_query($conn, 'ALTER TABLE products ADD COLUMN warranty_months INT NOT NULL DEFAULT 0');
    }
}

// Product form: Warranty (months) is shown for Inventory & Sales companies.
function product_warranty_enabled()
{
    return ($_SESSION['company_type'] ?? '') === 'Stock Product';
}

function product_warranty_save($conn, $user_id, $product_id, $months)
{
    if (!product_warranty_enabled()) {
        return;
    }
    product_warranty_schema($conn);
    $months = max(0, min(600, (int)$months));
    $stmt = mysqli_prepare($conn, 'UPDATE products SET warranty_months=? WHERE id=? AND user_id=?');
    mysqli_stmt_bind_param($stmt, 'iii', $months, $product_id, $user_id);
    mysqli_stmt_execute($stmt);
}

function product_warranty_months($conn, $user_id, $product_id)
{
    product_warranty_schema($conn);
    $row = mysqli_fetch_row(mysqli_query($conn, 'SELECT warranty_months FROM products WHERE id=' . (int)$product_id . ' AND user_id=' . (int)$user_id));
    return (int)($row[0] ?? 0);
}

/* ---------- small helpers ---------- */

function sw_actor_name()
{
    $name = trim((string)($_SESSION['login_name'] ?? $_SESSION['user_name'] ?? ''));
    return $name !== '' ? $name : 'Admin';
}

function sw_money($value)
{
    return round((float)$value, 2);
}

function sw_log($conn, $user_id, $kind, $record_id, $status, $note = '')
{
    $name = sw_actor_name();
    $stmt = mysqli_prepare($conn, 'INSERT INTO service_warranty_log (user_id, kind, record_id, status, note, created_by_name) VALUES (?,?,?,?,?,?)');
    mysqli_stmt_bind_param($stmt, 'isisss', $user_id, $kind, $record_id, $status, $note, $name);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException('History could not be saved.');
    }
}

function sw_history($conn, $kind, $record_id)
{
    $rows = [];
    $stmt = mysqli_prepare($conn, 'SELECT status, note, created_by_name, created_at FROM service_warranty_log WHERE kind=? AND record_id=? ORDER BY id');
    mysqli_stmt_bind_param($stmt, 'si', $kind, $record_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    return $rows;
}

// SRV-2611-0001 / WRN-2611-0001: per company and month.
function sw_next_number($conn, $user_id, $kind)
{
    [$table, $column, $prefix] = $kind === 'service' ? ['service_jobs', 'job_no', 'SRV'] : ['warranty_claims', 'claim_no', 'WRN'];
    $base = $prefix . '-' . date('ym') . '-';
    $like = mysqli_real_escape_string($conn, $base) . '%';
    $row = mysqli_fetch_row(mysqli_query($conn, "SELECT MAX(CAST(SUBSTRING({$column}, " . (strlen($base) + 1) . ") AS UNSIGNED)) FROM {$table} WHERE user_id=" . (int)$user_id . " AND {$column} LIKE '{$like}' FOR UPDATE"));
    return $base . str_pad((string)((int)($row[0] ?? 0) + 1), 4, '0', STR_PAD_LEFT);
}

function sw_service_statuses()
{
    return ['received' => 'Received', 'ready' => 'Ready', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'];
}

function sw_warranty_statuses()
{
    return ['received' => 'Received', 'sent_supplier' => 'Sent to Supplier', 'back_supplier' => 'Back from Supplier', 'ready' => 'Ready', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'];
}

function sw_status_badge($status)
{
    return ['received' => 'info', 'sent_supplier' => 'warning', 'back_supplier' => 'primary', 'ready' => 'success', 'delivered' => 'secondary', 'cancelled' => 'dark'][$status] ?? 'light';
}

function sw_warranty_delivery_types()
{
    return [
        'repaired' => 'Repaired / Fixed',
        'replaced_supplier' => 'Replaced by Supplier',
        'replaced_stock' => 'Replaced from our Stock',
        'returned' => 'Returned without repair',
    ];
}

function sw_supplier_results()
{
    return ['repaired' => 'Repaired', 'replaced' => 'Replaced', 'rejected' => 'Rejected (not covered)'];
}

function sw_branch_allows($conn, $record_branch_id)
{
    $scope = selected_branch_id($conn, false);
    return $scope <= 0 || $scope === (int)$record_branch_id;
}

function sw_wallet_adjust($conn, $user_id, $branch_id, $wallet_id, $type, $reference_id, $amount, $txn_no, $note)
{
    $amount = sw_money($amount);
    if ($amount <= 0) {
        return;
    }
    stock_require_wallet($conn, (int)$wallet_id, (int)$user_id, (int)$branch_id);
    $sign = $type === 'service_refund' ? -1 : 1;
    if ($sign < 0) {
        $row = mysqli_fetch_row(mysqli_query($conn, 'SELECT balance FROM wallets WHERE id=' . (int)$wallet_id . ' AND user_id=' . (int)$user_id . ' FOR UPDATE'));
        if ((float)($row[0] ?? 0) + 0.001 < $amount) {
            throw new RuntimeException('The selected wallet does not have enough balance for this refund.');
        }
    }
    record_wallet_transaction($conn, $txn_no, $user_id, $wallet_id, $type, $reference_id, $amount, $note, date('Y-m-d'), $branch_id);
    $signed = $sign * $amount;
    $stmt = mysqli_prepare($conn, 'UPDATE wallets SET balance = balance + ? WHERE id=? AND user_id=?');
    mysqli_stmt_bind_param($stmt, 'dii', $signed, $wallet_id, $user_id);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException('Wallet balance could not be updated.');
    }
}

// The invoice charge used for the service charge line (hidden from the normal invoice form).
function sw_service_charge_type_id($conn, $user_id)
{
    $stmt = mysqli_prepare($conn, "SELECT id FROM invoice_charge_types WHERE user_id=? AND charge_name='Service Charge' AND charge_type='add' ORDER BY id LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $id = (int)(mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0] ?? 0);
    if ($id > 0) {
        return $id;
    }
    $insert = mysqli_prepare($conn, "INSERT INTO invoice_charge_types (user_id, charge_name, charge_type, status, show_on_invoice, charge_value_type, default_value, apply_on) VALUES (?, 'Service Charge', 'add', 'active', 0, 'fixed', 0, 'invoice')");
    mysqli_stmt_bind_param($insert, 'i', $user_id);
    if (!mysqli_stmt_execute($insert)) {
        throw new RuntimeException('Service charge type could not be prepared.');
    }
    return (int)mysqli_insert_id($conn);
}

function sw_generate_invoice_no($conn)
{
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $no = 'INV-' . date('ymdHis') . random_int(10, 99);
        $esc = mysqli_real_escape_string($conn, $no);
        $row = mysqli_fetch_row(mysqli_query($conn, "SELECT (SELECT COUNT(*) FROM invoices WHERE invoice_no='{$esc}') + (SELECT COUNT(*) FROM transactions WHERE txn_no='{$esc}')"));
        if ((int)($row[0] ?? 0) === 0) {
            return $no;
        }
    }
    return 'INV-' . date('ymdHis') . random_int(100, 999);
}

/**
 * Posts a sales invoice for a service delivery or a warranty replacement.
 * Must run inside the caller's transaction.
 *
 * $o = [customer_id, customer_name, items => [[product_id, variant_name, qty, unit_price]],
 *       charge_amount, paid_cash, advance_used, wallet_id, note]
 * Returns [invoice_id, invoice_no, total, due].
 */
function sw_post_invoice($conn, $user_id, $branch_id, array $o)
{
    $user_id = (int)$user_id;
    $branch_id = (int)$branch_id;
    $customer_id = (int)($o['customer_id'] ?? 0);
    $customer_name = trim((string)($o['customer_name'] ?? '')) ?: 'Instant Customer';
    $items = $o['items'] ?? [];
    $charge = sw_money($o['charge_amount'] ?? 0);
    $paid_cash = sw_money($o['paid_cash'] ?? 0);
    $advance_used = sw_money($o['advance_used'] ?? 0);
    $wallet_id = (int)($o['wallet_id'] ?? 0);
    $note = (string)($o['note'] ?? '');

    // Monthly invoice limit of the subscription.
    $limit = mysqli_fetch_assoc(mysqli_query($conn, "SELECT max_invoices_monthly, (SELECT COUNT(*) FROM invoices WHERE user_id=users.id AND MONTH(invoice_date)=MONTH(CURDATE()) AND YEAR(invoice_date)=YEAR(CURDATE())) AS invoice_count FROM users WHERE id={$user_id}"));
    if ($limit && (int)$limit['max_invoices_monthly'] > 0 && (int)$limit['invoice_count'] >= (int)$limit['max_invoices_monthly']) {
        throw new RuntimeException('Monthly invoice limit reached for your subscription. ' . (function_exists('subscription_support_message') ? subscription_support_message() : ''));
    }

    // Merge lines per product/variant for the stock check.
    $subtotal = 0.0;
    $need = [];
    foreach ($items as $item) {
        $qty = (int)$item['qty'];
        $price = sw_money($item['unit_price']);
        if ($qty <= 0 || $price < 0) {
            throw new RuntimeException('Enter a valid quantity and price for every part.');
        }
        $subtotal += round($qty * $price, 2);
        $key = (int)$item['product_id'] . '|' . (string)$item['variant_name'];
        $need[$key] = ($need[$key] ?? 0) + $qty;
    }
    $GLOBALS['stock_wallet_branch_id'] = $branch_id;
    foreach ($need as $key => $qty) {
        [$product_id, $variant] = explode('|', $key, 2);
        if (!product_variant_is_valid($conn, (int)$product_id, $user_id, $variant)) {
            throw new RuntimeException('Select a valid variant for the selected product.');
        }
        $snapshot = product_stock_snapshot_for_invoice($conn, $user_id, (int)$product_id, 0, $variant);
        if (!$snapshot) {
            throw new RuntimeException('Product not found.');
        }
        if ($snapshot['is_stock_product'] && $qty > (float)$snapshot['available_stock'] + 0.0001) {
            throw new RuntimeException('Not enough stock for ' . $snapshot['product_name'] . ($variant !== '' ? ' (' . $variant . ')' : '') . '. Available: ' . (float)$snapshot['available_stock'] . ', needed: ' . $qty . '.');
        }
    }

    $total = round($subtotal + $charge, 2);
    $paid = round($advance_used + $paid_cash, 2);
    if ($paid > $total + 0.009) {
        throw new RuntimeException('Payment is more than the invoice total.');
    }
    $due = round($total - $paid, 2);
    if ($customer_id <= 0 && $due > 0.009) {
        throw new RuntimeException('A walk-in customer must pay the full amount on delivery.');
    }
    if ($paid_cash > 0 && $wallet_id <= 0) {
        throw new RuntimeException('Select the wallet that receives the payment.');
    }
    $status = $due <= 0.009 ? 'paid' : ($paid > 0 ? 'partial' : 'due');
    if ($due <= 0.009) {
        $due = 0.0;
    }

    $invoice_no = sw_generate_invoice_no($conn);
    $created_by_user_id = (int)($_SESSION['login_user_id'] ?? $user_id);
    $created_by_name = sw_actor_name();
    $created_by_type = ($_SESSION['user_role'] ?? '') === 'manager' ? 'Manager' : null;
    $stmt = mysqli_prepare($conn, "INSERT INTO invoices (user_id, branch_id, invoice_no, customer_id, receive_wallet_id, customer_name, invoice_date, total_amount, notes, staff_id, restaurant_table_id, paid_amount, due_amount, payment_status, created_by_user_id, created_by_name, created_by_type, accounting_status)
        VALUES (?,?,?,?,?,?,CURDATE(),?,?,0,0,?,?,?,?,?,?,'posted')");
    mysqli_stmt_bind_param($stmt, 'iisiisdsddsiss', $user_id, $branch_id, $invoice_no, $customer_id, $wallet_id, $customer_name, $total, $note, $paid, $due, $status, $created_by_user_id, $created_by_name, $created_by_type);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException('Invoice could not be saved: ' . mysqli_stmt_error($stmt));
    }
    $invoice_id = (int)mysqli_insert_id($conn);

    foreach ($items as $item) {
        $product_id = (int)$item['product_id'];
        $variant = (string)$item['variant_name'];
        $qty = (int)$item['qty'];
        $price = sw_money($item['unit_price']);
        $line = round($qty * $price, 2);
        $item_stmt = mysqli_prepare($conn, 'INSERT INTO invoice_items (invoice_id, product_id, variant_name, quantity, unit_price, total_price) VALUES (?,?,?,?,?,?)');
        mysqli_stmt_bind_param($item_stmt, 'iisidd', $invoice_id, $product_id, $variant, $qty, $price, $line);
        if (!mysqli_stmt_execute($item_stmt)) {
            throw new RuntimeException('Invoice item could not be saved.');
        }
        $invoice_item_id = (int)mysqli_insert_id($conn);
        invoice_item_warranty_save($conn, $user_id, $invoice_item_id, $product_id);
        if (!product_uses_stock($conn, $product_id, $user_id)) {
            continue;
        }
        $stock_stmt = mysqli_prepare($conn, 'UPDATE products SET current_stock = current_stock - ? WHERE id=? AND user_id=?');
        mysqli_stmt_bind_param($stock_stmt, 'iii', $qty, $product_id, $user_id);
        mysqli_stmt_execute($stock_stmt);
        $txn_note = 'Sales Invoice';
        $txn_stmt = mysqli_prepare($conn, "INSERT INTO stock_transactions (user_id, product_id, transaction_type, quantity, note, txn_date, reference_no) VALUES (?,?,'stock_out',?,?,CURDATE(),?)");
        mysqli_stmt_bind_param($txn_stmt, 'iiiss', $user_id, $product_id, $qty, $txn_note, $invoice_no);
        mysqli_stmt_execute($txn_stmt);
        $allocation = fifo_inventory_allocate_sale($conn, $user_id, $invoice_item_id, $product_id, $qty, $variant);
        if (empty($allocation['success'])) {
            throw new RuntimeException($allocation['error'] ?? 'Stock allocation failed.');
        }
    }

    if ($charge > 0) {
        $charge_type_id = sw_service_charge_type_id($conn, $user_id);
        $charge_stmt = mysqli_prepare($conn, 'INSERT INTO invoice_charges (invoice_id, charge_type_id, amount) VALUES (?,?,?)');
        mysqli_stmt_bind_param($charge_stmt, 'iid', $invoice_id, $charge_type_id, $charge);
        if (!mysqli_stmt_execute($charge_stmt)) {
            throw new RuntimeException('Service charge could not be saved.');
        }
    }

    // A registered customer's payments on this invoice (advance + cash now).
    if ($customer_id > 0 && $paid > 0.009) {
        $pay_note = 'Invoice Payment - ' . $invoice_no;
        $pay_stmt = mysqli_prepare($conn, 'INSERT INTO customer_payments (branch_id, user_id, customer_id, invoice_id, amount, payment_date, note) VALUES (?,?,?,?,?,CURDATE(),?)');
        mysqli_stmt_bind_param($pay_stmt, 'iiiids', $branch_id, $user_id, $customer_id, $invoice_id, $paid, $pay_note);
        if (!mysqli_stmt_execute($pay_stmt)) {
            throw new RuntimeException('Customer payment could not be saved.');
        }
    }
    if ($paid_cash > 0.009) {
        sw_wallet_adjust($conn, $user_id, $branch_id, $wallet_id, 'sales_invoice', $invoice_id, $paid_cash, $invoice_no, 'Sales Invoice - ' . $invoice_no);
    }

    return ['invoice_id' => $invoice_id, 'invoice_no' => $invoice_no, 'total' => $total, 'due' => $due];
}

/* ---------- service jobs ---------- */

function sw_service_get($conn, $user_id, $id)
{
    $stmt = mysqli_prepare($conn, 'SELECT j.*, w.wallet_name AS advance_wallet_name, s.name AS staff_name, i.invoice_no
        FROM service_jobs j
        LEFT JOIN wallets w ON w.id=j.advance_wallet_id AND w.user_id=j.user_id
        LEFT JOIN staff s ON s.id=j.staff_id AND s.user_id=j.user_id
        LEFT JOIN invoices i ON i.id=j.invoice_id AND i.user_id=j.user_id
        WHERE j.id=? AND j.user_id=?');
    mysqli_stmt_bind_param($stmt, 'ii', $id, $user_id);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

function sw_service_lock($conn, $user_id, $id)
{
    $row = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM service_jobs WHERE id=' . (int)$id . ' AND user_id=' . (int)$user_id . ' FOR UPDATE'));
    if (!$row) {
        throw new RuntimeException('Service job not found.');
    }
    return $row;
}

/**
 * Receive a product for service. $d keys: customer_id, customer_name, customer_phone,
 * item_name, brand_model, serial_no, problem, accessories, received_at, delivery_date,
 * estimated_charge, advance_amount, advance_wallet_id, staff_id, note, request_key.
 */
function sw_service_receive($conn, $user_id, $branch_id, array $d)
{
    $customer_id = (int)($d['customer_id'] ?? 0);
    $name = trim((string)($d['customer_name'] ?? ''));
    $phone = trim((string)($d['customer_phone'] ?? ''));
    if ($customer_id > 0) {
        $stmt = mysqli_prepare($conn, "SELECT customer_name, phone FROM customers WHERE id=? AND user_id=? AND status='active'");
        mysqli_stmt_bind_param($stmt, 'ii', $customer_id, $user_id);
        mysqli_stmt_execute($stmt);
        $customer = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if (!$customer) {
            throw new RuntimeException('Select a valid customer.');
        }
        $name = (string)$customer['customer_name'];
        $phone = (string)($customer['phone'] ?? '');
    } elseif ($name === '' || $phone === '') {
        throw new RuntimeException('Enter the walk-in customer\'s name and phone.');
    }
    $item = trim((string)($d['item_name'] ?? ''));
    $problem = trim((string)($d['problem'] ?? ''));
    if ($item === '' || $problem === '') {
        throw new RuntimeException('Enter the product and the problem.');
    }
    $received_at = sw_parse_datetime($d['received_at'] ?? '') ?: date('Y-m-d H:i:s');
    $delivery_date = sw_parse_date($d['delivery_date'] ?? '');
    if (!$delivery_date) {
        throw new RuntimeException('Enter the delivery date.');
    }
    if ($delivery_date < substr($received_at, 0, 10)) {
        throw new RuntimeException('Delivery date cannot be before the receive date.');
    }
    $estimated = sw_money($d['estimated_charge'] ?? 0);
    $advance = sw_money($d['advance_amount'] ?? 0);
    $wallet_id = (int)($d['advance_wallet_id'] ?? 0);
    if ($estimated < 0 || $advance < 0) {
        throw new RuntimeException('Amounts cannot be negative.');
    }
    if ($advance > 0 && $wallet_id <= 0) {
        throw new RuntimeException('Select the wallet that receives the advance.');
    }
    $staff_id = (int)($d['staff_id'] ?? 0);
    if ($staff_id > 0 && !mysqli_fetch_row(mysqli_query($conn, "SELECT id FROM staff WHERE id={$staff_id} AND user_id=" . (int)$user_id . " AND status='active'"))) {
        throw new RuntimeException('Select a valid technician.');
    }
    $key = trim((string)($d['request_key'] ?? ''));
    $key = $key !== '' ? substr($key, 0, 64) : null;
    if ($key !== null) {
        $existing = mysqli_fetch_row(mysqli_query($conn, "SELECT id FROM service_jobs WHERE request_key='" . mysqli_real_escape_string($conn, $key) . "'"));
        if ($existing) {
            return (int)$existing[0];
        }
    }

    mysqli_begin_transaction($conn);
    try {
        $job_no = sw_next_number($conn, $user_id, 'service');
        $accessories = trim((string)($d['accessories'] ?? ''));
        $serial = trim((string)($d['serial_no'] ?? ''));
        $brand = trim((string)($d['brand_model'] ?? ''));
        $note = trim((string)($d['note'] ?? ''));
        $by_id = (int)($_SESSION['login_user_id'] ?? $user_id);
        $by_name = sw_actor_name();
        $advance_wallet = $advance > 0 ? $wallet_id : 0;
        $stmt = mysqli_prepare($conn, 'INSERT INTO service_jobs (user_id, branch_id, job_no, customer_id, customer_name, customer_phone, item_name, brand_model, serial_no, problem, accessories, received_at, delivery_date, estimated_charge, advance_amount, advance_wallet_id, staff_id, note, created_by_user_id, created_by_name, request_key)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        mysqli_stmt_bind_param($stmt, 'iisisssssssssddiisiss', $user_id, $branch_id, $job_no, $customer_id, $name, $phone, $item, $brand, $serial, $problem, $accessories, $received_at, $delivery_date, $estimated, $advance, $advance_wallet, $staff_id, $note, $by_id, $by_name, $key);
        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException('Service job could not be saved: ' . mysqli_stmt_error($stmt));
        }
        $id = (int)mysqli_insert_id($conn);
        if ($advance > 0) {
            sw_wallet_adjust($conn, $user_id, $branch_id, $wallet_id, 'service_advance', $id, $advance, $job_no, 'Service Advance - ' . $job_no);
        }
        sw_log($conn, $user_id, 'service', $id, 'received', $advance > 0 ? 'Advance BDT ' . number_format($advance, 2) : '');
        mysqli_commit($conn);
        return $id;
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
}

function sw_service_mark_ready($conn, $user_id, $id, $note)
{
    mysqli_begin_transaction($conn);
    try {
        $job = sw_service_lock($conn, $user_id, $id);
        if ($job['status'] !== 'received') {
            throw new RuntimeException('Only a received job can be marked ready.');
        }
        mysqli_query($conn, "UPDATE service_jobs SET status='ready', ready_at=NOW() WHERE id=" . (int)$id);
        sw_log($conn, $user_id, 'service', $id, 'ready', trim((string)$note));
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
}

function sw_service_update_delivery_date($conn, $user_id, $id, $date, $note)
{
    $date = sw_parse_date($date);
    if (!$date) {
        throw new RuntimeException('Enter a valid delivery date.');
    }
    mysqli_begin_transaction($conn);
    try {
        $job = sw_service_lock($conn, $user_id, $id);
        if (!in_array($job['status'], ['received', 'ready'], true)) {
            throw new RuntimeException('This job is closed.');
        }
        if ($date < substr($job['received_at'], 0, 10)) {
            throw new RuntimeException('Delivery date cannot be before the receive date.');
        }
        $stmt = mysqli_prepare($conn, 'UPDATE service_jobs SET delivery_date=? WHERE id=?');
        mysqli_stmt_bind_param($stmt, 'si', $date, $id);
        mysqli_stmt_execute($stmt);
        sw_log($conn, $user_id, 'service', $id, $job['status'], 'Delivery date changed to ' . date('d M Y', strtotime($date)) . (trim((string)$note) !== '' ? ' · ' . trim((string)$note) : ''));
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
}

/**
 * Deliver: $parts = [[product_id, variant_name, qty, unit_price]], service charge,
 * cash paid now and wallet. Creates the sales invoice. Returns invoice id.
 */
function sw_service_deliver($conn, $user_id, $id, array $parts, $service_charge, $paid_now, $wallet_id, $note)
{
    $service_charge = sw_money($service_charge);
    $paid_now = sw_money($paid_now);
    if ($service_charge < 0 || $paid_now < 0) {
        throw new RuntimeException('Amounts cannot be negative.');
    }
    mysqli_begin_transaction($conn);
    try {
        $job = sw_service_lock($conn, $user_id, $id);
        if (!in_array($job['status'], ['received', 'ready'], true)) {
            throw new RuntimeException('This job is already closed.');
        }
        if (!sw_branch_allows($conn, $job['branch_id'])) {
            throw new RuntimeException('This job belongs to another branch.');
        }
        $branch_id = (int)$job['branch_id'];
        $parts_total = 0.0;
        foreach ($parts as $part) {
            $parts_total += round((int)$part['qty'] * sw_money($part['unit_price']), 2);
        }
        $total = round($parts_total + $service_charge, 2);
        if ($total <= 0) {
            throw new RuntimeException('Enter the service charge or add the parts used.');
        }
        $advance = sw_money($job['advance_amount']);
        $advance_used = min($advance, $total);
        $refund = round($advance - $advance_used, 2);
        if ($refund > 0 && $paid_now > 0) {
            throw new RuntimeException('The advance already covers this bill; no payment is needed.');
        }
        $job_note = 'Service Job ' . $job['job_no'] . ' · ' . $job['item_name'] . (trim((string)$note) !== '' ? ' · ' . trim((string)$note) : '');
        $invoice = sw_post_invoice($conn, $user_id, $branch_id, [
            'customer_id' => (int)$job['customer_id'],
            'customer_name' => (int)$job['customer_id'] > 0 ? $job['customer_name'] : trim($job['customer_name'] . ' (' . $job['customer_phone'] . ')'),
            'items' => $parts,
            'charge_amount' => $service_charge,
            'paid_cash' => $paid_now,
            'advance_used' => $advance_used,
            'wallet_id' => $wallet_id,
            'note' => $job_note,
        ]);
        if ($refund > 0) {
            // The unused advance goes back to the customer from the advance wallet.
            sw_wallet_adjust($conn, $user_id, $branch_id, (int)$job['advance_wallet_id'], 'service_refund', $id, $refund, $job['job_no'] . '-RF', 'Service Advance Refund - ' . $job['job_no']);
        }
        $stmt = mysqli_prepare($conn, "UPDATE service_jobs SET status='delivered', delivered_at=NOW(), service_charge=?, parts_total=?, invoice_id=?, refund_amount=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, 'ddidi', $service_charge, $parts_total, $invoice['invoice_id'], $refund, $id);
        mysqli_stmt_execute($stmt);
        $log = 'Invoice ' . $invoice['invoice_no'] . ' · BDT ' . number_format($invoice['total'], 2)
            . ($invoice['due'] > 0 ? ' · Due ' . number_format($invoice['due'], 2) : '')
            . ($refund > 0 ? ' · Advance refunded ' . number_format($refund, 2) : '');
        sw_log($conn, $user_id, 'service', $id, 'delivered', $log);
        mysqli_commit($conn);
        return $invoice['invoice_id'];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
}

function sw_service_cancel($conn, $user_id, $id, $refund_advance, $reason)
{
    $reason = trim((string)$reason);
    if ($reason === '') {
        throw new RuntimeException('Enter the reason for cancelling.');
    }
    mysqli_begin_transaction($conn);
    try {
        $job = sw_service_lock($conn, $user_id, $id);
        if (!in_array($job['status'], ['received', 'ready'], true)) {
            throw new RuntimeException('This job is already closed.');
        }
        $refund = $refund_advance ? sw_money($job['advance_amount']) : 0.0;
        if ($refund > 0) {
            sw_wallet_adjust($conn, $user_id, (int)$job['branch_id'], (int)$job['advance_wallet_id'], 'service_refund', $id, $refund, $job['job_no'] . '-RF', 'Service Advance Refund - ' . $job['job_no']);
        }
        $stmt = mysqli_prepare($conn, "UPDATE service_jobs SET status='cancelled', refund_amount=?, delivered_at=NOW() WHERE id=?");
        mysqli_stmt_bind_param($stmt, 'di', $refund, $id);
        mysqli_stmt_execute($stmt);
        sw_log($conn, $user_id, 'service', $id, 'cancelled', $reason . ($refund > 0 ? ' · Advance refunded ' . number_format($refund, 2) : ((float)$job['advance_amount'] > 0 ? ' · Advance kept' : '')));
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
}

/* ---------- warranty ---------- */

// Invoices matching an invoice number or a customer phone, with their items and warranty status.
function sw_warranty_search($conn, $user_id, $query)
{
    $query = trim((string)$query);
    if ($query === '') {
        return [];
    }
    product_warranty_schema($conn);
    invoice_item_warranty_schema($conn);
    $like = '%' . $query . '%';
    $digits = preg_replace('/\D+/', '', $query);
    $phone_like = strlen($digits) >= 6 ? '%' . substr($digits, -10) . '%' : '__no_phone__';
    $scope = branch_scope_sql($conn, 'i');
    $stmt = mysqli_prepare($conn, "SELECT i.id, i.invoice_no, i.invoice_date, i.customer_id, i.customer_name, i.branch_id, c.phone
        FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id AND c.user_id=i.user_id
        WHERE i.user_id=? {$scope} AND COALESCE(i.accounting_status,'posted')='posted'
        AND (i.invoice_no LIKE ? OR REPLACE(REPLACE(COALESCE(c.phone,''),'-',''),' ','') LIKE ? OR EXISTS (SELECT 1 FROM invoice_items sn WHERE sn.invoice_id=i.id AND sn.serial_no<>'' AND sn.serial_no LIKE ?))
        ORDER BY i.invoice_date DESC, i.id DESC LIMIT 20");
    mysqli_stmt_bind_param($stmt, 'isss', $user_id, $like, $phone_like, $like);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $invoices = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $row['items'] = [];
        $invoices[(int)$row['id']] = $row;
    }
    if (!$invoices) {
        return [];
    }
    $ids = implode(',', array_keys($invoices));
    $items = mysqli_query($conn, "SELECT ii.id, ii.invoice_id, ii.product_id, ii.variant_name, ii.quantity, ii.unit_price, ii.serial_no, p.product_name, COALESCE(ii.warranty_months, p.warranty_months, 0) AS warranty_months,
            (SELECT COUNT(*) FROM warranty_claims w WHERE w.invoice_item_id=ii.id AND w.status<>'cancelled') AS claims,
            (SELECT COUNT(*) FROM warranty_claims w WHERE w.invoice_item_id=ii.id AND w.status NOT IN ('delivered','cancelled')) AS open_claims
        FROM invoice_items ii LEFT JOIN products p ON p.id=ii.product_id
        WHERE ii.invoice_id IN ({$ids}) AND ii.quantity > 0 ORDER BY ii.id");
    while ($item = mysqli_fetch_assoc($items)) {
        $sale_date = $invoices[(int)$item['invoice_id']]['invoice_date'];
        $item += sw_warranty_period($sale_date, (int)$item['warranty_months']);
        $invoices[(int)$item['invoice_id']]['items'][] = $item;
    }
    return array_values(array_filter($invoices, static fn($inv) => $inv['items']));
}

function sw_warranty_period($sale_date, $months)
{
    if ($months <= 0 || !$sale_date) {
        return ['warranty_until' => null, 'in_warranty' => 0, 'days_left' => null];
    }
    $until = (new DateTimeImmutable($sale_date))->modify('+' . (int)$months . ' months')->format('Y-m-d');
    $days = (int)floor((strtotime($until) - strtotime(date('Y-m-d'))) / 86400);
    return ['warranty_until' => $until, 'in_warranty' => $days >= 0 ? 1 : 0, 'days_left' => $days];
}

function sw_warranty_receive($conn, $user_id, array $d)
{
    $item_id = (int)($d['invoice_item_id'] ?? 0);
    $scope = branch_scope_sql($conn, 'i');
    $item = mysqli_fetch_assoc(mysqli_query($conn, "SELECT ii.id, ii.invoice_id, ii.product_id, ii.variant_name, ii.quantity, ii.serial_no AS sold_serial, i.invoice_no, i.invoice_date, i.customer_id, i.customer_name, i.branch_id,
            p.product_name, COALESCE(ii.warranty_months, p.warranty_months, 0) AS warranty_months, c.phone
        FROM invoice_items ii INNER JOIN invoices i ON i.id=ii.invoice_id
        LEFT JOIN products p ON p.id=ii.product_id
        LEFT JOIN customers c ON c.id=i.customer_id AND c.user_id=i.user_id
        WHERE ii.id={$item_id} AND i.user_id=" . (int)$user_id . " {$scope}"));
    if (!$item) {
        throw new RuntimeException('Select the sold product from the invoice.');
    }
    $problem = trim((string)($d['problem'] ?? ''));
    if ($problem === '') {
        throw new RuntimeException('Enter the problem.');
    }
    $received_at = sw_parse_datetime($d['received_at'] ?? '') ?: date('Y-m-d H:i:s');
    $expected = sw_parse_date($d['expected_date'] ?? '');
    if ($expected && $expected < substr($received_at, 0, 10)) {
        throw new RuntimeException('Expected delivery date cannot be before the receive date.');
    }
    $period = sw_warranty_period($item['invoice_date'], (int)$item['warranty_months']);
    $in_warranty = (int)$period['in_warranty'];
    if (!$in_warranty && empty($d['allow_expired'])) {
        throw new RuntimeException((int)$item['warranty_months'] > 0 ? 'The warranty of this product has expired. Tick "Receive anyway" to continue.' : 'This product has no warranty period set. Tick "Receive anyway" to continue.');
    }
    $key = trim((string)($d['request_key'] ?? ''));
    $key = $key !== '' ? substr($key, 0, 64) : null;
    if ($key !== null) {
        $existing = mysqli_fetch_row(mysqli_query($conn, "SELECT id FROM warranty_claims WHERE request_key='" . mysqli_real_escape_string($conn, $key) . "'"));
        if ($existing) {
            return (int)$existing[0];
        }
    }

    mysqli_begin_transaction($conn);
    try {
        // One open claim per unit sold.
        $open = (int)mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM warranty_claims WHERE invoice_item_id={$item_id} AND status NOT IN ('delivered','cancelled') FOR UPDATE"))[0];
        if ($open >= (int)$item['quantity']) {
            throw new RuntimeException('This product already has an open warranty claim.');
        }
        $claim_no = sw_next_number($conn, $user_id, 'warranty');
        $branch_id = (int)$item['branch_id'];
        $serial = trim((string)($d['serial_no'] ?? '')) ?: trim((string)($item['sold_serial'] ?? ''));
        $accessories = trim((string)($d['accessories'] ?? ''));
        $note = trim((string)($d['note'] ?? ''));
        $phone = (string)($item['phone'] ?? '');
        if ((int)$item['customer_id'] <= 0) {
            $phone = trim((string)($d['customer_phone'] ?? ''));
        }
        $product_name = (string)($item['product_name'] ?? 'Product');
        $months = (int)$item['warranty_months'];
        $until = $period['warranty_until'];
        $by_id = (int)($_SESSION['login_user_id'] ?? $user_id);
        $by_name = sw_actor_name();
        $invoice_id = (int)$item['invoice_id'];
        $product_id = (int)$item['product_id'];
        $customer_id = (int)$item['customer_id'];
        $stmt = mysqli_prepare($conn, 'INSERT INTO warranty_claims (user_id, branch_id, claim_no, invoice_id, invoice_no, invoice_item_id, product_id, variant_name, product_name, customer_id, customer_name, customer_phone, sale_date, warranty_months, warranty_until, in_warranty, serial_no, problem, accessories, received_at, expected_date, note, created_by_user_id, created_by_name, request_key)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        mysqli_stmt_bind_param($stmt, 'iisisiississsisissssssiss', $user_id, $branch_id, $claim_no, $invoice_id, $item['invoice_no'], $item_id, $product_id, $item['variant_name'], $product_name, $customer_id, $item['customer_name'], $phone, $item['invoice_date'], $months, $until, $in_warranty, $serial, $problem, $accessories, $received_at, $expected, $note, $by_id, $by_name, $key);
        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException('Warranty claim could not be saved: ' . mysqli_stmt_error($stmt));
        }
        $id = (int)mysqli_insert_id($conn);
        sw_log($conn, $user_id, 'warranty', $id, 'received', $in_warranty ? 'In warranty' : 'Received outside warranty');
        mysqli_commit($conn);
        return $id;
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
}

function sw_warranty_get($conn, $user_id, $id)
{
    $stmt = mysqli_prepare($conn, 'SELECT w.*, s.supplier_name, rp.product_name AS replace_product_name, ri.invoice_no AS replace_invoice_no
        FROM warranty_claims w
        LEFT JOIN suppliers s ON s.id=w.supplier_id AND s.user_id=w.user_id
        LEFT JOIN products rp ON rp.id=w.replace_product_id
        LEFT JOIN invoices ri ON ri.id=w.replace_invoice_id AND ri.user_id=w.user_id
        WHERE w.id=? AND w.user_id=?');
    mysqli_stmt_bind_param($stmt, 'ii', $id, $user_id);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

function sw_warranty_lock($conn, $user_id, $id)
{
    $row = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM warranty_claims WHERE id=' . (int)$id . ' AND user_id=' . (int)$user_id . ' FOR UPDATE'));
    if (!$row) {
        throw new RuntimeException('Warranty claim not found.');
    }
    return $row;
}

/**
 * Status actions: send (supplier_id, date), back (date, result), ready, deliver
 * (delivery_type, replace product/variant), cancel (reason).
 */
function sw_warranty_action($conn, $user_id, $id, $action, array $d)
{
    $note = trim((string)($d['note'] ?? ''));
    mysqli_begin_transaction($conn);
    try {
        $claim = sw_warranty_lock($conn, $user_id, $id);
        if (!sw_branch_allows($conn, $claim['branch_id'])) {
            throw new RuntimeException('This claim belongs to another branch.');
        }
        $status = $claim['status'];
        $log = $note;
        if ($action === 'send') {
            if (!in_array($status, ['received'], true)) {
                throw new RuntimeException('Only a received claim can be sent to the supplier.');
            }
            $supplier_id = (int)($d['supplier_id'] ?? 0);
            $supplier = mysqli_fetch_assoc(mysqli_query($conn, "SELECT supplier_name FROM suppliers WHERE id={$supplier_id} AND user_id=" . (int)$user_id));
            if (!$supplier) {
                throw new RuntimeException('Select the supplier.');
            }
            $date = sw_parse_date($d['date'] ?? '') ?: date('Y-m-d');
            $stmt = mysqli_prepare($conn, "UPDATE warranty_claims SET status='sent_supplier', supplier_id=?, sent_date=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, 'isi', $supplier_id, $date, $id);
            mysqli_stmt_execute($stmt);
            $new = 'sent_supplier';
            $log = 'Sent to ' . $supplier['supplier_name'] . ' on ' . date('d M Y', strtotime($date)) . ($note !== '' ? ' · ' . $note : '');
        } elseif ($action === 'back') {
            if ($status !== 'sent_supplier') {
                throw new RuntimeException('This claim is not with the supplier.');
            }
            $result = (string)($d['supplier_result'] ?? '');
            if (!isset(sw_supplier_results()[$result])) {
                throw new RuntimeException('Select what the supplier did.');
            }
            $date = sw_parse_date($d['date'] ?? '') ?: date('Y-m-d');
            $stmt = mysqli_prepare($conn, "UPDATE warranty_claims SET status='back_supplier', back_date=?, supplier_result=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, 'ssi', $date, $result, $id);
            mysqli_stmt_execute($stmt);
            $new = 'back_supplier';
            $log = 'Back from supplier: ' . sw_supplier_results()[$result] . ($note !== '' ? ' · ' . $note : '');
        } elseif ($action === 'ready') {
            if (!in_array($status, ['received', 'back_supplier'], true)) {
                throw new RuntimeException('This claim cannot be marked ready now.');
            }
            mysqli_query($conn, "UPDATE warranty_claims SET status='ready' WHERE id=" . (int)$id);
            $new = 'ready';
        } elseif ($action === 'deliver') {
            if (!in_array($status, ['received', 'back_supplier', 'ready'], true)) {
                throw new RuntimeException($status === 'sent_supplier' ? 'Receive the product back from the supplier first.' : 'This claim is already closed.');
            }
            $type = (string)($d['delivery_type'] ?? '');
            if (!isset(sw_warranty_delivery_types()[$type])) {
                throw new RuntimeException('Select how the product is delivered.');
            }
            $replace_id = 0;
            $replace_product = 0;
            $replace_variant = '';
            if ($type === 'replaced_stock') {
                $replace_product = (int)($d['replace_product_id'] ?? 0) ?: (int)$claim['product_id'];
                $replace_variant = trim((string)($d['replace_variant'] ?? ''));
                $invoice = sw_post_invoice($conn, $user_id, (int)$claim['branch_id'], [
                    'customer_id' => (int)$claim['customer_id'],
                    'customer_name' => $claim['customer_name'],
                    'items' => [['product_id' => $replace_product, 'variant_name' => $replace_variant, 'qty' => 1, 'unit_price' => 0]],
                    'charge_amount' => 0,
                    'paid_cash' => 0,
                    'advance_used' => 0,
                    'wallet_id' => 0,
                    'note' => 'Warranty replacement ' . $claim['claim_no'] . ' for ' . $claim['invoice_no'],
                ]);
                $replace_id = (int)$invoice['invoice_id'];
                $log = 'Replaced from stock · ' . $invoice['invoice_no'] . ($note !== '' ? ' · ' . $note : '');
            } else {
                $log = sw_warranty_delivery_types()[$type] . ($note !== '' ? ' · ' . $note : '');
            }
            $stmt = mysqli_prepare($conn, "UPDATE warranty_claims SET status='delivered', delivered_at=NOW(), delivery_type=?, replace_product_id=?, replace_variant=?, replace_invoice_id=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, 'sisii', $type, $replace_product, $replace_variant, $replace_id, $id);
            mysqli_stmt_execute($stmt);
            $new = 'delivered';
        } elseif ($action === 'cancel') {
            if (in_array($status, ['delivered', 'cancelled'], true)) {
                throw new RuntimeException('This claim is already closed.');
            }
            if ($note === '') {
                throw new RuntimeException('Enter the reason for cancelling.');
            }
            mysqli_query($conn, "UPDATE warranty_claims SET status='cancelled', delivered_at=NOW() WHERE id=" . (int)$id);
            $new = 'cancelled';
        } elseif ($action === 'expected') {
            $date = sw_parse_date($d['date'] ?? '');
            if (!$date || in_array($status, ['delivered', 'cancelled'], true)) {
                throw new RuntimeException('Enter a valid date for an open claim.');
            }
            $stmt = mysqli_prepare($conn, 'UPDATE warranty_claims SET expected_date=? WHERE id=?');
            mysqli_stmt_bind_param($stmt, 'si', $date, $id);
            mysqli_stmt_execute($stmt);
            $new = $status;
            $log = 'Expected date changed to ' . date('d M Y', strtotime($date)) . ($note !== '' ? ' · ' . $note : '');
        } else {
            throw new RuntimeException('Unknown action.');
        }
        sw_log($conn, $user_id, 'warranty', $id, $new, $log);
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
}

/* ---------- input parsing ---------- */

function sw_parse_date($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($date && $date->format($format) === $value) {
            return $date->format('Y-m-d');
        }
    }
    return null;
}

function sw_parse_datetime($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    foreach (['Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        if ($date) {
            return $date->format('Y-m-d H:i:s');
        }
    }
    return null;
}

// Products for the parts / replacement pickers, with variants.
function sw_product_options($conn, $user_id)
{
    $rows = [];
    $result = mysqli_query($conn, "SELECT p.id, p.product_name, p.sku, p.sale_price FROM products p WHERE p.user_id=" . (int)$user_id . " AND p.status='active'" . (function_exists('restaurant_catalog_scope_sql') ? restaurant_catalog_scope_sql($conn, 'p', true) : '') . ' ORDER BY p.product_name');
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $rows[] = $row;
    }
    $variants = function_exists('product_variant_names_by_product') ? product_variant_names_by_product($conn, $user_id) : [];
    return [$rows, $variants];
}

// Sidebar menus (called right after the Sales menu for admins and managers).
function sw_sidebar_menus()
{
    if (!sw_module_active() || is_agent_user()) {
        return;
    }
    sidebar_tree('Service Center', 'fas fa-tools', [
        ['href' => app_path('sales/service_receive.php'), 'label' => 'Receive for Service'],
        ['href' => app_path('sales/service_jobs.php'), 'label' => 'Service Jobs'],
    ]);
    sidebar_tree('Warranty', 'fas fa-shield-alt', [
        ['href' => app_path('sales/warranty_receive.php'), 'label' => 'Warranty Receive'],
        ['href' => app_path('sales/warranty_claims.php'), 'label' => 'Warranty Claims'],
    ]);
}

/* ---------- warranty and serial on each sold line (Create / Edit Invoice) ---------- */

// invoice_items.warranty_months: NULL on lines saved before this existed (the product's
// current warranty is used for them); otherwise the warranty given on that sale.
function invoice_item_warranty_schema($conn)
{
    if (!schema_once('invoice_item_warranty_v1')) {
        return;
    }
    $col = mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'warranty_months'");
    if ($col && mysqli_num_rows($col) === 0) {
        mysqli_query($conn, 'ALTER TABLE invoice_items ADD COLUMN warranty_months INT NULL DEFAULT NULL');
    }
    $col = mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'serial_no'");
    if ($col && mysqli_num_rows($col) === 0) {
        mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN serial_no VARCHAR(255) NOT NULL DEFAULT ''");
    }
}

// Run before the invoice transaction starts (ALTER TABLE would end a transaction).
function invoice_warranty_prepare($conn)
{
    if (!product_warranty_enabled()) {
        return false;
    }
    product_warranty_schema($conn);
    invoice_item_warranty_schema($conn);
    return true;
}

// Warranty box under the product of one invoice line; shown by script only when the
// product has a warranty (or the line already has one).
function invoice_warranty_fields_html($months = null, $serial = '')
{
    if (!product_warranty_enabled()) {
        return '';
    }
    $months_value = $months === null || $months === '' ? '' : (string)(int)$months;
    return '<div class="iw-box mt-1" style="display:none">'
        . '<div class="input-group input-group-sm flex-nowrap">'
        . '<div class="input-group-prepend"><span class="input-group-text" title="Warranty"><i class="fas fa-shield-alt text-success"></i></span></div>'
        . '<input type="number" min="0" max="600" step="1" name="item_warranty[]" class="form-control iw-months" value="' . htmlspecialchars($months_value) . '" style="max-width:72px" title="Warranty (months)">'
        . '<div class="input-group-append"><span class="input-group-text">mo</span></div>'
        . '<input type="text" name="item_serial[]" class="form-control iw-serial" value="' . htmlspecialchars((string)$serial) . '" maxlength="255" placeholder="Serial / IMEI (optional)" title="Serial / IMEI">'
        . '</div></div>';
}

function invoice_warranty_script($conn, $user_id)
{
    if (!product_warranty_enabled()) {
        return '';
    }
    product_warranty_schema($conn);
    $map = [];
    $result = mysqli_query($conn, 'SELECT id, warranty_months FROM products WHERE user_id=' . (int)$user_id . ' AND warranty_months > 0');
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $map[(int)$row['id']] = (int)$row['warranty_months'];
    }
    return '<script>
document.addEventListener("DOMContentLoaded", function () {
    var warranty = ' . json_encode((object)$map) . ';
    var show = function (row, on) { var box = row.querySelector(".iw-box"); if (box) box.style.display = on ? "" : "none"; };
    // Initial state: a product with warranty, or a saved line that has one.
    document.querySelectorAll(".iw-box").forEach(function (box) {
        var row = box.closest("tr"); var select = row && row.querySelector("select.product");
        var months = box.querySelector(".iw-months");
        var pid = select ? select.value : "";
        if (pid && warranty[pid] && months.value === "") months.value = warranty[pid];
        show(row, (pid && warranty[pid]) || Number(months.value) > 0 || box.querySelector(".iw-serial").value !== "");
    });
    var changed = function (select) {
        var row = select.closest("tr"); if (!row || !row.querySelector(".iw-box")) return;
        var months = warranty[select.value] || 0;
        row.querySelector(".iw-months").value = months > 0 ? months : "";
        if (!months) row.querySelector(".iw-serial").value = "";
        show(row, months > 0);
    };
    document.addEventListener("change", function (e) { if (e.target.matches && e.target.matches("select.product")) changed(e.target); });
    if (window.jQuery) jQuery(document).on("change", "select.product", function () { changed(this); });
    // A new line starts empty.
    var add = document.getElementById("addRow");
    if (add) add.addEventListener("click", function () { setTimeout(function () {
        var rows = document.querySelectorAll("#productTable tbody tr"); var row = rows[rows.length - 1];
        if (row && row.querySelector(".iw-box")) { row.querySelector(".iw-months").value = ""; row.querySelector(".iw-serial").value = ""; show(row, false); }
    }, 0); });
});
</script>';
}

// Save the warranty and serial of one sold line. An empty months value means the
// product's warranty; lines of products without warranty get 0.
function invoice_item_warranty_save($conn, $user_id, $invoice_item_id, $product_id, $months_raw = null, $serial = '')
{
    if (!product_warranty_enabled()) {
        return;
    }
    $default = product_warranty_months($conn, $user_id, $product_id);
    $months_raw = $months_raw === null ? '' : trim((string)$months_raw);
    $months = $months_raw === '' ? $default : max(0, min(600, (int)$months_raw));
    $serial = substr(trim((string)$serial), 0, 255);
    $stmt = mysqli_prepare($conn, 'UPDATE invoice_items SET warranty_months=?, serial_no=? WHERE id=?');
    mysqli_stmt_bind_param($stmt, 'isi', $months, $serial, $invoice_item_id);
    mysqli_stmt_execute($stmt);
}

// "Warranty: 12 months (until 13 Nov 2027) · SN: ABC123" for invoice view and print.
function invoice_item_warranty_text(array $item, $invoice_date)
{
    if (!product_warranty_enabled()) {
        return '';
    }
    $parts = [];
    $months = isset($item['warranty_months']) && $item['warranty_months'] !== null ? (int)$item['warranty_months'] : 0;
    if ($months > 0) {
        $period = sw_warranty_period($invoice_date, $months);
        $parts[] = 'Warranty: ' . $months . ' month' . ($months === 1 ? '' : 's') . ($period['warranty_until'] ? ' (until ' . date('d M Y', strtotime($period['warranty_until'])) . ')' : '');
    }
    if (trim((string)($item['serial_no'] ?? '')) !== '') {
        $parts[] = 'SN: ' . trim((string)$item['serial_no']);
    }
    return implode(' · ', $parts);
}
