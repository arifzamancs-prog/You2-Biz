<?php
require_once __DIR__ . '/supplier_label_helper.php';

require_once __DIR__ . '/product_expiry_helper.php';
require_once __DIR__ . '/pricing_plan_visibility_helper.php';
require_once __DIR__ . '/multi_branch_helper.php';
require_once __DIR__ . '/restaurant_module_helper.php';

function ensure_sidebar_settings_table($conn)
{
    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS sidebar_settings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            company_type VARCHAR(100) NOT NULL DEFAULT '',
            layout_json LONGTEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_sidebar_settings_user_type (user_id, company_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $has_company_type = false;
    $columns = mysqli_query($conn, "SHOW COLUMNS FROM sidebar_settings LIKE 'company_type'");
    if($columns && mysqli_fetch_assoc($columns)) $has_company_type = true;
    if(!$has_company_type) mysqli_query($conn, "ALTER TABLE sidebar_settings ADD COLUMN company_type VARCHAR(100) NOT NULL DEFAULT '' AFTER user_id");

    $has_legacy_key = false;
    $has_type_key = false;
    $indexes = mysqli_query($conn, 'SHOW INDEX FROM sidebar_settings');
    while($indexes && ($index = mysqli_fetch_assoc($indexes))){
        if($index['Key_name'] === 'uniq_sidebar_settings_user') $has_legacy_key = true;
        if($index['Key_name'] === 'uniq_sidebar_settings_user_type') $has_type_key = true;
    }
    if($has_legacy_key) mysqli_query($conn, 'ALTER TABLE sidebar_settings DROP INDEX uniq_sidebar_settings_user');
    if(!$has_type_key) mysqli_query($conn, 'ALTER TABLE sidebar_settings ADD UNIQUE KEY uniq_sidebar_settings_user_type (user_id, company_type)');
}

function sidebar_settings_company_type($conn, $user_id)
{
    if(function_exists('project_package_company_type')){
        return trim((string)project_package_company_type($conn, (int)$user_id));
    }
    return '';
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

function sidebar_setting_item($id, $label, $href = '', $parent = '', $sort = 0, $section = '', $visible = 1)
{
    return [
        'id' => $id,
        'label' => $label,
        'href' => $href,
        'parent' => $parent,
        'sort' => $sort,
        'section' => $section !== '' ? $section : sidebar_setting_section_for_sort($sort),
        'visible' => (int)$visible === 1 ? 1 : 0,
    ];
}

function sidebar_default_layout_items($project_package_labels = [], $company_type = '')
{
    $module = $project_package_labels['module'] ?? 'Project & Package';
    $project = $project_package_labels['project'] ?? 'Project';
    $package_list = $project_package_labels['package_list'] ?? 'Package';
    $lead_management_visible = !in_array($company_type, ['Stock Product', 'Fashion house'], true);
    // Fashion retailers and restaurants normally sell through invoices; keep customer
    // management opt-in so an admin can enable it only when it is needed.
    $customer_manage_visible = !in_array($company_type, ['Fashion house', 'Restaurant & Cafe'], true);

    return [
        sidebar_setting_item('dashboard', 'Dashboard', app_path('dashboard.php'), '', 10),
        sidebar_setting_item('staff_manage', 'Staff Manage', '', '', 42, 'OPERATIONS'),
        sidebar_setting_item('staff_create', 'Create Staff', app_path('staff/index.php'), 'staff_manage', 43, 'OPERATIONS'),
        sidebar_setting_item('staff_attendance', 'Staff Attendance', app_path('staff/attendance.php'), 'staff_manage', 44, 'OPERATIONS'),
        sidebar_setting_item('staff_salary', 'Staff Salary', app_path('staff/salary.php'), 'staff_manage', 45, 'OPERATIONS'),
        sidebar_setting_item('staff_ledger', 'Other Payments', app_path('staff/ledger.php'), 'staff_manage', 46, 'OPERATIONS'),
        sidebar_setting_item('leave_application', 'Leave Application', app_path('staff/leave_application.php'), 'staff_manage', 47, 'OPERATIONS'),
        sidebar_setting_item('super_admin', 'Super Admin', '', '', 30),
        sidebar_setting_item('subscription', 'Subscription', app_path('super_admin/index.php'), 'super_admin', 31),
        sidebar_setting_item('eshop', 'E-shop', app_path('eshop/index.php'), '', 54),
        sidebar_setting_item('message_setup', 'Message Setup', app_path('super_admin/signup_message.php'), 'super_admin', 32),
        sidebar_setting_item('settings', 'Settings', app_path('super_admin/email_sms_settings.php'), 'super_admin', 33),
        sidebar_setting_item('company_delete_data', 'Company Delete Data', app_path('super_admin/company_delete_data.php'), 'super_admin', 34),
        sidebar_setting_item('marketing', 'Marketing', app_path('user_management/marketing.php'), 'super_admin', 35),
        sidebar_setting_item('notice_publish', 'Notice Publish', app_path('user_management/notice_publish.php'), '', 48),
        sidebar_setting_item('parking_action', 'Action', '', '', 40, 'OPERATIONS'),
        sidebar_setting_item('parking_entry', 'New Entry', app_path('parking/entry.php'), 'parking_action', 41, 'OPERATIONS'),
        sidebar_setting_item('parking_exit', 'Vehicle Exit', app_path('parking/exit.php'), 'parking_action', 42, 'OPERATIONS'),
        sidebar_setting_item('parking_lost_ticket', 'Lost Ticket', app_path('parking/lost_ticket.php'), 'parking_action', 43, 'OPERATIONS'),
        sidebar_setting_item('parking_shift', 'Shift', app_path('parking/shift.php'), 'parking_action', 44, 'OPERATIONS'),
        sidebar_setting_item('parking_management', 'Parking Setup', app_path('parking/setup.php'), '', 41, 'OPERATIONS'),
        sidebar_setting_item('sales', 'Sales / Invoice', '', '', 50),
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
        sidebar_setting_item('customer_manage', 'Customer Manage', '', '', 90, '', $customer_manage_visible),
        sidebar_setting_item('create_customer', 'Create Customer', app_path('customers/index.php'), 'customer_manage', 91),
        sidebar_setting_item('customer_form_settings', 'Cus. form settings', app_path('customers/form_settings.php'), 'customer_manage', 92),
        sidebar_setting_item('customer_login_settings', 'Cus. Login Settings', app_path('user_management/customer_login_settings.php'), 'customer_manage', 93),
        sidebar_setting_item('suppliers', supplier_display_text('Suppliers'), '', '', 74),
        sidebar_setting_item('warehouse', 'Main Warehouse', app_path('warehouse/index.php'), '', 75),
        sidebar_setting_item('live_report', 'Stock Live Report', app_path('warehouse/live_report.php'), '', 76),
        sidebar_setting_item('stock_sales', 'Sales', '', '', 76),
        sidebar_setting_item('menu_' . substr(sha1('Table Management'), 0, 16), 'Table Management', '', '', 77, 'OPERATIONS'),
        sidebar_setting_item('stock_create', 'Create Invoice', app_path('sales/create_invoice.php'), 'stock_sales', 1),
        sidebar_setting_item('stock_invoices', 'Invoice List', app_path('sales/invoice_list.php'), 'stock_sales', 2),
        sidebar_setting_item('stock_product', 'My Stock', app_path('sales/stock_product.php'), 'stock_sales', 3),
        sidebar_setting_item('stock_damaged_return', 'Return Damaged', app_path('sales/damaged_return.php'), 'stock_sales', 4),
        sidebar_setting_item('stock_receive_request', 'Product Req.', app_path('sales/receive_stock.php'), 'stock_sales', 5),
        sidebar_setting_item('stock_due', 'Due Payment', app_path('sales/receive_payment.php'), 'stock_sales', 6),
        sidebar_setting_item('stock_report', 'Sales Report', app_path('warehouse/sales_report.php'), 'stock_sales', 7),
        sidebar_setting_item('supplier_list', supplier_display_text('Suppliers'), app_path('suppliers/index.php'), 'suppliers', 101),
        sidebar_setting_item('purchases', 'Purchases', app_path('purchases/index.php'), 'suppliers', 102),
        sidebar_setting_item('supplier_due_payment', supplier_display_text('Supplier Due Payment'), app_path('suppliers/supplier_payment.php'), 'suppliers', 103),
        sidebar_setting_item('lead_management', 'Lead Management', '', '', 110, '', $lead_management_visible),
        sidebar_setting_item('new_lead', 'New Lead', app_path('lead_management/index.php?filter=lead'), 'lead_management', 111),
        sidebar_setting_item('qualified_list', 'Qualified List', app_path('lead_management/index.php?filter=successful'), 'lead_management', 112),
        sidebar_setting_item('not_qualified_list', 'Not Qualified List', app_path('lead_management/index.php?filter=not_qualified'), 'lead_management', 113),
        sidebar_setting_item('visited_list', 'Visited List', app_path('lead_management/index.php?filter=visited'), 'lead_management', 114),
        sidebar_setting_item('indecision_list', 'Indecision List', app_path('lead_management/index.php?filter=indecision'), 'lead_management', 115),
        sidebar_setting_item('successful_list', 'Successful List', app_path('lead_management/index.php?filter=customer'), 'lead_management', 116),
        sidebar_setting_item('land_ledger', 'Land Ledger', '', '', 117),
        sidebar_setting_item('land_manage', 'Land Manage', app_path('land/land_manage.php'), 'land_ledger', 118),
        sidebar_setting_item('plot_manage', 'Plot Manage', app_path('land/plot_manage.php'), 'land_ledger', 119),
        sidebar_setting_item('land_summary', 'Land Summary', app_path('land/land_summary.php'), 'land_ledger', 120),
        sidebar_setting_item('reports', 'Reports', '', '', 121),
        sidebar_setting_item('sales_report', 'Sales Report', app_path('reports/sales_report.php'), 'reports', 122),
        sidebar_setting_item('expense_report', 'Expense Report', app_path('reports/category_expense.php'), 'reports', 123),
        sidebar_setting_item('profit_report', 'Profit Report', app_path('reports/profit_report.php'), 'reports', 124),
        sidebar_setting_item('admin', 'Admin', '', '', 130),
        sidebar_setting_item('access_management', 'Access Management', app_path('user_management/index.php'), 'admin', 131),
        sidebar_setting_item('attendance_settings', 'Attendance Settings', app_path('staff/attendance_settings.php'), 'admin', 132),
        sidebar_setting_item('branch_manage', 'Branch Management', app_path('user_management/branch_manage.php'), 'admin', 133),
        sidebar_setting_item('wallet_approvals', 'Wallet Approvals', app_path('user_management/wallet_approvals.php'), 'admin', 134),
        sidebar_setting_item('dump_approval', 'Dumps Approval', app_path('user_management/dump_approval.php'), 'admin', 135),
        sidebar_setting_item('invoice_charges', 'Invoice Charges', app_path('user_management/invoice_charges.php'), 'admin', 135),
        sidebar_setting_item('printing_option', 'Printing Option', app_path('user_management/printing_option.php'), 'admin', 136),
        sidebar_setting_item('profit_cash_out', 'Wallet Cash Out', app_path('profit_cash_out/index.php'), 'admin', 137),
        sidebar_setting_item('sidebar_settings', 'Slidebar Settings', app_path('user_management/sidebar_settings.php'), 'admin', 138),
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
    $company_type = sidebar_settings_company_type($conn, $user_id);
    $defaults = sidebar_default_layout_items($project_package_labels, $company_type);
    $default_map = sidebar_setting_map_by_id($defaults);
    $saved = [];

    $stmt = mysqli_prepare($conn, 'SELECT layout_json FROM sidebar_settings WHERE user_id=? AND company_type=? LIMIT 1');
    if($stmt){
        mysqli_stmt_bind_param($stmt, 'is', $user_id, $company_type);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        $decoded = json_decode($row['layout_json'] ?? '[]', true);
        $saved = is_array($decoded) ? $decoded : [];
    }

    // Migrate a pre-company-type preference to the company's current type.
    if(!$saved && $company_type !== ''){
        $legacy_stmt = mysqli_prepare($conn, "SELECT layout_json FROM sidebar_settings WHERE user_id=? AND company_type='' LIMIT 1");
        mysqli_stmt_bind_param($legacy_stmt, 'i', $user_id);
        mysqli_stmt_execute($legacy_stmt);
        $legacy_row = mysqli_fetch_assoc(mysqli_stmt_get_result($legacy_stmt));
        if($legacy_row){
            $saved = json_decode($legacy_row['layout_json'] ?? '[]', true);
            $saved = is_array($saved) ? $saved : [];
            $move_stmt = mysqli_prepare($conn, "UPDATE sidebar_settings SET company_type=? WHERE user_id=? AND company_type=''");
            mysqli_stmt_bind_param($move_stmt, 'si', $company_type, $user_id);
            mysqli_stmt_execute($move_stmt);
        }
    }

    foreach($saved as $saved_item){
        $id = (string)($saved_item['id'] ?? '');
        if($id === '' || !isset($default_map[$id])){
            continue;
        }
        $default_map[$id]['parent'] = (string)($saved_item['parent'] ?? '');
        $default_map[$id]['sort'] = (int)($saved_item['sort'] ?? $default_map[$id]['sort']);
        $default_map[$id]['visible'] = !isset($saved_item['visible']) || (int)$saved_item['visible'] === 1 ? 1 : 0;
        if (in_array($saved_item['section'] ?? '', ['MAIN','OPERATIONS','INSIGHTS','ADMIN','HELP','SESSION'], true)) {
            $default_map[$id]['section'] = $saved_item['section'];
        }
    }

    $lead_management_default_hidden_update = in_array($company_type, ['Stock Product', 'Fashion house'], true)
        && !in_array('__lead_management_default_hidden_v1', array_column($saved, 'id'), true);
    if($lead_management_default_hidden_update){
        if(isset($default_map['lead_management'])){
            $default_map['lead_management']['visible'] = 0;
        }
    }
    $lead_management_submenus_visible_update = in_array($company_type, ['Stock Product', 'Fashion house'], true)
        && !in_array('__lead_management_submenus_visible_v2', array_column($saved, 'id'), true);
    if($lead_management_submenus_visible_update){
        foreach(['new_lead', 'qualified_list', 'not_qualified_list', 'visited_list', 'indecision_list', 'successful_list'] as $lead_item_id){
            if(isset($default_map[$lead_item_id])){
                $default_map[$lead_item_id]['visible'] = 1;
            }
        }
    }
    $customer_manage_hidden_marker = $company_type === 'Restaurant & Cafe'
        ? '__restaurant_customer_manage_default_hidden_v1'
        : '__fashion_customer_manage_default_hidden_v1';
    $customer_manage_default_hidden_update = in_array($company_type, ['Fashion house', 'Restaurant & Cafe'], true)
        && !in_array($customer_manage_hidden_marker, array_column($saved, 'id'), true);
    if($customer_manage_default_hidden_update && isset($default_map['customer_manage'])){
        $default_map['customer_manage']['visible'] = 0;
    }

    // These are structural children of the Admin menu. Older saved sidebar
    // layouts stored them as root items, which split the Admin menu in two.
    $admin_children = [
        'access_management',
        'attendance_settings',
        'branch_manage',
        'wallet_approvals',
        'dump_approval',
        'invoice_charges',
        'printing_option',
        'profit_cash_out',
        'sidebar_settings',
    ];
    if(isset($default_map['admin'])){
        $default_map['admin']['parent'] = '';
        $default_map['admin']['visible'] = 1;
    }
    foreach($admin_children as $admin_child){
        if(isset($default_map[$admin_child])){
            $default_map[$admin_child]['parent'] = 'admin';
        }
    }

    // Products are the primary catalogue for stock companies. A saved
    // sidebar preference must not make the only product add/list entry vanish.
    if(function_exists('project_package_company_type')
        && in_array(project_package_company_type($conn, (int)$user_id), ['Stock Product', 'Fashion house'], true)){
        foreach(['products', 'product_categories', 'product_list'] as $required_product_item){
            if(isset($default_map[$required_product_item])){
                $default_map[$required_product_item]['visible'] = 1;
            }
        }
        // Fashion House uses My Stock for branch inventory. Do not show
        // the legacy warehouse Sales Report in a saved sidebar layout.
        if(isset($default_map['stock_report'])){
            $default_map['stock_report']['visible'] = 0;
        }
    }

    $items = array_values($default_map);
    // Register the new report beside Warehouse even for older saved layouts.
    $saved_ids = array_column($saved, 'id');
    if (!in_array('live_report', $saved_ids, true) && isset($default_map['warehouse'])) {
        $warehouse_sort = (int)$default_map['warehouse']['sort'];
        foreach ($items as &$layout_item) {
            if ($layout_item['id'] === 'live_report') {
                $layout_item['parent'] = $default_map['warehouse']['parent'];
                $layout_item['sort'] = $warehouse_sort + 1;
            } elseif ((int)$layout_item['sort'] > $warehouse_sort) {
                $layout_item['sort'] = (int)$layout_item['sort'] + 1;
            }
        }
        unset($layout_item);
    }
    // Match the company-specific order applied by the visible sidebar,
    // including when an older saved layout is present.
    if (!$saved && function_exists('project_package_company_type')
        && project_package_company_type($conn, (int)$user_id) === 'Fashion house'
        && company_multi_branch_enabled($conn, (int)$user_id)) {
        $operations_order = array_flip([
            'stock_sales', 'wallets', 'products', 'suppliers', 'warehouse',
            'staff_manage', 'live_report', 'customer_manage',
            'lead_management', 'notice_publish',
        ]);
        foreach ($items as &$layout_item) {
            if (isset($operations_order[$layout_item['id']])) {
                $layout_item['section'] = 'OPERATIONS';
                // The sidebar reorders top-level items; preserve custom submenus.
                if ($layout_item['parent'] === '') {
                    $layout_item['sort'] = 40 + $operations_order[$layout_item['id']];
                }
            } elseif ($layout_item['parent'] === '' && $layout_item['section'] === 'OPERATIONS') {
                $layout_item['sort'] = 60 + (int)$layout_item['sort'];
            }
        }
        unset($layout_item);
        foreach ($items as &$layout_item) {
            if ($layout_item['parent'] === 'staff_manage') {
                $layout_item['section'] = 'OPERATIONS';
            }
        }
        unset($layout_item);
    }
    $upgrade_housing_order = function_exists('project_package_company_type')
        && project_package_company_type($conn, (int)$user_id) === 'Housing'
        && !in_array('__housing_operations_v1', array_column($saved, 'id'), true);
    $upgrade_inventory_order = in_array($company_type, ['Stock Product', 'Fashion house'], true)
        && !in_array('__inventory_operations_v2', array_column($saved, 'id'), true);
    $stock_product_operations_order_update = $company_type === 'Stock Product'
        && !in_array('__stock_product_operations_order_v3', array_column($saved, 'id'), true);
    $fashion_house_operations_order_update = $company_type === 'Fashion house'
        && !in_array('__fashion_house_operations_order_v3', array_column($saved, 'id'), true);
    $upgrade_parking_order = $company_type === 'Car Parking'
        && !in_array('__parking_operations_v1', array_column($saved, 'id'), true);
    $upgrade_restaurant_order = $company_type === 'Restaurant & Cafe'
        && !in_array('__restaurant_operations_v1', array_column($saved, 'id'), true);
    if ($upgrade_housing_order || $upgrade_inventory_order || $stock_product_operations_order_update || $fashion_house_operations_order_update || $upgrade_parking_order || $upgrade_restaurant_order) {
        // Keep company defaults and existing layouts consistent with the editor.
        $operations_order = array_flip(($stock_product_operations_order_update || $fashion_house_operations_order_update) ? [
            'stock_sales', 'wallets', 'products', 'suppliers', 'warehouse', 'live_report',
            'customer_manage', 'staff_manage', 'notice_publish',
        ] : ($upgrade_inventory_order ? [
            'stock_sales', 'wallets', 'products', 'suppliers', 'warehouse', 'live_report', 'staff_manage',
            'customer_manage',
        ] : [
            'sales', 'wallets', 'project_package', 'suppliers', 'staff_manage',
            'customer_manage', 'lead_management', 'notice_publish',
        ]));
        if ($upgrade_parking_order) {
            $operations_order = array_flip(['parking_action', 'wallets', 'parking_management', 'staff_manage']);
        }
        if ($upgrade_restaurant_order) {
            $operations_order = array_flip([
                'stock_sales', 'wallets', 'products',
                'menu_' . substr(sha1('Table Management'), 0, 16),
                'suppliers', 'staff_manage', 'customer_manage',
            ]);
        }
        foreach ($items as &$layout_item) {
            if (isset($operations_order[$layout_item['id']])) {
                $layout_item['section'] = 'OPERATIONS';
                $layout_item['parent'] = '';
                $layout_item['sort'] = 40 + $operations_order[$layout_item['id']];
            } elseif ($layout_item['parent'] === '' && $layout_item['section'] === 'OPERATIONS') {
                $layout_item['sort'] = 60 + (int)$layout_item['sort'];
            }
            if ($layout_item['parent'] === 'staff_manage') {
                $layout_item['section'] = 'OPERATIONS';
            }
        }
        unset($layout_item);
    }
    if (($upgrade_housing_order || $upgrade_inventory_order || $stock_product_operations_order_update || $fashion_house_operations_order_update || $upgrade_parking_order || $upgrade_restaurant_order || $lead_management_default_hidden_update || $lead_management_submenus_visible_update || $customer_manage_default_hidden_update) && $saved) {
        sidebar_save_layout($conn, $user_id, $items);
    }
    usort($items, static function($a, $b){
        return ((int)$a['sort'] <=> (int)$b['sort']) ?: strcmp($a['label'], $b['label']);
    });

    return $items;
}

function sidebar_saved_layout_exists($conn, $user_id)
{
    ensure_sidebar_settings_table($conn);
    $company_type = sidebar_settings_company_type($conn, $user_id);
    $stmt = mysqli_prepare($conn, 'SELECT id FROM sidebar_settings WHERE user_id=? AND company_type=? LIMIT 1');
    if(!$stmt){
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'is', $user_id, $company_type);
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
    $is_fashion_house = $conn instanceof mysqli
        && function_exists('project_package_company_type')
        && project_package_company_type($conn, $company_id) === 'Fashion house';

    foreach($items as $item){
        $id = (string)($item['id'] ?? '');

        if (restaurant_module_active() && in_array($id, ['stock_product', 'stock_damaged_return', 'stock_receive_request', 'stock_report'], true)) {
            continue;
        }

        if($id === 'branch_manage' && (!($conn instanceof mysqli) || !company_multi_branch_enabled($conn, $company_id))){
            continue;
        }

        // A single-branch company sells directly from Head Office; it has no
        // separate Main Warehouse to manage.
        if($id === 'warehouse' && (!($conn instanceof mysqli) || !company_multi_branch_enabled($conn, $company_id))){
            continue;
        }

        if(in_array($id, $super_admin_only, true) && !is_super_admin_user()){
            continue;
        }

        if(in_array($id, $admin_only, true) && is_super_admin_user()){
            continue;
        }

        if(in_array($id, $land_ledger_items, true) && !$is_housing_company){
            continue;
        }

        if($id === 'live_report' && (!($conn instanceof mysqli)
            || !in_array(project_package_company_type($conn, $company_id), ['Stock Product', 'Fashion house'], true)
            || !company_multi_branch_enabled($conn, $company_id))){
            continue;
        }

        if($id === 'dump_approval' && !$is_fashion_house){
            continue;
        }

        if(in_array($id, ['products', 'product_categories', 'product_list', 'expired_product', 'suppliers', 'warehouse', 'stock_sales', 'stock_create', 'stock_invoices', 'stock_receive_request', 'stock_due', 'stock_report'], true) && !products_module_enabled() && !($id === 'suppliers' && $is_housing_company)){
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
    $company_type = sidebar_settings_company_type($conn, $user_id);
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
            'section' => in_array($item['section'] ?? '', ['MAIN','OPERATIONS','INSIGHTS','ADMIN','HELP','SESSION'], true) ? $item['section'] : 'OPERATIONS',
        ];
    }

    if ($company_type === 'Housing') {
        $clean[] = ['id' => '__housing_operations_v1'];
    }
    if (in_array($company_type, ['Stock Product', 'Fashion house'], true)) {
        $clean[] = ['id' => '__inventory_operations_v2'];
        $clean[] = ['id' => '__lead_management_default_hidden_v1'];
        $clean[] = ['id' => '__lead_management_submenus_visible_v2'];
    }
    if ($company_type === 'Stock Product') {
        $clean[] = ['id' => '__stock_product_operations_order_v3'];
    }
    if ($company_type === 'Fashion house') {
        $clean[] = ['id' => '__fashion_house_operations_order_v3'];
        $clean[] = ['id' => '__fashion_customer_manage_default_hidden_v1'];
    }
    if ($company_type === 'Car Parking') {
        $clean[] = ['id' => '__parking_operations_v1'];
    }
    if ($company_type === 'Restaurant & Cafe') {
        $clean[] = ['id' => '__restaurant_operations_v1'];
        $clean[] = ['id' => '__restaurant_customer_manage_default_hidden_v1'];
    }
    $json = json_encode($clean);
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO sidebar_settings (user_id, company_type, layout_json) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE layout_json=VALUES(layout_json), updated_at=NOW()'
    );
    mysqli_stmt_bind_param($stmt, 'iss', $user_id, $company_type, $json);
    return mysqli_stmt_execute($stmt);
}

// Build the editor and saved layout from the menus actually authorized and
// rendered by sidebar.php. Disabled company features never enter this list.
function sidebar_rendered_layout($html, $conn, $user_id, $labels = [])
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);
    $known = sidebar_load_layout($conn, $user_id, $labels);
    $company_type = sidebar_settings_company_type($conn, $user_id);
    $saved = [];
    $stmt = mysqli_prepare($conn, 'SELECT layout_json FROM sidebar_settings WHERE user_id=? AND company_type=?');
    mysqli_stmt_bind_param($stmt, 'is', $user_id, $company_type);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    foreach (json_decode($row['layout_json'] ?? '[]', true) ?: [] as $item) $saved[$item['id']] = $item;
    $result = [];
    $walk = function ($list, $parent = '', $section = 'MAIN') use (&$walk, &$result, $xpath, $known, $saved) {
        foreach ($list->childNodes as $node) {
            if (!($node instanceof DOMElement) || $node->tagName !== 'li') continue;
            if (strpos($node->getAttribute('class'), 'nav-header') !== false) {
                $section = trim($node->textContent);
                continue;
            }
            $link = $xpath->query('./a', $node)->item(0);
            if (!$link) continue;
            $p = $xpath->query('./p', $link)->item(0);
            $label = '';
            if ($p) foreach ($p->childNodes as $part) if ($part instanceof DOMText) $label .= $part->textContent;
            $label = trim($label);
            $href = $link->getAttribute('href');
            $href = $href === '#' ? '' : $href;
            $match = null;
            foreach ($known as $candidate) {
                if (($href !== '' && $candidate['href'] === $href) || ($href === '' && $candidate['href'] === '' && $candidate['label'] === $label)) {
                    $match = $candidate;
                    break;
                }
            }
            $id = $match['id'] ?? ('menu_' . substr(sha1($href ?: $label), 0, 16));
            $item = sidebar_setting_item($id, $label, $href, $parent, count($result) * 10, $section);
            if ($match) {
                $item['section'] = $match['section'];
                $item['sort'] = $match['sort'];
                $item['visible'] = $match['visible'];
            }
            if (isset($saved[$id])) foreach (['parent','sort','visible','section'] as $key) {
                if (isset($saved[$id][$key])) $item[$key] = $saved[$id][$key];
            }
            if ($id === 'sidebar_settings') $item['visible'] = 1;
            $result[$id] = $item;
            $children = $xpath->query('./ul', $node)->item(0);
            if ($children) $walk($children, $id, $section);
        }
    };
    $list = $xpath->query('//ul[contains(concat(" ",normalize-space(@class)," ")," nav-sidebar ")]')->item(0);
    if ($list) $walk($list);
    foreach ($result as $id => &$item) {
        $seen = [$id => true];
        $parent = $item['parent'];
        while ($parent !== '') {
            if (!isset($result[$parent]) || isset($seen[$parent])) { $item['parent'] = ''; break; }
            $seen[$parent] = true;
            $parent = $result[$parent]['parent'];
        }
    }
    unset($item);
    usort($result, static function ($a, $b) { return (int)$a['sort'] <=> (int)$b['sort']; });
    return $result;
}
