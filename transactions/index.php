<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/printing_helper.php';

$user_id = $_SESSION['user_id'];
$company_profile = printing_company_profile_data($conn);

$from_date = trim($_GET['from_date'] ?? '');
$to_date = trim($_GET['to_date'] ?? '');

$sql = "SELECT
            t.*,
            w.wallet_name,
            fw.wallet_name AS from_wallet,
            tw.wallet_name AS to_wallet,
            CASE
                WHEN t.transaction_type = 'sales_invoice'
                    THEN COALESCE(i.customer_name, sales_customer.customer_name, '')
                WHEN t.transaction_type = 'receive_payment'
                    THEN COALESCE(payment_customer.customer_name, direct_customer.customer_name, '')
                ELSE ''
            END AS customer_name
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
        LEFT JOIN invoices i
        ON i.id = t.reference_id
        AND t.transaction_type = 'sales_invoice'
        LEFT JOIN customers sales_customer
        ON sales_customer.id = i.customer_id
        LEFT JOIN customer_payments cp
        ON cp.id = t.reference_id
        AND t.transaction_type = 'receive_payment'
        LEFT JOIN customers payment_customer
        ON payment_customer.id = cp.customer_id
        LEFT JOIN customers direct_customer
        ON direct_customer.id = t.reference_id
        AND t.transaction_type = 'receive_payment'
        WHERE t.user_id=?";

if($from_date !== '' && $to_date !== ''){
    $sql .= " AND t.txn_date BETWEEN ? AND ?";
}elseif($from_date !== ''){
    $sql .= " AND t.txn_date >= ?";
}elseif($to_date !== ''){
    $sql .= " AND t.txn_date <= ?";
}

$sql .= " ORDER BY t.id DESC";

$stmt = mysqli_prepare($conn, $sql);

if($from_date !== '' && $to_date !== ''){
    mysqli_stmt_bind_param(
        $stmt,
        "iss",
        $user_id,
        $from_date,
        $to_date
    );
}elseif($from_date !== ''){
    mysqli_stmt_bind_param(
        $stmt,
        "is",
        $user_id,
        $from_date
    );
}elseif($to_date !== ''){
    mysqli_stmt_bind_param(
        $stmt,
        "is",
        $user_id,
        $to_date
    );
}else{
    mysqli_stmt_bind_param(
        $stmt,
        "i",
        $user_id
    );
}

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$transactions = [];
$total_amount = 0;

