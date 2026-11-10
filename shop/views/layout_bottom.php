<?php
$phoneLink=preg_replace('/[^0-9+]/','',(string)$shop['phone']);
$whatsappLink=preg_replace('/[^0-9]/','',$shop['whatsapp']);
if($whatsappLink!=='' && strpos($whatsappLink,'0')===0) $whatsappLink='88'.$whatsappLink; // Bangladesh local numbers
$hasEmail=(bool)filter_var($shop['email']??'',FILTER_VALIDATE_EMAIL);
$safeSocial=function($url){ return preg_match('#\Ahttps?://#i',$url) ? $url : ''; };
$facebook=$safeSocial($shop['facebook_url']); $youtube=$safeSocial($shop['youtube_url']);
?>
</main>

<footer class="site-footer">
    <div class="container footer-grid">
        <section class="footer-brand">
            <a class="footer-logo" href="<?= h(shop_url()) ?>"><?= h($shop['shop_name']) ?></a>
            <?php if(trim((string)$shop['description'])!==''){ ?><p><?= nl2br(h(mb_strimwidth(trim((string)$shop['description']),0,260,'…','UTF-8'))) ?></p><?php } ?>
            <?php if($facebook!=='' || $youtube!==''){ ?><p class="social"><?php if($facebook!==''){ ?><a href="<?= h($facebook) ?>" target="_blank" rel="noopener">Facebook</a><?php } ?><?php if($youtube!==''){ ?><a href="<?= h($youtube) ?>" target="_blank" rel="noopener">YouTube</a><?php } ?></p><?php } ?>
        </section>
        <section>
            <h2>Shop</h2>
            <ul>
                <li><a href="<?= h(shop_url('products')) ?>">All Products</a></li>
                <?php foreach(array_slice($categories,0,5,true) as $category){ ?><li><a href="<?= h(category_url($category['id'],$category['name'])) ?>"><?= h($category['name']) ?></a></li><?php } ?>
                <li><a href="<?= h(shop_url('offers')) ?>">Deals</a></li>
            </ul>
        </section>
        <section>
            <h2>Customer Care</h2>
            <ul>
                <li><a href="<?= h(shop_url('track')) ?>">Track Order</a></li>
                <?php if(trim((string)$shop['return_policy'])!==''){ ?><li><a href="<?= h(shop_url('policy')) ?>">Return Policy</a></li><?php } ?>
                <?php if($phoneLink!==''){ ?><li><a href="tel:<?= h($phoneLink) ?>"><?= h($shop['phone']) ?></a></li><?php } ?>
                <?php if($hasEmail){ ?><li><a href="mailto:<?= h($shop['email']) ?>"><?= h($shop['email']) ?></a></li><?php } ?>
                <?php if(trim((string)$shop['address'])!==''){ ?><li class="footer-address"><?= nl2br(h($shop['address'])) ?></li><?php } ?>
            </ul>
        </section>
        <section>
            <h2>Your Account</h2>
            <ul>
                <li><a href="<?= h(shop_url('cart')) ?>">Shopping Cart</a></li>
                <li><a href="<?= h(shop_url('checkout')) ?>">Checkout</a></li>
                <li><a href="<?= h(shop_url('wishlist')) ?>">Wishlist</a></li>
                <li><a href="<?= h(shop_url('compare')) ?>">Compare</a></li>
                <li><a href="<?= h(shop_url($shop_customer?'track':'signin')) ?>"><?= $shop_customer?'My Orders':'Sign in' ?></a></li>
            </ul>
        </section>
    </div>
    <div class="container footer-bottom"><span>&copy; <?= date('Y') ?> <?= h($shop['shop_name']) ?>. All rights reserved.</span><span>Secure ordering · Cash on Delivery</span></div>
    <div class="footer-credit">Powered by <b>You2 Biz</b></div>
</footer>

<div class="floating" aria-label="Quick links">
    <?php if($whatsappLink!==''){ ?><a class="float-btn whatsapp" href="https://wa.me/<?= h($whatsappLink) ?>" target="_blank" rel="noopener" title="WhatsApp" aria-label="Chat on WhatsApp"><?= whatsapp_icon() ?></a><?php } ?>
    <a class="float-btn float-cart" href="<?= h(shop_url('cart')) ?>" data-cart-open title="Cart" aria-label="Cart, <?= $count ?> items"><?= icon('cart') ?><b class="count"><?= $count ?></b></a>
</div>
<script src="<?= h(app_path('shop/assets/store.js')) ?>?v=<?= $assetVersion ?>" defer></script>
</body>
</html>
