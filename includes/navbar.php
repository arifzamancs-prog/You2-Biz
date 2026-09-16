<?php

require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/lead_management_helper.php';

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

if(isset($conn) && $conn instanceof mysqli && !is_super_admin_user()){
    ensure_lead_management_table($conn);
    ensure_lead_followup_notification_table($conn);

    $notification_company_id = (int)($_SESSION['user_id'] ?? 0);
    $notification_user_id = (int)($_SESSION['login_user_id'] ?? $notification_company_id);
    $notification_user_name = trim((string)($_SESSION['login_name'] ?? ''));
    $notification_scope = is_manager_user() ? ' AND (l.created_by_user_id=? OR l.created_by_name=?)' : '';
    $notification_sql = "SELECT l.id, l.name, l.phone, l.status
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

        <li class="nav-item dropdown">
            <a class="nav-link position-relative" data-toggle="dropdown" href="#" title="Notifications" aria-label="Notifications">
                <i class="far fa-bell"></i>
                <?php if(count($followup_notifications) > 0){ ?>
                    <span class="badge badge-danger navbar-badge"><?= count($followup_notifications); ?></span>
                <?php } ?>
            </a>
            <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                <span class="dropdown-item dropdown-header"><?= count($followup_notifications); ?> Follow-up Notification<?= count($followup_notifications) === 1 ? '' : 's'; ?></span>
                <div class="dropdown-divider"></div>
                <?php if($followup_notifications){ ?>
                    <?php foreach($followup_notifications as $notification){ ?>
                        <a href="<?= htmlspecialchars(app_path('lead_management/read_followup_notification.php?id=' . (int)$notification['id'])); ?>" class="dropdown-item">
                            <i class="fas fa-calendar-day text-primary mr-2"></i>
                            <span><?= htmlspecialchars(lead_code_from_id((int)$notification['id'])); ?> — <?= htmlspecialchars($notification['name']); ?></span>
                            <small class="text-muted d-block ml-4"><?= htmlspecialchars(lead_management_title($notification['status'])); ?> · Follow-up today</small>
                        </a>
                        <div class="dropdown-divider"></div>
                    <?php } ?>
                <?php }else{ ?>
                    <span class="dropdown-item text-muted"><i class="far fa-bell-slash mr-2"></i>No follow-up for today.</span>
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
