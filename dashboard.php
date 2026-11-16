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

$user_id = (int)$_SESSION['user_id'];
$user_name = $_SESSION['login_name'] ?? $_SESSION['user_name'] ?? 'User';
$dashboard_is_agent = is_agent_user();
$dashboard_is_staff_login = is_manager_user();
ensure_branch_accounting_columns($conn, $user_id);
$wallet_scope = branch_scope_sql($conn);
$booking_scope = branch_scope_sql($conn, 'bi');
$expense_scope = branch_scope_sql($conn);
$invoice_scope = branch_scope_sql($conn);
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
        'supplier_payment' => 'Supplier Due Payment',
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
            return 'Supplier Due Payment';
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
            'supplier_payments' => 'Supplier Due Payment',
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

$total_customers = dashboard_value(
    $conn,
    "SELECT COUNT(*)
     FROM customers
     WHERE user_id=?",
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
        p.product_name,
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
    'supplier_due' => ['label' => 'Supplier Due', 'color' => '#6f42c1'],
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
</style>

<div class="<?= $dashboard_is_agent ? 'agent-dashboard' : ''; ?>">

<div class="row align-items-center mb-3">

    <div class="col-md-8">

        <h1 class="mb-1">
            <?= $dashboard_is_staff_login ? 'Branch Dashboard' : 'Dashboard'; ?>
        </h1>

        <p class="text-muted mb-0">
            Welcome back, <?= htmlspecialchars($user_name); ?>.
        </p>

    </div>

    <div class="col-md-4 text-md-right mt-3 mt-md-0">

        <?php if (!empty($dashboard_travel_plane)) { ?>
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
        <?php } ?>

        <?php if(sales_module_enabled()){ ?>
            <a href="sales/create_invoice.php" class="btn btn-primary agent-dashboard-clickable">
                <i class="fas fa-file-invoice"></i>
                Create Invoice
            </a>
        <?php } ?>

    </div>

</div>

<div class="row">

    <div class="col-lg-3 col-6">
        <a href="wallets/index.php" class="dashboard-card-link">
        <div class="small-box bg-info">
            <div class="inner">
                <h3>BDT <?= number_format($total_wallet_balance,2); ?></h3>
                <p>Wallet Balance</p>
            </div>
            <div class="icon">
                <i class="fas fa-wallet"></i>
            </div>
        </div>
        </a>
    </div>

    <div class="col-lg-3 col-6">
        <a href="<?= htmlspecialchars($dashboard_invoice_list_url); ?>" class="dashboard-card-link agent-dashboard-clickable">
        <div class="small-box bg-success">
            <div class="inner">
                <h3>BDT <?= number_format($total_sales,2); ?></h3>
                <p>Today's Sale</p>
            </div>
            <div class="icon">
                <i class="fas fa-chart-line"></i>
            </div>
        </div>
        </a>
    </div>

    <div class="col-lg-3 col-6">
        <a href="expenses/index.php" class="dashboard-card-link">
        <div class="small-box bg-danger">
            <div class="inner">
                <h3>BDT <?= number_format($today_expense,2); ?></h3>
                <p>Today's Expense</p>
            </div>
            <div class="icon">
                <i class="fas fa-receipt"></i>
            </div>
        </div>
        </a>
    </div>

    <div class="col-lg-3 col-6">
        <a href="purchases/index.php" class="dashboard-card-link">
        <div class="small-box bg-warning">
            <div class="inner">
                <h3>BDT <?= number_format($total_purchases,2); ?></h3>
                <p>Today's Purchases</p>
            </div>
            <div class="icon">
                <i class="fas fa-shopping-basket"></i>
            </div>
        </div>
        </a>
    </div>

</div>

<div class="row">

    <div class="col-lg-8">

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

                <div style="height:320px;">
                    <canvas id="businessChart"></canvas>
                </div>
            </div>

        </div>

    </div>

    <div class="col-lg-4">

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
                <div style="height:320px;">
                    <canvas id="walletChart"></canvas>
                </div>
            </div>

        </div>

    </div>

</div>

<div class="row">

    <div class="col-lg-12">

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

            <div class="card-body p-0">
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
                        <td><?= htmlspecialchars($invoice['customer_name']); ?></td>
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

</div>

<div class="row">

    <div class="col-lg-12">

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

            <div class="card-body p-0">
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

</div>

</div>

<?php

$page_script = '
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

    $("#businessChartPeriods").on("click", "button", function(){
        const period = businessData.periods[$(this).data("period")];

        if(!businessChart || !period){
            return;
        }

        $(this).addClass("active").siblings().removeClass("active");
        businessChart.data.labels = period.labels;
        businessKeys.forEach(function(key, index){
            businessChart.data.datasets[index].data = period.data[key];
        });
        businessChart.update();
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
                        position: "bottom"
                    }
                }
            }
        });
    }
});
</script>
';

require_once 'includes/footer.php';
?>
