<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/contact_unique_helper.php';
require_once '../includes/input_validation_helper.php';
require_once '../includes/staff_helper.php';
require_once '../includes/lead_management_helper.php';

function ensure_customer_form_columns($conn)
{
    $customer_code_column = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'customer_code'");
    if($customer_code_column && mysqli_num_rows($customer_code_column) === 0){
        mysqli_query($conn, "ALTER TABLE customers ADD COLUMN customer_code VARCHAR(60) NULL AFTER user_id");
    }

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
}

$user_id = (int)$_SESSION['user_id'];
ensure_staff_table($conn);
ensure_customer_form_columns($conn);
ensure_lead_management_table($conn);

$message = '';
$lead_id = (int)($_POST['lead_id'] ?? $_GET['lead_id'] ?? 0);
$pending_lead = null;
$lead_ref_name = '';

if($lead_id > 0){
    $lead_stmt = mysqli_prepare(
        $conn,
        "SELECT id, name, phone, email, note, created_by_name
         FROM leads
         WHERE id=?
         AND user_id=?
         AND (
            status IN ('lead','successful','not_qualified')
            OR (status='customer' AND (converted_customer_id IS NULL OR converted_customer_id=0))
         )
         LIMIT 1"
    );
    mysqli_stmt_bind_param($lead_stmt, 'ii', $lead_id, $user_id);
    mysqli_stmt_execute($lead_stmt);
    $pending_lead = mysqli_fetch_assoc(mysqli_stmt_get_result($lead_stmt));

    if(!$pending_lead){
        $lead_id = 0;
        $message = 'This pending lead is not available for conversion.';
    } else {
        $lead_ref_name = trim((string)($pending_lead['created_by_name'] ?? ''));
    }
}

$customer_code = trim($_POST['customer_code'] ?? '');
$customer_name = trim($_POST['customer_name'] ?? ($pending_lead['name'] ?? ''));
$ref_staff_id = (int)($_POST['ref_staff_id'] ?? 0);
$phone = trim($_POST['phone'] ?? ($pending_lead['phone'] ?? ''));
$email = trim($_POST['email'] ?? ($pending_lead['email'] ?? ''));
$address = trim($_POST['address'] ?? '');
$status = $_POST['status'] ?? 'active';

