<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';

$user_id = $_SESSION['user_id'];

$id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;

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

header("Location: index.php");
exit;