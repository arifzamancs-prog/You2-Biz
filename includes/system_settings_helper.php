<?php

function ensure_system_settings_table($conn)
{
    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )"
    );

    $defaults = system_settings_defaults();

    foreach($defaults as $key => $value){
        $stmt = mysqli_prepare(
            $conn,
            "INSERT IGNORE INTO system_settings (setting_key, setting_value)
             VALUES (?, ?)"
        );

        if($stmt){
            mysqli_stmt_bind_param($stmt, "ss", $key, $value);
            mysqli_stmt_execute($stmt);
        }
    }
}

function system_settings_defaults()
{
    return [
        'smtp_host' => 'mail.you2techbd.com',
        'smtp_port' => '465',
        'smtp_secure' => 'ssl',
        'smtp_username' => 'noreply@you2techbd.com',
        'smtp_password' => 'Wb2sd6yp8ZI3vI{q',
        'smtp_from_email' => 'noreply@you2techbd.com',
        'smtp_from_name' => 'You2 Biz',
        'sms_api_url' => 'https://api.bdbulksms.net/api.php?json',
        'sms_account_api_url' => 'https://api.bdbulksms.net/g_api.php',
        'sms_api_method' => 'POST',
        'sms_api_token' => '',
        'sms_token_param' => 'token',
        'sms_to_param' => 'to',
        'sms_message_param' => 'message',
        'sms_sender_id' => '',
        'sms_sender_param' => 'senderid',
        'sms_success_status' => 'SENT',
        'site_logo_file' => '',
        'site_favicon_file' => '',
        'company_default_avatar_file' => 'you2biz.png',
        'default_printing_option' => 'general',
        'default_printing_custom_width' => '8.27',
        'default_printing_custom_height' => '11.69',
        'default_printing_custom_top_margin' => '0.50',
        'default_print_invoice_notes' => 'active',
        'default_print_invoice_created_by' => 'active',
        'default_company_seal_file' => '',
        'default_paid_seal_file' => '',
        'default_print_company_seal' => 'inactive',
        'default_print_paid_seal' => 'inactive',
        'default_print_company_logo' => 'inactive',
        'default_print_company_profile' => 'active',
        'default_printing_general_top_margin' => '0.50',
        'default_print_general_top_margin' => 'inactive',
    ];
}

function system_settings_all($conn)
{
    ensure_system_settings_table($conn);

    $settings = system_settings_defaults();
    $result = mysqli_query($conn, "SELECT setting_key, setting_value FROM system_settings");

    while($result && $row = mysqli_fetch_assoc($result)){
        $settings[$row['setting_key']] = (string)$row['setting_value'];
    }

    return $settings;
}

function system_setting($conn, $key, $default = '')
{
    $settings = system_settings_all($conn);

    return $settings[$key] ?? $default;
}

function system_setting_save($conn, $key, $value)
{
    ensure_system_settings_table($conn);

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO system_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
    );

    if(!$stmt){
        return false;
    }

    $value = (string)$value;
    mysqli_stmt_bind_param($stmt, "ss", $key, $value);

    return mysqli_stmt_execute($stmt);
}

function system_settings_save_many($conn, $settings)
{
    foreach($settings as $key => $value){
        if(!system_setting_save($conn, $key, $value)){
            return false;
        }
    }

    return true;
}
