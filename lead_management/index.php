<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/lead_management_helper.php';

ensure_lead_management_table($conn);

$user_id = (int)$_SESSION['user_id'];
$filter = normalize_lead_filter($_GET['filter'] ?? 'lead');
$message = '';

if(manager_can_modify() && isset($_GET['set_status'], $_GET['id'])){
    $id = (int)$_GET['id'];
    $set_status = normalize_lead_filter($_GET['set_status']);

    $update_stmt = mysqli_prepare(
        $conn,
        "UPDATE leads
         SET status=?
         WHERE id=?
         AND user_id=?"
    );
    mysqli_stmt_bind_param($update_stmt, 'sii', $set_status, $id, $user_id);
    mysqli_stmt_execute($update_stmt);

    header('Location: index.php?filter=' . urlencode($filter));
    exit;
}

if(manager_can_modify() && isset($_GET['delete'])){
    $id = (int)$_GET['delete'];

    $delete_stmt = mysqli_prepare(
        $conn,
        "DELETE FROM leads
         WHERE id=?
         AND user_id=?"
    );
    mysqli_stmt_bind_param($delete_stmt, 'ii', $id, $user_id);
    mysqli_stmt_execute($delete_stmt);

    header('Location: index.php?filter=' . urlencode($filter));
    exit;
}

$leads = [];
$stmt = mysqli_prepare(
    $conn,
    "SELECT *
     FROM leads
     WHERE user_id=?
     AND status=?
     ORDER BY COALESCE(followup_date, DATE(created_at)) ASC, id DESC"
);
mysqli_stmt_bind_param($stmt, 'is', $user_id, $filter);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while($result && $row = mysqli_fetch_assoc($result)){
    $leads[] = $row;
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">
            <i class="fas fa-filter mr-2"></i>
            <?= htmlspecialchars(lead_management_title($filter)); ?>
        </h3>

        <div class="card-tools">
            <a href="create.php" class="btn btn-primary btn-sm">
                <i class="fas fa-plus"></i>
                Add Lead
            </a>
        </div>

    </div>

    <div class="card-body">

        <?php if($message){ ?>
            <div class="alert alert-danger"><?= htmlspecialchars($message); ?></div>
        <?php } ?>

        <table id="example1" class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>Lead ID</th>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Note</th>
                    <th>Followup Date</th>
                    <th>Status</th>
                    <th width="260">Action</th>
                </tr>
            </thead>

            <tbody>
                <?php if(empty($leads)){ ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted">
                            No data available in table
                        </td>
                    </tr>
                <?php } else { ?>
                    <?php foreach($leads as $lead){ ?>
                        <tr>
                            <td><?= htmlspecialchars(lead_code_from_id((int)$lead['id'])); ?></td>
                            <td><?= htmlspecialchars($lead['name']); ?></td>
                            <td><?= htmlspecialchars($lead['phone']); ?></td>
                            <td><?= htmlspecialchars($lead['email'] ?: '-'); ?></td>
                            <td><?= htmlspecialchars($lead['note'] ?: '-'); ?></td>
                            <td><?= htmlspecialchars($lead['followup_date'] ?: '-'); ?></td>
                            <td>
                                <span class="badge badge-<?=
                                    $lead['status'] === 'customer'
                                        ? 'primary'
                                        : ($lead['status'] === 'successful' ? 'success' : 'warning');
                                ?>">
                                    <?= htmlspecialchars(ucfirst($lead['status'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php if(manager_can_modify()){ ?>
                                    <?php if($lead['status'] !== 'lead'){ ?>
                                        <a href="index.php?filter=<?= urlencode($filter); ?>&set_status=lead&id=<?= (int)$lead['id']; ?>" class="btn btn-secondary btn-sm">
                                            New Lead
                                        </a>
                                    <?php } ?>

                                    <?php if($lead['status'] !== 'successful'){ ?>
                                        <a href="index.php?filter=<?= urlencode($filter); ?>&set_status=successful&id=<?= (int)$lead['id']; ?>" class="btn btn-success btn-sm">
                                            Successful
                                        </a>
                                    <?php } ?>

                                    <?php if($lead['status'] !== 'customer'){ ?>
                                        <a href="index.php?filter=<?= urlencode($filter); ?>&set_status=customer&id=<?= (int)$lead['id']; ?>" class="btn btn-info btn-sm">
                                            Convert
                                        </a>
                                    <?php } ?>

                                    <a
                                        href="index.php?filter=<?= urlencode($filter); ?>&delete=<?= (int)$lead['id']; ?>"
                                        class="btn btn-danger btn-sm"
                                        onclick="return confirm('Delete this lead?')">
                                        Delete
                                    </a>
                                <?php } else { ?>
                                    <span class="text-muted">No action</span>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                <?php } ?>
            </tbody>
        </table>

    </div>

</div>

<?php
require_once '../includes/footer.php';
?>
