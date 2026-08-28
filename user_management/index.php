<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/manager_access_helper.php';
require_once '../includes/staff_helper.php';

require_admin_user();
ensure_manager_access_columns($conn);
ensure_staff_table($conn);

$user_id = (int)$_SESSION['user_id'];
$message = '';
$message_type = '';
$edit_manager = null;

function user_management_redirect($query = '')
{
    $location = 'index.php';

    if($query !== ''){
        $location .= '?' . ltrim((string)$query, '?');
    }

    header('Location: ' . $location);
    exit;
}

function user_management_flash_and_redirect($message, $type = 'success', $query = '')
{
    $_SESSION['user_management_message'] = (string)$message;
    $_SESSION['user_management_message_type'] = (string)$type;
    user_management_redirect($query);
}

function normalize_agent_username_base($username)
{
    $username = strtolower(trim((string)$username));

    if (preg_match('/^(.+)@\d+$/', $username, $matches)) {
        $username = $matches[1];
    }

    return preg_replace('/[^a-z0-9._-]/', '', $username);
}

function build_agent_login_username($username, $owner_id)
{
    return normalize_agent_username_base($username) . '@' . (int)$owner_id;
}

function display_agent_username_base($username, $owner_id)
{
    $username = trim((string)$username);
    $suffix = '@' . (int)$owner_id;

    if (
        $suffix !== '@0'
        && substr($username, -strlen($suffix)) === $suffix
    ) {
        return substr($username, 0, -strlen($suffix));
    }

    return $username;
}

function user_management_subscription_support_message()
{
    if(function_exists('subscription_support_message')){
        return subscription_support_message();
    }

    return 'Please call +8801977592783 for subscription.';
}

function user_management_agent_placeholder_email($username)
{
    $local = preg_replace('/[^a-z0-9._-]/', '.', strtolower((string)$username));
    $local = trim($local, '.');

    if($local === ''){
        $local = 'agent';
    }

    return substr($local, 0, 50) . '@agent.local';
}

function user_management_agent_placeholder_phone($username)
{
    $clean = preg_replace('/[^a-z0-9]/', '', strtolower((string)$username));

    if($clean === ''){
        $clean = 'agent';
    }

    return 'AG' . substr($clean, 0, 18);
}

function user_management_table_has_column($conn, $table, $column)
{
    if(!preg_match('/^[A-Za-z0-9_]+$/', (string)$table) || !preg_match('/^[A-Za-z0-9_]+$/', (string)$column)){
        return false;
    }

    $escaped = mysqli_real_escape_string($conn, (string)$column);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE '{$escaped}'");

    return $result && mysqli_num_rows($result) > 0;
}

