<?php

session_start();

require_once 'includes/db.php';
require_once 'includes/email_verification_helper.php';

$token = trim($_GET['token'] ?? '');
$message = 'Invalid verification link.';
$type = 'danger';

if($token !== ''){
    $result = email_verification_activate_account($conn, $token);
    $message = $result['message'];
    $type = $result['success'] ? 'success' : 'danger';
}

$_SESSION['verification_message'] = $message;
$_SESSION['verification_message_type'] = $type;

header("Location: login.php");
exit;
