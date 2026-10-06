<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/booking_invoice_helper.php';
require_once '../includes/branch_context_helper.php';
require_once '../includes/smtp_mailer.php';
require_once '../includes/printing_helper.php';
require_once '../libraries/fpdf/fpdf.php';

require_sales_access();

$user_id = (int)$_SESSION['user_id'];
ensure_branch_accounting_columns($conn, $user_id);
ensure_booking_invoice_table($conn);
ensure_booking_invoice_type_table($conn, $user_id);
$branch_scope = branch_scope_sql($conn, 'bi');
$invoice_id = (int)($_POST['invoice_id'] ?? 0);

if($_SERVER['REQUEST_METHOD'] !== 'POST' || $invoice_id <= 0){
    header('Location: invoice_list.php?error=' . urlencode('Invalid invoice request.'));
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT bi.invoice_no, bi.invoice_type, bi.invoice_date, bi.amount, bi.status, bi.notes, c.customer_name, c.email, c.phone, c.address, p.project_name, pk.package_name, w.wallet_name FROM booking_invoices bi LEFT JOIN customers c ON c.id=bi.customer_id AND c.user_id=bi.user_id LEFT JOIN projects p ON p.id=bi.project_id AND p.user_id=bi.user_id LEFT JOIN packages pk ON pk.id=bi.package_id AND pk.user_id=bi.user_id LEFT JOIN wallets w ON w.id=bi.wallet_id AND w.user_id=bi.user_id WHERE bi.id=? AND bi.user_id=? {$branch_scope} LIMIT 1");
mysqli_stmt_bind_param($stmt, 'ii', $invoice_id, $user_id);
mysqli_stmt_execute($stmt);
$invoice = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if(!$invoice){
    header('Location: invoice_list.php?error=' . urlencode('Invoice not found.'));
    exit;
}

$customer_email = trim((string)($invoice['email'] ?? ''));
if(!filter_var($customer_email, FILTER_VALIDATE_EMAIL)){
    header('Location: invoice_list.php?error=' . urlencode('No valid email address was found for this customer. Please update the customer email first.'));
    exit;
}

$invoice_types = booking_invoice_types($conn, $user_id, false);
$type_label = booking_invoice_type_label($invoice['invoice_type'], $invoice_types);
$company_profile = printing_company_profile_data($conn);
$company_name = trim((string)($company_profile['name'] ?? '')) ?: 'Company';
$pdf_text = static function($value){
    return iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', (string)$value);
};
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetTitle((string)$invoice['invoice_no']);
$pdf->AddPage();
$pdf->SetFillColor(20, 121, 232);
$pdf->Rect(10, 10, 190, 4, 'F');
$pdf->SetY(22);
$pdf->SetFont('Arial', 'B', 20);
$pdf->SetTextColor(20, 121, 232);
$pdf->Cell(95, 10, $pdf_text($company_name), 0, 0);
$pdf->SetTextColor(23, 32, 51);
$pdf->Cell(95, 10, $pdf_text($type_label . ' Invoice'), 0, 1, 'R');
$pdf->SetFont('Arial', '', 11);
$pdf->SetTextColor(82, 98, 122);
$pdf->Cell(95, 6, $pdf_text(($company_profile['address'] ?? '') !== 'None' ? $company_profile['address'] : ''), 0, 0);
$pdf->Cell(95, 6, 'Invoice No: ' . $invoice['invoice_no'], 0, 1, 'R');
$pdf->Cell(95, 6, $pdf_text(($company_profile['phone'] ?? '') !== 'None' ? 'Phone: ' . $company_profile['phone'] : ''), 0, 0);
$pdf->Cell(95, 6, 'Date: ' . date('d-m-Y', strtotime((string)$invoice['invoice_date'])), 0, 1, 'R');
$pdf->Cell(95, 6, $pdf_text(($company_profile['email'] ?? '') !== 'None' ? 'Email: ' . $company_profile['email'] : ''), 0, 0);
$invoice_status = strtolower(trim((string)($invoice['status'] ?? 'pending'))) === 'confirmed' ? 'CONFIRMED' : 'PENDING';
if($invoice_status === 'CONFIRMED'){
    $pdf->SetFillColor(221, 246, 230);
    $pdf->SetTextColor(23, 122, 55);
}else{
    $pdf->SetFillColor(255, 242, 213);
    $pdf->SetTextColor(154, 98, 0);
}
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(95, 6, $invoice_status, 0, 1, 'R', true);
$pdf->SetTextColor(82, 98, 122);
$pdf->Ln(14);
$pdf->SetTextColor(123, 135, 153);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(95, 6, 'BILL TO', 0, 0);
$pdf->Cell(95, 6, 'INVOICE DETAILS', 0, 1);
$pdf->SetTextColor(23, 32, 51);
$pdf->SetFont('Arial', 'B', 13);
$pdf->Cell(95, 7, $pdf_text(substr((string)($invoice['customer_name'] ?: 'Customer'), 0, 45)), 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(95, 7, 'Service Category: ' . $pdf_text(substr((string)($invoice['project_name'] ?: '-'), 0, 40)), 0, 1);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(95, 6, $pdf_text('Phone: ' . ($invoice['phone'] ?: '-')), 0, 0);
$pdf->Cell(95, 6, 'Service: ' . $pdf_text(substr((string)($invoice['package_name'] ?: '-'), 0, 48)), 0, 1);
$pdf->Cell(95, 6, $pdf_text('Address: ' . substr((string)($invoice['address'] ?: '-'), 0, 55)), 0, 1);
$pdf->Ln(9);
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(20, 121, 232);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(78, 9, 'DESCRIPTION', 0, 0, 'L', true);
$pdf->Cell(38, 9, 'PAYMENT TYPE', 0, 0, 'L', true);
$pdf->Cell(35, 9, 'PAYMENT BY', 0, 0, 'L', true);
$pdf->Cell(39, 9, 'AMOUNT', 0, 1, 'R', true);
$pdf->SetTextColor(23, 32, 51);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(78, 12, $pdf_text(substr((string)($invoice['package_name'] ?: 'Service'), 0, 42)), 'B');
$pdf->Cell(38, 12, $pdf_text(substr($type_label, 0, 21)), 'B');
$pdf->Cell(35, 12, $pdf_text(substr((string)($invoice['wallet_name'] ?: 'Wallet'), 0, 19)), 'B');
$pdf->Cell(39, 12, 'BDT ' . number_format((float)$invoice['amount'], 2), 'B', 1, 'R');
$pdf->Ln(10);
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetFillColor(242, 246, 251);
$pdf->Cell(151, 11, 'TOTAL PAID', 0, 0, 'R', true);
$pdf->SetTextColor(20, 121, 232);
$pdf->Cell(39, 11, 'BDT ' . number_format((float)$invoice['amount'], 2), 0, 1, 'R');
$pdf->SetTextColor(123, 135, 153);
$paid_seal_path = should_print_paid_seal($conn) && $invoice_status === 'CONFIRMED'
    ? printing_upload_dir_path() . '/' . current_paid_seal_file($conn)
    : '';
if($paid_seal_path !== '' && is_file($paid_seal_path)){
    $pdf->Image($paid_seal_path, 117, $pdf->GetY() - 20, 24);
}
$pdf->Ln(20);
$company_seal_path = should_print_company_seal($conn)
    ? printing_upload_dir_path() . '/' . current_company_seal_file($conn)
    : '';
if($company_seal_path !== '' && is_file($company_seal_path)){
    $pdf->Image($company_seal_path, 166, $pdf->GetY(), 24);
}
$pdf->Ln(34);
$pdf->SetFont('Arial', 'I', 9);
$pdf->Cell(0, 7, 'This is a system-generated invoice.', 0, 1, 'C');
$pdf_content = $pdf->Output('S', 'invoice.pdf');
$filename = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$invoice['invoice_no']) . '.pdf';
$customer_name = trim((string)($invoice['customer_name'] ?? 'Customer')) ?: 'Customer';
$subject = 'Invoice ' . $invoice['invoice_no'];
$body = '<p>Dear ' . htmlspecialchars($customer_name, ENT_QUOTES, 'UTF-8') . ',</p>' .
    '<p>Your invoice <strong>' . htmlspecialchars((string)$invoice['invoice_no'], ENT_QUOTES, 'UTF-8') . '</strong> is attached.</p>' .
    '<p>Amount: <strong>BDT ' . number_format((float)$invoice['amount'], 2) . '</strong></p>' .
    '<p>Thank you.</p>';
[$sent, $error] = smtp_send_mail($customer_email, $customer_name, $subject, $body, [[
    'filename' => $filename,
    'content' => $pdf_content,
    'content_type' => 'application/pdf',
]], ['from_name' => $company_name]);

if($sent){
    header('Location: invoice_list.php?email_sent=1');
}else{
    header('Location: invoice_list.php?error=' . urlencode($error ?: 'Invoice email could not be sent.'));
}
exit;
