<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/customer_form_helper.php';

if(!is_admin_user()){
    header('Location: ' . app_path('dashboard.php?error=Permission%20denied'));
    exit;
}

$user_id = (int)$_SESSION['user_id'];
customer_form_ensure_schema($conn);
customer_form_seed_system_fields($conn, $user_id);

$message = '';
$message_type = 'success';
$edit_id = (int)($_GET['edit'] ?? 0);
$edit_field = null;

function customer_form_flash_redirect($text, $type = 'success')
{
    $_SESSION['customer_form_flash'] = [
        'message' => $text,
        'type' => $type === 'danger' ? 'danger' : 'success',
    ];

    header('Location: form_settings.php');
    exit;
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $action = $_POST['action'] ?? 'save';
    $field_id = (int)($_POST['field_id'] ?? 0);

    if($action === 'delete'){
        $stmt = mysqli_prepare($conn, "DELETE FROM customer_form_fields WHERE id=? AND user_id=? AND is_system=0");
        mysqli_stmt_bind_param($stmt, 'ii', $field_id, $user_id);
        mysqli_stmt_execute($stmt);
        customer_form_flash_redirect('Field deleted');
    }

    if($action === 'sort'){
        $ordered_ids = $_POST['field_order'] ?? [];
        $sort_order = 10;

        foreach($ordered_ids as $ordered_id){
            $ordered_id = (int)$ordered_id;
            if($ordered_id <= 0){
                continue;
            }

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE customer_form_fields SET sort_order=? WHERE id=? AND user_id=?"
            );
            mysqli_stmt_bind_param($stmt, 'iii', $sort_order, $ordered_id, $user_id);
            mysqli_stmt_execute($stmt);
            $sort_order += 10;
        }

        customer_form_flash_redirect('Sort order updated');
    }

    $label = trim((string)($_POST['label'] ?? ''));
    $field_type = customer_form_normalize_type($_POST['field_type'] ?? 'text');
    $options = customer_form_normalize_options($_POST['options'] ?? '');
    $is_required = isset($_POST['is_required']) ? 1 : 0;
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if($label === ''){
        $message = 'Field label is required.';
        $message_type = 'danger';
    } elseif($field_type === 'dropdown' && $options === ''){
        $message = 'Dropdown options are required.';
        $message_type = 'danger';
    } else {
        if($field_id > 0){
            $system_stmt = mysqli_prepare($conn, "SELECT is_system FROM customer_form_fields WHERE id=? AND user_id=? LIMIT 1");
            mysqli_stmt_bind_param($system_stmt, 'ii', $field_id, $user_id);
            mysqli_stmt_execute($system_stmt);
            $system_field = mysqli_fetch_assoc(mysqli_stmt_get_result($system_stmt));

            if(!empty($system_field['is_system'])){
                customer_form_flash_redirect('Built-in fields can only be sorted');
            }

            $key = customer_form_unique_key($conn, $user_id, $label, $field_id);
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE customer_form_fields
                 SET field_key=?, label=?, field_type=?, options=?, is_required=?, status=?
                 WHERE id=? AND user_id=?"
            );
            mysqli_stmt_bind_param(
                $stmt,
                'ssssisii',
                $key,
                $label,
                $field_type,
                $options,
                $is_required,
                $status,
                $field_id,
                $user_id
            );
            mysqli_stmt_execute($stmt);
            customer_form_flash_redirect('Field updated');
        }

        $key = customer_form_unique_key($conn, $user_id, $label);
        $next_order_result = mysqli_query(
            $conn,
            "SELECT COALESCE(MAX(sort_order), 0) + 10 AS next_order
             FROM customer_form_fields
             WHERE user_id=" . (int)$user_id
        );
        $next_order_row = $next_order_result ? mysqli_fetch_assoc($next_order_result) : null;
        $sort_order = (int)($next_order_row['next_order'] ?? 10);
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO customer_form_fields
                (user_id, field_key, label, field_type, options, is_required, sort_order, status)
             VALUES (?,?,?,?,?,?,?,?)"
        );
        mysqli_stmt_bind_param(
            $stmt,
            'issssiis',
            $user_id,
            $key,
            $label,
            $field_type,
            $options,
            $is_required,
            $sort_order,
            $status
        );
        mysqli_stmt_execute($stmt);
        customer_form_flash_redirect('Field added');
    }
}

