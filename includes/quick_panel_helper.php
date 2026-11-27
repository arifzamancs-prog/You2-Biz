<?php
// Quick Panel: the auto-hide shortcut panel on the right edge of every page.
// Each login (administrator or staff) keeps its own tile list; the instant search
// finds invoices of every kind, product codes/barcodes and serial numbers.

require_once __DIR__ . '/branch_context_helper.php';

const QUICK_PANEL_MAX_ITEMS = 24;

function quick_panel_enabled()
{
    return !is_super_admin_user() && (int)($_SESSION['user_id'] ?? 0) > 0;
}

function quick_panel_schema($conn)
{
    static $ready = false;
    if ($ready) return true;
    $ready = (bool)mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS quick_panel_layouts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            login_user_id INT NOT NULL,
            items TEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_quick_panel_login (company_id, login_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    return $ready;
}

function quick_panel_owner()
{
    return [(int)($_SESSION['user_id'] ?? 0), (int)($_SESSION['login_user_id'] ?? 0)];
}

// The saved tiles, or null when this login never customised its panel (defaults apply).
function quick_panel_load($conn)
{
    if (!quick_panel_schema($conn)) return null;
    [$company_id, $login_id] = quick_panel_owner();
    $stmt = mysqli_prepare($conn, 'SELECT items FROM quick_panel_layouts WHERE company_id=? AND login_user_id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $company_id, $login_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row) return null;
    $items = json_decode((string)$row['items'], true);
    return is_array($items) ? quick_panel_clean_items($items) : null;
}

// Tiles are links inside the application only: a path relative to the app root,
// its label, a Font Awesome icon and a colour.
function quick_panel_clean_items($items)
{
    $clean = [];
    $seen = [];
    foreach ((array)$items as $item) {
        if (!is_array($item)) continue;
        $path = ltrim(trim((string)($item['path'] ?? '')), '/');
        if ($path === '' || !preg_match('#^[A-Za-z0-9_\-/.]+\.php(\?[A-Za-z0-9_\-=&%.]*)?$#', $path) || str_contains($path, '..')) continue;
        if (isset($seen[$path])) continue;
        $seen[$path] = true;
        $label = trim(preg_replace('/\s+/u', ' ', (string)($item['label'] ?? '')));
        $icon = preg_match('/^(fas|far|fab) fa-[a-z0-9-]+$/', (string)($item['icon'] ?? '')) ? (string)$item['icon'] : 'fas fa-link';
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($item['color'] ?? '')) ? (string)$item['color'] : '#1677e8';
        $clean[] = [
            'path' => $path,
            'label' => mb_substr($label !== '' ? $label : basename(parse_url($path, PHP_URL_PATH), '.php'), 0, 40),
            'icon' => $icon,
            'color' => $color,
        ];
        if (count($clean) >= QUICK_PANEL_MAX_ITEMS) break;
    }
    return $clean;
}

function quick_panel_save($conn, $items)
{
    if (!quick_panel_schema($conn)) return false;
    [$company_id, $login_id] = quick_panel_owner();
    $json = json_encode(quick_panel_clean_items($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = mysqli_prepare($conn, 'INSERT INTO quick_panel_layouts(company_id, login_user_id, items) VALUES(?,?,?) ON DUPLICATE KEY UPDATE items=VALUES(items)');
    mysqli_stmt_bind_param($stmt, 'iis', $company_id, $login_id, $json);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function quick_panel_reset($conn)
{
    if (!quick_panel_schema($conn)) return false;
    [$company_id, $login_id] = quick_panel_owner();
    $stmt = mysqli_prepare($conn, 'DELETE FROM quick_panel_layouts WHERE company_id=? AND login_user_id=?');
    mysqli_stmt_bind_param($stmt, 'ii', $company_id, $login_id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function quick_panel_table_exists($conn, $table)
{
    static $tables = null;
    if ($tables === null) {
        $tables = [];
        $result = mysqli_query($conn, 'SHOW TABLES');
        while ($result && ($row = mysqli_fetch_row($result))) $tables[strtolower($row[0])] = true;
    }
    return isset($tables[strtolower($table)]);
}

function quick_panel_rows($conn, $sql, $types, $params)
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}

function quick_panel_money($value)
{
    return number_format((float)$value, 2);
}

function quick_panel_date($value)
{
    $time = strtotime((string)$value);
    return $time ? date('d M Y', $time) : '';
}

// Instant search. Every source is checked against the page it links to, so a staff
// account only sees what it could open, and records follow the selected branch.
function quick_panel_search($conn, $query)
{
    // Bangla digits typed in the search box match the stored English digits.
    $query = strtr(trim((string)$query), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
    $query = mb_substr($query, 0, 60);
    if (mb_strlen($query) < 2) return [];
    $company_id = (int)($_SESSION['user_id'] ?? 0);
    $like = '%' . addcslashes($query, '%_\\') . '%';
    $limit = 6;
    $results = [];
    $add = function ($type, $title, $subtitle, $path, $icon, $color, $exact) use (&$results) {
        $results[] = [
            'type' => $type,
            'title' => (string)$title,
            'subtitle' => trim((string)$subtitle, " ·"),
            'url' => app_path($path),
            'icon' => $icon,
            'color' => $color,
            'exact' => $exact ? 1 : 0,
        ];
    };
    $same = function ($value) use ($query) {
        return strcasecmp(trim((string)$value), $query) === 0;
    };
    $sources = [
        // Sales invoices (and their Sales Return credit notes).
        'invoices' => function () use ($conn, $company_id, $like, $query, $limit, $add, $same) {
            if (!current_user_can_open_path('sales/view_invoice.php')) return;
            $scope = branch_scope_sql($conn, 'i');
            $rows = quick_panel_rows($conn,
                "SELECT i.id, i.invoice_no, i.customer_name, i.invoice_date, i.total_amount, i.due_amount, i.created_by_type
                 FROM invoices i WHERE i.user_id=? {$scope} AND i.invoice_no LIKE ?
                 ORDER BY (i.invoice_no=?) DESC, i.id DESC LIMIT {$limit}",
                'iss', [$company_id, $like, $query]);
            foreach ($rows as $row) {
                $return = ($row['created_by_type'] ?? '') === 'sales_refund';
                $add($return ? 'Sales Return' : 'Sales Invoice', $row['invoice_no'],
                    trim(($row['customer_name'] ?: 'Walk-in') . ' · ' . quick_panel_date($row['invoice_date']) . ' · ' . quick_panel_money($row['total_amount'])
                        . ((float)$row['due_amount'] > 0 ? ' · Due ' . quick_panel_money($row['due_amount']) : '')),
                    'sales/view_invoice.php?id=' . (int)$row['id'], $return ? 'fas fa-undo-alt' : 'fas fa-file-invoice', $return ? '#e8590c' : '#d6336c', $same($row['invoice_no']));
            }
        },
        // Invoices of the service / project modules.
        'booking_invoices' => function () use ($conn, $company_id, $like, $query, $limit, $add, $same) {
            if (!current_user_can_open_path('create_invoice/print.php')) return;
            $scope = branch_scope_sql($conn, 'bi');
            $rows = quick_panel_rows($conn,
                "SELECT bi.id, bi.invoice_no, bi.invoice_date, bi.amount, bi.status, c.customer_name
                 FROM booking_invoices bi LEFT JOIN customers c ON c.id=bi.customer_id AND c.user_id=bi.user_id
                 WHERE bi.user_id=? {$scope} AND bi.invoice_no LIKE ?
                 ORDER BY (bi.invoice_no=?) DESC, bi.id DESC LIMIT {$limit}",
                'iss', [$company_id, $like, $query]);
            foreach ($rows as $row) {
                $add('Invoice', $row['invoice_no'],
                    ($row['customer_name'] ?: '') . ' · ' . quick_panel_date($row['invoice_date']) . ' · ' . quick_panel_money($row['amount']) . ' · ' . ucfirst((string)$row['status']),
                    'create_invoice/print.php?id=' . (int)$row['id'], 'fas fa-file-invoice-dollar', '#d6336c', $same($row['invoice_no']));
            }
        },
        // Money receipts of customer payments.
        'transactions' => function () use ($conn, $company_id, $like, $query, $limit, $add, $same) {
            if (!current_user_can_open_path('sales/print_money_receipt.php')) return;
            $scope = branch_scope_sql($conn, 't');
            $rows = quick_panel_rows($conn,
                "SELECT t.txn_no, t.amount, t.txn_date FROM transactions t
                 WHERE t.user_id=? {$scope} AND t.transaction_type='receive_payment' AND t.amount>0 AND t.txn_no LIKE ?
                 ORDER BY (t.txn_no=?) DESC, t.id DESC LIMIT {$limit}",
                'iss', [$company_id, $like, $query]);
            foreach ($rows as $row) {
                $add('Money Receipt', $row['txn_no'], quick_panel_date($row['txn_date']) . ' · ' . quick_panel_money($row['amount']),
                    'sales/print_money_receipt.php?no=' . rawurlencode($row['txn_no']), 'fas fa-receipt', '#2b8a3e', $same($row['txn_no']));
            }
        },
        'purchases' => function () use ($conn, $company_id, $like, $query, $limit, $add, $same) {
            if (!current_user_can_open_path('purchases/view.php')) return;
            $scope = branch_scope_sql($conn, 'p');
            $rows = quick_panel_rows($conn,
                "SELECT p.id, p.purchase_no, p.purchase_date, p.total_amount, p.due_amount, s.supplier_name
                 FROM purchases p LEFT JOIN suppliers s ON s.id=p.supplier_id
                 WHERE p.user_id=? {$scope} AND p.purchase_no LIKE ?
                 ORDER BY (p.purchase_no=?) DESC, p.id DESC LIMIT {$limit}",
                'iss', [$company_id, $like, $query]);
            foreach ($rows as $row) {
                $add('Purchase', $row['purchase_no'],
                    ($row['supplier_name'] ?: '') . ' · ' . quick_panel_date($row['purchase_date']) . ' · ' . quick_panel_money($row['total_amount'])
                        . ((float)$row['due_amount'] > 0 ? ' · Due ' . quick_panel_money($row['due_amount']) : ''),
                    'purchases/view.php?id=' . (int)$row['id'], 'fas fa-shopping-cart', '#e03131', $same($row['purchase_no']));
            }
        },
        'purchase_returns' => function () use ($conn, $company_id, $like, $query, $limit, $add, $same) {
            if (!current_user_can_open_path('purchases/purchase_return_print.php')) return;
            $scope = branch_scope_sql($conn, 'r');
            $rows = quick_panel_rows($conn,
                "SELECT r.id, r.return_no, r.return_date, r.total_amount, s.supplier_name
                 FROM purchase_returns r LEFT JOIN suppliers s ON s.id=r.supplier_id
                 WHERE r.user_id=? {$scope} AND r.return_no LIKE ?
                 ORDER BY (r.return_no=?) DESC, r.id DESC LIMIT {$limit}",
                'iss', [$company_id, $like, $query]);
            foreach ($rows as $row) {
                $add('Purchase Return', $row['return_no'],
                    ($row['supplier_name'] ?: '') . ' · ' . quick_panel_date($row['return_date']) . ' · ' . quick_panel_money($row['total_amount']),
                    'purchases/purchase_return_print.php?id=' . (int)$row['id'], 'fas fa-truck-loading', '#f08c00', $same($row['return_no']));
            }
        },
        'service_jobs' => function () use ($conn, $company_id, $like, $query, $limit, $add, $same) {
            if (!current_user_can_open_path('sales/service_view.php')) return;
            $scope = branch_scope_sql($conn, 'j');
            $rows = quick_panel_rows($conn,
                "SELECT j.id, j.job_no, j.serial_no, j.customer_name, j.item_name, j.status
                 FROM service_jobs j WHERE j.user_id=? {$scope} AND (j.job_no LIKE ? OR j.serial_no LIKE ?)
                 ORDER BY (j.job_no=? OR j.serial_no=?) DESC, j.id DESC LIMIT {$limit}",
                'issss', [$company_id, $like, $like, $query, $query]);
            foreach ($rows as $row) {
                $add('Service Job', $row['job_no'],
                    $row['item_name'] . ($row['serial_no'] !== '' && $row['serial_no'] !== null ? ' · SN ' . $row['serial_no'] : '') . ' · ' . $row['customer_name'] . ' · ' . ucwords(str_replace('_', ' ', (string)$row['status'])),
                    'sales/service_view.php?id=' . (int)$row['id'], 'fas fa-tools', '#0ca678', $same($row['job_no']) || $same($row['serial_no']));
            }
        },
        'warranty_claims' => function () use ($conn, $company_id, $like, $query, $limit, $add, $same) {
            if (!current_user_can_open_path('sales/warranty_view.php')) return;
            $scope = branch_scope_sql($conn, 'w');
            $rows = quick_panel_rows($conn,
                "SELECT w.id, w.claim_no, w.invoice_no, w.serial_no, w.product_name, w.customer_name, w.status
                 FROM warranty_claims w WHERE w.user_id=? {$scope} AND (w.claim_no LIKE ? OR w.serial_no LIKE ?)
                 ORDER BY (w.claim_no=? OR w.serial_no=?) DESC, w.id DESC LIMIT {$limit}",
                'issss', [$company_id, $like, $like, $query, $query]);
            foreach ($rows as $row) {
                $add('Warranty Claim', $row['claim_no'],
                    $row['product_name'] . ($row['serial_no'] ? ' · SN ' . $row['serial_no'] : '') . ' · ' . $row['customer_name'] . ' · ' . ucwords(str_replace('_', ' ', (string)$row['status'])),
                    'sales/warranty_view.php?id=' . (int)$row['id'], 'fas fa-shield-alt', '#1c7ed6', $same($row['claim_no']) || $same($row['serial_no']));
            }
        },
        // Travel bookings: booking no, PNR, visa application no, ticket and passport numbers.
        'travel_bookings' => function () use ($conn, $company_id, $like, $query, $limit, $add, $same) {
            if (!current_user_can_open_path('travel/booking_view.php') || !manager_has_permission('sales')) return;
            if (!function_exists('travel_agency_active')) require_once __DIR__ . '/travel_agency_helper.php';
            if (!travel_agency_active()) return;
            $scope = branch_scope_sql($conn, 'b');
            $passenger_match = quick_panel_table_exists($conn, 'travel_booking_passengers')
                ? ' OR EXISTS (SELECT 1 FROM travel_booking_passengers tp WHERE tp.booking_id=b.id AND (tp.ticket_no LIKE ? OR tp.passport_no LIKE ?))'
                : '';
            $types = 'issss' . ($passenger_match !== '' ? 'ss' : '') . 'sss';
            $params = [$company_id, $like, $like, $like, $like];
            if ($passenger_match !== '') array_push($params, $like, $like);
            array_push($params, $query, $query, $query);
            $rows = quick_panel_rows($conn,
                "SELECT b.id, b.booking_no, b.pnr, b.application_no, b.service_kind, b.booking_date, b.price, b.route, c.customer_name
                 FROM travel_bookings b LEFT JOIN customers c ON c.id=b.client_id AND c.user_id=b.user_id
                 WHERE b.user_id=? {$scope} AND (b.booking_no LIKE ? OR b.pnr LIKE ? OR b.application_no LIKE ? OR b.route LIKE ?{$passenger_match})
                 ORDER BY (b.booking_no=? OR b.pnr=? OR b.application_no=?) DESC, b.id DESC LIMIT {$limit}",
                $types, $params);
            foreach ($rows as $row) {
                $extra = $row['pnr'] ? 'PNR ' . $row['pnr'] : ($row['application_no'] ? 'App ' . $row['application_no'] : (string)$row['route']);
                $add('Booking', $row['booking_no'],
                    ucwords(str_replace('_', ' ', (string)$row['service_kind'])) . ' · ' . ($row['customer_name'] ?: '') . ' · ' . $extra . ' · ' . quick_panel_date($row['booking_date']),
                    'travel/booking_view.php?id=' . (int)$row['id'], 'fas fa-plane', '#7048e8', $same($row['booking_no']) || $same($row['pnr']) || $same($row['application_no']));
            }
        },
        'eshop_orders' => function () use ($conn, $company_id, $like, $query, $limit, $add, $same) {
            if (!current_user_can_open_path('sales/view_invoice.php')) return;
            $rows = quick_panel_rows($conn,
                "SELECT o.order_no, o.invoice_id, o.customer_name, o.total, o.created_at
                 FROM eshop_orders o WHERE o.company_id=? AND o.invoice_id>0 AND o.order_no LIKE ?
                 ORDER BY (o.order_no=?) DESC, o.id DESC LIMIT {$limit}",
                'iss', [$company_id, $like, $query]);
            foreach ($rows as $row) {
                $add('E-shop Order', $row['order_no'], $row['customer_name'] . ' · ' . quick_panel_date($row['created_at']) . ' · ' . quick_panel_money($row['total']),
                    'sales/view_invoice.php?id=' . (int)$row['invoice_id'], 'fas fa-store', '#ae3ec9', $same($row['order_no']));
            }
        },
        // Products by code (SKU), barcode or name.
        'products' => function () use ($conn, $company_id, $like, $query, $limit, $add, $same) {
            if (!current_user_can_open_path('products/edit.php')) return;
            $barcode_match = quick_panel_table_exists($conn, 'product_barcodes')
                ? ' OR EXISTS (SELECT 1 FROM product_barcodes pb WHERE pb.product_id=p.id AND pb.user_id=p.user_id AND pb.barcode LIKE ?)'
                : '';
            $types = 'iss' . ($barcode_match !== '' ? 's' : '') . 's';
            $params = [$company_id, $like, $like];
            if ($barcode_match !== '') $params[] = $like;
            $params[] = $query;
            $rows = quick_panel_rows($conn,
                "SELECT p.id, p.product_name, p.sku, p.sale_price, p.current_stock
                 FROM products p WHERE p.user_id=? AND (p.sku LIKE ? OR p.product_name LIKE ?{$barcode_match})
                 ORDER BY (p.sku=?) DESC, p.product_name LIMIT {$limit}",
                $types, $params);
            foreach ($rows as $row) {
                $add('Product', $row['product_name'],
                    ($row['sku'] ? 'Code ' . $row['sku'] . ' · ' : '') . 'Price ' . quick_panel_money($row['sale_price']) . ' · Stock ' . rtrim(rtrim(number_format((float)$row['current_stock'], 2, '.', ''), '0'), '.'),
                    'products/edit.php?id=' . (int)$row['id'], 'fas fa-box-open', '#f59f00', $same($row['sku']));
            }
        },
        // Serial numbers: where the unit is now and the invoice that sold it.
        'product_serials' => function () use ($conn, $company_id, $like, $query, $limit, $add, $same) {
            $can_invoice = current_user_can_open_path('sales/view_invoice.php');
            $can_product = current_user_can_open_path('products/edit.php');
            if (!$can_invoice && !$can_product) return;
            $rows = quick_panel_rows($conn,
                "SELECT s.serial_no, s.status, s.invoice_id, s.product_id, s.variant_name, p.product_name, i.invoice_no, i.branch_id AS invoice_branch
                 FROM product_serials s
                 LEFT JOIN products p ON p.id=s.product_id AND p.user_id=s.user_id
                 LEFT JOIN invoices i ON i.id=s.invoice_id AND i.user_id=s.user_id
                 WHERE s.user_id=? AND s.serial_no LIKE ?
                 ORDER BY (s.serial_no=?) DESC, s.id DESC LIMIT {$limit}",
                'iss', [$company_id, $like, $query]);
            $branch_id = selected_branch_id($conn, false);
            foreach ($rows as $row) {
                $sold = (int)$row['invoice_id'] > 0 && $row['invoice_no'] !== null && ($branch_id <= 0 || (int)$row['invoice_branch'] === $branch_id);
                if ($sold && $can_invoice) $path = 'sales/view_invoice.php?id=' . (int)$row['invoice_id'];
                elseif ($can_product) $path = 'products/edit.php?id=' . (int)$row['product_id'];
                else continue;
                $product = ($row['product_name'] ?: 'Product') . ($row['variant_name'] !== '' && $row['variant_name'] !== null && $row['variant_name'] !== '0' ? ' (' . $row['variant_name'] . ')' : '');
                $add('Serial', $row['serial_no'],
                    $product . ' · ' . ucwords(str_replace('_', ' ', (string)$row['status'])) . ($sold ? ' · Invoice ' . $row['invoice_no'] : ''),
                    $path, 'fas fa-barcode', '#495057', $same($row['serial_no']));
            }
        },
        // Serials typed on invoice lines (products without serial tracking).
        'invoice_items' => function () use ($conn, $company_id, $like, $query, $limit, $add, $same) {
            if (!current_user_can_open_path('sales/view_invoice.php')) return;
            $scope = branch_scope_sql($conn, 'i');
            $rows = quick_panel_rows($conn,
                "SELECT ii.serial_no, i.id, i.invoice_no, i.invoice_date, p.product_name
                 FROM invoice_items ii
                 JOIN invoices i ON i.id=ii.invoice_id AND i.user_id=?
                 LEFT JOIN products p ON p.id=ii.product_id AND p.user_id=i.user_id
                 WHERE ii.serial_no LIKE ? {$scope}
                 ORDER BY (ii.serial_no=?) DESC, ii.id DESC LIMIT {$limit}",
                'iss', [$company_id, $like, $query]);
            foreach ($rows as $row) {
                $add('Serial', $row['serial_no'], ($row['product_name'] ?: 'Product') . ' · Invoice ' . $row['invoice_no'] . ' · ' . quick_panel_date($row['invoice_date']),
                    'sales/view_invoice.php?id=' . (int)$row['id'], 'fas fa-barcode', '#495057', $same($row['serial_no']));
            }
        },
    ];

    foreach ($sources as $table => $source) {
        if (!quick_panel_table_exists($conn, $table)) continue;
        try {
            $source();
        } catch (Throwable $e) {
            error_log('Quick panel search (' . $table . '): ' . $e->getMessage());
        }
    }

    // Exact matches first; the same link found twice (e.g. a serial in two places) is shown once.
    $unique = [];
    foreach ($results as $index => $result) {
        $key = $result['url'] . '|' . strtolower($result['title']);
        if (!isset($unique[$key])) $unique[$key] = $result + ['order' => $index];
    }
    $results = array_values($unique);
    usort($results, function ($a, $b) {
        return [$b['exact'], $a['order']] <=> [$a['exact'], $b['order']];
    });
    return array_slice(array_map(function ($row) { unset($row['order']); return $row; }, $results), 0, 30);
}
