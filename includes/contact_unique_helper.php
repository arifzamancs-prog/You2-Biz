<?php

function contact_unique_value_is_blank($value)
{
    $value = strtolower(trim((string)$value));

    return $value === '' || $value === 'none';
}

function contact_normalize_phone_for_compare($phone)
{
    $phone = preg_replace('/[^0-9]/', '', (string)$phone);

    if(str_starts_with($phone, '8801')){
        return '0' . substr($phone, 3);
    }

    return $phone;
}

function contact_reserved_super_admin_message($field, $value)
{
    $field = $field === 'phone' ? 'phone' : 'email';
    $value = trim((string)$value);

    if(contact_unique_value_is_blank($value)){
        return '';
    }

    if($field === 'email' && function_exists('super_admin_notify_email')){
        if(strtolower($value) === strtolower(super_admin_notify_email())){
            return 'Email is reserved for Super Admin.';
        }
    }

    if($field === 'phone' && defined('SUPER_ADMIN_PROFILE_PHONE')){
        if(contact_normalize_phone_for_compare($value) === contact_normalize_phone_for_compare(SUPER_ADMIN_PROFILE_PHONE)){
            return 'Phone is reserved for Super Admin.';
        }
    }

    return '';
}

function contact_duplicate_message($conn, $field, $value, $exclude_table = '', $exclude_id = 0)
{
    $field = $field === 'phone' ? 'phone' : 'email';
    $value = trim((string)$value);

    if(contact_unique_value_is_blank($value)){
        return '';
    }

    $reserved_message = contact_reserved_super_admin_message($field, $value);

    if($reserved_message !== ''){
        return $reserved_message;
    }

    $tables = [
        'users' => 'User',
        'customers' => 'Customer',
        'suppliers' => 'Supplier',
    ];

    foreach($tables as $table => $label){
        $sql = "SELECT id
                FROM `$table`
                WHERE LOWER(`$field`)=LOWER(?)
                LIMIT 1";

        if($exclude_table === $table && (int)$exclude_id > 0){
            $sql = "SELECT id
                    FROM `$table`
                    WHERE LOWER(`$field`)=LOWER(?)
                    AND id<>?
                    LIMIT 1";
        }

        $stmt = mysqli_prepare($conn, $sql);

        if(!$stmt){
            continue;
        }

        if($exclude_table === $table && (int)$exclude_id > 0){
            $exclude_id = (int)$exclude_id;
            mysqli_stmt_bind_param($stmt, "si", $value, $exclude_id);
        }else{
            mysqli_stmt_bind_param($stmt, "s", $value);
        }

        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if($result && mysqli_num_rows($result) > 0){
            return ucfirst($field) . " already exists.";
        }
    }

    return '';
}

function contact_has_duplicate($conn, $field, $value, $exclude_table = '', $exclude_id = 0, &$message = '')
{
    $message = contact_duplicate_message($conn, $field, $value, $exclude_table, $exclude_id);

    return $message !== '';
}

function contact_company_user_conflict_message($conn, $field, $value, $company_user_id = 0, $exclude_user_id = 0)
{
    $field = $field === 'phone' ? 'phone' : 'email';
    $value = trim((string)$value);
    $company_user_id = (int)$company_user_id;
    $exclude_user_id = (int)$exclude_user_id;

    if(contact_unique_value_is_blank($value) || $company_user_id <= 0){
        return '';
    }

    $sql = "SELECT id
            FROM users
            WHERE LOWER(`{$field}`)=LOWER(?)
            AND (
                id=?
                OR owner_id=?
            )";

    if($exclude_user_id > 0){
        $sql .= " AND id<>?";
    }

    $sql .= " LIMIT 1";

    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return '';
    }

    if($exclude_user_id > 0){
        mysqli_stmt_bind_param($stmt, "siii", $value, $company_user_id, $company_user_id, $exclude_user_id);
    }else{
        mysqli_stmt_bind_param($stmt, "sii", $value, $company_user_id, $company_user_id);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if($result && mysqli_num_rows($result) > 0){
        return ucfirst($field) . " is already used by admin/manager/assistant.";
    }

    return '';
}

function contact_has_company_user_conflict($conn, $field, $value, $company_user_id = 0, &$message = '', $exclude_user_id = 0)
{
    $message = contact_company_user_conflict_message($conn, $field, $value, $company_user_id, $exclude_user_id);

    return $message !== '';
}

function contact_duplicate_message_in_table($conn, $table, $label, $field, $value, $exclude_id = 0, $scope_user_id = 0)
{
    $allowed_tables = ['users', 'customers', 'suppliers'];

    if(!in_array($table, $allowed_tables, true)){
        return '';
    }

    $field = $field === 'phone' ? 'phone' : 'email';
    $value = trim((string)$value);

    if(contact_unique_value_is_blank($value)){
        return '';
    }

    if($table === 'users'){
        $reserved_message = contact_reserved_super_admin_message($field, $value);

        if($reserved_message !== ''){
            return $reserved_message;
        }
    }

    $scope_user_id = (int)$scope_user_id;
    $use_user_scope = in_array($table, ['customers', 'suppliers'], true) && $scope_user_id > 0;

    $sql = "SELECT id
            FROM `$table`
            WHERE LOWER(`$field`)=LOWER(?)";

    if($use_user_scope){
        $sql .= " AND user_id=?";
    }

    if((int)$exclude_id > 0){
        $sql .= " AND id<>?";
    }

    $sql .= " LIMIT 1";

    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return '';
    }

    if($use_user_scope && (int)$exclude_id > 0){
        $exclude_id = (int)$exclude_id;
        mysqli_stmt_bind_param($stmt, "sii", $value, $scope_user_id, $exclude_id);
    }elseif($use_user_scope){
        mysqli_stmt_bind_param($stmt, "si", $value, $scope_user_id);
    }elseif((int)$exclude_id > 0){
        $exclude_id = (int)$exclude_id;
        mysqli_stmt_bind_param($stmt, "si", $value, $exclude_id);
    }else{
        mysqli_stmt_bind_param($stmt, "s", $value);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if($result && mysqli_num_rows($result) > 0){
        return ucfirst($field) . " already exists.";
    }

    return '';
}

function contact_has_duplicate_in_table($conn, $table, $label, $field, $value, $exclude_id = 0, &$message = '', $scope_user_id = 0)
{
    $message = contact_duplicate_message_in_table($conn, $table, $label, $field, $value, $exclude_id, $scope_user_id);

    return $message !== '';
}
