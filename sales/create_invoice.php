<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/invoice_charge_helper.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/customer_due_allocation_helper.php';
require_once '../includes/pending_invoice_stock_helper.php';
require_once '../includes/input_validation_helper.php';
require_once '../includes/staff_helper.php';
require_once '../includes/restaurant_table_helper.php';
require_once '../includes/invoice_reference_helper.php';
require_once '../includes/branch_context_helper.php';

$user_id = $_SESSION['user_id'];
ensure_invoice_posting_columns($conn);
ensure_invoice_charge_columns($conn);
ensure_staff_table($conn);
ensure_restaurant_tables_table($conn);
ensure_invoice_reference_columns($conn);
$table_system_is_enabled = table_system_enabled($conn, $user_id);

$pay_customer_id = isset($_GET['pay_customer_id'])
    ? (int)$_GET['pay_customer_id']
    : 0;
$existing_customer_phones = [];

/* Customers */

$customers = mysqli_query(
    $conn,
    "SELECT c.id,
            c.customer_name,
            c.phone
     FROM customers c
     WHERE c.user_id={$user_id}
     AND c.status='active'
     ORDER BY c.customer_name"
);

/* Products */

$products = mysqli_query(
    $conn,
    "SELECT p.id,
            p.product_name,
            p.sku,
            p.sale_price,
            p.current_stock,
            c.category_type
     FROM products p
     LEFT JOIN product_categories c ON c.id=p.category_id
     WHERE p.user_id={$user_id}
     AND p.status='active'
     ORDER BY p.product_name"
);

/* Wallets */

$wallets = active_wallets_result($conn, $user_id);

$reference_staff = mysqli_query($conn, "SELECT id, staff_code, name FROM staff WHERE user_id={$user_id} AND status='active' ORDER BY name");
$reference_tables = mysqli_query($conn, "SELECT id, staff_id, table_name FROM restaurant_tables WHERE user_id={$user_id} AND status='active' ORDER BY table_name");


/* Charge Types */

$charges = mysqli_query(
    $conn,
    "SELECT *
     FROM invoice_charge_types
     WHERE user_id={$user_id}
     AND status='active'
     AND show_on_invoice=1
     ORDER BY charge_name"
);

/* Recent invoices: same branch/user scope as Invoice List. */
$recent_branch_scope = branch_scope_sql($conn, 'invoices');
$recent_agent_user_id = (int)($_SESSION['login_user_id'] ?? 0);
if (is_agent_user()) {
    $recent_sql = "SELECT * FROM invoices WHERE user_id=? {$recent_branch_scope} AND created_by_user_id=? ORDER BY id DESC LIMIT 5";
    $recent_stmt = mysqli_prepare($conn, $recent_sql);
    mysqli_stmt_bind_param($recent_stmt, 'ii', $user_id, $recent_agent_user_id);
} else {
    $recent_sql = "SELECT * FROM invoices WHERE user_id=? {$recent_branch_scope} ORDER BY id DESC LIMIT 5";
    $recent_stmt = mysqli_prepare($conn, $recent_sql);
    mysqli_stmt_bind_param($recent_stmt, 'i', $user_id);
}
mysqli_stmt_execute($recent_stmt);
$recent_invoices = mysqli_stmt_get_result($recent_stmt);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">

            <i class="fas fa-file-invoice mr-2"></i>

            Create Invoice

        </h3>

    </div>

    <div class="card-body">

        <?php if(isset($_GET['success'])){ ?>
            <div class="alert alert-success" id="invoiceSuccessAlert">
                Sales voucher saved successfully.
            </div>
            <?php unset($_SESSION['error']); ?>
            <script>
                document.addEventListener('DOMContentLoaded', function(){
                    const url = new URL(window.location.href);
                    url.searchParams.delete('success');
                    window.history.replaceState({}, document.title, url.pathname + url.search + url.hash);

                    window.setTimeout(function(){
                        const alert = document.getElementById('invoiceSuccessAlert');
                        if(!alert){
                            return;
                        }

                        alert.style.transition = 'opacity 0.3s ease';
                        alert.style.opacity = '0';
                        window.setTimeout(function(){ alert.remove(); }, 300);
                    }, 3000);
                });
            </script>
        <?php } ?>

        <?php if(!empty($_SESSION['error'])){ ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($_SESSION['error']); ?>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php } ?>

        <form
            method="post"
            action="save_invoice.php">

            <!-- Customer Section -->

            <div class="row">

                <div class="col-md-4">

                    <label>

                        Customer Type

                    </label>

                    <select
                        id="customer_type"
                        class="form-control">

                        <option value="existing">

                            Existing Customer

                        </option>

                        <option value="instant" selected>

                            Instant Customer

                        </option>

                    </select>

                </div>

                <div
                    class="col-md-4"
                    id="existing_customer_div"
                    style="display:none;">

                    <label>

                        Customer

                    </label>

                    <select
                        name="customer_id"
                        class="form-control customer-select">

                        <option value="">

                            Select Customer

                        </option>

                        <?php while($c=mysqli_fetch_assoc($customers)){ ?>
                            <?php
                            $customer_option_label = $c['customer_name'];
                            $normalized_customer_phone = preg_replace('/[^0-9]/', '', (string)($c['phone'] ?? ''));
                            if(str_starts_with($normalized_customer_phone, '8801')){
                                $normalized_customer_phone = '0' . substr($normalized_customer_phone, 3);
                            }
                            if($normalized_customer_phone !== ''){
                                $existing_customer_phones[] = $normalized_customer_phone;
                            }
                            if(!empty($c['phone'])){
                                $customer_option_label .= ' (Ph. ' . $c['phone'] . ')';
                            }
                            ?>

                            <option
                                value="<?= $c['id']; ?>"
                                <?= $pay_customer_id === (int)$c['id'] ? 'selected' : ''; ?>
                                data-customer-balance="<?= number_format(customer_signed_balance_total($conn, $user_id, (int)$c['id']), 2, '.', ''); ?>"
                                data-customer-previous-due="<?= number_format(customer_previous_due_total($conn, $user_id, (int)$c['id']), 2, '.', ''); ?>">

                                <?= htmlspecialchars($customer_option_label); ?>

                            </option>

                        <?php } ?>

                    </select>

                </div>

                <div
                    class="col-md-4"
                    id="previous_due_div"
                    style="display:none;">

                    <label id="customer_balance_label">

                        Previous Due

                    </label>

                    <input
                        type="text"
                        id="previous_due_display"
                        class="form-control"
                        value="BDT 0.00"
                        readonly>

                </div>

