<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/contact_unique_helper.php';
require_once '../includes/input_validation_helper.php';
require_once '../includes/staff_helper.php';
require_once '../includes/lead_management_helper.php';
require_once '../includes/customer_form_helper.php';

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
customer_form_ensure_schema($conn);

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
$customer_form_fields = customer_form_get_fields($conn, $user_id);
$customer_custom_fields = array_values(array_filter($customer_form_fields, function($field){
    return empty($field['is_system']);
}));
$customer_extra_values = [];

foreach($customer_custom_fields as $custom_field){
    $field_key = (string)$custom_field['field_key'];
    $customer_extra_values[$field_key] = '';
}

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
    foreach($customer_custom_fields as $custom_field){
        $field_key = (string)$custom_field['field_key'];
        if($custom_field['field_type'] === 'photo'){
            $upload_message = '';
            $customer_extra_values[$field_key] = customer_form_upload_photo(
                $_FILES['custom_fields']['name'][$field_key] ?? null
                    ? [
                        'name' => $_FILES['custom_fields']['name'][$field_key],
                        'type' => $_FILES['custom_fields']['type'][$field_key],
                        'tmp_name' => $_FILES['custom_fields']['tmp_name'][$field_key],
                        'error' => $_FILES['custom_fields']['error'][$field_key],
                        'size' => $_FILES['custom_fields']['size'][$field_key],
                    ]
                    : null,
                $user_id,
                $field_key,
                $upload_message
            );
            if($upload_message !== ''){
                $message = $upload_message;
                break;
            }
            continue;
        }

        if(!customer_form_field_has_input($custom_field)){
            continue;
        }

        $field_value = trim((string)($_POST['custom_fields'][$field_key] ?? ''));
        if($custom_field['field_type'] === 'calendar'){
            $field_value = customer_form_normalize_calendar_value($field_value);
        }
        $customer_extra_values[$field_key] = $field_value;
    }

    if($message !== ''){
    } elseif($customer_code === ''){
        $message = 'Customer ID is required.';
    } elseif(($message = validate_person_name($customer_name, 'Customer name')) !== ''){
    } elseif(($message = validate_phone_input($phone, 'Phone')) !== ''){
    } elseif(($message = validate_email_input($email, 'Email')) !== ''){
    } elseif($address === ''){
        $message = 'Address is required.';
    } elseif($lead_id === 0 && $ref_staff_id <= 0) {
        $message = 'Ref. Name is required.';
    } elseif($lead_id === 0 && $ref_staff_id > 0) {
        $staff_stmt = mysqli_prepare($conn, "SELECT id FROM staff WHERE id=? AND user_id=? AND status='active' LIMIT 1");
        mysqli_stmt_bind_param($staff_stmt, "ii", $ref_staff_id, $user_id);
        mysqli_stmt_execute($staff_stmt);
        $staff_result = mysqli_stmt_get_result($staff_stmt);
        if(!$staff_result || mysqli_num_rows($staff_result) === 0){
            $message = 'Selected Ref. Name is not valid.';
        }
    }

    if($message === ''){
        foreach($customer_custom_fields as $custom_field){
            $field_key = (string)$custom_field['field_key'];
            $field_value = $customer_extra_values[$field_key] ?? '';

            if(!customer_form_field_has_input($custom_field)){
                continue;
            }

            if(!empty($custom_field['is_required']) && $field_value === ''){
                $message = $custom_field['label'] . ' is required.';
                break;
            }

            if($field_value !== '' && $custom_field['field_type'] === 'dropdown'){
                $allowed_options = array_filter(
                    array_map('trim', preg_split('/\r\n|\r|\n/', (string)$custom_field['options']))
                );

                if(!in_array($field_value, $allowed_options, true)){
                    $message = 'Selected ' . $custom_field['label'] . ' is not valid.';
                    break;
                }
            }

            if($field_value !== '' && $custom_field['field_type'] === 'calendar' && !customer_form_is_valid_calendar_value($field_value)){
                $message = $custom_field['label'] . ' must be dd/mm/yyyy format.';
                break;
            }
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
                    extra_data,
                    status
                )
                VALUES
                (
                    ?,?,?,?,NULLIF(?,0),?,?,?,?,?,?
                )";

        $stmt = mysqli_prepare($conn,$sql);
        $extra_data_json = json_encode($customer_extra_values, JSON_UNESCAPED_UNICODE);

        mysqli_stmt_bind_param(
            $stmt,
            "issiissssss",
            $user_id,
            $customer_code,
            $customer_name,
            $ref_staff_id,
            $lead_id,
            $lead_ref_name,
            $phone,
            $email,
            $address,
            $extra_data_json,
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

        <form method="post" enctype="multipart/form-data">

            <?php if($lead_id > 0){ ?>
                <input type="hidden" name="lead_id" value="<?= (int)$lead_id; ?>">
            <?php } ?>

            <?php foreach($customer_form_fields as $custom_field){ ?>
                <?php
                $field_key = (string)$custom_field['field_key'];
                $field_value = $customer_extra_values[$field_key] ?? '';
                ?>
                <?php if($field_key === 'customer_code'){ ?>
                    <div class="form-group">
                        <label>
                            <?= htmlspecialchars($custom_field['label']); ?>
                            <?php if(!empty($custom_field['is_required'])){ ?><span class="text-danger">*</span><?php } ?>
                        </label>
                        <input type="text" name="customer_code" id="customer_code" class="form-control" value="<?= htmlspecialchars($customer_code); ?>" required>
                        <?php if($_SERVER['REQUEST_METHOD'] === 'POST' && trim($customer_code) === ''){ ?>
                            <small class="text-danger">Customer ID is required.</small>
                        <?php } ?>
                        <small class="text-muted d-block mt-2">
                            Last Customer ID:
                            <?= $last_customer_code !== '' ? htmlspecialchars($last_customer_code) : 'No customer ID created yet.'; ?>
                        </small>
                        <small id="customer_code_status" class="d-block mt-1 text-muted">Type a new Customer ID to check availability.</small>
                    </div>
                <?php } elseif($field_key === 'customer_name'){ ?>
                    <div class="form-group">
                        <label>
                            <?= htmlspecialchars($custom_field['label']); ?>
                            <?php if(!empty($custom_field['is_required'])){ ?><span class="text-danger">*</span><?php } ?>
                        </label>
                        <input type="text" name="customer_name" class="form-control" minlength="2" pattern=".*[A-Za-z].*" value="<?= htmlspecialchars($customer_name); ?>" required>
                    </div>
                <?php } elseif($field_key === 'ref_staff_id'){ ?>
                    <div class="form-group">
                        <label>
                            <?= htmlspecialchars($custom_field['label']); ?>
                            <?php if(!empty($custom_field['is_required'])){ ?><span class="text-danger">*</span><?php } ?>
                        </label>
                        <?php if($lead_id > 0){ ?>
                            <input type="hidden" name="ref_staff_id" value="0">
                            <input type="text" class="form-control" value="<?= htmlspecialchars($lead_ref_name ?: 'General'); ?>" readonly>
                            <small class="text-muted">Fixed from the staff member who created this lead.</small>
                        <?php } else { ?>
                            <select name="ref_staff_id" class="form-control" required>
                                <option value="">Select General Staff</option>
                                <?php mysqli_data_seek($staff_options, 0); ?>
                                <?php while($staff = mysqli_fetch_assoc($staff_options)){ ?>
                                    <option value="<?= (int)$staff['id']; ?>" <?= $ref_staff_id === (int)$staff['id'] ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($staff['name']); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        <?php } ?>
                    </div>
                <?php } elseif($field_key === 'phone'){ ?>
                    <div class="form-group">
                        <label>
                            <?= htmlspecialchars($custom_field['label']); ?>
                            <?php if(!empty($custom_field['is_required'])){ ?><span class="text-danger">*</span><?php } ?>
                        </label>
                        <input type="text" name="phone" class="form-control" inputmode="numeric" value="<?= htmlspecialchars($phone); ?>" required>
                    </div>
                <?php } elseif($field_key === 'email'){ ?>
                    <div class="form-group">
                        <label>
                            <?= htmlspecialchars($custom_field['label']); ?>
                            <?php if(!empty($custom_field['is_required'])){ ?><span class="text-danger">*</span><?php } ?>
                        </label>
                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($email); ?>" required>
                    </div>
                <?php } elseif($field_key === 'address'){ ?>
                    <div class="form-group">
                        <label>
                            <?= htmlspecialchars($custom_field['label']); ?>
                            <?php if(!empty($custom_field['is_required'])){ ?><span class="text-danger">*</span><?php } ?>
                        </label>
                        <textarea name="address" class="form-control" rows="3" required><?= htmlspecialchars($address); ?></textarea>
                    </div>
                <?php } elseif($field_key === 'status'){ ?>
                    <div class="form-group">
                        <label>
                            <?= htmlspecialchars($custom_field['label']); ?>
                            <?php if(!empty($custom_field['is_required'])){ ?><span class="text-danger">*</span><?php } ?>
                        </label>
                        <select name="status" class="form-control" required>
                            <option value="active" <?= $status === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?= $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                <?php } else { ?>
                    <div class="form-group">
                        <label>
                            <?= htmlspecialchars($custom_field['label']); ?>
                            <?php if(!empty($custom_field['is_required'])){ ?><span class="text-danger">*</span><?php } ?>
                        </label>
                        <?php if($custom_field['field_type'] === 'dropdown'){ ?>
                            <select name="custom_fields[<?= htmlspecialchars($field_key); ?>]" class="form-control" <?= !empty($custom_field['is_required']) ? 'required' : ''; ?>>
                                <option value="">Select <?= htmlspecialchars($custom_field['label']); ?></option>
                                <?php $custom_options = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$custom_field['options']))); ?>
                                <?php foreach($custom_options as $option){ ?>
                                    <option value="<?= htmlspecialchars($option); ?>" <?= $field_value === $option ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($option); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        <?php } elseif($custom_field['field_type'] === 'photo'){ ?>
                            <input
                                type="file"
                                name="custom_fields[<?= htmlspecialchars($field_key); ?>]"
                                class="form-control"
                                accept="image/jpeg,image/png,image/webp"
                                <?= !empty($custom_field['is_required']) ? 'required' : ''; ?>>
                            <small class="text-muted">Max 2MB. Photo will be saved as 250x250 px.</small>
                        <?php } elseif($custom_field['field_type'] === 'calendar'){ ?>
                            <?php $calendar_picker_id = 'customer_calendar_' . preg_replace('/[^a-z0-9_]/i', '_', $field_key); ?>
                            <div class="input-group date customer-calendar-picker" id="<?= htmlspecialchars($calendar_picker_id); ?>" data-target-input="nearest">
                                <input
                                    type="text"
                                    name="custom_fields[<?= htmlspecialchars($field_key); ?>]"
                                    class="form-control datetimepicker-input"
                                    data-target="#<?= htmlspecialchars($calendar_picker_id); ?>"
                                    placeholder="dd/mm/yyyy"
                                    value="<?= htmlspecialchars(customer_form_normalize_calendar_value($field_value)); ?>"
                                    <?= !empty($custom_field['is_required']) ? 'required' : ''; ?>>
                                <div class="input-group-append" data-target="#<?= htmlspecialchars($calendar_picker_id); ?>" data-toggle="datetimepicker">
                                    <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                                </div>
                            </div>
                        <?php } elseif($custom_field['field_type'] === 'ruler'){ ?>
                            <hr>
                        <?php } elseif($custom_field['field_type'] === 'label'){ ?>
                            <div class="alert alert-light border mb-0">
                                <?= nl2br(htmlspecialchars($custom_field['options'] ?: $custom_field['label'])); ?>
                            </div>
                        <?php } else { ?>
                            <input type="text" name="custom_fields[<?= htmlspecialchars($field_key); ?>]" class="form-control" value="<?= htmlspecialchars($field_value); ?>" <?= !empty($custom_field['is_required']) ? 'required' : ''; ?>>
                        <?php } ?>
                    </div>
                <?php } ?>
            <?php } ?>

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
<style>
.customer-calendar-picker .datetimepicker-input{
    border-right:0;
    font-weight:500;
}
.customer-calendar-picker .input-group-text{
    background:linear-gradient(135deg,#0d6efd,#0b5ed7);
    border-color:#0d6efd;
    color:#fff;
    cursor:pointer;
    min-width:52px;
    justify-content:center;
    transition:all .18s ease;
}
.customer-calendar-picker .input-group-text:hover{
    background:linear-gradient(135deg,#0b5ed7,#084298);
    box-shadow:0 8px 18px rgba(13,110,253,.22);
}
.bootstrap-datetimepicker-widget.dropdown-menu{
    border:0;
    border-radius:14px;
    box-shadow:0 18px 45px rgba(15,23,42,.22);
    overflow:hidden;
    padding:10px;
}
.bootstrap-datetimepicker-widget table th{
    border-radius:8px;
    color:#1f2937;
    font-weight:800;
}
.bootstrap-datetimepicker-widget table th.picker-switch{
    color:#0d6efd;
    font-size:17px;
    letter-spacing:.2px;
}
.bootstrap-datetimepicker-widget table td{
    border-radius:9px;
    height:36px;
    line-height:36px;
    width:36px;
}
.bootstrap-datetimepicker-widget table td.day:hover,
.bootstrap-datetimepicker-widget table td span:hover,
.bootstrap-datetimepicker-widget table th.prev:hover,
.bootstrap-datetimepicker-widget table th.next:hover{
    background:#eaf3ff;
    color:#0d6efd;
}
.bootstrap-datetimepicker-widget table td.today:before{
    border-bottom-color:#0d6efd;
}
.bootstrap-datetimepicker-widget table td.active,
.bootstrap-datetimepicker-widget table td.active:hover{
    background:linear-gradient(135deg,#0d6efd,#2563eb);
    color:#fff;
    text-shadow:none;
    box-shadow:0 7px 16px rgba(37,99,235,.28);
}
.bootstrap-datetimepicker-widget table td.old,
.bootstrap-datetimepicker-widget table td.new{
    color:#a0aec0;
}
.bootstrap-datetimepicker-widget .picker-switch.accordion-toggle table td{
    padding-top:8px;
}
.bootstrap-datetimepicker-widget .picker-switch.accordion-toggle a{
    color:#0d6efd;
}
</style>
<script src="../adminlte/plugins/moment/moment.min.js"></script>
<script src="../adminlte/plugins/tempusdominus-bootstrap-4/js/tempusdominus-bootstrap-4.min.js"></script>
<script>
$(function(){
    $(".customer-calendar-picker").datetimepicker({
        format:"DD/MM/YYYY",
        useCurrent:false,
        buttons:{showToday:true,showClear:true,showClose:true},
        widgetPositioning:{horizontal:"auto",vertical:"bottom"},
        icons:{time:"far fa-clock",date:"far fa-calendar",up:"fas fa-arrow-up",down:"fas fa-arrow-down",previous:"fas fa-chevron-left",next:"fas fa-chevron-right",today:"far fa-calendar-check",clear:"far fa-trash-alt",close:"far fa-times-circle"}
    });

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
