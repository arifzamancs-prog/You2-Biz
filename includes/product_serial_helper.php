<?php
/*
 * Product serials (Inventory & Sales).
 *
 * A product with "Product Serial" on keeps one row per piece in product_serials:
 *   in_stock   – at branch_id (opening stock, purchase receive, received transfer)
 *   in_transit – sent to another location, not yet received (distribution_id)
 *   sold       – on an invoice line (invoice_id / invoice_item_id)
 *   damaged    – sent but marked damaged / missing on receive (not stock)
 * Serials are unique per company, so a scanned serial always names one piece.
 */

require_once __DIR__ . '/schema_cache_helper.php';

function product_serial_feature_enabled()
{
    return ($_SESSION['company_type'] ?? '') === 'Stock Product';
}

// Run before a transaction starts (ALTER TABLE ends a transaction).
function product_serial_schema($conn)
{
    if (!product_serial_feature_enabled()) {
        return false;
    }
    if (!schema_once('product_serial_v1')) {
        return true;
    }
    $col = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'serial_enabled'");
    if ($col && mysqli_num_rows($col) === 0) {
        mysqli_query($conn, 'ALTER TABLE products ADD COLUMN serial_enabled TINYINT(1) NOT NULL DEFAULT 0');
    }
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS product_serials (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        product_id INT NOT NULL,
        variant_name VARCHAR(100) NOT NULL DEFAULT '',
        serial_no VARCHAR(100) NOT NULL,
        branch_id INT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'in_stock',
        source_type VARCHAR(30) NOT NULL DEFAULT '',
        source_id INT NOT NULL DEFAULT 0,
        distribution_id INT NOT NULL DEFAULT 0,
        invoice_id INT NOT NULL DEFAULT 0,
        invoice_item_id INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sold_at DATETIME NULL DEFAULT NULL,
        UNIQUE KEY uniq_company_serial (user_id, serial_no),
        KEY idx_serial_product (user_id, product_id, status),
        KEY idx_serial_invoice (invoice_id),
        KEY idx_serial_distribution (distribution_id),
        KEY idx_serial_source (source_type, source_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Several serials can be written on one invoice line.
    $col = mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'serial_no'");
    $info = $col ? mysqli_fetch_assoc($col) : null;
    if ($info && stripos((string)$info['Type'], 'varchar(255)') === 0) {
        mysqli_query($conn, "ALTER TABLE invoice_items MODIFY serial_no VARCHAR(2000) NOT NULL DEFAULT ''");
    }
    // Warranty given while receiving from a vendor.
    $col = mysqli_query($conn, "SHOW COLUMNS FROM purchase_items LIKE 'warranty_months'");
    if ($col && mysqli_num_rows($col) === 0) {
        mysqli_query($conn, 'ALTER TABLE purchase_items ADD COLUMN warranty_months INT NULL DEFAULT NULL');
    }
    return true;
}

// "A1, A2" / one per line -> ['A1','A2'] (trimmed, no repeats ignoring letter case).
function product_serial_parse($raw)
{
    if (is_array($raw)) {
        $raw = implode("\n", array_map('strval', $raw));
    }
    $out = [];
    foreach (preg_split('/[\r\n,]+/', (string)$raw) as $serial) {
        $serial = trim(preg_replace('/\s+/', ' ', $serial));
        if ($serial === '') {
            continue;
        }
        if (mb_strlen($serial) > 100) {
            throw new RuntimeException('Serial "' . mb_substr($serial, 0, 30) . '…" is too long (max 100 characters).');
        }
        $out[mb_strtolower($serial)] = $serial;
    }
    return array_values($out);
}

// {"variant": "A1\nA2", "": "..."} or {"__none__": [...]} -> ['variant' => ['A1','A2']]
function product_serial_parse_groups($raw)
{
    if (is_string($raw)) {
        $raw = trim($raw) === '' ? [] : json_decode($raw, true);
    }
    $groups = [];
    foreach ((array)$raw as $variant => $serials) {
        $variant = (string)$variant === '__none__' ? '' : (string)$variant;
        $list = product_serial_parse($serials);
        if ($list) {
            $groups[$variant] = $list;
        }
    }
    return $groups;
}

function product_serial_is_enabled($conn, $user_id, $product_id)
{
    if (!product_serial_feature_enabled()) {
        return false;
    }
    $row = mysqli_fetch_row(mysqli_query($conn, 'SELECT serial_enabled FROM products WHERE id=' . (int)$product_id . ' AND user_id=' . (int)$user_id . ' LIMIT 1'));
    return (int)($row[0] ?? 0) === 1;
}

function product_serial_set_enabled($conn, $user_id, $product_id, $enabled)
{
    if (!product_serial_feature_enabled()) {
        return;
    }
    $value = $enabled ? 1 : 0;
    mysqli_query($conn, 'UPDATE products SET serial_enabled=' . $value . ' WHERE id=' . (int)$product_id . ' AND user_id=' . (int)$user_id);
}

// [product_id => true] of products with Product Serial on.
function product_serial_product_flags($conn, $user_id)
{
    $flags = [];
    if (!product_serial_feature_enabled()) {
        return $flags;
    }
    $result = mysqli_query($conn, 'SELECT id FROM products WHERE user_id=' . (int)$user_id . ' AND serial_enabled=1');
    while ($result && ($row = mysqli_fetch_row($result))) {
        $flags[(int)$row[0]] = true;
    }
    return $flags;
}

function product_serial_rows_by_value($conn, $user_id, array $serials, $lock = false)
{
    if (!$serials) {
        return [];
    }
    $in = implode(',', array_map(function ($s) use ($conn) { return "'" . mysqli_real_escape_string($conn, $s) . "'"; }, $serials));
    $result = mysqli_query($conn, 'SELECT * FROM product_serials WHERE user_id=' . (int)$user_id . " AND serial_no IN ({$in})" . ($lock ? ' FOR UPDATE' : ''));
    $rows = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $rows[mb_strtolower($row['serial_no'])] = $row;
    }
    return $rows;
}

// Product edit: correct the text of serials still in stock. Sold and in-transit
// serials stay as they are. $changes is [serial row id => new serial].
function product_serial_correct($conn, $user_id, $product_id, array $changes)
{
    $user_id = (int)$user_id;
    $product_id = (int)$product_id;
    $current = [];
    $result = mysqli_query($conn, 'SELECT id, serial_no, status FROM product_serials WHERE user_id=' . $user_id . ' AND product_id=' . $product_id . ' FOR UPDATE');
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $current[(int)$row['id']] = $row;
    }
    $updates = [];
    $final = [];
    foreach ($current as $sid => $row) {
        $final[$sid] = (string)$row['serial_no'];
    }
    foreach ($changes as $sid => $value) {
        $sid = (int)$sid;
        $value = mb_substr(trim(str_replace(["\r", "\n", ','], ' ', (string)$value)), 0, 100);
        if (!isset($current[$sid]) || $value === $current[$sid]['serial_no']) {
            continue;
        }
        if ($current[$sid]['status'] !== 'in_stock') {
            throw new RuntimeException('Serial "' . $current[$sid]['serial_no'] . '" is ' . str_replace('_', ' ', $current[$sid]['status']) . ' and cannot be changed.');
        }
        if ($value === '') {
            throw new RuntimeException('Serial "' . $current[$sid]['serial_no'] . '" cannot be empty.');
        }
        $updates[$sid] = $value;
        $final[$sid] = $value;
    }
    if (!$updates) {
        return 0;
    }
    $seen = [];
    foreach ($final as $value) {
        $key = mb_strtolower($value);
        if (isset($seen[$key])) {
            throw new RuntimeException('Serial "' . $value . '" is entered twice.');
        }
        $seen[$key] = 1;
    }
    $check = mysqli_prepare($conn, 'SELECT id FROM product_serials WHERE user_id=? AND serial_no=? AND product_id<>? LIMIT 1');
    foreach ($updates as $value) {
        mysqli_stmt_bind_param($check, 'isi', $user_id, $value, $product_id);
        mysqli_stmt_execute($check);
        if (mysqli_fetch_row(mysqli_stmt_get_result($check))) {
            throw new RuntimeException('Serial "' . $value . '" is already used by another product.');
        }
    }
    // Two steps, so serials can swap text without hitting the unique key.
    $temp = mysqli_prepare($conn, 'UPDATE product_serials SET serial_no=? WHERE id=? AND user_id=?');
    foreach ($updates as $sid => $value) {
        $placeholder = '__tmp_' . $sid . '_' . mt_rand(1000, 9999);
        mysqli_stmt_bind_param($temp, 'sii', $placeholder, $sid, $user_id);
        mysqli_stmt_execute($temp);
    }
    foreach ($updates as $sid => $value) {
        mysqli_stmt_bind_param($temp, 'sii', $value, $sid, $user_id);
        mysqli_stmt_execute($temp);
    }
    return count($updates);
}

