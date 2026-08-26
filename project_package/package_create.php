<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/project_package_helper.php';

require_admin_user();
ensure_project_package_tables($conn);

$user_id = (int)$_SESSION['user_id'];
$message = '';
$project_id = (int)($_POST['project_id'] ?? 0);
$package_name = trim($_POST['package_name'] ?? '');
$package_code = trim($_POST['package_code'] ?? '');
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
        $message = 'Project, Package Name and valid Price are required.';
    } else {
        if($package_code !== ''){
            $check_stmt = mysqli_prepare(
                $conn,
                "SELECT id
                 FROM packages
                 WHERE user_id=?
                 AND package_code=?
                 LIMIT 1"
            );
            mysqli_stmt_bind_param($check_stmt, "is", $user_id, $package_code);
            mysqli_stmt_execute($check_stmt);
            $check_result = mysqli_stmt_get_result($check_stmt);

            if($check_result && mysqli_num_rows($check_result) > 0){
                $message = 'Package Code already exists.';
            }
        }

        if($message === ''){
            $insert_stmt = mysqli_prepare(
                $conn,
                "INSERT INTO packages
                 (user_id, project_id, package_name, package_code, price, description, status)
                 VALUES
                 (?, ?, ?, ?, ?, ?, 'active')"
            );
            mysqli_stmt_bind_param(
                $insert_stmt,
                "iissds",
                $user_id,
                $project_id,
                $package_name,
                $package_code,
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
        <h3 class="card-title">Add Package</h3>
    </div>

    <div class="card-body">
        <?php if($message){ ?>
            <div class="alert alert-danger"><?= htmlspecialchars($message); ?></div>
        <?php } ?>

        <form method="post">
            <div class="form-group">
                <label>Project</label>
                <select name="project_id" class="form-control" required>
                    <option value="">Select Project</option>
                    <?php foreach($projects as $project){ ?>
                        <option value="<?= (int)$project['id']; ?>" <?= $project_id === (int)$project['id'] ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($project['project_name']); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>

            <div class="form-group">
                <label>Package Name</label>
                <input type="text" name="package_name" class="form-control" value="<?= htmlspecialchars($package_name); ?>" required>
            </div>

            <div class="form-group">
                <label>Package Code</label>
                <input type="text" name="package_code" class="form-control" value="<?= htmlspecialchars($package_code); ?>">
            </div>

            <div class="form-group">
                <label>Price</label>
                <input type="number" name="price" class="form-control" min="0" step="0.01" value="<?= htmlspecialchars($price); ?>" required>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control" rows="4"><?= htmlspecialchars($description); ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary">Save Package</button>
            <a href="packages.php" class="btn btn-secondary">Back</a>
        </form>
    </div>
</div>

<?php
require_once '../includes/footer.php';
?>
