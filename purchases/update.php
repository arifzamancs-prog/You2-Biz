<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/transaction_helper.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/wallet_split_helper.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/expense_helper.php';
require_once '../includes/product_category_helper.php';

$user_id = $_SESSION['user_id'];
ensure_fifo_inventory_tables($conn);
ensure_expense_support_tables($conn, (int)$user_id);

if($_SERVER['REQUEST_METHOD'] != 'POST'){

    header("Location:index.php");
    exit;

}

$purchase_id = (int)($_POST['purchase_id'] ?? 0);

// Inventory & Sales: warranty and product serials (schema changes run before the transaction).
require_once '../includes/service_warranty_helper.php';
$purchase_serial_enabled = product_serial_schema($conn);
if($purchase_serial_enabled) product_warranty_schema($conn);

// CREATE TABLE must not run inside the transaction.
ensure_wallet_splits_table($conn);

mysqli_begin_transaction($conn);

try{

    // Expand the grouped editor into individual variant lines for stock storage.
    if(isset($_POST['variant_quantity'])){
        $expanded = ['product_id'=>[], 'qty'=>[], 'cost_price'=>[], 'variant_name'=>[], 'line_serials'=>[], 'line_warranty'=>[]];
        foreach(($_POST['product_id'] ?? []) as $index=>$pid){
            $quantities = $_POST['variant_quantity'][$index] ?? [''=>($_POST['qty'][$index] ?? 0)];
            if(!is_array($quantities)) throw new Exception('Invalid variant quantities.');
            foreach($quantities as $variant=>$quantity){
                if(!is_numeric($quantity) || (float)$quantity < 0 || floor((float)$quantity) != (float)$quantity) throw new Exception('Invalid variant quantity.');
                if((float)$quantity == 0) continue;
                $expanded['product_id'][] = $pid;
                $expanded['qty'][] = $quantity;
                $expanded['cost_price'][] = $_POST['cost_price'][$index] ?? 0;
                $expanded['variant_name'][] = (string)$variant;
                $expanded['line_serials'][] = $_POST['line_serials'][$index] ?? '';
                $expanded['line_warranty'][] = $_POST['line_warranty'][$index] ?? '';
            }
        }
        if(!$expanded['product_id']) throw new Exception('Enter at least one positive quantity.');
        foreach($expanded as $field=>$values) $_POST[$field] = $values;
    }

    $supplier_id = (int)$_POST['supplier_id'];

    $purchase_date = $_POST['purchase_date'];

    $supplier_check = mysqli_prepare($conn, 'SELECT id FROM suppliers WHERE id=? AND user_id=?');
    mysqli_stmt_bind_param($supplier_check, 'ii', $supplier_id, $user_id);
    mysqli_stmt_execute($supplier_check);
    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($supplier_check))) throw new Exception(supplier_display_text('Supplier not found.'));
    $_POST['line_total'] = stock_line_totals($_POST['product_id'] ?? [], $_POST['qty'] ?? [], $_POST['cost_price'] ?? [], false);
    $grand_total = array_sum($_POST['line_total']);
    foreach (array_keys($_POST['line_total']) as $key) {
        $product_id = (int)$_POST['product_id'][$key];
        $category_condition = ($_SESSION['company_type'] ?? '') === 'Housing' ? '' : " AND c.category_type='stock_product'";
        $product_check = mysqli_prepare($conn, "SELECT p.id FROM products p JOIN product_categories c ON c.id=p.category_id AND c.user_id=p.user_id WHERE p.id=? AND p.user_id=?{$category_condition} FOR UPDATE");
        mysqli_stmt_bind_param($product_check, 'ii', $product_id, $user_id);
        mysqli_stmt_execute($product_check);
        if (!mysqli_fetch_assoc(mysqli_stmt_get_result($product_check))) throw new Exception('Stock product not found.');
    }

    $paid_amount = (float)$_POST['paid_amount'];
    
    $payment_wallet_id =
    (int)($_POST['payment_wallet_id'] ?? 0);

    $due_amount = $grand_total - $paid_amount;

    $payment_status = $due_amount <= 0 ? 'paid' : ($paid_amount > 0 ? 'partial' : 'due');
    $payment_multi_wallet = is_multi_wallet_value($_POST['payment_wallet_id'] ?? '');
    if ($paid_amount > 0 && !$payment_multi_wallet) stock_require_wallet($conn, $payment_wallet_id, $user_id, stock_customer_branch($conn));

    $notes = trim($_POST['notes']);

    $sql = "SELECT
            purchase_no,
            paid_amount,
            payment_wallet_id
        FROM purchases
        WHERE id=?
        AND user_id=? FOR UPDATE";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $purchase_id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$old_purchase = mysqli_fetch_assoc($result);