// Stock received with serials (opening stock, purchase): every serial must be new.
function product_serial_add($conn, $user_id, $product_id, $variant_name, array $serials, $branch_id, $source_type, $source_id)
{
    $serials = product_serial_parse($serials);
    if (!$serials) {
        return;
    }
    $existing = product_serial_rows_by_value($conn, $user_id, $serials);
    if ($existing) {
        $first = reset($existing);
        throw new RuntimeException('Serial "' . $first['serial_no'] . '" is already in use. Each serial can be entered only once.');
    }
    $user_id = (int)$user_id;
    $product_id = (int)$product_id;
    $branch_id = (int)$branch_id;
    $source_id = (int)$source_id;
    $variant_name = (string)$variant_name;
    $source_type = (string)$source_type;
    $stmt = mysqli_prepare($conn, "INSERT INTO product_serials (user_id, product_id, variant_name, serial_no, branch_id, status, source_type, source_id) VALUES (?,?,?,?,?,'in_stock',?,?)");
    foreach ($serials as $serial) {
        mysqli_stmt_bind_param($stmt, 'iissisi', $user_id, $product_id, $variant_name, $serial, $branch_id, $source_type, $source_id);
        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException('Serial "' . $serial . '" could not be saved.');
        }
    }
}

// Opening / purchase: one serial per piece. $groups = [variant => [serials]], $quantities = [variant => qty].
function product_serial_check_counts(array $groups, array $quantities, $label)
{
    foreach ($groups as $variant => $serials) {
        if (!array_key_exists($variant, $quantities)) {
            throw new RuntimeException($label . ': serials were entered for a variant that has no quantity.');
        }
    }
    foreach ($quantities as $variant => $qty) {
        $count = count($groups[$variant] ?? []);
        if ($count !== (int)$qty) {
            throw new RuntimeException($label . ($variant !== '' ? ' (' . $variant . ')' : '') . ': enter ' . (int)$qty . ' serial' . ((int)$qty === 1 ? '' : 's') . ' (one per piece); ' . $count . ' entered.');
        }
    }
}

