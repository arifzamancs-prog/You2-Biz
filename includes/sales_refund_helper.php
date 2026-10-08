<?php
// Schema work must happen before the refund transaction.
function sales_refund_schema($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS sales_refunds (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
        invoice_id INT NOT NULL, credit_invoice_id INT NOT NULL DEFAULT 0, branch_id BIGINT UNSIGNED NOT NULL,
        wallet_id BIGINT UNSIGNED NOT NULL, amount DECIMAL(15,2) NOT NULL,
        reason VARCHAR(1000) NOT NULL, actor_id BIGINT UNSIGNED NOT NULL,
        request_key CHAR(64) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY refund_request(user_id,request_key), KEY original_invoice(user_id,invoice_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS sales_refund_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, refund_id BIGINT UNSIGNED NOT NULL,
        invoice_item_id INT NOT NULL, quantity INT NOT NULL, restock TINYINT NOT NULL,
        KEY returned_item(invoice_item_id)
    ) ENGINE=InnoDB");
}

function sales_refund_query($conn, $sql, $types='', $values=[]) {
    $s=$conn->prepare($sql);
    if ($types!=='') $s->bind_param($types,...$values);
    $s->execute();
    return $s;
}

function sales_refund_protect_invoice($conn,$cid,$id) {
    if (!$conn->query("SHOW TABLES LIKE 'sales_refunds'")->num_rows) return;
    $row=sales_refund_query($conn,'SELECT id FROM sales_refunds WHERE user_id=? AND (invoice_id=? OR credit_invoice_id=?) LIMIT 1','iii',[$cid,$id,$id])->get_result()->fetch_assoc();
    if ($row) throw new RuntimeException('An invoice linked to a refund cannot be edited or deleted.');
}

function sales_issue_refund($conn,$cid,$branch,$invoiceId,$actor,$walletId,$amount,$reason,$items,$key) {
    if (!preg_match('/\A\d{1,10}(?:\.\d{1,2})?\z/',(string)$amount) || (float)$amount<=0) throw new RuntimeException('Enter a positive refund amount with up to two decimal places.');
    $amount=round((float)$amount,2); $reason=trim($reason);
    if ($reason==='' || mb_strlen($reason)>1000 || !preg_match('/\A[a-f0-9]{64}\z/',$key) || !is_array($items)) throw new RuntimeException('Enter a reason and reload the refund form.');
    $conn->begin_transaction();
    try {
        $invoice=sales_refund_query($conn,'SELECT * FROM invoices WHERE id=? AND user_id=? AND branch_id=? FOR UPDATE','iii',[$invoiceId,$cid,$branch])->get_result()->fetch_assoc();
        if (!$invoice || $invoice['accounting_status']!=='posted' || (float)$invoice['total_amount']<=0 || (int)$invoice['customer_id']<=0) throw new RuntimeException('Select a confirmed customer sale in the current branch.');
        $old=sales_refund_query($conn,'SELECT id,invoice_id FROM sales_refunds WHERE user_id=? AND request_key=?','is',[$cid,$key])->get_result()->fetch_assoc();
        if ($old) {
            if ((int)$old['invoice_id']!==$invoiceId) throw new RuntimeException('Refund key belongs to another invoice.');
            $conn->commit(); return (int)$old['id'];
        }
        $paid=(float)sales_refund_query($conn,'SELECT COALESCE(SUM(amount),0) total FROM customer_payments WHERE user_id=? AND invoice_id=? AND branch_id=?','iii',[$cid,$invoiceId,$branch])->get_result()->fetch_assoc()['total'];
        $refunded=(float)sales_refund_query($conn,'SELECT COALESCE(SUM(amount),0) total FROM sales_refunds WHERE user_id=? AND invoice_id=?','ii',[$cid,$invoiceId])->get_result()->fetch_assoc()['total'];
        if ($amount>round(min((float)$invoice['total_amount'],$paid)-$refunded,2)) throw new RuntimeException('Refund exceeds the remaining recorded payment for this invoice.');
        $wallet=sales_refund_query($conn,"SELECT balance FROM wallets WHERE id=? AND user_id=? AND branch_id=? AND status='active' FOR UPDATE",'iii',[$walletId,$cid,$branch])->get_result()->fetch_assoc();
        if (!$wallet || (float)$wallet['balance']<$amount) throw new RuntimeException('Select an active branch wallet with sufficient balance.');
        $lines=[]; ksort($items);
        foreach ($items as $itemId=>$input) {
            if (!is_array($input)) throw new RuntimeException('Invalid return item.');
            $qty=filter_var($input['quantity']??null,FILTER_VALIDATE_INT);
            if ($qty===0) continue;
            if ($qty===false || $qty<0) throw new RuntimeException('Return quantity must be a whole positive number.');
            $line=sales_refund_query($conn,'SELECT * FROM invoice_items WHERE id=? AND invoice_id=? FOR UPDATE','ii',[(int)$itemId,$invoiceId])->get_result()->fetch_assoc();
            if (!$line || (int)$line['quantity']<=0) throw new RuntimeException('Invalid original invoice item.');
            $returned=(int)sales_refund_query($conn,'SELECT COALESCE(SUM(ri.quantity),0) total FROM sales_refund_items ri JOIN sales_refunds r ON r.id=ri.refund_id WHERE ri.invoice_item_id=? AND r.user_id=?','ii',[(int)$itemId,$cid])->get_result()->fetch_assoc()['total'];
            if ($qty>(int)$line['quantity']-$returned) throw new RuntimeException('Return quantity exceeds the unreturned sold quantity.');
            $restock=!empty($input['restock']) ? 1 : 0;
            if ($restock) {
                $product=sales_refund_query($conn,"SELECT p.id,c.category_type FROM products p LEFT JOIN product_categories c ON c.id=p.category_id WHERE p.id=? AND p.user_id=? FOR UPDATE",'ii',[(int)$line['product_id'],$cid])->get_result()->fetch_assoc();
                if (!$product || $product['category_type']!=='stock_product') throw new RuntimeException('Only physical stock products can be restocked.');
            }
            $lines[]=[$line,$qty,$restock];
        }
        sales_refund_query($conn,'INSERT INTO sales_refunds(user_id,invoice_id,branch_id,wallet_id,amount,reason,actor_id,request_key) VALUES(?,?,?,?,?,?,?,?)','iiiidsis',[$cid,$invoiceId,$branch,$walletId,$amount,$reason,$actor,$key]);
        $refundId=$conn->insert_id; $number='REF-'.$refundId.'-'.bin2hex(random_bytes(4));
        $note='Refund '.$number.' for '.$invoice['invoice_no'].': '.$reason;
        // Credit note and negative receipt preserve the existing signed customer ledger.
        // No cash is counted twice and the original invoice remains unchanged.
        sales_refund_query($conn,"INSERT INTO invoices(user_id,branch_id,invoice_no,customer_id,customer_name,invoice_date,total_amount,paid_amount,due_amount,payment_status,accounting_status,notes,created_by_user_id,created_by_name,created_by_type) VALUES(?,?,?,?,?,CURDATE(),?,?,0,'paid','posted',?,?,'Admin refund','sales_refund')",'iisisddsi',[$cid,$branch,$number,(int)$invoice['customer_id'],$invoice['customer_name'],-$amount,-$amount,$note,$actor]);
        $creditId=$conn->insert_id;
        sales_refund_query($conn,'UPDATE sales_refunds SET credit_invoice_id=? WHERE id=?','ii',[$creditId,$refundId]);
        sales_refund_query($conn,'INSERT INTO customer_payments(user_id,branch_id,customer_id,invoice_id,amount,payment_date,note) VALUES(?,?,?,?,?,CURDATE(),?)','iiiids',[$cid,$branch,(int)$invoice['customer_id'],$creditId,-$amount,$note]);
        $receiptId=$conn->insert_id;
        sales_refund_query($conn,'UPDATE wallets SET balance=balance-? WHERE id=?','di',[$amount,$walletId]);
        // Reverse the existing receipt transaction type, keeping signed wallet reports consistent.
        sales_refund_query($conn,"INSERT INTO transactions(txn_no,user_id,branch_id,wallet_id,transaction_type,reference_id,amount,note,txn_date) VALUES(?,?,?,?,'receive_payment',?,?,?,CURDATE())",'siiiids',[$number,$cid,$branch,$walletId,$receiptId,-$amount,mb_substr($note,0,500)]);
        // Allocate the credit amount across its products so item-based sales reports
        // reconcile with the credit-note total, even for money-only adjustments.
        $creditLines=$lines;
        if (!$creditLines) {
            $originalItems=sales_refund_query($conn,'SELECT * FROM invoice_items WHERE invoice_id=? AND quantity>0 ORDER BY id','i',[$invoiceId])->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($originalItems as $originalItem) $creditLines[]=[$originalItem,0,0];
        }
        if (!$creditLines) throw new RuntimeException('The sale has no refundable product lines.');
        $weight=0;
        foreach ($creditLines as [$line,$qty,$restock]) $weight+=max(0.01,(float)$line['unit_price']*($qty?: (int)$line['quantity']));
        $cents=(int)round($amount*100); $remainingCents=$cents;
        foreach ($creditLines as $index=>[$line,$qty,$restock]) {
            $share=$index===count($creditLines)-1 ? $remainingCents : min($remainingCents,(int)round($cents*max(0.01,(float)$line['unit_price']*($qty?: (int)$line['quantity']))/$weight));
            $remainingCents-=$share;
            $cost=$restock ? round((float)($line['cost_amount']??0)/(int)$line['quantity']*$qty,2) : 0;
            sales_refund_query($conn,'INSERT INTO invoice_items(invoice_id,product_id,variant_name,quantity,unit_price,total_price,cost_amount) VALUES(?,?,?,?,?,?,?)','iisiddd',[$creditId,(int)$line['product_id'],$line['variant_name']??'',-$qty,$qty ? $share/100/$qty : 0,-$share/100,-$cost]);
        }
        foreach ($lines as [$line,$qty,$restock]) {
            sales_refund_query($conn,'INSERT INTO sales_refund_items(refund_id,invoice_item_id,quantity,restock) VALUES(?,?,?,?)','iiii',[$refundId,(int)$line['id'],$qty,$restock]);
            if (!$restock) continue;
            $cost=round((float)($line['cost_amount']??0)/(int)$line['quantity'],4);
            sales_refund_query($conn,"INSERT INTO stock_batches(user_id,branch_id,product_id,variant_name,source_type,source_id,source_no,quantity,remaining_quantity,unit_cost,batch_date) VALUES(?,?,?,?,'sales_refund',?,?,?,?,?,CURDATE())",'iiisisddd',[$cid,$branch,(int)$line['product_id'],$line['variant_name']??'',$refundId,$number,$qty,$qty,$cost]);
            sales_refund_query($conn,'UPDATE products SET current_stock=current_stock+? WHERE id=? AND user_id=?','iii',[$qty,(int)$line['product_id'],$cid]);
            sales_refund_query($conn,"INSERT INTO stock_transactions(user_id,product_id,transaction_type,quantity,note,txn_date,reference_no) VALUES(?,?,'stock_in',?,?,CURDATE(),?)",'iiiss',[$cid,(int)$line['product_id'],$qty,$note,$number]);
        }
        $conn->commit(); return $refundId;
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}