if($_SERVER['REQUEST_METHOD']=='POST'){

    $duplicate_message = '';

    $customer_code = trim((string)$_POST['customer_code']);
    $customer_name = trim((string)$_POST['customer_name']);
    $ref_staff_id = (int)($_POST['ref_staff_id'] ?? 0);
    $phone = trim((string)$_POST['phone']);
    $email = trim((string)$_POST['email']);
    $address = trim((string)$_POST['address']);
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    $customer_name = normalize_person_name($customer_name);
    $phone = normalize_phone_input($phone);
    $email = normalize_email_input($email);

    if($customer_code === ''){
        $message = 'Customer ID is required.';
    } elseif(($message = validate_person_name($customer_name, 'Customer name')) !== ''){
    } elseif(($message = validate_phone_input($phone, 'Phone')) !== ''){
    } elseif(($message = validate_email_input($email, 'Email')) !== ''){
    } elseif($lead_id === 0 && $ref_staff_id > 0) {
        $staff_stmt = mysqli_prepare($conn, "SELECT id FROM staff WHERE id=? AND user_id=? AND status='active' LIMIT 1");
        mysqli_stmt_bind_param($staff_stmt, "ii", $ref_staff_id, $user_id);
        mysqli_stmt_execute($staff_stmt);
        $staff_result = mysqli_stmt_get_result($staff_stmt);
        if(!$staff_result || mysqli_num_rows($staff_result) === 0){
            $message = 'Selected Ref. Name is not valid.';
        }
    }

    if(
        $message === '' &&
        (
            contact_has_company_user_conflict($conn, 'phone', $phone, $user_id, $duplicate_message) ||
            contact_has_company_user_conflict($conn, 'email', $email, $user_id, $duplicate_message) ||
            contact_has_duplicate_in_table($conn, 'customers', 'Customer', 'phone', $phone, 0, $duplicate_message, $user_id) ||
            contact_has_duplicate_in_table($conn, 'customers', 'Customer', 'email', $email, 0, $duplicate_message, $user_id)
        )
    ){
        $message = $duplicate_message;
    }

    if($message === ''){
        $code_stmt = mysqli_prepare(
            $conn,
            "SELECT id
             FROM customers
             WHERE user_id=?
             AND customer_code=?
             LIMIT 1"
        );
        mysqli_stmt_bind_param($code_stmt, "is", $user_id, $customer_code);
        mysqli_stmt_execute($code_stmt);
        $code_result = mysqli_stmt_get_result($code_stmt);

        if($code_result && mysqli_num_rows($code_result) > 0){
            $message = 'Customer ID already exists.';
        }
    }

    if($message === ''){

        $sql = "INSERT INTO customers
                (
                    user_id,
                    customer_code,
                    customer_name,
                    ref_staff_id,
                    lead_id,
                    lead_ref_name,
                    phone,
                    email,
                    address,
                    status
                )
                VALUES
                (
                    ?,?,?,?,NULLIF(?,0),?,?,?,?,?
                )";

        $stmt = mysqli_prepare($conn,$sql);

        mysqli_stmt_bind_param(
            $stmt,
            "issiisssss",
            $user_id,
            $customer_code,
            $customer_name,
            $ref_staff_id,
            $lead_id,
            $lead_ref_name,
            $phone,
            $email,
            $address,
            $status
        );

        if(mysqli_stmt_execute($stmt)){

            $customer_id = (int)mysqli_insert_id($conn);

            if($lead_id > 0){
                $convert_stmt = mysqli_prepare(
                $conn,
                "UPDATE leads
                     SET name=?, phone=?, email=?, status='customer', converted_customer_id=?
                     WHERE id=?
                     AND user_id=?
                     AND (converted_customer_id IS NULL OR converted_customer_id=0)"
                );
                mysqli_stmt_bind_param(
                    $convert_stmt,
                    'sssiii',
                    $customer_name,
                    $phone,
                    $email,
                    $customer_id,
                    $lead_id,
                    $user_id
                );
                mysqli_stmt_execute($convert_stmt);
            }

            header("Location: index.php");
            exit;

        }else{

            $message = "Failed To Save Customer";
        }
    }
}

$staff_options_stmt = mysqli_prepare(
    $conn,
    "SELECT id, name
     FROM staff
     WHERE user_id=?
     AND status='active'
     ORDER BY name ASC"
);
mysqli_stmt_bind_param($staff_options_stmt, "i", $user_id);
mysqli_stmt_execute($staff_options_stmt);
$staff_options = mysqli_stmt_get_result($staff_options_stmt);

$customer_codes = [];
$customer_codes_stmt = mysqli_prepare(
    $conn,
    "SELECT customer_code
     FROM customers
     WHERE user_id=?
     AND customer_code IS NOT NULL
     AND TRIM(customer_code) <> ''
     ORDER BY id DESC"
);
mysqli_stmt_bind_param($customer_codes_stmt, "i", $user_id);
mysqli_stmt_execute($customer_codes_stmt);
$customer_codes_result = mysqli_stmt_get_result($customer_codes_stmt);

while($customer_codes_result && $code_row = mysqli_fetch_assoc($customer_codes_result)){
    $code = trim((string)($code_row['customer_code'] ?? ''));
    if($code !== ''){
        $customer_codes[] = $code;
    }
}

