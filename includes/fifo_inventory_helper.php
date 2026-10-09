<?php

require_once __DIR__ . '/stock_module_helper.php';
require_once __DIR__ . '/product_category_helper.php';

function fifo_inventory_is_enabled()
{
    return ($_SESSION['company_type'] ?? '') === 'Restaurant & Cafe'
        || !isset($_SESSION['fifo_enabled']) || (bool)$_SESSION['fifo_enabled'];
}

function fifo_inventory_item_branch($conn, $user_id, $item_id)
{
    $stmt = mysqli_prepare($conn, 'SELECT i.branch_id FROM invoice_items ii INNER JOIN invoices i ON i.id=ii.invoice_id WHERE ii.id=? AND i.user_id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $item_id, $user_id);
    mysqli_stmt_execute($stmt);
    $id = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['branch_id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('Cannot allocate stock without an invoice branch.');
    return $id;
}

function fifo_inventory_migrate_company($conn, $user_id)
{
    static $done = [];
    $user_id = (int)$user_id;
    if (isset($done[$user_id])) return;
    $done[$user_id] = true;
    $head_id = stock_purchase_location_id($conn, $user_id);
    mysqli_query($conn, "INSERT IGNORE INTO stock_inventory_migrations(user_id) VALUES ({$user_id})");
    $existing = mysqli_fetch_assoc(mysqli_query($conn, "SELECT completed_at FROM stock_inventory_migrations WHERE user_id={$user_id}"));
    if (!empty($existing['completed_at'])) return;
    mysqli_begin_transaction($conn);
    try {
        $marker = mysqli_fetch_assoc(mysqli_query($conn, "SELECT completed_at FROM stock_inventory_migrations WHERE user_id={$user_id} FOR UPDATE"));
        if (empty($marker['completed_at'])) {
            mysqli_query($conn, "UPDATE stock_batches SET branch_id={$head_id} WHERE user_id={$user_id} AND branch_id=0");
            $products = mysqli_query($conn, "SELECT p.id,p.current_stock,p.purchase_price FROM products p INNER JOIN product_categories c ON c.id=p.category_id WHERE p.user_id={$user_id} AND c.category_type='stock_product' ORDER BY p.id FOR UPDATE");
            while ($product = mysqli_fetch_assoc($products)) {
                $id = (int)$product['id'];
                $recorded = max(0, (float)$product['current_stock']);
                $batch_qty = (float)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(remaining_quantity),0) AS qty FROM stock_batches WHERE user_id={$user_id} AND product_id={$id}"))['qty'];
                if (abs($recorded - $batch_qty) < 0.0001) continue;
                $stmt = mysqli_prepare($conn, 'INSERT INTO stock_migration_adjustments(user_id,product_id,recorded_quantity,batch_quantity) VALUES (?,?,?,?)');
                mysqli_stmt_bind_param($stmt, 'iidd', $user_id, $id, $recorded, $batch_qty);
                mysqli_stmt_execute($stmt);
                if ($recorded > $batch_qty) {
                    if (!fifo_inventory_create_batch($conn, $user_id, $id, $recorded-$batch_qty, max(0,(float)$product['purchase_price']), 'migration_opening', $id, 'FIFO activation opening', date('Y-m-d'), $head_id)) throw new RuntimeException('Opening stock migration failed.');
                } else {
                    // Account for sales made while FIFO was disabled without
                    // rewriting historical invoice costs or purchase totals.
                    $needed = $batch_qty - $recorded;
                    $batches = mysqli_query($conn, "SELECT id,remaining_quantity FROM stock_batches WHERE user_id={$user_id} AND product_id={$id} AND remaining_quantity>0 ORDER BY batch_date,id FOR UPDATE");
                    while ($batch = mysqli_fetch_assoc($batches)) {
                        if ($needed < 0.0001) break;
                        $take = min($needed, (float)$batch['remaining_quantity']);
                        $stmt = mysqli_prepare($conn, 'UPDATE stock_batches SET remaining_quantity=remaining_quantity-? WHERE id=?');
                        mysqli_stmt_bind_param($stmt, 'di', $take, $batch['id']);
                        mysqli_stmt_execute($stmt);
                        $needed -= $take;
                    }
                }
            }
            mysqli_query($conn, "UPDATE stock_inventory_migrations SET completed_at=NOW() WHERE user_id={$user_id}");
        }
        mysqli_commit($conn);
    } catch (Throwable $e) { mysqli_rollback($conn); throw $e; }
}

function fifo_inventory_distribute($conn, $user_id, $to_branch, $product_id, $quantity, $request_key, $note = '', $from_branch = null, $variant_name = '', $transfer_group = null, $manage_transaction = true)
{
    $user_id = (int)$user_id;
    $to_branch = (int)$to_branch;
    $product_id = (int)$product_id;
    $quantity = (float)$quantity;
    $variant_name = trim((string)$variant_name);
    if ($user_id !== (int)($_SESSION['user_id'] ?? 0) || !stock_can_manage_warehouse($conn)
        || (is_manager_user() && !manager_has_permission('warehouse'))) throw new RuntimeException('Warehouse distribution permission is required.');
    if (!company_multi_branch_enabled($conn, $user_id)) throw new RuntimeException('Multi Branch must be active to distribute stock.');
    if (!is_finite($quantity) || $quantity <= 0 || floor($quantity) !== $quantity) throw new RuntimeException('Enter a positive whole quantity.');
    if (!preg_match('/^[a-f0-9]{32,64}$/', $request_key)) throw new RuntimeException('Invalid distribution request.');
    $head_id = stock_head_office_id($conn, $user_id);
    $from_branch = $from_branch === null ? $head_id : (int)$from_branch;
    $source = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM branches WHERE id={$from_branch} AND user_id={$user_id} AND status='active'"));
    if (!$source) throw new RuntimeException('Select an active source location of this company.');
    $target = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM branches WHERE id={$to_branch} AND user_id={$user_id} AND status='active'"));
    if (!$target) throw new RuntimeException('Select an active destination location of this company.');
    if ($from_branch === $to_branch) throw new RuntimeException('Source and destination must be different.');
    if ($manage_transaction) mysqli_begin_transaction($conn);
    try {
        $product = mysqli_fetch_assoc(mysqli_query($conn, "SELECT p.id FROM products p INNER JOIN product_categories c ON c.id=p.category_id WHERE p.id={$product_id} AND p.user_id={$user_id} AND p.status='active' AND c.category_type='stock_product' FOR UPDATE"));
        if (!$product) throw new RuntimeException('Stock product not found.');
        if(!product_variant_is_valid($conn, $product_id, $user_id, $variant_name)) throw new RuntimeException('Select a valid product variant.');
        $stmt = mysqli_prepare($conn, 'SELECT id FROM stock_distributions WHERE user_id=? AND request_key=?');
        mysqli_stmt_bind_param($stmt, 'is', $user_id, $request_key);
        mysqli_stmt_execute($stmt);
        $duplicate = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if ($duplicate) { if ($manage_transaction) mysqli_commit($conn); return (int)$duplicate['id']; }

        $variant_sql = mysqli_real_escape_string($conn, $variant_name);
        $reserved = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(ii.quantity),0) AS qty FROM invoice_items ii INNER JOIN invoices i ON i.id=ii.invoice_id WHERE i.user_id={$user_id} AND i.branch_id={$from_branch} AND i.accounting_status='pending' AND ii.product_id={$product_id} AND COALESCE(ii.variant_name,'')='{$variant_sql}' AND ii.quantity>0"));
        $available = fifo_inventory_get_available_stock($conn, $user_id, $product_id, $from_branch, $variant_name) - (float)$reserved['qty'];
        if ($quantity > $available + 0.0001) throw new RuntimeException('Insufficient unreserved stock in the selected source location.');
        $transfer_group = $transfer_group ?? $request_key;
        $group_sql = mysqli_real_escape_string($conn, (string)$transfer_group);
        $group_reference = mysqli_fetch_assoc(mysqli_query($conn, "SELECT reference_no FROM stock_distributions WHERE user_id={$user_id} AND transfer_group='{$group_sql}' ORDER BY id LIMIT 1"));
        if($group_reference){
            $reference = (string)$group_reference['reference_no'];
        }else{
            $reference_prefix = 'D-' . date('dmy');
            $reference_number = 1;
            $reference_result = mysqli_query($conn, "SELECT reference_no FROM stock_distributions WHERE user_id={$user_id} AND reference_no LIKE '" . mysqli_real_escape_string($conn, $reference_prefix) . "%'");
            while ($existing_reference = mysqli_fetch_assoc($reference_result)) {
                if (preg_match('/^' . preg_quote($reference_prefix, '/') . '(\d+)$/', (string)$existing_reference['reference_no'], $matches)) {
                    $reference_number = max($reference_number, (int)$matches[1] + 1);
                }
            }
            $reference = $reference_prefix . $reference_number;
        }
        $actor = (int)($_SESSION['login_user_id'] ?? $user_id);
        $note = mb_substr(trim($note), 0, 500);
        $stmt = mysqli_prepare($conn, "INSERT INTO stock_distributions(user_id,from_branch_id,to_branch_id,product_id,variant_name,quantity,reference_no,request_key,created_by,note,transfer_group,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending')");
        mysqli_stmt_bind_param($stmt, 'iiiisdssiss', $user_id, $from_branch, $to_branch, $product_id, $variant_name, $quantity, $reference, $request_key, $actor, $note, $transfer_group);
        mysqli_stmt_execute($stmt);
        $id = mysqli_insert_id($conn);
        $batches = mysqli_query($conn, "SELECT * FROM stock_batches WHERE user_id={$user_id} AND product_id={$product_id} AND branch_id={$from_branch} AND variant_name='{$variant_sql}' AND remaining_quantity>0 ORDER BY COALESCE(batch_date,DATE(created_at)),id FOR UPDATE");
        $needed = $quantity;
        $total_cost = 0;
        while ($batch = mysqli_fetch_assoc($batches)) {
            if ($needed < 0.0001) break;
            $take = min($needed, (float)$batch['remaining_quantity']);
            $cost = (float)$batch['unit_cost'];
            $stmt = mysqli_prepare($conn, 'UPDATE stock_batches SET remaining_quantity=remaining_quantity-? WHERE id=? AND remaining_quantity>=?');
            mysqli_stmt_bind_param($stmt, 'did', $take, $batch['id'], $take);
            mysqli_stmt_execute($stmt);
            if (mysqli_stmt_affected_rows($stmt) !== 1) throw new RuntimeException('Stock changed; try again.');
            // Stock leaves the source immediately, but it is not sellable at
            // the destination until that branch confirms receipt.
            $destination_batch = 0;
            $stmt = mysqli_prepare($conn, 'INSERT INTO stock_distribution_items(distribution_id,source_batch_id,destination_batch_id,quantity,unit_cost) VALUES (?,?,?,?,?)');
            mysqli_stmt_bind_param($stmt, 'iiidd', $id, $batch['id'], $destination_batch, $take, $cost);
            mysqli_stmt_execute($stmt);
            $total_cost += $take * $cost;
            $needed -= $take;
        }
        if ($needed > 0.0001) throw new RuntimeException('Distribution could not be completed.');
        $stmt = mysqli_prepare($conn, 'UPDATE stock_distributions SET total_cost=? WHERE id=?');
        mysqli_stmt_bind_param($stmt, 'di', $total_cost, $id);
        mysqli_stmt_execute($stmt);
        // This is an internal stock movement: company stock and wallet balances
        // stay unchanged. Only a purchase/payment or sale moves wallet money.
        if ($manage_transaction) mysqli_commit($conn);
        return $id;
    } catch (Throwable $e) { if ($manage_transaction) mysqli_rollback($conn); throw $e; }
}

