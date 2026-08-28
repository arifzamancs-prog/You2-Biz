<?php

require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/wallet_helper.php';
require_once 'includes/invoice_posting_helper.php';
require_once 'includes/customer_opening_due_helper.php';
require_once 'includes/customer_due_allocation_helper.php';
require_once 'includes/booking_invoice_helper.php';

$user_id = (int)$_SESSION['user_id'];
$user_name = $_SESSION['login_name'] ?? $_SESSION['user_name'] ?? 'User';
$dashboard_is_agent = is_agent_user();
$dashboard_is_staff_login = is_manager_user();

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
     AND status='active'",
    "i",
    [$user_id]
);

$total_sales = dashboard_value(
    $conn,
    "SELECT COALESCE(SUM(bi.amount),0)
     FROM booking_invoices bi
     LEFT JOIN booking_invoice_types bit
        ON bit.user_id=bi.user_id
        AND bit.type_key=bi.invoice_type
     WHERE bi.user_id=?
     AND bi.status='confirmed'
     AND COALESCE(bit.behavior,'income')='income'
     AND bi.invoice_date = CURDATE()",
    "i",
    [$user_id]
);

$total_purchases = dashboard_value(
    $conn,
    "SELECT COALESCE(SUM(total_amount),0)
     FROM purchases
     WHERE user_id=?
     AND purchase_date = CURDATE()",
    "i",
    [$user_id]
);

$total_expense = dashboard_value(
    $conn,
    "SELECT COALESCE(SUM(amount),0)
     FROM expenses
     WHERE user_id=?
     AND approval_status='approved'",
    "i",
    [$user_id]
);

$customer_due = customer_due_report_total($conn, $user_id);

$supplier_due = dashboard_value(
    $conn,
    "SELECT COALESCE(SUM(due_amount),0)
     FROM purchases
     WHERE user_id=?",
    "i",
    [$user_id]
);

$month_sales = dashboard_value(
    $conn,
    "SELECT COALESCE(SUM(total_amount),0)
     FROM invoices
     WHERE user_id=?
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
     AND status='active'",
    "i",
    [$user_id]
);

$low_stock = dashboard_value(
    $conn,
    "SELECT COUNT(*)
     FROM products
     WHERE user_id=?
     AND status='active'
     AND current_stock <= minimum_stock",
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
     AND approval_status='approved'
     AND txn_date = CURDATE()",
    "i",
    [$user_id]
);

$recent_invoices = dashboard_rows(
    $conn,
    "SELECT
        bi.id,
        bi.invoice_no,
        bi.invoice_date,
        bi.amount,
        bi.status,
        c.customer_name
     FROM booking_invoices bi
     LEFT JOIN customers c ON c.id=bi.customer_id
     WHERE bi.user_id=?
     ORDER BY bi.invoice_date DESC, bi.id DESC
     LIMIT 6",
    "i",
    [$user_id]
);

$low_stock_products = dashboard_rows(
    $conn,
    "SELECT
        product_name,
        current_stock,
        minimum_stock
     FROM products
     WHERE user_id=?
     AND status='active'
     AND current_stock <= minimum_stock
     ORDER BY current_stock ASC, product_name ASC
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
     ORDER BY t.id DESC
     LIMIT 6",
    "i",
    [$user_id]
);

$month_labels = [];
$month_sales_map = [];
$month_expense_map = [];

for($i = 5; $i >= 0; $i--){
    $key = date('Y-m', strtotime("-{$i} months"));
    $month_labels[$key] = date('M Y', strtotime($key . '-01'));
    $month_sales_map[$key] = 0;
    $month_expense_map[$key] = 0;
}

$start_month = array_key_first($month_labels) . '-01';

$sales_rows = dashboard_rows(
    $conn,
    "SELECT
        DATE_FORMAT(bi.invoice_date,'%Y-%m') AS month_key,
        COALESCE(SUM(bi.amount),0) AS total
     FROM booking_invoices bi
     LEFT JOIN booking_invoice_types bit
        ON bit.user_id=bi.user_id
        AND bit.type_key=bi.invoice_type
     WHERE bi.user_id=?
     AND bi.status='confirmed'
     AND COALESCE(bit.behavior,'income')='income'
     AND bi.invoice_date >= ?
     GROUP BY DATE_FORMAT(bi.invoice_date,'%Y-%m')",
    "is",
    [$user_id, $start_month]
);

foreach($sales_rows as $row){
    if(isset($month_sales_map[$row['month_key']])){
        $month_sales_map[$row['month_key']] = (float)$row['total'];
    }
}

$expense_rows = dashboard_rows(
    $conn,
    "SELECT
        DATE_FORMAT(txn_date,'%Y-%m') AS month_key,
        COALESCE(SUM(amount),0) AS total
     FROM expenses
     WHERE user_id=?
     AND approval_status='approved'
     AND txn_date >= ?
     GROUP BY DATE_FORMAT(txn_date,'%Y-%m')",
    "is",
    [$user_id, $start_month]
);

foreach($expense_rows as $row){
    if(isset($month_expense_map[$row['month_key']])){
        $month_expense_map[$row['month_key']] = (float)$row['total'];
    }
}

$wallet_labels = array_map(function($wallet){
    return $wallet['wallet_name'];
}, $wallets);

$wallet_balances = array_map(function($wallet){
    return (float)$wallet['balance'];
}, $wallets);

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
            Dashboard
        </h1>

        <p class="text-muted mb-0">
            Welcome back, <?= htmlspecialchars($user_name); ?>.
        </p>

    </div>

    <div class="col-md-4 text-md-right mt-3 mt-md-0">

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
        <a href="create_invoice/invoice_list.php" class="dashboard-card-link agent-dashboard-clickable">
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
                    Sales vs Expenses
                </h3>
            </div>

            <div class="card-body">
                <div style="height:320px;">
                    <canvas id="salesExpenseChart"></canvas>
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
                    <a href="create_invoice/invoice_list.php" class="btn btn-tool">
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
                            <a href="create_invoice/print.php?id=<?= (int)$invoice['id']; ?>" target="_blank" rel="noopener">
                                <?= htmlspecialchars($invoice['invoice_no']); ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars(app_date($invoice['invoice_date'])); ?></td>
                        <td><?= htmlspecialchars($invoice['customer_name']); ?></td>
                        <td>BDT <?= number_format($invoice['amount'],2); ?></td>
                        <td>
                            <?php $is_confirmed = ($invoice['status'] ?? 'pending') === 'confirmed'; ?>
                            <span class="badge badge-<?= $is_confirmed ? 'success' : 'warning'; ?>">
                                <?= $is_confirmed ? 'Confirmed' : 'Pending'; ?>
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
                        'invoice_income'
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
    const salesExpenseCanvas = document.getElementById("salesExpenseChart");
    const walletCanvas = document.getElementById("walletChart");

    if(salesExpenseCanvas){
        new Chart(salesExpenseCanvas, {
            type: "bar",
            data: {
                labels: ' . json_encode(array_values($month_labels)) . ',
                datasets: [
                    {
                        label: "Sales",
                        data: ' . json_encode(array_values($month_sales_map)) . ',
                        backgroundColor: "#28a745"
                    },
                    {
                        label: "Expenses",
                        data: ' . json_encode(array_values($month_expense_map)) . ',
                        backgroundColor: "#dc3545"
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }

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
