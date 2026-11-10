<?php
/*
|----------------------------------
| Purchase Return (products sent back to the vendor/supplier)
|----------------------------------
| A return takes units out of the purchase's own FIFO batches, so only stock from
| that purchase which is still unsold can go back. Its value is settled by
| lowering the purchase due and/or by cash the vendor refunds into a wallet.
| The supplier ledger shows the return as a credit and any refund as a debit.
*/

require_once __DIR__ . '/fifo_inventory_helper.php';
require_once __DIR__ . '/transaction_helper.php';
require_once __DIR__ . '/wallet_helper.php';
require_once __DIR__ . '/pending_invoice_stock_helper.php';
require_once __DIR__ . '/supplier_label_helper.php';

function purchase_return_schema($conn)
{
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS purchase_returns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        purchase_id INT NOT NULL,
        supplier_id INT NOT NULL DEFAULT 0,
        return_no VARCHAR(50) NOT NULL,
        return_date DATE NOT NULL,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        due_adjusted DECIMAL(12,2) NOT NULL DEFAULT 0,
        cash_refund DECIMAL(12,2) NOT NULL DEFAULT 0,
        wallet_id INT NOT NULL DEFAULT 0,
        note TEXT NULL,
        actor_id INT NOT NULL DEFAULT 0,
        actor_name VARCHAR(150) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY company_return_no (user_id, return_no),
        KEY company_purchase (user_id, purchase_id),
        KEY company_supplier (user_id, supplier_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS purchase_return_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        return_id INT NOT NULL,
        purchase_item_id INT NOT NULL,
        product_id INT NOT NULL,
        variant_name VARCHAR(100) NOT NULL DEFAULT '',
        quantity INT NOT NULL,
        unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        KEY return_lines (return_id),
        KEY purchase_line (purchase_item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function purchase_return_query($conn, $sql, $types = '', $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);
    if(!$stmt){
        throw new RuntimeException('Purchase return query failed.');
    }
    if($types !== ''){
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if(!mysqli_stmt_execute($stmt)){
        throw new RuntimeException('Purchase return query failed.');
    }
    return $stmt;
}

function purchase_return_rows($conn, $sql, $types = '', $params = [])
{
    $result = mysqli_stmt_get_result(purchase_return_query($conn, $sql, $types, $params));
    return $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
}

function purchase_return_fetch_purchase($conn, $company_id, $purchase_id)
{
    $rows = purchase_return_rows(
        $conn,
        'SELECT p.*, s.supplier_name, s.phone AS supplier_phone FROM purchases p LEFT JOIN suppliers s ON s.id=p.supplier_id AND s.user_id=p.user_id WHERE p.id=? AND p.user_id=?',
        'ii',
        [(int)$purchase_id, (int)$company_id]
    );
    return $rows[0] ?? null;
}

// Purchase lines with how many units can still go back: never more than was bought minus
// earlier returns, and never more than this purchase's batches still hold unsold.
function purchase_return_lines($conn, $purchase)
{
    $company_id = (int)$purchase['user_id'];
    $purchase_id = (int)$purchase['id'];
    $rows = purchase_return_rows(
        $conn,
        "SELECT pi.*, pr.product_name, pr.sku,
                COALESCE((SELECT SUM(ri.quantity) FROM purchase_return_items ri JOIN purchase_returns r ON r.id=ri.return_id
                          WHERE ri.purchase_item_id=pi.id AND r.user_id=?), 0) AS returned_qty
         FROM purchase_items pi
         LEFT JOIN products pr ON pr.id=pi.product_id
         WHERE pi.purchase_id=? AND pi.quantity>0
         ORDER BY pi.id",
        'ii',
        [$company_id, $purchase_id]
    );
    $in_stock = [];
    foreach(purchase_return_rows(
        $conn,
        "SELECT product_id, variant_name, COALESCE(SUM(remaining_quantity),0) AS remaining FROM stock_batches
         WHERE user_id=? AND source_type='purchase' AND source_id=? GROUP BY product_id, variant_name",
        'ii',
        [$company_id, $purchase_id]
    ) as $batch){
        $in_stock[(int)$batch['product_id'] . '|' . (string)$batch['variant_name']] = (int)floor((float)$batch['remaining']);
    }

    $lines = [];
    foreach($rows as $row){
        $key = (int)$row['product_id'] . '|' . (string)($row['variant_name'] ?? '');
        $not_returned = max(0, (int)$row['quantity'] - (int)$row['returned_qty']);
        // Lines of the same product share the purchase's batches.
        $available = min($not_returned, max(0, $in_stock[$key] ?? 0));
        $in_stock[$key] = max(0, ($in_stock[$key] ?? 0) - $available);
        $row['returned_qty'] = (int)$row['returned_qty'];
        $row['returnable_qty'] = $available;
        $row['sold_or_used_qty'] = max(0, $not_returned - $available);
        $lines[(int)$row['id']] = $row;
    }
    return $lines;
}

function purchase_return_line_label($line)
{
    $variant = trim((string)($line['variant_name'] ?? ''));
    return (string)($line['product_name'] ?? 'Product') . ($variant !== '' ? ' (' . $variant . ')' : '');
}

// '' when this purchase can take a return, otherwise the reason.
function purchase_return_block_reason($conn, $purchase)
{
    if(!$purchase){
        return 'Purchase not found.';
    }
    if((int)$purchase['supplier_id'] <= 0){
        return supplier_display_text('This purchase has no supplier.');
    }
    $lines = purchase_return_lines($conn, $purchase);
    if(!$lines || array_sum(array_column($lines, 'returnable_qty')) <= 0){
        return 'Nothing from this purchase can be returned: its products were already sold, used or returned.';
    }
    return '';
}

// Saves a return. Call inside a transaction. $settle = ['due' => x, 'cash' => y, 'wallet_id' => id].
function purchase_return_create($conn, $company_id, $purchase, $quantities, $settle, $note, $actor_id, $actor_name)
{
    $company_id = (int)$company_id;
    $purchase_id = (int)$purchase['id'];
    $branch_id = (int)$purchase['branch_id'];
    $lines = purchase_return_lines($conn, $purchase);
    $picked = [];
    $total = 0.0;

    foreach((array)$quantities as $line_id => $qty){
        $qty = filter_var($qty, FILTER_VALIDATE_INT);
        if($qty === 0 || $qty === null){
            continue;
        }
        if($qty === false || $qty < 0){
            throw new RuntimeException('Return quantity must be a whole positive number.');
        }
        if(!isset($lines[(int)$line_id])){
            throw new RuntimeException('This product is not on the purchase.');
        }
        $line = $lines[(int)$line_id];
        if($qty > (int)$line['returnable_qty']){
            throw new RuntimeException('Only ' . (int)$line['returnable_qty'] . ' of ' . purchase_return_line_label($line) . ' can be returned.');
        }
        $value = round($qty * (float)$line['unit_cost'], 2);
        $picked[] = ['line' => $line, 'qty' => $qty, 'total' => $value];
        $total += $value;
    }
    $total = round($total, 2);
    if(!$picked || $total <= 0){
        throw new RuntimeException('Enter a quantity for at least one product to return.');
    }

    $due = round(max(0, (float)($settle['due'] ?? 0)), 2);
    $cash = round(max(0, (float)($settle['cash'] ?? 0)), 2);
    $wallet_id = $cash > 0 ? (int)($settle['wallet_id'] ?? 0) : 0;
    if(abs($due + $cash - $total) > 0.009){
        throw new RuntimeException('Due deducted + Cash refund must equal the return total (' . number_format($total, 2) . ').');
    }
    if($due > (float)$purchase['due_amount'] + 0.009){
        throw new RuntimeException('Only ' . number_format((float)$purchase['due_amount'], 2) . ' is due on this purchase. Take the rest as a cash refund.');
    }
    if($cash > 0){
        $wallet = purchase_return_rows($conn, "SELECT id FROM wallets WHERE id=? AND user_id=? AND status='active' FOR UPDATE", 'ii', [$wallet_id, $company_id]);
        if(!$wallet){
            throw new RuntimeException('Select the wallet that receives the vendor\'s cash refund.');
        }
    }

    // Stock that pending (unconfirmed) sales already hold must stay.
    foreach($picked as $row){
        $line = $row['line'];
        $product_id = (int)$line['product_id'];
        $variant = (string)($line['variant_name'] ?? '');
        $on_hand = (float)(purchase_return_rows($conn, 'SELECT COALESCE(SUM(remaining_quantity),0) AS q FROM stock_batches WHERE user_id=? AND product_id=? AND variant_name=? AND remaining_quantity>0', 'iis', [$company_id, $product_id, $variant])[0]['q'] ?? 0);
        $reserved = (float)pending_invoice_reserved_quantity($conn, $company_id, $product_id, 0, $variant);
        if($on_hand - $row['qty'] < $reserved - 0.0001){
            throw new RuntimeException(purchase_return_line_label($line) . ' is held by pending sales invoices. Confirm or cancel them first.');
        }
    }

    $return_no = generate_short_unique_txn_no($conn, 'PRT', 'purchase_returns', 'return_no');
    $note = mb_substr(trim((string)$note), 0, 1000);
    purchase_return_query(
        $conn,
        'INSERT INTO purchase_returns(user_id,branch_id,purchase_id,supplier_id,return_no,return_date,total_amount,due_adjusted,cash_refund,wallet_id,note,actor_id,actor_name) VALUES(?,?,?,?,?,CURDATE(),?,?,?,?,?,?,?)',
        'iiiisdddisis',
        [$company_id, $branch_id, $purchase_id, (int)$purchase['supplier_id'], $return_no, $total, $due, $cash, $wallet_id, $note, (int)$actor_id, mb_substr((string)$actor_name, 0, 150)]
    );
    $return_id = (int)mysqli_insert_id($conn);
    $stock_note = 'Purchase Return ' . $return_no . ' for ' . $purchase['purchase_no'];

    foreach($picked as $row){
        $line = $row['line'];
        $product_id = (int)$line['product_id'];
        $variant = (string)($line['variant_name'] ?? '');
        $qty = (int)$row['qty'];
        purchase_return_query(
            $conn,
            'INSERT INTO purchase_return_items(return_id,purchase_item_id,product_id,variant_name,quantity,unit_cost,total_amount) VALUES(?,?,?,?,?,?,?)',
            'iiisidd',
            [$return_id, (int)$line['id'], $product_id, $variant, $qty, (float)$line['unit_cost'], $row['total']]
        );

        // Take the units out of this purchase's own batches, newest first.
        $left = $qty;
        foreach(purchase_return_rows($conn, "SELECT id, remaining_quantity FROM stock_batches WHERE user_id=? AND source_type='purchase' AND source_id=? AND product_id=? AND variant_name=? AND remaining_quantity>0 ORDER BY id DESC FOR UPDATE", 'iiis', [$company_id, $purchase_id, $product_id, $variant]) as $batch){
            if($left <= 0){
                break;
            }
            $take = min($left, (int)floor((float)$batch['remaining_quantity']));
            if($take <= 0){
                continue;
            }
            purchase_return_query($conn, 'UPDATE stock_batches SET remaining_quantity=remaining_quantity-? WHERE id=?', 'di', [$take, (int)$batch['id']]);
            $left -= $take;
        }
        if($left > 0){
            throw new RuntimeException('Stock for ' . purchase_return_line_label($line) . ' changed. Refresh and try again.');
        }

        purchase_return_query($conn, 'UPDATE products SET current_stock=current_stock-? WHERE id=? AND user_id=?', 'iii', [$qty, $product_id, $company_id]);
        purchase_return_query(
            $conn,
            "INSERT INTO stock_transactions(user_id,product_id,transaction_type,quantity,note,txn_date,reference_no) VALUES(?,?,'stock_out',?,?,CURDATE(),?)",
            'iiiss',
            [$company_id, $product_id, $qty, mb_substr($stock_note, 0, 500), $return_no]
        );
    }

    if($due > 0){
        $new_due = round((float)$purchase['due_amount'] - $due, 2);
        $new_due = $new_due <= 0.009 ? 0 : $new_due;
        $status = $new_due <= 0 ? 'paid' : ((float)$purchase['paid_amount'] > 0 ? 'partial' : 'due');
        purchase_return_query($conn, 'UPDATE purchases SET due_amount=?, payment_status=? WHERE id=? AND user_id=?', 'dsii', [$new_due, $status, $purchase_id, $company_id]);
    }

    if($cash > 0){
        credit_wallet($conn, $wallet_id, $company_id, $cash);
        record_wallet_transaction($conn, $return_no, $company_id, $wallet_id, 'purchase_return', $return_id, $cash, 'Vendor refund - ' . $stock_note, date('Y-m-d'), $branch_id);
    }

    return ['id' => $return_id, 'return_no' => $return_no, 'total' => $total];
}

// Returns of one company, newest first; for the list page.
function purchase_return_list($conn, $company_id, $branch_id = 0, $supplier_id = 0)
{
    $sql = 'SELECT r.*, p.purchase_no, s.supplier_name, w.wallet_name FROM purchase_returns r
            LEFT JOIN purchases p ON p.id=r.purchase_id
            LEFT JOIN suppliers s ON s.id=r.supplier_id AND s.user_id=r.user_id
            LEFT JOIN wallets w ON w.id=r.wallet_id
            WHERE r.user_id=?';
    $types = 'i';
    $params = [(int)$company_id];
    if((int)$branch_id > 0){
        $sql .= ' AND r.branch_id=?';
        $types .= 'i';
        $params[] = (int)$branch_id;
    }
    if((int)$supplier_id > 0){
        $sql .= ' AND r.supplier_id=?';
        $types .= 'i';
        $params[] = (int)$supplier_id;
    }
    return purchase_return_rows($conn, $sql . ' ORDER BY r.id DESC LIMIT 500', $types, $params);
}

function purchase_return_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
