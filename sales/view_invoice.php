<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/branch_context_helper.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/sales_return_helper.php';

$user_id = $_SESSION['user_id'];
ensure_invoice_posting_columns($conn);

$invoice_id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;
require_branch_record_access($conn, 'invoices', $invoice_id, 'invoice_list.php?error=' . urlencode('Invoice not found.'));

/* Invoice */

$sql = "SELECT

            i.*,
            c.phone,
            c.address

        FROM invoices i

        LEFT JOIN customers c
        ON c.id = i.customer_id

        WHERE i.id=?
        AND i.user_id=?";

$stmt = mysqli_prepare(
    $conn,
    $sql
);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $invoice_id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result =
    mysqli_stmt_get_result($stmt);

$invoice =
    mysqli_fetch_assoc($result);

if(!$invoice){

    header('Location: invoice_list.php?error=' . urlencode('Invoice not found.'));
    exit;

}

// The page layout starts only after the checks above, which may redirect.
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$invoice_due_label = ((float)$invoice['due_amount']) < 0 ? 'Outstanding Amount' : 'Due';
$invoice_due_display = abs((float)$invoice['due_amount']);
$show_invoice_due_line = $invoice_due_display > 0.01;

/* Items */

$sql = "SELECT

            ii.*,
            p.product_name

        FROM invoice_items ii

        LEFT JOIN products p
        ON p.id = ii.product_id

        WHERE ii.invoice_id=?";

$stmt = mysqli_prepare(
    $conn,
    $sql
);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $invoice_id
);

mysqli_stmt_execute($stmt);

$items =
    mysqli_stmt_get_result($stmt);

/* Charges */

$sql = "SELECT

            ic.amount,
            ict.charge_name,
            ict.charge_type

        FROM invoice_charges ic

        LEFT JOIN invoice_charge_types ict
        ON ict.id = ic.charge_type_id

        WHERE ic.invoice_id=?";

$stmt = mysqli_prepare(
    $conn,
    $sql
);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $invoice_id
);

mysqli_stmt_execute($stmt);

$charges =
    mysqli_stmt_get_result($stmt);

function invoice_product_display($product_name, $quantity, $unit_price)
{
    $quantity_value = (float)$quantity;

    if((float)$unit_price == 0.0){
        return $product_name . ($quantity_value < 0 ? ' (Return)' : ' (Given)');
    }

    return $product_name;
}

function invoice_qty_display($quantity, $unit_price)
{
    $quantity_value = (float)$quantity;

    if((float)$unit_price == 0.0){
        $quantity_value = abs($quantity_value);
    }

    return rtrim(rtrim(number_format($quantity_value, 2, '.', ''), '0'), '.');
}

?>

<div class="row">

