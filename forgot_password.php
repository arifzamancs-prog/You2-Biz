<?php

session_start();

require_once 'includes/db.php';
require_once 'includes/smtp_mailer.php';
require_once 'includes/app_config.php';
require_once 'includes/branding_helper.php';

$auth_logo_url = branding_logo_url($conn);
$auth_favicon_url = branding_favicon_url($conn);

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $login = trim($_POST['login'] ?? '');

    if($login === ''){

        $message = "Please enter your username, email or phone.";
        $message_type = "danger";

    }else{

        $sql = "SELECT id, name, email, status
                FROM users
                WHERE username=?
                OR email=?
                OR phone=?
                LIMIT 1";

        $stmt = mysqli_prepare($conn, $sql);

        mysqli_stmt_bind_param(
            $stmt,
            "sss",
            $login,
            $login,
            $login
        );

        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($result);

        if($user && $user['status'] === 'active'){

            $token = bin2hex(random_bytes(32));
            $token_hash = hash('sha256', $token);
            $expires_at = date('Y-m-d H:i:s', time() + 3600);

            mysqli_query(
                $conn,
                "UPDATE password_resets
                 SET used_at = NOW()
                 WHERE user_id = " . (int)$user['id'] . "
                 AND used_at IS NULL"
            );

            $insert_sql = "INSERT INTO password_resets
                           (
                               user_id,
                               token,
                               expires_at
                           )
                           VALUES
                           (
                               ?,
                               ?,
                               ?
                           )";

            $insert_stmt = mysqli_prepare($conn, $insert_sql);

            mysqli_stmt_bind_param(
                $insert_stmt,
                "iss",
                $user['id'],
                $token_hash,
                $expires_at
            );

            mysqli_stmt_execute($insert_stmt);

            $reset_link = app_url('reset_password.php?token=' . urlencode($token));

            $body = '
                <div style="font-family:Arial,sans-serif;background:#f4f6f9;padding:24px;">
                    <div style="max-width:560px;margin:auto;background:#ffffff;border-radius:10px;padding:28px;">
                        <h2 style="margin-top:0;color:#17202b;">Reset Your You2 Biz Password</h2>
                        <p>Hello ' . htmlspecialchars($user['name']) . ',</p>
                        <p>We received a request to reset your password. Click the button below to set a new password.</p>
                        <p style="margin:28px 0;">
                            <a href="' . htmlspecialchars($reset_link) . '"
                               style="background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:8px;display:inline-block;">
                               Reset Password
                            </a>
                        </p>
                        <p>This link will expire in 1 hour.</p>
                        <p>If you did not request this, you can ignore this email.</p>
                    </div>
                </div>';

            [$sent, $error] = smtp_send_mail(
                $user['email'],
                $user['name'],
                'Reset Your You2 Biz Password',
                $body
            );

            if($sent){
                $message = "Password reset link has been sent to your email.";
                $message_type = "success";
            }else{
                $message = "Reset link was created, but email could not be sent. Please check SMTP settings.";
                $message_type = "danger";
            }

        }else{

            $message = "If the account exists, a reset link will be sent.";
            $message_type = "success";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Forgot Password - You2 Biz</title>
<link rel="icon" type="image/png" sizes="32x32" href="<?= htmlspecialchars($auth_favicon_url); ?>">
<link rel="shortcut icon" type="image/png" href="<?= htmlspecialchars($auth_favicon_url); ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($auth_favicon_url); ?>">
<link rel="stylesheet" href="adminlte/plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="adminlte/dist/css/adminlte.min.css">
<style>
body.auth-page{background:#eef2f7;min-height:100vh;}
.auth-shell{align-items:center;display:flex;justify-content:center;min-height:100vh;padding:24px;}
.auth-box{background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(15,23,42,.16);max-width:460px;padding:38px;width:100%;}
.auth-logo{align-items:center;display:flex;gap:12px;font-size:22px;font-weight:700;margin-bottom:30px;}
.auth-logo-mark{align-items:center;display:flex;height:50px;justify-content:center;width:50px;}
.auth-logo-image{border-radius:50%;height:50px;object-fit:cover;width:50px;}
.auth-box h1{color:#111827;font-size:28px;font-weight:700;margin:0 0 8px;}
.auth-subtitle{color:#6b7280;margin-bottom:24px;}
.auth-input{border:1px solid #d8dee8;border-radius:9px;height:46px;}
.auth-submit{border-radius:9px;font-weight:600;height:46px;}
.auth-link{color:#2563eb;font-weight:600;}
</style>
</head>
<body class="auth-page">
<div class="auth-shell">
    <div class="auth-box">
        <div class="auth-logo">
            <span class="auth-logo-mark"><img src="<?= htmlspecialchars($auth_logo_url); ?>" alt="You2 Biz Logo" class="auth-logo-image"></span>
            <span>You2 Biz</span>
        </div>

        <h1>Forgot password?</h1>
        <div class="auth-subtitle">Enter your username, email or phone. We will send a secure reset link.</div>

        <?php if($message){ ?>
            <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                <?= htmlspecialchars($message); ?>
            </div>
        <?php } ?>

        <form method="post">
            <div class="form-group">
                <label>Username, Email or Phone</label>
                <input
                    type="text"
                    name="login"
                    class="form-control auth-input"
                    required>
            </div>

            <button type="submit" class="btn btn-primary btn-block auth-submit">
                Send Reset Link
            </button>
        </form>

        <div class="text-center mt-4">
            <a href="login.php" class="auth-link">Back to login</a>
        </div>
    </div>
</div>
</body>
</html>
