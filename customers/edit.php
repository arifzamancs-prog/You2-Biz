<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/contact_unique_helper.php';
require_once '../includes/customer_form_helper.php';
require_once '../includes/staff_helper.php';

$user_id = $_SESSION['user_id'];
ensure_staff_table($conn);
customer_form_ensure_schema($conn);

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

$customer_form_fields = customer_form_get_fields($conn, (int)$user_id);
$customer_custom_fields = array_values(array_filter($customer_form_fields, function($field){
    return empty($field['is_system']);
}));
$customer_extra_values = customer_form_decode_extra($customer['extra_data'] ?? '');

if($_SERVER['REQUEST_METHOD']=='POST'){

    $customer_code =
    trim($_POST['customer_code'] ?? '');

    $customer_name =
    trim($_POST['customer_name']);

    $ref_staff_id =
    (int)($_POST['ref_staff_id'] ?? 0);

    $phone =
    trim($_POST['phone']);

    $email =
    trim($_POST['email']);

    $address =
    trim($_POST['address']);

    $status =
    $_POST['status'];
    $duplicate_message = '';

    if($customer_code === ''){
        $message = 'Customer ID is required.';
    } elseif($customer_name === ''){
        $message = 'Customer Name is required.';
    } elseif($ref_staff_id <= 0){
        $message = 'Ref. Name is required.';
    } elseif($phone === ''){
        $message = 'Phone is required.';
    } elseif($email === ''){
        $message = 'Email is required.';
    } elseif($address === ''){
        $message = 'Address is required.';
    }

if(empty($message)){
        foreach($customer_custom_fields as $custom_field){
            $field_key = (string)$custom_field['field_key'];
            if($custom_field['field_type'] === 'photo'){
                $upload_message = '';
                $uploaded_photo = customer_form_upload_photo(
                    $_FILES['custom_fields']['name'][$field_key] ?? null
                        ? [
                            'name' => $_FILES['custom_fields']['name'][$field_key],
                            'type' => $_FILES['custom_fields']['type'][$field_key],
                            'tmp_name' => $_FILES['custom_fields']['tmp_name'][$field_key],
                            'error' => $_FILES['custom_fields']['error'][$field_key],
                            'size' => $_FILES['custom_fields']['size'][$field_key],
                        ]
                        : null,
                    (int)$user_id,
                    $field_key,
                    $upload_message
                );
                if($upload_message !== ''){
                    $message = $upload_message;
                    break;
                }
                if($uploaded_photo !== ''){
                    $customer_extra_values[$field_key] = $uploaded_photo;
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
        empty($message) &&
        (
        contact_has_company_user_conflict($conn, 'phone', $phone, $user_id, $duplicate_message) ||
        contact_has_company_user_conflict($conn, 'email', $email, $user_id, $duplicate_message) ||
        contact_has_duplicate_in_table($conn, 'customers', 'Customer', 'phone', $phone, $id, $duplicate_message, $user_id) ||
        contact_has_duplicate_in_table($conn, 'customers', 'Customer', 'email', $email, $id, $duplicate_message, $user_id)
        )
    ){
        $message = $duplicate_message;
    }elseif(empty($message)){
        $code_stmt = mysqli_prepare(
            $conn,
            "SELECT id FROM customers WHERE user_id=? AND customer_code=? AND id<>? LIMIT 1"
        );
        mysqli_stmt_bind_param($code_stmt, 'isi', $user_id, $customer_code, $id);
        mysqli_stmt_execute($code_stmt);
        $code_result = mysqli_stmt_get_result($code_stmt);

        if($code_result && mysqli_num_rows($code_result) > 0){
            $message = 'Customer ID already exists.';
        }
    }

    if(empty($message)){

    $sql = "UPDATE customers
            SET
                customer_code=?,
                customer_name=?,
                ref_staff_id=?,
                phone=?,
                email=?,
                address=?,
                extra_data=?,
                status=?
            WHERE id=?
            AND user_id=?";

    $stmt = mysqli_prepare(
        $conn,
        $sql
    );
    $extra_data_json = json_encode($customer_extra_values, JSON_UNESCAPED_UNICODE);

    mysqli_stmt_bind_param(
        $stmt,
        "ssisssssii",
        $customer_code,
        $customer_name,
        $ref_staff_id,
        $phone,
        $email,
        $address,
        $extra_data_json,
        $status,
        $id,
        $user_id
    );

    mysqli_stmt_execute($stmt);

    header("Location: index.php");
    exit;
    }
}

$staff_options_stmt = mysqli_prepare(
    $conn,
    "SELECT id, name FROM staff WHERE user_id=? AND status='active' ORDER BY name ASC"
);
mysqli_stmt_bind_param($staff_options_stmt, 'i', $user_id);
mysqli_stmt_execute($staff_options_stmt);
$staff_options = mysqli_stmt_get_result($staff_options_stmt);

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

        <form method="post" enctype="multipart/form-data">

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
                        <input type="text" name="customer_code" class="form-control" value="<?= htmlspecialchars($_POST['customer_code'] ?? ($customer['customer_code'] ?? '')); ?>" required>
                    </div>
                <?php } elseif($field_key === 'customer_name'){ ?>
                    <div class="form-group">
                        <label>
                            <?= htmlspecialchars($custom_field['label']); ?>
                            <?php if(!empty($custom_field['is_required'])){ ?><span class="text-danger">*</span><?php } ?>
                        </label>
                        <input type="text" name="customer_name" class="form-control" value="<?= htmlspecialchars($_POST['customer_name'] ?? $customer['customer_name']); ?>" required>
                    </div>
                <?php } elseif($field_key === 'ref_staff_id'){ ?>
                    <div class="form-group">
                        <label>
                            <?= htmlspecialchars($custom_field['label']); ?>
                            <?php if(!empty($custom_field['is_required'])){ ?><span class="text-danger">*</span><?php } ?>
                        </label>
                        <select name="ref_staff_id" class="form-control" required>
                            <option value="">Select Ref. Name</option>
                            <?php
                            $selected_ref_staff_id = (int)($_POST['ref_staff_id'] ?? ($customer['ref_staff_id'] ?? 0));
                            if($staff_options){
                                mysqli_data_seek($staff_options, 0);
                            }
                            ?>
                            <?php while($staff_options && $staff = mysqli_fetch_assoc($staff_options)){ ?>
                                <option value="<?= (int)$staff['id']; ?>" <?= $selected_ref_staff_id === (int)$staff['id'] ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($staff['name']); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                <?php } elseif($field_key === 'phone'){ ?>
                    <div class="form-group">
                        <label>
                            <?= htmlspecialchars($custom_field['label']); ?>
                            <?php if(!empty($custom_field['is_required'])){ ?><span class="text-danger">*</span><?php } ?>
                        </label>
                        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($_POST['phone'] ?? $customer['phone']); ?>" required>
                    </div>
                <?php } elseif($field_key === 'email'){ ?>
                    <div class="form-group">
                        <label>
                            <?= htmlspecialchars($custom_field['label']); ?>
                            <?php if(!empty($custom_field['is_required'])){ ?><span class="text-danger">*</span><?php } ?>
                        </label>
                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? $customer['email']); ?>" required>
                    </div>
                <?php } elseif($field_key === 'address'){ ?>
                    <div class="form-group">
                        <label>
                            <?= htmlspecialchars($custom_field['label']); ?>
                            <?php if(!empty($custom_field['is_required'])){ ?><span class="text-danger">*</span><?php } ?>
                        </label>
                        <textarea name="address" class="form-control" required><?= htmlspecialchars($_POST['address'] ?? $customer['address']); ?></textarea>
                    </div>
                <?php } elseif($field_key === 'status'){ ?>
                    <div class="form-group">
                        <label>
                            <?= htmlspecialchars($custom_field['label']); ?>
                            <?php if(!empty($custom_field['is_required'])){ ?><span class="text-danger">*</span><?php } ?>
                        </label>
                        <select name="status" class="form-control" required>
                            <?php $selected_status = $_POST['status'] ?? $customer['status']; ?>
                            <option value="active" <?= $selected_status=='active'?'selected':''; ?>>Active</option>
                            <option value="inactive" <?= $selected_status=='inactive'?'selected':''; ?>>Inactive</option>
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
                            <?php if($field_value !== ''){ ?>
                                <div class="mb-2">
                                    <img
                                        src="../<?= htmlspecialchars($field_value); ?>"
                                        alt="<?= htmlspecialchars($custom_field['label']); ?>"
                                        style="width:70px;height:70px;object-fit:cover;border-radius:6px;border:1px solid #d1d5db;">
                                </div>
                            <?php } ?>
                            <input
                                type="file"
                                name="custom_fields[<?= htmlspecialchars($field_key); ?>]"
                                class="form-control"
                                accept="image/jpeg,image/png,image/webp"
                                <?= !empty($custom_field['is_required']) && $field_value === '' ? 'required' : ''; ?>>
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

                Update Customer

            </button>

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
});
</script>
';

require_once '../includes/footer.php';
?>
