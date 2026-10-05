<?php

function installation_reporting_config()
{
    return require __DIR__ . '/installation_reporting_config.php';
}

function installation_reporting_tables($conn)
{
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS installation_checkins (
        installation_id CHAR(32) PRIMARY KEY,
        site_url VARCHAR(300) NOT NULL,
        first_seen DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        reviewed TINYINT NOT NULL DEFAULT 0,
        INDEX new_reports (reviewed, last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS installation_report_state (
        site_hash CHAR(64) PRIMARY KEY,
        installation_id CHAR(32) NOT NULL,
        last_attempt BIGINT NOT NULL DEFAULT 0,
        last_success BIGINT NOT NULL DEFAULT 0,
        last_error VARCHAR(200) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function installation_reporting_valid_url($url)
{
    if (!is_string($url) || strlen($url) > 300 || !filter_var($url, FILTER_VALIDATE_URL)) return false;
    $parts = parse_url($url);
    if (!in_array($parts['scheme'] ?? '', ['http', 'https'], true)) return false;
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) return false;
    $host = strtolower($parts['host'] ?? '');
    if (strpos($host, '.') === false || preg_match('/\.(localhost|local|test|invalid)$/', $host)) return false;
    if (filter_var($host, FILTER_VALIDATE_IP)) return false;
    return (bool)filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
}

function installation_reporting_site_url()
{
    require_once __DIR__ . '/app_config.php';
    // Never follow forwarded-host headers or contact the reported address.
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $url = ($https ? 'https://' : 'http://') . $host . rtrim(app_root_path(), '/');
    return installation_reporting_valid_url($url) ? $url : null;
}

function installation_reporting_send($conn, $force = false)
{
    $config = installation_reporting_config();
    if (strlen($config['shared_key']) < 32) return 'Reporting key is not configured. See docs/installation-reporting.md.';
    if (!function_exists('curl_init')) return 'PHP cURL is unavailable. Ask hosting support to enable it.';
    $url = installation_reporting_site_url();
    // A manual localhost test checks HTTPS without registering localhost as an installation.
    if (!$url && !$force) return 'Local/development installation: automatic reporting skipped.';
    installation_reporting_tables($conn);
    $hash = hash('sha256', $url ?? 'local-connection-test');
    $id = bin2hex(random_bytes(16));
    $stmt = mysqli_prepare($conn, 'INSERT IGNORE INTO installation_report_state(site_hash,installation_id) VALUES (?,?)');
    mysqli_stmt_bind_param($stmt, 'ss', $hash, $id);
    mysqli_stmt_execute($stmt);
    $stmt = mysqli_prepare($conn, 'SELECT * FROM installation_report_state WHERE site_hash=?');
    mysqli_stmt_bind_param($stmt, 's', $hash);
    mysqli_stmt_execute($stmt);
    $state = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    $now = time();
    $cutoff = $now - ($force ? 30 : 86400);
    $stmt = mysqli_prepare($conn, 'UPDATE installation_report_state SET last_attempt=? WHERE site_hash=? AND last_attempt<?');
    mysqli_stmt_bind_param($stmt, 'isi', $now, $hash, $cutoff);
    mysqli_stmt_execute($stmt);
    if (mysqli_stmt_affected_rows($stmt) !== 1) return $force ? 'Please wait 30 seconds before testing again.' : 'Already attempted today.';
    $payload = json_encode(['installation_id'=>$state['installation_id'], 'site_url'=>$url, 'test'=>$force]);
    $timestamp = (string)$now;
    $signature = hash_hmac('sha256', $timestamp . "\n" . $payload, $config['shared_key']);
    $ch = curl_init($config['endpoint']);
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$payload,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'X-Report-Time: '.$timestamp, 'X-Report-Signature: '.$signature],
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>2, CURLOPT_TIMEOUT=>4,
        CURLOPT_FOLLOWLOCATION=>false, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($ch);
    curl_close($ch);
    $data = is_string($response) ? json_decode($response, true) : null;
    $success = $status === 200 && ($data['ok'] ?? false) === true;
    $error = $success ? '' : ($curlError ? 'HTTPS connection failed (cURL code '.$curlError.').' : 'Reporting server rejected the request (HTTP '.$status.'). Check receiver setup and matching key.');
    $lastSuccess = $success ? $now : (int)$state['last_success'];
    $stmt = mysqli_prepare($conn, 'UPDATE installation_report_state SET last_success=?,last_error=? WHERE site_hash=?');
    mysqli_stmt_bind_param($stmt, 'iss', $lastSuccess, $error, $hash);
    mysqli_stmt_execute($stmt);
    return $success ? 'Connection successful. '.($force ? 'Test only; installation list was not changed.' : 'Installation reported.') : $error;
}

function installation_reporting_schedule($conn)
{
    if (PHP_SAPI === 'cli') return;
    register_shutdown_function(function () use ($conn) {
        // Reporting must never prevent invoices, stock operations or login from working.
        try {
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            installation_reporting_send($conn);
        } catch (Throwable $e) {
            error_log('Installation reporting unavailable; application continues.');
        }
    });
}
