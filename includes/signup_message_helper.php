<?php

function ensure_signup_message_settings_table($conn)
{
    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS signup_message_settings (
            id TINYINT PRIMARY KEY,
            email_status VARCHAR(20) NOT NULL DEFAULT 'active',
            email_subject VARCHAR(255) NOT NULL,
            email_message TEXT NOT NULL,
            sms_status VARCHAR(20) NOT NULL DEFAULT 'active',
            sms_message TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );

    // Older installations created this table as latin1, which turns Bangla
    // characters into question marks when the SMS template is saved.
    $table_status = mysqli_query($conn, "SHOW TABLE STATUS LIKE 'signup_message_settings'");
    $table_info = $table_status ? mysqli_fetch_assoc($table_status) : null;

    if(
        $table_info &&
        stripos((string)($table_info['Collation'] ?? ''), 'utf8mb4_') !== 0
    ){
        mysqli_query(
            $conn,
            "ALTER TABLE signup_message_settings CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    }

    $extra_columns = [
        'admin_alert_status' => "ALTER TABLE signup_message_settings ADD COLUMN admin_alert_status VARCHAR(20) NOT NULL DEFAULT 'active'",
        'admin_alert_email' => "ALTER TABLE signup_message_settings ADD COLUMN admin_alert_email VARCHAR(255) NULL",
        'admin_alert_subject' => "ALTER TABLE signup_message_settings ADD COLUMN admin_alert_subject VARCHAR(255) NOT NULL DEFAULT 'New You2 Biz Company Registration'",
        'admin_alert_message' => "ALTER TABLE signup_message_settings ADD COLUMN admin_alert_message TEXT NULL",
        'trial_warning_email_status' => "ALTER TABLE signup_message_settings ADD COLUMN trial_warning_email_status VARCHAR(20) NOT NULL DEFAULT 'active'",
        'trial_warning_email_subject' => "ALTER TABLE signup_message_settings ADD COLUMN trial_warning_email_subject VARCHAR(255) NOT NULL DEFAULT 'Trial period reminder'",
        'trial_warning_email_message' => "ALTER TABLE signup_message_settings ADD COLUMN trial_warning_email_message TEXT NULL",
        'pricing_request_admin_email_status' => "ALTER TABLE signup_message_settings ADD COLUMN pricing_request_admin_email_status VARCHAR(20) NOT NULL DEFAULT 'active'",
        'pricing_request_admin_email_subject' => "ALTER TABLE signup_message_settings ADD COLUMN pricing_request_admin_email_subject VARCHAR(255) NOT NULL DEFAULT 'Pricing plan request sent'",
        'pricing_request_admin_email_message' => "ALTER TABLE signup_message_settings ADD COLUMN pricing_request_admin_email_message TEXT NULL",
        'pricing_request_super_admin_email_status' => "ALTER TABLE signup_message_settings ADD COLUMN pricing_request_super_admin_email_status VARCHAR(20) NOT NULL DEFAULT 'active'",
        'pricing_request_super_admin_email_subject' => "ALTER TABLE signup_message_settings ADD COLUMN pricing_request_super_admin_email_subject VARCHAR(255) NOT NULL DEFAULT 'New pricing plan request'",
        'pricing_request_super_admin_email_message' => "ALTER TABLE signup_message_settings ADD COLUMN pricing_request_super_admin_email_message TEXT NULL",
        'support_token_super_admin_email_status' => "ALTER TABLE signup_message_settings ADD COLUMN support_token_super_admin_email_status VARCHAR(20) NOT NULL DEFAULT 'active'",
        'support_token_super_admin_email_subject' => "ALTER TABLE signup_message_settings ADD COLUMN support_token_super_admin_email_subject VARCHAR(255) NOT NULL DEFAULT 'New support token: {ticket_token}'",
        'support_token_super_admin_email_message' => "ALTER TABLE signup_message_settings ADD COLUMN support_token_super_admin_email_message TEXT NULL",
    ];

    foreach($extra_columns as $column => $sql){
        $check = mysqli_query($conn, "SHOW COLUMNS FROM signup_message_settings LIKE '" . mysqli_real_escape_string($conn, $column) . "'");

        if($check && mysqli_num_rows($check) === 0){
            mysqli_query($conn, $sql);
        }
    }

    $user_check = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'trial_warning_sent_at'");

    if($user_check && mysqli_num_rows($user_check) === 0){
        mysqli_query($conn, "ALTER TABLE users ADD COLUMN trial_warning_sent_at DATETIME NULL");
    }

    $default = signup_message_default_settings();
    $stmt = mysqli_prepare(
        $conn,
        "INSERT IGNORE INTO signup_message_settings
            (id, email_status, email_subject, email_message, sms_status, sms_message)
         VALUES (1, ?, ?, ?, ?, ?)"
    );

    if($stmt){
        mysqli_stmt_bind_param(
            $stmt,
            "sssss",
            $default['email_status'],
            $default['email_subject'],
            $default['email_message'],
            $default['sms_status'],
            $default['sms_message']
        );
        mysqli_stmt_execute($stmt);
    }

    $admin_alert_email = function_exists('super_admin_notify_email')
        ? mysqli_real_escape_string($conn, super_admin_notify_email())
        : '';
    $admin_alert_message = mysqli_real_escape_string($conn, $default['admin_alert_message']);
    mysqli_query(
        $conn,
        "UPDATE signup_message_settings
         SET admin_alert_email='{$admin_alert_email}'
         WHERE id=1
         AND (admin_alert_email IS NULL OR admin_alert_email='')"
    );
    mysqli_query(
        $conn,
        "UPDATE signup_message_settings
         SET admin_alert_message='{$admin_alert_message}'
         WHERE id=1
         AND (admin_alert_message IS NULL OR admin_alert_message='')"
    );

    $warning_message = mysqli_real_escape_string($conn, $default['trial_warning_email_message']);
    mysqli_query(
        $conn,
        "UPDATE signup_message_settings
         SET trial_warning_email_message='{$warning_message}'
         WHERE id=1
         AND (trial_warning_email_message IS NULL OR trial_warning_email_message='')"
    );

    $pricing_request_admin_email_message = mysqli_real_escape_string($conn, $default['pricing_request_admin_email_message']);
    mysqli_query(
        $conn,
        "UPDATE signup_message_settings
         SET pricing_request_admin_email_message='{$pricing_request_admin_email_message}'
         WHERE id=1
         AND (pricing_request_admin_email_message IS NULL OR pricing_request_admin_email_message='')"
    );

    $pricing_request_super_admin_email_message = mysqli_real_escape_string($conn, $default['pricing_request_super_admin_email_message']);
    mysqli_query(
        $conn,
        "UPDATE signup_message_settings
         SET pricing_request_super_admin_email_message='{$pricing_request_super_admin_email_message}'
         WHERE id=1
         AND (pricing_request_super_admin_email_message IS NULL OR pricing_request_super_admin_email_message='')"
    );

    $support_token_super_admin_email_message = mysqli_real_escape_string($conn, $default['support_token_super_admin_email_message']);
    mysqli_query(
        $conn,
        "UPDATE signup_message_settings
         SET support_token_super_admin_email_message='{$support_token_super_admin_email_message}'
         WHERE id=1
         AND (support_token_super_admin_email_message IS NULL OR support_token_super_admin_email_message='')"
    );
}

function signup_message_default_settings()
{
    return [
        'email_status' => 'active',
        'email_subject' => 'Verify your You2 Biz email',
        'email_message' =>
            "Hello {name},\n\n" .
            "Your company account has been created. Please verify your email address to activate your account.\n\n" .
            "Verification link: {verification_link}\n\n" .
            "This verification link will expire in 24 hours.\n\n" .
            "Customer service: +8801977592783",
        'sms_status' => 'active',
        'sms_message' =>
            "[ইউটু বিজ] আপনার You2 Biz account registration হয়েছে। " .
            "Account active করতে আপনার email inbox থেকে verification link এ click করুন।",
        'admin_alert_status' => 'active',
        'admin_alert_email' => function_exists('super_admin_notify_email') ? super_admin_notify_email() : '',
        'admin_alert_subject' => 'New You2 Biz Company Registration',
        'admin_alert_message' =>
            "New Company Registration\n\n" .
            "A new company account has signed up in You2 Biz.\n\n" .
            "Company: {name}\n" .
            "Email: {email}\n" .
            "Phone: {phone}\n" .
            "Status: {status}\n" .
            "Email Verification: {email_verification_status}\n" .
            "Registration Time: {registration_time}\n" .
            "IP Address: {registration_ip}\n" .
            "Host: {registration_host}\n\n" .
            "Admin Login: {login_link}",
        'trial_warning_email_status' => 'active',
        'trial_warning_email_subject' => 'Trial period reminder',
        'trial_warning_email_message' =>
            "Hello {name},\n\n" .
            "Your company account is still on Trial. Your trial expires on {expires_at}.\n\n" .
            "Please login and use your account to keep it active.\n\n" .
            "Login link: {login_link}\n\n" .
            "Please contact {customer_service} for full version.",
        'pricing_request_admin_email_status' => 'active',
        'pricing_request_admin_email_subject' => 'Pricing plan request sent: {plan_name}',
        'pricing_request_admin_email_message' =>
            "Hello {admin_name},\n\n" .
            "Your plan activation request has been sent successfully.\n\n" .
            "Plan: {plan_name}\n" .
            "Software Price: {software_price}\n" .
            "Monthly Service Charge: {monthly_service_charge}\n" .
            "Hosting: {hosting_title}\n\n" .
            "Our support team will contact you soon.\n\n" .
            "Pricing Page: {pricing_link}",
        'pricing_request_super_admin_email_status' => 'active',
        'pricing_request_super_admin_email_subject' => 'New pricing plan request: {plan_name}',
        'pricing_request_super_admin_email_message' =>
            "A new pricing plan request has been submitted.\n\n" .
            "Admin: {admin_name}\n" .
            "Email: {admin_email}\n" .
            "Phone: {admin_phone}\n" .
            "Plan: {plan_name}\n" .
            "Software Price: {software_price}\n" .
            "Monthly Service Charge: {monthly_service_charge}\n" .
            "Hosting: {hosting_title}\n\n" .
            "Pricing Page: {pricing_link}",
        'support_token_super_admin_email_status' => 'active',
        'support_token_super_admin_email_subject' => 'New support token: {ticket_token}',
        'support_token_super_admin_email_message' =>
            "A new support token has been generated.\n\n" .
            "Token: {ticket_token}\n" .
            "Company: {company_name}\n" .
            "Admin: {admin_name}\n" .
            "Email: {admin_email}\n" .
            "Subject: {subject}\n" .
            "Message: {message}\n\n" .
            "Support Page: {support_link}",
    ];
}

function signup_message_settings($conn)
{
    ensure_signup_message_settings_table($conn);

    $result = mysqli_query(
        $conn,
        "SELECT *
         FROM signup_message_settings
         WHERE id=1
         LIMIT 1"
    );

    $settings = $result ? mysqli_fetch_assoc($result) : null;

    return $settings ?: signup_message_default_settings();
}

function signup_message_save_settings($conn, $email_status, $email_subject, $email_message, $sms_status, $sms_message, $trial_warning_email_status = 'active', $trial_warning_email_subject = '', $trial_warning_email_message = '', $admin_alert_status = 'active', $admin_alert_email = '', $admin_alert_subject = '', $admin_alert_message = '', $pricing_request_admin_email_status = 'active', $pricing_request_admin_email_subject = '', $pricing_request_admin_email_message = '', $pricing_request_super_admin_email_status = 'active', $pricing_request_super_admin_email_subject = '', $pricing_request_super_admin_email_message = '', $support_token_super_admin_email_status = 'active', $support_token_super_admin_email_subject = '', $support_token_super_admin_email_message = '')
{
    ensure_signup_message_settings_table($conn);

    $email_status = $email_status === 'inactive' ? 'inactive' : 'active';
    $sms_status = $sms_status === 'inactive' ? 'inactive' : 'active';
    $trial_warning_email_status = $trial_warning_email_status === 'inactive' ? 'inactive' : 'active';
    $admin_alert_status = $admin_alert_status === 'inactive' ? 'inactive' : 'active';
    $email_subject = trim((string)$email_subject);
    $email_message = trim((string)$email_message);
    $sms_message = trim((string)$sms_message);
    $trial_warning_email_subject = trim((string)$trial_warning_email_subject);
    $trial_warning_email_message = trim((string)$trial_warning_email_message);
    $admin_alert_email = trim((string)$admin_alert_email);
    $admin_alert_subject = trim((string)$admin_alert_subject);
    $admin_alert_message = trim((string)$admin_alert_message);
    $pricing_request_admin_email_status = $pricing_request_admin_email_status === 'inactive' ? 'inactive' : 'active';
    $pricing_request_admin_email_subject = trim((string)$pricing_request_admin_email_subject);
    $pricing_request_admin_email_message = trim((string)$pricing_request_admin_email_message);
    $pricing_request_super_admin_email_status = $pricing_request_super_admin_email_status === 'inactive' ? 'inactive' : 'active';
    $pricing_request_super_admin_email_subject = trim((string)$pricing_request_super_admin_email_subject);
    $pricing_request_super_admin_email_message = trim((string)$pricing_request_super_admin_email_message);
    $support_token_super_admin_email_status = $support_token_super_admin_email_status === 'inactive' ? 'inactive' : 'active';
    $support_token_super_admin_email_subject = trim((string)$support_token_super_admin_email_subject);
    $support_token_super_admin_email_message = trim((string)$support_token_super_admin_email_message);

    if($email_subject === ''){
        $email_subject = signup_message_default_settings()['email_subject'];
    }

    if($trial_warning_email_subject === ''){
        $trial_warning_email_subject = signup_message_default_settings()['trial_warning_email_subject'];
    }

    if($admin_alert_email === '' && function_exists('super_admin_notify_email')){
        $admin_alert_email = super_admin_notify_email();
    }

    if($admin_alert_subject === ''){
        $admin_alert_subject = signup_message_default_settings()['admin_alert_subject'];
    }

    if($pricing_request_admin_email_subject === ''){
        $pricing_request_admin_email_subject = signup_message_default_settings()['pricing_request_admin_email_subject'];
    }

    if($pricing_request_super_admin_email_subject === ''){
        $pricing_request_super_admin_email_subject = signup_message_default_settings()['pricing_request_super_admin_email_subject'];
    }

    if($support_token_super_admin_email_subject === ''){
        $support_token_super_admin_email_subject = signup_message_default_settings()['support_token_super_admin_email_subject'];
    }

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE signup_message_settings
         SET email_status=?,
             email_subject=?,
             email_message=?,
             sms_status=?,
             sms_message=?,
             admin_alert_status=?,
             admin_alert_email=?,
             admin_alert_subject=?,
             admin_alert_message=?,
             trial_warning_email_status=?,
             trial_warning_email_subject=?,
             trial_warning_email_message=?,
             pricing_request_admin_email_status=?,
             pricing_request_admin_email_subject=?,
             pricing_request_admin_email_message=?,
             pricing_request_super_admin_email_status=?,
             pricing_request_super_admin_email_subject=?,
             pricing_request_super_admin_email_message=?,
             support_token_super_admin_email_status=?,
             support_token_super_admin_email_subject=?,
             support_token_super_admin_email_message=?
         WHERE id=1"
    );

    if(!$stmt){
        return false;
    }

    mysqli_stmt_bind_param(
        $stmt,
        "sssssssssssssssssssss",
        $email_status,
        $email_subject,
        $email_message,
        $sms_status,
        $sms_message,
        $admin_alert_status,
        $admin_alert_email,
        $admin_alert_subject,
        $admin_alert_message,
        $trial_warning_email_status,
        $trial_warning_email_subject,
        $trial_warning_email_message,
        $pricing_request_admin_email_status,
        $pricing_request_admin_email_subject,
        $pricing_request_admin_email_message,
        $pricing_request_super_admin_email_status,
        $pricing_request_super_admin_email_subject,
        $pricing_request_super_admin_email_message,
        $support_token_super_admin_email_status,
        $support_token_super_admin_email_subject,
        $support_token_super_admin_email_message
    );

    return mysqli_stmt_execute($stmt);
}

function signup_message_apply_placeholders($template, $values)
{
    foreach($values as $key => $value){
        $template = str_replace('{' . $key . '}', (string)$value, $template);
    }

    return $template;
}

function signup_message_email_html($message)
{
    $message_html = nl2br(htmlspecialchars($message));

    return '
        <div style="font-family:Arial,sans-serif;background:#f4f6f9;padding:24px;">
            <div style="max-width:580px;margin:auto;background:#ffffff;border-radius:10px;padding:28px;">
                ' . $message_html . '
            </div>
        </div>';
}

function signup_message_send_trial_warnings($conn)
{
    if(!function_exists('smtp_send_mail') || !function_exists('app_url')){
        return 0;
    }

    ensure_signup_message_settings_table($conn);
    $settings = signup_message_settings($conn);

    if(($settings['trial_warning_email_status'] ?? 'active') !== 'active'){
        return 0;
    }

    $result = mysqli_query(
        $conn,
        "SELECT id, name, email, phone, subscription_expires_at
         FROM users
         WHERE role='admin'
         AND status='active'
         AND subscription_status='trial'
         AND created_at <= DATE_SUB(NOW(), INTERVAL 7 DAY)
         AND (last_login IS NULL OR last_login <= DATE_SUB(NOW(), INTERVAL 7 DAY))
         AND trial_warning_sent_at IS NULL
         AND email IS NOT NULL
         AND email<>''
         AND LOWER(email)<>'none'
         LIMIT 20"
    );

    $sent = 0;

    while($result && $user = mysqli_fetch_assoc($result)){
        $expires_at = !empty($user['subscription_expires_at'])
            ? date('d-m-Y', strtotime($user['subscription_expires_at']))
            : '-';
        $days_left = !empty($user['subscription_expires_at'])
            ? max(0, (int)ceil((strtotime($user['subscription_expires_at']) - time()) / 86400))
            : 0;
        $placeholders = [
            'name' => $user['name'],
            'company' => $user['name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'expires_at' => $expires_at,
            'days_left' => $days_left,
            'login_link' => app_url('login.php'),
            'customer_service' => '+8801977592783',
        ];
        $subject = signup_message_apply_placeholders(
            $settings['trial_warning_email_subject'] ?? 'Trial period reminder',
            $placeholders
        );
        $body = signup_message_apply_placeholders(
            $settings['trial_warning_email_message'] ?? '',
            $placeholders
        );
        $send_result = smtp_send_mail(
            $user['email'],
            $user['name'],
            $subject,
            signup_message_email_html($body)
        );

        if(is_array($send_result) && !empty($send_result[0])){
            $user_id = (int)$user['id'];
            mysqli_query($conn, "UPDATE users SET trial_warning_sent_at=NOW() WHERE id={$user_id}");
            $sent++;
        }
    }

    return $sent;
}
