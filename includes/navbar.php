<?php

require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/lead_management_helper.php';
require_once __DIR__ . '/branch_context_helper.php';
require_once __DIR__ . '/project_package_helper.php';

$avatar_file = $_SESSION['avatar'] ?? 'you2biz.png';
$has_active_subscription = false;

// `user_id` always contains the company owner ID, including for manager logins.
// Read the live value so a Super Admin subscription update takes effect on the
// very next page load without requiring the company to log out and back in.
if (!is_super_admin_user() && isset($conn)) {
    $company_user_id = (int)($_SESSION['user_id'] ?? 0);

    if ($company_user_id > 0) {
        $subscription_stmt = mysqli_prepare(
            $conn,
            "SELECT subscription_status FROM users WHERE id=? LIMIT 1"
        );

        if ($subscription_stmt) {
            mysqli_stmt_bind_param($subscription_stmt, 'i', $company_user_id);
            mysqli_stmt_execute($subscription_stmt);
            $subscription_result = mysqli_stmt_get_result($subscription_stmt);
            $subscription = $subscription_result ? mysqli_fetch_assoc($subscription_result) : null;
            $has_active_subscription = strtolower((string)($subscription['subscription_status'] ?? '')) === 'active';
            mysqli_stmt_close($subscription_stmt);
        }
    }
}

if (is_manager_user()) {
    $avatar_file = $_SESSION['login_avatar'] ?? $avatar_file;
}

$navbar_staff_id = isset($conn) ? current_manager_staff_id($conn) : current_manager_staff_id();
$followup_notifications = [];
$stock_receive_notifications = [];
$damaged_return_notifications = [];
$navbar_branches = [];
$navbar_selected_branch = 0;

if(isset($conn) && $conn instanceof mysqli && !is_super_admin_user()){
    $navbar_company_id = (int)($_SESSION['user_id'] ?? 0);
    ensure_branch_accounting_columns($conn, $navbar_company_id);
    $navbar_selected_branch = selected_branch_id($conn, false);
    $navbar_can_switch_branches = is_admin_user()
        || (is_manager_user() && manager_can_view_all_branches($conn, $navbar_company_id));
    if($navbar_can_switch_branches && company_multi_branch_enabled($conn, $navbar_company_id)){
        $branch_stmt = mysqli_prepare($conn, "SELECT id,branch_name,branch_code FROM branches WHERE user_id=? AND status='active' ORDER BY CASE WHEN is_head_office=1 THEN 0 WHEN LOWER(TRIM(branch_name))='main warehouse' THEN 1 ELSE 2 END, branch_name ASC");
        mysqli_stmt_bind_param($branch_stmt, 'i', $navbar_company_id);
        mysqli_stmt_execute($branch_stmt);
        $branch_result = mysqli_stmt_get_result($branch_stmt);
        while($branch_result && ($branch = mysqli_fetch_assoc($branch_result))) $navbar_branches[] = $branch;
    }
}

if (isset($conn) && $conn instanceof mysqli && !is_super_admin_user()) {
    $stock_notification_company_id = (int)($_SESSION['user_id'] ?? 0);
    $stock_notification_branch_id = $navbar_selected_branch > 0
        ? $navbar_selected_branch
        : selected_branch_id($conn, true);
    if ($stock_notification_company_id > 0 && $stock_notification_branch_id > 0 && company_multi_branch_enabled($conn, $stock_notification_company_id)) {
        $stock_notification_sql = "SELECT
                MIN(d.id) AS id,
                MAX(d.reference_no) AS reference_no,
                SUM(d.quantity) AS quantity,
                MAX(d.created_at) AS created_at,
                COUNT(d.id) AS request_count,
                p.id AS product_id,
                p.product_name,
                p.sku
            FROM stock_distributions d
            INNER JOIN products p ON p.id=d.product_id AND p.user_id=d.user_id
            WHERE d.user_id=? AND d.to_branch_id=? AND d.status='pending'
            GROUP BY p.id,p.product_name,p.sku
            ORDER BY MAX(d.created_at) ASC";
        $stock_notification_stmt = mysqli_prepare($conn, $stock_notification_sql);
        if ($stock_notification_stmt) {
            mysqli_stmt_bind_param($stock_notification_stmt, 'ii', $stock_notification_company_id, $stock_notification_branch_id);
            mysqli_stmt_execute($stock_notification_stmt);
            $stock_notification_result = mysqli_stmt_get_result($stock_notification_stmt);
            while ($stock_notification_result && ($stock_notification = mysqli_fetch_assoc($stock_notification_result))) $stock_receive_notifications[] = $stock_notification;
            mysqli_stmt_close($stock_notification_stmt);
        }
    }
}

