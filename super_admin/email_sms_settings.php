<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/system_settings_helper.php';
require_once '../includes/branding_helper.php';
require_once '../includes/smtp_mailer.php';
require_once '../includes/sms_helper.php';

require_super_admin_user();

$message = '';
$message_type = '';
$sms_account_info = null;

ensure_system_settings_table($conn);
ensure_branding_upload_dir();
$settings = system_settings_all($conn);

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $action = $_POST['action'] ?? 'save_settings';

    if($action === 'test_email'){
        $test_email = trim($_POST['test_email'] ?? '');

        if($test_email === ''){
            $message = 'Test email address is required.';
            $message_type = 'danger';
        }else{
            [$sent, $error] = smtp_send_mail(
                $test_email,
                'Test User',
                'You2 Biz SMTP Test',
                '<p>This is a test email from You2 Biz Email/SMS Settings.</p><p>If you received this, SMTP settings are working.</p>'
            );

            $message = $sent
                ? 'Test email sent successfully.'
                : 'Test email failed. ' . $error;
            $message_type = $sent ? 'success' : 'danger';
        }
    }elseif($action === 'test_sms'){
        $test_phone = trim($_POST['test_phone'] ?? '');
        $test_sms_message = trim($_POST['test_sms_message'] ?? 'You2 Biz SMS test successful.');
        $sms_token = sms_get_system_api_token($conn);

        if($test_phone === ''){
            $message = 'Test phone number is required.';
            $message_type = 'danger';
        }elseif($sms_token === ''){
            $message = 'System SMS API token is not configured.';
            $message_type = 'danger';
        }else{
            $send_result = sms_send_bulk_message($sms_token, [$test_phone], $test_sms_message);

            if($send_result['success']){
                $message = 'Test SMS sent: ' . (int)$send_result['sent'] . ', failed: ' . (int)$send_result['failed'] . '.';
                $message_type = ((int)$send_result['failed'] > 0) ? 'warning' : 'success';
            }else{
                $message = 'Test SMS failed. ' . $send_result['error'];
                $message_type = 'danger';
            }
        }
    }elseif($action === 'check_sms_account'){
        $sms_token = sms_get_system_api_token($conn);

        if($sms_token === ''){
            $message = 'System SMS API token is not configured.';
            $message_type = 'danger';
        }else{
            $sms_account_info = sms_get_account_info($conn, $sms_token);

            if($sms_account_info['success']){
                $message = 'SMS account information loaded successfully.';
                $message_type = 'success';
            }else{
                $message = 'SMS account information failed. ' . $sms_account_info['error'];
                $message_type = 'danger';
            }
        }
    }else{
    [$logo_ok, $site_logo_file, $logo_changed, $logo_error] = branding_upload_file(
        $conn,
        'site_logo_file',
        'site_logo_file',
        'site-logo'
    );
    [$favicon_ok, $site_favicon_file, $favicon_changed, $favicon_error] = branding_upload_file(
        $conn,
        'site_favicon_file',
        'site_favicon_file',
        'site-favicon',
        true
    );
    $smtp_password = trim($_POST['smtp_password'] ?? '');

    if(!$logo_ok){
        $message = $logo_error;
        $message_type = 'danger';
    }elseif(!$favicon_ok){
        $message = $favicon_error;
        $message_type = 'danger';
    }else{
    if($smtp_password === ''){
        $smtp_password = $settings['smtp_password'] ?? '';
    }

    $save_settings = [
        'smtp_host' => trim($_POST['smtp_host'] ?? ''),
        'smtp_port' => (string)max(1, (int)($_POST['smtp_port'] ?? 465)),
        'smtp_secure' => in_array($_POST['smtp_secure'] ?? 'ssl', ['ssl', 'tls', 'none'], true)
            ? $_POST['smtp_secure']
            : 'ssl',
        'smtp_username' => trim($_POST['smtp_username'] ?? ''),
        'smtp_password' => $smtp_password,
        'smtp_from_email' => trim($_POST['smtp_from_email'] ?? ''),
        'smtp_from_name' => trim($_POST['smtp_from_name'] ?? ''),
        'sms_api_url' => trim($_POST['sms_api_url'] ?? ''),
        'sms_account_api_url' => trim($_POST['sms_account_api_url'] ?? ''),
        'sms_api_method' => in_array($_POST['sms_api_method'] ?? 'POST', ['GET', 'POST'], true)
            ? $_POST['sms_api_method']
            : 'POST',
        'sms_api_token' => trim($_POST['sms_api_token'] ?? ''),
        'sms_token_param' => trim($_POST['sms_token_param'] ?? 'token'),
        'sms_to_param' => trim($_POST['sms_to_param'] ?? 'to'),
        'sms_message_param' => trim($_POST['sms_message_param'] ?? 'message'),
        'sms_sender_id' => trim($_POST['sms_sender_id'] ?? ''),
        'sms_sender_param' => trim($_POST['sms_sender_param'] ?? 'senderid'),
        'sms_success_status' => trim($_POST['sms_success_status'] ?? 'SENT'),
        'site_logo_file' => $site_logo_file,
        'site_favicon_file' => $site_favicon_file,
    ];

$previous_default_avatar = basename(trim((string)system_setting($conn, 'company_default_avatar_file', 'you2biz.png')));

    if(system_settings_save_many($conn, $save_settings)){
        $avatar_sync_message = '';
        $message_type = 'success';

        if($logo_changed){
            [$avatar_synced, $default_avatar_filename] = branding_sync_company_default_avatar($conn);

            if(
                $avatar_synced &&
                branding_apply_company_default_avatar_to_admins($conn, $default_avatar_filename, $previous_default_avatar) &&
                branding_apply_company_default_avatar_to_super_admin($default_avatar_filename, $previous_default_avatar)
            ){
                if(
        (defined('SUPER_ADMIN_PROFILE_AVATAR') ? basename((string)SUPER_ADMIN_PROFILE_AVATAR) : 'you2biz.png') === $default_avatar_filename
                ){
                    $_SESSION['avatar'] = $default_avatar_filename;
                    $_SESSION['login_avatar'] = $default_avatar_filename;
                }

                $avatar_sync_message = ' Super Admin profile photo updated immediately, and company default profile photo updated only for companies still using the previous default image.';
            }elseif(!$avatar_synced){
                $avatar_sync_message = ' Logo saved, but default company profile photo could not be synced.';
                $message_type = 'warning';
            }else{
                $avatar_sync_message = ' Logo saved, but company or Super Admin profile photos could not be updated.';
                $message_type = 'warning';
            }
        }

        $message = 'System settings updated.' . $avatar_sync_message;
        $settings = system_settings_all($conn);
    }else{
        $message = 'System settings could not be updated.';
        $message_type = 'danger';
    }
    }
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-cogs mr-2"></i>
            System Settings
        </h3>
    </div>

    <div class="card-body">
        <?php if($message){ ?>
            <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                <?= htmlspecialchars($message); ?>
            </div>
        <?php } ?>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save_settings">
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Login/Register Branding</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label>Login/Register Logo</label>
                                <input type="file" name="site_logo_file" id="site_logo_file" class="form-control-file" accept=".png,.jpg,.jpeg,.webp">
                                <small class="form-text text-muted">This logo will appear on login and registration pages. Recommended square image.</small>
                                <div class="mt-2">
                                    <img
                                        id="site_logo_preview"
                                        src="<?= htmlspecialchars(branding_logo_url($conn)); ?>"
                                        alt="Site logo preview"
                                        style="background:#f8fafc;border:1px solid #dbe4f0;border-radius:10px;max-height:64px;max-width:64px;object-fit:contain;padding:6px;">
                                </div>
                            </div>

                            <div class="form-group mb-0">
                                <label>Site Favicon</label>
                                <input type="file" name="site_favicon_file" id="site_favicon_file" class="form-control-file" accept=".png,.jpg,.jpeg,.webp,.ico">
                                <small class="form-text text-muted">This favicon will appear in browser tabs across the site.</small>
                                <div class="mt-2">
                                    <img
                                        id="site_favicon_preview"
                                        src="<?= htmlspecialchars(branding_favicon_url($conn)); ?>"
                                        alt="Favicon preview"
                                        style="background:#f8fafc;border:1px solid #dbe4f0;border-radius:10px;height:40px;object-fit:contain;padding:6px;width:40px;">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Email SMTP Settings</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label>SMTP Host</label>
                                <input type="text" name="smtp_host" class="form-control" value="<?= htmlspecialchars($settings['smtp_host'] ?? ''); ?>">
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label>SMTP Port</label>
                                    <input type="number" name="smtp_port" class="form-control" value="<?= htmlspecialchars($settings['smtp_port'] ?? '465'); ?>">
                                </div>
                                <div class="form-group col-md-6">
                                    <label>Security</label>
                                    <select name="smtp_secure" class="form-control">
                                        <?php foreach(['ssl' => 'SSL', 'tls' => 'TLS', 'none' => 'None'] as $value => $label){ ?>
                                            <option value="<?= htmlspecialchars($value); ?>" <?= ($settings['smtp_secure'] ?? 'ssl') === $value ? 'selected' : ''; ?>>
                                                <?= htmlspecialchars($label); ?>
                                            </option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>SMTP Username</label>
                                <input type="text" name="smtp_username" class="form-control" value="<?= htmlspecialchars($settings['smtp_username'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label>SMTP Password</label>
                                <input type="password" name="smtp_password" class="form-control" placeholder="Blank রাখলে আগের password থাকবে">
                            </div>

                            <div class="form-group">
                                <label>From Email</label>
                                <input type="email" name="smtp_from_email" class="form-control" value="<?= htmlspecialchars($settings['smtp_from_email'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label>From Name</label>
                                <input type="text" name="smtp_from_name" class="form-control" value="<?= htmlspecialchars($settings['smtp_from_name'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">SMS Settings</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label>SMS API URL</label>
                                <input
                                    type="url"
                                    name="sms_api_url"
                                    class="form-control"
                                    value="<?= htmlspecialchars($settings['sms_api_url'] ?? 'https://api.bdbulksms.net/api.php?json'); ?>">
                                <small class="form-text text-muted">Example: https://api.bdbulksms.net/api.php?json</small>
                            </div>

                            <div class="form-group">
                                <label>SMS Account API URL</label>
                                <input
                                    type="url"
                                    name="sms_account_api_url"
                                    class="form-control"
                                    value="<?= htmlspecialchars($settings['sms_account_api_url'] ?? 'https://api.bdbulksms.net/g_api.php'); ?>">
                                <small class="form-text text-muted">Balance, expiry, rate and usage will be checked from this URL.</small>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label>Request Method</label>
                                    <select name="sms_api_method" class="form-control">
                                        <?php foreach(['POST', 'GET'] as $method){ ?>
                                            <option value="<?= htmlspecialchars($method); ?>" <?= strtoupper($settings['sms_api_method'] ?? 'POST') === $method ? 'selected' : ''; ?>>
                                                <?= htmlspecialchars($method); ?>
                                            </option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div class="form-group col-md-6">
                                    <label>Success Status</label>
                                    <input
                                        type="text"
                                        name="sms_success_status"
                                        class="form-control"
                                        value="<?= htmlspecialchars($settings['sms_success_status'] ?? 'SENT'); ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>SMS API Token</label>
                                <input
                                    type="password"
                                    name="sms_api_token"
                                    class="form-control"
                                    value="<?= htmlspecialchars($settings['sms_api_token'] ?? ''); ?>">
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-4">
                                    <label>Token Param</label>
                                    <input type="text" name="sms_token_param" class="form-control" value="<?= htmlspecialchars($settings['sms_token_param'] ?? 'token'); ?>">
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Mobile Param</label>
                                    <input type="text" name="sms_to_param" class="form-control" value="<?= htmlspecialchars($settings['sms_to_param'] ?? 'to'); ?>">
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Message Param</label>
                                    <input type="text" name="sms_message_param" class="form-control" value="<?= htmlspecialchars($settings['sms_message_param'] ?? 'message'); ?>">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label>Sender ID</label>
                                    <input
                                        type="text"
                                        name="sms_sender_id"
                                        class="form-control"
                                        value="<?= htmlspecialchars($settings['sms_sender_id'] ?? ''); ?>"
                                        placeholder="Optional">
                                </div>
                                <div class="form-group col-md-6">
                                    <label>Sender Param</label>
                                    <input
                                        type="text"
                                        name="sms_sender_param"
                                        class="form-control"
                                        value="<?= htmlspecialchars($settings['sms_sender_param'] ?? 'senderid'); ?>">
                                </div>
                            </div>

                            <div class="alert alert-light border mb-0">
                                Signup SMS and Marketing SMS will use this system SMS gateway configuration.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i>
                Save Settings
            </button>
        </form>

        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card border-info">
                    <div class="card-header">
                        <h3 class="card-title">Quick Email Test</h3>
                    </div>
                    <form method="post">
                        <input type="hidden" name="action" value="test_email">
                        <div class="card-body">
                            <div class="form-group">
                                <label>Send Test Email To</label>
                                <input type="email" name="test_email" class="form-control" placeholder="name@example.com" required>
                            </div>
                            <button type="submit" class="btn btn-info">
                                <i class="fas fa-paper-plane"></i>
                                Send Test Email
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card border-info">
                    <div class="card-header">
                        <h3 class="card-title">Quick SMS Test</h3>
                    </div>
                    <form method="post">
                        <input type="hidden" name="action" value="test_sms">
                        <div class="card-body">
                            <div class="form-group">
                                <label>Send Test SMS To</label>
                                <input type="text" name="test_phone" class="form-control" placeholder="+8801XXXXXXXXX" required>
                            </div>
                            <div class="form-group">
                                <label>Test Message</label>
                                <textarea name="test_sms_message" class="form-control" rows="3" required>You2 Biz SMS test successful.</textarea>
                            </div>
                            <button type="submit" class="btn btn-info">
                                <i class="fas fa-sms"></i>
                                Send Test SMS
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="card border-success mt-4">
            <div class="card-header">
                <h3 class="card-title">SMS Balance & Validity</h3>
            </div>
            <div class="card-body">
                <form method="post" class="mb-3">
                    <input type="hidden" name="action" value="check_sms_account">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-sync-alt"></i>
                        Check SMS Balance & Validity
                    </button>
                </form>

                <?php if($sms_account_info && !empty($sms_account_info['data'])){ ?>
                    <?php
                    $sms_info_labels = [
                        'balance' => 'Balance',
                        'expiry' => 'Validity / Expiry',
                        'rate' => 'SMS Rate',
                        'tokensms' => 'Token Total SMS',
                        'totalsms' => 'Main Account Total SMS',
                        'monthlysms' => 'Main Account Current Month SMS',
                        'tokenmonthlysms' => 'Token Current Month SMS',
                    ];
                    $sms_summary_cards = [
                        'balance' => ['label' => 'SMS Balance', 'icon' => 'fas fa-coffee', 'class' => 'info'],
                        'expiry' => ['label' => 'Validity', 'icon' => 'fas fa-calendar-check', 'class' => 'success'],
                        'rate' => ['label' => 'SMS Rate', 'icon' => 'fas fa-tags', 'class' => 'warning'],
                    ];
                    $sms_usage_rows = [
                        'tokensms',
                        'totalsms',
                        'monthlysms',
                        'tokenmonthlysms',
                    ];
                    ?>
                    <div class="row">
                        <?php foreach($sms_summary_cards as $key => $card){ ?>
                            <?php if(array_key_exists($key, $sms_account_info['data'])){ ?>
                                <div class="col-md-4">
                                    <div class="small-box bg-<?= htmlspecialchars($card['class']); ?>">
                                        <div class="inner">
                                            <h3><?= htmlspecialchars((string)$sms_account_info['data'][$key]); ?></h3>
                                            <p><?= htmlspecialchars($card['label']); ?></p>
                                        </div>
                                        <div class="icon">
                                            <i class="<?= htmlspecialchars($card['icon']); ?>"></i>
                                        </div>
                                    </div>
                                </div>
                            <?php } ?>
                        <?php } ?>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-hover mb-3">
                            <thead>
                                <tr>
                                    <th>Usage Type</th>
                                    <th class="text-right">SMS Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($sms_usage_rows as $key){ ?>
                                    <?php if(array_key_exists($key, $sms_account_info['data'])){ ?>
                                        <tr>
                                            <td><?= htmlspecialchars($sms_info_labels[$key]); ?></td>
                                            <td class="text-right font-weight-bold"><?= htmlspecialchars((string)$sms_account_info['data'][$key]); ?></td>
                                        </tr>
                                    <?php } ?>
                                <?php } ?>

                                <?php foreach($sms_account_info['data'] as $key => $value){ ?>
                                    <?php if(!array_key_exists($key, $sms_info_labels) && strtolower((string)$key) !== 'token'){ ?>
                                        <tr>
                                            <td><?= htmlspecialchars(ucwords(str_replace(['_', '-'], ' ', (string)$key))); ?></td>
                                            <td class="text-right font-weight-bold"><?= htmlspecialchars(is_scalar($value) ? (string)$value : json_encode($value)); ?></td>
                                        </tr>
                                    <?php } ?>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>

                    <details>
                        <summary class="text-muted">Raw API Response</summary>
                        <textarea class="form-control mt-2" rows="4" readonly><?= htmlspecialchars($sms_account_info['raw']); ?></textarea>
                    </details>
                <?php } ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function bindImagePreview(inputId, previewId) {
        const input = document.getElementById(inputId);
        const preview = document.getElementById(previewId);

        if (!input || !preview) {
            return;
        }

        input.addEventListener('change', function () {
            const file = this.files && this.files[0] ? this.files[0] : null;

            if (!file) {
                return;
            }

            if (file.type && !file.type.startsWith('image/') && !file.name.toLowerCase().endsWith('.ico')) {
                return;
            }

            const reader = new FileReader();

            reader.onload = function (event) {
                if (event.target && event.target.result) {
                    preview.src = event.target.result;
                }
            };

            reader.readAsDataURL(file);
        });
    }

    bindImagePreview('site_logo_file', 'site_logo_preview');
    bindImagePreview('site_favicon_file', 'site_favicon_preview');
});
</script>

<?php require_once '../includes/footer.php'; ?>
