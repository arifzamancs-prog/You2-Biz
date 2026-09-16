<?php

require_once __DIR__ . '/product_expiry_helper.php';
require_once __DIR__ . '/pricing_plan_visibility_helper.php';

function ensure_sidebar_settings_table($conn)
{
    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS sidebar_settings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            layout_json LONGTEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_sidebar_settings_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function sidebar_setting_section_for_sort($sort)
{
    $sort = (int)$sort;
    if($sort >= 160){
        return 'SESSION';
    }
    if($sort >= 150){
        return 'HELP';
    }
    if($sort >= 130){
        return 'ADMIN';
    }
    if($sort >= 120){
        return 'INSIGHTS';
    }
    if($sort >= 40){
        return 'OPERATIONS';
    }
    return 'MAIN';
}

function sidebar_setting_item($id, $label, $href = '', $parent = '', $sort = 0, $section = '')
{
    return [
        'id' => $id,
        'label' => $label,
        'href' => $href,
        'parent' => $parent,
        'sort' => $sort,
        'section' => $section !== '' ? $section : sidebar_setting_section_for_sort($sort),
        'visible' => 1,
    ];
}

function sidebar_default_layout_items($project_package_labels = [])
{
    $module = $project_package_labels['module'] ?? 'Project & Package';
    $project = $project_package_labels['project'] ?? 'Project';
    $package_list = $project_package_labels['package_list'] ?? 'Package';

    return [
        sidebar_setting_item('dashboard', 'Dashboard', app_path('dashboard.php'), '', 10),
        sidebar_setting_item('staff_manage', 'Staff Manage', '', '', 20),
        sidebar_setting_item('staff_create', 'Create Staff', app_path('staff/index.php'), 'staff_manage', 21),
        sidebar_setting_item('staff_attendance', 'Staff Attendance', app_path('staff/attendance.php'), 'staff_manage', 22),
        sidebar_setting_item('staff_salary', 'Staff Salary', app_path('staff/salary.php'), 'staff_manage', 23),
        sidebar_setting_item('staff_ledger', 'Staff Ledger', app_path('staff/ledger.php'), 'staff_manage', 24),
        sidebar_setting_item('super_admin', 'Super Admin', '', '', 30),
        sidebar_setting_item('subscription', 'Subscription', app_path('super_admin/index.php'), 'super_admin', 31),
        sidebar_setting_item('message_setup', 'Message Setup', app_path('super_admin/signup_message.php'), 'super_admin', 32),
        sidebar_setting_item('settings', 'Settings', app_path('super_admin/email_sms_settings.php'), 'super_admin', 33),
        sidebar_setting_item('company_delete_data', 'Company Delete Data', app_path('super_admin/company_delete_data.php'), 'super_admin', 34),
        sidebar_setting_item('marketing', 'Marketing', app_path('user_management/marketing.php'), 'super_admin', 35),
        sidebar_setting_item('notice_publish', 'Notice Publish', app_path('user_management/notice_publish.php'), '', 40),
        sidebar_setting_item('sales', 'Sales', '', '', 50),
        sidebar_setting_item('create_invoice', 'Create Invoice', app_path('create_invoice/index.php'), 'sales', 51),
        sidebar_setting_item('invoice_list', 'Invoice List', app_path('create_invoice/invoice_list.php'), 'sales', 52),
        sidebar_setting_item('manage_payment_type', 'Manage Payment Type', app_path('create_invoice/manage_invoice_types.php'), 'sales', 53),
        sidebar_setting_item('wallets', 'Wallets', '', '', 60),
        sidebar_setting_item('wallet_list', 'Wallet List', app_path('wallets/index.php'), 'wallets', 61),
        sidebar_setting_item('expense_categories', 'Expense Categories', app_path('categories/index.php'), 'wallets', 62),
        sidebar_setting_item('money_in', 'Money In', app_path('moneyin/index.php'), 'wallets', 63),
        sidebar_setting_item('expenses', 'Expenses', app_path('expenses/index.php'), 'wallets', 64),
        sidebar_setting_item('transfers', 'Transfers', app_path('transfers/index.php'), 'wallets', 65),
        sidebar_setting_item('transactions', 'Transactions', app_path('transactions/index.php'), 'wallets', 66),
        sidebar_setting_item('products', 'Products', '', '', 70),
        sidebar_setting_item('product_categories', 'Categories', app_path('product_categories/index.php'), 'products', 71),
        sidebar_setting_item('product_list', 'Products', app_path('products/index.php'), 'products', 72),
        sidebar_setting_item('expired_product', 'Expired Product', app_path('products/expired.php'), 'products', 73),
        sidebar_setting_item('project_package', $module, '', '', 80),
        sidebar_setting_item('project_package_projects', $project, app_path('project_package/projects.php'), 'project_package', 81),
        sidebar_setting_item('project_package_packages', $package_list, app_path('project_package/packages.php'), 'project_package', 82),
        sidebar_setting_item('customer_manage', 'Customer Manage', '', '', 90),
        sidebar_setting_item('create_customer', 'Create Customer', app_path('customers/index.php'), 'customer_manage', 91),
        sidebar_setting_item('customer_form_settings', 'Cus. form settings', app_path('customers/form_settings.php'), 'customer_manage', 92),
        sidebar_setting_item('suppliers', 'Suppliers', '', '', 100),
        sidebar_setting_item('supplier_list', 'Suppliers', app_path('suppliers/index.php'), 'suppliers', 101),
        sidebar_setting_item('purchases', 'Purchases', app_path('purchases/index.php'), 'suppliers', 102),
        sidebar_setting_item('supplier_due_payment', 'Supplier Due Payment', app_path('suppliers/supplier_payment.php'), 'suppliers', 103),
        sidebar_setting_item('lead_management', 'Lead Management', '', '', 110),
        sidebar_setting_item('new_lead', 'New Lead', app_path('lead_management/index.php?filter=lead'), 'lead_management', 111),
        sidebar_setting_item('qualified_list', 'Qualified List', app_path('lead_management/index.php?filter=successful'), 'lead_management', 112),
        sidebar_setting_item('not_qualified_list', 'Not Qualified List', app_path('lead_management/index.php?filter=not_qualified'), 'lead_management', 113),
        sidebar_setting_item('successful_list', 'Successful List', app_path('lead_management/index.php?filter=customer'), 'lead_management', 114),
        sidebar_setting_item('land_ledger', 'Land Ledger', '', '', 115),
        sidebar_setting_item('land_manage', 'Land Manage', app_path('land/land_manage.php'), 'land_ledger', 116),
        sidebar_setting_item('plot_manage', 'Plot Manage', app_path('land/plot_manage.php'), 'land_ledger', 117),
        sidebar_setting_item('land_summary', 'Land Summary', app_path('land/land_summary.php'), 'land_ledger', 118),
        sidebar_setting_item('reports', 'Reports', '', '', 120),
        sidebar_setting_item('sales_report', 'Sales Report', app_path('reports/sales_report.php'), 'reports', 121),
        sidebar_setting_item('expense_report', 'Expense Report', app_path('reports/category_expense.php'), 'reports', 122),
        sidebar_setting_item('profit_report', 'Profit Report', app_path('reports/profit_report.php'), 'reports', 123),
        sidebar_setting_item('access_management', 'Access Management', app_path('user_management/index.php'), '', 130),
        sidebar_setting_item('attendance_settings', 'Attendance Settings', app_path('staff/attendance_settings.php'), '', 131),
        sidebar_setting_item('wallet_approvals', 'Wallet Approvals', app_path('user_management/wallet_approvals.php'), '', 132),
        sidebar_setting_item('invoice_charges', 'Invoice Charges', app_path('user_management/invoice_charges.php'), '', 133),
        sidebar_setting_item('printing_option', 'Printing Option', app_path('user_management/printing_option.php'), '', 134),
        sidebar_setting_item('profit_cash_out', 'Profit Cash Out', app_path('profit_cash_out/index.php'), '', 135),
        sidebar_setting_item('sidebar_settings', 'Slidebar Settings', app_path('user_management/sidebar_settings.php'), '', 136),
        sidebar_setting_item('tools', 'Tools', '', '', 140),
        sidebar_setting_item('export_data', 'Export Data', app_path('tools/export.php'), 'tools', 141),
        sidebar_setting_item('import_data', 'Import Data', app_path('tools/import.php'), 'tools', 142),
        sidebar_setting_item('delete_all_data', 'Delete All Data', app_path('tools/delete_data.php'), 'tools', 143),
        sidebar_setting_item('full_db_export', 'Full DB Export', app_path('tools/database_export.php'), 'tools', 144),
        sidebar_setting_item('full_db_import', 'Full DB Import', app_path('tools/database_import.php'), 'tools', 145),
        sidebar_setting_item('help', 'Help', '', '', 150),
        sidebar_setting_item('video_tutorial', 'Video Tutorial', app_path('help/video_tutorial.php'), 'help', 151),
        sidebar_setting_item('pricing_plan', 'Pricing Plan', app_path('help/pricing_plan.php'), 'help', 152),
        sidebar_setting_item('support', 'Support', app_path('help/support.php'), 'help', 153),
        sidebar_setting_item('logout', 'Logout', app_path('logout.php'), '', 160),
    ];
}

function sidebar_setting_map_by_id($items)
{
    $map = [];
    foreach($items as $item){
        $map[$item['id']] = $item;
    }
    return $map;
}

function sidebar_load_layout($conn, $user_id, $project_package_labels = [])
{
    ensure_sidebar_settings_table($conn);
    $defaults = sidebar_default_layout_items($project_package_labels);
    $default_map = sidebar_setting_map_by_id($defaults);
    $saved = [];

    $stmt = mysqli_prepare($conn, 'SELECT layout_json FROM sidebar_settings WHERE user_id=? LIMIT 1');
    if($stmt){
        mysqli_stmt_bind_param($stmt, 'i', $user_id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        $decoded = json_decode($row['layout_json'] ?? '[]', true);
        $saved = is_array($decoded) ? $decoded : [];
    }

    foreach($saved as $saved_item){
        $id = (string)($saved_item['id'] ?? '');
        if($id === '' || !isset($default_map[$id])){
            continue;
        }
        $default_map[$id]['parent'] = (string)($saved_item['parent'] ?? '');
        $default_map[$id]['sort'] = (int)($saved_item['sort'] ?? $default_map[$id]['sort']);
        $default_map[$id]['visible'] = !isset($saved_item['visible']) || (int)$saved_item['visible'] === 1 ? 1 : 0;
    }

    $items = array_values($default_map);
    usort($items, static function($a, $b){
        return ((int)$a['sort'] <=> (int)$b['sort']) ?: strcmp($a['label'], $b['label']);
    });

    return $items;
}

function sidebar_saved_layout_exists($conn, $user_id)
{
    ensure_sidebar_settings_table($conn);
    $stmt = mysqli_prepare($conn, 'SELECT id FROM sidebar_settings WHERE user_id=? LIMIT 1');
    if(!$stmt){
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return $result && mysqli_fetch_assoc($result) !== null;
}

function sidebar_layout_items_for_current_user($items)
{
    $allowed = [];
    $super_admin_only = [
        'super_admin',
        'subscription',
        'message_setup',
        'settings',
        'company_delete_data',
        'marketing',
        'full_db_export',
        'full_db_import',
    ];
    $admin_only = [
        'export_data',
        'import_data',
        'delete_all_data',
    ];
    $land_ledger_items = ['land_ledger', 'land_manage', 'plot_manage', 'land_summary'];
    $conn = $GLOBALS['conn'] ?? null;
    $company_id = (int)($_SESSION['user_id'] ?? 0);
    $is_housing_company = $conn instanceof mysqli
        && function_exists('project_package_company_type')
        && project_package_company_type($conn, $company_id) === 'Housing';

    foreach($items as $item){
        $id = (string)($item['id'] ?? '');

        if(in_array($id, $super_admin_only, true) && !is_super_admin_user()){
            continue;
        }

        if(in_array($id, $admin_only, true) && is_super_admin_user()){
            continue;
        }

        if(in_array($id, $land_ledger_items, true) && !$is_housing_company){
            continue;
        }

        if(in_array($id, ['products', 'product_categories', 'product_list', 'expired_product'], true) && !products_module_enabled()){
            continue;
        }

        if($id === 'expired_product' && (!isset($GLOBALS['conn']) || !($GLOBALS['conn'] instanceof mysqli) || !is_product_expiry_enabled($GLOBALS['conn']))){
            continue;
        }

        if($id === 'pricing_plan'){
            $conn = $GLOBALS['conn'] ?? null;
            $company_id = (int)($_SESSION['user_id'] ?? 0);
            if(!is_admin_user() || !($conn instanceof mysqli) || (!is_super_admin_user() && !company_pricing_plan_visible($conn, $company_id))){
                continue;
            }
        }

        $allowed[$id] = $item;
    }

    foreach($allowed as $id => $item){
        $parent = (string)($item['parent'] ?? '');
        if($parent !== '' && !isset($allowed[$parent])){
            $allowed[$id]['parent'] = '';
        }
    }

    return array_values($allowed);
}

function sidebar_save_layout($conn, $user_id, $items)
{
    ensure_sidebar_settings_table($conn);
    $clean = [];
    foreach($items as $item){
        $id = trim((string)($item['id'] ?? ''));
        if($id === ''){
            continue;
        }
        $parent = trim((string)($item['parent'] ?? ''));
        if($parent === $id){
            $parent = '';
        }
        $clean[] = [
            'id' => $id,
            'parent' => $parent,
            'sort' => (int)($item['sort'] ?? 0),
            'visible' => !isset($item['visible']) || (int)$item['visible'] === 1 ? 1 : 0,
        ];
    }

    $json = json_encode($clean);
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO sidebar_settings (user_id, layout_json) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE layout_json=VALUES(layout_json), updated_at=NOW()'
    );
    mysqli_stmt_bind_param($stmt, 'is', $user_id, $json);
    return mysqli_stmt_execute($stmt);
}
