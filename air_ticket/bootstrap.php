<?php
// Shared access check for the Dummy Air Ticket module.
// Only a company administrator whose company has the module turned on (by Super Admin) may use it.

if(session_status() === PHP_SESSION_NONE){
    session_start();
}

// JSON endpoints answer with JSON instead of being redirected to the login page.
if(!empty($air_ticket_json) && !isset($_SESSION['user_id']) && !isset($_SESSION['super_admin'])){
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['error' => 'Your session has ended. Please sign in again.']));
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/air_ticket_helper.php';

function air_ticket_deny($message)
{
    global $air_ticket_json;
    http_response_code(403);
    if(!empty($air_ticket_json)){
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['error' => $message]));
    }
    exit(htmlspecialchars($message));
}

if(is_super_admin_user() || !is_admin_user()){
    air_ticket_deny('Dummy Air Ticket is available to the company administrator only.');
}

$air_ticket_company_id = (int)($_SESSION['user_id'] ?? 0);
if(!company_air_ticket_enabled($conn, $air_ticket_company_id)){
    air_ticket_deny('Dummy Air Ticket is not enabled for this company. Please contact Super Admin.');
}
