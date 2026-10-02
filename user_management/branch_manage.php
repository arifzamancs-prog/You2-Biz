<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/branch_helper.php';
require_once '../includes/branch_delete_guard.php';

require_admin_user();

$user_id = (int)$_SESSION['user_id'];
require_company_multi_branch($conn, $user_id);
ensure_head_office_branch($conn, $user_id);
remove_empty_reserved_warehouse_branches($conn, $user_id);
ensure_branch_brand_tables($conn);

function branch_manage_redirect($message, $type = 'success')
{
    $_SESSION['branch_manage_message'] = $message;
    $_SESSION['branch_manage_message_type'] = $type;
    header('Location: branch_manage.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'brand_settings') {
    branch_manage_redirect('Brand settings updates are currently disabled.', 'warning');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_brand') {
    $brand_name = trim((string)($_POST['brand_name'] ?? ''));
    if ($brand_name === '') branch_manage_redirect('Brand name is required.', 'danger');
    $logo_path = '';
    if (!empty($_FILES['brand_logo']['name']) && (int)$_FILES['brand_logo']['error'] === UPLOAD_ERR_OK) {
        $image_info = @getimagesize($_FILES['brand_logo']['tmp_name']);
        $extension = strtolower(pathinfo((string)$_FILES['brand_logo']['name'], PATHINFO_EXTENSION));
        if (!$image_info || !in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) || (int)$_FILES['brand_logo']['size'] > 2 * 1024 * 1024) {
            branch_manage_redirect('Upload a valid image logo (JPG, PNG, GIF, or WebP; maximum 2 MB).', 'danger');
        }
        $directory = '../uploads/brands/' . $user_id;
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) branch_manage_redirect('Could not create the logo upload directory.', 'danger');
        $filename = 'brand_' . bin2hex(random_bytes(8)) . '.' . $extension;
        if (!move_uploaded_file($_FILES['brand_logo']['tmp_name'], $directory . '/' . $filename)) branch_manage_redirect('Brand logo upload failed.', 'danger');
        $logo_path = 'uploads/brands/' . $user_id . '/' . $filename;
    }
    $stmt = mysqli_prepare($conn, "INSERT INTO branch_brands (user_id, brand_name, logo_path, status) VALUES (?, ?, ?, 'active')");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'iss', $user_id, $brand_name, $logo_path);
        if (mysqli_stmt_execute($stmt)) branch_manage_redirect('New brand added successfully.');
    }
    branch_manage_redirect('A brand with this name already exists.', 'danger');
}

