<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/customer_due_allocation_helper.php';
require_once '../includes/branch_context_helper.php';
require_once '../includes/product_category_helper.php';
require_once '../includes/project_package_helper.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = (int)$_SESSION['user_id'];
$restaurant_invoice_layout = restaurant_catalog_enabled($conn, $user_id);
// Inventory & Sales and Fashion Retail lists do not need Customer and Due columns.
$compact_invoice_layout = !$restaurant_invoice_layout && in_array(project_package_company_type($conn, $user_id), ['Stock Product', 'Fashion house'], true);
$show_customer_due_columns = !$restaurant_invoice_layout && !$compact_invoice_layout;
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
// Invoice number search.
$invoice_search = substr(trim((string)($_GET['invoice_no'] ?? '')), 0, 100);
if($invoice_search !== ''){
    $invoice_like = addcslashes(mysqli_real_escape_string($conn, $invoice_search), '%_');
    $date_scope .= " AND invoice_no LIKE '%" . $invoice_like . "%'";
}
// Load 100 invoices at a time; Previous/Next 100 moves between batches.
$invoice_batch_size = 100;
$invoice_page = max(1, (int)($_GET['page'] ?? 1));

if(is_agent_user()){
    $sql = "SELECT *
            FROM invoices
            WHERE user_id=?
            {$branch_scope}
            {$date_scope}
            AND created_by_user_id=?
            ORDER BY id DESC";
    $count_sql = "SELECT COUNT(*) AS total FROM invoices WHERE user_id=? {$branch_scope} {$date_scope} AND created_by_user_id=?";
    $count_stmt = mysqli_prepare($conn, $count_sql);
    mysqli_stmt_bind_param($count_stmt, "ii", $user_id, $agent_user_id);
}else{
    $sql = "SELECT *
            FROM invoices
            WHERE user_id=?
            {$branch_scope}
            {$date_scope}
            ORDER BY id DESC";
    $count_sql = "SELECT COUNT(*) AS total FROM invoices WHERE user_id=? {$branch_scope} {$date_scope}";
    $count_stmt = mysqli_prepare($conn, $count_sql);
    mysqli_stmt_bind_param($count_stmt, "i", $user_id);
}

mysqli_stmt_execute($count_stmt);
$invoice_total = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($count_stmt))['total'] ?? 0);
$invoice_pages = max(1, (int)ceil($invoice_total / $invoice_batch_size));
$invoice_page = min($invoice_page, $invoice_pages);
$invoice_offset = ($invoice_page - 1) * $invoice_batch_size;
$sql .= " LIMIT {$invoice_batch_size} OFFSET {$invoice_offset}";

$stmt = mysqli_prepare($conn, $sql);
if(is_agent_user()){
    mysqli_stmt_bind_param($stmt, "ii", $user_id, $agent_user_id);
}else{
    mysqli_stmt_bind_param($stmt, "i", $user_id);
}
$invoice_page_url = function($page) use ($date_from, $date_to, $invoice_search){
    $params = array_filter(['date_from' => $date_from, 'date_to' => $date_to, 'invoice_no' => $invoice_search], 'strlen');
    $params['page'] = $page;
    return 'invoice_list.php?' . http_build_query($params);
};

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
                        <div class="col-md-2 mb-2">
                            <label for="invoice-date-from">From Date</label>
                            <input type="date" class="form-control" id="invoice-date-from" name="date_from" value="<?= htmlspecialchars($date_from, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-md-2 mb-2">
                            <label for="invoice-date-to">To Date</label>
                            <input type="date" class="form-control" id="invoice-date-to" name="date_to" value="<?= htmlspecialchars($date_to, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-md-4 mb-2">
                            <label for="invoice-no-search">Invoice No</label>
                            <input type="text" class="form-control" id="invoice-no-search" name="invoice_no" maxlength="100" placeholder="Search invoice no" value="<?= htmlspecialchars($invoice_search, ENT_QUOTES, 'UTF-8') ?>">
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

                            <th>Invoice No</th>
                            <th>Date</th>
                            <?php if($show_customer_due_columns){ ?><th>Customer</th><?php } ?>
                            <?php if(!$restaurant_invoice_layout){ ?><th class="invoice-list-amount">Total</th><?php } ?>
                            <th class="invoice-list-amount"><?= $restaurant_invoice_layout ? 'Amount' : 'Paid' ?></th>
                            <?php if($show_customer_due_columns){ ?><th>Due</th><?php } ?>
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


                            <td>
                                <?php echo $row['invoice_no']; ?>
                            </td>

                            <td>
                                <?php echo app_date($row['invoice_date']); ?>
                            </td>

                            <?php if($show_customer_due_columns){ ?><td>
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

                            <?php if($show_customer_due_columns){ ?><td>
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

                    <?php if($invoice_total > $invoice_batch_size){ ?>
                    <div class="d-flex flex-wrap justify-content-between align-items-center border-top pt-3 mt-3">
                        <div class="text-muted mb-2">
                            Loaded invoices <?= number_format($invoice_offset + 1) ?>–<?= number_format(min($invoice_offset + $invoice_batch_size, $invoice_total)) ?> of <?= number_format($invoice_total) ?>
                        </div>
                        <div class="mb-2">
                            <?php if($invoice_page > 1){ ?>
                                <a href="<?= htmlspecialchars($invoice_page_url($invoice_page - 1), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-primary"><i class="fas fa-chevron-left mr-1"></i>Previous 100</a>
                            <?php } else { ?>
                                <button type="button" class="btn btn-outline-secondary" disabled><i class="fas fa-chevron-left mr-1"></i>Previous 100</button>
                            <?php } ?>
                            <?php if($invoice_page < $invoice_pages){ ?>
                                <a href="<?= htmlspecialchars($invoice_page_url($invoice_page + 1), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary ml-1">Next 100<i class="fas fa-chevron-right ml-1"></i></a>
                            <?php } else { ?>
                                <button type="button" class="btn btn-outline-secondary ml-1" disabled>Next 100<i class="fas fa-chevron-right ml-1"></i></button>
                            <?php } ?>
                        </div>
                    </div>
                    <?php } ?>

                    <style>
                        #example1 .invoice-list-amount { width: 180px; min-width: 160px; }
                        #example1 .invoice-list-action { width: 1%; white-space: nowrap; }
                    </style>

                </div>

            </div>

        </div>


<?php
require_once '../includes/footer.php';
?>