// Remove the serials a stock entry brought in (purchase deleted, opening stock re-entered).
function product_serial_remove_source($conn, $user_id, $source_type, $source_id, $product_id = 0)
{
    $where = 'user_id=' . (int)$user_id . " AND source_type='" . mysqli_real_escape_string($conn, (string)$source_type) . "' AND source_id=" . (int)$source_id
        . ((int)$product_id > 0 ? ' AND product_id=' . (int)$product_id : '');
    $busy = mysqli_fetch_assoc(mysqli_query($conn, "SELECT serial_no, status FROM product_serials WHERE {$where} AND status<>'in_stock' LIMIT 1"));
    if ($busy) {
        throw new RuntimeException('Serial "' . $busy['serial_no'] . '" is already ' . ($busy['status'] === 'sold' ? 'sold' : 'sent to another location') . '; this stock entry cannot be changed.');
    }
    mysqli_query($conn, "DELETE FROM product_serials WHERE {$where}");
}

function product_serial_source_counts($conn, $user_id, $source_type, $source_id)
{
    $counts = [];
    $result = mysqli_query($conn, 'SELECT product_id, variant_name, COUNT(*) AS n FROM product_serials WHERE user_id=' . (int)$user_id
        . " AND source_type='" . mysqli_real_escape_string($conn, (string)$source_type) . "' AND source_id=" . (int)$source_id . ' GROUP BY product_id, variant_name');
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $counts[(int)$row['product_id'] . '|' . $row['variant_name']] = (int)$row['n'];
    }
    return $counts;
}

