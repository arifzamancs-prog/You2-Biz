<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/company_backup_helper.php';

$user_id = $_SESSION['user_id'];

$message = '';
$message_type = '';

function import_valid_table_name($table)
{
    return preg_match('/^[A-Za-z0-9_]+$/', (string)$table) === 1;
}

function import_table_has_column($conn, $table, $column)
{
    return company_backup_table_has_column($conn, $table, $column);
}

function import_restore_reference_maps()
{
    return [
        'wallet_id' => 'wallets',
        'from_wallet_id' => 'wallets',
        'to_wallet_id' => 'wallets',
        'category_id' => 'categories',
        'staff_id' => 'staff',
        'ref_staff_id' => 'staff',
        'customer_id' => 'customers',
        'converted_customer_id' => 'customers',
        'supplier_id' => 'suppliers',
        'project_id' => 'projects',
        'package_id' => 'packages',
        'lead_id' => 'leads',
        'product_id' => 'products',
        'purchase_id' => 'purchases',
    ];
}

function import_restore_priority($table)
{
    static $priority = [
        'wallets' => 10,
        'categories' => 20,
        'staff' => 30,
        'customers' => 40,
        'suppliers' => 45,
        'projects' => 50,
        'products' => 55,
        'packages' => 60,
        'purchases' => 70,
        'purchase_items' => 80,
        'supplier_payments' => 85,
        'transactions' => 90,
        'expenses' => 100,
        'money_ins' => 110,
        'transfers' => 120,
    ];

    return $priority[$table] ?? 500;
}

function import_prepare_row_for_insert($conn, $table, $row, $user_id, $manager_id_map, $id_maps = [])
{
    if(!is_array($row) || !import_valid_table_name($table)){
        return null;
    }

    unset($row['_replies']);

    if($table === 'users'){
        return null;
    }

    if(import_table_has_column($conn, $table, 'user_id')){
        $row['user_id'] = $user_id;
    }else{
        unset($row['user_id']);
    }

    if(import_table_has_column($conn, $table, 'admin_user_id')){
        $row['admin_user_id'] = $user_id;
    }

    if(isset($row['created_by']) && isset($manager_id_map[(int)$row['created_by']])){
        $row['created_by'] = $manager_id_map[(int)$row['created_by']];
    }

    foreach(import_restore_reference_maps() as $column => $map_table){
        if(
            array_key_exists($column, $row) &&
            isset($id_maps[$map_table][(int)$row[$column]])
        ){
            $row[$column] = $id_maps[$map_table][(int)$row[$column]];
        }
    }

    if(isset($row['id'])){
        unset($row['id']);
    }

    return $row;
}

function import_insert_row($conn, $table, $row)
{
    if(!is_array($row) || empty($row) || !import_valid_table_name($table)){
        return false;
    }

    $columns = array_keys($row);
    $values = array_values($row);
    $field_list = '`' . implode('`,`', $columns) . '`';
    $placeholders = implode(',', array_fill(0, count($values), '?'));
    $sql = "INSERT INTO `{$table}` ({$field_list}) VALUES ({$placeholders})";
    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        throw new Exception(mysqli_error($conn));
    }

    $types = str_repeat('s', count($values));
    mysqli_stmt_bind_param($stmt, $types, ...$values);

    if(!mysqli_stmt_execute($stmt)){
        throw new Exception(mysqli_stmt_error($stmt) ?: mysqli_error($conn));
    }

    return (int)mysqli_insert_id($conn);
}

