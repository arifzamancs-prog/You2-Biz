<?php

require_once __DIR__ . '/product_expiry_helper.php';
require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/restaurant_table_helper.php';
require_once __DIR__ . '/staff_incentive_helper.php';

$sidebar_avatar_file = $_SESSION['avatar'] ?? 'you2biz.png';
$sidebar_name = $_SESSION['user_name'] ?? 'Profile';

if(is_manager_user() && isset($conn) && $conn instanceof mysqli){
    $company_user_id = (int)($_SESSION['user_id'] ?? 0);
    $login_user_id = (int)($_SESSION['login_user_id'] ?? 0);

    if($login_user_id > 0){
        $company_from_login_stmt = mysqli_prepare(
            $conn,
            "SELECT
                company.id,
                company.name,
                company.avatar
             FROM users login_user
             LEFT JOIN users company
                ON company.id = CASE
                    WHEN login_user.owner_id IS NULL OR login_user.owner_id = 0
                    THEN login_user.id
                    ELSE login_user.owner_id
                END
             WHERE login_user.id=?
             LIMIT 1"
        );

        if($company_from_login_stmt){
            mysqli_stmt_bind_param($company_from_login_stmt, "i", $login_user_id);
            mysqli_stmt_execute($company_from_login_stmt);
            $company_from_login_result = mysqli_stmt_get_result($company_from_login_stmt);
            $company_from_login = $company_from_login_result
                ? mysqli_fetch_assoc($company_from_login_result)
                : null;

            if(
                $company_from_login &&
                (int)($company_from_login['id'] ?? 0) > 0 &&
                (int)$company_from_login['id'] !== $login_user_id
            ){
                $company_user_id = (int)$company_from_login['id'];
                $sidebar_name = $company_from_login['name'] ?: $sidebar_name;
                $sidebar_avatar_file = $company_from_login['avatar'] ?: $sidebar_avatar_file;
                $_SESSION['user_id'] = $company_user_id;
                $_SESSION['user_name'] = $sidebar_name;
                $_SESSION['avatar'] = $sidebar_avatar_file;
            }
        }
    }

    if($company_user_id === $login_user_id && $login_user_id > 0){
        $fallback_owner_result = mysqli_query(
            $conn,
            "SELECT id
             FROM users
             WHERE role='admin'
             AND status='active'
             ORDER BY id DESC
             LIMIT 1"
        );

        $fallback_owner = $fallback_owner_result
            ? mysqli_fetch_assoc($fallback_owner_result)
            : null;

        if($fallback_owner && (int)$fallback_owner['id'] > 0){
            $company_user_id = (int)$fallback_owner['id'];
            $_SESSION['user_id'] = $company_user_id;

            $owner_update_stmt = mysqli_prepare(
                $conn,
                "UPDATE users
                 SET owner_id=?
                 WHERE id=?
                 AND role='manager'"
            );

            if($owner_update_stmt){
                mysqli_stmt_bind_param(
                    $owner_update_stmt,
                    "ii",
                    $company_user_id,
                    $login_user_id
                );
                mysqli_stmt_execute($owner_update_stmt);
            }
        }
    }

    if($company_user_id > 0){
        $company_stmt = mysqli_prepare(
            $conn,
            "SELECT name, avatar
             FROM users
             WHERE id=?
             LIMIT 1"
        );

        if($company_stmt){
            mysqli_stmt_bind_param($company_stmt, "i", $company_user_id);
            mysqli_stmt_execute($company_stmt);
            $company_result = mysqli_stmt_get_result($company_stmt);
            $company = $company_result ? mysqli_fetch_assoc($company_result) : null;

            if($company){
                $sidebar_name = $company['name'] ?: $sidebar_name;
                $sidebar_avatar_file = $company['avatar'] ?: $sidebar_avatar_file;
                $_SESSION['user_name'] = $sidebar_name;
                $_SESSION['avatar'] = $sidebar_avatar_file;
            }
        }
    }
}

$sidebar_avatar = app_path('uploads/avatars/you2biz.png');

