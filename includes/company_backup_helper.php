<?php

require_once __DIR__ . '/app_config.php';

function ensure_company_delete_backups_table($conn)
{
    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS company_delete_backups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL DEFAULT 0,
            company_name VARCHAR(255) NOT NULL DEFAULT '',
            backup_type VARCHAR(50) NOT NULL DEFAULT 'delete_all_data',
            file_name VARCHAR(255) NOT NULL DEFAULT '',
            file_path VARCHAR(500) NOT NULL DEFAULT '',
            deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by_user_id INT NOT NULL DEFAULT 0,
            created_by_role VARCHAR(50) NOT NULL DEFAULT '',
            INDEX idx_company_delete_backups_company (company_id),
            INDEX idx_company_delete_backups_date (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function company_backup_valid_table_name($table)
{
    return preg_match('/^[A-Za-z0-9_]+$/', (string)$table) === 1;
}

function company_backup_table_has_column($conn, $table, $column)
{
    if(!company_backup_valid_table_name($table) || !company_backup_valid_table_name($column)){
        return false;
    }

    $column = mysqli_real_escape_string($conn, $column);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");

    return $result && mysqli_num_rows($result) > 0;
}

function company_backup_root_dir_path()
{
    return dirname(__DIR__) . '/backup_data';
}

function company_backup_root_dir_url()
{
    return app_path('backup_data');
}

function ensure_company_backup_root_dir()
{
    $dir = company_backup_root_dir_path();

    if(!is_dir($dir)){
        @mkdir($dir, 0777, true);
    }

    return is_dir($dir);
}

function company_backup_slug($value)
{
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim((string)$value, '-');

    return $value === '' ? 'company' : $value;
}

function company_backup_collect($conn, $company_id, $include_company_profile = false)
{
    $company_id = (int)$company_id;

    $company_sql = "SELECT *
                    FROM users
                    WHERE id=?
                    AND role='admin'
                    LIMIT 1";

    $company_stmt = mysqli_prepare($conn, $company_sql);

    if(!$company_stmt){
        return [false, 'Company information could not be loaded.', null];
    }

    mysqli_stmt_bind_param($company_stmt, "i", $company_id);
    mysqli_stmt_execute($company_stmt);
    $company_result = mysqli_stmt_get_result($company_stmt);
    $company = $company_result ? mysqli_fetch_assoc($company_result) : null;

    if(!$company){
        return [false, 'Company not found.', null];
    }

    $backup = [
        'backup_version' => '1.1',
        'created_at' => date('Y-m-d H:i:s'),
        'company_id' => $company_id,
        'company_name' => $company['name'] ?? '',
        'company_email' => $company['email'] ?? '',
        'company_phone' => $company['phone'] ?? '',
        'data' => [],
    ];

    if($include_company_profile){
        $company_profile = $company;
        $company_profile['_old_id'] = $company_profile['id'];
        unset($company_profile['id']);
        $backup['data']['company_admin_profile'] = $company_profile;
    }

    $tables = [];
    $table_result = mysqli_query($conn, "SHOW TABLES");

    while($table_result && $table_row = mysqli_fetch_array($table_result)){
        $table = $table_row[0];

        if(company_backup_table_has_column($conn, $table, 'user_id')){
            $tables[] = $table;
        }
    }

    foreach($tables as $table){
        $rows = [];
        $sql = "SELECT *
                FROM `{$table}`
                WHERE user_id=?";
        $stmt = mysqli_prepare($conn, $sql);

        if(!$stmt){
            continue;
        }

        mysqli_stmt_bind_param($stmt, "i", $company_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        while($row = mysqli_fetch_assoc($result)){
            unset($row['user_id']);
            $rows[] = $row;
        }

        $backup['data'][$table] = $rows;
    }

    $manager_rows = [];
    $manager_sql = "SELECT *
                    FROM users
                    WHERE owner_id=?
                    AND role='manager'
                    ORDER BY id ASC";

    $manager_stmt = mysqli_prepare($conn, $manager_sql);
    mysqli_stmt_bind_param($manager_stmt, "i", $company_id);
    mysqli_stmt_execute($manager_stmt);
    $manager_result = mysqli_stmt_get_result($manager_stmt);

    while($row = mysqli_fetch_assoc($manager_result)){
        $row['_old_id'] = $row['id'];
        unset($row['id'], $row['owner_id']);
        $manager_rows[] = $row;
    }

    $backup['data']['users_manager_agents'] = $manager_rows;

    $pricing_plan_requests = [];

    if(company_backup_table_has_column($conn, 'pricing_plan_requests', 'admin_user_id')){
        $result = mysqli_query(
            $conn,
            "SELECT *
             FROM pricing_plan_requests
             WHERE admin_user_id={$company_id}
             ORDER BY id ASC"
        );

        while($result && $row = mysqli_fetch_assoc($result)){
            $pricing_plan_requests[] = $row;
        }
    }

    $backup['data']['pricing_plan_requests'] = $pricing_plan_requests;

    $support_tickets = [];

    if(company_backup_table_has_column($conn, 'support_tickets', 'admin_user_id')){
        $ticket_result = mysqli_query(
            $conn,
            "SELECT *
             FROM support_tickets
             WHERE admin_user_id={$company_id}
             ORDER BY id ASC"
        );

        while($ticket_result && $ticket = mysqli_fetch_assoc($ticket_result)){
            $ticket_id = (int)($ticket['id'] ?? 0);
            $replies = [];

            if($ticket_id > 0 && company_backup_table_has_column($conn, 'support_ticket_replies', 'ticket_id')){
                $reply_result = mysqli_query(
                    $conn,
                    "SELECT *
                     FROM support_ticket_replies
                     WHERE ticket_id={$ticket_id}
                     ORDER BY id ASC"
                );

                while($reply_result && $reply = mysqli_fetch_assoc($reply_result)){
                    $replies[] = $reply;
                }
            }

            $ticket['_replies'] = $replies;
            $support_tickets[] = $ticket;
        }
    }

    $backup['data']['support_tickets'] = $support_tickets;

    return [true, '', $backup];
}

function company_backup_save_file($conn, $company_id, $backup, $backup_type, $created_by_user_id, $created_by_role)
{
    ensure_company_delete_backups_table($conn);

    if(!ensure_company_backup_root_dir()){
        return [false, 'Backup directory could not be created.', null];
    }

    $company_id = (int)$company_id;
    $created_by_user_id = (int)$created_by_user_id;
    $backup_type = trim((string)$backup_type) === '' ? 'delete_all_data' : trim((string)$backup_type);
    $file_name = 'you2biz_company_' . $company_id . '_' . $backup_type . '_' . date('Ymd_His') . '.json';
    $file_path = company_backup_root_dir_path() . '/' . $file_name;
    $json = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    if($json === false){
        return [false, 'Backup JSON could not be generated.', null];
    }

    if(@file_put_contents($file_path, $json) === false){
        return [false, 'Backup file could not be written.', null];
    }

    $relative_path = 'backup_data/' . $file_name;
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO company_delete_backups (
            company_id,
            company_name,
            backup_type,
            file_name,
            file_path,
            created_by_user_id,
            created_by_role
         )
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    if(!$stmt){
        @unlink($file_path);
        return [false, 'Backup log could not be saved.', null];
    }

    mysqli_stmt_bind_param(
        $stmt,
        "issssis",
        $company_id,
        $company_name,
        $backup_type,
        $file_name,
        $relative_path,
        $created_by_user_id,
        $created_by_role
    );

    if(!mysqli_stmt_execute($stmt)){
        @unlink($file_path);
        return [false, 'Backup log could not be saved.', null];
    }

    return [
        true,
        '',
        [
            'id' => mysqli_insert_id($conn),
            'file_name' => $file_name,
            'file_path' => $file_path,
            'relative_path' => $relative_path,
        ]
    ];
}

function company_backup_create_and_store($conn, $company_id, $backup_type, $created_by_user_id, $created_by_role, $include_company_profile = false)
{
    [$ok, $error, $backup] = company_backup_collect($conn, $company_id, $include_company_profile);

    if(!$ok){
        return [false, $error, null];
    }

    return company_backup_save_file(
        $conn,
        $company_id,
        $backup,
        $backup_type,
        $created_by_user_id,
        $created_by_role
    );
}