// Transfer: the serials picked for a line travel with its distribution(s).
function product_serial_send($conn, $user_id, array $distribution_ids, $from_branch_id, $product_id, $raw)
{
    $serials = product_serial_parse($raw);
    if (!$serials || !$distribution_ids) {
        return;
    }
    $distributions = [];
    $result = mysqli_query($conn, 'SELECT id, variant_name, quantity FROM stock_distributions WHERE user_id=' . (int)$user_id . ' AND id IN (' . implode(',', array_map('intval', $distribution_ids)) . ')');
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $distributions[(string)$row['variant_name']] = ['id' => (int)$row['id'], 'quantity' => (int)$row['quantity'], 'used' => 0];
    }
    $rows = product_serial_rows_by_value($conn, $user_id, $serials, true);
    $stmt = mysqli_prepare($conn, "UPDATE product_serials SET status='in_transit', distribution_id=? WHERE id=? AND status='in_stock'");
    foreach ($serials as $serial) {
        $row = $rows[mb_strtolower($serial)] ?? null;
        if (!$row || (int)$row['product_id'] !== (int)$product_id) {
            throw new RuntimeException('Serial "' . $serial . '" is not a serial of the selected product.');
        }
        if ($row['status'] !== 'in_stock' || (int)$row['branch_id'] !== (int)$from_branch_id) {
            throw new RuntimeException('Serial "' . $serial . '" is not in stock at the source location.');
        }
        $variant = (string)$row['variant_name'];
        if (!isset($distributions[$variant])) {
            throw new RuntimeException('Serial "' . $serial . '" belongs to ' . ($variant !== '' ? 'variant ' . $variant : 'no variant') . ', which is not being sent.');
        }
        if (++$distributions[$variant]['used'] > $distributions[$variant]['quantity']) {
            throw new RuntimeException('More serials than the quantity being sent' . ($variant !== '' ? ' for ' . $variant : '') . '.');
        }
        $distribution_id = $distributions[$variant]['id'];
        $serial_id = (int)$row['id'];
        mysqli_stmt_bind_param($stmt, 'ii', $distribution_id, $serial_id);
        mysqli_stmt_execute($stmt);
    }
}

function product_serial_receive_distribution($conn, $distribution_id, $branch_id, $received_ids = null)
{
    if (!product_serial_feature_enabled()) {
        return;
    }
    $table = mysqli_query($conn, "SHOW TABLES LIKE 'product_serials'");
    if (!$table || mysqli_num_rows($table) === 0) {
        return;
    }
    $distribution_id = (int)$distribution_id;
    $branch_id = (int)$branch_id;
    if ($received_ids === null) {
        mysqli_query($conn, "UPDATE product_serials SET status='in_stock', branch_id={$branch_id} WHERE distribution_id={$distribution_id} AND status='in_transit'");
        return;
    }
    // Ticked serials arrive in stock; the rest of this transfer are damaged / missing.
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$received_ids))));
    if ($ids) {
        mysqli_query($conn, "UPDATE product_serials SET status='in_stock', branch_id={$branch_id} WHERE distribution_id={$distribution_id} AND status='in_transit' AND id IN (" . implode(',', $ids) . ")");
    }
    mysqli_query($conn, "UPDATE product_serials SET status='damaged', branch_id={$branch_id} WHERE distribution_id={$distribution_id} AND status='in_transit'");
}

// Receive page: serials still on the way, by transfer row: [distribution_id => [id => serial]].
function product_serial_in_transit($conn, $user_id, array $distribution_ids)
{
    $list = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $distribution_ids))));
    if (!$ids || !product_serial_feature_enabled()) {
        return $list;
    }
    $table = mysqli_query($conn, "SHOW TABLES LIKE 'product_serials'");
    if (!$table || mysqli_num_rows($table) === 0) {
        return $list;
    }
    $result = mysqli_query($conn, 'SELECT id, distribution_id, serial_no FROM product_serials WHERE user_id=' . (int)$user_id . " AND status='in_transit' AND distribution_id IN (" . implode(',', $ids) . ') ORDER BY id');
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $list[(int)$row['distribution_id']][(int)$row['id']] = (string)$row['serial_no'];
    }
    return $list;
}

// Receive check: ticked serials must be of this transfer, no more than the good quantity,
// and the unticked ones no more than the damaged quantity.
function product_serial_check_receipt(array $serials, $selected, $sent_qty, $received_qty, $label)
{
    $selected = array_values(array_unique(array_map('intval', is_array($selected) ? $selected : [])));
    foreach ($selected as $sid) {
        if (!isset($serials[$sid])) {
            throw new RuntimeException($label . ': a ticked serial is not part of this transfer. Reload the page.');
        }
    }
    $ticked = count($selected);
    $received_qty = (int)$received_qty;
    $damaged_qty = max(0, (int)$sent_qty - $received_qty);
    if ($ticked > $received_qty || count($serials) - $ticked > $damaged_qty) {
        $need = max(0, min(count($serials), $received_qty));
        throw new RuntimeException($label . ': good received quantity is ' . $received_qty . ', so tick ' . $need . ' serial' . ($need === 1 ? '' : 's') . ' that arrived good; ' . $ticked . ' ticked.');
    }
    return $selected;
}