<div class="col-12">

    <div class="card">

        <div class="card-header">

            <h3 class="card-title">

                <?= ($invoice['created_by_type']??'')==='sales_refund' ? 'Refund Credit Note' : 'Invoice Details' ?>

            </h3>

            <div class="float-right">
                <?php $return_module = sales_return_enabled($conn, (int)$user_id); ?>
                <?php if($return_module){ ?>
                <?php sales_return_schema($conn); $view_return_state = sales_return_invoice_state($conn, $invoice); ?>
                <?php if(!is_agent_user() && sales_return_block_reason($invoice) === '' && $view_return_state !== 'returned'){ ?>
                <a href="sales_return_create.php?invoice_id=<?= (int)$invoice_id ?>" class="btn btn-outline-danger btn-sm">Return</a>
                <a href="create_invoice.php?exchange=<?= (int)$invoice_id ?>" class="btn btn-outline-primary btn-sm">Exchange</a>
                <?php } ?>
                <?php }elseif (($_SESSION['user_role']??'')==='admin' && !invoice_is_pending($invoice) && (float)$invoice['total_amount']>0) { ?>
                <a href="refund.php?id=<?= (int)$invoice_id ?>" class="btn btn-danger btn-sm">Refund / History</a>
                <?php } ?>

                <a href="invoice_list.php"
                   class="btn btn-secondary btn-sm">

                    Back

                </a>

                <?php if(invoice_is_pending($invoice)){ ?>
                <a href="post_invoice.php?id=<?php echo $invoice_id; ?>"
                   target="_blank"
                   class="btn btn-success btn-sm">

                    Pay & Print

                </a>
                <?php }else{ ?>
                <a href="print_invoice.php?id=<?php echo $invoice_id; ?>"
                   target="_blank"
                   class="btn btn-success btn-sm">

                    Print

                </a>
                <?php } ?>

            </div>

        </div>

        <div class="card-body">

            <div class="row">

                <div class="col-md-6">

                    <table class="table table-bordered">

                        <tr>

                            <th width="35%">
                                Invoice No
                            </th>

                            <td>
                                <?php echo htmlspecialchars(
                                    $invoice['invoice_no']
                                ); ?>
                            </td>

                        </tr>

                        <tr>

                            <th>
                                Date
                            </th>

                            <td>
                                <?php echo htmlspecialchars(
                                    app_date($invoice['invoice_date'])
                                ); ?>
                            </td>

                        </tr>

                        <tr>

                            <th>
                                Customer
                            </th>

                            <td>
                                <?php echo htmlspecialchars(
                                    $invoice['customer_name']
                                ); ?>
                            </td>

                        </tr>
                        <?php if(!empty($invoice['phone'])){ ?>

                        <tr>

                            <th>
                                Phone
                            </th>

                            <td>

                                <?php
                                echo htmlspecialchars(
                                    $invoice['phone']
                                );
                                ?>

                            </td>

                        </tr>

                        <?php } ?>

                        <?php if(!empty($invoice['address'])){ ?>

                            <tr>

                                <th>
                                    Address
                                </th>

                                <td>

                                    <?php
                                    echo htmlspecialchars(
                                        $invoice['address']
                                    );
                                    ?>

                                </td>

                            </tr>

                            <?php } ?>

                    </table>

                </div>

                <div class="col-md-6">

                    <table class="table table-bordered">

                        <tr>

                            <th width="35%">
                                Total
                            </th>

                            <td>

                                BDT
                                <?php echo number_format(
                                    $invoice['total_amount'],
                                    2
                                ); ?>

                            </td>

                        </tr>

                        <tr>

                            <th>
                                Paid
                            </th>

                            <td>

                                BDT
                                <?php echo number_format(
                                    $invoice['paid_amount'],
                                    2
                                ); ?>

                            </td>

                        </tr>

                        <tr>

                            <th>
                                <?= htmlspecialchars($invoice_due_label); ?>
                            </th>

                            <td>

                                BDT
                                <?php echo number_format(
                                    $invoice_due_display,
                                    2
                                ); ?>

                            </td>

                        </tr>

                        <tr>

                            <th>
                                Status
                            </th>

                            <td>

                                <?php

                                if(
                                    $invoice['payment_status']
                                    == 'paid'
                                ){

                                    echo '
                                    <span class="badge badge-success">
                                    Paid
                                    </span>';

                                }
                                elseif(
                                    $invoice['payment_status']
                                    == 'partial'
                                ){

                                    echo '
                                    <span class="badge badge-warning">
                                    Partial
                                    </span>';

                                }
                                else{

                                    echo '
                                    <span class="badge badge-danger">
                                    Due
                                    </span>';

                                }

                                if($return_module && !empty($view_return_state)){
                                    echo ' ' . sales_return_state_badge($view_return_state);
                                }

                                ?>

                            </td>

                        </tr>

                    </table>

                </div>

            </div>

            <hr>

            <h4>

                Products

            </h4>

            <table class="table table-bordered">

                <thead>

                <tr>

                    <th>
                        Product
                    </th>

                    <th>
                        Qty
                    </th>

                    <th>
                        Unit Price
                    </th>

                    <th>
                        Total
                    </th>

                </tr>

                </thead>

                <tbody>

                <?php
                while(
                    $row =
                    mysqli_fetch_assoc($items)
                ){
                ?>

                <tr>

                    <td>
                        <?php echo htmlspecialchars(invoice_product_display($row['product_name'], $row['quantity'], $row['unit_price'])); ?>
                        <?php if(!empty($row['variant_name'])){ ?>
                            <small class="d-block text-muted">Variant: <?php echo htmlspecialchars($row['variant_name']); ?></small>
                        <?php } ?>
                    </td>

                    <td>
                        <?php echo htmlspecialchars(invoice_qty_display($row['quantity'], $row['unit_price'])); ?>
                    </td>

                    <td>
                        <?php echo number_format(
                            $row['unit_price'],
                            2
                        ); ?>
                    </td>

                    <td>
                        <?php echo number_format(
                            $row['total_price'],
                            2
                        ); ?>
                    </td>

                </tr>

                <?php } ?>

                </tbody>

            </table>

            <?php

                $charge_count =
                    mysqli_num_rows($charges);

                if($charge_count > 0){

                ?>
            <hr>

            <h4>

                Charges

            </h4>

            <table class="table table-bordered">

                <thead>

                <tr>

                    <th>
                        Charge
                    </th>

                    <th>
                        Type
                    </th>

                    <th>
                        Amount
                    </th>

                </tr>

                </thead>

                <tbody>

                <?php
                while(
                    $row =
                    mysqli_fetch_assoc($charges)
                ){
                ?>

                <tr>

                    <td>
                        <?php echo htmlspecialchars(
                            $row['charge_name']
                        ); ?>
                    </td>

                    <td>
                        <?php echo htmlspecialchars(
                            $row['charge_type']
                        ); ?>
                    </td>

                    <td>
                        <?php echo number_format(
                            $row['amount'],
                            2
                        ); ?>
                    </td>

                </tr>

                <?php } ?>

                </tbody>

            </table>
            
            <?php } ?>

            <hr>

