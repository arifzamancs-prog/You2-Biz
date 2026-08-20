<?php

require_once __DIR__ . '/app_config.php';

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
