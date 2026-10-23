<?php
require __DIR__.'/partials.php';
$logo=shop_logo($shop);
$assetVersion=(int)max(filemtime(__DIR__.'/../assets/store.css'),filemtime(__DIR__.'/../assets/store.js'));
$searchValue=$filters['q']??'';
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
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= h(app_path('shop/assets/store.css')) ?>?v=<?= $assetVersion ?>">
<style>:root{--brand:<?= h($shop['theme_color']) ?>;--header:<?= h($shop['header_color']) ?>}</style>
<?php foreach($meta['jsonld'] as $jsonld){ ?><script type="application/ld+json"><?= json_encode(array_filter($jsonld,function($v){ return $v!==null; }),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG) ?></script><?php } ?>
</head>
<body class="page-<?= h($view) ?>">
<header class="site-header">
    <div class="topbar">
        <div class="container topbar-inner">
            <button type="button" class="icon-btn header-btn menu-toggle" data-menu-open aria-label="Open menu"><?= icon('menu') ?></button>
            <a class="logo" href="<?= h(shop_url()) ?>" aria-label="<?= h($shop['shop_name']) ?> home"><?php if($logo!==''){ ?><img src="<?= h($logo) ?>" alt="<?= h($shop['shop_name']) ?> logo"><?php } ?><span class="logo-text<?= $logo!==''?' with-image':'' ?>"><?= h($shop['shop_name']) ?></span></a>
            <form class="search-box" action="<?= h(shop_url('search')) ?>" method="get" role="search" data-search>
                <input type="search" name="q" value="<?= h($searchValue) ?>" placeholder="Search your favorite product..." aria-label="Search products" autocomplete="off" data-search-input data-suggest-url="<?= h(shop_url('suggest')) ?>">
                <button aria-label="Search"><?= icon('search') ?></button>
                <div class="search-results" data-search-results hidden></div>
            </form>
            <nav class="header-links" aria-label="Shop links">
                <a class="header-link" href="<?= h(shop_url('offers')) ?>"><span class="header-icon"><?= icon('gift') ?></span><span class="header-label"><strong>Offers</strong><small>Latest Offers</small></span></a>
                <a class="header-link" href="<?= h(shop_url('track')) ?>"><span class="header-icon"><?= icon('truck') ?></span><span class="header-label"><strong>Track Order</strong><small>Order status</small></span></a>
                <?php if($shop_customer){ ?>
                <form method="post" class="header-link header-account"><?= csrf_field() ?><input type="hidden" name="action" value="auth_logout"><span class="header-icon"><?= icon('user') ?></span><span class="header-label"><small>Hi, <?= h(mb_strimwidth($shop_customer['name'],0,18,'…','UTF-8')) ?></small><button class="link-btn">Sign out</button></span></form>
                <?php }else{ ?>
                <a class="header-link" href="<?= h(shop_url('signin')) ?>"><span class="header-icon"><?= icon('user') ?></span><span class="header-label"><small>Account</small><strong>Sign in</strong></span></a>
                <?php } ?>
                <a class="header-cart" href="<?= h(shop_url('cart')) ?>" aria-label="Cart, <?= $count ?> items"><?= icon('cart') ?><span>Cart</span><?php if($count){ ?><b class="count"><?= $count ?></b><?php } ?></a>
            </nav>
            <button type="button" class="icon-btn header-btn search-toggle" data-search-toggle aria-label="Search"><?= icon('search') ?></button>
            <a class="icon-btn header-btn mobile-cart" href="<?= h(shop_url('cart')) ?>" aria-label="Cart, <?= $count ?> items"><?= icon('cart') ?><?php if($count){ ?><b class="count"><?= $count ?></b><?php } ?></a>
        </div>
    </div>
    <?php if($categories){ ?>
    <nav class="category-nav" aria-label="Product categories">
        <ul class="container category-strip">
            <?php foreach($categories as $category){ ?>
            <li class="nav-item<?= $category['subs']?' has-children':'' ?>">
                <a href="<?= h(category_url($category['id'],$category['name'])) ?>"><?= h($category['name']) ?></a>
                <?php if($category['subs']){ ?>
                <div class="dropdown"><ul>
                    <?php foreach($category['subs'] as $sub){ ?><li><a href="<?= h(category_url($category['id'],$category['name'],['sub'=>$sub['name']])) ?>"><?= h($sub['name']) ?></a></li><?php } ?>
                </ul><a class="dropdown-all" href="<?= h(category_url($category['id'],$category['name'])) ?>">Show All <?= h($category['name']) ?> <?= icon('chevron','icon-sm') ?></a></div>
                <?php } ?>
            </li>
            <?php } ?>
        </ul>
    </nav>
    <?php } ?>
</header>

<div class="drawer" data-drawer hidden>
    <div class="drawer-backdrop" data-menu-close></div>
    <aside class="drawer-panel" aria-label="Menu">
        <div class="drawer-head"><strong><?= h($shop['shop_name']) ?></strong><button type="button" class="icon-btn" data-menu-close aria-label="Close menu"><?= icon('close') ?></button></div>
        <nav class="drawer-links">
            <a href="<?= h(shop_url()) ?>">Home</a>
            <a href="<?= h(shop_url('products')) ?>">All Products</a>
            <a href="<?= h(shop_url('offers')) ?>">Offers</a>
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

<?php if($toast!==''){ ?><div class="toast" role="status" data-toast><?= icon('check') ?><?= h($toast) ?></div><?php } ?>
<main class="site-main">