/*
 * One invoice line of a serial product: every serial must be an in-stock serial of
 * this product, one per piece (as many as are in stock when fewer). They become sold.
 * Returns the text kept on the invoice line.
 */
function product_serial_invoice_line($conn, $user_id, $invoice_id, $invoice_item_id, $product_id, $variant_name, $qty, $raw)
{
    $raw_text = is_array($raw) ? implode(', ', $raw) : (string)$raw;
    if (!product_serial_feature_enabled() || !product_serial_is_enabled($conn, $user_id, $product_id)) {
        return trim($raw_text);
    }
    $serials = product_serial_parse($raw_text);
    $qty = (int)$qty;
    if ($qty <= 0) {
        return mb_substr(implode(', ', $serials), 0, 2000);
    }
    $name_stmt = mysqli_prepare($conn, 'SELECT product_name FROM products WHERE id=? AND user_id=?');
    $product_id = (int)$product_id;
    $user_id = (int)$user_id;
    mysqli_stmt_bind_param($name_stmt, 'ii', $product_id, $user_id);
    mysqli_stmt_execute($name_stmt);
    $product_name = (string)(mysqli_fetch_row(mysqli_stmt_get_result($name_stmt))[0] ?? 'Product');
    if (count($serials) > $qty) {
        throw new RuntimeException($product_name . ': ' . count($serials) . ' serials entered but quantity is ' . $qty . '. Remove the extra serials or raise the quantity.');
    }
    $count_stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM product_serials WHERE user_id=? AND product_id=? AND status='in_stock'");
    mysqli_stmt_bind_param($count_stmt, 'ii', $user_id, $product_id);
    mysqli_stmt_execute($count_stmt);
    $available = (int)(mysqli_fetch_row(mysqli_stmt_get_result($count_stmt))[0] ?? 0);
    $need = min($qty, $available);
    if (count($serials) < $need) {
        throw new RuntimeException($product_name . ': enter ' . $need . ' serial' . ($need === 1 ? '' : 's') . ' (one per piece); ' . count($serials) . ' entered.');
    }
    $rows = product_serial_rows_by_value($conn, $user_id, $serials, true);
    $stmt = mysqli_prepare($conn, "UPDATE product_serials SET status='sold', invoice_id=?, invoice_item_id=?, sold_at=NOW() WHERE id=? AND status='in_stock'");
    foreach ($serials as $serial) {
        $row = $rows[mb_strtolower($serial)] ?? null;
        if (!$row) {
            throw new RuntimeException('Serial "' . $serial . '" is not in stock for ' . $product_name . '.');
        }
        if ((int)$row['product_id'] !== $product_id) {
            throw new RuntimeException('Serial "' . $serial . '" belongs to another product, not ' . $product_name . '.');
        }
        if ($row['variant_name'] !== '' && (string)$variant_name !== '' && mb_strtolower($row['variant_name']) !== mb_strtolower((string)$variant_name)) {
            throw new RuntimeException('Serial "' . $serial . '" is variant ' . $row['variant_name'] . ', not ' . $variant_name . '.');
        }
        if ($row['status'] === 'sold') {
            throw new RuntimeException('Serial "' . $serial . '" is already sold.');
        }
        if ($row['status'] === 'in_transit') {
            throw new RuntimeException('Serial "' . $serial . '" is on the way to another location; receive it first.');
        }
        if ($row['status'] !== 'in_stock') {
            throw new RuntimeException('Serial "' . $serial . '" is not in stock (' . $row['status'] . ').');
        }
        $serial_id = (int)$row['id'];
        $invoice_id = (int)$invoice_id;
        $invoice_item_id = (int)$invoice_item_id;
        mysqli_stmt_bind_param($stmt, 'iii', $invoice_id, $invoice_item_id, $serial_id);
        mysqli_stmt_execute($stmt);
    }
    return mb_substr(implode(', ', $serials), 0, 2000);
}