$last_customer_code = $customer_codes[0] ?? '';

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">
            <?= $lead_id > 0 ? 'Convert Pending Lead to Customer' : 'Add Customer'; ?>
        </h3>

    </div>

    <div class="card-body">

        <?php if($message){ ?>

            <div class="alert alert-danger">
                <?= htmlspecialchars($message); ?>
            </div>

        <?php } ?>

        <form method="post">

            <?php if($lead_id > 0){ ?>
                <input type="hidden" name="lead_id" value="<?= (int)$lead_id; ?>">
            <?php } ?>

            <div class="form-group">

                <label>
                    Customer ID
                </label>

                <input
                    type="text"
                    name="customer_code"
                    id="customer_code"
                    class="form-control"
                    value="<?= htmlspecialchars($customer_code); ?>"
                    required>

                <?php if($_SERVER['REQUEST_METHOD'] === 'POST' && trim($customer_code) === ''){ ?>
                    <small class="text-danger">Customer ID is required.</small>
                <?php } ?>

                <small class="text-muted d-block mt-2">
                    Last Customer ID:
                    <?= $last_customer_code !== ''
                        ? htmlspecialchars($last_customer_code)
                        : 'No customer ID created yet.'; ?>
                </small>

                <small
                    id="customer_code_status"
                    class="d-block mt-1 text-muted">
                    Type a new Customer ID to check availability.
                </small>

            </div>

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
                    value="<?= htmlspecialchars($customer_name); ?>"
                    required>

            </div>

            <div class="form-group">

                <label>
                    Ref. Name
                </label>

                <?php if($lead_id > 0){ ?>
                    <input type="hidden" name="ref_staff_id" value="0">
                    <input
                        type="text"
                        class="form-control"
                        value="<?= htmlspecialchars($lead_ref_name ?: 'General'); ?>"
                        readonly>
                    <small class="text-muted">Fixed from the staff member who created this lead.</small>
                <?php } else { ?>
                <select
                    name="ref_staff_id"
                    class="form-control">

                    <option value="">
                        Select General Staff
                    </option>

                    <?php while($staff = mysqli_fetch_assoc($staff_options)){ ?>
                        <option
                            value="<?= (int)$staff['id']; ?>"
                            <?= $ref_staff_id === (int)$staff['id'] ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($staff['name']); ?>
                        </option>
                    <?php } ?>

                </select>
                <?php } ?>

            </div>

            <div class="form-group">

                <label>
                    Phone
                </label>

                <input
                    type="text"
                    name="phone"
                    class="form-control"
                    inputmode="numeric"
                    value="<?= htmlspecialchars($phone); ?>">

            </div>

            <div class="form-group">

                <label>
                    Email
                </label>

                <input
                    type="email"
                    name="email"
                    class="form-control"
                    value="<?= htmlspecialchars($email); ?>">

            </div>

            <div class="form-group">

                <label>
                    Address
                </label>

                <textarea
                    name="address"
                    class="form-control"
                    rows="3"><?= htmlspecialchars($address); ?></textarea>

            </div>

            <div class="form-group">

                <label>
                    Status
                </label>

                <select
                    name="status"
                    class="form-control">

                    <option value="active" <?= $status === 'active' ? 'selected' : ''; ?>>
                        Active
                    </option>

                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : ''; ?>>
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
$page_script = '
<script>
$(function(){
    const existingCustomerCodes = ' . json_encode(array_values(array_map('strtolower', $customer_codes))) . ';
    const $customerCode = $("#customer_code");
    const $customerCodeStatus = $("#customer_code_status");

    function updateCustomerCodeStatus(){
        const rawValue = $.trim(String($customerCode.val() || ""));
        const normalizedValue = rawValue.toLowerCase();

        if(rawValue === ""){
            $customerCodeStatus
                .text("Customer ID is required.")
                .removeClass("text-success text-muted")
                .addClass("text-danger");
            return;
        }

        if(existingCustomerCodes.includes(normalizedValue)){
            $customerCodeStatus
                .text("This Customer ID already exists.")
                .removeClass("text-success text-muted")
                .addClass("text-danger");
            return;
        }

        $customerCodeStatus
            .text("Customer ID is available.")
            .removeClass("text-danger text-muted")
            .addClass("text-success");
    }

    $customerCode.on("input blur", updateCustomerCodeStatus);
    updateCustomerCodeStatus();
});
</script>
';

require_once '../includes/footer.php';
?>
