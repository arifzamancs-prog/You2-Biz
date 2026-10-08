<?php
require_once __DIR__.'/pending_invoice_stock_helper.php';
require_once __DIR__.'/eshop_order_helper.php';

// Public catalog availability is advisory only; checkout locks and validates again.
function eshop_catalog_available_quantity($conn,$companyId,$productId,$variantName='') {
    $companyId=(int)$companyId; $productId=(int)$productId; $variantName=trim((string)$variantName);
    if(mb_strlen($variantName)>100) return 0;
    try{ $branch=eshop_central_branch_id($conn,$companyId); }catch(Throwable $e){ return 0; }
    $s=mysqli_prepare($conn,"SELECT COALESCE(SUM(remaining_quantity),0) amount FROM stock_batches WHERE user_id=? AND product_id=? AND branch_id=? AND variant_name=? AND remaining_quantity>0");
    mysqli_stmt_bind_param($s,'iiis',$companyId,$productId,$branch,$variantName); mysqli_stmt_execute($s);
    $stock=(float)(mysqli_fetch_assoc(mysqli_stmt_get_result($s))['amount']??0);
    // Do not rely on an admin session's selected branch for a public storefront.
    $s=mysqli_prepare($conn,"SELECT COALESCE(SUM(ii.quantity),0) amount FROM invoice_items ii JOIN invoices i ON i.id=ii.invoice_id WHERE i.user_id=? AND i.branch_id=? AND ii.product_id=? AND ii.variant_name=? AND i.accounting_status='pending' AND ii.quantity>0");
    mysqli_stmt_bind_param($s,'iiis',$companyId,$branch,$productId,$variantName); mysqli_stmt_execute($s);
    $reserved=(float)(mysqli_fetch_assoc(mysqli_stmt_get_result($s))['amount']??0);
    return max(0,(int)floor($stock-$reserved));
}

function eshop_catalog_is_physical_product($conn,$companyId,$productId) {
    return product_uses_stock($conn,$productId,$companyId);
}

function eshop_catalog_variant_options($conn,$companyId,$productId) {
    if(!eshop_catalog_is_physical_product($conn,$companyId,$productId)) return [];
    $s=mysqli_prepare($conn,'SELECT id FROM product_variants WHERE product_id=? AND user_id=? LIMIT 1');
    if(!$s) return [];
    mysqli_stmt_bind_param($s,'ii',$productId,$companyId); mysqli_stmt_execute($s);
    if(!mysqli_fetch_assoc(mysqli_stmt_get_result($s))) return [['name'=>'','available'=>eshop_catalog_available_quantity($conn,$companyId,$productId)]];
    $s=mysqli_prepare($conn,'SELECT variant_name FROM product_variants WHERE product_id=? AND user_id=? ORDER BY id');
    mysqli_stmt_bind_param($s,'ii',$productId,$companyId); mysqli_stmt_execute($s); $result=mysqli_stmt_get_result($s);
    $variants=[]; while($row=mysqli_fetch_assoc($result)){ $name=trim((string)$row['variant_name']); if($name!=='') $variants[]=['name'=>$name,'available'=>eshop_catalog_available_quantity($conn,$companyId,$productId,$name)]; }
    return $variants;
}
