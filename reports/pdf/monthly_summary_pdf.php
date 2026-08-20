<?php

require_once '../../includes/auth.php';
require_once '../../includes/db.php';

require_once '../../libraries/fpdf/fpdf.php';

$user_id = $_SESSION['user_id'];

$month = isset($_GET['month'])
    ? (int)$_GET['month']
    : date('m');

$year = isset($_GET['year'])
    ? (int)$_GET['year']
    : date('Y');


/*
|--------------------------------------------------------------------------
| Money In
|--------------------------------------------------------------------------
*/

$sql = "SELECT SUM(amount) total_money_in
        FROM money_ins
        WHERE user_id=?
        AND approval_status='approved'
        AND MONTH(txn_date)=?
        AND YEAR(txn_date)=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "iii",
    $user_id,
    $month,
    $year
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$row = mysqli_fetch_assoc($result);

$total_money_in =
$row['total_money_in'] ?? 0;


/*
|--------------------------------------------------------------------------
| Expense
|--------------------------------------------------------------------------
*/

$sql = "SELECT SUM(amount) total_expense
        FROM expenses
        WHERE user_id=?
        AND approval_status='approved'
        AND MONTH(txn_date)=?
        AND YEAR(txn_date)=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "iii",
    $user_id,
    $month,
    $year
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$row = mysqli_fetch_assoc($result);

$total_expense =
$row['total_expense'] ?? 0;

$net_savings =
$total_money_in -
$total_expense;


/*
|--------------------------------------------------------------------------
| PDF
|--------------------------------------------------------------------------
*/

$pdf = new FPDF();

$pdf->AddPage();

$pdf->SetFont(
    'Arial',
    'B',
    18
);

$pdf->Cell(
    0,
    12,
    'You2 Biz',
    0,
    1,
    'C'
);

$pdf->SetFont(
    'Arial',
    '',
    12
);

$pdf->Cell(
    0,
    8,
    'Monthly Summary Report',
    0,
    1,
    'C'
);

$pdf->Ln(10);

$pdf->SetFont(
    'Arial',
    '',
    11
);

$pdf->Cell(
    50,
    8,
    'User:'
);

$pdf->Cell(
    100,
    8,
    $_SESSION['user_name'],
    0,
    1
);

$pdf->Cell(
    50,
    8,
    'Month:'
);

$pdf->Cell(
    100,
    8,
    date(
        'F',
        mktime(
            0,
            0,
            0,
            $month,
            1
        )
    ) . ' ' . $year,
    0,
    1
);

$pdf->Ln(5);

$pdf->SetFont(
    'Arial',
    'B',
    12
);

$pdf->Cell(
    90,
    10,
    'Total Money In',
    1
);

$pdf->Cell(
    90,
    10,
    'BDT ' .
    number_format(
        $total_money_in,
        2
    ),
    1,
    1
);

$pdf->Cell(
    90,
    10,
    'Total Expense',
    1
);

$pdf->Cell(
    90,
    10,
    'BDT ' .
    number_format(
        $total_expense,
        2
    ),
    1,
    1
);

$pdf->Cell(
    90,
    10,
    'Net Savings',
    1
);

$pdf->Cell(
    90,
    10,
    'BDT ' .
    number_format(
        $net_savings,
        2
    ),
    1,
    1
);

$pdf->Ln(10);

$pdf->SetFont(
    'Arial',
    'I',
    10
);

$pdf->Cell(
    0,
    8,
    'Generated: ' .
    date(
        'Y-m-d H:i:s'
    ),
    0,
    1
);

$pdf->Output(
    'I',
    'monthly_summary_report.pdf'
);

exit;