<div
    class="col-md-4"
    id="instant_customer_div">

    <label>
        Instant Customer Name
    </label>

    <input
        type="text"
        name="customer_name"
        class="form-control"
        minlength="2"
        pattern=".*[A-Za-z].*">

</div>

<div
    class="col-md-4"
    id="instant_phone_div">

    <label>
        Phone Number
    </label>

    <input
        type="text"
        name="customer_phone"
        class="form-control"
        inputmode="numeric">

</div>

<div
    class="col-md-4 mt-3"
    id="instant_address_div">

    <label>
        Address
    </label>

    <textarea
        name="customer_address"
        class="form-control"
        rows="2"></textarea>

</div>

            </div>

            <hr>

            <!-- Product Table -->

            <h5>

                Products

            </h5>

            <div class="table-responsive">

            <table
                class="table table-bordered"
                id="productTable">

                <thead>

                <tr>

                    <th width="30%">

                        Product

                    </th>

                    <th class="variant-column d-none">Variant</th>

                    <th class="stock-column">

                        Stock

                    </th>

                    <th>

                        Price

                    </th>

                    <th>

                        Qty

                    </th>

                    <th>

                        Total

                    </th>

                    <th>

                        Action

                    </th>

                </tr>

                </thead>

                <tbody>

                <tr>

                    <td>

                        <select
                            name="product_id[]"
                            class="form-control product">

                            <option value="">

                                Select Product

                            </option>

                            <?php
                            mysqli_data_seek($products,0);

                            while($p=mysqli_fetch_assoc($products)){
                            ?>

                            <option
                                value="<?= $p['id']; ?>">

                                <?= htmlspecialchars(product_option_label($p['product_name'], $p['sku'] ?? '')); ?>

                            </option>

                            <?php } ?>

                        </select>

                    </td>

                    <td class="variant-column d-none"><select name="variant_name[]" class="form-control product-variant d-none"><option value="">No variant</option></select></td>

                    <td class="stock-column">

                        <input
                            type="text"
                            class="form-control stock"
                            readonly>

                    </td>

                    <td>

                        <input
                            type="number"
                            step="0.01"
                            name="price[]"
                            class="form-control price">

                    </td>

                    <td>

                        <input
                            type="number"
                            step="1"
                            name="qty[]"
                            value="1"
                            class="form-control qty">

                    </td>

                    <td>

                        <input
                            type="number"
                            step="0.01"
                            name="line_total[]"
                            class="form-control line_total"
                            readonly>

                    </td>

                    <td>

                        <button
                            type="button"
                            class="btn btn-danger removeRow">

                            X

                        </button>

                    </td>

                </tr>

                </tbody>

            </table>

            </div>

            <button
                type="button"
                id="addRow"
                class="btn btn-success">

                <i class="fas fa-plus"></i>

                Add Product

            </button>

            <hr>

            <!-- Charges -->

            <div class="row">

                <?php while($charge=mysqli_fetch_assoc($charges)){ ?>

                    <div class="col-md-3 mb-3">

                        <label>

                            <?= htmlspecialchars($charge['charge_name']); ?>

                        </label>

                        <input
                            type="hidden"
                            name="charge_id[]"
                            value="<?= $charge['id']; ?>">

                        <input
                            type="hidden"
                            class="charge_type"
                            value="<?= $charge['charge_type']; ?>">

                        <input
                            type="hidden"
                            class="charge_value_type"
                            value="<?= htmlspecialchars($charge['charge_value_type'] ?? 'fixed'); ?>">

                        <input
                            type="number"
                            step="0.01"
                            value="0"
                            name="charge_amount[]"
                            class="form-control charge"
                            placeholder="<?= ($charge['charge_value_type'] ?? 'fixed') === 'percent' ? '%' : 'Amount'; ?>">

                    </div>

                <?php } ?>

            </div>

            <hr>

            <!-- Summary -->

            <div class="row">

                <div class="col-md-4">

                    <label>

                        Grand Total

                    </label>

                    <input
                        type="number"
                        step="0.01"
                        id="grand_total"
                        name="grand_total"
                        class="form-control"
                        readonly>

                </div>

                <div class="col-md-4">

    <label>
        Paid Amount
    </label>

    <input
        type="number"
        step="0.01"
        min="0"
        name="paid_amount"
        id="paid_amount"
        class="form-control">

    <input
        type="hidden"
        name="payment_status"
        id="payment_status">

    <div
        id="paid_amount_warning"
        class="text-danger small mt-2"
        style="display:none;">
        For instant customers, the Paid Amount must be equal to the Grand Total.
    </div>

    <div class="mt-2">

        <label>

            Receive To Wallet

        </label>

        <select
            name="receive_wallet_id"
            id="receive_wallet_id"
            class="form-control">

            <?php
            mysqli_data_seek($wallets,0);

            while($wallet=mysqli_fetch_assoc($wallets)){
            ?>

            <option
                value="<?= $wallet['id']; ?>"
                <?= $wallet['is_system']==1 ? 'selected' : ''; ?>>

                <?= htmlspecialchars($wallet['wallet_name']); ?>

            </option>

            <?php } ?>

        </select>

    </div>

