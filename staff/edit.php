<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/staff_helper.php';

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
    $name = $has_transactions ? $staff['name'] : trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $designation = staff_submitted_designation();
    $salary = trim($_POST['salary'] ?? '0');

    if($designation === ''){
        $error = 'Please select or enter a designation.';
    }elseif($salary === '' || !is_numeric($salary) || (float)$salary < 0){
        $error = 'Please enter a valid salary.';
    }else{
        $salary = round((float)$salary, 2);
        create_staff_designation($conn, $user_id, $designation);
        $stmt = mysqli_prepare($conn, "UPDATE staff SET name=?,email=?,phone=?,address=?,designation=?,salary=? WHERE id=? AND user_id=?");
        mysqli_stmt_bind_param($stmt, 'sssssdii', $name, $email, $phone, $address, $designation, $salary, $id, $user_id);
        mysqli_stmt_execute($stmt);
        header('Location:index.php');
        exit;
    }
}

$designations = staff_designations($conn, $user_id);
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title">Edit Staff</h3></div><div class="card-body"><?php if($has_transactions){ ?><div class="alert alert-info">This staff has transactions, so the name cannot be changed.</div><?php } ?><?php if($error){ ?><div class="alert alert-danger"><?=htmlspecialchars($error)?></div><?php } ?><form method="post"><div class="form-group"><label>Name</label><input class="form-control" name="name" value="<?=htmlspecialchars($staff['name'])?>" required <?=$has_transactions ? 'readonly' : ''?>></div><div class="form-group"><label>Email</label><input class="form-control" name="email" type="email" value="<?=htmlspecialchars($staff['email'] ?? '')?>" placeholder="staff@example.com"></div><div class="form-group"><label>Phone</label><input class="form-control" name="phone" value="<?=htmlspecialchars($staff['phone'])?>"></div><div class="form-group"><label>Address</label><textarea class="form-control" name="address" rows="3"><?=htmlspecialchars($staff['address'] ?? '')?></textarea></div><div class="form-group"><label>Designation</label><select class="form-control" name="designation" id="designation" required><option value="">Select Designation</option><?php foreach($designations as $item){ ?><option value="<?=htmlspecialchars($item)?>" <?=($staff['designation']??'')===$item?'selected':''?>><?=htmlspecialchars($item)?></option><?php } ?><option value="__new__">+ Add New Designation</option></select><input class="form-control mt-2" name="new_designation" id="new_designation" placeholder="Enter new designation" style="display:none;"></div><div class="form-group"><label>Salary (BDT)</label><input class="form-control" name="salary" type="number" min="0" step="0.01" value="<?=htmlspecialchars($_POST['salary'] ?? $staff['salary'] ?? '0.00')?>" required></div><button class="btn btn-primary">Update Staff</button></form></div></div><script>document.getElementById('designation').addEventListener('change',function(){const newDesignation=document.getElementById('new_designation');newDesignation.style.display=this.value==='__new__'?'block':'none';newDesignation.required=this.value==='__new__';});</script><?php require_once '../includes/footer.php'; ?>
