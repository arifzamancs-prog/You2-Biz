<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/invoice_charge_helper.php';

require_admin_user();
ensure_invoice_charge_columns($conn);

$user_id = (int)$_SESSION['user_id'];
ensure_default_invoice_charges($conn, $user_id);

$message = '';
$message_type = '';

if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajax_action'){
    header('Content-Type: application/json');
    $charge_id = (int)($_POST['charge_id'] ?? 0);
    $ajax_action = $_POST['charge_action'] ?? '';

    $charge_stmt = mysqli_prepare($conn, "SELECT * FROM invoice_charge_types WHERE id=? AND user_id=? LIMIT 1");
    mysqli_stmt_bind_param($charge_stmt, 'ii', $charge_id, $user_id);
    mysqli_stmt_execute($charge_stmt);
    $charge = mysqli_fetch_assoc(mysqli_stmt_get_result($charge_stmt));

    if(!$charge){
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Charge not found.']);
        exit;
    }

    if($ajax_action === 'edit'){
        echo json_encode(['success' => true, 'charge' => $charge]);
        exit;
    }

    if($ajax_action === 'delete'){
        $used_stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM invoice_charges WHERE charge_type_id=?");
        mysqli_stmt_bind_param($used_stmt, 'i', $charge_id);
        mysqli_stmt_execute($used_stmt);
        $used = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($used_stmt))['total'] ?? 0);
        if($used > 0){
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'This charge is already used.']);
            exit;
        }
        $stmt = mysqli_prepare($conn, "DELETE FROM invoice_charge_types WHERE id=? AND user_id=?");
        mysqli_stmt_bind_param($stmt, 'ii', $charge_id, $user_id);
        mysqli_stmt_execute($stmt);
        echo json_encode(['success' => true, 'deleted' => true]);
        exit;
    }

    if(in_array($ajax_action, ['show', 'hide'], true)){
        $value = $ajax_action === 'show' ? 1 : 0;
        $stmt = mysqli_prepare($conn, "UPDATE invoice_charge_types SET show_on_invoice=? WHERE id=? AND user_id=?");
        mysqli_stmt_bind_param($stmt, 'iii', $value, $charge_id, $user_id);
        mysqli_stmt_execute($stmt);
    }elseif(in_array($ajax_action, ['active', 'inactive'], true)){
        $value = $ajax_action;
        $stmt = mysqli_prepare($conn, "UPDATE invoice_charge_types SET status=? WHERE id=? AND user_id=?");
        mysqli_stmt_bind_param($stmt, 'sii', $value, $charge_id, $user_id);
        mysqli_stmt_execute($stmt);
    }else{
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Invalid action.']);
        exit;
    }

    $updated_stmt = mysqli_prepare($conn, "SELECT * FROM invoice_charge_types WHERE id=? AND user_id=? LIMIT 1");
    mysqli_stmt_bind_param($updated_stmt, 'ii', $charge_id, $user_id);
    mysqli_stmt_execute($updated_stmt);
    $updated_charge = mysqli_fetch_assoc(mysqli_stmt_get_result($updated_stmt));

    echo json_encode(['success' => true, 'action' => $ajax_action, 'charge' => $updated_charge]);
    exit;
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $charge_id = (int)($_POST['charge_id'] ?? 0);
    $charge_name = trim($_POST['charge_name'] ?? '');
    $charge_type = normalize_charge_type($_POST['charge_type'] ?? 'add');
    $charge_value_type = normalize_charge_value_type($_POST['charge_value_type'] ?? 'fixed');

    if($charge_name === ''){
        $message = 'Charge name is required.';
        $message_type = 'danger';
    }else{
        $check_sql = "SELECT id
                      FROM invoice_charge_types
                      WHERE user_id=?
                      AND charge_name=?
                      AND id<>?
                      LIMIT 1";

        $check_stmt = mysqli_prepare($conn, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "isi", $user_id, $charge_name, $charge_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);

        if(mysqli_num_rows($check_result) > 0){
            $message = 'Charge name already exists.';
            $message_type = 'danger';
        }elseif($charge_id > 0){
            $update_sql = "UPDATE invoice_charge_types
                           SET charge_name=?,
                               charge_type=?,
                               charge_value_type=?
                           WHERE id=?
                           AND user_id=?";

            $update_stmt = mysqli_prepare($conn, $update_sql);
            mysqli_stmt_bind_param(
                $update_stmt,
                "sssii",
                $charge_name,
                $charge_type,
                $charge_value_type,
                $charge_id,
                $user_id
            );

            if(mysqli_stmt_execute($update_stmt)){
                $message = 'Invoice charge updated.';
                $message_type = 'success';
            }else{
                $message = 'Invoice charge could not be updated.';
                $message_type = 'danger';
            }
        }else{
            $insert_sql = "INSERT INTO invoice_charge_types
                           (
                               user_id,
                               charge_name,
                               charge_type,
                               charge_value_type,
                               show_on_invoice,
                               status
                           )
                           VALUES
                           (
                               ?,
                               ?,
                               ?,
                               ?,
                               1,
                               'active'
                           )";

            $insert_stmt = mysqli_prepare($conn, $insert_sql);
            mysqli_stmt_bind_param(
                $insert_stmt,
                "isss",
                $user_id,
                $charge_name,
                $charge_type,
                $charge_value_type
            );

            if(mysqli_stmt_execute($insert_stmt)){
                $message = 'Invoice charge added.';
                $message_type = 'success';
            }else{
                $message = 'Invoice charge could not be added.';
                $message_type = 'danger';
            }
        }
    }
}

