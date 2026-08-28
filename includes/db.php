<?php

date_default_timezone_set('Asia/Dhaka');

require_once __DIR__ . '/app_guard.php';

$host = "localhost";
$user = "root";
$pass = "";
$db   = "you2biz";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    die("Database Connection Failed");
}

// Keep Bangla and other Unicode text intact when it is saved to MySQL.
mysqli_set_charset($conn, 'utf8mb4');
mysqli_query($conn, "SET time_zone = '+06:00'");

if(function_exists('refresh_current_manager_permissions')){
    refresh_current_manager_permissions($conn);
}