if(
    !empty($sidebar_avatar_file) &&
    file_exists(
        dirname(__DIR__) .
        '/uploads/avatars/' .
        $sidebar_avatar_file
    )
){
    $sidebar_avatar = app_path('uploads/avatars/') . $sidebar_avatar_file;
}

$sidebar_role = is_super_admin_user()
    ? 'Super Admin'
    : (is_manager_user()
        ? (is_agent_user() ? 'Assistant Access' : 'Manager Access')
        : 'Administrator');
$current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$current_query = [];
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $current_query);
$sidebar_is_super_admin = is_super_admin_user();
$sidebar_is_agent_only = is_agent_user() && !$sidebar_is_super_admin;
$sidebar_can_see_reports = $sidebar_is_super_admin || !is_agent_user();
$sidebar_can_see_admin = role_power_includes('admin');
$sidebar_table_system_enabled = false;

if(!$sidebar_is_super_admin && isset($conn) && $conn instanceof mysqli){
    ensure_restaurant_tables_table($conn);
    $sidebar_table_system_enabled = table_system_enabled(
        $conn,
        (int)($_SESSION['user_id'] ?? 0)
    );
}

function sidebar_is_active($href)
{
    global $current_path, $current_query;

    $href_path = parse_url($href, PHP_URL_PATH);
    if($current_path !== $href_path){
        return false;
    }

    $href_query = [];
    parse_str((string)parse_url($href, PHP_URL_QUERY), $href_query);

    foreach($href_query as $key => $value){
        if(!array_key_exists($key, $current_query) || (string)$current_query[$key] !== (string)$value){
            return false;
        }
    }

    return true;
}

function sidebar_group_active($items)
{
    foreach($items as $item){
        if(sidebar_is_active($item['href'])){
            return true;
        }
    }

    return false;
}

function sidebar_item($href, $label, $icon = 'far fa-circle', $class = '')
{
    $active = sidebar_is_active($href) ? ' active' : '';
?>
    <li class="nav-item">
        <a href="<?= htmlspecialchars($href); ?>"
           class="nav-link<?= $active; ?> <?= htmlspecialchars($class); ?>">
            <i class="nav-icon <?= htmlspecialchars($icon); ?>"></i>
            <p><?= htmlspecialchars($label); ?></p>
        </a>
    </li>
<?php
}

function sidebar_tree($label, $icon, $items)
{
    $open = sidebar_group_active($items);
?>
    <li class="nav-item has-treeview<?= $open ? ' menu-open' : ''; ?>">
        <a href="#"
           class="nav-link<?= $open ? ' active' : ''; ?>">
            <i class="nav-icon <?= htmlspecialchars($icon); ?>"></i>
            <p>
                <?= htmlspecialchars($label); ?>
                <i class="right fas fa-angle-left"></i>
            </p>
        </a>

        <ul class="nav nav-treeview">
            <?php foreach($items as $item){ ?>
                <?php
                sidebar_item(
                    $item['href'],
                    $item['label'],
                    $item['icon'] ?? 'far fa-circle',
                    $item['class'] ?? ''
                );
                ?>
            <?php } ?>
        </ul>
    </li>
<?php
}