// Invoice deleted or re-saved: its serials are back in stock.
function product_serial_release_invoice($conn, $user_id, $invoice_id)
{
    if (!product_serial_feature_enabled()) {
        return;
    }
    $table = mysqli_query($conn, "SHOW TABLES LIKE 'product_serials'");
    if (!$table || mysqli_num_rows($table) === 0) {
        return;
    }
    mysqli_query($conn, "UPDATE product_serials SET status='in_stock', invoice_id=0, invoice_item_id=0, sold_at=NULL WHERE user_id=" . (int)$user_id . ' AND invoice_id=' . (int)$invoice_id . " AND status='sold'");
}

// Scanner: SERIAL => [product_id, variant, serial] for serials in stock.
function product_serial_scan_map($conn, $user_id)
{
    $map = [];
    if (!product_serial_feature_enabled()) {
        return $map;
    }
    $table = mysqli_query($conn, "SHOW TABLES LIKE 'product_serials'");
    if (!$table || mysqli_num_rows($table) === 0) {
        return $map;
    }
    $result = mysqli_query($conn, "SELECT s.product_id, s.variant_name, s.serial_no FROM product_serials s INNER JOIN products p ON p.id=s.product_id AND p.user_id=s.user_id AND p.serial_enabled=1 WHERE s.user_id=" . (int)$user_id . " AND s.status='in_stock'");
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $map[mb_strtoupper(trim($row['serial_no']))] = [(int)$row['product_id'], (string)$row['variant_name'], (string)$row['serial_no']];
    }
    return $map;
}

