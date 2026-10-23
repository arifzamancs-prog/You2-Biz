<?php
$user_id = (int)$_SESSION['user_id'];
$last_code_stmt = mysqli_prepare($conn, 'SELECT supplier_code FROM suppliers WHERE user_id=? AND supplier_code IS NOT NULL AND supplier_code<>\'\' ORDER BY id DESC LIMIT 1');
mysqli_stmt_bind_param($last_code_stmt, 'i', $user_id);
mysqli_stmt_execute($last_code_stmt);
$last_supplier_code = (string)(mysqli_fetch_assoc(mysqli_stmt_get_result($last_code_stmt))['supplier_code'] ?? '');
mysqli_stmt_close($last_code_stmt);
$recent_suppliers = [];
$recent_stmt = mysqli_prepare($conn, 'SELECT supplier_code, supplier_name, phone, email FROM suppliers WHERE user_id=? ORDER BY id DESC LIMIT 5');
mysqli_stmt_bind_param($recent_stmt, 'i', $user_id);
mysqli_stmt_execute($recent_stmt);
$recent_result = mysqli_stmt_get_result($recent_stmt);
while ($recent_supplier = mysqli_fetch_assoc($recent_result)) {
    $recent_suppliers[] = $recent_supplier;
}
mysqli_stmt_close($recent_stmt);
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">
    <div class="card-header"><h3 class="card-title">Add Supplier</h3></div>
    <div class="card-body">
        <div id="supplier-save-message" aria-live="polite"></div>
        <?php if(isset($_SESSION['error'])){ ?>
            <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php } ?>
        <form method="post" action="save.php" id="supplier-create-form">
            <div class="form-group">
                <label for="supplier_code">Supplier ID</label>
                <input type="text" name="supplier_code" id="supplier_code" class="form-control">
                <small class="form-text text-muted">Last created Supplier ID: <strong id="last-supplier-id"><?= $last_supplier_code !== '' ? htmlspecialchars($last_supplier_code) : 'No previous ID' ?></strong></small>
            </div>
            <div class="form-group"><label for="supplier_name">Supplier Name</label><input type="text" name="supplier_name" id="supplier_name" class="form-control" minlength="2" pattern=".*[A-Za-z].*" required></div>
            <div class="form-group"><label for="phone">Phone</label><input type="text" name="phone" id="phone" class="form-control" inputmode="numeric"></div>
            <div class="form-group"><label for="email">Email</label><input type="email" name="email" id="email" class="form-control"></div>
            <div class="form-group"><label for="address">Address</label><textarea name="address" id="address" class="form-control"></textarea></div>
            <button type="submit" class="btn btn-primary">Save Supplier</button>
        </form>

        <hr class="my-4">
        <h5 class="mb-3">Last 5 Suppliers</h5>
        <div class="table-responsive">
            <table class="table table-bordered table-sm mb-0">
                <thead><tr><th>Supplier ID</th><th>Supplier Name</th><th>Phone</th><th>Email</th></tr></thead>
                <tbody id="recent-suppliers-list">
                    <?php if (!$recent_suppliers) { ?>
                        <tr class="recent-suppliers-empty"><td colspan="4" class="text-center text-muted">No suppliers found.</td></tr>
                    <?php } ?>
                    <?php foreach ($recent_suppliers as $recent_supplier) { ?>
                        <tr>
                            <td><?= htmlspecialchars($recent_supplier['supplier_code'] ?: '-'); ?></td>
                            <td><?= htmlspecialchars($recent_supplier['supplier_name']); ?></td>
                            <td><?= htmlspecialchars($recent_supplier['phone'] ?: '-'); ?></td>
                            <td><?= htmlspecialchars($recent_supplier['email'] ?: '-'); ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function(){
    const form = document.getElementById('supplier-create-form');
    const message = document.getElementById('supplier-save-message');
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
            message.firstChild.textContent = result.message || 'Unable to save supplier.';
            if (result.success) {
                const lastSupplierId = document.getElementById('last-supplier-id');
                if (lastSupplierId && result.supplier_code) lastSupplierId.textContent = result.supplier_code;
                if (result.supplier) {
                    const list = document.getElementById('recent-suppliers-list');
                    if (list) {
                        const empty = list.querySelector('.recent-suppliers-empty');
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
                document.getElementById('supplier_code').focus();
            }
        } catch (error) {
            message.innerHTML = '<div class="alert alert-danger">Unable to save supplier. Please try again.</div>';
        } finally {
            submit.disabled = false;
            submit.innerHTML = original;
        }
    });
})();
</script>

<?php require_once '../includes/footer.php'; ?>
