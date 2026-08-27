<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/expense_helper.php';

$user_id = $_SESSION['user_id'];
ensure_fifo_inventory_tables($conn);
ensure_expense_support_tables($conn, $user_id);

$purchase_id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;

if($purchase_id <= 0){

    die("Invalid Purchase");

}

mysqli_begin_transaction($conn);

try{

if(!fifo_inventory_purchase_is_editable($conn, $purchase_id)){
    throw new Exception("This purchase already affected FIFO stock usage. Delete is not allowed.");
}

    /*
------------------------------------
Get Purchase
------------------------------------
*/

$sql = "SELECT

            paid_amount,
            payment_wallet_id

        FROM purchases

        WHERE id=?
        AND user_id=?";

$stmt = mysqli_prepare(
    $conn,
    $sql
);

mysqli_stmt_bind_param(

    $stmt,

    "ii",

    $purchase_id,
    $user_id

);

mysqli_stmt_execute($stmt);

$result =
    mysqli_stmt_get_result($stmt);

$purchase =
    mysqli_fetch_assoc($result);

mysqli_free_result($result);
mysqli_stmt_close($stmt);

if(!$purchase){
    throw new Exception("Purchase not found.");
}

$paid_amount =
    (float)$purchase['paid_amount'];

$payment_wallet_id =
    (int)$purchase['payment_wallet_id'];
    
$payment_wallet_exists = false;

if($payment_wallet_id > 0){

    $wallet_stmt = mysqli_prepare(
        $conn,
        "SELECT id
         FROM wallets
         WHERE id=?
         AND user_id=?
         LIMIT 1"
    );

    mysqli_stmt_bind_param(
        $wallet_stmt,
        "ii",
        $payment_wallet_id,
        $user_id
    );

    mysqli_stmt_execute($wallet_stmt);

    $wallet_result = mysqli_stmt_get_result($wallet_stmt);
    $payment_wallet_exists = $wallet_result && mysqli_num_rows($wallet_result) > 0;

    if($wallet_result){
        mysqli_free_result($wallet_result);
    }

    mysqli_stmt_close($wallet_stmt);

}

$supplier_payment_stmt = mysqli_prepare(
    $conn,
    "SELECT id, wallet_id, amount
     FROM supplier_payments
     WHERE purchase_id=?
     AND user_id=?
     ORDER BY id DESC"
);
mysqli_stmt_bind_param($supplier_payment_stmt, "ii", $purchase_id, $user_id);
mysqli_stmt_execute($supplier_payment_stmt);
$supplier_payment_result = mysqli_stmt_get_result($supplier_payment_stmt);
$supplier_payments = [];
$supplier_payment_total = 0;

while($supplier_payment_result && $supplier_payment_row = mysqli_fetch_assoc($supplier_payment_result)){
    $supplier_payments[] = $supplier_payment_row;
    $supplier_payment_total += (float)$supplier_payment_row['amount'];
}

$initial_purchase_payment_amount = max(0, $paid_amount - $supplier_payment_total);

    /*
    ------------------------------------
    Get Purchase Items
    ------------------------------------
    */

    $sql = "SELECT
                product_id,
                quantity
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

    // Read the rows first, then release this statement before the FIFO helper
    // runs additional queries on the same MySQL connection.
    $items_result = mysqli_stmt_get_result($stmt);
    $items = [];
    while($item = mysqli_fetch_assoc($items_result)){
        $items[] = $item;
    }
    mysqli_free_result($items_result);
    mysqli_stmt_close($stmt);

    if(!fifo_inventory_remove_purchase_batches($conn, $purchase_id)){
        throw new Exception("FIFO batches could not be removed.");
    }

    /*
    ------------------------------------
    Reverse Stock
    ------------------------------------
    */

    foreach($items as $row){

        $sql = "UPDATE products

                SET current_stock =
                    current_stock - ?

                WHERE id=?
                AND user_id=?";

        $stmt = mysqli_prepare(
            $conn,
            $sql
        );

        mysqli_stmt_bind_param(

            $stmt,

            "dii",

            $row['quantity'],
            $row['product_id'],
            $user_id

        );

        mysqli_stmt_execute($stmt);

    }

    /*
    ------------------------------------
    Delete Purchase Items
    ------------------------------------
    */

    $sql = "DELETE
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

    /*
------------------------------------
RESTORE WALLET
------------------------------------
*/

if($paid_amount > 0){

    if($payment_wallet_exists && $initial_purchase_payment_amount > 0){
        credit_wallet(
            $conn,
            $payment_wallet_id,
            $user_id,
            $initial_purchase_payment_amount
        );
    }

}

foreach($supplier_payments as $supplier_payment_row){
    $supplier_wallet_exists = false;
    $supplier_wallet_id = (int)$supplier_payment_row['wallet_id'];

    if($supplier_wallet_id > 0){
        $supplier_wallet_stmt = mysqli_prepare(
            $conn,
            "SELECT id
             FROM wallets
             WHERE id=?
             AND user_id=?
             LIMIT 1"
        );
        mysqli_stmt_bind_param($supplier_wallet_stmt, "ii", $supplier_wallet_id, $user_id);
        mysqli_stmt_execute($supplier_wallet_stmt);
        $supplier_wallet_result = mysqli_stmt_get_result($supplier_wallet_stmt);
        $supplier_wallet_exists = $supplier_wallet_result && mysqli_num_rows($supplier_wallet_result) > 0;

        if($supplier_wallet_result){
            mysqli_free_result($supplier_wallet_result);
        }

        mysqli_stmt_close($supplier_wallet_stmt);
    }

    if($supplier_wallet_exists){
        credit_wallet(
            $conn,
            $supplier_wallet_id,
            $user_id,
            (float)$supplier_payment_row['amount']
        );
    }
}

$sql = "DELETE FROM transactions
        WHERE user_id=?
        AND transaction_type='purchase'
        AND reference_id=?";

$stmt = mysqli_prepare(
    $conn,
    $sql
);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $user_id,
    $purchase_id
);

