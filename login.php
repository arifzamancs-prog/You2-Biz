<?php

session_start();

require_once 'includes/db.php';
require_once 'includes/super_admin_config.php';
require_once 'includes/manager_access_helper.php';
require_once 'includes/smtp_mailer.php';
require_once 'includes/app_config.php';
require_once 'includes/signup_message_helper.php';
require_once 'includes/branding_helper.php';
require_once 'includes/login_email_otp_helper.php';
require_once 'includes/login_session_helper.php';

ensure_manager_access_columns($conn);
ensure_signup_message_settings_table($conn);
ensure_login_email_otp_columns($conn);
signup_message_send_trial_warnings($conn);

function start_super_admin_session()
{
    $_SESSION['super_admin'] = true;
    $_SESSION['user_role'] = 'super_admin';
    $_SESSION['login_user_id'] = 0;
    $_SESSION['user_id'] = 0;
    $_SESSION['login_name'] = SUPER_ADMIN_NAME;
    $_SESSION['user_name'] = SUPER_ADMIN_NAME;
    $_SESSION['avatar'] = SUPER_ADMIN_PROFILE_AVATAR;
    $_SESSION['login_avatar'] = SUPER_ADMIN_PROFILE_AVATAR;
}

$auth_logo_url = branding_logo_url($conn);
$auth_favicon_url = branding_favicon_url($conn);
$auth_has_custom_logo = branding_has_custom_logo($conn);
$auth_brand_icon_url = $auth_favicon_url !== '' ? $auth_favicon_url : $auth_logo_url;

$message = '';
$message_type = 'danger';
$full_version_message = 'Please call +8801977592783 for subscription.';

if(isset($_GET['registered']) && $_GET['registered'] === 'verify_email'){
    $message = 'Registration successful. Please verify your email to activate your account.';
    $message_type = 'success';
}

if(isset($_GET['registered']) && $_GET['registered'] === 'active'){
    $message = 'Registration successful. Your account is active. You can login now.';
    $message_type = 'success';
}

