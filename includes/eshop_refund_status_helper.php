<?php
// Read-only projection of Sales records. Never creates a refund or changes delivery.
function eshop_order_refund_status($conn,$companyId,$orderId,$includeHistory=false) {
    $s=$conn->prepare('SELECT o.invoice_id,i.total_amount FROM eshop_orders o LEFT JOIN invoices i ON i.id=o.invoice_id AND i.user_id=o.company_id WHERE o.id=? AND o.company_id=?');
    $s->bind_param('ii',$orderId,$companyId); $s->execute();
    $order=$s->get_result()->fetch_assoc();
    if (!$order) throw new RuntimeException('Order not found.');
    $summary=['status'=>'none','label'=>'No refund recorded','amount'=>0.0,'count'=>0,'history'=>[]];
    // Existing installations remain readable before the first Sales refund visit.
    try { $hasRefunds=mysqli_query($conn,'SELECT 1 FROM sales_refunds LIMIT 1')!==false; }
    catch(Throwable $e) { $hasRefunds=false; }
    if (!$hasRefunds) return $summary;
    $s=$conn->prepare('SELECT COUNT(*) count,COALESCE(SUM(amount),0) amount FROM sales_refunds WHERE user_id=? AND invoice_id=?');
    $s->bind_param('ii',$companyId,$order['invoice_id']); $s->execute();
    $totals=$s->get_result()->fetch_assoc();
    $summary['amount']=(float)$totals['amount']; $summary['count']=(int)$totals['count'];
    if ($summary['count']>0) {
        $fullyRefunded=$order['total_amount']!==null && (float)$order['total_amount']>0 && round($summary['amount'],2)>=round((float)$order['total_amount'],2);
        $summary['status']=$fullyRefunded?'full':'partial';
        $summary['label']=$fullyRefunded?'Fully refunded':'Partially refunded';
        if ($order['total_amount']===null) {
            $summary['status']='recorded'; $summary['label']='Refund recorded — invoice unavailable';
        }
    }
    if ($includeHistory && $summary['count']>0) {
        $s=$conn->prepare('SELECT id,credit_invoice_id,amount,reason,created_at FROM sales_refunds WHERE user_id=? AND invoice_id=? ORDER BY id DESC LIMIT 50');
        $s->bind_param('ii',$companyId,$order['invoice_id']); $s->execute();
        $summary['history']=$s->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    return $summary;
}