<div class="row">

    <div class="col-md-4 ml-auto">

        <table class="table table-bordered">

            <tr>

                <th>
                    Grand Total
                </th>

                <td>

                    BDT
                    <?php
                    echo number_format(
                        $invoice['total_amount'],
                        2
                    );
                    ?>

                </td>

            </tr>

            <tr>

                <th>
                    Paid
                </th>

                <td>

                    BDT
                    <?php
                    echo number_format(
                        $invoice['paid_amount'],
                        2
                    );
                    ?>

                </td>

            </tr>

            <tr>

                <th>
                    <?= htmlspecialchars($invoice_due_label); ?>
                </th>

                <td>

                    BDT
                    <?php
                    echo number_format(
                        $invoice_due_display,
                        2
                    );
                    ?>

                </td>

            </tr>

        </table>

    </div>

</div>

            <?php if($return_module){ ?>
            <?php
            $invoice_returns = sales_return_list_for_invoice($conn, (int)$user_id, (int)$invoice_id);
            // This invoice was paid partly by an exchange.
            $exchange_from = sales_refund_query(
                $conn,
                'SELECT r.id, r.exchange_amount, cn.invoice_no AS credit_no, i.invoice_no
                 FROM sales_refunds r
                 LEFT JOIN invoices cn ON cn.id=r.credit_invoice_id
                 LEFT JOIN invoices i ON i.id=r.invoice_id
                 WHERE r.user_id=? AND r.exchange_invoice_id=?',
                'ii',
                [(int)$user_id, (int)$invoice_id]
            )->get_result()->fetch_all(MYSQLI_ASSOC);
            $returns_show_due = (bool)array_filter($invoice_returns, fn($row) => (float)$row['due_adjusted'] > 0.009);
            $returns_show_advance = (bool)array_filter($invoice_returns, fn($row) => (float)$row['advance_amount'] > 0.009);
            ?>
            <?php foreach($exchange_from as $exchange_row){ ?>
            <div class="alert alert-info mt-3">
                Exchange of <?= htmlspecialchars((string)$exchange_row['invoice_no']); ?>:
                <?= htmlspecialchars((string)$exchange_row['credit_no']); ?>, credit <?= number_format((float)$exchange_row['exchange_amount'], 2); ?>
                <a href="sales_return_print.php?id=<?= (int)$exchange_row['id']; ?>" target="_blank" class="btn btn-sm btn-outline-light ml-2" style="text-decoration:none">Exchange Slip</a>
            </div>
            <?php } ?>
            <?php if($invoice_returns){ ?>
            <hr>
            <h4>Sales Returns</h4>
            <table class="table table-bordered table-sm">
                <thead>
                <tr>
                    <th>Return No</th>
                    <th>Date</th>
                    <th>Total</th>
                    <th>Cash Refund</th>
                    <?php if($returns_show_due){ ?><th>Due Deducted</th><?php } ?>
                    <?php if($returns_show_advance){ ?><th>Advance</th><?php } ?>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach($invoice_returns as $return_row){ ?>
                <tr>
                    <td>
                        <?= htmlspecialchars((string)($return_row['credit_no'] ?: ('#' . $return_row['id']))); ?>
                        <?php if(!empty($return_row['exchange_invoice_id'])){ ?><span class="badge badge-primary">Exchange</span><?php } ?>
                    </td>
                    <td><?= htmlspecialchars(app_date($return_row['created_at'])); ?></td>
                    <td><?= number_format((float)$return_row['return_value'] > 0 ? (float)$return_row['return_value'] : (float)$return_row['amount'], 2); ?></td>
                    <td><?= number_format((float)$return_row['amount'], 2); ?></td>
                    <?php if($returns_show_due){ ?><td><?= number_format((float)$return_row['due_adjusted'], 2); ?></td><?php } ?>
                    <?php if($returns_show_advance){ ?><td><?= number_format((float)$return_row['advance_amount'], 2); ?></td><?php } ?>
                    <td><a href="sales_return_print.php?id=<?= (int)$return_row['id']; ?>" target="_blank" class="btn btn-success btn-sm">Print</a></td>
                </tr>
                <?php } ?>
                </tbody>
            </table>
            <?php } ?>
            <?php } ?>

            <?php if(!empty($invoice['notes'])){ ?>

            <hr>

            <h4>

                Notes

            </h4>

            <p>

                <?php echo nl2br(
                    htmlspecialchars(
                        $invoice['notes']
                    )
                ); ?>

            </p>

            <?php } ?>

        </div>

    </div>

</div>

</div>

<?php
require_once '../includes/footer.php';
?>
