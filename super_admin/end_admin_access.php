<?php
// Leaves a company opened with Admin Access and brings the super admin session back.
if(session_status() === PHP_SESSION_NONE){
    session_start();
}
require_once __DIR__ . '/../includes/app_config.php';

$backup = $_SESSION['super_admin_backup'] ?? null;
if(!is_array($backup) || empty($backup['super_admin'])){
    header('Location: ' . app_path('login.php'));
    exit;
}
session_regenerate_id(true);
$_SESSION = $backup;
header('Location: ' . app_path('super_admin/index.php'));
exit;
