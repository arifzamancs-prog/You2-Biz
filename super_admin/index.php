<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/invoice_charge_helper.php';
require_once '../includes/company_settings_helper.php';
require_once '../includes/super_admin_config.php';
require_once '../includes/sms_helper.php';
require_once '../includes/branding_helper.php';
require_once '../includes/company_backup_helper.php';
require_once '../includes/smtp_mailer.php';
require_once '../includes/app_config.php';
require_once '../includes/email_verification_helper.php';
require_once '../includes/signup_message_helper.php';
require_once '../includes/contact_unique_helper.php';
require_once '../includes/restaurant_table_helper.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/printing_helper.php';
require_once '../includes/pricing_plan_visibility_helper.php';
require_once '../includes/product_category_helper.php';

require_super_admin_user();
ensure_all_admin_invoice_charges($conn);
ensure_company_setting_columns($conn);
ensure_sms_marketing_columns($conn);
ensure_email_verification_columns($conn);
ensure_signup_message_settings_table($conn);
ensure_restaurant_tables_table($conn);
printing_ensure_column($conn);
ensure_pricing_plan_visibility_column($conn);

function ensure_super_admin_company_type_column($conn)
{
    ensure_company_setting_columns($conn);
}

ensure_super_admin_company_type_column($conn);

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

ensure_pricing_plan_request_table($conn);
ensure_company_delete_backups_table($conn);

$trial_manager_limit = 2;
$trial_product_limit = 15;
$trial_invoice_limit = 150;
$trial_sms_quota = 5;
$trial_date_format = 'd-m-Y';
$unlimited_limit_value = 999999999;

$message = '';
$message_type = '';

if(isset($_SESSION['super_admin_flash_message'])){
    $message = (string)$_SESSION['super_admin_flash_message'];
    $message_type = (string)($_SESSION['super_admin_flash_type'] ?? 'success');
    unset($_SESSION['super_admin_flash_message'], $_SESSION['super_admin_flash_type']);
}

function super_admin_redirect_self()
{
    header("Location: " . app_path('super_admin/index.php'));
    exit;
}

function super_admin_flash_and_redirect($message, $type = 'success')
{
    $_SESSION['super_admin_flash_message'] = (string)$message;
    $_SESSION['super_admin_flash_type'] = (string)$type;
    super_admin_redirect_self();
}

function super_admin_send_company_verify_email($conn, $company_row)
{
    if(!is_array($company_row)){
        return [false, 'Company not found.'];
    }

    $company_id = (int)($company_row['id'] ?? 0);
    $company_name = trim((string)($company_row['name'] ?? ''));
    $company_email = trim((string)($company_row['email'] ?? ''));

    if($company_id <= 0 || $company_name === '' || $company_email === ''){
        return [false, 'Company information is incomplete.'];
    }

    if(!filter_var($company_email, FILTER_VALIDATE_EMAIL)){
        return [false, 'Valid email is required before sending verify link.'];
    }

    $verification_token = email_verification_create_token();
    $verification_token_hash = email_verification_hash($verification_token);
    $verification_expires_at = email_verification_expiry();

    $update_stmt = mysqli_prepare(
        $conn,
        "UPDATE users
         SET email_verified=0,
             email_verified_at=NULL,
             email_verification_token_hash=?,
             email_verification_expires_at=?,
             status='pending_verification'
         WHERE id=?
         AND role='admin'
         LIMIT 1"
    );

    if(!$update_stmt){
        return [false, 'Verification link could not be prepared.'];
    }

    mysqli_stmt_bind_param(
        $update_stmt,
        "ssi",
        $verification_token_hash,
        $verification_expires_at,
        $company_id
    );

    if(!mysqli_stmt_execute($update_stmt)){
        return [false, 'Verification link could not be prepared.'];
    }

    $signup_settings = signup_message_settings($conn);
    $verification_url = app_url('verify_email.php?token=' . urlencode($verification_token));
    $placeholders = [
        'name' => $company_name,
        'company' => $company_name,
        'email' => $company_email,
        'phone' => trim((string)($company_row['phone'] ?? '')),
        'verification_link' => $verification_url,
        'login_link' => app_url('login.php'),
        'customer_service' => '+8801977592783',
    ];

    $subject = signup_message_apply_placeholders(
        $signup_settings['email_subject'] ?? 'Verify your You2 Biz email',
        $placeholders
    );
    $body = signup_message_apply_placeholders(
        $signup_settings['email_message'] ?? '',
        $placeholders
    );

    $send_result = smtp_send_mail(
        $company_email,
        $company_name,
        $subject,
        signup_message_email_html($body)
    );

    if(!is_array($send_result) || empty($send_result[0])){
        return [false, 'Verify email could not be sent.'];
    }

    return [true, 'Verification link sent successfully.'];
}

function super_admin_valid_table_name($table)
{
    return preg_match('/^[A-Za-z0-9_]+$/', $table) === 1;
}

function super_admin_table_has_column($conn, $table, $column)
{
    if(!super_admin_valid_table_name($table) || !super_admin_valid_table_name($column)){
        return false;
    }

    $column = mysqli_real_escape_string($conn, $column);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");

    return $result && mysqli_num_rows($result) > 0;
}

function super_admin_delete_by_ids($conn, $table, $column, $ids)
{
    if(!super_admin_valid_table_name($table) || !super_admin_valid_table_name($column)){
        return false;
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

    if(empty($ids) || !super_admin_table_has_column($conn, $table, $column)){
        return true;
    }

    $id_list = implode(',', $ids);

    return mysqli_query($conn, "DELETE FROM `{$table}` WHERE `{$column}` IN ({$id_list})");
}

function super_admin_delete_by_value($conn, $table, $column, $value)
{
    if(!super_admin_valid_table_name($table) || !super_admin_valid_table_name($column)){
        return false;
    }

    if(!super_admin_table_has_column($conn, $table, $column)){
        return true;
    }

    $value = (int)$value;

    return mysqli_query($conn, "DELETE FROM `{$table}` WHERE `{$column}`={$value}");
}

function super_admin_company_avatar_cleanup_list($conn, $user_ids)
{
    $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));

    if(empty($user_ids)){
        return [];
    }

    $id_list = implode(',', $user_ids);
    $result = mysqli_query(
        $conn,
        "SELECT avatar
         FROM users
         WHERE id IN ({$id_list})"
    );

    $protected = [
        'you2biz.png',
        basename((string)SUPER_ADMIN_PROFILE_AVATAR),
        basename((string)branding_company_default_avatar_filename($conn)),
    ];

    $files = [];

    while($result && $row = mysqli_fetch_assoc($result)){
        $avatar = basename(trim((string)($row['avatar'] ?? '')));

        if($avatar === '' || in_array($avatar, $protected, true)){
            continue;
        }

        $files[] = $avatar;
    }

    return array_values(array_unique($files));
}

