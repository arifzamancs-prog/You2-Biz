<?php
// Quick Panel endpoint: instant search (GET) and saving the panel's tiles (POST).
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/quick_panel_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function quick_panel_reply($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!quick_panel_enabled()) {
    quick_panel_reply(['ok' => false, 'error' => 'Not available'], 403);
}

$action = (string)($_REQUEST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'search') {
    quick_panel_reply(['ok' => true, 'results' => quick_panel_search($conn, (string)($_GET['q'] ?? ''))]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['save', 'reset'], true)) {
    if (!hash_equals(stock_csrf_token(), (string)($_POST['token'] ?? ''))) {
        quick_panel_reply(['ok' => false, 'error' => 'This page expired. Reload it and try again.'], 403);
    }
    if ($action === 'reset') {
        quick_panel_reply(['ok' => quick_panel_reset($conn)]);
    }
    $items = json_decode((string)($_POST['items'] ?? '[]'), true);
    if (!is_array($items)) {
        quick_panel_reply(['ok' => false, 'error' => 'Invalid data'], 400);
    }
    quick_panel_reply(['ok' => quick_panel_save($conn, $items), 'items' => quick_panel_clean_items($items)]);
}

quick_panel_reply(['ok' => false, 'error' => 'Unknown request'], 400);
