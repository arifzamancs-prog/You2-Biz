<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/trial_lead_helper.php';

require_super_admin_user();
ensure_trial_leads_table($conn);

$result = mysqli_query(
    $conn,
    "SELECT *
     FROM trial_leads
     ORDER BY id DESC"
);

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="you2wallet_trial_leads_' . date('Ymd_His') . '.xls"');

echo "<table border='1'>";
echo "<tr>";
echo "<th>Date</th><th>Name</th><th>Phone</th><th>Business Name</th>";
echo "</tr>";

while($row = mysqli_fetch_assoc($result)){
    echo "<tr>";
    echo "<td>" . htmlspecialchars((string)$row['created_at']) . "</td>";
    echo "<td>" . htmlspecialchars((string)$row['full_name']) . "</td>";
    echo "<td>" . htmlspecialchars((string)$row['phone']) . "</td>";
    echo "<td>" . htmlspecialchars((string)$row['business_name']) . "</td>";
    echo "</tr>";
}

echo "</table>";
exit;
