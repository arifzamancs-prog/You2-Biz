<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/sms_helper.php';

require_super_admin_user();

$user_id = (int)$_SESSION['user_id'];
$message = '';
$message_type = '';
$sms_result_items = [];

ensure_sms_marketing_columns($conn);

function marketing_clean_phone($phone)
{
    return trim((string)$phone);
}

function marketing_valid_phone($phone)
{
    $phone = marketing_clean_phone($phone);

    return $phone !== '' && strtolower($phone) !== 'none';
}

function marketing_fetch_recipients($conn, $sql, $types, $params)
{
    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return [];
    }

    $bind_params = [$types];

    foreach($params as $key => $value){
        $bind_params[] = &$params[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $bind_params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];

    while($row = mysqli_fetch_assoc($result)){
        if(marketing_valid_phone($row['phone'] ?? '')){
            $rows[] = $row;
        }
    }

    return $rows;
}

$recipient_groups = [
    'customer' => marketing_fetch_recipients(
        $conn,
        "SELECT id, customer_name AS name, phone
         FROM customers
         WHERE user_id=?
         AND status='active'
         ORDER BY customer_name ASC",
        "i",
        [$user_id]
    ),
    'supplier' => marketing_fetch_recipients(
        $conn,
        "SELECT id, supplier_name AS name, phone
         FROM suppliers
         WHERE user_id=?
         AND status='active'
         ORDER BY supplier_name ASC",
        "i",
        [$user_id]
    ),
];

$recipient_type = $_POST['recipient_type'] ?? 'customer';
$send_mode = $_POST['send_mode'] ?? 'group';
$sms_body = trim($_POST['sms_body'] ?? '');
$selected_recipient_ids = array_map(
    'intval',
    $_POST['recipient_ids'] ?? []
);
$sms_api_token = sms_get_system_api_token($conn);
$sms_quota = sms_get_user_quota($conn, $user_id);
$recent_sms_history = [];

if(!isset($recipient_groups[$recipient_type])){
    $recipient_type = 'customer';
}

if(!in_array($send_mode, ['manual', 'group'], true)){
    $send_mode = 'group';
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $recipients = [];
    $sms_parts_per_recipient = sms_estimate_message_parts($sms_body);

    if($sms_body === ''){
        $message = 'SMS message is required.';
        $message_type = 'danger';
    }elseif($sms_api_token === ''){
        $message = 'System SMS API token is not configured.';
        $message_type = 'danger';
    }else{
        foreach($recipient_groups[$recipient_type] as $recipient){
            if($send_mode === 'group' || in_array((int)$recipient['id'], $selected_recipient_ids, true)){
                $recipients[] = $recipient;
            }
        }

        if(empty($recipients)){
            $message = 'No recipient selected.';
            $message_type = 'danger';
        }elseif((count($recipients) * $sms_parts_per_recipient) > (int)$sms_quota['remaining']){
            $message = 'SMS quota exceeded. This message needs ' . (count($recipients) * $sms_parts_per_recipient) . ' SMS from your quota. You can send ' . (int)$sms_quota['remaining'] . ' more SMS. ' . subscription_support_message();
            $message_type = 'danger';
        }else{
            $phones = array_column($recipients, 'phone');
            $send_result = sms_send_bulk_message($sms_api_token, $phones, $sms_body);
            $sms_result_items = $send_result['items'];
            $sent_count = (int)$send_result['sent'];
            $failed_count = (int)$send_result['failed'];
            $actual_sms_count = $sent_count * $sms_parts_per_recipient;

            if($actual_sms_count > 0){
                sms_consume_user_quota($conn, $user_id, $actual_sms_count);
                $sms_quota = sms_get_user_quota($conn, $user_id);
            }

            sms_record_history(
                $conn,
                $user_id,
                $recipient_type,
                $send_mode,
                count($recipients),
                $actual_sms_count,
                $failed_count,
                $sms_body,
                ($send_result['raw'] ?: $send_result['error']) . "\nEstimated SMS Count: " . $actual_sms_count
            );

            if($send_result['success']){
                $message = 'Recipients sent: ' . $sent_count . ', failed: ' . $failed_count . '. SMS quota used: ' . $actual_sms_count . '.';
                $message_type = $failed_count > 0 ? 'warning' : 'success';
            }else{
                $message = 'SMS sending failed. ' . $send_result['error'];
                $message_type = 'danger';
            }
        }
    }
}

