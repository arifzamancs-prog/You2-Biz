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

$sql = "SELECT
            c.category_name,
            SUM(e.amount) total_amount
        FROM expenses e
        INNER JOIN categories c
            ON c.id = e.category_id
        WHERE e.user_id=?
        AND e.approval_status='approved'
        AND MONTH(e.txn_date)=?
        AND YEAR(e.txn_date)=?
        GROUP BY e.category_id
        ORDER BY total_amount DESC";

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

$pdf = new FPDF();

$pdf->AddPage();

$pdf->SetFont('Arial','B',18);

$pdf->Cell(
    0,
    12,
    'You2 Biz',
    0,
    1,
    'C'
);

$pdf->SetFont('Arial','',12);

$pdf->Cell(
    0,
    8,
    'Expense By Category Report',
    0,
    1,
    'C'
);

$pdf->Ln(5);

$pdf->SetFont('Arial','',11);

$pdf->Cell(
    0,
    8,
    'Month: ' .
    date(
        'F',
        mktime(
            0,
            0,
            0,
            $month,
            1
        )
    ) .
    ' ' .
    $year,
    0,
    1
);

$pdf->Ln(5);

$pdf->SetFont('Arial','B',11);

$pdf->Cell(
    120,
    10,
    'Category',
    1
);

$pdf->Cell(
    60,
    10,
    'Amount',
    1,
    1
);

$pdf->SetFont('Arial','',11);

$total_expense = 0;

while($row = mysqli_fetch_assoc($result)){

    $pdf->Cell(
        120,
        10,
        $row['category_name'],
        1
    );

    $pdf->Cell(
        60,
        10,
        'BDT ' .
        number_format(
            $row['total_amount'],
            2
        ),
        1,
        1
    );

    $total_expense +=
    $row['total_amount'];
}

$pdf->SetFont(
    'Arial',
    'B',
    11
);

$pdf->Cell(
    120,
    10,
    'Total Expense',
    1
);

$pdf->Cell(
    60,
    10,
    'BDT ' .
    number_format(
        $total_expense,
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
    date('Y-m-d H:i:s'),
    0,
    1
);

$pdf->Output(
    'I',
    'category_expense_report.pdf'
);

exit;
