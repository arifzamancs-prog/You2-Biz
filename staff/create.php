<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/staff_helper.php';

require_admin_user();
ensure_staff_table($conn);

$user_id = (int)$_SESSION['user_id'];
$message = '';
$message_type = 'success';

if(isset($_GET['delete_designation'])){
    delete_staff_designation($conn, $user_id, (int)$_GET['delete_designation']);
    header('Location:create.php');
    exit;
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $designation = staff_submitted_designation();

    if($designation !== ''){
        create_staff_designation($conn, $user_id, $designation);
    }

    if($name !== ''){
        $stmt = mysqli_prepare($conn, "INSERT INTO staff(user_id,name,email,phone,address,designation) VALUES(?,?,?,?,?,?)");
        mysqli_stmt_bind_param($stmt, 'isssss', $user_id, $name, $email, $phone, $address, $designation);
        mysqli_stmt_execute($stmt);
        $staff_id = mysqli_insert_id($conn);
        $staff_code = staff_code_from_id($staff_id);
        $stmt = mysqli_prepare($conn, "UPDATE staff SET staff_code=? WHERE id=? AND user_id=?");
        mysqli_stmt_bind_param($stmt, 'sii', $staff_code, $staff_id, $user_id);
        mysqli_stmt_execute($stmt);
        header('Location:index.php');
        exit;
    }
}

$designations = staff_designations($conn, $user_id);
$designation_rows = staff_designation_rows($conn, $user_id);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<div class="card">
    <div class="card-header"><h3 class="card-title">Create Staff</h3></div>
    <div class="card-body">
        <form method="post">
            <div class="form-group"><label>Name</label><input class="form-control" name="name" required></div>
            <div class="form-group"><label>Email</label><input class="form-control" name="email" type="email" placeholder="staff@example.com"></div>
            <div class="form-group"><label>Phone</label><input class="form-control" name="phone"></div>
            <div class="form-group"><label>Address</label><textarea class="form-control" name="address" rows="2"></textarea></div>
            <div class="form-group">
                <label>Designation</label>
                <select class="form-control" name="designation" id="designation">
                    <option value="">Select Designation</option>
                    <?php foreach($designations as $item){ ?>
                        <option value="<?= htmlspecialchars($item) ?>"><?= htmlspecialchars($item) ?></option>
                    <?php } ?>
                    <option value="__new__">+ Add New Designation</option>
                </select>
                <input class="form-control mt-2" name="new_designation" id="new_designation" placeholder="Enter new designation" style="display:none;">
                <small class="text-muted d-block mt-2">Default 4 designations sob company automatic pabe. Custom designation add kora jabe.</small>
            </div>
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
    document.getElementById('new_designation').style.display = this.value === '__new__' ? 'block' : 'none';
});
</script>
<?php require_once '../includes/footer.php'; ?>
