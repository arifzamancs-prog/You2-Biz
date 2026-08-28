<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/date_helper.php';
require_once __DIR__ . '/manager_access_helper.php';

if (!isset($_SESSION['user_id']) && !isset($_SESSION['super_admin'])) {
    header("Location: " . app_path('login.php'));
    exit;
}

if (isset($_SESSION['super_admin']) && $_SESSION['super_admin'] === true) {
    $_SESSION['user_role'] = 'super_admin';
    $_SESSION['login_user_id'] = 0;
    $_SESSION['user_id'] = 0;
}

if (!isset($_SESSION['login_user_id'])) {
    $_SESSION['login_user_id'] = $_SESSION['user_id'];
}

if (!isset($_SESSION['user_role']) || trim((string)$_SESSION['user_role']) === '') {
    $_SESSION['user_role'] = 'admin';
}

if (!in_array($_SESSION['user_role'], ['admin', 'super_admin', 'manager'], true)) {
    $_SESSION['user_role'] = 'admin';
}

function block_disabled_modules()
{
    $request_path = strtolower((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));

    if (!sales_module_enabled() && preg_match('#(?:^|/)sales(?:/|$)#', $request_path)) {
        header("Location: " . app_path('dashboard.php?error=Sales module is disabled'));
        exit;
    }

    if (!products_module_enabled() && preg_match('#(?:^|/)(?:products|product_categories)(?:/|$)#', $request_path)) {
        header("Location: " . app_path('dashboard.php?error=Products module is disabled'));
        exit;
    }
}

block_disabled_modules();

function is_admin_user()
{
    return in_array(
        ($_SESSION['user_role'] ?? 'admin'),
        ['admin', 'super_admin'],
        true
    );
}

function is_super_admin_user()
{
    return ($_SESSION['user_role'] ?? '') === 'super_admin';
}

function is_manager_user()
{
    return ($_SESSION['user_role'] ?? 'admin') === 'manager';
}

function manager_access_type()
{
    return $_SESSION['manager_type'] ?? 'manager';
}

function is_agent_user()
{
    return is_manager_user() && manager_access_type() === 'agent';
}

function manager_has_permission($permission)
{
    if(!is_manager_user() || empty($_SESSION['permissions_configured'])){
        return true;
    }

    return in_array($permission, $_SESSION['access_permissions'] ?? [], true);
}

