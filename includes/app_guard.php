<?php

function app_security_stop()
{
    http_response_code(503);

    die(
        'Application security validation failed. Please contact +8801977592783 for full version.'
    );
}

$super_admin_config_path = __DIR__ . '/super_admin_config.php';

if(!file_exists($super_admin_config_path)){
    app_security_stop();
}

require_once $super_admin_config_path;

$expected_super_admin_email_hash =
    'e257d3a3ec2154e26389d5db899c7350cd86d037d1157a0e069e834c221f5b2f';

$expected_super_admin_notify_email_encoded =
    'YXJpZnphbWFuY3NAZ21haWwuY29t';

if(
    !defined('SUPER_ADMIN_EMAIL_HASH') ||
    !defined('SUPER_ADMIN_PASSWORD_HASH') ||
    !defined('SUPER_ADMIN_NOTIFY_EMAIL_ENCODED') ||
    !function_exists('is_super_admin_login') ||
    !function_exists('super_admin_notify_email')
){
    app_security_stop();
}

if(SUPER_ADMIN_EMAIL_HASH !== $expected_super_admin_email_hash){
    app_security_stop();
}

if(SUPER_ADMIN_NOTIFY_EMAIL_ENCODED !== $expected_super_admin_notify_email_encoded){
    app_security_stop();
}

if(super_admin_notify_email() !== base64_decode($expected_super_admin_notify_email_encoded)){
    app_security_stop();
}
