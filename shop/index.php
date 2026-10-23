<?php
require_once __DIR__.'/../includes/app_config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/eshop_helper.php';
require_once __DIR__.'/../includes/eshop_catalog_helper.php';
require_once __DIR__.'/../includes/eshop_tracking_helper.php';
require_once __DIR__.'/../includes/eshop_order_helper.php';
require_once __DIR__.'/../includes/eshop_customer_auth_helper.php';
require_once __DIR__.'/../includes/eshop_storefront_helper.php';
if(session_status() === PHP_SESSION_NONE) session_start();
header('Cache-Control: no-store');
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($v){ return '৳'.number_format((float)$v, 0); }
function shop_cart_key($productId,$variantName=''){ return (int)$productId.'|'.trim((string)$variantName); }
function shop_cart_key_parts($key){
    if(preg_match('/\A([1-9]\d*)\|(.*)\z/D',(string)$key,$match)) return [(int)$match[1],$match[2]];
    return [(int)$key,'']; // Backward compatibility with carts from before variants.
}
function shop_not_found($message='Page not found.'){ http_response_code(404); exit(h($message)); }

$slug = (string)($_GET['shop'] ?? '');
if(!eshop_valid_slug($slug)) shop_not_found('Shop not found.');
foreach(['eshop_settings','eshop_profiles','eshop_products'] as $table){
    $exists = mysqli_query($conn,"SHOW TABLES LIKE '$table'");
    if(!mysqli_num_rows($exists)) shop_not_found('Shop is not available yet.');
}
eshop_storefront_schema($conn);
$stmt = mysqli_prepare($conn,"SELECT e.company_id,e.slug,pr.* FROM eshop_settings e JOIN eshop_profiles pr ON pr.company_id=e.company_id JOIN users u ON u.id=e.company_id WHERE e.slug=? AND e.enabled=1 AND u.status='active' LIMIT 1");
mysqli_stmt_bind_param($stmt,'s',$slug); mysqli_stmt_execute($stmt);
$shop = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if(!$shop) shop_not_found('Shop is currently unavailable.');
$shop = eshop_storefront_profile($conn,$shop);
$cid = (int)$shop['company_id'];

$root = app_path('shop/'.rawurlencode($slug));
$absoluteRoot = app_url('shop/'.rawurlencode($slug));
function shop_url($route='',$params=[]){
    global $root;
    $url=$root.'/'.ltrim((string)$route,'/');
    $params=array_filter($params,function($v){ return $v!=='' && $v!==null && $v!==0 && $v!==[]; });
    return $params ? $url.'?'.http_build_query($params) : $url;
}
function product_url($p){ return shop_url('p/'.(int)$p['id'].'-'.eshop_storefront_slugify($p['product_name'])); }
function category_url($id,$name,$params=[]){ return shop_url('c/'.(int)$id.'-'.eshop_storefront_slugify($name),$params); }
// Turns an app-relative path such as /You2-Biz/shop/x into a full URL for SEO tags.
function shop_absolute($path){ $base=rtrim(app_path(''),'/'); return app_url(ltrim(substr((string)$path,strlen($base)),'/')); }
function shop_logo($shop){ $file=basename((string)($shop['logo_path']??'')); return $file!=='' && is_file(__DIR__.'/../uploads/eshop_logos/'.$file)?app_path('uploads/eshop_logos/'.rawurlencode($file)):''; }
// Redirects only ever stay inside this shop.
function shop_safe_return($url,$fallback){
    global $root;
    $url=(string)$url;
    if(preg_match('/[\r\n]/',$url) || strpos($url,$root.'/')!==0) return $fallback;
    return $url;
}
function shop_toast($message){ global $cid; $_SESSION['shop_toast'][$cid]=$message; }

