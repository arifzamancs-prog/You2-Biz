<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = $_SESSION['user_id'];

$supplier_id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;

$sql = "SELECT *
        FROM suppliers
        WHERE id=?
        AND user_id=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $supplier_id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$supplier = mysqli_fetch_assoc($result);

if(!$supplier){

    die("Supplier Not Found");

}
$ledger = [];

$sql = "SELECT
            purchase_date,
            purchase_no,
            total_amount,
            paid_amount
        FROM purchases
        WHERE supplier_id=?
        AND user_id=?";

$stmt = mysqli_prepare(
    $conn,
    $sql
);

mysqli_stmt_bind_param(

    $stmt,

    "ii",

    $supplier_id,
    $user_id

);

mysqli_stmt_execute($stmt);

$result =
    mysqli_stmt_get_result($stmt);

while($row=mysqli_fetch_assoc($result)){

    $ledger[] = [

        'trx_date' =>
            $row['purchase_date'],

        'type' =>
            'Purchase',

        'reference' =>
            $row['purchase_no'],

        'debit' =>
            $row['paid_amount'],

        'credit' =>
            0

    ];

}

$sql = "SELECT
            sp.payment_date,
            sp.amount,
            sp.note,
            p.purchase_no
        FROM supplier_payments sp
        LEFT JOIN purchases p
        ON p.id = sp.purchase_id
        WHERE sp.supplier_id=?
        AND sp.user_id=?";

$stmt = mysqli_prepare(
    $conn,
    $sql
);

mysqli_stmt_bind_param(

    $stmt,

    "ii",

    $supplier_id,
    $user_id

);

mysqli_stmt_execute($stmt);

$result =
    mysqli_stmt_get_result($stmt);

while($row=mysqli_fetch_assoc($result)){

    $ledger[] = [

        'trx_date' =>
            $row['payment_date'],

        'type' =>
            'Payment',

        'reference' =>
            $row['purchase_no'],

        'debit' =>
            0,

        'credit' =>
            $row['amount']

    ];

}

usort($ledger, function($a, $b){
    return strcmp($a['trx_date'], $b['trx_date']);
});
?>

<section class="content">

<div class="container-fluid">

<div class="card">

<div class="card-header">

<h3>

Supplier Ledger

</h3>

</div>

<div class="card-body">

<h5>

Supplier :
<?= htmlspecialchars($supplier['supplier_name']); ?>

</h5>

<table class="table table-bordered">

<thead>

<tr>

<th>Date</th>

<th>Type</th>

<th>Reference</th>

<th>Debit</th>

<th>Credit</th>

</tr>

</thead>

<tbody>

<?php foreach($ledger as $row){ ?>

<tr>

<td><?= htmlspecialchars(app_date($row['trx_date'])); ?></td>

<td><?= $row['type']; ?></td>

<td><?= $row['reference']; ?></td>

<td><?= number_format($row['debit'],2); ?></td>

<td><?= number_format($row['credit'],2); ?></td>

</tr>

<?php } ?>

</tbody>

</table>

</div>

</div>

</div>

</section>

<?php

require_once '../includes/footer.php';

?>
