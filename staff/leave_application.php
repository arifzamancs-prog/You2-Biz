<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/staff_attendance_helper.php';

if(!is_manager_user() || current_manager_staff_id($conn) <= 0){
    header('Location: ' . app_path('dashboard.php?error=Leave application is available for staff accounts only'));
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$staff_id = (int)current_manager_staff_id($conn);
$login_user_id = (int)($_SESSION['login_user_id'] ?? 0);
ensure_staff_attendance_tables($conn);
$error = '';
$editing_application = null;

if(!empty($_GET['edit'])){
    $edit_id = (int)$_GET['edit'];
    $edit_stmt = mysqli_prepare($conn, "SELECT * FROM staff_leave_applications WHERE id=? AND user_id=? AND staff_id=? AND status='rejected' LIMIT 1");
    mysqli_stmt_bind_param($edit_stmt, 'iii', $edit_id, $user_id, $staff_id);
    mysqli_stmt_execute($edit_stmt);
    $editing_application = mysqli_fetch_assoc(mysqli_stmt_get_result($edit_stmt)) ?: null;
}

function leave_application_compress_photo($upload, $target_path){
    if(empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) return 'Please select an application photo.';
    $image_info = @getimagesize($upload['tmp_name']);
    if(!$image_info || !in_array($image_info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) return 'Only JPG, PNG or WEBP photos are allowed.';
    // Some shared servers do not enable PHP GD. The browser compresses images
    // before upload in that case; retain the strict 50 KB server-side limit.
    if(!function_exists('imagecreatetruecolor')){
        if((int)filesize($upload['tmp_name']) > 50 * 1024) return 'Photo must be 50 KB or smaller.';
        return move_uploaded_file($upload['tmp_name'], $target_path) ? '' : 'Application photo could not be saved.';
    }
    $source = $image_info[2] === IMAGETYPE_JPEG ? @imagecreatefromjpeg($upload['tmp_name']) : ($image_info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($upload['tmp_name']) : @imagecreatefromwebp($upload['tmp_name']));
    if(!$source) return 'Application photo could not be processed.';
    $source_width = imagesx($source); $source_height = imagesy($source);
    $max_bytes = 50 * 1024;
    $saved = false;
    foreach([1400, 1100, 900, 700, 550, 420, 320] as $max_dimension){
        $scale = min(1, $max_dimension / max($source_width, $source_height));
        $width = max(1, (int)round($source_width * $scale)); $height = max(1, (int)round($source_height * $scale));
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, $source_width, $source_height);
        foreach([80, 65, 50, 35] as $quality){
            imagejpeg($canvas, $target_path, $quality);
            if(filesize($target_path) <= $max_bytes){ $saved = true; break 2; }
        }
        imagedestroy($canvas);
    }
    imagedestroy($source);
    if(!$saved){ @unlink($target_path); return 'Photo could not be compressed below 50 KB. Please use a simpler image.'; }
    return '';
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $application_date = trim($_POST['application_date'] ?? '');
    $leave_type = trim($_POST['leave_type'] ?? '');
    $application_id = (int)($_POST['application_id'] ?? 0);
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $application_date) || !in_array($leave_type, ['late','absent','casual_leave','medical_leave'], true)){
        $error = 'Select a valid date and application type.';
    }elseif($application_id > 0){
        $existing_stmt = mysqli_prepare($conn, "SELECT * FROM staff_leave_applications WHERE id=? AND user_id=? AND staff_id=? AND status='rejected' LIMIT 1");
        mysqli_stmt_bind_param($existing_stmt, 'iii', $application_id, $user_id, $staff_id);
        mysqli_stmt_execute($existing_stmt);
        $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($existing_stmt));
        $editing_application = $existing ?: null;
        if(!$existing){
            $error = 'Only a rejected application can be edited and resubmitted.';
        }else{
            $relative_path = $existing['photo_path'];
            $has_new_photo = !empty($_FILES['application_photo']['tmp_name']) && is_uploaded_file($_FILES['application_photo']['tmp_name']);
            if($has_new_photo){
                $upload_directory = dirname(__DIR__) . '/uploads/leave_applications';
                if(!is_dir($upload_directory) && !mkdir($upload_directory, 0775, true)) $error = 'Application upload folder could not be created.';
                $file_name = 'leave_' . $user_id . '_' . $staff_id . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.jpg';
                $relative_path = 'uploads/leave_applications/' . $file_name;
                if($error === '') $error = leave_application_compress_photo($_FILES['application_photo'], $upload_directory . '/' . $file_name);
            }
            if($error === ''){
                $update_stmt = mysqli_prepare($conn, "UPDATE staff_leave_applications SET application_date=?, leave_type=?, photo_path=?, status='pending', reviewed_by=NULL, reviewed_at=NULL, created_at=NOW() WHERE id=? AND user_id=? AND staff_id=? AND status='rejected'");
                mysqli_stmt_bind_param($update_stmt, 'sssiii', $application_date, $leave_type, $relative_path, $application_id, $user_id, $staff_id);
                if(mysqli_stmt_execute($update_stmt) && mysqli_stmt_affected_rows($update_stmt) > 0){
                    if($has_new_photo && !empty($existing['photo_path']) && $existing['photo_path'] !== $relative_path) @unlink(dirname(__DIR__) . '/' . $existing['photo_path']);
                    header('Location: leave_application.php?resubmitted=1'); exit;
                }
                if($has_new_photo) @unlink(dirname(__DIR__) . '/' . $relative_path);
                $error = 'Application could not be resubmitted.';
            }
        }
    }else{
        $upload_directory = dirname(__DIR__) . '/uploads/leave_applications';
        if(!is_dir($upload_directory) && !mkdir($upload_directory, 0775, true)) $error = 'Application upload folder could not be created.';
        $file_name = 'leave_' . $user_id . '_' . $staff_id . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.jpg';
        $relative_path = 'uploads/leave_applications/' . $file_name;
        if($error === '') $error = leave_application_compress_photo($_FILES['application_photo'] ?? [], $upload_directory . '/' . $file_name);
        if($error === ''){
            $stmt = mysqli_prepare($conn, 'INSERT INTO staff_leave_applications (user_id,staff_id,login_user_id,application_date,leave_type,photo_path) VALUES (?,?,?,?,?,?)');
            mysqli_stmt_bind_param($stmt, 'iiisss', $user_id, $staff_id, $login_user_id, $application_date, $leave_type, $relative_path);
            if(mysqli_stmt_execute($stmt)){ header('Location: leave_application.php?submitted=1'); exit; }
            @unlink(dirname(__DIR__) . '/' . $relative_path); $error = 'Application could not be submitted.';
        }
    }
}