$history_stmt = mysqli_prepare(
    $conn,
    "SELECT recipient_type, send_mode, recipient_count, sent_count, failed_count, created_at
     FROM sms_history
     WHERE user_id=?
     ORDER BY id DESC
     LIMIT 5"
);

if($history_stmt){
    mysqli_stmt_bind_param($history_stmt, "i", $user_id);
    mysqli_stmt_execute($history_stmt);
    $history_result = mysqli_stmt_get_result($history_stmt);

    while($row = mysqli_fetch_assoc($history_result)){
        $recent_sms_history[] = $row;
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-sms mr-2"></i>
            Marketing
        </h3>
    </div>

    <div class="card-body">
        <?php if($message){ ?>
            <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                <?= htmlspecialchars($message); ?>
            </div>
        <?php } ?>

        <div class="alert alert-light border">
            <div class="d-flex justify-content-between align-items-center">
                <strong class="text-dark">
                    <i class="fas fa-circle mr-1 text-<?= $sms_api_token !== '' ? 'success' : 'warning'; ?>"></i>
                    SMS System <?= $sms_api_token !== '' ? 'Live' : 'Setup Needed'; ?>
                </strong>
                <span class="badge <?= $sms_api_token !== '' ? 'badge-success' : 'badge-warning'; ?>">
                    <?= $sms_api_token !== '' ? 'Ready to Send' : 'Token Missing'; ?>
                </span>
            </div>
        </div>

        <?php if(!empty($sms_result_items)){ ?>
            <div class="alert alert-light border">
                <strong>SMS Result</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach($sms_result_items as $item){ ?>
                        <li>
                            <?= htmlspecialchars($item['to'] ?? ''); ?> -
                            <?= htmlspecialchars($item['status'] ?? ''); ?>:
                            <?= htmlspecialchars($item['statusmsg'] ?? ''); ?>
                        </li>
                    <?php } ?>
                </ul>
            </div>
        <?php } ?>

        <form method="post">
            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label>Recipient Type</label>
                        <select name="recipient_type" id="recipient_type" class="form-control">
                            <option value="customer" <?= $recipient_type === 'customer' ? 'selected' : ''; ?>>Customer</option>
                            <option value="supplier" <?= $recipient_type === 'supplier' ? 'selected' : ''; ?>>Supplier</option>
                        </select>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="form-group">
                        <label>Send Mode</label>
                        <select name="send_mode" id="send_mode" class="form-control">
                            <option value="manual" <?= $send_mode === 'manual' ? 'selected' : ''; ?>>Manual Select</option>
                            <option value="group" <?= $send_mode === 'group' ? 'selected' : ''; ?>>Group SMS</option>
                        </select>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="form-group">
                        <label>Message Count</label>
                        <div class="border rounded bg-light p-2">
                            <div>
                                Selected:
                                <strong id="message_count">0</strong>
                                |
                                Will Use:
                                <strong id="sms_quota_needed">0</strong>
                            </div>
                            <div>
                                Reserved:
                                <strong><?= (int)$sms_quota['total']; ?></strong>
                                |
                                Done:
                                <strong><?= (int)$sms_quota['used']; ?></strong>
                                |
                                Can Send:
                                <strong id="sms_quota_remaining"><?= (int)$sms_quota['remaining']; ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-group" id="manual_recipient_box">
                <label>Recipients</label>
                <input
                    type="text"
                    id="recipient_search"
                    class="form-control mb-2"
                    placeholder="Search recipients">
                <div class="border rounded p-2" style="max-height:260px;overflow:auto;">
                    <?php foreach($recipient_groups as $group_key => $recipients){ ?>
                        <?php foreach($recipients as $recipient){ ?>
                            <div
                                class="custom-control custom-checkbox marketing-recipient-row"
                                data-type="<?= htmlspecialchars($group_key); ?>"
                                data-search="<?= htmlspecialchars(strtolower($recipient['name'] . ' ' . $recipient['phone'])); ?>">
                                <input
                                    type="checkbox"
                                    class="custom-control-input marketing-recipient"
                                    id="recipient_<?= htmlspecialchars($group_key); ?>_<?= (int)$recipient['id']; ?>"
                                    name="recipient_ids[]"
                                    value="<?= (int)$recipient['id']; ?>"
                                    data-type="<?= htmlspecialchars($group_key); ?>"
                                    <?= $recipient_type === $group_key && in_array((int)$recipient['id'], $selected_recipient_ids, true) ? 'checked' : ''; ?>>
                                <label
                                    class="custom-control-label"
                                    for="recipient_<?= htmlspecialchars($group_key); ?>_<?= (int)$recipient['id']; ?>">
                                    <?= htmlspecialchars($recipient['name']); ?>
                                    (<?= htmlspecialchars($recipient['phone']); ?>)
                                </label>
                            </div>
                        <?php } ?>
                    <?php } ?>
                </div>
            </div>

            <div class="form-group">
                <label>SMS Message</label>
                <textarea
                    id="sms_body"
                    name="sms_body"
                    class="form-control"
                    rows="5"
                    required><?= htmlspecialchars($sms_body); ?></textarea>
                <small class="form-text text-muted">
                    Characters:
                    <strong id="sms_letter_count">0</strong>
                </small>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i>
                Send SMS
            </button>
            <small id="sms_quota_warning" class="text-danger ml-2" style="display:none;">
                This send exceeds available SMS quota.
            </small>
        </form>

        <?php if(!empty($recent_sms_history)){ ?>
            <hr>
            <h5>Recent SMS History</h5>
            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Recipient Type</th>
                            <th>Mode</th>
                            <th>Recipients</th>
                            <th>Sent</th>
                            <th>Failed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($recent_sms_history as $history){ ?>
                            <tr>
                                <td><?= htmlspecialchars(date('d-m-Y h:i A', strtotime($history['created_at']))); ?></td>
                                <td><?= htmlspecialchars(ucfirst($history['recipient_type'])); ?></td>
                                <td><?= htmlspecialchars($history['send_mode'] === 'group' ? 'Group SMS' : 'Manual Select'); ?></td>
                                <td><?= (int)$history['recipient_count']; ?></td>
                                <td><?= (int)$history['sent_count']; ?></td>
                                <td><?= (int)$history['failed_count']; ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
    </div>
</div>

<?php
$page_script = '
<script>
$(function(){
    function refreshSmsLetterCount(){
                const message = $("#sms_body").val() || "";
                const length = Array.from(message).length;

                $("#sms_letter_count").text(length);
                refreshRecipients();
            }

    function refreshRecipients(){
        const type = $("#recipient_type").val();
        const mode = $("#send_mode").val();
        const search = $("#recipient_search").val().toLowerCase();
        let count = 0;

        $(".marketing-recipient-row").hide();
        $(".marketing-recipient").prop("disabled", true);

        $(".marketing-recipient[data-type=\"" + type + "\"]").prop("disabled", false);

        $(".marketing-recipient-row[data-type=\"" + type + "\"]").each(function(){
            const text = String($(this).data("search") || "");

            if(search === "" || text.indexOf(search) !== -1){
                $(this).show();
            }
        });

        if(mode === "group"){
            $("#manual_recipient_box").hide();
            count = $(".marketing-recipient[data-type=\"" + type + "\"]").length;
        }else{
            $("#manual_recipient_box").show();
            count = $(".marketing-recipient[data-type=\"" + type + "\"]:checked").length;
        }

        const remaining = parseInt($("#sms_quota_remaining").text(), 10) || 0;
        const messageLength = Array.from($("#sms_body").val() || "").length;
        const partsPerRecipient = messageLength === 0
            ? 0
            : (messageLength <= 160 ? 1 : Math.ceil(messageLength / 153));
        const quotaNeeded = count * partsPerRecipient;

        $("#message_count").text(count);
        $("#sms_quota_needed").text(quotaNeeded);
        $("#sms_quota_warning").toggle(quotaNeeded > remaining);
        $("button[type=\"submit\"]").prop("disabled", quotaNeeded > remaining);
    }

    $("#recipient_type").on("change", function(){
        $(".marketing-recipient").prop("checked", false);
        refreshRecipients();
    });

    $("#send_mode").on("change", refreshRecipients);
    $("#recipient_search").on("keyup", refreshRecipients);
    $(".marketing-recipient").on("change", refreshRecipients);
    $("#sms_body").on("input keyup change", refreshSmsLetterCount);

    refreshRecipients();
    refreshSmsLetterCount();
});
</script>
';

require_once '../includes/footer.php';
?>
