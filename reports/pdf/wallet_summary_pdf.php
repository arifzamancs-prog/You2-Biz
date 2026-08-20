<?php

require_once '../../includes/auth.php';
require_once '../../includes/db.php';
require_once '../../includes/wallet_helper.php';
require_once '../../libraries/fpdf/fpdf.php';

$user_id = $_SESSION['user_id'];

ensure_default_cash_wallet($conn, $user_id);

$sql = "SELECT
            wallet_name,
            description,
            balance,
            status
        FROM wallets
        WHERE user_id=?
        ORDER BY wallet_name ASC";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$pdf = new FPDF('L','mm','A4');

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
    'Wallet Summary Report',
    0,
    1,
    'C'
);

$pdf->Ln(5);

$pdf->SetFont(
    'Arial',
    'B',
    10
);

$pdf->Cell(60,10,'Wallet',1);
$pdf->Cell(90,10,'Description',1);
$pdf->Cell(50,10,'Balance',1);
$pdf->Cell(40,10,'Status',1);
$pdf->Ln();

$pdf->SetFont(
    'Arial',
    '',
    10
);

$total_balance = 0;

while($row = mysqli_fetch_assoc($result)){

    $pdf->Cell(
        60,
        10,
        substr(
            $row['wallet_name'],
            0,
            30
        ),
        1
    );

    $pdf->Cell(
        90,
        10,
        substr(
            $row['description'],
            0,
            50
        ),
        1
    );

    $pdf->Cell(
        50,
        10,
        'BDT ' .
        number_format(
            $row['balance'],
            2
        ),
        1
    );

    $pdf->Cell(
        40,
        10,
        ucfirst(
            $row['status']
        ),
        1
    );

    $pdf->Ln();

    $total_balance +=
    $row['balance'];
}

$pdf->SetFont(
    'Arial',
    'B',
    10
);

$pdf->Cell(
    150,
    10,
    'Total Balance',
    1
);

$pdf->Cell(
    50,
    10,
    'BDT ' .
    number_format(
        $total_balance,
        2
    ),
    1
);

$pdf->Cell(
    40,
    10,
    '',
    1
);

$pdf->Ln(15);

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
    'wallet_summary_report.pdf'
);

exit;
