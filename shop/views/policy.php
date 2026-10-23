<div class="container narrow">
    <section class="panel prose">
        <h1>Return Policy</h1>
        <?php if(trim((string)$shop['return_policy'])!==''){ ?><p><?= nl2br(h($shop['return_policy'])) ?></p><?php }else{ ?><p class="muted">Please contact the shop for return information.</p><?php } ?>
        <?php if(trim((string)$shop['description'])!==''){ ?><h2>About <?= h($shop['shop_name']) ?></h2><p><?= nl2br(h($shop['description'])) ?></p><?php } ?>
    </section>
</div>
