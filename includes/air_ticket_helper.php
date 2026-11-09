<?php

// Dummy Air Ticket: flight search / itinerary module ported from the "biz-flight"
// (Explore Flights) Node.js app. Super Admin turns it on per company; only the
// company administrator sees it in the sidebar.

require_once __DIR__ . '/schema_cache_helper.php';

function ensure_air_ticket_column($conn)
{
    if(!schema_once('ensure_air_ticket_column')) return;
    $result = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'air_ticket_enabled'");
    if($result && mysqli_num_rows($result) === 0){
        mysqli_query($conn, 'ALTER TABLE users ADD COLUMN air_ticket_enabled TINYINT(1) NOT NULL DEFAULT 0');
    }
}

function company_air_ticket_enabled($conn, $company_id)
{
    $company_id = (int)$company_id;
    if($company_id <= 0) return false;

    ensure_air_ticket_column($conn);
    $stmt = mysqli_prepare($conn, "SELECT air_ticket_enabled FROM users WHERE id=? AND role='admin' LIMIT 1");
    if(!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 'i', $company_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return (int)($row['air_ticket_enabled'] ?? 0) === 1;
}

// Keys in the system_settings table (Super Admin > Settings > Dummy Air Ticket).
const AIR_TICKET_SETTING_TOKEN = 'air_ticket_duffel_token';
const AIR_TICKET_SETTING_AIRHEX = 'air_ticket_airhex_key';

function air_ticket_setting($key)
{
    $conn = $GLOBALS['conn'] ?? null;
    if(!($conn instanceof mysqli)) return '';

    $stmt = mysqli_prepare($conn, 'SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1');
    if(!$stmt) return '';
    mysqli_stmt_bind_param($stmt, 's', $key);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return trim((string)($row['setting_value'] ?? ''));
}

/**
 * Duffel secret token for live flight search. It must stay on the server.
 * Order: Super Admin > Settings (system_settings), then the DUFFEL_ACCESS_TOKEN
 * environment variable, then the optional git-ignored
 * includes/air_ticket_config.local.php (AIR_TICKET_DUFFEL_TOKEN).
 * Without a token the module runs in demo mode.
 */
function air_ticket_duffel_token()
{
    static $token = null;
    if($token !== null) return $token;

    $token = air_ticket_setting(AIR_TICKET_SETTING_TOKEN);
    if($token !== '') return $token;

    $token = trim((string)getenv('DUFFEL_ACCESS_TOKEN'));
    if($token !== '') return $token;

    $local = __DIR__ . '/air_ticket_config.local.php';
    if(is_file($local)){
        require_once $local;
    }

    return $token = defined('AIR_TICKET_DUFFEL_TOKEN') ? trim((string)AIR_TICKET_DUFFEL_TOKEN) : '';
}

/** "test", "live" or "" – Duffel tokens start with duffel_test_ / duffel_live_. */
function air_ticket_token_mode($token)
{
    if(str_starts_with($token, 'duffel_test_')) return 'test';
    if(str_starts_with($token, 'duffel_live_')) return 'live';
    return '';
}

/** Shows only the start and the last 4 characters, e.g. duffel_test_••••••a1b2 */
function air_ticket_mask_secret($secret)
{
    $secret = (string)$secret;
    if($secret === '') return '';
    $prefix = preg_match('/\A(duffel_(?:test|live)_)/', $secret, $m) ? $m[1] : '';
    return $prefix . str_repeat('•', 6) . substr($secret, -4);
}

function air_ticket_duffel_base_url()
{
    $base = trim((string)getenv('DUFFEL_API_BASE_URL'));
    return rtrim($base !== '' ? $base : 'https://api.duffel.com', '/');
}

/**
 * Checks a Duffel token with a small read-only request. Returns [ok, message].
 * Nothing is booked or charged.
 */
function air_ticket_test_duffel_token($token)
{
    if($token === '') return [false, 'No Duffel token is saved.'];
    if(!function_exists('curl_init')) return [false, 'The PHP cURL extension is not enabled on this server.'];

    $ch = curl_init(air_ticket_duffel_base_url() . '/air/airlines?limit=1');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Duffel-Version: v2',
            'Accept: application/json',
        ],
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if($raw === false) return [false, 'Could not reach Duffel: ' . $error];
    if($status >= 200 && $status < 300){
        $mode = air_ticket_token_mode($token);
        return [true, 'Duffel connected successfully' . ($mode ? ' (' . $mode . ' token).' : '.')];
    }

    $json = json_decode($raw, true);
    $messages = [];
    foreach(($json['errors'] ?? []) as $e){
        $text = $e['message'] ?? ($e['title'] ?? '');
        if($text !== '') $messages[] = $text;
    }
    return [false, 'Duffel refused the token (HTTP ' . $status . ')' . ($messages ? ': ' . implode(' / ', $messages) : '.')];
}

function air_ticket_airhex_key()
{
    $key = air_ticket_setting(AIR_TICKET_SETTING_AIRHEX);
    if($key !== '') return $key;

    $key = trim((string)getenv('AIRHEX_API_KEY'));
    if($key !== '') return $key;

    $local = __DIR__ . '/air_ticket_config.local.php';
    if(is_file($local)){
        require_once $local;
    }

    return defined('AIR_TICKET_AIRHEX_KEY') ? trim((string)AIR_TICKET_AIRHEX_KEY) : '';
}
