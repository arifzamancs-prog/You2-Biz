<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/parking_helper.php';

require_admin_user();
$company_id = (int)($_SESSION['user_id'] ?? 0);
$ticket_id = (int)($_GET['id'] ?? 0);
if (!parking_company_enabled($conn, $company_id) || $ticket_id <= 0) {
    header('Location: ' . app_path('parking/index.php'));
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT t.*, v.vehicle_name, eg.gate_name AS exit_gate_name, c.name AS company_name FROM parking_tickets t INNER JOIN parking_vehicle_types v ON v.id=t.vehicle_type_id LEFT JOIN parking_gates eg ON eg.id=t.exit_gate_id LEFT JOIN users c ON c.id=t.company_id WHERE t.id=? AND t.company_id=? AND t.ticket_status IN ('exited','lost') LIMIT 1");
mysqli_stmt_bind_param($stmt, 'ii', $ticket_id, $company_id);
mysqli_stmt_execute($stmt);
$ticket = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$ticket) {
    header('Location: exit.php?error=Exit receipt not found.');
    exit;
}

$printed_stmt = mysqli_prepare($conn, 'UPDATE parking_tickets SET exit_printed_at=COALESCE(exit_printed_at, NOW()) WHERE id=? AND company_id=?');
mysqli_stmt_bind_param($printed_stmt, 'ii', $ticket_id, $company_id);
mysqli_stmt_execute($printed_stmt);
mysqli_stmt_close($printed_stmt);
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Parking Exit Receipt</title><style>body{font-family:Arial,sans-serif;margin:0;padding:12px;background:#f4f4f4}.ticket{background:#fff;width:74mm;margin:auto;padding:3mm;text-align:center;box-shadow:0 1px 6px #aaa}.ticket h2{font-size:17px;margin:0 0 4px}.ticket p{font-size:11px;margin:2px 0}.line{border-top:1px dashed #222;margin:7px 0}.details{text-align:left;font-size:12px}.details div{display:flex;justify-content:space-between;gap:8px;margin:4px 0}.details span:last-child{font-weight:700;text-align:right}.total{font-size:16px;font-weight:700;margin:8px 0}.actions{text-align:center;margin:16px}@media print{@page{size:80mm auto;margin:2mm}body{background:#fff;padding:0}.ticket{box-shadow:none;width:auto;padding:2mm}.actions{display:none}}</style></head><body>
<div class="ticket"><h2><?= htmlspecialchars($ticket['company_name']) ?></h2><p>PARKING EXIT RECEIPT</p><div class="line"></div><div class="details"><div><span>Ticket</span><span><?= htmlspecialchars($ticket['ticket_no']) ?></span></div><div><span>Vehicle</span><span><?= htmlspecialchars($ticket['vehicle_name']) ?></span></div><div><span>Number</span><span><?= htmlspecialchars($ticket['vehicle_number'] ?: 'N/A') ?></span></div><div><span>Entry</span><span><?= htmlspecialchars(date('d M, h:i A', strtotime($ticket['entry_at']))) ?></span></div><div><span>Exit</span><span><?= htmlspecialchars(date('d M, h:i A', strtotime($ticket['exit_at']))) ?></span></div><div><span>Duration</span><span><?= (int)$ticket['duration_minutes'] ?> min</span></div><div><span>Exit Gate</span><span><?= htmlspecialchars($ticket['exit_gate_name'] ?: '-') ?></span></div></div><div class="line"></div><div class="total">Paid: BDT <?= number_format((float)$ticket['paid_amount'], 2) ?></div><p>Thank you. Drive safely.</p></div>
<div class="actions"><button onclick="window.print()">Print Receipt</button> <a href="exit.php">Vehicle Exit</a></div>
</body></html>
