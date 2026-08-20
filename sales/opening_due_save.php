<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/customer_opening_due_helper.php';
require_once '../includes/input_validation_helper.php';

if(is_agent_user()){
    header("Location: opening_due_entry.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
ensure_customer_opening_due_tables($conn);

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    header("Location: opening_due_entry.php");
    exit;
}

$customer_id = (int)($_POST['customer_id'] ?? 0);
$amount = (float)($_POST['amount'] ?? 0);
$entry_date = trim((string)($_POST['entry_date'] ?? date('Y-m-d')));
$notes = trim((string)($_POST['notes'] ?? ''));

mysqli_begin_transaction($conn);

try{
    if($customer_id <= 0){
        throw new Exception("Please select a customer.");
    }

    if($amount <= 0){
        throw new Exception("Due Amount must be greater than zero.");
    }

    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entry_date)){
        throw new Exception("Invalid entry date.");
    }

    $customer_sql = "SELECT id
                     FROM customers
                     WHERE id=?
                     AND user_id=?
                     LIMIT 1";
    $customer_stmt = mysqli_prepare($conn, $customer_sql);
    mysqli_stmt_bind_param($customer_stmt, "ii", $customer_id, $user_id);
    mysqli_stmt_execute($customer_stmt);
    $customer = mysqli_fetch_assoc(mysqli_stmt_get_result($customer_stmt));

    if(!$customer){
        throw new Exception("Customer not found.");
    }

    $due_no = generate_customer_opening_due_no($conn);

    $sql = "INSERT INTO customer_opening_dues
            (
                user_id,
                customer_id,
                due_no,
                entry_date,
                amount,
                paid_amount,
                due_amount,
                status,
                notes
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                0,
                ?,
                'due',
                ?
            )";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param(
        $stmt,
        "iissdds",
        $user_id,
        $customer_id,
        $due_no,
        $entry_date,
        $amount,
        $amount,
        $notes
    );

    if(!mysqli_stmt_execute($stmt)){
        throw new Exception(mysqli_stmt_error($stmt));
    }

    mysqli_commit($conn);

    header("Location: opening_due_entry.php?success=1");
    exit;

}catch(Exception $e){
    mysqli_rollback($conn);
    $_SESSION['error'] = $e->getMessage();
    header("Location: opening_due_entry.php");
    exit;
}