// Damaged returns are handled by the central warehouse. Keep these visible
// regardless of the branch currently selected in the navbar.
if (isset($conn) && $conn instanceof mysqli && !is_super_admin_user()) {
    $damage_notification_company_id = (int)($_SESSION['user_id'] ?? 0);
    if ($damage_notification_company_id > 0 && stock_can_manage_warehouse($conn) && company_multi_branch_enabled($conn, $damage_notification_company_id) && project_package_company_type($conn, $damage_notification_company_id) === 'Fashion house') {
        $damage_notification_sql = "SELECT dr.id,dr.quantity,dr.variant_name,dr.created_at,p.product_name,p.sku,b.branch_name,b.is_head_office
            FROM stock_damage_returns dr
            INNER JOIN products p ON p.id=dr.product_id AND p.user_id=dr.user_id
            INNER JOIN branches b ON b.id=dr.branch_id AND b.user_id=dr.user_id
            WHERE dr.user_id=? AND dr.status='pending' ORDER BY dr.created_at ASC LIMIT 20";
        $damage_notification_stmt = mysqli_prepare($conn, $damage_notification_sql);
        if ($damage_notification_stmt) {
            mysqli_stmt_bind_param($damage_notification_stmt, 'i', $damage_notification_company_id);
            mysqli_stmt_execute($damage_notification_stmt);
            $damage_notification_result = mysqli_stmt_get_result($damage_notification_stmt);
            while ($damage_notification_result && ($damage_notification = mysqli_fetch_assoc($damage_notification_result))) $damaged_return_notifications[] = $damage_notification;
            mysqli_stmt_close($damage_notification_stmt);
        }
    }
}

if(isset($conn) && $conn instanceof mysqli && !is_super_admin_user()){
    ensure_lead_management_table($conn);
    ensure_lead_followup_notification_table($conn);

    $notification_company_id = (int)($_SESSION['user_id'] ?? 0);
    $notification_user_id = (int)($_SESSION['login_user_id'] ?? $notification_company_id);
    $notification_user_name = trim((string)($_SESSION['login_name'] ?? ''));
    $notification_scope = is_manager_user() ? ' AND (l.created_by_user_id=? OR l.created_by_name=?)' : '';
    $notification_sql = "SELECT l.id, l.name, l.phone, l.status, l.created_by_name
        FROM leads l
        LEFT JOIN lead_followup_notification_reads n
            ON n.lead_id=l.id AND n.user_id=? AND n.followup_date=l.followup_date
        WHERE l.user_id=? AND l.followup_date=CURDATE() AND n.id IS NULL" . $notification_scope .
        ' ORDER BY l.name ASC';
    $notification_stmt = mysqli_prepare($conn, $notification_sql);
    if($notification_stmt){
        if(is_manager_user()){
            mysqli_stmt_bind_param($notification_stmt, 'iiis', $notification_user_id, $notification_company_id, $notification_user_id, $notification_user_name);
        }else{
            mysqli_stmt_bind_param($notification_stmt, 'ii', $notification_user_id, $notification_company_id);
        }
        mysqli_stmt_execute($notification_stmt);
        $notification_result = mysqli_stmt_get_result($notification_stmt);
        while($notification_result && ($notification = mysqli_fetch_assoc($notification_result))){
            $followup_notifications[] = $notification;
        }
        mysqli_stmt_close($notification_stmt);
    }
}

$avatar = app_path('uploads/avatars/you2biz.png');

if (
    !empty($avatar_file) &&
    file_exists(
        dirname(__DIR__) .
        '/uploads/avatars/' .
        $avatar_file
    )
) {

    $avatar =
        app_path('uploads/avatars/') .
        $avatar_file;
}

?>