function super_admin_delete_company($conn, $company_id)
{
    $company_id = (int)$company_id;

    if($company_id <= 0){
        return [false, 'Invalid company selected.'];
    }

    $company_stmt = mysqli_prepare(
        $conn,
        "SELECT id
         FROM users
         WHERE id=?
         AND role='admin'
         LIMIT 1"
    );

    if(!$company_stmt){
        return [false, 'Delete failed.'];
    }

    mysqli_stmt_bind_param($company_stmt, "i", $company_id);
    mysqli_stmt_execute($company_stmt);
    $company_result = mysqli_stmt_get_result($company_stmt);

    if(!$company_result || mysqli_num_rows($company_result) === 0){
        return [false, 'Company not found.'];
    }

    $user_ids = [$company_id];
    $manager_result = mysqli_query(
        $conn,
        "SELECT id
         FROM users
         WHERE owner_id={$company_id}
         AND role='manager'"
    );

    while($manager_result && $manager = mysqli_fetch_assoc($manager_result)){
        $user_ids[] = (int)$manager['id'];
    }

    $avatar_files = super_admin_company_avatar_cleanup_list($conn, $user_ids);

    mysqli_begin_transaction($conn);

    try{
        $child_deletes = [
            "DELETE FROM invoice_item_allocations
             WHERE invoice_item_id IN (
                SELECT id
                FROM invoice_items
                WHERE invoice_id IN (SELECT id FROM invoices WHERE user_id={$company_id})
             )",
            "DELETE FROM invoice_charges
             WHERE invoice_id IN (SELECT id FROM invoices WHERE user_id={$company_id})",
            "DELETE FROM invoice_items
             WHERE invoice_id IN (SELECT id FROM invoices WHERE user_id={$company_id})",
            "DELETE FROM purchase_items
             WHERE purchase_id IN (SELECT id FROM purchases WHERE user_id={$company_id})",
            "DELETE FROM support_ticket_replies
             WHERE ticket_id IN (
                SELECT id
                FROM support_tickets
                WHERE admin_user_id={$company_id}
             )",
        ];

        foreach($child_deletes as $sql){
            if(!mysqli_query($conn, $sql)){
                throw new Exception(mysqli_error($conn));
            }
        }

        $tables_result = mysqli_query($conn, "SHOW TABLES");

        while($tables_result && $table_row = mysqli_fetch_row($tables_result)){
            $table = $table_row[0];

            if($table === 'users' || !super_admin_valid_table_name($table)){
                continue;
            }

            if(super_admin_table_has_column($conn, $table, 'user_id')){
                if(!super_admin_delete_by_ids($conn, $table, 'user_id', $user_ids)){
                    throw new Exception(mysqli_error($conn));
                }
            }
        }

        $admin_id_tables = [
            'pricing_plan_requests',
            'support_tickets',
        ];

        foreach($admin_id_tables as $table){
            if(!super_admin_delete_by_value($conn, $table, 'admin_user_id', $company_id)){
                throw new Exception(mysqli_error($conn));
            }
        }

        if(!mysqli_query($conn, "DELETE FROM users WHERE owner_id={$company_id} AND role='manager'")){
            throw new Exception(mysqli_error($conn));
        }

        if(!mysqli_query($conn, "DELETE FROM users WHERE id={$company_id} AND role='admin'")){
            throw new Exception(mysqli_error($conn));
        }

        mysqli_commit($conn);

        $avatar_dir = branding_avatar_upload_dir_path();

        foreach($avatar_files as $avatar_file){
            $avatar_path = $avatar_dir . '/' . basename($avatar_file);

            if(is_file($avatar_path)){
                @unlink($avatar_path);
            }
        }

        return [true, 'Company and all related data deleted.'];
    }catch(Exception $e){
        mysqli_rollback($conn);

        return [false, 'Delete failed: ' . $e->getMessage()];
    }
}

