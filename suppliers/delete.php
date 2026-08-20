<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';

$user_id = $_SESSION['user_id'];

$id = (int)$_GET['id'];

mysqli_query(
    $conn,
    "DELETE FROM suppliers
     WHERE id='$id'
     AND user_id='$user_id'"
);

header("Location:index.php");
exit;