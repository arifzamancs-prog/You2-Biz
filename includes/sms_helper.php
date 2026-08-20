<?php

require_once __DIR__ . '/system_settings_helper.php';

function ensure_sms_marketing_columns($conn)
{
    $user_columns = [
        'sms_api_token' => "ALTER TABLE users ADD COLUMN sms_api_token VARCHAR(255) NULL",
        'sms_quota_total' => "ALTER TABLE users ADD COLUMN sms_quota_total INT NOT NULL DEFAULT 5",
        'sms_quota_used' => "ALTER TABLE users ADD COLUMN sms_quota_used INT NOT NULL DEFAULT 0",
    ];

    foreach($user_columns as $column => $sql){
        $check = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE '" . mysqli_real_escape_string($conn, $column) . "'");

        if($check && mysqli_num_rows($check) === 0){
            mysqli_query($conn, $sql);
        }
    }

    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS sms_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            recipient_type VARCHAR(30) NOT NULL,
            send_mode VARCHAR(30) NOT NULL,
            recipient_count INT NOT NULL DEFAULT 0,
            sent_count INT NOT NULL DEFAULT 0,
            failed_count INT NOT NULL DEFAULT 0,
            message TEXT NOT NULL,
            response LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );
}

function sms_get_user_quota($conn, $user_id)
{
    ensure_sms_marketing_columns($conn);

    $user_id = (int)$user_id;
    $stmt = mysqli_prepare(
        $conn,
        "SELECT sms_quota_total, sms_quota_used
         FROM users
         WHERE id=?
         LIMIT 1"
    );

    if(!$stmt){
        return [
            'total' => 0,
            'used' => 0,
            'remaining' => 0,
        ];
    }

    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    $total = max(0, (int)($row['sms_quota_total'] ?? 5));
    $used = max(0, (int)($row['sms_quota_used'] ?? 0));

    return [
        'total' => $total,
        'used' => $used,
        'remaining' => max(0, $total - $used),
    ];
}

function sms_consume_user_quota($conn, $user_id, $count)
{
    ensure_sms_marketing_columns($conn);

    $user_id = (int)$user_id;
    $count = max(0, (int)$count);

    if($user_id <= 0 || $count <= 0){
        return true;
    }

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE users
         SET sms_quota_used = sms_quota_used + ?
         WHERE id=?"
    );

    if(!$stmt){
        return false;
    }

    mysqli_stmt_bind_param($stmt, "ii", $count, $user_id);

    return mysqli_stmt_execute($stmt);
}

function sms_get_api_token($conn, $user_id)
{
    ensure_sms_marketing_columns($conn);

    $stmt = mysqli_prepare(
        $conn,
        "SELECT sms_api_token
         FROM users
         WHERE id=?
         LIMIT 1"
    );

    if(!$stmt){
        return '';
    }

    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    return trim((string)($row['sms_api_token'] ?? ''));
}

function sms_get_system_api_token($conn)
{
    ensure_sms_marketing_columns($conn);
    ensure_system_settings_table($conn);

    return trim(system_setting($conn, 'sms_api_token', ''));
}

function sms_gateway_config($conn = null)
{
    $settings = $conn instanceof mysqli
        ? system_settings_all($conn)
        : system_settings_defaults();

    $method = strtoupper(trim((string)($settings['sms_api_method'] ?? 'POST')));

    return [
        'url' => trim((string)($settings['sms_api_url'] ?? 'https://api.bdbulksms.net/api.php?json')),
        'method' => in_array($method, ['GET', 'POST'], true) ? $method : 'POST',
        'token_param' => trim((string)($settings['sms_token_param'] ?? 'token')) ?: 'token',
        'to_param' => trim((string)($settings['sms_to_param'] ?? 'to')) ?: 'to',
        'message_param' => trim((string)($settings['sms_message_param'] ?? 'message')) ?: 'message',
        'sender_id' => trim((string)($settings['sms_sender_id'] ?? '')),
        'sender_param' => trim((string)($settings['sms_sender_param'] ?? 'senderid')) ?: 'senderid',
        'success_status' => strtoupper(trim((string)($settings['sms_success_status'] ?? 'SENT'))) ?: 'SENT',
    ];
}

function sms_get_account_info($conn, $token)
{
    $token = trim((string)$token);
    $settings = system_settings_all($conn);
    $url = trim((string)($settings['sms_account_api_url'] ?? 'https://api.bdbulksms.net/g_api.php'));

    if($token === ''){
        return [
            'success' => false,
            'error' => 'SMS API token is missing.',
            'data' => [],
            'raw' => '',
        ];
    }

    if($url === ''){
        return [
            'success' => false,
            'error' => 'SMS account API URL is missing.',
            'data' => [],
            'raw' => '',
        ];
    }

    if(!function_exists('curl_init')){
        return [
            'success' => false,
            'error' => 'PHP cURL extension is not enabled.',
            'data' => [],
            'raw' => '',
        ];
    }

    $query = 'token=' . rawurlencode($token) .
        '&balance&expiry&rate&tokensms&totalsms&monthlysms&tokenmonthlysms&json';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . (str_contains($url, '?') ? '&' : '?') . $query);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_ENCODING, '');

    $raw = curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if($raw === false || $raw === ''){
        return [
            'success' => false,
            'error' => $curl_error ?: 'Empty response from SMS account API.',
            'data' => [],
            'raw' => (string)$raw,
        ];
    }

    $data = json_decode($raw, true);

    if(!is_array($data)){
        return [
            'success' => false,
            'error' => 'Invalid SMS account API response.',
            'data' => [],
            'raw' => $raw,
        ];
    }

    $normalized = [];

    foreach($data as $key => $value){
        if(is_array($value) && isset($value['action'])){
            $action = strtolower(trim((string)$value['action']));
            $normalized[$action] = $value['response'] ?? '';
        }else{
            $normalized[$key] = $value;
        }
    }

    return [
        'success' => true,
        'error' => '',
        'data' => $normalized,
        'raw' => $raw,
    ];
}

