<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/invoice_charge_helper.php';
require_once '../includes/customer_due_allocation_helper.php';
require_once '../includes/pending_invoice_stock_helper.php';
require_once '../includes/staff_helper.php';
require_once '../includes/restaurant_table_helper.php';
require_once '../includes/invoice_reference_helper.php';
require_once '../includes/branch_context_helper.php';
require_once '../includes/product_category_helper.php';

$user_id = $_SESSION['user_id'];
ensure_invoice_charge_columns($conn);
ensure_staff_table($conn);
ensure_restaurant_tables_table($conn);
ensure_invoice_reference_columns($conn);
$table_system_is_enabled = table_system_enabled($conn, $user_id);
$restaurant_catalog_is_enabled = restaurant_catalog_enabled($conn, $user_id);
$restaurant_invoice_reference_enabled = $table_system_is_enabled && restaurant_invoice_reference_enabled($conn, $user_id);
$restaurant_invoice_reference_type = $table_system_is_enabled ? restaurant_invoice_reference_type($conn, $user_id) : 'staff';

$invoice_id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;
require_branch_record_access($conn, 'invoices', $invoice_id, 'invoice_list.php');

/* Invoice */

$sql = "SELECT *
        FROM invoices
        WHERE id=?
        AND user_id=?";

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

$show_invoice_reference = $restaurant_invoice_reference_enabled
    && ((int)($invoice['staff_id'] ?? 0) > 0 || (int)($invoice['restaurant_table_id'] ?? 0) > 0);

$reference_staff = mysqli_query($conn, "SELECT id, staff_code, name FROM staff WHERE user_id={$user_id} AND (status='active' OR id=" . (int)($invoice['staff_id'] ?? 0) . ") ORDER BY name");
$reference_tables = mysqli_query($conn, "SELECT id, staff_id, table_name FROM restaurant_tables WHERE user_id={$user_id} AND status='active' ORDER BY table_name");

if(!can_modify_customer_invoice(
    $conn,
    $user_id,
    $invoice_id,
    (int)$invoice['customer_id']
)){
    die(customer_invoice_modify_lock_message());
}

$customer_balance_before_invoice = customer_signed_balance_total(
    $conn,
    $user_id,
    (int)$invoice['customer_id'],
    $invoice_id
);

if((int)$invoice['customer_id'] > 0){
    $customer_balance_before_invoice += customer_source_invoice_all_payment_total(
        $conn,
        $user_id,
        (int)$invoice['customer_id'],
        $invoice_id,
        $invoice['invoice_no']
    );
}

$previous_due_total = $customer_balance_before_invoice > 0
    ? $customer_balance_before_invoice
    : 0;
$outstanding_amount_total = $customer_balance_before_invoice < 0
    ? abs($customer_balance_before_invoice)
    : 0;
$display_paid_amount = (float)$invoice['paid_amount'];
$returnable_balance_summary = customer_returnable_balance_summary_text(
    $conn,
    $user_id,
    (int)$invoice['customer_id'],
    $invoice_id
);

/* Invoice Items */

$sql = "SELECT *
        FROM invoice_items
        WHERE invoice_id=?";

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
            ic.*,
            ict.charge_name,
            ict.charge_type,
            ict.charge_value_type
        FROM invoice_charges ic
        LEFT JOIN invoice_charge_types ict
        ON ict.id = ic.charge_type_id
        WHERE ic.invoice_id=?
        ORDER BY ic.id ASC";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $invoice_id
);

mysqli_stmt_execute($stmt);

$charges =
    mysqli_stmt_get_result($stmt);
$charge_rows = $charges ? mysqli_fetch_all($charges, MYSQLI_ASSOC) : [];

/* Products */

$products = mysqli_query(
    $conn,
    "SELECT
        p.id,
        p.product_name,
        p.sku,
        p.sale_price,
        p.current_stock,
        c.category_type
     FROM products p
     LEFT JOIN product_categories c ON c.id=p.category_id
     WHERE p.user_id={$user_id}
     AND p.status='active'
     ORDER BY product_name"
);

/* Wallets */

$wallets = active_wallets_result($conn, $user_id);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">

            Edit Invoice

        </h3>

    </div>