if(isset($_SESSION['verification_message'])){
    $message = $_SESSION['verification_message'];
    $message_type = $_SESSION['verification_message_type'] ?? 'danger';
    unset($_SESSION['verification_message'], $_SESSION['verification_message_type']);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $login    = trim($_POST['login']);
    $password = $_POST['password'];

    if (is_super_admin_login($login, $password)) {
        if(function_exists('super_admin_login_email_otp_status') && super_admin_login_email_otp_status() === 'active'){
            $super_admin_user = [
                'id' => -1,
                'name' => SUPER_ADMIN_NAME,
                'email' => function_exists('super_admin_notify_email') ? super_admin_notify_email() : '',
            ];

            list($otp_code) = login_email_otp_start($super_admin_user);
            $_SESSION['pending_login_otp']['is_super_admin'] = true;

            $send_result = login_email_otp_send_code($super_admin_user, $otp_code);

            if(!$send_result[0]){
                login_email_otp_clear();
                $message = 'OTP email could not be sent.';
            } else {
                header("Location: login_otp.php");
                exit;
            }
        } else {
            start_super_admin_session();
            header("Location: super_admin/index.php");
            exit;
        }
    }

    $sql = "SELECT *
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

    if ($user = mysqli_fetch_assoc($result)) {

        if (password_verify($password, $user['password'])) {

            if ($user['status'] === 'pending_verification') {

                $message = 'Please verify your email to activate your account.';

            } elseif ($user['status'] != 'active') {

                $message = $full_version_message;

            } else {

                $role = trim((string)($user['role'] ?? ''));

                if ($role === '' || !in_array($role, ['admin', 'manager'], true)) {
                    $role = 'admin';
                }

                $owner_id = (int)($user['owner_id'] ?: $user['id']);

                if ($role === 'manager' && (int)($user['owner_id'] ?? 0) <= 0) {
                    $fallback_owner_result = mysqli_query(
                        $conn,
                        "SELECT id
                         FROM users
                         WHERE role='admin'
                         AND status='active'
                         ORDER BY id DESC
                         LIMIT 1"
                    );

                    $fallback_owner = $fallback_owner_result
                        ? mysqli_fetch_assoc($fallback_owner_result)
                        : null;

                    if ($fallback_owner && (int)$fallback_owner['id'] > 0) {
                        $owner_id = (int)$fallback_owner['id'];

                        $owner_update_stmt = mysqli_prepare(
                            $conn,
                            "UPDATE users
                             SET owner_id=?
                             WHERE id=?
                             AND role='manager'"
                        );

                        if ($owner_update_stmt) {
                            mysqli_stmt_bind_param(
                                $owner_update_stmt,
                                "ii",
                                $owner_id,
                                $user['id']
                            );
                            mysqli_stmt_execute($owner_update_stmt);
                        }
                    }
                }

                $account = $user;

                if ($role === 'manager') {
                    $account_sql = "SELECT *
                                    FROM users
                                    WHERE id=?
                                    LIMIT 1";

                    $account_stmt = mysqli_prepare($conn, $account_sql);

                    mysqli_stmt_bind_param(
                        $account_stmt,
                        "i",
                        $owner_id
                    );

                    mysqli_stmt_execute($account_stmt);

                    $account_result = mysqli_stmt_get_result($account_stmt);
                    $account = mysqli_fetch_assoc($account_result) ?: $user;
                }

                if (!$account || $account['status'] !== 'active') {

                    $message = $full_version_message;

                } elseif (in_array($account['subscription_status'] ?? 'active', ['blocked','expired'], true)) {

                    $message = $full_version_message;

                } else {

                if($role === 'admin' && login_email_otp_enabled($user)){
                    list($otp_code) = login_email_otp_start($user);
                    $send_result = login_email_otp_send_code($user, $otp_code);

                    if(!$send_result[0]){
                        login_email_otp_clear();
                        $message = 'OTP email could not be sent.';
                    } else {
                        header("Location: login_otp.php");
                        exit;
                    }
                } else {
                    complete_user_login($conn, $user, $account, $owner_id, $role);
                    header("Location: dashboard.php");
                    exit;
                }
                }
            }

        } else {

            $message = "Invalid Password";
        }

    } else {

        $message = "User Not Found";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1">

    <title>
Login - You2 Biz
    </title>

    <link rel="icon" type="image/png" sizes="32x32" href="<?= htmlspecialchars($auth_favicon_url); ?>">
    <link rel="shortcut icon" type="image/png" href="<?= htmlspecialchars($auth_favicon_url); ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($auth_favicon_url); ?>">

    <link rel="stylesheet"
          href="adminlte/plugins/fontawesome-free/css/all.min.css">

    <link rel="stylesheet"
          href="adminlte/dist/css/adminlte.min.css">

</head>

<style>
    body.auth-page{
        background:#eef2f7;
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
        background:#fff;
        border-radius:14px;
        box-shadow:0 20px 60px rgba(15,23,42,.16);
        display:grid;
        grid-template-columns:1fr 1fr;
        max-width:980px;
        min-height:560px;
        overflow:hidden;
        width:100%;
    }

    .auth-panel{
        background:#17202b;
        color:#fff;
        display:flex;
        flex-direction:column;
        justify-content:space-between;
        padding:42px;
    }

    .auth-logo{
        align-items:center;
        display:flex;
        gap:12px;
        font-size:22px;
        font-weight:700;
    }

    .auth-logo-mark{
        align-items:center;
        background:#2563eb;
        border-radius:10px;
        display:flex;
        height:42px;
        justify-content:center;
        width:42px;
    }

    .auth-logo-image{
        border-radius:10px;
        height:42px;
        object-fit:contain;
        width:42px;
    }

    .auth-brand-icon{
        border-radius:10px;
        height:24px;
        object-fit:contain;
        width:24px;
    }

    .auth-panel h1{
        font-size:34px;
        font-weight:700;
        line-height:1.18;
        margin:36px 0 14px;
    }

    .auth-panel p{
        color:rgba(255,255,255,.72);
        font-size:15px;
        line-height:1.7;
        margin:0;
    }

    .auth-points{
        display:grid;
        gap:12px;
        margin-top:34px;
    }

    .auth-point{
        align-items:center;
        color:rgba(255,255,255,.82);
        display:flex;
        gap:10px;
        font-size:14px;
    }

    .auth-point i{
        color:#60a5fa;
    }

    .auth-support{
        align-items:center;
        background:rgba(255,255,255,.08);
        border:1px solid rgba(255,255,255,.1);
        border-radius:10px;
        color:rgba(255,255,255,.9);
        display:flex;
        gap:10px;
        margin-top:24px;
        padding:12px 14px;
    }

    .auth-support i{
        color:#60a5fa;
    }

    .auth-support span{
        display:block;
        font-size:12px;
        letter-spacing:.04em;
        text-transform:uppercase;
    }

    .auth-support strong{
        display:block;
        font-size:15px;
        font-weight:700;
        margin-top:2px;
    }

    .auth-support a{
        color:#fff;
        display:inline-block;
        font-size:15px;
        font-weight:700;
        margin-top:2px;
        text-decoration:none;
    }

    .auth-support a:hover{
        color:#bfdbfe;
        text-decoration:underline;
    }

    .auth-form{
        display:flex;
        flex-direction:column;
        justify-content:center;
        padding:48px;
    }

    .auth-form h2{
        color:#111827;
        font-size:28px;
        font-weight:700;
        margin:0 0 8px;
    }

    .auth-subtitle{
        color:#6b7280;
        margin-bottom:28px;
    }

    .auth-input{
        border:1px solid #d8dee8;
        border-radius:9px;
        height:46px;
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

    .auth-footer-note{
        color:#64748b;
        font-size:13px;
        line-height:1.6;
        margin-top:22px;
        text-align:center;
    }

    .auth-footer-note strong{
        color:#334155;
        display:block;
        font-weight:700;
    }

    .auth-footer-note a{
        color:#2563eb;
        text-decoration:none;
    }

    .auth-footer-note a:hover{
        text-decoration:underline;
    }

    .auth-mobile-hero{
        display:none;
    }

    @media (max-width: 767.98px){
        body.auth-page{
            background:linear-gradient(180deg, #dbeafe 0%, #bfdbfe 100%);
        }

        .auth-shell{
            align-items:flex-start;
            padding:16px 16px 8px;
        }

        .auth-card{
            background:linear-gradient(180deg, #eff6ff 0%, #dbeafe 100%);
            border:1px solid rgba(37,99,235,.18);
            border-radius:18px;
            box-shadow:0 14px 34px rgba(37,99,235,.14);
            grid-template-columns:1fr;
            min-height:calc(100vh - 24px);
            width:100%;
        }

        .auth-panel{
            display:none;
        }

        .auth-form{
            display:flex;
            flex-direction:column;
            justify-content:center;
            min-height:calc(100vh - 24px);
            padding:22px 18px 14px;
        }

        .auth-mobile-hero{
            display:block;
            margin-bottom:20px;
        }

        .auth-mobile-brand{
            align-items:center;
            color:#0f172a;
            display:flex;
            gap:12px;
            margin-bottom:14px;
        }

        .auth-mobile-brand-mark{
            align-items:center;
            background:#2563eb;
            border-radius:12px;
            color:#fff;
            display:flex;
            height:44px;
            justify-content:center;
            width:44px;
        }

        .auth-mobile-brand-image{
            border-radius:12px;
            height:44px;
            object-fit:contain;
            width:44px;
        }

        .auth-mobile-brand-title{
            font-size:19px;
            font-weight:700;
            line-height:1.1;
            margin:0;
        }

        .auth-mobile-brand-subtitle{
            color:#64748b;
            font-size:13px;
            margin:2px 0 0;
        }

        .auth-mobile-hero-card{
            background:rgba(255,255,255,.34);
            border:1px solid rgba(37,99,235,.16);
            border-radius:14px;
            color:#0f172a;
            padding:14px 15px;
        }

        .auth-mobile-hero-card h3{
            font-size:15px;
            font-weight:600;
            line-height:1.45;
            margin:0;
        }

        .auth-mobile-hero-card p{
            color:#64748b;
            font-size:12px;
            line-height:1.55;
            margin:6px 0 0;
        }

        .auth-mobile-chips{
            display:none;
        }

        .auth-mobile-chip{
            display:none;
        }

        .auth-form h2{
            font-size:24px;
        }

        .auth-subtitle{
            color:#475569;
            font-size:14px;
            margin-bottom:18px;
        }

        .form-group label{
            color:#1e3a8a;
            font-size:13px;
            font-weight:600;
            margin-bottom:7px;
        }

        .auth-input{
            background:rgba(255,255,255,.72);
            border-color:rgba(37,99,235,.16);
            color:#0f172a;
            height:48px;
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
            height:48px;
            margin-top:4px;
        }

        .text-center.mt-4{
            margin-top:16px !important;
        }

        .auth-footer-note{
            font-size:12px;
            margin-top:12px;
        }
    }
</style>

<body class="auth-page">

<div class="auth-shell">

    <div class="auth-card">

        <div class="auth-panel">
            <div>
                <div class="auth-logo">
                    <?php if($auth_has_custom_logo){ ?>
<img src="<?= htmlspecialchars($auth_logo_url); ?>" alt="You2 Biz Logo" class="auth-logo-image">
                    <?php }else{ ?>
                        <span class="auth-logo-mark">
<img src="<?= htmlspecialchars($auth_brand_icon_url); ?>" alt="You2 Biz Icon" class="auth-brand-icon">
                        </span>
                    <?php } ?>
<span>You2 Biz</span>
                </div>

                <h1>Manage business money with clarity.</h1>
                <p>
                    Track wallets, invoices, purchases, dues, and approvals from one secure workspace.
                </p>

                <div class="auth-points">
                    <div class="auth-point">
                        <i class="fas fa-check-circle"></i>
                        <span>Company and manager access</span>
                    </div>
                    <div class="auth-point">
                        <i class="fas fa-check-circle"></i>
                        <span>Wallet approval workflow</span>
                    </div>
                    <div class="auth-point">
                        <i class="fas fa-check-circle"></i>
                        <span>Sales, purchases, and reports</span>
                    </div>
                </div>

                <div class="auth-support">
                    <i class="fas fa-headset"></i>
                    <div>
                        <span>Customer service</span>
                        <strong>+8801977592783</strong>
                        <a
                            href="https://www.you2technologies.com"
                            target="_blank"
                            rel="noopener noreferrer">
                            www.you2technologies.com
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="auth-form">
            <div class="auth-mobile-hero">
                <div class="auth-mobile-brand">
                    <?php if($auth_has_custom_logo){ ?>
<img src="<?= htmlspecialchars($auth_logo_url); ?>" alt="You2 Biz Logo" class="auth-mobile-brand-image">
                    <?php }else{ ?>
                        <div class="auth-mobile-brand-mark">
<img src="<?= htmlspecialchars($auth_brand_icon_url); ?>" alt="You2 Biz Icon" class="auth-brand-icon">
                        </div>
                    <?php } ?>
                    <div>
<div class="auth-mobile-brand-title">You2 Biz</div>
<div class="auth-mobile-brand-subtitle">Cafe management workspace</div>
                    </div>
                </div>

            </div>

            <h2>Welcome back</h2>
            <div class="auth-subtitle">Sign in to continue to your account.</div>

            <?php if($message){ ?>
                <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                    <?= htmlspecialchars($message); ?>
                </div>
            <?php } ?>

            <form method="post">
                <div class="form-group">
                    <label>Username(Email or Phone No.)</label>
                    <div class="input-group">
                        <input
                            type="text"
                            name="login"
                            class="form-control auth-input"
                            placeholder="Username(Email or Phone No.)"
                            required>
                        <div class="input-group-append">
                            <div class="input-group-text auth-icon">
                                <span class="fas fa-user"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Password</label>
                    <div class="input-group">
                        <input
                            type="password"
                            name="password"
                            class="form-control auth-input"
                            placeholder="Enter your password"
                            required>
                        <div class="input-group-append">
                            <div class="input-group-text auth-icon">
                                <span class="fas fa-lock"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="text-right mb-3">
                    <a href="forgot_password.php" class="auth-link">
                        Forgot password?
                    </a>
                </div>

                <button
                    type="submit"
                    class="btn btn-primary btn-block auth-submit">
                    Sign In
                </button>
            </form>

            <div class="text-center mt-4">
                <span class="text-muted">New company?</span>
                <a href="register.php" class="auth-link">Create an account</a>
            </div>

            <div class="auth-footer-note">
                <strong>Powered by You2 Technologies</strong>
                <div>
                    Hotline: +8801977592783 |
                    <a
                        href="https://www.you2technologies.com">
                        www.you2technologies.com
                    </a>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="adminlte/plugins/jquery/jquery.min.js"></script>

<script src="adminlte/plugins/bootstrap/js/bootstrap.bundle.min.js"></script>

<script src="adminlte/dist/js/adminlte.min.js"></script>

</body>
</html>
