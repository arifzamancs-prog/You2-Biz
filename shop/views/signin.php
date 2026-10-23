<?php $contactField=$verification_method==='sms'?'phone':'email'; ?>
<div class="container narrow">
    <section class="panel auth-panel">
        <h1>Sign in with a code</h1>
        <?php if($auth_error!==''){ ?><div class="alert alert-danger" role="alert"><?= h($auth_error) ?></div><?php } ?>
        <p class="muted">Enter the <?= $verification_method==='sms'?'phone number':'email address' ?> used for a previous order. No password is required.</p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="auth_login_send">
            <label><?= $verification_method==='sms'?'Phone number':'Email address' ?><input name="<?= $contactField ?>" type="<?= $verification_method==='sms'?'tel':'email' ?>" required value="<?= h($_POST[$contactField]??'') ?>"></label>
            <button class="btn btn-primary btn-block">Send Sign-in Code</button>
        </form>
        <p class="muted small">New customer? Add products to your cart and verify at checkout.</p>
    </section>
</div>
