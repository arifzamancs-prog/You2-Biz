<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/customer_due_allocation_helper.php';
require_once '../includes/branch_context_helper.php';
require_once '../includes/product_category_helper.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = (int)$_SESSION['user_id'];
$restaurant_invoice_layout = restaurant_catalog_enabled($conn, $user_id);
ensure_invoice_posting_columns($conn);
ensure_branch_accounting_columns($conn, $user_id);
$branch_scope = branch_scope_sql($conn, 'invoices');
$show_actions = !is_agent_user();
$agent_user_id = (int)($_SESSION['login_user_id'] ?? 0);
$date_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_from'] ?? '')) ? (string)$_GET['date_from'] : '';
$date_to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_to'] ?? '')) ? (string)$_GET['date_to'] : '';
if($date_from !== '' && $date_to !== '' && $date_to < $date_from){
    $date_to = $date_from;
}
$date_scope = '';
if($date_from !== '') $date_scope .= " AND invoice_date>='" . $date_from . "'";
if($date_to !== '') $date_scope .= " AND invoice_date<='" . $date_to . "'";

if(is_agent_user()){
    $sql = "SELECT *
            FROM invoices
            WHERE user_id=?
            {$branch_scope}
            {$date_scope}
            AND created_by_user_id=?
            ORDER BY id DESC";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $user_id, $agent_user_id);
}else{
    $sql = "SELECT *
            FROM invoices
            WHERE user_id=?
            {$branch_scope}
            {$date_scope}
            ORDER BY id DESC";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

?>


        <div class="container-fluid">

            <div class="row mb-2">

                <div class="col-sm-6">
                    <h1>Invoice List</h1>
                </div>

                <div class="col-sm-6 text-right">
                    <a href="create_invoice.php"
                       class="btn btn-primary">
                        Create Invoice
                    </a>
                </div>

            </div>

        </div>
    </section>

    <section class="content">

        <div class="container-fluid">

            <?php if(isset($_GET['success'])){ ?>

                <div class="alert alert-success">
                    Invoice Created Successfully.
                </div>

            <?php } ?>

            <?php if(isset($_GET['error'])){ ?>

                <div class="alert alert-danger">
                    <?= htmlspecialchars($_GET['error']); ?>
                </div>

            <?php } ?>

            
            <div class="card">

                <div class="card-header">
                    <h3 class="card-title">
                        All Invoices
                    </h3>
                </div>

                <div class="card-body">

                    <form method="get" class="row align-items-end mb-3">
                        <div class="col-md-3 mb-2">
                            <label for="invoice-date-from">From Date</label>
                            <input type="date" class="form-control" id="invoice-date-from" name="date_from" value="<?= htmlspecialchars($date_from, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-md-3 mb-2">
                            <label for="invoice-date-to">To Date</label>
                            <input type="date" class="form-control" id="invoice-date-to" name="date_to" value="<?= htmlspecialchars($date_to, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-md-2 mb-2">
                            <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-filter"></i> Search</button>
                        </div>
                        <div class="col-md-2 mb-2">
                            <a href="invoice_list.php" class="btn btn-secondary btn-block">Reset</a>
                        </div>
                    </form>

                    <table
                        id="example1"
                        class="table table-bordered table-striped">

                        <thead>

                        <tr>

                            <th class="invoice-list-sl">SL</th>
                            <th>Invoice No</th>
                            <th>Date</th>
                            <?php if(!$restaurant_invoice_layout){ ?><th>Customer</th><?php } ?>
                            <?php if(!$restaurant_invoice_layout){ ?><th>Total</th><?php } ?>
                            <th><?= $restaurant_invoice_layout ? 'Amount' : 'Paid' ?></th>
                            <?php if(!$restaurant_invoice_layout){ ?><th>Due</th><?php } ?>
                            <th>Status</th>
                            <?php if($show_actions){ ?>
                                <th class="invoice-list-action">Action</th>
                            <?php } ?>

                        </tr>

                        </thead>

                        <tbody>

                        <?php
                        $sl = 1;
                        while($row = mysqli_fetch_assoc($result)){
                            $can_modify_invoice = can_modify_customer_invoice(
                                $conn,
                                $user_id,
                                (int)$row['id'],
                                (int)$row['customer_id']
                            );
                        ?>

                        <tr>

                            <td class="invoice-list-sl"><?= $sl++ ?></td>

                            <td>
                                <?php echo $row['invoice_no']; ?>
                            </td>

                            <td>
                                <?php echo app_date($row['invoice_date']); ?>
                            </td>

                            <?php if(!$restaurant_invoice_layout){ ?><td>
                                <?php echo $row['customer_name']; ?>
                            </td><?php } ?>

                            <?php if(!$restaurant_invoice_layout){ ?><td>
                                <?php echo number_format(
                                    $row['total_amount'],
                                    2
                                ); ?>
                            </td><?php } ?>

                            <td>
                                <?php echo number_format(
                                    $row['paid_amount'],
                                    2
                                ); ?>
                            </td>

                            <?php if(!$restaurant_invoice_layout){ ?><td>
                                <?php echo number_format(
                                    $row['due_amount'],
                                    2
                                ); ?>
                            </td><?php } ?>

                            <td>

                                <?php

                                if(($row['accounting_status'] ?? 'posted') === 'pending'){

                                    echo '<span class="badge badge-secondary">
                                            Pending
                                          </span>';

                                }elseif($row['payment_status']=='paid'){

                                    echo '<span class="badge badge-success">
                                            Paid
                                          </span>';

                                }elseif(
                                    $row['payment_status']=='partial'
                                ){

                                    echo '<span class="badge badge-warning">
                                            Partial
                                          </span>';

                                }else{

                                    echo '<span class="badge badge-danger">
                                            Due
                                          </span>';

                                }

                                ?>

                            </td>

                            <?php if($show_actions){ ?>
                            <td class="invoice-list-action">

                                <a href="view_invoice.php?id=<?php echo $row['id']; ?>"
                                   class="btn btn-info btn-sm">
                                    View
                                </a>
                                <?php if(manager_can_modify()){ ?>
                                <?php if($can_modify_invoice){ ?>
                                <a href="edit_invoice.php?id=<?php echo $row['id']; ?>"
                                class="btn btn-warning btn-sm">

                                    Edit

                                </a>
                                <a href="delete_invoice.php?id=<?php echo $row['id']; ?>"
                                class="btn btn-danger btn-sm"
                                onclick="return confirm('Are you sure you want to delete this invoice?');">

                                    Delete

                                </a>
                                <?php }else{ ?>
                                <button
                                    type="button"
                                    class="btn btn-warning btn-sm"
                                    disabled
                                    title="<?= htmlspecialchars(customer_invoice_modify_lock_message()); ?>">
                                    Edit
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-danger btn-sm"
                                    disabled
                                    title="<?= htmlspecialchars(customer_invoice_modify_lock_message()); ?>">
                                    Delete
                                </button>
                                <?php } ?>
                                <?php } ?>
                                <?php if(($row['accounting_status'] ?? 'posted') === 'pending'){ ?>
                                    <a href="post_invoice.php?id=<?php echo $row['id']; ?>&reload_parent=invoice_list"
                                       target="invoicePrintWindow"
                                       onclick="setTimeout(function(){ window.location.href='invoice_list.php'; }, 250);"
                                       class="btn btn-success btn-sm">
                                        Pay & Print
                                    </a>
                                <?php }else{ ?>
                                    <a href="print_invoice.php?id=<?php echo $row['id']; ?>"
                                       target="_blank"
                                       class="btn btn-success btn-sm">
                                        Print
                                    </a>
                                <?php } ?>

                            </td>
                            <?php } ?>

                        </tr>

                        <?php } ?>

                        </tbody>

                    </table>

                    <style>
                        #example1 .invoice-list-sl { width: 105px; min-width: 105px; }
                        #example1 .invoice-list-action { width: 285px; min-width: 285px; white-space: nowrap; }
                    </style>

                </div>

            </div>

        </div>


<?php
require_once '../includes/footer.php';
?>
