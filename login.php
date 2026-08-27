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

                // A manager login is issued to a staff member.  Keep the staff
                // record as the source of truth, so an inactive staff member
                // cannot continue to use an otherwise active login account.
                $staff_login_blocked = false;

                if ($role === 'manager' && (int)($user['staff_id'] ?? 0) > 0) {
                    $staff_login_stmt = mysqli_prepare(
                        $conn,
                        "SELECT status FROM staff WHERE id=? AND user_id=? LIMIT 1"
                    );

                    if ($staff_login_stmt) {
                        $staff_id = (int)$user['staff_id'];
                        $staff_owner_id = (int)$owner_id;

                        mysqli_stmt_bind_param(
                            $staff_login_stmt,
                            "ii",
                            $staff_id,
                            $staff_owner_id
                        );
                        mysqli_stmt_execute($staff_login_stmt);

                        $staff_login_result = mysqli_stmt_get_result($staff_login_stmt);
                        $linked_staff = mysqli_fetch_assoc($staff_login_result) ?: null;

                        $staff_login_blocked = !$linked_staff
                            || strtolower((string)($linked_staff['status'] ?? '')) !== 'active';
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

                if ($staff_login_blocked) {

                    $message = 'Your staff profile is inactive. Please contact your administrator.';

                } elseif (!$account || $account['status'] !== 'active') {

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
        min-height:560px;
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
        border-radius:0;
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

    .auth-logo--image-only{
        position:relative;
        padding:14px 18px 10px;
        width:max-content;
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

    .auth-panel p{
        color:rgba(255,255,255,.74);
        font-size:14px;
        line-height:1.55;
        margin:0;
        max-width:260px;
    }

    .auth-points{
        display:grid;
        gap:10px;
        margin-top:22px;
    }

    .auth-point{
        align-items:center;
        color:rgba(255,255,255,.84);
        display:flex;
        gap:10px;
        font-size:13px;
    }

    .auth-point i{
        color:#7dd3fc;
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
            padding:10px 10px 6px;
        }

        .auth-card{
            background:linear-gradient(180deg, #eff6ff 0%, #dbeafe 100%);
            border:1px solid rgba(37,99,235,.18);
            border-radius:16px;
            box-shadow:0 12px 28px rgba(37,99,235,.14);
            grid-template-columns:1fr;
            min-height:auto;
            width:100%;
        }

        .auth-panel{
            display:flex;
            justify-content:center;
            min-height:188px;
            padding:18px 16px 14px;
        }

        .auth-logo{
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
            height:76px;
            margin-left:-4px;
            max-width:180px;
        }

        .auth-logo-mark{
            border-radius:14px;
            height:56px;
            width:56px;
        }

        .auth-brand-icon{
            height:32px;
            width:32px;
        }

        .auth-logo-title{
            font-size:20px;
        }

        .auth-panel h1{
            font-size:22px;
            line-height:1.16;
            margin-top:10px;
            max-width:100%;
        }

        .auth-form{
            display:flex;
            flex-direction:column;
            justify-content:center;
            min-height:auto;
            padding:18px 14px 12px;
        }

        .auth-mobile-hero{
            display:none;
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
            font-size:22px;
        }

        .auth-subtitle{
            color:#475569;
            font-size:13px;
            margin-bottom:14px;
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
            height:44px;
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
            height:44px;
            margin-top:2px;
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
