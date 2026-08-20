<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/signup_message_helper.php';

require_super_admin_user();

$message = '';
$message_type = '';

ensure_signup_message_settings_table($conn);
$settings = signup_message_settings($conn);
$sms_message_display = (string)($settings['sms_message'] ?? '');

if(preg_match('/(?:à¦|à§|\?{3,})/', $sms_message_display)){
    $sms_message_display = signup_message_default_settings()['sms_message'];
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $email_status = $_POST['email_status'] ?? 'active';
    $email_subject = $_POST['email_subject'] ?? '';
    $email_message = $_POST['email_message'] ?? '';
    $sms_status = $_POST['sms_status'] ?? 'active';
    $sms_message = $_POST['sms_message'] ?? '';
    $trial_warning_email_status = $_POST['trial_warning_email_status'] ?? 'active';
    $trial_warning_email_subject = $_POST['trial_warning_email_subject'] ?? '';
    $trial_warning_email_message = $_POST['trial_warning_email_message'] ?? '';
    $admin_alert_status = $_POST['admin_alert_status'] ?? 'active';
    $admin_alert_email = $_POST['admin_alert_email'] ?? '';
    $admin_alert_subject = $_POST['admin_alert_subject'] ?? '';
    $admin_alert_message = $_POST['admin_alert_message'] ?? '';
    $pricing_request_admin_email_status = $_POST['pricing_request_admin_email_status'] ?? 'active';
    $pricing_request_admin_email_subject = $_POST['pricing_request_admin_email_subject'] ?? '';
    $pricing_request_admin_email_message = $_POST['pricing_request_admin_email_message'] ?? '';
    $pricing_request_super_admin_email_status = $_POST['pricing_request_super_admin_email_status'] ?? 'active';
    $pricing_request_super_admin_email_subject = $_POST['pricing_request_super_admin_email_subject'] ?? '';
    $pricing_request_super_admin_email_message = $_POST['pricing_request_super_admin_email_message'] ?? '';
    $support_token_super_admin_email_status = $_POST['support_token_super_admin_email_status'] ?? 'active';
    $support_token_super_admin_email_subject = $_POST['support_token_super_admin_email_subject'] ?? '';
    $support_token_super_admin_email_message = $_POST['support_token_super_admin_email_message'] ?? '';

    if(signup_message_save_settings(
        $conn,
        $email_status,
        $email_subject,
        $email_message,
        $sms_status,
        $sms_message,
        $trial_warning_email_status,
        $trial_warning_email_subject,
        $trial_warning_email_message,
        $admin_alert_status,
        $admin_alert_email,
        $admin_alert_subject,
        $admin_alert_message,
        $pricing_request_admin_email_status,
        $pricing_request_admin_email_subject,
        $pricing_request_admin_email_message,
        $pricing_request_super_admin_email_status,
        $pricing_request_super_admin_email_subject,
        $pricing_request_super_admin_email_message,
        $support_token_super_admin_email_status,
        $support_token_super_admin_email_subject,
        $support_token_super_admin_email_message
    )){
        $message = 'Signup message settings updated.';
        $message_type = 'success';
        $settings = signup_message_settings($conn);
    }else{
        $message = 'Signup message settings could not be updated.';
        $message_type = 'danger';
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-envelope-open-text mr-2"></i>
            Signup Message
        </h3>
    </div>

    <div class="card-body">
        <?php if($message){ ?>
            <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                <?= htmlspecialchars($message); ?>
            </div>
        <?php } ?>

        <form method="post">
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Signup Email</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="email_status" class="form-control">
                                    <option value="active" <?= ($settings['email_status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?= ($settings['email_status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Email Subject</label>
                                <input
                                    type="text"
                                    name="email_subject"
                                    class="form-control"
                                    value="<?= htmlspecialchars($settings['email_subject'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label>Email Message</label>
                                <textarea
                                    name="email_message"
                                    class="form-control"
                                    rows="10"><?= htmlspecialchars($settings['email_message'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Signup SMS</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="sms_status" class="form-control">
                                    <option value="active" <?= ($settings['sms_status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?= ($settings['sms_status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>SMS Message</label>
                                <textarea
                                    name="sms_message"
                                    class="form-control"
                                    rows="12"><?= htmlspecialchars($sms_message_display); ?></textarea>
                            </div>

                            <div class="alert alert-light border">
                                Placeholders:
                                <code>{name}</code>,
                                <code>{email}</code>,
                                <code>{phone}</code>,
                                <code>{verification_link}</code>,
                                <code>{login_link}</code>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">New Signup Alert to Super Admin</h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="admin_alert_status" class="form-control">
                                    <option value="active" <?= ($settings['admin_alert_status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?= ($settings['admin_alert_status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-8">
                            <div class="form-group">
                                <label>Alert Email</label>
                                <input
                                    type="email"
                                    name="admin_alert_email"
                                    class="form-control"
                                    value="<?= htmlspecialchars($settings['admin_alert_email'] ?? ''); ?>"
                                    placeholder="superadmin@example.com">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Alert Subject</label>
                        <input
                            type="text"
                            name="admin_alert_subject"
                            class="form-control"
                            value="<?= htmlspecialchars($settings['admin_alert_subject'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label>Alert Message</label>
                        <textarea
                            name="admin_alert_message"
                            class="form-control"
                            rows="8"><?= htmlspecialchars($settings['admin_alert_message'] ?? ''); ?></textarea>
                    </div>

                    <div class="alert alert-light border mb-0">
                        This email will be sent to the configured alert email after a new company signup.
                        Placeholders:
                        <code>{name}</code>,
                        <code>{email}</code>,
                        <code>{phone}</code>,
                        <code>{status}</code>,
                        <code>{email_verification_status}</code>,
                        <code>{registration_time}</code>,
                        <code>{registration_ip}</code>,
                        <code>{registration_host}</code>,
                        <code>{registration_url}</code>,
                        <code>{login_link}</code>,
                        <code>{user_agent}</code>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Trial Warning Email</h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="trial_warning_email_status" class="form-control">
                                    <option value="active" <?= ($settings['trial_warning_email_status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?= ($settings['trial_warning_email_status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-8">
                            <div class="form-group">
                                <label>Email Subject</label>
                                <input
                                    type="text"
                                    name="trial_warning_email_subject"
                                    class="form-control"
                                    value="<?= htmlspecialchars($settings['trial_warning_email_subject'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Email Message</label>
                        <textarea
                            name="trial_warning_email_message"
                            class="form-control"
                            rows="8"><?= htmlspecialchars($settings['trial_warning_email_message'] ?? ''); ?></textarea>
                    </div>

                    <div class="alert alert-light border mb-0">
                        This email will be sent once when a trial company does not login for 7 consecutive days.
                        Placeholders:
                        <code>{name}</code>,
                        <code>{email}</code>,
                        <code>{phone}</code>,
                        <code>{expires_at}</code>,
                        <code>{days_left}</code>,
                        <code>{login_link}</code>,
                        <code>{customer_service}</code>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Plan activation request Email to Admin</h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="pricing_request_admin_email_status" class="form-control">
                                    <option value="active" <?= ($settings['pricing_request_admin_email_status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?= ($settings['pricing_request_admin_email_status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-8">
                            <div class="form-group">
                                <label>Email Subject</label>
                                <input
                                    type="text"
                                    name="pricing_request_admin_email_subject"
                                    class="form-control"
                                    value="<?= htmlspecialchars($settings['pricing_request_admin_email_subject'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Email Message</label>
                        <textarea
                            name="pricing_request_admin_email_message"
                            class="form-control"
                            rows="8"><?= htmlspecialchars($settings['pricing_request_admin_email_message'] ?? ''); ?></textarea>
                    </div>

                    <div class="alert alert-light border mb-0">
                        Placeholders:
                        <code>{admin_name}</code>,
                        <code>{admin_email}</code>,
                        <code>{admin_phone}</code>,
                        <code>{plan_name}</code>,
                        <code>{software_price}</code>,
                        <code>{monthly_service_charge}</code>,
                        <code>{hosting_title}</code>,
                        <code>{pricing_link}</code>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Pricing Request Email to Super Admin</h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="pricing_request_super_admin_email_status" class="form-control">
                                    <option value="active" <?= ($settings['pricing_request_super_admin_email_status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?= ($settings['pricing_request_super_admin_email_status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-8">
                            <div class="form-group">
                                <label>Email Subject</label>
                                <input
                                    type="text"
                                    name="pricing_request_super_admin_email_subject"
                                    class="form-control"
                                    value="<?= htmlspecialchars($settings['pricing_request_super_admin_email_subject'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Email Message</label>
                        <textarea
                            name="pricing_request_super_admin_email_message"
                            class="form-control"
                            rows="8"><?= htmlspecialchars($settings['pricing_request_super_admin_email_message'] ?? ''); ?></textarea>
                    </div>

                    <div class="alert alert-light border mb-0">
                        Placeholders:
                        <code>{admin_name}</code>,
                        <code>{admin_email}</code>,
                        <code>{admin_phone}</code>,
                        <code>{plan_name}</code>,
                        <code>{software_price}</code>,
                        <code>{monthly_service_charge}</code>,
                        <code>{hosting_title}</code>,
                        <code>{pricing_link}</code>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Support Token Email to Super Admin</h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="support_token_super_admin_email_status" class="form-control">
                                    <option value="active" <?= ($settings['support_token_super_admin_email_status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?= ($settings['support_token_super_admin_email_status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-8">
                            <div class="form-group">
                                <label>Email Subject</label>
                                <input
                                    type="text"
                                    name="support_token_super_admin_email_subject"
                                    class="form-control"
                                    value="<?= htmlspecialchars($settings['support_token_super_admin_email_subject'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Email Message</label>
                        <textarea
                            name="support_token_super_admin_email_message"
                            class="form-control"
                            rows="8"><?= htmlspecialchars($settings['support_token_super_admin_email_message'] ?? ''); ?></textarea>
                    </div>

                    <div class="alert alert-light border mb-0">
                        Placeholders:
                        <code>{ticket_token}</code>,
                        <code>{company_name}</code>,
                        <code>{admin_name}</code>,
                        <code>{admin_email}</code>,
                        <code>{subject}</code>,
                        <code>{message}</code>,
                        <code>{support_link}</code>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i>
                Save Settings
            </button>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
