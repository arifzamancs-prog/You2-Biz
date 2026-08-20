<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/company_backup_helper.php';

$user_id = $_SESSION['user_id'];

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
'you2wallet_backup_' .
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