if(isset($_GET['id'], $_GET['action'])){
    $charge_id = (int)$_GET['id'];
    $action = $_GET['action'];

    if($action === 'show' || $action === 'hide'){
        $show_on_invoice = $action === 'show' ? 1 : 0;

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE invoice_charge_types
             SET show_on_invoice=?
             WHERE id=?
             AND user_id=?"
        );

        mysqli_stmt_bind_param($stmt, "iii", $show_on_invoice, $charge_id, $user_id);
        mysqli_stmt_execute($stmt);
    }

    if($action === 'active' || $action === 'inactive'){
        $status = $action === 'active' ? 'active' : 'inactive';

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE invoice_charge_types
             SET status=?
             WHERE id=?
             AND user_id=?"
        );

        mysqli_stmt_bind_param($stmt, "sii", $status, $charge_id, $user_id);
        mysqli_stmt_execute($stmt);
    }

    if($action === 'delete'){
        $used_stmt = mysqli_prepare(
            $conn,
            "SELECT COUNT(*) AS total
             FROM invoice_charges
             WHERE charge_type_id=?"
        );
        mysqli_stmt_bind_param($used_stmt, 'i', $charge_id);
        mysqli_stmt_execute($used_stmt);
        $used_row = mysqli_fetch_assoc(mysqli_stmt_get_result($used_stmt));

        if((int)($used_row['total'] ?? 0) === 0){
            $delete_stmt = mysqli_prepare(
                $conn,
                "DELETE FROM invoice_charge_types
                 WHERE id=?
                 AND user_id=?"
            );
            mysqli_stmt_bind_param($delete_stmt, 'ii', $charge_id, $user_id);
            mysqli_stmt_execute($delete_stmt);
        }
    }

    header("Location: invoice_charges.php");
    exit;
}

$edit_charge = null;

if(isset($_GET['edit'])){
    $edit_id = (int)$_GET['edit'];

    $edit_stmt = mysqli_prepare(
        $conn,
        "SELECT *
         FROM invoice_charge_types
         WHERE id=?
         AND user_id=?
         LIMIT 1"
    );

    mysqli_stmt_bind_param($edit_stmt, "ii", $edit_id, $user_id);
    mysqli_stmt_execute($edit_stmt);
    $edit_result = mysqli_stmt_get_result($edit_stmt);
    $edit_charge = $edit_result ? mysqli_fetch_assoc($edit_result) : null;
}

$charges_sql = "SELECT
                    ict.*,
                    (
                        SELECT COUNT(*)
                        FROM invoice_charges ic
                        WHERE ic.charge_type_id=ict.id
                    ) AS used_count
                FROM invoice_charge_types ict
                WHERE ict.user_id=?
                ORDER BY ict.show_on_invoice DESC, ict.status ASC, ict.id DESC";

