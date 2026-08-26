<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/project_package_helper.php';

require_admin_user();
ensure_project_package_tables($conn);
$user_id = (int)$_SESSION['user_id'];
$id = (int)($_GET['id'] ?? 0);
$message = '';

$stmt = mysqli_prepare($conn, "SELECT * FROM projects WHERE id=? AND user_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'ii', $id, $user_id);
mysqli_stmt_execute($stmt);
$project = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if(!$project){ die('Project not found.'); }

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $name = trim($_POST['project_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if($name === ''){
        $message = 'Project Name is required.';
    }else{
        $update = mysqli_prepare($conn, "UPDATE projects SET project_name=?, description=?, status=? WHERE id=? AND user_id=?");
        mysqli_stmt_bind_param($update, 'sssii', $name, $description, $status, $id, $user_id);
        if(mysqli_stmt_execute($update)){ header('Location: projects.php'); exit; }
        $message = 'Project could not be updated.';
    }
    $project = array_merge($project, ['project_name' => $name, 'description' => $description, 'status' => $status]);
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title">Edit Project</h3></div><div class="card-body">
<?php if($message){ ?><div class="alert alert-danger"><?= htmlspecialchars($message); ?></div><?php } ?>
<form method="post"><div class="form-group"><label>Project Name</label><input class="form-control" name="project_name" value="<?= htmlspecialchars($project['project_name']); ?>" required></div><div class="form-group"><label>Description</label><textarea class="form-control" name="description" rows="3"><?= htmlspecialchars($project['description'] ?? ''); ?></textarea></div><div class="form-group"><label>Status</label><select class="form-control" name="status"><option value="active" <?= ($project['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option><option value="inactive" <?= ($project['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option></select></div><button class="btn btn-primary">Update Project</button> <a href="projects.php" class="btn btn-secondary">Back</a></form>
</div></div>
<?php require_once '../includes/footer.php'; ?>
