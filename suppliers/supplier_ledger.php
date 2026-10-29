<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/supplier_helper.php';
require_once '../includes/supplier_ledger_helper.php';

$user_id = $_SESSION['user_id'];

$supplier_id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;

if(supplier_uses_auto_code($conn, $user_id)){
    ensure_supplier_code_column($conn);
    supplier_code_fill_missing($conn, (int)$user_id);
}

$data = supplier_ledger_data($conn, $user_id, $supplier_id);

if(!$data){

    die(supplier_display_text("Supplier Not Found"));

}

$supplier = $data['supplier'];
$total_purchase = $data['total_purchase'];
$total_paid = $data['total_paid'];
$current_due_label = $data['current_due_label'];
$current_due_display = $data['current_due_display'];

/* Newest first on screen */

$ledger = array_reverse($data['ledger']);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<section class="content-header">

<div class="container-fluid">

<div class="row">

    <div class="col-sm-6">

        <h1>
            <?= supplier_display_text('Supplier'); ?> Ledger
        </h1>

    </div>

    <div class="col-sm-6 text-right">

        <a
            href="print_ledger.php?id=<?= $supplier_id; ?>"
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
<?= supplier_display_text('Supplier'); ?>
</th>

<td>
<?= htmlspecialchars(supplier_name_with_code($supplier['supplier_name'], $supplier['supplier_code'] ?? '')); ?>
</td>

</tr>

<?php if(!empty($supplier['phone'])){ ?>

<tr>

<th>
Phone
</th>

<td>
<?= htmlspecialchars($supplier['phone']); ?>
</td>

</tr>

<?php } ?>

<?php if(!empty($supplier['address'])){ ?>

<tr>

<th>
Address
</th>

<td>
<?= htmlspecialchars($supplier['address']); ?>
</td>

</tr>

<?php } ?>

</table>

</div>

<div class="col-md-8">

<table class="table table-bordered">

<tr>

<th>
Total Purchase
</th>

<td>
<?= number_format($total_purchase, 2); ?>
</td>

<th>
Total Paid
</th>

<td>
<?= number_format($total_paid, 2); ?>
</td>

<th>
<?= htmlspecialchars($current_due_label); ?>
</th>

<td>
<?= number_format($current_due_display, 2); ?>
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
<th>Type</th>
<th>Reference</th>
<th>Purchase</th>
<th>Paid</th>
<th>Current Due</th>

</tr>

</thead>

<tbody>

<?php foreach($ledger as $row){ ?>

<tr>

<td><?= htmlspecialchars(app_date($row['trx_date'])); ?></td>

<td><?= htmlspecialchars($row['type']); ?></td>

<td><?= htmlspecialchars($row['reference']); ?></td>

<td><?= number_format($row['debit'], 2); ?></td>

<td><?= number_format($row['credit'], 2); ?></td>

<td><?= ledger_balance_display($row['running_balance']); ?></td>

</tr>

<?php } ?>

</tbody>

<tfoot>

<tr>

<th colspan="3" class="text-right">
Total
</th>

<th>
<?= number_format($total_purchase, 2); ?>
</th>

<th>
<?= number_format($total_paid, 2); ?>
</th>

<th>
<?= number_format($current_due_display, 2); ?>
</th>

</tr>

</tfoot>

</table>

</div>

</div>

</div>

</section>

<?php

require_once '../includes/footer.php';

?>