function fifo_inventory_receive_distribution($conn, $user_id, $distribution_id, $damaged_quantity = 0, $receiving_branch_id = null)
{
    $user_id = (int)$user_id;
    $distribution_id = (int)$distribution_id;
    $damaged_quantity = (float)$damaged_quantity;
    $branch_id = $receiving_branch_id === null ? selected_branch_id($conn, true) : (int)$receiving_branch_id;
    if ($distribution_id <= 0 || $branch_id <= 0 || !is_finite($damaged_quantity) || $damaged_quantity < 0 || floor($damaged_quantity) !== $damaged_quantity) {
        throw new RuntimeException('Invalid stock receipt.');
    }
    mysqli_begin_transaction($conn);
    try {
        $distribution = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM stock_distributions WHERE id={$distribution_id} AND user_id={$user_id} FOR UPDATE"));
        if (!$distribution || (int)$distribution['to_branch_id'] !== $branch_id) throw new RuntimeException('This stock request does not belong to your branch.');
        if (($distribution['status'] ?? 'accepted') !== 'pending') throw new RuntimeException('This stock request has already been received.');
        $quantity = (float)$distribution['quantity'];
        if ($damaged_quantity > $quantity) throw new RuntimeException('Damaged / missing quantity cannot exceed the transferred quantity.');

        $remaining_to_receive = $quantity - $damaged_quantity;
        $items = mysqli_query($conn, "SELECT * FROM stock_distribution_items WHERE distribution_id={$distribution_id} ORDER BY id FOR UPDATE");
        while ($item = mysqli_fetch_assoc($items)) {
            if ($remaining_to_receive <= 0) break;
            $received = min($remaining_to_receive, (float)$item['quantity']);
            if (!fifo_inventory_create_batch($conn, $user_id, (int)$distribution['product_id'], $received, (float)$item['unit_cost'], 'distribution', $distribution_id, (string)$distribution['reference_no'], date('Y-m-d'), $branch_id, (string)($distribution['variant_name'] ?? ''))) {
                throw new RuntimeException('Received stock could not be added.');
            }
            $destination_batch_id = (int)mysqli_insert_id($conn);
            $update = mysqli_prepare($conn, 'UPDATE stock_distribution_items SET destination_batch_id=? WHERE id=?');
            mysqli_stmt_bind_param($update, 'ii', $destination_batch_id, $item['id']);
            mysqli_stmt_execute($update);
            $remaining_to_receive -= $received;
        }
        if ($remaining_to_receive > 0.0001) throw new RuntimeException('Distribution item data is incomplete.');
        $receiver = (int)($_SESSION['login_user_id'] ?? $user_id);
        $update = mysqli_prepare($conn, "UPDATE stock_distributions SET status='accepted',damaged_quantity=?,received_at=NOW(),received_by=? WHERE id=? AND status='pending'");
        mysqli_stmt_bind_param($update, 'dii', $damaged_quantity, $receiver, $distribution_id);
        mysqli_stmt_execute($update);
        if (mysqli_stmt_affected_rows($update) !== 1) throw new RuntimeException('Stock receipt could not be saved.');
        mysqli_commit($conn);
    } catch (Throwable $e) { mysqli_rollback($conn); throw $e; }
}

