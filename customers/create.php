<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/contact_unique_helper.php';
require_once '../includes/input_validation_helper.php';

$user_id = $_SESSION['user_id'];

$message = '';

if($_SERVER['REQUEST_METHOD']=='POST'){

    $customer_name = trim($_POST['customer_name']);
    $phone         = trim($_POST['phone']);
    $email         = trim($_POST['email']);
    $address       = trim($_POST['address']);
    $status        = $_POST['status'];
    $duplicate_message = '';

    $customer_name = normalize_person_name($customer_name);
    $phone = normalize_phone_input($phone);
    $email = normalize_email_input($email);

    if(($message = validate_person_name($customer_name, 'Customer name')) !== ''){
    }elseif(($message = validate_phone_input($phone, 'Phone')) !== ''){
    }elseif(($message = validate_email_input($email, 'Email')) !== ''){
    }elseif(
        contact_has_company_user_conflict($conn, 'phone', $phone, $user_id, $duplicate_message) ||
        contact_has_company_user_conflict($conn, 'email', $email, $user_id, $duplicate_message) ||
        contact_has_duplicate_in_table($conn, 'customers', 'Customer', 'phone', $phone, 0, $duplicate_message, $user_id) ||
        contact_has_duplicate_in_table($conn, 'customers', 'Customer', 'email', $email, 0, $duplicate_message, $user_id)
    ){
        $message = $duplicate_message;
    }else{

    $sql = "INSERT INTO customers
            (
                user_id,
                customer_name,
                phone,
                email,
                address,
                status
            )
            VALUES
            (
                ?,?,?,?,?,?
            )";

    $stmt = mysqli_prepare($conn,$sql);

    mysqli_stmt_bind_param(
        $stmt,
        "isssss",
        $user_id,
        $customer_name,
        $phone,
        $email,
        $address,
        $status
    );

    if(mysqli_stmt_execute($stmt)){

        header("Location: index.php");
        exit;

    }else{

        $message = "Failed To Save Customer";
    }
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">

            Add Customer

        </h3>

    </div>

    <div class="card-body">

        <?php if($message){ ?>

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
                    minlength="2"
                    pattern=".*[A-Za-z].*"
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
                    inputmode="numeric">

            </div>

            <div class="form-group">

                <label>
                    Email
                </label>

                <input
                    type="email"
                    name="email"
                    class="form-control">

            </div>

            <div class="form-group">

                <label>
                    Address
                </label>

                <textarea
                    name="address"
                    class="form-control"
                    rows="3"></textarea>

            </div>

            <div class="form-group">

                <label>
                    Status
                </label>

                <select
                    name="status"
                    class="form-control">

                    <option value="active">
                        Active
                    </option>

                    <option value="inactive">
                        Inactive
                    </option>

                </select>

            </div>

            <button
                type="submit"
                class="btn btn-primary">

                <i class="fas fa-save"></i>

                Save Customer

            </button>

            <a
                href="index.php"
                class="btn btn-secondary">

                Back

            </a>

        </form>

    </div>

</div>

<?php
require_once '../includes/footer.php';
?>
