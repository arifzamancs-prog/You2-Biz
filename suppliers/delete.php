<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/supplier_helper.php';

$user_id = $_SESSION['user_id'];

$id = (int)$_GET['id'];

if(!supplier_has_transactions($conn, $id, $user_id)){
    $stmt = mysqli_prepare($conn, "DELETE FROM suppliers WHERE id=? AND user_id=?");
    mysqli_stmt_bind_param($stmt, 'ii', $id, $user_id);
    mysqli_stmt_execute($stmt);
}

header("Location:index.php");
exit;
