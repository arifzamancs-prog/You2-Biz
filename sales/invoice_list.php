<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/customer_due_allocation_helper.php';
require_once '../includes/branch_context_helper.php';
require_once '../includes/product_category_helper.php';
require_once '../includes/project_package_helper.php';
require_once '../includes/sales_return_helper.php';
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
// Sales Return keeps its credit notes (REF-...) on the Sales Return page, not in the invoice list.
$return_module_list = sales_return_enabled($conn, (int)$user_id);
// E-shop invoices whose order was cancelled (money never received) show as Cancelled.
$cancelled_eshop_invoices = [];
try{
    $eshop_tables = mysqli_query($conn, "SHOW TABLES LIKE 'eshop_delivery'");
    if($eshop_tables && mysqli_num_rows($eshop_tables) > 0){
        $cancel_stmt = mysqli_prepare($conn, "SELECT o.invoice_id FROM eshop_orders o JOIN eshop_delivery d ON d.order_id=o.id WHERE o.company_id=? AND d.status='cancelled'");
        mysqli_stmt_bind_param($cancel_stmt, 'i', $user_id);
        mysqli_stmt_execute($cancel_stmt);
        foreach(mysqli_fetch_all(mysqli_stmt_get_result($cancel_stmt)) as $cancel_row){ $cancelled_eshop_invoices[(int)$cancel_row[0]] = true; }
    }
}catch(Throwable $e){
    $cancelled_eshop_invoices = [];
}
if($return_module_list){
    sales_return_schema($conn);
    $date_scope .= " AND COALESCE(created_by_type,'')<>'sales_refund'";
}
// Every invoice is loaded; the list shows its first rows at once and the rest follow
// in the background (includes/quick_load_helper.php).
if(is_agent_user()){
    $sql = "SELECT *
            FROM invoices
            WHERE user_id=?
            {$branch_scope}
            {$date_scope}
            AND created_by_user_id=?
            ORDER BY id DESC";
}else{
    $sql = "SELECT *
            FROM invoices
            WHERE user_id=?
            {$branch_scope}
            {$date_scope}
            ORDER BY id DESC";
}

