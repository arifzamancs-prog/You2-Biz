<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/super_admin_config.php';
require_once '../includes/company_backup_helper.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
$target_company_name = '';

if(is_super_admin_user()){
    $target_company_id = (int)($_POST['company_id'] ?? ($_GET['company_id'] ?? 0));

    $company_stmt = mysqli_prepare($conn, "SELECT id, name FROM users WHERE id=? AND role='admin' LIMIT 1");
    mysqli_stmt_bind_param($company_stmt, 'i', $target_company_id);
    mysqli_stmt_execute($company_stmt);
    $company = mysqli_fetch_assoc(mysqli_stmt_get_result($company_stmt));

    if(!$company){
        die('Invalid company selected.');
    }

    $user_id = $target_company_id;
    $target_company_name = (string)$company['name'];
}

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
        'booking_invoice_id' => 'booking_invoices',
        'branch_id' => 'branches',
        'from_branch_id' => 'branches',
        'to_branch_id' => 'branches',
        'brand_id' => 'branch_brands',
        'payment_wallet_id' => 'wallets',
        'receive_wallet_id' => 'wallets',
        'paid_wallet_id' => 'wallets',
        'paid_ledger_id' => 'staff_ledger_entries',
        'source_batch_id' => 'stock_batches',
        'destination_batch_id' => 'stock_batches',
        'distribution_id' => 'stock_distributions',
        'damage_return_id' => 'stock_damage_returns',
        'completed_invoice_id' => 'invoices',
        'restaurant_table_id' => 'restaurant_tables',
        'vehicle_type_id' => 'parking_vehicle_types',
        'rate_plan_id' => 'parking_rate_plans',
        'zone_id' => 'parking_zones',
        'slot_id' => 'parking_slots',
        'entry_gate_id' => 'parking_gates',
        'exit_gate_id' => 'parking_gates',
        'gate_id' => 'parking_gates',
        'ticket_id' => 'parking_tickets',
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
        'invoice_id' => 'invoices',
        'invoice_item_id' => 'invoice_items',
        'charge_type_id' => 'invoice_charge_types',
        'stock_batch_id' => 'stock_batches',
        'opening_due_id' => 'customer_opening_dues',
    ];
}

function import_restore_priority($table)
{
    static $priority = [
        'branch_brands' => 1,
        'branches' => 2,
        'parking_settings' => 3,
        'parking_vehicle_types' => 4,
        'parking_rate_plans' => 5,
        'parking_gates' => 6,
        'parking_zones' => 7,
        'parking_slots' => 8,
        'parking_gate_staff_assignments' => 9,
        'product_categories' => 3,
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
        'stock_distributions' => 81,
        'stock_batches' => 82,
        'stock_distribution_items' => 91,
        'stock_damage_returns' => 92,
        'stock_damage_return_items' => 93,
        'invoice_charge_types' => 83,
        'invoices' => 84,
        'invoice_items' => 86,
        'invoice_charges' => 88,
        'invoice_item_allocations' => 90,
        'supplier_payments' => 95,
        'transactions' => 100,
        'expenses' => 110,
        'money_ins' => 120,
        'transfers' => 130,
        'parking_tickets' => 140,
        'parking_payments' => 145,
        'parking_gate_logs' => 150,
        'parking_shifts' => 155,
    ];

    return $priority[$table] ?? 500;
}

