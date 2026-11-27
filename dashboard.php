<?php

require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/wallet_helper.php';
require_once 'includes/invoice_posting_helper.php';
require_once 'includes/customer_opening_due_helper.php';
require_once 'includes/customer_due_allocation_helper.php';
require_once 'includes/booking_invoice_helper.php';
require_once 'includes/branch_context_helper.php';
require_once 'includes/fifo_inventory_helper.php';
require_once 'includes/supplier_label_helper.php';

$user_id = (int)$_SESSION['user_id'];
$user_name = $_SESSION['login_name'] ?? $_SESSION['user_name'] ?? 'User';
$dashboard_is_agent = is_agent_user();
$dashboard_is_staff_login = is_manager_user();
ensure_branch_accounting_columns($conn, $user_id);
$wallet_scope = branch_scope_sql($conn);
$booking_scope = branch_scope_sql($conn, 'bi');
$expense_scope = branch_scope_sql($conn);
$invoice_scope = branch_scope_sql($conn);
$invoice_scope_i = branch_scope_sql($conn, 'i');
$purchase_scope = branch_scope_sql($conn);
$transaction_scope = branch_scope_sql($conn, 't');
$dashboard_uses_sales_invoices = sales_module_enabled();
$dashboard_invoice_list_url = $dashboard_uses_sales_invoices ? 'sales/invoice_list.php' : 'create_invoice/invoice_list.php';
$dashboard_invoice_print_url = $dashboard_uses_sales_invoices ? 'sales/print_invoice.php' : 'create_invoice/print.php';

if($dashboard_is_staff_login && !manager_has_permission('dashboard')){
    require_once 'staff_dashboard.php';
    exit;
}

ensure_default_cash_wallet($conn, $user_id);
ensure_invoice_posting_columns($conn);
ensure_customer_opening_due_tables($conn);
ensure_booking_invoice_table($conn);
ensure_booking_invoice_type_table($conn, $user_id);

function dashboard_value($conn, $sql, $types = '', $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if($types != ''){
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);

    return $row ? reset($row) : 0;
}

