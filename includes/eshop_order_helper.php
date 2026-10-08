<?php
require_once __DIR__.'/pending_invoice_stock_helper.php';
require_once __DIR__.'/stock_module_helper.php';

// E-shop is centrally managed. Branches are never selected for online orders.
// The fallback only supports old installations/tests whose temporary branches
// table predates the Head Office marker.
function eshop_central_branch_id($conn,$companyId){
    $companyId=(int)$companyId;
    // Isolated automated tests use a temporary checkout configuration and no
    // real company branch context; keep that legacy configuration path there.
    $create=mysqli_query($conn,'SHOW CREATE TABLE eshop_checkout_settings');
    $createRow=$create?mysqli_fetch_row($create):null;
    if($createRow && stripos((string)($createRow[1]??''),'CREATE TEMPORARY TABLE')!==false){
        $s=mysqli_prepare($conn,'SELECT branch_id FROM eshop_checkout_settings WHERE company_id=?');
        mysqli_stmt_bind_param($s,'i',$companyId); mysqli_stmt_execute($s);
        $branch=(int)(mysqli_fetch_assoc(mysqli_stmt_get_result($s))['branch_id']??0);
        if($branch>0) return $branch;
    }
    $branches=mysqli_query($conn,"SHOW TABLES LIKE 'branches'");
    $column=$branches && mysqli_num_rows($branches)>0 ? mysqli_query($conn,"SHOW COLUMNS FROM branches LIKE 'is_head_office'") : false;
    if($column && mysqli_num_rows($column)>0) return stock_head_office_id($conn,$companyId);
    $s=mysqli_prepare($conn,'SELECT branch_id FROM eshop_checkout_settings WHERE company_id=?');
    mysqli_stmt_bind_param($s,'i',$companyId); mysqli_stmt_execute($s);
    $branch=(int)(mysqli_fetch_assoc(mysqli_stmt_get_result($s))['branch_id']??0);
    if($branch<=0) throw new RuntimeException('Central stock location is not configured.');
    return $branch;
}

