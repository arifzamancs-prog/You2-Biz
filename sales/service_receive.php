<?php
// Service Center: receive a customer's product for service and print the receipt.
require_once __DIR__ . '/_sw_init.php';

$form = $_POST;
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = $_POST;
        if (($data['customer_mode'] ?? 'registered') === 'walkin') {
            $data['customer_id'] = 0;
        }
        $id = sw_service_receive($conn, $user_id, $branch_id, $data);
        header('Location: service_print.php?id=' . $id . '&auto=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$customers = mysqli_query($conn, "SELECT id, customer_name, phone FROM customers WHERE user_id={$user_id} AND status='active' ORDER BY customer_name");
$staff = mysqli_query($conn, "SELECT id, name FROM staff WHERE user_id={$user_id} AND status='active' ORDER BY name");
$mode = ($form['customer_mode'] ?? 'registered') === 'walkin' ? 'walkin' : 'registered';
$value = static fn($key, $default = '') => sw_h($form[$key] ?? $default);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<section class="content pt-3"><div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <h4 class="mb-2 mb-sm-0"><i class="fas fa-tools mr-2 text-primary"></i>Receive for Service</h4>
        <a href="service_jobs.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-list mr-1"></i>Service Jobs</a>
    </div>
    <?= sw_flash_html(); ?>
    <?php if ($error !== '') { ?><div class="alert alert-danger"><?= sw_h($error); ?></div><?php } ?>
    <form method="post" id="service-form" autocomplete="off">
        <?= sw_csrf_field(); ?>
        <input type="hidden" name="request_key" value="<?= sw_h($form['request_key'] ?? sw_request_key()); ?>">
        <div class="row">
            <div class="col-lg-6">
                <div class="card card-outline card-primary">
                    <div class="card-header"><h3 class="card-title"><i class="fas fa-user mr-1"></i>Customer</h3></div>
                    <div class="card-body">
                        <div class="btn-group btn-group-toggle mb-3" data-toggle="buttons">
                            <label class="btn btn-outline-primary btn-sm <?= $mode === 'registered' ? 'active' : ''; ?>"><input type="radio" name="customer_mode" value="registered" <?= $mode === 'registered' ? 'checked' : ''; ?>> Registered</label>
                            <label class="btn btn-outline-primary btn-sm <?= $mode === 'walkin' ? 'active' : ''; ?>"><input type="radio" name="customer_mode" value="walkin" <?= $mode === 'walkin' ? 'checked' : ''; ?>> Walk-in</label>
                        </div>
                        <div class="form-group sw-registered">
                            <label>Customer <span class="text-danger">*</span></label>
                            <select name="customer_id" id="sw_customer" class="form-control">
                                <option value="">Select customer</option>
                                <?php while ($customers && ($c = mysqli_fetch_assoc($customers))) { ?>
                                    <option value="<?= (int)$c['id']; ?>" <?= (int)($form['customer_id'] ?? 0) === (int)$c['id'] ? 'selected' : ''; ?>><?= sw_h($c['customer_name'] . ($c['phone'] ? ' (' . $c['phone'] . ')' : '')); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="form-row sw-walkin">
                            <div class="form-group col-md-6"><label>Name <span class="text-danger">*</span></label><input name="customer_name" class="form-control" value="<?= $value('customer_name'); ?>" maxlength="150"></div>
                            <div class="form-group col-md-6"><label>Phone <span class="text-danger">*</span></label><input name="customer_phone" class="form-control" value="<?= $value('customer_phone'); ?>" maxlength="30"></div>
                        </div>
                    </div>
                </div>
                <div class="card card-outline card-info">
                    <div class="card-header"><h3 class="card-title"><i class="fas fa-calendar-check mr-1"></i>Receive &amp; Delivery</h3></div>
                    <div class="card-body">
                        <div class="form-row">
                            <div class="form-group col-md-6"><label>Received at</label><input type="datetime-local" name="received_at" class="form-control" value="<?= $value('received_at', date('Y-m-d\TH:i')); ?>"></div>
                            <div class="form-group col-md-6"><label>Delivery date <span class="text-danger">*</span></label><input type="date" name="delivery_date" class="form-control" value="<?= $value('delivery_date'); ?>" min="<?= date('Y-m-d'); ?>" required></div>
                        </div>
                        <div class="form-group mb-0"><label>Technician <span class="text-muted small">(optional)</span></label>
                            <select name="staff_id" class="form-control"><option value="0">—</option>
                                <?php while ($staff && ($s = mysqli_fetch_assoc($staff))) { ?><option value="<?= (int)$s['id']; ?>" <?= (int)($form['staff_id'] ?? 0) === (int)$s['id'] ? 'selected' : ''; ?>><?= sw_h($s['name']); ?></option><?php } ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card card-outline card-warning">
                    <div class="card-header"><h3 class="card-title"><i class="fas fa-box-open mr-1"></i>Product &amp; Problem</h3></div>
                    <div class="card-body">
                        <div class="form-row">
                            <div class="form-group col-md-6"><label>Product <span class="text-danger">*</span></label><input name="item_name" class="form-control" value="<?= $value('item_name'); ?>" maxlength="150" placeholder="e.g. Laptop, Mobile, TV" required></div>
                            <div class="form-group col-md-6"><label>Brand / Model</label><input name="brand_model" class="form-control" value="<?= $value('brand_model'); ?>" maxlength="150"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6"><label>Serial / IMEI</label><input name="serial_no" class="form-control" value="<?= $value('serial_no'); ?>" maxlength="100"></div>
                            <div class="form-group col-md-6"><label>Received with</label><input name="accessories" class="form-control" value="<?= $value('accessories'); ?>" maxlength="255" placeholder="Charger, box, cover…"></div>
                        </div>
                        <div class="form-group"><label>Problem <span class="text-danger">*</span></label><textarea name="problem" class="form-control" rows="3" required><?= $value('problem'); ?></textarea></div>
                        <div class="form-group mb-0"><label>Note <span class="text-muted small">(condition, password, etc.)</span></label><textarea name="note" class="form-control" rows="2"><?= $value('note'); ?></textarea></div>
                    </div>
                </div>
                <div class="card card-outline card-success">
                    <div class="card-header"><h3 class="card-title"><i class="fas fa-money-bill-wave mr-1"></i>Charge</h3></div>
                    <div class="card-body">
                        <div class="form-row">
                            <div class="form-group col-md-4"><label>Estimated charge</label><input type="number" step="0.01" min="0" name="estimated_charge" class="form-control" value="<?= $value('estimated_charge', '0'); ?>"></div>
                            <div class="form-group col-md-4"><label>Advance</label><input type="number" step="0.01" min="0" name="advance_amount" id="sw_advance" class="form-control" value="<?= $value('advance_amount', '0'); ?>"></div>
                            <div class="form-group col-md-4"><label>Advance wallet</label><select name="advance_wallet_id" class="form-control"><?= sw_wallet_options($conn, $user_id, (int)($form['advance_wallet_id'] ?? 0)); ?></select></div>
                        </div>
                        <p class="text-muted small mb-0">The final service charge and any parts are billed on delivery; the advance is deducted from that bill.</p>
                    </div>
                </div>
                <div class="text-right mb-4"><button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Receive &amp; Print Receipt</button></div>
            </div>
        </div>
    </form>
</div></section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('service-form');
    const toggle = function () {
        const walkin = form.querySelector('input[name="customer_mode"]:checked').value === 'walkin';
        form.querySelectorAll('.sw-registered').forEach(function (el) { el.style.display = walkin ? 'none' : ''; });
        form.querySelectorAll('.sw-walkin').forEach(function (el) { el.style.display = walkin ? '' : 'none'; });
    };
    form.querySelectorAll('input[name="customer_mode"]').forEach(function (radio) { radio.addEventListener('change', toggle); });
    toggle();
    if (window.jQuery && jQuery.fn.select2) jQuery('#sw_customer').select2({theme: 'bootstrap4', width: '100%'});
    form.addEventListener('submit', function () { form.querySelector('button[type="submit"]').disabled = true; });
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
