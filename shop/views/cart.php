<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?= h(shop_url()) ?>">Home</a><span>/</span><span>Shopping Cart</span></nav>
    <h1 class="page-title">Shopping Cart</h1>
    <?php if(!$cart_rows){ ?>
    <div class="panel empty-state"><?= icon('cart','icon-lg') ?><h2>Your cart is empty</h2><p>Explore our products and add something you love.</p><a class="btn btn-primary" href="<?= h(shop_url('products')) ?>">Continue Shopping</a></div>
    <?php }else{ ?>
    <p class="muted"><?= $count ?> item<?= $count===1?'':'s' ?> in your cart</p>
    <div class="cart-layout">
        <div class="cart-items">
            <?php foreach($cart_rows as $p){ ?>
            <div class="cart-item">
                <a class="cart-photo" href="<?= h(product_url($p)) ?>"><?php if($p['photo_url']!==''){ ?><img src="<?= h($p['photo_url']) ?>" alt="<?= h($p['product_name']) ?>"><?php }else{ ?><span class="no-photo">No photo</span><?php } ?></a>
                <div class="cart-info">
                    <h2><a href="<?= h(product_url($p)) ?>"><?= h($p['product_name']) ?></a></h2>
                    <small class="muted"><?= $p['brand']!==''?'Brand: '.h($p['brand']):'' ?><?= $p['variant_name']!==''?($p['brand']!==''?' · ':'').'Option: '.h($p['variant_name']):'' ?></small>
                    <div class="price"><?= money($p['price']) ?></div>
                    <form method="post" class="cart-qty">
                        <?= csrf_field() ?><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="variant_name" value="<?= h($p['variant_name']) ?>">
                        <div class="qty" data-qty><button type="button" data-qty-step="-1" aria-label="Decrease quantity">−</button><input type="number" name="quantity" min="1" max="<?= (int)$p['available_quantity'] ?>" value="<?= (int)$p['quantity'] ?>" aria-label="Quantity" data-qty-input><button type="button" data-qty-step="1" aria-label="Increase quantity">+</button></div>
                        <button class="btn btn-outline btn-sm" name="action" value="update">Update</button>
                        <button class="link-btn text-danger" name="action" value="remove"><?= icon('trash','icon-sm') ?> Remove</button>
                    </form>
                    <small class="muted"><?= (int)$p['available_quantity'] ?> available</small>
                </div>
                <strong class="cart-line"><?= money((float)$p['price']*$p['quantity']) ?></strong>
            </div>
            <?php } ?>
        </div>
        <aside class="summary">
            <h2>Order Summary</h2>
            <dl><dt>Subtotal</dt><dd><?= money($total) ?></dd><dt>Delivery</dt><dd><?= $zones?'Calculated at checkout':money($shop['delivery_charge']) ?></dd></dl>
            <div class="summary-total"><span>Total</span><strong><?= money($zones?$total:$total+(float)$shop['delivery_charge']) ?></strong></div>
            <?php if($checkout_ready){ ?><a class="btn btn-primary btn-block" href="<?= h(shop_url('checkout')) ?>">Proceed to Checkout <?= icon('chevron','icon-sm') ?></a>
            <?php }else{ ?><p class="notice">Online ordering is being prepared. Please contact the shop or check back shortly.</p><?php } ?>
            <a class="summary-link" href="<?= h(shop_url('products')) ?>">Continue Shopping</a>
            <form method="post" onsubmit="return confirm('Remove all items from your cart?');"><?= csrf_field() ?><input type="hidden" name="action" value="cart_clear"><button class="link-btn text-danger summary-link">Clear Cart</button></form>
            <p class="muted small">Items are not reserved until you place an order.</p>
        </aside>
    </div>
    <?php } ?>
</div>
