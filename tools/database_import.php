<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/schema_cache_helper.php';
require_once '../includes/super_admin_config.php';

if(!is_super_admin_user()){
    die('Only Super Admin can import full database.');
}

$message = '';
$message_type = '';

function db_import_run_sql($conn, $sql)
{
    // Keep the existing connection alive while a large dump is being processed.
    // A dropped connection makes even cleanup queries fail and obscures the real error.
    @set_time_limit(0);
    @ignore_user_abort(true);
    if(!mysqli_query($conn, 'SET FOREIGN_KEY_CHECKS=0')){
        return [false, 'Could not disable foreign key checks: ' . mysqli_error($conn)];
    }

    $statement_number = 0;
    $buffer = '';
    $delimiter = ';';
    $line_number = 0;
    $import_stream = fopen('php://temp', 'w+b');
    if($import_stream === false){
        mysqli_query($conn, 'SET FOREIGN_KEY_CHECKS=1');
        return [false, 'Could not prepare SQL import stream.'];
    }

    fwrite($import_stream, $sql);
    rewind($import_stream);
    unset($sql);

    while(($line = fgets($import_stream)) !== false){
        $line_number++;
        $trimmed_line = trim($line);

        // MySQL client directives control parsing only; they are not SQL statements.
        if(preg_match('/^DELIMITER\s+(.+)$/i', $trimmed_line, $matches)){
            $delimiter = trim($matches[1]);
            continue;
        }
        if($trimmed_line === '' || preg_match('/^(--(?:\s|$)|#)/', $trimmed_line)){
            continue;
        }

        $buffer .= $line;
        if($delimiter === '' || !preg_match('/' . preg_quote($delimiter, '/') . '\s*$/', rtrim($buffer))){
            continue;
        }

        $statement = trim(substr(rtrim($buffer), 0, -strlen($delimiter)));
        $buffer = '';
        if($statement === ''){
            continue;
        }

        $statement_number++;
        if(!mysqli_query($conn, $statement)){
            $error = 'line ' . $line_number . ': ' . mysqli_error($conn);
            break;
        }
    }

    if(!isset($error) && trim($buffer) !== ''){
        // Accept a final statement without a trailing delimiter.
        $statement_number++;
        if(!mysqli_query($conn, trim($buffer))){
            $error = 'line ' . $line_number . ': ' . mysqli_error($conn);
        }
    }
    fclose($import_stream);

    // Restore checks when possible. This query will naturally fail if MySQL has
    // already closed the connection; preserve the original import error then.
    if(mysqli_ping($conn)){
        mysqli_query($conn, 'SET FOREIGN_KEY_CHECKS=1');
    }

    if(isset($error)){
        return [false, 'Import stopped near SQL statement ' . $statement_number . ': ' . $error];
    }

    return [true, 'Full database imported successfully.'];
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    // Tables may be rebuilt here, so the schema checks run again afterwards.
    schema_cache_reset();
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
            $upload_path = $_FILES['database_file']['tmp_name'];
            $upload_size = (int)($_FILES['database_file']['size'] ?? 0);

            if($upload_size <= 0 || !is_uploaded_file($upload_path)){
                $message = 'SQL upload is empty or invalid. Check PHP upload_max_filesize and post_max_size for large files.';
                $message_type = 'danger';
            }elseif(!is_readable($upload_path)){
                $message = 'The uploaded SQL file could not be read.';
                $message_type = 'danger';
            }else{
                $sql = file_get_contents($upload_path);

                if($sql === false || trim($sql) === ''){
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
                <small class="form-text text-muted">
                    Large imports also depend on PHP upload_max_filesize, post_max_size, and MySQL max_allowed_packet settings.
                </small>
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
