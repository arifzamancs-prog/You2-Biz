<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/date_helper.php';

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

function block_manager_restricted_actions()
{
    if (is_super_admin_user()) {
        return;
    }

    if (!is_manager_user()) {
        return;
    }

    $path = strtolower(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    $path = ltrim($path, '/');
    $file = basename($path);

    if (is_agent_user()) {
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
