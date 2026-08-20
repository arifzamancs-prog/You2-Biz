<?php

require_once '../includes/auth.php';
require_admin_user();
require_once '../includes/db.php';
require_once '../includes/app_config.php';
require_once '../includes/smtp_mailer.php';
require_once '../includes/super_admin_config.php';
require_once '../includes/signup_message_helper.php';

function ensure_pricing_plan_table($conn)
{
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS pricing_plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        plan_name VARCHAR(100) NOT NULL,
        one_time_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        monthly_service_charge DECIMAL(12,2) NOT NULL DEFAULT 0,
        hosting_title VARCHAR(255) NOT NULL DEFAULT '',
        feature_details TEXT NOT NULL,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function ensure_pricing_plan_request_table($conn)
{
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS pricing_plan_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        plan_id INT NOT NULL,
        admin_user_id INT NOT NULL,
        admin_name VARCHAR(255) NOT NULL DEFAULT '',
        admin_email VARCHAR(255) NOT NULL DEFAULT '',
        admin_phone VARCHAR(100) NOT NULL DEFAULT '',
        request_status ENUM('waiting','approved','rejected') NOT NULL DEFAULT 'waiting',
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_pricing_request_plan (plan_id),
        INDEX idx_pricing_request_admin (admin_user_id),
        INDEX idx_pricing_request_status (request_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function pricing_plan_count($conn)
{
    $result = mysqli_query($conn, "SELECT COUNT(*) AS total_rows FROM pricing_plans");
    $row = $result ? mysqli_fetch_assoc($result) : null;

    return (int)($row['total_rows'] ?? 0);
}

function seed_default_pricing_plans($conn)
{
    if (pricing_plan_count($conn) > 0) {
        return;
    }

    $default_plans = [
        [
            'plan_name' => 'Basic',
            'one_time_price' => 25000,
            'monthly_service_charge' => 2000,
            'hosting_title' => 'Basic Cloud Host',
            'feature_details' => "Suitable for small business and startup shops.\nOwn software setup with essential sales, wallet and report modules.\nCloud hosting included with standard speed and regular maintenance support.",
            'status' => 'active',
            'sort_order' => 1,
        ],
        [
            'plan_name' => 'Advance',
            'one_time_price' => 35000,
            'monthly_service_charge' => 3000,
            'hosting_title' => 'Fast Cloud Host with Own Domain',
            'feature_details' => "Suitable for growing business with regular daily operations.\nIncludes custom domain setup, faster cloud performance and advanced management features.\nPriority maintenance and support guidance included.",
            'status' => 'active',
            'sort_order' => 2,
        ],
        [
            'plan_name' => 'Corporate',
            'one_time_price' => 45000,
            'monthly_service_charge' => 5000,
            'hosting_title' => 'Advanced Cloud Host with Own Domain',
            'feature_details' => "Suitable for large business, chain shop or corporate operation.\nAdvanced hosting environment, own domain, stronger performance and dedicated support flow.\nBest for multi-user usage, priority issue handling and long-term service management.",
            'status' => 'active',
            'sort_order' => 3,
        ],
    ];

    foreach ($default_plans as $plan) {
        $plan_name = mysqli_real_escape_string($conn, $plan['plan_name']);
        $hosting_title = mysqli_real_escape_string($conn, $plan['hosting_title']);
        $feature_details = mysqli_real_escape_string($conn, $plan['feature_details']);
        $status = mysqli_real_escape_string($conn, $plan['status']);
        $one_time_price = (float)$plan['one_time_price'];
        $monthly_service_charge = (float)$plan['monthly_service_charge'];
        $sort_order = (int)$plan['sort_order'];

        mysqli_query($conn, "INSERT INTO pricing_plans (
            plan_name, one_time_price, monthly_service_charge, hosting_title, feature_details, status, sort_order
        ) VALUES (
            '{$plan_name}',
            {$one_time_price},
            {$monthly_service_charge},
            '{$hosting_title}',
            '{$feature_details}',
            '{$status}',
            {$sort_order}
        )");
    }
}

function pricing_plan_money($amount)
{
    return 'BDT ' . number_format((float)$amount, 2);
}

function pricing_plan_request_mail_html($title, $lines)
{
    $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1f2937;">';
    $html .= '<h2 style="margin:0 0 16px 0;color:#111827;">' . htmlspecialchars($title) . '</h2>';

    foreach ($lines as $line) {
        $html .= '<p style="margin:0 0 10px 0;line-height:1.6;">' . nl2br(htmlspecialchars($line)) . '</p>';
    }

    $html .= '<p style="margin:20px 0 0 0;">';
    $html .= '<a href="' . htmlspecialchars(app_url('help/pricing_plan.php')) . '" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:6px;">Open Pricing Plan</a>';
    $html .= '</p>';
    $html .= '</div>';

    return $html;
}

function pricing_plan_redirect($message, $message_type, $edit_id = 0)
{
    $_SESSION['pricing_plan_flash_message'] = $message;
    $_SESSION['pricing_plan_flash_message_type'] = $message_type;

    $redirect_url = app_path('help/pricing_plan.php');

    if ((int)$edit_id > 0) {
        $redirect_url .= '?edit_id=' . (int)$edit_id;
    }

    header('Location: ' . $redirect_url);
    exit;
}

ensure_pricing_plan_table($conn);
ensure_pricing_plan_request_table($conn);
ensure_signup_message_settings_table($conn);
seed_default_pricing_plans($conn);

$is_super_admin = is_super_admin_user();
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$message = $_SESSION['pricing_plan_flash_message'] ?? '';
$message_type = $_SESSION['pricing_plan_flash_message_type'] ?? 'success';
unset($_SESSION['pricing_plan_flash_message'], $_SESSION['pricing_plan_flash_message_type']);
$signup_message_settings = signup_message_settings($conn);

$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$edit_plan = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'request_plan' && !$is_super_admin) {
        $plan_id = (int)($_POST['plan_id'] ?? 0);

        if ($plan_id <= 0) {
            pricing_plan_redirect('Invalid plan selected.', 'danger');
        }

        $plan_result = mysqli_query($conn, "SELECT * FROM pricing_plans WHERE id={$plan_id} AND status='active' LIMIT 1");
        $plan_row = $plan_result ? mysqli_fetch_assoc($plan_result) : null;

        if (!$plan_row) {
            pricing_plan_redirect('Selected plan is not available now.', 'danger');
        }

        $admin_result = mysqli_query($conn, "SELECT id, name, email, phone FROM users WHERE id={$current_user_id} LIMIT 1");
        $admin_row = $admin_result ? mysqli_fetch_assoc($admin_result) : null;

        if (!$admin_row) {
            pricing_plan_redirect('Admin account not found.', 'danger');
        }

        $pending_request_result = mysqli_query(
            $conn,
            "SELECT id
             FROM pricing_plan_requests
             WHERE plan_id={$plan_id}
             AND admin_user_id={$current_user_id}
             AND request_status='waiting'
             LIMIT 1"
        );

        if ($pending_request_result && mysqli_num_rows($pending_request_result) > 0) {
            pricing_plan_redirect('This plan request is already waiting for subscription.', 'danger');
        }

        $admin_name_sql = mysqli_real_escape_string($conn, (string)($admin_row['name'] ?? ''));
        $admin_email_sql = mysqli_real_escape_string($conn, (string)($admin_row['email'] ?? ''));
        $admin_phone_sql = mysqli_real_escape_string($conn, (string)($admin_row['phone'] ?? ''));

        $insert_request = mysqli_query(
            $conn,
            "INSERT INTO pricing_plan_requests (
                plan_id, admin_user_id, admin_name, admin_email, admin_phone, request_status
            ) VALUES (
                {$plan_id},
                {$current_user_id},
                '{$admin_name_sql}',
                '{$admin_email_sql}',
                '{$admin_phone_sql}',
                'waiting'
            )"
        );

        if (!$insert_request) {
            pricing_plan_redirect('Plan request failed.', 'danger');
        }

        $plan_title = (string)$plan_row['plan_name'];
        $price_text = pricing_plan_money($plan_row['one_time_price']);
        $monthly_text = pricing_plan_money($plan_row['monthly_service_charge']);

        $pricing_placeholders = [
            'admin_name' => (string)$admin_row['name'],
            'admin_email' => (string)$admin_row['email'],
            'admin_phone' => (string)$admin_row['phone'],
            'plan_name' => $plan_title,
            'software_price' => $price_text,
            'monthly_service_charge' => $monthly_text,
            'hosting_title' => (string)$plan_row['hosting_title'],
            'pricing_link' => app_url('help/pricing_plan.php'),
        ];

        if(($signup_message_settings['pricing_request_admin_email_status'] ?? 'active') === 'active'){
            $admin_subject = signup_message_apply_placeholders(
                $signup_message_settings['pricing_request_admin_email_subject'] ?? 'Pricing plan request sent: {plan_name}',
                $pricing_placeholders
            );
            $admin_body = signup_message_apply_placeholders(
                $signup_message_settings['pricing_request_admin_email_message'] ?? '',
                $pricing_placeholders
            );

            smtp_send_mail(
                (string)$admin_row['email'],
                (string)$admin_row['name'],
                $admin_subject,
                signup_message_email_html($admin_body)
            );
        }

        if(($signup_message_settings['pricing_request_super_admin_email_status'] ?? 'active') === 'active'){
            $super_admin_subject = signup_message_apply_placeholders(
                $signup_message_settings['pricing_request_super_admin_email_subject'] ?? 'New pricing plan request: {plan_name}',
                $pricing_placeholders
            );
            $super_admin_body = signup_message_apply_placeholders(
                $signup_message_settings['pricing_request_super_admin_email_message'] ?? '',
                $pricing_placeholders
            );

            smtp_send_mail(
                super_admin_notify_email(),
                defined('SUPER_ADMIN_NAME') ? SUPER_ADMIN_NAME : 'Super Admin',
                $super_admin_subject,
                signup_message_email_html($super_admin_body)
            );
        }

        pricing_plan_redirect('Plan activation request sent successfully.', 'success');
    }

    if (!$is_super_admin) {
        // Admin cannot manage plans beyond request submission.
    } elseif ($is_super_admin) {
    $plan_name = trim($_POST['plan_name'] ?? '');
    $one_time_price = max(0, (float)($_POST['one_time_price'] ?? 0));
    $monthly_service_charge = max(0, (float)($_POST['monthly_service_charge'] ?? 0));
    $hosting_title = trim($_POST['hosting_title'] ?? '');
    $feature_details = trim($_POST['feature_details'] ?? '');
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $sort_order = max(0, (int)($_POST['sort_order'] ?? 0));

    if ($action === 'delete_plan') {
        $plan_id = (int)($_POST['plan_id'] ?? 0);

        if ($plan_id <= 0) {
            pricing_plan_redirect('Invalid pricing plan selected.', 'danger');
        }

        if (mysqli_query($conn, "DELETE FROM pricing_plans WHERE id={$plan_id} LIMIT 1")) {
            pricing_plan_redirect('Pricing plan deleted successfully.', 'success');
        }

        pricing_plan_redirect('Pricing plan delete failed.', 'danger', $plan_id);
    }

    if ($plan_name === '' || $hosting_title === '' || $feature_details === '') {
        pricing_plan_redirect('Plan name, hosting title and feature details are required.', 'danger', $edit_id);
    }

    $plan_name_sql = mysqli_real_escape_string($conn, $plan_name);
    $hosting_title_sql = mysqli_real_escape_string($conn, $hosting_title);
    $feature_details_sql = mysqli_real_escape_string($conn, $feature_details);
    $status_sql = mysqli_real_escape_string($conn, $status);

    if ($action === 'update_plan') {
        $plan_id = (int)($_POST['plan_id'] ?? 0);

        if ($plan_id <= 0) {
            pricing_plan_redirect('Invalid pricing plan selected.', 'danger');
        }

        $updated = mysqli_query($conn, "UPDATE pricing_plans
                                        SET plan_name='{$plan_name_sql}',
                                            one_time_price={$one_time_price},
                                            monthly_service_charge={$monthly_service_charge},
                                            hosting_title='{$hosting_title_sql}',
                                            feature_details='{$feature_details_sql}',
                                            status='{$status_sql}',
                                            sort_order={$sort_order}
                                        WHERE id={$plan_id}
                                        LIMIT 1");

        if ($updated) {
            pricing_plan_redirect('Pricing plan updated successfully.', 'success', $plan_id);
        }

        pricing_plan_redirect('Pricing plan update failed.', 'danger', $plan_id);
    }

    if ($action === 'create_plan') {
        $created = mysqli_query($conn, "INSERT INTO pricing_plans (
            plan_name, one_time_price, monthly_service_charge, hosting_title, feature_details, status, sort_order
        ) VALUES (
            '{$plan_name_sql}',
            {$one_time_price},
            {$monthly_service_charge},
            '{$hosting_title_sql}',
            '{$feature_details_sql}',
            '{$status_sql}',
            {$sort_order}
        )");

        if ($created) {
            pricing_plan_redirect('Pricing plan created successfully.', 'success', (int)mysqli_insert_id($conn));
        }

        pricing_plan_redirect('Pricing plan create failed.', 'danger');
    }
    }
}

if ($edit_id > 0 && $is_super_admin) {
    $edit_result = mysqli_query($conn, "SELECT * FROM pricing_plans WHERE id={$edit_id} LIMIT 1");
    $edit_plan = $edit_result ? mysqli_fetch_assoc($edit_result) : null;
}

$plan_where = $is_super_admin ? '1' : "status='active'";
$plan_query = mysqli_query($conn, "SELECT *
                                   FROM pricing_plans
                                   WHERE {$plan_where}
                                   ORDER BY sort_order ASC, id ASC");

$plans = [];
if ($plan_query) {
    while ($row = mysqli_fetch_assoc($plan_query)) {
        $plans[] = $row;
    }
}

$waiting_requests = [];
$waiting_request_map = [];
$waiting_request_count = 0;
$approved_plan_id = 0;
$active_plan_name = '';

if(!$is_super_admin){
    $active_company_stmt = mysqli_prepare(
        $conn,
        "SELECT subscription_plan, subscription_status
         FROM users
         WHERE id=?
         LIMIT 1"
    );

    if($active_company_stmt){
        mysqli_stmt_bind_param($active_company_stmt, "i", $current_user_id);
        mysqli_stmt_execute($active_company_stmt);
        $active_company_result = mysqli_stmt_get_result($active_company_stmt);
        $active_company = $active_company_result ? mysqli_fetch_assoc($active_company_result) : null;
        mysqli_stmt_close($active_company_stmt);

        if($active_company && strtolower((string)($active_company['subscription_status'] ?? '')) === 'active'){
            $active_plan_name = trim((string)($active_company['subscription_plan'] ?? ''));

            // Companies activated directly from Super Admin have no plan
            // request.  Treat the legacy generic "Active" value (or no plan)
            // as the default Basic plan.
            if($active_plan_name === '' || strtolower($active_plan_name) === 'active'){
                $active_plan_name = 'Basic';
            }
        }
    }
}

$request_where = $is_super_admin
    ? "pricing_plan_requests.request_status='waiting'"
    : "pricing_plan_requests.request_status='waiting' AND pricing_plan_requests.admin_user_id={$current_user_id}";

$request_query = mysqli_query(
    $conn,
    "SELECT pricing_plan_requests.*, pricing_plans.plan_name
     FROM pricing_plan_requests
     LEFT JOIN pricing_plans
        ON pricing_plans.id = pricing_plan_requests.plan_id
     WHERE {$request_where}
     ORDER BY pricing_plan_requests.requested_at DESC, pricing_plan_requests.id DESC"
);

if ($request_query) {
    while ($request_row = mysqli_fetch_assoc($request_query)) {
        $waiting_requests[] = $request_row;
        $waiting_request_count++;
        $waiting_request_map[(int)$request_row['plan_id']] = true;
    }
}

if(!$is_super_admin && $active_plan_name !== '' && strtolower($active_plan_name) !== 'trial'){
    foreach($plans as $plan_row){
        if(strtolower(trim((string)$plan_row['plan_name'])) === strtolower($active_plan_name)){
            $approved_plan_id = (int)$plan_row['id'];
            break;
        }
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Pricing Plan</h3>
    </div>

    <div class="card-body">
        <?php if($message !== ''){ ?>
            <div class="alert alert-<?= $message_type === 'success' ? 'success' : 'danger'; ?>">
                <?= htmlspecialchars($message); ?>
            </div>
        <?php } ?>

        <?php if(!$is_super_admin && $waiting_request_count > 0){ ?>
            <div class="alert alert-info">
                Your plan activation request is waiting for subscription approval.
            </div>
        <?php } ?>

        <?php if($is_super_admin){ ?>
            <div class="card card-primary mb-4">
                <div class="card-header">
                    <h3 class="card-title"><?= $edit_plan ? 'Edit Pricing Plan' : 'Create Pricing Plan'; ?></h3>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="<?= $edit_plan ? 'update_plan' : 'create_plan'; ?>">
                        <?php if($edit_plan){ ?>
                            <input type="hidden" name="plan_id" value="<?= (int)$edit_plan['id']; ?>">
                        <?php } ?>

                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label>Plan Name</label>
                                <input type="text" name="plan_name" class="form-control" value="<?= htmlspecialchars($edit_plan['plan_name'] ?? ''); ?>" required>
                            </div>
                            <div class="form-group col-md-4">
                                <label>Software Price</label>
                                <input type="number" step="0.01" min="0" name="one_time_price" class="form-control" value="<?= htmlspecialchars((string)($edit_plan['one_time_price'] ?? '')); ?>" required>
                            </div>
                            <div class="form-group col-md-4">
                                <label>Monthly Service Charge</label>
                                <input type="number" step="0.01" min="0" name="monthly_service_charge" class="form-control" value="<?= htmlspecialchars((string)($edit_plan['monthly_service_charge'] ?? '')); ?>" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label>Hosting Title</label>
                                <input type="text" name="hosting_title" class="form-control" value="<?= htmlspecialchars($edit_plan['hosting_title'] ?? ''); ?>" required>
                            </div>
                            <div class="form-group col-md-3">
                                <label>Status</label>
                                <select name="status" class="form-control">
                                    <option value="active" <?= ($edit_plan['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?= ($edit_plan['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="form-group col-md-3">
                                <label>Sort Order</label>
                                <input type="number" min="0" name="sort_order" class="form-control" value="<?= htmlspecialchars((string)($edit_plan['sort_order'] ?? count($plans) + 1)); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Feature Details</label>
                            <textarea name="feature_details" class="form-control" rows="5" required><?= htmlspecialchars($edit_plan['feature_details'] ?? ''); ?></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save mr-1"></i><?= $edit_plan ? 'Update Plan' : 'Create Plan'; ?>
                        </button>
                        <?php if($edit_plan){ ?>
                                    <a href="<?= htmlspecialchars(app_path('help/pricing_plan.php')); ?>" class="btn btn-secondary">Cancel</a>
                        <?php } ?>
                    </form>
                </div>
            </div>
        <?php } ?>

        <div class="row">
            <?php foreach($plans as $plan){ ?>
                <div class="col-md-4">
                    <div class="card h-100 <?= $plan['status'] === 'active' ? 'card-outline card-primary' : 'card-outline card-secondary'; ?>">
                        <div class="card-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <h3 class="card-title font-weight-bold mb-0"><?= htmlspecialchars($plan['plan_name']); ?></h3>
                                <?php if(!$is_super_admin && $approved_plan_id === (int)$plan['id']){ ?>
                                    <span class="badge badge-success">Activated</span>
                                <?php }else{ ?>
                                    <span class="badge badge-<?= $plan['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                        <?= htmlspecialchars(ucfirst($plan['status'])); ?>
                                    </span>
                                <?php } ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <div class="h4 mb-1"><?= htmlspecialchars(pricing_plan_money($plan['one_time_price'])); ?></div>
                                <div class="text-muted">Software Price</div>
                            </div>

                            <div class="mb-3">
                                <div class="h5 mb-1"><?= htmlspecialchars(pricing_plan_money($plan['monthly_service_charge'])); ?></div>
                                <div class="text-muted">Monthly Service Charge</div>
                            </div>

                            <div class="mb-3">
                                <strong><?= htmlspecialchars($plan['hosting_title']); ?></strong>
                            </div>

                            <div style="white-space: pre-wrap;"><?= htmlspecialchars($plan['feature_details']); ?></div>
                        </div>

                        <?php if($is_super_admin){ ?>
                            <div class="card-footer bg-white">
                                                <a href="<?= htmlspecialchars(app_path('help/pricing_plan.php?edit_id=' . (int)$plan['id'])); ?>" class="btn btn-warning btn-sm">
                                    <i class="fas fa-edit mr-1"></i>Edit
                                </a>

                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this pricing plan?');">
                                    <input type="hidden" name="action" value="delete_plan">
                                    <input type="hidden" name="plan_id" value="<?= (int)$plan['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">
                                        <i class="fas fa-trash mr-1"></i>Delete
                                    </button>
                                </form>
                            </div>
                        <?php } else { ?>
                            <div class="card-footer bg-white">
                                <?php if($approved_plan_id === (int)$plan['id']){ ?>
                                    <button type="button" class="btn btn-success btn-sm" disabled>
                                        <i class="fas fa-check-circle mr-1"></i>Activated
                                    </button>
                                <?php } elseif(isset($waiting_request_map[(int)$plan['id']])){ ?>
                                    <button type="button" class="btn btn-warning btn-sm" disabled>
                                        <i class="fas fa-clock mr-1"></i>Waiting for Subscription
                                    </button>
                                <?php } else { ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Send plan activation request?');">
                                        <input type="hidden" name="action" value="request_plan">
                                        <input type="hidden" name="plan_id" value="<?= (int)$plan['id']; ?>">
                                        <button type="submit" class="btn btn-primary btn-sm">
                                            <i class="fas fa-paper-plane mr-1"></i>Request Activation
                                        </button>
                                    </form>
                                <?php } ?>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            <?php } ?>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
