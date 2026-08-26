<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/project_package_helper.php';

require_admin_user();
ensure_project_package_tables($conn);
$user_id = (int)$_SESSION['user_id'];
$id = (int)($_GET['id'] ?? 0);

if(!project_has_transactions($conn, $id, $user_id)){
    $stmt = mysqli_prepare($conn, "DELETE FROM projects WHERE id=? AND user_id=?");
    mysqli_stmt_bind_param($stmt, 'ii', $id, $user_id);
    mysqli_stmt_execute($stmt);
}

header('Location: projects.php');
exit;