function dashboard_rows($conn, $sql, $types = '', $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if($types != ''){
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $rows = [];

    while($row = mysqli_fetch_assoc($result)){
        $rows[] = $row;
    }

    return $rows;
}

function dashboard_resolve_transaction_type_label($conn, $transaction, $user_id)
{
    $transaction_type = trim((string)($transaction['transaction_type'] ?? ''));

    $transaction_labels = [
        'money_in' => 'Money In',
        'expense' => 'Expense',
        'transfer' => 'Transfer',
        'transfer_in' => 'Transfer In',
        'transfer_out' => 'Transfer Out',
        'sales_invoice' => 'Sales Invoice',
        'receive_payment' => 'Receive Due Payment',
        'purchase_return' => 'Vendor Return Refund',
        'travel_payment' => 'Client Payment',
        'travel_refund' => 'Client Refund',
        'travel_commission' => 'Agent Commission',
        'service_advance' => 'Service Advance',
        'service_refund' => 'Service Refund',
        'purchase' => 'Purchase',
        'supplier_payment' => supplier_display_text('Supplier Due Payment'),
        'invoice_income' => 'Booking Invoice',
        'invoice_expense' => 'Invoice Refund',
        'profit_cash_out' => 'Profit Cash Out',
        'staff_payment' => 'Staff Payment',
    ];

    if($transaction_type !== ''){
        return $transaction_labels[$transaction_type] ?? ucwords(str_replace('_', ' ', $transaction_type));
    }

    $note = strtolower(trim((string)($transaction['note'] ?? '')));
    $reference_id = (int)($transaction['reference_id'] ?? 0);

    if($note !== ''){
        if(strpos($note, 'supplier due payment') !== false || strpos($note, 'supplier payment') !== false){
            return supplier_display_text('Supplier Due Payment');
        }

        if(strpos($note, 'purchase') !== false){
            return 'Purchase';
        }

        if(strpos($note, 'profit cash out') !== false){
            return 'Profit Cash Out';
        }

        if(strpos($note, 'salary') !== false || strpos($note, 'bonus') !== false || strpos($note, 'incentive') !== false){
            return 'Staff Payment';
        }

        if(strpos($note, 'invoice') !== false){
            return 'Booking Invoice';
        }

        if(strpos($note, 'expense') !== false){
            return 'Expense';
        }

        if(strpos($note, 'money in') !== false){
            return 'Money In';
        }
    }

    if($reference_id > 0){
        $checks = [
            'transfer' => 'Transfer',
            'money_ins' => 'Money In',
            'expenses' => 'Expense',
            'supplier_payments' => supplier_display_text('Supplier Due Payment'),
            'purchases' => 'Purchase',
            'booking_invoices' => 'Booking Invoice',
            'profit_cash_outs' => 'Profit Cash Out',
            'staff_ledger_entries' => 'Staff Payment',
        ];

        foreach($checks as $table => $label){
            $stmt = mysqli_prepare($conn, "SELECT id FROM `{$table}` WHERE id=? AND user_id=? LIMIT 1");

            if(!$stmt){
                continue;
            }

            mysqli_stmt_bind_param($stmt, 'ii', $reference_id, $user_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            if($result && mysqli_num_rows($result) > 0){
                return $label;
            }
        }
    }

    return 'Related Entry';
}

$total_wallet_balance = dashboard_value(
    $conn,
    "SELECT COALESCE(SUM(balance),0)
     FROM wallets
     WHERE user_id=?
     {$wallet_scope}
     AND status='active'",
    "i",
    [$user_id]
);

if ($dashboard_uses_sales_invoices) {
    $total_sales = dashboard_value(
        $conn,
        "SELECT COALESCE(SUM(total_amount),0)
         FROM invoices
         WHERE user_id=?
         {$invoice_scope}
         AND accounting_status='posted'
         AND invoice_date = CURDATE()",
        "i",
        [$user_id]
    );
} else {
    $total_sales = dashboard_value(
        $conn,
        "SELECT COALESCE(SUM(bi.amount),0)
         FROM booking_invoices bi
         LEFT JOIN booking_invoice_types bit
            ON bit.user_id=bi.user_id
            AND bit.type_key=bi.invoice_type
         WHERE bi.user_id=?
         {$booking_scope}
         AND bi.status='confirmed'
         AND COALESCE(bit.behavior,'income')='income'
         AND bi.invoice_date = CURDATE()",
        "i",
        [$user_id]
    );
}

$total_purchases = dashboard_value(
    $conn,
    "SELECT COALESCE(SUM(total_amount),0)
     FROM purchases
     WHERE user_id=?
     {$purchase_scope}
     AND purchase_date = CURDATE()",
    "i",
    [$user_id]
);

$total_expense = dashboard_value(
    $conn,
    "SELECT COALESCE(SUM(amount),0)
     FROM expenses
     WHERE user_id=?
     {$expense_scope}
     AND approval_status='approved'",
    "i",
    [$user_id]
);

$customer_due = customer_due_report_total($conn, $user_id);

$supplier_due = dashboard_value(
    $conn,
    "SELECT COALESCE(SUM(due_amount),0)
     FROM purchases
     WHERE user_id=?
     {$purchase_scope}",
    "i",
    [$user_id]
);

$month_sales = dashboard_value(
    $conn,
    "SELECT COALESCE(SUM(total_amount),0)
     FROM invoices
     WHERE user_id=?
     {$invoice_scope}
     AND accounting_status='posted'
     AND MONTH(invoice_date)=MONTH(CURDATE())
     AND YEAR(invoice_date)=YEAR(CURDATE())",
    "i",
    [$user_id]
);

$month_expense = dashboard_value(
    $conn,
    "SELECT COALESCE(SUM(amount),0)
     FROM expenses
     WHERE user_id=?
     {$expense_scope}
     AND approval_status='approved'
     AND MONTH(txn_date)=MONTH(CURDATE())
     AND YEAR(txn_date)=YEAR(CURDATE())",
    "i",
    [$user_id]
);

$active_wallets = dashboard_value(
    $conn,
    "SELECT COUNT(*)
     FROM wallets
     WHERE user_id=?
     {$wallet_scope}
     AND status='active'",
    "i",
    [$user_id]
);

$low_stock = dashboard_value(
    $conn,
    "SELECT COUNT(*)
     FROM products p
     INNER JOIN product_categories pc ON pc.id=p.category_id AND pc.category_type='stock_product'
     WHERE p.user_id=?
     AND p.status='active'
     AND " . product_live_stock_sql('p') . " <= p.minimum_stock",
    "i",
    [$user_id]
);

$total_products = dashboard_value(
    $conn,
    "SELECT COUNT(*)
     FROM products
     WHERE user_id=?",
    "i",
    [$user_id]
);

$today_expense = dashboard_value(
    $conn,
    "SELECT COALESCE(SUM(amount),0)
     FROM expenses
     WHERE user_id=?
     {$expense_scope}
     AND approval_status='approved'
     AND txn_date = CURDATE()",
    "i",
    [$user_id]
);

if ($dashboard_uses_sales_invoices) {
    $recent_invoices = dashboard_rows(
        $conn,
        "SELECT i.id, i.invoice_no, i.invoice_date, i.total_amount AS amount,
                CASE WHEN i.accounting_status='posted' THEN 'confirmed' ELSE 'pending' END AS status,
                COALESCE(NULLIF(i.customer_name,''), c.customer_name, 'Walk-in Customer') AS customer_name
         FROM invoices i
         LEFT JOIN customers c ON c.id=i.customer_id AND c.user_id=i.user_id
         WHERE i.user_id=?
         {$invoice_scope}
         ORDER BY i.invoice_date DESC, i.id DESC
         LIMIT 6",
        "i",
        [$user_id]
    );
} else {
    $recent_invoices = dashboard_rows(
        $conn,
        "SELECT bi.id, bi.invoice_no, bi.invoice_date, bi.amount, bi.status, c.customer_name
         FROM booking_invoices bi
         LEFT JOIN customers c ON c.id=bi.customer_id
         WHERE bi.user_id=?
         {$booking_scope}
         ORDER BY bi.invoice_date DESC, bi.id DESC
         LIMIT 6",
        "i",
        [$user_id]
    );
}

$low_stock_products = dashboard_rows(
    $conn,
    "SELECT * FROM (SELECT
        p.id,
        p.product_name,
        p.sku,
        " . product_live_stock_sql('p') . " AS current_stock,
        p.minimum_stock
     FROM products p
     INNER JOIN product_categories pc ON pc.id=p.category_id AND pc.category_type='stock_product'
     WHERE p.user_id=?
     AND p.status='active') low
     WHERE low.current_stock <= low.minimum_stock
     ORDER BY low.current_stock ASC, low.product_name ASC
     LIMIT 6",
    "i",
    [$user_id]
);

$top_due_customers = array_slice(customer_due_report_rows($conn, $user_id), 0, 5);

// Top Customers / Top Vendors (all time, by posted sales / purchases) and Top Due
// Vendors (same source as the Supplier Due card). Walk-in sales have no customer.
$top_customers = dashboard_rows(
    $conn,
    "SELECT i.customer_id AS id,
            COALESCE(NULLIF(c.customer_name,''), i.customer_name) AS name,
            c.phone,
            i.total,
            i.cnt
     FROM (
        SELECT customer_id, MAX(customer_name) AS customer_name, SUM(total_amount) AS total, COUNT(*) AS cnt
        FROM invoices
        WHERE user_id=?
        {$invoice_scope}
        AND accounting_status='posted'
        AND customer_id > 0
        GROUP BY customer_id
     ) i
     LEFT JOIN customers c ON c.id = i.customer_id
     ORDER BY i.total DESC
     LIMIT 5",
    "i",
    [$user_id]
);

$top_vendors = dashboard_rows(
    $conn,
    "SELECT s.id, s.supplier_name AS name, s.phone, p.total, p.cnt
     FROM (
        SELECT supplier_id, SUM(total_amount) AS total, COUNT(*) AS cnt
        FROM purchases
        WHERE user_id=?
        {$purchase_scope}
        AND supplier_id > 0
        GROUP BY supplier_id
     ) p
     JOIN suppliers s ON s.id = p.supplier_id
     ORDER BY p.total DESC
     LIMIT 5",
    "i",
    [$user_id]
);

$top_due_vendors = dashboard_rows(
    $conn,
    "SELECT s.id, s.supplier_name AS name, s.phone, p.due, p.cnt
     FROM (
        SELECT supplier_id, SUM(due_amount) AS due, SUM(due_amount > 0) AS cnt
        FROM purchases
        WHERE user_id=?
        {$purchase_scope}
        AND supplier_id > 0
        GROUP BY supplier_id
        HAVING due > 0
     ) p
     JOIN suppliers s ON s.id = p.supplier_id
     ORDER BY p.due DESC
     LIMIT 5",
    "i",
    [$user_id]
);

$wallets = dashboard_rows(
    $conn,
    "SELECT wallet_name, balance
     FROM wallets
     WHERE user_id=?
     {$wallet_scope}
     AND status='active'
     ORDER BY balance DESC
     LIMIT 8",
    "i",
    [$user_id]
);

$recent_transactions = dashboard_rows(
    $conn,
    "SELECT
        t.txn_date,
        t.transaction_type,
        t.reference_id,
        t.amount,
        t.note,
        w.wallet_name,
        fw.wallet_name AS from_wallet,
        tw.wallet_name AS to_wallet
     FROM transactions t
     LEFT JOIN wallets w
        ON w.id = t.wallet_id
     LEFT JOIN transfers tr
        ON tr.id = t.reference_id
        AND t.transaction_type = 'transfer'
     LEFT JOIN wallets fw
        ON fw.id = tr.from_wallet_id
     LEFT JOIN wallets tw
        ON tw.id = tr.to_wallet_id
     WHERE t.user_id=?
     {$transaction_scope}
     ORDER BY t.id DESC
     LIMIT 6",
    "i",
    [$user_id]
);

$wallet_labels = array_map(function($wallet){
    return $wallet['wallet_name'];
}, $wallets);

$wallet_balances = array_map(function($wallet){
    return (float)$wallet['balance'];
}, $wallets);

// Travel Agency: sales come from travel bookings (refunds and reissues on their own dates),
// and the recent list shows bookings.
require_once 'includes/project_package_helper.php';
$dashboard_travel_plane = project_package_company_type($conn, $user_id) === 'Travel Agency';
// Inventory & Sales and Fashion: a second row of cards (dues, low stock, counts) as in You2 Wallet.
$dashboard_stock_cards = in_array(project_package_company_type($conn, $user_id), ['Stock Product', 'Fashion house'], true);
if (project_package_company_type($conn, $user_id) === 'Travel Agency') {
    require_once 'includes/travel_booking_helper.php';
    if (travel_booking_schema($conn)) {
        $total_sales = travel_sales_total($conn, $user_id, date('Y-m-d'), date('Y-m-d'));
        $dashboard_invoice_print_url = 'travel/booking_view.php';
        $recent_invoices = dashboard_rows(
            $conn,
            "SELECT b.id, b.booking_no AS invoice_no, b.booking_date AS invoice_date, " . travel_effective_price_sql() . " AS amount,
                    IF(b.status='active', 'confirmed', b.status) AS status, c.customer_name
             FROM travel_bookings b
             LEFT JOIN customers c ON c.id=b.client_id AND c.user_id=b.user_id
             WHERE b.user_id=?
             ORDER BY b.booking_date DESC, b.id DESC
             LIMIT 6",
            "i",
            [$user_id]
        );
    }
}

// Business chart: daily, weekly, monthly and yearly bars for the selected branch. Sales
// come from the same source as Today's Sale; due bars show what is still unpaid on the
// invoices / purchases dated in that period. Customer due exists only with sales invoices.
$chart_today = new DateTimeImmutable('today');
$chart_from = $chart_today->modify('first day of this month')->modify('-11 months')->format('Y-m-d');
$chart_this_year = (int)$chart_today->format('Y');
$chart_year_from = ($chart_this_year - 4) . '-01-01';
$chart_series = [
    'sales' => ['label' => 'Sales', 'color' => '#28a745'],
    'purchase' => ['label' => 'Purchase', 'color' => '#ffc107'],
    'expense' => ['label' => 'Expense', 'color' => '#dc3545'],
    'customer_due' => ['label' => 'Customer Due', 'color' => '#007bff'],
    'supplier_due' => ['label' => supplier_display_text('Supplier Due'), 'color' => '#6f42c1'],
];
$chart_sources = [
    'purchase' => ["SELECT {period} AS period, COALESCE(SUM(total_amount),0) AS total FROM purchases WHERE user_id=? {$purchase_scope} AND purchase_date >= ? GROUP BY period", 'purchase_date'],
    'expense' => ["SELECT {period} AS period, COALESCE(SUM(amount),0) AS total FROM expenses WHERE user_id=? {$expense_scope} AND approval_status='approved' AND txn_date >= ? GROUP BY period", 'txn_date'],
    'supplier_due' => ["SELECT {period} AS period, COALESCE(SUM(due_amount),0) AS total FROM purchases WHERE user_id=? {$purchase_scope} AND purchase_date >= ? GROUP BY period", 'purchase_date'],
];
$chart_days = [];
$chart_years = [];

if ($dashboard_travel_plane && function_exists('travel_sales_by_period')) {
    $chart_days['sales'] = travel_sales_by_period($conn, $user_id, $chart_from, $chart_today->format('Y-m-d'), 'day');
    $chart_years['sales'] = [];
    foreach (travel_sales_by_period($conn, $user_id, $chart_year_from, $chart_today->format('Y-m-d'), 'year') as $year_key => $amount) {
        $chart_years['sales'][(int)$year_key] = $amount;
    }
} elseif ($dashboard_uses_sales_invoices) {
    $chart_sources['sales'] = ["SELECT {period} AS period, COALESCE(SUM(total_amount),0) AS total FROM invoices WHERE user_id=? {$invoice_scope} AND accounting_status='posted' AND invoice_date >= ? GROUP BY period", 'invoice_date'];
} else {
    $chart_sources['sales'] = ["SELECT {period} AS period, COALESCE(SUM(bi.amount),0) AS total
         FROM booking_invoices bi
         LEFT JOIN booking_invoice_types bit
            ON bit.user_id=bi.user_id
            AND bit.type_key=bi.invoice_type
         WHERE bi.user_id=?
         {$booking_scope}
         AND bi.status='confirmed'
         AND COALESCE(bit.behavior,'income')='income'
         AND bi.invoice_date >= ?
         GROUP BY period", 'bi.invoice_date'];
}

if ($dashboard_uses_sales_invoices && !$dashboard_travel_plane) {
    $chart_sources['customer_due'] = ["SELECT {period} AS period, COALESCE(SUM(due_amount),0) AS total FROM invoices WHERE user_id=? {$invoice_scope} AND accounting_status='posted' AND invoice_date >= ? GROUP BY period", 'invoice_date'];
} else {
    unset($chart_series['customer_due']);
}

// Sales by Staff (AJAX): sales per staff between two dates, for the selected branch (or all).
//   login     – who made the invoice: the staff linked to the login, else the login's name.
//   reference – the staff on the invoice, else the staff who referred its customer.
//   service   – delivered service jobs (service charge + parts) by the job's staff.
$staff_sales_service = false;
if ($dashboard_stock_cards) {
    require_once 'includes/service_warranty_helper.php';
    $staff_sales_service = function_exists('sw_module_active') && sw_module_active($conn);
}
if ($dashboard_stock_cards && isset($_GET['staff_sales_from'], $_GET['staff_sales_to'])) {
    header('Content-Type: application/json; charset=utf-8');
    $staff_from = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$_GET['staff_sales_from']);
    $staff_to = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$_GET['staff_sales_to']);
    if (!$staff_from || !$staff_to || $staff_from > $staff_to) {
        http_response_code(422);
        echo json_encode(['error' => 'Select a valid From and To date.']);
        exit;
    }
    $staff_mode = (string)($_GET['staff_sales_mode'] ?? 'login');
    $staff_range = [$user_id, $staff_from->format('Y-m-d'), $staff_to->format('Y-m-d')];
    if ($staff_mode === 'service' && $staff_sales_service) {
        $job_scope = branch_scope_sql($conn, 'j');
        $staff_rows = dashboard_rows(
            $conn,
            "SELECT COALESCE(NULLIF(s.name, ''), 'Not assigned') AS name, COUNT(*) AS cnt,
                    COALESCE(SUM(j.service_charge + j.parts_total), 0) AS amount
             FROM service_jobs j
             LEFT JOIN staff s ON s.id=j.staff_id AND s.user_id=j.user_id
             WHERE j.user_id=? {$job_scope}
             AND j.status='delivered'
             AND DATE(j.delivered_at) BETWEEN ? AND ?
             GROUP BY name
             ORDER BY amount DESC
             LIMIT 15",
            "iss",
            $staff_range
        );
    } elseif ($staff_mode === 'reference') {
        $ref_column = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'ref_staff_id'");
        $ref_staff = $ref_column && mysqli_num_rows($ref_column) > 0 ? 'IF(COALESCE(i.staff_id,0)>0, i.staff_id, c.ref_staff_id)' : 'i.staff_id';
        $staff_rows = dashboard_rows(
            $conn,
            "SELECT s.name AS name, COUNT(*) AS cnt, COALESCE(SUM(i.total_amount), 0) AS amount
             FROM invoices i
             LEFT JOIN customers c ON c.id=i.customer_id AND c.user_id=i.user_id
             INNER JOIN staff s ON s.id={$ref_staff} AND s.user_id=i.user_id
             WHERE i.user_id=? {$invoice_scope_i}
             AND i.accounting_status='posted'
             AND i.invoice_date BETWEEN ? AND ?
             GROUP BY s.id, s.name
             ORDER BY amount DESC
             LIMIT 15",
            "iss",
            $staff_range
        );
    } else {
        $staff_rows = dashboard_rows(
            $conn,
            "SELECT COALESCE(NULLIF(s.name, ''), NULLIF(i.created_by_name, ''), NULLIF(u.name, ''), 'Unknown') AS name,
                    COUNT(*) AS cnt, COALESCE(SUM(i.total_amount), 0) AS amount
             FROM invoices i
             LEFT JOIN users u ON u.id=i.created_by_user_id
             LEFT JOIN staff s ON s.id=u.staff_id AND s.user_id=i.user_id
             WHERE i.user_id=? {$invoice_scope_i}
             AND i.accounting_status='posted'
             AND i.invoice_date BETWEEN ? AND ?
             GROUP BY name
             ORDER BY amount DESC
             LIMIT 15",
            "iss",
            $staff_range
        );
    }
    echo json_encode(['rows' => array_map(static function ($row) {
        return ['name' => (string)$row['name'], 'count' => (int)$row['cnt'], 'amount' => round((float)$row['amount'], 2)];
    }, $staff_rows)], JSON_UNESCAPED_UNICODE);
    exit;
}

