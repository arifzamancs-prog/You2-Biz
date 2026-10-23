<?php
// Storefront presentation data: product details, gallery, banners and delivery
// zones. Order, stock and invoice rules stay in the existing E-shop helpers.
require_once __DIR__.'/eshop_catalog_helper.php';

function eshop_storefront_add_column($conn,$table,$column,$definition)
{
    $result=mysqli_query($conn,"SHOW COLUMNS FROM `$table` LIKE '".mysqli_real_escape_string($conn,$column)."'");
    if($result && mysqli_num_rows($result)===0) mysqli_query($conn,"ALTER TABLE `$table` ADD COLUMN `$column` $definition");
}

function eshop_storefront_schema($conn)
{
    static $ready=false;
    if($ready) return;
    $ready=true;
    // The banner table and last profile column are created last; when both exist the upgrade already ran.
    try{
        $banners=mysqli_query($conn,"SHOW TABLES LIKE 'eshop_banners'");
        $headerColor=$banners && mysqli_num_rows($banners)>0 ? mysqli_query($conn,"SHOW COLUMNS FROM eshop_profiles LIKE 'header_color'") : false;
        if($headerColor && mysqli_num_rows($headerColor)>0) return;
    }catch(mysqli_sql_exception $e){
        // Missing profile table: continue and create what can be created.
    }
    $products=mysqli_query($conn,"SHOW TABLES LIKE 'eshop_products'");
    if($products && mysqli_num_rows($products)>0){
        eshop_storefront_add_column($conn,'eshop_products','regular_price','DECIMAL(12,2) NULL');
        eshop_storefront_add_column($conn,'eshop_products','brand',"VARCHAR(100) NOT NULL DEFAULT ''");
        eshop_storefront_add_column($conn,'eshop_products','key_features','TEXT NULL');
        eshop_storefront_add_column($conn,'eshop_products','specification','TEXT NULL');
        eshop_storefront_add_column($conn,'eshop_products','featured','TINYINT(1) NOT NULL DEFAULT 0');
        eshop_storefront_add_column($conn,'eshop_products','meta_title',"VARCHAR(190) NOT NULL DEFAULT ''");
        eshop_storefront_add_column($conn,'eshop_products','meta_description',"VARCHAR(300) NOT NULL DEFAULT ''");
    }
    $profiles=mysqli_query($conn,"SHOW TABLES LIKE 'eshop_profiles'");
    if($profiles && mysqli_num_rows($profiles)>0){
        eshop_storefront_add_column($conn,'eshop_profiles','whatsapp',"VARCHAR(30) NOT NULL DEFAULT ''");
        eshop_storefront_add_column($conn,'eshop_profiles','facebook_url',"VARCHAR(255) NOT NULL DEFAULT ''");
        eshop_storefront_add_column($conn,'eshop_profiles','youtube_url',"VARCHAR(255) NOT NULL DEFAULT ''");
        eshop_storefront_add_column($conn,'eshop_profiles','theme_color',"VARCHAR(7) NOT NULL DEFAULT '#e4002b'");
        eshop_storefront_add_column($conn,'eshop_profiles','header_color',"VARCHAR(7) NOT NULL DEFAULT '#081621'");
    }
    mysqli_query($conn,"CREATE TABLE IF NOT EXISTS eshop_product_images (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY product_images (company_id,product_id,sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn,"CREATE TABLE IF NOT EXISTS eshop_delivery_zones (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        zone_name VARCHAR(100) NOT NULL,
        charge DECIMAL(12,2) NOT NULL DEFAULT 0,
        delivery_time VARCHAR(100) NOT NULL DEFAULT '',
        sort_order INT NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        KEY company_zones (company_id,active,sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn,"CREATE TABLE IF NOT EXISTS eshop_banners (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        image VARCHAR(255) NOT NULL,
        title VARCHAR(150) NOT NULL DEFAULT '',
        subtitle VARCHAR(255) NOT NULL DEFAULT '',
        link_url VARCHAR(500) NOT NULL DEFAULT '',
        sort_order INT NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        KEY company_banners (company_id,active,sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function eshop_storefront_valid_color($color,$default)
{
    return preg_match('/\A#[0-9a-fA-F]{6}\z/',(string)$color) ? strtolower($color) : $default;
}

function eshop_storefront_slugify($text)
{
    $slug=strtolower(trim(preg_replace('/[^A-Za-z0-9]+/','-',(string)$text),'-'));
    return $slug!=='' ? substr($slug,0,80) : 'item';
}

// One feature per line. Leading bullets are removed so pasted lists stay tidy.
function eshop_storefront_lines($text)
{
    $lines=[];
    foreach(preg_split('/\r\n|\r|\n/',(string)$text) as $line){
        $line=trim(preg_replace('/\A[\s*\x{2022}\-]+/u','',$line));
        if($line!=='') $lines[]=$line;
    }
    return $lines;
}

// "Label: value" lines become specification rows; a line without a colon is a group heading.
function eshop_storefront_spec_rows($text)
{
    $rows=[];
    foreach(eshop_storefront_lines($text) as $line){
        $parts=explode(':',$line,2);
        $rows[]=count($parts)===2 && trim($parts[0])!=='' ? ['label'=>trim($parts[0]),'value'=>trim($parts[1])] : ['label'=>$line,'value'=>null];
    }
    return $rows;
}

function eshop_storefront_photo_url($file)
{
    $file=basename((string)$file);
    return $file!=='' && is_file(__DIR__.'/../uploads/products/'.$file) ? app_path('uploads/products/'.rawurlencode($file)) : '';
}

function eshop_storefront_images($conn,$companyId,$product)
{
    $urls=[];
    $main=eshop_storefront_photo_url($product['photo_path']??'');
    if($main!=='') $urls[]=$main;
    $s=mysqli_prepare($conn,'SELECT file_name FROM eshop_product_images WHERE company_id=? AND product_id=? ORDER BY sort_order,id LIMIT 12');
    $productId=(int)$product['id'];
    mysqli_stmt_bind_param($s,'ii',$companyId,$productId); mysqli_stmt_execute($s);
    foreach(mysqli_fetch_all(mysqli_stmt_get_result($s),MYSQLI_ASSOC) as $row){
        $url=eshop_storefront_photo_url($row['file_name']);
        if($url!=='') $urls[]=$url;
    }
    return $urls;
}

function eshop_storefront_profile($conn,$shop)
{
    $shop['theme_color']=eshop_storefront_valid_color($shop['theme_color']??'','#e4002b');
    $shop['header_color']=eshop_storefront_valid_color($shop['header_color']??'','#081621');
    foreach(['whatsapp','facebook_url','youtube_url'] as $key) $shop[$key]=trim((string)($shop[$key]??''));
    return $shop;
}

function eshop_storefront_zones($conn,$companyId)
{
    $s=mysqli_prepare($conn,'SELECT id,zone_name,charge,delivery_time FROM eshop_delivery_zones WHERE company_id=? AND active=1 ORDER BY sort_order,id');
    mysqli_stmt_bind_param($s,'i',$companyId); mysqli_stmt_execute($s);
    return mysqli_fetch_all(mysqli_stmt_get_result($s),MYSQLI_ASSOC);
}

function eshop_storefront_banners($conn,$companyId)
{
    $s=mysqli_prepare($conn,'SELECT id,image,title,subtitle,link_url FROM eshop_banners WHERE company_id=? AND active=1 ORDER BY sort_order,id LIMIT 8');
    mysqli_stmt_bind_param($s,'i',$companyId); mysqli_stmt_execute($s);
    $banners=[];
    foreach(mysqli_fetch_all(mysqli_stmt_get_result($s),MYSQLI_ASSOC) as $row){
        $file=basename((string)$row['image']);
        if($file==='' || !is_file(__DIR__.'/../uploads/eshop_banners/'.$file)) continue;
        $row['image_url']=app_path('uploads/eshop_banners/'.rawurlencode($file));
        $banners[]=$row;
    }
    return $banners;
}

const ESHOP_STOREFRONT_FROM="FROM eshop_products e JOIN products p ON p.id=e.product_id AND p.user_id=e.company_id JOIN product_categories c ON c.id=p.category_id AND c.category_type='stock_product' WHERE e.company_id=? AND e.published=1 AND p.status='active'";
const ESHOP_STOREFRONT_COLUMNS="p.id,p.product_name,p.sku,p.photo_path,p.sub_category,c.id AS category_id,c.category_name,COALESCE(e.online_price,p.sale_price) AS price,e.regular_price,e.brand,e.description,e.key_features,e.featured";

// Categories with their sub-categories, limited to what is actually published.
function eshop_storefront_categories($conn,$companyId)
{
    $s=mysqli_prepare($conn,"SELECT c.id,c.category_name,TRIM(COALESCE(p.sub_category,'')) AS sub_category,COUNT(*) AS total ".ESHOP_STOREFRONT_FROM." AND c.status='active' GROUP BY c.id,c.category_name,TRIM(COALESCE(p.sub_category,'')) ORDER BY c.category_name,sub_category");
    mysqli_stmt_bind_param($s,'i',$companyId); mysqli_stmt_execute($s);
    $categories=[];
    foreach(mysqli_fetch_all(mysqli_stmt_get_result($s),MYSQLI_ASSOC) as $row){
        $id=(int)$row['id'];
        if(!isset($categories[$id])) $categories[$id]=['id'=>$id,'name'=>$row['category_name'],'total'=>0,'subs'=>[]];
        $categories[$id]['total']+=(int)$row['total'];
        if($row['sub_category']!=='') $categories[$id]['subs'][]=['name'=>$row['sub_category'],'total'=>(int)$row['total']];
    }
    return $categories;
}

function eshop_storefront_brands($conn,$companyId,$categoryId=0)
{
    $s=mysqli_prepare($conn,"SELECT TRIM(e.brand) AS brand,COUNT(*) AS total ".ESHOP_STOREFRONT_FROM." AND TRIM(e.brand)<>'' AND (?=0 OR c.id=?) GROUP BY TRIM(e.brand) ORDER BY brand");
    mysqli_stmt_bind_param($s,'iii',$companyId,$categoryId,$categoryId); mysqli_stmt_execute($s);
    return mysqli_fetch_all(mysqli_stmt_get_result($s),MYSQLI_ASSOC);
}

// Filters: q, category, sub, brands[], min, max, offers, featured, ids[], exclude, sort.
function eshop_storefront_products($conn,$companyId,$filters,$limit=24,$offset=0)
{
    $where=''; $types='i'; $params=[$companyId];
    $q=trim((string)($filters['q']??''));
    if($q!==''){ $where.=' AND (p.product_name LIKE ? OR e.brand LIKE ? OR p.sku LIKE ?)'; $like='%'.$q.'%'; $types.='sss'; array_push($params,$like,$like,$like); }
    if(!empty($filters['category'])){ $where.=' AND c.id=?'; $types.='i'; $params[]=(int)$filters['category']; }
    if(($filters['sub']??'')!==''){ $where.=" AND TRIM(COALESCE(p.sub_category,''))=?"; $types.='s'; $params[]=(string)$filters['sub']; }
    $brands=array_values(array_filter(array_map('strval',(array)($filters['brands']??[])),'strlen'));
    if($brands){ $where.=' AND TRIM(e.brand) IN ('.implode(',',array_fill(0,count($brands),'?')).')'; $types.=str_repeat('s',count($brands)); array_push($params,...$brands); }
    if(isset($filters['min']) && $filters['min']!==''){ $where.=' AND COALESCE(e.online_price,p.sale_price)>=?'; $types.='d'; $params[]=(float)$filters['min']; }
    if(isset($filters['max']) && $filters['max']!==''){ $where.=' AND COALESCE(e.online_price,p.sale_price)<=?'; $types.='d'; $params[]=(float)$filters['max']; }
    if(!empty($filters['offers'])) $where.=' AND e.regular_price>COALESCE(e.online_price,p.sale_price)';
    if(!empty($filters['featured'])) $where.=' AND e.featured=1';
    if(isset($filters['ids'])){
        $ids=array_values(array_unique(array_filter(array_map('intval',(array)$filters['ids']))));
        if(!$ids) return [[],0];
        $where.=' AND p.id IN ('.implode(',',$ids).')';
    }
    if(!empty($filters['exclude'])){ $where.=' AND p.id<>?'; $types.='i'; $params[]=(int)$filters['exclude']; }
    $orders=['newest'=>'p.id DESC','price_low'=>'price ASC, p.id DESC','price_high'=>'price DESC, p.id DESC','name'=>'p.product_name ASC, p.id DESC','featured'=>'e.featured DESC, p.id DESC'];
    $order=$orders[$filters['sort']??'newest']??$orders['newest'];

    $s=mysqli_prepare($conn,'SELECT COUNT(*) AS total '.ESHOP_STOREFRONT_FROM.$where);
    mysqli_stmt_bind_param($s,$types,...$params); mysqli_stmt_execute($s);
    $total=(int)(mysqli_fetch_assoc(mysqli_stmt_get_result($s))['total']??0);

    $s=mysqli_prepare($conn,'SELECT '.ESHOP_STOREFRONT_COLUMNS.' '.ESHOP_STOREFRONT_FROM.$where.' ORDER BY '.$order.' LIMIT ? OFFSET ?');
    $types.='ii'; $params[]=(int)$limit; $params[]=(int)$offset;
    mysqli_stmt_bind_param($s,$types,...$params); mysqli_stmt_execute($s);
    $rows=mysqli_fetch_all(mysqli_stmt_get_result($s),MYSQLI_ASSOC);
    foreach($rows as &$row) $row=eshop_storefront_decorate($conn,$companyId,$row);
    return [$rows,$total];
}

function eshop_storefront_product($conn,$companyId,$productId)
{
    $s=mysqli_prepare($conn,'SELECT '.ESHOP_STOREFRONT_COLUMNS.',e.specification,e.meta_title,e.meta_description '.ESHOP_STOREFRONT_FROM.' AND p.id=?');
    mysqli_stmt_bind_param($s,'ii',$companyId,$productId); mysqli_stmt_execute($s);
    $row=mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    return $row ? eshop_storefront_decorate($conn,$companyId,$row) : null;
}

// Adds live availability, discount and display helpers to a product row.
function eshop_storefront_decorate($conn,$companyId,$row)
{
    $row['variants']=eshop_catalog_variant_options($conn,$companyId,(int)$row['id']);
    $row['available_quantity']=array_sum(array_column($row['variants'],'available'));
    $row['has_variants']=($row['variants'][0]['name']??'')!=='';
    $price=(float)$row['price']; $regular=(float)($row['regular_price']??0);
    $row['save']=$regular>$price ? round($regular-$price,2) : 0;
    $row['save_percent']=$row['save']>0 && $regular>0 ? (int)round($row['save']*100/$regular) : 0;
    $row['features']=eshop_storefront_lines($row['key_features']??'');
    $row['photo_url']=eshop_storefront_photo_url($row['photo_path']??'');
    $row['slug']=eshop_storefront_slugify($row['product_name']);
    return $row;
}