function ensure_fifo_inventory_tables($conn)
{
    if(!fifo_inventory_is_enabled()){
        return;
    }
    static $checked = false;

    if($checked){
        return;
    }

    $checked = true;

    require_once __DIR__ . '/stock_schema_helper.php';
    ensure_stock_base_tables($conn);
    ensure_product_variant_schema($conn);

    $remaining_column = mysqli_query($conn, "SHOW COLUMNS FROM purchase_items LIKE 'remaining_quantity'");

    if($remaining_column && mysqli_num_rows($remaining_column) === 0){
        mysqli_query(
            $conn,
            "ALTER TABLE purchase_items
             ADD COLUMN remaining_quantity DOUBLE NOT NULL DEFAULT 0 AFTER quantity"
        );
        mysqli_query(
            $conn,
            "UPDATE purchase_items
             SET remaining_quantity = quantity
             WHERE remaining_quantity = 0"
        );
    }

    $cost_column = mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'cost_amount'");

    if($cost_column && mysqli_num_rows($cost_column) === 0){
        mysqli_query(
            $conn,
            "ALTER TABLE invoice_items
             ADD COLUMN cost_amount DOUBLE NOT NULL DEFAULT 0 AFTER total_price"
        );
    }

    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS stock_batches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            product_id INT NOT NULL,
            source_type VARCHAR(30) NOT NULL DEFAULT 'purchase',
            source_id INT NOT NULL DEFAULT 0,
            source_no VARCHAR(100) NULL,
            quantity DOUBLE NOT NULL DEFAULT 0,
            remaining_quantity DOUBLE NOT NULL DEFAULT 0,
            unit_cost DOUBLE NOT NULL DEFAULT 0,
            batch_date DATE NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );

    // The variant index below depends on branch_id, including on fresh installs.
    $branch_column = mysqli_query($conn, "SHOW COLUMNS FROM stock_batches LIKE 'branch_id'");
    if (mysqli_num_rows($branch_column) === 0) {
        mysqli_query($conn, 'ALTER TABLE stock_batches ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0, ADD INDEX idx_stock_branch_product (user_id,branch_id,product_id)');
    }

    $batch_variant = mysqli_query($conn, "SHOW COLUMNS FROM stock_batches LIKE 'variant_name'");
    if($batch_variant && mysqli_num_rows($batch_variant) === 0){
        mysqli_query($conn, "ALTER TABLE stock_batches ADD COLUMN variant_name VARCHAR(100) NOT NULL DEFAULT '' AFTER product_id");
        mysqli_query($conn, "ALTER TABLE stock_batches ADD INDEX idx_stock_variant (user_id,branch_id,product_id,variant_name)");
    }

    $invoice_variant = mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'variant_name'");
    if($invoice_variant && mysqli_num_rows($invoice_variant) === 0){
        mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN variant_name VARCHAR(100) NOT NULL DEFAULT '' AFTER product_id");
    }
    $purchase_variant = mysqli_query($conn, "SHOW COLUMNS FROM purchase_items LIKE 'variant_name'");
    if($purchase_variant && mysqli_num_rows($purchase_variant) === 0){
        mysqli_query($conn, "ALTER TABLE purchase_items ADD COLUMN variant_name VARCHAR(100) NOT NULL DEFAULT '' AFTER product_id");
    }

    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS invoice_item_allocations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_item_id INT NOT NULL,
            stock_batch_id INT NOT NULL,
            quantity DOUBLE NOT NULL DEFAULT 0,
            unit_cost DOUBLE NOT NULL DEFAULT 0,
            total_cost DOUBLE NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );

    $opening_stock_column = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'opening_stock_quantity'");

    if($opening_stock_column && mysqli_num_rows($opening_stock_column) === 0){
        mysqli_query(
            $conn,
            "ALTER TABLE products
             ADD COLUMN opening_stock_quantity DOUBLE NOT NULL DEFAULT 0 AFTER current_stock"
        );
        mysqli_query(
            $conn,
            "UPDATE products
             SET opening_stock_quantity = current_stock
             WHERE opening_stock_quantity = 0"
        );
    }

    $opening_cost_column = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'opening_stock_unit_cost'");

    if($opening_cost_column && mysqli_num_rows($opening_cost_column) === 0){
        mysqli_query(
            $conn,
            "ALTER TABLE products
             ADD COLUMN opening_stock_unit_cost DOUBLE NOT NULL DEFAULT 0 AFTER opening_stock_quantity"
        );
        mysqli_query(
            $conn,
            "UPDATE products
             SET opening_stock_unit_cost = purchase_price
             WHERE opening_stock_unit_cost = 0"
        );
    }

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_distributions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL, from_branch_id BIGINT UNSIGNED NOT NULL,
        to_branch_id BIGINT UNSIGNED NOT NULL, product_id INT NOT NULL,
        quantity DECIMAL(18,4) NOT NULL, total_cost DECIMAL(18,4) NOT NULL DEFAULT 0,
        reference_no VARCHAR(80) NOT NULL, request_key VARCHAR(64) NOT NULL,
        created_by INT NOT NULL, note VARCHAR(500) NOT NULL DEFAULT '',
        status ENUM('pending','accepted') NOT NULL DEFAULT 'accepted',
        damaged_quantity DECIMAL(18,4) NOT NULL DEFAULT 0,
        received_at DATETIME NULL, received_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_distribution_request (user_id,request_key),
        KEY idx_distribution_branch (user_id,to_branch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $distribution_columns = [
        'transfer_group' => 'ADD COLUMN transfer_group VARCHAR(64) NULL',
        'status' => "ADD COLUMN status ENUM('pending','accepted') NOT NULL DEFAULT 'accepted' AFTER note",
        'damaged_quantity' => 'ADD COLUMN damaged_quantity DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER status',
        'received_at' => 'ADD COLUMN received_at DATETIME NULL AFTER damaged_quantity',
        'received_by' => 'ADD COLUMN received_by INT NULL AFTER received_at',
    ];
    foreach ($distribution_columns as $column => $definition) {
        $exists = mysqli_query($conn, "SHOW COLUMNS FROM stock_distributions LIKE '{$column}'");
        if ($exists && mysqli_num_rows($exists) === 0) mysqli_query($conn, "ALTER TABLE stock_distributions {$definition}");
    }
    $distribution_variant = mysqli_query($conn, "SHOW COLUMNS FROM stock_distributions LIKE 'variant_name'");
    if($distribution_variant && mysqli_num_rows($distribution_variant) === 0){
        mysqli_query($conn, "ALTER TABLE stock_distributions ADD COLUMN variant_name VARCHAR(100) NOT NULL DEFAULT '' AFTER product_id");
    }
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_distribution_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, distribution_id BIGINT UNSIGNED NOT NULL,
        source_batch_id INT NOT NULL, destination_batch_id INT NOT NULL,
        quantity DECIMAL(18,4) NOT NULL, unit_cost DECIMAL(18,4) NOT NULL,
        KEY idx_distribution_items (distribution_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_inventory_migrations (
        user_id INT PRIMARY KEY, completed_at DATETIME NULL
    ) ENGINE=InnoDB");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_migration_adjustments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
        product_id INT NOT NULL, recorded_quantity DECIMAL(18,4) NOT NULL,
        batch_quantity DECIMAL(18,4) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_damage_returns (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL, branch_id BIGINT UNSIGNED NOT NULL, product_id INT NOT NULL,
        variant_name VARCHAR(100) NOT NULL DEFAULT '', quantity DECIMAL(18,4) NOT NULL,
        request_key VARCHAR(64) NOT NULL, note VARCHAR(500) NOT NULL DEFAULT '',
        status ENUM('pending','accepted','dump_pending','dumped') NOT NULL DEFAULT 'pending',
        created_by INT NOT NULL, accepted_at DATETIME NULL, accepted_by INT NULL,
        dump_requested_at DATETIME NULL, dump_requested_by INT NULL,
        dump_decided_at DATETIME NULL, dump_decided_by INT NULL,
        dumped_at DATETIME NULL, dumped_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_damage_return_request (user_id,request_key),
        KEY idx_damage_return_status (user_id,status,branch_id,product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $damage_status_column = mysqli_query($conn, "SHOW COLUMNS FROM stock_damage_returns LIKE 'status'");
    $damage_status_definition = $damage_status_column ? mysqli_fetch_assoc($damage_status_column) : null;
    if ($damage_status_definition && strpos((string)$damage_status_definition['Type'], 'dump_pending') === false) {
        mysqli_query($conn, "ALTER TABLE stock_damage_returns MODIFY COLUMN status ENUM('pending','accepted','dump_pending','dumped') NOT NULL DEFAULT 'pending'");
    }
    foreach ([
        'dump_requested_at' => 'DATETIME NULL AFTER accepted_by',
        'dump_requested_by' => 'INT NULL AFTER dump_requested_at',
        'dump_decided_at' => 'DATETIME NULL AFTER dump_requested_by',
        'dump_decided_by' => 'INT NULL AFTER dump_decided_at',
    ] as $damage_column => $definition) {
        $column_exists = mysqli_query($conn, "SHOW COLUMNS FROM stock_damage_returns LIKE '{$damage_column}'");
        if ($column_exists && mysqli_num_rows($column_exists) === 0) {
            mysqli_query($conn, "ALTER TABLE stock_damage_returns ADD COLUMN {$damage_column} {$definition}");
        }
    }
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_damage_return_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, damage_return_id BIGINT UNSIGNED NOT NULL,
        source_batch_id INT NOT NULL, quantity DECIMAL(18,4) NOT NULL,
        KEY idx_damage_return_item (damage_return_id), KEY idx_damage_return_batch (source_batch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function fifo_inventory_pending_damage_qty($conn, $user_id, $product_id, $branch_id, $variant_name = '')
{
    $stmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(quantity),0) AS quantity FROM stock_damage_returns WHERE user_id=? AND product_id=? AND branch_id=? AND variant_name=? AND status='pending'");
    if (!$stmt) return 0;
    mysqli_stmt_bind_param($stmt, 'iiis', $user_id, $product_id, $branch_id, $variant_name);
    mysqli_stmt_execute($stmt);
    return (float)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['quantity'] ?? 0);
}

function fifo_inventory_request_damage_return($conn, $user_id, $branch_id, $product_id, $variant_name, $quantity, $request_key, $note = '')
{
    $branch_id = (int)$branch_id; $product_id = (int)$product_id; $quantity = (float)$quantity; $variant_name = trim((string)$variant_name);
    if ($branch_id <= 0 || $product_id <= 0 || $quantity <= 0 || floor($quantity) !== $quantity || !preg_match('/^[a-f0-9]{32,64}$/', $request_key)) throw new RuntimeException('Invalid damaged-return request.');
    mysqli_begin_transaction($conn);
    try {
        $product = mysqli_fetch_assoc(mysqli_query($conn, "SELECT p.id FROM products p INNER JOIN product_categories c ON c.id=p.category_id WHERE p.id={$product_id} AND p.user_id=" . (int)$user_id . " AND p.status='active' AND c.category_type='stock_product' FOR UPDATE"));
        if (!$product || !product_variant_is_valid($conn, $product_id, $user_id, $variant_name)) throw new RuntimeException('Stock product or variant is not available.');
        $stmt = mysqli_prepare($conn, 'SELECT id FROM stock_damage_returns WHERE user_id=? AND request_key=?');
        mysqli_stmt_bind_param($stmt, 'is', $user_id, $request_key); mysqli_stmt_execute($stmt);
        $duplicate = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if ($duplicate) { mysqli_commit($conn); return (int)$duplicate['id']; }
        $damage_sql = "SELECT COALESCE(SUM(d.damaged_quantity),0) - COALESCE((SELECT SUM(dr.quantity) FROM stock_damage_returns dr WHERE dr.user_id=d.user_id AND dr.branch_id=d.to_branch_id AND dr.product_id=d.product_id AND dr.variant_name=d.variant_name),0) AS available_damage FROM stock_distributions d WHERE d.user_id=? AND d.to_branch_id=? AND d.product_id=? AND d.variant_name=? AND d.status='accepted'";
        $damage_stmt = mysqli_prepare($conn, $damage_sql);
        mysqli_stmt_bind_param($damage_stmt, 'iiis', $user_id, $branch_id, $product_id, $variant_name);
        mysqli_stmt_execute($damage_stmt);
        $available = max(0, (float)(mysqli_fetch_assoc(mysqli_stmt_get_result($damage_stmt))['available_damage'] ?? 0));
        if ($quantity > $available + 0.0001) throw new RuntimeException('Quantity exceeds damaged stock available in this branch.');
        $actor = (int)($_SESSION['login_user_id'] ?? $user_id); $note = mb_substr(trim($note), 0, 500);
        $stmt = mysqli_prepare($conn, "INSERT INTO stock_damage_returns(user_id,branch_id,product_id,variant_name,quantity,request_key,note,created_by) VALUES (?,?,?,?,?,?,?,?)");
        mysqli_stmt_bind_param($stmt, 'iiisdssi', $user_id, $branch_id, $product_id, $variant_name, $quantity, $request_key, $note, $actor);
        mysqli_stmt_execute($stmt); $return_id = (int)mysqli_insert_id($conn);
        mysqli_commit($conn); return $return_id;
    } catch (Throwable $e) { mysqli_rollback($conn); throw $e; }
}

function fifo_inventory_accept_damage_return($conn, $user_id, $return_id)
{
    if (!stock_can_manage_warehouse($conn)) throw new RuntimeException('Warehouse permission is required.');
    mysqli_begin_transaction($conn);
    try {
        $return_id = (int)$return_id; $return = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM stock_damage_returns WHERE id={$return_id} AND user_id=" . (int)$user_id . " FOR UPDATE"));
        if (!$return || $return['status'] !== 'pending') throw new RuntimeException('This damaged return is no longer pending.');
        $actor = (int)($_SESSION['login_user_id'] ?? $user_id);
        $stmt = mysqli_prepare($conn, "UPDATE stock_damage_returns SET status='accepted',accepted_at=NOW(),accepted_by=? WHERE id=?"); mysqli_stmt_bind_param($stmt, 'ii', $actor, $return_id); mysqli_stmt_execute($stmt);
        mysqli_commit($conn);
    } catch (Throwable $e) { mysqli_rollback($conn); throw $e; }
}

function fifo_inventory_dump_damage_return($conn, $user_id, $return_id)
{
    if (!stock_can_manage_warehouse($conn)) throw new RuntimeException('Warehouse permission is required.');
    $actor = (int)($_SESSION['login_user_id'] ?? $user_id); $return_id = (int)$return_id;
    $stmt = mysqli_prepare($conn, "UPDATE stock_damage_returns SET status='dump_pending',dump_requested_at=NOW(),dump_requested_by=? WHERE id=? AND user_id=? AND status='accepted'");
    mysqli_stmt_bind_param($stmt, 'iii', $actor, $return_id, $user_id); mysqli_stmt_execute($stmt);
    if (mysqli_stmt_affected_rows($stmt) !== 1) {
        $current = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status FROM stock_damage_returns WHERE id={$return_id} AND user_id=" . (int)$user_id));
        if (!$current) throw new RuntimeException('Damaged return was not found. Refresh the page and try again.');
        if ($current['status'] !== 'accepted') throw new RuntimeException('This damaged return is currently ' . str_replace('_', ' ', $current['status']) . '. Refresh the page before taking action.');
        throw new RuntimeException('The dump request could not be saved. Please try again.');
    }
}

function fifo_inventory_decide_damage_dump($conn, $user_id, $return_id, $approve)
{
    if (!is_admin_user()) throw new RuntimeException('Only an administrator can approve damaged product dumps.');
    $return_id = (int)$return_id;
    $actor = (int)($_SESSION['login_user_id'] ?? $user_id);
    if ($approve) {
        $stmt = mysqli_prepare($conn, "UPDATE stock_damage_returns SET status='dumped',dump_decided_at=NOW(),dump_decided_by=?,dumped_at=NOW(),dumped_by=? WHERE id=? AND user_id=? AND status='dump_pending'");
        mysqli_stmt_bind_param($stmt, 'iiii', $actor, $actor, $return_id, $user_id);
    } else {
        $stmt = mysqli_prepare($conn, "UPDATE stock_damage_returns SET status='accepted',dump_decided_at=NOW(),dump_decided_by=? WHERE id=? AND user_id=? AND status='dump_pending'");
        mysqli_stmt_bind_param($stmt, 'iii', $actor, $return_id, $user_id);
    }
    mysqli_stmt_execute($stmt);
    if (mysqli_stmt_affected_rows($stmt) !== 1) throw new RuntimeException('This damaged dump request is no longer pending.');
}

function fifo_inventory_create_batch($conn, $user_id, $product_id, $quantity, $unit_cost, $source_type, $source_id, $source_no, $batch_date, $branch_id = null, $variant_name = '')
{
    if(!fifo_inventory_is_enabled()){
        return true;
    }
    ensure_fifo_inventory_tables($conn);

    $quantity = (float)$quantity;
    $unit_cost = (float)$unit_cost;
    $source_type = trim((string)$source_type);
    $source_no = trim((string)$source_no);
    $batch_date = trim((string)$batch_date);
    $variant_name = trim((string)$variant_name);
    $branch_id = $branch_id === null ? stock_purchase_location_id($conn, $user_id) : (int)$branch_id;
    if ($branch_id <= 0 || !is_finite($quantity) || !is_finite($unit_cost) || $unit_cost < 0) {
        throw new RuntimeException('Invalid stock batch.');
    }

    if($quantity <= 0){
        return true;
    }

    $sql = "INSERT INTO stock_batches
            (
                user_id,
                branch_id,
                product_id,
                variant_name,
                source_type,
                source_id,
                source_no,
                quantity,
                remaining_quantity,
                unit_cost,
                batch_date
            )
            VALUES
            (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )";

    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return false;
    }

    mysqli_stmt_bind_param(
        $stmt,
        "iiissisddds",
        $user_id,
        $branch_id,
        $product_id,
        $variant_name,
        $source_type,
        $source_id,
        $source_no,
        $quantity,
        $quantity,
        $unit_cost,
        $batch_date
    );

    return mysqli_stmt_execute($stmt);
}

function fifo_inventory_get_available_stock($conn, $user_id, $product_id, $branch_id = null, $variant_name = '')
{
    if(!fifo_inventory_is_enabled()){
        return 0;
    }
    ensure_fifo_inventory_tables($conn);

    $branch_id = $branch_id === null ? (int)($GLOBALS['stock_wallet_branch_id'] ?? selected_branch_id($conn, true)) : (int)$branch_id;

    $sql = "SELECT COALESCE(SUM(remaining_quantity), 0) AS available_stock
            FROM stock_batches
            WHERE user_id=?
            AND product_id=?
            AND branch_id=?
            AND variant_name=?
            AND remaining_quantity > 0";

    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return 0;
    }

    mysqli_stmt_bind_param($stmt, "iiis", $user_id, $product_id, $branch_id, $variant_name);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    return (float)($row['available_stock'] ?? 0);
}

function fifo_inventory_allocate_sale($conn, $user_id, $invoice_item_id, $product_id, $quantity, $variant_name = '')
{
    if(!fifo_inventory_is_enabled()){
        return ['success' => true, 'cost_amount' => 0];
    }
    ensure_fifo_inventory_tables($conn);

    $quantity = (float)$quantity;

    if($quantity <= 0){
        return [
            'success' => true,
            'cost_amount' => 0,
        ];
    }

    $branch_id = fifo_inventory_item_branch($conn, $user_id, $invoice_item_id);
    $variant_name = trim((string)$variant_name);
    $available_stock = fifo_inventory_get_available_stock($conn, $user_id, $product_id, $branch_id, $variant_name);

    if($available_stock + 0.0001 < $quantity){
        return [
            'success' => false,
            'error' => 'Insufficient FIFO stock.',
            'cost_amount' => 0,
        ];
    }

    $sql = "SELECT id, remaining_quantity, unit_cost FROM stock_batches
            WHERE user_id=? AND product_id=? AND branch_id=? AND variant_name=? AND remaining_quantity > 0
            ORDER BY COALESCE(batch_date, DATE(created_at)) ASC, id ASC FOR UPDATE";

    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return [
            'success' => false,
            'error' => 'Stock batch query failed.',
            'cost_amount' => 0,
        ];
    }

    mysqli_stmt_bind_param($stmt, "iiis", $user_id, $product_id, $branch_id, $variant_name);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $needed = $quantity;
    $cost_amount = 0;

    while($batch = mysqli_fetch_assoc($result)){
        if($needed <= 0){
            break;
        }

        $batch_id = (int)$batch['id'];
        $batch_remaining = (float)$batch['remaining_quantity'];
        $unit_cost = (float)$batch['unit_cost'];
        $used_quantity = min($needed, $batch_remaining);
        $line_cost = $used_quantity * $unit_cost;

        $update_stmt = mysqli_prepare(
            $conn,
            "UPDATE stock_batches
             SET remaining_quantity = remaining_quantity - ?
             WHERE id=?"
        );

        if(!$update_stmt){
            return [
                'success' => false,
                'error' => 'Stock batch update failed.',
                'cost_amount' => 0,
            ];
        }

        mysqli_stmt_bind_param($update_stmt, "di", $used_quantity, $batch_id);
        mysqli_stmt_execute($update_stmt);

        $alloc_stmt = mysqli_prepare(
            $conn,
            "INSERT INTO invoice_item_allocations
             (
                invoice_item_id,
                stock_batch_id,
                quantity,
                unit_cost,
                total_cost
             )
             VALUES
             (
                ?, ?, ?, ?, ?
             )"
        );

        if(!$alloc_stmt){
            return [
                'success' => false,
                'error' => 'Allocation save failed.',
                'cost_amount' => 0,
            ];
        }

        mysqli_stmt_bind_param(
            $alloc_stmt,
            "iiddd",
            $invoice_item_id,
            $batch_id,
            $used_quantity,
            $unit_cost,
            $line_cost
        );
        mysqli_stmt_execute($alloc_stmt);

        $cost_amount += $line_cost;
        $needed -= $used_quantity;
    }

    if($needed > 0.0001){
        return [
            'success' => false,
            'error' => 'FIFO allocation incomplete.',
            'cost_amount' => 0,
        ];
    }

    $cost_stmt = mysqli_prepare(
        $conn,
        "UPDATE invoice_items
         SET cost_amount=?
         WHERE id=?"
    );

    if($cost_stmt){
        mysqli_stmt_bind_param($cost_stmt, "di", $cost_amount, $invoice_item_id);
        mysqli_stmt_execute($cost_stmt);
    }

    return [
        'success' => true,
        'cost_amount' => $cost_amount,
    ];
}

