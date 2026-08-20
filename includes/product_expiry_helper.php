<?php

function ensure_product_expiry_column($conn)
{
    $result = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'expired_on'");

    if($result && mysqli_num_rows($result) === 0){
        mysqli_query(
            $conn,
            "ALTER TABLE products
             ADD COLUMN expired_on DATE NULL AFTER sale_price"
        );
    }
}

function ensure_product_management_columns($conn)
{
    ensure_product_expiry_column($conn);

    $result = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'product_expiry_option'");

    if($result && mysqli_num_rows($result) === 0){
        mysqli_query(
            $conn,
            "ALTER TABLE users
             ADD COLUMN product_expiry_option VARCHAR(20) NOT NULL DEFAULT 'active'"
        );
    }
}

function normalize_product_expiry_option($option)
{
    return $option === 'inactive' ? 'inactive' : 'active';
}

function current_product_expiry_option($conn)
{
    if(is_super_admin_user()){
        return normalize_product_expiry_option(
            $_SESSION['product_expiry_option'] ?? 'active'
        );
    }

    ensure_product_management_columns($conn);

    $user_id = (int)($_SESSION['user_id'] ?? 0);

    if($user_id <= 0){
        return 'active';
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT product_expiry_option
         FROM users
         WHERE id=?
         LIMIT 1"
    );

    if(!$stmt){
        return 'active';
    }

    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    return normalize_product_expiry_option($row['product_expiry_option'] ?? 'active');
}

function is_product_expiry_enabled($conn)
{
    return current_product_expiry_option($conn) === 'active';
}

function save_product_expiry_option($conn, $option)
{
    $option = normalize_product_expiry_option($option);

    if(is_super_admin_user()){
        $_SESSION['product_expiry_option'] = $option;
        return true;
    }

    ensure_product_management_columns($conn);

    $user_id = (int)($_SESSION['user_id'] ?? 0);

    if($user_id <= 0){
        return false;
    }

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE users
         SET product_expiry_option=?
         WHERE id=?"
    );

    if(!$stmt){
        return false;
    }

    mysqli_stmt_bind_param($stmt, "si", $option, $user_id);

    return mysqli_stmt_execute($stmt);
}
