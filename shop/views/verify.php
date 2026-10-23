<div class="container narrow">
    <section class="panel auth-panel">
        <h1>Verify your <?= $verification_method==='sms'?'phone':'email' ?></h1>
        <?php if($auth_error!==''){ ?><div class="alert alert-danger" role="alert"><?= h($auth_error) ?></div><?php } ?>
        <p>We sent a 6-digit code to <strong><?= h($_SESSION['eshop_otp_pending'][$cid]['recipient']??'your contact') ?></strong>. It expires in 10 minutes.</p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="auth_verify">
            <label>Verification code<input class="otp-input" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus autocomplete="one-time-code"></label>
            <button class="btn btn-primary btn-block">Verify and Continue</button>
        </form>
        <a class="summary-link" href="<?= h(shop_url($cart?'checkout':'signin')) ?>">Change details</a>
    </section>
</div>
