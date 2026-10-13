<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/notice_board_helper.php';
if (!is_admin_user() && !manager_has_permission('notice_publish')) {
    header('Location: ' . app_path('dashboard.php?error=Permission denied')); exit;
}
ensure_notice_board_table($conn);
$user_id = (int)$_SESSION['user_id'];
if (empty($_SESSION['notice_token'])) $_SESSION['notice_token'] = bin2hex(random_bytes(32));
$token = $_SESSION['notice_token'];
$message = $_SESSION['notice_publish_flash'] ?? '';
unset($_SESSION['notice_publish_flash']);
$error = '';
$edit = null;
$id = (int)($_POST['notice_id'] ?? $_GET['edit'] ?? 0);
if ($id > 0) {
    $stmt = mysqli_prepare($conn, 'SELECT * FROM notice_board WHERE id=? AND user_id=?');
    mysqli_stmt_bind_param($stmt, 'ii', $id, $user_id);
    mysqli_stmt_execute($stmt);
    $edit = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
}
$title = $edit['title'] ?? '';
$body = $edit['message'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $title = trim((string)($_POST['title'] ?? $title));
    $body = trim((string)($_POST['message'] ?? $body));
    if (!hash_equals($token, (string)($_POST['token'] ?? ''))) {
        $error = 'Please reload the page and try again.';
    } elseif (!in_array($action, ['publish', 'update', 'show', 'hide', 'delete'], true)) {
        $error = 'Invalid action.';
    } elseif ($action !== 'publish' && !$edit) {
        $error = 'Notice not found.';
    } elseif (in_array($action, ['publish', 'update'], true) && ($title === '' || $body === '')) {
        $error = 'Title and notice are required.';
    } else {
        mysqli_begin_transaction($conn);
        try {
            // Preserve the existing single-visible-notice rule.
            if ($action === 'publish' || $action === 'show') {
                $stmt = mysqli_prepare($conn, "UPDATE notice_board SET status='inactive' WHERE user_id=? AND status='active'");
                mysqli_stmt_bind_param($stmt, 'i', $user_id);
                if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Cannot hide previous notice.');
            }
            if ($action === 'publish') {
                $stmt = mysqli_prepare($conn, 'INSERT INTO notice_board (user_id,title,message) VALUES (?,?,?)');
                mysqli_stmt_bind_param($stmt, 'iss', $user_id, $title, $body);
                $flash = 'Notice published successfully.';
            } elseif ($action === 'update') {
                $stmt = mysqli_prepare($conn, 'UPDATE notice_board SET title=?, message=? WHERE id=? AND user_id=?');
                mysqli_stmt_bind_param($stmt, 'ssii', $title, $body, $id, $user_id);
                $flash = 'Notice updated successfully.';
            } elseif ($action === 'delete') {
                $stmt = mysqli_prepare($conn, 'DELETE FROM notice_board WHERE id=? AND user_id=?');
                mysqli_stmt_bind_param($stmt, 'ii', $id, $user_id);
                $flash = 'Notice deleted.';
            } else {
                $status = $action === 'show' ? 'active' : 'inactive';
                $stmt = mysqli_prepare($conn, 'UPDATE notice_board SET status=? WHERE id=? AND user_id=?');
                mysqli_stmt_bind_param($stmt, 'sii', $status, $id, $user_id);
                $flash = $action === 'show' ? 'Notice is now shown.' : 'Notice hidden.';
            }
            if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Cannot save notice.');
            mysqli_commit($conn);
            $_SESSION['notice_publish_flash'] = $flash;
            header('Location: notice_publish.php'); exit;
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $error = 'Unable to save the notice. Please try again.';
        }
    }
}
$notices = mysqli_query($conn, "SELECT * FROM notice_board WHERE user_id={$user_id} ORDER BY published_at DESC");
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<div class="row">
 <div class="col-lg-5"><div class="card">
  <div class="card-header"><h3 class="card-title"><?= $edit ? 'Edit Notice' : 'Publish Notice' ?></h3></div>
  <form method="post">
   <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
   <input type="hidden" name="action" value="<?= $edit ? 'update' : 'publish' ?>">
   <?php if ($edit): ?><input type="hidden" name="notice_id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
   <div class="card-body">
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <div class="form-group"><label for="noticeTitle">Title</label><input id="noticeTitle" name="title" required maxlength="180" class="form-control" value="<?= htmlspecialchars($title) ?>"></div>
    <div class="form-group"><label for="noticeMessage">Notice</label><textarea id="noticeMessage" name="message" required class="form-control" rows="6"><?= htmlspecialchars($body) ?></textarea></div>
   </div>
   <div class="card-footer">
    <button type="submit" class="btn btn-primary"><i class="fas <?= $edit ? 'fa-save' : 'fa-bullhorn' ?>"></i> <?= $edit ? 'Update Notice' : 'Publish Notice' ?></button>
    <?php if ($edit): ?><a href="notice_publish.php" class="btn btn-secondary">Cancel</a><?php endif; ?>
   </div>
  </form>
 </div></div>
 <div class="col-lg-7"><div class="card">
  <div class="card-header"><h3 class="card-title">Published Notices</h3></div>
  <div class="card-body table-responsive"><table class="table table-bordered notice-table">
   <thead><tr><th class="notice-sl">SL</th><th class="notice-title">Title</th><th class="notice-message">Notice</th><th class="notice-published">Published</th><th class="notice-action">Action</th></tr></thead>
   <tbody>
   <?php $sl=1; while ($row=mysqli_fetch_assoc($notices)): $active=$row['status']==='active'; ?>
    <tr>
     <td><?= $sl++ ?></td><td><?= htmlspecialchars($row['title']) ?></td>
     <td><?= nl2br(htmlspecialchars($row['message'])) ?></td>
     <td><?= htmlspecialchars(date('d-m-Y', strtotime($row['published_at']))) ?></td>
     <td>
      <a href="?edit=<?= (int)$row['id'] ?>" class="btn btn-info btn-sm mb-1" title="Edit" aria-label="Edit notice"><i class="fas fa-edit"></i></a>
      <form method="post" class="d-inline">
       <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
       <input type="hidden" name="action" value="<?= $active ? 'hide' : 'show' ?>">
       <input type="hidden" name="notice_id" value="<?= (int)$row['id'] ?>">
       <button type="submit" class="btn <?= $active ? 'btn-warning' : 'btn-success' ?> btn-sm mb-1" title="<?= $active ? 'Hide' : 'Show' ?>"><i class="fas <?= $active ? 'fa-eye-slash' : 'fa-eye' ?>"></i></button>
      </form>
      <form method="post" class="d-inline" onsubmit="return confirm('Delete this notice?');">
       <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
       <input type="hidden" name="action" value="delete">
       <input type="hidden" name="notice_id" value="<?= (int)$row['id'] ?>">
       <button type="submit" class="btn btn-danger btn-sm mb-1" title="Delete" aria-label="Delete notice"><i class="fas fa-trash"></i></button>
      </form>
     </td>
    </tr>
   <?php endwhile; ?>
   </tbody>
  </table></div>
 </div></div>
</div>
<style>
.notice-table { table-layout: fixed; }
.notice-table .notice-sl { width: 7%; }
.notice-table .notice-title { width: 20%; }
.notice-table .notice-message { width: 40%; }
.notice-table .notice-published { width: 16%; }
.notice-table .notice-action { width: 17%; }
.notice-table td:nth-child(2), .notice-table td:nth-child(3) { overflow-wrap: anywhere; }
</style>
<?php require_once '../includes/footer.php'; ?>
