<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/parking_helper.php';

require_admin_user();
$company_id = (int)($_SESSION['user_id'] ?? 0);
if (!parking_company_enabled($conn, $company_id)) {
    header('Location: ' . app_path('dashboard.php?error=Parking Management is not enabled for this company.'));
    exit;
}

function parking_export_date($value)
{
    $value = trim((string)$value);
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : date('Y-m-d');
}

$date_from = parking_export_date($_GET['date_from'] ?? date('Y-m-d'));
$date_to = parking_export_date($_GET['date_to'] ?? date('Y-m-d'));
if ($date_from > $date_to) [$date_from, $date_to] = [$date_to, $date_from];
$vehicle_type_id = max(0, (int)($_GET['vehicle_type_id'] ?? 0));
$gate_id = max(0, (int)($_GET['gate_id'] ?? 0));
$sql = "SELECT t.ticket_no, v.vehicle_name, t.vehicle_number, t.entry_at, t.exit_at, eg.gate_name AS entry_gate, xg.gate_name AS exit_gate, t.duration_minutes, t.calculated_fee, t.paid_amount, t.payment_status, t.ticket_status
    FROM parking_tickets t
    INNER JOIN parking_vehicle_types v ON v.id=t.vehicle_type_id
    LEFT JOIN parking_gates eg ON eg.id=t.entry_gate_id
    LEFT JOIN parking_gates xg ON xg.id=t.exit_gate_id
    WHERE t.company_id=? AND (DATE(t.entry_at) BETWEEN ? AND ? OR DATE(t.exit_at) BETWEEN ? AND ?)";
$types = 'issss'; $params = [$company_id, $date_from, $date_to, $date_from, $date_to];
if ($vehicle_type_id > 0) { $sql .= ' AND t.vehicle_type_id=?'; $types .= 'i'; $params[] = $vehicle_type_id; }
if ($gate_id > 0) { $sql .= ' AND (t.entry_gate_id=? OR t.exit_gate_id=?)'; $types .= 'ii'; $params[] = $gate_id; $params[] = $gate_id; }
$sql .= ' ORDER BY COALESCE(t.exit_at, t.entry_at) DESC';
$stmt = mysqli_prepare($conn, $sql);
$bindings = [$stmt, $types]; foreach ($params as $key => $value) { $params[$key] = $value; $bindings[] = &$params[$key]; } call_user_func_array('mysqli_stmt_bind_param', $bindings);
mysqli_stmt_execute($stmt); $rows = mysqli_stmt_get_result($stmt);

$filename = 'parking-report-' . $date_from . '-to-' . $date_to . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
echo "\xEF\xBB\xBF";
$output = fopen('php://output', 'w');
fputcsv($output, ['Ticket No', 'Vehicle Type', 'Vehicle Number', 'Entry Time', 'Exit Time', 'Entry Gate', 'Exit Gate', 'Duration (Min)', 'Calculated Fee', 'Paid Amount', 'Payment Status', 'Ticket Status']);
while ($row = mysqli_fetch_assoc($rows)) {
    fputcsv($output, [$row['ticket_no'], $row['vehicle_name'], $row['vehicle_number'], $row['entry_at'], $row['exit_at'], $row['entry_gate'], $row['exit_gate'], $row['duration_minutes'], $row['calculated_fee'], $row['paid_amount'], $row['payment_status'], $row['ticket_status']]);
}
fclose($output); mysqli_stmt_close($stmt); exit;
