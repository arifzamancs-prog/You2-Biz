<?php
// Warranty: find the sale invoice, pick the product and receive it for warranty.
require_once __DIR__ . '/_sw_init.php';

$q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$error = '';
$form = $_POST;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $id = sw_warranty_receive($conn, $user_id, $_POST);
        header('Location: warranty_print.php?id=' . $id . '&auto=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$invoices = $q !== '' ? sw_warranty_search($conn, $user_id, $q) : [];
$picked = (int)($form['invoice_item_id'] ?? $_GET['item'] ?? 0);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<section class="content pt-3"><div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <h4 class="mb-2 mb-sm-0"><i class="fas fa-shield-alt mr-2 text-primary"></i>Warranty Receive</h4>
        <a href="warranty_claims.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-list mr-1"></i>Warranty Claims</a>
    </div>
    <?= sw_flash_html(); ?>
    <?php if ($error !== '') { ?><div class="alert alert-danger"><?= sw_h($error); ?></div><?php } ?>
    <div class="card card-outline card-primary">
        <div class="card-body">
            <form method="get" class="form-row align-items-end">
                <div class="col-md-6 mb-2"><label class="mb-1">Find the sale</label><input name="q" class="form-control" value="<?= sw_h($q); ?>" placeholder="Invoice no, customer phone or serial / IMEI" autofocus required></div>
                <div class="col-md-2 mb-2"><button class="btn btn-primary btn-block"><i class="fas fa-search mr-1"></i>Search</button></div>
            </form>
        </div>
    </div>
    <?php if ($q !== '' && !$invoices) { ?><div class="alert alert-light border">No posted invoice found for "<?= sw_h($q); ?>".</div><?php } ?>
    <?php if ($invoices) { ?>
    <form method="post" id="warranty-form" autocomplete="off">
        <?= sw_csrf_field(); ?>
        <input type="hidden" name="q" value="<?= sw_h($q); ?>">
        <input type="hidden" name="request_key" value="<?= sw_h($form['request_key'] ?? sw_request_key()); ?>">
        <div class="card">
            <div class="card-header"><h3 class="card-title">1. Select the product</h3></div>
            <div class="card-body table-responsive p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="thead-light"><tr><th style="width:36px"></th><th>Invoice</th><th>Customer</th><th>Product</th><th class="text-center">Qty</th><th>Warranty</th></tr></thead>
                    <tbody>
                    <?php foreach ($invoices as $invoice) { foreach ($invoice['items'] as $item) {
                        $months = (int)$item['warranty_months'];
                        $full = (int)$item['open_claims'] >= (int)$item['quantity']; ?>
                        <tr class="<?= $full ? 'text-muted' : ''; ?>">
                            <td><input type="radio" name="invoice_item_id" value="<?= (int)$item['id']; ?>" data-walkin="<?= (int)$invoice['customer_id'] === 0 ? 1 : 0; ?>" data-ok="<?= (int)$item['in_warranty']; ?>" <?= $picked === (int)$item['id'] ? 'checked' : ''; ?> <?= $full ? 'disabled' : ''; ?> required></td>
                            <td class="text-nowrap"><strong><?= sw_h($invoice['invoice_no']); ?></strong><div class="small text-muted"><?= sw_date($invoice['invoice_date']); ?></div></td>
                            <td><?= sw_h($invoice['customer_name']); ?><div class="small text-muted"><?= sw_h($invoice['phone'] ?? ''); ?></div></td>
                            <td><?= sw_h($item['product_name'] ?? 'Product'); ?><?= $item['variant_name'] !== '' ? ' <span class="text-muted">(' . sw_h($item['variant_name']) . ')</span>' : ''; ?><?= trim((string)($item['serial_no'] ?? '')) !== '' ? '<div class="small text-muted">SN: ' . sw_h($item['serial_no']) . '</div>' : ''; ?></td>
                            <td class="text-center"><?= (int)$item['quantity']; ?></td>
                            <td>
                                <?php if ($months <= 0) { ?><span class="badge badge-secondary">No warranty set</span>
                                <?php } elseif ($item['in_warranty']) { ?><span class="badge badge-success">In warranty</span> <span class="small">until <?= sw_date($item['warranty_until']); ?> (<?= (int)$item['days_left']; ?> days left)</span>
                                <?php } else { ?><span class="badge badge-danger">Expired</span> <span class="small">on <?= sw_date($item['warranty_until']); ?></span><?php } ?>
                                <?= $full ? '<div class="small">Already under a warranty claim</div>' : ((int)$item['claims'] > 0 ? '<div class="small text-muted">' . (int)$item['claims'] . ' claim(s) before</div>' : ''); ?>
                            </td>
                        </tr>
                    <?php } } ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card card-outline card-warning">
            <div class="card-header"><h3 class="card-title">2. Receive details</h3></div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group col-md-3"><label>Received at</label><input type="datetime-local" name="received_at" class="form-control" value="<?= sw_h($form['received_at'] ?? date('Y-m-d\TH:i')); ?>"></div>
                    <div class="form-group col-md-3"><label>Expected delivery</label><input type="date" name="expected_date" class="form-control" value="<?= sw_h($form['expected_date'] ?? ''); ?>" min="<?= date('Y-m-d'); ?>"></div>
                    <div class="form-group col-md-3"><label>Serial / IMEI</label><input name="serial_no" class="form-control" value="<?= sw_h($form['serial_no'] ?? ''); ?>" maxlength="100"></div>
                    <div class="form-group col-md-3 sw-walkin-phone"><label>Customer phone</label><input name="customer_phone" class="form-control" value="<?= sw_h($form['customer_phone'] ?? ''); ?>" maxlength="30" placeholder="Walk-in invoice"></div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6"><label>Problem <span class="text-danger">*</span></label><textarea name="problem" class="form-control" rows="2" required><?= sw_h($form['problem'] ?? ''); ?></textarea></div>
                    <div class="form-group col-md-6"><label>Received with</label><input name="accessories" class="form-control mb-2" value="<?= sw_h($form['accessories'] ?? ''); ?>" maxlength="255" placeholder="Box, charger, cable…">
                        <input name="note" class="form-control" value="<?= sw_h($form['note'] ?? ''); ?>" maxlength="255" placeholder="Note (condition, scratches…)"></div>
                </div>
                <div class="custom-control custom-checkbox sw-expired" style="display:none"><input type="checkbox" class="custom-control-input" id="allow_expired" name="allow_expired" value="1" <?= !empty($form['allow_expired']) ? 'checked' : ''; ?>><label class="custom-control-label text-danger" for="allow_expired">This product is out of warranty (or has none). Receive anyway.</label></div>
            </div>
            <div class="card-footer text-right"><button class="btn btn-primary"><i class="fas fa-save mr-1"></i>Receive &amp; Print Receipt</button></div>
        </div>
    </form>
    <?php } ?>
</div></section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('warranty-form');
    if (!form) return;
    const sync = function () {
        const picked = form.querySelector('input[name="invoice_item_id"]:checked');
        form.querySelector('.sw-expired').style.display = picked && picked.dataset.ok !== '1' ? '' : 'none';
        form.querySelector('.sw-walkin-phone').style.display = picked && picked.dataset.walkin === '1' ? '' : 'none';
    };
    form.querySelectorAll('input[name="invoice_item_id"]').forEach(function (radio) { radio.addEventListener('change', sync); });
    sync();
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
