<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?= h(shop_url()) ?>">Home</a><span>/</span><span>Wishlist</span></nav>
    <h1 class="page-title">My Wishlist</h1>
    <?php if($products){ ?><?= product_grid($products) ?>
    <?php }else{ ?><div class="panel empty-state"><?= icon('heart','icon-lg') ?><h2>Your wishlist is empty</h2><p>Tap the heart on any product to save it here.</p><a class="btn btn-primary" href="<?= h(shop_url('products')) ?>">Browse Products</a></div><?php } ?>
</div>
