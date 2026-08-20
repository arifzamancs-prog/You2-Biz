<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/app_config.php';
require_once '../includes/company_backup_helper.php';

require_super_admin_user();
ensure_company_delete_backups_table($conn);

$message = '';
$message_type = '';
$total_backup_size = 0;

function backup_human_size($bytes)
{
    $bytes = max(0, (float)$bytes);
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $unit_index = 0;

    while($bytes >= 1024 && $unit_index < count($units) - 1){
        $bytes /= 1024;
        $unit_index++;
    }

    $precision = $unit_index === 0 ? 0 : 2;

    return number_format($bytes, $precision) . ' ' . $units[$unit_index];
}

if(isset($_GET['download'])){
    $download_id = (int)$_GET['download'];
    $stmt = mysqli_prepare(
        $conn,
        "SELECT file_name, file_path
         FROM company_delete_backups
         WHERE id=?
         LIMIT 1"
    );

    if($stmt){
        mysqli_stmt_bind_param($stmt, "i", $download_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $backup = $result ? mysqli_fetch_assoc($result) : null;

        if($backup){
            $path = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', (string)$backup['file_path']), '/');

            if(is_file($path)){
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="' . basename((string)$backup['file_name']) . '"');
                header('Content-Length: ' . filesize($path));
                readfile($path);
                exit;
            }
        }
    }

    $message = 'Backup file was not found.';
    $message_type = 'danger';
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_backup'){
    $backup_id = (int)($_POST['backup_id'] ?? 0);

    $stmt = mysqli_prepare(
        $conn,
        "SELECT file_path
         FROM company_delete_backups
         WHERE id=?
         LIMIT 1"
    );

    if($stmt){
        mysqli_stmt_bind_param($stmt, "i", $backup_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $backup = $result ? mysqli_fetch_assoc($result) : null;

        if($backup){
            $path = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', (string)$backup['file_path']), '/');

            if(is_file($path)){
                @unlink($path);
            }

            $delete_stmt = mysqli_prepare($conn, "DELETE FROM company_delete_backups WHERE id=?");

            if($delete_stmt){
                mysqli_stmt_bind_param($delete_stmt, "i", $backup_id);
                mysqli_stmt_execute($delete_stmt);
                $message = 'Backup file deleted successfully.';
                $message_type = 'success';
            }else{
                $message = 'Backup log could not be deleted.';
                $message_type = 'danger';
            }
        }else{
            $message = 'Backup record not found.';
            $message_type = 'danger';
        }
    }
}

$backup_result = mysqli_query(
    $conn,
    "SELECT *
     FROM company_delete_backups
     ORDER BY deleted_at DESC, id DESC"
);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-archive mr-2"></i>
            Company Delete Data
        </h3>
    </div>
    <div class="card-body">
        <?php if($message !== ''){ ?>
            <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                <?= htmlspecialchars($message); ?>
            </div>
        <?php } ?>

        <div class="table-responsive">
            <table class="table table-bordered table-hover" id="example1">
                <thead>
                    <tr>
                        <th>Del Date</th>
                        <th>Company</th>
                        <th>Size</th>
                        <th>Download File</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($backup_result && $row = mysqli_fetch_assoc($backup_result)){ ?>
                        <?php
                        $file_path = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', (string)($row['file_path'] ?? '')), '/');
                        $file_size = is_file($file_path) ? (float)filesize($file_path) : 0;
                        $total_backup_size += $file_size;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$row['deleted_at']); ?></td>
                            <td><?= htmlspecialchars((string)$row['company_name']); ?></td>
                            <td><?= htmlspecialchars(backup_human_size($file_size)); ?></td>
                            <td>
                                <a href="<?= htmlspecialchars(app_path('super_admin/company_delete_data.php?download=' . (int)$row['id'])); ?>" class="btn btn-sm btn-info">
                                    <i class="fas fa-download mr-1"></i>
                                    <?= htmlspecialchars((string)$row['file_name']); ?>
                                </a>
                            </td>
                            <td>
                                <form method="post" onsubmit="return confirm('Delete this backup file?');">
                                    <input type="hidden" name="action" value="delete_backup">
                                    <input type="hidden" name="backup_id" value="<?= (int)$row['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">
                                        <i class="fas fa-trash mr-1"></i>
                                        Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="2" class="text-right">Total Size</th>
                        <th><?= htmlspecialchars(backup_human_size($total_backup_size)); ?></th>
                        <th colspan="2"></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
