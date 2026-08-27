<?php

session_start();

require_once 'includes/db.php';
require_once 'includes/smtp_mailer.php';
require_once 'includes/app_config.php';
require_once 'includes/branding_helper.php';

$auth_logo_url = branding_logo_url($conn);
$auth_favicon_url = branding_favicon_url($conn);
$auth_has_custom_logo = branding_has_custom_logo($conn);
$auth_brand_icon_url = $auth_favicon_url !== '' ? $auth_favicon_url : $auth_logo_url;

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
body.auth-page{
    background:
        radial-gradient(circle at 15% 20%, rgba(34,197,94,.18), transparent 30%),
        radial-gradient(circle at 85% 18%, rgba(59,130,246,.18), transparent 34%),
        radial-gradient(circle at 50% 100%, rgba(14,165,233,.14), transparent 38%),
        linear-gradient(135deg, #edf4ff 0%, #e4edf8 46%, #d8e5f2 100%);
    min-height:100vh;
}

.auth-shell{
    align-items:center;
    display:flex;
    justify-content:center;
    min-height:100vh;
    padding:24px;
}

.auth-card{
    background:rgba(255,255,255,.9);
    border:1px solid rgba(255,255,255,.58);
    border-radius:26px;
    box-shadow:0 24px 80px rgba(15,23,42,.16);
    backdrop-filter:blur(18px);
    display:grid;
    grid-template-columns:minmax(280px, .86fr) minmax(0, 1.14fr);
    max-width:1020px;
    overflow:hidden;
    width:100%;
}

.auth-panel{
    background:
        linear-gradient(150deg, rgba(8,15,31,.9) 0%, rgba(14,28,54,.82) 36%, rgba(22,101,52,.54) 100%);
    border-right:1px solid rgba(255,255,255,.12);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.12);
    color:#fff;
    display:flex;
    flex-direction:column;
    justify-content:center;
    padding:32px 28px;
    position:relative;
    isolation:isolate;
}

.auth-panel::before{
    background:
        radial-gradient(circle at 14% 18%, rgba(59,130,246,.42), transparent 26%),
        radial-gradient(circle at 78% 22%, rgba(255,255,255,.16), transparent 18%),
        radial-gradient(circle at 82% 82%, rgba(34,197,94,.26), transparent 34%);
    content:"";
    inset:0;
    opacity:.95;
    position:absolute;
    z-index:-1;
}

.auth-panel::after{
    background:
        linear-gradient(180deg, rgba(255,255,255,.16), transparent 22%, rgba(255,255,255,.05) 100%),
        linear-gradient(115deg, transparent 0 56%, rgba(255,255,255,.08) 56% 62%, transparent 62% 100%);
    content:"";
    inset:1px;
    position:absolute;
    z-index:-1;
}

.auth-logo{
    align-items:center;
    display:flex;
    gap:16px;
    font-size:22px;
    font-weight:700;
    margin-bottom:18px;
}

.auth-logo-mark{
    align-items:center;
    background:rgba(255,255,255,.16);
    border:1px solid rgba(255,255,255,.2);
    border-radius:18px;
    box-shadow:0 12px 30px rgba(15,23,42,.22);
    display:flex;
    height:74px;
    justify-content:center;
    width:74px;
}

.auth-logo-image{
    filter:drop-shadow(0 16px 28px rgba(8,15,31,.28)) saturate(1.08) contrast(1.06);
    height:112px;
    mix-blend-mode:multiply;
    object-fit:contain;
    opacity:.96;
    width:auto;
    max-width:270px;
}

.auth-logo-title{
    display:block;
    filter:drop-shadow(0 8px 18px rgba(8,15,31,.2));
    font-size:24px;
    letter-spacing:.01em;
}

.auth-logo--image-only{
    display:block;
    position:relative;
    padding:14px 18px 10px;
    width:max-content;
}

.auth-logo--image-only .auth-logo-title{
    display:none;
}

.auth-logo--image-only .auth-logo-image{
    display:block;
    margin-left:-10px;
}

.auth-logo--image-only::before{
    background:radial-gradient(circle, rgba(255,255,255,.88) 0%, rgba(255,255,255,.44) 42%, transparent 72%);
    border-radius:999px;
    content:"";
    height:180px;
    left:16px;
    position:absolute;
    top:4px;
    width:180px;
    z-index:-1;
}

.auth-logo--image-only::after{
    background:linear-gradient(135deg, rgba(59,130,246,.26), rgba(34,197,94,.14));
    border:1px solid rgba(255,255,255,.18);
    border-radius:24px;
    content:"";
    inset:-14px -18px -12px -18px;
    position:absolute;
    z-index:-2;
}

.auth-logo--image-only .auth-logo-image,
.auth-logo--image-only .auth-logo-mark{
    position:relative;
    z-index:1;
}

.auth-brand-icon{
    border-radius:14px;
    height:42px;
    object-fit:contain;
    opacity:.88;
    width:42px;
}

.auth-panel h1{
    font-size:29px;
    font-weight:700;
    letter-spacing:-.02em;
    line-height:1.18;
    margin:18px 0 0;
    max-width:380px;
}

.auth-form{
    padding:42px 48px;
}

.auth-form h2{
    color:#111827;
    font-size:28px;
    font-weight:700;
    margin:0 0 8px;
}

.auth-subtitle{
    color:#6b7280;
    margin-bottom:24px;
}

.form-group label{
    color:#111827;
    font-size:14px;
    font-weight:600;
    margin-bottom:7px;
}