<div class="card-body">

        <?php if(!empty($_SESSION['error'])){ ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($_SESSION['error']); ?>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php } ?>

        <form
            method="post"
            action="update_invoice.php">

            <input
                type="hidden"
                name="invoice_id"
                value="<?php echo $invoice_id; ?>">

            <div class="row">

                <div class="col-md-4">

                    <label>
                        Customer
                    </label>

                    <input
                        type="text"
                        class="form-control"
                        value="<?php echo htmlspecialchars($invoice['customer_name']); ?>"
                        readonly>
                    <input
                        type="hidden"
                        name="customer_id"
                        value="<?= $invoice['customer_id']; ?>">
                    <input
                        type="hidden"
                        id="is_instant_customer"
                        value="<?= (int)$invoice['customer_id'] === 0 ? '1' : '0'; ?>">

                </div>

                <?php if($previous_due_total > 0 || $outstanding_amount_total > 0){ ?>
                <div class="col-md-4">

                    <label id="customer_balance_label">
                        <?= $outstanding_amount_total > 0 ? 'Outstanding Amount' : 'Previous Due'; ?>
                    </label>

                    <input
                        type="text"
                        class="form-control"
                        value="BDT <?= number_format($outstanding_amount_total > 0 ? $outstanding_amount_total : $previous_due_total, 2); ?>"
                        readonly>

                </div>
                <?php } ?>

                <div class="col-md-4">

                    <label>
                        Invoice Date
                    </label>

                    <input
                        type="date"
                        class="form-control"
                        value="<?php echo $invoice['invoice_date']; ?>"
                        readonly>
                    
                    <input
                        type="hidden"
                        name="invoice_date"
                        value="<?= $invoice['invoice_date']; ?>">

                </div>

            </div>

            <hr>

            <table
                class="table table-bordered">

                <thead>

                <tr>

                    <th width="35%">Product</th>
                    <th class="variant-column">Variant</th>
                    <th width="15%" class="stock-column">Stock</th>
                    <th width="12%">Qty</th>
                    <th width="15%">Price</th>
                    <th width="15%">Total</th>

                </tr>

                </thead>

                <tbody>

                <?php
                while(
                    $item =
                    mysqli_fetch_assoc($items)
                ){
                ?>

                <tr>

                    <td>

                        <select
                            name="product_id[]"
                            class="form-control product">

                        <?php
                        mysqli_data_seek($products,0);

                        while($p=mysqli_fetch_assoc($products)){
                        ?>

                        <option
                        value="<?= $p['id']; ?>"
                        <?= ($p['id']==$item['product_id']) ? 'selected' : ''; ?>>

                        <?= htmlspecialchars(product_option_label($p['product_name'], $p['sku'] ?? '')); ?>

                        </option>

                        <?php } ?>

                        </select>

                        </td>

                        <td class="variant-column">
                            <input type="hidden" name="variant_name[]" class="product-variant" value="<?= htmlspecialchars($item['variant_name'] ?? ''); ?>">
                            <div class="variant-choice-list"></div>
                        </td>

                        <td class="stock-column">

                        <input
                        type="text"
                        class="form-control stock"
                        readonly>

                        </td>
                    <td>

                        <input
                        type="number"
                        step="1"
                        name="qty[]"
                        value="<?php echo $item['quantity']; ?>"
                        class="form-control qty"
                        required>
                            
                    </td>

                    <td>

                        <input
                        type="number"
                        step="0.01"
                        name="price[]"
                        value="<?php echo $item['unit_price']; ?>"
                        class="form-control price"
                        required>
                    
                    </td>

                    <td>

                        <input type="number"
                            name="line_total[]"
                            value="<?php echo $item['total_price']; ?>"
                            class="form-control line_total"
                            readonly>

                    </td>

                </tr>

                <?php } ?>

                </tbody>

            </table>
            <hr>

            <?php if($charge_rows){ ?><h5>Charges</h5>

            <div class="row">

            <?php foreach($charge_rows as $charge){ ?>

            <div class="col-md-3">

            <label>
            <?= htmlspecialchars($charge['charge_name'] ?? 'Invoice Charge'); ?>
            <small class="text-muted">(<?= ($charge['charge_value_type'] ?? 'fixed') === 'percent' ? 'saved amount' : 'amount'; ?>)</small>
            </label>

<input
    type="hidden"
    name="charge_id[]"
    value="<?= $charge['charge_type_id']; ?>">

<input
    type="hidden"
    class="charge_type"
    value="<?= $charge['charge_type']; ?>">

<input
    type="number"
    step="0.01"
    min="0"
    name="charge_amount[]"
    value="<?= $charge['amount']; ?>"
    class="form-control charge">

            </div>

            <?php } ?>

            </div>
            <hr><?php } ?>