// Best Selling Products (AJAX): top products sold between two dates, by quantity.
if ($dashboard_stock_cards && isset($_GET['best_products_from'], $_GET['best_products_to'])) {
    header('Content-Type: application/json; charset=utf-8');
    $best_from = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$_GET['best_products_from']);
    $best_to = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$_GET['best_products_to']);
    if (!$best_from || !$best_to || $best_from > $best_to) {
        http_response_code(422);
        echo json_encode(['error' => 'Select a valid From and To date.']);
        exit;
    }

    // Returns are negative lines on credit invoices, so the totals are net of returns.
    // Free lines (returnable containers given at no price) are not sales and stay out.
    $best_rows = dashboard_rows(
        $conn,
        "SELECT COALESCE(NULLIF(p.product_name, ''), CONCAT('Product #', ii.product_id)) AS name,
                SUM(ii.quantity) AS qty,
                SUM(ii.total_price) AS amount
         FROM invoice_items ii
         INNER JOIN invoices i ON i.id=ii.invoice_id
         LEFT JOIN products p ON p.id=ii.product_id
         WHERE i.user_id=?
         {$invoice_scope_i}
         AND i.accounting_status='posted'
         AND i.invoice_date BETWEEN ? AND ?
         AND ii.product_id > 0
         AND ii.unit_price <> 0
         GROUP BY ii.product_id, p.product_name
         HAVING qty > 0
         ORDER BY qty DESC, amount DESC
         LIMIT 10",
        "iss",
        [$user_id, $best_from->format('Y-m-d'), $best_to->format('Y-m-d')]
    );

    echo json_encode(['rows' => array_map(function ($row) {
        return ['name' => (string)$row['name'], 'qty' => round((float)$row['qty'], 3), 'amount' => round((float)$row['amount'], 2)];
    }, $best_rows)], JSON_UNESCAPED_UNICODE);
    exit;
}

// Custom date range (AJAX): bars for every day, month or year between the two dates,
// chosen by the length of the range, as JSON in the same shape as one period below.
if (isset($_GET['business_chart_from'], $_GET['business_chart_to'])) {
    header('Content-Type: application/json; charset=utf-8');
    $custom_from = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$_GET['business_chart_from']);
    $custom_to = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$_GET['business_chart_to']);
    if (!$custom_from || !$custom_to || $custom_from > $custom_to) {
        http_response_code(422);
        echo json_encode(['error' => 'Select a valid From and To date.']);
        exit;
    }

    $custom_days = (int)$custom_from->diff($custom_to)->days + 1;
    $custom_group = $custom_days <= 62 ? 'daily' : ($custom_days <= 731 ? 'monthly' : 'yearly');
    $custom_from_sql = $custom_from->format('Y-m-d');
    $custom_to_sql = $custom_to->format('Y-m-d');

    // Day totals of every metric inside the range.
    $custom_totals = [];
    foreach (array_keys($chart_series) as $key) {
        $custom_totals[$key] = [];
        if ($key === 'sales' && $dashboard_travel_plane && function_exists('travel_sales_by_period')) {
            $custom_totals[$key] = travel_sales_by_period($conn, $user_id, $custom_from_sql, $custom_to_sql, 'day');
            continue;
        }
        if (!isset($chart_sources[$key])) continue;
        [$sql, $date_column] = $chart_sources[$key];
        foreach (dashboard_rows($conn, str_replace('{period}', $date_column, $sql), "is", [$user_id, $custom_from_sql]) as $row) {
            if ($row['period'] <= $custom_to_sql) $custom_totals[$key][$row['period']] = (float)$row['total'];
        }
    }

    $custom_buckets = [];
    for ($day = $custom_from; $day <= $custom_to; $day = $day->modify('+1 day')) {
        $bucket = $custom_group === 'daily' ? $day->format('d M') : ($custom_group === 'monthly' ? $day->format('M Y') : $day->format('Y'));
        $custom_buckets[$day->format('Y-m-d')] = $bucket;
    }

    $custom_labels = array_values(array_unique($custom_buckets));
    $custom_index = array_flip($custom_labels);
    $custom_data = [];
    foreach (array_keys($chart_series) as $key) {
        $custom_data[$key] = array_fill(0, count($custom_labels), 0);
        foreach ($custom_totals[$key] as $day => $amount) {
            $day = substr((string)$day, 0, 10);
            if (isset($custom_buckets[$day])) $custom_data[$key][$custom_index[$custom_buckets[$day]]] += (float)$amount;
        }
        $custom_data[$key] = array_map(function ($value) { return round($value, 2); }, $custom_data[$key]);
    }

    echo json_encode(['group' => $custom_group, 'labels' => $custom_labels, 'data' => $custom_data], JSON_UNESCAPED_UNICODE);
    exit;
}

$chart_days = $chart_days ?? [];
$chart_years = $chart_years ?? [];

foreach($chart_series as $key => $series){
    if(!isset($chart_sources[$key])){
        continue;
    }

    [$sql, $date_column] = $chart_sources[$key];
    $chart_days[$key] = [];
    $chart_years[$key] = [];

    foreach(dashboard_rows($conn, str_replace('{period}', $date_column, $sql), "is", [$user_id, $chart_from]) as $row){
        $chart_days[$key][$row['period']] = (float)$row['total'];
    }

    foreach(dashboard_rows($conn, str_replace('{period}', "YEAR({$date_column})", $sql), "is", [$user_id, $chart_year_from]) as $row){
        $chart_years[$key][(int)$row['period']] = (float)$row['total'];
    }
}

// [label, first day, last day] of every bar; weeks run Saturday to Friday.
$chart_ranges = ['daily' => [], 'weekly' => [], 'monthly' => []];

for($i = 29; $i >= 0; $i--){
    $day = $chart_today->modify("-{$i} days");
    $chart_ranges['daily'][] = [($day->format('d M')), $day->format('Y-m-d'), $day->format('Y-m-d')];
}

$week_start = $chart_today->modify('-' . (((int)$chart_today->format('w') + 1) % 7) . ' days');

for($i = 11; $i >= 0; $i--){
    $start = $week_start->modify('-' . ($i * 7) . ' days');
    $chart_ranges['weekly'][] = [($start->format('d M')), $start->format('Y-m-d'), $start->modify('+6 days')->format('Y-m-d')];
}

for($i = 11; $i >= 0; $i--){
    $month = $chart_today->modify('first day of this month')->modify("-{$i} months");
    $chart_ranges['monthly'][] = [($month->format('M Y')), $month->format('Y-m-d'), $month->format('Y-m-t')];
}

$business_chart = ['series' => $chart_series, 'periods' => []];

foreach($chart_ranges as $period => $ranges){
    $business_chart['periods'][$period] = ['labels' => array_column($ranges, 0), 'data' => []];

    foreach(array_keys($chart_series) as $key){
        foreach($ranges as [, $from, $to]){
            $total = 0;

            foreach($chart_days[$key] ?? [] as $day => $amount){
                if($day >= $from && $day <= $to){
                    $total += $amount;
                }
            }

            $business_chart['periods'][$period]['data'][$key][] = round($total, 2);
        }
    }
}

$business_chart['periods']['yearly'] = ['labels' => [], 'data' => []];

for($year = $chart_this_year - 4; $year <= $chart_this_year; $year++){
    $business_chart['periods']['yearly']['labels'][] = ((string)$year);

    foreach(array_keys($chart_series) as $key){
        $business_chart['periods']['yearly']['data'][$key][] = round($chart_years[$key][$year] ?? 0, 2);
    }
}

