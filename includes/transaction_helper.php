<?php

function generate_short_unique_txn_no($conn, $prefix, $source_table = '', $source_column = 'txn_no')
{
    $prefix = preg_replace('/[^A-Z0-9-]/i', '', (string)$prefix);
    $source_table = preg_replace('/[^A-Z0-9_]/i', '', (string)$source_table);
    $source_column = preg_replace('/[^A-Z0-9_]/i', '', (string)$source_column);

    for($attempt = 0; $attempt < 10; $attempt++){
        $txn_no = strtoupper($prefix) . '-' . date('ymdHis') . random_int(10, 99);
        $transaction_count = 0;
        $source_count = 0;

        $stmt = mysqli_prepare(
            $conn,
            "SELECT COUNT(*) AS total
             FROM transactions
             WHERE txn_no=?"
        );

        if($stmt){
            mysqli_stmt_bind_param($stmt, "s", $txn_no);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $row = $result ? mysqli_fetch_assoc($result) : null;
            $transaction_count = (int)($row['total'] ?? 0);
        }

        if($source_table !== '' && $source_column !== ''){
            $stmt = mysqli_prepare(
                $conn,
                "SELECT COUNT(*) AS total
                 FROM `{$source_table}`
                 WHERE `{$source_column}`=?"
            );

            if($stmt){
                mysqli_stmt_bind_param($stmt, "s", $txn_no);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $row = $result ? mysqli_fetch_assoc($result) : null;
                $source_count = (int)($row['total'] ?? 0);
            }
        }

        if($transaction_count === 0 && $source_count === 0){
            return $txn_no;
        }
    }

    return strtoupper($prefix) . '-' . date('ymdHis') . random_int(100, 999);
}

function record_wallet_transaction(
    $conn,
    $txn_no,
    $user_id,
    $wallet_id,
    $transaction_type,
    $reference_id,
    $amount,
    $note,
    $txn_date
){
    $base_txn_no = (string)$txn_no;

    for($attempt = 0; $attempt < 5; $attempt++){
        $txn_no = $attempt === 0
            ? $base_txn_no
            : $base_txn_no . '-' . random_int(1000, 9999);

        $sql = "INSERT INTO transactions
        (
            txn_no,
            user_id,
            wallet_id,
            transaction_type,
            reference_id,
            amount,
            note,
            txn_date
        )
        VALUES
        (
            ?,?,?,?,?,?,?,?
        )";

        $stmt = mysqli_prepare($conn,$sql);

        if(!$stmt){
            throw new Exception(mysqli_error($conn));
        }

        mysqli_stmt_bind_param(
            $stmt,
            "siisidss",
            $txn_no,
            $user_id,
            $wallet_id,
            $transaction_type,
            $reference_id,
            $amount,
            $note,
            $txn_date
        );

        if(mysqli_stmt_execute($stmt)){
            return;
        }

        $error_no = mysqli_stmt_errno($stmt);
        $error = mysqli_stmt_error($stmt);

        if($error_no !== 1062){
            throw new Exception($error);
        }

    }

    throw new Exception('Duplicate transaction number. Please try again.');
}