<style>
    .navbar-followup-dropdown { width: 320px; max-width: calc(100vw - 24px); padding: 0; border: 0; border-radius: 10px; overflow: hidden; box-shadow: 0 10px 28px rgba(18, 38, 63, .18); }
    .navbar-followup-heading { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #f8fafc; color: #26364a; font-weight: 600; }
    .navbar-followup-heading .badge { font-size: 11px; }
    .lead-followup-item { display: flex !important; align-items: flex-start; gap: 11px; padding: 14px 16px !important; white-space: normal; transition: background-color .15s ease; }
    .lead-followup-item:hover { background: #f1f7ff; }
    .lead-followup-icon { width: 34px; height: 34px; min-width: 34px; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%; color: #fff; background: #1683ff; }
    .lead-followup-content { min-width: 0; flex: 1; }
    .lead-followup-title { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #1f2d3d; font-weight: 600; }
    .lead-followup-meta { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #6c7a89; font-size: 12px; margin-top: 3px; }
    .lead-followup-due { display: block; color: #d97706; font-size: 12px; font-weight: 600; margin-top: 5px; }
</style>

<nav class="main-header navbar navbar-expand navbar-white navbar-light">

    <ul class="navbar-nav">

        <li class="nav-item">

            <a class="nav-link"
               data-widget="pushmenu"
               href="#"
               role="button">

                <i class="fas fa-bars"></i>

            </a>

        </li>

    </ul>

    <ul class="navbar-nav ml-auto">

        <?php if($navbar_branches){ ?>
        <li class="nav-item d-flex align-items-center mr-2">
            <form method="post" action="<?= htmlspecialchars(app_path('branch_context.php')); ?>" class="form-inline">
                <input type="hidden" name="return_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? app_path('dashboard.php')); ?>">
                <select name="branch_id" class="form-control form-control-sm" onchange="this.form.submit()" title="View branch">
                    <option value="0" <?= $navbar_selected_branch === 0 ? 'selected' : ''; ?>>All Branches</option>
                    <?php foreach($navbar_branches as $branch){ ?>
                        <option value="<?= (int)$branch['id']; ?>" <?= $navbar_selected_branch === (int)$branch['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($branch['branch_name'] . (!empty($branch['branch_code']) ? ' [' . $branch['branch_code'] . ']' : '')); ?></option>
                    <?php } ?>
                </select>
            </form>
        </li>
        <?php }elseif(is_manager_user() && isset($conn)){ ?>
        <li class="nav-item d-flex align-items-center mr-3"><span class="badge badge-primary p-2"><i class="fas fa-code-branch mr-1"></i><?= htmlspecialchars(current_branch_label($conn)); ?></span></li>
        <?php } ?>

        <li class="nav-item dropdown">
            <a class="nav-link position-relative" data-toggle="dropdown" href="#" title="Notifications" aria-label="Notifications">
                <i class="far fa-bell"></i>
                <?php if(count($followup_notifications) + count($stock_receive_notifications) + count($damaged_return_notifications) > 0){ ?>
                    <span class="badge badge-danger navbar-badge"><?= count($followup_notifications) + count($stock_receive_notifications) + count($damaged_return_notifications); ?></span>
                <?php } ?>
            </a>
            <div class="dropdown-menu dropdown-menu-right navbar-followup-dropdown">
                <div class="navbar-followup-heading">
                    <span><i class="far fa-bell mr-2"></i>Notifications</span>
                    <span class="badge badge-primary badge-pill"><?= count($followup_notifications) + count($stock_receive_notifications) + count($damaged_return_notifications); ?></span>
                </div>
                <?php if($damaged_return_notifications){ ?>
                    <?php foreach($damaged_return_notifications as $notification){ ?>
                        <a href="<?= htmlspecialchars(app_path('warehouse/damaged_returns.php?return_id=' . (int)$notification['id'] . '#damage-return-' . (int)$notification['id'])); ?>" class="dropdown-item lead-followup-item">
                            <span class="lead-followup-icon bg-danger"><i class="fas fa-exclamation-triangle"></i></span>
                            <span class="lead-followup-content"><span class="lead-followup-title"><?= htmlspecialchars($notification['product_name']); ?><?= $notification['variant_name'] !== '' ? ' · ' . htmlspecialchars($notification['variant_name']) : ''; ?> · <?= number_format((float)$notification['quantity'], 0); ?> pcs</span><span class="lead-followup-meta">Damaged return request · <?= htmlspecialchars($notification['is_head_office'] ? 'Head Office' : $notification['branch_name']); ?></span><span class="lead-followup-due">Date: <?= htmlspecialchars(app_date($notification['created_at'])); ?></span></span>
                        </a>
                    <?php } ?>
                <?php } ?>
                <?php if($stock_receive_notifications){ ?>
                    <?php foreach($stock_receive_notifications as $notification){ ?>
                        <a href="<?= htmlspecialchars(app_path('sales/receive_stock.php?distribution_id=' . (int)$notification['id'] . '#receive-request-' . (int)$notification['id'])); ?>" class="dropdown-item lead-followup-item">
                            <span class="lead-followup-icon bg-success"><i class="fas fa-truck-loading"></i></span>
                            <span class="lead-followup-content"><span class="lead-followup-title"><?= htmlspecialchars($notification['product_name']); ?><?= trim((string)($notification['sku'] ?? '')) !== '' ? ' [Code: ' . htmlspecialchars($notification['sku']) . ']' : ''; ?> · <?= number_format((float)$notification['quantity'], 0); ?> pcs</span><span class="lead-followup-meta">Stock receive request<?= (int)$notification['request_count'] > 1 ? ' · ' . (int)$notification['request_count'] . ' items' : ' · ' . htmlspecialchars($notification['reference_no']); ?></span><span class="lead-followup-due">Date: <?= htmlspecialchars(app_date($notification['created_at'])); ?></span></span>
                        </a>
                    <?php } ?>
                <?php } ?>
                <?php if($followup_notifications){ ?>
                    <?php foreach($followup_notifications as $notification){ ?>
                        <a href="<?= htmlspecialchars(app_path('lead_management/read_followup_notification.php?id=' . (int)$notification['id'])); ?>" class="dropdown-item lead-followup-item">
                            <span class="lead-followup-icon"><i class="fas fa-calendar-day"></i></span>
                            <span class="lead-followup-content">
                                <span class="lead-followup-title" title="<?= htmlspecialchars($notification['name']); ?>"><?= htmlspecialchars(lead_code_from_id((int)$notification['id'])); ?> · <?= htmlspecialchars($notification['name']); ?></span>
                                <span class="lead-followup-meta">
                                    <?= htmlspecialchars(lead_management_title($notification['status'])); ?>
                                    <?php if(is_admin_user() && trim((string)$notification['created_by_name']) !== ''){ ?> · Ref. <?= htmlspecialchars($notification['created_by_name']); ?><?php } ?>
                                </span>
                                <span class="lead-followup-due">Follow-up due today</span>
                            </span>
                        </a>
                    <?php } ?>
                <?php }elseif(!$stock_receive_notifications && !$damaged_return_notifications){ ?>
                    <span class="dropdown-item text-muted py-3"><i class="far fa-bell-slash mr-2"></i>No notifications.</span>
                <?php } ?>
            </div>
        </li>

        <li class="nav-item dropdown">

            <a class="nav-link d-flex align-items-center"
               data-toggle="dropdown"
               href="#">

                <img
                    src="<?= $avatar; ?>"
                    alt="Avatar"
                    class="img-circle elevation-2"
                    style="
                        width:35px;
                        height:35px;
                        object-fit:cover;
                        margin-right:8px;
                    ">

                <span>

                    <?= htmlspecialchars($_SESSION['login_name'] ?? $_SESSION['user_name']); ?>

                </span>

                <?php if ($has_active_subscription) { ?>
                    <i class="fas fa-check-circle text-primary ml-1"
                       title="Verified subscription"
                       aria-label="Verified subscription"></i>
                <?php } ?>

                <i class="fas fa-caret-down ml-2"></i>

            </a>

            <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">

                
                <div class="dropdown-divider"></div>

                <a href="<?= htmlspecialchars(app_path('profile/index.php')); ?>"
                   class="dropdown-item">

                    <i class="fas fa-user mr-2"></i>

                    My Profile

                </a>

                <?php if ($navbar_staff_id > 0) { ?>
                    <div class="dropdown-divider"></div>

                    <a href="<?= htmlspecialchars(app_path('staff/profile.php')); ?>"
                       class="dropdown-item">

                        <i class="fas fa-history mr-2"></i>

                        My History

                    </a>
                <?php } ?>

                <div class="dropdown-divider"></div>

                <a href="<?= htmlspecialchars(app_path('profile/change_password.php')); ?>"
                   class="dropdown-item">

                    <i class="fas fa-key mr-2"></i>

                    Change Password

                </a>

                <div class="dropdown-divider"></div>

                <a href="<?= htmlspecialchars(app_path('logout.php')); ?>"
                   class="dropdown-item text-danger">

                    <i class="fas fa-sign-out-alt mr-2"></i>

                    Logout

                </a>

            </div>

        </li>

    </ul>

</nav>