function fifo_inventory_add_return_batch($conn, $user_id, $invoice_item_id, $product_id, $quantity, $unit_cost, $source_no, $batch_date)
{
    if(!fifo_inventory_is_enabled()){
        return true;
    }
    ensure_fifo_inventory_tables($conn);

    $quantity = abs((float)$quantity);
    $unit_cost = max(0, (float)$unit_cost);

    if($quantity <= 0){
        return true;
    }

    $created = fifo_inventory_create_batch(
        $conn,
        $user_id,
        $product_id,
        $quantity,
        $unit_cost,
        'sales_return',
        $invoice_item_id,
        $source_no,
        $batch_date,
        fifo_inventory_item_branch($conn, $user_id, $invoice_item_id)
    );

    if(!$created){
        return false;
    }

    $cost_amount = -($quantity * $unit_cost);
    $stmt = mysqli_prepare($conn, "UPDATE invoice_items SET cost_amount=? WHERE id=?");

    if($stmt){
        mysqli_stmt_bind_param($stmt, "di", $cost_amount, $invoice_item_id);
        mysqli_stmt_execute($stmt);
    }

    return true;
}

function fifo_inventory_restore_invoice_item($conn, $invoice_item_id)
{
    if(!fifo_inventory_is_enabled()){
        return true;
    }
    ensure_fifo_inventory_tables($conn);

    $invoice_item_id = (int)$invoice_item_id;

    // A returned batch may already have been sold or distributed. Reversing
    // that invoice would destroy the stock trail, so keep it immutable then.
    $used_return = mysqli_query($conn, "SELECT id FROM stock_batches WHERE source_type='sales_return' AND source_id={$invoice_item_id} AND ABS(quantity-remaining_quantity)>0.0001 FOR UPDATE");
    if (mysqli_num_rows($used_return) > 0) throw new RuntimeException('Returned stock has already been used; this invoice cannot be edited or deleted.');

    $sql = "SELECT stock_batch_id, quantity
            FROM invoice_item_allocations
            WHERE invoice_item_id=? FOR UPDATE";

    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return false;
    }

    mysqli_stmt_bind_param($stmt, "i", $invoice_item_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while($row = mysqli_fetch_assoc($result)){
        $update_stmt = mysqli_prepare(
            $conn,
            "UPDATE stock_batches
             SET remaining_quantity = remaining_quantity + ?
             WHERE id=?"
        );

        if($update_stmt){
            mysqli_stmt_bind_param($update_stmt, "di", $row['quantity'], $row['stock_batch_id']);
            mysqli_stmt_execute($update_stmt);
        }
    }

    mysqli_query($conn, "DELETE FROM invoice_item_allocations WHERE invoice_item_id=" . $invoice_item_id);
    mysqli_query($conn, "DELETE FROM stock_batches WHERE source_type='sales_return' AND source_id=" . $invoice_item_id);
    mysqli_query($conn, "UPDATE invoice_items SET cost_amount=0 WHERE id=" . $invoice_item_id);

    return true;
}