$brands_enabled = branch_brands_enabled($conn, $user_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? 'branch') === 'branch') {
    $branch_id = (int)($_POST['branch_id'] ?? 0);
    $branch_name = trim((string)($_POST['branch_name'] ?? ''));
    $branch_code = strtoupper(trim((string)($_POST['branch_code'] ?? '')));
    $address = trim((string)($_POST['address'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $brand_id = (int)($_POST['brand_id'] ?? 0);

    if ($branch_name === '') {
        branch_manage_redirect('Branch name is required.', 'danger');
    }
    if ($branch_code === '' || !preg_match('/^[A-Z0-9_-]{2,50}$/', $branch_code)) {
        branch_manage_redirect('Branch code is required and may contain only letters, numbers, hyphens, or underscores.', 'danger');
    }

    if ($branch_id === 0 && branch_name_is_reserved($branch_name)) {
        branch_manage_redirect('Main Warehouse is managed automatically at Head Office and cannot be created as a branch.', 'danger');
    }

    if ($phone === '') {
        branch_manage_redirect('Phone number is required.', 'danger');
    }

    if ($brands_enabled && $branch_id === 0 && $brand_id <= 0) {
        branch_manage_redirect('Select a brand before creating a branch.', 'danger');
    }
    if ($brand_id > 0) {
        $brand_check = mysqli_prepare($conn, "SELECT id FROM branch_brands WHERE id=? AND user_id=? AND status='active' LIMIT 1");
        mysqli_stmt_bind_param($brand_check, 'ii', $brand_id, $user_id);
        mysqli_stmt_execute($brand_check);
        if (!mysqli_fetch_assoc(mysqli_stmt_get_result($brand_check))) branch_manage_redirect('Select a valid active brand.', 'danger');
    }

    if ($branch_id > 0) {
        $head_office_stmt = mysqli_prepare($conn, 'SELECT is_head_office, branch_name, brand_id, branch_code FROM branches WHERE id=? AND user_id=? LIMIT 1');
        mysqli_stmt_bind_param($head_office_stmt, 'ii', $branch_id, $user_id);
        mysqli_stmt_execute($head_office_stmt);
        $branch_record = mysqli_fetch_assoc(mysqli_stmt_get_result($head_office_stmt));
        if (!$branch_record) {
            branch_manage_redirect('Branch not found.', 'danger');
        }
        // Main Warehouse is a system location. Its inventory lookup relies on
        // the fixed name, but its operational details and code remain editable.
        if (branch_name_is_reserved((string)$branch_record['branch_name'])) $branch_name = (string)$branch_record['branch_name'];
        $code_check = mysqli_prepare($conn, 'SELECT id FROM branches WHERE user_id=? AND branch_code=? AND id<>? LIMIT 1');
        mysqli_stmt_bind_param($code_check, 'isi', $user_id, $branch_code, $branch_id); mysqli_stmt_execute($code_check);
        if (mysqli_fetch_assoc(mysqli_stmt_get_result($code_check))) branch_manage_redirect('This branch code is already in use.', 'danger');
        $stmt = mysqli_prepare(
            $conn,
            'UPDATE branches SET branch_name=?, branch_code=?, address=?, phone=?, brand_id=? WHERE id=? AND user_id=?'
        );
        $brand_value = branch_is_fixed_location($branch_record) ? null : ($brands_enabled ? ($brand_id > 0 ? $brand_id : null) : ($branch_record['brand_id'] !== null ? (int)$branch_record['brand_id'] : null));
        mysqli_stmt_bind_param($stmt, 'ssssiii', $branch_name, $branch_code, $address, $phone, $brand_value, $branch_id, $user_id);
        if (mysqli_stmt_execute($stmt)) {
            if ((int)$branch_record['is_head_office'] === 1) {
                $profile_sync = mysqli_prepare($conn, 'UPDATE users SET address=?, phone=? WHERE id=?');
                if ($profile_sync) {
                    mysqli_stmt_bind_param($profile_sync, 'ssi', $address, $phone, $user_id);
                    mysqli_stmt_execute($profile_sync);
                }
            }
            branch_manage_redirect('Branch updated successfully.');
        }
    } else {
        $code_check = mysqli_prepare($conn, 'SELECT id FROM branches WHERE user_id=? AND branch_code=? LIMIT 1');
        mysqli_stmt_bind_param($code_check, 'is', $user_id, $branch_code); mysqli_stmt_execute($code_check);
        if (mysqli_fetch_assoc(mysqli_stmt_get_result($code_check))) branch_manage_redirect('This branch code is already in use.', 'danger');
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO branches (user_id, brand_id, branch_name, branch_code, address, phone, status)
             VALUES (?, ?, ?, ?, ?, ?, 'active')"
        );
        $brand_value = $brand_id > 0 ? $brand_id : null;
        mysqli_stmt_bind_param($stmt, 'iissss', $user_id, $brand_value, $branch_name, $branch_code, $address, $phone);
        if (mysqli_stmt_execute($stmt)) {
            branch_manage_redirect('New branch created successfully.');
        }
    }

    branch_manage_redirect('A branch with this name already exists.', 'danger');
}

if (isset($_GET['delete'])) {
    $branch_id = (int)$_GET['delete'];
    $head_stmt = mysqli_prepare($conn, 'SELECT is_head_office, branch_name FROM branches WHERE id=? AND user_id=? LIMIT 1');
    mysqli_stmt_bind_param($head_stmt, 'ii', $branch_id, $user_id);
    mysqli_stmt_execute($head_stmt);
    $branch = mysqli_fetch_assoc(mysqli_stmt_get_result($head_stmt));

    if (!$branch) {
        branch_manage_redirect('Branch not found.', 'danger');
    }

    if (branch_is_fixed_location($branch)) {
        branch_manage_redirect('Head Office and Main Warehouse cannot be deleted.', 'danger');
    }

    mysqli_begin_transaction($conn);
    mysqli_query($conn, 'SELECT id FROM branches WHERE id=' . $branch_id . ' AND user_id=' . $user_id . ' FOR UPDATE');
    mysqli_query($conn, 'SELECT id FROM wallets WHERE branch_id=' . $branch_id . ' FOR UPDATE');
    $block_reason = branch_delete_block_reason($conn, $branch_id);
    if ($block_reason !== '') {
        mysqli_rollback($conn);
        branch_manage_redirect($block_reason, 'danger');
    }

    try {
    $wallet_delete = mysqli_prepare($conn, 'DELETE FROM wallets WHERE branch_id=? AND user_id=? AND is_system=1 AND balance=0');
    mysqli_stmt_bind_param($wallet_delete, 'ii', $branch_id, $user_id);
    if (!mysqli_stmt_execute($wallet_delete)) throw new RuntimeException('Cannot remove unused default wallet.');
    $delete = mysqli_prepare($conn, 'DELETE FROM branches WHERE id=? AND user_id=?');
    mysqli_stmt_bind_param($delete, 'ii', $branch_id, $user_id);
    if (!mysqli_stmt_execute($delete) || mysqli_stmt_affected_rows($delete) !== 1) throw new RuntimeException('Cannot delete branch.');
    mysqli_commit($conn);
    } catch (Throwable $error) {
        mysqli_rollback($conn);
        error_log('Branch deletion: ' . $error->getMessage());
        branch_manage_redirect('Branch could not be deleted. Please try again.', 'danger');
    }
    branch_manage_redirect('Branch deleted successfully.');
}

$message = $_SESSION['branch_manage_message'] ?? '';
$message_type = $_SESSION['branch_manage_message_type'] ?? 'success';
unset($_SESSION['branch_manage_message'], $_SESSION['branch_manage_message_type']);

$edit_branch = null;
if (isset($_GET['edit'])) {
    $branch_id = (int)$_GET['edit'];
    $stmt = mysqli_prepare($conn, 'SELECT * FROM branches WHERE id=? AND user_id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $branch_id, $user_id);
    mysqli_stmt_execute($stmt);
    $edit_branch = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
}
$edit_branch_is_fixed = $edit_branch && branch_is_fixed_location($edit_branch);

$brands = mysqli_query($conn, "SELECT * FROM branch_brands WHERE user_id=" . $user_id . " ORDER BY brand_name ASC");
$branches = mysqli_query(
    $conn,
    "SELECT b.*, bb.brand_name, bb.logo_path FROM branches b LEFT JOIN branch_brands bb ON bb.id=b.brand_id AND bb.user_id=b.user_id WHERE b.user_id=" . $user_id . " ORDER BY CASE WHEN b.is_head_office=1 THEN 0 WHEN b.branch_name='Main Warehouse' THEN 1 ELSE 2 END ASC, b.id DESC"
);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card card-outline card-info">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-copyright mr-2 text-info"></i>Brand Settings</h3></div>
    <div class="card-body">
        <form method="post" class="mb-3">
            <input type="hidden" name="action" value="brand_settings">
            <div class="custom-control custom-switch d-inline-block mr-2">
                <input type="checkbox" class="custom-control-input" id="brand_feature_enabled" name="brand_feature_enabled" value="1" <?= $brands_enabled ? 'checked' : ''; ?>>
                <label class="custom-control-label" for="brand_feature_enabled">Enable brands for branches</label>
            </div>
            <button type="submit" class="btn btn-sm btn-primary" disabled title="Brand settings updates are currently disabled">Update</button>
        </form>
        <?php if ($brands_enabled) { ?>
            <form method="post" enctype="multipart/form-data" class="row align-items-end border-top pt-3">
                <input type="hidden" name="action" value="add_brand">
                <div class="col-md-4 form-group mb-md-0"><label for="brand_name">Add New Brand</label><input type="text" id="brand_name" name="brand_name" class="form-control" maxlength="150" required placeholder="Brand name"></div>
                <div class="col-md-4 form-group mb-md-0"><label for="brand_logo">Logo <small class="text-muted">(optional)</small></label><input type="file" id="brand_logo" name="brand_logo" class="form-control-file" accept="image/png,image/jpeg,image/gif,image/webp"></div>
                <div class="col-md-4"><button type="submit" class="btn btn-success"><i class="fas fa-plus mr-1"></i>Add Brand</button></div>
            </form>
            <div class="table-responsive mt-3">
                <table class="table table-sm table-bordered mb-0">
                    <thead><tr><th style="width:70px;">Logo</th><th>Brand List</th></tr></thead>
                    <tbody>
                    <?php mysqli_data_seek($brands, 0); $has_brand = false; while ($brand = mysqli_fetch_assoc($brands)) { $has_brand = true; ?>
                        <tr><td class="text-center"><?php if (!empty($brand['logo_path'])) { ?><img src="../<?= htmlspecialchars($brand['logo_path']); ?>" alt="<?= htmlspecialchars($brand['brand_name']); ?>" style="width:32px;height:32px;object-fit:contain"><?php } else { ?><span class="text-muted">-</span><?php } ?></td><td><?= htmlspecialchars($brand['brand_name']); ?></td></tr>
                    <?php } ?>
                    <?php if (!$has_brand) { ?><tr><td colspan="2" class="text-muted text-center">No brand added yet.</td></tr><?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
    </div>
</div>

<div class="row">
    <div class="col-lg-4">
        <div class="card card-primary card-outline" id="branch-editor">
            <div class="card-header">
                <h3 class="card-title"><?= $edit_branch ? 'Edit Branch' : 'Add New Branch'; ?></h3>
            </div>
            <form method="post" action="branch_manage.php" id="branch-form">
                <div class="card-body">
                    <input type="hidden" name="action" value="branch">
                    <input type="hidden" name="branch_id" value="<?= (int)($edit_branch['id'] ?? 0); ?>">
                    <?php if ($brands_enabled && $edit_branch_is_fixed) { ?>
                    <div class="form-group">
                        <label for="fixed_brand">Brand Name</label>
                        <input type="text" class="form-control" id="fixed_brand" value="All Brand" readonly>
                    </div>
                    <?php } elseif ($brands_enabled) { ?>
                    <div class="form-group">
                        <label for="brand_id">Brand Name</label>
                        <select class="form-control" id="brand_id" name="brand_id" <?= !$edit_branch ? 'required' : ''; ?>>
                            <option value="">Select Brand</option>
                            <?php mysqli_data_seek($brands, 0); while ($brand = mysqli_fetch_assoc($brands)) { ?>
                                <option value="<?= (int)$brand['id']; ?>" <?= (int)($edit_branch['brand_id'] ?? 0) === (int)$brand['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($brand['brand_name']); ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <?php } ?>
                    <div class="form-group">
                        <label for="branch_name">Branch Name</label>
                        <input type="text" class="form-control" id="branch_name" name="branch_name" maxlength="150" required value="<?= htmlspecialchars($edit_branch['branch_name'] ?? ''); ?>" placeholder="e.g. Gulshan Branch" <?= $edit_branch && branch_name_is_reserved((string)$edit_branch['branch_name']) ? 'readonly' : ''; ?>>
                        <?php if ($edit_branch && branch_name_is_reserved((string)$edit_branch['branch_name'])) { ?><small class="text-muted">Main Warehouse name is system-managed; its code, address and phone can be updated.</small><?php } ?>
                    </div>
                    <div class="form-group">
                        <label for="branch_code">Branch Code</label>
                        <input type="text" class="form-control text-uppercase" id="branch_code" name="branch_code" maxlength="50" pattern="[A-Za-z0-9_-]{2,50}" required value="<?= htmlspecialchars($edit_branch['branch_code'] ?? ''); ?>" placeholder="e.g. GLS-01">
                        <small class="text-muted">Must be unique within your company. Use letters, numbers, hyphens, or underscores.</small>
                    </div>
                    <div class="form-group">
                        <label for="address">Address <small class="text-muted">(Optional)</small></label>
                        <textarea class="form-control" id="address" name="address" rows="2" maxlength="255"><?= htmlspecialchars($edit_branch['address'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-group mb-0">
                        <label for="phone">Phone <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="phone" name="phone" maxlength="50" required value="<?= htmlspecialchars($edit_branch['phone'] ?? ''); ?>">
                    </div>
                </div>
                <div class="card-footer bg-white">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i><?= $edit_branch ? 'Update Branch' : 'Create Branch'; ?></button>
                    <?php if ($edit_branch) { ?><a href="branch_manage.php" class="btn btn-default">Cancel</a><?php } ?>
                </div>
            </form>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-code-branch mr-2 text-primary"></i>Branch Management</h3>
            </div>
            <div class="card-body">
                <?php if ($message) { ?><div class="alert alert-<?= htmlspecialchars($message_type); ?>"><?= htmlspecialchars($message); ?></div><?php } ?>
                <div class="table-responsive">
                    <table id="branch-management-table" class="table table-bordered table-hover mb-0">
                        <thead><tr><?php if ($brands_enabled) { ?><th>Brand</th><?php } ?><th>Branch</th><th>Branch Code</th><th>Address</th><th>Phone</th><th class="text-center" style="width: 145px;">Action</th></tr></thead>
                        <tbody>
                        <?php while ($row = mysqli_fetch_assoc($branches)) { ?>
                            <tr>
                                <?php if ($brands_enabled) { ?><td><?php if (branch_is_fixed_location($row)) { ?><strong>All Brand</strong><?php } else { ?><?php if (!empty($row['logo_path'])) { ?><img src="../<?= htmlspecialchars($row['logo_path']); ?>" alt="" style="width:28px;height:28px;object-fit:contain" class="mr-1"><?php } ?><strong><?= htmlspecialchars($row['brand_name'] ?: '-'); ?></strong><?php } ?></td><?php } ?>
                                <td><strong><?= htmlspecialchars($row['branch_name']); ?></strong><?php if ((int)$row['is_head_office'] === 1) { ?><span class="badge badge-primary ml-2">Default</span><?php } ?></td>
                                <td><code><?= htmlspecialchars($row['branch_code'] ?: '-'); ?></code></td>
                                <td><?= htmlspecialchars($row['address'] ?: '-'); ?></td>
                                <td><?= htmlspecialchars($row['phone'] ?: '-'); ?></td>
                                <td class="text-center">
                                    <a href="branch_manage.php?edit=<?= (int)$row['id']; ?>" class="btn btn-sm btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                                    <?php if (!branch_is_fixed_location($row)) { $block_reason = branch_delete_block_reason($conn, (int)$row['id']); ?>
                                        <?php if ($block_reason !== '') { ?><span title="<?= htmlspecialchars($block_reason); ?>"><button type="button" class="btn btn-sm btn-danger ml-1" disabled aria-label="Delete unavailable: linked records exist"><i class="fas fa-trash"></i></button></span>
                                        <?php } else { ?><a href="branch_manage.php?delete=<?= (int)$row['id']; ?>" class="btn btn-sm btn-danger ml-1" title="Delete" data-ajax-action-link="true" data-ajax-remove="true" data-confirm="Delete this branch?"><i class="fas fa-trash"></i></a><?php } ?>
                                    <?php } ?>
                                </td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$page_script = '<script src="../assets/js/ajax_page_actions.js?v=' . filemtime(__DIR__ . '/../assets/js/ajax_page_actions.js') . '"></script>';
$page_script .= <<<'HTML'
<script>
document.addEventListener('ajax-page-action-complete', function(event){
    var detail = event.detail;
    if (!detail || !detail.source || detail.source.id !== 'branch-form') return;
    var source = new DOMParser().parseFromString(detail.result.html || '', 'text/html');
    var updatedTable = source.querySelector('#branch-management-table');
    var updatedEditor = source.querySelector('#branch-editor');
    var table = document.getElementById('branch-management-table');
    var editor = document.getElementById('branch-editor');
    if (!updatedTable || !updatedEditor || !table || !editor) {
        window.showAjaxActionMessage('Branch saved, but the list could not be refreshed. Please reload the page.', false);
        return;
    }
    table.replaceWith(updatedTable.cloneNode(true));
    editor.replaceWith(updatedEditor.cloneNode(true));
    window.history.replaceState(null, '', 'branch_manage.php');
});
</script>
HTML;
require_once '../includes/footer.php';
?>
