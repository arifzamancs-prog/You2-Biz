<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/customer_opening_due_helper.php';
require_once '../includes/booking_invoice_helper.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = $_SESSION['user_id'];
ensure_invoice_posting_columns($conn);
ensure_customer_opening_due_tables($conn);
ensure_booking_invoice_table($conn);

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

        'wallet_name' => '',

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

        'wallet_name' => '',

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

        'wallet_name' => '',

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

/* Confirmed Invoice Wallet Transactions */

$wallet_transaction_sql = "SELECT
        t.id,
        t.txn_no,
        t.txn_date,
        t.transaction_type,
        t.amount,
        t.note,
        bi.invoice_no,
        w.wallet_name
    FROM transactions t
    INNER JOIN booking_invoices bi
        ON bi.id=t.reference_id
        AND bi.user_id=t.user_id
    LEFT JOIN wallets w
        ON w.id=t.wallet_id
        AND w.user_id=t.user_id
    WHERE bi.customer_id=?
    AND bi.user_id=?
    AND bi.status='confirmed'
    AND t.transaction_type IN ('invoice_income', 'invoice_expense')
    ORDER BY t.txn_date, t.id";

$wallet_transaction_stmt = mysqli_prepare($conn, $wallet_transaction_sql);
mysqli_stmt_bind_param($wallet_transaction_stmt, 'ii', $customer_id, $user_id);
mysqli_stmt_execute($wallet_transaction_stmt);
$wallet_transactions = mysqli_stmt_get_result($wallet_transaction_stmt);

while($wallet_transactions && $row = mysqli_fetch_assoc($wallet_transactions)){
    $is_refund = $row['transaction_type'] === 'invoice_expense';

    $ledger[] = [
        'trx_date' => $row['txn_date'],
        'type' => $is_refund ? 'Wallet Refund' : 'Wallet Received',
        'reference' => trim((string)$row['invoice_no']),
        'invoice_no' => trim((string)$row['invoice_no']),
        'wallet_name' => (string)($row['wallet_name'] ?? ''),
        'sort_order' => $is_refund ? 3 : 2,
        'reference_id' => (int)$row['id'],
        'debit' => $is_refund ? (float)$row['amount'] : 0,
        'credit' => $is_refund ? 0 : (float)$row['amount'],
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

$total_paid = 0;

foreach($ledger as $entry){

    $total_paid +=
        $entry['credit'];

}

$running_ledger = [];
$balance = 0;

foreach($ledger as $row){

    $balance += (float)$row['debit'];
    $balance -= (float)$row['credit'];

    $row['running_balance'] = $balance;
    $running_ledger[] = $row;
}

$ledger = array_reverse($running_ledger);

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
    $customer['customer_name']
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
<th>Invoice No.</th>
<th>Type</th>
<th>Paid</th>

</tr>

</thead>

<tbody>

<?php

if(empty($ledger)){
?>

<tr>

<td colspan="4" class="text-center text-muted">
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
echo htmlspecialchars(
    $row['invoice_no'] !== ''
        ? $row['invoice_no']
        : $row['reference']
);
?>

</td>

<td>

<?php
echo htmlspecialchars(
    ($row['wallet_name'] ?? '') !== ''
        ? $row['wallet_name']
        : '-'
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

</tr>

<?php } ?>

</tbody>

<tfoot>

<tr>

<th colspan="3" class="text-right">
Total
</th>

<th>
<?php echo number_format($total_paid,2); ?>
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
