<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/branch_helper.php';

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
    $enabled = isset($_POST['brand_feature_enabled']) ? 1 : 0;
    $stmt = mysqli_prepare($conn, 'INSERT INTO company_branch_brand_settings (user_id, is_enabled) VALUES (?, ?) ON DUPLICATE KEY UPDATE is_enabled=VALUES(is_enabled)');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ii', $user_id, $enabled);
        mysqli_stmt_execute($stmt);
    }
    branch_manage_redirect($enabled ? 'Brand option is active.' : 'Brand option is inactive.');
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
    $address = trim((string)($_POST['address'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $brand_id = (int)($_POST['brand_id'] ?? 0);

    if ($branch_name === '') {
        branch_manage_redirect('Branch name is required.', 'danger');
    }

    if (branch_name_is_reserved($branch_name)) {
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
        $head_office_stmt = mysqli_prepare($conn, 'SELECT is_head_office, branch_name, brand_id FROM branches WHERE id=? AND user_id=? LIMIT 1');
        mysqli_stmt_bind_param($head_office_stmt, 'ii', $branch_id, $user_id);
        mysqli_stmt_execute($head_office_stmt);
        $branch_record = mysqli_fetch_assoc(mysqli_stmt_get_result($head_office_stmt));
        if (!$branch_record) {
            branch_manage_redirect('Branch not found.', 'danger');
        }
        $stmt = mysqli_prepare(
            $conn,
            'UPDATE branches SET branch_name=?, address=?, phone=?, brand_id=? WHERE id=? AND user_id=?'
        );
        $brand_value = branch_is_fixed_location($branch_record) ? null : ($brands_enabled ? ($brand_id > 0 ? $brand_id : null) : ($branch_record['brand_id'] !== null ? (int)$branch_record['brand_id'] : null));
        mysqli_stmt_bind_param($stmt, 'sssiii', $branch_name, $address, $phone, $brand_value, $branch_id, $user_id);
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
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO branches (user_id, brand_id, branch_name, address, phone, status)
             VALUES (?, ?, ?, ?, ?, 'active')"
        );
        $brand_value = $brand_id > 0 ? $brand_id : null;
        mysqli_stmt_bind_param($stmt, 'iisss', $user_id, $brand_value, $branch_name, $address, $phone);
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

    $delete = mysqli_prepare($conn, 'DELETE FROM branches WHERE id=? AND user_id=?');
    mysqli_stmt_bind_param($delete, 'ii', $branch_id, $user_id);
    mysqli_stmt_execute($delete);
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
    "SELECT b.*, bb.brand_name, bb.logo_path FROM branches b LEFT JOIN branch_brands bb ON bb.id=b.brand_id AND bb.user_id=b.user_id WHERE b.user_id=" . $user_id . " ORDER BY b.is_head_office DESC, b.branch_name ASC"
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
            <button type="submit" class="btn btn-sm btn-primary">Update</button>
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
        <div class="card card-primary card-outline">
            <div class="card-header">
                <h3 class="card-title"><?= $edit_branch ? 'Edit Branch' : 'Add New Branch'; ?></h3>
            </div>
            <form method="post">
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
                        <input type="text" class="form-control" id="branch_name" name="branch_name" maxlength="150" required value="<?= htmlspecialchars($edit_branch['branch_name'] ?? ''); ?>" placeholder="e.g. Gulshan Branch">
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
                    <table class="table table-bordered table-hover mb-0">
                        <thead><tr><?php if ($brands_enabled) { ?><th>Brand</th><?php } ?><th>Branch</th><th>Address</th><th>Phone</th><th class="text-center" style="width: 145px;">Action</th></tr></thead>
                        <tbody>
                        <?php while ($row = mysqli_fetch_assoc($branches)) { ?>
                            <tr>
                                <?php if ($brands_enabled) { ?><td><?php if (branch_is_fixed_location($row)) { ?><strong>All Brand</strong><?php } else { ?><?php if (!empty($row['logo_path'])) { ?><img src="../<?= htmlspecialchars($row['logo_path']); ?>" alt="" style="width:28px;height:28px;object-fit:contain" class="mr-1"><?php } ?><strong><?= htmlspecialchars($row['brand_name'] ?: '-'); ?></strong><?php } ?></td><?php } ?>
                                <td><strong><?= htmlspecialchars($row['branch_name']); ?></strong><?php if ((int)$row['is_head_office'] === 1) { ?><span class="badge badge-primary ml-2">Default</span><?php } ?></td>
                                <td><?= htmlspecialchars($row['address'] ?: '-'); ?></td>
                                <td><?= htmlspecialchars($row['phone'] ?: '-'); ?></td>
                                <td class="text-center">
                                    <a href="branch_manage.php?edit=<?= (int)$row['id']; ?>" class="btn btn-sm btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                                    <?php if (!branch_is_fixed_location($row)) { ?><a href="branch_manage.php?delete=<?= (int)$row['id']; ?>" class="btn btn-sm btn-danger ml-1" title="Delete" onclick="return confirm('Delete this branch?');"><i class="fas fa-trash"></i></a><?php } ?>
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

<?php require_once '../includes/footer.php'; ?>
