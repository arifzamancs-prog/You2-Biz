<?php

session_start();

require_once 'includes/db.php';
require_once 'includes/app_config.php';
require_once 'includes/branding_helper.php';
require_once 'includes/login_email_otp_helper.php';
require_once 'includes/login_session_helper.php';

ensure_login_email_otp_columns($conn);

if(!login_email_otp_pending()){
    header("Location: login.php");
    exit;
}

$auth_favicon_url = branding_favicon_url($conn);
$auth_logo_url = branding_logo_url($conn);
$auth_brand_icon_url = $auth_favicon_url !== '' ? $auth_favicon_url : $auth_logo_url;

$message = '';
$message_type = 'danger';
$pending_user_id = login_email_otp_pending_user_id();
$is_super_admin_otp = !empty($_SESSION['pending_login_otp']['is_super_admin']);

if(!$is_super_admin_otp && $pending_user_id <= 0){
    login_email_otp_clear();
    header("Location: login.php");
    exit;
}

if($is_super_admin_otp){
    $user = [
        'id' => -1,
        'name' => defined('SUPER_ADMIN_NAME') ? SUPER_ADMIN_NAME : 'Super Admin',
        'role' => 'super_admin',
    ];
} else {
    $user_stmt = mysqli_prepare(
        $conn,
        "SELECT * FROM users WHERE id=? LIMIT 1"
    );

    mysqli_stmt_bind_param($user_stmt, "i", $pending_user_id);
    mysqli_stmt_execute($user_stmt);
    $user_result = mysqli_stmt_get_result($user_stmt);
    $user = $user_result ? mysqli_fetch_assoc($user_result) : null;
}

if(!$user){
    login_email_otp_clear();
    header("Location: login.php");
    exit;
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $otp_code = trim((string)($_POST['otp_code'] ?? ''));
    $pending = $_SESSION['pending_login_otp'] ?? [];
    $expires_at = (int)($pending['expires_at'] ?? 0);
    $code_hash = (string)($pending['code_hash'] ?? '');
    $attempts = (int)($pending['attempts'] ?? 0);

    if($expires_at < time()){
        login_email_otp_clear();
        $_SESSION['verification_message'] = 'OTP expired. Please login again.';
        $_SESSION['verification_message_type'] = 'danger';
        header("Location: login.php");
        exit;
    }

    if($otp_code === '' || !preg_match('/^\d{6}$/', $otp_code)){
        $message = 'Enter a valid 6-digit OTP.';
    } elseif(!password_verify($otp_code, $code_hash)) {
        $_SESSION['pending_login_otp']['attempts'] = $attempts + 1;

        if($_SESSION['pending_login_otp']['attempts'] >= 5){
            login_email_otp_clear();
            $_SESSION['verification_message'] = 'Too many invalid OTP attempts. Please login again.';
            $_SESSION['verification_message_type'] = 'danger';
            header("Location: login.php");
            exit;
        }

        $message = 'Invalid OTP.';
    } else {
        if($is_super_admin_otp){
            $_SESSION['super_admin'] = true;
            $_SESSION['user_role'] = 'super_admin';
            $_SESSION['login_user_id'] = 0;
            $_SESSION['user_id'] = 0;
            $_SESSION['login_name'] = SUPER_ADMIN_NAME;
            $_SESSION['user_name'] = SUPER_ADMIN_NAME;
            $_SESSION['avatar'] = SUPER_ADMIN_PROFILE_AVATAR;
            $_SESSION['login_avatar'] = SUPER_ADMIN_PROFILE_AVATAR;
            login_email_otp_clear();
            header("Location: super_admin/index.php");
            exit;
        }

        $role = trim((string)($user['role'] ?? ''));

        if($role === '' || !in_array($role, ['admin', 'manager'], true)) {
            $role = 'admin';
        }

        $owner_id = (int)($user['owner_id'] ?: $user['id']);
        $account = $user;

        if($role === 'manager'){
            $account_stmt = mysqli_prepare(
                $conn,
                "SELECT * FROM users WHERE id=? LIMIT 1"
            );
            mysqli_stmt_bind_param($account_stmt, "i", $owner_id);
            mysqli_stmt_execute($account_stmt);
            $account_result = mysqli_stmt_get_result($account_stmt);
            $account = $account_result ? (mysqli_fetch_assoc($account_result) ?: $user) : $user;
        }

        complete_user_login($conn, $user, $account, $owner_id, $role);
        login_email_otp_clear();
        header("Location: dashboard.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login OTP - You2 Biz</title>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= htmlspecialchars($auth_favicon_url); ?>">
    <link rel="shortcut icon" type="image/png" href="<?= htmlspecialchars($auth_favicon_url); ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($auth_favicon_url); ?>">
    <link rel="stylesheet" href="adminlte/plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="adminlte/dist/css/adminlte.min.css">
</head>
<body class="hold-transition login-page" style="background:#eef2f7;">
<div class="login-box" style="width:420px;max-width:92vw;">
    <div class="card card-outline card-primary">
        <div class="card-body login-card-body">
            <div class="text-center mb-3">
                <img src="<?= htmlspecialchars($auth_brand_icon_url); ?>" alt="Brand Icon" style="width:56px;height:56px;border-radius:14px;object-fit:cover;">
                <h3 class="mt-3 mb-1">Email OTP Verification</h3>
                <p class="text-muted mb-0">Enter the 6-digit code sent to your email.</p>
            </div>

            <?php if($message !== ''){ ?>
                <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                    <?= htmlspecialchars($message); ?>
                </div>
            <?php } ?>

            <form method="post">
                <div class="form-group">
                    <label>OTP Code</label>
                    <input type="text" name="otp_code" class="form-control" maxlength="6" inputmode="numeric" pattern="\d{6}" placeholder="Enter 6-digit OTP" required>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Verify OTP</button>
                <a href="login.php" class="btn btn-secondary btn-block">Back to Login</a>
            </form>
        </div>
    </div>
</div>
</body>
</html>