$history_stmt = mysqli_prepare($conn, 'SELECT * FROM staff_leave_applications WHERE user_id=? AND staff_id=? ORDER BY created_at DESC LIMIT 20');
mysqli_stmt_bind_param($history_stmt, 'ii', $user_id, $staff_id); mysqli_stmt_execute($history_stmt); $applications = mysqli_stmt_get_result($history_stmt);
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-file-medical mr-2"></i>Leave Application</h3></div><div class="card-body">
<?php if(isset($_GET['submitted'])){ ?><div class="alert alert-success">Application submitted and waiting for approval.</div><script>history.replaceState&&history.replaceState({},document.title,'leave_application.php');</script><?php } ?>
<?php if(isset($_GET['resubmitted'])){ ?><div class="alert alert-success">Application resubmitted and waiting for approval.</div><script>history.replaceState&&history.replaceState({},document.title,'leave_application.php');</script><?php } ?>
<?php if($error){ ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php } ?>
<form method="post" enctype="multipart/form-data" class="row" id="leave-application-form">
 <input type="hidden" name="application_id" value="<?= (int)($editing_application['id'] ?? 0) ?>">
 <div class="col-md-4 form-group"><label>Date</label><input type="date" name="application_date" value="<?= htmlspecialchars($_POST['application_date'] ?? ($editing_application['application_date'] ?? date('Y-m-d'))) ?>" class="form-control" required></div>
 <div class="col-md-4 form-group"><label>Application Type</label><?php $selected_leave_type = $_POST['leave_type'] ?? ($editing_application['leave_type'] ?? ''); ?><select name="leave_type" class="form-control" required><option value="">Select type</option><option value="late" <?= $selected_leave_type==='late'?'selected':'' ?>>Late</option><option value="absent" <?= $selected_leave_type==='absent'?'selected':'' ?>>Absent</option><option value="casual_leave" <?= $selected_leave_type==='casual_leave'?'selected':'' ?>>Casual Leave</option><option value="medical_leave" <?= $selected_leave_type==='medical_leave'?'selected':'' ?>>Medical Leave</option></select></div>
 <div class="col-md-4 form-group"><label>Application Photo <small class="text-muted">(compressed to max 50 KB)</small></label><input type="file" name="application_photo" id="application_photo" accept="image/jpeg,image/png,image/webp" class="form-control" <?= $editing_application ? '' : 'required' ?>><small id="photo-compression-status" class="form-text text-muted"><?= $editing_application ? 'Leave empty to keep the existing photo.' : '' ?></small></div>
 <div class="col-12"><button class="btn btn-primary"><i class="fas fa-paper-plane"></i> <?= $editing_application ? 'Update & Resubmit Application' : 'Submit Application' ?></button><?php if($editing_application){ ?><a href="leave_application.php" class="btn btn-secondary ml-2">Cancel Edit</a><?php } ?></div>
