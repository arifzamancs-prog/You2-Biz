<?php

function eshop_notification_schema($conn)
{
    mysqli_query($conn,"CREATE TABLE IF NOT EXISTS eshop_notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        order_id BIGINT UNSIGNED NOT NULL,
        actor_id BIGINT UNSIGNED NOT NULL,
        request_key CHAR(64) NOT NULL UNIQUE,
        recipient VARCHAR(100) NOT NULL,
        delivery_status VARCHAR(20) NOT NULL,
        result VARCHAR(20) NOT NULL DEFAULT 'sending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY order_notifications(company_id,order_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function eshop_notification_content($order)
{
    $escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
    $labels=['new'=>'Pending confirmation','processing'=>'Processing','shipped'=>'Shipped','delivered'=>'Delivered','cancelled'=>'Cancelled'];
    $status=$labels[$order['delivery_status']]??'Pending confirmation';
    $body='<p>Dear '.$escape($order['customer_name']).',</p><p>Here is an update from <strong>'.$escape($order['company_name']).'</strong>.</p>';
    $body.='<p>Order: <strong>'.$escape($order['order_no']).'</strong><br>Status: <strong>'.$escape($status).'</strong></p>';
    $body.='<p>Order total: BDT '.number_format((float)$order['total'],2).'</p>';
    if($order['delivery_status']!=='cancelled' && $order['tracking']!=='') $body.='<p>Courier / tracking reference: '.$escape($order['tracking']).'</p>';
    // Fulfillment status is deliberately not a payment receipt.
    $body.='<p>For any questions, please contact the shop.</p>';
    return ['subject'=>'Order '.$order['order_no'].' — '.$status,'body'=>$body];
}

function eshop_notify_order($conn,$company_id,$order_id,$actor_id,$key,$sender=null)
{
    if(!preg_match('/\A[a-f0-9]{64}\z/',$key)) throw new RuntimeException('Refresh the order before sending.');
    $s=mysqli_prepare($conn,"SELECT o.*,u.name AS company_name,COALESCE(d.status,'new') AS delivery_status,COALESCE(d.tracking,'') AS tracking,i.id AS linked_invoice FROM eshop_orders o JOIN users u ON u.id=o.company_id LEFT JOIN eshop_delivery d ON d.order_id=o.id LEFT JOIN invoices i ON i.id=o.invoice_id AND i.user_id=o.company_id WHERE o.id=? AND o.company_id=?");
    mysqli_stmt_bind_param($s,'ii',$order_id,$company_id); mysqli_stmt_execute($s);
    $order=mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    if(!$order) throw new RuntimeException('Order not found.');
    if(!$order['linked_invoice'] && $order['delivery_status']!=='cancelled') throw new RuntimeException('Review the missing invoice before notifying this customer.');
    $recipient=trim((string)$order['email']);
    $valid_email=(bool)filter_var($recipient,FILTER_VALIDATE_EMAIL);
    $s=mysqli_prepare($conn,'INSERT IGNORE INTO eshop_notifications(company_id,order_id,actor_id,request_key,recipient,delivery_status) VALUES(?,?,?,?,?,?)');
    mysqli_stmt_bind_param($s,'iiisss',$company_id,$order_id,$actor_id,$key,$recipient,$order['delivery_status']); mysqli_stmt_execute($s);
    if(mysqli_stmt_affected_rows($s)!==1) throw new RuntimeException('This email request was already submitted. Check notification history.');
    $notification_id=mysqli_insert_id($conn);
    if(!$valid_email){
        $s=mysqli_prepare($conn,"UPDATE eshop_notifications SET result='invalid_email' WHERE id=? AND company_id=?"); mysqli_stmt_bind_param($s,'ii',$notification_id,$company_id); mysqli_stmt_execute($s);
        throw new RuntimeException('No valid email address was provided with this order.');
    }
    $content=eshop_notification_content($order);
    if($sender===null){ require_once __DIR__.'/smtp_mailer.php'; $sender='smtp_send_mail'; }
    $accepted=false;
    try{
        [$accepted]=$sender($recipient,$order['customer_name'],$content['subject'],$content['body'],[],['from_name'=>$order['company_name']]);
    }catch(Throwable $e){ $accepted=false; }
    $result=$accepted?'accepted':'failed';
    $s=mysqli_prepare($conn,'UPDATE eshop_notifications SET result=? WHERE id=? AND company_id=?'); mysqli_stmt_bind_param($s,'sii',$result,$notification_id,$company_id); mysqli_stmt_execute($s);
    if(!$accepted) throw new RuntimeException('Email could not be sent. Check SMTP settings and try again.');
    return 'Order update accepted by the mail server.';
}

// Call only after the order/status transaction has committed. Notification
// failure must never turn a successfully saved order into a checkout error.
function eshop_auto_notify($conn,$company_id,$order_id,$actor_id=0,$sender=null)
{
    try{
        $s=mysqli_prepare($conn,"SELECT COALESCE(d.status,'new') AS status FROM eshop_orders o LEFT JOIN eshop_delivery d ON d.order_id=o.id WHERE o.id=? AND o.company_id=?");
        mysqli_stmt_bind_param($s,'ii',$order_id,$company_id); mysqli_stmt_execute($s);
        $row=mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        if(!$row) return false;
        // Status transitions are forward-only; one automatic attempt per status.
        $key=hash('sha256','eshop-auto-v1:'.$company_id.':'.$order_id.':'.$row['status']);
        eshop_notify_order($conn,$company_id,$order_id,$actor_id,$key,$sender);
        return true;
    }catch(Throwable $error){
        return false;
    }
}

function eshop_auto_notify_reference($conn,$company_id,$order_no,$sender=null)
{
    try{
        $s=mysqli_prepare($conn,'SELECT id FROM eshop_orders WHERE company_id=? AND order_no=?');
        mysqli_stmt_bind_param($s,'is',$company_id,$order_no); mysqli_stmt_execute($s);
        $row=mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        return $row ? eshop_auto_notify($conn,$company_id,(int)$row['id'],0,$sender) : false;
    }catch(Throwable $error){ return false; }
}
