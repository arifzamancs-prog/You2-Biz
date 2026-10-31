<?php
/*
|----------------------------------
| Sales Return & Exchange (Inventory & Sales, Fashion Retail)
|----------------------------------
| Built on the Sales Refund records: every return is a sales_refunds row with
| a credit note (a negative invoice, REF-...) holding the returned lines, so
| sales, cost and the signed customer ledger stay right without extra work.
|
| A return is written in two steps inside one transaction:
|   1. sales_return_create()  - credit note, returned lines, stock back in
|                               (damaged lines are not restocked)
|   2. sales_return_settle()  - how the value is settled: cash from a branch
|                               wallet, deducted from dues (the returned invoice
|                               first), spent on an exchange invoice, or kept as
|                               advance (the credit note's negative due).
| Everything stays inside the returned invoice's branch.
*/

require_once __DIR__ . '/sales_refund_helper.php';
require_once __DIR__ . '/project_package_helper.php';
require_once __DIR__ . '/schema_cache_helper.php';

const SALES_RETURN_DAYS = 7;

function sales_return_enabled($conn, $company_id)
{
    return in_array(project_package_company_type($conn, (int)$company_id), ['Stock Product', 'Fashion house'], true);
}

// Before any transaction: CREATE/ALTER TABLE would commit it.
function sales_return_schema($conn)
{
    static $done = false;

    if($done){
        return;
    }

    $done = true;

    if(!schema_once('sales_return_schema')){
        return;
    }

    sales_refund_schema($conn);

    $columns = [
        'sales_refunds' => [
            'return_value' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
            'due_adjusted' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
            'advance_amount' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
            'exchange_invoice_id' => 'INT NULL',
            'exchange_amount' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
        ],
        'sales_refund_items' => [
            'item_condition' => 'VARCHAR(20) NULL',
            'unit_price' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
            'total_amount' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
        ],
    ];

    foreach($columns as $table => $list){
        foreach($list as $column => $definition){
            $exists = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");

            if($exists && $exists->num_rows === 0){
                $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        }
    }
}

function sales_return_fetch_invoice($conn, $company_id, $invoice_id)
{
    return sales_refund_query(
        $conn,
        'SELECT i.*, c.customer_code, c.phone AS customer_phone
         FROM invoices i
         LEFT JOIN customers c ON c.id=i.customer_id AND c.user_id=i.user_id
         WHERE i.id=? AND i.user_id=?',
        'ii',
        [(int)$invoice_id, (int)$company_id]
    )->get_result()->fetch_assoc() ?: null;
}

// '' when a return can be taken from this invoice today, otherwise the reason.
function sales_return_block_reason($invoice)
{
    if(!$invoice){
        return 'Invoice Not Found';
    }

    if(($invoice['created_by_type'] ?? '') === 'sales_refund' || (float)$invoice['total_amount'] <= 0){
        return 'This is a credit note; it cannot be returned.';
    }

    if(($invoice['accounting_status'] ?? 'posted') !== 'posted'){
        return 'Only a paid (posted) invoice can be returned.';
    }

    $days = (int)floor((strtotime(date('Y-m-d')) - strtotime((string)$invoice['invoice_date'])) / 86400);

    if($days > SALES_RETURN_DAYS){
        return 'Return is allowed only within ' . SALES_RETURN_DAYS . ' days of the invoice date.';
    }

    return '';
}

// The invoice's sold lines with what is still returnable and the refund per unit.
// A discount on the invoice lowers the refund in the same ratio; added charges are not refunded.
function sales_return_lines($conn, $invoice)
{
    $invoice_id = (int)$invoice['id'];
    $subtotal = (float)sales_refund_query($conn, 'SELECT COALESCE(SUM(total_price),0) s FROM invoice_items WHERE invoice_id=?', 'i', [$invoice_id])->get_result()->fetch_assoc()['s'];
    $ratio = $subtotal > 0 ? min(1.0, max(0.0, (float)$invoice['total_amount'] / $subtotal)) : 1.0;
    $rows = sales_refund_query(
        $conn,
        'SELECT ii.*, p.product_name, p.sku,
                COALESCE((SELECT SUM(ri.quantity) FROM sales_refund_items ri JOIN sales_refunds r ON r.id=ri.refund_id
                          WHERE ri.invoice_item_id=ii.id AND r.user_id=?), 0) AS returned_qty
         FROM invoice_items ii
         LEFT JOIN products p ON p.id=ii.product_id
         WHERE ii.invoice_id=? AND ii.quantity>0 AND ii.unit_price>0
         ORDER BY ii.id',
        'ii',
        [(int)$invoice['user_id'], $invoice_id]
    )->get_result()->fetch_all(MYSQLI_ASSOC);
    $lines = [];

    foreach($rows as $row){
        $sold = (int)$row['quantity'];
        $row['sold_qty'] = $sold;
        $row['returned_qty'] = (int)$row['returned_qty'];
        $row['returnable_qty'] = max(0, $sold - (int)$row['returned_qty']);
        $row['refund_unit_price'] = round(((float)$row['total_price'] / max(1, $sold)) * $ratio, 2);
        $lines[(int)$row['id']] = $row;
    }

    return $lines;
}

function sales_return_line_label($line)
{
    $variant = trim((string)($line['variant_name'] ?? ''));

    return (string)($line['product_name'] ?? 'Product') . ($variant !== '' ? ' (' . $variant . ')' : '');
}

// The lines picked on a return form (qty and condition per invoice item), checked against what can still be returned.
function sales_return_pick($conn, $invoice, $return_qty, $item_condition)
{
    $lines = sales_return_lines($conn, $invoice);
    $picked = [];
    $total = 0.0;

    foreach((array)$return_qty as $item_id => $qty){
        $item_id = (int)$item_id;
        $qty = filter_var($qty, FILTER_VALIDATE_INT);

        if($qty === 0 || $qty === null){
            continue;
        }

        if($qty === false || $qty < 0){
            throw new RuntimeException('Return quantity must be a whole positive number.');
        }

        if(!isset($lines[$item_id])){
            throw new RuntimeException('This product is not on the invoice.');
        }

        $line = $lines[$item_id];

        if($qty > (int)$line['returnable_qty']){
            throw new RuntimeException('Return quantity is more than what can be returned for ' . sales_return_line_label($line) . '.');
        }

        $line_total = round($qty * (float)$line['refund_unit_price'], 2);
        $picked[] = [
            'line' => $line,
            'qty' => $qty,
            'condition' => (($item_condition[$item_id] ?? 'restock') === 'damaged') ? 'damaged' : 'restock',
            'total' => $line_total,
        ];
        $total += $line_total;
    }

    $total = round($total, 2);

    if(!$picked || $total <= 0){
        throw new RuntimeException('Enter a quantity for at least one product to return.');
    }

    return ['lines' => $picked, 'total' => $total];
}

// Dues of the customer in one branch that a return can be deducted from.
function sales_return_customer_due($conn, $company_id, $branch_id, $customer_id)
{
    if((int)$customer_id <= 0){
        return 0.0;
    }

    $invoice_due = (float)sales_refund_query(
        $conn,
        "SELECT COALESCE(SUM(due_amount),0) d FROM invoices
         WHERE user_id=? AND branch_id=? AND customer_id=? AND accounting_status='posted' AND due_amount>0",
        'iii',
        [(int)$company_id, (int)$branch_id, (int)$customer_id]
    )->get_result()->fetch_assoc()['d'];
    $opening_due = 0.0;

    if($conn->query("SHOW COLUMNS FROM customer_opening_dues LIKE 'branch_id'")->num_rows > 0){
        $opening_due = (float)sales_refund_query(
            $conn,
            'SELECT COALESCE(SUM(due_amount),0) d FROM customer_opening_dues WHERE user_id=? AND branch_id=? AND customer_id=? AND due_amount>0',
            'iii',
            [(int)$company_id, (int)$branch_id, (int)$customer_id]
        )->get_result()->fetch_assoc()['d'];
    }

    return round($invoice_due + $opening_due, 2);
}

// Step 1: credit note with the returned lines, and stock back in. Call inside a transaction.
function sales_return_create($conn, $company_id, $branch_id, $invoice, $pick, $actor_id, $actor_name, $note)
{
    $company_id = (int)$company_id;
    $branch_id = (int)$branch_id;
    $invoice_id = (int)$invoice['id'];
    $customer_id = (int)$invoice['customer_id'];
    $value = (float)$pick['total'];
    $note = mb_substr(trim((string)$note), 0, 1000);
    $key = bin2hex(random_bytes(32));

    sales_refund_query(
        $conn,
        'INSERT INTO sales_refunds(user_id,invoice_id,branch_id,wallet_id,amount,reason,actor_id,request_key,return_value) VALUES(?,?,?,0,0,?,?,?,?)',
        'iiisisd',
        [$company_id, $invoice_id, $branch_id, $note, (int)$actor_id, $key, $value]
    );
    $refund_id = (int)$conn->insert_id;
    $number = 'REF-' . $refund_id . '-' . bin2hex(random_bytes(4));
    $cn_note = 'Return ' . $number . ' for ' . $invoice['invoice_no'] . ($note !== '' ? ': ' . $note : '');

    // The credit note starts fully unsettled (due = -value); step 2 settles it.
    sales_refund_query(
        $conn,
        "INSERT INTO invoices(user_id,branch_id,invoice_no,customer_id,customer_name,invoice_date,total_amount,paid_amount,due_amount,payment_status,accounting_status,notes,created_by_user_id,created_by_name,created_by_type)
         VALUES(?,?,?,?,?,CURDATE(),?,0,?,'paid','posted',?,?,?,'sales_refund')",
        'iisisddsis',
        [$company_id, $branch_id, $number, $customer_id, $invoice['customer_name'], -$value, -$value, $cn_note, (int)$actor_id, $actor_name]
    );
    $credit_id = (int)$conn->insert_id;
    sales_refund_query($conn, 'UPDATE sales_refunds SET credit_invoice_id=? WHERE id=?', 'ii', [$credit_id, $refund_id]);

    foreach($pick['lines'] as $row){
        $line = $row['line'];
        $qty = (int)$row['qty'];
        $restock = $row['condition'] === 'restock' ? 1 : 0;
        $unit_cost = (int)$line['quantity'] > 0 ? (float)($line['cost_amount'] ?? 0) / (int)$line['quantity'] : 0;
        $cost = $restock ? round($unit_cost * $qty, 2) : 0;

        sales_refund_query(
            $conn,
            'INSERT INTO invoice_items(invoice_id,product_id,variant_name,quantity,unit_price,total_price,cost_amount) VALUES(?,?,?,?,?,?,?)',
            'iisiddd',
            [$credit_id, (int)$line['product_id'], $line['variant_name'] ?? '', -$qty, (float)$line['refund_unit_price'], -(float)$row['total'], -$cost]
        );
        sales_refund_query(
            $conn,
            'INSERT INTO sales_refund_items(refund_id,invoice_item_id,quantity,restock,item_condition,unit_price,total_amount) VALUES(?,?,?,?,?,?,?)',
            'iiiisdd',
            [$refund_id, (int)$line['id'], $qty, $restock, $row['condition'], (float)$line['refund_unit_price'], (float)$row['total']]
        );

        if(!$restock){
            continue;
        }

        // Back to stock in the invoice's branch, the same variant, at the cost it was sold at.
        sales_refund_query(
            $conn,
            "INSERT INTO stock_batches(user_id,branch_id,product_id,variant_name,source_type,source_id,source_no,quantity,remaining_quantity,unit_cost,batch_date)
             VALUES(?,?,?,?,'sales_refund',?,?,?,?,?,CURDATE())",
            'iiisisddd',
            [$company_id, $branch_id, (int)$line['product_id'], $line['variant_name'] ?? '', $refund_id, $number, $qty, $qty, round($unit_cost, 4)]
        );
        sales_refund_query($conn, 'UPDATE products SET current_stock=current_stock+? WHERE id=? AND user_id=?', 'iii', [$qty, (int)$line['product_id'], $company_id]);
        sales_refund_query(
            $conn,
            "INSERT INTO stock_transactions(user_id,product_id,transaction_type,quantity,note,txn_date,reference_no) VALUES(?,?,'stock_in',?,?,CURDATE(),?)",
            'iiiss',
            [$company_id, (int)$line['product_id'], $qty, mb_substr($cn_note, 0, 500), $number]
        );
    }

    return ['id' => $refund_id, 'credit_id' => $credit_id, 'number' => $number, 'value' => $value, 'customer_id' => $customer_id, 'note' => $cn_note];
}

function sales_return_customer_payment($conn, $company_id, $branch_id, $customer_id, $invoice_id, $opening_due_id, $amount, $note)
{
    if($opening_due_id > 0){
        sales_refund_query(
            $conn,
            'INSERT INTO customer_payments(user_id,branch_id,customer_id,invoice_id,opening_due_id,amount,payment_date,note) VALUES(?,?,?,NULL,?,?,CURDATE(),?)',
            'iiiids',
            [(int)$company_id, (int)$branch_id, (int)$customer_id, (int)$opening_due_id, (float)$amount, $note]
        );
    }else{
        sales_refund_query(
            $conn,
            'INSERT INTO customer_payments(user_id,branch_id,customer_id,invoice_id,amount,payment_date,note) VALUES(?,?,?,?,?,CURDATE(),?)',
            'iiiids',
            [(int)$company_id, (int)$branch_id, (int)$customer_id, (int)$invoice_id, (float)$amount, $note]
        );
    }

    return (int)$conn->insert_id;
}

// Step 2: settle the return value. $settle: cash, wallet_id, due, advance, exchange (amount spent on an exchange invoice).
function sales_return_settle($conn, $company_id, $branch_id, $invoice, $return, $settle)
{
    $company_id = (int)$company_id;
    $branch_id = (int)$branch_id;
    $customer_id = (int)$return['customer_id'];
    $value = (float)$return['value'];
    $cash = round(max(0, (float)($settle['cash'] ?? 0)), 2);
    $due = round(max(0, (float)($settle['due'] ?? 0)), 2);
    $advance = round(max(0, (float)($settle['advance'] ?? 0)), 2);
    $exchange = round(max(0, (float)($settle['exchange'] ?? 0)), 2);
    $wallet_id = $cash > 0 ? (int)($settle['wallet_id'] ?? 0) : 0;

    if(abs($cash + $due + $advance + $exchange - $value) > 0.009){
        throw new RuntimeException('Cash + Due + Advance must equal the return total (' . number_format($value, 2) . ').');
    }

    if($customer_id <= 0 && ($due > 0 || $advance > 0)){
        throw new RuntimeException('Instant Customer: the return can only be refunded in cash.');
    }

    $credit_id = (int)$return['credit_id'];
    $number = (string)$return['number'];

    if($cash > 0){
        $wallet = sales_refund_query(
            $conn,
            "SELECT balance FROM wallets WHERE id=? AND user_id=? AND branch_id=? AND status='active' FOR UPDATE",
            'iii',
            [$wallet_id, $company_id, $branch_id]
        )->get_result()->fetch_assoc();

        if(!$wallet || (float)$wallet['balance'] < $cash){
            throw new RuntimeException('Select an active branch wallet with sufficient balance.');
        }

        $reference_id = $credit_id;

        if($customer_id > 0){
            $reference_id = sales_return_customer_payment($conn, $company_id, $branch_id, $customer_id, $credit_id, 0, -$cash, mb_substr($return['note'], 0, 1000));
        }

        sales_refund_query($conn, 'UPDATE wallets SET balance=balance-? WHERE id=?', 'di', [$cash, $wallet_id]);
        // A refund reverses a receipt, as the existing Sales Refund does.
        sales_refund_query(
            $conn,
            "INSERT INTO transactions(txn_no,user_id,branch_id,wallet_id,transaction_type,reference_id,amount,note,txn_date) VALUES(?,?,?,?,'receive_payment',?,?,?,CURDATE())",
            'siiiids',
            [$number, $company_id, $branch_id, $wallet_id, $reference_id, -$cash, mb_substr($return['note'], 0, 500)]
        );
    }

    if($due > 0){
        if($due > sales_return_customer_due($conn, $company_id, $branch_id, $customer_id) + 0.009){
            throw new RuntimeException('The due to deduct is more than the customer owes.');
        }

        sales_return_apply_due($conn, $company_id, $branch_id, $customer_id, $invoice, $number, $due);
        sales_return_customer_payment($conn, $company_id, $branch_id, $customer_id, $credit_id, 0, -$due, 'Return ' . $number . ' (deducted from due)');
    }

    if($exchange > 0 && $customer_id > 0){
        sales_return_customer_payment($conn, $company_id, $branch_id, $customer_id, $credit_id, 0, -$exchange, 'Return ' . $number . ' (exchange)');
    }

    // What is left unsettled (the advance) stays as the credit note's negative due.
    $paid = -round($value - $advance, 2);
    $credit_due = -$advance;
    sales_refund_query($conn, 'UPDATE invoices SET paid_amount=?, due_amount=? WHERE id=? AND user_id=?', 'ddii', [$paid, $credit_due, $credit_id, $company_id]);
    sales_refund_query(
        $conn,
        'UPDATE sales_refunds SET amount=?, wallet_id=?, due_adjusted=?, advance_amount=?, exchange_amount=? WHERE id=?',
        'didddi',
        [$cash, $wallet_id, $due, $advance, $exchange, (int)$return['id']]
    );
}

// Deducts a return from the customer's dues in the branch: the returned invoice first, then the oldest.
function sales_return_apply_due($conn, $company_id, $branch_id, $customer_id, $invoice, $number, $amount)
{
    $remaining = round((float)$amount, 2);
    $entries = sales_refund_query(
        $conn,
        "SELECT id, invoice_no AS ref, invoice_date AS entry_date, paid_amount, due_amount, 'invoice' AS entry_type FROM invoices
         WHERE user_id=? AND branch_id=? AND customer_id=? AND accounting_status='posted' AND due_amount>0",
        'iii',
        [(int)$company_id, (int)$branch_id, (int)$customer_id]
    )->get_result()->fetch_all(MYSQLI_ASSOC);

    if($conn->query("SHOW COLUMNS FROM customer_opening_dues LIKE 'branch_id'")->num_rows > 0){
        $opening = sales_refund_query(
            $conn,
            'SELECT id, due_no AS ref, entry_date, paid_amount, due_amount FROM customer_opening_dues WHERE user_id=? AND branch_id=? AND customer_id=? AND due_amount>0',
            'iii',
            [(int)$company_id, (int)$branch_id, (int)$customer_id]
        )->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach($opening as $row){
            $row['entry_type'] = 'opening_due';
            $entries[] = $row;
        }
    }

    $first = (int)$invoice['id'];
    usort($entries, function($a, $b) use ($first){
        $a_first = $a['entry_type'] === 'invoice' && (int)$a['id'] === $first;
        $b_first = $b['entry_type'] === 'invoice' && (int)$b['id'] === $first;

        if($a_first !== $b_first){
            return $a_first ? -1 : 1;
        }

        return strcmp((string)$a['entry_date'], (string)$b['entry_date']) ?: ((int)$a['id'] <=> (int)$b['id']);
    });

    foreach($entries as $entry){
        if($remaining <= 0.009){
            break;
        }

        $take = round(min($remaining, (float)$entry['due_amount']), 2);
        $new_paid = round((float)$entry['paid_amount'] + $take, 2);
        $new_due = round((float)$entry['due_amount'] - $take, 2);
        $new_due = $new_due <= 0.009 ? 0 : $new_due;
        $status = $new_due <= 0 ? 'paid' : 'partial';
        $note = 'Return ' . $number . ' (Due - ' . $entry['ref'] . ')';

        if($entry['entry_type'] === 'invoice'){
            sales_return_customer_payment($conn, $company_id, $branch_id, $customer_id, (int)$entry['id'], 0, $take, $note);
            sales_refund_query($conn, 'UPDATE invoices SET paid_amount=?, due_amount=?, payment_status=? WHERE id=? AND user_id=?', 'ddsii', [$new_paid, $new_due, $status, (int)$entry['id'], (int)$company_id]);
        }else{
            sales_return_customer_payment($conn, $company_id, $branch_id, $customer_id, 0, (int)$entry['id'], $take, $note);
            sales_refund_query($conn, 'UPDATE customer_opening_dues SET paid_amount=?, due_amount=?, status=? WHERE id=? AND user_id=?', 'ddsii', [$new_paid, $new_due, $status, (int)$entry['id'], (int)$company_id]);
        }

        $remaining = round($remaining - $take, 2);
    }

    if($remaining > 0.009){
        throw new RuntimeException('The due to deduct is more than the customer owes.');
    }
}

// The returns taken from one invoice, newest first.
function sales_return_list_for_invoice($conn, $company_id, $invoice_id)
{
    return sales_refund_query(
        $conn,
        'SELECT r.*, w.wallet_name, cn.invoice_no AS credit_no, xi.invoice_no AS exchange_invoice_no
         FROM sales_refunds r
         LEFT JOIN wallets w ON w.id=r.wallet_id
         LEFT JOIN invoices cn ON cn.id=r.credit_invoice_id
         LEFT JOIN invoices xi ON xi.id=r.exchange_invoice_id
         WHERE r.user_id=? AND r.invoice_id=? ORDER BY r.id DESC',
        'ii',
        [(int)$company_id, (int)$invoice_id]
    )->get_result()->fetch_all(MYSQLI_ASSOC);
}

// '', 'partial', 'returned' or 'exchanged' for badges.
function sales_return_state($sold_qty, $returned_qty, $exchanged_count = 0)
{
    if((int)$exchanged_count > 0){
        return 'exchanged';
    }

    if((float)$returned_qty <= 0){
        return '';
    }

    return (float)$returned_qty >= (float)$sold_qty ? 'returned' : 'partial';
}

function sales_return_state_badge($state)
{
    $badges = [
        'exchanged' => '<span class="badge badge-primary">Exchanged</span>',
        'returned' => '<span class="badge badge-dark">Returned</span>',
        'partial' => '<span class="badge badge-info">Partly Returned</span>',
    ];

    return $badges[$state] ?? '';
}

function sales_return_invoice_state($conn, $invoice)
{
    $lines = sales_return_lines($conn, $invoice);
    $exchanged = 0;

    foreach(sales_return_list_for_invoice($conn, (int)$invoice['user_id'], (int)$invoice['id']) as $row){
        if(!empty($row['exchange_invoice_id'])){
            $exchanged++;
        }
    }

    return sales_return_state(array_sum(array_column($lines, 'sold_qty')), array_sum(array_column($lines, 'returned_qty')), $exchanged);
}

// Damaged units customers brought back in a branch, as [product_id][variant] => qty, for the Return Damaged list.
function sales_return_damaged_by_product($conn, $company_id, $branch_id)
{
    // Checked once per session: the column only ever gets added, never removed.
    if(schema_recall('sales_refund_item_condition') !== true){
        $table = $conn->query("SHOW TABLES LIKE 'sales_refund_items'");
        $exists = $table && $table->num_rows > 0 ? $conn->query("SHOW COLUMNS FROM sales_refund_items LIKE 'item_condition'") : false;

        if(!$exists || $exists->num_rows === 0){
            return [];
        }

        schema_remember('sales_refund_item_condition', true);
    }

    $rows = sales_refund_query(
        $conn,
        "SELECT oi.product_id, oi.variant_name, SUM(ri.quantity) AS qty
         FROM sales_refund_items ri
         JOIN sales_refunds r ON r.id=ri.refund_id
         JOIN invoice_items oi ON oi.id=ri.invoice_item_id
         WHERE ri.item_condition='damaged' AND r.user_id=? AND r.branch_id=?
         GROUP BY oi.product_id, oi.variant_name",
        'ii',
        [(int)$company_id, (int)$branch_id]
    )->get_result()->fetch_all(MYSQLI_ASSOC);
    $map = [];

    foreach($rows as $row){
        $map[(int)$row['product_id']][(string)($row['variant_name'] ?? '')] = (float)$row['qty'];
    }

    return $map;
}

function sales_return_damaged_qty($conn, $company_id, $branch_id, $product_id, $variant_name)
{
    return (float)(sales_return_damaged_by_product($conn, $company_id, $branch_id)[(int)$product_id][(string)$variant_name] ?? 0);
}