<div class="row">

    <div class="col-md-4">

        <label>Grand Total</label>

        <input type="number"
            id="grand_total"
            name="grand_total"
            class="form-control"
            value="<?php echo $invoice['total_amount']; ?>">
        
        <input
            type="hidden"
            name="payment_status"
            value="<?= $invoice['payment_status']; ?>">
        <input
            type="hidden"
            id="customer_balance_amount"
            value="<?= number_format($customer_balance_before_invoice, 2, '.', ''); ?>">

        <div
            id="paid_amount_warning"
            class="text-danger small mt-2"
            style="display:none;">
            For instant customers, the Paid Amount must be equal to the Grand Total.
        </div>
    </div>

    <div class="col-md-4">

        <label>Paid Amount</label>

        <input
            type="number"
            step="0.01"
            min="0"
            name="paid_amount"
            id="paid_amount"
            value="<?= number_format($display_paid_amount, 2, '.', ''); ?>"
            class="form-control">

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

<?= $wallet['id']==$invoice['receive_wallet_id'] ? 'selected' : ''; ?>>

<?= htmlspecialchars($wallet['wallet_name']); ?>

</option>

<?php } ?>

</select>

</div>

    </div>

            <?php if($restaurant_catalog_is_enabled){ ?><input type="hidden" name="due_amount" id="due_amount" value="<?= $invoice['due_amount']; ?>">
            <?php }else{ ?><div class="col-md-4">

                <label><span id="due_amount_label">Due Amount</span></label>

                <input
                    type="number"
                    step="0.01"
                    name="due_amount"
                    id="due_amount"
                    value="<?= $invoice['due_amount']; ?>"
                    class="form-control"
                    readonly>

            </div><?php } ?>

        </div>

            <br>
            <?php if($show_invoice_reference){ ?><div class="form-group mt-3">
                <label>Ref.</label>
                <?php if($restaurant_invoice_reference_type === 'table'){ ?><input type="hidden" name="staff_id" id="reference_staff_id" value="<?= (int)($invoice['staff_id'] ?? 0) ?>"><select name="restaurant_table_id" id="reference_table_id" class="form-control"><option value="">Search ref.</option><?php while($table=mysqli_fetch_assoc($reference_tables)){ ?><option value="<?=$table['id']?>" data-staff-id="<?=$table['staff_id']?>" <?=((int)($invoice['restaurant_table_id'] ?? 0)===(int)$table['id'])?'selected':''?> <?= (int)$table['staff_id'] <= 0 ? 'disabled' : '' ?>><?=htmlspecialchars($table['table_name'])?></option><?php } ?></select><?php }else{ ?><select name="staff_id" id="reference_staff_id" class="form-control"><option value="">Search ref.</option><?php while($staff=mysqli_fetch_assoc($reference_staff)){ ?><option value="<?=$staff['id']?>" <?=((int)($invoice['staff_id'] ?? 0)===(int)$staff['id'])?'selected':''?>><?=htmlspecialchars($staff['name'])?><?= $staff['staff_code'] ? ' (' . htmlspecialchars($staff['staff_code']) . ')' : '' ?></option><?php } ?></select><?php } ?>
            </div><?php } ?>

            <div class="form-group mt-3"><label>Notes</label><textarea name="notes" class="form-control"><?= htmlspecialchars($invoice['notes']); ?></textarea></div>

            <button
                type="submit"
                class="btn btn-primary" id="update-invoice-button" <?= $show_invoice_reference ? 'disabled' : '' ?>>

                Update Invoice

            </button>

        </form>

    </div>

</div>
<?php require_once '../includes/footer.php'; ?>
<script>

let paidAmountManuallyChanged = true;
let allowAutoPaidSync = false;

