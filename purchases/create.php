<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/product_category_helper.php';

$user_id = $_SESSION['user_id'];
$housing_purchase = ($_SESSION['company_type'] ?? '') === 'Housing';
if(!$housing_purchase) ensure_fifo_only_product_categories($conn, $user_id);

$suppliers = mysqli_query(
    $conn,
    "SELECT id, supplier_name, phone
     FROM suppliers
     WHERE user_id='$user_id'
     AND status='active'
     ORDER BY supplier_name"
);

$products = mysqli_query(
    $conn,
    "SELECT p.id, p.product_name, p.sku
     FROM products p
     INNER JOIN product_categories c ON c.id=p.category_id
     WHERE p.user_id='$user_id'
     AND p.status='active'
     ORDER BY product_name"
);

$purchase_history = mysqli_query(
    $conn,
    "SELECT
        pu.id,
        pu.purchase_no,
        pu.purchase_date,
        pu.total_amount,
        pu.paid_amount,
        pu.due_amount,
        pu.payment_status,
        s.supplier_name,
        GROUP_CONCAT(
            CONCAT(
                p.product_name,
                CASE
                    WHEN COALESCE(pi.variant_name, '') <> '' THEN CONCAT(' (', pi.variant_name, ')')
                    ELSE ''
                END,
                ' x ', pi.quantity
            )
            ORDER BY p.product_name, pi.variant_name
            SEPARATOR ' || '
        ) AS products
     FROM purchases pu
     LEFT JOIN suppliers s ON s.id=pu.supplier_id
     LEFT JOIN purchase_items pi ON pi.purchase_id=pu.id
     LEFT JOIN products p ON p.id=pi.product_id
     WHERE pu.user_id='$user_id'
     GROUP BY pu.id, pu.purchase_no, pu.purchase_date, pu.total_amount, pu.paid_amount, pu.due_amount, pu.payment_status, s.supplier_name
     ORDER BY pu.purchase_date DESC, pu.id DESC
     LIMIT 100"
);

$wallets = active_wallets_result($conn, $user_id);

