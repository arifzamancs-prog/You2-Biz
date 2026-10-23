<?php
$p=$product;
$inStock=$p['available_quantity']>0;
$inWishlist=in_array((int)$p['id'],$wishlist,true); $inCompare=in_array((int)$p['id'],$compare,true);
$maxQty=min(99,max(1,(int)$p['available_quantity']));
?>
<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?= h(shop_url()) ?>">Home</a><span>/</span><a href="<?= h(category_url($p['category_id'],$p['category_name'])) ?>"><?= h($p['category_name']) ?></a><?php if(trim((string)$p['sub_category'])!==''){ ?><span>/</span><a href="<?= h(category_url($p['category_id'],$p['category_name'],['sub'=>trim($p['sub_category'])])) ?>"><?= h(trim($p['sub_category'])) ?></a><?php } ?><span>/</span><span><?= h($p['product_name']) ?></span></nav>

    <article class="product-detail">
        <div class="gallery" data-gallery>
            <div class="gallery-main">
                <?php if($p['save']>0){ ?><span class="save-badge">Save: <?= money($p['save']) ?></span><?php } ?>
                <?php if($images){ ?><img src="<?= h($images[0]) ?>" alt="<?= h($p['product_name']) ?>" data-gallery-main fetchpriority="high"><?php }else{ ?><span class="no-photo">No photo</span><?php } ?>
            </div>
            <?php if(count($images)>1){ ?><div class="gallery-thumbs"><?php foreach($images as $index=>$image){ ?><button type="button" class="<?= $index===0?'is-active':'' ?>" data-gallery-thumb="<?= h($image) ?>" aria-label="Show image <?= $index+1 ?>"><img src="<?= h($image) ?>" alt="" loading="lazy"></button><?php } ?></div><?php } ?>
        </div>

        <div class="product-info">
            <h1><?= h($p['product_name']) ?></h1>
            <div class="info-chips">
                <div><small>Price</small><strong class="text-brand"><?= money($p['price']) ?></strong></div>
                <?php if($p['save']>0){ ?><div><small>Regular Price</small><del><?= money($p['regular_price']) ?></del></div><?php } ?>
                <div><small>Status</small><strong class="<?= $inStock?'text-success':'text-danger' ?>"><?= $inStock?'In Stock':'Out of Stock' ?></strong></div>
                <?php if(trim((string)$p['sku'])!==''){ ?><div><small>Product Code</small><strong><?= h($p['sku']) ?></strong></div><?php } ?>
                <?php if($p['brand']!==''){ ?><div><small>Brand</small><strong><?= h($p['brand']) ?></strong></div><?php } ?>
            </div>
            <?php if($p['save']>0){ ?><p class="you-save">You Save: <?= money($p['save']) ?> (<?= $p['save_percent'] ?>%)</p><?php } ?>

            <?php if($p['features']){ ?>
            <section class="key-features">
                <div class="key-features-head"><h2>Key Features</h2><?php if($specs || trim((string)$p['description'])!==''){ ?><a href="#details">View More Info</a><?php } ?></div>
                <ul><?php foreach(array_slice($p['features'],0,8) as $feature){ ?><li><?= icon('check','icon-sm') ?><?= h($feature) ?></li><?php } ?></ul>
            </section>
            <?php } ?>

            <section class="buy-box">
                <div class="buy-price"><span class="price"><?= money($p['price']) ?></span><?php if($p['save']>0){ ?><del><?= money($p['regular_price']) ?></del><?php } ?></div>
                <small class="muted">Cash on Delivery</small>
                <?php if($inStock){ ?>
                <form method="post" class="buy-form" data-buy-form>
                    <?= csrf_field().return_field() ?><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                    <?php if($p['has_variants']){ ?>
                    <label class="variant-label">Choose option
                        <select name="variant_name" required data-variant-select>
                            <?php foreach($p['variants'] as $option){ ?><option value="<?= h($option['name']) ?>" data-available="<?= min(99,(int)$option['available']) ?>" <?= (int)$option['available']<1?'disabled':'' ?>><?= h($option['name']) ?><?= (int)$option['available']<1?' — out of stock':'' ?></option><?php } ?>
                        </select>
                    </label>
                    <?php }else{ ?><input type="hidden" name="variant_name" value=""><?php } ?>
                    <div class="buy-row">
                        <div class="qty" data-qty><button type="button" data-qty-step="-1" aria-label="Decrease quantity">−</button><input type="number" name="quantity" value="1" min="1" max="<?= $maxQty ?>" aria-label="Quantity" data-qty-input><button type="button" data-qty-step="1" aria-label="Increase quantity">+</button></div>
                        <button class="btn btn-cart" name="action" value="add"><?= icon('cart','icon-sm') ?> Add to Cart</button>
                        <button class="btn btn-primary" name="action" value="buy_now"><?= icon('bolt','icon-sm') ?> Order Now</button>
                    </div>
                </form>
                <?php }else{ ?><p class="stock-out">This product is currently out of stock.</p><?php } ?>
                <div class="buy-secondary">
                    <form method="post"><?= csrf_field().return_field() ?><input type="hidden" name="action" value="wishlist"><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>"><button class="link-btn"><?= icon('heart','icon-sm') ?> <?= $inWishlist?'Remove from Wishlist':'Add to Wishlist' ?></button></form>
                    <form method="post"><?= csrf_field().return_field() ?><input type="hidden" name="action" value="compare"><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>"><button class="link-btn"><?= icon('compare','icon-sm') ?> <?= $inCompare?'Remove from Compare':'Add to Compare' ?></button></form>
                </div>
            </section>

            <?php if($zones){ ?>
            <section class="delivery-info">
                <?= icon('truck') ?>
                <div><strong>Delivery</strong><ul><?php foreach($zones as $zone){ ?><li><?= h($zone['zone_name']) ?>: <?= (float)$zone['charge']>0?money($zone['charge']):'Free' ?><?= $zone['delivery_time']!==''?' · '.h($zone['delivery_time']):'' ?></li><?php } ?></ul></div>
            </section>
            <?php } ?>
        </div>
    </article>

    <?php if($specs || trim((string)$p['description'])!==''){ ?>
    <section class="details" id="details">
        <nav class="tabs" role="tablist"><?php if($specs){ ?><a href="#specification" role="tab">Specification</a><?php } ?><?php if(trim((string)$p['description'])!==''){ ?><a href="#description" role="tab">Description</a><?php } ?></nav>
        <?php if($specs){ ?>
        <div class="panel" id="specification"><h2>Specification</h2>
            <table class="spec-table"><tbody>
                <?php foreach($specs as $row){ ?><?php if($row['value']===null){ ?><tr class="spec-group"><th colspan="2"><?= h($row['label']) ?></th></tr><?php }else{ ?><tr><td><?= h($row['label']) ?></td><td><?= h($row['value']) ?></td></tr><?php } ?><?php } ?>
            </tbody></table>
        </div>
        <?php } ?>
        <?php if(trim((string)$p['description'])!==''){ ?><div class="panel prose" id="description"><h2>Description</h2><p><?= nl2br(h($p['description'])) ?></p></div><?php } ?>
    </section>
    <?php } ?>

    <?php if($related){ ?>
    <section class="section">
        <div class="section-head"><h2>Related Products</h2><a class="see-all" href="<?= h(category_url($p['category_id'],$p['category_name'])) ?>">See all <?= icon('chevron','icon-sm') ?></a></div>
        <?= product_grid($related) ?>
    </section>
    <?php } ?>
</div>
