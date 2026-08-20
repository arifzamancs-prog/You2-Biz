<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/contact_unique_helper.php';

$user_id = $_SESSION['user_id'];

$id = (int)$_POST['id'];
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$duplicate_message = '';

if(
    contact_has_company_user_conflict($conn, 'phone', $phone, $user_id, $duplicate_message) ||
    contact_has_company_user_conflict($conn, 'email', $email, $user_id, $duplicate_message) ||
    contact_has_duplicate_in_table($conn, 'suppliers', 'Supplier', 'phone', $phone, $id, $duplicate_message, $user_id) ||
    contact_has_duplicate_in_table($conn, 'suppliers', 'Supplier', 'email', $email, $id, $duplicate_message, $user_id)
){
    $_SESSION['error'] = $duplicate_message;
    header("Location:edit.php?id=" . $id);
    exit;
}

$sql = "UPDATE suppliers SET

            supplier_name=?,
            phone=?,
            email=?,
            address=?

        WHERE id=?
        AND user_id=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(

    $stmt,

    "ssssii",

    $_POST['supplier_name'],
    $phone,
    $email,
    $_POST['address'],
    $id,
    $user_id

);

mysqli_stmt_execute($stmt);

header("Location:index.php");
exit;
