<?php

function eshop_cancel_schema($conn)
{
    mysqli_query($conn,"CREATE TABLE IF NOT EXISTS eshop_cancellations (
        order_id BIGINT UNSIGNED PRIMARY KEY,
        actor_id BIGINT UNSIGNED NOT NULL,
        reason VARCHAR(500) NOT NULL,
        invoice_snapshot LONGTEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function eshop_cancel_pending_order($conn,$company_id,$order_id,$reason,$actor_id)
{
    $reason=trim($reason);
    if($reason==='' || mb_strlen($reason)>500) throw new RuntimeException('Enter a cancellation reason (up to 500 characters).');
    mysqli_begin_transaction($conn);
    try{
        $s=mysqli_prepare($conn,'SELECT invoice_id FROM eshop_orders WHERE id=? AND company_id=? FOR UPDATE');
        mysqli_stmt_bind_param($s,'ii',$order_id,$company_id); mysqli_stmt_execute($s);
        $order=mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        if(!$order) throw new RuntimeException('Order not found.');
        $s=mysqli_prepare($conn,'SELECT order_id FROM eshop_cancellations WHERE order_id=?'); mysqli_stmt_bind_param($s,'i',$order_id); mysqli_stmt_execute($s);
        if(mysqli_fetch_assoc(mysqli_stmt_get_result($s))){ mysqli_commit($conn); return; }
        $invoice_id=(int)$order['invoice_id'];
        $s=mysqli_prepare($conn,'SELECT * FROM invoices WHERE id=? AND user_id=? FOR UPDATE'); mysqli_stmt_bind_param($s,'ii',$invoice_id,$company_id); mysqli_stmt_execute($s);
        $invoice=mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        if(!$invoice || $invoice['accounting_status']!=='pending' || (float)$invoice['paid_amount']!=0) throw new RuntimeException('Only an unpaid Pending invoice can be cancelled here. Review confirmed invoices in Sales.');
        $s=mysqli_prepare($conn,'SELECT status FROM eshop_delivery WHERE order_id=? FOR UPDATE'); mysqli_stmt_bind_param($s,'i',$order_id); mysqli_stmt_execute($s);
        $status=mysqli_fetch_assoc(mysqli_stmt_get_result($s))['status']??'new';
        if(!in_array($status,['new','processing'],true)) throw new RuntimeException('Only new or processing orders can be cancelled.');
        // A pending invoice should have no posted accounting records. Never reverse them here.
        $s=mysqli_prepare($conn,'SELECT id FROM customer_payments WHERE invoice_id=? LIMIT 1'); mysqli_stmt_bind_param($s,'i',$invoice_id); mysqli_stmt_execute($s);
        if(mysqli_fetch_assoc(mysqli_stmt_get_result($s))) throw new RuntimeException('This invoice has payment records. Review it in Sales.');
        $s=mysqli_prepare($conn,"SELECT id FROM transactions WHERE user_id=? AND reference_id=? AND transaction_type='sales_invoice' LIMIT 1"); mysqli_stmt_bind_param($s,'ii',$company_id,$invoice_id); mysqli_stmt_execute($s);
        if(mysqli_fetch_assoc(mysqli_stmt_get_result($s))) throw new RuntimeException('This invoice has accounting records. Review it in Sales.');
        $s=mysqli_prepare($conn,'SELECT ii.*,p.product_name FROM invoice_items ii LEFT JOIN products p ON p.id=ii.product_id AND p.user_id=? WHERE ii.invoice_id=?'); mysqli_stmt_bind_param($s,'ii',$company_id,$invoice_id); mysqli_stmt_execute($s); $items=mysqli_fetch_all(mysqli_stmt_get_result($s),MYSQLI_ASSOC);
        $s=mysqli_prepare($conn,'SELECT * FROM invoice_charges WHERE invoice_id=?'); mysqli_stmt_bind_param($s,'i',$invoice_id); mysqli_stmt_execute($s); $charges=mysqli_fetch_all(mysqli_stmt_get_result($s),MYSQLI_ASSOC);
        $snapshot=json_encode(['invoice'=>$invoice,'items'=>$items,'charges'=>$charges],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $s=mysqli_prepare($conn,'INSERT INTO eshop_cancellations(order_id,actor_id,reason,invoice_snapshot) VALUES(?,?,?,?)'); mysqli_stmt_bind_param($s,'iiss',$order_id,$actor_id,$reason,$snapshot); mysqli_stmt_execute($s);
        foreach(['invoice_items','invoice_charges'] as $table){ $s=mysqli_prepare($conn,"DELETE FROM $table WHERE invoice_id=?"); mysqli_stmt_bind_param($s,'i',$invoice_id); mysqli_stmt_execute($s); }
        $s=mysqli_prepare($conn,"DELETE FROM invoices WHERE id=? AND user_id=? AND accounting_status='pending'"); mysqli_stmt_bind_param($s,'ii',$invoice_id,$company_id); mysqli_stmt_execute($s);
        $s=mysqli_prepare($conn,"INSERT INTO eshop_delivery(order_id,status,tracking) VALUES(?,'cancelled','') ON DUPLICATE KEY UPDATE status='cancelled'"); mysqli_stmt_bind_param($s,'i',$order_id); mysqli_stmt_execute($s);
        $s=mysqli_prepare($conn,"INSERT INTO eshop_delivery_history(order_id,actor_id,status,tracking) VALUES(?,?,'cancelled','')"); mysqli_stmt_bind_param($s,'ii',$order_id,$actor_id); mysqli_stmt_execute($s);
        mysqli_commit($conn);
    }catch(Throwable $e){ mysqli_rollback($conn); throw $e; }
}
