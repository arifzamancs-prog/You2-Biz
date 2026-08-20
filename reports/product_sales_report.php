<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/printing_helper.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
ensure_invoice_posting_columns($conn);
$company_profile = printing_company_profile_data($conn);

$from_date = trim($_GET['from_date'] ?? '');
$to_date = trim($_GET['to_date'] ?? '');

$sql = "SELECT
            p.id AS product_id,
            p.product_name,
            COUNT(DISTINCT i.id) AS total_invoice,
            COALESCE(SUM(ii.quantity),0) AS total_qty,
            COALESCE(SUM(ii.total_price),0) AS total_amount
        FROM invoice_items ii
        INNER JOIN invoices i
            ON i.id = ii.invoice_id
        INNER JOIN products p
            ON p.id = ii.product_id
        WHERE i.user_id=?
        AND i.accounting_status='posted'";

if($from_date !== '' && $to_date !== ''){
    $sql .= " AND i.invoice_date BETWEEN ? AND ?";
}elseif($from_date !== ''){
    $sql .= " AND i.invoice_date >= ?";
}elseif($to_date !== ''){
    $sql .= " AND i.invoice_date <= ?";
}

$sql .= " GROUP BY p.id, p.product_name
          ORDER BY total_qty DESC, total_amount DESC, p.product_name ASC";

$stmt = mysqli_prepare($conn, $sql);

if($from_date !== '' && $to_date !== ''){
    mysqli_stmt_bind_param($stmt, "iss", $user_id, $from_date, $to_date);
}elseif($from_date !== ''){
    mysqli_stmt_bind_param($stmt, "is", $user_id, $from_date);
}elseif($to_date !== ''){
    mysqli_stmt_bind_param($stmt, "is", $user_id, $to_date);
}else{
    mysqli_stmt_bind_param($stmt, "i", $user_id);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$items = [];
$total_products = 0;
$grand_qty = 0;
$grand_amount = 0;

while($row = mysqli_fetch_assoc($result)){
    $items[] = $row;
    $total_products++;
    $grand_qty += (float)$row['total_qty'];
    $grand_amount += (float)$row['total_amount'];
}

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
                                href="product_sales_report.php"
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
                <div class="report-print-title">Product Sales Report</div>
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

                    Product Sales Report

                </h3>

            </div>

            <div class="card-body">

                <table
                    id="example1"
                    class="table table-bordered table-striped">

                    <thead>

                    <tr>

                        <th>Product</th>
                        <th>Invoices</th>
                        <th>Sold Qty</th>
                        <th>Total Sale</th>

                    </tr>

                    </thead>

                    <tbody>

                    <?php if(empty($items)){ ?>

                    <tr>

                        <td colspan="4" class="text-center text-muted">
                            No product sales found for selected date range.
                        </td>

                    </tr>

                    <?php } ?>

                    <?php foreach($items as $row){ ?>

                    <tr>

                        <td>
                            <?= htmlspecialchars($row['product_name']); ?>
                        </td>

                        <td>
                            <?= (int)$row['total_invoice']; ?>
                        </td>

                        <td>
                            <?= number_format((float)$row['total_qty'], 2); ?>
                        </td>

                        <td>
                            <?= number_format((float)$row['total_amount'], 2); ?>
                        </td>

                    </tr>

                    <?php } ?>

                    </tbody>

                    <tfoot>

                    <tr>

                        <th class="text-right">
                            Total
                        </th>

                        <th>
                            <?= (int)$total_products; ?>
                        </th>

                        <th>
                            <?= number_format($grand_qty, 2); ?>
                        </th>

                        <th>
                            <?= number_format($grand_amount, 2); ?>
                        </th>

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

    #example1{ border-collapse:collapse !important; font-size:10px; margin:0 !important; width:100% !important; }
    #example1 thead{ display:table-header-group; }
    #example1 tr{ break-inside:avoid; page-break-inside:avoid; }
    #example1 th, #example1 td{ border:1px solid #cbd5e1 !important; padding:7px 8px !important; vertical-align:middle !important; }
    #example1 th{ background:#eaf1fb !important; color:#1e3a5f !important; font-size:9px; font-weight:700; text-transform:uppercase; }
    #example1 tbody tr:nth-child(even){ background:#f8fafc !important; }
    #example1 tfoot th{ background:#dbeafe !important; color:#102a43 !important; font-weight:800; }
}
</style>
';

require_once '../includes/footer.php';
?>
