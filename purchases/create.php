<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/product_category_helper.php';

$user_id = $_SESSION['user_id'];
ensure_product_category_type_column($conn);

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
    "SELECT p.id, p.product_name
     FROM products p
     INNER JOIN product_categories c ON c.id=p.category_id
     WHERE p.user_id='$user_id'
     AND p.status='active'
     AND c.category_type='stock_product'
     ORDER BY product_name"
);

// Keep the products created/used through purchasing visible on this screen so
// their current default cost can be maintained without opening Product Management.
$managed_products = mysqli_query(
    $conn,
    "SELECT p.id, p.product_name, p.purchase_price
     FROM products p
     INNER JOIN product_categories c ON c.id=p.category_id
     WHERE p.user_id='$user_id'
     AND p.status='active'
     AND c.category_type='stock_product'
     ORDER BY p.product_name"
);

$wallets = active_wallets_result($conn, $user_id);

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
    $product_options_html .= '<option value="' . (int)$product['id'] . '">' . htmlspecialchars($product['product_name']) . '</option>';
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
        <label>Supplier</label>
        <select name="supplier_id" class="form-control supplier-select" required>
            <option value="">Select Supplier</option>
            <?= $supplier_options_html; ?>
            <option value="__new__">+ Add New Supplier</option>
        </select>
    </div>
</div>

<div id="new_supplier_box" class="border rounded p-3 mt-3" style="display:none; background:#f8fbff;">
    <div class="row">
        <div class="col-md-4">
            <label>Supplier Name</label>
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
<th width="42%">Product</th>
<th width="18%">Price</th>
<th width="14%">Qty</th>
<th width="18%">Total</th>
<th width="10%">Action</th>
</tr>
</thead>
<tbody>
<tr>
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
<td>
    <input type="number" step="0.01" name="cost_price[]" class="form-control cost_price" required>
</td>
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
        <h3 class="card-title">Created Products</h3>
    </div>
    <div class="card-body">
        <div class="table-responsive">
        <table class="table table-bordered table-striped mb-0">
            <thead>
                <tr>
                    <th>Product Name</th>
                    <th width="220">Cost / Price</th>
                    <th width="150">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if($managed_products && mysqli_num_rows($managed_products) > 0){ ?>
                <?php while($managed_product = mysqli_fetch_assoc($managed_products)){ ?>
                    <?php $managed_product_used = product_has_transactions($conn, (int)$managed_product['id'], $user_id); ?>
                    <tr>
                        <td><?= htmlspecialchars($managed_product['product_name']); ?></td>
                        <td>
                            <form method="post" action="update_product_cost.php" class="form-inline">
                                <input type="hidden" name="product_id" value="<?= (int)$managed_product['id']; ?>">
                                <div class="input-group input-group-sm">
                                    <div class="input-group-prepend"><span class="input-group-text">BDT</span></div>
                                    <input type="number" step="0.01" min="0" name="purchase_price" class="form-control" value="<?= number_format((float)$managed_product['purchase_price'], 2, '.', ''); ?>" required>
                                    <div class="input-group-append">
                                        <button type="submit" class="btn btn-primary" title="Save Cost" aria-label="Save Cost"><i class="fas fa-save"></i></button>
                                    </div>
                                </div>
                            </form>
                        </td>
                        <td>
                            <?php if(!$managed_product_used){ ?>
                                <a href="../products/edit.php?id=<?= (int)$managed_product['id']; ?>" class="btn btn-warning btn-sm" title="Edit Product" aria-label="Edit Product"><i class="fas fa-edit"></i></a>
                                <a href="delete_product.php?id=<?= (int)$managed_product['id']; ?>" class="btn btn-danger btn-sm" title="Delete Product" aria-label="Delete Product" onclick="return confirm('Delete this product?');"><i class="fas fa-trash"></i></a>
                            <?php }else{ ?>
                                <button type="button" class="btn btn-danger btn-sm" disabled title="This product is already used and cannot be deleted." aria-label="Delete unavailable"><i class="fas fa-trash"></i></button>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
            <?php }else{ ?>
                <tr><td colspan="3" class="text-center text-muted">No products created yet.</td></tr>
            <?php } ?>
            </tbody>
        </table>
        </div>
        <small class="text-muted d-block mt-2">Used products keep their name and other details locked; only the cost/price can be updated.</small>
    </div>
</div>
</div>
</section>

<?php

$page_script = <<<SCRIPT
<script>
let paidAmountManuallyChanged = false;

$(function(){
    initProductSelect($("#purchaseTable"));
    initCustomerSupplierSelect($(document));
    bindSupplierMode();
    calculateGrandTotal();

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
            calculateRow(row);
            return;
        }

        newBox.hide();
        nameInput.prop("required", false).val("");

        if(id === ""){
            row.find(".cost_price").val("");
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

    $("#paid_amount").on("keyup change", function(){
        paidAmountManuallyChanged = true;
        calculateDue();
    });

    $("#addRow").click(function(){
        let row = $("#purchaseTable tbody tr:first").clone();

        row.find(".select2-container").remove();
        row.find("select").val("");
        row.find("select")
            .removeClass("select2-hidden-accessible")
            .removeAttr("data-select2-id tabindex aria-hidden");
        row.find("option").removeAttr("data-select2-id");

        row.find(".new-product-box").hide();
        row.find(".new-product-name").prop("required", false).val("");
        row.find(".cost_price").val("");
        row.find(".qty").val(1);
        row.find(".line_total").val("");

        $("#purchaseTable tbody").append(row);
        initProductSelect(row);
    });

    $(document).on("click", ".removeRow", function(){
        if($("#purchaseTable tbody tr").length > 1){
            $(this).closest("tr").remove();
        }
        calculateGrandTotal();
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

function initCustomerSupplierSelect(context){
    context.find(".supplier-select").each(function(){
        if($(this).hasClass("select2-hidden-accessible")){
            return;
        }

        $(this).select2({
            theme: "bootstrap4",
            width: "100%",
            placeholder: "Select Supplier",
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
