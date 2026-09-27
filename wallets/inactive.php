<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/branch_context_helper.php';

$id = (int)$_GET['id'];

$user_id = $_SESSION['user_id'];
$branch_scope = branch_scope_sql($conn, 'wallets');

$sql = "SELECT is_system
        FROM wallets
        WHERE id=?
        AND user_id=? {$branch_scope}";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$wallet = mysqli_fetch_assoc($result);
if(!$wallet){
    die("Wallet Not Found");
}

if($wallet['is_system']==1){

    die("System Wallet Cannot Be Inactivated");

}

$sql = "UPDATE wallets
        SET status='inactive'
        WHERE id=?
        AND user_id=? {$branch_scope}";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $id,
    $user_id
);

mysqli_stmt_execute($stmt);

header("Location: index.php");
exit;