if(empty($_SESSION['shop_cart_csrf'])) $_SESSION['shop_cart_csrf'] = bin2hex(random_bytes(32));
foreach(['shop_carts','shop_wishlist','shop_compare'] as $bucket){ if(!isset($_SESSION[$bucket][$cid]) || !is_array($_SESSION[$bucket][$cid])) $_SESSION[$bucket][$cid] = []; }
$cart =& $_SESSION['shop_carts'][$cid];
$wishlist =& $_SESSION['shop_wishlist'][$cid];
$compare =& $_SESSION['shop_compare'][$cid];
$checkout_error=''; $tracking_error=''; $tracked_order=null; $auth_error='';
$checkout_ready=false;
$table=mysqli_query($conn,"SHOW TABLES LIKE 'eshop_checkout_settings'");
if(mysqli_num_rows($table)){
    $check=mysqli_prepare($conn,'SELECT company_id FROM eshop_checkout_settings WHERE company_id=?'); mysqli_stmt_bind_param($check,'i',$cid); mysqli_stmt_execute($check); $checkout_ready=(bool)mysqli_fetch_assoc(mysqli_stmt_get_result($check));
}
// Provision the optional verification column/table only when a shop is opened.
eshop_order_schema($conn); eshop_customer_auth_schema($conn);
$verification_method=eshop_checkout_verification_method($conn,$cid);
$shop_customer=eshop_customer_session($cid);
if(empty($_SESSION['shop_order_key'][$cid])) $_SESSION['shop_order_key'][$cid]=bin2hex(random_bytes(32));
$zones=eshop_storefront_zones($conn,$cid);

// Routing: pretty URLs from shop/.htaccess, plus the older ?view= / ?product= links.
$route=trim((string)($_GET['route']??''),'/');
if($route==='' && isset($_GET['product'])) $route='p/'.(int)$_GET['product'];
if($route==='' && in_array($_GET['view']??'',['cart','track','signin','verify'],true)) $route=$_GET['view'];
if($route==='' && isset($_GET['ordered'])) $route='order-success';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if(!hash_equals($_SESSION['shop_cart_csrf'],(string)($_POST['csrf'] ?? ''))){ http_response_code(403); exit('Refresh the page and try again.'); }
    $action=(string)($_POST['action']??'');
    $back=shop_safe_return($_POST['return_to']??'',shop_url('cart'));
    if($action==='track'){
        $route='track';
        $tracked_order=eshop_public_order_tracking($conn,$cid,(string)($_POST['order_no']??''),(string)($_POST['phone']??''));
        if(!$tracked_order) $tracking_error='We could not find an order with that reference and phone number.';
    }elseif($action==='auth_send'){
        try{ eshop_customer_auth_start($conn,$cid,$shop,$_POST); header('Location: '.shop_url('verify')); exit; }
        catch(RuntimeException $e){ $auth_error=$e->getMessage(); $route='checkout'; }
    }elseif($action==='auth_verify'){
        try{
            eshop_customer_auth_verify($conn,$cid,(string)($_POST['code']??''));
            shop_toast('Contact verified. You are signed in.');
            header('Location: '.($cart?shop_url('checkout'):shop_url())); exit;
        }catch(RuntimeException $e){ $auth_error=$e->getMessage(); $route='verify'; }
    }elseif($action==='auth_logout'){
        eshop_customer_logout($cid); header('Location: '.shop_url()); exit;
    }elseif($action==='auth_login_send'){
        try{ eshop_customer_auth_login_start($conn,$cid,$shop,$_POST); header('Location: '.shop_url('verify')); exit; }
        catch(RuntimeException $e){ $auth_error=$e->getMessage(); $route='signin'; }
    }elseif($action==='checkout'){
        $route='checkout';
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
            $input=$_POST;
            // With delivery areas configured, the chosen area always decides the charge.
            if($zones) $input['delivery_zone_id']=(int)($_POST['delivery_zone_id']??0); else unset($input['delivery_zone_id']);
            $order=eshop_place_order($conn,$cid,$cart,$input,$_SESSION['shop_order_key'][$cid]);
            eshop_customer_link_sales_customer($conn,$cid,(int)$shop_customer['id'],(string)$shop_customer['phone']);
            $_SESSION['shop_last_order'][$cid]=$order;
            $cart=[]; $_SESSION['shop_order_key'][$cid]=bin2hex(random_bytes(32));
            eshop_auto_notify_reference($conn,$cid,$order);
            header('Location: '.shop_url('order-success')); exit;
        }catch(mysqli_sql_exception $e){ $checkout_error='Order could not be saved. Please contact the shop or try again.'; }
        catch(RuntimeException $e){ $checkout_error=$e->getMessage(); }
    }elseif($action==='wishlist' || $action==='compare'){
        $id=(int)($_POST['product_id']??0);
        $list =& $_SESSION['shop_'.$action][$cid];
        if(in_array($id,$list,true)){ $list=array_values(array_diff($list,[$id])); shop_toast($action==='wishlist'?'Removed from wishlist.':'Removed from compare.'); }
        elseif(eshop_storefront_product($conn,$cid,$id)){
            if($action==='compare' && count($list)>=4) shop_toast('You can compare up to 4 products.');
            else{ $list[]=$id; shop_toast($action==='wishlist'?'Added to wishlist.':'Added to compare.'); }
        }
        unset($list);
        header('Location: '.shop_safe_return($_POST['return_to']??'',shop_url())); exit;
    }elseif($action==='compare_clear'){
        $compare=[]; header('Location: '.shop_url('compare')); exit;
    }elseif($action==='cart_clear'){
        $cart=[]; header('Location: '.shop_url('cart')); exit;
    }else{
        $id = (int)($_POST['product_id'] ?? 0);
        $variant=trim((string)($_POST['variant_name']??''));
        $qty = filter_var($_POST['quantity'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>99]]);
        $cartKey=shop_cart_key($id,$variant);
        $adding=in_array($action,['add','buy_now'],true);
        if($action === 'remove'){ unset($cart[$cartKey]); }
        elseif($qty !== false && $qty !== null && ($adding || $action==='update') && eshop_storefront_product($conn,$cid,$id) && eshop_catalog_is_physical_product($conn,$cid,$id)){
            $variants=eshop_catalog_variant_options($conn,$cid,$id); $validVariant=false; $available=0;
            foreach($variants as $option){ if(hash_equals($option['name'],$variant)){ $validVariant=true; $available=(int)$option['available']; break; } }
            if($validVariant && (count($cart)<100 || isset($cart[$cartKey]))){
                $cart[$cartKey] = $adding ? min(99,$available,($cart[$cartKey] ?? 0)+$qty) : min($available,$qty);
                if(!$cart[$cartKey]) unset($cart[$cartKey]);
                elseif($adding) shop_toast('Added to cart.');
            }
        }
        if($action==='buy_now') $back=shop_url('checkout');
        elseif($action!=='add') $back=shop_url('cart');
        header('Location: '.$back); exit;
    }
}

