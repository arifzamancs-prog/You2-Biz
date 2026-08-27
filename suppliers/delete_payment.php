<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/expense_helper.php';

if(!manager_can_modify()){
    header('Location:supplier_payment.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$payment_id = (int)($_GET['id'] ?? 0);
$purchase_id = (int)($_GET['purchase_id'] ?? 0);

if($payment_id <= 0){
    header('Location:supplier_payment.php');
    exit;
}

ensure_expense_support_tables($conn, $user_id);
mysqli_begin_transaction($conn);

try{
    $payment_stmt = mysqli_prepare(
        $conn,
        "SELECT *
         FROM supplier_payments
         WHERE id=?
         AND user_id=?
         LIMIT 1
         FOR UPDATE"
    );
    mysqli_stmt_bind_param($payment_stmt, 'ii', $payment_id, $user_id);
    mysqli_stmt_execute($payment_stmt);
    $payment = mysqli_fetch_assoc(mysqli_stmt_get_result($payment_stmt));

    if(!$payment){
        throw new Exception('Supplier payment not found.');
    }

    $purchase_stmt = mysqli_prepare(
        $conn,
        "SELECT *
         FROM purchases
         WHERE id=?
         AND user_id=?
         LIMIT 1
         FOR UPDATE"
    );
    $linked_purchase_id = (int)$payment['purchase_id'];
    mysqli_stmt_bind_param($purchase_stmt, 'ii', $linked_purchase_id, $user_id);
    mysqli_stmt_execute($purchase_stmt);
    $purchase = mysqli_fetch_assoc(mysqli_stmt_get_result($purchase_stmt));

    $wallet_exists = false;

    if((int)$payment['wallet_id'] > 0){
        $wallet_stmt = mysqli_prepare(
            $conn,
            "SELECT id
             FROM wallets
             WHERE id=?
             AND user_id=?
             LIMIT 1"
        );
        $wallet_id = (int)$payment['wallet_id'];
        mysqli_stmt_bind_param($wallet_stmt, 'ii', $wallet_id, $user_id);
        mysqli_stmt_execute($wallet_stmt);
        $wallet_exists = mysqli_num_rows(mysqli_stmt_get_result($wallet_stmt)) > 0;
    }

    if($wallet_exists){
        credit_wallet($conn, (int)$payment['wallet_id'], $user_id, (float)$payment['amount']);
    }

    if($purchase){
        $updated_paid = max(0, (float)$purchase['paid_amount'] - (float)$payment['amount']);
        $updated_due = max(0, (float)$purchase['total_amount'] - $updated_paid);
        $updated_status = 'due';

        if($updated_due <= 0){
            $updated_status = 'paid';
        }elseif($updated_paid > 0){
            $updated_status = 'partial';
        }

        $update_purchase_stmt = mysqli_prepare(
            $conn,
            "UPDATE purchases
             SET paid_amount=?,
                 due_amount=?,
                 payment_status=?
             WHERE id=?
             AND user_id=?"
        );
        mysqli_stmt_bind_param($update_purchase_stmt, 'ddsii', $updated_paid, $updated_due, $updated_status, $linked_purchase_id, $user_id);

        if(!mysqli_stmt_execute($update_purchase_stmt)){
            throw new Exception(mysqli_stmt_error($update_purchase_stmt));
        }
    }

    $delete_txn_stmt = mysqli_prepare(
        $conn,
        "DELETE FROM transactions
         WHERE user_id=?
         AND transaction_type='supplier_payment'
         AND reference_id=?"
    );
    mysqli_stmt_bind_param($delete_txn_stmt, 'ii', $user_id, $payment_id);
    mysqli_stmt_execute($delete_txn_stmt);

    $delete_expense_stmt = mysqli_prepare(
        $conn,
        "DELETE FROM expenses
         WHERE user_id=?
         AND source_type='supplier_payment'
         AND source_id=?"
    );
    mysqli_stmt_bind_param($delete_expense_stmt, 'ii', $user_id, $payment_id);
    mysqli_stmt_execute($delete_expense_stmt);

    $delete_payment_stmt = mysqli_prepare(
        $conn,
        "DELETE FROM supplier_payments
         WHERE id=?
         AND user_id=?"
    );
    mysqli_stmt_bind_param($delete_payment_stmt, 'ii', $payment_id, $user_id);

    if(!mysqli_stmt_execute($delete_payment_stmt)){
        throw new Exception(mysqli_stmt_error($delete_payment_stmt));
    }

    mysqli_commit($conn);
    header('Location:supplier_payment.php?success=deleted');
    exit;
}catch(Exception $exception){
    mysqli_rollback($conn);
    $redirect_purchase_id = $purchase_id > 0 ? $purchase_id : 0;

    if($redirect_purchase_id > 0){
        $_SESSION['supplier_payment_error'] = $exception->getMessage();
        header('Location:supplier_payment_entry.php?id=' . $redirect_purchase_id);
        exit;
    }

    header('Location:supplier_payment.php?error=' . urlencode($exception->getMessage()));
    exit;
}
