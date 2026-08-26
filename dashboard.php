<?php

require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/wallet_helper.php';
require_once 'includes/invoice_posting_helper.php';
require_once 'includes/customer_opening_due_helper.php';
require_once 'includes/customer_due_allocation_helper.php';

$user_id = (int)$_SESSION['user_id'];
$user_name = $_SESSION['login_name'] ?? $_SESSION['user_name'] ?? 'User';
$dashboard_is_agent = is_agent_user();

ensure_default_cash_wallet($conn, $user_id);
ensure_invoice_posting_columns($conn);
ensure_customer_opening_due_tables($conn);

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
    "SELECT COALESCE(SUM(total_amount),0)
     FROM invoices
     WHERE user_id=?
     AND accounting_status='posted'
     AND invoice_date = CURDATE()",
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
        id,
        invoice_no,
        invoice_date,
        customer_name,
        total_amount,
        due_amount,
        payment_status
     FROM invoices
     WHERE user_id=?
     ORDER BY id DESC
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
$month_purchase_map = [];

for($i = 5; $i >= 0; $i--){
    $key = date('Y-m', strtotime("-{$i} months"));
    $month_labels[$key] = date('M Y', strtotime($key . '-01'));
    $month_sales_map[$key] = 0;
    $month_purchase_map[$key] = 0;
}

$start_month = array_key_first($month_labels) . '-01';

$sales_rows = dashboard_rows(
    $conn,
    "SELECT
        DATE_FORMAT(invoice_date,'%Y-%m') AS month_key,
        COALESCE(SUM(total_amount),0) AS total
     FROM invoices
     WHERE user_id=?
     AND accounting_status='posted'
     AND invoice_date >= ?
     GROUP BY DATE_FORMAT(invoice_date,'%Y-%m')",
    "is",
    [$user_id, $start_month]
);

foreach($sales_rows as $row){
    if(isset($month_sales_map[$row['month_key']])){
        $month_sales_map[$row['month_key']] = (float)$row['total'];
    }
}

$purchase_rows = dashboard_rows(
    $conn,
    "SELECT
        DATE_FORMAT(purchase_date,'%Y-%m') AS month_key,
        COALESCE(SUM(total_amount),0) AS total
     FROM purchases
     WHERE user_id=?
     AND purchase_date >= ?
     GROUP BY DATE_FORMAT(purchase_date,'%Y-%m')",
    "is",
    [$user_id, $start_month]
);