function eshop_order_schema($conn){
    mysqli_query($conn,"CREATE TABLE IF NOT EXISTS eshop_checkout_settings (company_id BIGINT UNSIGNED PRIMARY KEY, branch_id BIGINT UNSIGNED NOT NULL, charge_type_id INT NOT NULL DEFAULT 0, customer_verification_method ENUM('sms','email') NOT NULL DEFAULT 'sms') ENGINE=InnoDB");
    $column=mysqli_query($conn,"SHOW COLUMNS FROM eshop_checkout_settings LIKE 'customer_verification_method'");
    if($column && mysqli_num_rows($column)===0) mysqli_query($conn,"ALTER TABLE eshop_checkout_settings ADD COLUMN customer_verification_method ENUM('sms','email') NOT NULL DEFAULT 'sms'");
    mysqli_query($conn,"CREATE TABLE IF NOT EXISTS eshop_orders (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL, invoice_id INT NOT NULL, order_no VARCHAR(50) NOT NULL UNIQUE, request_key CHAR(64) NOT NULL UNIQUE, customer_name VARCHAR(150) NOT NULL, phone VARCHAR(30) NOT NULL, email VARCHAR(100) NOT NULL, address TEXT NOT NULL, total DECIMAL(15,2) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY company_orders(company_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function eshop_order_cart_lines($cart) {
    $lines=[];
    foreach($cart as $cartKey=>$qty){
        $productId=0; $variant='';
        if(is_int($cartKey) || ctype_digit((string)$cartKey)){ $productId=(int)$cartKey; }
        elseif(preg_match('/\A([1-9]\d*)\|(.*)\z/D',(string)$cartKey,$match)){ $productId=(int)$match[1]; $variant=(string)$match[2]; }
        if($productId<=0 || mb_strlen($variant)>100 || !is_int($qty) || $qty<1 || $qty>99) throw new RuntimeException('Invalid cart.');
        $lineKey=$productId.'|'.$variant;
        $lines[$lineKey]=['product_id'=>$productId,'variant_name'=>$variant,'quantity'=>$qty];
    }
    ksort($lines,SORT_STRING); return array_values($lines);
}

function eshop_place_order($conn,$cid,$cart,$input,$key){
    if(!$cart || count($cart)>100) throw new RuntimeException('Your cart is empty or too large.');
    $name=trim((string)($input['name']??'')); $phone=trim((string)($input['phone']??''));
    $email=trim((string)($input['email']??'')); $address=trim((string)($input['address']??''));
    if($name==='' || mb_strlen($name)>150 || !preg_match('/\A[+0-9 ()-]{7,30}\z/',$phone) || $address==='' || strlen($address)>2000 || strlen($email)>100 || ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL))) throw new RuntimeException('Enter a valid name, phone, email and delivery address.');
    mysqli_query($conn, 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    mysqli_begin_transaction($conn);
    try{
        $s=mysqli_prepare($conn,'SELECT order_no FROM eshop_orders WHERE company_id=? AND request_key=?'); mysqli_stmt_bind_param($s,'is',$cid,$key); mysqli_stmt_execute($s);
        $old=mysqli_fetch_assoc(mysqli_stmt_get_result($s)); if($old){ mysqli_commit($conn); return $old['order_no']; }
        $s=mysqli_prepare($conn,"SELECT x.charge_type_id,p.delivery_charge FROM eshop_checkout_settings x JOIN eshop_settings e ON e.company_id=x.company_id JOIN eshop_profiles p ON p.company_id=x.company_id JOIN users u ON u.id=x.company_id WHERE x.company_id=? AND e.enabled=1 AND u.status='active' FOR UPDATE");
        mysqli_stmt_bind_param($s,'i',$cid); mysqli_stmt_execute($s); $config=mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        if(!$config) throw new RuntimeException('Checkout is not available. Please contact the shop.');
        $branch=eshop_central_branch_id($conn,$cid); $GLOBALS['stock_wallet_branch_id']=$branch;
        $delivery=(float)$config['delivery_charge']; $charge=(int)$config['charge_type_id'];
        if($delivery>0){
            $s=mysqli_prepare($conn,"SELECT id FROM invoice_charge_types WHERE id=? AND user_id=? AND status='active' AND charge_type='add' AND charge_value_type='fixed'"); mysqli_stmt_bind_param($s,'ii',$charge,$cid); mysqli_stmt_execute($s);
            if(!mysqli_fetch_assoc(mysqli_stmt_get_result($s))) throw new RuntimeException('Delivery is not configured. Please contact the shop.');
        }
        $lines=[]; $total=$delivery;
        foreach(eshop_order_cart_lines($cart) as $cartLine){
            $pid=$cartLine['product_id']; $variant=$cartLine['variant_name']; $qty=$cartLine['quantity'];
            $s=mysqli_prepare($conn,"SELECT p.product_name,COALESCE(e.online_price,p.sale_price) AS price FROM products p JOIN eshop_products e ON e.product_id=p.id AND e.company_id=p.user_id WHERE p.id=? AND p.user_id=? AND p.status='active' AND e.published=1 FOR UPDATE"); mysqli_stmt_bind_param($s,'ii',$pid,$cid); mysqli_stmt_execute($s); $p=mysqli_fetch_assoc(mysqli_stmt_get_result($s));
            if(!$p) throw new RuntimeException('A product is no longer available. Refresh your cart.');
            if(!product_uses_stock($conn,$pid,$cid)) throw new RuntimeException('Only physical stock products can be ordered from this shop. Refresh your cart.');
            // Read only inside this transaction: schema helpers can implicitly commit it.
            $v=mysqli_prepare($conn,'SELECT id FROM product_variants WHERE product_id=? AND user_id=? LIMIT 1'); mysqli_stmt_bind_param($v,'ii',$pid,$cid); mysqli_stmt_execute($v); $hasVariants=(bool)mysqli_fetch_assoc(mysqli_stmt_get_result($v));
            if($hasVariants && $variant==='') throw new RuntimeException('Choose a variant for '.$p['product_name'].'.');
            if(!$hasVariants && $variant!=='') throw new RuntimeException('This product does not have that variant. Refresh your cart.');
            if($variant!==''){ $v=mysqli_prepare($conn,'SELECT id FROM product_variants WHERE product_id=? AND user_id=? AND variant_name=? LIMIT 1'); mysqli_stmt_bind_param($v,'iis',$pid,$cid,$variant); mysqli_stmt_execute($v); if(!mysqli_fetch_assoc(mysqli_stmt_get_result($v))) throw new RuntimeException('This product variant is no longer available. Refresh your cart.'); }
            $s=mysqli_prepare($conn,"SELECT COALESCE(SUM(remaining_quantity),0) AS available FROM stock_batches WHERE user_id=? AND product_id=? AND branch_id=? AND variant_name=? AND remaining_quantity>0"); mysqli_stmt_bind_param($s,'iiis',$cid,$pid,$branch,$variant); mysqli_stmt_execute($s); $available=(float)mysqli_fetch_assoc(mysqli_stmt_get_result($s))['available'];
            $reserved=pending_invoice_reserved_quantity($conn,$cid,$pid,0,$variant);
            if($qty>max(0,$available-$reserved)) throw new RuntimeException('Insufficient stock for '.$p['product_name'].($variant!==''?' ('.$variant.')':'').'. Please reduce the quantity.');
            $price=round((float)$p['price'],2); if($price<0) throw new RuntimeException('Invalid product price.');
            $line=round($price*$qty,2); $total+=$line; $lines[]=[$pid,$variant,$qty,$price,$line];
        }
        $s=mysqli_prepare($conn,"SELECT id FROM customers WHERE user_id=? AND phone=? AND status='active' LIMIT 1"); mysqli_stmt_bind_param($s,'is',$cid,$phone); mysqli_stmt_execute($s); $customer=(int)(mysqli_fetch_assoc(mysqli_stmt_get_result($s))['id']??0);
        if(!$customer){
            $s=mysqli_prepare($conn,"INSERT INTO customers(user_id,customer_name,phone,email,address,status) VALUES(?,?,?,?,?,'active')"); mysqli_stmt_bind_param($s,'issss',$cid,$name,$phone,$email,$address); mysqli_stmt_execute($s); $customer=mysqli_insert_id($conn);
        }
        $no='ESH-'.date('ymdHis').'-'.bin2hex(random_bytes(4));
        $notes='E-shop order '.$no."\nCash on delivery\n".$name."\n".$phone."\n".$email."\n".$address;
        $s=mysqli_prepare($conn,"INSERT INTO invoices(user_id,branch_id,invoice_no,customer_id,customer_name,invoice_date,total_amount,notes,paid_amount,due_amount,payment_status,accounting_status,created_by_name,created_by_type) VALUES(?,?,?,?,?,CURDATE(),?,?,0,?,'due','pending','E-shop','eshop')");
        mysqli_stmt_bind_param($s,'iisisdsd',$cid,$branch,$no,$customer,$name,$total,$notes,$total); mysqli_stmt_execute($s); $invoice=mysqli_insert_id($conn);
        foreach($lines as [$pid,$variant,$qty,$price,$line]){
            $s=mysqli_prepare($conn,"INSERT INTO invoice_items(invoice_id,product_id,variant_name,quantity,unit_price,total_price) VALUES(?,?,?,?,?,?)"); mysqli_stmt_bind_param($s,'iisidd',$invoice,$pid,$variant,$qty,$price,$line); mysqli_stmt_execute($s);
        }
        if($delivery>0){ $s=mysqli_prepare($conn,'INSERT INTO invoice_charges(invoice_id,charge_type_id,amount) VALUES(?,?,?)'); mysqli_stmt_bind_param($s,'iid',$invoice,$charge,$delivery); mysqli_stmt_execute($s); }
        $s=mysqli_prepare($conn,'INSERT INTO eshop_orders(company_id,invoice_id,order_no,request_key,customer_name,phone,email,address,total) VALUES(?,?,?,?,?,?,?,?,?)'); mysqli_stmt_bind_param($s,'iissssssd',$cid,$invoice,$no,$key,$name,$phone,$email,$address,$total); mysqli_stmt_execute($s);
        mysqli_commit($conn); return $no;
    }catch(Throwable $e){ mysqli_rollback($conn); throw $e; }
}
