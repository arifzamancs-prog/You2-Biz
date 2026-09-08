<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/super_admin_config.php';

if(!is_super_admin_user()){
    die('Only Super Admin can import full database.');
}

$message = '';
$message_type = '';

function db_import_run_sql($conn, $sql)
{
    mysqli_query($conn, 'SET FOREIGN_KEY_CHECKS=0');

    if(mysqli_multi_query($conn, $sql)){
        do{
            if($result = mysqli_store_result($conn)){
                mysqli_free_result($result);
            }
        }while(mysqli_more_results($conn) && mysqli_next_result($conn));
    }

    if(mysqli_errno($conn)){
        $error = mysqli_error($conn);
        mysqli_query($conn, 'SET FOREIGN_KEY_CHECKS=1');
        return [false, $error];
    }

    mysqli_query($conn, 'SET FOREIGN_KEY_CHECKS=1');
    return [true, 'Full database imported successfully.'];
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $password = $_POST['password'] ?? '';
    $confirmed = isset($_POST['confirm_import']);

    if(!$confirmed){
        $message = 'Please confirm the full database import.';
        $message_type = 'danger';
    }elseif(!password_verify($password, SUPER_ADMIN_PASSWORD_HASH)){
        $message = 'Invalid Super Admin password.';
        $message_type = 'danger';
    }elseif(!isset($_FILES['database_file']) || $_FILES['database_file']['error'] !== UPLOAD_ERR_OK){
        $message = 'Please upload a valid SQL file.';
        $message_type = 'danger';
    }else{
        $original_name = (string)($_FILES['database_file']['name'] ?? '');
        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

        if($extension !== 'sql'){
            $message = 'Only .sql database backup file is allowed.';
            $message_type = 'danger';
        }else{
            $sql = file_get_contents($_FILES['database_file']['tmp_name']);

            if(trim((string)$sql) === ''){
                $message = 'SQL file is empty.';
                $message_type = 'danger';
            }else{
                [$ok, $import_message] = db_import_run_sql($conn, $sql);
                $message = $import_message;
                $message_type = $ok ? 'success' : 'danger';
            }
        }
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card card-danger">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-database mr-2"></i> Full MySQL Database Import
        </h3>
    </div>
    <div class="card-body">
        <?php if($message){ ?>
            <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                <?= htmlspecialchars($message); ?>
            </div>
        <?php } ?>

        <div class="alert alert-warning">
            <strong>WARNING!</strong><br>
            Ei option uploaded SQL file current MySQL database-e run korbe. Existing data replace/delete hote pare.
            Import-er age Full DB Export kore backup rekhe nin.
        </div>

        <form method="post" enctype="multipart/form-data">
            <div class="form-group">
                <label>SQL Backup File</label>
                <input type="file" name="database_file" class="form-control" accept=".sql" required>
            </div>

            <div class="form-group">
                <label>Super Admin Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>

            <div class="form-group">
                <div class="custom-control custom-checkbox">
                    <input
                        type="checkbox"
                        name="confirm_import"
                        value="1"
                        class="custom-control-input"
                        id="confirmDatabaseImport">
                    <label class="custom-control-label" for="confirmDatabaseImport">
                        I understand this will import SQL into the current database.
                    </label>
                </div>
            </div>

            <button type="submit" class="btn btn-danger">
                <i class="fas fa-file-import"></i> Import Full Database
            </button>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
