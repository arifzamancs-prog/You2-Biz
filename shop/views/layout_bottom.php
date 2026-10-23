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
        <section>
            <h2>About <?= h($shop['shop_name']) ?></h2>
            <?php if(trim((string)$shop['description'])!==''){ ?><p><?= nl2br(h(mb_strimwidth(trim((string)$shop['description']),0,400,'…','UTF-8'))) ?></p><?php } ?>
            <?php if($facebook!=='' || $youtube!==''){ ?><p class="social"><?php if($facebook!==''){ ?><a href="<?= h($facebook) ?>" target="_blank" rel="noopener">Facebook</a><?php } ?><?php if($youtube!==''){ ?><a href="<?= h($youtube) ?>" target="_blank" rel="noopener">YouTube</a><?php } ?></p><?php } ?>
        </section>
        <section>
            <h2>Support</h2>
            <ul>
                <li><a href="<?= h(shop_url('track')) ?>">Track Order</a></li>
                <?php if(trim((string)$shop['return_policy'])!==''){ ?><li><a href="<?= h(shop_url('policy')) ?>">Return Policy</a></li><?php } ?>
                <li><a href="<?= h(shop_url('offers')) ?>">Offers</a></li>
                <li><a href="<?= h(shop_url('wishlist')) ?>">Wishlist</a></li>
                <li><a href="<?= h(shop_url('compare')) ?>">Compare</a></li>
            </ul>
        </section>
        <section>
            <h2>Contact</h2>
            <?php if(trim((string)$shop['address'])!==''){ ?><p><?= nl2br(h($shop['address'])) ?></p><?php } ?>
            <?php if($phoneLink!==''){ ?><p><a class="footer-phone" href="tel:<?= h($phoneLink) ?>"><?= icon('phone','icon-sm') ?><?= h($shop['phone']) ?></a></p><?php } ?>
            <?php if($hasEmail){ ?><p><a href="mailto:<?= h($shop['email']) ?>"><?= h($shop['email']) ?></a></p><?php } ?>
        </section>
    </div>
    <div class="footer-bottom"><div class="container">&copy; <?= date('Y') ?> <?= h($shop['shop_name']) ?>. All rights reserved. <span>Powered by You2 Biz</span></div></div>
</footer>

<div class="floating" aria-label="Quick links">
    <a class="float-btn" href="<?= h(shop_url('offers')) ?>" title="Offers" aria-label="Offers"><?= icon('bolt') ?></a>
    <a class="float-btn" href="<?= h(shop_url('wishlist')) ?>" title="Wishlist" aria-label="Wishlist, <?= count($wishlist) ?> items"><?= icon('heart') ?><?php if($wishlist){ ?><b class="count"><?= count($wishlist) ?></b><?php } ?></a>
    <?php if($compare){ ?><a class="float-btn" href="<?= h(shop_url('compare')) ?>" title="Compare" aria-label="Compare, <?= count($compare) ?> items"><?= icon('compare') ?><b class="count"><?= count($compare) ?></b></a><?php } ?>
    <a class="float-btn" href="<?= h(shop_url('cart')) ?>" title="Cart" aria-label="Cart, <?= $count ?> items"><?= icon('cart') ?><?php if($count){ ?><b class="count"><?= $count ?></b><?php } ?></a>
    <?php if($whatsappLink!==''){ ?><a class="float-btn whatsapp" href="https://wa.me/<?= h($whatsappLink) ?>" target="_blank" rel="noopener" title="WhatsApp" aria-label="Chat on WhatsApp"><?= whatsapp_icon() ?></a><?php } ?>
</div>
<script src="<?= h(app_path('shop/assets/store.js')) ?>?v=<?= $assetVersion ?>" defer></script>
</body>
</html>