function autoPaidAmountForCurrentSelection(grandTotal){

    grandTotal = parseFloat(grandTotal) || 0;

    let isInstantCustomer =
        $('#is_instant_customer').val() === '1';

    if(isInstantCustomer){
        return grandTotal;
    }

    let customerBalance =
        parseFloat(
            $('#customer_balance_amount').val()
        ) || 0;

    let previousDueTotal = Math.max(customerBalance, 0);
    let outstandingAmountTotal = Math.abs(Math.min(customerBalance, 0));

    if(outstandingAmountTotal > 0){
        return Math.max(grandTotal - outstandingAmountTotal, 0);
    }

    if(previousDueTotal > 0){
        return grandTotal + previousDueTotal;
    }

    return grandTotal;

}

function calculateInvoice(){

    let grandTotal = 0;

    $('table tbody tr').each(function(){

        let qty = parseFloat(
            $(this)
            .find('input[name="qty[]"]')
            .val()
        ) || 0;

        let price = parseFloat(
            $(this)
            .find('input[name="price[]"]')
            .val()
        ) || 0;

        let total = qty * price;

        $(this)
        .find('input[name="line_total[]"]')
        .val(total.toFixed(2));

        grandTotal += total;

    });


    $('.charge').each(function(){

    let amount = parseFloat($(this).val()) || 0;

   let type = $(this)
            .prev(".charge_type")
            .val();

    if(type == "less"){

        grandTotal -= amount;

    }else{

        grandTotal += amount;

    }

});
    $('#grand_total')
        .val(grandTotal.toFixed(2));

    let isInstantCustomer =
        $('#is_instant_customer').val() === '1';

    if(allowAutoPaidSync && !paidAmountManuallyChanged){
        $('#paid_amount').val(
            autoPaidAmountForCurrentSelection(grandTotal).toFixed(2)
        );
    }

    let paid =
        parseFloat(
            $('#paid_amount').val()
        ) || 0;

    if(isInstantCustomer && Math.abs(paid - grandTotal) > 0.01){
        $('#paid_amount').addClass('is-invalid');
    }else{
        $('#paid_amount').removeClass('is-invalid');
    }

    let customerBalance =
        parseFloat(
            $('#customer_balance_amount').val()
        ) || 0;

    let previousDueTotal =
        !isInstantCustomer
            ? Math.max(customerBalance, 0)
            : 0;
    let outstandingAmountTotal =
        !isInstantCustomer
            ? Math.abs(Math.min(customerBalance, 0))
            : 0;
    let currentInvoiceCashPayment =
        Math.min(paid, grandTotal);
    let appliedOutstandingAmount = !isInstantCustomer && outstandingAmountTotal > 0
        ? Math.min(
            outstandingAmountTotal,
            Math.max(grandTotal - currentInvoiceCashPayment, 0)
        )
        : 0;
    let finalCustomerBalance = customerBalance + grandTotal - paid;
    let outstandingPayable = !isInstantCustomer && finalCustomerBalance < -0.01
        ? Math.abs(finalCustomerBalance)
        : 0;
    let totalDue = isInstantCustomer
        ? Math.max(grandTotal - paid, 0)
        : Math.max(finalCustomerBalance, 0);

    if(isInstantCustomer){
        $('#due_amount_label').text('Due Amount');
        $('#due_amount').val((grandTotal - paid).toFixed(2));
    }else if(outstandingPayable > 0.01){
        $('#due_amount_label').text('Outstanding Amount');
        $('#due_amount').val(outstandingPayable.toFixed(2));
    }else{
        $('#due_amount_label').text('Due Amount');
        $('#due_amount').val(totalDue.toFixed(2));
    }

    if(isInstantCustomer && Math.abs(paid - grandTotal) > 0.01){
        $('#paid_amount_warning').show();
    }else{
        $('#paid_amount_warning').hide();
    }

    let status = 'due';

    if(isInstantCustomer){
        if(Math.abs(grandTotal - paid) <= 0.01){
            status = 'paid';
        }else if(paid > 0){
            status = 'partial';
        }
    }else if(outstandingPayable > 0.01 || totalDue <= 0.01){

        status = 'paid';

    }else if(paid > 0 || appliedOutstandingAmount > 0){

        status = 'partial';

    }

    $('input[name="payment_status"]')
        .val(status);
            if(paid > 0){



    $("#receive_wallet_id")

        .prop("required", true);



}else{



    $("#receive_wallet_id")

        .prop("required", false);



}

}

$(document).on(
    'keyup change',
    'input[name="qty[]"], input[name="price[]"], .charge',
    function(){

        calculateInvoice();

    }
);

