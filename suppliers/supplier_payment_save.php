<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/transaction_helper.php';
require_once '../includes/wallet_helper.php';

$user_id = $_SESSION['user_id'];

if($_SERVER['REQUEST_METHOD']!='POST'){

    header("Location:supplier_payment.php");
    exit;

}

$purchase_id = (int)$_POST['purchase_id'];

$amount = (float)$_POST['amount'];

$payment_wallet_id = (int)$_POST['payment_wallet_id'];

$notes = trim($_POST['notes']);

mysqli_begin_transaction($conn);

try{
    $supplier_payment_txn_no = generate_short_unique_txn_no($conn, 'SPAY');

    /*
    ------------------------------------------
    Get Purchase
    ------------------------------------------
    */

    $sql = "SELECT

                supplier_id,
                paid_amount,
                due_amount,
                purchase_no

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

        throw new Exception("Purchase Not Found");

    }

    if($amount<=0){

        throw new Exception("Invalid Amount");

    }

    if($amount>$purchase['due_amount']){

        throw new Exception("Amount exceeds Due");

    }

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

    if($amount > (float)$wallet['balance']){

        throw new Exception("Insufficient Wallet Balance");

    }

    /*
    ------------------------------------------
    Purchase Update
    ------------------------------------------
    */

    $paid_amount =
        $purchase['paid_amount'] + $amount;

    $due_amount =
        $purchase['due_amount'] - $amount;

    $status = "due";

    if($due_amount<=0){

        $status = "paid";

    }elseif($paid_amount>0){

        $status = "partial";

    }

    $sql = "UPDATE purchases

            SET paid_amount=?,
                due_amount=?,
                payment_status=?

            WHERE id=?
            AND user_id=?";

    $stmt = mysqli_prepare($conn,$sql);

    mysqli_stmt_bind_param(

        $stmt,

        "ddsii",

        $paid_amount,
        $due_amount,
        $status,
        $purchase_id,
        $user_id

    );

    if(!mysqli_stmt_execute($stmt)){

        throw new Exception(
            mysqli_stmt_error($stmt)
        );

    }

    /*
------------------------------------------
SAVE SUPPLIER PAYMENT
------------------------------------------
*/

$sql = "INSERT INTO supplier_payments
(
    user_id,
    supplier_id,
    purchase_id,
    wallet_id,
    amount,
    payment_date,
    note
)
VALUES
(
    ?,
    ?,
    ?,
    ?,
    ?,
    CURDATE(),
    ?
)";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(

    $stmt,

    "iiiids",

    $user_id,
    $purchase['supplier_id'],
    $purchase_id,
    $payment_wallet_id,
    $amount,
    $notes

);

if(!mysqli_stmt_execute($stmt)){

    throw new Exception(
        mysqli_stmt_error($stmt)
    );

}

$supplier_payment_id = mysqli_insert_id($conn);

    /*
    ------------------------------------------
    Wallet Minus
    ------------------------------------------
    */

    debit_wallet(
        $conn,
        $payment_wallet_id,
        $user_id,
        $amount
    );

    record_wallet_transaction(
        $conn,
        $supplier_payment_txn_no,
        $user_id,
        $payment_wallet_id,
        'supplier_payment',
        $supplier_payment_id,
        $amount,
        'Supplier Due Payment - ' . $purchase['purchase_no'],
        date('Y-m-d')
    );

    mysqli_commit($conn);

    header("Location:supplier_payment.php?success=1");

    exit;

}catch(Exception $e){

    mysqli_rollback($conn);

    $_SESSION['supplier_payment_error'] = $e->getMessage();

    header("Location:supplier_payment_entry.php?id=" . $purchase_id);
    exit;

}
