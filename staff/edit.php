<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/staff_helper.php';
require_once '../includes/customer_form_helper.php';

require_admin_user();
ensure_staff_table($conn);

$user_id = (int)$_SESSION['user_id'];
$id = (int)($_GET['id'] ?? 0);

$stmt = mysqli_prepare($conn, "SELECT * FROM staff WHERE id=? AND user_id=?");
mysqli_stmt_bind_param($stmt, 'ii', $id, $user_id);
mysqli_stmt_execute($stmt);
$staff = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if(!$staff){ die('Staff record not found.'); }

$has_transactions = staff_has_transactions($conn, $id);
$error = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $staff_code = trim($_POST['staff_code'] ?? '');
    $name = $has_transactions ? $staff['name'] : trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $designation = staff_submitted_designation();
    $salary = trim($_POST['salary'] ?? '0');
    $photo = $staff['photo'] ?? '';
    $upload_message = '';
    $uploaded_photo = customer_form_upload_photo($_FILES['photo'] ?? null, $user_id, 'staff_photo', $upload_message);

    if($upload_message !== ''){
        $error = $upload_message;
    }elseif($uploaded_photo !== ''){
        $photo = $uploaded_photo;
    }

    if($error !== ''){
    }elseif($staff_code === ''){
        $error = 'Staff ID is required.';
    }elseif($designation === ''){
        $error = 'Please select or enter a designation.';
    }elseif($salary === '' || !is_numeric($salary) || (float)$salary < 0){
        $error = 'Please enter a valid salary.';
    }else{
        $code_stmt = mysqli_prepare($conn, "SELECT id FROM staff WHERE user_id=? AND staff_code=? AND id<>? LIMIT 1");
        mysqli_stmt_bind_param($code_stmt, 'isi', $user_id, $staff_code, $id);
        mysqli_stmt_execute($code_stmt);
        $code_result = mysqli_stmt_get_result($code_stmt);
        if($code_result && mysqli_num_rows($code_result) > 0){
            $error = 'Staff ID already exists.';
        }else{
            $salary = round((float)$salary, 2);
            create_staff_designation($conn, $user_id, $designation);
            $stmt = mysqli_prepare($conn, "UPDATE staff SET staff_code=?,name=?,email=?,phone=?,address=?,designation=?,salary=?,photo=? WHERE id=? AND user_id=?");
            mysqli_stmt_bind_param($stmt, 'ssssssdsii', $staff_code, $name, $email, $phone, $address, $designation, $salary, $photo, $id, $user_id);
            mysqli_stmt_execute($stmt);
            header('Location:index.php');
            exit;
        }
    }
}

$designations = staff_designations($conn, $user_id);
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title">Edit Staff</h3></div><div class="card-body"><?php if($has_transactions){ ?><div class="alert alert-info">This staff has transactions, so the name cannot be changed.</div><?php } ?><?php if($error){ ?><div class="alert alert-danger"><?=htmlspecialchars($error)?></div><?php } ?><form method="post" enctype="multipart/form-data"><div class="form-group"><label>Staff ID <span class="text-danger">*</span></label><input class="form-control" name="staff_code" value="<?=htmlspecialchars($_POST['staff_code'] ?? $staff['staff_code'] ?? staff_code_from_id($staff['id']))?>" required></div><?php if(!empty($staff['photo'])){ ?><div class="form-group"><label>Current Photo</label><div><img src="../<?=htmlspecialchars($staff['photo'])?>" alt="Staff Photo" style="width:90px;height:90px;object-fit:cover;border-radius:50%;"></div></div><?php } ?><div class="form-group"><label>Update Photo</label><input class="form-control" name="photo" type="file" accept="image/jpeg,image/png,image/webp"><small class="text-muted">Max 2MB. Photo will be saved as 250x250 px.</small></div><div class="form-group"><label>Name</label><input class="form-control" name="name" value="<?=htmlspecialchars($staff['name'])?>" required <?=$has_transactions ? 'readonly' : ''?>></div><div class="form-group"><label>Email</label><input class="form-control" name="email" type="email" value="<?=htmlspecialchars($staff['email'] ?? '')?>" placeholder="staff@example.com"></div><div class="form-group"><label>Phone</label><input class="form-control" name="phone" value="<?=htmlspecialchars($staff['phone'])?>"></div><div class="form-group"><label>Address</label><textarea class="form-control" name="address" rows="3"><?=htmlspecialchars($staff['address'] ?? '')?></textarea></div><div class="form-group"><label>Designation</label><select class="form-control" name="designation" id="designation" required><option value="">Select Designation</option><?php foreach($designations as $item){ ?><option value="<?=htmlspecialchars($item)?>" <?=($staff['designation']??'')===$item?'selected':''?>><?=htmlspecialchars($item)?></option><?php } ?><option value="__new__">+ Add New Designation</option></select><input class="form-control mt-2" name="new_designation" id="new_designation" placeholder="Enter new designation" style="display:none;"></div><div class="form-group"><label>Salary (BDT)</label><input class="form-control" name="salary" type="number" min="0" step="0.01" value="<?=htmlspecialchars($_POST['salary'] ?? $staff['salary'] ?? '0.00')?>" required></div><button class="btn btn-primary">Update Staff</button></form></div></div><script>document.getElementById('designation').addEventListener('change',function(){const newDesignation=document.getElementById('new_designation');newDesignation.style.display=this.value==='__new__'?'block':'none';newDesignation.required=this.value==='__new__';});</script><?php require_once '../includes/footer.php'; ?>
