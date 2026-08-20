<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/customer_due_allocation_helper.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = (int)$_SESSION['user_id'];
ensure_invoice_posting_columns($conn);
$show_actions = !is_agent_user();
$agent_user_id = (int)($_SESSION['login_user_id'] ?? 0);

if(is_agent_user()){
    $sql = "SELECT *
            FROM invoices
            WHERE user_id=?
            AND created_by_user_id=?
            ORDER BY id DESC";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $user_id, $agent_user_id);
}else{
    $sql = "SELECT *
            FROM invoices
            WHERE user_id=?
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

                    <table
                        id="example1"
                        class="table table-bordered table-striped">

                        <thead>

                        <tr>

                            <th>Invoice No</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Due</th>
                            <th>Status</th>
                            <?php if($show_actions){ ?>
                                <th>Action</th>
                            <?php } ?>

                        </tr>

                        </thead>

                        <tbody>

                        <?php
                        while($row = mysqli_fetch_assoc($result)){
                            $can_modify_invoice = can_modify_customer_invoice(
                                $conn,
                                $user_id,
                                (int)$row['id'],
                                (int)$row['customer_id']
                            );
                        ?>

                        <tr>

                            <td>
                                <?php echo $row['invoice_no']; ?>
                            </td>

                            <td>
                                <?php echo app_date($row['invoice_date']); ?>
                            </td>

                            <td>
                                <?php echo $row['customer_name']; ?>
                            </td>

                            <td>
                                <?php echo number_format(
                                    $row['total_amount'],
                                    2
                                ); ?>
                            </td>

                            <td>
                                <?php echo number_format(
                                    $row['paid_amount'],
                                    2
                                ); ?>
                            </td>

                            <td>
                                <?php echo number_format(
                                    $row['due_amount'],
                                    2
                                ); ?>
                            </td>

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
                            <td>

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

                </div>

            </div>

        </div>


<?php
require_once '../includes/footer.php';
?>
