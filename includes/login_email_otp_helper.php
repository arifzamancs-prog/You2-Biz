<?php

function ensure_login_email_otp_columns($conn)
{
    $columns = [
        'login_email_otp_status' => "ALTER TABLE users ADD COLUMN login_email_otp_status ENUM('active','inactive') NOT NULL DEFAULT 'inactive'",
    ];

    foreach($columns as $column => $sql){
        $safe_column = mysqli_real_escape_string($conn, $column);
        $check = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE '" . $safe_column . "'");

        if($check && mysqli_num_rows($check) === 0){
            mysqli_query($conn, $sql);
        }
    }
}

function login_email_otp_status($user)
{
    return strtolower(trim((string)($user['login_email_otp_status'] ?? 'inactive'))) === 'active'
        ? 'active'
        : 'inactive';
}

function login_email_otp_enabled($user)
{
    return login_email_otp_status($user) === 'active';
}

function login_email_otp_generate_code()
{
    return (string)random_int(100000, 999999);
}

function login_email_otp_send_code($user, $otp_code)
{
    $name = trim((string)($user['name'] ?? 'Admin'));
    $email = trim((string)($user['email'] ?? ''));

    if($email === ''){
        return [false, 'Email address not found.'];
    }

    $subject = 'Your Login OTP';
    $html_body =
        '<p>Hello ' . htmlspecialchars($name) . ',</p>' .
        '<p>Your login OTP is:</p>' .
        '<h2 style="letter-spacing:4px;">' . htmlspecialchars($otp_code) . '</h2>' .
        '<p>This code will expire in 10 minutes.</p>';

    return smtp_send_mail($email, $name, $subject, $html_body);
}

function login_email_otp_start($user)
{
    $otp_code = login_email_otp_generate_code();

    $_SESSION['pending_login_otp'] = [
        'user_id' => (int)($user['id'] ?? 0),
        'code_hash' => password_hash($otp_code, PASSWORD_DEFAULT),
        'expires_at' => time() + 600,
        'attempts' => 0,
    ];

    return [$otp_code, $_SESSION['pending_login_otp']];
}

function login_email_otp_pending()
{
    return isset($_SESSION['pending_login_otp']) && is_array($_SESSION['pending_login_otp']);
}

function login_email_otp_clear()
{
    unset($_SESSION['pending_login_otp']);
}

function login_email_otp_pending_user_id()
{
    if(!login_email_otp_pending()){
        return 0;
    }

    return (int)($_SESSION['pending_login_otp']['user_id'] ?? 0);
}
