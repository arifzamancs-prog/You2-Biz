<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_category_helper.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = $_SESSION['user_id'];
ensure_fifo_inventory_tables($conn);
ensure_product_subcategory_schema($conn);

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
            p.product_name,
            p.sku,
            p.sub_category,
            c.category_name

        FROM purchase_items pi

        LEFT JOIN products p
        ON p.id = pi.product_id

        LEFT JOIN product_categories c
        ON c.id = p.category_id

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

$items_result = mysqli_stmt_get_result($stmt);
$items = [];
// A purchase can contain the same product/code on multiple lines (for
// example when it was entered variant-wise).  The details page shows that
// code once, with its total quantity and cost.
while($row = mysqli_fetch_assoc($items_result)){
    $key = trim((string)($row['sku'] ?? '')) !== ''
        ? 'code:' . $row['sku']
        : 'product:' . (int)$row['product_id'];
    if(!isset($items[$key])){
        $row['variant_quantities'] = [];
        $items[$key] = $row;
    }else{
        $items[$key]['quantity'] += (float)$row['quantity'];
        $items[$key]['total_cost'] += (float)$row['total_cost'];
    }
    $variant = trim((string)($row['variant_name'] ?? ''));
    if($variant !== ''){
        $items[$key]['variant_quantities'][$variant] = ($items[$key]['variant_quantities'][$variant] ?? 0) + (float)$row['quantity'];
    }
}
foreach($items as &$item){
    $item['unit_cost'] = (float)$item['quantity'] > 0
        ? (float)$item['total_cost'] / (float)$item['quantity']
        : 0;
    $item['variant_summary'] = implode(' || ', array_map(
        static function($name, $qty){ return $name . ': ' . number_format($qty, 0); },
        array_keys($item['variant_quantities']),
        $item['variant_quantities']
    ));
}
unset($item);

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
                <h3 class="card-title float-none mb-1 font-weight-bold text-dark"><i class="fas fa-file-invoice mr-2 text-primary"></i>Purchase Details</h3>
                <div class="text-muted small">Purchase No. <?= htmlspecialchars($purchase['purchase_no']); ?></div>
            </div>
            <a href="print_purchase.php?id=<?= (int)$purchase_id; ?>" target="_blank" class="btn btn-primary btn-sm px-3"><i class="fas fa-print mr-1"></i> Print</a>
        </div>
    </div>

    <div class="card-body pt-0">
        <div class="row mb-4">
            <div class="col-lg-7 mb-3 mb-lg-0">
                <div class="border rounded h-100 p-3 bg-light">
                    <div class="text-uppercase small font-weight-bold text-muted mb-3"><?= supplier_display_text('Supplier'); ?> Information</div>
                    <div class="row">
                        <div class="col-sm-7 mb-3 mb-sm-0">
                            <div class="small text-muted mb-1"><?= supplier_display_text('Supplier'); ?></div>
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
            <style>
                .purchase-detail-items{width:100%;table-layout:auto}
                .purchase-detail-items th,.purchase-detail-items td{vertical-align:middle}
                .purchase-detail-items th{white-space:nowrap}
                .purchase-detail-items tbody td:first-child{min-width:260px;overflow-wrap:anywhere}
                .purchase-detail-items th:nth-child(n+3),.purchase-detail-items tbody td:nth-child(n+3){width:1%;white-space:nowrap}
                .purchase-detail-items tfoot td{white-space:nowrap}
            </style>
            <table class="table table-hover mb-0 purchase-detail-items">
                <thead class="bg-dark">
                    <tr>
                        <th class="border-0">Product</th>
                        <th class="border-0">Category</th>
                        <th class="border-0 text-center">Quantity</th>
                        <th class="border-0 text-right">Cost Price</th>
                        <th class="border-0 text-right">Line Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($items as $row){ ?>
                    <tr>
                        <td><div class="font-weight-bold"><?= htmlspecialchars($row['product_name'] ?: 'Deleted Product'); ?></div><?php if(!empty($row['variant_summary'])){ ?><div class="small text-muted mt-1"><?= htmlspecialchars($row['variant_summary']); ?></div><?php } ?></td>
                        <td><?= htmlspecialchars($row['category_name'] ?: '—'); ?><?php if(!empty($row['sub_category'])){ ?><div class="small text-muted mt-1"><?= htmlspecialchars($row['sub_category']); ?></div><?php } ?></td>
                        <td class="text-center"><?= number_format((float)$row['quantity'], 0); ?></td>
                        <td class="text-right">BDT <?= number_format((float)$row['unit_cost'], 2); ?></td>
                        <td class="text-right font-weight-bold">BDT <?= number_format((float)$row['total_cost'], 2); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
                <tfoot class="bg-light">
                    <tr><td colspan="4" class="text-right font-weight-bold">Grand Total</td><td class="text-right font-weight-bold text-dark">BDT <?= number_format((float)$purchase['total_amount'], 2); ?></td></tr>
                    <tr><td colspan="4" class="text-right text-success font-weight-bold">Paid Amount</td><td class="text-right text-success font-weight-bold">BDT <?= number_format((float)$purchase['paid_amount'], 2); ?></td></tr>
                    <tr><td colspan="4" class="text-right text-danger font-weight-bold">Due Amount</td><td class="text-right text-danger font-weight-bold">BDT <?= number_format((float)$purchase['due_amount'], 2); ?></td></tr>
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
