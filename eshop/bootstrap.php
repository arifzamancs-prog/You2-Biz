<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/eshop_helper.php';
if(is_super_admin_user() || !is_admin_user()){
    http_response_code(403); exit('Company administrator access required.');
}
$company_id = (int)$_SESSION['user_id'];
$shop = eshop_company_settings($conn, $company_id);
if(!$shop || !(int)$shop['enabled']){
    http_response_code(403); exit('E-shop is not enabled for this company.');
}
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS eshop_profiles (
    company_id BIGINT UNSIGNED PRIMARY KEY,
    shop_name VARCHAR(100) NOT NULL,
    phone VARCHAR(40) NOT NULL DEFAULT '',
    email VARCHAR(150) NOT NULL DEFAULT '',
    address TEXT NOT NULL,
    description TEXT NOT NULL,
    delivery_charge DECIMAL(12,2) NOT NULL DEFAULT 0,
    return_policy TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$logoColumn=mysqli_query($conn, "SHOW COLUMNS FROM eshop_profiles LIKE 'logo_path'");
if($logoColumn && mysqli_num_rows($logoColumn)===0) mysqli_query($conn, "ALTER TABLE eshop_profiles ADD COLUMN logo_path VARCHAR(255) NOT NULL DEFAULT '' AFTER shop_name");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS eshop_products (
    company_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    published TINYINT(1) NOT NULL DEFAULT 0,
    online_price DECIMAL(12,2) NULL,
    description TEXT NOT NULL,
    PRIMARY KEY(company_id,product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if(empty($_SESSION['eshop_editor_csrf'])) $_SESSION['eshop_editor_csrf'] = bin2hex(random_bytes(32));
function eshop_escape($value){ return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function eshop_check_csrf(){
    if(!hash_equals($_SESSION['eshop_editor_csrf'], (string)($_POST['csrf'] ?? ''))){
        http_response_code(403); exit('Session changed. Refresh and try again.');
    }
}