// Preflight checks run before any company data is deleted.
function import_preflight($conn, $backup)
{
    if(!isset($backup['data']) || !is_array($backup['data']) ||
       ($backup['backup_version'] ?? '') !== '1.3'){
        throw new Exception('Unsupported backup format/version. Expected You2 Biz 1.3.');
    }
    if(!isset($backup['row_counts']) || !is_array($backup['row_counts'])){
        throw new Exception('Missing row_counts. Import cancelled.');
    }
    $required = ['branches','wallets','products','product_categories','customers',
        'suppliers','invoices','invoice_items','invoice_item_allocations',
        'purchases','purchase_items','stock_batches','stock_distributions',
        'stock_distribution_items','transactions','expenses','money_ins','transfers'];
    foreach($required as $table){
        if(!isset($backup['data'][$table]) || !is_array($backup['data'][$table])){
            throw new Exception('Missing required backup section: ' . $table);
        }
    }
    foreach($backup['data'] as $table => $rows){
        if($table === 'company_admin_profile') continue;
        if(!import_valid_table_name($table) || !is_array($rows) ||
           !array_key_exists($table, $backup['row_counts']) ||
           count($rows) !== (int)$backup['row_counts'][$table]){
            throw new Exception('Invalid table/row count: ' . $table);
        }
        if($table === 'users_manager_agents') continue;
        $allowed_child_tables = ['purchase_items','invoice_items','invoice_charges',
            'invoice_item_allocations','booking_invoice_charges',
            'stock_distribution_items','stock_damage_return_items'];
        if(!in_array($table, $allowed_child_tables, true) &&
           !import_table_has_column($conn, $table, 'company_id') &&
           !import_table_has_column($conn, $table, 'user_id') &&
           !in_array($table, ['pricing_plan_requests','support_tickets'], true)){
            throw new Exception('Table has no company ownership: ' . $table);
        }
        $check = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
        if(!$check || mysqli_num_rows($check) !== 1){
            throw new Exception('Target table missing: ' . $table);
        }
        foreach($rows as $row){
            if(!is_array($row)) throw new Exception('Invalid row in ' . $table);
            foreach(array_keys($row) as $column){
                if($column === '_replies') continue;
                if(!import_valid_table_name($column) ||
                   !import_table_has_column($conn, $table, $column)){
                    throw new Exception('Unknown column ' . $table . '.' . $column);
                }
            }
        }
    }
    // Rollback safety requires transactional tables; MyISAM changes cannot roll back.
    $engines = mysqli_query($conn, "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'");
    while($t = mysqli_fetch_assoc($engines)){
        if(strcasecmp((string)$t['ENGINE'], 'InnoDB') !== 0){
            throw new Exception('Non-InnoDB table detected: ' . $t['TABLE_NAME'] . '. Import cancelled.');
        }
    }
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
        if($table !== 'parking_gate_staff_assignments') $row['user_id'] = $user_id;
    }else{
        unset($row['user_id']);
    }

    if(import_table_has_column($conn, $table, 'admin_user_id')){
        $row['admin_user_id'] = $user_id;
    }

    if(import_table_has_column($conn, $table, 'company_id')){
        $row['company_id'] = $user_id;
    }

    // Parking assignment rows store the authenticated user in user_id, not
    // the company owner. Keep that relation when restoring company-owned data.
    if($table === 'parking_gate_staff_assignments' && isset($row['user_id'])){
        $row['user_id'] = $manager_id_map[(int)$row['user_id']] ?? (int)$row['user_id'];
    }

    if(isset($row['created_by']) && isset($manager_id_map[(int)$row['created_by']])){
        $row['created_by'] = $manager_id_map[(int)$row['created_by']];
    }

    foreach(import_restore_reference_maps() as $column => $map_table){
        if($column === 'category_id' && $table === 'products') $map_table = 'product_categories';
        if(
            array_key_exists($column, $row) &&
            isset($id_maps[$map_table][(int)$row[$column]])
        ){
            $row[$column] = $id_maps[$map_table][(int)$row[$column]];
        }
    }

    // Stock batches use a polymorphic source reference, so map it explicitly.
    if($table === 'stock_batches' && isset($row['source_id'], $row['source_type'])){
        $source_type = strtolower(trim((string)$row['source_type']));
        $source_table = ['purchase'=>'purchases', 'invoice'=>'invoices', 'sales_return'=>'invoice_items', 'product_opening'=>'products', 'opening'=>'products', 'distribution'=>'stock_distributions'][$source_type] ?? null;

        if($source_table && isset($id_maps[$source_table][(int)$row['source_id']])){
            $row['source_id'] = $id_maps[$source_table][(int)$row['source_id']];
        }
    }

    foreach(['created_by_user_id', 'login_user_id', 'reference_user_id'] as $actor_column){
        if(isset($row[$actor_column], $manager_id_map[(int)$row[$actor_column]])){
            $row[$actor_column] = $manager_id_map[(int)$row[$actor_column]];
        }
    }
    foreach(['entry_operator_id', 'exit_operator_id', 'received_by', 'operator_id'] as $operator_column){
        if(isset($row[$operator_column], $manager_id_map[(int)$row[$operator_column]])){
            $row[$operator_column] = $manager_id_map[(int)$row[$operator_column]];
        }
    }
    if($table === 'transactions'){
        $reference_table = ['sales_invoice'=>'invoices', 'invoice'=>'invoices', 'invoice_payment'=>'customer_payments', 'purchase'=>'purchases', 'supplier_payment'=>'supplier_payments', 'expense'=>'expenses', 'money_in'=>'money_ins', 'transfer_in'=>'transfers', 'transfer_out'=>'transfers', 'transfer'=>'transfers'][$row['transaction_type'] ?? ''] ?? null;
        if(in_array($row['transaction_type'] ?? '', ['invoice_income','invoice_expense'], true)) $reference_table = 'booking_invoices';
        if(($row['transaction_type'] ?? '') === 'money_in'
            && (preg_match('/parking|lost parking/i', (string)($row['note'] ?? '')) || str_starts_with((string)($row['txn_no'] ?? ''), 'PARK'))
        ) $reference_table = 'parking_payments';
        if($reference_table && isset($id_maps[$reference_table][(int)($row['reference_id'] ?? 0)])){
            $row['reference_id'] = $id_maps[$reference_table][(int)$row['reference_id']];
        }
    }
    if($table === 'expenses'){
        $source_table = ['purchase_payment'=>'purchases', 'supplier_payment'=>'supplier_payments'][$row['source_type'] ?? ''] ?? null;
        if($source_table && isset($id_maps[$source_table][(int)($row['source_id'] ?? 0)])){
            $row['source_id'] = $id_maps[$source_table][(int)$row['source_id']];
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

    if(is_super_admin_user()){
        $password_valid = password_verify($password, SUPER_ADMIN_PASSWORD_HASH);
    }else{
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

        $password_valid = $user && password_verify($password, $user['password']);
    }

    if(!$password_valid){

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

                mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
                try {
                    import_preflight($conn, $backup);
                } catch (Throwable $e) {
                    $message = $e->getMessage();
                    $message_type = 'danger';
                }
                if($message_type !== 'danger'){
                mysqli_begin_transaction(
                    $conn
                );
                
                mysqli_query(
                    $conn,
                    "SET FOREIGN_KEY_CHECKS=0"
                );

                try{
                    foreach(($backup['row_counts'] ?? []) as $count_table => $expected_count){
                        if(!isset($backup['data'][$count_table]) || !is_array($backup['data'][$count_table])
                            || count($backup['data'][$count_table]) !== (int)$expected_count){
                            throw new Exception('Backup row count mismatch: ' . $count_table . '. No data was replaced.');
                        }
                    }
                    // Reject partial financial backups before deleting anything.
                    foreach(['wallets','invoices','transactions','expenses','money_ins','transfers'] as $required_table){
                        if(!isset($backup['data'][$required_table]) || !is_array($backup['data'][$required_table])){
                            throw new Exception('Incomplete backup: missing ' . $required_table . '. No data was replaced.');
                        }
                    }

                    /*
                    --------------------------------
                    Delete Existing Data
                    --------------------------------
                    */

                    // Remove child rows first: these tables are linked to
                    // company-owned parents and do not have their own user_id.
                    mysqli_query($conn, "DELETE d FROM booking_invoice_charges d INNER JOIN booking_invoices p ON p.id=d.booking_invoice_id WHERE p.user_id={$user_id}");
                    foreach(['stock_distribution_items'=>['stock_distributions','distribution_id'], 'stock_damage_return_items'=>['stock_damage_returns','damage_return_id']] as $detail_table => [$parent_table, $parent_key]){
                        mysqli_query($conn, "DELETE d FROM `{$detail_table}` d INNER JOIN `{$parent_table}` p ON p.id=d.`{$parent_key}` WHERE p.user_id={$user_id}");
                    }
                    mysqli_query(
                        $conn,
                        "DELETE invoice_item_allocations
                         FROM invoice_item_allocations
                         INNER JOIN invoice_items ON invoice_items.id = invoice_item_allocations.invoice_item_id
                         INNER JOIN invoices ON invoices.id = invoice_items.invoice_id
                         WHERE invoices.user_id={$user_id}"
                    );
                    mysqli_query(
                        $conn,
                        "DELETE invoice_charges
                         FROM invoice_charges
                         INNER JOIN invoices ON invoices.id = invoice_charges.invoice_id
                         WHERE invoices.user_id={$user_id}"
                    );
                    mysqli_query(
                        $conn,
                        "DELETE invoice_items
                         FROM invoice_items
                         INNER JOIN invoices ON invoices.id = invoice_items.invoice_id
                         WHERE invoices.user_id={$user_id}"
                    );
                    mysqli_query(
                        $conn,
                        "DELETE purchase_items
                         FROM purchase_items
                         INNER JOIN purchases ON purchases.id = purchase_items.purchase_id
                         WHERE purchases.user_id={$user_id}"
                    );

                    // Delete only rows belonging to the selected company.
                    // Prefer company_id, matching the export helper's ownership rule.
                    $tables = [];
                    $result = mysqli_query($conn, "SHOW TABLES");
                    while($r = mysqli_fetch_row($result)){
                        $table = $r[0];
                        if(in_array($table, ['users','company_delete_backups','support_tickets',
                            'support_ticket_replies','pricing_plan_requests'], true)) continue;
                        if(import_table_has_column($conn, $table, 'company_id')){
                            $tables[$table] = 'company_id';
                        }elseif(import_table_has_column($conn, $table, 'user_id')){
                            $tables[$table] = 'user_id';
                        }
                    }
                    foreach(array_reverse($tables, true) as $table => $owner_column){
                        mysqli_query($conn, "DELETE FROM `{$table}` WHERE `{$owner_column}`={$user_id}");
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
                    $restored_rows = [];

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
                            if($new_id > 0 && array_key_exists('id', $row)){
                                $restored_rows[] = [$table, $new_id, $row];
                            }

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

                    // Resolve forward and circular references using the ORIGINAL
                    // backup values only, after every table has its new IDs.
                    foreach($restored_rows as [$restored_table, $restored_id, $original_row]){
                        $fixed = import_prepare_row_for_insert($conn, $restored_table, $original_row, $user_id, $manager_id_map, $id_maps);
                        $fields = []; $values = [];
                        foreach($fixed as $column => $value){
                            if($column !== 'user_id' && str_ends_with($column, '_id')){
                                $fields[] = "`{$column}`=?"; $values[] = $value;
                            }
                        }
                        if($fields){
                            $values[] = $restored_id;
                            $repair = mysqli_prepare($conn, "UPDATE `{$restored_table}` SET " . implode(',', $fields) . ' WHERE id=?');
                            mysqli_stmt_bind_param($repair, str_repeat('s', count($values)), ...$values);
                            if(!mysqli_stmt_execute($repair)) throw new Exception(mysqli_stmt_error($repair));
                            mysqli_stmt_close($repair);
                        }
                    }
                    foreach($manager_rows as $original_manager){
                        $old_manager = (int)($original_manager['_old_id'] ?? 0);
                        $old_staff = (int)($original_manager['staff_id'] ?? 0);
                        if(isset($manager_id_map[$old_manager], $id_maps['staff'][$old_staff])){
                            $manager_fix = mysqli_prepare($conn, 'UPDATE users SET staff_id=? WHERE id=? AND owner_id=?');
                            mysqli_stmt_bind_param($manager_fix, 'iii', $id_maps['staff'][$old_staff], $manager_id_map[$old_manager], $user_id);
                            mysqli_stmt_execute($manager_fix);
                        }
                    }
                    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS=1");

                    mysqli_commit(
                        $conn
                    );
                    unset($_SESSION['selected_branch_id']);

                    $message =
                    "Backup Restored Successfully";

                    $message_type =
                    "success";

                }catch(Throwable $e){

                mysqli_rollback(
                    $conn
                );
                mysqli_query($conn, "SET FOREIGN_KEY_CHECKS=1");

                $message =
                $e->getMessage();

                $message_type =
                "danger";
            }
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

        <?php if(is_super_admin_user()){ ?>
            <div class="alert alert-info">
                Super Admin import target:
                <strong><?= htmlspecialchars($target_company_name); ?></strong>
            </div>
        <?php } ?>

        <form
            method="post"
            enctype="multipart/form-data">

            <?php if(is_super_admin_user()){ ?>
                <input type="hidden" name="company_id" value="<?= (int)$user_id; ?>">
            <?php } ?>

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
