<?php

function eshop_delivery_steps($status)
{
    return ['new'=>['processing'], 'processing'=>['shipped'], 'shipped'=>['delivered'], 'delivered'=>[]][$status] ?? [];
}

function eshop_delivery_schema($conn)
{
    mysqli_query($conn,"CREATE TABLE IF NOT EXISTS eshop_delivery (
        order_id BIGINT UNSIGNED PRIMARY KEY,
        status VARCHAR(20) NOT NULL DEFAULT 'new',
        tracking VARCHAR(200) NOT NULL DEFAULT '',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn,"CREATE TABLE IF NOT EXISTS eshop_delivery_history (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL,
        actor_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL,
        tracking VARCHAR(200) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY order_history(order_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function eshop_update_delivery($conn,$company_id,$order_id,$status,$tracking,$actor_id)
{
    if(!in_array($status,['new','processing','shipped','delivered'],true) || mb_strlen($tracking)>200){
        throw new RuntimeException('Enter a valid status and tracking reference (up to 200 characters).');
    }
    mysqli_begin_transaction($conn);
    try{
        $s=mysqli_prepare($conn,'SELECT invoice_id FROM eshop_orders WHERE id=? AND company_id=? FOR UPDATE');
        mysqli_stmt_bind_param($s,'ii',$order_id,$company_id); mysqli_stmt_execute($s);
        $order=mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        if(!$order) throw new RuntimeException('Order not found.');
        $s=mysqli_prepare($conn,'SELECT accounting_status FROM invoices WHERE id=? AND user_id=? FOR UPDATE');
        mysqli_stmt_bind_param($s,'ii',$order['invoice_id'],$company_id); mysqli_stmt_execute($s);
        $invoice=mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        if(!$invoice) throw new RuntimeException('The linked invoice is no longer available. Review this order in Sales.');
        $s=mysqli_prepare($conn,'SELECT status,tracking FROM eshop_delivery WHERE order_id=? FOR UPDATE');
        mysqli_stmt_bind_param($s,'i',$order_id); mysqli_stmt_execute($s);
        $previous=mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: ['status'=>'new','tracking'=>''];
        if($status!==$previous['status'] && !in_array($status,eshop_delivery_steps($previous['status']),true)) throw new RuntimeException('Status changed or transition is invalid. Refresh the order and try again.');
        if(in_array($status,['shipped','delivered'],true) && $invoice['accounting_status']!=='posted') throw new RuntimeException('Confirm the Sales invoice before shipping the order.');
        if($status===$previous['status'] && $tracking===$previous['tracking']){ mysqli_commit($conn); return; }
        $s=mysqli_prepare($conn,'INSERT INTO eshop_delivery(order_id,status,tracking) VALUES(?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),tracking=VALUES(tracking)');
        mysqli_stmt_bind_param($s,'iss',$order_id,$status,$tracking); mysqli_stmt_execute($s);
        $s=mysqli_prepare($conn,'INSERT INTO eshop_delivery_history(order_id,actor_id,status,tracking) VALUES(?,?,?,?)');
        mysqli_stmt_bind_param($s,'iiss',$order_id,$actor_id,$status,$tracking); mysqli_stmt_execute($s);
        mysqli_commit($conn);
    }catch(Throwable $e){ mysqli_rollback($conn); throw $e; }
}
