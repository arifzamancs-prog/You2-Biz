<div class="container narrow">
    <section class="panel auth-panel">
        <h1>Track Your Order</h1>
        <p class="muted">Enter the order reference from your confirmation and the phone number used at checkout.</p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="track">
            <label>Order reference<input name="order_no" maxlength="50" required value="<?= h($_POST['order_no']??'') ?>" placeholder="ESH-..."></label>
            <label>Phone number<input name="phone" type="tel" maxlength="30" required value="<?= h($_POST['phone']??'') ?>"></label>
            <button class="btn btn-primary btn-block">Track Order</button>
        </form>
    </section>
    <?php if($tracking_error!==''){ ?><div class="alert alert-danger" role="alert"><?= h($tracking_error) ?></div><?php } ?>
    <?php if($tracked_order){
        $steps=['new'=>'Order Placed','processing'=>'Processing','shipped'=>'Shipped','delivered'=>'Delivered'];
        $stepKeys=array_keys($steps);
        $currentStep=array_search($tracked_order['delivery_status'],$stepKeys,true);
    ?>
    <section class="panel" role="status">
        <h2>Order <?= h($tracked_order['order_no']) ?></h2>
        <p>Hello <?= h($tracked_order['customer_name']) ?>. Placed: <?= h($tracked_order['created_at']) ?></p>
        <?php if($currentStep!==false){ ?><ol class="progress"><?php foreach($stepKeys as $i=>$key){ ?><li class="<?= $i<=$currentStep?'is-done':'' ?>"><?= h($steps[$key]) ?></li><?php } ?></ol>
        <?php }else{ ?><p class="notice">Delivery status: <?= h(ucfirst($tracked_order['delivery_status'])) ?></p><?php } ?>
        <div class="track-grid">
            <div><small>Delivery</small><strong><?= h(ucfirst($tracked_order['delivery_status'])) ?></strong><?php if($tracked_order['tracking']!==''){ ?><span>Tracking: <?= h($tracked_order['tracking']) ?></span><?php } ?></div>
            <div><small>Payment</small><strong><?= h($tracked_order['accounting_status']==='posted'?'Confirmed':'Pending confirmation') ?></strong><span><?= h(ucfirst($tracked_order['payment_status']??'due')) ?></span></div>
            <div><small>Refund</small><strong><?= h($tracked_order['refund']['label']) ?></strong><?php if($tracked_order['refund']['count']>0){ ?><span><?= money($tracked_order['refund']['amount']) ?> refunded</span><?php } ?></div>
        </div>
        <h3>Order items</h3>
        <?php if($tracked_order['items']){ ?>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th>Option</th><th>Qty</th><th>Total</th></tr></thead><tbody>
            <?php foreach($tracked_order['items'] as $item){ ?><tr><td><?= h($item['product_name']) ?></td><td><?= h($item['variant_name']?:'—') ?></td><td><?= (int)$item['quantity'] ?></td><td><?= money($item['total_price']) ?></td></tr><?php } ?>
        </tbody></table></div>
        <?php }else{ ?><p class="muted">Order item details are unavailable.</p><?php } ?>
        <p><strong>Order total:</strong> <?= money($tracked_order['total']) ?></p>
        <?php if($tracked_order['history']){ ?><h3>Delivery updates</h3><ul class="history"><?php foreach($tracked_order['history'] as $event){ ?><li><?= h($event['created_at'].' — '.ucfirst($event['status']).($event['tracking']!==''?' — '.$event['tracking']:'')) ?></li><?php } ?></ul><?php } ?>
    </section>
    <?php } ?>
</div>
