<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/fifo_inventory_helper.php';

$user_id = $_SESSION['user_id'];
ensure_fifo_inventory_tables($conn);

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

$paid_amount =
    (float)$purchase['paid_amount'];

$payment_wallet_id =
    (int)$purchase['payment_wallet_id'];

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

    if(!fifo_inventory_remove_purchase_batches($conn, $purchase_id)){
        throw new Exception("FIFO batches could not be removed.");
    }

    $items =
        mysqli_stmt_get_result($stmt);

    /*
    ------------------------------------
    Reverse Stock
    ------------------------------------
    */

    while(
        $row =
        mysqli_fetch_assoc($items)
    ){

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

    credit_wallet(
        $conn,
        $payment_wallet_id,
        $user_id,
        $paid_amount
    );

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