// Profit Overview (Inventory & Sales, Fashion): Sales, Purchase, Expense, Gross Profit and
// Net Profit per day / week / month / year, worked out as in the Profit Report:
// Gross Profit = sales − purchase price of the goods sold, Net Profit = Gross Profit − expenses
// (supplier / purchase payments are not expenses here; the purchase price already counts them).
$profit_chart = null;
if ($dashboard_stock_cards) {
    $profit_series = [
        'sales' => ['label' => 'Sales', 'color' => '#28a745'],
        'purchase' => ['label' => 'Purchase', 'color' => '#ffc107'],
        'expense' => ['label' => 'Expense', 'color' => '#dc3545'],
        'gross_profit' => ['label' => 'Gross Profit', 'color' => '#17a2b8'],
        'net_profit' => ['label' => 'Net Profit', 'color' => '#6f42c1'],
    ];
    // Day totals (Y-m-d => amount) of sales, cost of the goods sold, purchase and expense.
    $profit_days = static function ($from, $to) use ($conn, $user_id) {
        $invoice_branch = branch_scope_sql($conn, 'i');
        $plain_branch = branch_scope_sql($conn);
        $cost_line = "CASE WHEN ii.cost_amount<>0 THEN ii.cost_amount ELSE ii.quantity*COALESCE(p.purchase_price,0) END";
        $queries = [
            'sales' => "SELECT i.invoice_date AS d, COALESCE(SUM(i.total_amount),0) AS total FROM invoices i WHERE i.user_id=? {$invoice_branch} AND COALESCE(i.accounting_status,'posted')='posted' AND i.invoice_date BETWEEN ? AND ? GROUP BY d",
            'cost' => "SELECT i.invoice_date AS d, COALESCE(SUM({$cost_line}),0) AS total FROM invoice_items ii INNER JOIN invoices i ON i.id=ii.invoice_id LEFT JOIN products p ON p.id=ii.product_id WHERE i.user_id=? {$invoice_branch} AND COALESCE(i.accounting_status,'posted')='posted' AND i.invoice_date BETWEEN ? AND ? GROUP BY d",
            'purchase' => "SELECT purchase_date AS d, COALESCE(SUM(total_amount),0) AS total FROM purchases WHERE user_id=? {$plain_branch} AND purchase_date BETWEEN ? AND ? GROUP BY d",
            'expense' => "SELECT txn_date AS d, COALESCE(SUM(amount),0) AS total FROM expenses WHERE user_id=? {$plain_branch} AND approval_status='approved' AND COALESCE(source_type,'') NOT IN ('purchase_payment','supplier_payment') AND txn_date BETWEEN ? AND ? GROUP BY d",
        ];
        $days = ['sales' => [], 'cost' => [], 'purchase' => [], 'expense' => []];
        foreach ($queries as $key => $sql) {
            foreach (dashboard_rows($conn, $sql, 'iss', [$user_id, $from, $to]) as $row) {
                $days[$key][substr((string)$row['d'], 0, 10)] = (float)$row['total'];
            }
        }
        // Staff salary / ledger payments count as expenses, as in the Profit Report.
        $ledger_table = mysqli_query($conn, "SHOW TABLES LIKE 'staff_ledger_entries'");
        if ($ledger_table && mysqli_num_rows($ledger_table) > 0) {
            foreach (dashboard_rows($conn, "SELECT entry_date AS d, COALESCE(SUM(amount),0) AS total FROM staff_ledger_entries WHERE user_id=? {$plain_branch} AND entry_date BETWEEN ? AND ? GROUP BY d", 'iss', [$user_id, $from, $to]) as $row) {
                $day = substr((string)$row['d'], 0, 10);
                $days['expense'][$day] = ($days['expense'][$day] ?? 0) + (float)$row['total'];
            }
        }
        return $days;
    };
    // One period: [[label, first day, last day], ...] => labels and the five series.
    $profit_period = static function ($days, $ranges) {
        $period = ['labels' => array_column($ranges, 0), 'data' => ['sales' => [], 'purchase' => [], 'expense' => [], 'gross_profit' => [], 'net_profit' => [], 'cost' => []]];
        foreach ($ranges as [, $from, $to]) {
            $sum = ['sales' => 0.0, 'cost' => 0.0, 'purchase' => 0.0, 'expense' => 0.0];
            foreach ($sum as $key => $unused) {
                foreach ($days[$key] as $day => $amount) {
                    if ($day >= $from && $day <= $to) {
                        $sum[$key] += $amount;
                    }
                }
            }
            $gross = $sum['sales'] - $sum['cost'];
            $period['data']['sales'][] = round($sum['sales'], 2);
            $period['data']['purchase'][] = round($sum['purchase'], 2);
            $period['data']['expense'][] = round($sum['expense'], 2);
            $period['data']['gross_profit'][] = round($gross, 2);
            $period['data']['net_profit'][] = round($gross - $sum['expense'], 2);
            $period['data']['cost'][] = round($sum['cost'], 2); // for the Profit Split pie
        }
        return $period;
    };

    // Custom range (AJAX): days up to 62 days, months up to 2 years, else years.
    if (isset($_GET['profit_chart_from'], $_GET['profit_chart_to'])) {
        header('Content-Type: application/json; charset=utf-8');
        $custom_from = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$_GET['profit_chart_from']);
        $custom_to = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$_GET['profit_chart_to']);
        if (!$custom_from || !$custom_to || $custom_from > $custom_to) {
            http_response_code(422);
            echo json_encode(['error' => 'Select a valid From and To date.']);
            exit;
        }
        $custom_days = (int)$custom_from->diff($custom_to)->days + 1;
        $custom_group = $custom_days <= 62 ? 'daily' : ($custom_days <= 731 ? 'monthly' : 'yearly');
        $custom_ranges = [];
        for ($day = $custom_from; $day <= $custom_to; $day = $day->modify('+1 day')) {
            $label = $custom_group === 'daily' ? $day->format('d M') : ($custom_group === 'monthly' ? $day->format('M Y') : $day->format('Y'));
            $key = count($custom_ranges) - 1;
            if ($key >= 0 && $custom_ranges[$key][0] === $label) {
                $custom_ranges[$key][2] = $day->format('Y-m-d');
            } else {
                $custom_ranges[] = [$label, $day->format('Y-m-d'), $day->format('Y-m-d')];
            }
        }
        $custom = $profit_period($profit_days($custom_from->format('Y-m-d'), $custom_to->format('Y-m-d')), $custom_ranges);
        echo json_encode(['group' => $custom_group] + $custom, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $profit_all_days = $profit_days($chart_year_from, $chart_today->format('Y-m-d'));
    $profit_chart = ['series' => $profit_series, 'periods' => []];
    foreach ($chart_ranges as $period => $ranges) {
        $profit_chart['periods'][$period] = $profit_period($profit_all_days, $ranges);
    }
    $profit_year_ranges = [];
    for ($year = $chart_this_year - 4; $year <= $chart_this_year; $year++) {
        $profit_year_ranges[] = [(string)$year, $year . '-01-01', $year . '-12-31'];
    }
    $profit_chart['periods']['yearly'] = $profit_period($profit_all_days, $profit_year_ranges);
}

require_once 'includes/header.php';
require_once 'includes/navbar.php';
require_once 'includes/sidebar.php';

?>

<style>
.dashboard-card-link,
.dashboard-card-link:hover {
    color: inherit;
    display: block;
    text-decoration: none;
}

.dashboard-split-link,
.dashboard-split-link:hover {
    color: inherit;
    text-decoration: none;
}

.agent-dashboard a:not(.agent-dashboard-clickable) {
    pointer-events: none;
    cursor: default;
}

.small-box .inner h3,
.info-box .info-box-number {
    font-size: 1.05rem;
    line-height: 1.2;
    overflow-wrap: anywhere;
    word-break: break-word;
}

@media (max-width: 991.98px) {
    .small-box .inner h3,
    .info-box .info-box-number {
        font-size: 0.95rem;
    }
}

/* Panels side by side are as tall as the tallest one: the shorter card stretches and its
   chart grows to fill it, so no gap is left under it. */
@media (min-width: 992px) {
    #dashPanels > .dash-panel { display: flex; flex-direction: column; }
    #dashPanels > .dash-panel > .card { flex: 1 1 auto; display: flex; flex-direction: column; }
    #dashPanels > .dash-panel > .card > .card-body { flex: 1 1 auto; display: flex; flex-direction: column; }
    #dashPanels > .dash-panel > .card > .card-body > .dash-resize-target { flex: 1 1 auto; }
    #dashPanels > .dash-panel > .card > .card-body > .btn-group { align-self: flex-start; }
}

/* Recent Invoices / Recent Wallet Activity side by side: one line per row (long text is cut
   with … and shown in full on hover), so the rows keep their height. */
[data-panel="recent_invoices"] .table,
[data-panel="recent_wallet_activity"] .table { table-layout: fixed; font-size: .85rem; }
[data-panel="recent_invoices"] .table th,
[data-panel="recent_invoices"] .table td,
[data-panel="recent_wallet_activity"] .table th,
[data-panel="recent_wallet_activity"] .table td { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; vertical-align: middle; }
[data-panel="recent_invoices"] .table td[colspan],
[data-panel="recent_wallet_activity"] .table td[colspan] { white-space: normal; }
[data-panel="recent_invoices"] .badge,
[data-panel="recent_wallet_activity"] .badge { font-size: .72rem; }
[data-panel="recent_invoices"] .table th:nth-child(1) { width: 31%; }
[data-panel="recent_invoices"] .table th:nth-child(2) { width: 17%; }
[data-panel="recent_invoices"] .table th:nth-child(3) { width: 20%; }
[data-panel="recent_invoices"] .table th:nth-child(4) { width: 17%; }
[data-panel="recent_invoices"] .table th:nth-child(5) { width: 15%; }
/* Second card line: the cards give up a little width so Reset Layout / Edit sit at its right end. */
.dash-layout-tools {
    order: 99;
    flex: 0 0 100%;
    max-width: 100%;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    white-space: nowrap;
    padding: 0 7.5px 1rem;
}
@media (min-width: 992px) {
    .dash-card-row[data-row=bottom] > .dash-card,
    .dash-card-row[data-row=bottom] > .dash-card-placeholder {
        flex: 0 0 calc((100% - 200px) / 4);
        max-width: calc((100% - 200px) / 4);
    }
    .dash-layout-tools {
        flex: 0 0 200px;
        max-width: 200px;
        margin-left: auto;
        padding-left: 0;
    }
}
</style>

<div class="<?= $dashboard_is_agent ? 'agent-dashboard' : ''; ?>">

<?php if (!empty($dashboard_travel_plane)) { ?>
<div class="row align-items-center mb-2">

    <div class="col-12 text-md-right">

        <!-- Travel Agency: a small plane flying between two airports beside the heading. -->
        <style>
            .dash-plane{width:280px;max-width:100%;height:80px;vertical-align:middle}
            .dash-plane .dp-trail{animation:dp-dash 1.4s linear infinite}
            @keyframes dp-dash{to{stroke-dashoffset:-20}}
            @media (prefers-reduced-motion:reduce){.dash-plane .dp-trail{animation:none}}
        </style>
        <svg class="dash-plane d-none d-md-inline-block" viewBox="0 0 280 80" aria-hidden="true">
            <path d="M206 30a9 9 0 0 1 17-3 7 7 0 0 1 11 6h-30a5 5 0 0 1 2-3z" fill="#dbe7f5"/>
            <path d="M40 34a7 7 0 0 1 13-2 5 5 0 0 1 8 4H37a4 4 0 0 1 3-2z" fill="#e6eef8"/>
            <path id="dpRoute" d="M18 64 C 80 6, 200 6, 262 58" fill="none" stroke="#9fbfe6" stroke-width="1.6" stroke-dasharray="5 5" class="dp-trail"/>
            <circle cx="18" cy="64" r="4" fill="#2f80ed"/>
            <circle cx="262" cy="58" r="4" fill="#f2994a"/>
            <g class="dp-plane" transform="translate(18 64) rotate(-45)">
                <path d="M11 0 L-4 -2.4 L-7.5 -9 L-10.5 -9 L-8.3 -2.2 L-12.8 -2.2 L-15 -6 L-17.3 -6 L-15.8 0 L-17.3 6 L-15 6 L-12.8 2.2 L-8.3 2.2 L-10.5 9 L-7.5 9 L-4 2.4 Z" fill="#1f5fbf"/>
            </g>
        </svg>
        <script>
        (function () {
            var svg = document.querySelector('.dash-plane');
            if (!svg || !window.requestAnimationFrame) return;
            if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            var route = svg.querySelector('#dpRoute'), plane = svg.querySelector('.dp-plane');
            if (!route.getTotalLength) return;
            var length = route.getTotalLength(), duration = 6000, start = null;
            var ease = function (t) { return t < .5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2; };
            var frame = function (now) {
                if (start === null) start = now;
                var d = ease(((now - start) % duration) / duration) * length;
                var p = route.getPointAtLength(d), a = route.getPointAtLength(Math.max(0, d - 1)), b = route.getPointAtLength(Math.min(length, d + 1));
                plane.setAttribute('transform', 'translate(' + p.x.toFixed(1) + ' ' + p.y.toFixed(1) + ') rotate(' + (Math.atan2(b.y - a.y, b.x - a.x) * 180 / Math.PI).toFixed(1) + ')');
                requestAnimationFrame(frame);
            };
            requestAnimationFrame(frame);
        })();
        </script>

    </div>

</div>
<?php } ?>

