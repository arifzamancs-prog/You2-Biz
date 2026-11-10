<?php
// Section images come from published products so every shop gets a complete page.
$firstPhoto=function($rows,$skip=0){ $found=[]; foreach($rows as $row){ if($row['photo_url']!=='') $found[]=$row['photo_url']; } return $found[$skip] ?? ($found[0] ?? ''); };
$tiles=[]; foreach($categorySections as $section){ $tiles[]=['category'=>$section['category'],'photo'=>$firstPhoto($section['products'])]; }
$tiles=array_slice($tiles,0,6);
$pool=array_merge($featured,$newest);
$heroBanner=$banners[0] ?? null;
$heroPhoto=$heroBanner ? '' : $firstPhoto($pool);
$heroTitle=$heroBanner && trim($heroBanner['title'])!=='' ? $heroBanner['title'] : $shop['shop_name'];
$heroText=$heroBanner && trim($heroBanner['subtitle'])!=='' ? $heroBanner['subtitle'] : mb_strimwidth(trim((string)$shop['description']),0,140,'…','UTF-8');
$heroWords=preg_split('/\s+/u',trim($heroTitle)); $heroLast=count($heroWords)>1 ? array_pop($heroWords) : '';
$signoff=array_slice(array_map(function($c){ return $c['name']; },$categories),0,3);
$campaignPhotos=[]; foreach($pool as $row){ if($row['photo_url']!=='' && !in_array($row['photo_url'],$campaignPhotos,true)) $campaignPhotos[]=$row['photo_url']; if(count($campaignPhotos)===3) break; }
$campaignBanner=$banners[1] ?? null;
$promoTones=['1','2','3'];
$sectionHead=function($eyebrow,$title,$link,$label){ ?>
    <div class="section-head">
        <div><span class="eyebrow"><?= h($eyebrow) ?></span><h2><?= h($title) ?></h2></div>
        <?php if($link!==''){ ?><a class="see-all" href="<?= h($link) ?>"><?= h($label) ?> <?= icon('arrow','icon-sm') ?></a><?php } ?>
    </div>
<?php };
?>
<section class="hero-wrap">
<div class="container">
<div class="hero">
    <div class="hero-copy">
        <span class="hero-kicker">Welcome to <?= h($shop['shop_name']) ?></span>
        <h1><?= h(implode(' ',$heroWords)) ?><?php if($heroLast!==''){ ?> <em><?= h($heroLast) ?></em><?php } ?></h1>
        <?php if($heroText!==''){ ?><p><?= h($heroText) ?></p><?php } ?>
        <div class="hero-actions">
            <a class="btn btn-gold" href="<?= h(shop_url('products')) ?>">Shop Now <span aria-hidden="true">→</span></a>
            <a class="btn btn-ghost" href="#latest">New Arrivals</a>
        </div>
        <?php if($signoff){ ?><small class="hero-signoff"><?= h(implode(' • ',$signoff)) ?></small><?php } ?>
    </div>
    <div class="hero-photo">
        <?php if($banners){ ?>
        <div class="slider" data-slider aria-label="Promotions">
            <?php foreach($banners as $index=>$banner){ $link=preg_match('#\A(https?://|/)#i',$banner['link_url'])?$banner['link_url']:''; ?>
            <div class="slide<?= $index===0?' is-active':'' ?>" data-slide>
                <?php if($link!==''){ ?><a href="<?= h($link) ?>"><?php } ?><img src="<?= h($banner['image_url']) ?>" alt="<?= h($banner['title']?:$shop['shop_name']) ?>" <?= $index===0?'fetchpriority="high"':'loading="lazy"' ?>><?php if($link!==''){ ?></a><?php } ?>
            </div>
            <?php } ?>
            <?php if(count($banners)>1){ ?><div class="slider-dots"><?php foreach($banners as $index=>$banner){ ?><button type="button" class="<?= $index===0?'is-active':'' ?>" data-slide-dot="<?= $index ?>" aria-label="Show slide <?= $index+1 ?>"></button><?php } ?></div><?php } ?>
        </div>
        <?php }elseif($heroPhoto!==''){ ?><img src="<?= h($heroPhoto) ?>" alt="<?= h($shop['shop_name']) ?>" fetchpriority="high">
        <?php }else{ ?><span class="hero-monogram"><?= h(mb_strtoupper(mb_substr($shop['shop_name'],0,1,'UTF-8'),'UTF-8')) ?></span><?php } ?>
    </div>
</div>
<?php if($tiles){ ?>
<nav class="cat-row" aria-label="Shop by category">
    <?php foreach($tiles as $tile){ $total=(int)$tile['category']['total']; ?>
    <a class="cat-tile" href="<?= h(category_url($tile['category']['id'],$tile['category']['name'])) ?>">
        <span class="cat-img"><?php if($tile['photo']!==''){ ?><img src="<?= h($tile['photo']) ?>" alt="" loading="lazy"><?php }else{ ?><?= icon('bag') ?><?php } ?></span>
        <span class="cat-text"><strong><?= h($tile['category']['name']) ?></strong><small><?= $total ?> item<?= $total===1?'':'s' ?></small></span>
    </a>
    <?php } ?>
</nav>
<?php } ?>
</div>
</section>

