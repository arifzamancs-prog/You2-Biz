<?php

function ensure_email_verification_columns($conn)
{
    $columns = [
        'email_verified' => "ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0",
        'email_verification_token_hash' => "ALTER TABLE users ADD COLUMN email_verification_token_hash VARCHAR(255) NULL",
        'email_verification_expires_at' => "ALTER TABLE users ADD COLUMN email_verification_expires_at DATETIME NULL",
        'email_verified_at' => "ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL",
    ];

    foreach($columns as $column => $sql){
        $check = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE '" . mysqli_real_escape_string($conn, $column) . "'");

        if($check && mysqli_num_rows($check) === 0){
            mysqli_query($conn, $sql);
        }
    }

    $status_column = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'status'");
    $status_info = $status_column ? mysqli_fetch_assoc($status_column) : null;
    $status_type = strtolower((string)($status_info['Type'] ?? ''));

    if(
        $status_type !== '' &&
        strpos($status_type, 'pending_verification') === false
    ){
        mysqli_query(
            $conn,
            "ALTER TABLE users
             MODIFY COLUMN status
             ENUM('active','inactive','pending_verification')
             NOT NULL DEFAULT 'active'"
        );
    }
}

function email_verification_create_token()
{
    return bin2hex(random_bytes(32));
}

function email_verification_hash($token)
{
    return hash('sha256', (string)$token);
}

function email_verification_expiry()
{
    return date('Y-m-d H:i:s', strtotime('+24 hours'));
}

function email_verification_activate_account($conn, $token)
{
    ensure_email_verification_columns($conn);

    $token_hash = email_verification_hash($token);
    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, name, status, email_verified, email_verification_expires_at
         FROM users
         WHERE email_verification_token_hash=?
         LIMIT 1"
    );

    if(!$stmt){
        return ['success' => false, 'message' => 'Verification failed. Please try again.'];
    }

    mysqli_stmt_bind_param($stmt, "s", $token_hash);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = $result ? mysqli_fetch_assoc($result) : null;

    if(!$user){
        return ['success' => false, 'message' => 'Invalid verification link.'];
    }

    if((int)($user['email_verified'] ?? 0) === 1 && $user['status'] === 'active'){
        return ['success' => true, 'message' => 'Your account is already verified.'];
    }

    if(
        !empty($user['email_verification_expires_at']) &&
        strtotime($user['email_verification_expires_at']) < time()
    ){
        return ['success' => false, 'message' => 'Verification link has expired. Please contact support.'];
    }

    $update_stmt = mysqli_prepare(
        $conn,
        "UPDATE users
         SET email_verified=1,
             email_verified_at=NOW(),
             email_verification_token_hash=NULL,
             email_verification_expires_at=NULL,
             status='active'
         WHERE id=?"
    );

    if(!$update_stmt){
        return ['success' => false, 'message' => 'Verification failed. Please try again.'];
    }

    mysqli_stmt_bind_param($update_stmt, "i", $user['id']);
    mysqli_stmt_execute($update_stmt);

    return ['success' => true, 'message' => 'Email verified successfully. You can now login.'];
}