<?php
// Summary cards. Each one renders in both shapes: the top row shows the large coloured
// box, the bottom row the compact one. Moving a card between rows switches its shape.
$dashboard_cards = [
    ['key' => 'wallet_balance', 'href' => 'wallets/index.php', 'color' => 'info', 'icon' => 'fas fa-wallet', 'label' => 'Wallet Balance', 'value' => 'BDT ' . number_format($total_wallet_balance)],
    ['key' => 'today_sale', 'href' => $dashboard_invoice_list_url, 'color' => 'success', 'icon' => 'fas fa-chart-line', 'label' => "Today's Sale", 'value' => 'BDT ' . number_format($total_sales), 'clickable' => true],
    ['key' => 'today_expense', 'href' => 'expenses/index.php', 'color' => 'danger', 'icon' => 'fas fa-receipt', 'label' => "Today's Expense", 'value' => 'BDT ' . number_format($today_expense)],
    ['key' => 'today_purchases', 'href' => 'purchases/index.php', 'color' => 'warning', 'icon' => 'fas fa-shopping-basket', 'label' => "Today's Purchases", 'value' => 'BDT ' . number_format($total_purchases)],
];
if ($dashboard_stock_cards) {
    $dashboard_cards[] = ['key' => 'customer_due', 'href' => 'reports/customer_due_report.php', 'color' => 'primary', 'icon' => 'fas fa-hand-holding-usd', 'label' => 'Customer Due', 'value' => 'BDT ' . number_format($customer_due), 'row' => 'bottom'];
    $dashboard_cards[] = ['key' => 'supplier_due', 'href' => 'suppliers/supplier_payment.php', 'color' => 'danger', 'icon' => 'fas fa-truck-loading', 'label' => supplier_display_text('Supplier Due'), 'value' => 'BDT ' . number_format($supplier_due), 'row' => 'bottom'];
    $dashboard_cards[] = ['key' => 'low_stock', 'href' => 'reports/stock_alert_report.php', 'color' => 'warning', 'icon' => 'fas fa-exclamation-triangle', 'label' => 'Low Stock', 'value' => (int)$low_stock . ' Products', 'row' => 'bottom'];
    $dashboard_cards[] = ['key' => 'customers_products', 'href' => 'products/index.php', 'color' => 'success', 'icon' => 'fas fa-boxes', 'label' => 'Products', 'value' => (int)$total_products . ' Items', 'row' => 'bottom'];
}

function dashboard_card_html($card)
{
    $label = $card['label_html'] ?? htmlspecialchars($card['label']);
    $value = $card['value_html'] ?? htmlspecialchars($card['value']);
    $color = htmlspecialchars($card['color']);
    $icon = htmlspecialchars($card['icon']);
    $open = $card['href'] !== ''
        ? '<a href="' . htmlspecialchars($card['href']) . '" class="dashboard-card-link' . (!empty($card['clickable']) ? ' agent-dashboard-clickable' : '') . '">'
        : '';
    $close = $card['href'] !== '' ? '</a>' : '';

    return '<div class="col-lg-3 col-6 dash-card" data-card="' . htmlspecialchars($card['key']) . '">'
        . '<div class="dash-card-top">' . $open
        . '<div class="small-box bg-' . $color . '"><div class="inner"><h3>' . $value . '</h3><p>' . $label . '</p></div><div class="icon"><i class="' . $icon . '"></i></div></div>'
        . $close . '</div>'
        . '<div class="dash-card-bottom">' . $open
        . '<div class="info-box"><span class="info-box-icon bg-' . $color . '"><i class="' . $icon . '"></i></span><div class="info-box-content"><span class="info-box-text">' . $label . '</span><span class="info-box-number">' . $value . '</span></div></div>'
        . $close . '</div>'
        . '</div>';
}
?>

<?php foreach (['top', 'bottom'] as $card_row) { ?>
<div class="row dash-card-row" data-row="<?= $card_row; ?>">
    <?php foreach ($dashboard_cards as $card) {
        if (($card['row'] ?? 'top') === $card_row) echo dashboard_card_html($card);
    } ?>
    <?php if ($card_row === 'bottom') { ?>
    <!-- Reset Layout and Edit (added by dashboard_layout.js) at the right end of the second card line. -->
    <div class="dash-layout-tools">
        <a href="#" id="dashLayoutReset" class="btn btn-link btn-sm text-muted agent-dashboard-clickable" title="Cards and charts can be dragged and resized; this restores the original layout">
            <i class="fas fa-undo-alt mr-1"></i>Reset Layout
        </a>
    </div>
    <?php } ?>
</div>
<?php } ?>