if($edit_id > 0){
    $edit_stmt = mysqli_prepare($conn, "SELECT * FROM customer_form_fields WHERE id=? AND user_id=? AND is_system=0 LIMIT 1");
    mysqli_stmt_bind_param($edit_stmt, 'ii', $edit_id, $user_id);
    mysqli_stmt_execute($edit_stmt);
    $edit_field = mysqli_fetch_assoc(mysqli_stmt_get_result($edit_stmt));
}

$fields = customer_form_get_fields($conn, $user_id, false);
$flash = $_SESSION['customer_form_flash'] ?? null;
unset($_SESSION['customer_form_flash']);
$flash_message = is_array($flash) ? (string)($flash['message'] ?? '') : '';
$flash_type = is_array($flash) && ($flash['type'] ?? 'success') === 'danger' ? 'danger' : 'success';

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<?php if($flash_message !== ''){ ?>
    <div class="alert alert-<?= htmlspecialchars($flash_type); ?>">
        <?= htmlspecialchars($flash_message); ?>
    </div>
<?php } ?>

<?php if($message !== ''){ ?>
    <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
        <?= htmlspecialchars($message); ?>
    </div>
<?php } ?>

<div class="row">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <?= $edit_field ? 'Update Customer Field' : 'Add Customer Field'; ?>
                </h3>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="field_id" value="<?= (int)($edit_field['id'] ?? 0); ?>">

                    <div class="form-group">
                        <label>Field Label</label>
                        <input
                            type="text"
                            name="label"
                            class="form-control"
                            value="<?= htmlspecialchars($edit_field['label'] ?? ''); ?>"
                            required>
                    </div>

                    <div class="form-group">
                        <label>Field Type</label>
                        <select name="field_type" class="form-control" id="field_type">
                            <?php $selected_type = $edit_field['field_type'] ?? 'text'; ?>
                            <?php foreach(customer_form_field_types() as $type_key => $type_label){ ?>
                                <option value="<?= htmlspecialchars($type_key); ?>" <?= $selected_type === $type_key ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($type_label); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="form-group" id="field_options_group">
                        <label id="field_options_label">Dropdown Options</label>
                        <textarea
                            name="options"
                            class="form-control"
                            rows="5"
                            placeholder="One option per line"><?= htmlspecialchars($edit_field['options'] ?? ''); ?></textarea>
                        <small class="text-muted" id="field_options_help">Dropdown hole prottek option notun line e din.</small>
                    </div>

                    <div class="form-group">
                        <label>Status</label>
                        <?php $selected_status = $edit_field['status'] ?? 'active'; ?>
                        <select name="status" class="form-control">
                            <option value="active" <?= $selected_status === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?= $selected_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="form-check mb-3">
                        <input
                            type="checkbox"
                            name="is_required"
                            class="form-check-input"
                            id="is_required"
                            <?= !empty($edit_field['is_required']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_required">Required field</label>
                    </div>

                    <button class="btn btn-primary" type="submit">
                        <i class="fas fa-save"></i>
                        <?= $edit_field ? 'Update Field' : 'Add Field'; ?>
                    </button>

                    <?php if($edit_field){ ?>
                        <a href="form_settings.php" class="btn btn-secondary">Cancel</a>
                    <?php } ?>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Customer Form Settings</h3>
            </div>
            <div class="card-body">
                <p class="text-muted mb-3">
                    Built-in fields list e thakbe, kintu delete/edit kora jabe na. Mouse diye row drag kore order change korun.
                </p>
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>Label</th>
                            <th>Type</th>
                            <th>Required</th>
                            <th>Status</th>
                            <th width="120">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($fields)){ ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted">No custom field added yet.</td>
                            </tr>
                        <?php } ?>
                        <?php foreach($fields as $field){ ?>
                            <tr class="customer-field-row" draggable="true" data-field-id="<?= (int)$field['id']; ?>">
                                <td>
                                    <i class="fas fa-grip-vertical text-muted mr-2 drag-handle" title="Drag to sort"></i>
                                    <?= htmlspecialchars($field['label']); ?>
                                    <?php if(!empty($field['is_system'])){ ?>
                                        <span class="badge badge-info ml-1">Built-in</span>
                                    <?php } ?>
                                </td>
                                <td><?= htmlspecialchars(customer_form_field_types()[$field['field_type']] ?? ucfirst($field['field_type'])); ?></td>
                                <td>
                                    <span class="badge badge-<?= !empty($field['is_required']) ? 'success' : 'secondary'; ?>">
                                        <?= !empty($field['is_required']) ? 'Yes' : 'No'; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $field['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                        <?= htmlspecialchars(ucfirst($field['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if(empty($field['is_system'])){ ?>
                                        <a
                                            href="form_settings.php?edit=<?= (int)$field['id']; ?>"
                                            class="btn btn-warning btn-sm"
                                            title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this field? Saved old customer data for this field will remain in database.');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="field_id" value="<?= (int)$field['id']; ?>">
                                            <button class="btn btn-danger btn-sm" type="submit" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    <?php } else { ?>
                                        <span class="text-muted">Locked</span>
                                    <?php } ?>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
                <form method="post" id="sort_order_form">
                    <input type="hidden" name="action" value="sort">
                    <div id="field_order_inputs"></div>
                    <button class="btn btn-success" type="submit">
                        <i class="fas fa-sort"></i>
                        Save Sort Order
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$page_script = '
<style>
.customer-field-row{cursor:move}
.customer-field-row.dragging{opacity:.55}
.drag-handle{cursor:grab}
</style>
<script>
$(function(){
    const $type = $("#field_type");
    const $options = $("#field_options_group");

    function toggleOptions(){
        const selectedType = $type.val();
        $options.toggle(selectedType === "dropdown");
        $("#field_options_label").text("Dropdown Options");
        $("#field_options_help").text("Dropdown hole prottek option notun line e din.");
    }

    $type.on("change", toggleOptions);
    toggleOptions();

    const tableBody = document.querySelector(".customer-field-row") ? document.querySelector(".customer-field-row").parentElement : null;
    const orderInputs = document.getElementById("field_order_inputs");

    function refreshOrderInputs(){
        if(!tableBody || !orderInputs){
            return;
        }
        orderInputs.innerHTML = "";
        tableBody.querySelectorAll(".customer-field-row").forEach(function(row){
            const input = document.createElement("input");
            input.type = "hidden";
            input.name = "field_order[]";
            input.value = row.getAttribute("data-field-id");
            orderInputs.appendChild(input);
        });
    }

    if(tableBody){
        tableBody.addEventListener("dragstart", function(event){
            const row = event.target.closest(".customer-field-row");
            if(row){
                row.classList.add("dragging");
            }
        });

        tableBody.addEventListener("dragend", function(event){
            const row = event.target.closest(".customer-field-row");
            if(row){
                row.classList.remove("dragging");
            }
            refreshOrderInputs();
        });

        tableBody.addEventListener("dragover", function(event){
            event.preventDefault();
            const dragging = tableBody.querySelector(".dragging");
            const afterRow = Array.from(tableBody.querySelectorAll(".customer-field-row:not(.dragging)")).find(function(row){
                const box = row.getBoundingClientRect();
                return event.clientY < box.top + box.height / 2;
            });

            if(!dragging){
                return;
            }

            if(afterRow){
                tableBody.insertBefore(dragging, afterRow);
            }else{
                tableBody.appendChild(dragging);
            }
        });
    }

    refreshOrderInputs();
});
</script>
';

require_once '../includes/footer.php';
?>