</form>
<hr><h4>Application History</h4><div class="table-responsive"><table class="table table-bordered table-striped"><thead><tr><th>Date</th><th>Type</th><th>Photo</th><th>Status</th><th>Submitted</th><th>Action</th></tr></thead><tbody><?php if(mysqli_num_rows($applications)===0){ ?><tr><td colspan="6" class="text-center text-muted">No application submitted yet.</td></tr><?php } while($row=mysqli_fetch_assoc($applications)){ ?><tr><td><?= date('d-m-Y',strtotime($row['application_date'])) ?></td><td><?= htmlspecialchars(ucwords(str_replace('_',' ',$row['leave_type']))) ?></td><td><?php if(!empty($row['photo_path'])){ ?><a href="../<?= htmlspecialchars($row['photo_path']) ?>" target="_blank" class="btn btn-outline-primary btn-sm"><i class="fas fa-image"></i> View</a><?php } ?></td><td><span class="badge badge-<?= $row['status']==='approved'?'success':($row['status']==='rejected'?'danger':'warning') ?>"><?= htmlspecialchars(ucfirst($row['status'])) ?></span></td><td><?= date('d-m-Y',strtotime($row['created_at'])) ?></td><td><?php if($row['status']==='rejected'){ ?><a href="leave_application.php?edit=<?= (int)$row['id'] ?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i> Edit & Resubmit</a><?php } else { ?>-<?php } ?></td></tr><?php } ?></tbody></table></div>
</div></div>
<script>
(function(){
 const photoInput=document.getElementById('application_photo'), status=document.getElementById('photo-compression-status');
 if(!photoInput || !window.FileReader || !window.DataTransfer) return;
 photoInput.addEventListener('change',function(){
   const source=this.files&&this.files[0]; if(!source) return;
   status.className='form-text text-muted'; status.textContent='Compressing photo…';
   const reader=new FileReader();
   reader.onload=function(event){
     const image=new Image(); image.onload=function(){
       let scale=Math.min(1,1400/Math.max(image.width,image.height));
       const canvas=document.createElement('canvas'); canvas.width=Math.max(1,Math.round(image.width*scale)); canvas.height=Math.max(1,Math.round(image.height*scale));
       canvas.getContext('2d').drawImage(image,0,0,canvas.width,canvas.height);
       const attempts=[0.80,0.65,0.50,0.35]; let index=0;
       const makeFile=function(){ canvas.toBlob(function(blob){
         if(!blob){ status.className='form-text text-danger'; status.textContent='Photo compression failed.'; return; }
         if(blob.size<=51200 || index>=attempts.length-1){
           if(blob.size>51200){ status.className='form-text text-danger'; status.textContent='Photo cannot be compressed below 50 KB. Choose a simpler image.'; photoInput.value=''; return; }
           const transfer=new DataTransfer(); transfer.items.add(new File([blob],'leave-application.jpg',{type:'image/jpeg'})); photoInput.files=transfer.files;
           status.className='form-text text-success'; status.textContent='Photo ready: '+Math.ceil(blob.size/1024)+' KB.'; return;
         }
         index++; makeFile();
       },'image/jpeg',attempts[index]); };
       makeFile();
     }; image.onerror=function(){ status.className='form-text text-danger'; status.textContent='Selected photo is invalid.'; }; image.src=event.target.result;
   }; reader.readAsDataURL(source);
 });
})();
</script>
<?php require_once '../includes/footer.php'; ?>