$charges_stmt = mysqli_prepare($conn, $charges_sql);
mysqli_stmt_bind_param($charges_stmt, "i", $user_id);
mysqli_stmt_execute($charges_stmt);
$charges = mysqli_stmt_get_result($charges_stmt);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="row">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title" id="invoice-charge-form-title">
                    <?= $edit_charge ? 'Edit Invoice Charge' : 'Add Invoice Charge'; ?>
                </h3>
            </div>

            <div class="card-body">
                <?php if($message){ ?>
                    <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                        <?= htmlspecialchars($message); ?>
                    </div>
                <?php } ?>

                <form method="post" id="invoice-charge-form">
                    <input
                        id="charge_id"
                        type="hidden"
                        name="charge_id"
                        value="<?= (int)($edit_charge['id'] ?? 0); ?>">

                    <div class="form-group">
                        <label>Charge Name</label>
                        <input
                            id="charge_name"
                            type="text"
                            name="charge_name"
                            class="form-control"
                            value="<?= htmlspecialchars($edit_charge['charge_name'] ?? ''); ?>"
                            placeholder="Discount / VAT / Delivery"
                            required>
                    </div>

                    <div class="form-group">
                        <label>Effect</label>
                        <select id="charge_type" name="charge_type" class="form-control">
                            <option
                                value="add"
                                <?= (($edit_charge['charge_type'] ?? 'add') === 'add') ? 'selected' : ''; ?>>
                                Add (+)
                            </option>
                            <option
                                value="less"
                                <?= (($edit_charge['charge_type'] ?? '') === 'less') ? 'selected' : ''; ?>>
                                Less (-)
                            </option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Value Type</label>
                        <select id="charge_value_type" name="charge_value_type" class="form-control">
                            <option
                                value="fixed"
                                <?= (($edit_charge['charge_value_type'] ?? 'fixed') === 'fixed') ? 'selected' : ''; ?>>
                                Fixed Amount
                            </option>
                            <option
                                value="percent"
                                <?= (($edit_charge['charge_value_type'] ?? '') === 'percent') ? 'selected' : ''; ?>>
                                Percentage (%)
                            </option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary" id="invoice-charge-submit">
                        <i class="fas fa-save"></i>
                        <?= $edit_charge ? 'Update Charge' : 'Add Charge'; ?>
                    </button>

                    <a href="invoice_charges.php" class="btn btn-secondary <?= $edit_charge ? '' : 'd-none'; ?>" id="invoice-charge-cancel">
                        Cancel
                    </a>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Invoice Charges</h3>
            </div>

            <div class="card-body">
                <table id="example1" class="table table-bordered table-striped">
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Effect</th>
                        <th>Value</th>
                        <th>Invoice</th>
                        <th>Status</th>
                        <th>Used</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php while($charge = mysqli_fetch_assoc($charges)){ ?>
                        <tr data-charge-id="<?= (int)$charge['id']; ?>">
                            <td><?= htmlspecialchars($charge['charge_name']); ?></td>
                            <td>
                                <?= $charge['charge_type'] === 'less' ? 'Less (-)' : 'Add (+)'; ?>
                            </td>
                            <td>
                                <?= ($charge['charge_value_type'] ?? 'fixed') === 'percent' ? 'Percentage (%)' : 'Fixed Amount'; ?>
                            </td>
                            <td>
                                <?php if((int)($charge['show_on_invoice'] ?? 0) === 1){ ?>
                                    <span class="badge badge-success invoice-visibility-badge">Shown</span>
                                <?php }else{ ?>
                                    <span class="badge badge-secondary invoice-visibility-badge">Hidden</span>
                                <?php } ?>
                            </td>
                            <td>
                                <?php if($charge['status'] === 'active'){ ?>
                                    <span class="badge badge-success invoice-status-badge">Active</span>
                                <?php }else{ ?>
                                    <span class="badge badge-danger invoice-status-badge">Inactive</span>
                                <?php } ?>
                            </td>
                            <td>
                                <?= (int)($charge['used_count'] ?? 0); ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-info invoice-charge-action" data-charge-id="<?= (int)$charge['id']; ?>" data-charge-action="edit" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>

                                <?php if((int)($charge['show_on_invoice'] ?? 0) === 1){ ?>
                                    <button type="button" class="btn btn-sm btn-warning invoice-charge-action invoice-visibility-action" data-charge-id="<?= (int)$charge['id']; ?>" data-charge-action="hide" title="Hide"><i class="fas fa-eye-slash"></i></button>
                                <?php }else{ ?>
                                    <button type="button" class="btn btn-sm btn-success invoice-charge-action invoice-visibility-action" data-charge-id="<?= (int)$charge['id']; ?>" data-charge-action="show" title="Show"><i class="fas fa-eye"></i></button>
                                <?php } ?>

                                <?php if($charge['status'] === 'active'){ ?>
                                    <button type="button" class="btn btn-sm btn-danger invoice-charge-action invoice-status-action" data-charge-id="<?= (int)$charge['id']; ?>" data-charge-action="inactive" title="Make Inactive"><i class="fas fa-toggle-off"></i></button>
                                <?php }else{ ?>
                                    <button type="button" class="btn btn-sm btn-info invoice-charge-action invoice-status-action" data-charge-id="<?= (int)$charge['id']; ?>" data-charge-action="active" title="Make Active"><i class="fas fa-toggle-on"></i></button>
                                <?php } ?>

                                <?php if((int)($charge['used_count'] ?? 0) === 0){ ?>
                                    <button type="button" class="btn btn-sm btn-danger invoice-charge-action" data-charge-id="<?= (int)$charge['id']; ?>" data-charge-action="delete" title="Delete"><i class="fas fa-trash"></i></button>
                                <?php }else{ ?>
                                    <button class="btn btn-sm btn-secondary" disabled title="This charge is already used"><i class="fas fa-trash"></i></button>
                                <?php } ?>

                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('click', async function(event) {
    const button = event.target.closest('.invoice-charge-action');
    if (!button || button.disabled) {
        return;
    }

    const action = button.dataset.chargeAction;
    const chargeId = button.dataset.chargeId;

    if (action === 'delete' && !window.confirm('Delete this invoice charge?')) {
        return;
    }

    button.disabled = true;

    try {
        const response = await fetch('invoice_charges.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: new URLSearchParams({
                action: 'ajax_action',
                charge_id: chargeId,
                charge_action: action
            })
        });
        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Action could not be completed.');
        }

        const row = button.closest('tr');

        if (action === 'edit') {
            const charge = data.charge;
            document.getElementById('charge_id').value = charge.id;
            document.getElementById('charge_name').value = charge.charge_name;
            document.getElementById('charge_type').value = charge.charge_type;
            document.getElementById('charge_value_type').value = charge.charge_value_type || 'fixed';
            document.getElementById('invoice-charge-form-title').textContent = 'Edit Invoice Charge';
            document.getElementById('invoice-charge-submit').innerHTML = '<i class="fas fa-save"></i> Update Charge';
            document.getElementById('invoice-charge-cancel').classList.remove('d-none');
            document.getElementById('charge_name').focus();
            return;
        }

        if (action === 'delete') {
            if (window.jQuery && jQuery.fn.DataTable && jQuery.fn.DataTable.isDataTable('#example1')) {
                jQuery('#example1').DataTable().row(row).remove().draw(false);
            } else {
                row.remove();
            }
            return;
        }

        const charge = data.charge;
        if (action === 'show' || action === 'hide') {
            const shown = Number(charge.show_on_invoice) === 1;
            const badge = row.querySelector('.invoice-visibility-badge');
            badge.textContent = shown ? 'Shown' : 'Hidden';
            badge.className = 'badge invoice-visibility-badge ' + (shown ? 'badge-success' : 'badge-secondary');
            button.dataset.chargeAction = shown ? 'hide' : 'show';
            button.title = shown ? 'Hide' : 'Show';
            button.className = 'btn btn-sm invoice-charge-action invoice-visibility-action ' + (shown ? 'btn-warning' : 'btn-success');
            button.innerHTML = shown ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
        }

        if (action === 'active' || action === 'inactive') {
            const active = charge.status === 'active';
            const badge = row.querySelector('.invoice-status-badge');
            badge.textContent = active ? 'Active' : 'Inactive';
            badge.className = 'badge invoice-status-badge ' + (active ? 'badge-success' : 'badge-danger');
            button.dataset.chargeAction = active ? 'inactive' : 'active';
            button.title = active ? 'Make Inactive' : 'Make Active';
            button.className = 'btn btn-sm invoice-charge-action invoice-status-action ' + (active ? 'btn-danger' : 'btn-info');
            button.innerHTML = active ? '<i class="fas fa-toggle-off"></i>' : '<i class="fas fa-toggle-on"></i>';
        }
    } catch (error) {
        window.alert(error.message || 'Action could not be completed.');
    } finally {
        button.disabled = false;
    }
});
</script>

<?php
require_once '../includes/footer.php';
?>
