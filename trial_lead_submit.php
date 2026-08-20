<?php

if(session_status() === PHP_SESSION_NONE){
    session_start();
}

require_once __DIR__ . '/includes/app_config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/input_validation_helper.php';
require_once __DIR__ . '/includes/trial_lead_helper.php';

ensure_trial_leads_table($conn);

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    header("Location: " . app_path());
    exit;
}

$full_name = normalize_person_name($_POST['full_name'] ?? '');
$phone = normalize_phone_input($_POST['phone'] ?? '');
$business_name = normalize_person_name($_POST['business_name'] ?? '');
$landing_page = trial_lead_clean_value($_POST['landing_page'] ?? app_base_url(), 500);
$referrer_url = trial_lead_clean_value($_SERVER['HTTP_REFERER'] ?? '', 500);
$utm_source = trial_lead_clean_value($_POST['utm_source'] ?? '', 100);
$utm_medium = trial_lead_clean_value($_POST['utm_medium'] ?? '', 100);
$utm_campaign = trial_lead_clean_value($_POST['utm_campaign'] ?? '', 150);
$utm_content = trial_lead_clean_value($_POST['utm_content'] ?? '', 150);
$utm_term = trial_lead_clean_value($_POST['utm_term'] ?? '', 150);
$fbclid = trial_lead_clean_value($_POST['fbclid'] ?? '', 255);
$ip_address = trial_lead_clean_value($_SERVER['REMOTE_ADDR'] ?? '', 64);
$user_agent = trial_lead_clean_value($_SERVER['HTTP_USER_AGENT'] ?? '', 5000);

$name_error = validate_person_name($full_name, 'Name');
$phone_error = validate_phone_input($phone, 'Phone', true);
$business_error = validate_person_name($business_name, 'Business name');

if($name_error !== '' || $phone_error !== '' || $business_error !== ''){
    $_SESSION['trial_lead_error'] = $name_error ?: ($phone_error ?: $business_error);
    header("Location: " . trial_lead_error_redirect_url());
    exit;
}

$stmt = mysqli_prepare(
    $conn,
    "INSERT INTO trial_leads (
        full_name,
        phone,
        business_name,
        landing_page,
        referrer_url,
        utm_source,
        utm_medium,
        utm_campaign,
        utm_content,
        utm_term,
        fbclid,
        ip_address,
        user_agent,
        lead_status
    ) VALUES (
        ?,?,?,?,?,?,?,?,?,?,?,?,?, 'new'
    )"
);

if(!$stmt){
    $_SESSION['trial_lead_error'] = 'Lead could not be saved.';
    header("Location: " . trial_lead_error_redirect_url());
    exit;
}

mysqli_stmt_bind_param(
    $stmt,
    "sssssssssssss",
    $full_name,
    $phone,
    $business_name,
    $landing_page,
    $referrer_url,
    $utm_source,
    $utm_medium,
    $utm_campaign,
    $utm_content,
    $utm_term,
    $fbclid,
    $ip_address,
    $user_agent
);

if(!mysqli_stmt_execute($stmt)){
    $_SESSION['trial_lead_error'] = 'Lead could not be saved.';
    header("Location: " . trial_lead_error_redirect_url());
    exit;
}

unset($_SESSION['trial_lead_error']);

header("Location: " . trial_lead_redirect_url());
exit;
