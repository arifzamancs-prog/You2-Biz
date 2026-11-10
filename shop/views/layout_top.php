<?php
require __DIR__.'/partials.php';
$logo=shop_logo($shop);
$assetVersion=(int)max(filemtime(__DIR__.'/../assets/store.css'),filemtime(__DIR__.'/../assets/store.js'));
$searchValue=$filters['q']??'';
$navCategories=array_slice($categories,0,5,true);
$openCart=$toast==='Added to cart.' && $view!=='cart' && $view!=='checkout';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($meta['title']) ?></title>
<meta name="description" content="<?= h($meta['description']) ?>">
<?php if($meta['noindex']){ ?><meta name="robots" content="noindex,follow"><?php }else{ ?><link rel="canonical" href="<?= h($meta['canonical']) ?>"><?php } ?>
<meta property="og:site_name" content="<?= h($shop['shop_name']) ?>">
<meta property="og:title" content="<?= h($meta['title']) ?>">
<meta property="og:description" content="<?= h($meta['description']) ?>">
<meta property="og:type" content="<?= $view==='product'?'product':'website' ?>">
<meta property="og:url" content="<?= h($meta['canonical']) ?>">
<?php if($meta['image']!==''){ ?><meta property="og:image" content="<?= h($meta['image']) ?>"><meta name="twitter:card" content="summary_large_image"><?php }else{ ?><meta name="twitter:card" content="summary"><?php } ?>
<?php if($logo!==''){ ?><link rel="icon" href="<?= h($logo) ?>"><?php } ?>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@600;700;800&family=Hind+Siliguri:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="<?= h(app_path('shop/assets/store.css')) ?>?v=<?= $assetVersion ?>">
<style>:root{--brand:<?= h($shop['theme_color']) ?>;--header:<?= h($shop['header_color']) ?>}</style>
<?php foreach($meta['jsonld'] as $jsonld){ ?><script type="application/ld+json"><?= json_encode(array_filter($jsonld,function($v){ return $v!==null; }),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG) ?></script><?php } ?>
</head>
<body class="page-<?= h($view) ?> theme-<?= h($shop['store_theme']) ?>"<?= $openCart?' data-open-cart':'' ?>>
<div class="topbar">
    <div class="container topbar-inner">
        <span class="topbar-note"><?= icon('truck','icon-sm') ?> সারা বাংলাদেশে ক্যাশ অন ডেলিভারি<?= $zones?' · '.h(implode(' · ',array_map(function($z){ return $z['zone_name'].' '.money($z['charge']); },array_slice($zones,0,2)))):'' ?></span>
        <span class="topbar-links"><?php if(trim((string)$shop['phone'])!==''){ ?><a href="tel:<?= h(preg_replace('/[^0-9+]/','',(string)$shop['phone'])) ?>"><?= icon('phone','icon-sm') ?> <?= h($shop['phone']) ?></a><?php } ?><a href="<?= h(shop_url('track')) ?>">Track Order</a></span>
    </div>
</div>
<header class="site-header">
    <div class="container header-inner">
        <button type="button" class="header-icon-btn menu-toggle" data-menu-open aria-label="Open menu"><?= icon('menu') ?></button>
        <a class="logo" href="<?= h(shop_url()) ?>" aria-label="<?= h($shop['shop_name']) ?> home"><?php if($logo!==''){ ?><img src="<?= h($logo) ?>" alt="<?= h($shop['shop_name']) ?> logo"><?php }else{ ?><span class="logo-mark"><?= h(mb_strtoupper(mb_substr($shop['shop_name'],0,1,'UTF-8'),'UTF-8')) ?>.</span><?php } ?><span class="logo-text<?= $logo!==''?' with-image':'' ?>"><?= h($shop['shop_name']) ?></span></a>
        <nav class="main-nav" aria-label="Main">
            <ul>
                <li><a href="<?= h(shop_url()) ?>"<?= $view==='home'?' class="is-current"':'' ?>>Home</a></li>
                <li><a href="<?= h(shop_url('products')) ?>">Shop</a></li>
                <?php foreach($navCategories as $category){ ?>
                <li class="nav-item<?= $category['subs']?' has-children':'' ?>">
                    <a href="<?= h(category_url($category['id'],$category['name'])) ?>"><?= h($category['name']) ?></a>
                    <?php if($category['subs']){ ?>
                    <div class="dropdown"><ul>
                        <?php foreach($category['subs'] as $sub){ ?><li><a href="<?= h(category_url($category['id'],$category['name'],['sub'=>$sub['name']])) ?>"><?= h($sub['name']) ?></a></li><?php } ?>
                    </ul><a class="dropdown-all" href="<?= h(category_url($category['id'],$category['name'])) ?>">All <?= h($category['name']) ?> <?= icon('arrow','icon-sm') ?></a></div>
                    <?php } ?>
                </li>
                <?php } ?>
                <li><a href="<?= h(shop_url('products')) ?>">New Arrivals</a></li>
                <li><a class="nav-deals" href="<?= h(shop_url('offers')) ?>">Deals</a></li>
            </ul>
        </nav>
        <div class="header-actions">
            <button type="button" class="header-icon-btn" data-search-toggle aria-label="Search" aria-expanded="false"><?= icon('search') ?></button>
            <?php if($shop_customer){ ?>
            <a class="header-icon-btn account-btn" href="<?= h(shop_url('track')) ?>" title="Hi, <?= h($shop_customer['name']) ?> — my orders" aria-label="My orders"><?= icon('user') ?></a>
            <?php }else{ ?>
            <a class="header-icon-btn account-btn" href="<?= h(shop_url('signin')) ?>" title="Sign in" aria-label="Sign in"><?= icon('user') ?></a>
            <?php } ?>
            <a class="header-icon-btn wish-btn" href="<?= h(shop_url('wishlist')) ?>" aria-label="Wishlist, <?= count($wishlist) ?> items"><?= icon('heart') ?><?php if($wishlist){ ?><b class="count"><?= count($wishlist) ?></b><?php } ?></a>
            <a class="header-icon-btn" href="<?= h(shop_url('cart')) ?>" data-cart-open aria-label="Cart, <?= $count ?> items"><?= icon('bag') ?><b class="count"><?= $count ?></b></a>
        </div>
    </div>
    <div class="search-panel" data-search-panel hidden>
        <form class="container search-box" action="<?= h(shop_url('search')) ?>" method="get" role="search" data-search>
            <?= icon('search') ?>
            <input type="search" name="q" value="<?= h($searchValue) ?>" placeholder="Search products..." aria-label="Search products" autocomplete="off" data-search-input data-suggest-url="<?= h(shop_url('suggest')) ?>">
            <button class="btn btn-dark">Search</button>
            <div class="search-results" data-search-results hidden></div>
        </form>
    </div>