function user_management_manager_has_transactions($conn, $manager_id)
{
    $manager_id = (int)$manager_id;

    if($manager_id <= 0){
        return false;
    }

    $checks = [
        ['table' => 'invoices', 'column' => 'created_by_user_id'],
        ['table' => 'money_ins', 'column' => 'created_by'],
        ['table' => 'money_ins', 'column' => 'approved_by'],
        ['table' => 'expenses', 'column' => 'created_by'],
        ['table' => 'expenses', 'column' => 'approved_by'],
        ['table' => 'transfers', 'column' => 'created_by'],
        ['table' => 'transfers', 'column' => 'approved_by'],
    ];

    foreach($checks as $check){
        if(!user_management_table_has_column($conn, $check['table'], $check['column'])){
            continue;
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT id
             FROM `{$check['table']}`
             WHERE `{$check['column']}`=?
             LIMIT 1"
        );

        if(!$stmt){
            continue;
        }

        mysqli_stmt_bind_param($stmt, "i", $manager_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if($result && mysqli_num_rows($result) > 0){
            mysqli_stmt_close($stmt);
            return true;
        }

        mysqli_stmt_close($stmt);
    }

    return false;
}

if(isset($_GET['edit'])){
    $edit_id = (int)$_GET['edit'];

    $edit_sql = "SELECT id,
                        name,
                        username,
                         manager_type,
                         staff_id,
                         access_permissions
                 FROM users
                 WHERE id=?
                 AND owner_id=?
                 AND role='manager'
                 LIMIT 1";

    $edit_stmt = mysqli_prepare($conn, $edit_sql);
    mysqli_stmt_bind_param($edit_stmt, "ii", $edit_id, $user_id);
    mysqli_stmt_execute($edit_stmt);
    $edit_result = mysqli_stmt_get_result($edit_stmt);
    $edit_manager = $edit_result ? mysqli_fetch_assoc($edit_result) : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete_manager') {

    $manager_id = (int)($_POST['manager_id'] ?? 0);
    $staff_id = (int)($_POST['staff_id'] ?? 0);
    $name = '';
    $manager_type = 'agent';
    $access_permissions = normalize_manager_permissions($_POST['access_permissions'] ?? []);
    $access_permissions_json = json_encode($access_permissions);
    $sensitive_permissions = ['dashboard', 'projects', 'admin'];
    $requires_admin_password = count(array_intersect($sensitive_permissions, $access_permissions)) > 0;
    $admin_password = (string)($_POST['admin_password'] ?? '');
    $username_base = normalize_agent_username_base($_POST['username'] ?? '');
    $username = build_agent_login_username($username_base, $user_id);
    $password = $_POST['password'] ?? '';

    $limit_sql = "SELECT
                    max_managers,
                    (
                        SELECT COUNT(*)
                        FROM users managers
                        WHERE managers.owner_id=users.id
                        AND managers.role='manager'
                    ) AS manager_count
                  FROM users
                  WHERE id=?";

    $limit_stmt = mysqli_prepare($conn, $limit_sql);
    $limit = null;
    $limit_error = '';

    if($limit_stmt){
        mysqli_stmt_bind_param($limit_stmt, "i", $user_id);
        mysqli_stmt_execute($limit_stmt);
        $limit_result = mysqli_stmt_get_result($limit_stmt);
        $limit = $limit_result ? mysqli_fetch_assoc($limit_result) : null;
    }else{
        $limit_error = "Subscription limit could not be checked. " . user_management_subscription_support_message();
    }

    $selected_staff = null;
    if($staff_id > 0){
        $staff_stmt = mysqli_prepare($conn, "SELECT id,name FROM staff WHERE id=? AND user_id=? AND status='active' LIMIT 1");
        mysqli_stmt_bind_param($staff_stmt, 'ii', $staff_id, $user_id);
        mysqli_stmt_execute($staff_stmt);
        $selected_staff = mysqli_fetch_assoc(mysqli_stmt_get_result($staff_stmt));
        if($selected_staff){ $name = trim((string)$selected_staff['name']); }
    }

    if ($requires_admin_password) {
        $admin_password_stmt = mysqli_prepare($conn, "SELECT password FROM users WHERE id=? AND role='admin' LIMIT 1");
        mysqli_stmt_bind_param($admin_password_stmt, 'i', $user_id);
        mysqli_stmt_execute($admin_password_stmt);
        $admin_row = mysqli_fetch_assoc(mysqli_stmt_get_result($admin_password_stmt));

        if(!$admin_row || !password_verify($admin_password, $admin_row['password'])){
            user_management_flash_and_redirect('Admin Password is required to grant Company Dashboard, Project & Package, or Admin access.', 'danger', $manager_id > 0 ? ('edit=' . $manager_id) : '');
        }
    }

    if ($limit_error !== '') {

        user_management_flash_and_redirect($limit_error, 'danger', $manager_id > 0 ? ('edit=' . $manager_id) : '');

    } elseif (!$selected_staff || $name === '' || $username_base === '' || ($manager_id === 0 && $password === '')) {
 
        user_management_flash_and_redirect('Select a staff member, then enter username and password.', 'danger', $manager_id > 0 ? ('edit=' . $manager_id) : '');

    } elseif ($manager_id === 0 && $limit && (int)$limit['manager_count'] >= (int)$limit['max_managers']) {

        user_management_flash_and_redirect(
            "Manager limit reached for your subscription. " . user_management_subscription_support_message(),
            'danger'
        );

    } elseif ($password !== '' && strlen($password) < 6) {

        user_management_flash_and_redirect('Password must be at least 6 characters.', 'danger', $manager_id > 0 ? ('edit=' . $manager_id) : '');

    } else {

        $check_sql = "SELECT id
                      FROM users
                      WHERE username=?
                      AND id<>?
                      LIMIT 1";

        $check_stmt = mysqli_prepare($conn, $check_sql);
        $check_result = false;

        if($check_stmt){
            mysqli_stmt_bind_param(
                $check_stmt,
                "si",
                $username,
                $manager_id
            );

            mysqli_stmt_execute($check_stmt);
            $check_result = mysqli_stmt_get_result($check_stmt);
        }

        $staff_account_sql = "SELECT id FROM users WHERE owner_id=? AND staff_id=? AND id<>? LIMIT 1";
        $staff_account_stmt = mysqli_prepare($conn, $staff_account_sql);
        $staff_account_result = false;
        if($staff_account_stmt){
            mysqli_stmt_bind_param($staff_account_stmt, 'iii', $user_id, $staff_id, $manager_id);
            mysqli_stmt_execute($staff_account_stmt);
            $staff_account_result = mysqli_stmt_get_result($staff_account_stmt);
        }

        if (!$check_stmt || !$staff_account_stmt) {

            user_management_flash_and_redirect('Username could not be checked. Please try again.', 'danger', $manager_id > 0 ? ('edit=' . $manager_id) : '');

        } elseif (mysqli_num_rows($check_result) > 0) {

            user_management_flash_and_redirect('Username already exists.', 'danger', $manager_id > 0 ? ('edit=' . $manager_id) : '');
 
        } elseif (mysqli_num_rows($staff_account_result) > 0) {

            user_management_flash_and_redirect('This staff member already has an Assistant/Manager account.', 'danger', $manager_id > 0 ? ('edit=' . $manager_id) : '');

        } else {

            if($manager_id > 0){
                if($password !== ''){
                    $hash = password_hash($password, PASSWORD_DEFAULT);

                    $update_sql = "UPDATE users
                                   SET name=?,
                                       username=?,
                                       password=?,
                                        manager_type=?,
                                       staff_id=?,
                                       access_permissions=?
                                   WHERE id=?
                                   AND owner_id=?
                                   AND role='manager'";

                    $update_stmt = mysqli_prepare($conn, $update_sql);
                    mysqli_stmt_bind_param(
                        $update_stmt,
                        "ssssisii",
                        $name,
                        $username,
                        $hash,
                        $manager_type,
                        $staff_id,
                        $access_permissions_json,
                        $manager_id,
                        $user_id
                    );
                }else{
                    $update_sql = "UPDATE users
                                   SET name=?,
                                       username=?,
                                        manager_type=?,
                                       staff_id=?,
                                       access_permissions=?
                                   WHERE id=?
                                   AND owner_id=?
                                   AND role='manager'";

                    $update_stmt = mysqli_prepare($conn, $update_sql);
                    mysqli_stmt_bind_param(
                        $update_stmt,
                        "sssisii",
                        $name,
                        $username,
                        $manager_type,
                        $staff_id,
                        $access_permissions_json,
                        $manager_id,
                        $user_id
                    );
                }

                if(mysqli_stmt_execute($update_stmt)){
                    user_management_flash_and_redirect('Assistant/Manager updated successfully.', 'success');
                }

                user_management_flash_and_redirect('Assistant/Manager could not be updated.', 'danger', 'edit=' . $manager_id);
            } else {

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $email = user_management_agent_placeholder_email($username);
            $phone = user_management_agent_placeholder_phone($username);
            $address = 'None';

            $insert_sql = "INSERT INTO users
                           (
                               name,
                               username,
                               address,
                               email,
                               phone,
                               password,
                               role,
                               manager_type,
                                owner_id,
                                staff_id,
                                access_permissions,
                               status
                           )
                           VALUES
                           (
                               ?,
                               ?,
                               ?,
                                ?,
                                ?,
                                ?,
                                 'manager',
                                ?,
                                 ?,
                                 ?,
                                 ?,
                               'active'
                           )";

            $insert_stmt = mysqli_prepare($conn, $insert_sql);
            $inserted = false;

            if($insert_stmt){
                mysqli_stmt_bind_param(
                    $insert_stmt,
                    "sssssssiis",
                    $name,
                    $username,
                    $address,
                    $email,
                    $phone,
                    $hash,
                    $manager_type,
                    $user_id,
                    $staff_id
                    ,$access_permissions_json
                );

                try {
                    $inserted = mysqli_stmt_execute($insert_stmt);
                } catch (mysqli_sql_exception $exception) {
                    $inserted = false;
                }
            }

            if ($inserted) {

                user_management_flash_and_redirect(
                    "Assistant/Manager created successfully. Login username: " . $username,
                    'success'
                );

            } else {

                user_management_flash_and_redirect(
                    'Assistant/Manager could not be created. Please check username or subscription setup.',
                    'danger'
                );
            }
            }
        }
    }
}

if(isset($_SESSION['user_management_message'])){
    $message = (string)$_SESSION['user_management_message'];
    $message_type = (string)($_SESSION['user_management_message_type'] ?? 'success');
    unset($_SESSION['user_management_message'], $_SESSION['user_management_message_type']);
}

if (isset($_GET['status'], $_GET['id'])) {

    $manager_id = (int)$_GET['id'];
    $status = $_GET['status'] === 'active' ? 'active' : 'inactive';

    $status_sql = "UPDATE users
                   SET status=?
                   WHERE id=?
                   AND owner_id=?
                   AND role='manager'";

    $status_stmt = mysqli_prepare($conn, $status_sql);

    mysqli_stmt_bind_param(
        $status_stmt,
        "sii",
        $status,
        $manager_id,
        $user_id
    );

    mysqli_stmt_execute($status_stmt);

    header("Location: index.php");
    exit;
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_manager'){
    $manager_id = (int)($_POST['manager_id'] ?? 0);

    if($manager_id <= 0){
        user_management_flash_and_redirect('Invalid assistant/manager selected.', 'danger');
    }elseif(user_management_manager_has_transactions($conn, $manager_id)){
        user_management_flash_and_redirect('Delete not allowed. This assistant/manager already has transaction history.', 'danger');
    }else{
        $delete_stmt = mysqli_prepare(
            $conn,
            "DELETE FROM users
             WHERE id=?
             AND owner_id=?
             AND role='manager'
             LIMIT 1"
        );

        if($delete_stmt){
            mysqli_stmt_bind_param($delete_stmt, "ii", $manager_id, $user_id);

            if(mysqli_stmt_execute($delete_stmt) && mysqli_stmt_affected_rows($delete_stmt) > 0){
                user_management_flash_and_redirect('Assistant/Manager deleted successfully.', 'success');
            }else{
                user_management_flash_and_redirect('Assistant/Manager could not be deleted.', 'danger');
            }
        }else{
            user_management_flash_and_redirect('Assistant/Manager could not be deleted.', 'danger');
        }
    }
}

$sql = "SELECT id,
               name,
               username,
               manager_type,
               status,
               last_login,
               created_at
        FROM users
        WHERE owner_id=?
        AND role='manager'
        ORDER BY id DESC";

$stmt = mysqli_prepare($conn, $sql);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $user_id
);

