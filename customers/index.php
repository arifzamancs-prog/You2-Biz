<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/customer_helper.php';
require_once '../includes/lead_management_helper.php';

$user_id = $_SESSION['user_id'];

$ref_staff_column = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'ref_staff_id'");
if($ref_staff_column && mysqli_num_rows($ref_staff_column) === 0){
    mysqli_query($conn, "ALTER TABLE customers ADD COLUMN ref_staff_id BIGINT UNSIGNED NULL AFTER customer_name");
    mysqli_query($conn, "ALTER TABLE customers ADD INDEX idx_customers_ref_staff (ref_staff_id)");
}

$lead_id_column = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'lead_id'");
if($lead_id_column && mysqli_num_rows($lead_id_column) === 0){
    mysqli_query($conn, "ALTER TABLE customers ADD COLUMN lead_id BIGINT UNSIGNED NULL AFTER ref_staff_id");
    mysqli_query($conn, "ALTER TABLE customers ADD UNIQUE KEY uniq_customers_lead_id (lead_id)");
}

$lead_ref_column = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'lead_ref_name'");
if($lead_ref_column && mysqli_num_rows($lead_ref_column) === 0){
    mysqli_query($conn, "ALTER TABLE customers ADD COLUMN lead_ref_name VARCHAR(150) NULL AFTER lead_id");
}

ensure_lead_management_table($conn);

if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_pending_lead'){
    if(manager_can_modify()){
        $lead_id = (int)($_POST['lead_id'] ?? 0);
        $cancel_stmt = mysqli_prepare(
            $conn,
            "UPDATE leads
             SET status='successful'
             WHERE id=?
             AND user_id=?
             AND status='customer'
             AND (converted_customer_id IS NULL OR converted_customer_id=0)"
        );
        mysqli_stmt_bind_param($cancel_stmt, 'ii', $lead_id, $user_id);
        mysqli_stmt_execute($cancel_stmt);
    }

    header('Location: index.php');
    exit;
}

$sql = "SELECT c.*, COALESCE(NULLIF(s.name, ''), NULLIF(c.lead_ref_name, '')) AS ref_staff_name
        FROM customers c
        LEFT JOIN staff s ON s.id=c.ref_staff_id AND s.user_id=c.user_id
        WHERE c.user_id=?
        ORDER BY c.id DESC";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$customers = [];
while($result && $row = mysqli_fetch_assoc($result)){
    $row['row_kind'] = 'customer';
    $customers[] = $row;
}

$pending_stmt = mysqli_prepare(
    $conn,
    "SELECT id, name, phone, email, note, created_by_name, status
     FROM leads
     WHERE user_id=?
     AND status='customer'
     AND (converted_customer_id IS NULL OR converted_customer_id=0)
     ORDER BY id DESC"
);
mysqli_stmt_bind_param($pending_stmt, 'i', $user_id);
mysqli_stmt_execute($pending_stmt);
$pending_result = mysqli_stmt_get_result($pending_stmt);
$pending_leads = [];
while($pending_result && $lead = mysqli_fetch_assoc($pending_result)){
    $pending_leads[] = [
        'row_kind' => 'pending_lead',
        'lead_id' => (int)$lead['id'],
        'customer_name' => $lead['name'],
        'ref_staff_name' => $lead['created_by_name'] ?: '-',
        'phone' => $lead['phone'],
        'address' => $lead['note'] ?: '-',
        'email' => $lead['email'] ?: '-',
        'status' => 'pending',
        'lead_status' => $lead['status'],
    ];
}

$customers = array_merge($pending_leads, $customers);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">

            <i class="fas fa-users mr-2"></i>

            Create Customer

        </h3>

        <div class="card-tools">

            <a
                href="create.php"
                class="btn btn-primary btn-sm">

                <i class="fas fa-plus"></i>

                Add Customer

            </a>

        </div>

    </div>

    <div class="card-body">

        <table
            id="example1"
            class="table table-bordered table-striped">

            <thead>

            <tr>

                <th>CID</th>
                <th>Customer Name</th>
                <th>Ref</th>
                <th>Phone</th>
                <th>Address</th>
                <th>Email</th>
                <th>Status</th>
                <th width="130">Action</th>

            </tr>

            </thead>

            <tbody>

            <?php foreach($customers as $row){ $is_pending_lead = ($row['row_kind'] ?? '') === 'pending_lead'; $used = !$is_pending_lead && customer_has_transactions($conn, $row['id'], $user_id); ?>

            <tr>

                <td>
                    <?= $is_pending_lead ? '-' : htmlspecialchars($row['customer_code'] ?: '-'); ?>
                </td>

                <td>
                    <?= htmlspecialchars($row['customer_name']); ?>
                </td>

                <td>
                    <?= htmlspecialchars($row['ref_staff_name'] ?: '-'); ?>
                </td>

                <td>
                    <?= htmlspecialchars($row['phone']); ?>
                </td>

                <td>
                    <?= htmlspecialchars($row['address'] ?: '-'); ?>
                </td>

                <td>
                    <?= htmlspecialchars($row['email']); ?>
                </td>

                <td>

                    <?php if($is_pending_lead){ ?>

                        <span class="badge badge-warning">Pending</span>

                    <?php } elseif($row['status']=='active'){ ?>

                        <span class="badge badge-success">

                            Active

                        </span>

                    <?php } else { ?>

                        <span class="badge badge-danger">

                            Inactive

                        </span>

                    <?php } ?>

                </td>

                <td>

                    <?php if($is_pending_lead){ ?>

                        <?php if(manager_can_modify()){ ?>
                            <a
                                href="create.php?lead_id=<?= (int)$row['lead_id']; ?>"
                                class="btn btn-primary btn-sm"
                                title="Convert to Customer">
                                <i class="fas fa-user-plus"></i>
                            </a>

                            <?php if(($row['lead_status'] ?? '') === 'customer'){ ?>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="action" value="cancel_pending_lead">
                                    <input type="hidden" name="lead_id" value="<?= (int)$row['lead_id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" title="Cancel and return to Qualified List" onclick="return confirm('Cancel this pending customer conversion?')">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </form>
                            <?php } ?>
                        <?php } else { ?>
                            <span class="text-muted">No action</span>
                        <?php } ?>

                    <?php } else { ?>

                    <a
                        href="customer_ledger.php?id=<?= $row['id']; ?>"
                        class="btn btn-info btn-sm"
                        title="Ledger">

                        <i class="fas fa-book"></i>

                    </a>

                    <?php if(manager_can_modify()){ ?>

                    <a
                        href="edit.php?id=<?= $row['id']; ?>"
                        class="btn btn-warning btn-sm"
                        title="Edit">

                        <i class="fas fa-edit"></i>

                    </a>

                    <?php if(!$used){ ?>
                        <a
                            href="delete.php?id=<?= $row['id']; ?>"
                            class="btn btn-danger btn-sm"
                            onclick="return confirm('Delete this customer?')"
                            title="Delete">

                            <i class="fas fa-trash"></i>

                        </a>
                    <?php }else{ ?>
                        <button
                            type="button"
                            class="btn btn-secondary btn-sm"
                            disabled
                            title="This customer has transactions and cannot be deleted">

                            <i class="fas fa-trash"></i>

                        </button>
                    <?php } ?>

                    <?php } ?>

                    <?php } ?>

                </td>

            </tr>

            <?php } ?>

            </tbody>

        </table>

    </div>

</div>

<?php
require_once '../includes/footer.php';
?>