foreach($purchase_rows as $row){
    if(isset($month_purchase_map[$row['month_key']])){
        $month_purchase_map[$row['month_key']] = (float)$row['total'];
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
        <a href="sales/invoice_list.php" class="dashboard-card-link agent-dashboard-clickable">
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

    <div class="col-lg-3 col-6">
        <a href="reports/customer_due_report.php" class="dashboard-card-link">
        <div class="small-box bg-danger">
            <div class="inner">
                <h3>BDT <?= number_format($customer_due,2); ?></h3>
                <p>Customer Due</p>
            </div>
            <div class="icon">
                <i class="fas fa-hand-holding-usd"></i>
            </div>
        </div>
        </a>
    </div>

</div>

<div class="row">

    <div class="col-lg-3 col-6">
        <a href="expenses/index.php" class="dashboard-card-link">
        <div class="info-box">
            <span class="info-box-icon bg-primary">
                <i class="fas fa-coins"></i>
            </span>
            <div class="info-box-content">
                <span class="info-box-text">Today's Expense</span>
                <span class="info-box-number">BDT <?= number_format($today_expense,2); ?></span>
            </div>
        </div>
        </a>
    </div>

    <div class="col-lg-3 col-6">
        <a href="suppliers/supplier_payment.php" class="dashboard-card-link">
        <div class="info-box">
            <span class="info-box-icon bg-danger">
                <i class="fas fa-truck-loading"></i>
            </span>
            <div class="info-box-content">
                <span class="info-box-text">Supplier Due</span>
                <span class="info-box-number">BDT <?= number_format($supplier_due,2); ?></span>
            </div>
        </div>
        </a>
    </div>

    <div class="col-lg-3 col-6">
        <a href="reports/stock_alert_report.php" class="dashboard-card-link">
        <div class="info-box">
            <span class="info-box-icon bg-warning">
                <i class="fas fa-exclamation-triangle"></i>
            </span>
            <div class="info-box-content">
                <span class="info-box-text">Low Stock</span>
                <span class="info-box-number"><?= (int)$low_stock; ?> Products</span>
            </div>
        </div>
        </a>
    </div>

    <div class="col-lg-3 col-6">
        <div class="info-box">
            <span class="info-box-icon bg-success">
                <i class="fas fa-users"></i>
            </span>
            <div class="info-box-content">
                <span class="info-box-text">
                    <a href="customers/index.php" class="dashboard-split-link">
                        Customers
                    </a>
                    <?php if(products_module_enabled()){ ?>
                    /
                    <a href="products/index.php" class="dashboard-split-link">
                        Products
                    </a>
                    <?php } ?>
                </span>
                <span class="info-box-number">
                    <a href="customers/index.php" class="dashboard-split-link">
                        <?= (int)$total_customers; ?>
                    </a>
                    <?php if(products_module_enabled()){ ?>
                    /
                    <a href="products/index.php" class="dashboard-split-link">
                        <?= (int)$total_products; ?>
                    </a>
                    <?php } ?>
                </span>
            </div>
        </div>
    </div>

</div>

<div class="row">

    <div class="col-lg-8">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Sales vs Purchases
                </h3>
            </div>

            <div class="card-body">
                <div style="height:320px;">
                    <canvas id="salesPurchaseChart"></canvas>
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

    <div class="col-lg-7">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Recent Invoices
                </h3>
                <div class="card-tools">
                    <a href="sales/invoice_list.php" class="btn btn-tool">
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
                            <a href="sales/view_invoice.php?id=<?= (int)$invoice['id']; ?>">
                                <?= htmlspecialchars($invoice['invoice_no']); ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars(app_date($invoice['invoice_date'])); ?></td>
                        <td><?= htmlspecialchars($invoice['customer_name']); ?></td>
                        <td>BDT <?= number_format($invoice['total_amount'],2); ?></td>
                        <td>
                            <?php if($invoice['payment_status'] == 'paid'){ ?>
                                <span class="badge badge-success">Paid</span>
                            <?php }elseif($invoice['payment_status'] == 'partial'){ ?>
                                <span class="badge badge-warning">Partial</span>
                            <?php }else{ ?>
                                <span class="badge badge-danger">Due</span>
                            <?php } ?>
                        </td>
                    </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>

        </div>

    </div>

    <div class="col-lg-5">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Top Due Customers
                </h3>
                <div class="card-tools">
                    <a href="reports/customer_due_report.php" class="btn btn-tool">
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>

            <div class="card-body p-0">
                <table class="table table-striped table-sm mb-0">
                    <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Phone</th>
                        <th>Due</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if(empty($top_due_customers)){ ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-3">
                            No customer due found.
                        </td>
                    </tr>
                    <?php } ?>
                    <?php foreach($top_due_customers as $customer){ ?>
                    <tr>
                        <td><?= htmlspecialchars($customer['customer_name']); ?></td>
                        <td><?= htmlspecialchars($customer['phone']); ?></td>
                        <td>
                            <strong>BDT <?= number_format($customer['total_due'],2); ?></strong>
                        </td>
                        <td class="text-right">
                            <a
                                href="customers/customer_ledger.php?id=<?= (int)$customer['id']; ?>"
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

</div>

<div class="row">

    <div class="col-lg-6">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Low Stock Watch
                </h3>
                <div class="card-tools">
                    <a href="reports/stock_alert_report.php" class="btn btn-tool">
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>

            <div class="card-body p-0">
                <table class="table table-striped table-sm mb-0">
                    <thead>
                    <tr>
                        <th>Product</th>
                        <th>Stock</th>
                        <th>Minimum</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if(empty($low_stock_products)){ ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-3">
                            Stock levels look healthy.
                        </td>
                    </tr>
                    <?php } ?>
                    <?php foreach($low_stock_products as $product){ ?>
                    <tr>
                        <td><?= htmlspecialchars($product['product_name']); ?></td>
                        <td><?= number_format($product['current_stock'],0); ?></td>
                        <td><?= number_format($product['minimum_stock'],0); ?></td>
                        <td>
                            <?php if((float)$product['current_stock'] <= 0){ ?>
                                <span class="badge badge-danger">Out</span>
                            <?php }else{ ?>
                                <span class="badge badge-warning">Low</span>
                            <?php } ?>
                        </td>
                    </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>

        </div>

    </div>

    <div class="col-lg-6">

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
                    $transaction_labels = [
                        'money_in' => 'Money In',
                        'expense' => 'Expense',
                        'transfer' => 'Transfer',
                        'transfer_in' => 'Transfer In',
                        'transfer_out' => 'Transfer Out',
                        'sales_invoice' => 'Sales Invoice',
                        'receive_payment' => 'Receive Due Payment',
                        'purchase' => 'Purchase',
                        'supplier_payment' => 'Supplier Due Payment'
                    ];

                    $income_types = [
                        'money_in',
                        'transfer_in',
                        'sales_invoice',
                        'receive_payment'
                    ];

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
                                    <?= htmlspecialchars(
                                        $transaction_labels[$transaction['transaction_type']]
                                        ?? $transaction['transaction_type']
                                    ); ?>
                                </span>
                            <?php }elseif($transaction['transaction_type'] == 'expense'){ ?>
                                <span class="badge badge-danger">Expense</span>
                            <?php }else{ ?>
                                <span class="badge badge-secondary">
                                    <?= htmlspecialchars(
                                        $transaction_labels[$transaction['transaction_type']]
                                        ?? $transaction['transaction_type']
                                    ); ?>
                                </span>
                            <?php } ?>
                        </td>
                        <td><?= htmlspecialchars($wallet_text); ?></td>
                        <td>
                            <strong class="<?= $transaction['transaction_type'] == 'transfer' ? 'text-info' : ($is_income ? 'text-success' : 'text-danger'); ?>">
                                <?= $transaction['transaction_type'] == 'transfer' ? '' : ($is_income ? '+' : '-'); ?>
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
    const salesPurchaseCanvas = document.getElementById("salesPurchaseChart");
    const walletCanvas = document.getElementById("walletChart");

    if(salesPurchaseCanvas){
        new Chart(salesPurchaseCanvas, {
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
                        label: "Purchases",
                        data: ' . json_encode(array_values($month_purchase_map)) . ',
                        backgroundColor: "#ffc107"
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
