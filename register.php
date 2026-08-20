<?php

session_start();

require_once 'includes/db.php';
require_once 'includes/smtp_mailer.php';
require_once 'includes/super_admin_config.php';
require_once 'includes/app_config.php';
require_once 'includes/wallet_helper.php';
require_once 'includes/invoice_charge_helper.php';
require_once 'includes/contact_unique_helper.php';
require_once 'includes/company_settings_helper.php';
require_once 'includes/email_verification_helper.php';
require_once 'includes/sms_helper.php';
require_once 'includes/signup_message_helper.php';
require_once 'includes/branding_helper.php';
require_once 'includes/printing_helper.php';
require_once 'includes/product_category_helper.php';

ensure_company_setting_columns($conn);
ensure_email_verification_columns($conn);
ensure_sms_marketing_columns($conn);
ensure_signup_message_settings_table($conn);
printing_ensure_column($conn);

$auth_logo_url = branding_logo_url($conn);
$auth_favicon_url = branding_favicon_url($conn);
$auth_has_custom_logo = branding_has_custom_logo($conn);
$auth_brand_icon_url = $auth_favicon_url !== '' ? $auth_favicon_url : $auth_logo_url;

if(isset($_SESSION['user_id'])){

    header("Location: dashboard.php");
    exit;

}

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $name             = trim($_POST['name']);
    $email            = trim($_POST['email']);
    $phone            = trim($_POST['phone']);
    $password         = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if($password != $confirm_password){

        $message = "Password and Confirm Password do not match";
        $message_type = "danger";

    }else{
        $duplicate_message = '';

        if(
            contact_has_duplicate_in_table($conn, 'users', 'User', 'email', $email, 0, $duplicate_message) ||
            contact_has_duplicate_in_table($conn, 'users', 'User', 'phone', $phone, 0, $duplicate_message)
        ){

            $message =
            $duplicate_message;

            $message_type =
            "danger";

        }else{

            $hash =
            password_hash(
                $password,
                PASSWORD_DEFAULT
            );
            $verification_token = email_verification_create_token();
            $verification_token_hash = email_verification_hash($verification_token);
            $verification_expires_at = email_verification_expiry();
            $signup_settings = signup_message_settings($conn);
            $email_verification_active = ($signup_settings['email_status'] ?? 'active') === 'active';
            $initial_status = $email_verification_active ? 'pending_verification' : 'active';
            $initial_email_verified = $email_verification_active ? 0 : 1;
            $insert_token_hash = $email_verification_active ? $verification_token_hash : null;
            $insert_token_expires_at = $email_verification_active ? $verification_expires_at : null;

            $sql = "INSERT INTO users
                    (
                        name,
                        email,
                        phone,
                        password,
                        status,
                        email_verified,
                        email_verification_token_hash,
                        email_verification_expires_at
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?
                    )";

            $stmt =
            mysqli_prepare(
                $conn,
                $sql
            );

            mysqli_stmt_bind_param(
                $stmt,
                "sssssiss",
                $name,
                $email,
                $phone,
                $hash,
                $initial_status,
                $initial_email_verified,
                $insert_token_hash,
                $insert_token_expires_at
            );

            if(
                mysqli_stmt_execute(
                    $stmt
                )
            ){
                
                $user_id = mysqli_insert_id($conn);

                mysqli_query(
                    $conn,
                    "UPDATE users
                     SET role='admin',
                         owner_id={$user_id},
                         avatar='" . mysqli_real_escape_string($conn, branding_company_default_avatar_filename($conn)) . "',
                         printing_option='" . mysqli_real_escape_string($conn, printing_default_user_value($conn, 'printing_option', 'general')) . "',
                         printing_custom_width='" . (float)printing_default_user_value($conn, 'printing_custom_width', 8.27) . "',
                         printing_custom_height='" . (float)printing_default_user_value($conn, 'printing_custom_height', 11.69) . "',
                         printing_custom_top_margin='" . (float)printing_default_user_value($conn, 'printing_custom_top_margin', 0.50) . "',
                         print_invoice_notes='" . mysqli_real_escape_string($conn, printing_default_user_value($conn, 'print_invoice_notes', 'active')) . "',
                         print_invoice_created_by='" . mysqli_real_escape_string($conn, printing_default_user_value($conn, 'print_invoice_created_by', 'active')) . "',
                         company_seal_file='" . mysqli_real_escape_string($conn, basename((string)printing_default_user_value($conn, 'company_seal_file', ''))) . "',
                         paid_seal_file='" . mysqli_real_escape_string($conn, basename((string)printing_default_user_value($conn, 'paid_seal_file', ''))) . "',
                         print_company_seal='" . mysqli_real_escape_string($conn, printing_default_user_value($conn, 'print_company_seal', 'inactive')) . "',
                         print_paid_seal='" . mysqli_real_escape_string($conn, printing_default_user_value($conn, 'print_paid_seal', 'inactive')) . "',
                         print_company_logo='" . mysqli_real_escape_string($conn, printing_default_user_value($conn, 'print_company_logo', 'inactive')) . "',
                         print_company_profile='" . mysqli_real_escape_string($conn, printing_default_user_value($conn, 'print_company_profile', 'active')) . "',
                         printing_general_top_margin='" . (float)printing_default_user_value($conn, 'printing_general_top_margin', 0.50) . "',
                         print_general_top_margin='" . mysqli_real_escape_string($conn, printing_default_user_value($conn, 'print_general_top_margin', 'inactive')) . "',
                         subscription_plan='Trial',
                         subscription_status='trial',
                         max_managers=2,
                         max_products=15,
                         max_invoices_monthly=150,
                         subscription_expires_at=DATE_ADD(created_at, INTERVAL 30 DAY),
                         date_format='d-m-Y'
                     WHERE id={$user_id}"
                );

ensure_default_product_categories($conn, $user_id);

ensure_default_invoice_charges($conn, $user_id);

/* Create Default Cash Box Wallet */

ensure_default_cash_wallet($conn, $user_id);

$registration_base_url = app_base_url();
$registration_url = app_url('register.php');
$verification_url = app_url('verify_email.php?token=' . urlencode($verification_token));
$registration_host = $_SERVER['HTTP_X_FORWARDED_HOST']
    ?? ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'Unknown'));
$registration_ip = $_SERVER['HTTP_X_FORWARDED_FOR']
    ?? ($_SERVER['REMOTE_ADDR'] ?? 'Unknown');
$registration_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
$registration_time = date('Y-m-d H:i:s');

$admin_alert_placeholders = [
    'name' => $name,
    'company' => $name,
    'email' => $email,
    'phone' => $phone,
    'user_id' => $user_id,
    'status' => $initial_status,
    'email_verification_status' => $email_verification_active ? 'Pending verification' : 'Verified',
    'registration_time' => $registration_time,
    'registration_ip' => $registration_ip,
    'registration_host' => $registration_host,
    'registration_base_url' => $registration_base_url,
    'registration_url' => $registration_url,
    'verification_link' => $verification_url,
    'login_link' => app_url('login.php'),
    'user_agent' => $registration_user_agent,
];

if(($signup_settings['admin_alert_status'] ?? 'active') === 'active'){
    $admin_alert_email = trim((string)($signup_settings['admin_alert_email'] ?? ''));

    if($admin_alert_email === ''){
        $admin_alert_email = super_admin_notify_email();
    }

    $admin_alert_subject = signup_message_apply_placeholders(
        $signup_settings['admin_alert_subject'] ?? 'New You2 Biz Company Registration',
        $admin_alert_placeholders
    );
    $admin_alert_message = signup_message_apply_placeholders(
        $signup_settings['admin_alert_message'] ?? '',
        $admin_alert_placeholders
    );

    smtp_send_mail(
        $admin_alert_email,
        SUPER_ADMIN_NAME,
        $admin_alert_subject,
        signup_message_email_html($admin_alert_message)
    );
}

$signup_placeholders = [
    'name' => $name,
    'company' => $name,
    'email' => $email,
    'phone' => $phone,
    'verification_link' => $verification_url,
    'login_link' => app_url('login.php'),
    'customer_service' => '+8801977592783',
];

if($email_verification_active){
    $signup_email_message = signup_message_apply_placeholders(
        $signup_settings['email_message'] ?? '',
        $signup_placeholders
    );
    $signup_email_subject = signup_message_apply_placeholders(
        $signup_settings['email_subject'] ?? 'Verify your You2 Biz email',
        $signup_placeholders
    );

    smtp_send_mail(
        $email,
        $name,
        $signup_email_subject,
        signup_message_email_html($signup_email_message)
    );
}

$system_sms_token = sms_get_system_api_token($conn);

if(($signup_settings['sms_status'] ?? 'active') === 'active' && $system_sms_token !== '' && sms_normalize_phone($phone) !== ''){
    $signup_sms = '[ইউটু বিজ] আপনার You2 Biz account registration হয়েছে। Account active করতে আপনার email inbox থেকে verification link এ click করুন।';
    $signup_sms = signup_message_apply_placeholders(
        $signup_settings['sms_message'] ?? $signup_sms,
        $signup_placeholders
    );
    $signup_sms_result = sms_send_bulk_message($system_sms_token, [$phone], $signup_sms);

    sms_record_history(
        $conn,
        $user_id,
        'registration',
        'single',
        1,
        (int)$signup_sms_result['sent'],
        (int)$signup_sms_result['failed'],
        $signup_sms,
        $signup_sms_result['raw'] ?: $signup_sms_result['error']
    );
}
                
                header(
                    "Location: login.php?registered=" . ($email_verification_active ? "verify_email" : "active")
                );

                exit;

            }else{

                $message =
                "Registration Failed";

                $message_type =
                "danger";
            }
        }
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
Register - You2 Biz
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
        grid-template-columns:1fr 1.1fr;
        max-width:1040px;
        overflow:hidden;
        width:100%;
    }

    .auth-panel{
        background:#17202b;
        color:#fff;
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
        font-size:32px;
        font-weight:700;
        line-height:1.2;
        margin:40px 0 14px;
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
    }
