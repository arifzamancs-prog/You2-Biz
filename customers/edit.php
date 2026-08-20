<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/contact_unique_helper.php';

$user_id = $_SESSION['user_id'];

$id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;

$sql = "SELECT *
        FROM customers
        WHERE id=?
        AND user_id=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$customer = mysqli_fetch_assoc($result);

if(!$customer){

    die('Customer Not Found');

}

if($_SERVER['REQUEST_METHOD']=='POST'){

    $customer_name =
    trim($_POST['customer_name']);

    $phone =
    trim($_POST['phone']);

    $email =
    trim($_POST['email']);

    $address =
    trim($_POST['address']);

    $status =
    $_POST['status'];
    $duplicate_message = '';

    if(
        contact_has_company_user_conflict($conn, 'phone', $phone, $user_id, $duplicate_message) ||
        contact_has_company_user_conflict($conn, 'email', $email, $user_id, $duplicate_message) ||
        contact_has_duplicate_in_table($conn, 'customers', 'Customer', 'phone', $phone, $id, $duplicate_message, $user_id) ||
        contact_has_duplicate_in_table($conn, 'customers', 'Customer', 'email', $email, $id, $duplicate_message, $user_id)
    ){
        $message = $duplicate_message;
    }else{

    $sql = "UPDATE customers
            SET
                customer_name=?,
                phone=?,
                email=?,
                address=?,
                status=?
            WHERE id=?
            AND user_id=?";

    $stmt = mysqli_prepare(
        $conn,
        $sql
    );

    mysqli_stmt_bind_param(
        $stmt,
        "sssssii",
        $customer_name,
        $phone,
        $email,
        $address,
        $status,
        $id,
        $user_id
    );

    mysqli_stmt_execute($stmt);

    header("Location: index.php");
    exit;
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">

            Edit Customer

        </h3>

    </div>

    <div class="card-body">

        <?php if(!empty($message)){ ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($message); ?>
            </div>
        <?php } ?>

        <form method="post">

            <div class="form-group">

                <label>
                    Customer Name
                </label>

                <input
                    type="text"
                    name="customer_name"
                    class="form-control"
                    value="<?= htmlspecialchars($customer['customer_name']); ?>"
                    required>

            </div>

            <div class="form-group">

                <label>
                    Phone
                </label>

                <input
                    type="text"
                    name="phone"
                    class="form-control"
                    value="<?= htmlspecialchars($customer['phone']); ?>">

            </div>

            <div class="form-group">

                <label>
                    Email
                </label>

                <input
                    type="email"
                    name="email"
                    class="form-control"
                    value="<?= htmlspecialchars($customer['email']); ?>">

            </div>

            <div class="form-group">

                <label>
                    Address
                </label>

                <textarea
                    name="address"
                    class="form-control"><?= htmlspecialchars($customer['address']); ?></textarea>

            </div>

            <div class="form-group">

                <label>
                    Status
                </label>

                <select
                    name="status"
                    class="form-control">

                    <option
                        value="active"
                        <?= $customer['status']=='active'?'selected':''; ?>>

                        Active

                    </option>

                    <option
                        value="inactive"
                        <?= $customer['status']=='inactive'?'selected':''; ?>>

                        Inactive

                    </option>

                </select>

            </div>

            <button
                type="submit"
                class="btn btn-primary">

                Update Customer

            </button>

        </form>

    </div>

</div>

<?php
require_once '../includes/footer.php';
?>
