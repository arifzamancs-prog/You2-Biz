<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/contact_unique_helper.php';
require_once '../includes/supplier_helper.php';

$user_id = $_SESSION['user_id'];

$id = (int)$_POST['id'];
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$duplicate_message = '';
$supplier_ids_enabled = supplier_uses_auto_code($conn, $user_id);
$supplier_code = trim((string)($_POST['supplier_code'] ?? ''));

if($supplier_ids_enabled){
    ensure_supplier_code_column($conn);

    if(($error_message = supplier_code_error($conn, $user_id, $supplier_code, $id)) !== ''){
        $_SESSION['error'] = $error_message;
        header("Location:edit.php?id=" . $id);
        exit;
    }
}

if(
    contact_has_company_user_conflict($conn, 'phone', $phone, $user_id, $duplicate_message) ||
    contact_has_company_user_conflict($conn, 'email', $email, $user_id, $duplicate_message) ||
    contact_has_duplicate_in_table($conn, 'suppliers', supplier_display_text('Supplier'), 'phone', $phone, $id, $duplicate_message, $user_id) ||
    contact_has_duplicate_in_table($conn, 'suppliers', supplier_display_text('Supplier'), 'email', $email, $id, $duplicate_message, $user_id)
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

// The Vendor/Supplier ID is updated too.
if($supplier_ids_enabled){
    $code_stmt = mysqli_prepare($conn, "UPDATE suppliers SET supplier_code=? WHERE id=? AND user_id=?");
    mysqli_stmt_bind_param($code_stmt, "sii", $supplier_code, $id, $user_id);
    mysqli_stmt_execute($code_stmt);
}

header("Location:index.php");
exit;
