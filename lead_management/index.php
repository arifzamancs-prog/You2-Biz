<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/lead_management_helper.php';

ensure_lead_management_table($conn);

$user_id = (int)$_SESSION['user_id'];
$filter = normalize_lead_filter($_GET['filter'] ?? 'lead');
$message = '';

if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'inline_update'){
    header('Content-Type: application/json');

    if(!manager_can_modify()){
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

    if($field === 'followup_date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)){
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Enter a valid followup date.']);
        exit;
    }

    if($field === 'note'){
        $value = $value ?: 'General';
    }

    $sql = $field === 'followup_date'
        ? 'UPDATE leads SET followup_date=? WHERE id=? AND user_id=?'
        : 'UPDATE leads SET note=? WHERE id=? AND user_id=?';
    $update_stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($update_stmt, 'sii', $value, $lead_id, $user_id);
    mysqli_stmt_execute($update_stmt);

    $display_value = $field === 'followup_date'
        ? date('d-m-Y', strtotime($value))
        : $value;
    echo json_encode(['success' => true, 'value' => $value, 'display_value' => $display_value]);
    exit;
}

if(manager_can_modify() && isset($_GET['set_status'], $_GET['id'])){
    $id = (int)$_GET['id'];
    $set_status = normalize_lead_filter($_GET['set_status']);

    $update_stmt = mysqli_prepare(
        $conn,
        "UPDATE leads
         SET status=?
         WHERE id=?
         AND user_id=?"
    );
    mysqli_stmt_bind_param($update_stmt, 'sii', $set_status, $id, $user_id);
    mysqli_stmt_execute($update_stmt);

    header('Location: index.php?filter=' . urlencode($set_status === 'customer' ? 'customer' : $filter));
    exit;
}

if(manager_can_modify() && isset($_GET['delete'])){
    $id = (int)$_GET['delete'];

    $delete_stmt = mysqli_prepare(
        $conn,
        "DELETE FROM leads
         WHERE id=?
         AND user_id=?"
    );
    mysqli_stmt_bind_param($delete_stmt, 'ii', $id, $user_id);
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
     AND l.status=?
     ORDER BY COALESCE(l.followup_date, DATE(l.created_at)) ASC, l.id DESC"
);
mysqli_stmt_bind_param($stmt, 'is', $user_id, $filter);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while($result && $row = mysqli_fetch_assoc($result)){
    $leads[] = $row;
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
            <div class="alert alert-danger"><?= htmlspecialchars($message); ?></div>
        <?php } ?>

        <table id="example1" class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th width="90">Lead ID</th>
                    <th width="220">Name</th>
                    <th width="145">Phone</th>
                    <th width="145">Followup Date</th>
                    <th width="300">Note</th>
                    <th width="230">Action</th>
                </tr>
            </thead>

            <tbody>
                <?php if(empty($leads)){ ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted">
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
                            <td>
                                <span class="lead-edit-value"><?= htmlspecialchars($lead['followup_date'] ? date('d-m-Y', strtotime($lead['followup_date'])) : '-'); ?></span>
                                <?php if(manager_can_modify()){ ?>
                                    <button type="button" class="btn btn-outline-secondary btn-xs lead-inline-edit" data-id="<?= (int)$lead['id']; ?>" data-field="followup_date" data-value="<?= htmlspecialchars($lead['followup_date'] ?: ''); ?>" title="Edit Followup Date"><i class="fas fa-edit"></i></button>
                                <?php } ?>
                            </td>
                            <td>
                                <span class="lead-edit-value"><?= htmlspecialchars($lead['note'] ?: 'General'); ?></span>
                                <?php if(manager_can_modify()){ ?>
                                    <button type="button" class="btn btn-outline-secondary btn-xs lead-inline-edit" data-id="<?= (int)$lead['id']; ?>" data-field="note" data-value="<?= htmlspecialchars($lead['note'] ?: 'General'); ?>" title="Edit Note"><i class="fas fa-edit"></i></button>
                                <?php } ?>
                            </td>
                            <td>
                                <?php if(manager_can_modify()){ ?>
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

        <script>
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
