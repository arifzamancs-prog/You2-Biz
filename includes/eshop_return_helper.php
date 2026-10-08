<?php
function eshop_return_schema($conn)
{
    mysqli_query($conn,"CREATE TABLE IF NOT EXISTS eshop_return_requests (
        order_id BIGINT UNSIGNED PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        actor_id BIGINT UNSIGNED NOT NULL,
        reason VARCHAR(1000) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY company_returns(company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function eshop_request_return($conn,$company_id,$order_id,$actor_id,$reason)
{
    $reason=trim($reason);
    if($reason==='' || mb_strlen($reason)>1000) throw new RuntimeException('Enter a return reason (up to 1,000 characters).');
    mysqli_begin_transaction($conn);
    try{
        $s=mysqli_prepare($conn,'SELECT invoice_id FROM eshop_orders WHERE id=? AND company_id=? FOR UPDATE'); mysqli_stmt_bind_param($s,'ii',$order_id,$company_id); mysqli_stmt_execute($s);
        $order=mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        if(!$order) throw new RuntimeException('Order not found.');
        $s=mysqli_prepare($conn,'SELECT accounting_status FROM invoices WHERE id=? AND user_id=? FOR UPDATE'); mysqli_stmt_bind_param($s,'ii',$order['invoice_id'],$company_id); mysqli_stmt_execute($s);
        $invoice=mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        if(!$invoice || $invoice['accounting_status']!=='posted') throw new RuntimeException('Return review is available only for a confirmed Sales invoice.');
        $s=mysqli_prepare($conn,'SELECT status FROM eshop_delivery WHERE order_id=? FOR UPDATE'); mysqli_stmt_bind_param($s,'i',$order_id); mysqli_stmt_execute($s);
        $status=mysqli_fetch_assoc(mysqli_stmt_get_result($s))['status']??'new';
        if(!in_array($status,['shipped','delivered'],true)) throw new RuntimeException('Only shipped or delivered orders can be submitted for return review.');
        $s=mysqli_prepare($conn,'SELECT order_id FROM eshop_return_requests WHERE order_id=?'); mysqli_stmt_bind_param($s,'i',$order_id); mysqli_stmt_execute($s);
        if(mysqli_fetch_assoc(mysqli_stmt_get_result($s))){ mysqli_commit($conn); return; }
        $s=mysqli_prepare($conn,'INSERT INTO eshop_return_requests(order_id,company_id,actor_id,reason) VALUES(?,?,?,?)'); mysqli_stmt_bind_param($s,'iiis',$order_id,$company_id,$actor_id,$reason); mysqli_stmt_execute($s);
        mysqli_commit($conn);
    }catch(Throwable $e){ mysqli_rollback($conn); throw $e; }
}
