<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = $_SESSION['user_id'];

$purchase_id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;

/* Purchase */

$sql = "SELECT

            p.*,
            s.supplier_name,
            s.phone

        FROM purchases p

        LEFT JOIN suppliers s
        ON s.id = p.supplier_id

        WHERE p.id=?
        AND p.user_id=?";

$stmt = mysqli_prepare(
    $conn,
    $sql
);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $purchase_id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result =
    mysqli_stmt_get_result($stmt);

$purchase =
    mysqli_fetch_assoc($result);

if(!$purchase){

    die("Purchase Not Found");

}

/* Items */

$sql = "SELECT

            pi.*,
            p.product_name

        FROM purchase_items pi

        LEFT JOIN products p
        ON p.id = pi.product_id

        WHERE pi.purchase_id=?";

$stmt = mysqli_prepare(
    $conn,
    $sql
);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $purchase_id
);

mysqli_stmt_execute($stmt);

$items =
    mysqli_stmt_get_result($stmt);

?>

<?php
$payment_status = strtolower((string)($purchase['payment_status'] ?? 'due'));
$status_class = $payment_status === 'paid' ? 'success' : ($payment_status === 'partial' ? 'warning' : 'danger');
?>

<section class="content">
<div class="container-fluid">
<div class="card shadow-sm purchase-detail-card">
    <div class="card-header bg-white border-bottom-0 py-3">
        <div class="d-flex align-items-center justify-content-between flex-wrap">
            <div>
                <h3 class="card-title mb-1 font-weight-bold text-dark"><i class="fas fa-file-invoice mr-2 text-primary"></i>Purchase Details</h3>
                <div class="text-muted small">Purchase No. <?= htmlspecialchars($purchase['purchase_no']); ?></div>
            </div>
            <a href="print_purchase.php?id=<?= (int)$purchase_id; ?>" target="_blank" class="btn btn-primary btn-sm px-3"><i class="fas fa-print mr-1"></i> Print</a>
        </div>
    </div>

    <div class="card-body pt-0">
        <div class="row mb-4">
            <div class="col-lg-7 mb-3 mb-lg-0">
                <div class="border rounded h-100 p-3 bg-light">
                    <div class="text-uppercase small font-weight-bold text-muted mb-3">Supplier Information</div>
                    <div class="row">
                        <div class="col-sm-7 mb-3 mb-sm-0">
                            <div class="small text-muted mb-1">Supplier</div>
                            <div class="font-weight-bold text-dark"><?= htmlspecialchars($purchase['supplier_name'] ?: '—'); ?></div>
                        </div>
                        <div class="col-sm-5">
                            <div class="small text-muted mb-1">Phone</div>
                            <div class="font-weight-bold text-dark"><?= htmlspecialchars($purchase['phone'] ?: '—'); ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="border rounded h-100 p-3">
                    <div class="row">
                        <div class="col-6 border-right">
                            <div class="small text-muted mb-1">Purchase Date</div>
                            <div class="font-weight-bold"><?= app_date($purchase['purchase_date']); ?></div>
                        </div>
                        <div class="col-6 pl-4">
                            <div class="small text-muted mb-1">Payment Status</div>
                            <span class="badge badge-<?= $status_class; ?> px-2 py-1"><?= htmlspecialchars(ucfirst($payment_status)); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-responsive border rounded">
            <table class="table table-hover mb-0">
                <thead class="bg-dark">
                    <tr>
                        <th class="border-0">Product</th>
                        <th class="border-0 text-center" width="120">Quantity</th>
                        <th class="border-0 text-right" width="180">Cost Price</th>
                        <th class="border-0 text-right" width="190">Line Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php while($row = mysqli_fetch_assoc($items)){ ?>
                    <tr>
                        <td class="font-weight-bold"><?= htmlspecialchars($row['product_name'] ?: 'Deleted Product'); ?></td>
                        <td class="text-center"><?= number_format((float)$row['quantity'], 0); ?></td>
                        <td class="text-right">BDT <?= number_format((float)$row['unit_cost'], 2); ?></td>
                        <td class="text-right font-weight-bold">BDT <?= number_format((float)$row['total_cost'], 2); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
                <tfoot class="bg-light">
                    <tr><td colspan="3" class="text-right font-weight-bold">Grand Total</td><td class="text-right font-weight-bold text-dark">BDT <?= number_format((float)$purchase['total_amount'], 2); ?></td></tr>
                    <tr><td colspan="3" class="text-right text-success font-weight-bold">Paid Amount</td><td class="text-right text-success font-weight-bold">BDT <?= number_format((float)$purchase['paid_amount'], 2); ?></td></tr>
                    <tr><td colspan="3" class="text-right text-danger font-weight-bold">Due Amount</td><td class="text-right text-danger font-weight-bold">BDT <?= number_format((float)$purchase['due_amount'], 2); ?></td></tr>
                </tfoot>
            </table>
        </div>

        <?php if(!empty(trim((string)$purchase['notes']))){ ?>
            <div class="mt-4 border-left border-primary pl-3 py-1">
                <div class="small text-muted text-uppercase font-weight-bold mb-1">Notes</div>
                <div><?= nl2br(htmlspecialchars($purchase['notes'])); ?></div>
            </div>
        <?php } ?>
    </div>
</div>
</div>
</section>

<?php
require_once '../includes/footer.php';
?>
