<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/super_admin_config.php';
require_once '../includes/branding_helper.php';
require_once '../includes/company_backup_helper.php';

$user_id = $_SESSION['user_id'];

$message = '';
$message_type = '';

ensure_company_delete_backups_table($conn);

function delete_data_valid_table_name($table)
{
    return preg_match('/^[A-Za-z0-9_]+$/', $table) === 1;
}

function delete_data_table_has_column($conn, $table, $column)
{
    if(!delete_data_valid_table_name($table) || !delete_data_valid_table_name($column)){
        return false;
    }

    $column = mysqli_real_escape_string($conn, $column);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");

    return $result && mysqli_num_rows($result) > 0;
}

function delete_data_by_ids($conn, $table, $column, $ids)
{
    if(!delete_data_valid_table_name($table) || !delete_data_valid_table_name($column)){
        return false;
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

    if(empty($ids) || !delete_data_table_has_column($conn, $table, $column)){
        return true;
    }

    $id_list = implode(',', $ids);

    return mysqli_query($conn, "DELETE FROM `{$table}` WHERE `{$column}` IN ({$id_list})");
}

function delete_data_by_value($conn, $table, $column, $value)
{
    if(!delete_data_valid_table_name($table) || !delete_data_valid_table_name($column)){
        return false;
    }

    if(!delete_data_table_has_column($conn, $table, $column)){
        return true;
    }

    $value = (int)$value;

    return mysqli_query($conn, "DELETE FROM `{$table}` WHERE `{$column}`={$value}");
}

function delete_data_manager_avatar_cleanup_list($conn, $owner_id)
{
    $owner_id = (int)$owner_id;

    if($owner_id <= 0){
        return [];
    }

    $result = mysqli_query(
        $conn,
        "SELECT avatar
         FROM users
         WHERE owner_id={$owner_id}
         AND role='manager'"
    );

    $protected = [
        'you2biz.png',
        basename((string)SUPER_ADMIN_PROFILE_AVATAR),
        basename((string)branding_company_default_avatar_filename($conn)),
    ];

    $files = [];

    while($result && $row = mysqli_fetch_assoc($result)){
        $avatar = basename(trim((string)($row['avatar'] ?? '')));

        if($avatar === '' || in_array($avatar, $protected, true)){
            continue;
        }

        $files[] = $avatar;
    }

    return array_values(array_unique($files));
}

function delete_company_business_data($conn, $company_id)
{
    $company_id = (int)$company_id;

    if($company_id <= 0){
        return [false, 'Invalid account selected.'];
    }

    $user_ids = [$company_id];
    $manager_result = mysqli_query(
        $conn,
        "SELECT id
         FROM users
         WHERE owner_id={$company_id}
         AND role='manager'"
    );

    while($manager_result && $manager = mysqli_fetch_assoc($manager_result)){
        $user_ids[] = (int)$manager['id'];
    }

    $avatar_files = delete_data_manager_avatar_cleanup_list($conn, $company_id);

    mysqli_begin_transaction($conn);

    try{
        $child_deletes = [
            "DELETE FROM invoice_item_allocations
             WHERE invoice_item_id IN (
                SELECT id
                FROM invoice_items
                WHERE invoice_id IN (SELECT id FROM invoices WHERE user_id={$company_id})
             )",
            "DELETE FROM invoice_charges
             WHERE invoice_id IN (SELECT id FROM invoices WHERE user_id={$company_id})",
            "DELETE FROM invoice_items
             WHERE invoice_id IN (SELECT id FROM invoices WHERE user_id={$company_id})",
            "DELETE FROM purchase_items
             WHERE purchase_id IN (SELECT id FROM purchases WHERE user_id={$company_id})",
            "DELETE FROM support_ticket_replies
             WHERE ticket_id IN (
                SELECT id
                FROM support_tickets
                WHERE admin_user_id={$company_id}
             )",
        ];

        foreach($child_deletes as $sql){
            if(!mysqli_query($conn, $sql)){
                throw new Exception(mysqli_error($conn));
            }
        }

        $tables_result = mysqli_query($conn, "SHOW TABLES");

        while($tables_result && $table_row = mysqli_fetch_row($tables_result)){
            $table = $table_row[0];

            if($table === 'users' || !delete_data_valid_table_name($table)){
                continue;
            }

            if(delete_data_table_has_column($conn, $table, 'user_id')){
                if(!delete_data_by_ids($conn, $table, 'user_id', $user_ids)){
                    throw new Exception(mysqli_error($conn));
                }
            }
        }

        foreach(['pricing_plan_requests', 'support_tickets'] as $table){
            if(!delete_data_by_value($conn, $table, 'admin_user_id', $company_id)){
                throw new Exception(mysqli_error($conn));
            }
        }

        if(!mysqli_query($conn, "DELETE FROM users WHERE owner_id={$company_id} AND role='manager'")){
            throw new Exception(mysqli_error($conn));
        }

        mysqli_commit($conn);

        $avatar_dir = branding_avatar_upload_dir_path();

        foreach($avatar_files as $avatar_file){
            $avatar_path = $avatar_dir . '/' . basename($avatar_file);

            if(is_file($avatar_path)){
                @unlink($avatar_path);
            }
        }

        return [true, 'All company data deleted successfully.'];
    }catch(Exception $e){
        mysqli_rollback($conn);
        return [false, 'Delete failed: ' . $e->getMessage()];
    }
}

if($_SERVER['REQUEST_METHOD']=='POST' && !is_super_admin_user()){

    $password = $_POST['password'] ?? '';

    if(!isset($_POST['confirm'])){

        $message = "Please confirm the action.";
        $message_type = "danger";

    }else{

        $password_valid = false;

        if(
            is_super_admin_user()
        ){

            $password_valid =
            password_verify(
                $password,
                SUPER_ADMIN_PASSWORD_HASH
            );

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

            if(
                $user
                && isset($user['password'])
            ){

                $password_valid =
                password_verify(
                    $password,
                    $user['password']
                );
            }
        }

        if(
            !$password_valid
        ){

            $message = "Invalid Password";
            $message_type = "danger";

        }else{
            [$backup_saved, $backup_error, $backup_info] = company_backup_create_and_store(
                $conn,
                $user_id,
                'delete_all_data',
                (int)($_SESSION['login_user_id'] ?? $user_id),
                (string)($_SESSION['user_role'] ?? 'admin'),
                false
            );

            if(!$backup_saved){
                $message = "Backup could not be created. Delete was stopped. " . $backup_error;
                $message_type = "danger";
            }else{
                [$deleted, $delete_message] = delete_company_business_data($conn, $user_id);
                $message = $delete_message;
                $message_type = $deleted ? "success" : "danger";
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
            Delete All Data
        </h3>

    </div>

    <div class="card-body">

        <?php if($message){ ?>

            <div class="alert alert-<?= $message_type; ?>">

                <?= htmlspecialchars($message); ?>

            </div>

        <?php } ?>

        <div class="alert alert-warning">

            <strong>WARNING!</strong>

            <br><br>

            This will permanently delete:

            <ul class="mb-0">

                <li>Sales, invoices and invoice charges</li>
                <li>Purchases and stock data</li>
                <li>Wallets</li>
                <li>Categories</li>
                <li>Products</li>
                <li>Customers and due history</li>
                <li>Suppliers and supplier payments</li>
                <li>Money In</li>
                <li>Expenses</li>
                <li>Transfers</li>
                <li>Transactions</li>
                <li>SMS history and support tickets</li>
                <li>Assistants / Managers</li>

            </ul>

            <br>

            This action cannot be undone.

        </div>

        <?php if(is_super_admin_user()){ ?>

            <div class="alert alert-secondary">
                This option is inactive for Super Admin.
            </div>

        <?php } ?>

        <form method="post">

            <div class="form-group">

                <label>
                    Current Password
                </label>

                <input
                    type="password"
                    name="password"
                    class="form-control"
                    <?= is_super_admin_user() ? 'disabled' : ''; ?>
                    required>

            </div>

            <div class="form-group">

                <div class="custom-control custom-checkbox">

                    <input
                        type="checkbox"
                        name="confirm"
                        value="1"
                        class="custom-control-input"
                        id="confirmDelete"
                        <?= is_super_admin_user() ? 'disabled' : ''; ?>>

                    <label
                        class="custom-control-label"
                        for="confirmDelete">

                        I understand this action cannot be undone.

                    </label>

                </div>

            </div>

            <button
                type="submit"
                class="btn btn-danger"
                <?= is_super_admin_user() ? 'disabled' : ''; ?>>

                <i class="fas fa-trash"></i>

                Delete All Data

            </button>

        </form>

    </div>

</div>

<?php
require_once '../includes/footer.php';
?>
