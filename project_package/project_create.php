<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/project_package_helper.php';

require_admin_user();
ensure_project_package_tables($conn);

$user_id = (int)$_SESSION['user_id'];
$message = '';
$project_name = trim($_POST['project_name'] ?? '');
$project_code = '';
$description = trim($_POST['description'] ?? '');

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if($project_name === ''){
        $message = 'Project Name is required.';
    } else {
        $project_code = 'PRJ-' . date('ymdHis') . '-' . random_int(100, 999);
        $check_stmt = mysqli_prepare(
            $conn,
            "SELECT id
             FROM projects
             WHERE user_id=?
             AND project_code=?
             LIMIT 1"
        );
        mysqli_stmt_bind_param($check_stmt, "is", $user_id, $project_code);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);

        if($check_result && mysqli_num_rows($check_result) > 0){
            $message = 'Project Code already exists.';
        } else {
            $insert_stmt = mysqli_prepare(
                $conn,
                "INSERT INTO projects
                 (user_id, project_name, project_code, description, status)
                 VALUES
                 (?, ?, ?, ?, 'active')"
            );
            mysqli_stmt_bind_param($insert_stmt, "isss", $user_id, $project_name, $project_code, $description);

            if(mysqli_stmt_execute($insert_stmt)){
                header('Location: projects.php');
                exit;
            }

            $message = 'Failed to save project.';
        }
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Add Project</h3>
    </div>

    <div class="card-body">
        <?php if($message){ ?>
            <div class="alert alert-danger"><?= htmlspecialchars($message); ?></div>
        <?php } ?>

        <form method="post">
            <div class="form-group">
                <label>Project Name</label>
                <input type="text" name="project_name" class="form-control" value="<?= htmlspecialchars($project_name); ?>" required>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($description); ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary">Save Project</button>
            <a href="projects.php" class="btn btn-secondary">Back</a>
        </form>
    </div>
</div>

<?php
require_once '../includes/footer.php';
?>
