<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/company_backup_helper.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);

if(is_super_admin_user()){
    $target_company_id = (int)($_GET['company_id'] ?? 0);

    $company_stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE id=? AND role='admin' LIMIT 1");
    mysqli_stmt_bind_param($company_stmt, 'i', $target_company_id);
    mysqli_stmt_execute($company_stmt);
    $company = mysqli_fetch_assoc(mysqli_stmt_get_result($company_stmt));

    if(!$company){
        die('Invalid company selected.');
    }

    $user_id = $target_company_id;
}

[$ok, $error, $backup] = company_backup_collect($conn, $user_id, false);

if(!$ok){
    die($error);
}

$backup['exported_by'] = $_SESSION['user_name'];

/*
|--------------------------------------------------------------------------
| Download JSON
|--------------------------------------------------------------------------
*/

$filename =
'you2biz_backup_' .
date('Ymd_His') .
'.json';

header(
    'Content-Type: application/json'
);

header(
    'Content-Disposition: attachment; filename="'.$filename.'"'
);

echo json_encode(
    $backup,
    JSON_PRETTY_PRINT
);

exit;
