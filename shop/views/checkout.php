<?php
$verified=(bool)$shop_customer;
$value=function($field) use ($shop_customer){ return $_POST[$field] ?? ($shop_customer[$field] ?? ''); };
// The chosen area is kept in the session so it survives the OTP step.
$selectedZone=(int)($_POST['delivery_zone_id'] ?? ($_SESSION['shop_zone'][$cid] ?? 0));
if(!in_array($selectedZone,array_map('intval',array_column($zones,'id')),true)) $selectedZone=(int)($zones[0]['id'] ?? 0);
$selectedCharge=(float)$shop['delivery_charge'];
foreach($zones as $zone){ if((int)$zone['id']===$selectedZone) $selectedCharge=(float)$zone['charge']; }
$emailRequired=$verification_method==='email';
?>
<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?= h(shop_url()) ?>">Home</a><span>/</span><a href="<?= h(shop_url('cart')) ?>">Cart</a><span>/</span><span>Checkout</span></nav>
    <h1 class="page-title">চেকআউট</h1>
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
                <h2><span class="step-no">1</span> বিলিং ও শিপিং তথ্য</h2>
                <?php if($verified){ ?><p class="verified-note"><?= icon('check','icon-sm') ?> যাচাইকৃত <?= $shop_customer['channel']==='sms'?'মোবাইল':'ইমেইল' ?>: <strong><?= h($shop_customer[$shop_customer['channel']==='sms'?'phone':'email']) ?></strong> <button type="submit" form="change-verified-contact" class="link-btn change-contact">পরিবর্তন করুন</button></p>
                <?php }else{ ?><p class="muted">অর্ডার নিশ্চিত করতে আপনার <?= $verification_method==='sms'?'মোবাইলে SMS':'ইমেইলে' ?> একটি ৬ সংখ্যার কোড পাঠানো হবে। কোনো পাসওয়ার্ড লাগবে না।</p><?php } ?>
                <div class="form-grid">
                    <label>আপনার নাম *<input name="name" maxlength="150" required value="<?= h($value('name')) ?>" autocomplete="name"></label>
                    <label>মোবাইল নম্বর *<input name="phone" type="tel" maxlength="30" required placeholder="017XXXXXXXX" value="<?= h($value('phone')) ?>" autocomplete="tel" <?= $verified && $shop_customer['channel']==='sms'?'readonly':'' ?>></label>
                    <label>ইমেইল <?= $emailRequired?'*':'(ঐচ্ছিক)' ?><input name="email" type="email" maxlength="100" value="<?= h($value('email')) ?>" autocomplete="email" <?= $emailRequired?'required':'' ?> <?= $verified && $shop_customer['channel']==='email'?'readonly':'' ?>></label>
                    <label class="span-2">সম্পূর্ণ ঠিকানা *<textarea name="address" maxlength="2000" rows="3" required autocomplete="street-address" placeholder="বাসা/রোড, এলাকা, থানা, জেলা"><?= h($value('address')) ?></textarea></label>
                </div>
            </section>

            <?php if($zones){ ?>
            <section class="panel step">
                <h2><span class="step-no">2</span> Shipment</h2>
                <p class="muted small"><?= count($zones)>1?'ঠিকানা অনুযায়ী ডেলিভারি এলাকা বেছে নিন। চার্জ সঙ্গে সঙ্গে মোট দামে যোগ হবে।':'এই শপে এখন শুধু নিচের এলাকায় ডেলিভারি চালু আছে।' ?></p>
                <?php foreach($zones as $zone){ ?>
                <label class="choice"><input type="radio" name="delivery_zone_id" value="<?= (int)$zone['id'] ?>" data-charge="<?= h((float)$zone['charge']) ?>" <?= (int)$zone['id']===$selectedZone?'checked':'' ?> required><span><strong><?= h($zone['zone_name']) ?></strong><?php if($zone['delivery_time']!==''){ ?><small><?= h($zone['delivery_time']) ?></small><?php } ?></span><b><?= (float)$zone['charge']>0?money($zone['charge']):'Free' ?></b></label>
                <?php } ?>
                <?php if(count($zones)===1){ $zonePhone=preg_replace('/[^0-9+]/','',(string)$shop['phone']); ?><p class="zone-help">অন্য এলাকায় পাঠাতে চান?<?php if($zonePhone!==''){ ?> কল করুন <a href="tel:<?= h($zonePhone) ?>"><?= h($shop['phone']) ?></a><?php }else{ ?> দোকানের সাথে যোগাযোগ করুন<?php } ?>।</p><?php } ?>
            </section>
            <?php } ?>
            <section class="panel step">
                <h2><span class="step-no"><?= $zones?3:2 ?></span> পেমেন্ট পদ্ধতি</h2>
                <label class="choice is-static"><input type="radio" checked disabled><span><strong>Cash on Delivery</strong><small>পণ্য হাতে পেয়ে মূল্য পরিশোধ করুন</small></span></label>
            </section>
        </div>

        <aside class="summary">
            <h2>আপনার অর্ডার</h2>
            <ul class="summary-items">
                <?php foreach($cart_rows as $p){ ?><li data-checkout-item data-price="<?= h((float)$p['price']) ?>" data-product="<?= (int)$p['id'] ?>" data-variant="<?= h($p['variant_name']) ?>"><?php if($p['photo_url']!==''){ ?><img src="<?= h($p['photo_url']) ?>" alt=""><?php } ?><span><?= h($p['product_name']) ?><?= $p['variant_name']!==''?' ('.h($p['variant_name']).')':'' ?><small><?= money($p['price']) ?> ×</small><span class="qty qty-sm" data-qty><button type="button" data-qty-step="-1" aria-label="Decrease quantity">−</button><input type="number" min="1" max="<?= (int)$p['available_quantity'] ?>" value="<?= (int)$p['quantity'] ?>" aria-label="Quantity of <?= h($p['product_name']) ?>" data-qty-input data-checkout-qty><button type="button" data-qty-step="1" aria-label="Increase quantity">+</button></span></span><b data-checkout-line><?= money((float)$p['price']*$p['quantity']) ?></b></li><?php } ?>
            </ul>
            <dl><dt>সাবটোটাল</dt><dd data-subtotal="<?= h($total) ?>"><?= money($total) ?></dd><dt>ডেলিভারি চার্জ</dt><dd data-delivery data-charge="<?= h($selectedCharge) ?>"><?= money($selectedCharge) ?></dd></dl>
            <div class="summary-total"><span>সর্বমোট</span><strong data-total><?= money($total+$selectedCharge) ?></strong></div>
            <?php if($verified){ ?><button class="btn btn-gold btn-block btn-lg">অর্ডার নিশ্চিত করুন <?= icon('chevron','icon-sm') ?></button>
            <?php }else{ ?><button class="btn btn-gold btn-block btn-lg">ভেরিফিকেশন কোড পাঠান <?= icon('chevron','icon-sm') ?></button><?php } ?>
            <p class="muted small">অর্ডার করার মাধ্যমে আপনি আমাদের <?= trim((string)$shop['return_policy'])!==''?'<a href="'.h(shop_url('policy')).'">রিটার্ন পলিসি</a>':'শর্তাবলি' ?>-তে সম্মত হচ্ছেন।</p>
        </aside>
    </form>
    <?php if($verified){ ?><form method="post" id="change-verified-contact" hidden><?= csrf_field() ?><input type="hidden" name="action" value="auth_logout"><input type="hidden" name="return_to" value="<?= h(shop_url('checkout')) ?>"></form><?php } ?>
    <?php } ?>
</div>