while($row = mysqli_fetch_assoc($result)){
    $transactions[] = $row;
    $total_amount += (float)$row['amount'];
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<section class="content">

    <div class="container-fluid">

        <div class="card">

            <div class="card-body">

                <form method="GET">

                    <div class="row">

                        <div class="col-md-3">

                            <label>From Date</label>

                            <input
                                type="date"
                                name="from_date"
                                class="form-control"
                                value="<?= htmlspecialchars($from_date); ?>">

                        </div>

                        <div class="col-md-3">

                            <label>To Date</label>

                            <input
                                type="date"
                                name="to_date"
                                class="form-control"
                                value="<?= htmlspecialchars($to_date); ?>">

                        </div>

                        <div class="col-md-2">

                            <label>&nbsp;</label>

                            <button
                                type="submit"
                                class="btn btn-primary btn-block">

                                Search

                            </button>

                        </div>

                        <div class="col-md-2">

                            <label>&nbsp;</label>

                            <button
                                type="button"
                                class="btn btn-info btn-block"
                                onclick="window.print()">

                                Print

                            </button>

                        </div>

                        <div class="col-md-2">

                            <label>&nbsp;</label>

                            <a
                                href="index.php"
                                class="btn btn-secondary btn-block">

                                Reset

                            </a>

                        </div>

                    </div>

                </form>

            </div>

        </div>

        <div class="card">

            <div class="report-print-header d-none">
                <h2><?= htmlspecialchars($company_profile['name']); ?></h2>
                <div><?= htmlspecialchars($company_profile['address']); ?></div>
                <div>
                    Phone: <?= htmlspecialchars($company_profile['phone']); ?>
                    |
                    Email: <?= htmlspecialchars($company_profile['email']); ?>
                </div>
                <div class="report-print-title">Transaction History</div>
                <?php if($from_date !== '' || $to_date !== ''){ ?>
                <div class="report-print-range">
                    Date:
                    <?= htmlspecialchars($from_date !== '' ? app_date($from_date) : 'Start'); ?>
                    to
                    <?= htmlspecialchars($to_date !== '' ? app_date($to_date) : 'Today'); ?>
                </div>
                <?php } ?>
                <div class="report-print-generated">
                    Printed: <?= htmlspecialchars(date('d M Y, h:i A')); ?>
                </div>
            </div>

            <div class="card-header">

                <h3 class="card-title">

                    <i class="fas fa-exchange-alt mr-2"></i>

                    Transaction History

                </h3>

            </div>

            <div class="card-body">

                <table
                    id="example1"
                    class="table table-bordered table-striped">

                    <thead>

                    <tr>
                        <th>Date</th>
                        <th>Txn No</th>
                        <th>Type</th>
                        <th>Customer</th>
                        <th>Wallet</th>
                        <th>Amount</th>
                        <th class="d-print-none">Note</th>
                    </tr>

                    </thead>

                    <tbody>

                    <?php if(empty($transactions)){ ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted">
                            No transaction found for selected date range.
                        </td>
                    </tr>
                    <?php } ?>

                    <?php foreach($transactions as $row){ ?>

                    <?php
                    $type_labels = [
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

                    $transaction_type = strtolower(trim((string)($row['transaction_type'] ?? '')));
                    $txn_no = trim((string)($row['txn_no'] ?? ''));
                    $note = trim((string)($row['note'] ?? ''));
                    $type_label = $type_labels[$transaction_type] ?? '';

                    // Older transaction rows may not have a saved type. Use their
                    // reference number or note so the history is always meaningful.
                    if($type_label === ''){
                        if(stripos($note, 'supplier due payment') !== false || stripos($txn_no, 'SPAY-') === 0){
                            $type_label = 'Supplier Due Payment';
                        }elseif(stripos($note, 'purchase') !== false || stripos($txn_no, 'PUR-') === 0){
                            $type_label = 'Purchase';
                        }elseif(stripos($note, 'invoice') !== false || stripos($txn_no, 'INV-') === 0){
                            $type_label = 'Invoice';
                        }elseif(stripos($note, 'transfer') !== false || stripos($txn_no, 'TRF-') === 0){
                            $type_label = 'Transfer';
                        }elseif(stripos($note, 'money in') !== false || stripos($txn_no, 'MIN-') === 0){
                            $type_label = 'Money In';
                        }elseif(stripos($note, 'expense') !== false || stripos($txn_no, 'EXP-') === 0){
                            $type_label = 'Expense';
                        }else{
                            $type_label = 'General Transaction';
                        }
                    }

                    $income_types = [
                        'money_in',
                        'transfer_in',
                        'sales_invoice',
                        'receive_payment'
                    ];

                    $is_income = in_array(
                        $transaction_type,
                        $income_types
                    );

                    $badge_class = $is_income ? 'badge-success' : 'badge-danger';

                    if($transaction_type == 'transfer_out'){
                        $badge_class = 'badge-warning';
                    }elseif($transaction_type == 'transfer_in'){
                        $badge_class = 'badge-info';
                    }elseif($transaction_type == 'transfer'){
                        $badge_class = 'badge-secondary';
                    }

                    $wallet_text = $row['wallet_name'];

                    if($transaction_type == 'transfer'){
                        $wallet_text =
                            ($row['from_wallet'] ?? '') .
                            ' -> ' .
                            ($row['to_wallet'] ?? '');
                    }
                    ?>

                    <tr>

                        <td>
                            <?= htmlspecialchars(app_date($row['txn_date'])); ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($row['txn_no']); ?>
                        </td>

                        <td>

                            <span class="badge transaction-type-badge <?= $badge_class; ?>">
                                <?= htmlspecialchars(
                                    $type_label
                                ); ?>
                            </span>

                        </td>

                        <td>
                            <?= htmlspecialchars($row['customer_name'] ?? ''); ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($wallet_text); ?>
                        </td>

                        <td>

                            <span class="text-dark font-weight-bold">
                                BDT <?= number_format($row['amount'],2); ?>
                            </span>

                        </td>

                        <td class="d-print-none">
                            <?= htmlspecialchars($row['note']); ?>
                        </td>

                    </tr>

                    <?php } ?>

                    </tbody>

                    <tfoot>
                    <tr>
                        <th colspan="5" class="text-right">Total</th>
                        <th>BDT <?= number_format($total_amount, 2); ?></th>
                        <th class="d-print-none"></th>
                    </tr>
                    </tfoot>

                </table>

            </div>

        </div>

    </div>

</section>

<?php
$page_script = '
<style>
@page{
    size:A4 portrait;
    margin:12mm;
}

@media print{
    html,
    body{
        background:#fff !important;
        color:#172033 !important;
        font-family:Arial, sans-serif;
        font-size:10px;
        line-height:1.35;
    }

    .main-header,
    .main-sidebar,
    .main-footer,
    .content-header,
    .card:first-child,
    .dataTables_length,
    .dataTables_filter,
    .dataTables_info,
    .dataTables_paginate,
    .d-print-none{
        display:none !important;
    }

    .content-wrapper,
    .content,
    .container-fluid{
        margin:0 !important;
        max-width:none !important;
        padding:0 !important;
        width:100% !important;
    }

    .card{
        border:none !important;
        box-shadow:none !important;
    }

    .card-header{
        display:none !important;
    }

    .table-responsive{
        overflow:visible !important;
    }

    .report-print-header{
        border-bottom:2px solid #1d4ed8;
        display:block !important;
        margin-bottom:12px;
        padding-bottom:9px;
        text-align:center;
    }

    .report-print-header h2{
        color:#102a43;
        font-size:22px;
        font-weight:700;
        letter-spacing:.2px;
        margin:0 0 4px;
    }

    .report-print-title{
        color:#1e3a5f;
        font-size:15px;
        letter-spacing:.08em;
        text-transform:uppercase;
        font-weight:800;
        margin-top:10px;
    }

    .report-print-range,
    .report-print-generated{
        color:#64748b;
        font-size:10px;
        margin-top:3px;
    }

    .report-print-generated{
        margin-top:2px;
    }

    #example1{
        border-collapse:collapse !important;
        font-size:9.5px;
        margin:0 !important;
        table-layout:auto;
        width:100% !important;
    }

    #example1 thead{
        display:table-header-group;
    }

    #example1 tr{
        break-inside:avoid;
        page-break-inside:avoid;
    }

    #example1 th,
    #example1 td{
        border:1px solid #cbd5e1 !important;
        padding:6px 7px !important;
        vertical-align:middle !important;
        white-space:normal !important;
    }

    #example1 th{
        background:#eaf1fb !important;
        color:#1e3a5f !important;
        font-size:9px;
        font-weight:700;
        text-transform:uppercase;
    }

    #example1 tbody tr:nth-child(even){
        background:#f8fafc !important;
    }

    #example1 tfoot th{
        background:#dbeafe !important;
        color:#102a43 !important;
        font-weight:800;
    }

    #example1 td:last-child,
    #example1 tfoot th:nth-child(2){
        text-align:right;
    }

    .text-success{
        color:#087f3d !important;
    }

    .text-danger{
        color:#b42318 !important;
    }

    .text-info{
        color:#075985 !important;
    }

    .transaction-type-badge{
        background:transparent !important;
        border:none !important;
        box-shadow:none !important;
        color:#212529 !important;
        font-size:inherit !important;
        font-weight:400 !important;
        padding:0 !important;
    }
}
</style>
<script>
(function(){
    var originalTitle = document.title;
    var printTitle = "Transaction History";

    window.addEventListener("beforeprint", function(){
        document.title = printTitle;
    });

    window.addEventListener("afterprint", function(){
        document.title = originalTitle;
    });
})();
</script>
';

require_once '../includes/footer.php';
?>
