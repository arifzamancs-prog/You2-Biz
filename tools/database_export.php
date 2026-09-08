<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/super_admin_config.php';

if(!is_super_admin_user()){
    die('Only Super Admin can export full database.');
}

$message = '';
$message_type = '';

function db_export_quote_identifier($name)
{
    return '`' . str_replace('`', '``', (string)$name) . '`';
}

function db_export_sql_value($conn, $value)
{
    if($value === null){
        return 'NULL';
    }

    return "'" . mysqli_real_escape_string($conn, (string)$value) . "'";
}

function db_export_download($conn, $db)
{
    $db_name = (string)$db;
    $filename = 'you2biz_full_database_' . date('Ymd_His') . '.sql';

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    echo "-- You2 Biz full database backup\n";
    echo "-- Database: " . $db_name . "\n";
    echo "-- Exported at: " . date('Y-m-d H:i:s') . "\n\n";
    echo "SET FOREIGN_KEY_CHECKS=0;\n";
    echo "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    echo "SET time_zone = \"+06:00\";\n\n";

    $tables_result = mysqli_query($conn, 'SHOW FULL TABLES WHERE Table_type = "BASE TABLE"');

    while($tables_result && $table_row = mysqli_fetch_array($tables_result)){
        $table = $table_row[0];
        $quoted_table = db_export_quote_identifier($table);

        echo "\n-- --------------------------------------------------------\n";
        echo "-- Table structure for {$quoted_table}\n";
        echo "-- --------------------------------------------------------\n\n";
        echo "DROP TABLE IF EXISTS {$quoted_table};\n";

        $create_result = mysqli_query($conn, "SHOW CREATE TABLE {$quoted_table}");
        $create_row = $create_result ? mysqli_fetch_assoc($create_result) : null;
        $create_sql = $create_row['Create Table'] ?? '';

        if($create_sql !== ''){
            echo $create_sql . ";\n\n";
        }

        echo "-- Data for {$quoted_table}\n\n";

        $data_result = mysqli_query($conn, "SELECT * FROM {$quoted_table}");

        while($data_result && $row = mysqli_fetch_assoc($data_result)){
            $columns = array_map('db_export_quote_identifier', array_keys($row));
            $values = [];

            foreach($row as $value){
                $values[] = db_export_sql_value($conn, $value);
            }

            echo "INSERT INTO {$quoted_table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ");\n";
        }

        echo "\n";
    }

    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    exit;
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $password = $_POST['password'] ?? '';

    if(!password_verify($password, SUPER_ADMIN_PASSWORD_HASH)){
        $message = 'Invalid Super Admin password.';
        $message_type = 'danger';
    }else{
        db_export_download($conn, $db);
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-database mr-2"></i> Full MySQL Database Export
        </h3>
    </div>
    <div class="card-body">
        <?php if($message){ ?>
            <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                <?= htmlspecialchars($message); ?>
            </div>
        <?php } ?>

        <div class="alert alert-info">
            Ei option current full MySQL database-er complete SQL backup download korbe.
        </div>

        <form method="post">
            <div class="form-group">
                <label>Super Admin Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-success">
                <i class="fas fa-file-export"></i> Export Full Database
            </button>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
