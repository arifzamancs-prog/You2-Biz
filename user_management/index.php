<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/manager_access_helper.php';
require_once '../includes/customer_portal_helper.php';
require_once '../includes/staff_helper.php';
require_once '../includes/branch_helper.php';
require_once '../includes/project_package_helper.php';

require_admin_user();
ensure_manager_access_columns($conn);
ensure_customer_access_table($conn);
ensure_staff_table($conn);

$user_id = (int)$_SESSION['user_id'];
ensure_head_office_branch($conn, $user_id);
$multi_branch_enabled = company_multi_branch_enabled($conn, $user_id);
$company_type = project_package_company_type($conn, $user_id);
$project_package_labels = project_package_labels($conn, $user_id);
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

function user_management_ajax_response($message, $success)
{
    if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'xmlhttprequest') {
        return false;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => (bool)$success, 'message' => (string)$message]);
    exit;
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

function user_management_customer_access_has_history($conn, $access_id)
{
    return (int)$access_id <= 0 ? false : true;
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
                         staff_id,
                         access_permissions,
                         (SELECT s.branch_id FROM staff s WHERE s.id=users.staff_id AND s.user_id=users.owner_id LIMIT 1) AS branch_id
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_customer_access') {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $username_base = normalize_customer_access_username($_POST['customer_username'] ?? '');
    $username = build_customer_access_username($username_base, $user_id);
    $password = (string)($_POST['customer_password'] ?? '');

    $customer_stmt = mysqli_prepare(
        $conn,
        "SELECT id, customer_name
         FROM customers
         WHERE id=?
         AND user_id=?
         AND status='active'
         LIMIT 1"
    );
    $selected_customer = null;
    if($customer_stmt){
        mysqli_stmt_bind_param($customer_stmt, 'ii', $customer_id, $user_id);
        mysqli_stmt_execute($customer_stmt);
        $selected_customer = mysqli_fetch_assoc(mysqli_stmt_get_result($customer_stmt));
    }

    if(!$selected_customer || $username_base === '' || $password === ''){
        user_management_flash_and_redirect('Select a customer, then enter username and password.', 'danger');
    } elseif(strlen($password) < 6) {
        user_management_flash_and_redirect('Customer password must be at least 6 characters.', 'danger');
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $insert_stmt = mysqli_prepare(
            $conn,
            "INSERT INTO customer_access_accounts (user_id, customer_id, username, password, status)
             VALUES (?, ?, ?, ?, 'active')"
        );

        if($insert_stmt){
            mysqli_stmt_bind_param($insert_stmt, 'iiss', $user_id, $customer_id, $username, $hash);
            try {
                if(mysqli_stmt_execute($insert_stmt)){
                    user_management_flash_and_redirect('Customer access created successfully. Login username: ' . $username, 'success');
                }
            } catch (mysqli_sql_exception $exception) {
                user_management_flash_and_redirect('This customer or username already has access.', 'danger');
            }
        }

        user_management_flash_and_redirect('Customer access could not be created.', 'danger');
    }
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_customer_access'){
    $access_id = (int)($_POST['access_id'] ?? 0);
    $delete_stmt = mysqli_prepare(
        $conn,
        "DELETE FROM customer_access_accounts
         WHERE id=?
         AND user_id=?
         LIMIT 1"
    );

    if($delete_stmt){
        mysqli_stmt_bind_param($delete_stmt, 'ii', $access_id, $user_id);
        if(mysqli_stmt_execute($delete_stmt) && mysqli_stmt_affected_rows($delete_stmt) > 0){
            user_management_flash_and_redirect('Customer access deleted successfully.', 'success');
        }
    }

    user_management_flash_and_redirect('Customer access could not be deleted.', 'danger');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array(($_POST['action'] ?? ''), ['delete_manager', 'create_customer_access', 'delete_customer_access'], true)) {

    $manager_id = (int)($_POST['manager_id'] ?? 0);
    $staff_id = (int)($_POST['staff_id'] ?? 0);
    $branch_id = (int)($_POST['branch_id'] ?? 0);
    $name = '';
    $access_permissions = normalize_manager_permissions($_POST['access_permissions'] ?? []);
    if ($company_type !== 'Fashion house') {
        $access_permissions = array_values(array_diff($access_permissions, ['stock_live_report']));
    }

    // A single-branch company always operates from Head Office. Do not trust a
    // posted branch or branch-only permissions when Multi Branch is disabled.
    if(!$multi_branch_enabled){
        $head_office_stmt = mysqli_prepare(
            $conn,
            "SELECT id FROM branches WHERE user_id=? AND is_head_office=1 AND status='active' LIMIT 1"
        );
        if($head_office_stmt){
            mysqli_stmt_bind_param($head_office_stmt, 'i', $user_id);
            mysqli_stmt_execute($head_office_stmt);
            $head_office = mysqli_fetch_assoc(mysqli_stmt_get_result($head_office_stmt));
            $branch_id = (int)($head_office['id'] ?? 0);
        }

        $access_permissions = array_values(array_diff(
            $access_permissions,
            ['dashboard', 'all_branches', 'warehouse', 'branch_management']
        ));
    }

    // Housing companies do not use the stock/invoice modules. Enforce the
    // same rule on submitted data as on the visible permission list.
    if($company_type === 'Housing'){
        $access_permissions = array_values(array_diff(
            $access_permissions,
            ['stock_sales', 'products', 'warehouse']
        ));
    }

    if($company_type === 'Service type'){
        $access_permissions = array_values(array_diff(
            $access_permissions,
            ['stock_sales', 'products', 'warehouse', 'suppliers']
        ));
    }

    if($company_type === 'Car Parking'){
        $parking_permissions = ['main_dashboard', 'parking_dashboard', 'parking_entry', 'parking_exit', 'parking_reports', 'parking_settings'];
        $access_permissions = array_values(array_intersect($access_permissions, $parking_permissions));
    }

    // Stock companies use the dedicated Sales and Products modules;
    // their service invoice/category module must not be assignable.
    if(in_array($company_type, ['Stock Product', 'Fashion house'], true)){
        $access_permissions = array_values(array_diff(
            $access_permissions,
            ['sales', 'projects']
        ));
    }

    // A staff account can open either the company-wide dashboard or its
    // assigned branch dashboard, but never both at the same time.
    if(in_array('main_dashboard', $access_permissions, true) && in_array('dashboard', $access_permissions, true)){
        $access_permissions = array_values(array_diff($access_permissions, ['dashboard']));
    }

    $admin_sidebar_permission_keys = array_keys(admin_sidebar_permissions());
    if(!in_array('admin', $access_permissions, true)){
        $access_permissions = array_values(array_diff(
            $access_permissions,
            array_merge($admin_sidebar_permission_keys, ['admin_sidebar_configured'])
        ));
    }

    $access_permissions_json = json_encode($access_permissions);
    $sensitive_permissions = ['main_dashboard', 'dashboard', 'all_branches', 'projects', 'admin'];
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

    $selected_branch = null;
    if($branch_id > 0){
        $branch_stmt = mysqli_prepare($conn, "SELECT id,is_head_office FROM branches WHERE id=? AND user_id=? AND status='active' LIMIT 1");
        mysqli_stmt_bind_param($branch_stmt, 'ii', $branch_id, $user_id);
        mysqli_stmt_execute($branch_stmt);
        $selected_branch = mysqli_fetch_assoc(mysqli_stmt_get_result($branch_stmt));
    }

    // Under Multi Branch, administrative and cross-branch controls are reserved
    // for Head Office staff. Enforce this server-side against forged requests.
    if($multi_branch_enabled && (!$selected_branch || (int)($selected_branch['is_head_office'] ?? 0) !== 1)){
        $access_permissions = array_values(array_diff($access_permissions, ['all_branches', 'admin']));
        $access_permissions_json = json_encode($access_permissions);
    }
    $requires_admin_password = count(array_intersect($sensitive_permissions, $access_permissions)) > 0;

    if ($requires_admin_password) {
        $admin_password_stmt = mysqli_prepare($conn, "SELECT password FROM users WHERE id=? AND role='admin' LIMIT 1");
        mysqli_stmt_bind_param($admin_password_stmt, 'i', $user_id);
        mysqli_stmt_execute($admin_password_stmt);
        $admin_row = mysqli_fetch_assoc(mysqli_stmt_get_result($admin_password_stmt));

        if(!$admin_row || !password_verify($admin_password, $admin_row['password'])){
            user_management_flash_and_redirect('Admin Password is required to grant Branch Dashboard, All Branches, ' . $project_package_labels['module'] . ', or Admin access.', 'danger', $manager_id > 0 ? ('edit=' . $manager_id) : '');
        }
    }

    if ($limit_error !== '') {

        user_management_flash_and_redirect($limit_error, 'danger', $manager_id > 0 ? ('edit=' . $manager_id) : '');

    } elseif (!$selected_staff || !$selected_branch || $name === '' || $username_base === '' || ($manager_id === 0 && $password === '')) {
 
        user_management_flash_and_redirect('Select a staff member and branch, then enter username and password.', 'danger', $manager_id > 0 ? ('edit=' . $manager_id) : '');

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

            user_management_flash_and_redirect('This staff member already has a login access account.', 'danger', $manager_id > 0 ? ('edit=' . $manager_id) : '');

        } else {

            if($manager_id > 0){
                if($password !== ''){
                    $hash = password_hash($password, PASSWORD_DEFAULT);

                    $update_sql = "UPDATE users
                                   SET name=?,
                                       username=?,
                                       password=?,
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
                        $hash,
                        $staff_id,
                        $access_permissions_json,
                        $manager_id,
                        $user_id
                    );
                }else{
                    $update_sql = "UPDATE users
                                   SET name=?,
                                       username=?,
                                       staff_id=?,
                                       access_permissions=?
                                   WHERE id=?
                                   AND owner_id=?
                                   AND role='manager'";

                    $update_stmt = mysqli_prepare($conn, $update_sql);
                    mysqli_stmt_bind_param(
                        $update_stmt,
                        "ssisii",
                        $name,
                        $username,
                        $staff_id,
                        $access_permissions_json,
                        $manager_id,
                        $user_id
                    );
                }

                if(mysqli_stmt_execute($update_stmt)){
                    $staff_branch_stmt = mysqli_prepare($conn, 'UPDATE staff SET branch_id=? WHERE id=? AND user_id=?');
                    mysqli_stmt_bind_param($staff_branch_stmt, 'iii', $branch_id, $staff_id, $user_id);
                    mysqli_stmt_execute($staff_branch_stmt);
                    user_management_flash_and_redirect('Staff login access updated successfully.', 'success');
                }

                user_management_flash_and_redirect('Staff login access could not be updated.', 'danger', 'edit=' . $manager_id);
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
                               'active'
                           )";

            $insert_stmt = mysqli_prepare($conn, $insert_sql);
            $inserted = false;

            if($insert_stmt){
                mysqli_stmt_bind_param(
                    $insert_stmt,
                    "ssssssiis",
                    $name,
                    $username,
                    $address,
                    $email,
                    $phone,
                    $hash,
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

                $staff_branch_stmt = mysqli_prepare($conn, 'UPDATE staff SET branch_id=? WHERE id=? AND user_id=?');
                mysqli_stmt_bind_param($staff_branch_stmt, 'iii', $branch_id, $staff_id, $user_id);
                mysqli_stmt_execute($staff_branch_stmt);

                user_management_flash_and_redirect(
                    "Staff login access created successfully. Login username: " . $username,
                    'success'
                );

            } else {

                user_management_flash_and_redirect(
                    'Staff login access could not be created. Please check username or subscription setup.',
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

if (isset($_GET['customer_access_status'], $_GET['customer_access_id'])) {
    $access_id = (int)$_GET['customer_access_id'];
    $status = $_GET['customer_access_status'] === 'active' ? 'active' : 'inactive';

    $status_stmt = mysqli_prepare(
        $conn,
        "UPDATE customer_access_accounts
         SET status=?
         WHERE id=?
         AND user_id=?"
    );

    if($status_stmt){
        mysqli_stmt_bind_param($status_stmt, 'sii', $status, $access_id, $user_id);
        mysqli_stmt_execute($status_stmt);
    }

    header("Location: index.php");
    exit;
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_manager'){
    $manager_id = (int)($_POST['manager_id'] ?? 0);

    if($manager_id <= 0){
        user_management_ajax_response('Invalid staff login access selected.', false);
        user_management_flash_and_redirect('Invalid staff login access selected.', 'danger');
    }elseif(user_management_manager_has_transactions($conn, $manager_id)){
        user_management_ajax_response('Delete not allowed. This staff login access already has transaction history.', false);
        user_management_flash_and_redirect('Delete not allowed. This staff login access already has transaction history.', 'danger');
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
                user_management_ajax_response('Staff login access deleted successfully.', true);
                user_management_flash_and_redirect('Staff login access deleted successfully.', 'success');
            }else{
                error_log('Staff access delete failed for manager ' . $manager_id . ': ' . mysqli_stmt_error($delete_stmt));
                user_management_ajax_response('Staff login access could not be deleted. Please refresh and try again.', false);
                user_management_flash_and_redirect('Staff login access could not be deleted.', 'danger');
            }
        }else{
            user_management_ajax_response('Staff login access could not be deleted. Please refresh and try again.', false);
            user_management_flash_and_redirect('Staff login access could not be deleted.', 'danger');
        }
    }
}

$sql = "SELECT u.id,
               u.name,
               u.username,
               u.status,
               u.last_login,
               u.created_at,
               s.designation,
               COALESCE(NULLIF(b.branch_name,''), 'Head Office') AS branch_name
        FROM users u
        LEFT JOIN staff s ON s.id=u.staff_id AND s.user_id=u.owner_id
        LEFT JOIN branches b ON b.id=s.branch_id AND b.user_id=s.user_id
        WHERE u.owner_id=?
        AND u.role='manager'
        ORDER BY u.id DESC";

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
$admin_sidebar_permission_keys = array_keys(admin_sidebar_permissions());
$admin_sidebar_is_legacy = in_array('admin', $selected_access_permissions, true)
    && !in_array('admin_sidebar_configured', $selected_access_permissions, true);
$selected_admin_sidebar_permissions = $admin_sidebar_is_legacy
    ? $admin_sidebar_permission_keys
    : $selected_access_permissions;
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

$branch_options_stmt = mysqli_prepare($conn, "SELECT id,branch_name,is_head_office FROM branches WHERE user_id=? AND status='active' ORDER BY is_head_office DESC,branch_name ASC");
mysqli_stmt_bind_param($branch_options_stmt, 'i', $user_id);
mysqli_stmt_execute($branch_options_stmt);
$branch_options = mysqli_stmt_get_result($branch_options_stmt);
$selected_branch_id = (int)($edit_manager['branch_id'] ?? 0);

$customer_access_options_stmt = mysqli_prepare(
    $conn,
    "SELECT c.id, c.customer_name, c.phone
     FROM customers c
     WHERE c.user_id=?
     AND c.status='active'
     AND NOT EXISTS (
        SELECT 1
        FROM customer_access_accounts ca
        WHERE ca.user_id=c.user_id
        AND ca.customer_id=c.id
     )
     ORDER BY c.customer_name ASC, c.id ASC"
);
mysqli_stmt_bind_param($customer_access_options_stmt, 'i', $user_id);
mysqli_stmt_execute($customer_access_options_stmt);
$customer_access_options = mysqli_stmt_get_result($customer_access_options_stmt);

$customer_access_stmt = mysqli_prepare(
    $conn,
    "SELECT
        ca.id,
        ca.username,
        ca.status,
        ca.last_login,
        ca.created_at,
        c.customer_name,
        c.customer_code,
        c.phone,
        c.email
     FROM customer_access_accounts ca
     INNER JOIN customers c
        ON c.id=ca.customer_id
        AND c.user_id=ca.user_id
     WHERE ca.user_id=?
     ORDER BY ca.id DESC"
);
mysqli_stmt_bind_param($customer_access_stmt, 'i', $user_id);
mysqli_stmt_execute($customer_access_stmt);
$customer_access_result = mysqli_stmt_get_result($customer_access_stmt);

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

                <form method="post" action="index.php" id="staff-access-form">
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

                    <?php if($multi_branch_enabled){ ?>
                        <div class="form-group">
                            <label>Branch Name</label>
                            <select name="branch_id" id="access_branch_id" class="form-control access-branch-select" required>
                                <option value="">Select Branch</option>
                                <?php while($branch_option = mysqli_fetch_assoc($branch_options)){ ?>
                                    <option value="<?= (int)$branch_option['id']; ?>" data-head-office="<?= (int)$branch_option['is_head_office']; ?>" <?= $selected_branch_id === (int)$branch_option['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($branch_option['branch_name']); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    <?php } ?>

                    <div class="form-group">
                        <label>Access Permissions</label>
                        <div class="row">
                            <?php foreach(available_manager_permissions($project_package_labels) as $permission_key => $permission_label){ if(in_array($permission_key, array_merge($admin_sidebar_permission_keys, ['admin_sidebar_configured']), true)) continue; if($permission_key === 'land_ledger' && $company_type !== 'Housing') continue; if($company_type === 'Housing' && in_array($permission_key, ['stock_sales', 'products', 'warehouse'], true)) continue; if($company_type === 'Service type' && in_array($permission_key, ['stock_sales', 'products', 'warehouse', 'suppliers'], true)) continue; if($company_type === 'Car Parking' && !in_array($permission_key, ['main_dashboard', 'parking_dashboard', 'parking_entry', 'parking_exit', 'parking_reports', 'parking_settings'], true)) continue; if(in_array($company_type, ['Stock Product', 'Fashion house'], true) && in_array($permission_key, ['sales', 'projects'], true)) continue; if(!$multi_branch_enabled && in_array($permission_key, ['dashboard', 'all_branches', 'warehouse'], true)) continue; ?>
                                <?php if ($permission_key === 'stock_live_report' && $company_type !== 'Fashion house') continue; ?>
                                <div class="col-md-6 mb-2">
                                    <div class="custom-control custom-checkbox">
                                        <input type="checkbox" class="custom-control-input" id="permission_<?= htmlspecialchars($permission_key); ?>" name="access_permissions[]" value="<?= htmlspecialchars($permission_key); ?>" <?= in_array($permission_key, $selected_access_permissions, true) ? 'checked' : ''; ?>>
                                        <label class="custom-control-label" for="permission_<?= htmlspecialchars($permission_key); ?>"><?= htmlspecialchars($permission_label); ?></label>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                        <div id="admin-sidebar-permissions" class="border rounded p-3 mt-2" style="display:none;">
                            <strong class="d-block mb-2">Admin Sidebar Options</strong>
                            <input type="hidden" name="access_permissions[]" value="admin_sidebar_configured" disabled>
                            <div class="row">
                                <?php foreach(admin_sidebar_permissions() as $permission_key => $permission_label){ if($permission_key === 'branch_management' && !$multi_branch_enabled) continue; ?>
                                    <div class="col-md-6 mb-2">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input admin-sidebar-option" id="permission_<?= htmlspecialchars($permission_key); ?>" name="access_permissions[]" value="<?= htmlspecialchars($permission_key); ?>" <?= in_array($permission_key, $selected_admin_sidebar_permissions, true) ? 'checked' : ''; ?> disabled>
                                            <label class="custom-control-label" for="permission_<?= htmlspecialchars($permission_key); ?>"><?= htmlspecialchars($permission_label); ?></label>
                                        </div>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>
                        <?php if($multi_branch_enabled){ ?><small class="text-muted">Admin and All Branches access can only be granted to Head Office staff. Other Head Office access works globally; branch staff access stays local.</small><?php } ?>
                    </div>

                    <div class="form-group" id="sensitive-permission-password" style="display:none;">
                        <label>Admin Password</label>
                        <input type="password" name="admin_password" class="form-control" style="max-width: 280px;" autocomplete="current-password">
                        <small class="text-muted">Required for Main Dashboard, Branch Dashboard, All Branches, <?= htmlspecialchars($project_package_labels['module']); ?>, or Admin access.</small>
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
                <h3 class="card-title">Staff Access Management</h3>
            </div>

            <div class="card-body">

                <table
                    id="example1"
                    class="table table-bordered table-striped">

                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Designation</th>
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
                                    <?= htmlspecialchars($row['designation'] ?: '-'); ?>
                                    <small class="text-muted d-block"><?= htmlspecialchars($row['branch_name']); ?></small>
                                </td>
                                <td><?= htmlspecialchars($row['username']); ?></td>
                                <td>
                                    <?php if($row['status'] === 'active'){ ?>
                                        <span class="badge badge-success ajax-status-badge">Active</span>
                                    <?php }else{ ?>
                                        <span class="badge badge-secondary ajax-status-badge">Inactive</span>
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
                                            class="btn btn-sm btn-warning" title="Deactivate Access" aria-label="Deactivate Access" data-ajax-action-link="true" data-ajax-status="inactive">
                                            <i class="fas fa-ban"></i>
                                        </a>
                                    <?php }else{ ?>
                                        <a
                                            href="index.php?id=<?= $row['id']; ?>&status=active"
                                            class="btn btn-sm btn-success" title="Activate Access" aria-label="Activate Access" data-ajax-action-link="true" data-ajax-status="active">
                                            <i class="fas fa-check"></i>
                                        </a>
                                    <?php } ?>

                                    <?php if(!empty($row['can_delete'])){ ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this staff login access?');">
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
$page_script = "<script>$(function(){ $('.staff-select').select2({theme: 'bootstrap4', width: '100%', placeholder: 'Search and select staff'}); $('.customer-select').select2({theme: 'bootstrap4', width: '100%', placeholder: 'Search and select customer'}); $('.access-branch-select').select2({theme: 'bootstrap4', width: '100%', placeholder: 'Select Branch', allowClear: true}); }); document.addEventListener('DOMContentLoaded', function(){ const multiBranchEnabled = " . ($multi_branch_enabled ? 'true' : 'false') . "; const sensitive = ['main_dashboard', 'dashboard', 'all_branches', 'projects', 'admin']; const box = document.getElementById('sensitive-permission-password'); const input = box ? box.querySelector('input[name=admin_password]') : null; const branch = document.getElementById('access_branch_id'); const mainDashboard = document.getElementById('permission_main_dashboard'); const branchDashboard = document.getElementById('permission_dashboard'); const syncDashboardChoice = function(changed){ if(!mainDashboard || !branchDashboard){ return; } if(changed === mainDashboard && mainDashboard.checked){ branchDashboard.checked = false; } if(changed === branchDashboard && branchDashboard.checked){ mainDashboard.checked = false; } }; const syncBranchPermission = function(){ if(!multiBranchEnabled || !branch){ return; } const option = branch.options[branch.selectedIndex]; const isHeadOffice = option && option.dataset.headOffice === '1'; document.querySelectorAll('[data-head-office-only=\"1\"]').forEach(function(row){ const permission = row.querySelector('input[name=\"access_permissions[]\"]'); row.style.display = isHeadOffice ? '' : 'none'; if(permission){ permission.disabled = !isHeadOffice; if(!isHeadOffice){ permission.checked = false; } } }); }; const sync = function(){ syncBranchPermission(); const needed = sensitive.some(function(key){ const permission = document.getElementById('permission_' + key); return permission && permission.checked; }); if(box){ box.style.display = needed ? '' : 'none'; } if(input){ input.required = needed; if(!needed){ input.value = ''; } } }; if(branch){ branch.addEventListener('change', sync); } document.querySelectorAll('input[name=\"access_permissions[]\"]').forEach(function(permission){ permission.addEventListener('change', function(){ syncDashboardChoice(permission); sync(); }); }); syncDashboardChoice(null); sync(); });</script>";
$page_script .= "<script>document.addEventListener('DOMContentLoaded', function(){ var admin = document.getElementById('permission_admin'); var panel = document.getElementById('admin-sidebar-permissions'); if(!admin || !panel){ return; } var options = panel.querySelectorAll('.admin-sidebar-option'); var configured = panel.querySelector('input[value=admin_sidebar_configured]'); function syncAdminSidebar(){ var enabled = admin.checked && !admin.disabled; panel.style.display = enabled ? '' : 'none'; options.forEach(function(option){ option.disabled = !enabled; if(!enabled){ option.checked = false; } }); if(configured){ configured.disabled = !enabled; } } admin.addEventListener('change', syncAdminSidebar); syncAdminSidebar(); });</script>";
$page_script .= '<script src="../assets/js/ajax_page_actions.js?v=' . filemtime(__DIR__ . '/../assets/js/ajax_page_actions.js') . '"></script>';
$page_script .= <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function(){
    var accessForm = document.getElementById('staff-access-form');
    if (!accessForm) return;

    function replaceAccessRows(source){
        var sourceRows = source.querySelectorAll('#example1 tbody tr');
        var table = document.getElementById('example1');
        if (!table) return;

        if (window.jQuery && jQuery.fn.DataTable && jQuery.fn.DataTable.isDataTable(table)) {
            var dataTable = jQuery(table).DataTable();
            dataTable.clear();
            sourceRows.forEach(function(row){ dataTable.row.add(row.cloneNode(true)); });
            dataTable.draw(false);
        } else {
            var body = table.querySelector('tbody');
            if (body) body.replaceChildren.apply(body, Array.from(sourceRows, function(row){ return row.cloneNode(true); }));
        }
    }

    function replaceStaffOptions(source){
        var select = accessForm.querySelector('.staff-select');
        var sourceSelect = source.querySelector('#staff-access-form .staff-select');
        if (!select || !sourceSelect) return;

        var current = select.value;
        var $select = window.jQuery && window.jQuery.fn.select2 ? window.jQuery(select) : null;
        if ($select && select.classList.contains('select2-hidden-accessible')) $select.select2('destroy');

        select.replaceChildren.apply(select, Array.from(sourceSelect.options, function(option){ return option.cloneNode(true); }));
        if (Array.from(select.options).some(function(option){ return option.value === current; })) select.value = current;

        if ($select) $select.select2({theme: 'bootstrap4', width: '100%', placeholder: 'Search and select staff'});
    }

    var refreshSequence = 0;
    async function refreshAccessPageParts(resetForm){
        var sequence = ++refreshSequence;
        var url = new URL('index.php', window.location.href);
        var managerId = accessForm.querySelector('[name="manager_id"]').value;
        if (!resetForm && Number(managerId) > 0) url.searchParams.set('edit', managerId);
        var response = await fetch(url, {cache: 'no-store', credentials: 'same-origin'});
        if (!response.ok) throw new Error('Staff list could not be refreshed.');
        var source = new DOMParser().parseFromString(await response.text(), 'text/html');
        if (sequence !== refreshSequence) return;
        if (!source.querySelector('#staff-access-form') || !source.querySelector('#example1')) {
            throw new Error('Access saved, but the staff list could not be refreshed. Please reload the page.');
        }
        if (resetForm || source.querySelector('#staff-access-form [name="manager_id"]').value === '0') {
            accessForm.reset();
            accessForm.querySelector('[name="manager_id"]').value = '0';
            accessForm.querySelectorAll('input[type="password"], input[name="username"]').forEach(function(input){ input.value = ''; });
            accessForm.querySelector('[name="password"]').required = true;
            accessForm.querySelectorAll('input[type="checkbox"]').forEach(function(input){ input.checked = false; input.dispatchEvent(new Event('change')); });
            accessForm.querySelectorAll('select').forEach(function(select){ select.value = ''; if(window.jQuery) jQuery(select).trigger('change'); });
            accessForm.closest('.card').querySelector('.card-title').textContent = 'Create Staff Login Access';
            accessForm.querySelector('button[type="submit"]').innerHTML = '<i class="fas fa-user-plus"></i> Create Access';
            accessForm.querySelector('a.btn-secondary')?.remove();
            accessForm.querySelector('[name="password"]').closest('.form-group').querySelector('label small')?.remove();
            window.history.replaceState(null, '', 'index.php');
        }
        replaceStaffOptions(source);
        replaceAccessRows(source);
    }

    document.addEventListener('ajax-page-action-complete', function(event){
        var sourceForm = event.detail && event.detail.source;
        if (!sourceForm) return;
        var action = event.detail && event.detail.action;
        if (sourceForm !== accessForm && action !== 'delete_manager' && action !== 'status') return;
        var deletedCurrent = action === 'delete_manager' && sourceForm.querySelector('[name="manager_id"]').value === accessForm.querySelector('[name="manager_id"]').value;

        refreshAccessPageParts(sourceForm === accessForm || deletedCurrent).catch(function(error){
            if (window.showAjaxActionMessage) window.showAjaxActionMessage(error.message, false);
        });
    });
});
</script>
HTML;
require_once '../includes/footer.php';
?>
