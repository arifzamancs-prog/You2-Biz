<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/project_package_helper.php';

require_admin_user();
ensure_project_package_tables($conn);

$user_id = (int)$_SESSION['user_id'];
$labels = project_package_labels($conn, $user_id);
$message = '';
$project_id = (int)($_POST['project_id'] ?? 0);
$package_name = trim($_POST['package_name'] ?? '');
$price = trim($_POST['price'] ?? '');
$description = trim($_POST['description'] ?? '');

$projects = [];
$project_stmt = mysqli_prepare(
    $conn,
    "SELECT id, project_name
     FROM projects
     WHERE user_id=?
     ORDER BY project_name ASC"
);
mysqli_stmt_bind_param($project_stmt, "i", $user_id);
mysqli_stmt_execute($project_stmt);
$project_result = mysqli_stmt_get_result($project_stmt);
while($project_result && $project = mysqli_fetch_assoc($project_result)){
    $projects[] = $project;
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if($description === '') $description = 'General';
    $price_value = is_numeric($price) ? (float)$price : -1;

    if($project_id <= 0 || $package_name === '' || $price_value < 0){
        $message = $labels['project'] . ', ' . $labels['package_name'] . ' and valid Price are required.';
    } else {
        if($message === ''){
            $insert_stmt = mysqli_prepare(
                $conn,
                "INSERT INTO packages
                 (user_id, project_id, package_name, price, description, status)
                 VALUES
                 (?, ?, ?, ?, ?, 'active')"
            );
            mysqli_stmt_bind_param(
                $insert_stmt,
                "iisds",
                $user_id,
                $project_id,
                $package_name,
                $price_value,
                $description
            );

            if(mysqli_stmt_execute($insert_stmt)){
                header('Location: packages.php');
                exit;
            }

            $message = 'Failed to save package.';
        }
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><?= htmlspecialchars($labels['package_add']); ?></h3>
    </div>

    <div class="card-body">
        <?php if($message){ ?>
            <div class="alert alert-danger"><?= htmlspecialchars($message); ?></div>
        <?php } ?>

        <form method="post">
            <div class="form-group">
                <label><?= htmlspecialchars($labels['project']); ?></label>
                <select name="project_id" class="form-control" required>
                    <option value=""><?= htmlspecialchars($labels['project_select']); ?></option>
                    <?php foreach($projects as $project){ ?>
                        <option value="<?= (int)$project['id']; ?>" <?= $project_id === (int)$project['id'] ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($project['project_name']); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>

            <div class="form-group">
                <label><?= htmlspecialchars($labels['package_name']); ?></label>
                <input type="text" name="package_name" class="form-control" value="<?= htmlspecialchars($package_name); ?>" required>
            </div>

            <div class="form-group">
                <label>Price</label>
                <input type="number" name="price" class="form-control" min="0" step="0.01" value="<?= htmlspecialchars($price); ?>" required>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control" rows="4"><?= htmlspecialchars($description); ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary"><?= htmlspecialchars($labels['package_save']); ?></button>
            <a href="packages.php" class="btn btn-secondary">Back</a>
        </form>
    </div>
</div>

<?php
require_once '../includes/footer.php';
?>
