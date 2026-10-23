<div class="container">
<?php if($banners){ ?>
<section class="slider" data-slider aria-label="Promotions">
    <div class="slides">
        <?php foreach($banners as $index=>$banner){ $link=preg_match('#\A(https?://|/)#i',$banner['link_url'])?$banner['link_url']:''; ?>
        <div class="slide<?= $index===0?' is-active':'' ?>" data-slide>
            <?php if($link!==''){ ?><a href="<?= h($link) ?>"><?php } ?>
            <img src="<?= h($banner['image_url']) ?>" alt="<?= h($banner['title']?:$shop['shop_name']) ?>" <?= $index===0?'fetchpriority="high"':'loading="lazy"' ?>>
            <?php if($banner['title']!=='' || $banner['subtitle']!==''){ ?><div class="slide-caption"><?php if($banner['title']!==''){ ?><h2><?= h($banner['title']) ?></h2><?php } ?><?php if($banner['subtitle']!==''){ ?><p><?= h($banner['subtitle']) ?></p><?php } ?></div><?php } ?>
            <?php if($link!==''){ ?></a><?php } ?>
        </div>
        <?php } ?>
    </div>
    <?php if(count($banners)>1){ ?>
    <button type="button" class="slider-arrow prev" data-slide-prev aria-label="Previous slide"><?= icon('chevron') ?></button>
    <button type="button" class="slider-arrow next" data-slide-next aria-label="Next slide"><?= icon('chevron') ?></button>
    <div class="slider-dots"><?php foreach($banners as $index=>$banner){ ?><button type="button" class="<?= $index===0?'is-active':'' ?>" data-slide-dot="<?= $index ?>" aria-label="Show slide <?= $index+1 ?>"></button><?php } ?></div>
    <?php } ?>
</section>
<?php }else{ ?>
<section class="hero">
    <span class="hero-kicker">Welcome to our store</span>
    <h1><?= h($shop['shop_name']) ?></h1>
    <?php if(trim((string)$shop['description'])!==''){ ?><p><?= h(mb_strimwidth(trim((string)$shop['description']),0,220,'…','UTF-8')) ?></p><?php } ?>
    <a class="btn btn-light" href="<?= h(shop_url('products')) ?>">Shop Now</a>
</section>
<?php } ?>

<section class="promises" aria-label="Why shop with us">
    <div><?= icon('truck') ?><span><strong>Fast Delivery</strong><small><?= $zones?h(implode(' · ',array_map(function($z){ return $z['zone_name'].' '.money($z['charge']); },array_slice($zones,0,2)))):'Delivered to your door' ?></small></span></div>
    <div><?= icon('shield') ?><span><strong>Cash on Delivery</strong><small>Pay when your order arrives</small></span></div>
    <div><?= icon('check') ?><span><strong>Genuine Stock</strong><small>Only available stock can be ordered</small></span></div>
    <div><?= icon('phone') ?><span><strong>Support</strong><small><?= h($shop['phone']?:'We are here to help') ?></small></span></div>
</section>

<?php if($categories){ ?>
<section class="section">
    <div class="section-head"><h2>Featured Categories</h2><p>Get your desired product from our categories</p></div>
    <div class="category-tiles">
        <?php foreach($categories as $category){ ?><a class="category-tile" href="<?= h(category_url($category['id'],$category['name'])) ?>"><strong><?= h($category['name']) ?></strong><small><?= (int)$category['total'] ?> product<?= (int)$category['total']===1?'':'s' ?></small></a><?php } ?>
    </div>
</section>
<?php } ?>

<?php foreach([['Featured Products','Check & get your desired product!',$featured,shop_url('products')],['Special Offers','Save more on these deals',$offers,shop_url('offers')],['New Arrivals','Fresh products just added',$newest,shop_url('products')]] as [$title,$subtitle,$rows,$link]){ if(!$rows) continue; ?>
<section class="section">
    <div class="section-head"><h2><?= h($title) ?></h2><p><?= h($subtitle) ?></p><a class="see-all" href="<?= h($link) ?>">See all <?= icon('chevron','icon-sm') ?></a></div>
    <?= product_grid($rows) ?>
</section>
<?php } ?>

<?php foreach($categorySections as $section){ ?>
<section class="section">
    <div class="section-head"><h2><?= h($section['category']['name']) ?></h2><a class="see-all" href="<?= h(category_url($section['category']['id'],$section['category']['name'])) ?>">See all <?= icon('chevron','icon-sm') ?></a></div>
    <?= product_grid($section['products']) ?>
</section>
<?php } ?>

<?php if(!$newest){ ?><section class="panel empty-state"><h2>Products are coming soon</h2><p>Please check back shortly.</p></section><?php } ?>
</div>
