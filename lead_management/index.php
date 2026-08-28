<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/lead_management_helper.php';

ensure_lead_management_table($conn);
require_lead_management_access();

$user_id = (int)$_SESSION['user_id'];
$lead_owner_id = (int)($_SESSION['login_user_id'] ?? 0);
$lead_scope_sql = is_manager_user() ? ' AND created_by_user_id=?' : '';
$can_manage_leads = is_admin_user() || is_manager_user();
$show_lead_reference = is_admin_user();
$filter = normalize_lead_filter($_GET['filter'] ?? 'lead');
$message = $_SESSION['lead_management_flash_message'] ?? '';
$message_type = $_SESSION['lead_management_flash_type'] ?? 'success';
unset($_SESSION['lead_management_flash_message'], $_SESSION['lead_management_flash_type']);
$today = date('Y-m-d');

if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_reference'){
    if(!is_admin_user()){
        http_response_code(403);
        exit('Permission denied.');
    }

    $reference_lead_id = (int)($_POST['lead_id'] ?? 0);
    $reference_user_id = (int)($_POST['reference_user_id'] ?? 0);
    $admin_password = (string)($_POST['admin_password'] ?? '');

    $admin_stmt = mysqli_prepare($conn, "SELECT password FROM users WHERE id=? AND role='admin' LIMIT 1");
    mysqli_stmt_bind_param($admin_stmt, 'i', $user_id);
    mysqli_stmt_execute($admin_stmt);
    $admin_account = mysqli_fetch_assoc(mysqli_stmt_get_result($admin_stmt));

    if($reference_lead_id <= 0 || $reference_user_id <= 0 || !$admin_account || !password_verify($admin_password, $admin_account['password'])){
        $_SESSION['lead_management_flash_message'] = 'A valid Admin Password and staff reference are required.';
        $_SESSION['lead_management_flash_type'] = 'danger';
    }else{
        $reference_stmt = mysqli_prepare(
            $conn,
            "SELECT u.id, s.name
             FROM users u
             INNER JOIN staff s ON s.id=u.staff_id AND s.user_id=u.owner_id
             WHERE u.id=? AND u.owner_id=? AND u.role='manager' AND u.status='active' AND s.status='active'
             LIMIT 1"
        );
        mysqli_stmt_bind_param($reference_stmt, 'ii', $reference_user_id, $user_id);
        mysqli_stmt_execute($reference_stmt);
        $reference_staff = mysqli_fetch_assoc(mysqli_stmt_get_result($reference_stmt));

        if(!$reference_staff){
            $_SESSION['lead_management_flash_message'] = 'Selected staff reference is not available.';
            $_SESSION['lead_management_flash_type'] = 'danger';
        }else{
            mysqli_begin_transaction($conn);
            try {
                $reference_name = trim((string)$reference_staff['name']);
                $lead_update_stmt = mysqli_prepare($conn, "UPDATE leads SET created_by_user_id=?, created_by_name=? WHERE id=? AND user_id=?");
                mysqli_stmt_bind_param($lead_update_stmt, 'isii', $reference_user_id, $reference_name, $reference_lead_id, $user_id);
                mysqli_stmt_execute($lead_update_stmt);

                $customer_update_stmt = mysqli_prepare($conn, "UPDATE customers SET lead_ref_name=? WHERE lead_id=? AND user_id=?");
                mysqli_stmt_bind_param($customer_update_stmt, 'sii', $reference_name, $reference_lead_id, $user_id);
                mysqli_stmt_execute($customer_update_stmt);

                mysqli_commit($conn);
                $_SESSION['lead_management_flash_message'] = 'Lead reference updated successfully.';
                $_SESSION['lead_management_flash_type'] = 'success';
            }catch(Throwable $exception){
                mysqli_rollback($conn);
                $_SESSION['lead_management_flash_message'] = 'Unable to update the lead reference.';
                $_SESSION['lead_management_flash_type'] = 'danger';
            }
        }
    }

    header('Location: index.php?filter=' . urlencode($filter));
    exit;
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'inline_update'){
    header('Content-Type: application/json');

    if(!$can_manage_leads){
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Permission denied.']);
        exit;
    }

    $lead_id = (int)($_POST['id'] ?? 0);
    $field = $_POST['field'] ?? '';
    $value = trim($_POST['value'] ?? '');

    if($lead_id <= 0 || !in_array($field, ['followup_date', 'note'], true)){
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Invalid update request.']);
        exit;
    }

    if($field === 'followup_date'){
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)){
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Enter a valid followup date.']);
            exit;
        }
        if($value < $today){
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Followup Date cannot be earlier than today.']);
            exit;
        }
    }

    if($field === 'note'){
        $value = $value ?: 'General';
    }

    $sql = $field === 'followup_date'
        ? 'UPDATE leads SET followup_date=? WHERE id=? AND user_id=?' . $lead_scope_sql
        : 'UPDATE leads SET note=? WHERE id=? AND user_id=?' . $lead_scope_sql;
    $update_stmt = mysqli_prepare($conn, $sql);
    if(is_manager_user()){
        mysqli_stmt_bind_param($update_stmt, 'siii', $value, $lead_id, $user_id, $lead_owner_id);
    }else{
        mysqli_stmt_bind_param($update_stmt, 'sii', $value, $lead_id, $user_id);
    }
    mysqli_stmt_execute($update_stmt);

    $display_value = $field === 'followup_date'
        ? date('d-m-Y', strtotime($value))
        : $value;
    echo json_encode(['success' => true, 'value' => $value, 'display_value' => $display_value]);
    exit;
}

