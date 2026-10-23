<div class="container narrow">
    <section class="panel success-panel" role="status">
        <span class="success-icon"><?= icon('check','icon-lg') ?></span>
        <h1>Thank you for your order!</h1>
        <p>Order reference: <strong><?= h($_SESSION['shop_last_order'][$cid]) ?></strong></p>
        <p>Your order is pending confirmation. Payment method: cash on delivery.</p>
        <p class="muted">Please save this reference. You will need it with your phone number to track delivery.</p>
        <div class="success-actions"><a class="btn btn-primary" href="<?= h(shop_url('track')) ?>">Track This Order</a><a class="btn btn-outline" href="<?= h(shop_url('products')) ?>">Continue Shopping</a></div>
    </section>
</div>
