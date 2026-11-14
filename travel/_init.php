<?php
// Shared start of the Travel Agency booking pages.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/project_package_helper.php';
require_once __DIR__ . '/../includes/travel_agency_helper.php';
require_once __DIR__ . '/../includes/travel_booking_helper.php';

require_sales_access();

$user_id = (int)$_SESSION['user_id'];
$branch_id = (int)selected_branch_id($conn, true);

if (project_package_company_type($conn, $user_id) !== 'Travel Agency') {
    header('Location: ' . app_path('dashboard.php'));
    exit;
}
if (!travel_booking_schema($conn)) {
    exit('Travel booking tables could not be prepared.');
}

function travel_csrf_field()
{
    return '<input type="hidden" name="travel_csrf" value="' . htmlspecialchars(stock_csrf_token()) . '">';
}

function travel_verify_csrf()
{
    if (!hash_equals(stock_csrf_token(), (string)($_POST['travel_csrf'] ?? ''))) {
        http_response_code(403);
        exit('This page expired. Reload it and try again.');
    }
}

function travel_money($value)
{
    return number_format((float)$value, 2);
}

function travel_wallet_options($conn, $user_id, $selected = 0)
{
    $html = '';
    $wallets = active_wallets_result($conn, $user_id);
    while ($wallets && ($wallet = mysqli_fetch_assoc($wallets))) {
        $html .= '<option value="' . (int)$wallet['id'] . '"' . ((int)$wallet['id'] === (int)$selected ? ' selected' : '') . '>'
            . htmlspecialchars($wallet['wallet_name']) . ' (BDT ' . travel_money($wallet['balance']) . ')</option>';
    }
    return $html;
}

function travel_client_label($client)
{
    if (!$client) return '';
    return $client['customer_name'] . ($client['customer_code'] ? ' [' . $client['customer_code'] . ']' : '') . ($client['phone'] ? ' · ' . $client['phone'] : '');
}
