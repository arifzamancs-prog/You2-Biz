<?php

function customer_has_transactions($conn, $customer_id, $user_id)
{
    $customer_id = (int)$customer_id;
    $user_id = (int)$user_id;

    if($customer_id <= 0 || $user_id <= 0){
        return false;
    }

    foreach(['invoices', 'customer_payments', 'customer_opening_dues', 'booking_invoices'] as $table){
        $table_result = mysqli_query($conn, "SHOW TABLES LIKE '" . $table . "'");
        if(!$table_result || mysqli_num_rows($table_result) === 0){
            continue;
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT id FROM " . $table . " WHERE customer_id=? AND user_id=? LIMIT 1"
        );

        if(!$stmt){
            continue;
        }

        mysqli_stmt_bind_param($stmt, 'ii', $customer_id, $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if($result && mysqli_num_rows($result) > 0){
            return true;
        }
    }

    return false;
}