// Cart rows always use current published prices and live availability.
$cart_rows=[]; $total=0; $count=0;
foreach($cart as $cartKey=>$qty){
    [$id,$variant]=shop_cart_key_parts($cartKey); $p = eshop_storefront_product($conn,$cid,$id);
    $available=0; foreach(($p['variants']??[]) as $option){ if($option['name']===$variant){$available=(int)$option['available']; break;} }
    if(!$p || !$available){ unset($cart[$cartKey]); continue; }
    if($qty>$available) $cart[$cartKey]=$qty=$available;
    $p['available_quantity']=$available;
    $p['variant_name']=$variant; $p['cart_key']=$cartKey;
    $p['quantity']=$qty; $cart_rows[]=$p; $total+=(float)$p['price']*$qty; $count+=$qty;
}
$categories=eshop_storefront_categories($conn,$cid);
$toast=$_SESSION['shop_toast'][$cid]??''; unset($_SESSION['shop_toast'][$cid]);
$currentUrl=$root.'/'.$route.(($_SERVER['QUERY_STRING']??'')!=='' ? '?'.http_build_query(array_diff_key($_GET,['shop'=>1,'route'=>1])) : '');
$currentUrl=rtrim($currentUrl,'?');

// Page metadata defaults; each view may refine them.
$meta=['title'=>$shop['shop_name'],'description'=>mb_strimwidth(trim((string)$shop['description'])?:$shop['shop_name'].' online shop',0,300,'…','UTF-8'),'canonical'=>$absoluteRoot.'/','image'=>'','noindex'=>false,'jsonld'=>[]];
$perPage=20;