if(!$old_purchase){

    throw new Exception("Purchase Not Found");

}

if(($_SESSION['company_type'] ?? '') !== 'Housing' && !fifo_inventory_purchase_is_editable($conn, $purchase_id)){
    throw new Exception("This purchase already affected FIFO stock usage. Edit is not allowed.");
}

$settlements = mysqli_prepare($conn, "SELECT id FROM supplier_payments WHERE purchase_id=? AND user_id=? FOR UPDATE");
mysqli_stmt_bind_param($settlements, "ii", $purchase_id, $user_id);
mysqli_stmt_execute($settlements);
if(mysqli_num_rows(mysqli_stmt_get_result($settlements)) > 0){
    throw new Exception("This purchase has separate supplier payments and cannot be edited.");
}

$old_paid_amount =
    (float)$old_purchase['paid_amount'];

$old_wallet_id =
    (int)$old_purchase['payment_wallet_id'];

$purchase_no =
    $old_purchase['purchase_no'];

if(!is_finite($paid_amount) || $paid_amount < 0){

    throw new Exception("Invalid Paid Amount");

}

if($paid_amount > $grand_total){

    throw new Exception("Paid Amount cannot exceed Grand Total");

}

// Multi-wallet before or after the edit: each wallet needs its new part, plus what it gets back.
$old_payment_splits = wallet_splits_get($conn, $user_id, 'purchase', $purchase_id);
$payment_splits = ($paid_amount > 0 && $payment_multi_wallet)
    ? multi_wallet_post_splits($conn, $user_id, $paid_amount, false, stock_customer_branch($conn))
    : [];
$old_wallet_map = wallet_splits_or_single($old_payment_splits, $old_wallet_id, $old_paid_amount);
$new_wallet_map = wallet_splits_or_single($payment_splits, $payment_wallet_id, $paid_amount);

if($old_payment_splits || $payment_splits){
    $need = [];

    foreach($new_wallet_map as $wallet_id => $amount){
        $extra = round($amount - ($old_wallet_map[$wallet_id] ?? 0), 2);

        if($extra > 0.009){
            $need[$wallet_id] = $extra;
        }
    }

    $short_wallet = wallet_splits_shortfall($conn, $user_id, $need);

    if($short_wallet !== ''){
        throw new Exception("Insufficient Wallet Balance: " . $short_wallet);
    }

    if($payment_splits){
        wallet_splits_save($conn, $user_id, 'purchase', $purchase_id, $payment_splits);
    }else{
        wallet_splits_delete($conn, $user_id, 'purchase', $purchase_id);
    }
}elseif($paid_amount > 0){

    $sql = "SELECT balance
            FROM wallets
            WHERE id=?
            AND user_id=?
            AND status='active'";

    $stmt = mysqli_prepare($conn,$sql);

    mysqli_stmt_bind_param(
        $stmt,
        "ii",
        $payment_wallet_id,
        $user_id
    );

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    $wallet = mysqli_fetch_assoc($result);

    if(!$wallet){

        throw new Exception("Wallet Not Found");

    }

    $available_balance = (float)$wallet['balance'];

    if($old_wallet_id == $payment_wallet_id){

        $available_balance += $old_paid_amount;

    }

    if($paid_amount > $available_balance){

        throw new Exception("Insufficient Wallet Balance");

    }

}


    $sql = "SELECT
            product_id,
            quantity
        FROM purchase_items
        WHERE purchase_id=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $purchase_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

