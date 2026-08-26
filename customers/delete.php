<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/customer_helper.php';

$user_id = $_SESSION['user_id'];

$id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;

if(!customer_has_transactions($conn, $id, $user_id)){
    $sql = "DELETE
            FROM customers
            WHERE id=?
            AND user_id=?";

    $stmt = mysqli_prepare(
        $conn,
        $sql
    );

    mysqli_stmt_bind_param(
        $stmt,
        "ii",
        $id,
        $user_id
    );

    mysqli_stmt_execute($stmt);
}

header("Location: index.php");
exit;
