<?php
require_once __DIR__.'/eshop_refund_status_helper.php';

function eshop_public_order_tracking($conn,$companyId,$orderNo,$phone) {
    $companyId=(int)$companyId; $orderNo=trim((string)$orderNo); $phone=trim((string)$phone);
    if($orderNo==='' || mb_strlen($orderNo)>50 || $phone==='' || mb_strlen($phone)>30) return null;
    $sql="SELECT o.id,o.invoice_id,o.order_no,o.customer_name,o.total,o.created_at,COALESCE(d.status,'new') delivery_status,COALESCE(d.tracking,'') tracking,i.accounting_status,i.payment_status,i.due_amount,i.total_amount
          FROM eshop_orders o
          LEFT JOIN eshop_delivery d ON d.order_id=o.id
          LEFT JOIN invoices i ON i.id=o.invoice_id AND i.user_id=o.company_id
          WHERE o.company_id=? AND o.order_no=? AND o.phone=? LIMIT 1";
    $s=mysqli_prepare($conn,$sql); mysqli_stmt_bind_param($s,'iss',$companyId,$orderNo,$phone); mysqli_stmt_execute($s);
    $order=mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    if(!$order) return null;
    $order['refund']=eshop_order_refund_status($conn,$companyId,(int)$order['id'],false);
    $s=mysqli_prepare($conn,'SELECT status,tracking,created_at FROM eshop_delivery_history WHERE order_id=? ORDER BY id ASC LIMIT 50');
    mysqli_stmt_bind_param($s,'i',$order['id']); mysqli_stmt_execute($s);
    $order['history']=mysqli_fetch_all(mysqli_stmt_get_result($s),MYSQLI_ASSOC);
    $order['items']=[];
    if((int)$order['invoice_id']>0){
        $s=mysqli_prepare($conn,'SELECT COALESCE(p.product_name,\'Product\') product_name,ii.variant_name,ii.quantity,ii.unit_price,ii.total_price FROM invoice_items ii LEFT JOIN products p ON p.id=ii.product_id AND p.user_id=? WHERE ii.invoice_id=? ORDER BY ii.id ASC');
        mysqli_stmt_bind_param($s,'ii',$companyId,$order['invoice_id']); mysqli_stmt_execute($s);
        $order['items']=mysqli_fetch_all(mysqli_stmt_get_result($s),MYSQLI_ASSOC);
    }
    return $order;
}
