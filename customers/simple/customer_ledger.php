<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/customer_opening_due_helper.php';
require_once '../includes/project_package_helper.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = $_SESSION['user_id'];

require_once '../includes/contact_code_helper.php';
contact_code_prepare($conn, 'customer', $user_id);
ensure_invoice_posting_columns($conn);
ensure_customer_opening_due_tables($conn);

$customer_id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;

/* Customer */

$sql = "SELECT *
        FROM customers
        WHERE id=?
        AND user_id=?";

$stmt = mysqli_prepare(
    $conn,
    $sql
);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $customer_id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result =
    mysqli_stmt_get_result($stmt);

$customer =
    mysqli_fetch_assoc($result);

if(!$customer){

    die("Customer Not Found");

}

/* Invoices */

$ledger = [];

// Same helper as the full Biz ledger page, which PHP may have declared already.
if(!function_exists('ledger_invoice_no_from_payment_reference')){
    function ledger_invoice_no_from_payment_reference($reference)
    {
        $reference = trim((string)$reference);
    
        if(preg_match('/^Invoice Payment - (INV-[0-9]+)/', $reference, $matches)){
            return $matches[1];
        }
    
        if(preg_match('/^Outstanding Amount - (INV-[0-9]+)/', $reference, $matches)){
            return $matches[1];
        }
    
        return '';
    }
}

$sql = "SELECT

            invoice_date,
            invoice_no,
            total_amount,
            id

        FROM invoices

        WHERE customer_id=?
        AND user_id=?
        AND accounting_status='posted'";

$stmt = mysqli_prepare(
    $conn,
    $sql
);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $customer_id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result =
    mysqli_stmt_get_result($stmt);

while(
    $row =
    mysqli_fetch_assoc($result)
){

    $ledger[] = [

        'trx_date' =>
            $row['invoice_date'],

        'type' =>
            'Invoice',

        'reference' =>
            $row['invoice_no'],

        'invoice_no' =>
            $row['invoice_no'],

        'sort_order' =>
            1,

        'reference_id' =>
            $row['id'],

        'debit' =>
            $row['total_amount'],

        'credit' =>
            0

    ];

}

/* Payments */

$sql = "SELECT

            payment_date,
            amount,
            note,
            id

        FROM customer_payments

        WHERE customer_id=?
        AND user_id=?";

$stmt = mysqli_prepare(
    $conn,
    $sql
);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $customer_id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result =
    mysqli_stmt_get_result($stmt);

while(
    $row =
    mysqli_fetch_assoc($result)
){

    $payment_invoice_no =
        ledger_invoice_no_from_payment_reference($row['note']);

    $ledger[] = [

        'trx_date' =>
            $row['payment_date'],

        'type' =>
            'Payment',

        'reference' =>
            $row['note'],

        'invoice_no' =>
            $payment_invoice_no,

        'sort_order' =>
            2,

        'reference_id' =>
            $row['id'],

        'debit' =>
            0,

        'credit' =>
            $row['amount']

    ];

}

/* Previous Due Entries */

$sql = "SELECT
            entry_date,
            due_no,
            amount,
            id
        FROM customer_opening_dues
        WHERE customer_id=?
        AND user_id=?";

$stmt = mysqli_prepare(
    $conn,
    $sql
);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $customer_id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result =
    mysqli_stmt_get_result($stmt);

while(
    $row =
    mysqli_fetch_assoc($result)
){

    $ledger[] = [

        'trx_date' =>
            $row['entry_date'],

        'type' =>
            'Previous Due',

        'reference' =>
            $row['due_no'],

        'invoice_no' =>
            '',

        'sort_order' =>
            0,

        'reference_id' =>
            $row['id'],

        'debit' =>
            $row['amount'],

        'credit' =>
            0

    ];

}

/* Merge Invoice + Same Invoice Payment */

$merged_ledger = [];