function refresh_current_manager_permissions($conn)
{
    if(!is_manager_user() || !($conn instanceof mysqli)){
        return;
    }

    $login_user_id = (int)($_SESSION['login_user_id'] ?? 0);
    if($login_user_id <= 0){ return; }
    $stmt = mysqli_prepare($conn, 'SELECT manager_type, access_permissions, status FROM users WHERE id=? AND role=\'manager\' LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $login_user_id);
    mysqli_stmt_execute($stmt);
    $manager = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if(!$manager || ($manager['status'] ?? '') !== 'active'){
        session_unset(); session_destroy(); header('Location: ' . app_path('login.php')); exit;
    }

    $_SESSION['manager_type'] = normalize_manager_type($manager['manager_type'] ?? 'agent');
    $_SESSION['access_permissions'] = normalize_manager_permissions(json_decode($manager['access_permissions'] ?? '[]', true));
    $_SESSION['permissions_configured'] = $manager['access_permissions'] !== null;
}

function role_power_includes($role)
{
    if (is_super_admin_user()) {
        return true;
    }

    $role = strtolower(trim((string)$role));

    if ($role === 'admin') {
        return is_admin_user();
    }

    if ($role === 'manager') {
        return is_manager_user();
    }

    if ($role === 'agent') {
        return is_agent_user();
    }

    return false;
}

function require_admin_user()
{
    if (!is_admin_user()) {
        header("Location: " . app_path('dashboard.php?error=Permission denied'));
        exit;
    }
}

function require_staff_manage_access()
{
    if(is_admin_user() || (is_manager_user() && manager_has_permission('staff'))){
        return;
    }

    header("Location: " . app_path('dashboard.php?error=Permission denied'));
    exit;
}

function require_sales_access()
{
    if(is_admin_user() || (is_manager_user() && manager_has_permission('sales'))){
        return;
    }

    header("Location: " . app_path('dashboard.php?error=Permission denied'));
    exit;
}

function require_lead_management_access()
{
    if(is_admin_user() || (is_manager_user() && manager_has_permission('leads'))){
        return;
    }

    header("Location: " . app_path('dashboard.php?error=Permission denied'));
    exit;
}

function require_admin_module_access()
{
    if(is_admin_user() || (is_manager_user() && manager_has_permission('admin'))){
        return;
    }

    header("Location: " . app_path('dashboard.php?error=Permission denied'));
    exit;
}

function require_super_admin_user()
{
    if (!is_super_admin_user()) {
        header("Location: " . app_path('dashboard.php?error=Permission denied'));
        exit;
    }
}

function subscription_support_message()
{
    return 'Please call +8801977592783 for subscription.';
}

function manager_can_modify()
{
    return !is_manager_user();
}

function can_delete_company_records()
{
    // Only the signed-in company administrator (or the platform super admin)
    // may remove saved business data. Managers and assistants may still use
    // the modules they have been granted, but cannot delete records.
    return is_admin_user();
}

function block_manager_restricted_actions()
{
    $requested_path = strtolower(ltrim((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
    $application_path = strtolower(trim((string)app_root_path(), '/'));
    if($application_path !== '' && str_starts_with($requested_path, $application_path . '/')){
        $requested_path = substr($requested_path, strlen($application_path) + 1);
    }
    $admin_only_paths = [
        'staff/attendance_settings.php',
        'create_invoice/manage_invoice_types.php',
        'wallets/create.php',
        'categories/create.php',
        'transfers/create.php',
        'help/pricing_plan.php',
        'help/support.php',
    ];
    $admin_only_prefixes = ['user_management/', 'profit_cash_out/', 'tools/'];

    if(!is_admin_user()){
        $is_admin_only = in_array($requested_path, $admin_only_paths, true);
        foreach($admin_only_prefixes as $admin_only_prefix){
            if(str_starts_with($requested_path, $admin_only_prefix)){
                $is_admin_only = true;
                break;
            }
        }
        if($requested_path === 'user_management/notice_publish.php' && manager_has_permission('notice_publish')){
            $is_admin_only = false;
        }
        // The Admin permission grants the Admin section to a staff account.
        // Wallet approvals remain restricted to the company administrator.
        if(
            is_manager_user() &&
            manager_has_permission('admin') &&
            $requested_path !== 'user_management/wallet_approvals.php' &&
            $requested_path !== 'user_management/index.php' &&
            !str_starts_with($requested_path, 'tools/') &&
            (
                str_starts_with($requested_path, 'user_management/') ||
                str_starts_with($requested_path, 'profit_cash_out/') ||
                str_starts_with($requested_path, 'tools/')
            )
        ){
            $is_admin_only = false;
        }
        if($is_admin_only){
            header("Location: " . app_path('dashboard.php?error=This option is available to the company administrator only'));
            exit;
        }
    }

    if (is_super_admin_user()) {
        return;
    }

    if (!is_manager_user()) {
        return;
    }

    $path = $requested_path;
    $file = basename($path);

    // Delete protection is checked before permission routing, because several
    // modules perform their deletion through an index page or an AJAX action.
    $delete_action = false;
    foreach(['action', 'form_action', 'staff_action'] as $action_key){
        $action_value = strtolower(trim((string)($_POST[$action_key] ?? $_GET[$action_key] ?? '')));
        if($action_value !== '' && str_contains($action_value, 'delete')){
            $delete_action = true;
            break;
        }
    }

    // A staff member may remove only their own lead. The lead endpoint applies
    // the ownership condition; every other delete action remains admin-only.
    $is_own_lead_delete = $path === 'lead_management/index.php'
        && isset($_GET['delete'])
        && manager_has_permission('leads');

    if((isset($_GET['delete']) || isset($_POST['delete']) || str_contains($file, 'delete') || $delete_action) && !$is_own_lead_delete){
        header("Location: " . app_path('dashboard.php?error=Only the company administrator can delete records'));
        exit;
    }

    if (is_manager_user()) {
        $permissions = $_SESSION['access_permissions'] ?? [];
        if(!empty($_SESSION['permissions_configured'])){
            $always_allowed_paths = ['dashboard.php', 'profile/index.php', 'profile/change_password.php', 'help/video_tutorial.php', 'logout.php'];
            $permission_paths = [
                'staff' => ['staff/'],
                'sales' => ['sales/', 'create_invoice/'],
                'wallets' => ['wallets/', 'categories/', 'moneyin/', 'expenses/', 'transfers/', 'transactions/', 'profit_cash_out/'],
                'projects' => ['project_package/'],
                'customers' => ['customers/'],
                'suppliers' => ['suppliers/', 'purchases/'],
                'leads' => ['lead_management/'],
                'admin' => ['user_management/', 'tools/'],
                'notice_publish' => ['user_management/notice_publish.php'],
            ];

            if(!in_array($path, $always_allowed_paths, true)){
                $allowed = false;
                foreach($permission_paths as $permission => $paths){
                    foreach($paths as $allowed_path){
                        if(str_starts_with($path, $allowed_path) && in_array($permission, $permissions, true)){
                            $allowed = true;
                        }
                    }
                }

                if(!$allowed){
                    header("Location: " . app_path('dashboard.php?error=Permission denied'));
                    exit;
                }
            }

            return;
        }

        $agent_allowed_paths = [
            'dashboard.php',
            'sales/create_invoice.php',
            'sales/invoice_list.php',
            'sales/receive_payment.php',
            'sales/save_invoice.php',
            'sales/get_product.php',
            'profile/index.php',
            'profile/change_password.php',
            'logout.php',
        ];

        if (!in_array($path, $agent_allowed_paths, true)) {
            header("Location: " . app_path('dashboard.php?error=Permission denied'));
            exit;
        }
    }

    $blocked_files = [
        'edit.php',
        'update.php',
        'delete.php',
        'active.php',
        'inactive.php',
        'edit_invoice.php',
        'update_invoice.php',
        'delete_invoice.php',
    ];

    $blocked_paths = [
        'categories/create.php',
        'product_categories/create.php',
        'products/create.php',
        'suppliers/create.php',
        'suppliers/save.php',
        'wallets/create.php',
        'tools/delete_data.php',
        'tools/import.php',
    ];

    if (str_starts_with($file, 'edit_') ||
        str_starts_with($file, 'delete_') ||
        str_starts_with($file, 'update_') ||
        in_array($file, $blocked_files, true) ||
        in_array($path, $blocked_paths, true)) {

        header("Location: " . app_path('dashboard.php?error=Permission denied'));
        exit;
    }
}

block_manager_restricted_actions();
