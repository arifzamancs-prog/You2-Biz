<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/branch_context_helper.php';

if (!is_admin_user() && !(is_manager_user() && manager_can_view_all_branches($conn, (int)($_SESSION['user_id'] ?? 0)))) {
    header('Location: ' . app_path('dashboard.php'));
    exit;
}

$company_id = (int)$_SESSION['user_id'];
$branch_id = max(0, (int)($_POST['branch_id'] ?? 0));
if ($branch_id > 0) {
    $stmt = mysqli_prepare($conn, "SELECT id FROM branches WHERE id=? AND user_id=? AND status='active' LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $branch_id, $company_id);
    mysqli_stmt_execute($stmt);
    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) $branch_id = 0;
}
$_SESSION['selected_branch_id'] = $branch_id;
$return = (string)($_POST['return_to'] ?? app_path('dashboard.php'));
$root = app_root_path();
if ($return === '' || !str_starts_with(parse_url($return, PHP_URL_PATH) ?: '', $root)) $return = app_path('dashboard.php');
header('Location: ' . $return);
exit;