foreach($ledger as $entry){

    $merge_key = '';

    if(
        $entry['invoice_no'] !== '' &&
        in_array($entry['type'], ['Invoice', 'Payment'], true)
    ){
        $merge_key =
            $entry['trx_date'] . '|' .
            $entry['invoice_no'];
    }

    if($merge_key === ''){
        $merged_ledger[] = $entry;
        continue;
    }

    $existing_index = null;

    foreach($merged_ledger as $index => $merged_entry){
        if(($merged_entry['merge_key'] ?? '') === $merge_key){
            $existing_index = $index;
            break;
        }
    }

    if($existing_index === null){
        $entry['merge_key'] = $merge_key;

        if($entry['type'] === 'Payment'){
            $entry['type'] = 'Invoice';
            $entry['reference'] = $entry['invoice_no'] !== ''
                ? $entry['invoice_no']
                : $entry['reference'];
            $entry['sort_order'] = 1;
        }

        $merged_ledger[] = $entry;
        continue;
    }

    $merged_ledger[$existing_index]['debit'] +=
        (float)$entry['debit'];

    $merged_ledger[$existing_index]['credit'] +=
        (float)$entry['credit'];

    if(
        $merged_ledger[$existing_index]['invoice_no'] === '' &&
        $entry['invoice_no'] !== ''
    ){
        $merged_ledger[$existing_index]['invoice_no'] =
            $entry['invoice_no'];
    }

    if(
        $merged_ledger[$existing_index]['reference'] === '' ||
        str_starts_with(
            (string)$merged_ledger[$existing_index]['reference'],
            'Invoice Payment - '
        )
    ){
        $merged_ledger[$existing_index]['reference'] =
            $entry['invoice_no'] !== ''
                ? $entry['invoice_no']
                : $entry['reference'];
    }

    $merged_ledger[$existing_index]['type'] = 'Invoice';
    $merged_ledger[$existing_index]['sort_order'] = 1;
}

$ledger = $merged_ledger;

/* Sort By Date For Running Balance */

usort(

    $ledger,

    function($a,$b){

        $date_compare =
            strtotime($a['trx_date']) -
            strtotime($b['trx_date']);

        if($date_compare != 0){

            return $date_compare;

        }

        if($a['sort_order'] != $b['sort_order']){

            return $a['sort_order'] - $b['sort_order'];

        }

        return $a['reference_id'] - $b['reference_id'];

    }

);

/* Summary */

$total_sales = 0;
$total_paid = 0;

foreach($ledger as $entry){

    $total_sales +=
        $entry['debit'];

    $total_paid +=
        $entry['credit'];

}

$current_due =
    $total_sales -
    $total_paid;

$current_due_label =
    $current_due < 0
        ? 'Current Outstanding'
        : 'Current Due';

$current_due_display =
    $current_due < 0
        ? abs($current_due)
        : $current_due;

$running_ledger = [];
$balance = 0;

foreach($ledger as $row){

    $balance += (float)$row['debit'];
    $balance -= (float)$row['credit'];

    $row['running_balance'] = $balance;
    $running_ledger[] = $row;
}

$ledger = array_reverse($running_ledger);

/* Returnable Products */

$returnable_sql = "SELECT
                       i.invoice_date,
                       p.product_name,
                       SUM(CASE WHEN ii.quantity > 0 THEN ii.quantity ELSE 0 END) AS given_qty,
                       SUM(CASE WHEN ii.quantity < 0 THEN ABS(ii.quantity) ELSE 0 END) AS returned_qty
                   FROM invoice_items ii
                   INNER JOIN invoices i
                       ON i.id = ii.invoice_id
                   LEFT JOIN products p
                       ON p.id = ii.product_id
                   WHERE i.customer_id=?
                   AND i.user_id=?
                   AND i.accounting_status='posted'
                   AND ii.unit_price = 0
                   GROUP BY i.invoice_date, ii.product_id, p.product_name
                   HAVING given_qty > 0 OR returned_qty > 0
                   ORDER BY i.invoice_date ASC, p.product_name ASC";

$returnable_stmt = mysqli_prepare($conn, $returnable_sql);

mysqli_stmt_bind_param(
    $returnable_stmt,
    "ii",
    $customer_id,
    $user_id
);

mysqli_stmt_execute($returnable_stmt);

$returnable_products =
    mysqli_stmt_get_result($returnable_stmt);

// Inventory and Fashion Retail show this section only when the customer has returnable products.
$show_returnable_products = mysqli_num_rows($returnable_products) > 0
    || !in_array(project_package_company_type($conn, (int)$user_id), ['Stock Product', 'Fashion house'], true);

/* Purchased Products: every product line on the customer's posted invoices */

