<?php
$p=$product;
$inStock=$p['available_quantity']>0;
$inWishlist=in_array((int)$p['id'],$wishlist,true); $inCompare=in_array((int)$p['id'],$compare,true);
$maxQty=min(99,max(1,(int)$p['available_quantity']));
$firstVariant=null; foreach($p['variants'] as $option){ if((int)$option['available']>0){ $firstVariant=$option['name']; break; } }
?>
<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?= h(shop_url()) ?>">Home</a><span>/</span><a href="<?= h(category_url($p['category_id'],$p['category_name'])) ?>"><?= h($p['category_name']) ?></a><?php if(trim((string)$p['sub_category'])!==''){ ?><span>/</span><a href="<?= h(category_url($p['category_id'],$p['category_name'],['sub'=>trim($p['sub_category'])])) ?>"><?= h(trim($p['sub_category'])) ?></a><?php } ?><span>/</span><span><?= h($p['product_name']) ?></span></nav>

    <article class="product-detail">
        <div class="gallery" data-gallery>
            <div class="gallery-main">
                <?php if($p['save']>0){ ?><span class="sale-badge">Sale!</span><?php } ?>
                <?php if($images){ ?><img src="<?= h($images[0]) ?>" alt="<?= h($p['product_name']) ?>" data-gallery-main fetchpriority="high"><?php }else{ ?><span class="no-photo">No photo</span><?php } ?>
            </div>
            <?php if(count($images)>1){ ?><div class="gallery-thumbs"><?php foreach($images as $index=>$image){ ?><button type="button" class="<?= $index===0?'is-active':'' ?>" data-gallery-thumb="<?= h($image) ?>" aria-label="Show image <?= $index+1 ?>"><img src="<?= h($image) ?>" alt="" loading="lazy"></button><?php } ?></div><?php } ?>
        </div>

        <div class="product-info">
            <span class="card-meta"><?= h($p['category_name']) ?><?= $p['brand']!==''?' · '.h($p['brand']):'' ?></span>
            <h1><?= h($p['product_name']) ?></h1>
            <div class="product-price"><?php if($p['save']>0){ ?><del><?= money($p['regular_price']) ?></del><?php } ?><ins><?= money($p['price']) ?></ins><?php if($p['save']>0){ ?><span class="save-pill">Save <?= money($p['save']) ?> (<?= $p['save_percent'] ?>%)</span><?php } ?></div>
            <p class="stock-line <?= $inStock?'is-in':'is-out' ?>"><?= $inStock?(int)$p['available_quantity'].' in stock':'Out of stock' ?></p>

            <?php if($p['features']){ ?>
            <ul class="feature-list"><?php foreach(array_slice($p['features'],0,8) as $feature){ ?><li><?= icon('check','icon-sm') ?><?= h($feature) ?></li><?php } ?></ul>
            <?php } ?>

            <?php if($inStock){ ?>
            <form method="post" class="buy-form" data-buy-form>
                <?= csrf_field().return_field() ?><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                <?php if($p['has_variants']){ ?>
                <fieldset class="variant-chips" data-variant-chips>
                    <legend>Choose option</legend>
                    <?php foreach($p['variants'] as $option){ $out=(int)$option['available']<1; ?>
                    <label class="chip<?= $out?' is-out':'' ?>"><input type="radio" name="variant_name" value="<?= h($option['name']) ?>" data-available="<?= min(99,(int)$option['available']) ?>" <?= $out?'disabled':'' ?> <?= $option['name']===$firstVariant?'checked':'' ?> required><span><?= h($option['name']) ?></span></label>
                    <?php } ?>
                </fieldset>
                <?php }else{ ?><input type="hidden" name="variant_name" value=""><?php } ?>
                <div class="buy-row">
                    <div class="qty" data-qty><button type="button" data-qty-step="-1" aria-label="Decrease quantity">−</button><input type="number" name="quantity" value="1" min="1" max="<?= $maxQty ?>" aria-label="Quantity" data-qty-input><button type="button" data-qty-step="1" aria-label="Increase quantity">+</button></div>
                    <button class="btn btn-dark" name="action" value="add"><?= icon('bag','icon-sm') ?> Add to Cart</button>
                    <button class="btn btn-gold" name="action" value="buy_now">Buy Now <span aria-hidden="true">→</span></button>
                </div>
            </form>
            <?php }else{ ?><p class="stock-out">This product is currently out of stock.</p><?php } ?>
            <div class="buy-secondary">
                <form method="post"><?= csrf_field().return_field() ?><input type="hidden" name="action" value="wishlist"><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>"><button class="link-btn<?= $inWishlist?' is-active':'' ?>"><?= icon('heart','icon-sm') ?> <?= $inWishlist?'In Wishlist':'Wishlist' ?></button></form>
                <form method="post"><?= csrf_field().return_field() ?><input type="hidden" name="action" value="compare"><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>"><button class="link-btn<?= $inCompare?' is-active':'' ?>"><?= icon('compare','icon-sm') ?> <?= $inCompare?'In Compare':'Compare' ?></button></form>
            </div>

            <p class="product-meta"><?php if(trim((string)$p['sku'])!==''){ ?><span>Code: <b><?= h($p['sku']) ?></b></span><?php } ?><span>Category: <a href="<?= h(category_url($p['category_id'],$p['category_name'])) ?>"><?= h($p['category_name']) ?></a><?= trim((string)$p['sub_category'])!==''?', '.h(trim($p['sub_category'])):'' ?></span></p>

            <ul class="assurance">
                <li><?= icon('shield','icon-sm') ?> Cash on Delivery</li>
                <?php foreach($zones as $zone){ ?><li><?= icon('truck','icon-sm') ?> <?= h($zone['zone_name']) ?>: <?= (float)$zone['charge']>0?money($zone['charge']):'Free' ?><?= $zone['delivery_time']!==''?' · '.h($zone['delivery_time']):'' ?></li><?php } ?>
            </ul>
        </div>
    </article>

    <?php if($specs || trim((string)$p['description'])!==''){ ?>
    <section class="details" id="details" data-tabs>
        <nav class="tabs" role="tablist">
            <?php if(trim((string)$p['description'])!==''){ ?><button type="button" role="tab" data-tab="description" class="is-active">Description</button><?php } ?>
            <?php if($specs){ ?><button type="button" role="tab" data-tab="specification" class="<?= trim((string)$p['description'])===''?'is-active':'' ?>">Additional information</button><?php } ?>
        </nav>
        <?php if(trim((string)$p['description'])!==''){ ?><div class="tab-body prose" id="description" data-tab-pane="description"><h2>Description</h2><p><?= nl2br(h($p['description'])) ?></p></div><?php } ?>
        <?php if($specs){ ?>
        <div class="tab-body" id="specification" data-tab-pane="specification" <?= trim((string)$p['description'])!==''?'hidden':'' ?>><h2>Additional information</h2>
            <table class="spec-table"><tbody>
                <?php foreach($specs as $row){ ?><?php if($row['value']===null){ ?><tr class="spec-group"><th colspan="2"><?= h($row['label']) ?></th></tr><?php }else{ ?><tr><td><?= h($row['label']) ?></td><td><?= h($row['value']) ?></td></tr><?php } ?><?php } ?>
            </tbody></table>
        </div>
        <?php } ?>
    </section>
    <?php } ?>

    <?php if($related){ ?>
    <section class="section">
        <div class="section-head"><div><span class="eyebrow">You may also like</span><h2>Related Products</h2></div><a class="see-all" href="<?= h(category_url($p['category_id'],$p['category_name'])) ?>">View all <?= icon('arrow','icon-sm') ?></a></div>
        <?= product_grid($related) ?>
    </section>
    <?php } ?>
</div>