while($row=mysqli_fetch_assoc($result)){
    if(($_SESSION['company_type'] ?? '') === 'Housing') continue;

    $sql="UPDATE products

          SET current_stock=
          current_stock-?

          WHERE id=?
          AND user_id=?";

    $stmt=mysqli_prepare($conn,$sql);

    mysqli_stmt_bind_param(

        $stmt,

        "dii",

        $row['quantity'],
        $row['product_id'],
        $user_id

    );

    mysqli_stmt_execute($stmt);

}
mysqli_query(

    $conn,

    "DELETE
     FROM purchase_items
     WHERE purchase_id='$purchase_id'"

);

// The serials of this purchase are entered again with its lines (not possible once one is sold or sent).
if($purchase_serial_enabled){
    try{ product_serial_remove_source($conn, $user_id, 'purchase', $purchase_id); }
    catch(RuntimeException $e){ throw new Exception($e->getMessage()); }
}
if(($_SESSION['company_type'] ?? '') !== 'Housing' && !fifo_inventory_remove_purchase_batches($conn, $purchase_id)){
    throw new Exception("Old FIFO batches could not be removed.");
}
$sql = "UPDATE purchases SET

            supplier_id=?,
            payment_wallet_id=?,
            purchase_date=?,
            total_amount=?,
            paid_amount=?,
            due_amount=?,
            payment_status=?,
            notes=?

        WHERE id=?
        AND user_id=?";

$stmt = mysqli_prepare(
    $conn,
    $sql
);

mysqli_stmt_bind_param(

    $stmt,

    "iisdddssii",

    $supplier_id,
    $payment_wallet_id,
    $purchase_date,
    $grand_total,
    $paid_amount,
    $due_amount,
    $payment_status,
    $notes,
    $purchase_id,
    $user_id

);

mysqli_stmt_execute($stmt);
$product_ids = $_POST['product_id'];

$qtys = $_POST['qty'];

$prices = $_POST['cost_price'];

$totals = $_POST['line_total'];
$variant_names = $_POST['variant_name'] ?? [];

foreach($product_ids as $key=>$product_id){

    if(empty($product_id)){
        continue;
    }

    $qty = (int)$qtys[$key];

    $price = (float)$prices[$key];
    if(!is_finite($price) || $price <= 0){
        throw new Exception("Purchase price is required for every product.");
    }

    $total = (float)$totals[$key];
    $variant_name = trim((string)($variant_names[$key] ?? ''));
    if(!product_variant_is_valid($conn, (int)$product_id, $user_id, $variant_name)){
        throw new Exception('Please select a valid variant for the product.');
    }

    $sql = "INSERT INTO purchase_items(

                purchase_id,
                product_id,
                variant_name,
                quantity,
                unit_cost,
                total_cost

            )

            VALUES(

                ?,?,?,?,?,?

            )";

    $stmt = mysqli_prepare(
        $conn,
        $sql
    );

    mysqli_stmt_bind_param(

        $stmt,

        "iisidd",

        $purchase_id,
        $product_id,
        $variant_name,
        $qty,
        $price,
        $total

    );

    mysqli_stmt_execute($stmt);
    $purchase_item_id = (int)mysqli_insert_id($conn);

    if($purchase_serial_enabled && product_serial_is_enabled($conn, $user_id, (int)$product_id)){
        // Warranty given here becomes the product's warranty for later sales.
        $line_warranty = trim((string)($_POST['line_warranty'][$key] ?? ''));
        if($line_warranty !== ''){
            $line_warranty = max(0, min(600, (int)$line_warranty));
            mysqli_query($conn, 'UPDATE purchase_items SET warranty_months=' . $line_warranty . ' WHERE id=' . $purchase_item_id);
            product_warranty_save($conn, $user_id, (int)$product_id, $line_warranty);
        }
        // One serial for each piece received.
        try{
            $line_serial_groups = product_serial_parse_groups($_POST['line_serials'][$key] ?? '');
            $line_serial_list = $line_serial_groups[$variant_name] ?? [];
            product_serial_check_counts([$variant_name => $line_serial_list], [$variant_name => (int)$qty], 'Serials');
            product_serial_add($conn, $user_id, (int)$product_id, $variant_name, $line_serial_list, stock_purchase_location_id($conn, (int)$user_id), 'purchase', $purchase_id);
        }catch(RuntimeException $e){ throw new Exception($e->getMessage()); }
    }

    if(($_SESSION['company_type'] ?? '') === 'Housing') continue;
    if(!fifo_inventory_create_batch(
        $conn,
        $user_id,
        (int)$product_id,
        $qty,
        $price,
        'purchase',
        $purchase_id,
        $purchase_no,
        $purchase_date,
        null,
        $variant_name
    )){
        throw new Exception("FIFO batch could not be created.");
    }

        $sql = "UPDATE products

            SET current_stock =
            current_stock + ?

            WHERE id=?
            AND user_id=?";

    $stmt = mysqli_prepare(
        $conn,
        $sql
    );

    mysqli_stmt_bind_param(

        $stmt,

        "dii",

        $qty,
        $product_id,
        $user_id

    );

    mysqli_stmt_execute($stmt);

}

