<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/product_category_helper.php';
require_once '../includes/supplier_helper.php';
require_once '../includes/project_package_helper.php';

if (restaurant_catalog_enabled($conn, (int)$_SESSION['user_id'])) {
    ensure_restaurant_supplier_code_column($conn);
    require_once '../restaurant/suppliers_create.php';
    exit;
}

$is_fashion_retail = project_package_company_type($conn, (int)$_SESSION['user_id']) === 'Fashion house';
$last_supplier_code = '';
if ($is_fashion_retail) {
    ensure_supplier_code_column($conn);
    $last_code_stmt = mysqli_prepare($conn, 'SELECT supplier_code FROM suppliers WHERE user_id=? AND supplier_code IS NOT NULL AND supplier_code<>\'\' ORDER BY id DESC LIMIT 1');
    mysqli_stmt_bind_param($last_code_stmt, 'i', $_SESSION['user_id']);
    mysqli_stmt_execute($last_code_stmt);
    $last_supplier_code = (string)(mysqli_fetch_assoc(mysqli_stmt_get_result($last_code_stmt))['supplier_code'] ?? '');
    mysqli_stmt_close($last_code_stmt);
}

$recent_vendors = [];
if ($is_fashion_retail) {
    $recent_stmt = mysqli_prepare($conn, 'SELECT supplier_code, supplier_name, phone, email FROM suppliers WHERE user_id=?' . restaurant_catalog_scope_sql($conn) . ' ORDER BY id DESC LIMIT 5');
    mysqli_stmt_bind_param($recent_stmt, 'i', $_SESSION['user_id']);
    mysqli_stmt_execute($recent_stmt);
    $recent_result = mysqli_stmt_get_result($recent_stmt);
    while ($recent_vendor = mysqli_fetch_assoc($recent_result)) {
        $recent_vendors[] = $recent_vendor;
    }
    mysqli_stmt_close($recent_stmt);
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">

            Add <?= supplier_display_text('Supplier'); ?>

        </h3>

    </div>

<div class="card-body">

        <?php if(isset($_SESSION['error'])){ ?>

            <div class="alert alert-danger">
                <?= htmlspecialchars($_SESSION['error']); ?>
            </div>

            <?php unset($_SESSION['error']); ?>

        <?php } ?>

        <div id="vendor-save-message" aria-live="polite"></div>

        <form
            method="post"
            action="save.php"
            id="vendor-create-form">

            <?php if ($is_fashion_retail) { ?>
            <div class="form-group">

                <label for="supplier_code">Vendor ID <small class="text-muted">(Optional)</small></label>

                <input
                    type="text"
                    id="supplier_code"
                    name="supplier_code"
                    class="form-control">

                <small class="form-text text-muted">Last created Vendor ID: <strong id="last-vendor-id"><?= $last_supplier_code !== '' ? htmlspecialchars($last_supplier_code) : 'No previous ID'; ?></strong></small>

            </div>
            <?php } ?>

            <div class="form-group">

                <label>
                    <?= supplier_display_text('Supplier'); ?> Name
                </label>

                <input
                    type="text"
                    name="supplier_name"
                    class="form-control"
                    minlength="2"
                    pattern=".*[A-Za-z].*"
                    required>

            </div>

            <div class="form-group">

                <label>
                    Phone
                </label>

                <input
                    type="text"
                    name="phone"
                    class="form-control"
                    inputmode="numeric">

            </div>

            <div class="form-group">

                <label>
                    Email
                </label>

                <input
                    type="email"
                    name="email"
                    class="form-control">

            </div>

            <div class="form-group">

                <label>
                    Address
                </label>

                <textarea
                    name="address"
                    class="form-control"></textarea>

            </div>

            <button
                type="submit"
                class="btn btn-primary">

                Save <?= supplier_display_text('Supplier'); ?>

            </button>

        </form>

        <?php if ($is_fashion_retail) { ?>
        <hr class="my-4">
        <h5 class="mb-3">Last 5 Vendors</h5>
        <div class="table-responsive">
            <table class="table table-bordered table-sm mb-0">
                <thead>
                    <tr>
                        <th>Vendor ID</th>
                        <th>Vendor Name</th>
                        <th>Phone</th>
                        <th>Email</th>
                    </tr>
                </thead>
                <tbody id="recent-vendors-list">
                    <?php if (!$recent_vendors) { ?>
                        <tr class="recent-vendors-empty"><td colspan="4" class="text-center text-muted">No vendors found.</td></tr>
                    <?php } ?>
                    <?php foreach ($recent_vendors as $recent_vendor) { ?>
                        <tr>
                            <td><?= htmlspecialchars($recent_vendor['supplier_code'] ?: '-'); ?></td>
                            <td><?= htmlspecialchars($recent_vendor['supplier_name']); ?></td>
                            <td><?= htmlspecialchars($recent_vendor['phone'] ?: '-'); ?></td>
                            <td><?= htmlspecialchars($recent_vendor['email'] ?: '-'); ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php } ?>

    </div>

</div>

<?php
$page_script = supplier_display_text('
<script>
$(function(){
    $("input[name=\"supplier_name\"]").attr("title", "Supplier name must contain letters.");
    $("input[name=\"phone\"]").attr("title", "Phone must contain at least 11 digits.");
});
</script>
');
require_once '../includes/footer.php';
?>
<script>
(function(){
    const form = document.getElementById('vendor-create-form');
    const message = document.getElementById('vendor-save-message');
    if (!form || !message || !window.fetch || !window.FormData) return;

    form.addEventListener('submit', async function(event){
        event.preventDefault();
        const submit = form.querySelector('[type="submit"]');
        const original = submit.innerHTML;
        submit.disabled = true;
        submit.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        message.innerHTML = '';

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                body: new FormData(form),
                credentials: 'same-origin'
            });
            const result = await response.json();
            message.innerHTML = '<div class="alert alert-' + (result.success ? 'success' : 'danger') + '"></div>';
            message.firstChild.textContent = result.message || 'Unable to save vendor.';
            if (result.success) {
                const lastVendorId = document.getElementById('last-vendor-id');
                if (lastVendorId && result.supplier_code) lastVendorId.textContent = result.supplier_code;
                if (result.supplier) {
                    const list = document.getElementById('recent-vendors-list');
                    if (list) {
                        const empty = list.querySelector('.recent-vendors-empty');
                        if (empty) empty.remove();
                        const row = document.createElement('tr');
                        [result.supplier.code || '-', result.supplier.name || '-', result.supplier.phone || '-', result.supplier.email || '-'].forEach(function(value){
                            const cell = document.createElement('td');
                            cell.textContent = value;
                            row.appendChild(cell);
                        });
                        list.prepend(row);
                        while (list.rows.length > 5) list.deleteRow(-1);
                    }
                }
                form.reset();
                form.querySelector('[name="supplier_code"], [name="supplier_name"]').focus();
            }
        } catch (error) {
            message.innerHTML = '<div class="alert alert-danger">Unable to save vendor. Please try again.</div>';
        } finally {
            submit.disabled = false;
            submit.innerHTML = original;
        }
    });
})();
</script>
