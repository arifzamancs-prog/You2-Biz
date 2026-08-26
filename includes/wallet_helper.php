<?php

function ensure_default_cash_wallet($conn, $user_id)
{
    $user_id = (int)$user_id;

    /* Rename legacy system wallets without changing their transaction links. */
    $rename_sql = "UPDATE wallets
                   SET wallet_name='Cash'
                   WHERE user_id=?
                   AND wallet_name='Cash Box'
                   AND is_system=1";
    $rename_stmt = mysqli_prepare($conn, $rename_sql);
    mysqli_stmt_bind_param($rename_stmt, "i", $user_id);
    mysqli_stmt_execute($rename_stmt);

    $sql = "SELECT id, is_system, status
            FROM wallets
            WHERE user_id=?
            AND wallet_name='Cash'
            ORDER BY is_system DESC, id ASC
            LIMIT 1";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $wallet = mysqli_fetch_assoc($result);

    if($wallet){
        if((int)$wallet['is_system'] !== 1 || $wallet['status'] !== 'active'){
            $update_sql = "UPDATE wallets
                           SET is_system=1,
                               status='active',
                               description='System Default Cash Wallet'
                           WHERE id=?";

            $update_stmt = mysqli_prepare($conn, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "i", $wallet['id']);
            mysqli_stmt_execute($update_stmt);
        }

        return (int)$wallet['id'];
    }

    $insert_sql = "INSERT INTO wallets
                   (
                       user_id,
                       wallet_name,
                       description,
                       balance,
                       status,
                       is_system
                   )
                   VALUES
                   (
                       ?,
                       'Cash',
                       'System Default Cash Wallet',
                       0,
                       'active',
                       1
                   )";

    $insert_stmt = mysqli_prepare($conn, $insert_sql);
    mysqli_stmt_bind_param($insert_stmt, "i", $user_id);
    mysqli_stmt_execute($insert_stmt);

    return (int)mysqli_insert_id($conn);
}

function active_wallets_result($conn, $user_id)
{
    $user_id = (int)$user_id;

    ensure_default_cash_wallet($conn, $user_id);

    return mysqli_query(
        $conn,
        "SELECT
            id,
            wallet_name,
            balance,
            is_system
         FROM wallets
         WHERE user_id={$user_id}
         AND status='active'
         ORDER BY is_system DESC,
                  wallet_name ASC"
    );
}

function debit_wallet($conn, $wallet_id, $user_id, $amount)
{
    if($amount <= 0){
        return;
    }

    $sql = "UPDATE wallets
            SET balance = balance - ?
            WHERE id=?
            AND user_id=?
            AND balance >= ?";

    $stmt = mysqli_prepare($conn,$sql);

    mysqli_stmt_bind_param(
        $stmt,
        "diid",
        $amount,
        $wallet_id,
        $user_id,
        $amount
    );

    if(!mysqli_stmt_execute($stmt)){
        throw new Exception(mysqli_stmt_error($stmt));
    }

    if(mysqli_stmt_affected_rows($stmt) <= 0){
        throw new Exception("Insufficient Wallet Balance");
    }
}

function credit_wallet($conn, $wallet_id, $user_id, $amount)
{
    if($amount <= 0){
        return;
    }

    $sql = "UPDATE wallets
            SET balance = balance + ?
            WHERE id=?
            AND user_id=?";

    $stmt = mysqli_prepare($conn,$sql);

    mysqli_stmt_bind_param(
        $stmt,
        "dii",
        $amount,
        $wallet_id,
        $user_id
    );

    if(!mysqli_stmt_execute($stmt)){
        throw new Exception(mysqli_stmt_error($stmt));
    }

    if(mysqli_stmt_affected_rows($stmt) <= 0){
        throw new Exception("Wallet Not Found");
    }
}