if($can_manage_leads && isset($_GET['set_status'], $_GET['id'])){
    $id = (int)$_GET['id'];
    $set_status = normalize_lead_filter($_GET['set_status']);

    $update_stmt = mysqli_prepare(
        $conn,
        "UPDATE leads
         SET status=?
         WHERE id=?
         AND user_id=?{$lead_scope_sql}"
    );
    if(is_manager_user()){
        mysqli_stmt_bind_param($update_stmt, 'siii', $set_status, $id, $user_id, $lead_owner_id);
    }else{
        mysqli_stmt_bind_param($update_stmt, 'sii', $set_status, $id, $user_id);
    }
    mysqli_stmt_execute($update_stmt);

    header('Location: index.php?filter=' . urlencode($set_status === 'customer' ? 'customer' : $filter));
    exit;
}

if($can_manage_leads && isset($_GET['delete'])){
    $id = (int)$_GET['delete'];

    $delete_stmt = mysqli_prepare(
        $conn,
        "DELETE FROM leads
         WHERE id=?
         AND user_id=?{$lead_scope_sql}"
    );
    if(is_manager_user()){
        mysqli_stmt_bind_param($delete_stmt, 'iii', $id, $user_id, $lead_owner_id);
    }else{
        mysqli_stmt_bind_param($delete_stmt, 'ii', $id, $user_id);
    }
    mysqli_stmt_execute($delete_stmt);

    header('Location: index.php?filter=' . urlencode($filter));
    exit;
}