mysqli_stmt_execute($stmt);
$managers_result = mysqli_stmt_get_result($stmt);
$managers = [];

while($managers_result && $row = mysqli_fetch_assoc($managers_result)){
    $row['can_delete'] = !user_management_manager_has_transactions($conn, (int)$row['id']);
    $managers[] = $row;
}

$selected_staff_id = (int)($edit_manager['staff_id'] ?? 0);
$selected_access_permissions = normalize_manager_permissions(
    json_decode($edit_manager['access_permissions'] ?? '[]', true)
);
$editing_manager_id = (int)($edit_manager['id'] ?? 0);
$staff_options_sql = "SELECT s.id,s.name,s.designation
                      FROM staff s
                      WHERE s.user_id=?
                      AND (s.status='active' OR s.id=?)
                      AND NOT EXISTS (
                          SELECT 1 FROM users u
                          WHERE u.owner_id=?
                          AND u.staff_id=s.id
                          AND u.role='manager'
                          AND u.id<>?
                      )
                      ORDER BY s.name ASC,s.id ASC";
$staff_options_stmt = mysqli_prepare($conn, $staff_options_sql);
mysqli_stmt_bind_param($staff_options_stmt, 'iiii', $user_id, $selected_staff_id, $user_id, $editing_manager_id);
mysqli_stmt_execute($staff_options_stmt);
$staff_options = mysqli_stmt_get_result($staff_options_stmt);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="row">

    <div class="col-lg-4">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    <?= $edit_manager ? 'Edit Staff Login Access' : 'Create Staff Login Access'; ?>
                </h3>
            </div>

            <div class="card-body">

                <?php if($message){ ?>
                    <div class="alert alert-<?= $message_type; ?>">
                        <?= htmlspecialchars($message); ?>
                    </div>
                <?php } ?>

                <form method="post">
                    <input
                        type="hidden"
                        name="manager_id"
                        value="<?= (int)($edit_manager['id'] ?? 0); ?>">

                    <div class="form-group">
                        <label>Staff Name</label>
                        <select name="staff_id" class="form-control staff-select" required>
                            <option value="">Search and select staff</option>
                            <?php while($staff_option = mysqli_fetch_assoc($staff_options)){ $label = $staff_option['name'] . (!empty($staff_option['designation']) ? ' (' . $staff_option['designation'] . ')' : ''); ?>
                                <option value="<?= (int)$staff_option['id']; ?>" <?= ((int)($edit_manager['staff_id'] ?? 0) === (int)$staff_option['id']) ? 'selected' : ''; ?>><?= htmlspecialchars($label); ?></option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Access Permissions</label>
                        <div class="row">
                            <?php foreach(available_manager_permissions() as $permission_key => $permission_label){ ?>
                                <div class="col-md-6 mb-2">
                                    <div class="custom-control custom-checkbox">
                                        <input type="checkbox" class="custom-control-input" id="permission_<?= htmlspecialchars($permission_key); ?>" name="access_permissions[]" value="<?= htmlspecialchars($permission_key); ?>" <?= in_array($permission_key, $selected_access_permissions, true) ? 'checked' : ''; ?>>
                                        <label class="custom-control-label" for="permission_<?= htmlspecialchars($permission_key); ?>"><?= htmlspecialchars($permission_label); ?></label>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                    </div>

                    <div class="form-group" id="sensitive-permission-password" style="display:none;">
                        <label>Admin Password</label>
                        <input type="password" name="admin_password" class="form-control" style="max-width: 280px;" autocomplete="current-password">
                        <small class="text-muted">Required for Company Dashboard, Project &amp; Package, or Admin access.</small>
                    </div>

                    <div class="form-group">
                        <label>Username</label>
                        <div class="input-group" style="max-width: 280px;">
                            <input
                                type="text"
                                name="username"
                                class="form-control"
                                value="<?= htmlspecialchars(display_agent_username_base($edit_manager['username'] ?? '', $user_id)); ?>"
                                required>
                            <div class="input-group-append">
                                <span class="input-group-text">@<?= (int)$user_id; ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>
                            Password
                            <?php if($edit_manager){ ?>
                                <small class="text-muted">(leave blank to keep current password)</small>
                            <?php } ?>
                        </label>
                        <input
                            type="password"
                            name="password"
                            class="form-control"
                            style="max-width: 280px;"
                            minlength="6"
                            <?= $edit_manager ? '' : 'required'; ?>>
                    </div>

                    <button
                        type="submit"
                        class="btn btn-primary">
                        <i class="fas <?= $edit_manager ? 'fa-save' : 'fa-user-plus'; ?>"></i>
                        <?= $edit_manager ? 'Update Access' : 'Create Access'; ?>
                    </button>

                    <?php if($edit_manager){ ?>
                        <a href="index.php" class="btn btn-secondary">
                            Cancel
                        </a>
                    <?php } ?>

                </form>

            </div>

        </div>

    </div>

    <div class="col-lg-8">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">Access Management</h3>
            </div>

            <div class="card-body">

                <table
                    id="example1"
                    class="table table-bordered table-striped">

                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Login Username</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Created</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach($managers as $row){ ?>

                            <tr>
                                <td><?= htmlspecialchars($row['name']); ?></td>
                                <td>
                                    <?php if(($row['manager_type'] ?? 'manager') === 'agent'){ ?>
                                        <span class="badge badge-info">Assistant</span>
                                    <?php }else{ ?>
                                        <span class="badge badge-primary">Manager</span>
                                    <?php } ?>
                                </td>
                                <td><?= htmlspecialchars($row['username']); ?></td>
                                <td>
                                    <?php if($row['status'] === 'active'){ ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php }else{ ?>
                                        <span class="badge badge-secondary">Inactive</span>
                                    <?php } ?>
                                </td>
                                <td><?= htmlspecialchars(app_datetime($row['last_login'] ?? null)); ?></td>
                                <td><?= htmlspecialchars(app_datetime($row['created_at'])); ?></td>
                                <td>
                                    <a
                                        href="index.php?edit=<?= $row['id']; ?>"
                                        class="btn btn-sm btn-info" title="Edit Access" aria-label="Edit Access">
                                        <i class="fas fa-edit"></i>
                                    </a>

                                    <?php if($row['status'] === 'active'){ ?>
                                        <a
                                            href="index.php?id=<?= $row['id']; ?>&status=inactive"
                                            class="btn btn-sm btn-warning" title="Deactivate Access" aria-label="Deactivate Access">
                                            <i class="fas fa-ban"></i>
                                        </a>
                                    <?php }else{ ?>
                                        <a
                                            href="index.php?id=<?= $row['id']; ?>&status=active"
                                            class="btn btn-sm btn-success" title="Activate Access" aria-label="Activate Access">
                                            <i class="fas fa-check"></i>
                                        </a>
                                    <?php } ?>

                                    <?php if(!empty($row['can_delete'])){ ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this assistant/manager?');">
                                            <input type="hidden" name="action" value="delete_manager">
                                            <input type="hidden" name="manager_id" value="<?= (int)$row['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="Delete Access" aria-label="Delete Access">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    <?php }else{ ?>
                                        <button type="button" class="btn btn-sm btn-danger" disabled title="Cannot delete: account has history" aria-label="Cannot delete: account has history">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php } ?>
                                </td>
                            </tr>

                        <?php } ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>

<?php
$page_script = "<script>$(function(){ $('.staff-select').select2({theme: 'bootstrap4', width: '100%', placeholder: 'Search and select staff'}); }); document.addEventListener('DOMContentLoaded', function(){ const sensitive = ['dashboard', 'projects', 'admin']; const box = document.getElementById('sensitive-permission-password'); const input = box ? box.querySelector('input[name=admin_password]') : null; const sync = function(){ const needed = sensitive.some(function(key){ const permission = document.getElementById('permission_' + key); return permission && permission.checked; }); if(box){ box.style.display = needed ? '' : 'none'; } if(input){ input.required = needed; if(!needed){ input.value = ''; } } }; document.querySelectorAll('input[name=\"access_permissions[]\"]').forEach(function(permission){ permission.addEventListener('change', sync); }); sync(); });</script>";
require_once '../includes/footer.php';
?>
