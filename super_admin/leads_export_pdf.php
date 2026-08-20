<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/trial_lead_helper.php';
require_once '../libraries/fpdf/fpdf.php';

require_super_admin_user();
ensure_trial_leads_table($conn);

$result = mysqli_query(
    $conn,
    "SELECT *
     FROM trial_leads
     ORDER BY id DESC"
);

$pdf = new FPDF('L', 'mm', 'A4');
$pdf->SetAutoPageBreak(true, 10);
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 15);
$pdf->Cell(0, 10, 'You2 Biz Trial Leads', 0, 1, 'C');
$pdf->Ln(2);

$headers = [
    ['Date', 45],
    ['Name', 70],
    ['Phone', 55],
    ['Business Name', 95],
];

$pdf->SetFont('Arial', 'B', 9);
foreach($headers as $header){
    $pdf->Cell($header[1], 8, $header[0], 1, 0, 'C');
}
$pdf->Ln();

$pdf->SetFont('Arial', '', 8);
while($row = mysqli_fetch_assoc($result)){
    $pdf->Cell(45, 8, date('d-m-Y H:i', strtotime((string)$row['created_at'])), 1);
    $pdf->Cell(70, 8, mb_substr((string)$row['full_name'], 0, 38), 1);
    $pdf->Cell(55, 8, mb_substr((string)$row['phone'], 0, 28), 1);
    $pdf->Cell(95, 8, mb_substr((string)$row['business_name'], 0, 52), 1);
    $pdf->Ln();
}

$pdf->Output('I', 'you2wallet_trial_leads_' . date('Ymd_His') . '.pdf');
exit;