mysqli_stmt_execute($stmt);

$supplier_txn_stmt = mysqli_prepare(
    $conn,
    "DELETE FROM transactions
     WHERE user_id=?
     AND transaction_type='supplier_payment'
     AND reference_id IN (
        SELECT id
        FROM supplier_payments
        WHERE purchase_id=?
        AND user_id=?
     )"
);
mysqli_stmt_bind_param($supplier_txn_stmt, "iii", $user_id, $purchase_id, $user_id);
mysqli_stmt_execute($supplier_txn_stmt);

$delete_supplier_expenses_stmt = mysqli_prepare(
    $conn,
    "DELETE FROM expenses
     WHERE user_id=?
     AND (
        (source_type='purchase_payment' AND source_id=?)
        OR
        (source_type='supplier_payment' AND source_id IN (
            SELECT id
            FROM supplier_payments
            WHERE purchase_id=?
            AND user_id=?
        ))
     )"
);
mysqli_stmt_bind_param($delete_supplier_expenses_stmt, "iiii", $user_id, $purchase_id, $purchase_id, $user_id);
mysqli_stmt_execute($delete_supplier_expenses_stmt);

$delete_supplier_payments_stmt = mysqli_prepare(
    $conn,
    "DELETE FROM supplier_payments
     WHERE purchase_id=?
     AND user_id=?"
);
mysqli_stmt_bind_param($delete_supplier_payments_stmt, "ii", $purchase_id, $user_id);
mysqli_stmt_execute($delete_supplier_payments_stmt);

    /*
    ------------------------------------
    Delete Purchase
    ------------------------------------
    */

    $sql = "DELETE
            FROM purchases
            WHERE id=?
            AND user_id=?";

    $stmt = mysqli_prepare(
        $conn,
        $sql
    );

    mysqli_stmt_bind_param(

        $stmt,

        "ii",

        $purchase_id,
        $user_id

    );

    mysqli_stmt_execute($stmt);

    mysqli_commit($conn);

    header(
        "Location:index.php"
    );

    exit;

}catch(Exception $e){

    mysqli_rollback($conn);

    $error_message = $e->getMessage();

    if($error_message === "This purchase already affected FIFO stock usage. Delete is not allowed."){
        $error_message = "This purchase has already been used in FIFO stock calculations, so it can no longer be deleted.";
    }

    $_SESSION['error'] = $error_message;

    header("Location:index.php");
    exit;

}