</style>

<body class="auth-page">

<div class="auth-shell">

    <div class="auth-card">

        <div class="auth-panel">
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

            <h1>Start with a clean business wallet workspace.</h1>
            <p>
                Create your company account and manage customers, suppliers, wallets, invoices, purchases, and reports in one place.
            </p>

            <div class="auth-points">
                <div class="auth-point">
                    <i class="fas fa-check-circle"></i>
                    <span>Default cash wallet setup</span>
                </div>
                <div class="auth-point">
                    <i class="fas fa-check-circle"></i>
                    <span>Manager accounts and approvals</span>
                </div>
                <div class="auth-point">
                    <i class="fas fa-check-circle"></i>
                    <span>Ready for invoices and reports</span>
                </div>
            </div>

        </div>

        <div class="auth-form">
            <div class="auth-mobile-hero">
                <div class="auth-mobile-brand">
                    <?php if($auth_has_custom_logo){ ?>
<img src="<?= htmlspecialchars($auth_logo_url); ?>" alt="You2 Biz Logo" class="auth-logo-image">
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

            <h2>Create account</h2>
            <div class="auth-subtitle">Register your company profile.</div>

            <?php if($message){ ?>
                <div class="alert alert-<?= $message_type; ?>">
                    <?= htmlspecialchars($message); ?>
                </div>
            <?php } ?>

            <form method="post">
                <div class="form-group">
                    <label>Company or Account Name</label>
                    <div class="input-group">
                        <input
                            type="text"
                            name="name"
                            class="form-control auth-input"
                            placeholder="Enter account name"
                            required>
                        <div class="input-group-append">
                            <div class="input-group-text auth-icon">
                                <span class="fas fa-building"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Email</label>
                        <div class="input-group">
                            <input
                                type="email"
                                name="email"
                                class="form-control auth-input"
                                placeholder="Email"
                                required>
                            <div class="input-group-append">
                                <div class="input-group-text auth-icon">
                                    <span class="fas fa-envelope"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group col-md-6">
                        <label>Phone</label>
                        <div class="input-group">
                            <input
                                type="text"
                                name="phone"
                                class="form-control auth-input"
                                placeholder="Phone"
                                required>
                            <div class="input-group-append">
                                <div class="input-group-text auth-icon">
                                    <span class="fas fa-phone"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Password</label>
                        <div class="input-group">
                            <input
                                type="password"
                                name="password"
                                class="form-control auth-input"
                                placeholder="Password"
                                required>
                            <div class="input-group-append">
                                <div class="input-group-text auth-icon">
                                    <span class="fas fa-lock"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group col-md-6">
                        <label>Confirm Password</label>
                        <div class="input-group">
                            <input
                                type="password"
                                name="confirm_password"
                                class="form-control auth-input"
                                placeholder="Confirm password"
                                required>
                            <div class="input-group-append">
                                <div class="input-group-text auth-icon">
                                    <span class="fas fa-lock"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <button
                    type="submit"
                    class="btn btn-primary btn-block auth-submit">
                    Create Account
                </button>
            </form>

            <div class="text-center mt-4">
                <span class="text-muted">Already registered?</span>
                <a href="login.php" class="auth-link">Sign in</a>
            </div>
        </div>
    </div>

</div>

<script src="adminlte/plugins/jquery/jquery.min.js"></script>

<script src="adminlte/plugins/bootstrap/js/bootstrap.bundle.min.js"></script>

<script src="adminlte/dist/js/adminlte.min.js"></script>

</body>
</html>
