<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';

$id = (int)$_GET['id'];

$user_id = $_SESSION['user_id'];

$sql = "UPDATE categories
        SET status='inactive'
        WHERE id=?
        AND user_id=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $id,
    $user_id
);

mysqli_stmt_execute($stmt);

header("Location: index.php");
exit;