$leads = [];
$stmt = mysqli_prepare(
    $conn,
    "SELECT l.*, c.customer_name AS converted_customer_name, c.customer_code AS converted_customer_code,
            c.phone AS converted_customer_phone
     FROM leads l
     LEFT JOIN customers c
        ON c.id=l.converted_customer_id
        AND c.user_id=l.user_id
     WHERE l.user_id=?
     AND l.status=?{$lead_scope_sql}
     ORDER BY COALESCE(l.followup_date, DATE(l.created_at)) ASC, l.id DESC"
);
if(is_manager_user()){
    mysqli_stmt_bind_param($stmt, 'isi', $user_id, $filter, $lead_owner_id);
}else{
    mysqli_stmt_bind_param($stmt, 'is', $user_id, $filter);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while($result && $row = mysqli_fetch_assoc($result)){
    $leads[] = $row;
}

$reference_staff_options = [];
if(is_admin_user()){
    $reference_options_stmt = mysqli_prepare(
        $conn,
        "SELECT u.id AS login_user_id, s.name, s.designation
         FROM users u
         INNER JOIN staff s ON s.id=u.staff_id AND s.user_id=u.owner_id
         WHERE u.owner_id=? AND u.role='manager' AND u.status='active' AND s.status='active'
         ORDER BY s.name ASC"
    );
    mysqli_stmt_bind_param($reference_options_stmt, 'i', $user_id);
    mysqli_stmt_execute($reference_options_stmt);
    $reference_options_result = mysqli_stmt_get_result($reference_options_stmt);
    while($reference_options_result && $reference_staff = mysqli_fetch_assoc($reference_options_result)){
        $reference_staff_options[] = $reference_staff;
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">
            <i class="fas fa-filter mr-2"></i>
            <?= htmlspecialchars(lead_management_title($filter)); ?>
        </h3>

        <?php if($filter === 'lead'){ ?>
            <div class="card-tools">
                <a href="create.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i>
                    Add Lead
                </a>
            </div>
        <?php } ?>

    </div>

    <div class="card-body">

        <?php if($message){ ?>
            <div class="alert alert-<?= htmlspecialchars($message_type); ?>"><?= htmlspecialchars($message); ?></div>
        <?php } ?>

        <table id="example1" class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th width="90">Lead ID</th>
                    <th width="220">Name</th>
                    <th width="145">Phone</th>
                    <?php if($show_lead_reference){ ?><th width="160">Ref</th><?php } ?>
                    <th width="145">Followup Date</th>
                    <th width="300">Note</th>
                    <th width="230">Action</th>
                </tr>
            </thead>

            <tbody>
                <?php if(empty($leads)){ ?>
                    <tr>
                        <td colspan="<?= $show_lead_reference ? 7 : 6; ?>" class="text-center text-muted">
                            No data available in table
                        </td>
                    </tr>
                <?php } else { ?>
                    <?php foreach($leads as $lead){ ?>
                        <tr>
                            <td><?= htmlspecialchars(lead_code_from_id((int)$lead['id'])); ?></td>
                            <td>
                                <?php if($filter === 'customer' && !empty($lead['converted_customer_name'])){ ?>
                                    <?= htmlspecialchars($lead['converted_customer_name']); ?>(CID-<?= htmlspecialchars($lead['converted_customer_code'] ?: '-'); ?>)
                                <?php } else { ?>
                                    <?= htmlspecialchars($lead['name']); ?>
                                <?php } ?>
                            </td>
                            <td>
                                <?= htmlspecialchars(
                                    $filter === 'customer' && !empty($lead['converted_customer_phone'])
                                        ? $lead['converted_customer_phone']
                                        : $lead['phone']
                                ); ?>
                            </td>
                            <?php if($show_lead_reference){ ?>
                                <td>
                                    <?= htmlspecialchars($lead['created_by_name'] ?: '-'); ?>
                                    <button type="button"
                                            class="btn btn-outline-secondary btn-xs lead-reference-change"
                                            data-id="<?= (int)$lead['id']; ?>"
                                            data-name="<?= htmlspecialchars($lead['created_by_name'] ?: '-'); ?>"
                                            title="Change Ref">
                                        <i class="fas fa-user-edit"></i>
                                    </button>
                                </td>
                            <?php } ?>
                            <td>
                                <span class="lead-edit-value"><?= htmlspecialchars($lead['followup_date'] ? date('d-m-Y', strtotime($lead['followup_date'])) : '-'); ?></span>
                                <?php if($can_manage_leads){ ?>
                                    <button type="button" class="btn btn-outline-secondary btn-xs lead-inline-edit" data-id="<?= (int)$lead['id']; ?>" data-field="followup_date" data-value="<?= htmlspecialchars($lead['followup_date'] ?: ''); ?>" data-min="<?= htmlspecialchars($today); ?>" title="Edit Followup Date"><i class="fas fa-edit"></i></button>
                                <?php } ?>
                            </td>
                            <td>
                                <span class="lead-edit-value"><?= htmlspecialchars($lead['note'] ?: 'General'); ?></span>
                                <?php if($can_manage_leads){ ?>
                                    <button type="button" class="btn btn-outline-secondary btn-xs lead-inline-edit" data-id="<?= (int)$lead['id']; ?>" data-field="note" data-value="<?= htmlspecialchars($lead['note'] ?: 'General'); ?>" title="Edit Note"><i class="fas fa-edit"></i></button>
                                <?php } ?>
                            </td>
                            <td>
                                <?php if($can_manage_leads){ ?>
                                    <?php if($filter === 'customer' && (int)($lead['converted_customer_id'] ?? 0) > 0){ ?>
                                        <strong class="text-success">Converted to Customer</strong>
                                    <?php } elseif($filter === 'customer'){ ?>
                                        <strong class="text-warning">Waiting for Customer</strong>
                                    <?php } else { ?>
                                    <?php if($filter === 'lead'){ ?>
                                        <a href="edit.php?id=<?= (int)$lead['id']; ?>" class="btn btn-warning btn-sm" title="Edit Lead">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    <?php } ?>

                                    <?php if($lead['status'] !== 'lead'){ ?>
                                        <a href="index.php?filter=<?= urlencode($filter); ?>&set_status=lead&id=<?= (int)$lead['id']; ?>" class="btn btn-secondary btn-sm" title="Back to New Lead">
                                            <i class="fas fa-arrow-left"></i>
                                        </a>
                                    <?php } ?>

                                    <?php if($lead['status'] !== 'successful'){ ?>
                                        <a href="index.php?filter=<?= urlencode($filter); ?>&set_status=successful&id=<?= (int)$lead['id']; ?>" class="btn btn-success btn-sm" title="Mark Qualified">
                                            <i class="fas fa-check"></i>
                                        </a>
                                    <?php } ?>

                                    <?php if(in_array($filter, ['successful', 'customer'], true)){ ?>
                                        <a href="index.php?filter=<?= urlencode($filter); ?>&set_status=not_qualified&id=<?= (int)$lead['id']; ?>" class="btn btn-danger btn-sm" title="Not Qualified">
                                            <i class="fas fa-times"></i>
                                        </a>
                                    <?php } ?>

                                    <?php if($filter === 'lead'){ ?>
                                        <a href="index.php?filter=<?= urlencode($filter); ?>&set_status=not_qualified&id=<?= (int)$lead['id']; ?>" class="btn btn-danger btn-sm" title="Not Qualified">
                                            <i class="fas fa-times"></i>
                                        </a>
                                    <?php } ?>

                                    <?php if(in_array($filter, ['lead', 'successful', 'not_qualified'], true) && $lead['status'] !== 'customer'){ ?>
                                        <a href="index.php?filter=<?= urlencode($filter); ?>&set_status=customer&id=<?= (int)$lead['id']; ?>" class="btn btn-info btn-sm" title="Move to Successful List">
                                            <i class="fas fa-exchange-alt"></i>
                                        </a>
                                    <?php } ?>

                                    <a
                                        href="index.php?filter=<?= urlencode($filter); ?>&delete=<?= (int)$lead['id']; ?>"
                                        class="btn btn-danger btn-sm"
                                        title="Delete Lead"
                                        onclick="return confirm('Delete this lead?')">
                                        <i class="fas fa-trash"></i>
                                    </a>

                                    <?php } ?>
                                <?php } else { ?>
                                    <span class="text-muted">No action</span>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                <?php } ?>
            </tbody>
        </table>

        <?php if($show_lead_reference){ ?>
            <div class="modal fade" id="changeLeadReferenceModal" tabindex="-1" role="dialog" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <form method="post" class="modal-content">
                        <input type="hidden" name="action" value="change_reference">
                        <input type="hidden" name="lead_id" id="reference-lead-id">
                        <div class="modal-header">
                            <h5 class="modal-title">Change Lead Ref</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted mb-3">Current Ref: <strong id="reference-current-name"></strong></p>
                            <div class="form-group">
                                <label>New Ref Staff</label>
                                <select name="reference_user_id" class="form-control" required>
                                    <option value="">Select staff</option>
                                    <?php foreach($reference_staff_options as $reference_staff){ ?>
                                        <option value="<?= (int)$reference_staff['login_user_id']; ?>">
                                            <?= htmlspecialchars($reference_staff['name'] . (!empty($reference_staff['designation']) ? ' (' . $reference_staff['designation'] . ')' : '')); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="form-group mb-0">
                                <label>Admin Password</label>
                                <input type="password" name="admin_password" class="form-control" autocomplete="current-password" required>
                                <small class="text-muted">Required to change a lead reference.</small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Update Ref</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php } ?>

        <script>
        document.querySelectorAll('.lead-reference-change').forEach(function(button) {
            button.addEventListener('click', function() {
                document.getElementById('reference-lead-id').value = button.dataset.id;
                document.getElementById('reference-current-name').textContent = button.dataset.name;
                $('#changeLeadReferenceModal').modal('show');
            });
        });

        document.querySelectorAll('.lead-inline-edit').forEach(function(button) {
            button.addEventListener('click', function() {
                if(button.dataset.editing === '1') return;

                const field = button.dataset.field;
                const cell = button.parentElement;
                const valueLabel = cell.querySelector('.lead-edit-value');
                const input = document.createElement(field === 'followup_date' ? 'input' : 'textarea');

                input.className = 'form-control form-control-sm d-inline-block mr-1';
                input.value = button.dataset.value;
                input.style.width = field === 'followup_date' ? '125px' : '70%';
                if(field === 'followup_date'){
                    input.type = 'date';
                    input.min = button.dataset.min || '';
                }else{
                    input.rows = 2;
                    input.style.verticalAlign = 'middle';
                }

                const saveButton = document.createElement('button');
                saveButton.type = 'button';
                saveButton.className = 'btn btn-success btn-xs mr-1';
                saveButton.title = 'Update';
                saveButton.innerHTML = '<i class="fas fa-check"></i>';

                const cancelButton = document.createElement('button');
                cancelButton.type = 'button';
                cancelButton.className = 'btn btn-secondary btn-xs';
                cancelButton.title = 'Cancel';
                cancelButton.innerHTML = '<i class="fas fa-times"></i>';

                button.dataset.editing = '1';
                valueLabel.style.display = 'none';
                button.style.display = 'none';
                cell.appendChild(input);
                cell.appendChild(saveButton);
                cell.appendChild(cancelButton);
                input.focus();

                function closeEditor() {
                    input.remove();
                    saveButton.remove();
                    cancelButton.remove();
                    valueLabel.style.display = '';
                    button.style.display = '';
                    delete button.dataset.editing;
                }

                cancelButton.addEventListener('click', closeEditor);
                saveButton.addEventListener('click', function() {
                    const value = input.value.trim();
                    if(field === 'followup_date' && value === ''){
                        window.alert('Followup Date is required.');
                        return;
                    }
                    if(field === 'followup_date' && input.min && value < input.min){
                        window.alert('Followup Date cannot be earlier than today.');
                        return;
                    }

                    saveButton.disabled = true;
                    fetch('index.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: new URLSearchParams({action: 'inline_update', id: button.dataset.id, field: field, value: value}).toString()
                    })
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        if(!data.success) throw new Error(data.message || 'Update failed.');
                        button.dataset.value = data.value;
                        valueLabel.textContent = data.display_value;
                        closeEditor();
                    })
                    .catch(function(error) {
                        saveButton.disabled = false;
                        window.alert(error.message);
                    });
                });
            });
        });
        </script>

    </div>

</div>

<?php
require_once '../includes/footer.php';
?>