</div>


                <div class="col-md-4">

                    <label>

                        <span id="due_amount_label">Due Amount</span>

                    </label>

                    <input
                        type="number"
                        step="0.01"
                        id="due_amount"
                        name="due_amount"
                        class="form-control"
                        readonly>

                </div>

            </div>

            <br>

            <?php if($table_system_is_enabled){ ?><div class="form-group">
                <label>Ref.</label>
                <div class="row">
                    <div class="col-md-6">
                        <label class="font-weight-normal">Staff</label>
                        <select name="staff_id" id="reference_staff_id" class="form-control">
                            <option value="">Select Staff</option>
                            <?php while($staff=mysqli_fetch_assoc($reference_staff)){ ?><option value="<?=$staff['id']?>"><?=htmlspecialchars($staff['name'])?><?= $staff['staff_code'] ? ' (' . htmlspecialchars($staff['staff_code']) . ')' : '' ?></option><?php } ?>
                        </select>
                    </div>
                    <?php if($table_system_is_enabled){ ?><div class="col-md-6">
                        <label class="font-weight-normal">Table</label>
                        <select name="restaurant_table_id" id="reference_table_id" class="form-control" disabled>
                            <option value="">Select Table</option>
                            <?php while($table=mysqli_fetch_assoc($reference_tables)){ ?><option value="<?=$table['id']?>" data-staff-id="<?=$table['staff_id']?>"><?=htmlspecialchars($table['table_name'])?></option><?php } ?>
                        </select>
                    </div>
                    <?php } ?>
                </div>
            </div><?php } ?>

            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" class="form-control"></textarea>
            </div>

<input
    type="hidden"
    id="action"
    value="save">
            

<div class="d-flex flex-column flex-sm-row">

<button
    type="submit"
    name="action"
    value="save"
    class="btn btn-primary btn-lg mb-2 mb-sm-0"
    onclick="$('#action').val('save');">

    <i class="fas fa-save"></i>

    Save Invoice

</button>

<?php if(!is_agent_user()){ ?>

<button
    type="submit"
    name="action"
    value="print"
    class="btn btn-success btn-lg ml-sm-2"
    onclick="$('#action').val('print');">

    <i class="fas fa-print"></i>

    Pay & Print

</button>
<?php } ?>

</div>

        </form>

    </div>

</div>