function fifo_inventory_purchase_is_editable($conn, $purchase_id)
{
    if(!fifo_inventory_is_enabled()){
        return true;
    }
    ensure_fifo_inventory_tables($conn);

    $purchase_id = (int)$purchase_id;

    $sql = "SELECT quantity, remaining_quantity
            FROM stock_batches
            WHERE source_type='purchase'
            AND source_id=?
            FOR UPDATE";

    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return false;
    }

    mysqli_stmt_bind_param($stmt, "i", $purchase_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while($row = mysqli_fetch_assoc($result)){
        if(abs((float)$row['quantity'] - (float)$row['remaining_quantity']) > 0.0001){
            return false;
        }
    }
    return true;
}

function fifo_inventory_remove_purchase_batches($conn, $purchase_id)
{
    if(!fifo_inventory_is_enabled()){
        return true;
    }
    ensure_fifo_inventory_tables($conn);

    $purchase_id = (int)$purchase_id;

    return mysqli_query(
        $conn,
        "DELETE FROM stock_batches
         WHERE source_type='purchase'
         AND source_id={$purchase_id}"
    ) !== false;
}

function fifo_inventory_remove_product_opening_batches($conn, $product_id)
{
    if(!fifo_inventory_is_enabled()){
        return true;
    }
    ensure_fifo_inventory_tables($conn);

    $product_id = (int)$product_id;

    return mysqli_query(
        $conn,
        "DELETE FROM stock_batches
         WHERE source_type='product_opening'
         AND source_id={$product_id}"
    ) !== false;
}

