<?php
$verified=(bool)$shop_customer;
$value=function($field) use ($shop_customer){ return $_POST[$field] ?? ($shop_customer[$field] ?? ''); };
$selectedZone=(int)($_POST['delivery_zone_id'] ?? ($zones[0]['id'] ?? 0));
$selectedCharge=(float)$shop['delivery_charge'];
foreach($zones as $zone){ if((int)$zone['id']===$selectedZone) $selectedCharge=(float)$zone['charge']; }
$emailRequired=$verification_method==='email';
?>
<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?= h(shop_url()) ?>">Home</a><span>/</span><a href="<?= h(shop_url('cart')) ?>">Cart</a><span>/</span><span>Checkout</span></nav>
    <h1 class="page-title">Checkout</h1>
    <?php foreach(array_filter([$checkout_error,$auth_error]) as $error){ ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php } ?>
    <?php if(!$checkout_ready){ ?>
    <div class="panel notice"><h2>Checkout is temporarily unavailable</h2><p>This shop is preparing its online-order settings. Your cart is saved on this device; please contact the shop or check back shortly.</p></div>
    <?php }else{ ?>
    <form method="post" class="checkout-layout" data-checkout>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $verified?'checkout':'auth_send' ?>">
        <?php if($verified){ ?><input type="hidden" name="order_key" value="<?= h($_SESSION['shop_order_key'][$cid]) ?>"><?php } ?>
        <div class="checkout-steps">
            <section class="panel step">
                <h2><span class="step-no">1</span> Delivery Information</h2>
                <?php if($verified){ ?><p class="verified-note"><?= icon('check','icon-sm') ?> Verified <?= $shop_customer['channel']==='sms'?'phone':'email' ?>: <strong><?= h($shop_customer[$shop_customer['channel']==='sms'?'phone':'email']) ?></strong></p>
                <?php }else{ ?><p class="muted">For your security we will send a 6-digit code to your <?= $verification_method==='sms'?'phone by SMS':'email' ?>. No password is needed.</p><?php } ?>
                <div class="form-grid">
                    <label>Full Name *<input name="name" maxlength="150" required value="<?= h($value('name')) ?>" autocomplete="name"></label>
                    <label>Mobile Number (01XXXXXXXXX) *<input name="phone" type="tel" maxlength="30" required placeholder="017XXXXXXXX" value="<?= h($value('phone')) ?>" autocomplete="tel" <?= $verified && $shop_customer['channel']==='sms'?'readonly':'' ?>></label>
                    <label>Email <?= $emailRequired?'*':'(optional)' ?><input name="email" type="email" maxlength="100" value="<?= h($value('email')) ?>" autocomplete="email" <?= $emailRequired?'required':'' ?> <?= $verified && $shop_customer['channel']==='email'?'readonly':'' ?>></label>
                    <label class="span-2">Full Address *<textarea name="address" maxlength="2000" rows="3" required autocomplete="street-address"><?= h($value('address')) ?></textarea></label>
                </div>
            </section>

            <?php if($verified){ ?>
            <?php if($zones){ ?>
            <section class="panel step">
                <h2><span class="step-no">2</span> Delivery Method</h2>
                <?php foreach($zones as $zone){ ?>
                <label class="choice"><input type="radio" name="delivery_zone_id" value="<?= (int)$zone['id'] ?>" data-charge="<?= h((float)$zone['charge']) ?>" <?= (int)$zone['id']===$selectedZone?'checked':'' ?> required><span><strong><?= h($zone['zone_name']) ?></strong><?php if($zone['delivery_time']!==''){ ?><small><?= h($zone['delivery_time']) ?></small><?php } ?></span><b><?= (float)$zone['charge']>0?money($zone['charge']):'Free' ?></b></label>
                <?php } ?>
            </section>
            <?php } ?>
            <section class="panel step">
                <h2><span class="step-no"><?= $zones?3:2 ?></span> Payment Method</h2>
                <label class="choice is-static"><input type="radio" checked disabled><span><strong>Cash on Delivery</strong><small>Pay when your order arrives</small></span></label>
            </section>
            <?php } ?>
        </div>

        <aside class="summary">
            <h2>Your Order</h2>
            <ul class="summary-items">
                <?php foreach($cart_rows as $p){ ?><li><?php if($p['photo_url']!==''){ ?><img src="<?= h($p['photo_url']) ?>" alt=""><?php } ?><span><?= h($p['product_name']) ?><?= $p['variant_name']!==''?' ('.h($p['variant_name']).')':'' ?><small><?= money($p['price']) ?> × <?= (int)$p['quantity'] ?></small></span><b><?= money((float)$p['price']*$p['quantity']) ?></b></li><?php } ?>
            </ul>
            <dl><dt>Subtotal</dt><dd data-subtotal="<?= h($total) ?>"><?= money($total) ?></dd><dt>Delivery</dt><dd data-delivery><?= $verified||!$zones?money($selectedCharge):'Select after verification' ?></dd></dl>
            <div class="summary-total"><span>Total</span><strong data-total><?= money($total+($verified||!$zones?$selectedCharge:0)) ?></strong></div>
            <?php if($verified){ ?><button class="btn btn-primary btn-block">Place Order <?= icon('chevron','icon-sm') ?></button>
            <?php }else{ ?><button class="btn btn-primary btn-block">Send Verification Code <?= icon('chevron','icon-sm') ?></button><?php } ?>
            <p class="muted small">By placing this order you agree to our <?= trim((string)$shop['return_policy'])!==''?'<a href="'.h(shop_url('policy')).'">return policy</a>':'terms' ?>.</p>
        </aside>
    </form>
    <?php } ?>
</div>
