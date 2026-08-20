<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/transaction_helper.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/fifo_inventory_helper.php';

$user_id = $_SESSION['user_id'];
ensure_fifo_inventory_tables($conn);

if($_SERVER['REQUEST_METHOD'] != 'POST'){

    header("Location:index.php");
    exit;

}

$purchase_id = (int)($_POST['purchase_id'] ?? 0);

mysqli_begin_transaction($conn);

try{

    $supplier_id = (int)$_POST['supplier_id'];

    $purchase_date = $_POST['purchase_date'];

    $grand_total = (float)$_POST['grand_total'];

    $paid_amount = (float)$_POST['paid_amount'];
    
    $payment_wallet_id =
    (int)($_POST['payment_wallet_id'] ?? 0);

    $due_amount = (float)$_POST['due_amount'];

    $payment_status = $_POST['payment_status'];

    $notes = trim($_POST['notes']);

    $sql = "SELECT
            purchase_no,
            paid_amount,
            payment_wallet_id
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

$old_purchase = mysqli_fetch_assoc($result);

if(!$old_purchase){

    throw new Exception("Purchase Not Found");

}

if(!fifo_inventory_purchase_is_editable($conn, $purchase_id)){
    throw new Exception("This purchase already affected FIFO stock usage. Edit is not allowed.");
}

$old_paid_amount =
    (float)$old_purchase['paid_amount'];

$old_wallet_id =
    (int)$old_purchase['payment_wallet_id'];

$purchase_no =
    $old_purchase['purchase_no'];

if($paid_amount < 0){

    throw new Exception("Invalid Paid Amount");

}

if($paid_amount > $grand_total){

    throw new Exception("Paid Amount cannot exceed Grand Total");

}

if($paid_amount > 0){

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

if(!fifo_inventory_remove_purchase_batches($conn, $purchase_id)){
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

foreach($product_ids as $key=>$product_id){

    if(empty($product_id)){
        continue;
    }

    $qty = (int)$qtys[$key];

    $price = (float)$prices[$key];

    $total = (float)$totals[$key];

    $sql = "INSERT INTO purchase_items(

                purchase_id,
                product_id,
                quantity,
                unit_cost,
                total_cost

            )

            VALUES(

                ?,?,?,?,?

            )";

    $stmt = mysqli_prepare(
        $conn,
        $sql
    );

    mysqli_stmt_bind_param(

        $stmt,

        "iiddd",

        $purchase_id,
        $product_id,
        $qty,
        $price,
        $total

    );

    mysqli_stmt_execute($stmt);

    if(!fifo_inventory_create_batch(
        $conn,
        $user_id,
        (int)$product_id,
        $qty,
        $price,
        'purchase',
        $purchase_id,
        $purchase_no,
        $purchase_date
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

if($old_wallet_id == $payment_wallet_id){

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

if($paid_amount > 0){

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