/*
==========================================
UPDATE WALLET
==========================================
*/

if($old_payment_splits || $payment_splits){

    // Multi-wallet: each wallet moves only by its own difference.
    wallet_splits_apply_change($conn, $user_id, $old_wallet_map, $new_wallet_map, false);

}elseif($old_wallet_id == $payment_wallet_id){

    /*
    Same Wallet
    */

    $difference = $paid_amount - $old_paid_amount;

    if($difference != 0){

        if($difference > 0){

            debit_wallet(
                $conn,
                $payment_wallet_id,
                $user_id,
                $difference
            );

        }else{

            credit_wallet(
                $conn,
                $payment_wallet_id,
                $user_id,
                abs($difference)
            );

        }

    }

}else{

    /*
    Return Old Wallet
    */

    if($old_paid_amount > 0){

        credit_wallet(
            $conn,
            $old_wallet_id,
            $user_id,
            $old_paid_amount
        );

    }

    /*
    Deduct New Wallet
    */

    if($paid_amount > 0){

        debit_wallet(
            $conn,
            $payment_wallet_id,
            $user_id,
            $paid_amount
        );

    }

}

mysqli_query(
    $conn,
    "DELETE FROM transactions
     WHERE user_id={$user_id}
     AND transaction_type='purchase'
     AND reference_id={$purchase_id}"
);

if($paid_amount > 0 && $payment_splits){

    wallet_splits_record_transactions($conn, $purchase_no, $user_id, 'purchase', $purchase_id, $payment_splits, 'Purchase - ' . $purchase_no, $purchase_date);

}elseif($paid_amount > 0){

    record_wallet_transaction(
        $conn,
        $purchase_no,
        $user_id,
        $payment_wallet_id,
        'purchase',
        $purchase_id,
        $paid_amount,
        'Purchase - ' . $purchase_no,
        $purchase_date
    );

}

$expense_delete = mysqli_prepare($conn, "DELETE FROM expenses WHERE user_id=? AND source_type='purchase_payment' AND source_id=?");
mysqli_stmt_bind_param($expense_delete, 'ii', $user_id, $purchase_id);
mysqli_stmt_execute($expense_delete);
if ($paid_amount > 0 && $payment_splits) wallet_splits_record_supplier_expenses($conn, $user_id, $payment_splits, $purchase_date, supplier_display_text('Supplier Payment - ') . $purchase_no, 'purchase_payment', $purchase_id);
elseif ($paid_amount > 0) record_supplier_payment_expense($conn, $user_id, $payment_wallet_id, $paid_amount, $purchase_date, supplier_display_text('Supplier Payment - ') . $purchase_no, 'purchase_payment', $purchase_id);

mysqli_commit($conn);

header("Location:index.php");

exit;

}catch(Exception $e){

    mysqli_rollback($conn);

    $error_message = $e->getMessage();

    if($error_message === "This purchase already affected FIFO stock usage. Edit is not allowed."){
        $error_message = "This purchase has already been used in FIFO stock calculations, so it can no longer be edited.";
    }

    $_SESSION['error'] = $error_message;

    header("Location:edit.php?id=" . $purchase_id);
    exit;

}
