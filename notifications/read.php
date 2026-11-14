<?php
// Opens a bell notification: marks it read for this person, then shows the invoice or purchase.
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/activity_notification_helper.php';

$company_id = (int)($_SESSION['user_id'] ?? 0);

if(isset($_GET['all'])){
    activity_notification_mark_read($conn, $company_id, 0);
    $back = (string)($_SERVER['HTTP_REFERER'] ?? '');
    header('Location: ' . (preg_match('#^https?://' . preg_quote((string)($_SERVER['HTTP_HOST'] ?? ''), '#') . '/#i', $back) ? $back : app_path('dashboard.php')));
    exit;
}

$url = activity_notification_mark_read($conn, $company_id, (int)($_GET['id'] ?? 0));
// Only pages inside this app are opened.
if($url === '' || preg_match('#^[a-z]+:|^//|\.\.#i', $url)){
    header('Location: ' . app_path('dashboard.php'));
    exit;
}
header('Location: ' . app_path(ltrim($url, '/')));
exit;