.auth-input{
    border:1px solid #d8dee8;
    border-radius:9px;
    height:44px;
}

.auth-input:focus{
    border-color:#2563eb;
    box-shadow:0 0 0 .2rem rgba(37,99,235,.12);
}

.auth-icon{
    background:#f8fafc;
    border:1px solid #d8dee8;
    border-left:0;
    border-radius:0 9px 9px 0;
    color:#64748b;
}

.auth-submit{
    border-radius:9px;
    font-weight:600;
    height:46px;
}

.auth-link{
    color:#2563eb;
    font-weight:600;
}

.auth-mobile-hero{
    display:none;
}

@media (max-width: 767.98px){
    body.auth-page{
        background:linear-gradient(180deg, #dbeafe 0%, #bfdbfe 100%);
        overflow-x:hidden;
    }

    .auth-shell{
        align-items:flex-start;
        min-height:100dvh;
        padding:8px 8px 6px;
    }

    .auth-card{
        background:linear-gradient(180deg, #eff6ff 0%, #dbeafe 100%);
        border:1px solid rgba(37,99,235,.18);
        border-radius:14px;
        box-shadow:0 12px 28px rgba(37,99,235,.14);
        grid-template-columns:1fr;
        min-height:auto;
        width:100%;
    }

    .auth-panel{
        display:flex;
        justify-content:center;
        min-height:154px;
        padding:14px 12px 10px;
        text-align:center;
    }

    .auth-logo{
        justify-content:center;
        margin-bottom:12px;
    }

    .auth-logo--image-only{
        padding:8px 10px 6px;
    }

    .auth-logo--image-only::before{
        height:124px;
        left:10px;
        top:0;
        width:124px;
    }

    .auth-logo--image-only::after{
        border-radius:18px;
        inset:-8px -10px -8px -10px;
    }

    .auth-logo--image-only .auth-logo-image{
        height:68px;
        margin-left:0;
        max-width:164px;
    }

    .auth-logo-mark{
        border-radius:14px;
        height:52px;
        width:52px;
    }

    .auth-brand-icon{
        height:30px;
        width:30px;
    }

    .auth-logo-title{
        font-size:18px;
    }

    .auth-panel h1{
        font-size:19px;
        line-height:1.2;
        margin-top:8px;
        max-width:100%;
    }

    .auth-form{
        display:flex;
        flex-direction:column;
        justify-content:center;
        min-height:auto;
        padding:16px 12px 12px;
    }

    .auth-mobile-hero{
        display:none;
    }

    .form-group label{
        color:#1e3a8a;
        font-size:13px;
        font-weight:600;
        margin-bottom:7px;
    }

    .auth-subtitle{
        color:#475569;
        font-size:13px;
        line-height:1.5;
        margin-bottom:12px;
    }

    .auth-input{
        background:rgba(255,255,255,.72);
        border-color:rgba(37,99,235,.16);
        color:#0f172a;
        height:42px;
    }

    .auth-icon{
        background:rgba(219,234,254,.92);
        border-color:rgba(37,99,235,.16);
        color:#1d4ed8;
    }

    .alert{
        border-radius:12px;
        font-size:13px;
        margin-bottom:16px;
    }

    .auth-submit{
        background:#2563eb;
        border-color:#2563eb;
        border-radius:12px;
        height:42px;
        margin-top:0;
    }

    .text-center.mt-4{
        margin-top:12px !important;
    }
}

@media (max-width: 420px){
    .auth-shell{
        padding:6px 6px 4px;
    }

    .auth-card{
        border-radius:12px;
    }

    .auth-panel{
        min-height:138px;
        padding:12px 10px 8px;
    }

    .auth-panel h1{
        font-size:17px;
    }

    .auth-form{
        padding:14px 10px 10px;
    }

    .auth-form h2{
        font-size:21px;
    }

    .form-group{
        margin-bottom:12px;
    }
}
</style>
</head>
<body class="auth-page">
<div class="auth-shell">
    <div class="auth-card">
        <div class="auth-panel">
            <div>
                <?php if($auth_has_custom_logo){ ?>
                    <div class="auth-logo auth-logo--image-only">
                        <img src="<?= htmlspecialchars($auth_logo_url); ?>" alt="You2 Biz Logo" class="auth-logo-image">
                    </div>
                <?php }else{ ?>
                    <div class="auth-logo">
                        <span class="auth-logo-mark">
                            <img src="<?= htmlspecialchars($auth_brand_icon_url); ?>" alt="You2 Biz Icon" class="auth-brand-icon">
                        </span>
                        <span class="auth-logo-title">You2 Biz</span>
                    </div>
                <?php } ?>

                <h1>Empower your business<br>with smarter financial control !</h1>
            </div>
        </div>

        <div class="auth-form">
            <h2>Forgot password?</h2>
            <div class="auth-subtitle">Enter your username, email or phone. We will send a secure reset link.</div>

            <?php if($message){ ?>
                <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                    <?= htmlspecialchars($message); ?>
                </div>
            <?php } ?>

            <form method="post">
                <div class="form-group">
                    <label>Username, Email or Phone</label>
                    <div class="input-group">
                        <input
                            type="text"
                            name="login"
                            class="form-control auth-input"
                            placeholder="Enter username, email or phone"
                            required>
                        <div class="input-group-append">
                            <div class="input-group-text auth-icon">
                                <span class="fas fa-user-shield"></span>
                            </div>
                        </div>
                    </div>
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
</div>
</body>
</html>
