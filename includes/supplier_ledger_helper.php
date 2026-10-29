<?php

/*
|----------------------------------
| Supplier Ledger
|----------------------------------
| Works like the customer ledger: each purchase adds to what we owe the
| supplier, and payments reduce it. purchases.paid_amount already includes
| later supplier payments, so the amount paid on the purchase itself is
| paid_amount minus that purchase's supplier_payments.
*/

// Supplier Due Payments made after the purchase, as rows (id, wallet_id, amount).
function purchase_later_payments($conn, $user_id, $purchase_id)
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, wallet_id, amount FROM supplier_payments WHERE purchase_id=? AND user_id=? ORDER BY id"
    );
    mysqli_stmt_bind_param($stmt, "ii", $purchase_id, $user_id);
    mysqli_stmt_execute($stmt);

    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

// Paid on the purchase itself vs. paid later: purchases.paid_amount holds both together.
function purchase_payment_split($conn, $user_id, $purchase_id, $paid_amount)
{
    $later = 0;

    foreach(purchase_later_payments($conn, $user_id, $purchase_id) as $payment){
        $later += (float)$payment['amount'];
    }

    return [
        'at_purchase' => max(0, round((float)$paid_amount - $later, 2)),
        'later' => round($later, 2),
    ];
}

// Returns null when the supplier does not belong to the company.
function supplier_ledger_data($conn, $user_id, $supplier_id)
{
    $user_id = (int)$user_id;
    $supplier_id = (int)$supplier_id;

    $stmt = mysqli_prepare($conn, "SELECT * FROM suppliers WHERE id=? AND user_id=?");
    mysqli_stmt_bind_param($stmt, "ii", $supplier_id, $user_id);
    mysqli_stmt_execute($stmt);
    $supplier = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if(!$supplier){
        return null;
    }

    /* Payments made after the purchase, per purchase */

    $payments = [];
    $later_paid = [];

    $stmt = mysqli_prepare(
        $conn,
        "SELECT sp.id, sp.purchase_id, sp.payment_date, sp.amount, sp.note, p.purchase_no
         FROM supplier_payments sp
         LEFT JOIN purchases p ON p.id = sp.purchase_id
         WHERE sp.supplier_id=? AND sp.user_id=?"
    );
    mysqli_stmt_bind_param($stmt, "ii", $supplier_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while($row = mysqli_fetch_assoc($result)){
        $payments[] = $row;
        $later_paid[(int)$row['purchase_id']] = ($later_paid[(int)$row['purchase_id']] ?? 0) + (float)$row['amount'];
    }

    /* Purchases */

    $ledger = [];
    $purchase_rows = [];

    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, purchase_date, purchase_no, total_amount, paid_amount
         FROM purchases
         WHERE supplier_id=? AND user_id=?"
    );
    mysqli_stmt_bind_param($stmt, "ii", $supplier_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while($row = mysqli_fetch_assoc($result)){
        $paid_on_purchase = max(0, (float)$row['paid_amount'] - ($later_paid[(int)$row['id']] ?? 0));

        $purchase_rows[$row['purchase_date'] . '|' . $row['id']] = count($ledger);
        $ledger[] = [
            'trx_date' => $row['purchase_date'],
            'type' => 'Purchase',
            'reference' => $row['purchase_no'],
            'sort_order' => 1,
            'reference_id' => (int)$row['id'],
            'debit' => (float)$row['total_amount'],
            'credit' => $paid_on_purchase,
        ];
    }

    /* Payments: a payment on the purchase date joins the purchase row, like the customer ledger */

    foreach($payments as $row){
        $merge_key = $row['payment_date'] . '|' . (int)$row['purchase_id'];

        if(isset($purchase_rows[$merge_key])){
            $ledger[$purchase_rows[$merge_key]]['credit'] += (float)$row['amount'];
            continue;
        }

        $reference = trim((string)($row['purchase_no'] ?? ''));

        if($reference === ''){
            $reference = trim((string)$row['note']) !== '' ? trim((string)$row['note']) : 'Payment';
        }

        $ledger[] = [
            'trx_date' => $row['payment_date'],
            'type' => 'Payment',
            'reference' => $reference,
            'sort_order' => 2,
            'reference_id' => (int)$row['id'],
            'debit' => 0,
            'credit' => (float)$row['amount'],
        ];
    }

    usort($ledger, function($a, $b){
        $date_compare = strtotime($a['trx_date']) - strtotime($b['trx_date']);

        if($date_compare != 0){
            return $date_compare;
        }

        if($a['sort_order'] != $b['sort_order']){
            return $a['sort_order'] - $b['sort_order'];
        }

        return $a['reference_id'] - $b['reference_id'];
    });

    /* Running balance and summary */

    $total_purchase = 0;
    $total_paid = 0;

    foreach($ledger as $index => $row){
        $total_purchase += $row['debit'];
        $total_paid += $row['credit'];
        $ledger[$index]['running_balance'] = $total_purchase - $total_paid;
    }

    $current_due = $total_purchase - $total_paid;

    return [
        'supplier' => $supplier,
        'ledger' => $ledger,
        'total_purchase' => $total_purchase,
        'total_paid' => $total_paid,
        'current_due' => $current_due,
        'current_due_label' => $current_due < 0 ? 'Current Outstanding' : 'Current Due',
        'current_due_display' => abs($current_due),
    ];
}

// Ledger running balance without a minus sign: a negative balance means the company paid in advance.
if(!function_exists('ledger_balance_display')){
    function ledger_balance_display($balance)
    {
        $balance = round((float)$balance, 2);

        return $balance < 0
            ? number_format(abs($balance), 2) . ' (Advance)'
            : number_format($balance, 2);
    }
}