if($_SERVER['REQUEST_METHOD']=='POST'){

    $password = $_POST['password'];

    $sql = "SELECT password
            FROM users
            WHERE id=?";

    $stmt = mysqli_prepare($conn,$sql);

    mysqli_stmt_bind_param(
        $stmt,
        "i",
        $user_id
    );

    mysqli_stmt_execute($stmt);

    $result =
    mysqli_stmt_get_result($stmt);

    $user =
    mysqli_fetch_assoc($result);

    if(
        !password_verify(
            $password,
            $user['password']
        )
    ){

        $message =
        "Invalid Password";

        $message_type =
        "danger";

    }else{

        if(
            isset($_FILES['backup_file']) &&
            $_FILES['backup_file']['error']==0
        ){

            $json =
            file_get_contents(
                $_FILES['backup_file']['tmp_name']
            );

            $backup =
            json_decode(
                $json,
                true
            );

            if(
                !$backup ||
                !isset($backup['data'])
            ){

                $message =
                "Invalid Backup File";

                $message_type =
                "danger";

            }else{

                mysqli_begin_transaction(
                    $conn
                );
                
                mysqli_query(
                    $conn,
                    "SET FOREIGN_KEY_CHECKS=0"
                );

                try{

                    /*
                    --------------------------------
                    Delete Existing Data
                    --------------------------------
                    */

                    $tables = [];

                    $result = mysqli_query(
                        $conn,
                        "SHOW TABLES"
                    );

                    while(
                        $row =
                        mysqli_fetch_array($result)
                    ){

                        $table = $row[0];

                        $columns = mysqli_query(
                            $conn,
                            "SHOW COLUMNS FROM `$table`"
                        );

                        $has_user_id = false;

                        while(
                            $col =
                            mysqli_fetch_assoc($columns)
                        ){

                            if(
                                $col['Field']
                                == 'user_id'
                            ){

                                $has_user_id = true;
                                break;
                            }
                        }

                        if(
                            $has_user_id
                        ){

                            $tables[] =
                            $table;
                        }
                    }

                    foreach(
                        array_reverse($tables)
                        as $table
                    ){

                        mysqli_query(
                            $conn,
                            "DELETE FROM `$table`
                             WHERE user_id={$user_id}"
                        );
                    }

                    mysqli_query(
                        $conn,
                        "DELETE FROM users
                         WHERE owner_id={$user_id}
                         AND role='manager'"
                    );

                    if(import_table_has_column($conn, 'pricing_plan_requests', 'admin_user_id')){
                        mysqli_query(
                            $conn,
                            "DELETE FROM pricing_plan_requests
                             WHERE admin_user_id={$user_id}"
                        );
                    }

                    if(import_table_has_column($conn, 'support_tickets', 'admin_user_id')){
                        mysqli_query(
                            $conn,
                            "DELETE FROM support_ticket_replies
                             WHERE ticket_id IN (
                                SELECT id
                                FROM support_tickets
                                WHERE admin_user_id={$user_id}
                             )"
                        );

                        mysqli_query(
                            $conn,
                            "DELETE FROM support_tickets
                             WHERE admin_user_id={$user_id}"
                        );
                    }

                    /*
                    --------------------------------
                    Restore Company Profile
                    --------------------------------
                    */

                    $company_profile = $backup['data']['company_admin_profile'] ?? null;

                    if(is_array($company_profile) && !empty($company_profile)){
                        $allowed_profile_columns = [
                            'name',
                            'address',
                            'email',
                            'phone',
                            'avatar',
                            'printing_option',
                            'currency_code',
                            'timezone_name',
                            'date_format',
                            'printing_custom_width',
                            'printing_custom_height',
                            'printing_custom_top_margin',
                            'product_expiry_option',
                            'print_invoice_notes',
                            'print_invoice_created_by',
                            'sms_api_token',
                            'company_seal_file',
                            'paid_seal_file',
                            'print_company_seal',
                            'print_paid_seal',
                            'print_company_logo',
                            'printing_general_top_margin',
                            'print_general_top_margin',
                            'print_company_profile',
                        ];

                        $profile_fields = [];
                        $profile_values = [];

                        foreach($allowed_profile_columns as $column){
                            if(array_key_exists($column, $company_profile) && import_table_has_column($conn, 'users', $column)){
                                $profile_fields[] = "`{$column}`=?";
                                $profile_values[] = $company_profile[$column];
                            }
                        }

                        if(!empty($profile_fields)){
                            $profile_values[] = $user_id;
                            $sql = "UPDATE users
                                    SET " . implode(', ', $profile_fields) . "
                                    WHERE id=?";
                            $stmt = mysqli_prepare($conn, $sql);
                            $types = str_repeat('s', count($profile_values) - 1) . 'i';
                            mysqli_stmt_bind_param($stmt, $types, ...$profile_values);

                            if(!mysqli_stmt_execute($stmt)){
                                throw new Exception(mysqli_stmt_error($stmt) ?: mysqli_error($conn));
                            }
                        }
                    }

                    /*
                    --------------------------------
                    Restore Assistants / Managers
                    --------------------------------
                    */

                    $manager_id_map = [];
                    $manager_rows = $backup['data']['users_manager_agents'] ?? [];

                    foreach($manager_rows as $manager_row){
                        $old_manager_id = (int)($manager_row['_old_id'] ?? 0);
                        unset($manager_row['_old_id']);
                        unset($manager_row['id']);

                        $manager_row['owner_id'] = $user_id;
                        $manager_row['role'] = 'manager';

                        $base_username = trim((string)($manager_row['username'] ?? ''));

                        if($base_username === ''){
                            $base_username = 'agent_' . $user_id . '_' . time();
                        }

                        $username = $base_username;
                        $suffix = 1;

                        while(true){
                            $check_stmt = mysqli_prepare(
                                $conn,
                                "SELECT id
                                 FROM users
                                 WHERE username=?
                                 LIMIT 1"
                            );

                            mysqli_stmt_bind_param($check_stmt, "s", $username);
                            mysqli_stmt_execute($check_stmt);
                            $check_result = mysqli_stmt_get_result($check_stmt);

                            if(mysqli_num_rows($check_result) === 0){
                                break;
                            }

                            $username = $base_username . '_' . $user_id . '_' . $suffix;
                            $suffix++;
                        }

                        $manager_row['username'] = $username;

                        $columns = array_keys($manager_row);
                        $values = array_values($manager_row);

                        $field_list = '`' . implode('`,`', $columns) . '`';
                        $placeholders = implode(',', array_fill(0, count($values), '?'));

                        $sql = "INSERT INTO users
                                ($field_list)
                                VALUES
                                ($placeholders)";

                        $stmt = mysqli_prepare($conn, $sql);
                        $types = str_repeat('s', count($values));

                        mysqli_stmt_bind_param($stmt, $types, ...$values);
                        mysqli_stmt_execute($stmt);

                        if($old_manager_id > 0){
                            $manager_id_map[$old_manager_id] = mysqli_insert_id($conn);
                        }
                    }

                    /*
                    --------------------------------
                    Restore Data
                    --------------------------------
                    */

                    $restore_data = $backup['data'];
                    uksort($restore_data, function ($left, $right) {
                        $left_priority = import_restore_priority($left);
                        $right_priority = import_restore_priority($right);

                        if($left_priority === $right_priority){
                            return strcmp((string)$left, (string)$right);
                        }

                        return $left_priority <=> $right_priority;
                    });

                    $id_maps = [];

                    foreach(
                        $restore_data
                        as $table => $rows
                    ){
                        if(
                            $table === 'users_manager_agents' ||
                            $table === 'company_admin_profile' ||
                            $table === 'pricing_plan_requests' ||
                            $table === 'support_tickets'
                        ){
                            continue;
                        }

                        if(!import_valid_table_name($table)){
                            continue;
                        }

                        foreach(
                            $rows
                            as $row
                        ){
                            $old_id = (int)($row['id'] ?? 0);
                            $prepared_row = import_prepare_row_for_insert(
                                $conn,
                                $table,
                                $row,
                                $user_id,
                                $manager_id_map,
                                $id_maps
                            );

                            if($prepared_row === null || empty($prepared_row)){
                                continue;
                            }

                            $new_id = import_insert_row($conn, $table, $prepared_row);

                            if($old_id > 0 && $new_id > 0){
                                if(!isset($id_maps[$table])){
                                    $id_maps[$table] = [];
                                }

                                $id_maps[$table][$old_id] = $new_id;
                            }
                        }
                    }

                    /*
                    --------------------------------
                    Restore Pricing Requests
                    --------------------------------
                    */

                    $pricing_rows = $backup['data']['pricing_plan_requests'] ?? [];

                    foreach($pricing_rows as $row){
                        $prepared_row = import_prepare_row_for_insert(
                            $conn,
                            'pricing_plan_requests',
                            $row,
                            $user_id,
                            $manager_id_map,
                            $id_maps
                        );

                        if($prepared_row === null || empty($prepared_row)){
                            continue;
                        }

                        import_insert_row($conn, 'pricing_plan_requests', $prepared_row);
                    }

                    /*
                    --------------------------------
                    Restore Support Tickets
                    --------------------------------
                    */

                    $ticket_rows = $backup['data']['support_tickets'] ?? [];

                    foreach($ticket_rows as $ticket_row){
                        $ticket_replies = is_array($ticket_row['_replies'] ?? null)
                            ? $ticket_row['_replies']
                            : [];

                        $prepared_ticket = import_prepare_row_for_insert(
                            $conn,
                            'support_tickets',
                            $ticket_row,
                            $user_id,
                            $manager_id_map,
                            $id_maps
                        );

                        if($prepared_ticket === null || empty($prepared_ticket)){
                            continue;
                        }

                        import_insert_row($conn, 'support_tickets', $prepared_ticket);
                        $new_ticket_id = mysqli_insert_id($conn);

                        foreach($ticket_replies as $reply_row){
                            if(isset($reply_row['id'])){
                                unset($reply_row['id']);
                            }

                            $reply_row['ticket_id'] = $new_ticket_id;
                            import_insert_row($conn, 'support_ticket_replies', $reply_row);
                        }
                    }

                    mysqli_query(
                        $conn,
                        "SET FOREIGN_KEY_CHECKS=1"
                    );

                    mysqli_commit(
                        $conn
                    );

                    $message =
                    "Backup Restored Successfully";

                    $message_type =
                    "success";

                }catch(Exception $e){

                mysqli_query(
                    $conn,
                    "SET FOREIGN_KEY_CHECKS=1"
                );

                mysqli_rollback(
                    $conn
                );

                $message =
                $e->getMessage();

                $message_type =
                "danger";
            }
            }
        }
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">
            Import Data
        </h3>

    </div>

    <div class="card-body">

        <?php if($message){ ?>

            <div class="alert alert-<?= $message_type; ?>">

                <?= htmlspecialchars($message); ?>

            </div>

        <?php } ?>

        <div class="alert alert-warning">

            <strong>Warning:</strong>

            Existing data will be deleted
            before import.

        </div>

        <form
            method="post"
            enctype="multipart/form-data">

            <div class="form-group">

                <label>
                    Backup File
                </label>

                <input
                    type="file"
                    name="backup_file"
                    class="form-control"
                    required>

            </div>

            <div class="form-group">

                <label>
                    Current Password
                </label>

                <input
                    type="password"
                    name="password"
                    class="form-control"
                    required>

            </div>

            <button
                type="submit"
                class="btn btn-primary">

                Import Backup

            </button>

        </form>

    </div>

</div>

<?php
require_once '../includes/footer.php';
?>
