<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/project_package_helper.php';

require_admin_user();
ensure_project_package_tables($conn);
$user_id = (int)$_SESSION['user_id'];
$id = (int)($_GET['id'] ?? 0);
$message = '';

$stmt = mysqli_prepare($conn, "SELECT * FROM packages WHERE id=? AND user_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'ii', $id, $user_id);
mysqli_stmt_execute($stmt);
$package = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if(!$package){ die('Package not found.'); }

$project_stmt = mysqli_prepare($conn, "SELECT id, project_name FROM projects WHERE user_id=? ORDER BY project_name");
mysqli_stmt_bind_param($project_stmt, 'i', $user_id);
mysqli_stmt_execute($project_stmt);
$projects = mysqli_stmt_get_result($project_stmt);

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $project_id = (int)($_POST['project_id'] ?? 0);
    $name = trim($_POST['package_name'] ?? '');
    $code = trim($_POST['package_code'] ?? '');
    $price = is_numeric($_POST['price'] ?? null) ? (float)$_POST['price'] : -1;
    $description = trim($_POST['description'] ?? '');
    if($description === '') $description = 'General';
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if($project_id <= 0 || $name === '' || $price < 0){
        $message = 'Project, Package Name and valid Price are required.';
    }else{
        $project_check = mysqli_prepare($conn, "SELECT id FROM projects WHERE id=? AND user_id=? LIMIT 1");
        mysqli_stmt_bind_param($project_check, 'ii', $project_id, $user_id);
        mysqli_stmt_execute($project_check);
        if(mysqli_num_rows(mysqli_stmt_get_result($project_check)) === 0){
            $message = 'Selected project was not found.';
        }else{
            $check = mysqli_prepare($conn, "SELECT id FROM packages WHERE user_id=? AND package_code=? AND id<>? LIMIT 1");
            mysqli_stmt_bind_param($check, 'isi', $user_id, $code, $id);
            mysqli_stmt_execute($check);
            if($code !== '' && mysqli_num_rows(mysqli_stmt_get_result($check)) > 0){
                $message = 'Package Code already exists.';
            }else{
                $update = mysqli_prepare($conn, "UPDATE packages SET project_id=?, package_name=?, package_code=?, price=?, description=?, status=? WHERE id=? AND user_id=?");
                mysqli_stmt_bind_param($update, 'issdssii', $project_id, $name, $code, $price, $description, $status, $id, $user_id);
                if(mysqli_stmt_execute($update)){ header('Location: packages.php'); exit; }
                $message = 'Package could not be updated.';
            }
        }
    }
    $package = array_merge($package, ['project_id' => $project_id, 'package_name' => $name, 'package_code' => $code, 'price' => $price, 'description' => $description, 'status' => $status]);
}

require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title">Edit Package</h3></div><div class="card-body">
<?php if($message){ ?><div class="alert alert-danger"><?= htmlspecialchars($message); ?></div><?php } ?>
<form method="post"><div class="form-group"><label>Project</label><select name="project_id" class="form-control" required><?php while($project = mysqli_fetch_assoc($projects)){ ?><option value="<?= (int)$project['id']; ?>" <?= (int)$package['project_id'] === (int)$project['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($project['project_name']); ?></option><?php } ?></select></div><div class="form-group"><label>Package Name</label><input class="form-control" name="package_name" value="<?= htmlspecialchars($package['package_name']); ?>" required></div><div class="form-group"><label>Package Code</label><input class="form-control" name="package_code" value="<?= htmlspecialchars($package['package_code'] ?? ''); ?>"></div><div class="form-group"><label>Price</label><input type="number" min="0" step="0.01" class="form-control" name="price" value="<?= htmlspecialchars($package['price']); ?>" required></div><div class="form-group"><label>Description</label><textarea class="form-control" name="description" rows="3"><?= htmlspecialchars($package['description'] ?? ''); ?></textarea></div><div class="form-group"><label>Status</label><select class="form-control" name="status"><option value="active" <?= ($package['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option><option value="inactive" <?= ($package['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option></select></div><button class="btn btn-primary">Update Package</button> <a href="packages.php" class="btn btn-secondary">Back</a></form>
</div></div>
<?php require_once '../includes/footer.php'; ?>