$history_items_stmt = mysqli_prepare($conn, "SELECT pi.product_id, p.product_name, pi.variant_name, SUM(pi.quantity) AS quantity
    FROM purchase_items pi
    INNER JOIN purchases pu ON pu.id=pi.purchase_id AND pu.user_id=?
    LEFT JOIN products p ON p.id=pi.product_id AND p.user_id=pu.user_id
    WHERE pi.purchase_id=?
    GROUP BY pi.product_id,p.product_name,pi.variant_name ORDER BY pi.product_id");

$supplier_options_html = '';
while($supplier = mysqli_fetch_assoc($suppliers)){
    $label = $supplier['supplier_name'];
    if(!empty($supplier['phone'])){
        $label .= ' (Ph. ' . $supplier['phone'] . ')';
    }

    $supplier_options_html .= '<option value="' . (int)$supplier['id'] . '">' . htmlspecialchars($label) . '</option>';
}

$product_options_html = '';
while($product = mysqli_fetch_assoc($products)){
    $label = product_option_label($product['product_name'], $product['sku'] ?? '', !$housing_purchase);
    $product_options_html .= '<option value="' . (int)$product['id'] . '">' . htmlspecialchars($label) . '</option>';
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<section class="content">
<div class="container-fluid">
<div class="card">
<div class="card-header">
<h3 class="card-title">Create Purchase</h3>
</div>
<div class="card-body">

<?php if(isset($_SESSION['error'])){ ?>
    <div class="alert alert-danger">
        <?= htmlspecialchars($_SESSION['error']); ?>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php } ?>

<?php if(isset($_SESSION['success'])){ ?>
    <div class="alert alert-success">
        <?= htmlspecialchars($_SESSION['success']); ?>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php } ?>

<form method="post" action="save_purchase.php">

<div class="row">
    <div class="col-md-12">
        <label><?= supplier_display_text('Supplier'); ?></label>
        <select name="supplier_id" class="form-control supplier-select" required>
            <option value="">Select <?= supplier_display_text('Supplier'); ?></option>
            <?= $supplier_options_html; ?>
            <option value="__new__">+ Add New <?= supplier_display_text('Supplier'); ?></option>
        </select>
    </div>
</div>

<div id="new_supplier_box" class="border rounded p-3 mt-3" style="display:none; background:#f8fbff;">
    <div class="row">
        <div class="col-md-4">
            <label><?= supplier_display_text('Supplier'); ?> Name</label>
            <input type="text" name="new_supplier_name" class="form-control" placeholder="Enter supplier name">
        </div>
        <div class="col-md-4">
            <label>Mobile</label>
            <input type="text" name="new_supplier_phone" class="form-control" placeholder="Enter mobile number">
        </div>
        <div class="col-md-4">
            <label>Address</label>
            <input type="text" name="new_supplier_address" class="form-control" placeholder="Enter address">
        </div>
    </div>
</div>

<hr>

<table class="table table-bordered" id="purchaseTable">
<thead>
<tr>
<th width="22%">Product</th>
<th width="13%" class="variant-column">Variant</th>
<?php if(!$housing_purchase){ ?><th width="12%">Stock</th><?php } ?>
<th width="17%"><?= $housing_purchase ? 'Price' : 'Purchase Price'; ?></th>
<?php if(!$housing_purchase){ ?><th width="17%">Sale Price</th><?php } ?>
<th width="11%">Qty</th>
<th width="15%">Total</th>
<th width="6%">Action</th>
</tr>
</thead>
<tbody>
<tr data-line-index="0">
<td>
    <select name="product_id[]" class="form-control product">
        <option value="">Select Product</option>
        <?= $product_options_html; ?>
        <option value="__new__">+ Add New Product</option>
    </select>

    <div class="new-product-box mt-2" style="display:none;">
        <input type="text" name="new_product_name[]" class="form-control new-product-name" placeholder="Enter product name">
    </div>
</td>
<td class="variant-column"><div class="product-variant-quantities text-muted small">No variant</div></td>
<?php if(!$housing_purchase){ ?><td>
    <input type="text" class="form-control product_stock" readonly>
</td><?php } ?>
<td>
    <input type="number" step="0.01" name="cost_price[]" class="form-control cost_price" required>
</td>
<?php if(!$housing_purchase){ ?><td>
    <input type="number" step="0.01" min="0" name="sale_price[]" class="form-control sale_price" value="0" required>
</td><?php } ?>
<td>
    <input type="number" step="1" min="1" name="qty[]" class="form-control qty" value="1" required>
</td>
<td>
    <input type="text" name="line_total[]" class="form-control line_total" readonly>
</td>
<td>
    <button type="button" class="btn btn-danger removeRow">
        <i class="fas fa-times"></i>
    </button>
</td>
</tr>
</tbody>
</table>

<button type="button" id="addRow" class="btn btn-success">
    <i class="fas fa-plus"></i>
    Add Product
</button>

<hr>

<div class="row">
    <div class="col-md-4">
        <label>Grand Total</label>
        <input type="text" id="grand_total" name="grand_total" class="form-control" readonly>
    </div>

    <div class="col-md-4">
        <label>Paid Amount</label>
        <input type="number" step="0.01" id="paid_amount" name="paid_amount" class="form-control" value="0">
        <input type="hidden" id="payment_status" name="payment_status">

        <div class="mt-2">
            <label>Pay From Wallet</label>
            <select name="payment_wallet_id" id="payment_wallet_id" class="form-control">
                <?php
                mysqli_data_seek($wallets, 0);
                while($wallet = mysqli_fetch_assoc($wallets)){
                ?>
                <option
                    value="<?= $wallet['id']; ?>"
                    data-balance="<?= number_format((float)$wallet['balance'], 2, '.', ''); ?>"
                    <?= $wallet['is_system'] == 1 ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($wallet['wallet_name']); ?>
                </option>
                <?php } ?>
            </select>
        </div>

        <div class="mt-2" id="payment_wallet_balance_group" style="display:none;">
            <div id="payment_wallet_balance" class="small font-weight-bold text-muted">
                BDT 0.00
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <label>Due Amount</label>
        <input type="text" id="due_amount" name="due_amount" class="form-control" readonly>
    </div>
</div>

<div class="form-group mt-3">
    <label>Notes</label>
    <textarea name="notes" class="form-control" rows="3"></textarea>
</div>

<button type="submit" class="btn btn-primary">Save Purchase</button>

</form>

</div>
</div>
</div>
</section>

<section class="content">
<div class="container-fluid">
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Purchase History</h3>
    </div>
    <div class="card-body">
        <div class="table-responsive">
        <table class="table table-bordered table-striped mb-0">
            <thead>
                <tr>
                    <th>Purchase No.</th>
                    <th>Date</th>
                    <th>Supplier</th>
                    <th>Products</th>
                    <th>Total</th>
                    <th>Paid</th>
                    <th>Due</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if($purchase_history && mysqli_num_rows($purchase_history) > 0){ ?>
                <?php while($purchase = mysqli_fetch_assoc($purchase_history)){ ?>
                    <tr>
                        <td><?= htmlspecialchars($purchase['purchase_no']); ?></td>
                        <td><?= htmlspecialchars(date('d-m-Y', strtotime($purchase['purchase_date']))); ?></td>
                        <td><?= htmlspecialchars($purchase['supplier_name'] ?: '-'); ?></td>
                        <td>
                        <?php
                        mysqli_stmt_bind_param($history_items_stmt, 'ii', $user_id, $purchase['id']);
                        mysqli_stmt_execute($history_items_stmt);
                        $history_items = mysqli_stmt_get_result($history_items_stmt);
                        $history_products = [];
                        while($item = mysqli_fetch_assoc($history_items)){
                            $history_products[$item['product_id']]['name'] = $item['product_name'] ?? 'Missing Product';
                            $history_products[$item['product_id']]['quantities'][$item['variant_name']] = $item['quantity'];
                        }
                        foreach($history_products as $product_id => $history_product){
                            $quantities = $history_product['quantities'];
                            $order = array_unique(array_merge(product_variant_names($conn, (int)$product_id, $user_id), array_keys($quantities)));
                            $parts = [];
                            foreach($order as $variant_name){
                                if($variant_name !== '' && array_key_exists($variant_name, $quantities)){
                                    $parts[] = $variant_name . ': ' . number_format($quantities[$variant_name], 0);
                                }
                            }
                            ?>
                            <div><?= htmlspecialchars($history_product['name']); ?><?= isset($quantities['']) ? ' x ' . number_format($quantities[''], 0) : ''; ?>
                                <?php if($parts){ ?><small class="d-block text-muted"><?= htmlspecialchars(implode(' || ', $parts)); ?></small><?php } ?>
                            </div>
                        <?php } ?>
                        </td>
                        <td>BDT <?= number_format((float)$purchase['total_amount'], 2); ?></td>
                        <td>BDT <?= number_format((float)$purchase['paid_amount'], 2); ?></td>
                        <td>BDT <?= number_format((float)$purchase['due_amount'], 2); ?></td>
                        <td><span class="badge badge-<?= $purchase['payment_status'] === 'paid' ? 'success' : ($purchase['payment_status'] === 'partial' ? 'warning' : 'danger'); ?>"><?= htmlspecialchars(ucfirst($purchase['payment_status'])); ?></span></td>
                    </tr>
                <?php } ?>
            <?php }else{ ?>
                <tr><td colspan="8" class="text-center text-muted">No purchase history found.</td></tr>
            <?php } ?>
            </tbody>
        </table>
        </div>
        <small class="text-muted d-block mt-2">Latest 100 purchase records.</small>
    </div>
</div>
</div>
</section>

<?php

$supplier_display_label = supplier_display_text('Supplier'); $page_script = <<<SCRIPT
<script>
let paidAmountManuallyChanged = false;

$(function(){
    initProductSelect($("#purchaseTable"));
    initCustomerSupplierSelect($(document));
    bindSupplierMode();
    calculateGrandTotal();
    updateVariantColumnVisibility();

    function updatePaymentWalletBalance(){
        let selected = $("#payment_wallet_id option:selected");
        let walletId = selected.val();
        let balance = parseFloat(selected.data("balance")) || 0;

        if(walletId){
            $("#payment_wallet_balance").text("Present Balance: BDT " + balance.toFixed(2));
            $("#payment_wallet_balance_group").show();
        }else{
            $("#payment_wallet_balance").text("BDT 0.00");
            $("#payment_wallet_balance_group").hide();
        }
    }

    $("#payment_wallet_id").on("change", updatePaymentWalletBalance);
    updatePaymentWalletBalance();

    $('form[action="save_purchase.php"]').on("submit", function(e){
        let paidAmount = parseFloat($("#paid_amount").val()) || 0;
        let walletBalance = parseFloat($("#payment_wallet_id option:selected").data("balance")) || 0;

        if(paidAmount > walletBalance){
            alert("Paid amount exceeds wallet balance.");
            e.preventDefault();
            return false;
        }

        return true;
    });

    $(document).on("change", ".supplier-select", function(){
        bindSupplierMode();
    });

    $(document).on("change", ".product", function(){
        let row = $(this).closest("tr");
        let id = $(this).val();
        let newBox = row.find(".new-product-box");
        let nameInput = row.find(".new-product-name");

        if(id === "__new__"){
            newBox.show();
            nameInput.prop("required", true);
            row.find(".cost_price").val("0");
            row.find(".sale_price").val("0");
            row.find(".product_stock").val(0);
            row.find(".product-variant-quantities").text("No variant");
            row.find(".qty").prop("readonly", false).val(1);
            row.data("has-variants", false); updateVariantColumnVisibility();
            calculateRow(row);
            return;
        }

        newBox.hide();
        nameInput.prop("required", false).val("");

        if(id === ""){
            row.find(".cost_price").val("");
            row.find(".sale_price").val("");
            row.find(".product_stock").val("");
            row.find(".product-variant-quantities").text("No variant");
            row.find(".qty").prop("readonly", false).val(1);
            row.data("has-variants", false); updateVariantColumnVisibility();
            row.find(".line_total").val("");
            calculateGrandTotal();
            return;
        }

        $.ajax({
            url: "get_product.php",
            type: "POST",
            data: {product_id: id},
            dataType: "json",
            success: function(res){
                row.find(".cost_price").val(res.cost_price);
                row.find(".sale_price").val(res.sale_price);
                row.find(".product_stock").val(res.stock);
                let variant = row.find(".product-variant-quantities");
                variant.empty();
                if((res.variants || []).length){
                    row.data("has-variants", true);
                    row.find(".qty").val(0).prop("readonly", true);
                    const lineIndex=row.data("line-index");
                    res.variants.forEach(function(name){
                        const group=$("<div>").addClass("input-group input-group-sm mb-1");
                        group.append($("<div>").addClass("input-group-prepend").append($("<span>").addClass("input-group-text").text(name)));
                        const input=$("<input>",{type:"number",min:0,step:1,value:0}).addClass("form-control variant-qty").attr("name","variant_quantity["+lineIndex+"]["+name+"]");
                        group.append(input); variant.append(group);
                    });
                }else{
                    variant.text("No variant"); row.find(".qty").val(1).prop("readonly", false); row.data("has-variants", false);
                }
                updateVariantColumnVisibility();
                calculateRow(row);
            },
            error: function(xhr){
                console.log(xhr.responseText);
            }
        });
    });

    $(document).on("keyup change", ".qty,.cost_price", function(){
        calculateRow($(this).closest("tr"));
    });

    $(document).on("input change", ".variant-qty", function(){
        let row=$(this).closest("tr"), total=0;
        row.find(".variant-qty").each(function(){ total+=Math.max(0,parseInt($(this).val(),10)||0); });
        row.find(".qty").val(total);
        calculateRow(row);
    });

    $("#paid_amount").on("keyup change", function(){
        paidAmountManuallyChanged = true;
        calculateDue();
    });

    $("#addRow").click(function(){
        let row = $("#purchaseTable tbody tr:first").clone();
        row.data("line-index", $("#purchaseTable tbody tr").length);

        row.find(".select2-container").remove();
        row.find("select").val("");
        row.find("select")
            .removeClass("select2-hidden-accessible")
            .removeAttr("data-select2-id tabindex aria-hidden");
        row.find("option").removeAttr("data-select2-id");

        row.find(".new-product-box").hide();
        row.find(".new-product-name").prop("required", false).val("");
        row.find(".cost_price").val("");
        row.find(".sale_price").val("");
        row.find(".product_stock").val("");
        row.find(".product-variant-quantities").text("No variant");
        row.data("has-variants", false);
        row.find(".qty").val(1).prop("readonly", false);
        row.find(".line_total").val("");

        $("#purchaseTable tbody").append(row);
        initProductSelect(row);
    });

    $(document).on("click", ".removeRow", function(){
        if($("#purchaseTable tbody tr").length > 1){
            $(this).closest("tr").remove();
        }
        calculateGrandTotal();
        updateVariantColumnVisibility();
    });
});

function bindSupplierMode(){
    let isNewSupplier = $(".supplier-select").val() === "__new__";

    $("#new_supplier_box").toggle(isNewSupplier);
    $("input[name='new_supplier_name']").prop("required", isNewSupplier);
    $("input[name='new_supplier_phone']").prop("required", isNewSupplier);
}

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

function updateVariantColumnVisibility(){
    let visible=false;
    $("#purchaseTable tbody tr").each(function(){ if($(this).data("has-variants")){ visible=true; } });
    $("#purchaseTable .variant-column").toggle(visible);
}

function initCustomerSupplierSelect(context){
    context.find(".supplier-select").each(function(){
        if($(this).hasClass("select2-hidden-accessible")){
            return;
        }

        $(this).select2({
            theme: "bootstrap4",
            width: "100%",
            placeholder: "Select {$supplier_display_label}",
            allowClear: true
        });
    });
}

function calculateRow(row){
    let price = parseFloat(row.find(".cost_price").val()) || 0;
    let qty = parseFloat(row.find(".qty").val()) || 0;
    let total = price * qty;

    row.find(".line_total").val(total.toFixed(2));
    calculateGrandTotal();
}

function calculateGrandTotal(){
    let grand = 0;

    $(".line_total").each(function(){
        grand += parseFloat($(this).val()) || 0;
    });

    $("#grand_total").val(grand.toFixed(2));

    if(!paidAmountManuallyChanged){
        $("#paid_amount").val(grand.toFixed(2));
    }

    calculateDue();
}

function calculateDue(){
    let grand = parseFloat($("#grand_total").val()) || 0;
    let paid = parseFloat($("#paid_amount").val()) || 0;
    let due = grand - paid;

    $("#due_amount").val(due.toFixed(2));

    let status = "due";
    if(due <= 0){
        status = "paid";
    }else if(paid > 0){
        status = "partial";
    }

    $("#payment_status").val(status);

    if(paid > 0){
        $("#payment_wallet_id").prop("required", true);
    }else{
        $("#payment_wallet_id").prop("required", false);
    }
}
</script>
SCRIPT;

require_once "../includes/footer.php";

?>