if($route===''){
    $view='home';
    $banners=eshop_storefront_banners($conn,$cid);
    [$featured]=eshop_storefront_products($conn,$cid,['featured'=>1],10);
    [$offers]=eshop_storefront_products($conn,$cid,['offers'=>1,'sort'=>'featured'],10);
    [$newest]=eshop_storefront_products($conn,$cid,['sort'=>'newest'],10);
    $categorySections=[];
    foreach(array_slice($categories,0,4,true) as $category){
        [$rows]=eshop_storefront_products($conn,$cid,['category'=>$category['id']],5);
        if($rows) $categorySections[]=['category'=>$category,'products'=>$rows];
    }
    $meta['jsonld'][]=['@context'=>'https://schema.org','@type'=>'Store','name'=>$shop['shop_name'],'url'=>$absoluteRoot.'/','telephone'=>$shop['phone'],'address'=>$shop['address'],'image'=>shop_logo($shop)!==''?shop_absolute(shop_logo($shop)):null];
}elseif(in_array($route,['products','offers','search'],true) || preg_match('#\Ac/(\d+)(?:-[a-z0-9-]*)?\z#',$route,$categoryMatch)){
    $view='listing';
    $categoryId=isset($categoryMatch[1]) ? (int)$categoryMatch[1] : 0;
    $currentCategory=$categories[$categoryId]??null;
    if($categoryId && !$currentCategory) shop_not_found('Category not found.');
    $filters=[
        'q'=>mb_substr(trim((string)($_GET['q']??'')),0,100),
        'category'=>$categoryId,
        'sub'=>mb_substr(trim((string)($_GET['sub']??'')),0,100),
        'brands'=>array_slice(array_map(function($b){ return mb_substr(trim((string)$b),0,100); },(array)($_GET['brand']??[])),0,20),
        'min'=>is_numeric($_GET['min']??null)?max(0,(float)$_GET['min']):'',
        'max'=>is_numeric($_GET['max']??null)?max(0,(float)$_GET['max']):'',
        'offers'=>$route==='offers',
        'sort'=>in_array($_GET['sort']??'',['newest','price_low','price_high','name'],true)?$_GET['sort']:'newest',
    ];
    $page=max(1,min(100000,(int)($_GET['page']??1)));
    [$products,$productTotal]=eshop_storefront_products($conn,$cid,$filters,$perPage,($page-1)*$perPage);
    $pageCount=max(1,(int)ceil($productTotal/$perPage));
    $brands=eshop_storefront_brands($conn,$cid,$categoryId);
    $listingTitle=$currentCategory ? $currentCategory['name'].($filters['sub']!==''?' › '.$filters['sub']:'') : ($route==='offers'?'Special Offers':($filters['q']!==''?'Search results for “'.$filters['q'].'”':'All Products'));
    $listingBase=$currentCategory ? category_url($currentCategory['id'],$currentCategory['name']) : shop_url($route);
    $meta['title']=$listingTitle.' | '.$shop['shop_name'];
    $meta['canonical']=shop_absolute($listingBase).($page>1?'?page='.$page:'');
    if($filters['q']!=='') $meta['noindex']=true;
}elseif(preg_match('#\Ap/(\d+)(?:-[a-z0-9-]*)?\z#',$route,$productMatch)){
    $view='product';
    $product=eshop_storefront_product($conn,$cid,(int)$productMatch[1]);
    if(!$product) shop_not_found('Product not available.');
    $images=eshop_storefront_images($conn,$cid,$product);
    $specs=eshop_storefront_spec_rows($product['specification']??'');
    [$related]=eshop_storefront_products($conn,$cid,['category'=>$product['category_id'],'exclude'=>$product['id']],5);
    $productAbsolute=shop_absolute(product_url($product));
    $meta['title']=($product['meta_title']!==''?$product['meta_title']:$product['product_name']).' | '.$shop['shop_name'];
    $summary=trim((string)$product['meta_description'])!==''?$product['meta_description']:(trim((string)$product['description'])!==''?$product['description']:implode(', ',$product['features']));
    $meta['description']=mb_strimwidth(preg_replace('/\s+/',' ',$summary?:$product['product_name']),0,300,'…','UTF-8');
    $meta['canonical']=$productAbsolute;
    $absoluteImages=array_map('shop_absolute',$images);
    $meta['image']=$absoluteImages[0]??'';
    $meta['jsonld'][]=array_filter(['@context'=>'https://schema.org/','@type'=>'Product','name'=>$product['product_name'],'description'=>$meta['description'],'image'=>$absoluteImages,'sku'=>$product['sku'],'category'=>$product['category_name'],'brand'=>$product['brand']!==''?['@type'=>'Brand','name'=>$product['brand']]:null,'offers'=>['@type'=>'Offer','url'=>$productAbsolute,'priceCurrency'=>'BDT','price'=>number_format((float)$product['price'],2,'.',''),'itemCondition'=>'https://schema.org/NewCondition','availability'=>$product['available_quantity']>0?'https://schema.org/InStock':'https://schema.org/OutOfStock']]);
}elseif($route==='cart'){ $view='cart'; $meta['title']='Shopping Cart | '.$shop['shop_name']; $meta['noindex']=true; }
elseif($route==='checkout'){
    if(!$cart_rows) { header('Location: '.shop_url('cart')); exit; }
    $view='checkout'; $meta['title']='Checkout | '.$shop['shop_name']; $meta['noindex']=true;
}elseif($route==='verify'){ $view='verify'; $meta['title']='Verify | '.$shop['shop_name']; $meta['noindex']=true; }
elseif($route==='signin'){ $view='signin'; $meta['title']='Sign in | '.$shop['shop_name']; $meta['noindex']=true; }
elseif($route==='track'){ $view='track'; $meta['title']='Track Order | '.$shop['shop_name']; $meta['noindex']=true; }
elseif($route==='wishlist' || $route==='compare'){
    $view=$route;
    [$products]=eshop_storefront_products($conn,$cid,['ids'=>$route==='wishlist'?$wishlist:$compare],50);
    $meta['title']=($route==='wishlist'?'Wishlist':'Compare Products').' | '.$shop['shop_name']; $meta['noindex']=true;
}elseif($route==='order-success'){
    if(empty($_SESSION['shop_last_order'][$cid])){ header('Location: '.shop_url()); exit; }
    $view='success'; $meta['title']='Order received | '.$shop['shop_name']; $meta['noindex']=true;
}elseif($route==='policy'){ $view='policy'; $meta['title']='Return Policy | '.$shop['shop_name']; }
elseif($route==='suggest'){
    header('Content-Type: application/json; charset=utf-8');
    $q=mb_substr(trim((string)($_GET['q']??'')),0,100);
    $items=[];
    if(mb_strlen($q)>=2){
        [$rows]=eshop_storefront_products($conn,$cid,['q'=>$q],8);
        foreach($rows as $row) $items[]=['name'=>$row['product_name'],'price'=>money($row['price']),'url'=>product_url($row),'image'=>$row['photo_url']];
    }
    echo json_encode(['items'=>$items]); exit;
}elseif($route==='sitemap.xml'){
    header('Content-Type: application/xml; charset=utf-8');
    [$rows]=eshop_storefront_products($conn,$cid,[],5000);
    echo '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    echo '<url><loc>'.h($absoluteRoot.'/').'</loc></url>';
    foreach($categories as $category) echo '<url><loc>'.h(shop_absolute(category_url($category['id'],$category['name']))).'</loc></url>';
    foreach($rows as $row) echo '<url><loc>'.h(shop_absolute(product_url($row))).'</loc></url>';
    echo '</urlset>'; exit;
}elseif($route==='robots.txt'){
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nDisallow: ".$root."/cart\nDisallow: ".$root."/checkout\nSitemap: ".$absoluteRoot."/sitemap.xml\n"; exit;
}else{
    shop_not_found();
}

require __DIR__.'/views/layout_top.php';
require __DIR__.'/views/'.$view.'.php';
require __DIR__.'/views/layout_bottom.php';
