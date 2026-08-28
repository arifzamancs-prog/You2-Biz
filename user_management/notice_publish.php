<?php
require_once '../includes/auth.php'; require_once '../includes/db.php'; require_once '../includes/notice_board_helper.php';
if(!is_admin_user() && !manager_has_permission('notice_publish')){ header('Location: ' . app_path('dashboard.php?error=Permission denied')); exit; }
ensure_notice_board_table($conn); $user_id=(int)$_SESSION['user_id']; $message='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 $action=$_POST['action'] ?? 'publish';
 if($action==='hide'){
  $id=(int)($_POST['notice_id'] ?? 0); $stmt=mysqli_prepare($conn,"UPDATE notice_board SET status='inactive' WHERE id=? AND user_id=?"); mysqli_stmt_bind_param($stmt,'ii',$id,$user_id); mysqli_stmt_execute($stmt); $_SESSION['notice_publish_flash']='Notice hidden.';
 }elseif($action==='delete'){
  $id=(int)($_POST['notice_id'] ?? 0); $stmt=mysqli_prepare($conn,'DELETE FROM notice_board WHERE id=? AND user_id=?'); mysqli_stmt_bind_param($stmt,'ii',$id,$user_id); mysqli_stmt_execute($stmt); $_SESSION['notice_publish_flash']='Notice deleted.';
 }else{
  $title=trim($_POST['title'] ?? ''); $body=trim($_POST['message'] ?? '');
  if($title!=='' && $body!==''){
   $hide_stmt=mysqli_prepare($conn,"UPDATE notice_board SET status='inactive' WHERE user_id=? AND status='active'"); mysqli_stmt_bind_param($hide_stmt,'i',$user_id); mysqli_stmt_execute($hide_stmt);
   $stmt=mysqli_prepare($conn,"INSERT INTO notice_board (user_id,title,message) VALUES (?,?,?)"); mysqli_stmt_bind_param($stmt,'iss',$user_id,$title,$body); mysqli_stmt_execute($stmt);
   $_SESSION['notice_publish_flash']='Notice published successfully.';
  }else{ $_SESSION['notice_publish_flash']='Title and notice are required.'; }
 }
 header('Location: notice_publish.php'); exit;
}
$message=$_SESSION['notice_publish_flash'] ?? ''; unset($_SESSION['notice_publish_flash']);
$notices=mysqli_query($conn,"SELECT * FROM notice_board WHERE user_id={$user_id} ORDER BY published_at DESC");
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="row"><div class="col-lg-5"><div class="card"><div class="card-header"><h3 class="card-title">Publish Notice</h3></div><form method="post"><input type="hidden" name="action" value="publish"><div class="card-body"><?php if($message): ?><div class="alert alert-success"><?=htmlspecialchars($message)?></div><?php endif;?><div class="form-group"><label>Title</label><input name="title" required class="form-control"></div><div class="form-group"><label>Notice</label><textarea name="message" required class="form-control" rows="6"></textarea></div></div><div class="card-footer"><button class="btn btn-primary"><i class="fas fa-bullhorn"></i> Publish Notice</button></div></form></div></div><div class="col-lg-7"><div class="card"><div class="card-header"><h3 class="card-title">Published Notices</h3></div><div class="card-body table-responsive"><table class="table table-bordered"><thead><tr><th>SL</th><th>Title</th><th>Notice</th><th>Published</th><th>Action</th></tr></thead><tbody><?php $sl=1; while($row=mysqli_fetch_assoc($notices)){?><tr><td><?= $sl++ ?></td><td><?=htmlspecialchars($row['title'])?></td><td><?=nl2br(htmlspecialchars($row['message']))?></td><td><?=htmlspecialchars(app_datetime($row['published_at']))?></td><td><?php if(($row['status'] ?? 'active')==='active'){?><form method="post" class="d-inline"><input type="hidden" name="action" value="hide"><input type="hidden" name="notice_id" value="<?= (int)$row['id'] ?>"><button class="btn btn-warning btn-sm" title="Hide"><i class="fas fa-eye-slash"></i></button></form><?php }else{?><span class="badge badge-secondary">Hidden</span><?php }?><form method="post" class="d-inline" onsubmit="return confirm('Delete this notice?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="notice_id" value="<?= (int)$row['id'] ?>"><button class="btn btn-danger btn-sm" title="Del"><i class="fas fa-trash"></i></button></form></td></tr><?php }?></tbody></table></div></div></div></div>
<?php require_once '../includes/footer.php'; ?>