$show_purchase_history = in_array(project_package_company_type($conn, (int)$user_id), ['Stock Product', 'Fashion house'], true);
$show_purchase_branch = $show_purchase_history && company_multi_branch_enabled($conn, (int)$user_id);
$purchase_history = [];

if($show_purchase_history){
    $purchase_stmt = mysqli_prepare(
        $conn,
        "SELECT i.id AS invoice_id, i.invoice_no, i.invoice_date,
                COALESCE(NULLIF(b.branch_name, ''), 'Head Office') AS branch_name,
                p.product_name, p.sku, ii.variant_name, ii.quantity, ii.unit_price, ii.total_price
         FROM invoice_items ii
         INNER JOIN invoices i ON i.id = ii.invoice_id
         LEFT JOIN products p ON p.id = ii.product_id
         LEFT JOIN branches b ON b.id = i.branch_id
         WHERE i.customer_id=?
         AND i.user_id=?
         AND i.accounting_status='posted'
         ORDER BY i.invoice_date DESC, i.id DESC, ii.id ASC"
    );
    mysqli_stmt_bind_param($purchase_stmt, "ii", $customer_id, $user_id);
    mysqli_stmt_execute($purchase_stmt);
    $purchase_history = mysqli_fetch_all(mysqli_stmt_get_result($purchase_stmt), MYSQLI_ASSOC);
}

?>

<section class="content-header">

<div class="container-fluid">

<div class="row">

    <div class="col-sm-6">

        <h1>
            Customer Ledger
        </h1>

    </div>

    <div class="col-sm-6 text-right">

        <a
            href="print_ledger.php?id=<?php echo $customer_id; ?>"
            target="_blank"
            class="btn btn-primary">

            <i class="fas fa-print"></i>

            Print Ledger

        </a>

    </div>

</div>

</div>

</section>

<section class="content">

<div class="container-fluid">

<div class="card">

<div class="card-body">

<div class="row">

<div class="col-md-4">

<table class="table table-bordered">

<tr>

<th>
Customer
</th>

<td>

<?php
echo htmlspecialchars(
    contact_name_with_code($customer['customer_name'], $customer['customer_code'] ?? '')
);
?>

</td>

</tr>

<tr>

<th>
Phone
</th>

<td>

<?php
echo htmlspecialchars(
    $customer['phone']
);
?>

</td>

</tr>

<?php if(!empty($customer['address'])){ ?>

<tr>

<th>
Address
</th>

<td>

<?php
echo htmlspecialchars(
    $customer['address']
);
?>

</td>

</tr>

<?php } ?>

</table>

</div>

<div class="col-md-8">

<table class="table table-bordered">

<tr>

<th>
Total Sales
</th>

<td>

<?php
echo number_format(
    $total_sales,
    2
);
?>

</td>

<th>
Total Paid
</th>

<td>

<?php
echo number_format(
    $total_paid,
    2
);
?>

</td>

<th>
<?= htmlspecialchars($current_due_label); ?>
</th>

<td>

<?php
echo number_format(
    $current_due_display,
    2
);
?>

</td>

</tr>

</table>

</div>

</div>

<hr>

<table
id="example1"
data-desktop-table="true"
class="table table-bordered table-striped">

<thead>

<tr>

<th>Date</th>
<th>Reference</th>
<th>Sales</th>
<th>Paid</th>
<th>Current Due</th>

</tr>

</thead>

<tbody>

<?php

if(empty($ledger)){
?>

<tr>

<td colspan="5" class="text-center text-muted">
No ledger entries found.
</td>

</tr>

<?php
}

foreach($ledger as $row){

?>

<tr>

<td>

<?php
echo htmlspecialchars(app_date($row['trx_date']));
?>

</td>

<td>

<?php
echo htmlspecialchars($row['reference']);
?>

</td>

<td>

<?php
echo number_format(
    $row['debit'],
    2
);
?>

</td>

<td>

<?php
echo number_format(
    $row['credit'],
    2
);
?>

</td>

<td>

<?php
echo ledger_balance_display($row['running_balance']);
?>

</td>

</tr>

<?php } ?>

</tbody>

<tfoot>

<tr>

<th colspan="2" class="text-right">
Total
</th>

<th>
<?php echo number_format($total_sales,2); ?>
</th>

<th>
<?php echo number_format($total_paid,2); ?>
</th>