// Serial entry box: type or scan, Enter adds; each serial shows as a removable tag.
// <div class="ps-box" data-name="field name" data-value="A1\nA2"></div>; window.psBoxInit(root) builds it.
// New-serial pages (opening stock, purchase): serials already used in this company, so the
// box refuses them while typing. $except_source = [source_type, source_id] being edited.
function product_serial_taken_script($conn, $user_id, array $except_source = [])
{
    $taken = [];
    if (product_serial_feature_enabled()) {
        $sql = 'SELECT serial_no FROM product_serials WHERE user_id=' . (int)$user_id;
        if (count($except_source) === 2) {
            $sql .= " AND NOT (source_type='" . mysqli_real_escape_string($conn, (string)$except_source[0]) . "' AND source_id=" . (int)$except_source[1] . ')';
        }
        $result = mysqli_query($conn, $sql);
        while ($result && ($row = mysqli_fetch_row($result))) {
            $taken[mb_strtolower(trim((string)$row[0]))] = 1;
        }
    }
    return '<script>window.psTakenSerials = ' . json_encode((object)$taken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
}

function product_serial_box_script()
{
    static $printed = false;
    if ($printed) {
        return '';
    }
    $printed = true;
    return <<<'HTML'
<style>
.ps-box{border:1px solid #ced4da;border-radius:4px;padding:4px;background:#fff}
.ps-box .ps-tags{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:4px}
.ps-box .ps-tags:empty{display:none}
.ps-box .ps-tag{background:#e9f5ee;border:1px solid #b7dfc6;border-radius:3px;padding:1px 6px;font-size:12px;display:inline-flex;align-items:center;gap:4px}
.ps-box .ps-tag button{border:0;background:none;padding:0;line-height:1;color:#a33;cursor:pointer}
.ps-box .ps-input{border:0;outline:0;width:100%;font-size:13px;padding:2px}
.ps-box .ps-count{font-size:11px;color:#6c757d}
.ps-box .ps-count.bad{color:#c0392b}
.ps-box .ps-msg{font-size:11px;color:#c0392b}
</style>
<script>
(function(){
  function list(box){ return Array.prototype.map.call(box.querySelectorAll('.ps-tag'), function(t){ return t.dataset.serial; }); }
  function sync(box){
    var values = list(box), hidden = box.querySelector('.ps-hidden');
    hidden.value = values.join('\n');
    var count = box.querySelector('.ps-count'), need = box.dataset.need === undefined || box.dataset.need === '' ? null : parseInt(box.dataset.need, 10);
    count.textContent = values.length + (need === null ? '' : ' / ' + need) + ' serial';
    count.classList.toggle('bad', need !== null && values.length !== need);
    box.dispatchEvent(new CustomEvent('ps:change', {bubbles: true}));
  }
  function add(box, value){
    value = String(value || '').replace(/\s+/g, ' ').trim();
    var msg = box.querySelector('.ps-msg'); msg.textContent = '';
    if (!value) return false;
    // Never more serials than the quantity.
    var need = box.dataset.need === undefined || box.dataset.need === '' ? null : parseInt(box.dataset.need, 10);
    if (need !== null && list(box).length >= need) { msg.textContent = 'Quantity is ' + need + ' — no more serials can be added.'; return false; }
    var lower = value.toLowerCase();
    var all = document.querySelectorAll('.ps-box .ps-tag');
    for (var i = 0; i < all.length; i++) { if (all[i].dataset.serial.toLowerCase() === lower) { msg.textContent = '"' + value + '" is already entered.'; return false; } }
    // New stock: a serial used before (earlier purchase / opening stock) cannot be entered again.
    if (box.dataset.optional !== '1' && window.psTakenSerials && window.psTakenSerials[lower]) { msg.textContent = '"' + value + '" already exists — each serial can be entered only once.'; return false; }
    var tag = document.createElement('span'); tag.className = 'ps-tag'; tag.dataset.serial = value;
    tag.appendChild(document.createTextNode(value));
    var x = document.createElement('button'); x.type = 'button'; x.innerHTML = '&times;'; x.title = 'Remove';
    x.addEventListener('click', function(){ tag.remove(); sync(box); });
    tag.appendChild(x); box.querySelector('.ps-tags').appendChild(tag); sync(box); return true;
  }
  window.psBoxInit = function(root){
    (root || document).querySelectorAll('.ps-box:not([data-ready])').forEach(function(box){
      box.dataset.ready = '1';
      box.innerHTML = '<div class="ps-tags"></div><input type="text" class="ps-input" placeholder="Serial / IMEI" autocomplete="off"><input type="hidden" class="ps-hidden"><div class="d-flex justify-content-between"><span class="ps-count"></span><span class="ps-msg"></span></div>';
      box.querySelector('.ps-hidden').name = box.dataset.name || '';
      var input = box.querySelector('.ps-input');
      input.addEventListener('keydown', function(e){
        if (e.key === 'Enter' || e.key === 'Tab' && input.value.trim() !== '') { e.preventDefault(); e.stopPropagation(); if (add(box, input.value)) input.value = ''; }
      });
      input.addEventListener('paste', function(){ setTimeout(function(){ var parts = input.value.split(/[\r\n,]+/); if (parts.length > 1) { parts.forEach(function(p){ add(box, p); }); input.value = ''; } }, 0); });
      String(box.dataset.value || '').split(/[\r\n,]+/).forEach(function(v){ if (v.trim()) add(box, v); });
      sync(box);
    });
  };
  window.psBoxSetNeed = function(box, need){ box.dataset.need = need === null ? '' : String(need); if (box.querySelector('.ps-hidden')) sync(box); };
  window.psBoxClear = function(box){ box.querySelectorAll('.ps-tag').forEach(function(t){ t.remove(); }); if (box.querySelector('.ps-hidden')) sync(box); };
  // Before a form is sent: a serial still typed in a box is added, and every visible box
  // must hold exactly as many serials as its quantity (checked here so nothing typed is lost).
  document.addEventListener('submit', function(e){
    var form = e.target, bad = null;
    form.querySelectorAll('.ps-box[data-ready]').forEach(function(box){
      var input = box.querySelector('.ps-input');
      if (input && input.value.trim() !== '') { if (add(box, input.value)) input.value = ''; }
      if (box.offsetParent === null || box.dataset.optional === '1') return;
      var need = box.dataset.need === undefined || box.dataset.need === '' ? null : parseInt(box.dataset.need, 10);
      if (need !== null && list(box).length !== need && !bad) bad = {box: box, need: need, have: list(box).length};
    });
    if (bad) {
      e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
      bad.box.querySelector('.ps-msg').textContent = 'Enter ' + bad.need + ' serial' + (bad.need === 1 ? '' : 's') + ' (one per piece); ' + bad.have + ' entered.';
      bad.box.scrollIntoView({behavior: 'smooth', block: 'center'});
      var input = bad.box.querySelector('.ps-input'); if (input) input.focus();
    }
  }, true);
  document.addEventListener('DOMContentLoaded', function(){ window.psBoxInit(document); });
})();
</script>
HTML;
}
