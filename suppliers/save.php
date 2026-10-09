<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/contact_unique_helper.php';
require_once '../includes/input_validation_helper.php';
require_once '../includes/product_category_helper.php';
require_once '../includes/supplier_helper.php';

$user_id = $_SESSION['user_id'];

if($_SERVER['REQUEST_METHOD'] != 'POST'){

    header("Location:create.php");
    exit;

}

$supplier_name = trim($_POST['supplier_name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$address = trim($_POST['address'] ?? '');
$is_restaurant_catalog = restaurant_catalog_enabled($conn, $user_id);
$supplier_code = trim($_POST['supplier_code'] ?? '');

if($is_restaurant_catalog){
    ensure_restaurant_supplier_code_column($conn);
}

$supplier_name = normalize_person_name($supplier_name);
$phone = normalize_phone_input($phone);
$email = normalize_email_input($email);

if(($error_message = validate_person_name($supplier_name, supplier_display_text('Supplier name'))) !== ''){

    $_SESSION['error'] = $error_message;
    header("Location:create.php");
    exit;

}

if(($error_message = validate_phone_input($phone, 'Phone')) !== ''){

    $_SESSION['error'] = $error_message;
    header("Location:create.php");
    exit;

}

if(($error_message = validate_email_input($email, 'Email')) !== ''){

    $_SESSION['error'] = $error_message;
    header("Location:create.php");
    exit;

}

$duplicate_message = '';

if(
    contact_has_company_user_conflict($conn, 'phone', $phone, $user_id, $duplicate_message) ||
    contact_has_company_user_conflict($conn, 'email', $email, $user_id, $duplicate_message) ||
    contact_has_duplicate_in_table($conn, 'suppliers', supplier_display_text('Supplier'), 'phone', $phone, 0, $duplicate_message, $user_id) ||
    contact_has_duplicate_in_table($conn, 'suppliers', supplier_display_text('Supplier'), 'email', $email, 0, $duplicate_message, $user_id)
){
    $_SESSION['error'] = $duplicate_message;
    header("Location:create.php");
    exit;
}

if($is_restaurant_catalog && $supplier_code !== ''){
    $code_stmt = mysqli_prepare($conn, 'SELECT id FROM suppliers WHERE user_id=? AND supplier_code=? LIMIT 1');
    mysqli_stmt_bind_param($code_stmt, 'is', $user_id, $supplier_code);
    mysqli_stmt_execute($code_stmt);
    $duplicate_code = (bool)mysqli_fetch_assoc(mysqli_stmt_get_result($code_stmt));
    mysqli_stmt_close($code_stmt);
    if($duplicate_code){
        $_SESSION['error'] = 'This Supplier ID is already in use.';
        header("Location:create.php");
        exit;
    }
}

$sql = $is_restaurant_catalog
    ? "INSERT INTO suppliers (user_id, supplier_code, supplier_name, phone, email, address, status) VALUES (?, ?, ?, ?, ?, ?, 'active')"
    : "INSERT INTO suppliers
        (
            user_id,
            supplier_name,
            phone,
            email,
            address,
            status
        )
        VALUES
        (
            ?,?,?,?,?, 'active'
        )";

$stmt = mysqli_prepare($conn,$sql);

if($is_restaurant_catalog){
    mysqli_stmt_bind_param($stmt, "isssss", $user_id, $supplier_code, $supplier_name, $phone, $email, $address);
}else{
    mysqli_stmt_bind_param($stmt, "issss", $user_id, $supplier_name, $phone, $email, $address);
}

if(mysqli_stmt_execute($stmt)){

    $_SESSION['success'] = supplier_display_text("Supplier saved successfully.");
    header("Location:index.php");
    exit;

}

$_SESSION['error'] = mysqli_stmt_error($stmt);
header("Location:create.php");
exit;