<section class="perks" aria-label="Why shop with us">
    <div class="container perks-grid">
        <div><?= icon('truck') ?><span><strong>Fast Delivery</strong><small><?= $zones?h(implode(' · ',array_map(function($z){ return $z['zone_name'].' '.money($z['charge']); },array_slice($zones,0,2)))):'Delivered to your door' ?></small></span></div>
        <div><?= icon('shield') ?><span><strong>Cash on Delivery</strong><small>Pay when your order arrives</small></span></div>
        <div><?= icon('check') ?><span><strong>Genuine Products</strong><small>Quality checked stock</small></span></div>
        <div><?= icon('phone') ?><span><strong>Support</strong><small><?= h($shop['phone']?:'We are here to help') ?></small></span></div>
    </div>
</section>

<?php if($newest){ ?>
<section class="section" id="latest">
    <div class="container">
        <?php $sectionHead('Just in','New Arrivals',shop_url('products'),'View all'); ?>
        <?php if($categorySections){ ?>
        <div class="pills" role="tablist" aria-label="Filter collection">
            <button type="button" class="pill is-active" role="tab" aria-selected="true" data-tab="all">All Products</button>
            <?php foreach($categorySections as $section){ ?><button type="button" class="pill" role="tab" aria-selected="false" data-tab="c<?= (int)$section['category']['id'] ?>"><?= h($section['category']['name']) ?></button><?php } ?>
        </div>
        <?php } ?>
        <div data-tab-pane="all"><?= product_grid($newest) ?></div>
        <?php foreach($categorySections as $section){ ?><div data-tab-pane="c<?= (int)$section['category']['id'] ?>" hidden><?= product_grid($section['products']) ?></div><?php } ?>
    </div>
</section>
<?php } ?>

<?php if($categorySections){ ?>
<section class="section promos" aria-label="Collections">
    <div class="container promo-grid">
        <?php foreach(array_slice($categorySections,0,3) as $index=>$section){ $category=$section['category']; $photo=$firstPhoto($section['products'],1); $subs=array_slice(array_map(function($s){ return $s['name']; },$category['subs']),0,3); ?>
        <a class="promo promo-<?= $promoTones[$index] ?>" href="<?= h(category_url($category['id'],$category['name'])) ?>">
            <div class="promo-copy">
                <h3><?= h($category['name']) ?></h3>
                <p><?= $subs?h(implode(', ',$subs)):(int)$category['total'].' product'.((int)$category['total']===1?'':'s') ?></p>
                <span class="promo-link">Shop now <?= icon('arrow','icon-xs') ?></span>
            </div>
            <?php if($photo!==''){ ?><div class="promo-photo"><img src="<?= h($photo) ?>" alt="" loading="lazy"></div><?php } ?>
        </a>
        <?php } ?>
    </div>
</section>
<?php } ?>

<?php if($featured){ ?>
<section class="section">
    <div class="container">
        <?php $sectionHead('Top picks','Featured Products',shop_url('products'),'View all'); ?>
        <?= product_grid($featured) ?>
    </div>
</section>
<?php } ?>

<?php if($campaignPhotos){ ?>
<section class="campaign">
    <div class="container campaign-inner">
        <div class="campaign-copy">
            <span class="eyebrow">Why shop with us</span>
            <h2><?= h($campaignBanner && trim($campaignBanner['title'])!=='' ? $campaignBanner['title'] : 'Quality You Can Trust.') ?></h2>
            <p><?= h($campaignBanner && trim($campaignBanner['subtitle'])!=='' ? $campaignBanner['subtitle'] : 'Genuine products · Cash on Delivery · Easy ordering') ?></p>
            <a class="btn btn-gold" href="<?= h(shop_url('products')) ?>">Start Shopping <span aria-hidden="true">→</span></a>
        </div>
        <div class="campaign-photos count-<?= count($campaignPhotos) ?>"><?php foreach($campaignPhotos as $photo){ ?><div><img src="<?= h($photo) ?>" alt="" loading="lazy"></div><?php } ?></div>
    </div>
</section>
<?php } ?>

<?php if($offers){ ?>
<section class="section deals" id="deals">
    <div class="container">
        <?php $sectionHead('Limited time','Hot Deals',shop_url('offers'),'All offers'); ?>
        <?= product_grid($offers) ?>
    </div>
</section>
<?php } ?>

<?php if($newest){ ?>
<section class="cta">
    <div class="container cta-inner">
        <div><h2>Everything you need, in one place.</h2><p>Browse the full range from <?= h($shop['shop_name']) ?> — delivered to your door.</p></div>
        <div class="cta-actions">
            <?php $ctaPhone=preg_replace('/[^0-9+]/','',(string)$shop['phone']); if($ctaPhone!==''){ ?><a class="btn btn-outline" href="tel:<?= h($ctaPhone) ?>"><?= icon('phone','icon-sm') ?> <?= h($shop['phone']) ?></a><?php } ?>
            <a class="btn btn-dark" href="<?= h(shop_url('products')) ?>">Explore All Products <span aria-hidden="true">→</span></a>
        </div>
    </div>
</section>
<?php }else{ ?>
<div class="container"><section class="panel empty-state"><h2>Products are coming soon</h2><p>Please check back shortly.</p></section></div>
<?php } ?>
