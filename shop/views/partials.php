<?php
// Small building blocks shared by every storefront page.

function csrf_field(){ return '<input type="hidden" name="csrf" value="'.h($_SESSION['shop_cart_csrf']).'">'; }
function return_field(){ global $currentUrl; return '<input type="hidden" name="return_to" value="'.h($currentUrl).'">'; }

function icon($name,$class='icon'){
    $paths=[
        'search'=>'<path d="m21 21-4.35-4.35M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z"/>',
        'cart'=>'<path d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z"/>',
        'heart'=>'<path d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z"/>',
        'compare'=>'<path d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"/>',
        'user'=>'<path d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/>',
        'gift'=>'<path d="M21 11.25v8.25a1.5 1.5 0 0 1-1.5 1.5H4.5A1.5 1.5 0 0 1 3 19.5v-8.25M12 4.875A2.625 2.625 0 1 0 9.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1 1 14.625 7.5H12m0 0V21m-8.625-9.75h18c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125h-18c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"/>',
        'truck'=>'<path d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/>',
        'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>',
        'close'=>'<path d="M6 18 18 6M6 6l12 12"/>',
        'check'=>'<path d="m4.5 12.75 6 6 9-13.5"/>',
        'chevron'=>'<path d="m8.25 4.5 7.5 7.5-7.5 7.5"/>',
        'down'=>'<path d="m19.5 8.25-7.5 7.5-7.5-7.5"/>',
        'trash'=>'<path d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/>',
        'bolt'=>'<path d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z"/>',
        'filter'=>'<path d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 0 1-.659 1.591l-5.432 5.432a2.25 2.25 0 0 0-.659 1.591v2.927a2.25 2.25 0 0 1-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 0 0-.659-1.591L3.659 7.409A2.25 2.25 0 0 1 3 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0 1 12 3Z"/>',
        'shield'=>'<path d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/>',
        'phone'=>'<path d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z"/>',
    ];
    return '<svg class="'.h($class).'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true" stroke-linecap="round" stroke-linejoin="round">'.($paths[$name]??'').'</svg>';
}

function whatsapp_icon(){
    return '<svg class="icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.47 14.38c-.3-.15-1.75-.86-2.02-.96-.27-.1-.47-.15-.67.15-.2.3-.77.96-.94 1.16-.17.2-.35.22-.64.07-.3-.15-1.25-.46-2.38-1.47-.88-.79-1.47-1.76-1.65-2.05-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.6-.92-2.2-.24-.57-.48-.5-.67-.5h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.08c.15.2 2.1 3.2 5.08 4.48.71.31 1.26.49 1.69.63.71.22 1.36.19 1.87.12.57-.09 1.75-.72 2-1.41.25-.69.25-1.29.17-1.41-.07-.12-.27-.2-.57-.35M12.05 21.5h-.01a9.45 9.45 0 0 1-4.82-1.32l-.35-.21-3.58.94.96-3.49-.23-.36a9.42 9.42 0 0 1-1.44-5.03c0-5.2 4.24-9.44 9.46-9.44a9.4 9.4 0 0 1 6.68 2.77 9.38 9.38 0 0 1 2.77 6.68c0 5.21-4.24 9.45-9.44 9.45M20.1 3.9A11.32 11.32 0 0 0 12.04.56C5.77.56.67 5.66.67 11.93c0 2 .52 3.96 1.52 5.68L.57 23.5l6.03-1.58a11.34 11.34 0 0 0 5.44 1.39h.01c6.27 0 11.37-5.1 11.37-11.37 0-3.04-1.18-5.9-3.33-8.04"/></svg>';
}

function product_card($p){
    global $wishlist,$compare;
    $url=product_url($p);
    $inWishlist=in_array((int)$p['id'],$wishlist,true); $inCompare=in_array((int)$p['id'],$compare,true);
    ob_start(); ?>
<article class="card">
    <?php if($p['save']>0){ ?><span class="save-badge">Save: <?= money($p['save']) ?></span><?php } ?>
    <a class="card-photo" href="<?= h($url) ?>"><?php if($p['photo_url']!==''){ ?><img loading="lazy" src="<?= h($p['photo_url']) ?>" alt="<?= h($p['product_name']) ?>"><?php }else{ ?><span class="no-photo">No photo</span><?php } ?></a>
    <div class="card-body">
        <h3 class="card-title"><a href="<?= h($url) ?>"><?= h($p['product_name']) ?></a></h3>
        <?php if($p['features'] || $p['brand']!==''){ ?><ul class="card-features"><?php if($p['brand']!==''){ ?><li>Brand: <?= h($p['brand']) ?></li><?php } ?><?php foreach(array_slice($p['features'],0,$p['brand']!==''?2:3) as $feature){ ?><li><?= h($feature) ?></li><?php } ?></ul><?php } ?>
        <div class="card-price"><span class="price"><?= money($p['price']) ?></span><?php if($p['save']>0){ ?><del><?= money($p['regular_price']) ?></del><?php } ?></div>
        <div class="card-actions">
            <?php if($p['available_quantity']<1){ ?><span class="btn btn-disabled">Out of Stock</span>
            <?php }elseif($p['has_variants']){ ?><a class="btn btn-outline" href="<?= h($url) ?>">Choose Option</a>
            <?php }else{ ?><form method="post"><?= csrf_field().return_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="variant_name" value=""><input type="hidden" name="quantity" value="1"><button class="btn btn-outline">Add to Cart</button></form><?php } ?>
            <form method="post"><?= csrf_field().return_field() ?><input type="hidden" name="action" value="wishlist"><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>"><button class="icon-btn<?= $inWishlist?' is-active':'' ?>" title="<?= $inWishlist?'Remove from wishlist':'Add to wishlist' ?>" aria-label="<?= $inWishlist?'Remove from wishlist':'Add to wishlist' ?>"><?= icon('heart') ?></button></form>
            <form method="post"><?= csrf_field().return_field() ?><input type="hidden" name="action" value="compare"><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>"><button class="icon-btn<?= $inCompare?' is-active':'' ?>" title="<?= $inCompare?'Remove from compare':'Add to compare' ?>" aria-label="<?= $inCompare?'Remove from compare':'Add to compare' ?>"><?= icon('compare') ?></button></form>
        </div>
    </div>
</article>
<?php return ob_get_clean();
}

function product_grid($products,$class='grid'){
    if(!$products) return '';
    $html='<div class="'.h($class).'">';
    foreach($products as $p) $html.=product_card($p);
    return $html.'</div>';
}
