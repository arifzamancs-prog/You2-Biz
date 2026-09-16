<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/lead_management_helper.php';

require_lead_management_access();
ensure_lead_management_table($conn);
ensure_lead_followup_notification_table($conn);

$lead_id = (int)($_GET['id'] ?? 0);
$company_id = (int)($_SESSION['user_id'] ?? 0);
$notification_user_id = (int)($_SESSION['login_user_id'] ?? $company_id);
$notification_user_name = trim((string)($_SESSION['login_name'] ?? ''));
$lead = null;

if($lead_id > 0){
    $scope = is_manager_user() ? ' AND (created_by_user_id=? OR created_by_name=?)' : '';
    $lead_stmt = mysqli_prepare(
        $conn,
        "SELECT id, followup_date, status FROM leads
         WHERE id=? AND user_id=? AND followup_date=CURDATE()" . $scope . ' LIMIT 1'
    );
    if($lead_stmt){
        if(is_manager_user()){
            mysqli_stmt_bind_param($lead_stmt, 'iiis', $lead_id, $company_id, $notification_user_id, $notification_user_name);
        }else{
            mysqli_stmt_bind_param($lead_stmt, 'ii', $lead_id, $company_id);
        }
        mysqli_stmt_execute($lead_stmt);
        $lead = mysqli_fetch_assoc(mysqli_stmt_get_result($lead_stmt));
        mysqli_stmt_close($lead_stmt);
    }
}

if($lead){
    $read_stmt = mysqli_prepare(
        $conn,
        'INSERT INTO lead_followup_notification_reads (user_id, lead_id, followup_date) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE read_at=CURRENT_TIMESTAMP'
    );
    mysqli_stmt_bind_param($read_stmt, 'iis', $notification_user_id, $lead_id, $lead['followup_date']);
    mysqli_stmt_execute($read_stmt);
    mysqli_stmt_close($read_stmt);
}

$target_filter = $lead['status'] ?? 'lead';
$target_url = 'index.php?filter=' . urlencode($target_filter);
if($lead){
    $target_url .= '&focus_lead=' . (int)$lead_id . '#lead-row-' . (int)$lead_id;
}
header('Location: ' . $target_url);
exit;
