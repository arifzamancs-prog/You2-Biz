<?php
require_once __DIR__.'/../includes/app_config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/eshop_helper.php';
require_once __DIR__.'/../includes/eshop_catalog_helper.php';
require_once __DIR__.'/../includes/eshop_tracking_helper.php';
require_once __DIR__.'/../includes/eshop_order_helper.php';
require_once __DIR__.'/../includes/eshop_customer_auth_helper.php';
if(session_status() === PHP_SESSION_NONE) session_start();
header('Cache-Control: no-store');
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function shop_cart_key($productId,$variantName=''){ return (int)$productId.'|'.trim((string)$variantName); }
function shop_cart_key_parts($key){
    if(preg_match('/\A([1-9]\d*)\|(.*)\z/D',(string)$key,$match)) return [(int)$match[1],$match[2]];
    return [(int)$key,'']; // Backward compatibility with carts from before variants.
}
$slug = (string)($_GET['shop'] ?? '');
if(!eshop_valid_slug($slug)){ http_response_code(404); exit('Shop not found.'); }
foreach(['eshop_settings','eshop_profiles','eshop_products'] as $table){
    $exists = mysqli_query($conn,"SHOW TABLES LIKE '$table'");
    if(!mysqli_num_rows($exists)){ http_response_code(404); exit('Shop is not available yet.'); }
}
$stmt = mysqli_prepare($conn,"SELECT e.company_id,e.slug,pr.* FROM eshop_settings e JOIN eshop_profiles pr ON pr.company_id=e.company_id JOIN users u ON u.id=e.company_id WHERE e.slug=? AND e.enabled=1 AND u.status='active' LIMIT 1");
mysqli_stmt_bind_param($stmt,'s',$slug); mysqli_stmt_execute($stmt);
$shop = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if(!$shop){ http_response_code(404); exit('Shop is currently unavailable.'); }
$cid = (int)$shop['company_id'];
$base = app_path('shop/index.php').'?shop='.rawurlencode($slug);
if(empty($_SESSION['shop_cart_csrf'])) $_SESSION['shop_cart_csrf'] = bin2hex(random_bytes(32));
if(!isset($_SESSION['shop_carts'][$cid])) $_SESSION['shop_carts'][$cid] = [];
$cart =& $_SESSION['shop_carts'][$cid];
$checkout_error='';
$tracking_error=''; $tracked_order=null;
$checkout_ready=false; $auth_error=''; $auth_message='';
$table=mysqli_query($conn,"SHOW TABLES LIKE 'eshop_checkout_settings'");
if(mysqli_num_rows($table)){
    $check=mysqli_prepare($conn,'SELECT company_id FROM eshop_checkout_settings WHERE company_id=?'); mysqli_stmt_bind_param($check,'i',$cid); mysqli_stmt_execute($check); $checkout_ready=(bool)mysqli_fetch_assoc(mysqli_stmt_get_result($check));
}
// Provision the optional verification column/table only when a shop is opened.
eshop_order_schema($conn); eshop_customer_auth_schema($conn);
$verification_method=eshop_checkout_verification_method($conn,$cid);
$shop_customer=eshop_customer_session($cid);
if(empty($_SESSION['shop_order_key'][$cid])) $_SESSION['shop_order_key'][$cid]=bin2hex(random_bytes(32));
// Always read current published products and prices from this company.
function shop_product($conn,$cid,$id){
    $s = mysqli_prepare($conn,"SELECT p.id,p.product_name,p.photo_path,COALESCE(e.online_price,p.sale_price) AS price,e.description FROM eshop_products e JOIN products p ON p.id=e.product_id AND p.user_id=e.company_id JOIN product_categories c ON c.id=p.category_id AND c.category_type='stock_product' WHERE e.company_id=? AND p.id=? AND e.published=1 AND p.status='active'");
    mysqli_stmt_bind_param($s,'ii',$cid,$id); mysqli_stmt_execute($s);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($s));
}
function shop_photo($p){
    $file = basename((string)($p['photo_path'] ?? ''));
    return $file !== '' && is_file(__DIR__.'/../uploads/products/'.$file) ? app_path('uploads/products/'.rawurlencode($file)) : '';
}
function shop_logo($shop){ $file=basename((string)($shop['logo_path']??'')); return $file!=='' && is_file(__DIR__.'/../uploads/eshop_logos/'.$file)?app_path('uploads/eshop_logos/'.rawurlencode($file)):''; }
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if(!hash_equals($_SESSION['shop_cart_csrf'],(string)($_POST['csrf'] ?? ''))){ http_response_code(403); exit('Refresh the page and try again.'); }
    if(($_POST['action']??'')==='track'){
        $tracked_order=eshop_public_order_tracking($conn,$cid,(string)($_POST['order_no']??''),(string)($_POST['phone']??''));
        if(!$tracked_order) $tracking_error='We could not find an order with that reference and phone number.';
    }elseif(($_POST['action']??'')==='auth_send'){
        try{
            eshop_customer_auth_start($conn,$cid,$shop,$_POST);
            header('Location: '.$base.'&view=verify'); exit;
        }catch(RuntimeException $e){ $auth_error=$e->getMessage(); }
    }elseif(($_POST['action']??'')==='auth_verify'){
        try{
            eshop_customer_auth_verify($conn,$cid,(string)($_POST['code']??''));
            $_SESSION['shop_flash'][$cid]='Contact verified. You are signed in and can complete your order.';
            header('Location: '.$base.'&view=cart'); exit;
        }catch(RuntimeException $e){ $auth_error=$e->getMessage(); }
    }elseif(($_POST['action']??'')==='auth_logout'){
        eshop_customer_logout($cid); header('Location: '.$base); exit;
    }elseif(($_POST['action']??'')==='auth_login_send'){
        try{ eshop_customer_auth_login_start($conn,$cid,$shop,$_POST); header('Location: '.$base.'&view=verify'); exit; }
        catch(RuntimeException $e){ $auth_error=$e->getMessage(); }
    }elseif(($_POST['action']??'')==='checkout'){
        try{
            if(!$checkout_ready) throw new RuntimeException('Checkout is not available yet.');
            $shop_customer=eshop_customer_session($cid);
            if(!$shop_customer) throw new RuntimeException('Verify your contact before placing an order.');
            if($shop_customer['channel']==='sms' && eshop_customer_normalize_phone($_POST['phone']??'')!==$shop_customer['phone']) throw new RuntimeException('Use the verified phone number for this order.');
            if($shop_customer['channel']==='email' && strtolower(trim((string)($_POST['email']??'')))!==$shop_customer['email']) throw new RuntimeException('Use the verified email address for this order.');
            if(!hash_equals($_SESSION['shop_order_key'][$cid],(string)($_POST['order_key']??''))) throw new RuntimeException('Please refresh checkout before ordering.');
            require_once __DIR__.'/../includes/eshop_delivery_helper.php';
            require_once __DIR__.'/../includes/eshop_notification_helper.php';
            // Provision notification tables before the order transaction begins.
            eshop_delivery_schema($conn);
            eshop_notification_schema($conn);
            $order=eshop_place_order($conn,$cid,$cart,$_POST,$_SESSION['shop_order_key'][$cid]);
            eshop_customer_link_sales_customer($conn,$cid,(int)$shop_customer['id'],(string)$shop_customer['phone']);
            $_SESSION['shop_last_order'][$cid]=$order;
            $cart=[]; $_SESSION['shop_order_key'][$cid]=bin2hex(random_bytes(32));
            eshop_auto_notify_reference($conn,$cid,$order);
            header('Location: '.$base.'&ordered=1'); exit;
        }catch(mysqli_sql_exception $e){ $checkout_error='Order could not be saved. Please contact the shop or try again.'; }
        catch(RuntimeException $e){ $checkout_error=$e->getMessage(); }
    }else{
    $id = (int)($_POST['product_id'] ?? 0);
    $variant=trim((string)($_POST['variant_name']??''));
    $qty = filter_var($_POST['quantity'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>99]]);
    $action = $_POST['action'] ?? '';
    $cartKey=shop_cart_key($id,$variant);
    if($action === 'remove'){ unset($cart[$cartKey]); }
    elseif($qty !== false && $qty !== null && in_array($action,['add','update'],true) && shop_product($conn,$cid,$id) && eshop_catalog_is_physical_product($conn,$cid,$id)){
        $variants=eshop_catalog_variant_options($conn,$cid,$id); $validVariant=false;
        foreach($variants as $option){ if(hash_equals($option['name'],$variant)){ $validVariant=true; $available=(int)$option['available']; break; } }
        if($validVariant && (count($cart)<100 || isset($cart[$cartKey]))){
            $cart[$cartKey] = $action==='add' ? min(99,$available,($cart[$cartKey] ?? 0)+$qty) : min($available,$qty);
            if(!$cart[$cartKey]) unset($cart[$cartKey]);
        }
    }
    header('Location: '.$base.'&view=cart'); exit;
    }
}
$cart_rows=[]; $total=0; $count=0;
foreach($cart as $cartKey=>$qty){
    [$id,$variant]=shop_cart_key_parts($cartKey); $p = shop_product($conn,$cid,$id);
    $variants=$p && eshop_catalog_is_physical_product($conn,$cid,$id) ? eshop_catalog_variant_options($conn,$cid,$id) : [];
    $available=0; foreach($variants as $option){ if($option['name']===$variant){$available=(int)$option['available']; break;} }
    if(!$p || !$available){ unset($cart[$cartKey]); continue; }
    if($qty>$available) $cart[$cartKey]=$qty=$available;
    $p['available_quantity']=$available;
    $p['variant_name']=$variant; $p['cart_key']=$cartKey;
    $p['quantity']=$qty; $cart_rows[]=$p; $total+=(float)$p['price']*$qty; $count+=$qty;
}
$requestedView=(string)($_GET['view']??'');
$view = $checkout_error!=='' || $auth_error!==''?'cart':($requestedView==='verify'?'verify':($requestedView==='signin'?'signin':($requestedView==='cart'?'cart':($requestedView==='track' || $tracked_order!==null || $tracking_error!==''?'track':'catalog'))));
$detail = isset($_GET['product']) ? shop_product($conn,$cid,(int)$_GET['product']) : null;
if(isset($_GET['product']) && !$detail){ http_response_code(404); exit('Product not available.'); }
$search=mb_substr(trim((string)($_GET['q'] ?? '')),0,100);
$categoryId=max(0,(int)($_GET['category']??0));
$sort=(string)($_GET['sort']??'newest');
$sortOrder=['newest'=>'p.id DESC','price_low'=>'COALESCE(e.online_price,p.sale_price) ASC, p.id DESC','price_high'=>'COALESCE(e.online_price,p.sale_price) DESC, p.id DESC','name'=>'p.product_name ASC, p.id DESC'];
if(!isset($sortOrder[$sort])) $sort='newest';
$page=max(1,min(100000,(int)($_GET['page'] ?? 1))); $offset=($page-1)*24; $like='%'.$search.'%';
$s=mysqli_prepare($conn,"SELECT DISTINCT c.id,c.category_name FROM eshop_products e JOIN products p ON p.id=e.product_id AND p.user_id=e.company_id JOIN product_categories c ON c.id=p.category_id AND c.category_type='stock_product' AND c.status='active' WHERE e.company_id=? AND e.published=1 AND p.status='active' ORDER BY c.category_name");
mysqli_stmt_bind_param($s,'i',$cid); mysqli_stmt_execute($s); $categories=mysqli_fetch_all(mysqli_stmt_get_result($s),MYSQLI_ASSOC);
$categoryExists=false; foreach($categories as $category){ if((int)$category['id']===$categoryId){$categoryExists=true; break;} } if(!$categoryExists) $categoryId=0;
$s=mysqli_prepare($conn,"SELECT p.id,p.product_name,p.photo_path,COALESCE(e.online_price,p.sale_price) AS price,e.description FROM eshop_products e JOIN products p ON p.id=e.product_id AND p.user_id=e.company_id JOIN product_categories c ON c.id=p.category_id AND c.category_type='stock_product' WHERE e.company_id=? AND e.published=1 AND p.status='active' AND p.product_name LIKE ? AND (?=0 OR c.id=?) ORDER BY ".$sortOrder[$sort]." LIMIT 25 OFFSET ?");
mysqli_stmt_bind_param($s,'isiii',$cid,$like,$categoryId,$categoryId,$offset); mysqli_stmt_execute($s); $products=mysqli_fetch_all(mysqli_stmt_get_result($s),MYSQLI_ASSOC); $more=count($products)>24; $products=array_slice($products,0,24);
$catalog=$detail?[$detail]:$products;
foreach($catalog as $catalogKey=>$catalogProduct){
    $catalog[$catalogKey]['variants']=eshop_catalog_is_physical_product($conn,$cid,(int)$catalogProduct['id']) ? eshop_catalog_variant_options($conn,$cid,(int)$catalogProduct['id']) : [];
    $catalog[$catalogKey]['available_quantity']=array_sum(array_column($catalog[$catalogKey]['variants'],'available'));
}
if($detail) $detail=$catalog[0]; else $products=$catalog;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= h($shop['shop_name']) ?></title><link rel="stylesheet" href="<?= h(app_path('shop/style.css')) ?>?v=<?= (int)filemtime(__DIR__.'/style.css') ?>"></head><body>
<header><a class="brand" href="<?= h($base) ?>"><?php if(shop_logo($shop)){ ?><img class="shop-logo" style="width:42px;height:42px;max-width:42px;max-height:42px;object-fit:contain" src="<?= h(shop_logo($shop)) ?>" alt="<?= h($shop['shop_name']) ?> logo"><?php } ?><?= h($shop['shop_name']) ?></a><nav class="store-nav"><a href="<?= h($base.'&view=track') ?>">Track order</a><?php if($shop_customer){ ?><span class="signed-in">Hi, <?= h($shop_customer['name']) ?></span><form method="post" class="inline-form"><input type="hidden" name="action" value="auth_logout"><input type="hidden" name="csrf" value="<?= h($_SESSION['shop_cart_csrf']) ?>"><button class="quiet">Sign out</button></form><?php }else{ ?><a href="<?= h($base.'&view=signin') ?>">Sign in</a><?php } ?><a class="button" href="<?= h($base.'&view=cart') ?>">Cart · <?= $count ?></a></nav></header>
<main>
<?php if(isset($_GET['ordered'],$_SESSION['shop_last_order'][$cid])){ ?><section class="panel order-success" role="status"><span class="success-kicker">ORDER RECEIVED</span><h1>Thank you for your order</h1><p>Order reference: <strong><?= h($_SESSION['shop_last_order'][$cid]) ?></strong></p><p>Your order is pending confirmation. Payment method: cash on delivery.</p><p class="muted">Please save this reference. You will need it with your phone number to track delivery.</p><a class="button" href="<?= h($base.'&view=track') ?>">Track this order</a></section><?php } ?>
<?php if($checkout_error!==''){ ?><section class="panel" role="alert"><?= h($checkout_error) ?></section><?php } ?>
<?php if(!empty($_SESSION['shop_flash'][$cid])){ ?><section class="panel order-success" role="status"><?= h($_SESSION['shop_flash'][$cid]); unset($_SESSION['shop_flash'][$cid]); ?></section><?php } ?>
<?php if($auth_error!==''){ ?><section class="panel" role="alert"><?= h($auth_error) ?></section><?php } ?>
<?php if($view==='verify'){ ?><a href="<?= h($base.'&view=cart') ?>">← Back to cart</a><section class="panel"><h1>Verify your <?= $verification_method==='sms'?'phone':'email' ?></h1><p>We sent a 6-digit code to <?= h(($_SESSION['eshop_otp_pending'][$cid]['recipient']??'')) ?>. It expires in 10 minutes.</p><form method="post"><input type="hidden" name="action" value="auth_verify"><input type="hidden" name="csrf" value="<?= h($_SESSION['shop_cart_csrf']) ?>"><label>Verification code<br><input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus></label><p><button>Verify and continue</button></p></form></section>
<?php }elseif($view==='signin'){ ?><a href="<?= h($base) ?>">← Continue shopping</a><section class="panel"><h1>Sign in with a code</h1><p>Enter the <?= $verification_method==='sms'?'phone number':'email address' ?> used for a previous E-shop order. No password is required.</p><form method="post"><input type="hidden" name="action" value="auth_login_send"><input type="hidden" name="csrf" value="<?= h($_SESSION['shop_cart_csrf']) ?>"><label><?= $verification_method==='sms'?'Phone number':'Email address' ?><br><input name="<?= $verification_method==='sms'?'phone':'email' ?>" type="<?= $verification_method==='sms'?'tel':'email' ?>" required></label><p><button>Send sign-in code</button></p></form></section>
<?php }elseif($view==='track'){ ?>
<a href="<?= h($base) ?>">← Continue shopping</a><h1>Track your order</h1><section class="panel track-panel"><p>Enter the order reference from your confirmation and the phone number used at checkout.</p>
<form method="post"><input type="hidden" name="action" value="track"><input type="hidden" name="csrf" value="<?= h($_SESSION['shop_cart_csrf']) ?>"><p><label>Order reference<br><input name="order_no" maxlength="50" required value="<?= h($_POST['order_no']??'') ?>"></label></p><p><label>Phone number<br><input name="phone" type="tel" maxlength="30" required value="<?= h($_POST['phone']??'') ?>"></label></p><button>Track order</button></form></section>
<?php if($tracking_error!==''){ ?><section class="panel" role="alert"><?= h($tracking_error) ?></section><?php } ?>
<?php if($tracked_order){ ?><section class="panel" role="status"><h2>Order <?= h($tracked_order['order_no']) ?></h2><p>Hello <?= h($tracked_order['customer_name']) ?>. Placed: <?= h($tracked_order['created_at']) ?></p><div class="tracking-grid"><p><strong>Delivery</strong><br><?= h(ucfirst($tracked_order['delivery_status'])) ?><?= $tracked_order['tracking']!==''?'<br>Tracking: '.h($tracked_order['tracking']):'' ?></p><p><strong>Payment</strong><br><?= h($tracked_order['accounting_status']==='posted'?'Confirmed':'Pending confirmation') ?><br><?= h(ucfirst($tracked_order['payment_status']??'due')) ?></p><p><strong>Refund</strong><br><?= h($tracked_order['refund']['label']) ?><?php if($tracked_order['refund']['count']>0){ ?><br>BDT <?= number_format((float)$tracked_order['refund']['amount'],2) ?> refunded<?php } ?></p></div><h3>Order items</h3><?php if($tracked_order['items']){ ?><div class="order-items"><table><thead><tr><th>Product</th><th>Variant</th><th>Qty</th><th>Total</th></tr></thead><tbody><?php foreach($tracked_order['items'] as $item){ ?><tr><td><?= h($item['product_name']) ?></td><td><?= h($item['variant_name']?:'—') ?></td><td><?= (int)$item['quantity'] ?></td><td>BDT <?= number_format((float)$item['total_price'],2) ?></td></tr><?php } ?></tbody></table></div><?php }else{ ?><p class="muted">Order item details are unavailable.</p><?php } ?><p><strong>Order total:</strong> BDT <?= number_format((float)$tracked_order['total'],2) ?></p><?php if($tracked_order['history']){ ?><h3>Delivery updates</h3><ul><?php foreach($tracked_order['history'] as $event){ ?><li><?= h($event['created_at'].' — '.ucfirst($event['status']).($event['tracking']!==''?' — '.$event['tracking']:'')) ?></li><?php } ?></ul><?php } ?></section><?php } ?>
<?php }elseif($view==='cart'){ ?>
<a href="<?= h($base) ?>">← Continue shopping</a><h1>Your cart</h1>
<?php if(!$cart_rows){ ?><section class="panel">Your cart is empty. Explore our products to get started.</section><?php } ?>
<?php foreach($cart_rows as $p){ ?><section class="panel cart-row"><div><h2><?= h($p['product_name']) ?></h2><p>BDT <?= number_format((float)$p['price'],2) ?> each<?php if($p['variant_name']!==''){ ?><br>Variant: <?= h($p['variant_name']) ?><?php } ?><br><span class="muted"><?= (int)$p['available_quantity'] ?> available now</span></p></div><form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['shop_cart_csrf']) ?>"><input type="hidden" name="product_id" value="<?= $p['id'] ?>"><input type="hidden" name="variant_name" value="<?= h($p['variant_name']) ?>"><label>Quantity <input type="number" name="quantity" min="0" max="<?= (int)$p['available_quantity'] ?>" required value="<?= $p['quantity'] ?>"></label><button name="action" value="update">Update</button><button class="quiet" name="action" value="remove">Remove</button></form><strong>BDT <?= number_format((float)$p['price']*$p['quantity'],2) ?></strong></section><?php } ?>
<?php if($cart_rows){ ?><section class="panel"><h2>Subtotal · BDT <?= number_format($total,2) ?></h2><p>Delivery charge: BDT <?= number_format((float)$shop['delivery_charge'],2) ?></p><strong>Estimated total: BDT <?= number_format($total+(float)$shop['delivery_charge'],2) ?></strong><p class="muted">Items in your cart are not reserved until you place an order.</p></section><?php } ?>
<?php if($cart_rows && $checkout_ready && !$shop_customer){ ?><section class="panel"><h2>Verify to checkout</h2><p>For your security, verify your <?= $verification_method==='sms'?'delivery phone by SMS':'email address by email' ?>. A password is not needed; after verification you will be signed in automatically.</p><form method="post"><input type="hidden" name="action" value="auth_send"><input type="hidden" name="csrf" value="<?= h($_SESSION['shop_cart_csrf']) ?>">
<?php foreach(['name'=>'Full name','phone'=>'Delivery phone','email'=>$verification_method==='email'?'Email':'Email (optional)','address'=>'Delivery address'] as $field=>$label){ ?><p><label><?= $label ?><br><input style="width:100%" name="<?= $field ?>" type="<?= $field==='email'?'email':($field==='phone'?'tel':'text') ?>" maxlength="<?= $field==='address'?2000:($field==='phone'?30:($field==='email'?100:150)) ?>" <?= ($field==='email'?$verification_method==='email':true)?'required':'' ?> value="<?= h($_POST[$field]??'') ?>"></label></p><?php } ?><button>Send verification code</button></form></section><?php } ?>
<?php if($cart_rows && $checkout_ready && $shop_customer){ ?><section class="panel"><h2>Checkout</h2><p>Payment: Cash on delivery. Your order will be reviewed by the shop.</p><p class="muted">Verified <?= $shop_customer['channel']==='sms'?'phone':'email' ?>: <?= h($shop_customer[$shop_customer['channel']==='sms'?'phone':'email']) ?></p><form method="post"><input type="hidden" name="action" value="checkout"><input type="hidden" name="csrf" value="<?= h($_SESSION['shop_cart_csrf']) ?>"><input type="hidden" name="order_key" value="<?= h($_SESSION['shop_order_key'][$cid]) ?>">
<?php foreach(['name'=>'Full name','phone'=>'Phone','email'=>'Email','address'=>'Delivery address'] as $field=>$label){ $verified=($shop_customer['channel']==='sms' && $field==='phone') || ($shop_customer['channel']==='email' && $field==='email'); ?><p><label><?= $label ?><br><input style="width:100%" name="<?= $field ?>" type="<?= $field==='email'?'email':($field==='phone'?'tel':'text') ?>" maxlength="<?= $field==='address'?2000:($field==='phone'?30:($field==='email'?100:150)) ?>" <?= $field==='email' && !$verified?'':'required' ?> <?= $verified?'readonly':'' ?> value="<?= h($shop_customer[$field]??'') ?>"></label></p><?php } ?><button>Place order</button></form></section><?php } ?>
<?php if($cart_rows && !$checkout_ready){ ?><section class="panel checkout-unavailable" role="status"><h2>Checkout is temporarily unavailable</h2><p>This shop is preparing its online-order settings. Your cart is saved on this device; please contact the shop or check back shortly.</p></section><?php } ?>
<?php }elseif($view!=='track'){ ?>
<?php if(!$detail){ ?><section class="hero"><span>WELCOME TO OUR STORE</span><h1><?= h($shop['shop_name']) ?></h1><p><?= nl2br(h($shop['description'])) ?></p></section><section class="store-promises" aria-label="Shopping benefits"><div><strong>Cash on delivery</strong><span>Pay when your order arrives</span></div><div><strong>Live availability</strong><span>Only available stock can be ordered</span></div><div><strong>Easy tracking</strong><span>Follow delivery with your order reference</span></div></section><form class="search" method="get" action="<?= h(app_path('shop/index.php')) ?>"><input type="hidden" name="shop" value="<?= h($slug) ?>"><input aria-label="Search products" name="q" value="<?= h($search) ?>" placeholder="Find something you love…"><?php if($categories){ ?><select aria-label="Product category" name="category"><option value="0">All categories</option><?php foreach($categories as $category){ ?><option value="<?= (int)$category['id'] ?>" <?= (int)$category['id']===$categoryId?'selected':'' ?>><?= h($category['category_name']) ?></option><?php } ?></select><?php } ?><select aria-label="Sort products" name="sort"><option value="newest" <?= $sort==='newest'?'selected':'' ?>>Newest</option><option value="price_low" <?= $sort==='price_low'?'selected':'' ?>>Price: low to high</option><option value="price_high" <?= $sort==='price_high'?'selected':'' ?>>Price: high to low</option><option value="name" <?= $sort==='name'?'selected':'' ?>>Name: A to Z</option></select><button>Search</button><?php if($search!=='' || $categoryId || $sort!=='newest'){ ?><a class="button quiet-filter" href="<?= h($base) ?>">Clear</a><?php } ?></form><h2><?= $categoryId || $search!==''?'Filtered products':'Explore products' ?></h2><?php }else{ ?><p><a href="<?= h($base) ?>">← All products</a></p><?php } ?>
<div class="<?= $detail?'detail':'grid' ?>"><?php foreach($detail?[$detail]:$products as $p){ ?><article class="product">
<a href="<?= h($base.'&product='.$p['id']) ?>" class="photo"><?php if(shop_photo($p)){ ?><img loading="lazy" src="<?= h(shop_photo($p)) ?>" alt="<?= h($p['product_name']) ?>"><?php }else{ ?><span>No photo yet</span><?php } ?></a>
<div class="product-body"><h2><a href="<?= h($base.'&product='.$p['id']) ?>"><?= h($p['product_name']) ?></a></h2><p class="price">BDT <?= number_format((float)$p['price'],2) ?></p><?php if($detail){ ?><p><?= nl2br(h($p['description'])) ?></p><?php }elseif(trim((string)$p['description'])!==''){ ?><p class="product-description"><?= h(mb_strimwidth(trim((string)$p['description']),0,120,'…','UTF-8')) ?></p><?php } ?>
<?php if((int)$p['available_quantity']>0){ ?><p class="availability"><?= (int)$p['available_quantity'] ?> available</p><form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['shop_cart_csrf']) ?>"><input type="hidden" name="product_id" value="<?= $p['id'] ?>"><input type="hidden" name="action" value="add"><?php if(($p['variants'][0]['name']??'')!==''){ ?><label>Variant <select class="variant-picker" name="variant_name" required><?php foreach($p['variants'] as $option){ ?><option value="<?= h($option['name']) ?>" data-available="<?= min(99,(int)$option['available']) ?>" <?= (int)$option['available']<1?'disabled':'' ?>><?= h($option['name']) ?> — <?= (int)$option['available'] ?> available</option><?php } ?></select></label><?php }else{ ?><input type="hidden" name="variant_name" value=""><?php } ?><label class="qty">Qty <input aria-label="Quantity" class="variant-quantity" type="number" name="quantity" value="1" min="1" max="<?= min(99,(int)$p['available_quantity']) ?>" required></label><button>Add to cart</button></form><?php }else{ ?><p class="sold-out" role="status">Out of stock</p><?php } ?></div></article><?php } ?></div>
<?php if(!$detail && !$products){ ?><section class="panel">No products found. Please check back soon.</section><?php } ?>
<?php if(!$detail){ ?><nav class="pages"><?php if($page>1){ ?><a class="button" href="<?= h($base.'&'.http_build_query(['q'=>$search,'category'=>$categoryId,'sort'=>$sort,'page'=>$page-1])) ?>">Previous</a><?php } ?> <?php if($more){ ?><a class="button" href="<?= h($base.'&'.http_build_query(['q'=>$search,'category'=>$categoryId,'sort'=>$sort,'page'=>$page+1])) ?>">Next</a><?php } ?></nav><?php } ?>
<?php } ?>
<?php $shopPhoneLink=preg_replace('/[^0-9+]/','',(string)$shop['phone']); $shopHasEmail=(bool)filter_var($shop['email']??'',FILTER_VALIDATE_EMAIL); ?><footer><h2><?= h($shop['shop_name']) ?></h2><p><?= nl2br(h($shop['address'])) ?></p><p><?php if($shopPhoneLink!==''){ ?><a href="tel:<?= h($shopPhoneLink) ?>"><?= h($shop['phone']) ?></a><?php }elseif($shop['phone']!==''){ ?><?= h($shop['phone']) ?><?php } ?><?php if($shopPhoneLink!=='' && $shopHasEmail){ ?> · <?php } ?><?php if($shopHasEmail){ ?><a href="mailto:<?= h($shop['email']) ?>"><?= h($shop['email']) ?></a><?php } ?></p><?php if($shop['return_policy']){ ?><details><summary>Return policy</summary><p><?= nl2br(h($shop['return_policy'])) ?></p></details><?php } ?></footer>
</main><script>
document.querySelectorAll('.variant-picker').forEach(function(select){
    var quantity=select.form.querySelector('.variant-quantity');
    function syncQuantity(){
        var available=parseInt(select.options[select.selectedIndex].dataset.available||'1',10);
        quantity.max=Math.max(1,available);
        if(parseInt(quantity.value||'1',10)>available) quantity.value=Math.max(1,available);
    }
    select.addEventListener('change',syncQuantity); syncQuantity();
});
</script></body></html>
