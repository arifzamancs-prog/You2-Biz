<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = $_SESSION['user_id'];

$purchase_id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;

if($purchase_id <= 0){

    die("Invalid Purchase");

}
$sql = "SELECT *
        FROM purchases
        WHERE id=?
        AND user_id=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $purchase_id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$purchase = mysqli_fetch_assoc($result);

if(!$purchase){

    die("Purchase Not Found");

}
$suppliers = mysqli_query(

    $conn,

    "SELECT id,supplier_name

     FROM suppliers

     WHERE user_id='$user_id'

     ORDER BY supplier_name"

);
$products = mysqli_query(

    $conn,

    "SELECT id,
            product_name

     FROM products

     WHERE user_id='$user_id'

     ORDER BY product_name"

);

/* Wallets */

$wallets = active_wallets_result($conn, $user_id);

$sql = "SELECT *

        FROM purchase_items

        WHERE purchase_id=?";

$stmt = mysqli_prepare(
    $conn,
    $sql
);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $purchase_id
);

mysqli_stmt_execute($stmt);

$items =
    mysqli_stmt_get_result($stmt);
?>

<div class="card">

<div class="card-header">

<h3 class="card-title">

Edit Purchase

</h3>

</div>

<div class="card-body">

<?php if(isset($_SESSION['error'])){ ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($_SESSION['error']); ?>
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php } ?>

<form
method="post"
action="update.php">

<input
type="hidden"
name="purchase_id"
value="<?= $purchase_id; ?>">
<div class="row">

<div class="col-md-4">

<label>Supplier</label>

<select
name="supplier_id"
class="form-control supplier-select"
required>

<?php
while($supplier = mysqli_fetch_assoc($suppliers)){
?>

<option
value="<?= $supplier['id']; ?>"

<?= ($purchase['supplier_id']==$supplier['id'])
? 'selected'
: ''; ?>>

<?= htmlspecialchars($supplier['supplier_name']); ?>

</option>

<?php } ?>

</select>

</div>
<div class="col-md-4">

<label>Purchase Date</label>

<input
type="date"
name="purchase_date"
class="form-control"
value="<?= $purchase['purchase_date']; ?>">

</div>

</div>

<hr>
<table
class="table table-bordered"
id="purchaseTable">

<thead>

<tr>

<th>Product</th>
<th>Cost Price</th>
<th>Qty</th>
<th>Total</th>
<th>Action</th>

</tr>

</thead>

<tbody>
<?php
while($item = mysqli_fetch_assoc($items)){
?>

<tr>

<td>

<select
name="product_id[]"
class="form-control product"
required>

<option value="">
Select Product
</option>

<?php

mysqli_data_seek($products,0);

while($product = mysqli_fetch_assoc($products)){

?>

<option
value="<?= $product['id']; ?>"

<?= ($product['id']==$item['product_id'])
? 'selected'
: ''; ?>>

<?= htmlspecialchars($product['product_name']); ?>

</option>

<?php } ?>

</select>

</td>

<td>

<input
type="number"
step="0.01"
name="cost_price[]"
value="<?= $item['unit_cost']; ?>"
class="form-control cost_price"
required>

</td>

<td>

<input
type="number"
step="1"
min="1"
name="qty[]"
value="<?= $item['quantity']; ?>"
class="form-control qty"
required>

</td>

<td>

<input
type="number"
step="0.01"
name="line_total[]"
value="<?= $item['total_cost']; ?>"
class="form-control line_total"
readonly>

</td>

<td>

<button
type="button"
class="btn btn-danger removeRow">

Remove

</button>

</td>

</tr>

<?php } ?>

</tbody>

</table>
<button
type="button"
id="addRow"
class="btn btn-success">

Add Product

</button>

<hr>
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
value="<?= $purchase['total_amount']; ?>"
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
id="paid_amount"
name="paid_amount"
value="<?= $purchase['paid_amount']; ?>"
class="form-control">

<input
type="hidden"
id="payment_status"
name="payment_status"
value="<?= $purchase['payment_status']; ?>">

<div class="mt-2">

<label>

Pay From Wallet

</label>

<select
    name="payment_wallet_id"
    id="payment_wallet_id"
    class="form-control">

<?php

mysqli_data_seek($wallets,0);

while($wallet=mysqli_fetch_assoc($wallets)){

?>

<option
value="<?= $wallet['id']; ?>"

<?= $wallet['id']==$purchase['payment_wallet_id'] ? 'selected' : ''; ?>>

<?= htmlspecialchars($wallet['wallet_name']); ?>

</option>

<?php } ?>

</select>

</div>

</div>

<div class="col-md-4">

<label>
Due Amount
</label>

<input
type="number"
step="0.01"
id="due_amount"
name="due_amount"
value="<?= $purchase['due_amount']; ?>"
class="form-control"
readonly>

</div>

</div>

<br>

<div class="form-group">

<label>
Notes
</label>

<textarea
name="notes"
class="form-control"><?= htmlspecialchars($purchase['notes']); ?></textarea>

</div>

<button
type="submit"
class="btn btn-primary">

Update Purchase

</button>

</form>

</div>

</div>

<?php

$page_script = <<<SCRIPT
<script>

$(function(){

    initProductSelect($("#purchaseTable"));
    initCustomerSupplierSelect($(document));

    calculateGrandTotal();

    $(document).on("change", ".product", function(){
        let row = $(this).closest("tr");
        let id = $(this).val();

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
                row.find(".cost_price").val(parseFloat(res.cost_price || 0).toFixed(2));
                calculateRow(row);
            }
        });
    });

    $(document).on("keyup change", ".cost_price,.qty", function(){
        calculateRow($(this).closest("tr"));
    });

    $("#paid_amount").on("keyup change", function(){
        calculateDue();
    });

    $("#addRow").click(function(){
        let row = $("#purchaseTable tbody tr:first").clone();

        row.find(".select2-container").remove();

        row.find("select")
            .val("")
            .removeClass("select2-hidden-accessible")
            .removeAttr("data-select2-id tabindex aria-hidden");

        row.find("option").removeAttr("data-select2-id");

        row.find(".cost_price").val("");
        row.find(".qty").val(1);
        row.find(".line_total").val("");

        $("#purchaseTable tbody").append(row);
        initProductSelect(row);
        calculateGrandTotal();
    });

    $(document).on("click", ".removeRow", function(){
        if($("#purchaseTable tbody tr").length > 1){
            $(this).closest("tr").remove();
            calculateGrandTotal();
        }
    });

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
    calculateDue();
}

function calculateDue(){
    let grand = parseFloat($("#grand_total").val()) || 0;
    let paid = parseFloat($("#paid_amount").val()) || 0;
    let due = grand - paid;

    if(due < 0){
        due = 0;
    }

    $("#due_amount").val(due.toFixed(2));

    let status = "due";

    if(grand > 0 && due <= 0){
        status = "paid";
    }else if(paid > 0){
        status = "partial";
    }

    $("#payment_status").val(status);

    $("#payment_wallet_id").prop("required", paid > 0);
}

</script>
SCRIPT;

require_once '../includes/footer.php';
?>
