<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/contact_unique_helper.php';
require_once '../includes/input_validation_helper.php';
require_once '../includes/product_category_helper.php';
require_once '../includes/supplier_helper.php';
require_once '../includes/project_package_helper.php';

$user_id = $_SESSION['user_id'];
$is_ajax_save = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

function supplier_save_response($success, $message, $data = [])
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => (bool)$success, 'message' => $message], $data));
    exit;
}

function supplier_save_failure($message, $is_ajax_save)
{
    if ($is_ajax_save) {
        supplier_save_response(false, $message);
    }
    $_SESSION['error'] = $message;
    header('Location:create.php');
    exit;
}

if($_SERVER['REQUEST_METHOD'] != 'POST'){
    supplier_save_failure('Invalid request.', $is_ajax_save);

}

$supplier_name = trim($_POST['supplier_name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$address = trim($_POST['address'] ?? '');
$is_restaurant_catalog = restaurant_catalog_enabled($conn, $user_id);
$is_fashion_retail = project_package_company_type($conn, $user_id) === 'Fashion house';
$supplier_ids_enabled = supplier_uses_auto_code($conn, $user_id);
$uses_supplier_code = $is_restaurant_catalog || $is_fashion_retail || $supplier_ids_enabled;
$supplier_code_label = supplier_code_label();
$supplier_code = trim($_POST['supplier_code'] ?? '');

if($uses_supplier_code){
    ensure_supplier_code_column($conn);
}

if($supplier_ids_enabled && ($error_message = supplier_code_error($conn, $user_id, $supplier_code)) !== ''){
    supplier_save_failure($error_message, $is_ajax_save);
}

$supplier_name = normalize_person_name($supplier_name);
$phone = normalize_phone_input($phone);
$email = normalize_email_input($email);

if(($error_message = validate_person_name($supplier_name, supplier_display_text('Supplier name'))) !== ''){
    supplier_save_failure($error_message, $is_ajax_save);

}

if(($error_message = validate_phone_input($phone, 'Phone')) !== ''){
    supplier_save_failure($error_message, $is_ajax_save);

}

if(($error_message = validate_email_input($email, 'Email')) !== ''){
    supplier_save_failure($error_message, $is_ajax_save);

}

$duplicate_message = '';

if(
    contact_has_company_user_conflict($conn, 'phone', $phone, $user_id, $duplicate_message) ||
    contact_has_company_user_conflict($conn, 'email', $email, $user_id, $duplicate_message) ||
    contact_has_duplicate_in_table($conn, 'suppliers', supplier_display_text('Supplier'), 'phone', $phone, 0, $duplicate_message, $user_id) ||
    contact_has_duplicate_in_table($conn, 'suppliers', supplier_display_text('Supplier'), 'email', $email, 0, $duplicate_message, $user_id)
){
    supplier_save_failure($duplicate_message, $is_ajax_save);
}

if($uses_supplier_code && $supplier_code !== ''){
    $code_stmt = mysqli_prepare($conn, 'SELECT id FROM suppliers WHERE user_id=? AND supplier_code=?' . restaurant_catalog_scope_sql($conn, '', true) . ' LIMIT 1');
    mysqli_stmt_bind_param($code_stmt, 'is', $user_id, $supplier_code);
    mysqli_stmt_execute($code_stmt);
    $duplicate_code = (bool)mysqli_fetch_assoc(mysqli_stmt_get_result($code_stmt));
    mysqli_stmt_close($code_stmt);
    if($duplicate_code){
        supplier_save_failure('This ' . $supplier_code_label . ' is already in use.', $is_ajax_save);
    }
}

$supplier_branch_column = $is_restaurant_catalog ? 'branch_id,' : '';
$supplier_branch_value = $is_restaurant_catalog ? selected_branch_id($conn, true) . ',' : '';
$sql = $uses_supplier_code
    ? "INSERT INTO suppliers ({$supplier_branch_column}user_id, supplier_code, supplier_name, phone, email, address, status) VALUES ({$supplier_branch_value}?, ?, ?, ?, ?, ?, 'active')"
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

if($uses_supplier_code){
    // An optional ID left blank is stored as NULL: the unique key allows many NULLs but only one ''.
    $supplier_code_value = $supplier_code !== '' ? $supplier_code : null;
    mysqli_stmt_bind_param($stmt, "isssss", $user_id, $supplier_code_value, $supplier_name, $phone, $email, $address);
}else{
    mysqli_stmt_bind_param($stmt, "issss", $user_id, $supplier_name, $phone, $email, $address);
}

if(mysqli_stmt_execute($stmt)){

    if ($is_ajax_save) {
        supplier_save_response(true, supplier_display_text('Supplier saved successfully.'), [
            'supplier_code' => $supplier_code,
            'next_supplier_code' => $supplier_ids_enabled ? supplier_code_next($conn, $user_id) : '',
            'supplier' => [
                'code' => $supplier_code,
                'name' => $supplier_name,
                'phone' => $phone,
                'email' => $email,
            ],
        ]);
    }

    $_SESSION['success'] = supplier_display_text("Supplier saved successfully.");
    header("Location:index.php");
    exit;

}

supplier_save_failure(mysqli_stmt_error($stmt), $is_ajax_save);
