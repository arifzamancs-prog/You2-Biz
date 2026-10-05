<?php
require_once __DIR__ . '/includes/installation_reporting_helper.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
$config = installation_reporting_config();
function installation_report_exit($status, $ok = false) {
    http_response_code($status);
    echo json_encode(['ok'=>$ok]);
    exit;
}
if (!$config['receiver_enabled'] || strlen($config['shared_key']) < 32) installation_report_exit(503);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') installation_report_exit(405);
$body = file_get_contents('php://input', false, null, 0, 2049);
if (strlen($body) > 2048) installation_report_exit(413);
$timestamp = $_SERVER['HTTP_X_REPORT_TIME'] ?? '';
$signature = $_SERVER['HTTP_X_REPORT_SIGNATURE'] ?? '';
if (!ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 300 ||
    !hash_equals(hash_hmac('sha256', $timestamp . "\n" . $body, $config['shared_key']), $signature)) installation_report_exit(401);
$data = json_decode($body, true);
if (!is_array($data) || !is_string($data['installation_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $data['installation_id'])) installation_report_exit(400);
if (($data['test'] ?? false) === true) installation_report_exit(200, true);
if (!installation_reporting_valid_url($data['site_url'] ?? null)) installation_report_exit(400);
try {
    require_once __DIR__ . '/includes/db.php';
    installation_reporting_tables($conn);
    // Self-reported domains are unverified. Do not fetch them (SSRF risk).
    $stmt = mysqli_prepare($conn, "INSERT INTO installation_checkins (installation_id,site_url,first_seen,last_seen)
        VALUES (?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE
        reviewed=IF(site_url=VALUES(site_url),reviewed,0),site_url=VALUES(site_url),last_seen=UTC_TIMESTAMP()");
    mysqli_stmt_bind_param($stmt, 'ss', $data['installation_id'], $data['site_url']);
    mysqli_stmt_execute($stmt);
    installation_report_exit(200, true);
} catch (Throwable $e) {
    installation_report_exit(503);
}