$stmt = mysqli_prepare($conn, $sql);
if(is_agent_user()){
    mysqli_stmt_bind_param($stmt, "ii", $user_id, $agent_user_id);
}else{
    mysqli_stmt_bind_param($stmt, "i", $user_id);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$invoice_rows = mysqli_fetch_all($result, MYSQLI_ASSOC);

// Products of the loaded invoices ("Name x qty"), read in one query.
$invoice_products = [];
$invoice_ids = array_map('intval', array_column($invoice_rows, 'id'));
if($invoice_ids){
    $product_sql = "SELECT ii.invoice_id, ii.variant_name, ii.quantity, p.product_name
                    FROM invoice_items ii
                    LEFT JOIN products p ON p.id=ii.product_id AND p.user_id=?
                    WHERE ii.invoice_id IN (" . implode(',', $invoice_ids) . ")
                    ORDER BY ii.invoice_id, ii.id";
    $product_stmt = mysqli_prepare($conn, $product_sql);
    mysqli_stmt_bind_param($product_stmt, "i", $user_id);
    mysqli_stmt_execute($product_stmt);
    foreach(mysqli_fetch_all(mysqli_stmt_get_result($product_stmt), MYSQLI_ASSOC) as $item){
        $name = trim((string)($item['product_name'] ?? '')) !== '' ? (string)$item['product_name'] : 'Deleted product';
        $variant = trim((string)($item['variant_name'] ?? ''));
        if($variant !== '' && $variant !== '0'){
            $name .= ' (' . $variant . ')';
        }
        $invoice_products[(int)$item['invoice_id']][] = $name . ' x ' . (int)$item['quantity'];
    }
}

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
                    <?= isset($_GET['received']) ? 'Payment received for ' . htmlspecialchars((string)$_GET['received']) . ' and added to the wallet.' : (isset($_GET['cancelled']) ? 'E-shop order ' . htmlspecialchars((string)$_GET['cancelled']) . ' cancelled: products are back in stock and the due is cleared.' : 'Invoice Created Successfully.') ?>
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
                        <div class="col-md-2 mb-2">
                            <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-filter"></i> Search</button>
                        </div>
                    </form>

                    <table
                        id="example1"
                        class="table table-bordered table-striped">

                        <thead>

                        <tr>

                            <th class="invoice-list-no">Invoice No</th>
                            <th class="invoice-list-date">Date</th>
                            <th class="invoice-list-products">Product</th>
                            <?php if($show_customer_due_columns){ ?><th>Customer</th><?php } ?>
                            <?php if(!$restaurant_invoice_layout){ ?><th class="invoice-list-amount">Total</th><?php } ?>
                            <th class="invoice-list-amount"><?= $restaurant_invoice_layout ? 'Amount' : 'Paid' ?></th>
                            <?php if($show_customer_due_columns){ ?><th>Due</th><?php } ?>
                            <th class="invoice-list-status">Status</th>
                            <?php if($show_actions){ ?>
                                <th class="invoice-list-action">Action</th>
                            <?php } ?>

                        </tr>

                        </thead>

                        <tbody>

                        <?php
                        $sl = 1;

                        // The latest invoice of each customer is read once per customer, not once per row
                        // (same rule as can_modify_customer_invoice()).
                        $latest_customer_invoice = [];
                        foreach($invoice_rows as $row){
                            $row_customer_id = (int)$row['customer_id'];
                            if($row_customer_id > 0 && !isset($latest_customer_invoice[$row_customer_id])){
                                $latest_customer_invoice[$row_customer_id] = latest_existing_customer_invoice_id($conn, $user_id, $row_customer_id);
                            }
                            $can_modify_invoice = (int)$row['id'] > 0
                                && ($row_customer_id <= 0 || $latest_customer_invoice[$row_customer_id] === (int)$row['id']);

                            // An invoice linked to a refund, return or exchange is locked (edit/delete refuse it too).
                            if($can_modify_invoice){
                                try{
                                    sales_refund_protect_invoice($conn, (int)$user_id, (int)$row['id']);
                                }catch(RuntimeException $e){
                                    $can_modify_invoice = false;
                                }
                            }
                        ?>

                        <tr>


                            <td>
                                <?php echo $row['invoice_no']; ?>
                            </td>

                            <td>
                                <?php echo app_date($row['invoice_date']); ?>
                            </td>

                            <td class="invoice-list-products">
                                <?php foreach($invoice_products[(int)$row['id']] ?? [] as $product_line){ ?>
                                    <div><?= htmlspecialchars($product_line, ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php } ?>
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
                                    isset($cancelled_eshop_invoices[(int)$row['id']]) ? 0 : $row['paid_amount'],
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

                                $is_cancelled_row = isset($cancelled_eshop_invoices[(int)$row['id']]);
                                if($is_cancelled_row){

                                    echo '<span class="badge badge-danger" title="E-shop order cancelled: payment not received, products returned to stock.">
                                            Cancelled
                                          </span>';

                                }elseif(($row['accounting_status'] ?? 'posted') === 'pending'){

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

                                $row_return_state = '';
                                if(!$is_cancelled_row && $return_module_list && (float)$row['total_amount'] > 0 && ($row['created_by_type'] ?? '') !== 'sales_refund'){
                                    $row_return_state = sales_return_invoice_state($conn, $row);
                                    $return_badge = sales_return_state_badge($row_return_state);

                                    if($return_badge !== ''){
                                        echo '<br>' . $return_badge;
                                    }
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
                                <?php $is_eshop_row = ($row['created_by_type'] ?? '') === 'eshop'; ?>
                                <?php if(($row['accounting_status'] ?? 'posted') === 'pending'){ ?>
                                    <a href="post_invoice.php?id=<?php echo $row['id']; ?>&reload_parent=invoice_list"
                                       target="invoicePrintWindow"
                                       onclick="setTimeout(function(){ window.location.href='invoice_list.php'; }, 250);"
                                       class="btn btn-success btn-sm"
                                       <?= $is_eshop_row ? 'title="Confirm the online order: stock is deducted, money stays due (cash on delivery)."' : '' ?>>
                                        <?= $is_eshop_row ? 'Create Invoice' : 'Pay & Print' ?>
                                    </a>
                                <?php }else{ ?>
                                    <a href="print_invoice.php?id=<?php echo $row['id']; ?>"
                                       target="_blank"
                                       class="btn btn-success btn-sm">
                                        Print
                                    </a>
                                    <?php if($is_eshop_row && (float)($row['due_amount'] ?? 0) > 0.009 && ($row_return_state ?? '') !== 'returned' && manager_can_modify()){ ?>
                                    <a href="payment_entry.php?id=<?= (int)$row['id']; ?>&return=invoice_list"
                                       class="btn btn-primary btn-sm"
                                       title="Cash received from the courier/customer: record it into a wallet.">
                                        Received
                                    </a>
                                    <?php if((float)($row['paid_amount'] ?? 0) <= 0.009){ ?>
                                    <form method="post" action="eshop_cancel_invoice.php" class="d-inline"
                                          onsubmit="var r=prompt('Cancel this E-shop order? Products go back to stock and the full due (with delivery charge) is cleared.\n\nReason (optional):',''); if(r===null) return false; this.reason.value=r; return true;">
                                        <input type="hidden" name="invoice_id" value="<?= (int)$row['id']; ?>">
                                        <input type="hidden" name="reason" value="">
                                        <button type="submit" class="btn btn-outline-danger btn-sm" title="Money was not received: cancel the order and return the products to stock.">Cancel</button>
                                    </form>
                                    <?php } ?>
                                    <?php } ?>
                                <?php } ?>

                            </td>
                            <?php } ?>

                        </tr>

                        <?php } ?>

                        </tbody>

                    </table>


                    <style>
                        #example1 .invoice-list-no { min-width: 200px; }
                        #example1 .invoice-list-products { min-width: 180px; }
                        #example1 .invoice-list-status { width: 170px; }
                        #example1 .invoice-list-date { width: 150px; min-width: 130px; white-space: nowrap; }
                        #example1 .invoice-list-amount { width: 120px; min-width: 100px; }
                        #example1 .invoice-list-action { width: 1%; white-space: nowrap; }
                    </style>

                </div>

            </div>

        </div>


<?php
require_once '../includes/footer.php';
?>