function sidebar_has_active_manager()
{
    global $conn;

    if(!isset($conn) || !($conn instanceof mysqli)){
        return false;
    }

    if(is_super_admin_user()){
        $sql = "SELECT COUNT(*)
                FROM users
                WHERE role='manager'
                AND status='active'";

        $stmt = mysqli_prepare($conn, $sql);
    }else{
        $sql = "SELECT COUNT(*)
                FROM users
                WHERE owner_id=?
                AND role='manager'
                AND status='active'";

        $stmt = mysqli_prepare($conn, $sql);
        $user_id = (int)($_SESSION['user_id'] ?? 0);
        mysqli_stmt_bind_param($stmt, "i", $user_id);
    }

    if(!$stmt){
        return false;
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $count = $result ? (int)mysqli_fetch_row($result)[0] : 0;

    return $count > 0;
}

$sidebar_product_items = [
    [
        'href' => app_path('product_categories/index.php'),
        'label' => 'Categories',
    ],
    [
        'href' => app_path('products/index.php'),
        'label' => 'Products',
    ],
];

if(isset($conn) && $conn instanceof mysqli && is_product_expiry_enabled($conn)){
    $sidebar_product_items[] = [
        'href' => app_path('products/expired.php'),
        'label' => 'Expired Product',
    ];
}

?>

<aside class="main-sidebar sidebar-dark-primary elevation-4 app-sidebar">

    <a href="<?= htmlspecialchars(app_path('dashboard.php')); ?>"
       class="brand-link app-brand">
        <img
            src="<?= htmlspecialchars($sidebar_avatar); ?>"
            alt="Account"
            class="brand-image img-circle elevation-2 app-brand-image">

        <span class="brand-text app-brand-text">
            <span class="app-brand-name">
                <?= htmlspecialchars($sidebar_name); ?>
            </span>
            <span class="app-brand-role">
                <?= htmlspecialchars($sidebar_role); ?>
            </span>
        </span>
    </a>

    <div class="sidebar">

        <nav class="mt-3">

            <ul class="nav nav-pills nav-sidebar flex-column nav-compact"
                data-widget="treeview"
                role="menu"
                data-accordion="false">

                <li class="nav-header">MAIN</li>

                <?php sidebar_item(app_path('dashboard.php'), 'Dashboard', 'fas fa-home'); ?>

                <?php sidebar_tree('Staff Manage', 'fas fa-user-tie', [
                    ['href' => app_path('staff/index.php'), 'label' => 'Staff List'],
                    ['href' => app_path('staff/ledger.php'), 'label' => 'Staff Ledger'],
                ]); ?>

                <?php if(is_super_admin_user()){ ?>
                    <?php
                    sidebar_tree(
                        'Super Admin',
                        'fas fa-shield-alt',
                        [
                            [
                                'href' => app_path('super_admin/index.php'),
                                'label' => 'Subscription',
                            ],
                            [
                                'href' => app_path('super_admin/signup_message.php'),
                                'label' => 'Message Setup',
                            ],
                            [
                                'href' => app_path('super_admin/email_sms_settings.php'),
                                'label' => 'Settings',
                            ],
                            [
                                'href' => app_path('super_admin/company_delete_data.php'),
                                'label' => 'Company Delete Data',
                                'icon' => 'fas fa-archive',
                            ],
                            [
                                'href' => app_path('super_admin/leads.php'),
                                'label' => 'Leads',
                                'icon' => 'fas fa-user-plus',
                            ],
                            [
                                'href' => app_path('user_management/marketing.php'),
                                'label' => 'Marketing',
                                'icon' => 'fas fa-sms',
                            ],
                        ]
                    );
                    ?>
                <?php } ?>

                <li class="nav-header">OPERATIONS</li>

                <?php if($sidebar_is_agent_only){ ?>
                    <?php
                    if(sales_module_enabled()){
                    sidebar_tree(
                        'Sales',
                        'fas fa-file-invoice',
                        [
                            [
                                'href' => app_path('sales/create_invoice.php'),
                                'label' => 'Create Invoice',
                            ],
                            [
                                'href' => app_path('sales/invoice_list.php'),
                                'label' => 'Invoice List',
                            ],
                        ]
                    );
                    }
                    ?>
                <?php }else{ ?>

                <?php
                if(sales_module_enabled()){
                sidebar_tree(
                    'Sales',
                    'fas fa-file-invoice',
                    array_merge(
                    [
                        [
                            'href' => app_path('sales/create_invoice.php'),
                            'label' => 'Create Invoice',
                        ],
                        [
                            'href' => app_path('sales/invoice_list.php'),
                            'label' => 'Invoice List',
                        ],
                        [
                            'href' => app_path('sales/opening_due_entry.php'),
                            'label' => 'Previous Due Entry',
                        ],
                        [
                            'href' => app_path('sales/receive_payment.php'),
                            'label' => 'Due Payment',
                        ],
                    ],
                    []
                    )
                );
                }

                sidebar_tree(
                    'Wallets',
                    'fas fa-wallet',
                    array_merge([
                        [
                            'href' => app_path('wallets/index.php'),
                            'label' => 'Wallet List',
                        ],
                        [
                            'href' => app_path('categories/index.php'),
                            'label' => 'Expense Categories',
                        ],
                        [
                            'href' => app_path('moneyin/index.php'),
                            'label' => 'Money In',
                        ],
                        [
                            'href' => app_path('expenses/index.php'),
                            'label' => 'Expenses',
                        ],
                        [
                            'href' => app_path('transfers/index.php'),
                            'label' => 'Transfers',
                        ],
                        [
                            'href' => app_path('transactions/index.php'),
                            'label' => 'Transactions',
                        ],
                    ], is_manager_user() ? [] : [[
                        'href' => app_path('profit_cash_out/index.php'),
                        'label' => 'Profit Cash Out',
                    ]])
                );

                if(products_module_enabled()){
                    sidebar_tree(
                        'Products',
                        'fas fa-boxes',
                        $sidebar_product_items
                    );
                }

                if($sidebar_table_system_enabled){
                    sidebar_tree(
                        'Table Management',
                        'fas fa-chair',
                        [
                            [
                                'href' => app_path('table_management/index.php'),
                                'label' => 'Table List',
                            ],
                        ]
                    );

                    if(incentive_system_enabled()){
                        sidebar_tree(
                            'Incentive Management',
                            'fas fa-percent',
                            [
                                [
                                    'href' => app_path('incentive_management/index.php'),
                                    'label' => 'Staff Commission',
                                ],
                            ]
                        );
                    }
                }

                sidebar_tree(
                    'Customer Manage',
                    'fas fa-users',
                    [
                        [
                            'href' => app_path('customers/index.php'),
                            'label' => 'Create Customer',
                        ],
                    ]
                );

                sidebar_tree(
                    'Suppliers',
                    'fas fa-truck',
                    [
                        [
                            'href' => app_path('suppliers/index.php'),
                            'label' => is_manager_user() ? 'Suppliers' : 'Add Supplier',
                        ],
                        [
                            'href' => app_path('purchases/index.php'),
                            'label' => 'Purchases',
                        ],
                        [
                            'href' => app_path('suppliers/supplier_payment.php'),
                            'label' => 'Supplier Due Payment',
                        ],
                        [
                            'href' => app_path('suppliers/supplier_payment_history.php'),
                            'label' => 'Payment History',
                        ],
                    ]
                );

                sidebar_tree(
                    'Project & Package',
                    'fas fa-project-diagram',
                    [
                        [
                            'href' => app_path('project_package/projects.php'),
                            'label' => 'Project',
                            'icon' => 'far fa-circle',
                        ],
                        [
                            'href' => app_path('project_package/packages.php'),
                            'label' => 'Package List',
                            'icon' => 'far fa-circle',
                        ],
                    ]
                );

                sidebar_tree(
                    'Lead Management',
                    'fas fa-filter',
                    [
                        [
                            'href' => app_path('lead_management/index.php?filter=lead'),
                            'label' => 'New Lead',
                            'icon' => 'far fa-circle',
                        ],
                        [
                            'href' => app_path('lead_management/index.php?filter=successful'),
                            'label' => 'Qualified List',
                            'icon' => 'far fa-circle',
                        ],
                        [
                            'href' => app_path('lead_management/index.php?filter=not_qualified'),
                            'label' => 'Not Qualified List',
                            'icon' => 'far fa-circle',
                        ],
                        [
                            'href' => app_path('lead_management/index.php?filter=customer'),
                            'label' => 'Successful List',
                            'icon' => 'far fa-circle',
                        ],
                    ]
                );

                sidebar_tree(
                    'Sales',
                    'fas fa-file-invoice',
                    [
                        [
                            'href' => app_path('create_invoice/index.php'),
                            'label' => 'Create Invoice',
                            'icon' => 'far fa-circle',
                        ],
                        [
                            'href' => app_path('create_invoice/invoice_list.php'),
                            'label' => 'Invoice List',
                            'icon' => 'far fa-circle',
                        ],
                        [
                            'href' => app_path('create_invoice/manage_invoice_types.php'),
                            'label' => 'Manage Invoice Type',
                            'icon' => 'far fa-circle',
                        ],
                    ]
                );
                ?>

                <?php if($sidebar_can_see_admin && sidebar_has_active_manager()){ ?>
                    <?php sidebar_item(app_path('user_management/wallet_approvals.php'), 'Wallet Approvals', 'fas fa-check-circle'); ?>
                <?php } ?>

                <?php } ?>

                <?php if($sidebar_can_see_reports){ ?>

                <li class="nav-header">INSIGHTS</li>

                <?php
                $sidebar_report_items = [
                    ['href' => app_path('reports/sales_report.php'), 'label' => 'Sales Report', 'icon' => 'fas fa-chart-line'],
                    ['href' => app_path('reports/category_expense.php'), 'label' => 'Expense Report', 'icon' => 'fas fa-receipt'],
                    ['href' => app_path('reports/profit_report.php'), 'label' => 'Profit Report', 'icon' => 'fas fa-coins'],
                ];
                sidebar_tree('Reports', 'fas fa-chart-bar', $sidebar_report_items);
                ?>
                <?php } ?>

                <?php if($sidebar_can_see_admin){ ?>
                    <li class="nav-header">ADMIN</li>

                    <?php sidebar_item(app_path('user_management/index.php'), 'Access Management', 'fas fa-user-cog'); ?>
                    <?php sidebar_item(app_path('user_management/invoice_charges.php'), 'Invoice Charges', 'fas fa-percentage'); ?>
                    <?php sidebar_item(app_path('user_management/printing_option.php'), 'Printing Option', 'fas fa-print'); ?>
                    <?php
                    sidebar_tree(
                        'Tools',
                        'fas fa-tools',
                        [
                            [
                                'href' => is_super_admin_user() ? '#' : app_path('tools/export.php'),
                                'label' => 'Export Data',
                                'class' => is_super_admin_user() ? 'disabled text-muted' : '',
                            ],
                            [
                                'href' => is_super_admin_user() ? '#' : app_path('tools/import.php'),
                                'label' => 'Import Data',
                                'class' => is_super_admin_user() ? 'disabled text-muted' : '',
                            ],
                            [
                                'href' => is_super_admin_user() ? '#' : app_path('tools/delete_data.php'),
                                'label' => 'Delete All Data',
                                'class' => is_super_admin_user() ? 'disabled text-muted' : 'text-danger',
                            ],
                        ]
                    );
                    ?>
                <?php } ?>

                <?php if(is_admin_user()){ ?>
                    <li class="nav-header">HELP</li>

                    <?php
                    sidebar_tree(
                        'Help',
                        'fas fa-life-ring',
                        [
                            [
                                'href' => app_path('help/video_tutorial.php'),
                                'label' => 'Video Tutorial',
                            ],
                            [
                                'href' => app_path('help/pricing_plan.php'),
                                'label' => 'Pricing Plan',
                            ],
                            [
                                'href' => app_path('help/support.php'),
                                'label' => 'Support',
                            ],
                        ]
                    );
                    ?>
                <?php } ?>

                <li class="nav-header">SESSION</li>

                <?php sidebar_item(app_path('logout.php'), 'Logout', 'fas fa-sign-out-alt', 'text-danger'); ?>

                <li class="nav-item sidebar-bottom-break"></li>

            </ul>

        </nav>

    </div>

</aside>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const navigation = document.querySelector('.nav-sidebar');
    if (!navigation) return;

    const operationsHeader = Array.from(navigation.children).find(function (item) {
        return item.classList.contains('nav-header') && item.textContent.trim() === 'OPERATIONS';
    });
    if (!operationsHeader) return;

    const orderedLabels = ['Sales', 'Wallets', 'Project & Package', 'Customer Manage', 'Lead Management', 'Suppliers'];
    let previousItem = operationsHeader;

    orderedLabels.forEach(function (label) {
        const item = Array.from(navigation.children).find(function (candidate) {
            const link = candidate.querySelector(':scope > .nav-link > p');
            return link && link.textContent.trim() === label;
        });

        if (item) {
            previousItem.insertAdjacentElement('afterend', item);
            previousItem = item;
        }
    });
});
</script>

<div class="content-wrapper">

<section class="content pt-3">

<div class="container-fluid">
