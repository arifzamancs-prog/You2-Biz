<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/project_package_helper.php';

require_admin_user();
ensure_project_package_tables($conn);

$user_id = (int)$_SESSION['user_id'];
$labels = project_package_labels($conn, $user_id);
$message = '';
if(empty($_SESSION['package_project_token'])){
    $_SESSION['package_project_token'] = bin2hex(random_bytes(32));
}
$creating_project = ($_POST['project_id'] ?? '') === '__new__';
$new_project_name = trim((string)($_POST['new_project_name'] ?? ''));
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

    $owned_project = in_array($project_id, array_map('intval', array_column($projects, 'id')), true);
    if(!hash_equals($_SESSION['package_project_token'], (string)($_POST['token'] ?? ''))){
        $message = 'Please refresh the page and try again.';
    } elseif(($creating_project ? $new_project_name === '' : !$owned_project) || $package_name === '' || $price_value < 0){
        $message = $labels['project'] . ', ' . $labels['package_name'] . ' and valid Price are required.';
    } else {
        mysqli_begin_transaction($conn);
        try {
            if($creating_project){
                $new_code = 'PRJ-' . date('ymdHis') . '-' . bin2hex(random_bytes(5));
                $new_description = '';
                $new_stmt = mysqli_prepare($conn, "INSERT INTO projects (user_id, project_name, project_code, description, status) VALUES (?, ?, ?, ?, 'active')");
                mysqli_stmt_bind_param($new_stmt, 'isss', $user_id, $new_project_name, $new_code, $new_description);
                if(!mysqli_stmt_execute($new_stmt)) throw new RuntimeException('Project insert failed');
                $project_id = (int)mysqli_insert_id($conn);
            }
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
                mysqli_commit($conn);
                header('Location: packages.php');
                exit;
            }

            throw new RuntimeException('Package insert failed');
        } catch(Throwable $e){
            mysqli_rollback($conn);
            if($creating_project) $project_id = 0;
            $message = 'Failed to save package. Please try again.';
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
            <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['package_project_token'], ENT_QUOTES); ?>">
            <div class="form-group">
                <label><?= htmlspecialchars($labels['project']); ?></label>
                <select name="project_id" id="project-select" class="form-control" required>
                    <option value=""><?= htmlspecialchars($labels['project_select']); ?></option>
                    <?php foreach($projects as $project){ ?>
                        <option value="<?= (int)$project['id']; ?>" <?= $project_id === (int)$project['id'] ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($project['project_name']); ?>
                        </option>
                    <?php } ?>
                    <option value="__new__" <?= $creating_project ? 'selected' : ''; ?>>+ <?= htmlspecialchars($labels['project'] === 'Project' ? 'Add New Project' : $labels['project_add']); ?></option>
                </select>
                <div id="new-project-panel" class="mt-2" <?= $creating_project ? '' : 'hidden'; ?>>
                    <label for="new-project-name">New <?= htmlspecialchars($labels['project'] === 'Service Category' ? $labels['project'] : $labels['project_name']); ?></label>
                    <input id="new-project-name" name="new_project_name" type="text" class="form-control" placeholder="<?= htmlspecialchars('Enter ' . strtolower($labels['project'] === 'Service Category' ? $labels['project'] : $labels['project_name']), ENT_QUOTES); ?>" value="<?= htmlspecialchars($new_project_name, ENT_QUOTES); ?>" <?= $creating_project ? 'required' : 'disabled'; ?>>
                </div>
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

<script>
(function(){
    const select = document.getElementById('project-select');
    const panel = document.getElementById('new-project-panel');
    const name = document.getElementById('new-project-name');
    select.addEventListener('change', function(){
        const creating = select.value === '__new__';
        panel.hidden = !creating;
        name.disabled = !creating;
        name.required = creating;
        if(creating) name.focus();
    });
})();
</script>
<?php
require_once '../includes/footer.php';
?>