function super_admin_activate_requested_plan($conn, $request_id, $unlimited_limit_value)
{
    $request_id = (int)$request_id;

    if($request_id <= 0){
        return [false, 'Invalid subscription request.'];
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT pricing_plan_requests.id,
                pricing_plan_requests.admin_user_id,
                pricing_plan_requests.plan_id,
                pricing_plan_requests.request_status,
                pricing_plans.plan_name
         FROM pricing_plan_requests
         LEFT JOIN pricing_plans
            ON pricing_plans.id = pricing_plan_requests.plan_id
         WHERE pricing_plan_requests.id=?
         LIMIT 1"
    );

    if(!$stmt){
        return [false, 'Subscription request could not be loaded.'];
    }

    mysqli_stmt_bind_param($stmt, "i", $request_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $request = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    if(!$request){
        return [false, 'Subscription request not found.'];
    }

    if(($request['request_status'] ?? '') === 'approved'){
        return [true, 'Requested package is already active.'];
    }

    $company_id = (int)($request['admin_user_id'] ?? 0);
    $plan_name = trim((string)($request['plan_name'] ?? ''));

    if($company_id <= 0 || $plan_name === ''){
        return [false, 'Requested package information is incomplete.'];
    }

    mysqli_begin_transaction($conn);

    try{
        $subscription_status = 'active';
        $company_status = 'active';
        $subscription_expires_at = null;

        $update_stmt = mysqli_prepare(
            $conn,
            "UPDATE users
             SET status=?,
                 subscription_plan=?,
                 subscription_status=?,
                 max_managers=?,
                 max_products=?,
                 max_invoices_monthly=?,
                 subscription_expires_at=?
             WHERE id=?
             AND role='admin'
             LIMIT 1"
        );

        if(!$update_stmt){
            throw new Exception(mysqli_error($conn));
        }

        mysqli_stmt_bind_param(
            $update_stmt,
            "sssiiisi",
            $company_status,
            $plan_name,
            $subscription_status,
            $unlimited_limit_value,
            $unlimited_limit_value,
            $unlimited_limit_value,
            $subscription_expires_at,
            $company_id
        );

        if(!mysqli_stmt_execute($update_stmt)){
            throw new Exception(mysqli_stmt_error($update_stmt));
        }

        mysqli_stmt_close($update_stmt);

        if(!mysqli_query(
            $conn,
            "UPDATE pricing_plan_requests
             SET request_status='rejected'
             WHERE admin_user_id={$company_id}
             AND request_status='waiting'
             AND id<>{$request_id}"
        )){
            throw new Exception(mysqli_error($conn));
        }

        if(!mysqli_query(
            $conn,
            "UPDATE pricing_plan_requests
             SET request_status='approved'
             WHERE id={$request_id}
             LIMIT 1"
        )){
            throw new Exception(mysqli_error($conn));
        }

        mysqli_commit($conn);

        return [true, 'Requested package activated successfully.'];
    }catch(Exception $e){
        mysqli_rollback($conn);

        return [false, 'Package activation failed. ' . $e->getMessage()];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $company_id = (int)($_POST['company_id'] ?? 0);
    $form_action = $_POST['form_action'] ?? 'update_company';

    if($form_action === 'update_company_type'){
        $company_type = trim((string)($_POST['company_type'] ?? ''));

        if($company_id <= 0){
            super_admin_flash_and_redirect('Invalid company selected.', 'danger');
        }

        if(!valid_company_type($company_type)){
            super_admin_flash_and_redirect('Please select company type.', 'danger');
        }

        $company_type = normalize_company_type($company_type);

        $type_stmt = mysqli_prepare(
            $conn,
            "UPDATE users
             SET company_type=?
             WHERE id=?
             AND role='admin'
             LIMIT 1"
        );

        if(!$type_stmt){
            super_admin_flash_and_redirect('Company type update failed.', 'danger');
        }

        mysqli_stmt_bind_param($type_stmt, 'si', $company_type, $company_id);

        if(mysqli_stmt_execute($type_stmt)){
            super_admin_flash_and_redirect('Company type updated successfully.', 'success');
        }

        super_admin_flash_and_redirect('Company type update failed.', 'danger');
    }elseif($form_action === 'update_pricing_plan_visibility'){
        $visible = (int)($_POST['pricing_plan_visible'] ?? 1) === 1 ? 1 : 0;
        if($company_id <= 0){
            super_admin_flash_and_redirect('Invalid company selected.', 'danger');
        }
        $stmt = mysqli_prepare($conn, "UPDATE users SET show_pricing_plan=? WHERE id=? AND role='admin' LIMIT 1");
        if($stmt){
            mysqli_stmt_bind_param($stmt, 'ii', $visible, $company_id);
            if(mysqli_stmt_execute($stmt)){
                super_admin_flash_and_redirect('Pricing Plan visibility updated.', 'success');
            }
        }
        super_admin_flash_and_redirect('Pricing Plan visibility update failed.', 'danger');
    }elseif($form_action === 'update_single_user_license'){
        $license_status = strtolower(trim((string)($_POST['license_status'] ?? 'inactive'))) === 'active'
            ? 'active'
            : 'inactive';
        $license_company_id = (int)($_POST['license_company_id'] ?? 0);
        $license_site_title = trim((string)($_POST['license_site_title'] ?? ''));
        $license_slogan = trim((string)($_POST['license_slogan'] ?? ''));

        if($license_status === 'active'){
            if($license_company_id <= 0){
                super_admin_flash_and_redirect('Please select a company for single user licence.', 'danger');
            }

            $company_check_stmt = mysqli_prepare(
                $conn,
                "SELECT id FROM users WHERE id=? AND role='admin' LIMIT 1"
            );

            mysqli_stmt_bind_param($company_check_stmt, 'i', $license_company_id);
            mysqli_stmt_execute($company_check_stmt);
            $company_check = mysqli_fetch_assoc(mysqli_stmt_get_result($company_check_stmt));

            if(!$company_check){
                super_admin_flash_and_redirect('Selected company was not found.', 'danger');
            }

            if($license_site_title === ''){
                super_admin_flash_and_redirect('Site title is required.', 'danger');
            }

            if($license_slogan === ''){
                super_admin_flash_and_redirect('Company slogan is required.', 'danger');
            }
        }

        [$logo_ok, $logo_file, $logo_uploaded, $logo_error] = branding_upload_file(
            $conn,
            'license_logo',
            'single_user_license_logo_file',
            'single-user-logo',
            false
        );

        if(!$logo_ok){
            super_admin_flash_and_redirect($logo_error ?: 'Logo could not be uploaded.', 'danger');
        }

        if($license_status === 'active' && trim((string)$logo_file) === ''){
            super_admin_flash_and_redirect('Logo is required for single user licence.', 'danger');
        }

        system_settings_save_many($conn, [
            'single_user_license_status' => $license_status,
            'single_user_license_company_id' => (string)$license_company_id,
            'single_user_license_site_title' => $license_site_title,
            'single_user_license_slogan' => $license_slogan,
        ]);

        if($license_status === 'active'){
            $subscription_plan = 'Basic';
            $subscription_status = 'active';
            $company_status = 'active';
            $subscription_expires_at = null;

            $license_subscription_stmt = mysqli_prepare(
                $conn,
                "UPDATE users
                 SET status=?,
                     subscription_plan=?,
                     subscription_status=?,
                     max_managers=?,
                     max_products=?,
                     max_invoices_monthly=?,
                     subscription_expires_at=?,
                     table_system_enabled=1
                 WHERE id=?
                 AND role='admin'
                 LIMIT 1"
            );

            if($license_subscription_stmt){
                mysqli_stmt_bind_param(
                    $license_subscription_stmt,
                    'sssiiisi',
                    $company_status,
                    $subscription_plan,
                    $subscription_status,
                    $unlimited_limit_value,
                    $unlimited_limit_value,
                    $unlimited_limit_value,
                    $subscription_expires_at,
                    $license_company_id
                );
                mysqli_stmt_execute($license_subscription_stmt);
            }

            mysqli_query(
                $conn,
                "UPDATE pricing_plan_requests
                 SET request_status='approved'
                 WHERE admin_user_id={$license_company_id}
                 AND request_status='waiting'"
            );
        }

        super_admin_flash_and_redirect(
            $license_status === 'active'
                ? 'Single user licence activated successfully.'
                : 'Single user licence inactive. Default public branding restored.',
            'success'
        );
    }elseif($form_action === 'create_company'){
        $company_name = trim((string)($_POST['company_name'] ?? ''));
        $company_type = trim((string)($_POST['company_type'] ?? ''));
        $company_email = trim((string)($_POST['company_email'] ?? ''));
        $company_phone = trim((string)($_POST['company_phone'] ?? ''));
        $company_password = (string)($_POST['company_password'] ?? '');

        if($company_name === ''){
            super_admin_flash_and_redirect('Company or account name is required.', 'danger');
        }

        if(!valid_company_type($company_type)){
            super_admin_flash_and_redirect('Please select company type.', 'danger');
        }

        if(!filter_var($company_email, FILTER_VALIDATE_EMAIL)){
            super_admin_flash_and_redirect('Please enter a valid company email address.', 'danger');
        }

        if($company_phone === ''){
            super_admin_flash_and_redirect('Company phone number is required.', 'danger');
        }

        if(strlen($company_password) < 6){
            super_admin_flash_and_redirect('Password must contain at least 6 characters.', 'danger');
        }

        $duplicate_message = contact_duplicate_message($conn, 'email', $company_email);
        if($duplicate_message === ''){
            $duplicate_message = contact_duplicate_message($conn, 'phone', $company_phone);
        }

        if($duplicate_message !== ''){
            super_admin_flash_and_redirect($duplicate_message, 'danger');
        }

        $password_hash = password_hash($company_password, PASSWORD_DEFAULT);
        $avatar = branding_company_default_avatar_filename($conn);
        $create_stmt = mysqli_prepare(
            $conn,
            "INSERT INTO users
                (name, company_type, email, phone, password, role, status, avatar, email_verified, email_verified_at)
             VALUES (?, ?, ?, ?, ?, 'admin', 'active', ?, 1, NOW())"
        );

        if(!$create_stmt){
            super_admin_flash_and_redirect('Company could not be created.', 'danger');
        }

        mysqli_stmt_bind_param(
            $create_stmt,
            'ssssss',
            $company_name,
            normalize_company_type($company_type),
            $company_email,
            $company_phone,
            $password_hash,
            $avatar
        );

        if(!mysqli_stmt_execute($create_stmt)){
            mysqli_stmt_close($create_stmt);
            super_admin_flash_and_redirect('Company could not be created.', 'danger');
        }

        $new_company_id = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($create_stmt);

        $safe_avatar = mysqli_real_escape_string($conn, $avatar);
        $default_printing_option = mysqli_real_escape_string($conn, printing_default_user_value($conn, 'printing_option', 'general'));
        $default_seal = mysqli_real_escape_string($conn, basename((string)printing_default_user_value($conn, 'company_seal_file', printing_default_company_seal_filename())));
        $default_paid_seal = mysqli_real_escape_string($conn, basename((string)printing_default_user_value($conn, 'paid_seal_file', printing_default_paid_seal_filename())));

        mysqli_query($conn, "UPDATE users SET
            owner_id={$new_company_id},
            avatar='{$safe_avatar}',
            printing_option='{$default_printing_option}',
            company_seal_file='{$default_seal}',
            paid_seal_file='{$default_paid_seal}',
            subscription_plan='Trial',
            subscription_status='trial',
            max_managers={$trial_manager_limit},
            max_products={$trial_product_limit},
            max_invoices_monthly={$trial_invoice_limit},
            subscription_expires_at=DATE_ADD(created_at, INTERVAL 30 DAY),
            date_format='{$trial_date_format}'
            WHERE id={$new_company_id} LIMIT 1");

        ensure_default_product_categories($conn, $new_company_id);
        ensure_default_invoice_charges($conn, $new_company_id);
        ensure_default_cash_wallet($conn, $new_company_id);

        super_admin_flash_and_redirect('Company created and verified successfully.', 'success');
    }elseif($form_action === 'approve_request'){
        $request_id = (int)($_POST['request_id'] ?? 0);
        [$approved, $approve_message] = super_admin_activate_requested_plan($conn, $request_id, $unlimited_limit_value);
        super_admin_flash_and_redirect($approve_message, $approved ? 'success' : 'danger');
    }elseif($form_action === 'delete_request'){
        $request_id = (int)($_POST['request_id'] ?? 0);

        if($request_id <= 0){
            super_admin_flash_and_redirect('Invalid subscription request.', 'danger');
        }else{
            $request_stmt = mysqli_prepare(
                $conn,
                "DELETE FROM pricing_plan_requests
                 WHERE id=?
                 LIMIT 1"
            );

            if($request_stmt){
                mysqli_stmt_bind_param($request_stmt, "i", $request_id);

                if(mysqli_stmt_execute($request_stmt) && mysqli_stmt_affected_rows($request_stmt) > 0){
                    super_admin_flash_and_redirect('Subscription request deleted successfully.', 'success');
                }else{
                    super_admin_flash_and_redirect('Subscription request could not be deleted.', 'danger');
                }

                mysqli_stmt_close($request_stmt);
            }else{
                super_admin_flash_and_redirect('Subscription request could not be deleted.', 'danger');
            }
        }
    }elseif($form_action === 'update_contact'){
        $company_name = trim((string)($_POST['company_name'] ?? ''));
        $company_email = trim((string)($_POST['company_email'] ?? ''));
        $company_phone = trim((string)($_POST['company_phone'] ?? ''));

        if($company_id <= 0){
            super_admin_flash_and_redirect('Invalid company selected.', 'danger');
        }

        if($company_name === ''){
            super_admin_flash_and_redirect('Company name is required.', 'danger');
        }

        if($company_email === '' || !filter_var($company_email, FILTER_VALIDATE_EMAIL)){
            super_admin_flash_and_redirect('Please enter a valid email address.', 'danger');
        }

        if($company_phone === ''){
            super_admin_flash_and_redirect('Company phone number is required.', 'danger');
        }

        $company_check_stmt = mysqli_prepare(
            $conn,
            "SELECT id, role
             FROM users
             WHERE id=?
             LIMIT 1"
        );

        if(!$company_check_stmt){
            super_admin_flash_and_redirect('Company check failed.', 'danger');
        }

        mysqli_stmt_bind_param($company_check_stmt, "i", $company_id);
        mysqli_stmt_execute($company_check_stmt);
        $company_check_result = mysqli_stmt_get_result($company_check_stmt);
        $company_check_row = $company_check_result ? mysqli_fetch_assoc($company_check_result) : null;

        if(!$company_check_row || ($company_check_row['role'] ?? '') !== 'admin'){
            super_admin_flash_and_redirect('Only admin company profile can be updated here.', 'danger');
        }

        $duplicate_message = contact_duplicate_message_in_table(
            $conn,
            'users',
            'User',
            'email',
            $company_email,
            $company_id
        );

        if($duplicate_message !== ''){
            super_admin_flash_and_redirect($duplicate_message, 'danger');
        }

        $duplicate_message = contact_duplicate_message_in_table(
            $conn,
            'users',
            'User',
            'phone',
            $company_phone,
            $company_id
        );

        if($duplicate_message !== ''){
            super_admin_flash_and_redirect($duplicate_message, 'danger');
        }

        $contact_stmt = mysqli_prepare(
            $conn,
            "UPDATE users
             SET name=?,
                 email=?,
                 phone=?,
                 status='active',
                 email_verified=1,
                 email_verified_at=NOW(),
                 email_verification_token_hash=NULL,
                 email_verification_expires_at=NULL
             WHERE id=?
             AND role='admin'
             LIMIT 1"
        );

        if(!$contact_stmt){
            super_admin_flash_and_redirect('Contact update failed.', 'danger');
        }

        mysqli_stmt_bind_param($contact_stmt, "sssi", $company_name, $company_email, $company_phone, $company_id);

        if(mysqli_stmt_execute($contact_stmt)){
            super_admin_flash_and_redirect('Company profile updated and verified successfully.', 'success');
        }

        super_admin_flash_and_redirect('Contact update failed.', 'danger');
    }elseif($form_action === 'force_verify_company'){
        if($company_id <= 0){
            super_admin_flash_and_redirect('Invalid company selected.', 'danger');
        }

        $verify_stmt = mysqli_prepare(
            $conn,
            "UPDATE users
             SET email_verified=1,
                 email_verified_at=NOW(),
                 email_verification_token_hash=NULL,
                 email_verification_expires_at=NULL,
                 status='active'
             WHERE id=?
             AND role='admin'
             LIMIT 1"
        );

        if(!$verify_stmt){
            super_admin_flash_and_redirect('Forced verification failed.', 'danger');
        }

        mysqli_stmt_bind_param($verify_stmt, 'i', $company_id);

        if(mysqli_stmt_execute($verify_stmt) && mysqli_stmt_affected_rows($verify_stmt) > 0){
            super_admin_flash_and_redirect('Company forced verified successfully.', 'success');
        }

        super_admin_flash_and_redirect('Company forced verification failed.', 'danger');
    }elseif($form_action === 'send_verify_link'){
        if($company_id <= 0){
            super_admin_flash_and_redirect('Invalid company selected.', 'danger');
        }

        $company_stmt = mysqli_prepare(
            $conn,
            "SELECT id, name, email, phone
             FROM users
             WHERE id=?
             AND role='admin'
             LIMIT 1"
        );

        if(!$company_stmt){
            super_admin_flash_and_redirect('Company not found.', 'danger');
        }

        mysqli_stmt_bind_param($company_stmt, "i", $company_id);
        mysqli_stmt_execute($company_stmt);
        $company_result = mysqli_stmt_get_result($company_stmt);
        $company_row = $company_result ? mysqli_fetch_assoc($company_result) : null;

        [$sent, $verify_message] = super_admin_send_company_verify_email($conn, $company_row);
        super_admin_flash_and_redirect($verify_message, $sent ? 'success' : 'danger');
    }elseif($form_action === 'delete_company'){
        $delete_password = $_POST['super_admin_password'] ?? '';

        if(!is_super_admin_login(super_admin_notify_email(), $delete_password)){
            super_admin_flash_and_redirect('Super Admin password is incorrect.', 'danger');
        }else{
            [$backup_saved, $backup_error, $backup_info] = company_backup_create_and_store(
                $conn,
                $company_id,
                'company_delete',
                (int)($_SESSION['login_user_id'] ?? 0),
                (string)($_SESSION['user_role'] ?? 'super_admin'),
                true
            );

            if(!$backup_saved){
                super_admin_flash_and_redirect('Backup could not be created. Delete was stopped. ' . $backup_error, 'danger');
            }else{
                [$deleted, $delete_message] = super_admin_delete_company($conn, $company_id);
                super_admin_flash_and_redirect($delete_message, $deleted ? 'success' : 'danger');
            }
        }
    }else{
    $subscription_status = $_POST['subscription_status'] ?? 'trial';
    $table_system_enabled = isset($_POST['table_system_enabled']) && (int)$_POST['table_system_enabled'] === 0 ? 0 : 1;
    $allowed_subscription_status = ['trial','active','expired','blocked'];
    $max_managers = max(0, (int)($_POST['max_managers'] ?? 1));
    $max_products = max(0, (int)($_POST['max_products'] ?? 100));
    $max_invoices_monthly = max(0, (int)($_POST['max_invoices_monthly'] ?? 300));
    $sms_quota_total = max(0, (int)($_POST['sms_quota_total'] ?? $trial_sms_quota));
    $subscription_expires_at = trim($_POST['subscription_expires_at'] ?? '');
    $currency_code = normalize_company_currency($_POST['currency_code'] ?? 'BDT');
    $timezone_name = normalize_company_timezone($_POST['timezone_name'] ?? 'Asia/Dhaka');
    $date_format = normalize_company_date_format($_POST['date_format'] ?? 'Y-m-d');

    if(!in_array($subscription_status, $allowed_subscription_status, true)){
        $subscription_status = 'trial';
    }

    $status = in_array($subscription_status, ['expired', 'blocked'], true)
        ? 'inactive'
        : 'active';
    $subscription_plan = ucfirst($subscription_status);

    if($subscription_status === 'trial'){
        $subscription_plan = 'Trial';
        $subscription_status = 'trial';
        $max_managers = $trial_manager_limit;
        $max_products = $trial_product_limit;
        $max_invoices_monthly = $trial_invoice_limit;
        $date_format = $trial_date_format;

        $created_stmt = mysqli_prepare(
            $conn,
            "SELECT DATE(DATE_ADD(created_at, INTERVAL 30 DAY)) AS trial_expiry
             FROM users
             WHERE id=?
             AND role='admin'
             LIMIT 1"
        );

        if($created_stmt){
            mysqli_stmt_bind_param($created_stmt, "i", $company_id);
            mysqli_stmt_execute($created_stmt);
            $created_result = mysqli_stmt_get_result($created_stmt);
            $created_row = $created_result ? mysqli_fetch_assoc($created_result) : null;
            $subscription_expires_at = $created_row['trial_expiry'] ?? date('Y-m-d', strtotime('+30 days'));
        }
    }elseif($subscription_status === 'active'){
        // Direct Super Admin activation has no plan request to identify a
        // selected package, so it activates the default Basic package.
        $subscription_plan = 'Basic';
        $subscription_expires_at = null;
        $max_managers = $unlimited_limit_value;
        $max_products = $unlimited_limit_value;
        $max_invoices_monthly = $unlimited_limit_value;
    }elseif(in_array($subscription_status, ['expired', 'blocked'], true)){
        $subscription_expires_at = date('Y-m-d');
    }

    if($subscription_expires_at === ''){
        $subscription_expires_at = null;
    }

    $sql = "UPDATE users
            SET status=?,
                subscription_plan=?,
                subscription_status=?,
                max_managers=?,
                max_products=?,
                max_invoices_monthly=?,
                sms_quota_total=GREATEST(?, sms_quota_used),
                subscription_expires_at=?,
                currency_code=?,
                timezone_name=?,
                date_format=?,
                table_system_enabled=?
            WHERE id=?
            AND role='admin'";

    $stmt = mysqli_prepare($conn, $sql);

    mysqli_stmt_bind_param(
        $stmt,
        "sssiiiissssii",
        $status,
        $subscription_plan,
        $subscription_status,
        $max_managers,
        $max_products,
        $max_invoices_monthly,
        $sms_quota_total,
        $subscription_expires_at,
        $currency_code,
        $timezone_name,
        $date_format,
        $table_system_enabled,
        $company_id
    );

    if(mysqli_stmt_execute($stmt)){
        if($subscription_status === 'active'){
            mysqli_query(
                $conn,
                "UPDATE pricing_plan_requests
                 SET request_status='approved'
                 WHERE admin_user_id={$company_id}
                 AND request_status='waiting'"
            );
        }

        super_admin_flash_and_redirect("Company subscription updated.", "success");
    }else{
        super_admin_flash_and_redirect("Update failed.", "danger");
    }
    }
}

mysqli_query(
    $conn,
    "UPDATE users
     SET subscription_plan='Trial',
         max_managers={$trial_manager_limit},
         max_products={$trial_product_limit},
         max_invoices_monthly={$trial_invoice_limit},
         subscription_expires_at=DATE(DATE_ADD(created_at, INTERVAL 30 DAY)),
         date_format='{$trial_date_format}'
     WHERE role='admin'
     AND (
        LOWER(subscription_plan)='trial'
        OR subscription_status='trial'
     )"
);

$summary_sql = "SELECT
                    COUNT(*) AS total_companies,
                    SUM(status='active') AS active_companies,
                    SUM(status='inactive') AS inactive_companies
                FROM users
                WHERE role='admin'";

$summary_result = mysqli_query($conn, $summary_sql);
$summary = mysqli_fetch_assoc($summary_result);

$waiting_pricing_requests = [];
$waiting_pricing_requests_result = mysqli_query(
    $conn,
    "SELECT pricing_plan_requests.*, pricing_plans.plan_name
     FROM pricing_plan_requests
     LEFT JOIN pricing_plans
        ON pricing_plans.id = pricing_plan_requests.plan_id
     WHERE pricing_plan_requests.request_status IN ('waiting','approved')
     ORDER BY pricing_plan_requests.requested_at DESC, pricing_plan_requests.id DESC"
);

if($waiting_pricing_requests_result){
    while($waiting_row = mysqli_fetch_assoc($waiting_pricing_requests_result)){
        $waiting_pricing_requests[] = $waiting_row;
    }
}

$sql = "SELECT
            u.id,
            u.name,
            u.company_type,
            u.email,
            u.phone,
            u.email_verified,
            u.status,
            u.subscription_plan,
            u.subscription_status,
            u.max_managers,
            u.max_products,
            u.max_invoices_monthly,
            u.sms_quota_total,
            u.sms_quota_used,
            u.subscription_expires_at,
            u.currency_code,
            u.timezone_name,
            u.date_format,
            u.table_system_enabled,
            u.show_pricing_plan,
            u.created_at,
            u.last_login,
            COUNT(DISTINCT m.id) AS manager_count,
            COUNT(DISTINCT p.id) AS product_count,
            COUNT(DISTINCT i.id) AS invoice_count
        FROM users u
        LEFT JOIN users m
            ON m.owner_id = u.id
            AND m.role='manager'
        LEFT JOIN products p
            ON p.user_id = u.id
        LEFT JOIN invoices i
            ON i.user_id = u.id
        WHERE u.role='admin'
        GROUP BY
            u.id,
            u.name,
            u.company_type,
            u.email,
            u.phone,
            u.email_verified,
            u.status,
            u.subscription_plan,
            u.subscription_status,
            u.max_managers,
            u.max_products,
            u.max_invoices_monthly,
            u.sms_quota_total,
            u.sms_quota_used,
            u.subscription_expires_at,
            u.currency_code,
            u.timezone_name,
            u.date_format,
            u.table_system_enabled,
            u.show_pricing_plan,
            u.created_at,
            u.last_login
        ORDER BY u.id DESC";

$companies = mysqli_query($conn, $sql);
$license_companies = mysqli_query($conn, "SELECT id, name, email FROM users WHERE role='admin' ORDER BY name ASC");
$single_user_license_status = system_setting($conn, 'single_user_license_status', 'inactive');
$single_user_license_company_id = (int)system_setting($conn, 'single_user_license_company_id', '0');
$single_user_license_logo_file = system_setting($conn, 'single_user_license_logo_file', '');
$single_user_license_site_title = system_setting($conn, 'single_user_license_site_title', '');
$single_user_license_slogan = system_setting($conn, 'single_user_license_slogan', '');
$single_user_license_logo_url = branding_file_url($single_user_license_logo_file, '');
$timezone_options = company_timezone_options();
$date_format_options = company_date_format_options();

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="row">
    <div class="col-md-4">
        <div class="info-box">
            <span class="info-box-icon bg-primary">
                <i class="fas fa-building"></i>
            </span>
            <div class="info-box-content">
                <span class="info-box-text">Companies</span>
                <span class="info-box-number"><?= (int)($summary['total_companies'] ?? 0); ?></span>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="info-box">
            <span class="info-box-icon bg-success">
                <i class="fas fa-check-circle"></i>
            </span>
            <div class="info-box-content">
                <span class="info-box-text">Active</span>
                <span class="info-box-number"><?= (int)($summary['active_companies'] ?? 0); ?></span>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="info-box">
            <span class="info-box-icon bg-secondary">
                <i class="fas fa-ban"></i>
            </span>
            <div class="info-box-content">
                <span class="info-box-text">Inactive</span>
                <span class="info-box-number"><?= (int)($summary['inactive_companies'] ?? 0); ?></span>
            </div>
        </div>
    </div>
</div>

<?php if($message){ ?>
    <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
        <?= htmlspecialchars($message); ?>
    </div>
<?php } ?>

<?php if(!empty($waiting_pricing_requests)){ ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Subscription Requests</h3>
        </div>
        <div class="card-body">
            <table id="subscriptionRequestsTable" class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Admin</th>
                        <th>Contact</th>
                        <th>Plan</th>
                        <th>Status</th>
                        <th width="120">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($waiting_pricing_requests as $request){ ?>
                        <?php
                            $request_done = ($request['request_status'] ?? '') === 'approved';
                        ?>
                        <tr>
                            <td><?= htmlspecialchars(date('d-m-Y h:i A', strtotime($request['requested_at']))); ?></td>
                            <td><?= htmlspecialchars($request['admin_name']); ?></td>
                            <td>
                                <?= htmlspecialchars($request['admin_email']); ?><br>
                                <small><?= htmlspecialchars($request['admin_phone']); ?></small>
                            </td>
                            <td><?= htmlspecialchars($request['plan_name'] ?? 'Plan'); ?></td>
                            <td>
                                <span class="badge badge-<?= $request_done ? 'success' : 'warning'; ?>">
                                    <?= $request_done ? 'Done' : 'Waiting'; ?>
                                </span>
                            </td>
                            <td>
                                <?php if(!$request_done){ ?>
                                    <form method="post" class="d-inline-block mr-1" onsubmit="return confirm('Approve this package request?');">
                                        <input type="hidden" name="form_action" value="approve_request">
                                        <input type="hidden" name="request_id" value="<?= (int)$request['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-success">Approve</button>
                                    </form>
                                <?php } ?>
                                <form method="post" class="d-inline-block" onsubmit="return confirm('Delete this subscription request?');">
                                    <input type="hidden" name="form_action" value="delete_request">
                                    <input type="hidden" name="request_id" value="<?= (int)$request['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
<?php } ?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">
            Super Admin
        </h3>
        <div class="card-tools">
            <?php if($single_user_license_status === 'active'){ ?>
                <span class="font-weight-bold text-danger mr-2">Single User Licence Activated</span>
            <?php } ?>
            <button type="button" class="btn btn-success btn-sm mr-1" data-toggle="modal" data-target="#singleUserLicenseModal">
                <i class="fas fa-user-shield"></i> Single User Licence
            </button>
            <button
                type="button"
                class="btn btn-primary btn-sm"
                data-toggle="modal"
                data-target="#createCompanyModal"
                <?= $single_user_license_status === 'active' ? 'disabled title="Single user licence active thakle new company create kora jabe na."' : ''; ?>>
                <i class="fas fa-plus"></i> Create Company
            </button>
        </div>
    </div>

    <div class="card-body">
        <table id="example1" class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>Company</th>
                    <th>Contact</th>
                    <th>Status</th>
                    <th>Usage</th>
                    <th>Subscription</th>
                    <th width="360">Control</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = mysqli_fetch_assoc($companies)){
                    $is_single_user_license_company = $single_user_license_status === 'active'
                        && $single_user_license_company_id === (int)$row['id'];

                    if($single_user_license_status === 'active' && !$is_single_user_license_company){
                        continue;
                    }
                ?>
                    <tr>
                        <td>
                            <strong>
                                <?= htmlspecialchars($row['name']); ?>
                                <?php if($is_single_user_license_company){ ?>
                                    <span class="text-warning ml-1" title="Single User Licence Activated">
                                        <i class="fas fa-crown"></i>
                                    </span>
                                    <span class="text-info ml-1" title="Licensed Company">
                                        <i class="fas fa-gem"></i>
                                    </span>
                                <?php } ?>
                            </strong><br>
                            <small class="text-muted">
                                Registered: <?= htmlspecialchars(app_datetime($row['created_at'])); ?>
                            </small><br>
                            <small class="text-muted">
                                Last login: <?= htmlspecialchars(app_datetime($row['last_login'] ?? null)); ?>
                            </small>
                            <form method="post" class="mt-2">
                                <input type="hidden" name="company_id" value="<?= (int)$row['id']; ?>">
                                <input type="hidden" name="form_action" value="update_company_type">
                                <label class="small text-muted mb-1">Company Type</label>
                                <div class="input-group input-group-sm">
                                    <?php $company_type = ($row['company_type'] ?? 'Housing') === 'Others' ? 'Others' : 'Housing'; ?>
                                    <select name="company_type" class="form-control">
                                        <option value="Housing" <?= $company_type === 'Housing' ? 'selected' : ''; ?>>Housing</option>
                                        <option value="Others" <?= $company_type === 'Others' ? 'selected' : ''; ?>>Others</option>
                                    </select>
                                    <div class="input-group-append">
                                        <button type="submit" class="btn btn-primary">Update</button>
                                    </div>
                                </div>
                            </form>
                        </td>
                        <td>
                            <form method="post" class="contact-edit-form mb-2">
                                <input type="hidden" name="company_id" value="<?= (int)$row['id']; ?>">
                                <input type="hidden" name="form_action" value="update_contact">
                                <div class="company-email-readonly">
                                    <?= htmlspecialchars($row['email']); ?>
                                </div>
                                <small class="company-phone-readonly"><?= htmlspecialchars($row['phone']); ?></small>
                                <div class="company-profile-editor mt-2" style="display:none;">
                                    <input
                                        type="text"
                                        name="company_name"
                                        class="form-control form-control-sm company-profile-input mb-1"
                                        value="<?= htmlspecialchars($row['name']); ?>"
                                        placeholder="Company name"
                                        required>
                                    <input
                                        type="email"
                                        name="company_email"
                                        class="form-control form-control-sm company-profile-input mb-1"
                                        value="<?= htmlspecialchars($row['email']); ?>"
                                        placeholder="Email"
                                        required>
                                    <input
                                        type="text"
                                        name="company_phone"
                                        class="form-control form-control-sm company-profile-input"
                                        value="<?= htmlspecialchars($row['phone']); ?>"
                                        placeholder="Phone"
                                        required>
                                </div>
                                <div class="mt-2">
                                    <button
                                        type="button"
                                        class="btn btn-info btn-sm email-edit-toggle"
                                        onclick="return toggleCompanyProfileEdit(this);">
                                        Edit Profile
                                    </button>
                                    <button
                                        type="submit"
                                        name="form_action"
                                        value="send_verify_link"
                                        class="btn btn-warning btn-sm ml-1">
                                        Send Verify Link
                                    </button>
                                    <button
                                        type="submit"
                                        name="form_action"
                                        value="force_verify_company"
                                        class="btn btn-success btn-sm ml-1"
                                        onclick="return confirm('Force verify this company without email confirmation?');">
                                        Force Verify
                                    </button>
                                </div>
                            </form>
                        </td>
                        <td>
                            <?php
                            $subscription_badge = [
                                'trial' => 'info',
                                'active' => 'success',
                                'expired' => 'warning',
                                'blocked' => 'danger',
                            ];
                            $subscription_key = strtolower($row['subscription_status'] ?? 'trial');
                            ?>
                            <span class="badge badge-<?= htmlspecialchars($subscription_badge[$subscription_key] ?? 'secondary'); ?>">
                                <?= htmlspecialchars(ucfirst($subscription_key)); ?>
                            </span>
                            <br>
                            <small class="text-muted">
                                Email:
                                <span class="badge badge-<?= (int)($row['email_verified'] ?? 0) === 1 ? 'success' : 'warning'; ?>">
                                    <?= (int)($row['email_verified'] ?? 0) === 1 ? 'Verified' : 'Not Verified'; ?>
                                </span>
                            </small>
                        </td>
                        <td>
                            <?php $is_unlimited_plan = strtolower($row['subscription_status'] ?? '') === 'active'; ?>
                            Managers: <?= (int)$row['manager_count']; ?> / <?= $is_unlimited_plan ? 'Unlimited' : (int)$row['max_managers']; ?><br>
                            Products: <?= (int)$row['product_count']; ?> / <?= $is_unlimited_plan ? 'Unlimited' : (int)$row['max_products']; ?><br>
                            Invoices: <?= (int)$row['invoice_count']; ?> / <?= $is_unlimited_plan ? 'Unlimited' : (int)$row['max_invoices_monthly']; ?><br>
                            SMS: <?= (int)$row['sms_quota_used']; ?> / <?= (int)$row['sms_quota_total']; ?>
                        </td>
                        <td>
                            <?= htmlspecialchars(strtolower($row['subscription_plan']) === 'trial' ? 'Trial' : $row['subscription_plan']); ?><br>
                            <small class="text-muted">
                                Expires: <?= htmlspecialchars(!empty($row['subscription_expires_at']) ? app_date($row['subscription_expires_at']) : '-'); ?>
                            </small><br>
                            <small class="text-muted">
                                <?= htmlspecialchars($row['currency_code'] ?? 'BDT'); ?>
                                | <?= htmlspecialchars($row['timezone_name'] ?? 'Asia/Dhaka'); ?>
                            </small>
                        </td>
                        <td>
                            <form method="post">
                                <input type="hidden" name="company_id" value="<?= (int)$row['id']; ?>">

                                <div class="form-group mb-2">
                                    <label>Pricing Plan (Help sidebar)</label>
                                    <div class="input-group input-group-sm">
                                        <select name="pricing_plan_visible" class="form-control">
                                            <option value="1" <?= (int)($row['show_pricing_plan'] ?? 1) === 1 ? 'selected' : ''; ?>>Show</option>
                                            <option value="0" <?= (int)($row['show_pricing_plan'] ?? 1) === 0 ? 'selected' : ''; ?>>Hide</option>
                                        </select>
                                        <div class="input-group-append">
                                            <button type="submit" name="form_action" value="update_pricing_plan_visibility" class="btn btn-info">Update</button>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group col-md-12">
                                        <label>Subscription</label>
                                        <?php $trial_expiry_value = date('Y-m-d', strtotime($row['created_at'] . ' +30 days')); ?>
                                        <select
                                            name="subscription_status"
                                            class="form-control form-control-sm"
                                            data-trial-expiry="<?= htmlspecialchars($trial_expiry_value); ?>"
                                            data-current-date="<?= htmlspecialchars(date('Y-m-d')); ?>">
                                            <?php foreach(['trial','active','expired','blocked'] as $status_option){ ?>
                                                <option
                                                    value="<?= $status_option; ?>"
                                                    <?= $row['subscription_status'] === $status_option ? 'selected' : ''; ?>>
                                                    <?= ucfirst($status_option); ?>
                                                </option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group col-md-6">
                                        <label>Plan</label>
                                        <input
                                            type="text"
                                            class="form-control form-control-sm subscription-plan-display"
                                            value="<?= htmlspecialchars($row['subscription_plan'] ?? ucfirst($row['subscription_status'] ?? 'trial')); ?>"
                                            readonly>
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label>Expires</label>
                                        <input
                                            type="date"
                                            name="subscription_expires_at"
                                            class="form-control form-control-sm subscription-expires-input"
                                            value="<?= htmlspecialchars($row['subscription_expires_at'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group col-md-6">
                                        <label>Table System</label>
                                        <select name="table_system_enabled" class="form-control form-control-sm">
                                            <option value="1" <?= (int)($row['table_system_enabled'] ?? 1) === 1 ? 'selected' : ''; ?>>Active</option>
                                            <option value="0" <?= (int)($row['table_system_enabled'] ?? 1) === 0 ? 'selected' : ''; ?>>Disabled</option>
                                        </select>
                                        <small class="text-muted">Disabled hides all table features for this company.</small>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group col-md-4">
                                        <label>Managers</label>
                                        <input
                                            type="text"
                                            name="max_managers"
                                            class="form-control form-control-sm subscription-limit-input"
                                            data-trial-value="<?= (int)$trial_manager_limit; ?>"
                                            data-current-value="<?= (int)$row['max_managers']; ?>"
                                            data-unlimited-value="<?= (int)$unlimited_limit_value; ?>"
                                            value="<?= strtolower($row['subscription_status'] ?? '') === 'active' ? 'Unlimited' : (int)$row['max_managers']; ?>">
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label>Products</label>
                                        <input
                                            type="text"
                                            name="max_products"
                                            class="form-control form-control-sm subscription-limit-input"
                                            data-trial-value="<?= (int)$trial_product_limit; ?>"
                                            data-current-value="<?= (int)$row['max_products']; ?>"
                                            data-unlimited-value="<?= (int)$unlimited_limit_value; ?>"
                                            value="<?= strtolower($row['subscription_status'] ?? '') === 'active' ? 'Unlimited' : (int)$row['max_products']; ?>">
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label>Invoices</label>
                                        <input
                                            type="text"
                                            name="max_invoices_monthly"
                                            class="form-control form-control-sm subscription-limit-input"
                                            data-trial-value="<?= (int)$trial_invoice_limit; ?>"
                                            data-current-value="<?= (int)$row['max_invoices_monthly']; ?>"
                                            data-unlimited-value="<?= (int)$unlimited_limit_value; ?>"
                                            value="<?= strtolower($row['subscription_status'] ?? '') === 'active' ? 'Unlimited' : (int)$row['max_invoices_monthly']; ?>">
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group col-md-4">
                                        <label>Currency</label>
                                        <input
                                            type="text"
                                            name="currency_code"
                                            class="form-control form-control-sm"
                                            maxlength="10"
                                            value="<?= htmlspecialchars($row['currency_code'] ?? 'BDT'); ?>">
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label>Timezone</label>
                                        <select name="timezone_name" class="form-control form-control-sm">
                                            <?php foreach($timezone_options as $timezone_value => $timezone_label){ ?>
                                                <option
                                                    value="<?= htmlspecialchars($timezone_value); ?>"
                                                    <?= ($row['timezone_name'] ?? 'Asia/Dhaka') === $timezone_value ? 'selected' : ''; ?>>
                                                    <?= htmlspecialchars($timezone_label); ?>
                                                </option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label>Date Format</label>
                                        <select name="date_format" class="form-control form-control-sm">
                                            <?php foreach($date_format_options as $format_value => $format_label){ ?>
                                                <option
                                                    value="<?= htmlspecialchars($format_value); ?>"
                                                    <?= ($row['date_format'] ?? $trial_date_format) === $format_value ? 'selected' : ''; ?>>
                                                    <?= htmlspecialchars($format_label); ?>
                                                </option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group col-md-12">
                                        <label>SMS Quota</label>
                                        <input
                                            type="number"
                                            name="sms_quota_total"
                                            class="form-control form-control-sm"
                                            min="<?= (int)$row['sms_quota_used']; ?>"
                                            value="<?= (int)$row['sms_quota_total']; ?>">
                                        <small class="text-muted">
                                            Used: <?= (int)$row['sms_quota_used']; ?>,
                                            Remaining: <?= max(0, (int)$row['sms_quota_total'] - (int)$row['sms_quota_used']); ?>
                                        </small>
                                    </div>
                                </div>

                                <button
                                    type="submit"
                                    name="form_action"
                                    value="update_company"
                                    class="btn btn-primary btn-sm">
                                    Update
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-danger btn-sm ml-2"
                                    onclick="showDeleteConfirm(this);">
                                    Delete
                                </button>

                                <div class="delete-confirm-box mt-2" style="display:none;">
                                    <div class="form-group mb-2">
                                        <label>Super Admin Password</label>
                                        <input
                                            type="password"
                                            name="super_admin_password"
                                            class="form-control form-control-sm"
                                            autocomplete="current-password"
                                            placeholder="Enter password to delete">
                                    </div>
                                    <button
                                        type="submit"
                                        name="form_action"
                                        value="delete_company"
                                        class="btn btn-danger btn-sm"
                                        onclick="return confirm('Delete this company and all related data permanently?');">
                                        Confirm Delete
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-secondary btn-sm ml-1"
                                        onclick="hideDeleteConfirm(this);">
                                        Cancel
                                    </button>
                                </div>
                            </form>

                            <div class="border-top mt-3 pt-3">
                                <strong class="d-block mb-2">
                                    <i class="fas fa-tools"></i> Company Tools
                                </strong>
                                <a
                                    href="<?= htmlspecialchars(app_path('tools/export.php?company_id=' . (int)$row['id'])); ?>"
                                    class="btn btn-outline-success btn-sm mb-1">
                                    <i class="fas fa-file-export"></i> Export
                                </a>
                                <a
                                    href="<?= htmlspecialchars(app_path('tools/import.php?company_id=' . (int)$row['id'])); ?>"
                                    class="btn btn-outline-primary btn-sm mb-1">
                                    <i class="fas fa-file-import"></i> Import
                                </a>
                                <a
                                    href="<?= htmlspecialchars(app_path('tools/delete_data.php?company_id=' . (int)$row['id'])); ?>"
                                    class="btn btn-outline-danger btn-sm mb-1">
                                    <i class="fas fa-trash"></i> Delete All Data
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="singleUserLicenseModal" tabindex="-1" role="dialog" aria-labelledby="singleUserLicenseModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form method="post" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="form_action" value="update_single_user_license">
            <div class="modal-header">
                <h5 class="modal-title" id="singleUserLicenseModalLabel">Single User Licence</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    Active korle selected company branding login/forgot page e use hobe, site favicon/title replace hobe, ebong public registration bondho thakbe.
                </div>

                <div class="form-group">
                    <label>Status</label>
                    <select name="license_status" class="form-control">
                        <option value="active" <?= $single_user_license_status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?= $single_user_license_status !== 'active' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Company</label>
                    <select name="license_company_id" class="form-control">
                        <option value="">Select Company</option>
                        <?php while($license_company = mysqli_fetch_assoc($license_companies)){ ?>
                            <option value="<?= (int)$license_company['id']; ?>" <?= $single_user_license_company_id === (int)$license_company['id'] ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($license_company['name'] . ' - ' . $license_company['email']); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Logo</label>
                    <?php if($single_user_license_logo_url !== ''){ ?>
                        <div class="mb-2">
                            <img src="<?= htmlspecialchars($single_user_license_logo_url); ?>" alt="Single user licence logo" style="max-height:72px;max-width:220px;">
                        </div>
                    <?php } ?>
                    <input type="file" name="license_logo" class="form-control-file" accept=".png,.jpg,.jpeg,.webp">
                    <small class="text-muted">Max 2MB. Ei logo login/forgot left side main logo ebong full site favicon hisebe use hobe.</small>
                </div>

                <div class="form-group">
                    <label>Site Title</label>
                    <input type="text" name="license_site_title" class="form-control" value="<?= htmlspecialchars($single_user_license_site_title); ?>" placeholder="Example: ExploreX ERP">
                </div>

                <div class="form-group mb-0">
                    <label>Company Slogan</label>
                    <textarea name="license_slogan" class="form-control" rows="3" placeholder="Company slogan"><?= htmlspecialchars($single_user_license_slogan); ?></textarea>
                    <small class="text-muted">Login/Forgot Password page er slogan replace korbe.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save Licence</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="createCompanyModal" tabindex="-1" role="dialog" aria-labelledby="createCompanyModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form method="post" class="modal-content">
            <input type="hidden" name="form_action" value="create_company">
            <div class="modal-header">
                <h5 class="modal-title" id="createCompanyModalLabel">Create Verified Company</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="company_name">Company or Account Name</label>
                    <input type="text" id="company_name" name="company_name" class="form-control" required autocomplete="organization">
                </div>
                <div class="form-group">
                    <label>Company Type</label>
                    <div class="custom-control custom-radio">
                        <input type="radio" id="create_company_type_housing" name="company_type" value="Housing" class="custom-control-input" required>
                        <label class="custom-control-label" for="create_company_type_housing">Housing</label>
                    </div>
                    <div class="custom-control custom-radio">
                        <input type="radio" id="create_company_type_others" name="company_type" value="Others" class="custom-control-input" required>
                        <label class="custom-control-label" for="create_company_type_others">Others</label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="company_email">Email</label>
                    <input type="email" id="company_email" name="company_email" class="form-control" required autocomplete="email">
                </div>
                <div class="form-group">
                    <label for="company_phone">Phone</label>
                    <input type="text" id="company_phone" name="company_phone" class="form-control" required autocomplete="tel">
                </div>
                <div class="form-group mb-0">
                    <label for="company_password">Password</label>
                    <input type="password" id="company_password" name="company_password" class="form-control" minlength="6" required autocomplete="new-password">
                    <small class="text-muted">The company will be created active and email-verified. No verification link will be sent.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-building"></i> Create Company</button>
            </div>
        </form>
    </div>
</div>

<?php
$page_script = '
<script>
$(function () {
    if($("#subscriptionRequestsTable").length){
        $("#subscriptionRequestsTable").DataTable({
            responsive: true,
            autoWidth: false,
            pageLength: 10,
            order: []
        });
    }
});

function showDeleteConfirm(button){
    var form = button.closest("form");
    var box = form ? form.querySelector(".delete-confirm-box") : null;

    if(box){
        box.style.display = "block";
        var input = box.querySelector("input[name=\"super_admin_password\"]");

        if(input){
            input.focus();
        }
    }
}

function toggleCompanyProfileEdit(button){
    if(!button){
        return false;
    }

    var form = button.closest(".contact-edit-form");
    var input = form ? form.querySelector(".company-profile-input") : null;
    var readonlyBox = form ? form.querySelector(".company-email-readonly") : null;
    var phoneReadonly = form ? form.querySelector(".company-phone-readonly") : null;
    var editorBox = form ? form.querySelector(".company-profile-editor") : null;
    var actionInput = form ? form.querySelector("input[name=\"form_action\"]") : null;

    if(!form || !input || !actionInput || !readonlyBox || !phoneReadonly || !editorBox){
        return false;
    }

    if(button.getAttribute("type") === "submit"){
        actionInput.value = "update_contact";
        return true;
    }

    readonlyBox.style.display = "none";
    phoneReadonly.style.display = "none";
    editorBox.style.display = "block";
    input.focus();
    input.select();
    actionInput.value = "update_contact";
    button.textContent = "Update Profile";
    button.type = "submit";
    return false;
}

function hideDeleteConfirm(button){
    var box = button.closest(".delete-confirm-box");

    if(box){
        box.style.display = "none";
        var input = box.querySelector("input[name=\"super_admin_password\"]");

        if(input){
            input.value = "";
        }
    }
}

document.addEventListener("change", function(event){
    if(event.target && event.target.name === "subscription_status"){
        var form = event.target.closest("form");
        var planInput = form ? form.querySelector(".subscription-plan-display") : null;
        var expiresInput = form ? form.querySelector(".subscription-expires-input") : null;
        var limitInputs = form ? form.querySelectorAll(".subscription-limit-input") : [];
        var value = event.target.value || "trial";

        if(planInput){
            planInput.value = value === "active"
                ? "Basic"
                : value.charAt(0).toUpperCase() + value.slice(1);
        }

        if(expiresInput){
            if(value === "trial"){
                expiresInput.disabled = false;
                expiresInput.value = event.target.getAttribute("data-trial-expiry") || "";
            }else if(value === "active"){
                expiresInput.value = "";
                expiresInput.disabled = true;
            }else if(value === "expired" || value === "blocked"){
                expiresInput.disabled = false;
                expiresInput.value = event.target.getAttribute("data-current-date") || "";
            }else{
                expiresInput.disabled = false;
            }
        }

        limitInputs.forEach(function(input){
            if(value === "active"){
                input.value = "Unlimited";
                input.readOnly = true;
            }else if(value === "trial"){
                input.value = input.getAttribute("data-trial-value") || "0";
                input.readOnly = true;
            }else{
                var currentValue = input.getAttribute("data-current-value") || "0";

                if(currentValue === input.getAttribute("data-unlimited-value")){
                    currentValue = "0";
                }

                input.value = currentValue;
                input.readOnly = false;
            }
        });
    }
});

document.addEventListener("DOMContentLoaded", function(){
    document.querySelectorAll("select[name=\"subscription_status\"]").forEach(function(select){
        select.dispatchEvent(new Event("change"));
    });
});
</script>
';

require_once '../includes/footer.php';
?>