</header>

<div class="drawer" data-drawer hidden>
    <div class="drawer-backdrop" data-menu-close></div>
    <aside class="drawer-panel" aria-label="Menu">
        <div class="drawer-head"><strong><?= h($shop['shop_name']) ?></strong><button type="button" class="header-icon-btn" data-menu-close aria-label="Close menu"><?= icon('close') ?></button></div>
        <nav class="drawer-links">
            <a href="<?= h(shop_url()) ?>">Home</a>
            <a href="<?= h(shop_url('products')) ?>">Shop</a>
            <a class="drawer-deals" href="<?= h(shop_url('offers')) ?>">Deals</a>
            <?php foreach($categories as $category){ ?>
                <?php if($category['subs']){ ?>
                <details><summary><?= h($category['name']) ?><?= icon('down','icon-sm') ?></summary>
                    <a href="<?= h(category_url($category['id'],$category['name'])) ?>">All <?= h($category['name']) ?></a>
                    <?php foreach($category['subs'] as $sub){ ?><a href="<?= h(category_url($category['id'],$category['name'],['sub'=>$sub['name']])) ?>"><?= h($sub['name']) ?></a><?php } ?>
                </details>
                <?php }else{ ?><a href="<?= h(category_url($category['id'],$category['name'])) ?>"><?= h($category['name']) ?></a><?php } ?>
            <?php } ?>
            <hr>
            <a href="<?= h(shop_url('wishlist')) ?>">Wishlist (<?= count($wishlist) ?>)</a>
            <a href="<?= h(shop_url('compare')) ?>">Compare (<?= count($compare) ?>)</a>
            <a href="<?= h(shop_url('track')) ?>">Track Order</a>
            <?php if($shop_customer){ ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="auth_logout"><button class="link-btn">Sign out (<?= h($shop_customer['name']) ?>)</button></form><?php }else{ ?><a href="<?= h(shop_url('signin')) ?>">Sign in</a><?php } ?>
        </nav>
    </aside>
</div>

<?php if($view!=="cart"){ ?><div class="side-cart" data-side-cart hidden>
    <div class="side-cart-backdrop" data-cart-close></div>
    <aside class="side-cart-panel" aria-label="Shopping cart" role="dialog" aria-modal="true">
        <div class="side-cart-head"><strong>Shopping Cart <small>(<?= $count ?>)</small></strong><button type="button" class="header-icon-btn" data-cart-close aria-label="Close cart"><?= icon('close') ?></button></div>
        <?php if($cart_rows){ ?>
        <ul class="side-cart-items">
            <?php foreach($cart_rows as $p){ ?>
            <li>
                <a class="side-cart-photo" href="<?= h(product_url($p)) ?>"><?php if($p['photo_url']!==''){ ?><img src="<?= h($p['photo_url']) ?>" alt="" loading="lazy"><?php } ?></a>
                <div><a href="<?= h(product_url($p)) ?>"><?= h($p['product_name']) ?></a><?php if($p['variant_name']!==''){ ?><small><?= h($p['variant_name']) ?></small><?php } ?><span><?= (int)$p['quantity'] ?> × <?= money($p['price']) ?></span></div>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="variant_name" value="<?= h($p['variant_name']) ?>"><button class="side-cart-remove" name="action" value="remove" aria-label="Remove <?= h($p['product_name']) ?>"><?= icon('close','icon-sm') ?></button></form>
            </li>
            <?php } ?>
        </ul>
        <div class="side-cart-foot">
            <div class="side-cart-total"><span>Subtotal</span><strong><?= money($total) ?></strong></div>
            <a class="btn btn-outline btn-block" href="<?= h(shop_url('cart')) ?>">View Cart</a>
            <a class="btn btn-gold btn-block" href="<?= h(shop_url('checkout')) ?>">Checkout <?= icon('chevron','icon-sm') ?></a>
        </div>
        <?php }else{ ?>
        <div class="side-cart-empty"><?= icon('bag','icon-lg') ?><p>Your cart is empty.</p><a class="btn btn-dark" href="<?= h(shop_url('products')) ?>">Start Shopping</a></div>
        <?php } ?>
    </aside>
</div><?php } ?>

<?php if($toast!==''){ ?><div class="toast" role="status" data-toast><?= icon('check') ?><?= h($toast) ?></div><?php } ?>
<main class="site-main">
