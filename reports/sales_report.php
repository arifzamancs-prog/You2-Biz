<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/printing_helper.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = $_SESSION['user_id'];
ensure_invoice_posting_columns($conn);
$company_profile = printing_company_profile_data($conn);

$from_date = trim($_GET['from_date'] ?? '');
$to_date   = trim($_GET['to_date'] ?? '');

$sql = "SELECT *
        FROM invoices
        WHERE user_id = ?
        AND accounting_status='posted'";

if($from_date != '' && $to_date != ''){

    $sql .= " AND invoice_date BETWEEN ? AND ?";

}elseif($from_date != ''){

    $sql .= " AND invoice_date >= ?";

}elseif($to_date != ''){

    $sql .= " AND invoice_date <= ?";

}

$sql .= " ORDER BY invoice_date DESC, id DESC";

$stmt = mysqli_prepare($conn, $sql);

if($from_date != '' && $to_date != ''){

    mysqli_stmt_bind_param(
        $stmt,
        "iss",
        $user_id,
        $from_date,
        $to_date
    );

}elseif($from_date != ''){

    mysqli_stmt_bind_param(
        $stmt,
        "is",
        $user_id,
        $from_date
    );

}elseif($to_date != ''){

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

$invoices = [];
$total_sales   = 0;
$total_paid    = 0;
$total_due     = 0;
$total_invoice = 0;

while($row = mysqli_fetch_assoc($result)){

    $invoices[] = $row;
    $total_sales += (float)$row['total_amount'];
    $total_paid += (float)$row['paid_amount'];
    $total_due += (float)$row['due_amount'];
    $total_invoice++;

}
?>

<section class="content">

    <div class="container-fluid">

        <!-- Filter Card -->
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
                                href="sales_report.php"
                                class="btn btn-secondary btn-block">

                                Reset

                            </a>

                        </div>

                    </div>

                </form>

            </div>

        </div>

        <!-- Sales Table -->
        <div class="card">

            <div class="report-print-header d-none">
                <h2><?= htmlspecialchars($company_profile['name']); ?></h2>
                <div><?= htmlspecialchars($company_profile['address']); ?></div>
                <div>
                    Phone: <?= htmlspecialchars($company_profile['phone']); ?>
                    |
                    Email: <?= htmlspecialchars($company_profile['email']); ?>
                </div>
                <div class="report-print-title">Sales Report</div>
                <?php if($from_date !== '' || $to_date !== ''){ ?>
                <div class="report-print-range">
                    Date:
                    <?= htmlspecialchars($from_date !== '' ? app_date($from_date) : 'Start'); ?>
                    to
                    <?= htmlspecialchars($to_date !== '' ? app_date($to_date) : 'Today'); ?>
                </div>
                <?php } ?>
            </div>

            <div class="card-header">

                <h3 class="card-title">

                    Sales Report

                </h3>

            </div>

            <div class="card-body">

                <table
                    id="example1"
                    class="table table-bordered table-striped">

                    <thead>

                    <tr>

                        <th>Invoice</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Due</th>
                        <th>Status</th>

                    </tr>

                    </thead>

                    <tbody>

                    <?php if(empty($invoices)){ ?>

                    <tr>

                        <td colspan="7" class="text-center text-muted">
                            No sales found for selected date range.
                        </td>

                    </tr>

                    <?php } ?>

                    <?php
                    foreach($invoices as $row){
                    ?>

                    <tr>

                        <td>
                            <?= htmlspecialchars($row['invoice_no']); ?>
                        </td>

                        <td>
                            <?= htmlspecialchars(app_date($row['invoice_date'])); ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($row['customer_name'] ?? 'Walk-in Customer'); ?>
                        </td>

                        <td>
                            <?= number_format($row['total_amount'],2); ?>
                        </td>

                        <td>
                            <?= number_format($row['paid_amount'],2); ?>
                        </td>

                        <td>
                            <?= number_format($row['due_amount'],2); ?>
                        </td>

                        <td>

                            <?php if($row['payment_status']=='paid'){ ?>

                                <span class="badge report-status-badge badge-success">
                                    Paid
                                </span>

                            <?php }elseif($row['payment_status']=='partial'){ ?>

                                <span class="badge report-status-badge badge-warning">
                                    Partial
                                </span>

                            <?php }else{ ?>

                                <span class="badge report-status-badge badge-danger">
                                    Due
                                </span>

                            <?php } ?>

                        </td>

                    </tr>

                    <?php } ?>

                    </tbody>

                    <tfoot>

                    <tr>

                        <th colspan="3" class="text-right">
                            Total
                        </th>
                        <th><?= number_format($total_sales,2); ?></th>
                        <th><?= number_format($total_paid,2); ?></th>
                        <th><?= number_format($total_due,2); ?></th>
                        <th></th>

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
@page{ size:A4 portrait; margin:12mm; }
@media print{
    html, body{ background:#fff !important; color:#172033 !important; font-family:Arial,sans-serif; font-size:10px; line-height:1.35; }
    .main-header,
    .main-sidebar,
    .main-footer,
    .content-header,
    .card:first-child,
    .dataTables_length,
    .dataTables_filter,
    .dataTables_info,
    .dataTables_paginate{
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
        margin:0 0 4px;
    }

    .report-print-title{
        color:#1e3a5f;
        font-size:15px;
        font-weight:800;
        letter-spacing:.08em;
        margin-top:10px;
        text-transform:uppercase;
    }

    .report-print-range{
        color:#64748b;
        font-size:10px;
        margin-top:3px;
    }

    #example1{ border-collapse:collapse !important; font-size:9.5px; margin:0 !important; width:100% !important; }
    #example1 thead{ display:table-header-group; }
    #example1 tr{ break-inside:avoid; page-break-inside:avoid; }
    #example1 th, #example1 td{ border:1px solid #cbd5e1 !important; padding:6px 7px !important; vertical-align:middle !important; }
    #example1 th{ background:#eaf1fb !important; color:#1e3a5f !important; font-size:9px; font-weight:700; text-transform:uppercase; }
    #example1 tbody tr:nth-child(even){ background:#f8fafc !important; }
    #example1 tfoot th{ background:#dbeafe !important; color:#102a43 !important; font-weight:800; }

    .report-status-badge{
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
    var printTitle = "Sales Report";

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

