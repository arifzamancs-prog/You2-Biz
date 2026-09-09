<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/staff_helper.php';
require_once '../includes/customer_form_helper.php';

require_admin_user();
ensure_staff_table($conn);

$user_id = (int)$_SESSION['user_id'];
$message = '';
$message_type = 'success';
$staff_code = trim($_POST['staff_code'] ?? '');
$photo = '';

if(isset($_GET['delete_designation'])){
    delete_staff_designation($conn, $user_id, (int)$_GET['delete_designation']);
    header('Location:create.php');
    exit;
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $staff_code = trim($_POST['staff_code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $designation = staff_submitted_designation();
    $salary = trim($_POST['salary'] ?? '0');
    $upload_message = '';
    $photo = customer_form_upload_photo($_FILES['photo'] ?? null, $user_id, 'staff_photo', $upload_message);

    if($upload_message !== ''){
        $message = $upload_message;
        $message_type = 'danger';
    }elseif($staff_code === ''){
        $message = 'Staff ID is required.';
        $message_type = 'danger';
    }elseif($name === ''){
        $message = 'Staff name is required.';
        $message_type = 'danger';
    }elseif($designation === ''){
        $message = 'Please select or enter a designation.';
        $message_type = 'danger';
    }elseif($salary === '' || !is_numeric($salary) || (float)$salary < 0){
        $message = 'Please enter a valid salary.';
        $message_type = 'danger';
    }else{
        $code_stmt = mysqli_prepare($conn, "SELECT id FROM staff WHERE user_id=? AND staff_code=? LIMIT 1");
        mysqli_stmt_bind_param($code_stmt, 'is', $user_id, $staff_code);
        mysqli_stmt_execute($code_stmt);
        $code_result = mysqli_stmt_get_result($code_stmt);
        if($code_result && mysqli_num_rows($code_result) > 0){
            $message = 'Staff ID already exists.';
            $message_type = 'danger';
        }else{
            $salary = round((float)$salary, 2);
            create_staff_designation($conn, $user_id, $designation);
            $stmt = mysqli_prepare($conn, "INSERT INTO staff(user_id,staff_code,photo,name,email,phone,address,designation,salary) VALUES(?,?,?,?,?,?,?,?,?)");
            mysqli_stmt_bind_param($stmt, 'isssssssd', $user_id, $staff_code, $photo, $name, $email, $phone, $address, $designation, $salary);
            mysqli_stmt_execute($stmt);
            header('Location:index.php');
            exit;
        }
    }
}

$designations = staff_designations($conn, $user_id);
$designation_rows = staff_designation_rows($conn, $user_id);
$staff_codes = [];
$staff_codes_stmt = mysqli_prepare(
    $conn,
    "SELECT staff_code
     FROM staff
     WHERE user_id=?
     AND staff_code IS NOT NULL
     AND TRIM(staff_code) <> ''
     ORDER BY id DESC"
);
mysqli_stmt_bind_param($staff_codes_stmt, 'i', $user_id);
mysqli_stmt_execute($staff_codes_stmt);
$staff_codes_result = mysqli_stmt_get_result($staff_codes_stmt);
while($staff_codes_result && $code_row = mysqli_fetch_assoc($staff_codes_result)){
    $code = trim((string)($code_row['staff_code'] ?? ''));
    if($code !== ''){
        $staff_codes[] = $code;
    }
}
$last_staff_code = $staff_codes[0] ?? '';

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<div class="card">
    <div class="card-header"><h3 class="card-title">Create Staff</h3></div>
    <div class="card-body">
        <?php if($message !== ''){ ?><div class="alert alert-<?= htmlspecialchars($message_type); ?>"><?= htmlspecialchars($message); ?></div><?php } ?>
        <form method="post" enctype="multipart/form-data">
            <div class="form-group">
                <label>Staff ID <span class="text-danger">*</span></label>
                <input class="form-control" name="staff_code" id="staff_code" value="<?= htmlspecialchars($staff_code); ?>" required>
                <small class="text-muted d-block mt-2">
                    Last Staff ID:
                    <?= $last_staff_code !== '' ? htmlspecialchars($last_staff_code) : 'No staff ID created yet.'; ?>
                </small>
                <small id="staff_code_status" class="d-block mt-1 text-muted">Type a new Staff ID to check availability.</small>
            </div>
            <div class="form-group"><label>Photo</label><input class="form-control" name="photo" type="file" accept="image/jpeg,image/png,image/webp"><small class="text-muted">Max 2MB. Photo will be saved as 250x250 px.</small></div>
            <div class="form-group"><label>Name</label><input class="form-control" name="name" value="<?= htmlspecialchars($_POST['name'] ?? ''); ?>" required></div>
            <div class="form-group"><label>Email</label><input class="form-control" name="email" type="email" placeholder="staff@example.com" value="<?= htmlspecialchars($_POST['email'] ?? ''); ?>"></div>
            <div class="form-group"><label>Phone</label><input class="form-control" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? ''); ?>"></div>
            <div class="form-group"><label>Address</label><textarea class="form-control" name="address" rows="2"><?= htmlspecialchars($_POST['address'] ?? ''); ?></textarea></div>
            <div class="form-group">
                <label>Designation</label>
                <select class="form-control" name="designation" id="designation" required>
                    <option value="">Select Designation</option>
                    <?php foreach($designations as $item){ ?>
                        <option value="<?= htmlspecialchars($item) ?>"><?= htmlspecialchars($item) ?></option>
                    <?php } ?>
                    <option value="__new__">+ Add New Designation</option>
                </select>
                <input class="form-control mt-2" name="new_designation" id="new_designation" placeholder="Enter new designation" style="display:none;">
                <small class="text-muted d-block mt-2">Default 4 designations sob company automatic pabe. Custom designation add kora jabe.</small>
            </div>
            <div class="form-group"><label>Salary (BDT)</label><input class="form-control" name="salary" type="number" min="0" step="0.01" value="<?= htmlspecialchars($_POST['salary'] ?? '0.00') ?>" required></div>
            <button class="btn btn-primary">Save Staff</button>
            <a href="index.php" class="btn btn-secondary">Back</a>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3 class="card-title">Designation List</h3></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Designation</th>
                        <th>Type</th>
                        <th width="160">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($designation_rows as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['designation_name']); ?></td>
                            <td><?= (int)$row['is_default'] === 1 ? 'Default' : 'Custom'; ?></td>
                            <td>
                                <?php if((int)$row['is_default'] === 1): ?>
                                    <span class="text-muted small">Fixed</span>
                                <?php elseif($row['can_delete']): ?>
                                    <a class="btn btn-danger btn-sm" href="create.php?delete_designation=<?= (int)$row['id']; ?>" onclick="return confirm('Delete this designation?')">Delete</a>
                                <?php else: ?>
                                    <span class="text-muted small">In use</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.getElementById('designation').addEventListener('change', function () {
    const newDesignation = document.getElementById('new_designation');
    newDesignation.style.display = this.value === '__new__' ? 'block' : 'none';
    newDesignation.required = this.value === '__new__';
});

const existingStaffCodes = <?= json_encode(array_values(array_map('strtolower', $staff_codes))); ?>;
const staffCode = document.getElementById('staff_code');
const staffCodeStatus = document.getElementById('staff_code_status');

function updateStaffCodeStatus() {
    const rawValue = String(staffCode.value || '').trim();
    const normalizedValue = rawValue.toLowerCase();
    if(rawValue === '') {
        staffCodeStatus.textContent = 'Staff ID is required.';
        staffCodeStatus.className = 'd-block mt-1 text-danger';
        return;
    }
    if(existingStaffCodes.includes(normalizedValue)) {
        staffCodeStatus.textContent = 'This Staff ID already exists.';
        staffCodeStatus.className = 'd-block mt-1 text-danger';
        return;
    }
    staffCodeStatus.textContent = 'Staff ID is available.';
    staffCodeStatus.className = 'd-block mt-1 text-success';
}

staffCode.addEventListener('input', updateStaffCodeStatus);
staffCode.addEventListener('blur', updateStaffCodeStatus);
updateStaffCodeStatus();
</script>
<?php require_once '../includes/footer.php'; ?>
