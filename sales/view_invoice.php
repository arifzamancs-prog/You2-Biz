<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = $_SESSION['user_id'];
ensure_invoice_posting_columns($conn);

$invoice_id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;

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

    die("Invoice Not Found");

}

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

                Invoice Details

            </h3>

            <div class="float-right">

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
