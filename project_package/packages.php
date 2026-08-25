<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/project_package_helper.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$user_id = (int)$_SESSION['user_id'];
ensure_project_package_tables($conn);

$packages = [];
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

$package_stmt = mysqli_prepare(
    $conn,
    "SELECT
        pk.*,
        pr.project_name
     FROM packages pk
     LEFT JOIN projects pr
        ON pr.id = pk.project_id
     WHERE pk.user_id=?
     ORDER BY pk.id DESC"
);
mysqli_stmt_bind_param($package_stmt, "i", $user_id);
mysqli_stmt_execute($package_stmt);
$package_result = mysqli_stmt_get_result($package_stmt);
while($package_result && $row = mysqli_fetch_assoc($package_result)){
    $packages[] = $row;
}

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">
            <i class="fas fa-box-open mr-2"></i>
            Package List
        </h3>

        <?php if(manager_can_modify()){ ?>
        <div class="card-tools">
            <a href="package_create.php" class="btn btn-primary btn-sm">
                <i class="fas fa-plus"></i>
                Add Package
            </a>
        </div>
        <?php } ?>

    </div>

    <div class="card-body">

        <div class="row mb-3">
            <div class="col-md-6">
                <div class="d-flex">
                    <select class="form-control mr-2" disabled>
                        <option>All Projects</option>
                        <?php foreach($projects as $project){ ?>
                            <option><?= htmlspecialchars($project['project_name']); ?></option>
                        <?php } ?>
                    </select>
                    <button type="button" class="btn btn-primary mr-2 disabled">Filter</button>
                    <button type="button" class="btn btn-secondary disabled">Reset</button>
                </div>
            </div>
        </div>

        <table
            id="example1"
            class="table table-bordered table-striped">

            <thead>
                <tr>
                    <th>Project</th>
                    <th>Package Name</th>
                    <th>Package Code</th>
                    <th>Price</th>
                    <th>Description</th>
                    <th>Status</th>
                    <?php if(manager_can_modify()){ ?>
                        <th width="180">Action</th>
                    <?php } ?>
                </tr>
            </thead>

            <tbody>
                <?php if(empty($packages)): ?>
                    <tr>
                        <td colspan="<?= manager_can_modify() ? '7' : '6'; ?>" class="text-center text-muted">
                            No package found yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach($packages as $package): ?>
                        <tr>
                            <td><?= htmlspecialchars($package['project_name'] ?? '-'); ?></td>
                            <td><?= htmlspecialchars($package['package_name']); ?></td>
                            <td><?= htmlspecialchars($package['package_code'] ?: '-'); ?></td>
                            <td>BDT <?= number_format((float)$package['price'], 2); ?></td>
                            <td><?= htmlspecialchars($package['description'] ?: '-'); ?></td>
                            <td>
                                <span class="badge badge-<?= ($package['status'] ?? 'active') === 'active' ? 'success' : 'danger'; ?>">
                                    <?= htmlspecialchars(ucfirst($package['status'] ?? 'active')); ?>
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
