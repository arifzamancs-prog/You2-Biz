<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/branch_helper.php';

require_admin_user();

$user_id = (int)$_SESSION['user_id'];
ensure_head_office_branch($conn, $user_id);

function branch_manage_redirect($message, $type = 'success')
{
    $_SESSION['branch_manage_message'] = $message;
    $_SESSION['branch_manage_message_type'] = $type;
    header('Location: branch_manage.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $branch_id = (int)($_POST['branch_id'] ?? 0);
    $branch_name = trim((string)($_POST['branch_name'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));

    if ($branch_name === '') {
        branch_manage_redirect('Branch name is required.', 'danger');
    }

    if ($phone === '') {
        branch_manage_redirect('Phone number is required.', 'danger');
    }

    if ($branch_id > 0) {
        $head_office_stmt = mysqli_prepare($conn, 'SELECT is_head_office FROM branches WHERE id=? AND user_id=? LIMIT 1');
        mysqli_stmt_bind_param($head_office_stmt, 'ii', $branch_id, $user_id);
        mysqli_stmt_execute($head_office_stmt);
        $branch_record = mysqli_fetch_assoc(mysqli_stmt_get_result($head_office_stmt));
        if (!$branch_record) {
            branch_manage_redirect('Branch not found.', 'danger');
        }
        $stmt = mysqli_prepare(
            $conn,
            'UPDATE branches SET branch_name=?, address=?, phone=? WHERE id=? AND user_id=?'
        );
        mysqli_stmt_bind_param($stmt, 'sssii', $branch_name, $address, $phone, $branch_id, $user_id);
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
            "INSERT INTO branches (user_id, branch_name, address, phone, status)
             VALUES (?, ?, ?, ?, 'active')"
        );
        mysqli_stmt_bind_param($stmt, 'isss', $user_id, $branch_name, $address, $phone);
        if (mysqli_stmt_execute($stmt)) {
            branch_manage_redirect('New branch created successfully.');
        }
    }

    branch_manage_redirect('A branch with this name already exists.', 'danger');
}

if (isset($_GET['delete'])) {
    $branch_id = (int)$_GET['delete'];
    $head_stmt = mysqli_prepare($conn, 'SELECT is_head_office FROM branches WHERE id=? AND user_id=? LIMIT 1');
    mysqli_stmt_bind_param($head_stmt, 'ii', $branch_id, $user_id);
    mysqli_stmt_execute($head_stmt);
    $branch = mysqli_fetch_assoc(mysqli_stmt_get_result($head_stmt));

    if (!$branch) {
        branch_manage_redirect('Branch not found.', 'danger');
    }

    if ((int)$branch['is_head_office'] === 1) {
        branch_manage_redirect('Head Office cannot be deleted.', 'danger');
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

$branches = mysqli_query(
    $conn,
    "SELECT * FROM branches WHERE user_id=" . $user_id . " ORDER BY is_head_office DESC, branch_name ASC"
);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="row">
    <div class="col-lg-4">
        <div class="card card-primary card-outline">
            <div class="card-header">
                <h3 class="card-title"><?= $edit_branch ? 'Edit Branch' : 'Add New Branch'; ?></h3>
            </div>
            <form method="post">
                <div class="card-body">
                    <input type="hidden" name="branch_id" value="<?= (int)($edit_branch['id'] ?? 0); ?>">
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
                        <thead><tr><th>Branch</th><th>Address</th><th>Phone</th><th class="text-center" style="width: 145px;">Action</th></tr></thead>
                        <tbody>
                        <?php while ($row = mysqli_fetch_assoc($branches)) { ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($row['branch_name']); ?></strong><?php if ((int)$row['is_head_office'] === 1) { ?><span class="badge badge-primary ml-2">Default</span><?php } ?></td>
                                <td><?= htmlspecialchars($row['address'] ?: '-'); ?></td>
                                <td><?= htmlspecialchars($row['phone'] ?: '-'); ?></td>
                                <td class="text-center">
                                    <a href="branch_manage.php?edit=<?= (int)$row['id']; ?>" class="btn btn-sm btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                                    <?php if (!(int)$row['is_head_office']) { ?><a href="branch_manage.php?delete=<?= (int)$row['id']; ?>" class="btn btn-sm btn-danger ml-1" title="Delete" onclick="return confirm('Delete this branch?');"><i class="fas fa-trash"></i></a><?php } ?>
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
