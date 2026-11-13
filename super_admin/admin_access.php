<?php
// Super Admin "Admin Access": opens a company exactly as its admin sees it, without the password.
// The super admin session is kept aside and comes back with super_admin/end_admin_access.php.
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/company_settings_helper.php';

if(!is_super_admin_user() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'){
    header('Location: ' . app_path('super_admin/index.php'));
    exit;
}
if(!hash_equals((string)($_SESSION['admin_access_csrf'] ?? ''), (string)($_POST['admin_access_csrf'] ?? ''))){
    exit('This page expired. Reload Super Admin and try again.');
}

$company_id = (int)($_POST['admin_access_company'] ?? 0);
$stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE id=? AND role='admin' LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $company_id);
mysqli_stmt_execute($stmt);
$company = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if(!$company){
    exit('Company not found.');
}

// Who opened which company, and when.
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS super_admin_access_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    company_name VARCHAR(190) NOT NULL DEFAULT '',
    ip_address VARCHAR(64) NOT NULL DEFAULT '',
    user_agent VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY company_access (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$log = mysqli_prepare($conn, 'INSERT INTO super_admin_access_log(company_id,company_name,ip_address,user_agent) VALUES(?,?,?,?)');
$ip = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
$agent = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
$name = (string)$company['name'];
mysqli_stmt_bind_param($log, 'isss', $company_id, $name, $ip, $agent);
mysqli_stmt_execute($log);

// Keep the super admin session, start a clean admin session for the company.
$super_admin_session = $_SESSION['super_admin_backup'] ?? $_SESSION;
unset($super_admin_session['super_admin_backup'], $super_admin_session['admin_access']);
session_regenerate_id(true);
$_SESSION = [];
$_SESSION['super_admin_backup'] = $super_admin_session;
$_SESSION['admin_access'] = ['company_id' => $company_id, 'company_name' => $name, 'started_at' => date('Y-m-d H:i:s')];

// The same values a normal admin login sets, without its side effects
// (last login, attendance, salary runs and the bill notice stay untouched).
$_SESSION['login_user_id'] = $company_id;
$_SESSION['login_name'] = $name;
$_SESSION['login_avatar'] = !empty($company['avatar']) ? $company['avatar'] : 'you2biz.png';
$_SESSION['user_role'] = 'admin';
$_SESSION['manager_type'] = 'admin';
$_SESSION['staff_id'] = 0;
$_SESSION['access_permissions'] = [];
$_SESSION['permissions_configured'] = false;
$_SESSION['user_id'] = $company_id;
$_SESSION['company_type'] = normalize_company_type($company['company_type'] ?? 'Housing');
$_SESSION['stock_product_enabled'] = company_type_uses_stock_products($_SESSION['company_type']);
$_SESSION['user_name'] = $name;
$_SESSION['avatar'] = !empty($company['avatar']) ? $company['avatar'] : 'you2biz.png';
if(array_key_exists('fifo_enabled', $company)){
    $_SESSION['fifo_enabled'] = (int)$company['fifo_enabled'];
}

header('Location: ' . app_path('dashboard.php'));
exit;