function fifo_inventory_product_opening_is_editable($conn, $user_id, $product_id)
{
    if(!fifo_inventory_is_enabled()){
        return true;
    }
    ensure_fifo_inventory_tables($conn);

    $user_id = (int)$user_id;
    $product_id = (int)$product_id;

    $sql = "SELECT COUNT(*) AS restricted_count
            FROM stock_batches
            WHERE user_id=?
            AND product_id=?
            AND (
                (source_type='product_opening' AND source_id=? AND ABS(quantity - remaining_quantity) > 0.0001)
                OR
                NOT (source_type='product_opening' AND source_id=?)
            )";

    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return false;
    }

    mysqli_stmt_bind_param($stmt, "iiii", $user_id, $product_id, $product_id, $product_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    return (int)($row['restricted_count'] ?? 0) === 0;
}

function fifo_inventory_product_is_editable_before_sale($conn, $user_id, $product_id)
{
    if(!fifo_inventory_is_enabled()){
        return true;
    }
    ensure_fifo_inventory_tables($conn);

    $user_id = (int)$user_id;
    $product_id = (int)$product_id;

    $sql = "SELECT COUNT(*) AS consumed_batches
            FROM stock_batches
            WHERE user_id=?
            AND product_id=?
            AND ABS(quantity - remaining_quantity) > 0.0001";

    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return false;
    }

    mysqli_stmt_bind_param($stmt, "ii", $user_id, $product_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    return (int)($row['consumed_batches'] ?? 0) === 0;
}
