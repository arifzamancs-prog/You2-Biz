<?php

require_once '../../includes/auth.php';
require_once '../../includes/db.php';
require_once '../../libraries/fpdf/fpdf.php';

$user_id = $_SESSION['user_id'];

$sql = "SELECT
            t.*,
            w.wallet_name,
            fw.wallet_name AS from_wallet,
            tw.wallet_name AS to_wallet
        FROM transactions t
        LEFT JOIN wallets w
            ON w.id=t.wallet_id
        LEFT JOIN transfers tr
            ON tr.id=t.reference_id
            AND t.transaction_type='transfer'
        LEFT JOIN wallets fw
            ON fw.id=tr.from_wallet_id
        LEFT JOIN wallets tw
            ON tw.id=tr.to_wallet_id
        WHERE t.user_id=?
        ORDER BY t.id DESC";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$pdf = new FPDF(
    'L',
    'mm',
    'A4'
);

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
    'Transaction Report',
    0,
    1,
    'C'
);

$pdf->Ln(5);

$pdf->SetFont(
    'Arial',
    'B',
    9
);

$pdf->Cell(30,10,'Date',1);
$pdf->Cell(35,10,'Txn No',1);
$pdf->Cell(30,10,'Type',1);
$pdf->Cell(50,10,'Wallet',1);
$pdf->Cell(35,10,'Amount',1);
$pdf->Cell(90,10,'Note',1);

$pdf->Ln();

$pdf->SetFont(
    'Arial',
    '',
    8
);

$income_types = [
    'money_in',
    'transfer_in',
    'sales_invoice',
    'receive_payment'
];

$type_labels = [
    'money_in' => 'Money In',
    'expense' => 'Expense',
    'transfer' => 'Transfer',
    'transfer_in' => 'Transfer In',
    'transfer_out' => 'Transfer Out',
    'sales_invoice' => 'Sales Invoice',
    'receive_payment' => 'Receive Due Payment',
    'purchase' => 'Purchase',
    'supplier_payment' => 'Supplier Due Payment'
];

$total_in = 0;
$total_out = 0;

while($row = mysqli_fetch_assoc($result)){

    $pdf->Cell(
        30,
        10,
        app_date($row['txn_date']),
        1
    );

    $pdf->Cell(
        35,
        10,
        $row['txn_no'],
        1
    );

    $pdf->Cell(
        30,
        10,
        $type_labels[$row['transaction_type']]
        ?? ucfirst(str_replace('_',' ',$row['transaction_type'])),
        1
    );

    $wallet_text = $row['wallet_name'];

    if($row['transaction_type'] == 'transfer'){
        $wallet_text =
            ($row['from_wallet'] ?? '') .
            ' -> ' .
            ($row['to_wallet'] ?? '');
    }

    $pdf->Cell(
        50,
        10,
        substr(
            $wallet_text,
            0,
            25
        ),
        1
    );

    $is_income =
        in_array(
            $row['transaction_type'],
            $income_types
        );

    $pdf->Cell(
        35,
        10,
        ($row['transaction_type'] == 'transfer' ? '' : ($is_income ? '+' : '-')) .
        number_format(
            $row['amount'],
            2
        ),
        1
    );

    $pdf->Cell(
        90,
        10,
        substr(
            $row['note'],
            0,
            50
        ),
        1
    );

    $pdf->Ln();

    if($row['transaction_type'] == 'transfer'){
        // Internal wallet movement, not business income or expense.
    }elseif($is_income){
        $total_in += $row['amount'];
    }else{
        $total_out += $row['amount'];
    }
}

$pdf->SetFont(
    'Arial',
    'B',
    9
);

$pdf->Cell(
    145,
    10,
    'Total In / Out / Net',
    1
);

$pdf->Cell(
    125,
    10,
    'In: BDT ' . number_format($total_in,2) .
    ' | Out: BDT ' . number_format($total_out,2) .
    ' | Net: BDT ' . number_format($total_in - $total_out,2),
    1
);

$pdf->Ln(15);

$pdf->SetFont(
    'Arial',
    'I',
    9
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
    'transaction_report.pdf'
);

exit;
