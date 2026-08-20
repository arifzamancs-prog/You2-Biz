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
                <h3 class="card-title">
                    <?= $edit_charge ? 'Edit Invoice Charge' : 'Add Invoice Charge'; ?>
                </h3>
            </div>

            <div class="card-body">
                <?php if($message){ ?>
                    <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                        <?= htmlspecialchars($message); ?>
                    </div>
                <?php } ?>

                <form method="post">
                    <input
                        type="hidden"
                        name="charge_id"
                        value="<?= (int)($edit_charge['id'] ?? 0); ?>">

                    <div class="form-group">
                        <label>Charge Name</label>
                        <input
                            type="text"
                            name="charge_name"
                            class="form-control"
                            value="<?= htmlspecialchars($edit_charge['charge_name'] ?? ''); ?>"
                            placeholder="Discount / VAT / Delivery"
                            required>
                    </div>

                    <div class="form-group">
                        <label>Effect</label>
                        <select name="charge_type" class="form-control">
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
                        <select name="charge_value_type" class="form-control">
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

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        <?= $edit_charge ? 'Update Charge' : 'Add Charge'; ?>
                    </button>

                    <?php if($edit_charge){ ?>
                        <a href="invoice_charges.php" class="btn btn-secondary">
                            Cancel
                        </a>
                    <?php } ?>
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
                        <tr>
                            <td><?= htmlspecialchars($charge['charge_name']); ?></td>
                            <td>
                                <?= $charge['charge_type'] === 'less' ? 'Less (-)' : 'Add (+)'; ?>
                            </td>
                            <td>
                                <?= ($charge['charge_value_type'] ?? 'fixed') === 'percent' ? 'Percentage (%)' : 'Fixed Amount'; ?>
                            </td>
                            <td>
                                <?php if((int)($charge['show_on_invoice'] ?? 0) === 1){ ?>
                                    <span class="badge badge-success">Shown</span>
                                <?php }else{ ?>
                                    <span class="badge badge-secondary">Hidden</span>
                                <?php } ?>
                            </td>
                            <td>
                                <?php if($charge['status'] === 'active'){ ?>
                                    <span class="badge badge-success">Active</span>
                                <?php }else{ ?>
                                    <span class="badge badge-danger">Inactive</span>
                                <?php } ?>
                            </td>
                            <td>
                                <?= (int)($charge['used_count'] ?? 0); ?>
                            </td>
                            <td>
                                <a
                                    href="invoice_charges.php?edit=<?= (int)$charge['id']; ?>"
                                    class="btn btn-sm btn-info">
                                    Edit
                                </a>

                                <?php if((int)($charge['show_on_invoice'] ?? 0) === 1){ ?>
                                    <a href="invoice_charges.php?id=<?= (int)$charge['id']; ?>&action=hide" class="btn btn-sm btn-warning">Hide</a>
                                <?php }else{ ?>
                                    <a href="invoice_charges.php?id=<?= (int)$charge['id']; ?>&action=show" class="btn btn-sm btn-success">Show</a>
                                <?php } ?>

                                <?php if($charge['status'] === 'active'){ ?>
                                    <a href="invoice_charges.php?id=<?= (int)$charge['id']; ?>&action=inactive" class="btn btn-sm btn-danger">Inactive</a>
                                <?php }else{ ?>
                                    <a href="invoice_charges.php?id=<?= (int)$charge['id']; ?>&action=active" class="btn btn-sm btn-info">Active</a>
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

<?php
require_once '../includes/footer.php';
?>