function sms_get_token_sent_count($conn, $token)
{
    $info = sms_get_account_info($conn, $token);

    if(!$info['success']){
        return null;
    }

    $data = $info['data'];
    $value = $data['tokensms'] ?? null;

    if($value === null){
        foreach($data as $key => $item){
            if(strtolower((string)$key) === 'tokensms'){
                $value = $item;
                break;
            }
        }
    }

    if($value === null || !is_numeric($value)){
        return null;
    }

    return (int)$value;
}

function sms_estimate_message_parts($message)
{
    $message = (string)$message;

    if($message === ''){
        return 0;
    }

    if(function_exists('mb_strlen')){
        $length = mb_strlen($message, 'UTF-8');
    }else{
        $length = strlen($message);
    }

    if($length <= 160){
        return 1;
    }

    return (int)ceil($length / 153);
}

function sms_save_api_token($conn, $user_id, $token)
{
    ensure_sms_marketing_columns($conn);

    $token = trim((string)$token);
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE users
         SET sms_api_token=?
         WHERE id=?"
    );

    if(!$stmt){
        return false;
    }

    mysqli_stmt_bind_param($stmt, "si", $token, $user_id);

    return mysqli_stmt_execute($stmt);
}

function sms_normalize_phone($phone)
{
    $phone = preg_replace('/[^0-9+]/', '', (string)$phone);

    if(str_starts_with($phone, '8801')){
        return '+' . $phone;
    }

    return $phone;
}

function sms_send_bulk_message($token, $phones, $message)
{
    global $conn;

    $token = trim((string)$token);
    $message = trim((string)$message);
    $phones = array_values(array_filter(array_map('sms_normalize_phone', $phones)));
    $config = sms_gateway_config(isset($conn) && $conn instanceof mysqli ? $conn : null);

    if($token === ''){
        return [
            'success' => false,
            'error' => 'SMS API token is missing.',
            'raw' => '',
            'items' => [],
            'sent' => 0,
            'failed' => count($phones),
        ];
    }

    if(empty($phones)){
        return [
            'success' => false,
            'error' => 'No valid phone number found.',
            'raw' => '',
            'items' => [],
            'sent' => 0,
            'failed' => 0,
        ];
    }

    if(!function_exists('curl_init')){
        return [
            'success' => false,
            'error' => 'PHP cURL extension is not enabled.',
            'raw' => '',
            'items' => [],
            'sent' => 0,
            'failed' => count($phones),
        ];
    }

    $data = [
        $config['token_param'] => $token,
        $config['to_param'] => implode(',', $phones),
        $config['message_param'] => $message,
    ];

    if($config['sender_id'] !== ''){
        $data[$config['sender_param']] = $config['sender_id'];
    }

    if($config['url'] === ''){
        return [
            'success' => false,
            'error' => 'SMS API URL is missing.',
            'raw' => '',
            'items' => [],
            'sent' => 0,
            'failed' => count($phones),
        ];
    }

    $ch = curl_init();
    $query = http_build_query($data);
    curl_setopt($ch, CURLOPT_URL, $config['method'] === 'GET' ? $config['url'] . (str_contains($config['url'], '?') ? '&' : '?') . $query : $config['url']);
    curl_setopt($ch, CURLOPT_POST, $config['method'] === 'POST');
    if($config['method'] === 'POST'){
        curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_ENCODING, '');

    $raw = curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if($raw === false || $raw === ''){
        return [
            'success' => false,
            'error' => $curl_error ?: 'Empty response from SMS API.',
            'raw' => (string)$raw,
            'items' => [],
            'sent' => 0,
            'failed' => count($phones),
        ];
    }

    $items = json_decode($raw, true);

    if(!is_array($items)){
        return [
            'success' => false,
            'error' => 'Invalid SMS API response.',
            'raw' => $raw,
            'items' => [],
            'sent' => 0,
            'failed' => count($phones),
        ];
    }

    $sent = 0;
    $failed = 0;

    if(isset($items['status']) || isset($items['statusmsg'])){
        $items = [$items];
    }

    foreach($items as $item){
        if(!is_array($item)){
            $failed++;
            continue;
        }

        $status = strtoupper((string)($item['status'] ?? $item['statusmsg'] ?? ''));

        if($status === $config['success_status']){
            $sent++;
        }else{
            $failed++;
        }
    }

    return [
        'success' => $sent > 0,
        'error' => '',
        'raw' => $raw,
        'items' => $items,
        'sent' => $sent,
        'failed' => $failed,
    ];
}

function sms_record_history($conn, $user_id, $recipient_type, $send_mode, $recipient_count, $sent_count, $failed_count, $message, $response)
{
    ensure_sms_marketing_columns($conn);

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO sms_history
            (user_id, recipient_type, send_mode, recipient_count, sent_count, failed_count, message, response)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if(!$stmt){
        return false;
    }

    mysqli_stmt_bind_param(
        $stmt,
        "issiiiss",
        $user_id,
        $recipient_type,
        $send_mode,
        $recipient_count,
        $sent_count,
        $failed_count,
        $message,
        $response
    );

    return mysqli_stmt_execute($stmt);
}
