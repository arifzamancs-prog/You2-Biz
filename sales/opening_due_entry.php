<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/customer_opening_due_helper.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

if(is_agent_user()){
    header("Location: invoice_list.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
ensure_customer_opening_due_tables($conn);

$customers = mysqli_query(
    $conn,
    "SELECT id, customer_name, phone
     FROM customers
     WHERE user_id={$user_id}
     AND status='active'
     ORDER BY customer_name ASC"
);

$recent_entries = mysqli_query(
    $conn,
    "SELECT od.id, od.due_no, od.entry_date, od.amount, od.paid_amount, od.due_amount,
            c.customer_name, c.phone
     FROM customer_opening_dues od
     INNER JOIN customers c
        ON c.id = od.customer_id
        AND c.user_id = od.user_id
     WHERE od.user_id={$user_id}
     ORDER BY od.id DESC
     LIMIT 50"
);

?>

<section class="content-header">
    <div class="container-fluid">
        <h1>Previous Due Entry</h1>
    </div>
</section>

<section class="content">
    <div class="container-fluid">

        <?php if(isset($_GET['success'])){ ?>
            <div class="alert alert-success">
                Previous due entry saved successfully.
            </div>
        <?php } ?>

        <?php if(!empty($_SESSION['error'])){ ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($_SESSION['error']); ?>
            </div>
        <?php unset($_SESSION['error']); } ?>

        <div class="row">
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Add Previous Due</h3>
                    </div>
                    <div class="card-body">
                        <form method="post" action="opening_due_save.php">
                            <div class="form-group">
                                <label>Customer</label>
                                <select name="customer_id" class="form-control customer-select" required>
                                    <option value="">Select Customer</option>
                                    <?php while($customer = mysqli_fetch_assoc($customers)){ ?>
                                        <option value="<?= (int)$customer['id']; ?>">
                                            <?= htmlspecialchars($customer['customer_name'] . ' (Ph. ' . $customer['phone'] . ')'); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Due Amount</label>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    name="amount"
                                    class="form-control"
                                    required>
                            </div>

                            <div class="form-group">
                                <label>Entry Date</label>
                                <input
                                    type="date"
                                    name="entry_date"
                                    value="<?= htmlspecialchars(date('Y-m-d')); ?>"
                                    class="form-control"
                                    required>
                            </div>

                            <div class="form-group">
                                <label>Notes</label>
                                <textarea
                                    name="notes"
                                    class="form-control"
                                    rows="3"
                                    placeholder="Optional note"></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                Save Previous Due
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Recent Previous Due Entries</h3>
                    </div>
                    <div class="card-body">
                        <table id="example1" class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Reference</th>
                                    <th>Customer</th>
                                    <th>Total</th>
                                    <th>Paid</th>
                                    <th>Due</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = mysqli_fetch_assoc($recent_entries)){ ?>
                                    <tr>
                                        <td><?= app_date($row['entry_date']); ?></td>
                                        <td><?= htmlspecialchars($row['due_no']); ?></td>
                                        <td>
                                            <?= htmlspecialchars($row['customer_name']); ?>
                                            <br>
                                            <small class="text-muted"><?= htmlspecialchars($row['phone']); ?></small>
                                        </td>
                                        <td><?= number_format((float)$row['amount'], 2); ?></td>
                                        <td><?= number_format((float)$row['paid_amount'], 2); ?></td>
                                        <td><?= number_format((float)$row['due_amount'], 2); ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</section>

<?php require_once '../includes/footer.php'; ?>

<script>
$(function(){
    $('.customer-select').select2({
        theme: 'bootstrap4',
        width: '100%',
        placeholder: 'Select Customer',
        allowClear: true
    });
});
</script>
