<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/project_package_helper.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = (int)$_SESSION['user_id'];
ensure_project_package_tables($conn);

$projects = [];
$stmt = mysqli_prepare(
    $conn,
    "SELECT *
     FROM projects
     WHERE user_id=?
     ORDER BY id DESC"
);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while($result && $row = mysqli_fetch_assoc($result)){
    $projects[] = $row;
}

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">
            <i class="fas fa-project-diagram mr-2"></i>
            Project List
        </h3>

        <?php if(manager_can_modify()){ ?>
        <div class="card-tools">
            <a href="project_create.php" class="btn btn-primary btn-sm">
                <i class="fas fa-plus"></i>
                Add Project
            </a>
        </div>
        <?php } ?>

    </div>

    <div class="card-body">

        <table
            id="example1"
            class="table table-bordered table-striped">

            <thead>
                <tr>
                    <th>Project Name</th>
                    <th>Project Code</th>
                    <th>Description</th>
                    <th>Status</th>
                    <?php if(manager_can_modify()){ ?>
                        <th width="180">Action</th>
                    <?php } ?>
                </tr>
            </thead>

            <tbody>
                <?php if(empty($projects)): ?>
                    <tr>
                        <td colspan="<?= manager_can_modify() ? '5' : '4'; ?>" class="text-center text-muted">
                            No project found yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach($projects as $project): ?>
                        <tr>
                            <td><?= htmlspecialchars($project['project_name']); ?></td>
                            <td><?= htmlspecialchars($project['project_code']); ?></td>
                            <td><?= htmlspecialchars($project['description'] ?: '-'); ?></td>
                            <td>
                                <span class="badge badge-<?= ($project['status'] ?? 'active') === 'active' ? 'success' : 'danger'; ?>">
                                    <?= htmlspecialchars(ucfirst($project['status'] ?? 'active')); ?>
                                </span>
                            </td>
                            <?php if(manager_can_modify()){ ?>
                                <td>
                                    <button type="button" class="btn btn-info btn-sm disabled">Edit</button>
                                    <button type="button" class="btn btn-danger btn-sm disabled">Delete</button>
                                </td>
                            <?php } ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>

        </table>

    </div>

</div>

<?php
require_once '../includes/footer.php';
?>