<th>
<?php echo number_format($current_due_display,2); ?>
</th>

</tr>

</tfoot>

</table>

<?php if($show_purchase_history){ ?>
<hr>

<h4>
    Purchased Products
</h4>

<table
id="purchaseHistoryTable"
class="table table-bordered table-striped">

<thead>

<tr>

<th>Date</th>
<th>Invoice</th>
<?php if($show_purchase_branch){ ?><th>Branch</th><?php } ?>
<th>Product</th>
<th class="text-right">Qty</th>
<th class="text-right">Price</th>
<th class="text-right">Total</th>

</tr>

</thead>

<tbody>

<?php
$purchase_total_qty = 0;
$purchase_total_amount = 0;
foreach($purchase_history as $line){
    $line_qty = (float)$line['quantity'];
    $purchase_total_qty += $line_qty;
    $purchase_total_amount += (float)$line['total_price'];
    $line_product = (string)($line['product_name'] ?? 'Product');
    if(trim((string)$line['variant_name']) !== ''){
        $line_product .= ' (' . trim((string)$line['variant_name']) . ')';
    }
?>

<tr>

<td data-order="<?= htmlspecialchars((string)$line['invoice_date']); ?>"><?= htmlspecialchars(app_date($line['invoice_date'])); ?></td>
<td>
    <a href="<?= htmlspecialchars(app_path('sales/view_invoice.php?id=' . (int)$line['invoice_id'])); ?>"><?= htmlspecialchars($line['invoice_no']); ?></a>
</td>
<?php if($show_purchase_branch){ ?><td><?= htmlspecialchars($line['branch_name']); ?></td><?php } ?>
<td>
    <?= htmlspecialchars($line_product); ?>
    <?php if(trim((string)$line['sku']) !== ''){ ?><br><small class="text-muted">Code: <?= htmlspecialchars($line['sku']); ?></small><?php } ?>
    <?php if($line_qty < 0){ ?><span class="badge badge-warning ml-1">Return</span><?php } ?>
</td>
<td class="text-right"><?= number_format($line_qty, 0); ?></td>
<td class="text-right"><?= number_format((float)$line['unit_price'], 2); ?></td>
<td class="text-right"><?= number_format((float)$line['total_price'], 2); ?></td>

</tr>

<?php } ?>

</tbody>

<tfoot>

<tr>

<th colspan="<?= $show_purchase_branch ? 4 : 3; ?>" class="text-right">Total</th>
<th class="text-right"><?= number_format($purchase_total_qty, 0); ?></th>
<th></th>
<th class="text-right"><?= number_format($purchase_total_amount, 2); ?></th>

</tr>

</tfoot>

</table>
<?php } ?>

<?php if($show_returnable_products){ ?>
<hr>

<h4>
    Returnable Products
</h4>

<table
id="returnableTable"
class="table table-bordered table-striped">

<thead>

<tr>

<th>Date</th>
<th>Product</th>
<th>Given</th>
<th>Returned</th>
<th>Remaining</th>

</tr>

</thead>

<tbody>

<?php while($product = mysqli_fetch_assoc($returnable_products)){ ?>

<?php
$given_qty = (float)$product['given_qty'];
$returned_qty = (float)$product['returned_qty'];
$remaining_qty = $given_qty - $returned_qty;
?>

<tr>

<td><?= htmlspecialchars(app_date($product['invoice_date'])); ?></td>
<td><?= htmlspecialchars($product['product_name'] ?? 'Product'); ?></td>
<td><?= number_format($given_qty,0); ?></td>
<td><?= number_format($returned_qty,0); ?></td>
<td>
    <strong><?= number_format($remaining_qty,0); ?></strong>
</td>

</tr>

<?php } ?>

</tbody>

</table>
<?php } ?>

</div>

</div>

</div>

</section>

<?php
$page_script = '
<script>
$(function(){
    if($("#purchaseHistoryTable").length){
        $("#purchaseHistoryTable").DataTable({
            responsive: false,
            autoWidth: false,
            order: [[0, "desc"]]
        });
    }
    if($("#returnableTable").length){
        $("#returnableTable").DataTable({
            responsive: false,
            autoWidth: false,
            order: [[0, "desc"]]
        });
    }
});
</script>
';

require_once '../includes/footer.php';
?>