<div class="card" id="recent-invoices-card">
    <div class="card-header d-flex align-items-center">
        <h3 class="card-title"><i class="fas fa-history mr-2"></i>Recent 5 Invoices</h3>
        <a href="invoice_list.php" class="btn btn-sm btn-outline-primary ml-auto">View All Invoices</a>
    </div>
    <div class="card-body table-responsive p-0">
        <table class="table table-bordered table-striped mb-0">
            <thead>
                <tr><th>Invoice No</th><th>Date</th><th>Customer</th><th>Total</th><th>Paid</th><th>Due</th><th>Status</th><th>Action</th></tr>
            </thead>
            <tbody id="recent-invoices-body">
            <?php while ($recent = mysqli_fetch_assoc($recent_invoices)) {
                $recent_pending = ($recent['accounting_status'] ?? 'posted') === 'pending';
                $recent_status = $recent_pending ? 'Pending' : (($recent['payment_status'] ?? '') === 'paid' ? 'Paid' : (($recent['payment_status'] ?? '') === 'partial' ? 'Partial' : 'Due'));
                $recent_badge = $recent_pending ? 'secondary' : (($recent['payment_status'] ?? '') === 'paid' ? 'success' : (($recent['payment_status'] ?? '') === 'partial' ? 'warning' : 'danger'));
            ?>
                <tr data-invoice-id="<?= (int)$recent['id']; ?>">
                    <td><?= htmlspecialchars($recent['invoice_no']); ?></td>
                    <td><?= htmlspecialchars(app_date($recent['invoice_date'])); ?></td>
                    <td><?= htmlspecialchars($recent['customer_name']); ?></td>
                    <td><?= number_format((float)$recent['total_amount'], 2); ?></td>
                    <td><?= number_format((float)$recent['paid_amount'], 2); ?></td>
                    <td><?= number_format((float)$recent['due_amount'], 2); ?></td>
                    <td><span class="badge badge-<?= $recent_badge; ?>"><?= $recent_status; ?></span></td>
                    <td class="text-nowrap">
                        <a href="view_invoice.php?id=<?= (int)$recent['id']; ?>" class="btn btn-info btn-sm">View</a>
                        <?php if ($recent_pending && !is_agent_user()) { ?>
                            <a href="post_invoice.php?id=<?= (int)$recent['id']; ?>&reload_parent=create" target="invoicePrintWindow" class="btn btn-success btn-sm">Pay &amp; Print</a>
                        <?php } elseif (!$recent_pending) { ?>
                            <a href="print_invoice.php?id=<?= (int)$recent['id']; ?>" target="_blank" class="btn btn-success btn-sm">Print</a>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
            <?php if (mysqli_num_rows($recent_invoices) === 0) { ?><tr id="recent-invoices-empty"><td colspan="8" class="text-center text-muted">No invoices created yet.</td></tr><?php } ?>
            </tbody>
        </table>
    </div>
</div>

<?php

$page_script = '

<script>

let paidAmountManuallyChanged = false;
const existingCustomerPhones = ' . json_encode(array_values(array_unique($existing_customer_phones))) . ';

function normalizePhoneForCompare(phone){
    let normalized = String(phone || "").replace(/[^0-9]/g, "");

    if(normalized.startsWith("8801")){
        normalized = "0" + normalized.slice(3);
    }

    return normalized;
}

function hasValidHumanName(name){
    const value = $.trim(String(name || ""));

    if(value.length < 2){
        return false;
    }

    if(!/[A-Za-z]/.test(value)){
        return false;
    }

    if(/^\d+$/.test(value)){
        return false;
    }

    return /^[A-Za-z0-9 .,&()\'-]+$/.test(value);
}

function hasValidPhone(phone){
    const digits = String(phone || "").replace(/[^0-9]/g, "");
    return digits.length >= 11 && digits.length <= 14;
}

function hasValidEmail(email){
    const value = $.trim(String(email || ""));

    if(value === ""){
        return true;
    }

    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}


$(document).ready(function(){

    initProductSelect($("#productTable"));
    initCustomerSupplierSelect($(document));
    updateVariantColumnVisibility();

    /* Customer Type */

    $("#customer_type").change(function(){

paidAmountManuallyChanged = false;

if($(this).val()=="instant"){

    $("#existing_customer_div").hide();

    $(".customer-select").val("").trigger("change");

    $("#previous_due_div").hide();
    $("#customer_balance_label").text("Previous Due");
    $("#previous_due_display").val("BDT 0.00");
    $("#returnable_balance_display").text("");

    $("#instant_customer_div").show();

    $("#instant_phone_div").show();

    $("#instant_address_div").show();

}else{

    $("#existing_customer_div").show();

    updatePreviousDueDisplay();

    $("#instant_customer_div").hide();

    $("#instant_phone_div").hide();

    $("#instant_address_div").hide();

    $("input[name=\"customer_name\"]").val("");
    $("input[name=\"customer_phone\"]").val("");
    $("textarea[name=\"customer_address\"]").val("");

}

    });

    $(document).on("change", ".customer-select", function(){
        paidAmountManuallyChanged = false;
        updatePreviousDueDisplay();
    });

    $("form[action=\"save_invoice.php\"]").on("submit", function(e){
        const currentAction = $("#action").val() || "save";
        const grandTotal = parseFloat($("#grand_total").val()) || 0;
        const paidTotal = parseFloat($("#paid_amount").val()) || 0;
        const isInstantCustomer = $("#customer_type").val() === "instant";

        if(paidTotal < 0){
            alert("Paid Amount cannot be negative.");
            e.preventDefault();
            return false;
        }

        if(isInstantCustomer && Math.abs(paidTotal - grandTotal) > 0.01){
            alert("For instant customers, the Paid Amount must be equal to the Grand Total.");
            e.preventDefault();
            return false;
        }

        if($("#customer_type").val() !== "instant"){
            return true;
        }

        const customerName = $.trim($("input[name=\"customer_name\"]").val());
        const customerPhone = $.trim($("input[name=\"customer_phone\"]").val());
        const customerAddress = $.trim($("textarea[name=\"customer_address\"]").val());
        const hasAnyCustomerData = customerName !== "" || customerPhone !== "" || customerAddress !== "";
        const normalizedPhone = normalizePhoneForCompare(customerPhone);

        if(normalizedPhone !== "" && existingCustomerPhones.includes(normalizedPhone)){
            alert("Phone already exists.");
            e.preventDefault();
            return false;
        }

        if(!hasAnyCustomerData){
            return true;
        }

        if(customerName === "" || customerPhone === ""){
            alert("Instant Customer register korte hole Customer Name and Phone Number duita dite hobe. Na hole sob field blank rakhun.");
            e.preventDefault();
            return false;
        }

        if(!hasValidHumanName(customerName)){
            alert("Customer Name valid dite hobe. Only number diye name hobe na.");
            e.preventDefault();
            return false;
        }

        if(!hasValidPhone(customerPhone)){
            alert("Phone Number valid dite hobe. Minimum 11 digits lagbe.");
            e.preventDefault();
            return false;
        }

        const instantEmail = "";

        if(!hasValidEmail(instantEmail)){
            alert("Invalid email address.");
            e.preventDefault();
            return false;
        }

        return true;
    });

    function showInvoiceAjaxMessage(message, success){
        let alertBox = $("#invoiceAjaxMessage");
        if(!alertBox.length){
            alertBox = $("<div id=\"invoiceAjaxMessage\" role=\"alert\"></div>");
            $("form[action=\"save_invoice.php\"]").before(alertBox);
        }
        alertBox
            .removeClass("alert-success alert-danger")
            .addClass("alert " + (success ? "alert-success" : "alert-danger"))
            .text(message)
            .stop(true, true)
            .show();
        if(success){
            window.setTimeout(function(){ alertBox.fadeOut(250); }, 3500);
        }
    }

    function resetInvoiceFormAfterAjax(){
        const form = $("form[action=\"save_invoice.php\"]");
        form[0].reset();
        $("#productTable tbody tr").slice(1).remove();
        const firstRow = $("#productTable tbody tr:first");
        firstRow.removeData("is-stock-product").removeData("has-variants");
        firstRow.find(".product").val("").trigger("change");
        firstRow.find(".product-variant")
            .html("<option value=\"\">No variant</option>")
            .val("")
            .prop("required", false)
            .removeClass("select2-hidden-accessible")
            .addClass("d-none");
        firstRow.find(".stock,.price,.line_total").val("");
        firstRow.find(".qty").val(1);
        $(".charge").val(0);
        $(".customer-select").val("").trigger("change");
        $("#customer_type").trigger("change");
        $("#reference_staff_id,#reference_table_id").val("").trigger("change");
        paidAmountManuallyChanged = false;
        $("#action").val("save");
        updateStockColumnVisibility();
        updateVariantColumnVisibility();
        calculateGrandTotal();
    }

    function prependRecentInvoice(invoice){
        if(!invoice) return;
        const body = $("#recent-invoices-body");
        body.find("#recent-invoices-empty").remove();
        const row = $("<tr>").attr("data-invoice-id", invoice.id);
        $("<td>").text(invoice.invoice_no).appendTo(row);
        $("<td>").text(invoice.invoice_date).appendTo(row);
        $("<td>").text(invoice.customer_name).appendTo(row);
        $("<td>").text(invoice.total_amount).appendTo(row);
        $("<td>").text(invoice.paid_amount).appendTo(row);
        $("<td>").text(invoice.due_amount).appendTo(row);
        $("<td>").append($("<span>").addClass("badge badge-" + invoice.badge).text(invoice.status)).appendTo(row);
        const actions = $("<td>").addClass("text-nowrap");
        $("<a>").attr("href", "view_invoice.php?id=" + invoice.id).addClass("btn btn-info btn-sm").text("View").appendTo(actions);
        if(invoice.print_url){
            $("<a>").attr({href: invoice.print_url, target: "_blank"}).addClass("btn btn-success btn-sm ml-1").text(invoice.status === "Pending" ? "Pay & Print" : "Print").appendTo(actions);
        }
        actions.appendTo(row);
        body.prepend(row);
        body.children("tr").slice(5).remove();
    }

    $("form[action=\"save_invoice.php\"]").on("submit.invoiceAjax", async function(e){
        if(e.isDefaultPrevented()) return;
        e.preventDefault();

        const form = this;
        const submitter = e.originalEvent && e.originalEvent.submitter ? e.originalEvent.submitter : null;
        const currentAction = submitter && submitter.value ? submitter.value : ($("#action").val() || "save");
        $("#action").val(currentAction);
        const buttons = $(form).find("button[type=\"submit\"]");
        const originalPhone = $.trim($(form).find("input[name=\"customer_phone\"]").val());
        let printWindow = null;

        if(currentAction === "print"){
            printWindow = window.open("about:blank", "invoicePrintWindow");
            if(printWindow){
                printWindow.document.write("<!doctype html><title>Preparing invoice...</title><p style=\"font-family:Arial;padding:24px\">Preparing invoice...</p>");
                printWindow.document.close();
            }
        }

        buttons.prop("disabled", true);
        const formData = new FormData(form);
        formData.set("action", currentAction);
        formData.set("ajax", "1");

        try{
            const response = await fetch("save_invoice.php", {
                method: "POST",
                body: formData,
                headers: {"X-Requested-With": "XMLHttpRequest"}
            });
            const raw = await response.text();
            let result;
            try{ result = JSON.parse(raw); }catch(parseError){ throw new Error("Invalid server response. Please try again."); }
            if(!response.ok || !result.ok) throw new Error(result.message || "Invoice could not be saved.");

            const normalizedPhone = normalizePhoneForCompare(originalPhone);
            if(normalizedPhone && !existingCustomerPhones.includes(normalizedPhone)) existingCustomerPhones.push(normalizedPhone);
            prependRecentInvoice(result.invoice);
            resetInvoiceFormAfterAjax();
            showInvoiceAjaxMessage(result.message + " Invoice: " + result.invoice_no, true);

            if(currentAction === "print" && result.print_url){
                if(printWindow && !printWindow.closed){
                    printWindow.location.replace(result.print_url);
                }else{
                    window.open(result.print_url, "_blank");
                }
            }
        }catch(error){
            if(printWindow && !printWindow.closed) printWindow.close();
            showInvoiceAjaxMessage(error.message || "Invoice could not be saved.", false);
        }finally{
            buttons.prop("disabled", false);
        }
    });

    /* Product Change */

    $(document).on("change",".product",function(){

        let row = $(this).closest("tr");

        let product_id = $(this).val();

        if(product_id==""){
            row.find(".product-variant")
                .html("<option value=\"\">No variant</option>")
                .prop("disabled", false)
                .prop("required", false)
                .addClass("d-none");
            row.removeData("has-variants");
            updateVariantColumnVisibility();
            return;
        }

        $.ajax({

    url: "get_product.php",

    type: "POST",

    data: {
        product_id: product_id
    },

    dataType: "json",

    success: function(res){

        if(res.success){

            row.find(".stock")
                .val(res.product.is_stock_product ? res.product.available_stock : "Unlimited");

            let variant = row.find(".product-variant");
            variant.html("");
            if((res.product.variants || []).length){
                variant.append("<option value=\"\">Select variant</option>");
                res.product.variants.forEach(function(name){ variant.append($("<option>").val(name).text(name)); });
                variant.prop("disabled", false).prop("required", true).removeClass("d-none");
                row.find(".stock").val("Select variant");
                row.data("has-variants", true);
            }else{
                variant.append("<option value=\"\">No variant</option>")
                    .prop("disabled", false)
                    .prop("required", false)
                    .addClass("d-none");
                row.data("has-variants", false);
            }

            row.data("is-stock-product", !!res.product.is_stock_product);
            updateStockColumnVisibility();
            updateVariantColumnVisibility();

            row.find(".price")
                .val(res.product.sale_price);

            calculateRow(row);

        }

    }

});

    });

    $(document).on("change", ".product-variant", function(){
        let row=$(this).closest("tr"), productId=row.find(".product").val(), variantName=$(this).val();
        if(!productId || !variantName){ row.find(".stock").val("Select variant"); return; }
        $.ajax({url:"get_product.php",type:"POST",data:{product_id:productId,variant_name:variantName},dataType:"json",success:function(res){
            if(res.success){ row.find(".stock").val(res.product.is_stock_product ? res.product.available_stock : "Unlimited"); }
        }});
    });

    /* Qty / Price Change */

    $(document).on(

        "keyup change",

        ".qty,.price",

        function(){

            let row = $(this).closest("tr");

            calculateRow(row);

        }

    );

    /* Charge Change */

    $(document).on(

        "keyup change",

        ".charge",
        

        function(){

            calculateGrandTotal();

        }

    );

    /* Paid Amount Change */

$(document).on(

    "keyup change",

    "#paid_amount",

    function(){

        paidAmountManuallyChanged = true;

        calculateDue();

    }

);


    /* Add Row */

    $("#addRow").click(function(){

        let row = $("#productTable tbody tr:first")
                    .clone();

        row.find(".select2-container").remove();

        row.find("input").val("");

        row.find(".qty").val(1);
        row.find(".product-variant")
            .html("<option value=\"\">No variant</option>")
            .prop("disabled", false)
            .prop("required", false)
            .addClass("d-none");
        row.removeData("is-stock-product");
        row.removeData("has-variants");

        row.find("select")
            .val("")
            .removeClass("select2-hidden-accessible")
            .removeAttr("data-select2-id tabindex aria-hidden");

        row.find("option").removeAttr("data-select2-id");

        $("#productTable tbody").append(row);

        initProductSelect(row);
        updateVariantColumnVisibility();

    });

    /* Remove Row */

    $(document).on(

        "click",

        ".removeRow",

        function(){

            if($("#productTable tbody tr").length > 1){

                $(this).closest("tr").remove();

                updateStockColumnVisibility();
                updateVariantColumnVisibility();
                calculateGrandTotal();

            }

        }

    );

    const payCustomerId = ' . (int)$pay_customer_id . ';

    if(payCustomerId > 0){
        $("#customer_type").val("existing").trigger("change");
        $(".customer-select").val(String(payCustomerId)).trigger("change");

        calculateGrandTotal();
        calculateDue();
    }

});

function initProductSelect(context){

    context.find(".product").each(function(){

        if($(this).hasClass("select2-hidden-accessible")){
            return;
        }

        $(this).select2({
            theme: "bootstrap4",
            width: "100%",
            placeholder: "Select Product",
            allowClear: true
        });

    });

}

function updateStockColumnVisibility(){
    var selectedRows = $("#productTable tbody tr").filter(function(){
        return $(this).find(".product").val() !== "";
    });
    var hasStockProduct = selectedRows.toArray().some(function(row){
        return $(row).data("is-stock-product") === true;
    });

    // When every selected product is Non Stock, stock is not relevant to the sale.
    $("#productTable .stock-column").toggle(hasStockProduct || selectedRows.length === 0);
}

function updateVariantColumnVisibility(){
    var hasVariantProduct = $("#productTable tbody tr").toArray().some(function(row){
        return $(row).find(".product").val() !== "" && $(row).data("has-variants") === true;
    });

    $("#productTable .variant-column").toggleClass("d-none", !hasVariantProduct);
}

function initCustomerSupplierSelect(context){

    context.find(".customer-select").each(function(){

        if($(this).hasClass("select2-hidden-accessible")){
            return;
        }

        $(this).select2({
            theme: "bootstrap4",
            width: "100%",
            placeholder: "Select Customer",
            allowClear: true
        });

    });

}

function updatePreviousDueDisplay(){

    if($("#customer_type").val() !== "existing"){
        $("#previous_due_div").hide();
        $("#customer_balance_label").text("Previous Due");
        $("#previous_due_display").val("BDT 0.00");
        if(!paidAmountManuallyChanged){
            $("#paid_amount").val((parseFloat($("#grand_total").val()) || 0).toFixed(2));
        }
        calculateDue();
        return;
    }

    let selectedOption = $(".customer-select option:selected");
    let customerBalance = parseFloat(selectedOption.data("customer-balance")) || 0;
    let outstandingAmount = customerBalance < 0 ? Math.abs(customerBalance) : 0;
    let previousDue = parseFloat(selectedOption.data("customer-previous-due")) || 0;

    if(customerBalance < 0){
        $("#customer_balance_label").text("Outstanding Amount");
        $("#previous_due_display").val("BDT " + outstandingAmount.toFixed(2));
        $("#previous_due_div").show();
    }else if(previousDue > 0){
        $("#customer_balance_label").text("Previous Due");
        $("#previous_due_display").val("BDT " + previousDue.toFixed(2));
        $("#previous_due_div").show();
    }else{
        $("#customer_balance_label").text("Previous Due");
        $("#previous_due_display").val("BDT 0.00");
        $("#previous_due_div").hide();
    }

    if(!paidAmountManuallyChanged){
        let grand = parseFloat($("#grand_total").val()) || 0;
        $("#paid_amount").val(autoPaidAmountForCurrentSelection(grand).toFixed(2));
    }

    calculateDue();

}

function selectedCustomerBalanceAmount(){

    if($("#customer_type").val() !== "existing"){
        return 0;
    }

    let selectedOption = $(".customer-select option:selected");
    return parseFloat(selectedOption.data("customer-balance")) || 0;

}

function selectedCustomerPreviousDueAmount(){

    if($("#customer_type").val() !== "existing"){
        return 0;
    }

    let selectedOption = $(".customer-select option:selected");
    return parseFloat(selectedOption.data("customer-previous-due")) || 0;

}

function autoPaidAmountForCurrentSelection(grand){

    grand = parseFloat(grand) || 0;

    if($("#customer_type").val() !== "existing"){
        return grand;
    }

    let customerBalance = selectedCustomerBalanceAmount();
    let previousDue = selectedCustomerPreviousDueAmount();
    let outstandingAmount = customerBalance < 0 ? Math.abs(customerBalance) : 0;

    if(outstandingAmount > 0){
        return Math.max(grand - outstandingAmount, 0);
    }

    if(previousDue > 0){
        return grand + previousDue;
    }

    return grand;

}

/* Row Total */

function calculateRow(row){

    let price = parseFloat(
        row.find(".price").val()
    ) || 0;

    let qty = parseFloat(
        row.find(".qty").val()
    ) || 0;

    let total = price * qty;

    row.find(".line_total")
       .val(total.toFixed(2));

    calculateGrandTotal();

}

/* Grand Total */

function calculateGrandTotal(){

    let subtotal = 0;

    $(".line_total").each(function(){

        subtotal += parseFloat(
            $(this).val()
        ) || 0;

    });

    let grand = subtotal;

    $(".charge").each(function(){

        let amount = parseFloat(
            $(this).val()
        ) || 0;

        let wrapper = $(this).closest(".col-md-3");

        let type = wrapper
                    .find(".charge_type")
                    .val();

        let valueType = wrapper
                    .find(".charge_value_type")
                    .val();

        let chargeAmount = valueType == "percent"
            ? (subtotal * amount / 100)
            : amount;

        if(type=="less"){

            grand -= chargeAmount;

        }else{

            grand += chargeAmount;

        }

    });

    $("#grand_total")
        .val(grand.toFixed(2));

    if(!paidAmountManuallyChanged){
        $("#paid_amount")
            .val(autoPaidAmountForCurrentSelection(grand).toFixed(2));

    }

    calculateDue();

}

/* Due */

function calculateDue(){

    let grand = parseFloat(
        $("#grand_total").val()
    ) || 0;

    let paid = parseFloat(
        $("#paid_amount").val()
    ) || 0;

    let customerBalance = selectedCustomerBalanceAmount();
    let isInstantCustomer = $("#customer_type").val() === "instant";
    let previousDueTotal = !isInstantCustomer
        ? Math.max(customerBalance, 0)
        : 0;
    let outstandingAmountTotal = !isInstantCustomer
        ? Math.abs(Math.min(customerBalance, 0))
        : 0;
    let finalCustomerBalance = customerBalance + grand - paid;
    let totalDue = isInstantCustomer
        ? Math.max(grand - paid, 0)
        : Math.max(finalCustomerBalance, 0);
    let outstandingPayable = !isInstantCustomer && finalCustomerBalance < -0.01
        ? Math.abs(finalCustomerBalance)
        : 0;
    let appliedOutstandingAmount = !isInstantCustomer && outstandingAmountTotal > 0
        ? Math.min(outstandingAmountTotal, Math.max(grand - Math.min(paid, grand), 0))
        : 0;

    if(isInstantCustomer){
        $("#due_amount_label").text("Due Amount");
        $("#due_amount").val((grand - paid).toFixed(2));
    }else if(outstandingPayable > 0.01){
        $("#due_amount_label").text("Outstanding Amount");
        $("#due_amount").val(outstandingPayable.toFixed(2));
    }else{
        $("#due_amount_label").text("Due Amount");
        $("#due_amount").val(totalDue.toFixed(2));
    }

    if(isInstantCustomer && Math.abs(paid - grand) > 0.01){
        $("#paid_amount_warning").show();
    }else{
        $("#paid_amount_warning").hide();
    }
    let status = "due";

    if(isInstantCustomer){
        if(Math.abs(grand - paid) <= 0.01){
            status = "paid";
        }else if(paid > 0){
            status = "partial";
        }
    }else if(outstandingPayable > 0.01 || totalDue <= 0.01){

        status = "paid";

    }else if(paid > 0 || appliedOutstandingAmount > 0){

        status = "partial";

    }

    $("#payment_status").val(status);

    if(paid > 0){

        $("#receive_wallet_id")
            .prop("required", true);

    }else{

        $("#receive_wallet_id")
            .prop("required", false);

    }

}

</script>

<script>
document.addEventListener("DOMContentLoaded", function(){
    const staff = document.getElementById("reference_staff_id");
    const table = document.getElementById("reference_table_id");
    if(!staff || !table) return;
    function filterTables(){
        const selected = staff.value;
        table.value = "";
        table.disabled = !selected;
        const matching = Array.from(table.options).filter(function(option){
            if(!option.value) return;
            option.hidden = !selected || option.dataset.staffId !== selected;
            return selected && option.dataset.staffId === selected;
        });
        if(matching.length === 1){ table.value = matching[0].value; }
    }
    staff.addEventListener("change", filterTables);
    filterTables();
});
</script>

';
?>

<?php
require_once '../includes/footer.php';
?>