$(document).on(
    'keyup change',
    '#paid_amount',
    function(){

        paidAmountManuallyChanged = true;
        calculateInvoice();

    }
);

$('form[action="update_invoice.php"]').on('submit', function(e){

    let grandTotal = parseFloat($('#grand_total').val()) || 0;
    let paid = parseFloat($('#paid_amount').val()) || 0;
    let isInstantCustomer = $('#is_instant_customer').val() === '1';

    if(paid < 0){
        alert('Paid Amount cannot be negative.');
        e.preventDefault();
        return false;
    }

    if(isInstantCustomer && Math.abs(paid - grandTotal) > 0.01){
        alert('For instant customers, the Paid Amount must be equal to the Grand Total.');
        e.preventDefault();
        return false;
    }

    return true;

});

$(document).on(

    "change",

    ".product",

    function(){

        let row = $(this).closest("tr");
        row.find('.product-variant').val('');
        row.find('.variant-choice-list').empty();
        loadProduct(row);

    }

);

$(document).ready(function(){

    initProductSelect($(document));

    $("table tbody tr").each(function(){

        loadProduct($(this));

    });

    calculateInvoice();
    paidAmountManuallyChanged = false;
    allowAutoPaidSync = true;

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

let editInvoiceVariantSequence = 0;
function renderEditVariantChoices(row, variants, selected){
    const list=row.find('.variant-choice-list').empty();
    const hidden=row.find('.product-variant');
    const group='edit_invoice_variant_'+(++editInvoiceVariantSequence);
    variants.forEach(function(name){
        const id=group+'_'+name.replace(/[^a-z0-9]/gi,'_');
        const label=$('<label>').addClass('variant-choice mb-1').attr('for',id);
        const input=$('<input>',{type:'checkbox',id:id,value:name}).addClass('variant-choice-input').prop('checked',name===selected);
        input.on('change',function(){
            if(this.checked) list.find('.variant-choice-input').not(this).prop('checked',false);
            hidden.val(this.checked ? this.value : '');
            loadProduct(row);
        });
        label.append(input).append($('<span>').text(name));
        list.append(label);
    });
}

function loadProduct(row){

    let product_id = row.find(".product").val();
    let variant_name = row.find('.product-variant').val() || '';

    if(product_id==""){

        row.find(".stock").val("");
        row.find(".price").val("");

        calculateInvoice();

        return;
    }

    $.ajax({

        url:"get_product.php",

        type:"POST",

        data:{
            product_id:product_id,
            variant_name:variant_name,
            exclude_invoice_id:<?= (int)$invoice_id; ?>
        },

        dataType:"json",

        success:function(res){

            if(res.success){

                row.find(".stock")
                   .val(res.product.is_stock_product ? res.product.available_stock : "Unlimited");

                const variants=res.product.variants || [];
                if(variants.length){
                    renderEditVariantChoices(row,variants,variant_name);
                    if(!variant_name){ row.find('.stock').val('Select variant'); }
                }else{
                    row.find('.variant-choice-list').empty();
                    row.find('.product-variant').val('');
                }

                row.find(".price")
                   .val(res.product.sale_price);

                calculateInvoice();

            }

        }

    });

}

</script>

<style>
.variant-choice-list{min-width:150px}.variant-choice{display:flex;align-items:center;gap:7px;padding:6px 8px;border:1px solid #ced4da;border-radius:4px;background:#fff;cursor:pointer;font-weight:400}.variant-choice:hover{border-color:#78a8f8;background:#f4f8ff}.variant-choice-input{width:15px;height:15px;margin:0;accent-color:#0d6efd}.variant-choice-input:checked+span{font-weight:700;color:#0d6efd}
</style>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const staff=document.getElementById('reference_staff_id'), table=document.getElementById('reference_table_id');
    const update=document.getElementById('update-invoice-button');
    const reference=table || staff;
    if(!reference) return;
    function syncReference(){
        if(table && staff){ const selected=table.options[table.selectedIndex]; staff.value=selected&&selected.dataset.staffId?selected.dataset.staffId:''; }
        if(update) update.disabled=!reference.value;
    }
    reference.addEventListener('change',syncReference); syncReference();
    if(window.jQuery && jQuery.fn.select2){ jQuery(reference).select2({theme:'bootstrap4',width:'100%',placeholder:'Search ref.',allowClear:true}).on('change',syncReference); }
});
</script>

