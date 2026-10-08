<?php
// Read-only order reporting for the company E-shop dashboard.
function eshop_dashboard_summary($conn,$companyId) {
    $companyId=(int)$companyId;
    try { $hasRefunds=mysqli_query($conn,'SELECT 1 FROM sales_refunds LIMIT 1')!==false; }
    catch(Throwable $e) { $hasRefunds=false; }
    $refundJoin=$hasRefunds
        ? 'LEFT JOIN (SELECT invoice_id,COALESCE(SUM(amount),0) amount FROM sales_refunds WHERE user_id=? GROUP BY invoice_id) r ON r.invoice_id=i.id'
        : 'LEFT JOIN (SELECT 0 invoice_id,0 amount) r ON 1=0';
    $refundBind=$hasRefunds?'ii':'i';
    $sql="SELECT COUNT(*) orders_total,
          COALESCE(SUM(i.accounting_status='pending'),0) pending_confirmation,
          COALESCE(SUM(COALESCE(d.status,'new')='new'),0) delivery_new,
          COALESCE(SUM(COALESCE(d.status,'new')='processing'),0) delivery_processing,
          COALESCE(SUM(COALESCE(d.status,'new')='shipped'),0) delivery_shipped,
          COALESCE(SUM(COALESCE(d.status,'new')='delivered'),0) delivery_delivered,
          COALESCE(SUM(COALESCE(d.status,'new')='cancelled'),0) delivery_cancelled,
          COALESCE(SUM(CASE WHEN i.accounting_status='posted' THEN i.total_amount-COALESCE(r.amount,0) ELSE 0 END),0) net_confirmed_sales,
          COALESCE(SUM(COALESCE(r.amount,0)),0) refunded_total
          FROM eshop_orders o
          LEFT JOIN invoices i ON i.id=o.invoice_id AND i.user_id=o.company_id
          LEFT JOIN eshop_delivery d ON d.order_id=o.id $refundJoin
          WHERE o.company_id=?";
    $s=mysqli_prepare($conn,$sql);
    if($hasRefunds) mysqli_stmt_bind_param($s,$refundBind,$companyId,$companyId); else mysqli_stmt_bind_param($s,$refundBind,$companyId);
    mysqli_stmt_execute($s); $summary=mysqli_fetch_assoc(mysqli_stmt_get_result($s))?:[];
    foreach(['orders_total','pending_confirmation','delivery_new','delivery_processing','delivery_shipped','delivery_delivered','delivery_cancelled'] as $key) $summary[$key]=(int)($summary[$key]??0);
    foreach(['net_confirmed_sales','refunded_total'] as $key) $summary[$key]=(float)($summary[$key]??0);
    $s=mysqli_prepare($conn,"SELECT o.id,o.order_no,o.customer_name,o.total,o.created_at,COALESCE(d.status,'new') delivery_status,i.accounting_status,COALESCE(r.amount,0) refunded_amount FROM eshop_orders o LEFT JOIN invoices i ON i.id=o.invoice_id AND i.user_id=o.company_id LEFT JOIN eshop_delivery d ON d.order_id=o.id $refundJoin WHERE o.company_id=? ORDER BY o.id DESC LIMIT 5");
    if($hasRefunds) mysqli_stmt_bind_param($s,$refundBind,$companyId,$companyId); else mysqli_stmt_bind_param($s,$refundBind,$companyId);
    mysqli_stmt_execute($s); $summary['recent']=mysqli_fetch_all(mysqli_stmt_get_result($s),MYSQLI_ASSOC);
    return $summary;
}
