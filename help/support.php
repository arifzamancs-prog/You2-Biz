<?php

require_once '../includes/auth.php';
require_admin_user();
require_once '../includes/db.php';
require_once '../includes/app_config.php';
require_once '../includes/smtp_mailer.php';
require_once '../includes/signup_message_helper.php';
require_once '../includes/super_admin_config.php';

function support_users_has_column($conn, $column)
{
    $column = trim((string)$column);

    if ($column === '' || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return false;
    }

    $escaped = mysqli_real_escape_string($conn, $column);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE '{$escaped}'");

    return $result && mysqli_num_rows($result) > 0;
}

function support_admin_company_field($conn)
{
    foreach (['company_name', 'business_name', 'shop_name', 'name'] as $column) {
        if (support_users_has_column($conn, $column)) {
            return $column;
        }
    }

    return 'name';
}

function ensure_support_ticket_tables($conn)
{
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS support_tickets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_token VARCHAR(50) NOT NULL UNIQUE,
        admin_user_id INT NOT NULL,
        company_name VARCHAR(255) NOT NULL DEFAULT '',
        admin_name VARCHAR(255) NOT NULL DEFAULT '',
        admin_email VARCHAR(255) NOT NULL DEFAULT '',
        subject VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        status ENUM('open','answered','solved') NOT NULL DEFAULT 'open',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_support_admin_user (admin_user_id),
        INDEX idx_support_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS support_ticket_replies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT NOT NULL,
        reply_from_role ENUM('admin','super_admin') NOT NULL,
        reply_from_name VARCHAR(255) NOT NULL DEFAULT '',
        reply_message TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_support_reply_ticket (ticket_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function generate_support_ticket_token($conn)
{
    do {
        $token = 'SUP-' . date('ymdHis') . '-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 4));
        $escaped_token = mysqli_real_escape_string($conn, $token);
        $exists = mysqli_query($conn, "SELECT id FROM support_tickets WHERE ticket_token='{$escaped_token}' LIMIT 1");
    } while ($exists && mysqli_num_rows($exists) > 0);

    return $token;
}

function support_mail_template($title, $message_lines, $button_label = '', $button_link = '')
{
    $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1f2937;">';
    $html .= '<h2 style="margin:0 0 16px 0;color:#111827;">' . htmlspecialchars($title) . '</h2>';

    foreach ($message_lines as $line) {
        $html .= '<p style="margin:0 0 10px 0;line-height:1.6;">' . nl2br(htmlspecialchars($line)) . '</p>';
    }

    if ($button_label !== '' && $button_link !== '') {
        $html .= '<p style="margin:20px 0 0 0;">';
        $html .= '<a href="' . htmlspecialchars($button_link) . '" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:6px;">' . htmlspecialchars($button_label) . '</a>';
        $html .= '</p>';
    }

    $html .= '</div>';

    return $html;
}

function send_support_status_mail($to_email, $to_name, $subject, $lines)
{
    if (trim((string)$to_email) === '') {
        return [false, 'Email not found'];
    }

    return smtp_send_mail(
        $to_email,
        $to_name,
        $subject,
        support_mail_template(
            $subject,
            $lines,
            'Open Support',
            app_url('help/support.php')
        )
    );
}

ensure_support_ticket_tables($conn);
ensure_signup_message_settings_table($conn);

$is_super_admin = is_super_admin_user();
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$message = $_SESSION['support_flash_message'] ?? '';
$message_type = $_SESSION['support_flash_message_type'] ?? 'success';
unset($_SESSION['support_flash_message'], $_SESSION['support_flash_message_type']);
$edit_ticket_id = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;
$signup_message_settings = signup_message_settings($conn);

function support_redirect_with_message($message, $message_type, $ticket_id = 0)
{
    $_SESSION['support_flash_message'] = $message;
    $_SESSION['support_flash_message_type'] = $message_type;

    $redirect_url = app_path('help/support.php');

    if ((int)$ticket_id > 0) {
        $redirect_url .= '?ticket_id=' . (int)$ticket_id;
    }

    header('Location: ' . $redirect_url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_ticket' && !$is_super_admin) {
        $subject = trim($_POST['subject'] ?? '');
        $ticket_message = trim($_POST['message'] ?? '');

        if ($subject === '' || $ticket_message === '') {
            support_redirect_with_message('Subject and message are required.', 'danger');
        } else {
            $company_field = support_admin_company_field($conn);
            $admin_result = mysqli_query(
                $conn,
                "SELECT id, name, email, {$company_field} AS support_company_name
                 FROM users
                 WHERE id={$current_user_id}
                 LIMIT 1"
            );
            $admin_data = $admin_result ? mysqli_fetch_assoc($admin_result) : null;

            if (!$admin_data) {
                support_redirect_with_message('Admin account not found.', 'danger');
            } else {
                $ticket_token = generate_support_ticket_token($conn);
                $subject_sql = mysqli_real_escape_string($conn, $subject);
                $message_sql = mysqli_real_escape_string($conn, $ticket_message);
                $ticket_token_sql = mysqli_real_escape_string($conn, $ticket_token);
                $company_name_sql = mysqli_real_escape_string($conn, (string)($admin_data['support_company_name'] ?? $admin_data['name'] ?? ''));
                $admin_name_sql = mysqli_real_escape_string($conn, (string)($admin_data['name'] ?? ''));
                $admin_email_sql = mysqli_real_escape_string($conn, (string)($admin_data['email'] ?? ''));

                $insert_ticket = mysqli_query($conn, "INSERT INTO support_tickets (
                    ticket_token, admin_user_id, company_name, admin_name, admin_email, subject, message, status
                ) VALUES (
                    '{$ticket_token_sql}',
                    {$current_user_id},
                    '{$company_name_sql}',
                    '{$admin_name_sql}',
                    '{$admin_email_sql}',
                    '{$subject_sql}',
                    '{$message_sql}',
                    'open'
                )");

                if ($insert_ticket) {
                    $ticket_id = (int)mysqli_insert_id($conn);
                    mysqli_query($conn, "INSERT INTO support_ticket_replies (
                        ticket_id, reply_from_role, reply_from_name, reply_message
                    ) VALUES (
                        {$ticket_id},
                        'admin',
                        '{$admin_name_sql}',
                        '{$message_sql}'
                    )");

                    send_support_status_mail(
                        (string)$admin_data['email'],
                        (string)$admin_data['name'],
                        'Support token created: ' . $ticket_token,
                        [
                            'Your support token has been created successfully.',
                            'Token: ' . $ticket_token,
                            'Subject: ' . $subject,
                            'Our team will reply here as soon as possible.'
                        ]
                    );

                    if(($signup_message_settings['support_token_super_admin_email_status'] ?? 'active') === 'active'){
                        $super_admin_subject = signup_message_apply_placeholders(
                            $signup_message_settings['support_token_super_admin_email_subject'] ?? 'New support token: {ticket_token}',
                            [
                                'ticket_token' => $ticket_token,
                                'company_name' => (string)($admin_data['support_company_name'] ?? $admin_data['name'] ?? ''),
                                'admin_name' => (string)($admin_data['name'] ?? ''),
                                'admin_email' => (string)($admin_data['email'] ?? ''),
                                'subject' => $subject,
                                'message' => $ticket_message,
                                'support_link' => app_url('help/support.php?ticket_id=' . $ticket_id),
                            ]
                        );

                        $super_admin_body = signup_message_apply_placeholders(
                            $signup_message_settings['support_token_super_admin_email_message'] ?? '',
                            [
                                'ticket_token' => $ticket_token,
                                'company_name' => (string)($admin_data['support_company_name'] ?? $admin_data['name'] ?? ''),
                                'admin_name' => (string)($admin_data['name'] ?? ''),
                                'admin_email' => (string)($admin_data['email'] ?? ''),
                                'subject' => $subject,
                                'message' => $ticket_message,
                                'support_link' => app_url('help/support.php?ticket_id=' . $ticket_id),
                            ]
                        );

                        smtp_send_mail(
                            super_admin_notify_email(),
                            defined('SUPER_ADMIN_NAME') ? SUPER_ADMIN_NAME : 'Super Admin',
                            $super_admin_subject,
                            signup_message_email_html($super_admin_body)
                        );
                    }

                    support_redirect_with_message('Support token created successfully.', 'success', $ticket_id);
                } else {
                    support_redirect_with_message('Failed to create support token.', 'danger');
                }
            }
        }
    }

    if ($action === 'reply_ticket' && $is_super_admin) {
        $ticket_id = (int)($_POST['ticket_id'] ?? 0);
        $reply_message = trim($_POST['reply_message'] ?? '');
        $new_status = $_POST['status'] ?? 'answered';

        if (!in_array($new_status, ['answered', 'solved'], true)) {
            $new_status = 'answered';
        }

        if ($ticket_id <= 0 || $reply_message === '') {
            support_redirect_with_message('Reply message is required.', 'danger', $ticket_id);
        } else {
            $ticket_result = mysqli_query($conn, "SELECT * FROM support_tickets WHERE id={$ticket_id} LIMIT 1");
            $ticket_data = $ticket_result ? mysqli_fetch_assoc($ticket_result) : null;

            if (!$ticket_data) {
                support_redirect_with_message('Support token not found.', 'danger');
            } else {
                $super_admin_name = trim((string)($_SESSION['super_admin_name'] ?? $_SESSION['name'] ?? 'Super Admin'));
                if ($super_admin_name === '') {
                    $super_admin_name = 'Super Admin';
                }

                $reply_message_sql = mysqli_real_escape_string($conn, $reply_message);
                $super_admin_name_sql = mysqli_real_escape_string($conn, $super_admin_name);
                $new_status_sql = mysqli_real_escape_string($conn, $new_status);

                $reply_insert = mysqli_query($conn, "INSERT INTO support_ticket_replies (
                    ticket_id, reply_from_role, reply_from_name, reply_message
                ) VALUES (
                    {$ticket_id},
                    'super_admin',
                    '{$super_admin_name_sql}',
                    '{$reply_message_sql}'
                )");

                if ($reply_insert) {
                    mysqli_query($conn, "UPDATE support_tickets
                                         SET status='{$new_status_sql}'
                                         WHERE id={$ticket_id}
                                         LIMIT 1");

                    $subject_prefix = $new_status === 'solved' ? 'Support token solved: ' : 'Support token reply: ';
                    send_support_status_mail(
                        (string)$ticket_data['admin_email'],
                        (string)$ticket_data['admin_name'],
                        $subject_prefix . $ticket_data['ticket_token'],
                        [
                            'Your support token has been updated.',
                            'Token: ' . $ticket_data['ticket_token'],
                            'Status: ' . ucfirst($new_status),
                            'Reply: ' . $reply_message
                        ]
                    );

                    support_redirect_with_message(
                        $new_status === 'solved'
                            ? 'Support token solved successfully.'
                            : 'Reply sent successfully.',
                        'success',
                        $ticket_id
                    );
                } else {
                    support_redirect_with_message('Failed to save reply.', 'danger', $ticket_id);
                }
            }
        }
    }
}

$ticket_where = $is_super_admin
    ? '1'
    : 'support_tickets.admin_user_id=' . $current_user_id;

$tickets = [];
$ticket_query = mysqli_query($conn, "SELECT support_tickets.*
                                     FROM support_tickets
                                     WHERE {$ticket_where}
                                     ORDER BY support_tickets.updated_at DESC, support_tickets.id DESC");

if ($ticket_query) {
    while ($row = mysqli_fetch_assoc($ticket_query)) {
        $tickets[] = $row;
    }
}

$selected_ticket = null;
if ($edit_ticket_id > 0) {
    foreach ($tickets as $ticket_row) {
        if ((int)$ticket_row['id'] === $edit_ticket_id) {
            $selected_ticket = $ticket_row;
            break;
        }
    }
}

if (!$selected_ticket && !empty($tickets)) {
    $selected_ticket = $tickets[0];
}

$replies = [];
if ($selected_ticket) {
    $selected_ticket_id = (int)$selected_ticket['id'];
    $reply_query = mysqli_query($conn, "SELECT *
                                        FROM support_ticket_replies
                                        WHERE ticket_id={$selected_ticket_id}
                                        ORDER BY id ASC");

    if ($reply_query) {
        while ($reply_row = mysqli_fetch_assoc($reply_query)) {
            $replies[] = $reply_row;
        }
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="row">
    <div class="col-md-5">
        <?php if(!$is_super_admin){ ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title">Create Support Token</h3>
                </div>
                <div class="card-body">
                    <?php if($message !== ''){ ?>
                        <div class="alert alert-<?= $message_type === 'success' ? 'success' : 'danger'; ?>">
                            <?= htmlspecialchars($message); ?>
                        </div>
                    <?php } ?>

                    <form method="post">
                        <input type="hidden" name="action" value="create_ticket">

                        <div class="form-group">
                            <label>Subject</label>
                            <input type="text" name="subject" class="form-control" maxlength="255" required>
                        </div>

                        <div class="form-group">
                            <label>Message</label>
                            <textarea name="message" class="form-control" rows="5" required></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-life-ring mr-1"></i> Generate Token
                        </button>
                    </form>
                </div>
            </div>
        <?php } else { ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title">Support Tokens</h3>
                </div>
                <div class="card-body">
                    <?php if($message !== ''){ ?>
                        <div class="alert alert-<?= $message_type === 'success' ? 'success' : 'danger'; ?>">
                            <?= htmlspecialchars($message); ?>
                        </div>
                    <?php } ?>

                    <div class="small text-muted">
                        Total Tokens: <?= count($tickets); ?>
                    </div>
                </div>
            </div>
        <?php } ?>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><?= $is_super_admin ? 'All Support Tokens' : 'My Support Tokens'; ?></h3>
            </div>
            <div class="card-body p-0">
                <?php if(empty($tickets)){ ?>
                    <div class="p-3 text-muted">No support token found.</div>
                <?php } else { ?>
                    <div class="list-group list-group-flush">
                        <?php foreach($tickets as $ticket){ ?>
                            <?php
                                $active_class = $selected_ticket && (int)$selected_ticket['id'] === (int)$ticket['id']
                                    ? 'active'
                                    : '';
                                $status_badge = $ticket['status'] === 'solved'
                                    ? 'success'
                                    : ($ticket['status'] === 'answered' ? 'info' : 'warning');
                            ?>
                            <a href="?ticket_id=<?= (int)$ticket['id']; ?>"
                               class="list-group-item list-group-item-action <?= $active_class; ?>">
                                <div class="d-flex w-100 justify-content-between">
                                    <strong><?= htmlspecialchars($ticket['ticket_token']); ?></strong>
                                    <span class="badge badge-<?= $status_badge; ?>">
                                        <?= htmlspecialchars(ucfirst($ticket['status'])); ?>
                                    </span>
                                </div>
                                <div><?= htmlspecialchars($ticket['subject']); ?></div>
                                <?php if($is_super_admin){ ?>
                                    <small><?= htmlspecialchars($ticket['company_name']); ?> | <?= htmlspecialchars($ticket['admin_email']); ?></small>
                                <?php } ?>
                                <div><small><?= htmlspecialchars(date('d-m-Y h:i A', strtotime($ticket['updated_at']))); ?></small></div>
                            </a>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Support Conversation</h3>
            </div>
            <div class="card-body">
                <?php if(!$selected_ticket){ ?>
                    <div class="text-muted">Select a token to view conversation.</div>
                <?php } else { ?>
                    <div class="border rounded p-3 mb-3">
                        <div class="row">
                            <div class="col-md-6">
                                <div><strong>Token:</strong> <?= htmlspecialchars($selected_ticket['ticket_token']); ?></div>
                                <div><strong>Subject:</strong> <?= htmlspecialchars($selected_ticket['subject']); ?></div>
                                <div><strong>Status:</strong> <?= htmlspecialchars(ucfirst($selected_ticket['status'])); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div><strong>Company:</strong> <?= htmlspecialchars($selected_ticket['company_name']); ?></div>
                                <div><strong>Admin:</strong> <?= htmlspecialchars($selected_ticket['admin_name']); ?></div>
                                <div><strong>Email:</strong> <?= htmlspecialchars($selected_ticket['admin_email']); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="timeline">
                        <?php foreach($replies as $reply){ ?>
                            <?php $is_admin_reply = $reply['reply_from_role'] === 'admin'; ?>
                            <?php
                                $reply_display_name = $is_admin_reply
                                    ? trim((string)$reply['reply_from_name']) . ' (Admin)'
                                    : 'Support Team';
                            ?>
                            <div class="mb-3 p-3 border rounded <?= $is_admin_reply ? 'bg-light' : 'border-primary'; ?>">
                                <div class="d-flex justify-content-between flex-wrap">
                                    <strong>
                                        <?= htmlspecialchars($reply_display_name); ?>
                                    </strong>
                                    <small><?= htmlspecialchars(date('d-m-Y h:i A', strtotime($reply['created_at']))); ?></small>
                                </div>
                                <div class="mt-2" style="white-space: pre-wrap;"><?= htmlspecialchars($reply['reply_message']); ?></div>
                            </div>
                        <?php } ?>
                    </div>

                    <?php if($is_super_admin){ ?>
                        <hr>
                        <form method="post">
                            <input type="hidden" name="action" value="reply_ticket">
                            <input type="hidden" name="ticket_id" value="<?= (int)$selected_ticket['id']; ?>">

                            <div class="form-group">
                                <label>Reply</label>
                                <textarea name="reply_message" class="form-control" rows="5" required></textarea>
                            </div>

                            <div class="form-group">
                                <label>Update Status</label>
                                <select name="status" class="form-control">
                                    <option value="answered" <?= $selected_ticket['status'] === 'answered' ? 'selected' : ''; ?>>Answered</option>
                                    <option value="solved" <?= $selected_ticket['status'] === 'solved' ? 'selected' : ''; ?>>Solved</option>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane mr-1"></i> Send Reply
                            </button>
                        </form>
                    <?php } ?>
                <?php } ?>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