<div class="row" id="dashPanels">

    <div class="col-lg-8 dash-panel" data-panel="business_overview">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Business Overview
                </h3>

                <div class="card-tools">
                    <div class="btn-group btn-group-sm" id="businessChartPeriods">
                        <button type="button" class="btn btn-outline-success active" data-period="daily">Daily</button>
                        <button type="button" class="btn btn-outline-success" data-period="weekly">Weekly</button>
                        <button type="button" class="btn btn-outline-success" data-period="monthly">Monthly</button>
                        <button type="button" class="btn btn-outline-success" data-period="yearly">Yearly</button>
                        <button type="button" class="btn btn-outline-success" data-period="custom"><i class="far fa-calendar-alt mr-1"></i>Custom</button>
                    </div>
                </div>
            </div>

            <div class="card-body">
                <div class="btn-group btn-group-sm flex-wrap mb-3" id="businessChartMetrics">
                    <?php foreach($business_chart['series'] as $metric_key => $metric){ ?>
                        <button type="button" class="btn btn-outline-secondary<?= $metric_key === 'sales' ? ' active' : ''; ?>" data-metric="<?= htmlspecialchars($metric_key); ?>"><?= htmlspecialchars($metric['label']); ?></button>
                    <?php } ?>
                    <button type="button" class="btn btn-outline-secondary" data-metric="all">All</button>
                </div>

                <form class="form-inline mb-3 d-none" id="businessChartCustom">
                    <label class="mr-2 small text-muted" for="businessChartFrom">From</label>
                    <input type="date" class="form-control form-control-sm mr-2" id="businessChartFrom" value="<?= htmlspecialchars($chart_today->modify('-29 days')->format('Y-m-d')); ?>" max="<?= htmlspecialchars($chart_today->format('Y-m-d')); ?>" required>
                    <label class="mr-2 small text-muted" for="businessChartTo">To</label>
                    <input type="date" class="form-control form-control-sm mr-2" id="businessChartTo" value="<?= htmlspecialchars($chart_today->format('Y-m-d')); ?>" required>
                    <button type="submit" class="btn btn-success btn-sm">Show</button>
                    <small class="text-danger ml-2" id="businessChartCustomError"></small>
                </form>

                <div class="dash-resize-target" style="height:320px;">
                    <canvas id="businessChart"></canvas>
                </div>
            </div>

        </div>

    </div>

    <div class="col-lg-4 dash-panel" data-panel="wallet_split">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Wallet Split
                </h3>
                <div class="card-tools">
                    <span class="badge badge-info">
                        <?= (int)$active_wallets; ?> Active
                    </span>
                </div>
            </div>

            <div class="card-body">
                <div class="dash-resize-target d-flex flex-column" style="height:320px;">
                    <div class="flex-grow-1 position-relative" style="min-height:120px">
                        <canvas id="walletChart"></canvas>
                    </div>
                    <?php $wallet_split_total = array_sum(array_filter(array_map('floatval', $wallet_balances), static fn($v) => $v > 0)); ?>
                    <div id="walletSplitList" class="mt-2 small" style="max-height:60%;overflow-y:auto">
                        <?php foreach ($wallet_labels as $wallet_index => $wallet_label) { $wallet_amount = (float)($wallet_balances[$wallet_index] ?? 0); ?>
                            <div class="d-flex align-items-center py-1 border-bottom">
                                <span class="mr-2" style="display:inline-block;width:10px;height:10px;border-radius:2px;background:<?= ['#007bff', '#28a745', '#ffc107', '#dc3545', '#17a2b8', '#6f42c1', '#fd7e14', '#20c997'][$wallet_index % 8]; ?>"></span>
                                <span class="flex-grow-1 text-truncate" title="<?= htmlspecialchars($wallet_label); ?>"><?= htmlspecialchars($wallet_label); ?></span>
                                <span class="text-muted mx-2"><?= $wallet_split_total > 0 && $wallet_amount > 0 ? number_format($wallet_amount * 100 / $wallet_split_total, 1) . '%' : ''; ?></span>
                                <strong class="text-nowrap<?= $wallet_amount < 0 ? ' text-danger' : ''; ?>">BDT <?= number_format($wallet_amount, 2); ?></strong>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>

        </div>

    </div>

    <?php if($dashboard_stock_cards){ ?>
    <?php if ($profit_chart) { ?>
    <style>
    /* Phones / tablets: the pie goes under the bars, each with its own height. */
    @media (max-width: 991.98px){
        [data-panel="profit_overview"] .dash-resize-target,
        [data-panel="profit_overview"] .dash-resize-target > .row,
        [data-panel="profit_overview"] .dash-resize-target .col-lg-8,
        [data-panel="profit_overview"] .dash-resize-target .col-lg-4{height:auto !important}
        [data-panel="profit_overview"] .flex-grow-1{height:280px}
        [data-panel="profit_overview"] .border-left{border-left:0 !important;margin-top:1rem}
    }
    </style>
    <!-- Profit Overview (bars) and Profit Split (pie) are one panel: they move, resize and change together. -->
    <div class="col-lg-12 dash-panel" data-panel="profit_overview">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Profit Overview
                </h3>

                <div class="card-tools">
                    <div class="btn-group btn-group-sm" id="profitChartPeriods">
                        <button type="button" class="btn btn-outline-success active" data-period="daily">Daily</button>
                        <button type="button" class="btn btn-outline-success" data-period="weekly">Weekly</button>
                        <button type="button" class="btn btn-outline-success" data-period="monthly">Monthly</button>
                        <button type="button" class="btn btn-outline-success" data-period="yearly">Yearly</button>
                        <button type="button" class="btn btn-outline-success" data-period="custom"><i class="far fa-calendar-alt mr-1"></i>Custom</button>
                    </div>
                </div>
            </div>

            <div class="card-body">
                <form class="form-inline mb-3 d-none" id="profitChartCustom">
                    <label class="mr-2 small text-muted" for="profitChartFrom">From</label>
                    <input type="date" class="form-control form-control-sm mr-2" id="profitChartFrom" value="<?= htmlspecialchars($chart_today->modify('-29 days')->format('Y-m-d')); ?>" max="<?= htmlspecialchars($chart_today->format('Y-m-d')); ?>" required>
                    <label class="mr-2 small text-muted" for="profitChartTo">To</label>
                    <input type="date" class="form-control form-control-sm mr-2" id="profitChartTo" value="<?= htmlspecialchars($chart_today->format('Y-m-d')); ?>" required>
                    <button type="submit" class="btn btn-success btn-sm">Show</button>
                    <small class="text-danger ml-2" id="profitChartCustomError"></small>
                </form>

                <div class="dash-resize-target" style="height:380px;">
                    <div class="row h-100">
                        <div class="col-lg-8 d-flex flex-column h-100">
                            <div class="btn-group btn-group-sm flex-wrap mb-3 align-self-start" id="profitChartMetrics">
                                <?php foreach($profit_chart['series'] as $metric_key => $metric){ ?>
                                    <button type="button" class="btn btn-outline-secondary<?= $metric_key === 'net_profit' ? ' active' : ''; ?>" data-metric="<?= htmlspecialchars($metric_key); ?>"><?= htmlspecialchars($metric['label']); ?></button>
                                <?php } ?>
                                <button type="button" class="btn btn-outline-secondary" data-metric="all">All</button>
                            </div>
                            <div class="flex-grow-1 position-relative" style="min-height:0">
                                <canvas id="profitChart"></canvas>
                            </div>
                        </div>
                        <div class="col-lg-4 d-flex flex-column h-100 border-left">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <strong>Profit Split</strong>
                                <span class="badge badge-info" id="profitSplitPeriod">Last 30 days</span>
                            </div>
                            <div class="flex-grow-1 position-relative" style="min-height:0">
                                <canvas id="profitSplitChart"></canvas>
                            </div>
                            <div class="w-100 mt-2"><table class="table table-sm mb-0" style="min-width:0;white-space:normal">
                                <tr><td>Sales</td><td class="text-right font-weight-bold" id="profitSplitSales">0.00</td></tr>
                                <tr><td>Gross Profit</td><td class="text-right font-weight-bold" id="profitSplitGross">0.00</td></tr>
                                <tr><td>Net Profit</td><td class="text-right font-weight-bold" id="profitSplitNet">0.00</td></tr>
                            </table></div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>
    <?php } ?>
    <div class="col-lg-12 dash-panel" data-panel="best_selling_products">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Best Selling Products
                </h3>

                <div class="card-tools d-flex flex-wrap align-items-center" id="bestProductsTools">
                    <small class="text-muted mr-3" id="bestProductsRange"></small>
                    <div class="btn-group btn-group-sm mr-2" id="bestProductsMetric">
                        <button type="button" class="btn btn-outline-secondary active" data-metric="qty">Quantity</button>
                        <button type="button" class="btn btn-outline-secondary" data-metric="amount">Amount</button>
                    </div>
                    <div class="btn-group btn-group-sm" id="bestProductsPeriods">
                        <button type="button" class="btn btn-outline-success" data-period="daily">Daily</button>
                        <button type="button" class="btn btn-outline-success" data-period="weekly">Weekly</button>
                        <button type="button" class="btn btn-outline-success" data-period="monthly">Monthly</button>
                        <button type="button" class="btn btn-outline-success" data-period="yearly">Yearly</button>
                        <button type="button" class="btn btn-outline-success" data-period="custom"><i class="far fa-calendar-alt mr-1"></i>Custom</button>
                    </div>
                </div>
            </div>

            <div class="card-body">
                <div class="d-flex flex-wrap align-items-center">
                    <form class="form-inline mb-3 d-none" id="bestProductsCustom">
                        <label class="mr-2 small text-muted" for="bestProductsFrom">From</label>
                        <input type="date" class="form-control form-control-sm mr-2" id="bestProductsFrom" value="<?= htmlspecialchars(date('Y-m-01')); ?>" max="<?= htmlspecialchars(date('Y-m-d')); ?>" required>
                        <label class="mr-2 small text-muted" for="bestProductsTo">To</label>
                        <input type="date" class="form-control form-control-sm mr-2" id="bestProductsTo" value="<?= htmlspecialchars(date('Y-m-d')); ?>" required>
                        <button type="submit" class="btn btn-success btn-sm">Show</button>
                    </form>
                    <small class="text-danger ml-2" id="bestProductsCustomError"></small>
                </div>

                <div class="dash-resize-target position-relative" style="height:320px;">
                    <canvas id="bestProductsChart"></canvas>
                    <div id="bestProductsEmpty" class="d-none align-items-center justify-content-center text-muted" style="position:absolute;top:0;right:0;bottom:0;left:0;">
                        No products sold in this period.
                    </div>
                </div>
            </div>

        </div>

    </div>

    <div class="col-lg-12 dash-panel" data-panel="sales_by_staff">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Sales by Staff
                </h3>

                <div class="card-tools d-flex flex-wrap align-items-center">
                    <small class="text-muted mr-3" id="staffSalesRange"></small>
                    <div class="btn-group btn-group-sm mr-2" id="staffSalesMode">
                        <button type="button" class="btn btn-outline-secondary active" data-mode="login" title="Who made the invoice (staff login)">By Login</button>
                        <button type="button" class="btn btn-outline-secondary" data-mode="reference" title="Staff on the invoice, or the staff who referred the customer">By Reference</button>
                        <?php if ($staff_sales_service) { ?><button type="button" class="btn btn-outline-secondary" data-mode="service" title="Delivered service jobs by their staff">Service</button><?php } ?>
                    </div>
                    <div class="btn-group btn-group-sm" id="staffSalesPeriods">
                        <button type="button" class="btn btn-outline-success" data-period="daily">Daily</button>
                        <button type="button" class="btn btn-outline-success" data-period="weekly">Weekly</button>
                        <button type="button" class="btn btn-outline-success active" data-period="monthly">Monthly</button>
                        <button type="button" class="btn btn-outline-success" data-period="yearly">Yearly</button>
                        <button type="button" class="btn btn-outline-success" data-period="custom"><i class="far fa-calendar-alt mr-1"></i>Custom</button>
                    </div>
                </div>
            </div>

            <div class="card-body">
                <div class="d-flex flex-wrap align-items-center">
                    <form class="form-inline mb-3 d-none" id="staffSalesCustom">
                        <label class="mr-2 small text-muted" for="staffSalesFrom">From</label>
                        <input type="date" class="form-control form-control-sm mr-2" id="staffSalesFrom" value="<?= htmlspecialchars(date('Y-m-01')); ?>" max="<?= htmlspecialchars(date('Y-m-d')); ?>" required>
                        <label class="mr-2 small text-muted" for="staffSalesTo">To</label>
                        <input type="date" class="form-control form-control-sm mr-2" id="staffSalesTo" value="<?= htmlspecialchars(date('Y-m-d')); ?>" required>
                        <button type="submit" class="btn btn-success btn-sm">Show</button>
                    </form>
                    <small class="text-danger ml-2" id="staffSalesError"></small>
                </div>

                <div class="dash-resize-target position-relative" style="height:300px;">
                    <canvas id="staffSalesChart"></canvas>
                    <div id="staffSalesEmpty" class="d-none align-items-center justify-content-center text-muted" style="position:absolute;top:0;right:0;bottom:0;left:0;">
                        No sales in this period.
                    </div>
                </div>
            </div>

        </div>

    </div>
    <?php } ?>


    <?php
    // Top Customers / Top Suppliers / Top Due Customers / Top Due Suppliers (shown as
    // "Vendors" where supplier_display_text() says so): top 5 each,
    // laid out like the You2 Wallet dashboard (name, phone, amount, Ledger button).
    $dashboard_top_lists = [
        [
            'key' => 'top_customers', 'title' => 'Top Customers', 'all' => 'customers/index.php',
            'ledger' => 'customers/customer_ledger.php', 'name_label' => 'Customer', 'amount_label' => 'Total Sale',
            'empty' => 'No customer sales found.',
            'rows' => array_map(function ($r) { return ['id' => $r['id'], 'name' => $r['name'], 'phone' => $r['phone'] ?? '', 'amount' => $r['total']]; }, $top_customers),
        ],
        [
            'key' => 'top_vendors', 'title' => supplier_display_text('Top Suppliers'), 'all' => 'suppliers/index.php',
            'ledger' => 'suppliers/supplier_ledger.php', 'name_label' => supplier_display_text('Supplier'), 'amount_label' => 'Total Purchase',
            'empty' => supplier_display_text('No supplier purchases found.'),
            'rows' => array_map(function ($r) { return ['id' => $r['id'], 'name' => $r['name'], 'phone' => $r['phone'] ?? '', 'amount' => $r['total']]; }, $top_vendors),
        ],
        [
            'key' => 'top_due_customers', 'title' => 'Top Due Customers', 'all' => 'reports/customer_due_report.php',
            'ledger' => 'customers/customer_ledger.php', 'name_label' => 'Customer', 'amount_label' => 'Due',
            'empty' => 'No customer due found.',
            'rows' => array_map(function ($r) { return ['id' => $r['id'], 'name' => $r['customer_name'], 'phone' => $r['phone'] ?? '', 'amount' => $r['total_due']]; }, $top_due_customers),
        ],
        [
            'key' => 'top_due_vendors', 'title' => supplier_display_text('Top Due Suppliers'), 'all' => 'suppliers/supplier_payment.php',
            'ledger' => 'suppliers/supplier_ledger.php', 'name_label' => supplier_display_text('Supplier'), 'amount_label' => 'Due',
            'empty' => supplier_display_text('No supplier due found.'),
            'rows' => array_map(function ($r) { return ['id' => $r['id'], 'name' => $r['name'], 'phone' => $r['phone'] ?? '', 'amount' => $r['due']]; }, $top_due_vendors),
        ],
    ];
    foreach ($dashboard_top_lists as $top_list) {
    ?>
    <div class="col-lg-6 dash-panel" data-panel="<?= htmlspecialchars($top_list['key']); ?>">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    <?= htmlspecialchars($top_list['title']); ?>
                </h3>
                <div class="card-tools">
                    <a href="<?= htmlspecialchars($top_list['all']); ?>" class="btn btn-tool">
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>

            <div class="card-body p-0 dash-resize-target">
                <table class="table table-striped table-sm mb-0">
                    <thead>
                    <tr>
                        <th><?= htmlspecialchars($top_list['name_label']); ?></th>
                        <th>Phone</th>
                        <th><?= htmlspecialchars($top_list['amount_label']); ?></th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($top_list['rows'])) { ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-3">
                            <?= htmlspecialchars($top_list['empty']); ?>
                        </td>
                    </tr>
                    <?php } ?>
                    <?php foreach ($top_list['rows'] as $row) { ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$row['name']); ?></td>
                        <td><?= htmlspecialchars((string)$row['phone']); ?></td>
                        <td>
                            <strong>BDT <?= number_format((float)$row['amount'], 2); ?></strong>
                        </td>
                        <td class="text-right">
                            <a
                                href="<?= htmlspecialchars($top_list['ledger']); ?>?id=<?= (int)$row['id']; ?>"
                                class="btn btn-info btn-xs">
                                Ledger
                            </a>
                        </td>
                    </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>

        </div>

    </div>
    <?php } ?>

    <?php /* Recent Invoices / Recent Wallet Activity sit at the bottom of the dashboard. */ ?>
    <div class="col-lg-6 dash-panel" data-panel="recent_invoices">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Recent Invoices
                </h3>
                <div class="card-tools">
                    <a href="<?= htmlspecialchars($dashboard_invoice_list_url); ?>" class="btn btn-tool">
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>

            <div class="card-body p-0 dash-resize-target">
                <table class="table table-striped table-sm mb-0">
                    <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Total</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if(empty($recent_invoices)){ ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-3">
                            No invoices found.
                        </td>
                    </tr>
                    <?php } ?>
                    <?php foreach($recent_invoices as $invoice){ ?>
                    <tr>
                        <td>
                            <a href="<?= htmlspecialchars($dashboard_invoice_print_url); ?>?id=<?= (int)$invoice['id']; ?>" target="_blank" rel="noopener">
                                <?= htmlspecialchars($invoice['invoice_no']); ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars(app_date($invoice['invoice_date'])); ?></td>
                        <td title="<?= htmlspecialchars($invoice['customer_name']); ?>"><?= htmlspecialchars($invoice['customer_name']); ?></td>
                        <td>BDT <?= number_format($invoice['amount'],2); ?></td>
                        <td>
                            <?php $invoice_status = (string)($invoice['status'] ?? 'pending'); $is_confirmed = $invoice_status === 'confirmed'; ?>
                            <span class="badge badge-<?= $is_confirmed ? 'success' : ($invoice_status === 'pending' ? 'warning' : 'secondary'); ?>">
                                <?= $is_confirmed ? 'Confirmed' : htmlspecialchars(ucfirst($invoice_status)); ?>
                            </span>
                        </td>
                    </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>

        </div>

    </div>

    <div class="col-lg-6 dash-panel" data-panel="recent_wallet_activity">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Recent Wallet Activity
                </h3>
                <div class="card-tools">
                    <a href="transactions/index.php" class="btn btn-tool">
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>

            <div class="card-body p-0 dash-resize-target">
                <table class="table table-striped table-sm mb-0">
                    <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Wallet</th>
                        <th>Amount</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if(empty($recent_transactions)){ ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-3">
                            No wallet activity found.
                        </td>
                    </tr>
                    <?php } ?>
                    <?php foreach($recent_transactions as $transaction){ ?>
                    <?php
                    $income_types = [
                        'money_in',
                        'transfer_in',
                        'sales_invoice',
                        'receive_payment',
                        'purchase_return',
                        'invoice_income',
                        'travel_payment',
                        'service_advance'
                    ];

                    $type_label =
                        dashboard_resolve_transaction_type_label(
                            $conn,
                            $transaction,
                            $user_id
                        );

                    $is_income =
                        in_array(
                            $transaction['transaction_type'],
                            $income_types
                        );

                    $wallet_text = $transaction['wallet_name'] ?? '';

                    if($transaction['transaction_type'] == 'transfer'){
                        $wallet_text =
                            ($transaction['from_wallet'] ?? '') .
                            ' -> ' .
                            ($transaction['to_wallet'] ?? '');
                    }
                    ?>
                    <tr>
                        <td><?= htmlspecialchars(app_date($transaction['txn_date'])); ?></td>
                        <td>
                            <?php if($is_income){ ?>
                                <span class="badge badge-success">
                                    <?= htmlspecialchars($type_label); ?>
                                </span>
                            <?php }elseif($transaction['transaction_type'] == 'expense'){ ?>
                                <span class="badge badge-danger">Expense</span>
                            <?php }else{ ?>
                                <span class="badge badge-secondary">
                                    <?= htmlspecialchars($type_label); ?>
                                </span>
                            <?php } ?>
                        </td>
                        <td><?= htmlspecialchars($wallet_text); ?></td>
                        <td>
                            <strong class="text-dark">
                                BDT <?= number_format($transaction['amount'],2); ?>
                            </strong>
                        </td>
                    </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>

        </div>

    </div>

    <?php if ($dashboard_stock_cards) { ?>
    <!-- Kept as the final panel so stock that needs attention is always visible at the
         bottom of the default dashboard layout. -->
    <div class="col-lg-12 dash-panel" data-panel="low_stock_watch">

        <div class="card card-outline card-warning">

            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-exclamation-triangle text-warning mr-2"></i>
                    Low Stock Watch
                    <span class="badge badge-warning ml-2"><?= (int)$low_stock; ?></span>
                </h3>
                <div class="card-tools">
                    <a href="products/index.php" class="btn btn-tool" title="View products">
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>

            <div class="card-body p-0 dash-resize-target">
                <table class="table table-striped table-sm mb-0">
                    <thead>
                    <tr>
                        <th>Product</th>
                        <th>Code</th>
                        <th class="text-right">Current Stock</th>
                        <th class="text-right">Minimum Stock</th>
                        <th class="text-right">Shortage</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($low_stock_products)) { ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-3">
                            <i class="fas fa-check-circle text-success mr-1"></i>No low-stock products found.
                        </td>
                    </tr>
                    <?php } ?>
                    <?php foreach ($low_stock_products as $product) {
                        $current_stock = (float)$product['current_stock'];
                        $minimum_stock = (float)$product['minimum_stock'];
                        $shortage = max(0, $minimum_stock - $current_stock);
                        $out_of_stock = $current_stock <= 0;
                    ?>
                    <tr>
                        <td>
                            <a href="products/edit.php?id=<?= (int)$product['id']; ?>">
                                <?= htmlspecialchars((string)$product['product_name']); ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars((string)($product['sku'] ?? '')); ?></td>
                        <td class="text-right"><strong class="<?= $out_of_stock ? 'text-danger' : 'text-warning'; ?>"><?= number_format($current_stock, 0); ?></strong></td>
                        <td class="text-right"><?= number_format($minimum_stock, 0); ?></td>
                        <td class="text-right"><?= number_format($shortage, 0); ?></td>
                        <td><span class="badge badge-<?= $out_of_stock ? 'danger' : 'warning'; ?>"><?= $out_of_stock ? 'Out of Stock' : 'Low Stock'; ?></span></td>
                    </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>

        </div>

    </div>
    <?php } ?>

</div>

</div>

<?php

$dashboard_layout_key = 'you2biz.dashboard.layout.' . (int)$user_id . '.' . (int)($_SESSION['login_user_id'] ?? $user_id);
$page_script = '
<script>window.dashBsTooltip = $.fn.tooltip; window.dashBsButton = $.fn.button;</script>
<script src="' . htmlspecialchars(app_path('adminlte/plugins/jquery-ui/jquery-ui.min.js')) . '"></script>
<script>
// jQuery UI has its own tooltip / button widgets; keep the Bootstrap ones.
$.fn.tooltip = window.dashBsTooltip;
$.fn.button = window.dashBsButton;
</script>
<script src="' . htmlspecialchars(app_path('assets/js/dashboard_layout.js') . '?v=' . @filemtime(__DIR__ . '/assets/js/dashboard_layout.js')) . '"></script>
<script>
window.dashboardVerticalBarValueLabels = function(id){
    return {
        id: id,
        afterDatasetsDraw: function(chartInstance){
            const context = chartInstance.ctx;
            context.save();
            context.font = "600 10px Arial, sans-serif";
            context.textAlign = "center";

            chartInstance.data.datasets.forEach(function(dataset, datasetIndex){
                if(!chartInstance.isDatasetVisible(datasetIndex)) return;
                const bars = chartInstance.getDatasetMeta(datasetIndex).data;
                bars.forEach(function(bar, index){
                    const value = Number(dataset.data[index] || 0);
                    if(!isFinite(value) || value === 0) return;
                    const text = value.toLocaleString("en-US", {maximumFractionDigits: 2});
                    const textWidth = context.measureText(text).width;
                    const barHeight = Math.abs(bar.base - bar.y);

                    if(barHeight >= textWidth + 12){
                        context.fillStyle = "#ffffff";
                        context.textBaseline = "middle";
                        context.save();
                        context.translate(bar.x, (bar.base + bar.y) / 2);
                        context.rotate(-Math.PI / 2);
                        context.fillText(text, 0, 0);
                        context.restore();
                    }else{
                        context.fillStyle = "#343a40";
                        context.textBaseline = value > 0 ? "bottom" : "top";
                        context.fillText(text, bar.x, value > 0 ? bar.y - 4 : bar.y + 4);
                    }
                });
            });

            context.restore();
        }
    };
};
$(function(){
    DashboardLayout.init({
        storageKey: ' . json_encode($dashboard_layout_key) . ',
        resetLink: "#dashLayoutReset",
        defaultPanelOrder: [
            "business_overview", "wallet_split", "profit_overview", "best_selling_products", "sales_by_staff",
            "top_customers", "top_vendors", "top_due_customers", "top_due_vendors",
            "recent_invoices", "recent_wallet_activity",
            "low_stock_watch"
        ]
    });
});
</script>
<script src="' . htmlspecialchars(app_path('assets/js/best_products_chart.js') . '?v=' . @filemtime(__DIR__ . '/assets/js/best_products_chart.js')) . '"></script>
<script>
$(function(){
    if (window.BestProductsChart) BestProductsChart.init({ url: window.location.pathname, defaultPeriod: "monthly" });
});
</script>
<script>
$(function(){
    const businessCanvas = document.getElementById("businessChart");
    const businessData = ' . json_encode($business_chart, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_TAG) . ';
    const businessKeys = Object.keys(businessData.series);
    const walletCanvas = document.getElementById("walletChart");

    let businessChart = null;

    if(businessCanvas){
        const firstPeriod = businessData.periods.daily;

        businessChart = new Chart(businessCanvas, {
            type: "bar",
            plugins: [window.dashboardVerticalBarValueLabels("businessOverviewValues")],
            data: {
                labels: firstPeriod.labels,
                datasets: businessKeys.map(function(key){
                    return {
                        label: businessData.series[key].label,
                        data: firstPeriod.data[key],
                        backgroundColor: businessData.series[key].color,
                        hidden: key !== "sales"
                    };
                })
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { top: 16, bottom: 16 } },
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }

    function showBusinessPeriod(period){
        businessChart.data.labels = period.labels;
        businessKeys.forEach(function(key, index){
            businessChart.data.datasets[index].data = period.data[key] || [];
        });
        businessChart.update();
    }

    $("#businessChartPeriods").on("click", "button", function(){
        const name = $(this).data("period");

        if(!businessChart){
            return;
        }

        $(this).addClass("active").siblings().removeClass("active");
        $("#businessChartCustom").toggleClass("d-none", name !== "custom");

        if(name === "custom"){
            $("#businessChartCustom").trigger("submit");
            return;
        }

        if(businessData.periods[name]){
            showBusinessPeriod(businessData.periods[name]);
        }
    });

    // Custom: any From / To dates; up to 62 days shows days, up to 2 years months, else years.
    $("#businessChartCustom").on("submit", function(event){
        event.preventDefault();
        const from = $("#businessChartFrom").val();
        const to = $("#businessChartTo").val();
        const error = $("#businessChartCustomError").text("");

        if(!from || !to || from > to){
            error.text("From date must be on or before To date.");
            return;
        }

        $.getJSON(window.location.pathname, { business_chart_from: from, business_chart_to: to })
            .done(showBusinessPeriod)
            .fail(function(xhr){
                error.text((xhr.responseJSON && xhr.responseJSON.error) || "Could not load this date range.");
            });
    });

    // One metric at a time, or every metric side by side with a legend.
    $("#businessChartMetrics").on("click", "button", function(){
        const metric = $(this).data("metric");

        if(!businessChart){
            return;
        }

        $(this).addClass("active").siblings().removeClass("active");
        businessKeys.forEach(function(key, index){
            businessChart.data.datasets[index].hidden = metric !== "all" && key !== metric;
        });
        businessChart.options.plugins.legend.display = metric === "all";
        businessChart.update();
    });

    if(walletCanvas){
        new Chart(walletCanvas, {
            type: "doughnut",
            data: {
                labels: ' . json_encode($wallet_labels) . ',
                datasets: [{
                    data: ' . json_encode($wallet_balances) . ',
                    backgroundColor: [
                        "#007bff",
                        "#28a745",
                        "#ffc107",
                        "#dc3545",
                        "#17a2b8",
                        "#6f42c1",
                        "#fd7e14",
                        "#20c997"
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
    }
});
</script>
';

if ($profit_chart) {
    $page_script .= '<script>
// Profit Overview bars and the Profit Split pie (sales = cost of goods + expense + net profit).
$(function(){
    const canvas = document.getElementById("profitChart");
    if(!canvas || typeof Chart === "undefined") return;
    const data = ' . json_encode($profit_chart, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_TAG) . ';
    const keys = Object.keys(data.series);
    const money = function(v){ return Number(v || 0).toLocaleString("en-US", {minimumFractionDigits: 2, maximumFractionDigits: 2}); };
    const sum = function(list){ return (list || []).reduce(function(a, b){ return a + Number(b || 0); }, 0); };

    const chart = new Chart(canvas, {
        type: "bar",
        plugins: [window.dashboardVerticalBarValueLabels("profitOverviewValues")],
        data: {
            labels: data.periods.daily.labels,
            datasets: keys.map(function(key){
                return { label: data.series[key].label, data: data.periods.daily.data[key], backgroundColor: data.series[key].color, hidden: key !== "net_profit" };
            })
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: { top: 16, bottom: 16 } },
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true } }
        }
    });

    const splitCanvas = document.getElementById("profitSplitChart");
    const split = splitCanvas ? new Chart(splitCanvas, {
        type: "doughnut",
        data: { labels: ["Cost of Goods Sold", "Expense", "Net Profit"], datasets: [{ data: [0, 0, 0], backgroundColor: ["#fd7e14", "#dc3545", "#6f42c1"] }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: "bottom" } } }
    }) : null;

    function show(period, name){
        chart.data.labels = period.labels;
        keys.forEach(function(key, index){ chart.data.datasets[index].data = period.data[key] || []; });
        chart.update();
        const sales = sum(period.data.sales), cost = sum(period.data.cost), expense = sum(period.data.expense);
        const gross = sum(period.data.gross_profit), net = sum(period.data.net_profit);
        $("#profitSplitSales").text("BDT " + money(sales));
        $("#profitSplitGross").text("BDT " + money(gross)).toggleClass("text-danger", gross < 0);
        $("#profitSplitNet").text((net < 0 ? "Loss BDT " : "BDT ") + money(Math.abs(net))).toggleClass("text-danger", net < 0).toggleClass("text-success", net >= 0);
        $("#profitSplitPeriod").text(name);
        if(split){
            // A loss has no slice; the table above shows it.
            split.data.datasets[0].data = [Math.max(cost, 0), Math.max(expense, 0), Math.max(net, 0)];
            split.update();
        }
    }

    const names = { daily: "Last 30 days", weekly: "Last 12 weeks", monthly: "Last 12 months", yearly: "Last 5 years" };
    show(data.periods.daily, names.daily);

    $("#profitChartPeriods").on("click", "button", function(){
        const name = $(this).data("period");
        $(this).addClass("active").siblings().removeClass("active");
        $("#profitChartCustom").toggleClass("d-none", name !== "custom");
        if(name === "custom"){ $("#profitChartCustom").trigger("submit"); return; }
        if(data.periods[name]) show(data.periods[name], names[name]);
    });

    $("#profitChartCustom").on("submit", function(event){
        event.preventDefault();
        const from = $("#profitChartFrom").val(), to = $("#profitChartTo").val();
        const error = $("#profitChartCustomError").text("");
        if(!from || !to || from > to){ error.text("From date must be on or before To date."); return; }
        $.getJSON(window.location.pathname, { profit_chart_from: from, profit_chart_to: to })
            .done(function(period){ show(period, from + " to " + to); })
            .fail(function(xhr){ error.text((xhr.responseJSON && xhr.responseJSON.error) || "Could not load this date range."); });
    });

    $("#profitChartMetrics").on("click", "button", function(){
        const metric = $(this).data("metric");
        $(this).addClass("active").siblings().removeClass("active");
        keys.forEach(function(key, index){ chart.data.datasets[index].hidden = metric !== "all" && key !== metric; });
        chart.options.plugins.legend.display = metric === "all";
        chart.update();
    });
});
</script>
';
}

if ($dashboard_stock_cards) {
    $page_script .= '<script>
// Sales by Staff: horizontal bars of the sales amount per staff; the tooltip shows the count.
$(function(){
    const canvas = document.getElementById("staffSalesChart");
    if(!canvas || typeof Chart === "undefined") return;
    let mode = "login", from = "", to = "";
    const pad = function(n){ return (n < 10 ? "0" : "") + n; };
    const ymd = function(d){ return d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate()); };
    const label = function(v){ const p = v.split("-"); return p[2] + " " + ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"][Number(p[1]) - 1] + " " + p[0]; };
    const money = function(v){ return Number(v || 0).toLocaleString("en-US", {minimumFractionDigits: 2, maximumFractionDigits: 2}); };
    function preset(period){
        const today = new Date();
        let start = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        if(period === "weekly") start.setDate(start.getDate() - ((start.getDay() + 1) % 7)); // weeks start on Saturday
        if(period === "monthly") start.setDate(1);
        if(period === "yearly") start = new Date(today.getFullYear(), 0, 1);
        return [ymd(start), ymd(today)];
    }
    const valueLabelsPlugin = {
        id: "staffSalesValueLabels",
        afterDatasetsDraw: function(chartInstance){
            const context = chartInstance.ctx;
            const dataset = chartInstance.data.datasets[0];
            const bars = chartInstance.getDatasetMeta(0).data;
            if(!dataset || !bars) return;
            context.save();
            context.font = "600 12px Arial, sans-serif";
            context.textBaseline = "middle";
            bars.forEach(function(bar, index){
                const text = "BDT " + money(dataset.data[index]);
                const width = context.measureText(text).width;
                const fitsInside = Math.abs(bar.x - bar.base) >= width + 14;
                context.fillStyle = fitsInside ? "#ffffff" : "#343a40";
                context.textAlign = fitsInside ? "right" : "left";
                context.fillText(text, fitsInside ? bar.x - 7 : bar.x + 7, bar.y);
            });
            context.restore();
        }
    };
    const chart = new Chart(canvas, {
        type: "bar",
        plugins: [valueLabelsPlugin],
        data: { labels: [], datasets: [{ label: "Sales", data: [], counts: [], backgroundColor: "#17a2b8" }] },
        options: {
            indexAxis: "y", responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: function(ctx){
                    const count = ctx.dataset.counts[ctx.dataIndex] || 0;
                    return " BDT " + money(ctx.parsed.x) + " (" + count + (mode === "service" ? " job" : " invoice") + (count === 1 ? "" : "s") + ")";
                } } }
            },
            scales: { x: { beginAtZero: true } }
        }
    });
    function load(){
        $("#staffSalesError").text("");
        $("#staffSalesRange").text(from === to ? label(from) : label(from) + " – " + label(to));
        $.getJSON(window.location.pathname, { staff_sales_from: from, staff_sales_to: to, staff_sales_mode: mode })
            .done(function(res){
                const rows = res.rows || [];
                chart.data.labels = rows.map(function(r){ return r.name; });
                chart.data.datasets[0].data = rows.map(function(r){ return r.amount; });
                chart.data.datasets[0].counts = rows.map(function(r){ return r.count; });
                chart.update();
                $("#staffSalesEmpty").toggleClass("d-none", rows.length > 0).toggleClass("d-flex", rows.length === 0);
            })
            .fail(function(xhr){ $("#staffSalesError").text((xhr.responseJSON && xhr.responseJSON.error) || "Could not load sales by staff."); });
    }
    $("#staffSalesPeriods").on("click", "button", function(){
        const period = $(this).data("period");
        $(this).addClass("active").siblings().removeClass("active");
        $("#staffSalesCustom").toggleClass("d-none", period !== "custom");
        if(period === "custom"){ $("#staffSalesCustom").trigger("submit"); return; }
        [from, to] = preset(period);
        load();
    });
    $("#staffSalesCustom").on("submit", function(event){
        event.preventDefault();
        const f = $("#staffSalesFrom").val(), t = $("#staffSalesTo").val();
        if(!f || !t || f > t){ $("#staffSalesError").text("From date must be on or before To date."); return; }
        from = f; to = t;
        load();
    });
    $("#staffSalesMode").on("click", "button", function(){
        mode = $(this).data("mode");
        $(this).addClass("active").siblings().removeClass("active");
        load();
    });
    [from, to] = preset("monthly");
    load();
});
</script>
';
}

// Super Admin notice for company admins: a popup the first time an admin logs in while
// it is published; never again for that admin.
require_once 'includes/system_notice_helper.php';
if (is_admin_user() && !is_super_admin_user() && !is_manager_user() && !$dashboard_is_agent) {
    $admin_notice = system_notice_active($conn, 'admin');
    if ($admin_notice && system_notice_mark_seen($conn, (int)$admin_notice['id'], (int)$user_id)) {
        $page_script .= '
<div class="modal fade" id="systemNoticeModal" tabindex="-1" role="dialog" aria-labelledby="systemNoticeTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary">
                <h5 class="modal-title" id="systemNoticeTitle"><i class="fas fa-bullhorn mr-2"></i>' . htmlspecialchars($admin_notice['title']) . '</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">' . nl2br(htmlspecialchars($admin_notice['message'])) . '</div>
            <div class="modal-footer"><button type="button" class="btn btn-primary" data-dismiss="modal">OK</button></div>
        </div>
    </div>
</div>
<script>$(function(){ $("#systemNoticeModal").modal("show"); });</script>
';
    }
}

require_once 'includes/footer.php';
?>
